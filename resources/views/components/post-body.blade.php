@props(['post'])
@unless($post->type === 'boost' && $post->resolvedBoostedPost())<p {{ $attributes }}>{!! App\Support\PostBodyLinker::format($post->displayBody()) !!}</p>@endunless
