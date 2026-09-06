@props(['result'])

@php
    $reportedCount = (int) ($result->total_report_count ?? $result->report_count ?? 0);
    $responseCount = (int) ($result->report_response_count ?? 0);
@endphp

<div class="release-badges flex flex-wrap items-center gap-2 empty:hidden">
    @if($reportedCount > 0)
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200"
              title="Reported: {{ $result->all_report_reasons ?? \App\Models\ReleaseReport::reasonKeysToLabels($result->report_reasons ?? '') }} | Original report: {{ $result->latest_report_reason ?? 'Unknown' }} - {{ $result->latest_report_description ?? 'No additional report details were provided.' }}">
            <i aria-hidden="true" class="fas fa-flag mr-1"></i> Reported ({{ $reportedCount }})
        </span>
    @endif
    @if($responseCount > 0)
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200"
              title="Staff response available on release details">
            <i aria-hidden="true" class="fas fa-reply mr-1"></i> Response
        </span>
    @endif
    @if(!empty($result->failed_count) && $result->failed_count > 0)
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200"
              title="{{ $result->failed_count }} user(s) reported download failure">
            <i aria-hidden="true" class="fas fa-exclamation-triangle mr-1"></i> Failed ({{ $result->failed_count }})
        </span>
    @endif
    @if(isset($result->haspreview) && $result->haspreview == 1)
        <button type="button"
                class="preview-badge inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200 hover:bg-purple-200 dark:hover:bg-purple-800 transition cursor-pointer"
                data-guid="{{ $result->guid }}"
                data-image-url="{{ getImageAssetUrl('preview', $result->guid . '_thumb') }}"
                title="View preview image">
            <i aria-hidden="true" class="fas fa-image mr-1"></i> Preview
        </button>
    @endif
    @if(isset($result->jpgstatus) && $result->jpgstatus == 1)
        <button type="button"
                class="sample-badge inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 hover:bg-green-200 dark:hover:bg-green-800 transition cursor-pointer"
                data-guid="{{ $result->guid }}"
                data-image-url="{{ getImageAssetUrl('sample', $result->guid . '_thumb') }}"
                title="View sample image">
            <i aria-hidden="true" class="fas fa-images mr-1"></i> Sample
        </button>
    @endif
    @if(!empty($result->videos_id) && (int) $result->videos_id > 0)
        <a href="{{ url('/series/' . $result->videos_id) }}"
           class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200 hover:bg-indigo-200 dark:hover:bg-indigo-800 transition"
           title="View full series">
            <i aria-hidden="true" class="fas fa-tv mr-1"></i> View Series
        </a>
    @endif
    @if(isset($result->reid) && $result->reid != null)
        <button type="button"
                class="mediainfo-badge inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 dark:bg-primary-900/50 text-primary-800 dark:text-primary-200 hover:bg-primary-200 dark:hover:bg-primary-800 transition cursor-pointer"
                data-release-id="{{ $result->id }}"
                title="View media info">
            <i aria-hidden="true" class="fas fa-info-circle mr-1"></i> Media Info
        </button>
    @endif
    @if(isset($result->nfostatus) && $result->nfostatus == 1)
        <button type="button"
                class="nfo-badge inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 hover:bg-yellow-200 dark:hover:bg-yellow-800 transition cursor-pointer"
                data-guid="{{ $result->guid }}"
                title="View NFO file">
            <i aria-hidden="true" class="fas fa-file-alt mr-1"></i> NFO
        </button>
    @endif
</div>
