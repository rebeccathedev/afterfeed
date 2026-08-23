<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationController extends Controller
{
    private const MAX_REPLIES_PER_PAGE = 400;

    public function index(Request $request): View
    {
        $accounts = SocialAccount::whereHas('posts', fn ($query) => $query->whereNotNull('reply_to_external_id'))->get();
        $roots = Post::query()->with(['socialAccount', 'attachments', 'boostedPostByUrl.socialAccount', 'boostedPostByUrl.attachments'])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('posts as replies')->whereColumn('replies.social_account_id', 'posts.social_account_id')->whereColumn('replies.reply_to_external_id', 'posts.external_id'))
            ->when($request->filled('account'), fn ($query) => $query->where('social_account_id', $request->integer('account')))
            ->whereDoesntHave('annotation', fn ($query) => $query->where('hidden', true))
            ->latest('posted_at')->paginate(12)->withQueryString();
        $frontier = $roots->getCollection()->groupBy('social_account_id')->map(fn ($posts) => $posts->pluck('external_id')->unique()->values()->all())->all();
        $seenIds = $roots->pluck('id')->all();
        $replies = collect();
        for ($depth = 0; $depth < 12 && $frontier !== [] && $replies->count() < self::MAX_REPLIES_PER_PAGE; $depth++) {
            $remaining = self::MAX_REPLIES_PER_PAGE - $replies->count();
            $level = Post::with(['socialAccount', 'attachments', 'boostedPostByUrl.socialAccount', 'boostedPostByUrl.attachments'])
                ->where(function ($query) use ($frontier) {
                    foreach ($frontier as $accountId => $parentIds) {
                        $query->orWhere(fn ($account) => $account->where('social_account_id', $accountId)->whereIn('reply_to_external_id', $parentIds));
                    }
                })
                ->whereNotIn('id', $seenIds)
                ->whereDoesntHave('annotation', fn ($query) => $query->where('hidden', true))
                ->orderBy('posted_at')->limit($remaining)->get();
            if ($level->isEmpty()) {
                break;
            }
            $replies = $replies->concat($level)->unique('id')->values();
            $seenIds = [...$seenIds, ...$level->pluck('id')->all()];
            $frontier = $level->groupBy('social_account_id')->map(fn ($posts) => $posts->pluck('external_id')->unique()->values()->all())->all();
        }
        $replyGroups = $replies->groupBy(fn ($post) => $post->social_account_id.'|'.$post->reply_to_external_id);
        $truncated = $replies->count() >= self::MAX_REPLIES_PER_PAGE;

        return view('discovery.conversations', compact('roots', 'replyGroups', 'accounts', 'truncated'));
    }
}
