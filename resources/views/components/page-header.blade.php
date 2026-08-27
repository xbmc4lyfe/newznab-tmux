@props([
    'title',
    'description' => null,
    'icon' => null,
    'eyebrow' => 'Explore',
    'current' => null,
])

<header {{ $attributes->merge(['class' => 'app-page-header workspace-hero relative mb-6 overflow-hidden rounded-2xl px-5 py-6 text-white shadow-lg sm:px-7 sm:py-8']) }}>
    <div class="workspace-hero__glow workspace-hero__glow--one" aria-hidden="true"></div>
    <div class="workspace-hero__glow workspace-hero__glow--two" aria-hidden="true"></div>

    <div class="relative z-10 flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
        <div class="min-w-0 max-w-3xl">
            <nav aria-label="Breadcrumb" class="mb-4 text-sm text-white/70">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ url('/') }}" class="rounded hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70">Home</a></li>
                    <li aria-hidden="true"><i class="fas fa-chevron-right text-[0.65rem]"></i></li>
                    <li aria-current="page" class="font-medium text-white">{{ $current ?? $title }}</li>
                </ol>
            </nav>

            <div class="flex items-center gap-3">
                @if($icon)
                    <span class="workspace-hero__icon" aria-hidden="true"><i class="{{ $icon }}"></i></span>
                @endif
                <div>
                    @if($eyebrow)
                        <p class="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-white/65">{{ $eyebrow }}</p>
                    @endif
                    <h1 class="break-words text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $title }}</h1>
                </div>
            </div>

            @if($description)
                <p class="mt-4 max-w-2xl text-sm leading-6 text-white/75 sm:text-base">{{ $description }}</p>
            @endif

            @isset($stats)
                <div class="mt-5 flex flex-wrap gap-2 text-sm">
                    {{ $stats }}
                </div>
            @endisset
        </div>

        @isset($actions)
            <div class="app-page-header__actions flex shrink-0 flex-wrap items-center gap-3">
                {{ $actions }}
            </div>
        @endisset
    </div>
</header>
