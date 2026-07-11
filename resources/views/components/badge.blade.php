@blaze
@props([
    'type' => 'default',
    'size' => 'sm',
])

@php
    $sizes = [
        'xs' => 'px-2 py-0.5 text-xs',
        'sm' => 'px-2.5 py-0.5 text-xs',
        'md' => 'px-3 py-1 text-sm',
    ];

    $tones = [
        'default' => 'bg-primary-100 text-primary-800 dark:bg-primary-900/50 dark:text-primary-200',
        'info' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
        'success' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'danger' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'warning' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    ];

    $classes = 'inline-flex items-center rounded-full font-medium '.($sizes[$size] ?? $sizes['sm']).' '.($tones[$type] ?? $tones['default']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
