@props([
    'icon' => 'fas fa-circle-info',
    'title',
    'message' => null,
])

<div {{ $attributes->merge(['class' => 'px-6 py-12 text-center']) }}>
    <i class="{{ $icon }} mb-4 text-4xl text-gray-400 dark:text-gray-600" aria-hidden="true"></i>
    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h3>
    @if($message)
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
    @endif
    @if(trim($slot) !== '')
        <div class="mt-4">
            {{ $slot }}
        </div>
    @endif
</div>
