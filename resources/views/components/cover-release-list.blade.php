@props([
    'releases',
    'totalReleases' => null,
    'max' => 2,
    'showCheckbox' => false,
    'showAddDate' => false,
    'showGroup' => false,
    'showStats' => false,
])

@php
    $releaseItems = $releases instanceof \Illuminate\Support\Collection ? $releases->all() : (array) $releases;
    $maxReleases = (int) $max;
    $displayReleases = array_slice($releaseItems, 0, $maxReleases);
    $totalReleaseCount = $totalReleases ?? count($releaseItems);
    $shouldShowCheckbox = filter_var($showCheckbox, FILTER_VALIDATE_BOOL);
    $shouldShowAddDate = filter_var($showAddDate, FILTER_VALIDATE_BOOL);
    $shouldShowGroup = filter_var($showGroup, FILTER_VALIDATE_BOOL);
    $shouldShowStats = filter_var($showStats, FILTER_VALIDATE_BOOL);
@endphp

@if(!empty($displayReleases))
    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
            Available Releases
            @if($totalReleaseCount > $maxReleases)
                <span class="text-xs font-normal text-gray-500">(Showing {{ $maxReleases }} of {{ $totalReleaseCount }})</span>
            @endif
        </h4>
        <div class="space-y-2">
            @foreach($displayReleases as $release)
                @if(!empty($release->searchname))
                    <div class="surface-panel-alt rounded-lg p-2 border">
                        <div class="space-y-2">
                            <div class="{{ $shouldShowCheckbox ? 'flex items-start justify-between gap-2' : '' }}">
                                <a href="{{ url('/details/' . $release->guid) }}" class="text-sm text-gray-800 dark:text-gray-200 hover:text-primary-600 dark:hover:text-primary-400 font-medium block break-all {{ $shouldShowCheckbox ? 'flex-1' : '' }}" title="{{ $release->searchname }}">
                                    {{ $release->searchname }}
                                </a>
                                @if($shouldShowCheckbox)
                                    <label class="inline-flex items-center shrink-0">
                                        <input type="checkbox" class="chkRelease form-checkbox h-4 w-4 text-primary-600" value="{{ $release->guid }}" name="release[]" @change="onCheckboxChange()">
                                    </label>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                @if(isset($release->size))
                                    <span class="release-chip">
                                        <i class="fas fa-hdd mr-1"></i>{{ number_format($release->size / 1073741824, 2) }} GB
                                    </span>
                                @endif
                                @if(isset($release->postdate))
                                    <span class="release-chip">
                                        <i class="fas fa-calendar-alt mr-1"></i>{{ date('M d, Y H:i', strtotime($release->postdate)) }}
                                    </span>
                                @endif
                                @if($shouldShowAddDate && isset($release->adddate))
                                    <span class="release-chip">
                                        <i class="fas fa-plus-circle mr-1"></i>{{ userDateDiffForHumans($release->adddate) }}
                                    </span>
                                @endif
                                @if((isset($release->nfoid) && !empty($release->nfoid)) || (isset($release->nfostatus) && (int) $release->nfostatus === 1))
                                    <button type="button"
                                            class="nfo-badge inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 hover:bg-yellow-200 dark:hover:bg-yellow-800 transition cursor-pointer"
                                            data-guid="{{ $release->guid }}"
                                            title="View NFO file">
                                        <i class="fas fa-file-alt mr-1"></i> NFO
                                    </button>
                                @endif
                                @if($shouldShowGroup && isset($release->group_name) && !empty($release->group_name))
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200" title="Poster/Uploader">
                                        <i class="fas fa-user mr-1"></i> {{ $release->group_name }}
                                    </span>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="{{ url('/getnzb/' . $release->guid) }}" class="release-action-sm release-action-download px-2 py-0.5">
                                    <i class="fas fa-download mr-1"></i> Download
                                    @if($shouldShowStats && isset($release->grabs) && $release->grabs > 0)
                                        <span class="ml-1 px-1 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 text-xs rounded">{{ $release->grabs }}</span>
                                    @endif
                                </a>
                                <button type="button" class="add-to-cart release-action-sm release-action-primary px-2 py-0.5" data-guid="{{ $release->guid }}">
                                    <i class="fas fa-shopping-cart mr-1"></i> Cart
                                </button>
                                <a href="{{ url('/details/' . $release->guid) }}" class="release-action-sm release-action-muted px-2 py-0.5">
                                    <i class="fas fa-info-circle mr-1"></i> Details
                                    @if($shouldShowStats && isset($release->comments) && $release->comments > 0)
                                        <span class="ml-1 px-1 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 text-xs rounded">{{ $release->comments }}</span>
                                    @endif
                                </a>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif
