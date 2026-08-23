<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PeopleIndexer
{
    private array $people = [];

    private array $interactions = [];

    public function rebuild(): array
    {
        DB::transaction(function (): void {
            $personIds = DB::table('people')->where('user_id', auth()->id())->pluck('id');
            DB::table('person_interactions')->whereIn('person_id', $personIds)->delete();
            DB::table('people')->where('user_id', auth()->id())->delete();
            $this->people = [];
            $this->interactions = [];
            $this->indexConnections();
            $this->indexMessages();
            $this->indexPosts();
            $this->indexReactions();
            $this->flush();
        });

        $personIds = DB::table('people')->where('user_id', auth()->id())->pluck('id');

        return ['people' => $personIds->count(), 'interactions' => DB::table('person_interactions')->whereIn('person_id', $personIds)->count()];
    }

    private function indexConnections(): void
    {
        $rows = DB::table('social_connections')->join('archives', 'archives.id', '=', 'social_connections.archive_id')->join('social_accounts', 'social_accounts.id', '=', 'archives.social_account_id')->where('social_accounts.user_id', auth()->id())->where('social_accounts.platform', '!=', 'reddit')->select('social_connections.*', 'social_accounts.id as owner_id', 'social_accounts.platform')->cursor();
        foreach ($rows as $row) {
            $identifier = $this->connectionIdentifier($row->platform, $row->external_account_id, $row->url);
            if (! $identifier || ($row->platform === 'facebook' && preg_match('/^[a-f0-9]{40}$/', $identifier))) {
                continue;
            }
            $person = $this->person($row->platform, $identifier, null, $row->url);
            $this->interaction($person, $row->owner_id, $row->direction, 'connection', $row->direction, null, null, $row->url);
        }
    }

    private function indexMessages(): void
    {
        $rows = DB::table('direct_messages')->join('social_accounts', 'social_accounts.id', '=', 'direct_messages.social_account_id')->where('social_accounts.user_id', auth()->id())->select('direct_messages.*', 'social_accounts.platform', 'social_accounts.handle as owner_handle', 'social_accounts.display_name as owner_name')->cursor();
        foreach ($rows as $row) {
            $candidate = $row->direction === 'sent' ? $row->recipient : $row->sender;
            $candidate = $candidate ?: ($row->direction === 'sent' ? $row->subject : null);
            if (! $candidate || $this->isOwner($candidate, $row->owner_handle, $row->owner_name)) {
                continue;
            }
            $person = $this->person($row->platform, $candidate, $candidate);
            $this->interaction($person, $row->social_account_id, 'dm', 'direct_message', (string) $row->id, $row->sent_at, $row->body ?: $row->subject, $row->url);
        }
    }

    private function indexPosts(): void
    {
        $rows = DB::table('posts')->join('social_accounts', 'social_accounts.id', '=', 'posts.social_account_id')->where('social_accounts.user_id', auth()->id())->select('posts.id', 'posts.external_id', 'posts.social_account_id', 'posts.reply_to_external_id', 'posts.body', 'posts.url', 'posts.posted_at', 'posts.metadata', 'social_accounts.platform')->cursor();
        foreach ($rows as $row) {
            $metadata = json_decode($row->metadata ?: '[]', true);
            if (! is_array($metadata)) {
                continue;
            }
            $mentions = $row->platform === 'twitter' ? data_get($metadata, 'entities.user_mentions', []) : data_get($metadata, 'tags', []);
            foreach ($mentions as $index => $mention) {
                if ($row->platform === 'twitter') {
                    $identifier = isset($mention['screen_name']) ? '@'.ltrim($mention['screen_name'], '@') : null;
                    $name = $mention['name'] ?? null;
                    $url = $identifier ? 'https://x.com/'.ltrim($identifier, '@') : null;
                } else {
                    if (($mention['type'] ?? null) !== 'Mention') {
                        continue;
                    }
                    $identifier = $mention['name'] ?? null;
                    $name = $identifier;
                    $url = $mention['href'] ?? null;
                }
                if (! $identifier) {
                    continue;
                }
                $person = $this->person($row->platform, $identifier, $name, $url);
                $kind = $row->reply_to_external_id && $index === 0 ? 'reply' : 'mention';
                $this->interaction($person, $row->social_account_id, $kind, 'post', (string) $row->id, $row->posted_at, $row->body, $row->url, $row->id);
            }
        }
    }

    private function indexReactions(): void
    {
        $rows = DB::table('liked_posts')->join('social_accounts', 'social_accounts.id', '=', 'liked_posts.social_account_id')->where('social_accounts.user_id', auth()->id())->whereNotNull('liked_posts.url')->select('liked_posts.*', 'social_accounts.platform')->cursor();
        foreach ($rows as $row) {
            $identity = $this->identityFromUrl($row->platform, $row->url);
            if (! $identity) {
                continue;
            }
            $person = $this->person($row->platform, $identity, null, $row->url);
            $this->interaction($person, $row->social_account_id, 'reaction', 'liked_post', (string) $row->id, null, $row->body, $row->url);
        }
    }

    private function person(string $platform, string $identifier, ?string $displayName = null, ?string $url = null): int
    {
        $identifier = trim($identifier);
        $key = $platform.'|'.mb_strtolower(ltrim($identifier, '@'));
        if (isset($this->people[$key])) {
            if ($displayName && mb_strtolower(ltrim($displayName, '@')) !== mb_strtolower(ltrim($identifier, '@'))) {
                Person::whereKey($this->people[$key])->update(['display_name' => $displayName]);
            }

            return $this->people[$key];
        }
        $person = Person::updateOrCreate(['platform' => $platform, 'identifier' => $identifier], ['display_name' => $displayName, 'profile_url' => $url]);

        return $this->people[$key] = $person->id;
    }

    private function interaction(int $person, int $account, string $kind, string $sourceType, string $sourceId, mixed $date = null, ?string $excerpt = null, ?string $url = null, ?int $post = null): void
    {
        $now = now();
        $this->interactions[] = ['person_id' => $person, 'social_account_id' => $account, 'post_id' => $post, 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId, 'occurred_at' => $date, 'excerpt' => $excerpt ? Str::limit(strip_tags($excerpt), 500) : null, 'source_url' => $url, 'created_at' => $now, 'updated_at' => $now];
        if (count($this->interactions) >= 500) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->interactions) {
            DB::table('person_interactions')->insertOrIgnore($this->interactions);
            $this->interactions = [];
        }
    }

    private function connectionIdentifier(string $platform, string $identifier, ?string $url): ?string
    {
        return $this->identityFromUrl($platform, $url) ?: $identifier;
    }

    private function identityFromUrl(string $platform, ?string $url): ?string
    {
        if (! $url || ! ($host = parse_url($url, PHP_URL_HOST)) || ! ($path = trim((string) parse_url($url, PHP_URL_PATH), '/'))) {
            return null;
        }
        $first = explode('/', $path)[0];
        if ($platform === 'twitter' && ! in_array(strtolower($first), ['i', 'intent', 'search'], true)) {
            return '@'.ltrim($first, '@');
        }
        if ($platform === 'mastodon' && str_starts_with($first, '@')) {
            return $first.'@'.preg_replace('/^www\./', '', strtolower($host));
        }
        if ($platform === 'instagram' && ! in_array(strtolower($first), ['p', 'reel', 'stories'], true)) {
            return '@'.ltrim($first, '@');
        }

        return null;
    }

    private function isOwner(string $candidate, ?string $handle, ?string $name): bool
    {
        $candidate = mb_strtolower(ltrim(trim($candidate), '@'));

        return in_array($candidate, array_filter([mb_strtolower(ltrim((string) $handle, '@')), mb_strtolower((string) $name)]), true);
    }
}
