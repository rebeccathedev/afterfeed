@props(['platform'])
@php($service = strtolower($platform) === 'twitter' ? 'x' : strtolower($platform))
<span {{ $attributes->class(['service-icon'])->merge(['data-service' => $service, 'title' => $service === 'x' ? 'Twitter / X' : ucfirst($service)]) }}>
@switch($service)
@case('facebook')<b>f</b>@break
@case('x')<b>𝕏</b>@break
@case('reddit')<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.5a2.4 2.4 0 0 0-3.7-2.3A12 12 0 0 0 12.6 8l.8-3.5 2.5.5a1.8 1.8 0 1 0 .3-1.3L13 3a.7.7 0 0 0-.8.5l-1 4.4a12 12 0 0 0-4.1.9 2.4 2.4 0 0 0-3.6 2.7A4 4 0 0 0 3 14c0 3.4 4 6.1 9 6.1s9-2.7 9-6.1c0-.9-.2-1.7-.5-2.5ZM7.6 13a1.4 1.4 0 1 1 0 2.8 1.4 1.4 0 0 1 0-2.8Zm8 4.3c-.8.8-2 1.1-3.6 1.1s-2.8-.3-3.6-1.1a.6.6 0 0 1 .9-.9c.5.5 1.4.8 2.7.8s2.2-.3 2.7-.8a.6.6 0 1 1 .9.9Zm.8-1.5a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8Z"/></svg>@break
@case('bluesky')<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 10.8c-1-2-3.8-5.7-6.4-7.5C3.1 1.6 2.2 1.9 1.6 2.2.9 2.5.8 3.5.8 4.1c0 .7.4 5.8.7 6.6.8 2.6 3.4 3.4 5.8 3-4.2.7-7.9 2.4-3 7 5.3 5.5 7.3-1.2 7.7-2.8.4 1.6 2.4 8.3 7.7 2.8 4.9-4.6 1.2-6.3-3-7 2.4.4 5-.4 5.8-3 .3-.8.7-5.9.7-6.6 0-.6-.1-1.6-.8-1.9-.6-.3-1.5-.6-4 1.1-2.6 1.8-5.4 5.5-6.4 7.5Z"/></svg>@break
@case('mastodon')<b>M</b>@break
@case('instagram')<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7Zm10.3 1.5a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/></svg>@break
@case('livejournal')<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m19.7 2.9 1.4 1.4a2.1 2.1 0 0 1 0 3L9.4 19l-5.1.8.8-5.1L16.8 2.9a2.1 2.1 0 0 1 2.9 0ZM7 15.4l-.3 1.9 1.9-.3 9.9-9.9-1.6-1.6L7 15.4Zm7.6 2.2 2-2v4.2a2.2 2.2 0 0 1-2.2 2.2H4.2A2.2 2.2 0 0 1 2 19.8V9.6a2.2 2.2 0 0 1 2.2-2.2h4.2l-2 2H4.2v10.4h10.4v-2.2Z"/></svg>@break
@default<b>{{ strtoupper(substr($service, 0, 1)) }}</b>
@endswitch
</span>
