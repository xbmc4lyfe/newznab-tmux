@blaze
@props(['label', 'for', 'help' => null])

<div class="space-y-2">
    @if($label)
        <x-label :for="$for" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ $label }}
        </x-label>
    @endif

    {{ $slot }}

    @if($help)
        <small class="block text-xs leading-5 text-gray-600 dark:text-gray-400">{{ $help }}</small>
    @endif
</div>
