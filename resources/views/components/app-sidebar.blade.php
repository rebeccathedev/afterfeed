@props(['active' => 'library'])
<aside class="sidebar"><a class="brand" href="/"><img class="brand-mark" src="{{ asset('brand/afterfeed-mark.svg') }}" alt="">Afterfeed</a>
<nav>
<a @class(['nav-item','active'=>$active==='timeline']) href="/">≋ <span>Timeline</span></a>
<a @class(['nav-item','active'=>$active==='today']) href="{{ route('on-this-day.index') }}">◷ <span>On this day</span></a>
<a @class(['nav-item','active'=>$active==='calendar']) href="{{ route('calendar.index') }}">▦ <span>Calendar</span></a>
<a @class(['nav-item','active'=>$active==='conversations']) href="{{ route('conversations.index') }}">◌ <span>Conversations</span></a>
<a @class(['nav-item','active'=>$active==='media']) href="{{ route('media.index') }}">▧ <span>Media</span></a>
<a @class(['nav-item','active'=>$active==='map']) href="{{ route('map.index') }}">⌖ <span>Map</span></a>
<a @class(['nav-item','active'=>$active==='statistics']) href="{{ route('statistics.index') }}">⌁ <span>Statistics</span></a>
<a @class(['nav-item','active'=>$active==='people']) href="{{ route('people.index') }}">♙ <span>People</span></a>
<a @class(['nav-item','active'=>$active==='collections']) href="{{ route('collections.index') }}">◇ <span>Collections</span></a>
<a @class(['nav-item','active'=>$active==='settings']) href="{{ route('settings.edit') }}">⚙ <span>Settings</span></a>
</nav>
<div class="privacy"><i></i><div><strong>{{ auth()->user()->name }}</strong><small>Your private archive</small></div><form method="post" action="{{ route('logout') }}">@csrf<button aria-label="Sign out" title="Sign out">↪</button></form></div></aside>
