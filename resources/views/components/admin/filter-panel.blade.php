{{-- Compact filter panel for admin list pages --}}
<div {{ $attributes->merge(['class' => 'px-4 sm:px-6 py-4 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700']) }}>
    {{ $slot }}
</div>
