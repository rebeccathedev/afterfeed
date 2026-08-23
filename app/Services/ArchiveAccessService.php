<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ArchiveAccessService
{
    public function __construct(private readonly DatabaseDialect $database) {}

    public function accounts(): array
    {
        return SocialAccount::query()
            ->withCount(['posts', 'archives'])
            ->orderBy('platform')
            ->orderBy('handle')
            ->get()
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'bio' => $account->bio,
                'website' => $account->website,
                'location' => $account->location,
                'posts_count' => $account->posts_count,
                'imports_count' => $account->archives_count,
            ])->all();
    }

    public function posts(array $filters = []): LengthAwarePaginator
    {
        $search = trim(mb_substr((string) ($filters['q'] ?? ''), 0, 200));
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return Post::query()
            ->with(['socialAccount:id,platform,handle,display_name', 'attachments', 'annotation'])
            ->whereDoesntHave('annotation', fn (Builder $query) => $query->where('hidden', true))
            ->when($filters['account_id'] ?? null, fn (Builder $query, $account) => $query->where('social_account_id', (int) $account))
            ->when($filters['platform'] ?? null, fn (Builder $query, $platform) => $query->whereHas('socialAccount', fn (Builder $account) => $account->where('platform', $platform)))
            ->when($filters['year'] ?? null, fn (Builder $query, $year) => $query->whereYear('posted_at', (int) $year))
            ->when($filters['month'] ?? null, fn (Builder $query, $month) => $query->whereMonth('posted_at', (int) $month))
            ->when(array_key_exists('has_media', $filters), fn (Builder $query) => filter_var($filters['has_media'], FILTER_VALIDATE_BOOL) ? $query->whereHas('attachments') : $query->whereDoesntHave('attachments'))
            ->when($search !== '', fn (Builder $query) => $this->addSearch($query, $search))
            ->latest('posted_at')
            ->paginate($perPage, ['*'], 'page', max((int) ($filters['page'] ?? 1), 1));
    }

    public function post(Post $post): array
    {
        return $this->serializePost($post->loadMissing(['socialAccount:id,platform,handle,display_name', 'attachments', 'annotation', 'collections:id,name,color']));
    }

    public function serializePost(Post $post): array
    {
        return [
            'id' => $post->id,
            'external_id' => $post->external_id,
            'type' => $post->type,
            'body' => $post->body,
            'url' => $post->url,
            'original_url' => $post->originalUrl(),
            'shared_url' => $post->sharedUrl(),
            'posted_at' => $post->posted_at?->toIso8601String(),
            'reply_to_external_id' => $post->reply_to_external_id,
            'account' => $post->socialAccount ? [
                'id' => $post->socialAccount->id,
                'platform' => $post->socialAccount->platform,
                'handle' => $post->socialAccount->handle,
                'display_name' => $post->socialAccount->display_name,
            ] : null,
            'attachments' => $post->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'type' => $attachment->type,
                'path' => $attachment->path,
                'alt_text' => $attachment->alt_text,
            ])->all(),
            'annotation' => $post->annotation ? [
                'note' => $post->annotation->note,
                'tags' => $post->annotation->tags,
                'favorite' => $post->annotation->favorite,
                'place_name' => $post->annotation->place_name,
                'latitude' => $post->annotation->latitude,
                'longitude' => $post->annotation->longitude,
            ] : null,
            'collections' => $post->relationLoaded('collections') ? $post->collections->map(fn ($collection) => [
                'id' => $collection->id,
                'name' => $collection->name,
                'color' => $collection->color,
            ])->all() : null,
        ];
    }

    public function statistics(): array
    {
        return [
            'posts' => Post::count(),
            'accounts' => SocialAccount::count(),
            'attachments' => Attachment::count(),
            'date_range' => [
                'first' => Post::whereNotNull('posted_at')->min('posted_at'),
                'last' => Post::whereNotNull('posted_at')->max('posted_at'),
            ],
            'by_platform' => Post::query()->join('social_accounts', 'social_accounts.id', '=', 'posts.social_account_id')->selectRaw('social_accounts.platform label, count(*) total')->groupBy('social_accounts.platform')->orderByDesc('total')->get(),
            'by_year' => Post::whereNotNull('posted_at')->selectRaw($this->database->year('posted_at').' label, count(*) total')->groupBy('label')->orderBy('label')->get(),
        ];
    }

    private function addSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $this->database->searchPosts($query, $search)
                ->orWhereHas('socialAccount', fn (Builder $account) => $account->where('display_name', 'like', '%'.$search.'%')->orWhere('handle', 'like', '%'.$search.'%'));
        });
    }
}
