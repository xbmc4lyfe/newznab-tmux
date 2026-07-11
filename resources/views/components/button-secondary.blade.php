@props([
    'type' => 'button',
])

<x-button :type="$type" variant="secondary" {{ $attributes }}>
    {{ $slot }}
</x-button>
