<?php

namespace App\Services\Import;

use App\Models\AccountAlias;
use App\Models\Archive;
use App\Models\Attachment;
use App\Models\LikedPost;
use App\Models\Post;
use App\Models\ProfileSnapshot;
use App\Models\SavedSearch;
use App\Models\SocialAccount;
use App\Models\SocialConnection;
use App\Models\SocialList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class TwitterArchiveImporter
{
    public function import(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Archive not found: {$path}");
        }

        $fingerprint = hash_file('sha256', $path);
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not a readable ZIP archive.');
        }

        try {
            $accountData = $this->readWrappedJson($zip, 'data/account.js')[0]['account'] ?? null;
            if (! $accountData) {
                throw new InvalidArgumentException('This does not look like a supported Twitter archive.');
            }
            $manifest = $this->readObject($zip, 'data/manifest.js');
            $tweetFiles = $this->tweetFiles($zip);
            $mediaFiles = $this->mediaFiles($zip);

            return DB::transaction(function () use ($zip, $accountData, $manifest, $tweetFiles, $mediaFiles, $fingerprint, $path) {
                $account = SocialAccount::updateOrCreate(
                    ['platform' => 'twitter', 'external_id' => $accountData['accountId']],
                    ['handle' => '@'.$accountData['username'], 'display_name' => $accountData['accountDisplayName'], 'metadata' => ['created_at' => $accountData['createdAt'] ?? null]]
                );
                $archive = Archive::firstOrCreate(
                    ['fingerprint' => $fingerprint],
                    [
                        'social_account_id' => $account->id, 'label' => basename($path),
                        'exported_at' => data_get($manifest, 'archiveInfo.generationDate'), 'imported_at' => now(),
                        'status' => 'ready',
                        'metadata' => ['source_path' => realpath($path), 'partial' => (bool) data_get($manifest, 'archiveInfo.isPartialArchive', false)],
                    ]
                );
                $created = $archive->wasRecentlyCreated;
                $this->importAccountData($zip, $account, $archive);

                $count = 0;
                foreach ($tweetFiles as $file) {
                    $count += $this->importTweets($zip, $account, $archive, $mediaFiles, $this->streamWrappedJson($zip, $file));
                }
                $count += $this->importTweets($zip, $account, $archive, $mediaFiles, $this->streamOptionalWrappedJson($zip, 'data/deleted-tweets.js'), true);

                return ['archive' => $archive, 'posts' => $count, 'created' => $created];
            });
        } finally {
            $zip->close();
        }
    }

    private function importTweets(ZipArchive $zip, SocialAccount $account, Archive $archive, array $mediaFiles, iterable $items, bool $deleted = false): int
    {
        $batch = [];
        $count = 0;
        foreach ($items as $item) {
            $batch[] = $item['tweet'];
            $count++;
            if (count($batch) === 250) {
                $this->persistTweetBatch($zip, $account, $archive, $mediaFiles, $batch, $deleted);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->persistTweetBatch($zip, $account, $archive, $mediaFiles, $batch, $deleted);
        }

        return $count;
    }

    private function persistTweetBatch(ZipArchive $zip, SocialAccount $account, Archive $archive, array $mediaFiles, array $tweets, bool $deleted): void
    {
        $now = now();
        $rows = [];
        foreach ($tweets as $tweet) {
            $attributes = $this->postAttributes($account, $tweet);
            $rows[] = [
                'social_account_id' => $account->id,
                'external_id' => $tweet['id_str'],
                'type' => $attributes['type'],
                'body' => $attributes['body'],
                'url' => $attributes['url'],
                'posted_at' => $attributes['posted_at']->toDateTimeString(),
                'deleted_at' => $deleted ? CarbonImmutable::createFromFormat('D M d H:i:s O Y', $tweet['deleted_at'])->toDateTimeString() : null,
                'reply_to_external_id' => $attributes['reply_to_external_id'],
                'metadata' => json_encode($attributes['metadata']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('posts')->upsert($rows, ['social_account_id', 'external_id'], ['type', 'body', 'url', 'posted_at', 'deleted_at', 'reply_to_external_id', 'metadata', 'updated_at']);
        $posts = Post::where('social_account_id', $account->id)->whereIn('external_id', collect($tweets)->pluck('id_str'))->get()->keyBy('external_id');
        DB::table('archive_post')->insertOrIgnore($posts->map(fn ($post) => ['archive_id' => $archive->id, 'post_id' => $post->id])->values()->all());
        foreach ($tweets as $tweet) {
            if ($post = $posts->get($tweet['id_str'])) {
                $this->attachMedia($zip, $post, $mediaFiles[$tweet['id_str']] ?? []);
            }
        }
    }

    private function postAttributes(SocialAccount $account, array $tweet): array
    {
        $body = $tweet['full_text'] ?? '';
        $type = str_starts_with($body, 'RT @') ? 'retweet' : (isset($tweet['in_reply_to_status_id_str']) ? 'reply' : 'post');

        return [
            'type' => $type, 'body' => $body,
            'url' => 'https://twitter.com/'.ltrim($account->handle, '@').'/status/'.$tweet['id_str'],
            'posted_at' => CarbonImmutable::createFromFormat('D M d H:i:s O Y', $tweet['created_at']),
            'reply_to_external_id' => $tweet['in_reply_to_status_id_str'] ?? null,
            'metadata' => [
                'language' => $tweet['lang'] ?? null, 'source' => $tweet['source'] ?? null,
                'favorite_count' => (int) ($tweet['favorite_count'] ?? 0), 'retweet_count' => (int) ($tweet['retweet_count'] ?? 0),
                'entities' => $tweet['entities'] ?? [],
                'coordinates' => $tweet['coordinates'] ?? null, 'geo' => $tweet['geo'] ?? null,
                'place' => $tweet['place'] ?? null,
            ],
        ];
    }

    private function mediaFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^data/(?:tweets_media|deleted_tweets_media)/(\d+)-[^/]+$#', $name, $matches)) {
                $files[$matches[1]][] = $name;
            }
        }

        return $files;
    }

    private function attachMedia(ZipArchive $zip, Post $post, array $files): void
    {
        foreach ($files as $file) {
            $filename = basename($file);
            $storagePath = "archive-media/{$post->social_account_id}/{$filename}";
            if (! Storage::disk('public')->exists($storagePath)) {
                $stream = $zip->getStream($file);
                Storage::disk('public')->put($storagePath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            Attachment::updateOrCreate(
                ['post_id' => $post->id, 'path' => $storagePath],
                ['type' => in_array($extension, ['mp4', 'mov', 'webm']) ? 'video' : 'image']
            );
        }
    }

    private function importAccountData(ZipArchive $zip, SocialAccount $account, Archive $archive): void
    {
        $profile = data_get($this->readOptionalWrappedJson($zip, 'data/profile.js'), '0.profile', []);
        $description = $profile['description'] ?? [];
        $avatarPath = $this->extractProfileMedia($zip, $account, $profile['avatarMediaUrl'] ?? null, 'avatar');
        $headerPath = $this->extractProfileMedia($zip, $account, $profile['headerMediaUrl'] ?? null, 'header');
        $timezone = data_get($this->readOptionalWrappedJson($zip, 'data/account-timezone.js'), '0.accountTimezone.timeZone');
        $verified = (bool) data_get($this->readOptionalWrappedJson($zip, 'data/verified.js'), '0.verified.verified', false);

        $account->update([
            'bio' => $description['bio'] ?? null, 'website' => $description['website'] ?? null,
            'location' => $description['location'] ?? null, 'avatar_path' => $avatarPath,
            'header_path' => $headerPath, 'timezone' => $timezone, 'verified' => $verified,
        ]);
        ProfileSnapshot::updateOrCreate(['archive_id' => $archive->id], [
            'bio' => $account->bio, 'website' => $account->website, 'location' => $account->location,
            'avatar_path' => $avatarPath, 'header_path' => $headerPath,
            'metadata' => ['avatar_url' => $profile['avatarMediaUrl'] ?? null, 'header_url' => $profile['headerMediaUrl'] ?? null],
        ]);

        foreach ($this->readOptionalWrappedJson($zip, 'data/screen-name-change.js') as $item) {
            $change = data_get($item, 'screenNameChange.screenNameChange', []);
            foreach (array_filter([$change['changedFrom'] ?? null, $change['changedTo'] ?? null]) as $handle) {
                AccountAlias::updateOrCreate(
                    ['social_account_id' => $account->id, 'handle' => '@'.ltrim($handle, '@')],
                    ['changed_at' => $change['changedAt'] ?? null]
                );
            }
        }
        foreach ($this->streamOptionalWrappedJson($zip, 'data/like.js') as $item) {
            $like = $item['like'];
            LikedPost::updateOrCreate(
                ['social_account_id' => $account->id, 'external_id' => $like['tweetId']],
                ['body' => $like['fullText'] ?? null, 'url' => $like['expandedUrl'] ?? null]
            );
        }
        $this->importConnections($zip, $archive, 'follower', 'follower');
        $this->importConnections($zip, $archive, 'following', 'following');

        foreach ($this->streamOptionalWrappedJson($zip, 'data/lists-created.js') as $item) {
            $url = data_get($item, 'userListInfo.url');
            SocialList::firstOrCreate(['archive_id' => $archive->id, 'url' => $url], ['name' => basename($url)]);
        }
        foreach ($this->streamOptionalWrappedJson($zip, 'data/saved-search.js') as $item) {
            $search = $item['savedSearch'];
            SavedSearch::firstOrCreate(
                ['archive_id' => $archive->id, 'external_id' => $search['savedSearchId'] ?? null],
                ['query' => $search['query']]
            );
        }
    }

    private function importConnections(ZipArchive $zip, Archive $archive, string $file, string $key): void
    {
        foreach ($this->streamOptionalWrappedJson($zip, "data/{$file}.js") as $item) {
            $connection = $item[$key];
            SocialConnection::firstOrCreate([
                'archive_id' => $archive->id, 'direction' => $key,
                'external_account_id' => $connection['accountId'],
            ], ['url' => $connection['userLink'] ?? null]);
        }
    }

    private function extractProfileMedia(ZipArchive $zip, SocialAccount $account, ?string $url, string $kind): ?string
    {
        if (! $url) {
            return null;
        }
        $token = basename($url);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'data/profile_media/') && str_contains(basename($name), '-'.$token)) {
                $path = "profile-media/{$account->id}/{$kind}.".pathinfo($name, PATHINFO_EXTENSION);
                Storage::disk('public')->put($path, $zip->getFromName($name));

                return $path;
            }
        }

        return null;
    }

    private function tweetFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^data/tweets(?:-part\d+)?\.js$#', $name)) {
                $files[] = $name;
            }
        }
        sort($files);

        return $files;
    }

    private function readWrappedJson(ZipArchive $zip, string $name): array
    {
        $content = $zip->getFromName($name);
        $start = $content === false ? false : strpos($content, '[');
        if ($start === false) {
            throw new InvalidArgumentException("Missing or malformed {$name}");
        }

        return json_decode(substr($content, $start), true, flags: JSON_THROW_ON_ERROR);
    }

    private function readOptionalWrappedJson(ZipArchive $zip, string $name): array
    {
        return $zip->locateName($name) === false ? [] : $this->readWrappedJson($zip, $name);
    }

    private function streamOptionalWrappedJson(ZipArchive $zip, string $name): \Generator
    {
        if ($zip->locateName($name) !== false) {
            yield from $this->streamWrappedJson($zip, $name);
        }
    }

    private function streamWrappedJson(ZipArchive $zip, string $name): \Generator
    {
        $stream = $zip->getStream($name);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException("Missing {$name}");
        }

        $arrayStarted = false;
        $depth = 0;
        $object = '';
        $inString = false;
        $escaped = false;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new RuntimeException("Could not read {$name}");
                }
                $length = strlen($chunk);
                for ($index = 0; $index < $length; $index++) {
                    $character = $chunk[$index];
                    if (! $arrayStarted) {
                        $arrayStarted = $character === '[';

                        continue;
                    }
                    if ($depth === 0) {
                        if ($character === '{') {
                            $depth = 1;
                            $object = '{';
                        }

                        continue;
                    }

                    $object .= $character;
                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($character === '\\') {
                            $escaped = true;
                        } elseif ($character === '"') {
                            $inString = false;
                        }

                        continue;
                    }
                    if ($character === '"') {
                        $inString = true;
                    } elseif ($character === '{' || $character === '[') {
                        $depth++;
                    } elseif ($character === '}' || $character === ']') {
                        $depth--;
                        if ($depth === 0) {
                            yield json_decode($object, true, flags: JSON_THROW_ON_ERROR);
                            $object = '';
                        }
                    }
                }
            }
        } finally {
            fclose($stream);
        }

        if (! $arrayStarted || $depth !== 0) {
            throw new InvalidArgumentException("Missing or malformed {$name}");
        }
    }

    private function readObject(ZipArchive $zip, string $name): array
    {
        $content = $zip->getFromName($name);
        $start = $content === false ? false : strpos($content, '{');
        if ($start === false) {
            throw new InvalidArgumentException("Missing or malformed {$name}");
        }

        return json_decode(rtrim(substr($content, $start), "; \n\r\t"), true, flags: JSON_THROW_ON_ERROR);
    }
}
