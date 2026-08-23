<?php

namespace App\Services\Import;

use App\Models\Archive;
use App\Models\Attachment;
use App\Models\BookmarkedPost;
use App\Models\LikedPost;
use App\Models\Post;
use App\Models\ProfileSnapshot;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class MastodonArchiveImporter
{
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
            $actor = $this->json($zip, 'actor.json');
            $outbox = $this->json($zip, 'outbox.json');
            if (($actor['type'] ?? null) !== 'Person' || ($outbox['type'] ?? null) !== 'OrderedCollection') {
                throw new InvalidArgumentException('This does not look like a supported Mastodon archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path, $actor, $outbox));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path, array $actor, array $outbox): array
    {
        $host = parse_url($actor['id'], PHP_URL_HOST);
        $handle = '@'.$actor['preferredUsername'].'@'.$host;
        $account = SocialAccount::updateOrCreate(
            ['platform' => 'mastodon', 'external_id' => $actor['id']],
            ['handle' => $handle, 'display_name' => $actor['name'] ?: $actor['preferredUsername']]
        );
        $fingerprint = hash_file('sha256', $path);
        $archive = Archive::firstOrCreate(['fingerprint' => $fingerprint], [
            'social_account_id' => $account->id, 'label' => basename($path), 'exported_at' => $this->exportDate($path),
            'imported_at' => now(), 'status' => 'ready',
            'metadata' => ['source_path' => realpath($path), 'activitypub_actor' => $actor['id'], 'instance' => $host],
        ]);
        $created = $archive->wasRecentlyCreated;
        $this->profile($zip, $account, $archive, $actor);
        $account->posts()->where('type', 'boost')->where('external_id', 'activity')->delete();

        $count = 0;
        foreach ($outbox['orderedItems'] ?? [] as $activity) {
            if (($activity['type'] ?? null) === 'Announce') {
                $url = is_string($activity['object'] ?? null) ? $activity['object'] : data_get($activity, 'object.id');
                $post = Post::updateOrCreate(
                    ['social_account_id' => $account->id, 'external_id' => 'boost-'.basename($url)],
                    ['type' => 'boost', 'body' => null, 'url' => $url, 'posted_at' => $activity['published'], 'metadata' => ['activity_id' => $activity['id'], 'boosted_account' => $this->federatedHandle($url)]]
                );
            } elseif (($activity['type'] ?? null) === 'Create' && is_array($activity['object'] ?? null)) {
                $object = $activity['object'];
                $post = Post::updateOrCreate(
                    ['social_account_id' => $account->id, 'external_id' => basename($object['id'])],
                    [
                        'type' => empty($object['inReplyTo']) ? 'post' : 'reply', 'body' => $this->plainText($object['content'] ?? ''),
                        'url' => $object['url'] ?? $object['id'], 'posted_at' => $object['published'] ?? $activity['published'],
                        'reply_to_external_id' => isset($object['inReplyTo']) ? basename($object['inReplyTo']) : null,
                        'metadata' => [
                            'content_html' => $object['content'] ?? null, 'content_warning' => $object['summary'] ?? null,
                            'sensitive' => (bool) ($object['sensitive'] ?? false), 'conversation' => $object['conversation'] ?? null,
                            'favorite_count' => (int) data_get($object, 'likes.totalItems', 0),
                            'retweet_count' => (int) data_get($object, 'shares.totalItems', 0), 'tags' => $object['tag'] ?? [],
                        ],
                    ]
                );
                $this->attachments($zip, $post, $object['attachment'] ?? []);
            } else {
                continue;
            }
            $archive->posts()->syncWithoutDetaching($post->id);
            $count++;
        }
        $this->collections($zip, $account);

        return ['archive' => $archive, 'posts' => $count, 'created' => $created];
    }

    private function profile(ZipArchive $zip, SocialAccount $account, Archive $archive, array $actor): void
    {
        $fields = collect($actor['attachment'] ?? [])->mapWithKeys(fn ($field) => [$field['name'] => $this->plainText($field['value'] ?? '')]);
        $avatar = $this->extract($zip, 'avatar.jpg', "profile-media/{$account->id}/avatar.jpg");
        $headerName = $zip->locateName('header.jpeg') !== false ? 'header.jpeg' : 'header.jpg';
        $header = $this->extract($zip, $headerName, "profile-media/{$account->id}/header.".pathinfo($headerName, PATHINFO_EXTENSION));
        $account->update([
            'bio' => $this->plainText($actor['summary'] ?? ''), 'website' => $fields->get('Website'),
            'location' => $fields->get('Location'), 'avatar_path' => $avatar, 'header_path' => $header,
            'metadata' => ['profile_fields' => $fields, 'moved_to' => $actor['movedTo'] ?? null, 'also_known_as' => $actor['alsoKnownAs'] ?? []],
        ]);
        ProfileSnapshot::updateOrCreate(['archive_id' => $archive->id], [
            'bio' => $account->bio, 'website' => $account->website, 'location' => $account->location,
            'avatar_path' => $avatar, 'header_path' => $header, 'metadata' => ['fields' => $fields],
        ]);
    }

    private function attachments(ZipArchive $zip, Post $post, array $items): void
    {
        foreach ($items as $item) {
            $source = ltrim($item['url'], '/');
            $parts = explode('/', $source, 2);
            $source = str_starts_with($parts[1] ?? '', 'media_attachments/') ? $parts[1] : $source;
            if ($zip->locateName($source) === false) {
                continue;
            }
            $filename = sha1($item['url']).'-'.basename($source);
            $path = "archive-media/{$post->social_account_id}/mastodon/{$filename}";
            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, $zip->getFromName($source));
            }
            $mediaType = $item['mediaType'] ?? '';
            $type = str_starts_with($mediaType, 'video/') ? 'video' : (str_starts_with($mediaType, 'audio/') ? 'audio' : 'image');
            Attachment::updateOrCreate(['post_id' => $post->id, 'path' => $path], [
                'type' => $type, 'alt_text' => $item['name'] ?? null,
                'metadata' => ['media_type' => $mediaType, 'width' => $item['width'] ?? null, 'height' => $item['height'] ?? null, 'blurhash' => $item['blurhash'] ?? null],
            ]);
        }
    }

    private function collections(ZipArchive $zip, SocialAccount $account): void
    {
        foreach (['likes.json' => LikedPost::class, 'bookmarks.json' => BookmarkedPost::class] as $file => $model) {
            if ($zip->locateName($file) === false) {
                continue;
            }
            foreach ($this->json($zip, $file)['orderedItems'] ?? [] as $url) {
                $model::updateOrCreate(
                    ['social_account_id' => $account->id, 'external_id' => basename($url)],
                    $model === LikedPost::class ? ['url' => $url] : ['url' => $url]
                );
            }
        }
    }

    private function extract(ZipArchive $zip, string $source, string $path): ?string
    {
        if ($zip->locateName($source) === false) {
            return null;
        }
        Storage::disk('public')->put($path, $zip->getFromName($source));

        return $path;
    }

    private function plainText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function federatedHandle(?string $url): ?string
    {
        $host = parse_url($url ?? '', PHP_URL_HOST);
        $path = parse_url($url ?? '', PHP_URL_PATH) ?: '';
        if (! $host || ! preg_match('#/(?:users|@|p)/([^/]+)(?:/statuses)?/#', $path.'/', $match)) {
            return null;
        }

        return '@'.$match[1].'@'.preg_replace('/^www\./i', '', $host);
    }

    private function exportDate(string $path): ?string
    {
        return preg_match('/archive-(\d{14})/', basename($path), $match)
            ? CarbonImmutable::createFromFormat('YmdHis', $match[1], 'UTC') : null;
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
