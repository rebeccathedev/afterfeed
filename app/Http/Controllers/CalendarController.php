<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\DatabaseDialect;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function __construct(private readonly DatabaseDialect $database) {}

    public function index(Request $request): View
    {
        $latest = Post::max('posted_at');
        $year = $request->integer('year') ?: ($latest ? CarbonImmutable::parse($latest)->year : null);
        $year = $year ?: now()->year;
        $accounts = SocialAccount::orderBy('platform')->orderBy('display_name')->get();
        $query = Post::query()->whereYear('posted_at', $year)
            ->whereDoesntHave('annotation', fn ($query) => $query->where('hidden', true))
            ->when($request->filled('account'), fn ($query) => $query->where('social_account_id', $request->integer('account')));
        $counts = $query->selectRaw($this->database->monthDay('posted_at').' day, count(*) total')->groupBy('day')->pluck('total', 'day');
        $max = max(1, (int) $counts->max());
        $days = collect();
        for ($date = CarbonImmutable::create($year, 1, 1); $date->year === $year; $date = $date->addDay()) {
            $count = (int) ($counts[$date->format('m-d')] ?? 0);
            $days->push(['date' => $date, 'count' => $count, 'level' => $count ? max(1, (int) ceil(($count / $max) * 5)) : 0]);
        }
        $years = Post::whereNotNull('posted_at')->selectRaw($this->database->year('posted_at').' year')->distinct()->orderByDesc('year')->pluck('year');

        return view('discovery.calendar', compact('days', 'year', 'years', 'accounts', 'max'));
    }
}
