<?php

namespace App\Services\Import;

use App\Models\Archive;
use App\Models\BookmarkedPost;
use App\Models\DirectMessage;
use App\Models\LikedPost;
use App\Models\Post;
use App\Models\ProfileSnapshot;
use App\Models\SocialAccount;
use App\Models\SocialConnection;
use App\Models\SocialList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class RedditArchiveImporter
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
            if ($zip->locateName('posts.csv') === false || $zip->locateName('comments.csv') === false) {
                throw new InvalidArgumentException('This does not look like a supported Reddit archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path): array
    {
        $username = preg_match('/^export_(.+?)_\d{8}\.zip$/', basename($path), $m) ? $m[1] : pathinfo($path, PATHINFO_FILENAME);
        $account = SocialAccount::updateOrCreate(['platform' => 'reddit', 'external_id' => $username], ['handle' => 'u/'.$username, 'display_name' => $username]);
        $archive = Archive::firstOrCreate(['fingerprint' => hash_file('sha256', $path)], [
            'social_account_id' => $account->id, 'label' => basename($path), 'exported_at' => $this->exportDate($path), 'imported_at' => now(), 'status' => 'ready', 'metadata' => ['source_path' => realpath($path), 'format' => 'reddit-csv'],
        ]);
        $created = $archive->wasRecentlyCreated;
        ProfileSnapshot::updateOrCreate(['archive_id' => $archive->id], ['metadata' => ['username' => $username]]);
        $count = $this->posts($zip, $account, $archive, 'posts.csv', 'post') + $this->posts($zip, $account, $archive, 'comments.csv', 'comment');
        $this->votes($zip, $account, 'post_votes.csv', 'post');
        $this->votes($zip, $account, 'comment_votes.csv', 'comment');
        $this->bookmarks($zip, $account, 'saved_posts.csv', 'saved_post');
        $this->bookmarks($zip, $account, 'saved_comments.csv', 'saved_comment');
        $this->bookmarks($zip, $account, 'hidden_posts.csv', 'hidden_post');
        $this->communities($zip, $archive, 'subscribed_subreddits.csv', 'subscribed');
        $this->communities($zip, $archive, 'moderated_subreddits.csv', 'moderated');
        $this->lists($zip, $archive);
        $this->messages($zip, $account);

        return ['archive' => $archive, 'posts' => $count, 'created' => $created];
    }

    private function posts(ZipArchive $zip, SocialAccount $account, Archive $archive, string $file, string $type): int
    {
        $count = 0;
        foreach ($this->csv($zip, $file) as $row) {
            $body = $type === 'post' ? trim(($row['title'] ?? '').(! empty($row['body']) ? "\n\n{$row['body']}" : '')) : $row['body'];
            $post = Post::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $row['id']], [
                'type' => $type, 'body' => $body, 'url' => $row['permalink'], 'posted_at' => $this->date($row['date']),
                'reply_to_external_id' => $type === 'comment' ? ($row['parent'] ?: null) : null,
                'metadata' => array_filter(['subreddit' => $row['subreddit'] ?? null, 'gildings' => (int) ($row['gildings'] ?? 0), 'link' => $row['link'] ?? null, 'media' => $row['media'] ?? null, 'external_url' => $type === 'post' ? ($row['url'] ?? null) : null], fn ($v) => $v !== null && $v !== ''),
            ]);
            $archive->posts()->syncWithoutDetaching($post->id);
            $count++;
        }

        return $count;
    }

    private function votes(ZipArchive $zip, SocialAccount $account, string $file, string $kind): void
    {
        foreach ($this->csv($zip, $file) as $r) {
            LikedPost::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $r['id']], ['url' => $r['permalink'], 'body' => $r['direction'], 'metadata' => ['direction' => $r['direction'], 'kind' => $kind]]);
        }
    }

    private function bookmarks(ZipArchive $zip, SocialAccount $account, string $file, string $kind): void
    {
        foreach ($this->csv($zip, $file) as $r) {
            BookmarkedPost::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $r['id']], ['url' => $r['permalink'], 'kind' => $kind]);
        }
    }

    private function communities(ZipArchive $zip, Archive $archive, string $file, string $direction): void
    {
        foreach ($this->csv($zip, $file) as $r) {
            $name = $r['subreddit'];
            SocialConnection::firstOrCreate(['archive_id' => $archive->id, 'direction' => $direction, 'external_account_id' => $name], ['url' => 'https://www.reddit.com/r/'.$name]);
        }
    }

    private function lists(ZipArchive $zip, Archive $archive): void
    {
        foreach ($this->csv($zip, 'multireddits.csv') as $r) {
            SocialList::updateOrCreate(['archive_id' => $archive->id, 'url' => 'https://www.reddit.com/user/me/m/'.$r['display_name']], ['name' => $r['display_name'], 'metadata' => ['privacy' => $r['privacy'], 'subreddits' => explode('+', $r['subreddits'])]]);
        }
    }

    private function messages(ZipArchive $zip, SocialAccount $account): void
    {
        foreach ($this->csv($zip, 'messages.csv') as $r) {
            DirectMessage::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $r['id']], [
                'thread_id' => $r['thread_id'], 'direction' => $r['from'] === 'u/'.$account->external_id ? 'sent' : 'received', 'sender' => $r['from'], 'recipient' => $r['to'], 'subject' => $r['subject'], 'body' => $r['body'], 'url' => $r['permalink'], 'sent_at' => $this->date($r['date']),
            ]);
        }
    }

    private function csv(ZipArchive $zip, string $name): \Generator
    {
        if ($zip->locateName($name) === false) {
            return;
        } $stream = $zip->getStream($name);
        $headers = fgetcsv($stream, escape: '');
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            $values = array_pad($values, count($headers), null);
            yield array_combine($headers, array_slice($values, 0, count($headers)));
        } fclose($stream);
    }

    private function date(?string $date): ?string
    {
        return $date ? CarbonImmutable::parse($date)->utc()->toDateTimeString() : null;
    }

    private function exportDate(string $path): ?string
    {
        return preg_match('/_(\d{8})\.zip$/',basename($path),$m) ? CarbonImmutable::createFromFormat('Ymd',$m[1],'UTC') : null;
    }
}
