<?php

namespace App\Services\Import;

use App\Models\Archive;
use App\Models\Attachment;
use App\Models\BookmarkedPost;
use App\Models\DirectMessage;
use App\Models\LikedPost;
use App\Models\Post;
use App\Models\ProfileSnapshot;
use App\Models\SocialAccount;
use App\Models\SocialConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class InstagramArchiveImporter
{
    private const PROFILE = 'personal_information/personal_information/personal_information.json';

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
            if ($zip->locateName(self::PROFILE) === false || $zip->locateName('your_instagram_activity/content/posts_1.json') === false) {
                throw new InvalidArgumentException('This does not look like a supported Instagram archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path): array
    {
        $profile = data_get($this->json($zip, self::PROFILE), 'profile_user.0', []);
        $fields = $profile['string_map_data'] ?? [];
        $username = data_get($fields, 'Username.value') ?: (preg_match('/^instagram-([^-]+)/', basename($path), $m) ? $m[1] : 'instagram');
        $account = SocialAccount::updateOrCreate(['platform' => 'instagram', 'external_id' => $username], [
            'handle' => '@'.$username, 'display_name' => $this->text(data_get($fields, 'Name.value', $username)),
            'bio' => $this->text(data_get($fields, 'Bio.value', '')), 'website' => data_get($fields, 'Website.value'),
            'metadata' => ['pronouns' => data_get($fields, 'Pronouns.value'), 'private' => data_get($fields, 'Private Account.value') === 'True'],
        ]);
        $archive = Archive::firstOrCreate(['fingerprint' => hash_file('sha256', $path)], [
            'social_account_id' => $account->id, 'label' => basename($path), 'exported_at' => $this->exportDate($path),
            'imported_at' => now(), 'status' => 'ready', 'metadata' => ['source_path' => realpath($path), 'format' => 'instagram-json'],
        ]);
        $created = $archive->wasRecentlyCreated;
        $this->profile($zip, $account, $archive, $profile);
        $count = $this->mediaPosts($zip, $account, $archive, $this->json($zip, 'your_instagram_activity/content/posts_1.json'), 'post');
        $count += $this->mediaPosts($zip, $account, $archive, data_get($this->optionalJson($zip, 'your_instagram_activity/content/igtv_videos.json'), 'ig_igtv_media', []), 'video');
        $count += $this->comments($zip, $account, $archive);
        $this->likes($zip, $account);
        $this->saved($zip, $account);
        $this->connections($zip, $archive);
        $this->messages($zip, $account);

        return ['archive' => $archive, 'posts' => $count, 'created' => $created];
    }

    private function mediaPosts(ZipArchive $zip, SocialAccount $account, Archive $archive, array $records, string $type): int
    {
        foreach (array_chunk($records, 200) as $chunk) {
            $rows = [];
            foreach ($chunk as $record) {
                $id = $this->mediaId($record, $type);
                $rows[] = [
                    'social_account_id' => $account->id, 'external_id' => $id, 'type' => $type, 'body' => $this->text($record['title'] ?? ''),
                    'url' => null, 'posted_at' => date('Y-m-d H:i:s', $record['creation_timestamp'] ?? data_get($record, 'media.0.creation_timestamp', 0)),
                    'metadata' => json_encode(['cross_post_source' => data_get($record, 'media.0.cross_post_source.source_app')]), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('posts')->upsert($rows, ['social_account_id', 'external_id'], ['type', 'body', 'posted_at', 'metadata', 'updated_at']);
            $posts = Post::where('social_account_id', $account->id)->whereIn('external_id', collect($rows)->pluck('external_id'))->get()->keyBy('external_id');
            DB::table('archive_post')->insertOrIgnore($posts->map(fn ($p) => ['archive_id' => $archive->id, 'post_id' => $p->id])->values()->all());
            foreach ($chunk as $record) {
                if ($post = $posts->get($this->mediaId($record, $type))) {
                    $this->attachments($zip, $post, $record['media'] ?? []);
                }
            }
        }

        return count($records);
    }

    private function comments(ZipArchive $zip, SocialAccount $account, Archive $archive): int
    {
        $records = $this->json($zip, 'your_instagram_activity/comments/post_comments_1.json');
        $externalIds = [];
        foreach ($records as $record) {
            $map = $record['string_map_data'];
            $rawBody = data_get($map, 'Comment.value', '');
            $body = $this->text($rawBody);
            $time = data_get($map, 'Time.timestamp');
            $externalId = sha1(json_encode([$time, $rawBody, data_get($map, 'Media Owner.value')]));
            $externalIds[] = $externalId;
            $post = Post::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $externalId], [
                'type' => 'comment', 'body' => $body, 'posted_at' => date('Y-m-d H:i:s', $time), 'metadata' => ['media_owner' => data_get($map, 'Media Owner.value')],
            ]);
            $archive->posts()->syncWithoutDetaching($post->id);
        }

        $stale = $archive->posts()->where('type', 'comment')->whereNotIn('external_id', $externalIds)->get();
        $archive->posts()->detach($stale->pluck('id'));
        Post::whereIn('id', $stale->pluck('id'))->whereDoesntHave('archives')->delete();

        return count($records);
    }

    private function attachments(ZipArchive $zip, Post $post, array $media): void
    {
        foreach ($media as $item) {
            $uri = $item['uri'] ?? null;
            if (! $uri || $zip->locateName($uri) === false) {
                continue;
            }
            $path = "archive-media/{$post->social_account_id}/instagram/".sha1($uri).'-'.basename($uri);
            if (! Storage::disk('public')->exists($path)) {
                $stream = $zip->getStream($uri);
                Storage::disk('public')->put($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
            Attachment::updateOrCreate(['post_id' => $post->id, 'path' => $path], [
                'type' => in_array($ext, ['mp4', 'mov', 'webm']) ? 'video' : 'image', 'alt_text' => $this->text($item['title'] ?? ''),
                'metadata' => ['created_at' => $item['creation_timestamp'] ?? null],
            ]);
        }
    }

    private function profile(ZipArchive $zip, SocialAccount $account, Archive $archive, array $profile): void
    {
        $photo = data_get($profile, 'media_map_data.Profile Photo');
        $path = null;
        if ($photo && $zip->locateName($photo['uri']) !== false) {
            $path = "profile-media/{$account->id}/avatar.".pathinfo($photo['uri'], PATHINFO_EXTENSION);
            $stream = $zip->getStream($photo['uri']);
            Storage::disk('public')->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }$account->update(['avatar_path' => $path]);
        }
        ProfileSnapshot::updateOrCreate(['archive_id' => $archive->id], ['bio' => $account->bio, 'website' => $account->website, 'avatar_path' => $path, 'metadata' => ['display_name' => $account->display_name]]);
    }

    private function likes(ZipArchive $zip, SocialAccount $account): void
    {
        foreach (['your_instagram_activity/likes/liked_posts.json' => 'likes_media_likes', 'your_instagram_activity/likes/liked_comments.json' => 'likes_comment_likes'] as $file => $key) {
            foreach (data_get($this->optionalJson($zip, $file), $key, []) as $item) {
                $data = data_get($item, 'string_list_data.0', []);
                $url = $data['href'] ?? null;
                $id = sha1(json_encode([$url, $data['timestamp'] ?? null, $key]));
                LikedPost::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $id], ['url' => $url, 'body' => $this->text($item['title'] ?? ''), 'metadata' => ['kind' => $key, 'timestamp' => $data['timestamp'] ?? null]]);
            }
        }
    }

    private function saved(ZipArchive $zip, SocialAccount $account): void
    {
        foreach (data_get($this->optionalJson($zip, 'your_instagram_activity/saved/saved_posts.json'), 'saved_saved_media', []) as $item) {
            $url = data_get($item, 'string_map_data.Saved on.href');
            $id = sha1($url);
            BookmarkedPost::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $id], ['url' => $url, 'kind' => 'saved_post']);
        }
    }

    private function connections(ZipArchive $zip, Archive $archive): void
    {
        foreach (['connections/followers_and_following/followers_1.json' => [null, 'follower'], 'connections/followers_and_following/following.json' => ['relationships_following', 'following']] as $file => [$key,$direction]) {
            $json = $this->optionalJson($zip, $file);
            $items = $key ? data_get($json, $key, []) : $json;
            foreach ($items as $item) {
                $data = data_get($item, 'string_list_data.0', []);
                $name = $data['value'] ?? basename($data['href'] ?? '');
                SocialConnection::firstOrCreate(['archive_id' => $archive->id, 'direction' => $direction, 'external_account_id' => $name], ['url' => $data['href'] ?? null]);
            }
        }
    }

    private function messages(ZipArchive $zip, SocialAccount $account): void
    {
        foreach ($this->matchingFiles($zip, '#^your_instagram_activity/messages/(?:inbox|message_requests)/.+/message_\d+\.json$#') as $file) {
            $thread = $this->json($zip, $file);
            foreach ($thread['messages'] ?? [] as $i => $message) {
                $sender = $this->text($message['sender_name'] ?? '');
                $content = $this->text($message['content'] ?? '');
                $timestamp = (int) (($message['timestamp_ms'] ?? 0) / 1000);
                $id = sha1(json_encode([$thread['thread_path'] ?? $file, $message['timestamp_ms'] ?? 0, $sender, $i]));
                DirectMessage::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $id], [
                    'thread_id' => $thread['thread_path'] ?? $file, 'direction' => $sender === $account->display_name ? 'sent' : 'received', 'sender' => $sender,
                    'recipient' => null, 'subject' => $this->text($thread['title'] ?? ''), 'body' => $content, 'sent_at' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
                    'metadata' => array_diff_key($message, array_flip(['sender_name', 'timestamp_ms', 'content'])),
                ]);
            }
        }
    }

    private function mediaId(array $record, string $type): string
    {
        return sha1(json_encode([$type, $record['creation_timestamp'] ?? null, $record['title'] ?? null, collect($record['media'] ?? [])->pluck('uri')->all()]));
    }

    private function text(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $fixed = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);

        return $fixed !== false && mb_check_encoding($fixed, 'UTF-8') ? $fixed : $value;
    }

    private function matchingFiles(ZipArchive $zip, string $pattern): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match($pattern, $n = $zip->getNameIndex($i))) {
                $files[] = $n;
            }
        }sort($files, SORT_NATURAL);

        return $files;
    }

    private function optionalJson(ZipArchive $zip, string $name): array
    {
        return $zip->locateName($name) === false ? [] : $this->json($zip, $name);
    }

    private function json(ZipArchive $zip, string $name): array
    {
        $data = $zip->getFromName($name);
        if ($data === false) {
            throw new InvalidArgumentException("Missing {$name}");
        }

        return json_decode($data, true, flags: JSON_THROW_ON_ERROR);
    }

    private function exportDate(string $path): ?string
    {
        return preg_match('/instagram-[^-]+-(\d{4}-\d{2}-\d{2})-/', basename($path), $m) ? $m[1] : null;
    }
}
