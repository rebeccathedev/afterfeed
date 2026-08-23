<?php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialConnection;
use App\Services\AppSettings;
use App\Services\DatabaseDialect;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArchiveController extends Controller
{
    public function __construct(private readonly DatabaseDialect $database, private readonly AppSettings $settings) {}

    public function index(Request $request): View
    {
        $archives = Archive::query()->with('socialAccount')->withCount('posts')->orderByDesc('exported_at')->get();
        $profiles = SocialAccount::query()->withCount(['posts', 'likedPosts'])->get();
        $profile = $request->filled('account') ? $profiles->firstWhere('id', $request->integer('account')) : null;
        $latestArchiveId = $profile?->archives()->latest('exported_at')->value('id');
        $connectionCounts = $latestArchiveId ? SocialConnection::where('archive_id', $latestArchiveId)->selectRaw('direction, count(*) total')->groupBy('direction')->pluck('total', 'direction') : collect();
        $year = $request->integer('year') ?: null;
        $month = in_array($request->integer('month'), range(1, 12), true) ? $request->integer('month') : null;
        $day = in_array($request->integer('day'), range(1, 31), true) ? $request->integer('day') : null;
        $search = trim($request->string('q')->limit(200)->toString());
        $yearExpression = $this->database->year('posted_at');
        $timelineYears = Post::query()
            ->when($request->filled('account'), fn ($query) => $query->where('social_account_id', $request->integer('account')))
            ->whereNotNull('posted_at')
            ->selectRaw("{$yearExpression} as year")
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year');
        $timelinePosts = Post::query()
            ->with(['socialAccount', 'attachments', 'boostedPostByUrl.socialAccount', 'boostedPostByUrl.attachments'])
            ->when($request->filled('account'), fn ($query) => $query->where('social_account_id', $request->integer('account')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->boolean('media'), fn ($query) => $query->whereHas('attachments'))
            ->whereDoesntHave('annotation', fn ($query) => $query->where('hidden', true))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $this->database->searchPosts($query, $search)
                    ->orWhereHas('socialAccount', fn ($account) => $account->where('display_name', 'like', '%'.$search.'%')->orWhere('handle', 'like', '%'.$search.'%'));
            }))
            ->when($year, fn ($query) => $query->whereYear('posted_at', $year))
            ->when($month, fn ($query) => $query->whereMonth('posted_at', $month))
            ->when($day, fn ($query) => $query->whereDay('posted_at', $day))
            ->latest('posted_at')
            ->paginate($this->settings->get('timeline_per_page', 18))
            ->withQueryString();

        return view('archives.index', compact('archives', 'timelinePosts', 'profile', 'profiles', 'connectionCounts', 'timelineYears', 'year', 'month', 'day', 'search'));
    }
}
