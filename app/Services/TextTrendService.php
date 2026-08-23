<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TextTrendService
{
    public function analyze(array $stopWords): array
    {
        $words = [];
        $hashtags = [];
        $stops = array_fill_keys($stopWords, true);
        foreach (DB::table('posts')->join('social_accounts', 'social_accounts.id', '=', 'posts.social_account_id')->where('social_accounts.user_id', auth()->id())->select('posts.body', 'posts.metadata')->where(fn ($query) => $query->whereNotNull('posts.body')->orWhereNotNull('posts.metadata'))->orderBy('posts.id')->cursor() as $post) {
            $body = mb_strtolower(strip_tags($post->body ?? ''));
            preg_match_all('/[\p{L}\p{N}_]{3,}/u', $body, $tokens);
            foreach ($tokens[0] as $word) {
                if (! isset($stops[$word]) && ! is_numeric($word) && (isset($words[$word]) || count($words) < 150000)) {
                    $words[$word] = ($words[$word] ?? 0) + 1;
                }
            }
            $metadata = json_decode($post->metadata ?: '[]', true);
            $metadata = is_array($metadata) ? $metadata : [];
            $postTags = [];
            foreach (data_get($metadata, 'entities.hashtags', []) as $tag) {
                if ($label = mb_strtolower(ltrim((string) ($tag['text'] ?? ''), '#'))) {
                    $postTags[$label] = true;
                }
            }
            foreach (data_get($metadata, 'tags', []) as $tag) {
                if (($tag['type'] ?? null) !== 'Mention' && ($label = mb_strtolower(ltrim((string) ($tag['name'] ?? ''), '#')))) {
                    $postTags[$label] = true;
                }
            }
            if ($postTags === []) {
                preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]{2,})/u', $body, $tags);
                $postTags = array_fill_keys($tags[1], true);
            }
            foreach (array_keys($postTags) as $tag) {
                $hashtags[$tag] = ($hashtags[$tag] ?? 0) + 1;
            }
        }
        arsort($words);
        arsort($hashtags);

        return [
            'words' => collect(array_slice($words, 0, 30, true))->map(fn ($total, $label) => (object) compact('label', 'total'))->values(),
            'hashtags' => collect(array_slice($hashtags, 0, 20, true))->map(fn ($total, $label) => (object) compact('label', 'total'))->values(),
        ];
    }
}
