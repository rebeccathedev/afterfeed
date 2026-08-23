<?php

namespace App\Services\Import;

use App\Models\Archive;
use App\Models\Post;
use App\Models\ProfileSnapshot;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class LiveJournalArchiveImporter
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
            $entries = $this->entryFiles($zip);
            if ($entries === []) {
                throw new InvalidArgumentException('This does not look like a supported LiveJournal archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path, $entries));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path, array $entries): array
    {
        $username = explode('/', $entries[0], 2)[0];
        $account = SocialAccount::updateOrCreate(
            ['platform' => 'livejournal', 'external_id' => $username],
            ['handle' => '@'.$username, 'display_name' => $username, 'website' => "https://{$username}.livejournal.com"]
        );
        $archive = Archive::firstOrCreate(['fingerprint' => hash_file('sha256', $path)], [
            'social_account_id' => $account->id,
            'label' => basename($path),
            'imported_at' => now(),
            'status' => 'ready',
            'metadata' => ['source_path' => realpath($path), 'format' => 'livejournal-entry-text'],
        ]);
        $created = $archive->wasRecentlyCreated;
        ProfileSnapshot::updateOrCreate(['archive_id' => $archive->id], [
            'website' => $account->website,
            'metadata' => ['username' => $username],
        ]);

        foreach (array_chunk($entries, 250) as $chunk) {
            $rows = [];
            foreach ($chunk as $file) {
                $entry = $this->parse($zip->getFromName($file));
                $externalId = $entry['headers']['ItemID'] ?? pathinfo($file, PATHINFO_FILENAME);
                $subject = $entry['headers']['Subject'] ?? '';
                $plainBody = $this->plainText($entry['body']);
                $rows[] = [
                    'social_account_id' => $account->id,
                    'external_id' => (string) $externalId,
                    'type' => 'journal',
                    'body' => trim($subject.($subject && $plainBody ? "\n\n" : '').$plainBody),
                    'url' => "https://{$username}.livejournal.com/{$externalId}.html",
                    'posted_at' => $this->date($entry['headers']['Date'] ?? null),
                    'metadata' => json_encode(array_filter([
                        'subject' => $subject ?: null,
                        'tags' => $this->tags($entry['headers']['Tags'] ?? ''),
                        'picture_keyword' => ($entry['headers']['Picture'] ?? '') ?: null,
                        'body_html' => $entry['body'] ?: null,
                        'original_date' => ($entry['headers']['Date'] ?? '') ?: null,
                    ], fn ($value) => $value !== null && $value !== [])),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('posts')->upsert($rows, ['social_account_id', 'external_id'], ['type', 'body', 'url', 'posted_at', 'metadata', 'updated_at']);
            $posts = Post::where('social_account_id', $account->id)->whereIn('external_id', collect($rows)->pluck('external_id'))->pluck('id');
            DB::table('archive_post')->insertOrIgnore($posts->map(fn ($id) => ['archive_id' => $archive->id, 'post_id' => $id])->all());
        }

        return ['archive' => $archive, 'posts' => count($entries), 'created' => $created];
    }

    private function entryFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^[^/]+/\d{8}_\d{4}$#', $name)) {
                $sample = $zip->getFromIndex($i, 512);
                if (is_string($sample) && preg_match('/^Date:\s+.+\RSubject:\s*/', $sample)) {
                    $files[] = $name;
                }
            }
        }
        sort($files, SORT_NATURAL);

        return $files;
    }

    private function parse(string $contents): array
    {
        [$headerBlock, $body] = array_pad(preg_split('/\R\R/', $contents, 2), 2, '');
        $headers = [];
        foreach (preg_split('/\R/', $headerBlock) as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $match)) {
                $headers[trim($match[1])] = trim($match[2]);
            }
        }

        return ['headers' => $headers, 'body' => trim($body)];
    }

    private function plainText(string $html): string
    {
        $withBreaks = preg_replace('#<(?:br\s*/?|/p|/div|/li)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private function tags(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }

    private function date(?string $date): ?string
    {
        return $date ? CarbonImmutable::createFromFormat('Y-m-d H:i', $date, config('app.timezone'))->utc()->toDateTimeString() : null;
    }
}
