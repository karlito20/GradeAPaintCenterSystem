@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex justify-between items-center text-xs">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded shadow-2xs">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded shadow-2xs">
                {!! __('pagination.next') !!}
            </span>
        @endif
    </nav>
@endif
