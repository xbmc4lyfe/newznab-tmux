<div class="release-bulk-actions flex flex-wrap items-center gap-2" role="group" aria-label="Actions for selected releases">
    <span class="text-xs font-medium text-gray-600 dark:text-gray-400">With selected</span>
    <button type="button" class="nzb_multi_operations_download release-bulk-actions__button bg-green-700 text-white hover:bg-green-800" title="Download selected NZBs">
        <i class="fa fa-cloud-download" aria-hidden="true"></i>
        <span>Download</span>
    </button>
    <button type="button" class="nzb_multi_operations_cart release-bulk-actions__button surface-panel border text-gray-700 hover:text-primary-700 dark:text-gray-200 dark:hover:text-primary-300" title="Send selected releases to Download Basket">
        <i class="fa fa-shopping-basket" aria-hidden="true"></i>
        <span>Add to basket</span>
    </button>
    @if(auth()->check() && auth()->user()->hasRole('Admin'))
        <button type="button" class="nzb_multi_operations_delete release-bulk-actions__button border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950" title="Delete selected releases">
            <i class="fa fa-trash" aria-hidden="true"></i>
            <span>Delete</span>
        </button>
    @endif
</div>
