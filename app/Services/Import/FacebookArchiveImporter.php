<?php

namespace App\Services\Import;

use App\Models\AccountAlias;
use App\Models\Archive;
use App\Models\Attachment;
use App\Models\BookmarkedPost;
use App\Models\Post;
use App\Models\ProfileSnapshot;
use App\Models\SocialAccount;
use App\Models\SocialConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class FacebookArchiveImporter
{
    use RepairsMetaEncoding;

    private const PROFILE = 'personal_information/profile_information/profile_information.json';

    private array $profileMedia = [];

    public function import(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Archive not found: {$path}");
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not a readable ZIP archive.');
        }

        try {
            if ($zip->locateName(self::PROFILE) === false) {
                throw new InvalidArgumentException('This does not look like a supported Facebook archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path): array
    {
        $profile = $this->repairMetaEncoding($this->json($zip, self::PROFILE)['profile_v2']);
        $slug = preg_match('/^facebook-([^-]+)/', basename($path), $match) ? $match[1] : sha1($profile['name']['full_name']);
        $account = SocialAccount::updateOrCreate(
            ['platform' => 'facebook', 'external_id' => $slug],
            ['handle' => '@'.$slug, 'display_name' => $profile['name']['full_name'], 'location' => data_get($profile, 'current_city.name')]
        );
        $fingerprint = hash_file('sha256', $path);
        $archive = Archive::firstOrCreate(['fingerprint' => $fingerprint], [
            'social_account_id' => $account->id, 'label' => basename($path), 'exported_at' => $this->exportDate($path),
            'imported_at' => now(), 'status' => 'ready', 'metadata' => ['source_path' => realpath($path), 'format' => 'facebook-json'],
        ]);
        $created = $archive->wasRecentlyCreated;
        $this->profile($zip, $account, $archive, $profile);

        $count = 0;
        foreach ($this->matchingFiles($zip, '#^your_facebook_activity/posts/your_posts__check_ins__photos_and_videos_\d+\.json$#') as $file) {
            $count += $this->importRecords($zip, $account, $archive, $this->json($zip, $file), 'post', true);
        }
        $comments = [];
        foreach (data_get($this->optionalJson($zip, 'your_facebook_activity/comments_and_reactions/comments.json'), 'comments_v2', []) as $record) {
            $comment = data_get($record, 'data.0.comment', []);
            if (! isset($comment['comment'])) {
                continue;
            }
            $comments[] = [
                'timestamp' => $comment['timestamp'] ?? $record['timestamp'], 'title' => $record['title'] ?? null,
                'data' => [['post' => $comment['comment']]],
            ];
        }
        $count += $this->importRecords($zip, $account, $archive, $comments, 'comment', false);
        $this->reactions($zip, $account);
        $this->savedItems($zip, $account);
        $this->friends($zip, $archive);
        $this->applyProfileMedia($account, $archive);

        return ['archive' => $archive, 'posts' => $count, 'created' => $created];
    }

    private function importRecords(ZipArchive $zip, SocialAccount $account, Archive $archive, array $records, string $type, bool $withMedia): int
    {
        foreach (array_chunk($records, 250) as $chunk) {
            $this->removeEncodingRepairDuplicates($account, $chunk, $type);
            $normalized = collect($chunk)->map(fn ($record) => $this->normalizeRecord($account, $record, $type));
            DB::table('posts')->upsert(
                $normalized->values()->all(), ['social_account_id', 'external_id'],
                ['type', 'body', 'url', 'posted_at', 'metadata', 'updated_at']
            );
            $posts = Post::where('social_account_id', $account->id)
                ->whereIn('external_id', $normalized->pluck('external_id'))->get()->keyBy('external_id');
            DB::table('archive_post')->insertOrIgnore(
                $posts->map(fn ($post) => ['archive_id' => $archive->id, 'post_id' => $post->id])->values()->all()
            );
            if ($withMedia) {
                foreach ($chunk as $record) {
                    if ($post = $posts->get($this->externalId($record, $type))) {
                        $this->attachments($zip, $post, $record['attachments'] ?? []);
                        $this->rememberProfileMedia($record, $post);
                    }
                }
            }
        }

        return count($records);
    }

    private function normalizeRecord(SocialAccount $account, array $record, string $type): array
    {
        $externalId = $this->externalId($record, $type);
        $record = $this->repairMetaEncoding($record);
        $body = collect($record['data'] ?? [])->pluck('post')->filter()->first();
        $externalContexts = collect($record['attachments'] ?? [])
            ->flatMap(fn ($attachment) => $attachment['data'] ?? [])
            ->pluck('external_context')
            ->filter();
        $linkedContext = $externalContexts->first(fn ($context) => ! empty($context['url'])) ?? $externalContexts->first();
        $externalUrl = $linkedContext['url'] ?? null;
        $place = collect($record['attachments'] ?? [])->flatMap(fn ($attachment) => $attachment['data'] ?? [])->pluck('place')->filter()->first();
        $location = $place ? array_filter([
            'name' => $place['name'] ?? null,
            'address' => $place['address'] ?? null,
            'latitude' => data_get($place, 'coordinate.latitude'),
            'longitude' => data_get($place, 'coordinate.longitude'),
        ], fn ($value) => $value !== null) : null;

        return [
            'social_account_id' => $account->id, 'external_id' => $externalId,
            'type' => $type, 'body' => $body ?: ($record['title'] ?? null), 'url' => $externalUrl,
            'posted_at' => isset($record['timestamp']) ? date('Y-m-d H:i:s', $record['timestamp']) : null,
            'metadata' => json_encode([
                'title' => $record['title'] ?? null,
                'external_url' => $externalUrl,
                'external_name' => $linkedContext['name'] ?? null,
                'external_source' => $linkedContext['source'] ?? null,
                'location' => $location,
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function externalId(array $record, string $type): string
    {
        $body = collect($record['data'] ?? [])->pluck('post')->filter()->first();
        $identity = [$record['timestamp'] ?? 0, $type, $body, $record['title'] ?? null, $this->mediaUris($record['attachments'] ?? [])];

        return sha1(json_encode($identity));
    }

    private function attachments(ZipArchive $zip, Post $post, array $groups): void
    {
        $groups = $this->repairMetaEncoding($groups);
        foreach ($groups as $group) {
            foreach ($group['data'] ?? [] as $data) {
                $media = $data['media'] ?? null;
                if (! $media || empty($media['uri']) || $zip->locateName($media['uri']) === false) {
                    continue;
                }
                $filename = sha1($media['uri']).'-'.basename($media['uri']);
                $path = "archive-media/{$post->social_account_id}/facebook/{$filename}";
                if (! Storage::disk('public')->exists($path)) {
                    $stream = $zip->getStream($media['uri']);
                    Storage::disk('public')->put($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $extension = strtolower(pathinfo($media['uri'], PATHINFO_EXTENSION));
                Attachment::updateOrCreate(['post_id' => $post->id, 'path' => $path], [
                    'type' => in_array($extension, ['mp4', 'mov', 'webm']) ? 'video' : 'image',
                    'alt_text' => $media['description'] ?? null,
                    'metadata' => ['title' => $media['title'] ?? null, 'created_at' => $media['creation_timestamp'] ?? null],
                ]);
            }
        }
    }

    private function removeEncodingRepairDuplicates(SocialAccount $account, array $records, string $type): void
    {
        $duplicateIds = collect($records)->map(function (array $record) use ($type): ?string {
            $rawId = $this->externalId($record, $type);
            $repairedId = $this->externalId($this->repairMetaEncoding($record), $type);

            return $rawId === $repairedId ? null : $repairedId;
        })->filter();

        if ($duplicateIds->isNotEmpty()) {
            Post::where('social_account_id', $account->id)->whereIn('external_id', $duplicateIds)->delete();
        }
    }

    private function reactions(ZipArchive $zip, SocialAccount $account): void
    {
        foreach ($this->matchingFiles($zip, '#^your_facebook_activity/comments_and_reactions/likes_and_reactions(?:_\d+)?\.json$#') as $file) {
            $rows = [];
            foreach ($this->json($zip, $file) as $item) {
                $rawValues = collect($item['label_values'] ?? []);
                $rawUrl = data_get($rawValues->firstWhere('label', 'URL'), 'href') ?: data_get($rawValues->firstWhere('label', 'URL'), 'value');
                $rawReaction = data_get($rawValues->firstWhere('label', 'Reaction'), 'value');
                $id = (string) ($item['fbid'] ?? sha1(json_encode([$item['timestamp'] ?? null, $rawUrl, $rawReaction])));
                $item = $this->repairMetaEncoding($item);
                $values = collect($item['label_values'] ?? []);
                $url = data_get($values->firstWhere('label', 'URL'), 'href') ?: data_get($values->firstWhere('label', 'URL'), 'value');
                $reaction = data_get($values->firstWhere('label', 'Reaction'), 'value');
                $rows[] = [
                    'social_account_id' => $account->id, 'external_id' => $id, 'url' => $url, 'body' => $reaction,
                    'metadata' => json_encode(['reaction' => $reaction, 'timestamp' => $item['timestamp'] ?? null]),
                    'created_at' => now(), 'updated_at' => now(),
                ];
                if (count($rows) === 500) {
                    $this->upsertReactions($rows);
                    $rows = [];
                }
            }
            $this->upsertReactions($rows);
        }
    }

    private function upsertReactions(array $rows): void
    {
        if (! $rows) {
            return;
        }
        DB::table('liked_posts')->upsert(
            $rows, ['social_account_id', 'external_id'], ['url', 'body', 'metadata', 'updated_at']
        );
    }

    private function savedItems(ZipArchive $zip, SocialAccount $account): void
    {
        BookmarkedPost::where('social_account_id', $account->id)->delete();
        foreach (data_get($this->optionalJson($zip, 'your_facebook_activity/saved_items_and_collections/your_saved_items.json'), 'saves_v2', []) as $item) {
            $url = data_get($item, 'attachments.0.data.0.external_context.url');
            $id = sha1(json_encode([$item['timestamp'] ?? null, $item['title'] ?? null, $url]));
            BookmarkedPost::updateOrCreate(
                ['social_account_id' => $account->id, 'external_id' => $id], ['url' => $url ?: 'facebook://saved/'.$id]
            );
        }
    }

    private function friends(ZipArchive $zip, Archive $archive): void
    {
        SocialConnection::where('archive_id', $archive->id)->where('direction', 'friend')->delete();
        foreach (data_get($this->optionalJson($zip, 'connections/friends/your_friends.json'), 'friends_v2', []) as $friend) {
            SocialConnection::firstOrCreate([
                'archive_id' => $archive->id, 'direction' => 'friend',
                'external_account_id' => sha1(json_encode([$friend['name'], $friend['timestamp'] ?? null])),
            ], ['url' => null]);
        }
    }

    private function profile(ZipArchive $zip, SocialAccount $account, Archive $archive, array $profile): void
    {
        $account->update(['metadata' => [
            'hometown' => data_get($profile, 'hometown.name'), 'work' => $profile['work_experiences'] ?? [],
            'education' => $profile['education_experiences'] ?? [], 'relationship_status' => data_get($profile, 'relationship.status'),
        ]]);
        foreach ($profile['previous_names'] ?? [] as $previous) {
            AccountAlias::updateOrCreate(
                ['social_account_id' => $account->id, 'handle' => $previous['name']],
                ['changed_at' => isset($previous['timestamp']) ? date('c', $previous['timestamp']) : null]
            );
        }
        ProfileSnapshot::updateOrCreate(['archive_id' => $archive->id], [
            'location' => $account->location, 'metadata' => ['display_name' => $account->display_name],
        ]);
        $this->albumProfileMedia($zip, $account, $archive);
    }

    private function albumProfileMedia(ZipArchive $zip, SocialAccount $account, Archive $archive): void
    {
        $updates = [];
        foreach ($this->matchingFiles($zip, '#^your_facebook_activity/posts/album/\d+\.json$#') as $file) {
            $album = $this->json($zip, $file);
            $kind = ($album['name'] ?? '') === 'Profile pictures' ? 'avatar' : (($album['name'] ?? '') === 'Cover photos' ? 'header' : null);
            if (! $kind || empty($album['photos'])) {
                continue;
            }
            $photo = collect($album['photos'])->sortByDesc('creation_timestamp')->first();
            if ($zip->locateName($photo['uri']) === false) {
                continue;
            }
            $path = "profile-media/{$account->id}/{$kind}.".pathinfo($photo['uri'], PATHINFO_EXTENSION);
            $stream = $zip->getStream($photo['uri']);
            Storage::disk('public')->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $updates[$kind.'_path'] = $path;
        }
        if ($updates) {
            $account->update($updates);
            ProfileSnapshot::where('archive_id', $archive->id)->update($updates);
        }
    }

    private function rememberProfileMedia(array $record, Post $post): void
    {
        $title = strtolower($record['title'] ?? '');
        $kind = str_contains($title, 'profile picture') ? 'avatar' : (str_contains($title, 'cover photo') ? 'header' : null);
        if (! $kind || empty($record['timestamp'])) {
            return;
        }
        $attachment = $post->attachments()->first();
        if ($attachment && ($record['timestamp'] > ($this->profileMedia[$kind]['timestamp'] ?? 0))) {
            $this->profileMedia[$kind] = ['timestamp' => $record['timestamp'], 'path' => $attachment->path];
        }
    }

    private function applyProfileMedia(SocialAccount $account, Archive $archive): void
    {
        $updates = [];
        foreach (['avatar', 'header'] as $kind) {
            if (isset($this->profileMedia[$kind])) {
                $source = $this->profileMedia[$kind]['path'];
                $path = "profile-media/{$account->id}/{$kind}.".pathinfo($source, PATHINFO_EXTENSION);
                Storage::disk('public')->copy($source, $path);
                $updates[$kind.'_path'] = $path;
            }
        }
        if ($updates) {
            $account->update($updates);
            ProfileSnapshot::where('archive_id', $archive->id)->update($updates);
        }
    }

    private function mediaUris(array $groups): array
    {
        $uris = [];
        foreach ($groups as $group) {
            foreach ($group['data'] ?? [] as $data) {
                if (isset($data['media']['uri'])) {
                    $uris[] = $data['media']['uri'];
                }
            }
        }

        return $uris;
    }

    private function matchingFiles(ZipArchive $zip, string $pattern): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match($pattern, $name = $zip->getNameIndex($i))) {
                $files[] = $name;
            }
        }
        sort($files, SORT_NATURAL);

        return $files;
    }

    private function exportDate(string $path): ?string
    {
        return preg_match('/facebook-[^-]+-(\d{4}-\d{2}-\d{2})-/', basename($path), $match) ? $match[1] : null;
    }

    private function optionalJson(ZipArchive $zip, string $name): array
    {
        return $zip->locateName($name) === false ? [] : $this->json($zip, $name);
    }

    private function json(ZipArchive $zip, string $name): array
    {
        $content = $zip->getFromName($name);
        if ($content === false) {
            throw new InvalidArgumentException("Missing {$name}");
        }

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
