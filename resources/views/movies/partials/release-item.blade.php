@props([
    'release',
    'index' => 0,
])

@if(($release->searchname ?? null) && ($release->guid ?? null))
    <article class="movie-release-row surface-panel-alt rounded-xl border p-3">
        <div class="release-card-container">
            <div class="release-info-wrapper min-w-0 flex-1">
                <a href="{{ url('/details/' . $release->guid) }}"
                   class="line-clamp-2 block break-all text-sm font-semibold leading-5 text-gray-800 hover:text-primary-600 dark:text-gray-200 dark:hover:text-primary-400"
                   title="{{ $release->searchname }}">
                    {{ $release->searchname }}
                </a>

                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    @if($release->size)
                        <span class="release-chip py-1">
                            <i class="fas fa-hard-drive mr-1" aria-hidden="true"></i>{{ number_format($release->size / 1073741824, 2) }} GB
                        </span>
                    @endif

                    @if($release->postdate)
                        <span class="release-chip py-1">
                            <i class="fas fa-calendar-days mr-1" aria-hidden="true"></i>{{ userDate($release->postdate, 'M j, Y') }}
                        </span>
                    @endif

                    @if($release->adddate)
                        <span class="release-chip py-1">
                            <i class="fas fa-clock mr-1" aria-hidden="true"></i>{{ userDateDiffForHumans($release->adddate) }}
                        </span>
                    @endif

                    @if(($release->haspreview ?? 0) == 1)
                        <button type="button"
                                class="preview-badge inline-flex cursor-pointer items-center rounded bg-purple-100 px-2 py-1 text-xs font-medium text-purple-800 transition hover:bg-purple-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 dark:bg-purple-900 dark:text-purple-200 dark:hover:bg-purple-800"
                                data-guid="{{ $release->guid }}"
                                data-image-url="{{ getImageAssetUrl('preview', $release->guid . '_thumb') }}"
                                aria-label="Preview {{ $release->searchname }}">
                            <i class="fas fa-image mr-1" aria-hidden="true"></i>Preview
                        </button>
                    @endif
                </div>
            </div>

            <div class="release-actions">
                <a href="{{ url('/getnzb/' . $release->guid) }}"
                   class="release-action-sm release-action-download focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-500"
                   aria-label="Download {{ $release->searchname }}">
                    <i class="fas fa-download" aria-hidden="true"></i><span>Download</span>
                </a>

                <button type="button"
                        class="add-to-cart release-action-sm release-action-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        data-guid="{{ $release->guid }}"
                        aria-label="Add {{ $release->searchname }} to cart">
                    <i class="fas fa-cart-plus" aria-hidden="true"></i><span>Cart</span>
                </button>

                <a href="{{ url('/details/' . $release->guid) }}"
                   class="release-action-sm release-action-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-500"
                   aria-label="View details for {{ $release->searchname }}">
                    <i class="fas fa-circle-info" aria-hidden="true"></i><span>Details</span>
                </a>

                @if($release->id)
                    <x-report-button :release-id="(int)$release->id" variant="icon" />
                @endif
            </div>
        </div>
    </article>
@endif
