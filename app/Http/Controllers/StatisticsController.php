<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\PersonInteraction;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\DatabaseDialect;
use App\Services\TextTrendService;
use Illuminate\View\View;

class StatisticsController extends Controller
{
    public function __construct(private readonly DatabaseDialect $database, private readonly TextTrendService $textTrends) {}

    public function index(): View
    {
        $postYear = $this->database->year('posted_at');
        $joinedPostYear = $this->database->year('posts.posted_at');
        $years = Post::query()->whereNotNull('posted_at')->selectRaw("{$postYear} label, count(*) total")->groupBy('label')->orderByDesc('total')->limit(15)->get();
        $hours = $this->completeSeries(Post::query()->whereNotNull('posted_at')->selectRaw($this->database->hour('posted_at').' label, count(*) total')->groupBy('label')->pluck('total', 'label'), range(0, 23));
        $weekdays = $this->completeSeries(Post::query()->whereNotNull('posted_at')->selectRaw($this->database->weekday('posted_at').' label, count(*) total')->groupBy('label')->pluck('total', 'label'), range(0, 6));
        $photosByYear = Attachment::query()->join('posts', 'posts.id', '=', 'attachments.post_id')->where('attachments.type', 'image')->whereNotNull('posts.posted_at')->selectRaw("{$joinedPostYear} label, count(*) total")->groupBy('label')->orderBy('label')->get();
        $serviceYears = Post::query()->join('social_accounts', 'social_accounts.id', '=', 'posts.social_account_id')->whereNotNull('posts.posted_at')->selectRaw("{$joinedPostYear} year, social_accounts.platform, count(*) total")->groupBy('year', 'social_accounts.platform')->orderBy('year')->get();
        $community = $this->database->jsonText('metadata', 'subreddit');
        $communities = Post::query()->whereRaw($this->database->validJson('metadata'))->whereRaw("{$community} is not null")->selectRaw("{$community} label, count(*) total")->groupBy('label')->orderByDesc('total')->limit(15)->get();
        $trends = $this->textTrends->analyze($this->stopWords());
        $words = $trends['words'];
        $hashtags = $trends['hashtags'];
        $people = PersonInteraction::query()->join('people', 'people.id', '=', 'person_interactions.person_id')->whereIn('person_interactions.kind', ['mention', 'reply', 'dm', 'reaction'])->selectRaw('coalesce(people.display_name, people.identifier) label, count(*) total')->groupBy('people.id', 'people.display_name', 'people.identifier')->orderByDesc('total')->limit(20)->get();
        $summary = ['posts' => Post::count(), 'photos' => Attachment::where('type', 'image')->count(), 'years' => Post::whereNotNull('posted_at')->selectRaw("count(distinct {$postYear}) total")->value('total'), 'accounts' => SocialAccount::count()];

        return view('statistics.index', compact('years', 'hours', 'weekdays', 'photosByYear', 'serviceYears', 'communities', 'words', 'hashtags', 'people', 'summary'));
    }

    private function completeSeries($values, array $labels): object
    {
        return collect($labels)->map(fn ($label) => (object) ['label' => $label, 'total' => (int) ($values[$label] ?? 0)]);
    }

    private function stopWords(): array
    {
        return ['the', 'and', 'that', 'this', 'with', 'for', 'you', 'your', 'are', 'was', 'but', 'not', 'have', 'from', 'they', 'will', 'just', 'like', 'what', 'when', 'all', 'can', 'out', 'get', 'has', 'had', 'how', 'its', 'our', 'who', 'would', 'there', 'about', 'one', 'some', 'more', 'been', 'were', 'them', 'then', 'into', 'than', 'because', 'https', 'http', 'com', 'www', 'amp', 'quot'];
    }
}
