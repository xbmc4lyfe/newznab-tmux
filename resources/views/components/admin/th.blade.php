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

<th {{ $attributes->merge(['class' => "px-4 py-3 {$alignClass} text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider {$widthClass}"]) }}>
    {{ $slot }}
</th>
