{{-- Admin card wrapper with consistent styling --}}
@props([
    'noPadding' => false,
])

<div {{ $attributes->merge(['class' => 'admin-card surface-panel rounded-2xl border shadow-sm']) }}>
    {{ $slot }}
</div>
