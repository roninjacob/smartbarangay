@if($paginator->hasPages())
<nav aria-label="Pagination">
    @if($showSummary ?? false)<p class="app-note mb-2">Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }} results</p>@endif
    <ul class="pagination mb-0">
        <li @class(['page-item', 'disabled' => $paginator->onFirstPage()])>
            @if($paginator->onFirstPage())<span class="page-link" aria-disabled="true" aria-label="Previous page">&lsaquo;</span>@else<a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">&lsaquo;</a>@endif
        </li>
        @foreach($elements as $element)
            @if(is_string($element))<li class="page-item disabled"><span class="page-link" aria-disabled="true">{{ $element }}</span></li>@endif
            @if(is_array($element))
                @foreach($element as $page => $url)
                    <li @class(['page-item', 'active' => $page === $paginator->currentPage()])>
                        @if($page === $paginator->currentPage())<span class="page-link" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>@else<a class="page-link" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>@endif
                    </li>
                @endforeach
            @endif
        @endforeach
        <li @class(['page-item', 'disabled' => !$paginator->hasMorePages()])>
            @if($paginator->hasMorePages())<a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">&rsaquo;</a>@else<span class="page-link" aria-disabled="true" aria-label="Next page">&rsaquo;</span>@endif
        </li>
    </ul>
</nav>
@endif
