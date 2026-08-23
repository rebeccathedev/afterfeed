<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\ArchiveAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArchiveApiController extends Controller
{
    public function __construct(private readonly ArchiveAccessService $archives) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => ['name' => 'Afterfeed', 'api_version' => 'v1', 'read_only' => true]]);
    }

    public function accounts(): JsonResponse
    {
        return response()->json(['data' => $this->archives->accounts()]);
    }

    public function posts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'platform' => ['nullable', 'string', 'max:40'],
            'year' => ['nullable', 'integer', 'between:1900,2200'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'has_media' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $posts = $this->archives->posts($filters);

        return response()->json([
            'data' => collect($posts->items())->map(fn (Post $post) => $this->archives->serializePost($post)),
            'meta' => ['current_page' => $posts->currentPage(), 'last_page' => $posts->lastPage(), 'per_page' => $posts->perPage(), 'total' => $posts->total()],
            'links' => ['first' => $posts->url(1), 'last' => $posts->url($posts->lastPage()), 'prev' => $posts->previousPageUrl(), 'next' => $posts->nextPageUrl()],
        ]);
    }

    public function post(Post $post): JsonResponse
    {
        abort_if($post->annotation?->hidden, 404);

        return response()->json(['data' => $this->archives->post($post)]);
    }

    public function statistics(): JsonResponse
    {
        return response()->json(['data' => $this->archives->statistics()]);
    }
}
