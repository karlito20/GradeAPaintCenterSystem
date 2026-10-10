@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between text-xs">
        <div class="flex gap-2 items-center justify-between sm:hidden flex-1">
            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded shadow-2xs">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded shadow-2xs">
                    {!! __('pagination.next') !!}
                </span>
            @endif
        </div>

        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-xs text-slate-600 leading-normal">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-semibold text-slate-800">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-semibold text-slate-800">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-semibold text-slate-800">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div>
                <span class="relative z-0 inline-flex rtl:flex-row-reverse rounded shadow-2xs">
                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                            <span class="relative inline-flex items-center px-2 py-1 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded-l leading-normal" aria-hidden="true">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="relative inline-flex items-center px-2 py-1 text-xs font-medium text-slate-600 bg-white border border-slate-300 rounded-l leading-normal hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150" aria-label="{{ __('pagination.previous') }}">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <span aria-disabled="true">
                                <span class="relative inline-flex items-center px-2.5 py-1 -ml-px text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-default leading-normal">{{ $element }}</span>
                            </span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page">
                                        <span class="relative inline-flex items-center px-2.5 py-1 -ml-px text-xs font-bold text-white bg-[#00a3cc] border border-[#00a3cc] cursor-default leading-normal z-10">{{ $page }}</span>
                                    </span>
                                @else
                                    <a href="{{ $url }}" class="relative inline-flex items-center px-2.5 py-1 -ml-px text-xs font-medium text-slate-700 bg-white border border-slate-300 leading-normal hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="relative inline-flex items-center px-2 py-1 -ml-px text-xs font-medium text-slate-600 bg-white border border-slate-300 rounded-r leading-normal hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150" aria-label="{{ __('pagination.next') }}">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                            <span class="relative inline-flex items-center px-2 py-1 -ml-px text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded-r leading-normal" aria-hidden="true">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </span>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
