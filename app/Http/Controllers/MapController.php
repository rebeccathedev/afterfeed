<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\DatabaseDialect;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MapController extends Controller
{
    public function __construct(private readonly DatabaseDialect $database) {}

    public function index(Request $request): View
    {
        $accounts = SocialAccount::whereHas('posts')->orderBy('platform')->orderBy('display_name')->get();
        $posts = Post::with(['socialAccount', 'annotation', 'attachments' => fn ($query) => $query->where('type', 'image')->orderBy('id')])
            ->when($request->filled('account'), fn ($query) => $query->where('social_account_id', $request->integer('account')))
            ->when($request->integer('year'), fn ($query) => $query->whereYear('posted_at', $request->integer('year')))
            ->when($request->boolean('photos'), fn ($query) => $query->whereHas('attachments', fn ($attachments) => $attachments->where('type', 'image')))
            ->whereDoesntHave('annotation', fn ($query) => $query->where('hidden', true))
            ->where(function ($query) {
                $query->whereHas('annotation', fn ($annotation) => $annotation->whereNotNull('latitude')->whereNotNull('longitude'))
                    ->orWhere(function ($metadata) {
                        $metadata->whereRaw($this->database->validJson('metadata'))->where(function ($coordinates) {
                            foreach (['location.latitude', 'place.coordinate.latitude', 'coordinates.coordinates.0', 'geo.coordinates.0', 'place.bounding_box.coordinates.0.0.0'] as $path) {
                                $coordinates->orWhereRaw($this->database->jsonText('metadata', $path).' is not null');
                            }
                        });
                    });
            })->latest('posted_at')->limit(5000)->get();

        $markers = $posts->map(function (Post $post) {
            $location = $this->location($post);
            if (! $location) {
                return null;
            }

            return [
                'post' => $post, 'latitude' => $location['latitude'], 'longitude' => $location['longitude'],
                'place' => $location['place'] ?: 'Pinned location',
                'left' => (($location['longitude'] + 180) / 360) * 100,
                'top' => ((90 - $location['latitude']) / 180) * 100,
            ];
        })->filter()->values();

        $unmapped = Post::with('socialAccount')
            ->when($request->filled('account'), fn ($query) => $query->where('social_account_id', $request->integer('account')))
            ->when($request->integer('year'), fn ($query) => $query->whereYear('posted_at', $request->integer('year')))
            ->where(function ($query) {
                $query->where('body', 'like', '% was at %')->orWhere('body', 'like', '% was with % at %');
            })->latest('posted_at')->limit(100)->get()->map(function ($post) {
                preg_match('/\bat (.+?)(?:\.|$)/i', $post->body, $match);
                $post->place_name = $match[1] ?? 'Mentioned place';

                return $post;
            });
        $years = Post::whereNotNull('posted_at')->selectRaw($this->database->year('posted_at').' year')->distinct()->orderByDesc('year')->pluck('year');

        $photoCount = $markers->filter(fn ($marker) => $marker['post']->attachments->isNotEmpty())->count();

        return view('map.index', compact('markers', 'unmapped', 'accounts', 'years', 'photoCount'));
    }

    private function location(Post $post): ?array
    {
        if ($post->annotation?->latitude !== null && $post->annotation?->longitude !== null) {
            return ['latitude' => $post->annotation->latitude, 'longitude' => $post->annotation->longitude, 'place' => $post->annotation->place_name];
        }
        $metadata = $post->metadata ?? [];
        $latitude = data_get($metadata, 'location.latitude') ?? data_get($metadata, 'place.coordinate.latitude');
        $longitude = data_get($metadata, 'location.longitude') ?? data_get($metadata, 'place.coordinate.longitude');
        $coordinates = data_get($metadata, 'coordinates.coordinates');
        if (($latitude === null || $longitude === null) && is_array($coordinates) && count($coordinates) >= 2) {
            [$longitude, $latitude] = $coordinates;
        }
        $geo = data_get($metadata, 'geo.coordinates');
        if (($latitude === null || $longitude === null) && is_array($geo) && count($geo) >= 2) {
            [$latitude, $longitude] = $geo;
        }
        $placeBounds = data_get($metadata, 'place.bounding_box.coordinates.0');
        if (($latitude === null || $longitude === null) && is_array($placeBounds) && $placeBounds !== []) {
            $points = collect($placeBounds)->filter(fn ($point) => is_array($point) && count($point) >= 2);
            if ($points->isNotEmpty()) {
                $longitude = $points->avg(fn ($point) => (float) $point[0]);
                $latitude = $points->avg(fn ($point) => (float) $point[1]);
            }
        }
        if (! is_numeric($latitude) || ! is_numeric($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude, 'place' => data_get($metadata, 'location.name') ?? data_get($metadata, 'place.full_name')];
    }
}
