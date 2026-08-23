@props(['post', 'compact' => false])
@php($labels=$post->contextLabels())
@php($sharedUrl=$post->sharedUrl())
@php($mapPoint=$post->mapPoint())
@php($embeddedBoost=$post->resolvedBoostedPost())
@if($labels || $sharedUrl || $mapPoint || $embeddedBoost)
<div @class(['post-context','compact'=>$compact])>
@if($labels)<div class="post-context-labels">@foreach($labels as $label)@if(str_starts_with($label,'#'))<a href="{{ route('archives.index',['q'=>$label]) }}#timeline">{{ $label }}</a>@else<span>{{ $label }}</span>@endif @endforeach</div>@endif
@if($embeddedBoost)<div class="boost-attribution">↻ Boosted from {{ $embeddedBoost->socialAccount->handle }}</div><x-embedded-post :post="$embeddedBoost"/>@elseif($sharedUrl)<a class="shared-link-card" href="{{ $sharedUrl }}" target="_blank" rel="noreferrer"><span class="shared-link-icon">{{ $post->type === 'boost' ? '↻' : '↗' }}</span><span><small>{{ strtoupper($post->sharedLinkLabel()) }} · {{ strtoupper($post->sharedLinkHost()) }}</small><strong>{{ $post->sharedLinkTitle() }}</strong>@if(data_get($post->metadata,'external_source'))<em>{{ data_get($post->metadata,'external_source') }}</em>@endif</span><b>{{ $post->sharedLinkAction() }}</b></a>@endif
@if($mapPoint)<div class="post-mini-map" data-mini-map data-latitude="{{ $mapPoint['latitude'] }}" data-longitude="{{ $mapPoint['longitude'] }}" data-place="{{ $mapPoint['place'] }}"><div class="mini-map-placeholder"><i>⌖</i><span><strong>{{ $mapPoint['place'] }}</strong><small>{{ number_format($mapPoint['latitude'],3) }}, {{ number_format($mapPoint['longitude'],3) }}</small></span><button type="button" data-load-mini-map>Load map<small>Uses OpenStreetMap</small></button></div></div>@endif
</div>
@endif
