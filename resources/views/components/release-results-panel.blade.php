@props([
    'results',
    'showThumbs' => false,
    'dateField' => 'adddate',
    'showTopPagination' => false,
    'showBottomPagination' => true,
    'showSort' => true,
])

@php
    $hasPaginatorLinks = is_object($results) && method_exists($results, 'links');
    $shouldShowThumbs = filter_var($showThumbs, FILTER_VALIDATE_BOOL);
@endphp

<form
    id="nzb_multi_operations_form"
    method="get"
    x-data="releaseMultiOps"
    @if($shouldShowThumbs) data-show-thumbs="{{ request()->query('thumbs', '0') === '1' ? '1' : '0' }}" @endif
>
    <div class="release-results-toolbar surface-panel-alt border-b px-4 py-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
            <div class="min-w-0 text-sm text-gray-600 dark:text-gray-400">
                @isset($summary)
                    {{ $summary }}
                @else
                    Showing <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($results->firstItem() ?? 0) }}–{{ number_format($results->lastItem() ?? 0) }}</span>
                    of <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($results->total()) }}</span> releases
                @endisset
            </div>

            @isset($beforeActions)
                <div class="flex min-w-0 flex-wrap items-center gap-3">
                    {{ $beforeActions }}
                </div>
            @endisset
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
            <x-release-bulk-actions />

            <div class="release-results-toolbar__filters flex min-w-0 flex-wrap items-center gap-3">
                @isset($toolbarRight)
                    {{ $toolbarRight }}
                @endisset

                @if($showSort)
                    <x-sort-dropdown />
                @endif
            </div>
        </div>
    </div>

    @if($showTopPagination && $hasPaginatorLinks)
        <div @class(['px-4 py-3 sm:px-6 surface-panel-alt border-b', 'hidden md:block' => $showBottomPagination])>
            {{ $results->links() }}
        </div>
    @endif

    <x-release-results :results="$results" :show-thumbs="$shouldShowThumbs" :date-field="$dateField" />

    @if($showBottomPagination && $hasPaginatorLinks)
        <div class="px-4 py-3 sm:px-6 surface-panel-alt border-t">
            {{ $results->appends(request()->query())->links() }}
        </div>
    @endif
</form>
