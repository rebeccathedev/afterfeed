<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnThisDayController extends Controller
{
    public function index(Request $request): View
    {
        $today = CarbonImmutable::today();
        $month = in_array($request->integer('month'), range(1, 12), true) ? $request->integer('month') : $today->month;
        $day = in_array($request->integer('day'), range(1, 31), true) ? $request->integer('day') : $today->day;
        $posts = Post::with(['socialAccount', 'attachments', 'boostedPostByUrl.socialAccount', 'boostedPostByUrl.attachments'])
            ->whereMonth('posted_at', $month)->whereDay('posted_at', $day)
            ->whereDoesntHave('annotation', fn ($query) => $query->where('hidden', true))
            ->orderByDesc('posted_at')->paginate(20)->withQueryString();
        $date = CarbonImmutable::create(2000, $month, min($day, CarbonImmutable::create(2000, $month)->daysInMonth));

        return view('discovery.on-this-day', compact('posts', 'date', 'month', 'day'));
    }
}
