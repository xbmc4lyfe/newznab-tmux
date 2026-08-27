{{-- Admin page header with title, icon, optional subtitle and action buttons --}}
@props([
    'title',
    'icon' => null,
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'admin-page-header px-4 py-5 sm:px-6']) }}>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="flex items-center gap-3 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                @if($icon)
                    <span class="admin-page-header__icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" aria-hidden="true">
                        <i class="{{ $icon }}"></i>
                    </span>
                @endif
                <span class="break-words">{{ $title }}</span>
            </h1>
            @if($subtitle)
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ $subtitle }}</p>
            @endif
        </div>
        @if(isset($actions))
            <div class="admin-page-header__actions flex flex-wrap gap-2">
                {{ $actions }}
            </div>
        @endif
    </div>
</div>
