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
    <div class="px-6 py-4 surface-panel-alt border-b">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="space-y-3">
                @isset($beforeActions)
                    {{ $beforeActions }}
                @endisset

                <div class="flex flex-wrap items-center gap-2">
                    <small class="text-gray-600 dark:text-gray-400">With Selected:</small>
                    <div class="flex gap-1">
                        <button type="button" class="nzb_multi_operations_download px-3 py-1 bg-green-600 dark:bg-green-700 text-white rounded-lg hover:bg-green-700 dark:hover:bg-green-800 transition text-sm" title="Download NZBs">
                            <i class="fa fa-cloud-download"></i>
                        </button>
                        <button type="button" class="nzb_multi_operations_cart px-3 py-1 bg-primary-600 dark:bg-primary-700 text-white rounded-lg hover:bg-primary-700 dark:hover:bg-primary-800 transition text-sm" title="Send to Download Basket">
                            <i class="fa fa-shopping-basket"></i>
                        </button>
                        @if(auth()->check() && auth()->user()->hasRole('Admin'))
                            <button type="button" class="nzb_multi_operations_delete px-3 py-1 bg-red-600 dark:bg-red-700 text-white rounded-lg hover:bg-red-700 dark:hover:bg-red-800 transition text-sm" title="Delete">
                                <i class="fa fa-trash"></i>
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-center">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    @isset($summary)
                        {{ $summary }}
                    @else
                        Showing {{ $results->firstItem() }} to {{ $results->lastItem() }} of {{ $results->total() }} results
                    @endisset
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
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
        <div class="px-6 py-3 surface-panel-alt border-b">
            {{ $results->links() }}
        </div>
    @endif

    <x-release-results :results="$results" :show-thumbs="$shouldShowThumbs" :date-field="$dateField" />

    @if($showBottomPagination && $hasPaginatorLinks)
        <div class="px-6 py-3 surface-panel-alt border-t">
            {{ $results->appends(request()->query())->links() }}
        </div>
    @endif
</form>
