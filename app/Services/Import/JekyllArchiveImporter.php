<?php

namespace App\Services\Import;

use App\Models\Archive;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class JekyllArchiveImporter
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
                throw new InvalidArgumentException('This does not look like a supported Jekyll site archive.');
            }

            return DB::transaction(fn () => $this->persist($zip, $path, $postFiles));
        } finally {
            $zip->close();
        }
    }

    private function persist(ZipArchive $zip, string $path, array $postFiles): array
    {
        $contentRoot = substr($postFiles[0], 0, strpos($postFiles[0], '_posts/'));
        $config = $this->siteConfig($zip, $contentRoot);
        $author = $this->yamlFile($zip, $contentRoot.'_data/author.yaml') ?: $this->yamlFile($zip, $contentRoot.'_data/author.yml');
        $displayName = trim((string) ($author['name'] ?? $config['name'] ?? 'Jekyll author'));
        $website = rtrim((string) ($config['url'] ?? ''), '/');
        $host = mb_strtolower(preg_replace('/^www\./i', '', parse_url($website, PHP_URL_HOST) ?: ''));
        $externalId = $host ?: hash('sha256', $displayName.'|'.$website);

        $account = SocialAccount::updateOrCreate(['platform' => 'jekyll', 'external_id' => $externalId], [
            'handle' => $host ?: $displayName, 'display_name' => $displayName, 'website' => $website ?: null,
            'location' => $author['location'] ?? null,
            'bio' => $this->plainText((string) ($config['description'] ?? $config['tagline'] ?? '')) ?: null,
            'metadata' => array_filter([
                'site_name' => $config['name'] ?? null, 'permalink' => $config['permalink'] ?? null,
                'author_email' => $author['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
        $this->profileImage($zip, $account, $contentRoot, $author['avatar'] ?? null);

        $archive = Archive::firstOrCreate(['fingerprint' => hash_file('sha256', $path)], [
            'social_account_id' => $account->id, 'label' => basename($path), 'imported_at' => now()->utc(),
            'status' => 'ready', 'metadata' => ['source_path' => realpath($path), 'format' => 'jekyll-site-zip'],
        ]);
        $created = $archive->wasRecentlyCreated;
        $archive->update(['social_account_id' => $account->id, 'label' => basename($path), 'imported_at' => now()->utc(), 'status' => 'ready']);

        foreach ($postFiles as $file) {
            $record = $this->post($zip, $file, $contentRoot, $config, $website);
            $post = Post::updateOrCreate(['social_account_id' => $account->id, 'external_id' => $record['external_id']], [
                'type' => 'article', 'body' => $record['body'], 'url' => $record['url'],
                'posted_at' => $record['posted_at'], 'metadata' => $record['metadata'],
            ]);
            $archive->posts()->syncWithoutDetaching($post->id);
            $this->attachments($zip, $post, $contentRoot, $record['media']);
        }

        return ['archive' => $archive, 'posts' => count($postFiles), 'created' => $created];
    }

    private function post(ZipArchive $zip, string $file, string $contentRoot, array $config, string $website): array
    {
        $source = $zip->getFromName($file);
        if ($source === false || ! preg_match('/\A---\s*\R(.*?)\R---\s*\R?(.*)\z/s', $source, $matches)) {
            throw new InvalidArgumentException("Jekyll post has invalid front matter: {$file}");
        }
        $frontMatter = $this->parseYaml($matches[1]);
        $markdown = trim($matches[2]);
        preg_match('#_posts/(\d{4})-(\d{2})-(\d{2})-(.+?)\.(?:md|markdown|mkd|mdown)$#i', $file, $filename);
        $slug = trim((string) ($frontMatter['slug'] ?? ($filename[4] ?? 'post')));
        $date = $frontMatter['date'] ?? implode('-', array_slice($filename, 1, 3));
        $postedAt = match (true) {
            $date instanceof \DateTimeInterface => CarbonImmutable::instance($date)->utc(),
            is_int($date) || (is_string($date) && ctype_digit($date)) => CarbonImmutable::createFromTimestampUTC((int) $date),
            default => CarbonImmutable::parse((string) $date, config('app.timezone', 'UTC'))->utc(),
        };
        $title = trim((string) ($frontMatter['title'] ?? Str::headline($slug)));
        $text = $this->plainText($markdown);
        $permalink = (string) ($frontMatter['permalink'] ?? $config['permalink'] ?? '/:year/:month/:title/');
        $urlPath = strtr($permalink, [
            ':year' => $postedAt->format('Y'), ':month' => $postedAt->format('m'), ':day' => $postedAt->format('d'),
            ':title' => $slug, ':slug' => $slug, ':categories' => $this->categoriesPath($frontMatter['categories'] ?? []),
        ]);
        $relative = Str::after($file, $contentRoot);

        return [
            'external_id' => sha1($relative), 'body' => trim($title.($text !== '' ? "\n\n".$text : '')) ?: null,
            'url' => $website !== '' ? $website.'/'.ltrim($urlPath, '/') : null,
            'posted_at' => $postedAt->toDateTimeString(), 'media' => $this->mediaReferences($markdown),
            'metadata' => array_filter([
                'title' => $title ?: null, 'subtitle' => $frontMatter['subtitle'] ?? null,
                'author' => $frontMatter['author'] ?? null, 'slug' => $slug,
                'tags' => $this->stringList($frontMatter['tags'] ?? []),
                'categories' => $this->stringList($frontMatter['categories'] ?? []),
                'source_path' => $relative, 'source_markdown' => $markdown, 'front_matter' => $frontMatter,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []),
        ];
    }

    private function plainText(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }
        $markdown = preg_replace('/<!--\s*more\s*-->/i', '', $markdown);
        $markdown = preg_replace('/{%\s*highlight\s+([^%]+)%}/', '```$1', $markdown);
        $markdown = preg_replace('/{%\s*endhighlight\s*%}/', '```', $markdown);
        $markdown = preg_replace('/{%.*?%}|{{.*?}}/s', '', $markdown);
        $markdown = preg_replace('/!\[([^]]*)]\([^)]+\)/', '$1', $markdown);
        $markdown = preg_replace_callback('/(?<!!)\[([^]]+)]\((https?:\/\/[^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/', fn ($match) => $match[1].' ('.$match[2].')', $markdown);
        $html = (new GithubFlavoredMarkdownConverter)->convert($markdown)->getContent();
        $html = str_replace(['</p>', '</li>', '</h1>', '</h2>', '</h3>', '<br>', '<br/>', '<br />'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+\n|\n{3,}/", "\n\n", $text));
    }

    private function mediaReferences(string $markdown): array
    {
        preg_match_all('/!\[[^]]*]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)|<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $markdown, $matches, PREG_SET_ORDER);
        $media = [];
        foreach ($matches as $match) {
            $source = html_entity_decode($match[1] ?: $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! preg_match('#^(?:https?:)?//#i', $source)) {
                $media[] = strtok($source, '?#');
            }
        }

        // Custom Jekyll includes and gallery plugins commonly embed local
        // assets as src="/assets/..." or YAML-like "img: /assets/..." values.
        preg_match_all('#(?<![\w/])(/assets/[^\s"\'\)\]}]+)#i', $markdown, $assetMatches);
        $media = [...$media, ...$assetMatches[1]];

        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'mp4', 'mov', 'webm', 'm4v', 'mp3', 'wav', 'm4a', 'ogg'];
        $media = array_filter($media, fn ($source) => in_array(strtolower(pathinfo(strtok($source, '?#'), PATHINFO_EXTENSION)), $extensions));

        return array_values(array_unique(array_filter($media)));
    }

    private function attachments(ZipArchive $zip, Post $post, string $contentRoot, array $media): void
    {
        foreach ($media as $reference) {
            $internal = $contentRoot.ltrim(rawurldecode($reference), '/');
            if ($zip->locateName($internal) === false) {
                continue;
            }
            $extension = strtolower(pathinfo($internal, PATHINFO_EXTENSION));
            $type = in_array($extension, ['mp4', 'mov', 'webm', 'm4v']) ? 'video' : (in_array($extension, ['mp3', 'wav', 'm4a', 'ogg']) ? 'audio' : 'image');
            $stored = "archive-media/{$post->social_account_id}/jekyll/".sha1($internal).'-'.basename($internal);
            if (! Storage::disk('public')->exists($stored)) {
                $stream = $zip->getStream($internal);
                if ($stream === false) {
                    continue;
                }
                Storage::disk('public')->put($stored, $stream);
                fclose($stream);
            }
            Attachment::updateOrCreate(['post_id' => $post->id, 'path' => $stored], ['type' => $type, 'metadata' => ['source_path' => $internal]]);
        }
    }

    private function profileImage(ZipArchive $zip, SocialAccount $account, string $contentRoot, mixed $avatar): void
    {
        if (! is_string($avatar) || preg_match('#^(?:https?:)?//#i', $avatar)) {
            return;
        }
        $internal = $contentRoot.ltrim($avatar, '/');
        if ($zip->locateName($internal) === false || ($stream = $zip->getStream($internal)) === false) {
            return;
        }
        $stored = "profile-media/{$account->id}/jekyll-avatar-".basename($internal);
        Storage::disk('public')->put($stored, $stream);
        fclose($stream);
        $account->update(['avatar_path' => $stored]);
    }

    private function postFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#(?:^|/)_posts/\d{4}-\d{2}-\d{2}-.+\.(?:md|markdown|mkd|mdown)$#i', $name)) {
                $files[] = $name;
            }
        }
        sort($files);

        return $files;
    }

    private function siteConfig(ZipArchive $zip, string $contentRoot): array
    {
        foreach ([$contentRoot.'_config.yml', $contentRoot.'_config.yaml'] as $file) {
            if ($config = $this->yamlFile($zip, $file)) {
                return $config;
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#(?:^|/)_config\.ya?ml$#i', $name) && ($config = $this->yamlFile($zip, $name))) {
                return $config;
            }
        }

        return [];
    }

    private function yamlFile(ZipArchive $zip, string $file): array
    {
        $yaml = $zip->getFromName($file);

        return $yaml === false ? [] : $this->parseYaml($yaml);
    }

    private function parseYaml(string $yaml): array
    {
        try {
            return Yaml::parse($yaml) ?: [];
        } catch (ParseException $exception) {
            // Ruby's YAML parser, commonly used by Jekyll, accepts duplicate
            // top-level keys and keeps the last value. Mirror that behavior for
            // older sites whose front matter would otherwise be unimportable.
            $blocks = [];
            $prefix = [];
            $key = null;
            foreach (preg_split('/\R/', $yaml) as $line) {
                if (preg_match('/^([A-Za-z0-9_-]+)\s*:/', $line, $match)) {
                    $key = $match[1];
                    $blocks[$key] = [$line];
                } elseif ($key !== null) {
                    $blocks[$key][] = $line;
                } else {
                    $prefix[] = $line;
                }
            }
            $normalized = implode("\n", [...$prefix, ...array_merge(...array_values($blocks))]);

            return Yaml::parse($normalized) ?: throw $exception;
        }
    }

    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/\s+/', trim((string) $value));

        return array_values(array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $values)));
    }

    private function categoriesPath(mixed $categories): string
    {
        return implode('/', array_map(fn ($category) => Str::slug($category), $this->stringList($categories)));
    }
}
