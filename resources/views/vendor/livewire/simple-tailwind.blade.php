@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="flex justify-between items-center text-xs">
            <span>
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <span class="relative inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded shadow-2xs">
                        {!! __('pagination.previous') !!}
                    </span>
                @else
                    @if(method_exists($paginator,'getCursorName'))
                        <button type="button" dusk="previousPage" wire:key="cursor-{{ $paginator->getCursorName() }}-{{ $paginator->previousCursor()->encode() }}" wire:click="setPage('{{$paginator->previousCursor()->encode()}}','{{ $paginator->getCursorName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                                {!! __('pagination.previous') !!}
                        </button>
                    @else
                        <button
                            type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}" class="relative inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                                {!! __('pagination.previous') !!}
                        </button>
                    @endif
                @endif
            </span>

            <span>
                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    @if(method_exists($paginator,'getCursorName'))
                        <button type="button" dusk="nextPage" wire:key="cursor-{{ $paginator->getCursorName() }}-{{ $paginator->nextCursor()->encode() }}" wire:click="setPage('{{$paginator->nextCursor()->encode()}}','{{ $paginator->getCursorName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                                {!! __('pagination.next') !!}
                        </button>
                    @else
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}" class="relative inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition ease-in-out duration-150">
                                {!! __('pagination.next') !!}
                        </button>
                    @endif
                @else
                    <span class="relative inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded shadow-2xs">
                        {!! __('pagination.next') !!}
                    </span>
                @endif
            </span>
        </nav>
    @endif
</div>
