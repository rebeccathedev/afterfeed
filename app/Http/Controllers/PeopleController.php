<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonInteraction;
use App\Services\DatabaseDialect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PeopleController extends Controller
{
    public function __construct(private readonly DatabaseDialect $database) {}

    public function index(Request $request): View
    {
        $search = trim($request->string('q')->limit(100)->toString());
        $kind = $request->string('kind')->toString();
        $people = Person::query()
            ->withCount(['interactions', 'interactions as dm_count' => fn ($query) => $query->where('kind', 'dm'), 'interactions as mention_count' => fn ($query) => $query->whereIn('kind', ['mention', 'reply']), 'interactions as connection_count' => fn ($query) => $query->whereIn('kind', ['friend', 'follower', 'following']), 'interactions as reaction_count' => fn ($query) => $query->where('kind', 'reaction')])
            ->when($search, fn ($query) => $query->where(fn ($query) => $query->where('identifier', 'like', '%'.$search.'%')->orWhere('display_name', 'like', '%'.$search.'%')))
            ->when($kind, fn ($query) => $query->whereHas('interactions', fn ($interactions) => $interactions->where('kind', $kind)))
            ->when(! $request->boolean('all'), fn ($query) => $query->has('interactions', '>=', 2))
            ->orderByDesc('interactions_count')
            ->orderBy('identifier')
            ->paginate(48)
            ->withQueryString();
        $summary = ['people' => Person::count(), 'recurring' => Person::has('interactions', '>=', 2)->count(), 'interactions' => PersonInteraction::count()];

        return view('people.index', compact('people', 'summary', 'search', 'kind'));
    }

    public function show(Person $person): View
    {
        $normalized = mb_strtolower(ltrim($person->identifier, '@'));
        $identities = Person::whereIn(DB::raw('lower(identifier)'), [$normalized, '@'.$normalized])->orderBy('platform')->get();
        $interactions = PersonInteraction::with(['socialAccount', 'post.attachments'])
            ->whereIn('person_id', $identities->pluck('id'))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(30);
        $kinds = PersonInteraction::whereIn('person_id', $identities->pluck('id'))->selectRaw('kind, count(*) total')->groupBy('kind')->orderByDesc('total')->pluck('total', 'kind');
        $years = PersonInteraction::whereIn('person_id', $identities->pluck('id'))->whereNotNull('occurred_at')->selectRaw($this->database->year('occurred_at').' year, count(*) total')->groupBy('year')->orderBy('year')->get();

        return view('people.show', compact('person', 'identities', 'interactions', 'kinds', 'years'));
    }
}
