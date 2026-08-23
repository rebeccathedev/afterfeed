<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function index(Request $request): View
    {
        $accounts = SocialAccount::query()->whereHas('posts.attachments')->orderBy('platform')->orderBy('display_name')->get();
        $media = Attachment::query()
            ->with('post.socialAccount')
            ->whereHas('post')
            ->when($request->filled('account'), fn ($query) => $query->whereHas('post', fn ($post) => $post->where('social_account_id', $request->integer('account'))))
            ->when(in_array($request->string('type')->toString(), ['image', 'video'], true), fn ($query) => $query->where('attachments.type', $request->string('type')))
            ->join('posts', 'posts.id', '=', 'attachments.post_id')
            ->select('attachments.*')
            ->orderByDesc('posts.posted_at')
            ->paginate(48)
            ->withQueryString();

        return view('media.index', compact('accounts', 'media'));
    }
}
