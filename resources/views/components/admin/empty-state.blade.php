@props([
    'icon' => 'fas fa-circle-info',
    'title',
    'message' => null,
])

<div {{ $attributes->merge(['class' => 'empty-state px-6 py-14 text-center']) }}>
    <span class="empty-state__icon mb-5 inline-flex h-16 w-16 items-center justify-center rounded-2xl" aria-hidden="true"><i class="{{ $icon }} text-2xl"></i></span>
    <h3 class="text-lg font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $title }}</h3>
    @if($message)
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
    @endif
    @if(trim($slot) !== '')
        <div class="mt-4">
            {{ $slot }}
        </div>
    @endif
</div>
