{{-- Consistent table header cell --}}
@props([
    'align' => 'left',
    'width' => null,
])

@php
    $alignClass = match($align) {
        'center' => 'text-center',
        'right' => 'text-right',
        default => 'text-left',
    };
    $widthClass = $width ? "w-{$width}" : '';
@endphp

<th {{ $attributes->merge(['class' => "px-4 py-2.5 {$alignClass} text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide {$widthClass}"]) }}>
    {{ $slot }}
</th>
