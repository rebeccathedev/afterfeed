<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PostDetailController extends Controller
{
    public function show(Post $post): View
    {
        $post->load(['socialAccount', 'attachments', 'annotation', 'collections', 'boostedPostByUrl.socialAccount', 'boostedPostByUrl.attachments']);
        $collections = PostCollection::withCount('posts')->orderBy('name')->get();

        return view('posts.show', compact('post', 'collections'));
    }

    public function annotate(Request $request, Post $post): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000'], 'tags' => ['nullable', 'string', 'max:500'], 'favorite' => ['nullable', 'boolean'], 'hidden' => ['nullable', 'boolean'], 'place_name' => ['nullable', 'string', 'max:200'], 'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'], 'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude']]);
        $post->annotation()->updateOrCreate([], [
            'note' => $data['note'] ?? null,
            'tags' => collect(explode(',', $data['tags'] ?? ''))->map(fn ($tag) => trim($tag))->filter()->unique()->values()->all(),
            'favorite' => $request->boolean('favorite'),
            'hidden' => $request->boolean('hidden'),
            'place_name' => $data['place_name'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ]);

        return back()->with('status', 'Annotation saved.');
    }
}
