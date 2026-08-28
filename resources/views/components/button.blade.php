@blaze(fold: true)
@props([
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
    'icon' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-xl border font-semibold shadow-sm transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 disabled:cursor-not-allowed disabled:opacity-60';

    $sizes = [
        'sm' => 'min-h-9 px-3 py-1.5 text-xs',
        'md' => 'min-h-11 px-4 py-2 text-sm',
        'lg' => 'min-h-12 px-6 py-3 text-base',
        'icon' => 'h-11 w-11 p-0 text-sm',
    ];

    $variants = [
        'primary' => 'border-primary-600 bg-primary-600 text-white hover:bg-primary-700 dark:border-primary-700 dark:bg-primary-700 dark:hover:bg-primary-800',
        'secondary' => 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700',
        'muted' => 'border-transparent bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600',
        'success' => 'border-green-600 bg-green-600 text-white hover:bg-green-700 dark:border-green-700 dark:bg-green-700 dark:hover:bg-green-800',
        'danger' => 'border-red-600 bg-red-600 text-white hover:bg-red-700 dark:border-red-700 dark:bg-red-700 dark:hover:bg-red-800',
        'warning' => 'border-yellow-500 bg-yellow-500 text-white hover:bg-yellow-600 dark:border-yellow-600 dark:bg-yellow-600 dark:hover:bg-yellow-700',
        'ghost' => 'border-transparent bg-transparent text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800',
    ];

    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if($icon)
        <i class="{{ $icon }}" aria-hidden="true"></i>
    @endif
    {{ $slot }}
</button>
