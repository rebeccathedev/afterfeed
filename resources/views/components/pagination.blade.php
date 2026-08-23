@props(['paginator', 'previous' => '← Previous', 'next' => 'Next →', 'fragment' => ''])
@if($paginator->hasPages())
<nav class="pagination" aria-label="Pagination">
@if($paginator->onFirstPage())<span aria-disabled="true">{{ $previous }}</span>@else<a href="{{ $paginator->previousPageUrl().$fragment }}" rel="prev">{{ $previous }}</a>@endif
<b><span class="pagination-wide">Page </span>{{ number_format($paginator->currentPage()) }} <span>of</span> {{ number_format($paginator->lastPage()) }}</b>
@if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl().$fragment }}" rel="next">{{ $next }}</a>@else<span aria-disabled="true">{{ $next }}</span>@endif
</nav>
@endif
