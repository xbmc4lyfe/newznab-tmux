{{-- Admin blue info alert box --}}
<div class="border-b border-blue-100 bg-blue-50/80 px-6 py-4 dark:border-blue-800 dark:bg-blue-900/20" role="note">
    <div class="flex">
        <div class="shrink-0">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/60 dark:text-blue-300">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
            </span>
        </div>
        <div class="ml-3 self-center text-sm leading-6 text-blue-800 dark:text-blue-200">
            {{ $slot }}
        </div>
    </div>
</div>
