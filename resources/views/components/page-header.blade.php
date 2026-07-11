@props([
    'title',
    'description' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'px-6 py-6']) }}>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="flex items-center gap-3 text-2xl font-bold text-gray-900 dark:text-gray-100 sm:text-3xl">
                @if($icon)
                    <i class="{{ $icon }} text-primary-600 dark:text-primary-400" aria-hidden="true"></i>
                @endif
                <span class="break-words">{{ $title }}</span>
            </h1>
            @if($description)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 sm:text-base">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
