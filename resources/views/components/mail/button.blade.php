@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    $colorClass = match ($color) {
        'success' => 'button button-success',
        'danger' => 'button button-danger',
        'secondary' => 'button button-secondary',
        default => 'button',
    };
@endphp
<div style="text-align: {{ $align }}; margin: 24px 0;">
    <a href="{{ $url }}" class="{{ $colorClass }}" target="_blank" rel="noopener">{{ $slot }}</a>
</div>
