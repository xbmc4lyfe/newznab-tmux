@props([
    'title',
    'description' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'app-page-header px-4 py-5 sm:px-6 sm:py-6']) }}>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="flex items-center gap-3 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white sm:text-3xl">
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
            <div class="app-page-header__actions flex shrink-0 flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
