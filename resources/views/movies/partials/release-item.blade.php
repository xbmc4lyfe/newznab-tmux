{{-- Release Item Partial --}}
{{-- Props: $release (object with ->id, ->guid, ->searchname, ->size, ->postdate, ->adddate, ->haspreview), $layout, $index --}}

@props([
    'release',
    'layout' => 2,
    'index' => 0,
])

@if(($release->searchname ?? null) && ($release->guid ?? null))
    <div class="surface-panel-alt rounded-lg p-3 border">
        <div class="release-card-container {{ $layout == 1 ? 'flex flex-row items-start justify-between gap-4' : 'flex flex-col space-y-3' }}">
            <div class="release-info-wrapper {{ $layout == 1 ? 'flex-1 min-w-0' : '' }}">
                {{-- Release Name --}}
                <a href="{{ url('/details/' . $release->guid) }}"
                   class="text-sm text-gray-800 dark:text-gray-200 hover:text-primary-600 dark:hover:text-primary-400 font-medium block break-all"
                   title="{{ $release->searchname }}">
                    {{ $release->searchname }}
                </a>

                {{-- Info Badges --}}
                <div class="flex flex-wrap items-center gap-2 mt-2">
                    @if($release->size)
                        <span class="release-chip py-1">
                            <i class="fas fa-hdd mr-1"></i>{{ number_format($release->size / 1073741824, 2) }} GB
                        </span>
                    @endif

                    @if($release->postdate)
                        <span class="release-chip py-1">
                            <i class="fas fa-calendar-alt mr-1"></i>{{ userDate($release->postdate, 'M d, Y H:i') }}
                        </span>
                    @endif

                    @if($release->adddate)
                        <span class="release-chip py-1">
                            <i class="fas fa-plus-circle mr-1"></i>{{ userDateDiffForHumans($release->adddate) }}
                        </span>
                    @endif

                    @if(($release->haspreview ?? 0) == 1)
                        <button type="button"
                                class="preview-badge inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200 hover:bg-purple-200 dark:hover:bg-purple-800 transition cursor-pointer"
                                data-guid="{{ $release->guid }}"
                                title="View preview image">
                            <i class="fas fa-image mr-1"></i> Preview
                        </button>
                    @endif
                </div>
            </div>

            {{-- Action Buttons --}}
            <div class="release-actions flex flex-wrap items-center gap-2 {{ $layout == 1 ? 'shrink-0' : 'mt-2' }}">
                <a href="{{ url('/getnzb/' . $release->guid) }}"
                   class="release-action-sm release-action-download">
                    <i class="fas fa-download mr-1"></i> Download
                </a>

                <button type="button"
                        class="add-to-cart release-action-sm release-action-primary"
                        data-guid="{{ $release->guid }}">
                    <i class="fas fa-shopping-cart mr-1"></i> Cart
                </button>

                <a href="{{ url('/details/' . $release->guid) }}"
                   class="release-action-sm release-action-muted">
                    <i class="fas fa-info-circle mr-1"></i> Details
                </a>

                @if($release->id)
                    <x-report-button :release-id="(int)$release->id" variant="icon" />
                @endif
            </div>
        </div>
    </div>
@endif
