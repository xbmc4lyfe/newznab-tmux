<!-- Incomplete upload warning -->
@if(isset($release->articles_expected))
    <div class="detail-partial-card bg-linear-to-r from-amber-50 to-yellow-50 dark:from-amber-900 dark:to-yellow-900 rounded-lg p-6 border border-amber-200 dark:border-amber-800">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2 flex items-center">
            <i class="fas fa-exclamation-triangle mr-2 text-amber-600 dark:text-amber-400"></i> Incomplete Upload
        </h3>
        <p class="text-sm text-gray-700 dark:text-gray-300">
            This release was published before its upload was complete:
            {{ number_format((int) $release->articles_found) }} of {{ number_format((int) $release->articles_expected) }} declared articles
            @if(isset($release->files_expected) && (int) $release->files_found < (int) $release->files_expected)
                in {{ number_format((int) $release->files_found) }} of {{ number_format((int) $release->files_expected) }} declared files
            @endif
            were found on Usenet. The download may need PAR2 repair or may not complete.
        </p>
    </div>
@endif
