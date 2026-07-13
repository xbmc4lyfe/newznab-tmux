{{-- Dense action/filter bar for admin list pages --}}
<div {{ $attributes->merge(['class' => 'px-4 sm:px-6 py-3 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700']) }}>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        {{ $slot }}
    </div>
</div>
