<?php

namespace App\Services\Import;

use App\Models\Archive;
use App\Models\Attachment;
use App\Models\LikedPost;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class GooglePlusArchiveImporter
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
            $postFiles = $this->postFiles($zip);
            if ($postFiles === []) {
                throw new InvalidArgumentException('This does not look like a supported Google+ Takeout archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path, $postFiles));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path, array $postFiles): array
    {
        $first = $this->parsePost($zip, $postFiles[0]);
        $profileUrl = $first['author_url'];
        $externalId = trim((string) parse_url($profileUrl, PHP_URL_PATH), '/') ?: sha1($first['author_name']);
        $handle = str_starts_with($externalId, '+') ? $externalId : '@'.$externalId;
        $account = SocialAccount::updateOrCreate(
            ['platform' => 'google_plus', 'external_id' => $externalId],
            ['handle' => $handle, 'display_name' => $first['author_name'] ?: $handle, 'website' => $profileUrl, 'metadata' => ['avatar_url' => $first['author_image']]]
        );
        $archive = Archive::firstOrCreate(['fingerprint' => hash_file('sha256', $path)], [
            'social_account_id' => $account->id, 'label' => basename($path), 'imported_at' => now()->utc(), 'status' => 'ready',
            'metadata' => ['source_path' => realpath($path), 'format' => 'google-plus-takeout-html'],
        ]);
        $created = $archive->wasRecentlyCreated;

        foreach (array_chunk($postFiles, 100) as $files) {
            $records = collect($files)->map(fn (string $file) => $this->parsePost($zip, $file));
            $now = now()->utc();
            $rows = $records->map(fn (array $record) => [
                'social_account_id' => $account->id, 'external_id' => $record['external_id'], 'type' => $record['type'],
                'body' => $record['body'], 'url' => $record['url'], 'posted_at' => $record['posted_at'],
                'metadata' => json_encode($record['metadata'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
            ])->all();
            DB::table('posts')->upsert($rows, ['social_account_id', 'external_id'], ['type', 'body', 'url', 'posted_at', 'metadata', 'updated_at']);
            $posts = Post::where('social_account_id', $account->id)->whereIn('external_id', $records->pluck('external_id'))->get()->keyBy('external_id');
            DB::table('archive_post')->insertOrIgnore($posts->map(fn (Post $post) => ['archive_id' => $archive->id, 'post_id' => $post->id])->values()->all());
            foreach ($records as $record) {
                $this->attachments($zip, $posts[$record['external_id']], $record['media']);
            }
        }

        $commentCount = $this->activityComments($zip, $account, $archive);
        $this->activityLikes($zip, $account);

        return ['archive' => $archive, 'posts' => count($postFiles) + $commentCount, 'created' => $created];
    }

    private function activityComments(ZipArchive $zip, SocialAccount $account, Archive $archive): int
    {
        $records = $this->activityRecords($zip, 'Takeout/Google+ Stream/ActivityLog/Comments.html');
        foreach (array_chunk($records, 100) as $chunk) {
            $now = now()->utc();
            $rows = collect($chunk)->map(fn (array $record) => [
                'social_account_id' => $account->id, 'external_id' => $record['external_id'], 'type' => 'comment',
                'body' => $record['body'], 'url' => $record['url'], 'posted_at' => $record['posted_at'],
                'metadata' => json_encode(['title' => $record['title'], 'activity_log' => true], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ])->all();
            DB::table('posts')->upsert($rows, ['social_account_id', 'external_id'], ['type', 'body', 'url', 'posted_at', 'metadata', 'updated_at']);
            $posts = Post::where('social_account_id', $account->id)->whereIn('external_id', collect($chunk)->pluck('external_id'))->get();
            DB::table('archive_post')->insertOrIgnore($posts->map(fn (Post $post) => ['archive_id' => $archive->id, 'post_id' => $post->id])->all());
        }

        return count($records);
    }

    private function activityLikes(ZipArchive $zip, SocialAccount $account): void
    {
        foreach (['+1s on posts.html' => 'post', '+1s on comments.html' => 'comment'] as $filename => $kind) {
            foreach ($this->activityRecords($zip, 'Takeout/Google+ Stream/ActivityLog/'.$filename) as $record) {
                LikedPost::updateOrCreate(
                    ['social_account_id' => $account->id, 'external_id' => $record['external_id']],
                    ['url' => $record['url'], 'body' => $record['body'], 'metadata' => ['title' => $record['title'], 'kind' => $kind, 'liked_at' => $record['posted_at']]]
                );
            }
        }
    }

    private function activityRecords(ZipArchive $zip, string $file): array
    {
        $html = $zip->getFromName($file);
        if ($html === false) {
            return [];
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $records = [];
        foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' item ')]") as $item) {
            $titleNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' item-title ')][1]", $item)->item(0);
            $timeNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' time ')][1]", $item)->item(0);
            $textNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' text ')][1]", $item)->item(0);
            if (! $titleNode instanceof DOMElement || ! $timeNode instanceof DOMElement || ! $textNode) {
                continue;
            }
            $url = $titleNode->getAttribute('href');
            $postedAt = $this->utcDate($timeNode->getAttribute('title'));
            $body = collect(iterator_to_array($textNode->childNodes))->filter(fn (DOMNode $node) => $node->nodeType === XML_TEXT_NODE)->map(fn (DOMNode $node) => $node->textContent)->implode(' ');
            $body = trim(preg_replace('/\s+/u', ' ', html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $title = $this->text($titleNode);
            $records[] = [
                'external_id' => sha1(json_encode([$file, $url, $postedAt, $body], JSON_THROW_ON_ERROR)),
                'url' => $url ?: null, 'title' => $title ?: null, 'body' => $body ?: $title, 'posted_at' => $postedAt,
            ];
        }

        return $records;
    }

    private function parsePost(ZipArchive $zip, string $file): array
    {
        $html = $zip->getFromName($file);
        if ($html === false) {
            throw new InvalidArgumentException("Missing {$file}");
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $page = $xpath->query('//body')->item(0);
        $url = $page instanceof DOMElement ? $page->getAttribute('itemid') : null;
        $author = $xpath->query("//body/*[1]//*[@itemprop='author'][1]")->item(0);
        $authorName = $this->text($xpath->query(".//*[@itemprop='name'][1]", $author)->item(0));
        $authorLink = $xpath->query(".//*[@itemprop='url'][1]", $author)->item(0);
        $authorUrl = $authorLink instanceof DOMElement ? $authorLink->getAttribute('href') : null;
        $authorImage = $xpath->query(".//*[@itemprop='image'][1]", $author)->item(0);
        $created = $this->text($xpath->query("//body/*[1]//*[@itemprop='dateCreated'][1]")->item(0));
        $bodyText = $this->text($xpath->query("//*[@class='main-content']//*[@itemprop='text'][1]")->item(0));
        $reshare = $this->text($xpath->query("//*[@class='reshare-attribution'][1]")->item(0));
        $link = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' link-embed ')][1]")->item(0);
        $externalUrl = $link instanceof DOMElement ? $link->getAttribute('href') : null;
        $externalName = $this->text($xpath->query('.//h3[1]', $link)->item(0));
        $location = $this->location($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' location ')][1]")->item(0));
        $visibility = preg_replace('/^Shared with:\s*/i', '', $this->text($xpath->query("//*[@class='visibility'][1]")->item(0)));
        $media = [];
        foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' media-link ') and not(ancestor::*[@itemtype='http://schema.org/Comment'])]") as $node) {
            if (! $node instanceof DOMElement || ! $node->hasAttribute('href')) {
                continue;
            }
            $internal = $this->resolveInternalPath($file, $node->getAttribute('href'));
            if ($internal && $zip->locateName($internal) !== false) {
                $image = $xpath->query('.//*[@alt][1]', $node)->item(0);
                $media[$internal] = $image instanceof DOMElement ? $image->getAttribute('alt') : null;
            }
        }
        $comments = [];
        foreach ($xpath->query("//*[@itemtype='http://schema.org/Comment']") as $comment) {
            $commentAuthor = $xpath->query(".//*[@itemprop='author'][1]", $comment)->item(0);
            $commentLink = $xpath->query(".//*[@itemprop='url'][1]", $commentAuthor)->item(0);
            $comments[] = array_filter([
                'author' => $this->text($xpath->query(".//*[@itemprop='name'][1]", $commentAuthor)->item(0)),
                'author_url' => $commentLink instanceof DOMElement ? $commentLink->getAttribute('href') : null,
                'posted_at' => $this->utcDate($this->text($xpath->query(".//*[@itemprop='dateCreated'][1]", $comment)->item(0))),
                'body' => $this->text($xpath->query(".//*[@itemprop='text'][1]", $comment)->item(0)),
            ]);
        }
        $identity = $url ?: $file;

        return [
            'external_id' => basename(parse_url($identity, PHP_URL_PATH) ?: '') ?: sha1($identity),
            'type' => $reshare !== '' ? 'reshare' : 'post', 'body' => $bodyText ?: ($location['name'] ?? $reshare ?: null),
            'url' => $url ?: null, 'posted_at' => $this->utcDate($created), 'author_name' => $authorName,
            'author_url' => $authorUrl, 'author_image' => $authorImage instanceof DOMElement ? $authorImage->getAttribute('src') : null, 'media' => $media,
            'metadata' => array_filter([
                'external_url' => $externalUrl ?: null, 'external_name' => $externalName ?: null, 'location' => $location,
                'visibility' => $visibility ?: null, 'comments' => $comments ?: null, 'comment_count' => count($comments),
                'reshare_attribution' => $reshare ?: null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function attachments(ZipArchive $zip, Post $post, array $media): void
    {
        foreach ($media as $internal => $altText) {
            $path = "archive-media/{$post->social_account_id}/google-plus/".sha1($internal).'-'.basename($internal);
            if (! Storage::disk('public')->exists($path)) {
                $stream = $zip->getStream($internal);
                if ($stream === false) {
                    continue;
                }
                Storage::disk('public')->put($path, $stream);
                fclose($stream);
            }
            $extension = strtolower(pathinfo($internal, PATHINFO_EXTENSION));
            Attachment::updateOrCreate(['post_id' => $post->id, 'path' => $path], [
                'type' => in_array($extension, ['mp4', 'mov', 'webm', 'm4v']) ? 'video' : 'image',
                'alt_text' => $altText ?: null, 'metadata' => ['source_path' => $internal],
            ]);
        }
    }

    private function location(?DOMNode $node): ?array
    {
        if (! $node instanceof DOMElement) {
            return null;
        }
        [$name, $address] = array_pad(preg_split('/\s*Address:\s*/i', $this->text($node), 2), 2, null);
        $location = array_filter(['name' => $name ?: null, 'address' => $address ?: null]);
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $node->getAttribute('title'), $matches)) {
            $location['latitude'] = (float) $matches[1];
            $location['longitude'] = (float) $matches[2];
        }

        return $location ?: null;
    }

    private function resolveInternalPath(string $postFile, string $href): ?string
    {
        $href = rawurldecode(parse_url(html_entity_decode($href), PHP_URL_PATH) ?: '');
        if ($href === '' || str_starts_with($href, '/')) {
            return null;
        }
        $resolved = [];
        foreach (explode('/', dirname($postFile).'/'.$href) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $part === '..' ? array_pop($resolved) : $resolved[] = $part;
        }
        $path = implode('/', $resolved);

        return str_starts_with($path, 'Takeout/Google+ Stream/Photos/') ? $path : null;
    }

    private function postFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^Takeout/Google\+ Stream/Posts/.+\.html$#', $name)) {
                $files[] = $name;
            }
        }
        sort($files, SORT_NATURAL);

        return $files;
    }

    private function text(?DOMNode $node): string
    {
        return $node ? trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '';
    }

    private function utcDate(?string $date): ?string
    {
        return $date ? CarbonImmutable::parse($date)->utc()->toDateTimeString() : null;
    }
}
