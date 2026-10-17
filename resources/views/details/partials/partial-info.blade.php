<!-- Incomplete upload warning -->
@if(isset($release->articles_expected) && (int) $release->articles_expected > 0 && (int) $release->articles_found < (int) $release->articles_expected)
    <div class="detail-partial-card bg-linear-to-r from-amber-50 to-yellow-50 dark:from-amber-900 dark:to-yellow-900 rounded-lg p-6 border border-amber-200 dark:border-amber-800">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2 flex items-center">
            <i class="fas fa-exclamation-triangle mr-2 text-amber-600 dark:text-amber-400"></i> Incomplete Upload
        </h3>
        <p class="text-sm text-gray-700 dark:text-gray-300">
            Only {{ number_format((int) $release->articles_found) }} of {{ number_format((int) $release->articles_expected) }} articles
            ({{ number_format(100 * (int) $release->articles_found / (int) $release->articles_expected, 1) }}%) were found on Usenet.
            The download may need PAR2 repair or may not complete.
        </p>
    </div>
@endif
