<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CollectionController extends Controller
{
    public function index(): View
    {
        $collections = PostCollection::with(['posts' => fn ($query) => $query->with(['socialAccount', 'attachments'])->latest('posted_at')])->withCount('posts')->latest()->get();

        return view('collections.index', compact('collections'));
    }

    public function store(Request $request): RedirectResponse
    {
        PostCollection::create($request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:500'], 'color' => ['required', 'regex:/^#[0-9a-f]{6}$/i']]));

        return back()->with('status', 'Collection created.');
    }

    public function addPost(PostCollection $postCollection, Post $post): RedirectResponse
    {
        $postCollection->posts()->syncWithoutDetaching($post->id);

        return back()->with('status', 'Post added to '.$postCollection->name.'.');
    }

    public function removePost(PostCollection $postCollection, Post $post): RedirectResponse
    {
        $postCollection->posts()->detach($post->id);

        return back()->with('status', 'Post removed.');
    }
}
