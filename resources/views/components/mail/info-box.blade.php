@props([
    'variant' => 'info',
])
@php
    $variantClass = $variant === 'warning' ? 'warning-box' : 'info-box';
@endphp
<div class="{{ $variantClass }}">{{ $slot }}</div>
