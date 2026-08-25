<?php

namespace App\Services\Import;

use App\Models\Archive;
use App\Models\DirectMessage;
use App\Models\LikedPost;
use App\Models\Post;
use App\Models\ProfileSnapshot;
use App\Models\SocialAccount;
use App\Services\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class NextdoorArchiveImporter
{
    private const PROFILE = 'Profile Information.csv';

    public function __construct(private readonly AppSettings $settings) {}

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
            if ($zip->locateName(self::PROFILE) === false || $zip->locateName('Posts.csv') === false || $zip->locateName('Comments.csv') === false) {
                throw new InvalidArgumentException('This does not look like a supported Nextdoor archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path): array
    {
        $profile = $this->csv($zip, self::PROFILE)->first() ?? [];
        $displayName = trim(implode(' ', array_filter([$profile['First name'] ?? null, $profile['Last name'] ?? null]))) ?: 'Nextdoor member';
        $identity = mb_strtolower(trim($profile['Email'] ?? '')) ?: $displayName;
        $account = SocialAccount::updateOrCreate(['platform' => 'nextdoor', 'external_id' => hash('sha256', $identity)], [
            'handle' => 'Nextdoor', 'display_name' => $displayName, 'bio' => $profile['Bio'] ?: null,
            'location' => $profile['Home town'] ?: null,
            'metadata' => array_filter([
                'pronouns' => $profile['Pronouns'] ?? null, 'occupation' => $profile['Occupation'] ?? null,
                'interests' => $profile['Interests'] ?? null, 'skills' => $profile['Skills'] ?? null,
                'neighborhood_note' => $profile['What I love about my neighborhood'] ?? null,
                'pets' => $profile['Pets'] ?? null, 'joined_at' => $profile['Date joined'] ?? null,
                'verification_status' => $profile['Verification status'] ?? null,
                'lead_status' => $profile['Lead status'] ?? null, 'founding_status' => $profile['Founding status'] ?? null,
                'profile_photo_url' => $profile['Profile photo'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
        $archive = Archive::firstOrCreate(['fingerprint' => hash_file('sha256', $path)], [
            'social_account_id' => $account->id, 'label' => basename($path), 'imported_at' => now()->utc(),
            'status' => 'ready', 'metadata' => ['source_path' => realpath($path), 'format' => 'nextdoor-content-activity-csv'],
        ]);
        $created = $archive->wasRecentlyCreated;
        $archive->update(['social_account_id' => $account->id, 'label' => basename($path), 'imported_at' => now()->utc(), 'status' => 'ready']);
        ProfileSnapshot::updateOrCreate(['archive_id' => $archive->id], [
            'bio' => $account->bio, 'location' => $account->location,
            'metadata' => ['display_name' => $displayName, 'profile_photo_url' => $profile['Profile photo'] ?? null],
        ]);

        $count = $this->posts($zip, $account, $archive, 'Posts.csv', 'post');
        $count += $this->posts($zip, $account, $archive, 'Comments.csv', 'comment');
        $count += $this->posts($zip, $account, $archive, 'FS&F Listings.csv', 'listing');
        $count += $this->posts($zip, $account, $archive, 'Seasonal Activities.csv', 'activity');
        $this->reactions($zip, $account);
        $this->messages($zip, $account, $displayName);

        return ['archive' => $archive, 'posts' => $count, 'created' => $created];
    }

    private function posts(ZipArchive $zip, SocialAccount $account, Archive $archive, string $file, string $type): int
    {
        $rows = $this->csv($zip, $file);
        foreach ($rows as $row) {
            $subject = $row['Subject'] ?? $row['Post subject'] ?? $row['Map activity'] ?? null;
            $body = $row['Body'] ?? $row['Response text'] ?? null;
            $content = trim(($subject ?? '').($body && $body !== $subject ? ($subject ? "\n\n" : '').$body : '')) ?: null;
            $date = $row['Creation date'] ?? null;
            $externalId = sha1(json_encode([$file, $date, $subject, $body], JSON_THROW_ON_ERROR));
            $post = Post::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $externalId], [
                'type' => $type, 'body' => $content, 'posted_at' => $this->date($date),
                'metadata' => array_filter([
                    'subject' => $subject, 'message_type' => $row['Message type'] ?? null,
                    'scope' => $row['Scope'] ?? null, 'status' => $row['Status'] ?? null,
                    'visible' => isset($row['Currently visible on Nextdoor']) ? $row['Currently visible on Nextdoor'] === 'Yes' : null,
                    'media_urls' => $this->mediaUrls($row['Media urls'] ?? null),
                ], fn ($value) => $value !== null && $value !== '' && $value !== []),
            ]);
            $archive->posts()->syncWithoutDetaching($post->id);
        }

        return $rows->count();
    }

    private function reactions(ZipArchive $zip, SocialAccount $account): void
    {
        foreach ($this->csv($zip, 'Reactions.csv') as $row) {
            $id = sha1(json_encode([$row['Type'], $row['Post subject'], $row['Reaction type'], $row['Creation date']], JSON_THROW_ON_ERROR));
            LikedPost::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $id], [
                'body' => $row['Post subject'] ?: null,
                'metadata' => ['reaction' => $row['Reaction type'], 'kind' => mb_strtolower($row['Type']), 'reacted_at' => $this->date($row['Creation date']), 'visible' => $row['Currently visible on Nextdoor'] === 'Yes'],
            ]);
        }
    }

    private function messages(ZipArchive $zip, SocialAccount $account, string $displayName): void
    {
        foreach ($this->csv($zip, 'Private Messages.csv') as $row) {
            $id = sha1(json_encode([$row['Subject'], $row['Message'], $row['Sender'], $row['Creation date']], JSON_THROW_ON_ERROR));
            DirectMessage::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $id], [
                'thread_id' => sha1($row['Subject'] ?: 'Nextdoor messages'),
                'direction' => strcasecmp(trim($row['Sender']), $displayName) === 0 ? 'sent' : 'received',
                'sender' => $row['Sender'] ?: null, 'subject' => $row['Subject'] ?: null,
                'body' => $row['Message'] ?: null, 'sent_at' => $this->date($row['Creation date']),
                'metadata' => ['status' => $row['Status'] ?? null],
            ]);
        }
    }

    private function csv(ZipArchive $zip, string $name): Collection
    {
        if ($zip->locateName($name) === false) {
            return collect();
        }
        $stream = $zip->getStream($name);
        $headers = fgetcsv($stream, escape: '');
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $rows = [];
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            $values = array_pad($values, count($headers), null);
            $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
        }
        fclose($stream);

        return collect($rows);
    }

    private function mediaUrls(?string $value): array
    {
        preg_match_all('#https?://[^\s\]\'\"]+#i', $value ?? '', $matches);

        return array_values(array_unique($matches[0]));
    }

    private function date(?string $date): ?string
    {
        if (! $date) {
            return null;
        }
        if (preg_match('/^[A-Z][a-z]{2} \d{2}, \d{4} - /', $date)) {
            return CarbonImmutable::createFromFormat('M d, Y - g:i:s A', $date, $this->timezone())->utc()->toDateTimeString();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [AP]M$/', $date)) {
            return CarbonImmutable::createFromFormat('Y-m-d h:i:s A', $date, $this->timezone())->utc()->toDateTimeString();
        }

        return CarbonImmutable::parse($date)->utc()->toDateTimeString();
    }

    private function timezone(): string
    {
        return $this->settings->get('timezone', config('app.timezone', 'UTC'));
    }
}
