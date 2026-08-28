@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div class="surface-panel rounded-xl shadow-sm transition-colors duration-200">
    @php
        $currentCategoryLabel = match (true) {
            is_object($catname ?? null) => $catname->title ?? 'All',
            is_string($catname ?? null) && strtolower($catname) !== 'all' => $catname,
            default => 'All',
        };
        $browseTitle = !empty($parentcat) && $parentcat !== 'All' ? $parentcat : 'Browse releases';
    @endphp
    <x-page-header :title="$browseTitle" :current="$currentCategoryLabel" eyebrow="Discover and download" description="Browse the latest indexed releases, refine the list, and send selections to your download basket." icon="fas fa-compass">
        <x-slot:stats>
            <span class="workspace-hero__stat"><i class="fas fa-layer-group" aria-hidden="true"></i>{{ number_format($results->total()) }} releases</span>
            <span class="workspace-hero__stat"><i class="fas fa-folder-open" aria-hidden="true"></i>{{ $currentCategoryLabel }}</span>
        </x-slot:stats>
    </x-page-header>

    @if($results->count() > 0)
        @php
            $searchPlaceholder = 'Search';
            if (!empty($parentcat) && $parentcat !== 'All') {
                $searchPlaceholder .= ' in ' . $parentcat;
                if (!empty($catname) && $catname !== 'All' && $catname !== 'all') {
                    $searchPlaceholder .= ' ' . $catname;
                }
            }
            $searchPlaceholder .= '...';
        @endphp

        <x-release-results-panel :results="$results" :show-thumbs="true" date-field="adddate" :show-top-pagination="true">
            <x-slot:beforeActions>
                @if(isset($shows))
                    <div class="flex flex-wrap gap-2 text-sm">
                        <a href="{{ route('series') }}" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300" title="View available TV series">Series List</a>
                        <span class="text-gray-400 dark:text-gray-500">|</span>
                        <a href="{{ route('trending-tv') }}" class="text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300" title="View trending TV shows"><i class="fas fa-fire mr-1"></i>Trending TV</a>
                        <span class="text-gray-400 dark:text-gray-500">|</span>
                        <a href="{{ route('myshows') }}" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300" title="Manage your shows">Manage My Shows</a>
                        <span class="text-gray-400 dark:text-gray-500">|</span>
                        <a href="{{ url('/rss/myshows?dl=1&i=' . auth()->id() . '&api_token=' . auth()->user()->api_token) }}" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300" title="RSS Feed">RSS Feed</a>
                    </div>
                @endif

                @if(isset($covgroup) && $covgroup != '' || isset($shows) && $shows)
                    <x-view-toggle
                        current-view="list"
                        :covgroup="$covgroup ?? null"
                        :category="$catname ?? 'All'"
                        :parentcat="$parentcat ?? null"
                        :shows="$shows ?? false"
                    />
                @endif
            </x-slot:beforeActions>

            <x-slot:toolbarRight>
                <x-inline-search :placeholder="$searchPlaceholder" :category="$category ?? null" />
            </x-slot:toolbarRight>
        </x-release-results-panel>
    @else
        <x-empty-state
            icon="fas fa-search"
            title="No releases found"
            message="Try adjusting your search criteria or browse other categories."
        />
    @endif

    {{-- All modals (preview, mediainfo, filelist, NFO) are included globally via layouts.main --}}
</div>
@endsection
