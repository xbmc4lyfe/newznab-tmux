<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SteamService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Stringable;
use Throwable;

class SteamLookupGame extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_steam_lookup_format_basic_info(
        uint32_t has_title,
        const char *title_ptr,
        size_t title_len,
        uint32_t has_steamid,
        const char *steamid_ptr,
        size_t steamid_len,
        uint32_t has_type,
        const char *type_ptr,
        size_t type_len,
        uint32_t has_publisher,
        const char *publisher_ptr,
        size_t publisher_len,
        const char *developers_ptr,
        size_t developers_len,
        uint32_t has_releasedate,
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t has_genres,
        const char *genres_ptr,
        size_t genres_len,
        const char *platforms_ptr,
        size_t platforms_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_steam_lookup_format_price_rows(
        uint32_t has_currency,
        const char *currency_ptr,
        size_t currency_len,
        uint32_t has_final_formatted,
        const char *final_formatted_ptr,
        size_t final_formatted_len,
        double final_price,
        double initial_price,
        int64_t discount_percent,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_steam_lookup_format_reviews_rows(
        uint32_t has_rating,
        const char *rating_ptr,
        size_t rating_len,
        int64_t total_positive,
        int64_t total_negative,
        int64_t total_reviews,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminAnidbBookMediaCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'steam:lookup
                            {title : The game title to search for}
                            {--details : Show full game details}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Look up a game on Steam by title';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Execute the console command.
     */
    public function handle(SteamService $steamService): int
    {
        $title = $this->argument('title');
        $showDetails = $this->option('details');
        $outputJson = $this->option('json');

        $this->info("Searching for: {$title}");
        $this->newLine();

        // Search for the game
        $appId = $steamService->search($title);

        if ($appId === null) {
            $this->error('No matching game found.');

            return Command::FAILURE;
        }

        $this->info("Found game with Steam App ID: {$appId}");
        $this->newLine();

        if ($showDetails) {
            $details = $steamService->getGameDetails($appId);

            if ($details === false) {
                $this->error('Could not retrieve game details.');

                return Command::FAILURE;
            }

            if ($outputJson) {
                $this->line(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->displayGameDetails($details);
            }

            // Get additional data
            $reviews = $steamService->getReviewsSummary($appId);
            $playerCount = $steamService->getPlayerCount($appId);

            if ($reviews !== null) {
                $this->newLine();
                $this->info('Reviews:');
                $this->table(
                    ['Metric', 'Value'],
                    self::formatReviewsRows($reviews)
                );
            }

            if ($playerCount !== null) {
                $this->info('Current Players: '.number_format($playerCount));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Display game details in a formatted way.
     *
     * @param  array<string, mixed>  $details
     */
    protected function displayGameDetails(array $details): void
    {
        $this->info('=== Game Details ===');
        $this->newLine();

        // Basic info table
        $basicInfo = self::formatBasicInfoRows($details);

        $this->table(['Property', 'Value'], $basicInfo);

        // Scores
        if (! empty($details['metacritic_score'])) {
            $this->newLine();
            $this->info("Metacritic Score: {$details['metacritic_score']}");
        }

        // Price
        if (! empty($details['price'])) {
            $price = $details['price'];
            $this->newLine();
            $this->info('Pricing:');

            $priceInfo = self::formatPriceRows(is_array($price) ? $price : []);

            $this->table(['Field', 'Value'], $priceInfo);
        }

        // Description
        if (! empty($details['description'])) {
            $this->newLine();
            $this->info('Description:');
            $this->line(wordwrap($details['description'], 80));
        }

        // URLs
        $this->newLine();
        $this->info('Links:');
        $this->line("Store: {$details['directurl']}");

        if (! empty($details['website'])) {
            $this->line("Website: {$details['website']}");
        }

        if (! empty($details['metacritic_url'])) {
            $this->line("Metacritic: {$details['metacritic_url']}");
        }

        // Stats
        if (! empty($details['achievements']) || ! empty($details['recommendations'])) {
            $this->newLine();
            $this->info('Stats:');

            if (! empty($details['achievements'])) {
                $this->line("Achievements: {$details['achievements']}");
            }

            if (! empty($details['recommendations'])) {
                $this->line('Recommendations: '.number_format($details['recommendations']));
            }
        }

        // Categories (multiplayer, etc.)
        if (! empty($details['categories'])) {
            $this->newLine();
            $this->info('Features:');
            $this->line(implode(', ', $details['categories']));
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatBasicInfoRows(array $details): array
    {
        if (self::$useRustFfi) {
            return self::formatBasicInfoRowsNative($details);
        }

        return self::formatBasicInfoRowsPhp($details);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatBasicInfoRowsPhp(array $details): array
    {
        $developers = is_array($details['developers'] ?? null) ? $details['developers'] : [];
        $platforms = is_array($details['platforms'] ?? null) ? $details['platforms'] : [];

        return [
            ['Title', $details['title'] ?? 'N/A'],
            ['Steam ID', $details['steamid'] ?? 'N/A'],
            ['Type', ucfirst((string) ($details['type'] ?? 'game'))],
            ['Publisher', $details['publisher'] ?? 'N/A'],
            ['Developers', implode(', ', $developers) ?: 'N/A'],
            ['Release Date', $details['releasedate'] ?? 'N/A'],
            ['Genres', $details['genres'] ?? 'N/A'],
            ['Platforms', implode(', ', $platforms)],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatBasicInfoRowsNative(array $details): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatBasicInfoRowsPhp($details);
        }

        foreach (['title', 'steamid', 'type', 'publisher', 'releasedate', 'genres'] as $key) {
            $val = $details[$key] ?? null;
            if ($val !== null && ! is_scalar($val) && ! $val instanceof Stringable) {
                return self::formatBasicInfoRowsPhp($details);
            }
        }
        if ((isset($details['developers']) && ! is_array($details['developers']))
            || (isset($details['platforms']) && ! is_array($details['platforms']))) {
            return self::formatBasicInfoRowsPhp($details);
        }

        $hasTitle = isset($details['title']) ? 1 : 0;
        $titleStr = $hasTitle === 1 ? (string) $details['title'] : '';

        $hasSteamId = isset($details['steamid']) ? 1 : 0;
        $steamIdStr = $hasSteamId === 1 ? (string) $details['steamid'] : '';

        $hasType = isset($details['type']) ? 1 : 0;
        $typeStr = $hasType === 1 ? (string) $details['type'] : '';

        $hasPublisher = isset($details['publisher']) ? 1 : 0;
        $publisherStr = $hasPublisher === 1 ? (string) $details['publisher'] : '';

        $developersStr = implode(', ', $details['developers'] ?? []);

        $hasReleaseDate = isset($details['releasedate']) ? 1 : 0;
        $releaseDateStr = $hasReleaseDate === 1 ? (string) $details['releasedate'] : '';

        $hasGenres = isset($details['genres']) ? 1 : 0;
        $genresStr = $hasGenres === 1 ? (string) $details['genres'] : '';

        $platformsStr = implode(', ', $details['platforms'] ?? []);

        $approxLen = strlen($titleStr) + strlen($steamIdStr) + strlen($typeStr)
            + strlen($publisherStr) + strlen($developersStr) + strlen($releaseDateStr)
            + strlen($genresStr) + strlen($platformsStr) + 64;

        try {
            $lens = $ffi->new('size_t[8]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, $approxLen), $cap);
            if ($buf === null) {
                return self::formatBasicInfoRowsPhp($details);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_steam_lookup_format_basic_info',
                $hasTitle,
                $titleStr,
                strlen($titleStr),
                $hasSteamId,
                $steamIdStr,
                strlen($steamIdStr),
                $hasType,
                $typeStr,
                strlen($typeStr),
                $hasPublisher,
                $publisherStr,
                strlen($publisherStr),
                $developersStr,
                strlen($developersStr),
                $hasReleaseDate,
                $releaseDateStr,
                strlen($releaseDateStr),
                $hasGenres,
                $genresStr,
                strlen($genresStr),
                $platformsStr,
                strlen($platformsStr),
                $lens,
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::formatBasicInfoRowsPhp($details);
            }

            $raw = $rc > 0 ? FFI::string($buf, $rc) : '';
            $values = [];
            $offset = 0;
            for ($i = 0; $i < 8; $i++) {
                $len = (int) $lens[$i];
                $values[] = $len > 0 ? substr($raw, $offset, $len) : '';
                $offset += $len;
            }

            return [
                ['Title', isset($details['title']) && ! is_string($details['title']) ? $details['title'] : $values[0]],
                ['Steam ID', isset($details['steamid']) && ! is_string($details['steamid']) ? $details['steamid'] : $values[1]],
                ['Type', $values[2]],
                ['Publisher', isset($details['publisher']) && ! is_string($details['publisher']) ? $details['publisher'] : $values[3]],
                ['Developers', $values[4]],
                ['Release Date', isset($details['releasedate']) && ! is_string($details['releasedate']) ? $details['releasedate'] : $values[5]],
                ['Genres', isset($details['genres']) && ! is_string($details['genres']) ? $details['genres'] : $values[6]],
                ['Platforms', $values[7]],
            ];
        } catch (Throwable) {
            return self::formatBasicInfoRowsPhp($details);
        }
    }

    /**
     * @param  array<string, mixed>  $price
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatPriceRows(array $price): array
    {
        if (self::$useRustFfi) {
            return self::formatPriceRowsNative($price);
        }

        return self::formatPriceRowsPhp($price);
    }

    /**
     * @param  array<string, mixed>  $price
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatPriceRowsPhp(array $price): array
    {
        $priceInfo = [
            ['Currency', $price['currency'] ?? 'USD'],
            ['Price', $price['final_formatted'] ?? sprintf('$%.2f', $price['final'] ?? 0)],
        ];

        if (($price['discount_percent'] ?? 0) > 0) {
            $priceInfo[] = ['Original', sprintf('$%.2f', $price['initial'] ?? 0)];
            $priceInfo[] = ['Discount', "{$price['discount_percent']}%"];
        }

        return $priceInfo;
    }

    /**
     * @param  array<string, mixed>  $price
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatPriceRowsNative(array $price): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatPriceRowsPhp($price);
        }

        if ((isset($price['currency']) && ! is_string($price['currency']))
            || (isset($price['final_formatted']) && ! is_string($price['final_formatted']))
            || (isset($price['final']) && ! is_int($price['final']) && ! is_float($price['final']))
            || (isset($price['initial']) && ! is_int($price['initial']) && ! is_float($price['initial']))
            || (isset($price['discount_percent']) && ! is_int($price['discount_percent']))) {
            return self::formatPriceRowsPhp($price);
        }

        $hasCurrency = isset($price['currency']) ? 1 : 0;
        $currencyStr = $price['currency'] ?? '';

        $hasFinalFormatted = isset($price['final_formatted']) ? 1 : 0;
        $finalFormattedStr = $price['final_formatted'] ?? '';

        $finalPrice = (float) ($price['final'] ?? 0);
        $initialPrice = (float) ($price['initial'] ?? 0);
        $discountPercent = (int) ($price['discount_percent'] ?? 0);

        try {
            $lens = $ffi->new('size_t[4]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch(
                $ffi,
                max(128, strlen($currencyStr) + strlen($finalFormattedStr) + 64),
                $cap
            );
            if ($buf === null) {
                return self::formatPriceRowsPhp($price);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_steam_lookup_format_price_rows',
                $hasCurrency,
                $currencyStr,
                strlen($currencyStr),
                $hasFinalFormatted,
                $finalFormattedStr,
                strlen($finalFormattedStr),
                $finalPrice,
                $initialPrice,
                $discountPercent,
                $lens,
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::formatPriceRowsPhp($price);
            }

            $raw = $rc > 0 ? FFI::string($buf, $rc) : '';
            $values = [];
            $offset = 0;
            for ($i = 0; $i < 4; $i++) {
                $len = (int) $lens[$i];
                $values[] = $len > 0 ? substr($raw, $offset, $len) : '';
                $offset += $len;
            }

            $rows = [
                ['Currency', $values[0]],
                ['Price', $values[1]],
            ];
            if ($discountPercent > 0) {
                $rows[] = ['Original', $values[2]];
                $rows[] = ['Discount', $values[3]];
            }

            return $rows;
        } catch (Throwable) {
            return self::formatPriceRowsPhp($price);
        }
    }

    /**
     * @param  array<string, mixed>  $reviews
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatReviewsRows(array $reviews): array
    {
        if (self::$useRustFfi) {
            return self::formatReviewsRowsNative($reviews);
        }

        return self::formatReviewsRowsPhp($reviews);
    }

    /**
     * @param  array<string, mixed>  $reviews
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatReviewsRowsPhp(array $reviews): array
    {
        return [
            ['Rating', $reviews['review_score_desc'] ?? 'Unknown'],
            ['Positive', number_format($reviews['total_positive'] ?? 0)],
            ['Negative', number_format($reviews['total_negative'] ?? 0)],
            ['Total', number_format($reviews['total_reviews'] ?? 0)],
        ];
    }

    /**
     * @param  array<string, mixed>  $reviews
     * @return list<array{0: string, 1: mixed}>
     */
    public static function formatReviewsRowsNative(array $reviews): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatReviewsRowsPhp($reviews);
        }

        if (isset($reviews['review_score_desc']) && ! is_string($reviews['review_score_desc'])) {
            return self::formatReviewsRowsPhp($reviews);
        }
        foreach (['total_positive', 'total_negative', 'total_reviews'] as $key) {
            $val = $reviews[$key] ?? 0;
            if (! is_int($val) || abs($val) > 9_000_000_000_000_000) {
                return self::formatReviewsRowsPhp($reviews);
            }
        }

        $hasRating = isset($reviews['review_score_desc']) ? 1 : 0;
        $ratingStr = $reviews['review_score_desc'] ?? '';
        $positive = (int) ($reviews['total_positive'] ?? 0);
        $negative = (int) ($reviews['total_negative'] ?? 0);
        $total = (int) ($reviews['total_reviews'] ?? 0);

        try {
            $lens = $ffi->new('size_t[4]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, strlen($ratingStr) + 96), $cap);
            if ($buf === null) {
                return self::formatReviewsRowsPhp($reviews);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_steam_lookup_format_reviews_rows',
                $hasRating,
                $ratingStr,
                strlen($ratingStr),
                $positive,
                $negative,
                $total,
                $lens,
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::formatReviewsRowsPhp($reviews);
            }

            $raw = $rc > 0 ? FFI::string($buf, $rc) : '';
            $values = [];
            $offset = 0;
            for ($i = 0; $i < 4; $i++) {
                $len = (int) $lens[$i];
                $values[] = $len > 0 ? substr($raw, $offset, $len) : '';
                $offset += $len;
            }

            return [
                ['Rating', $values[0]],
                ['Positive', $values[1]],
                ['Negative', $values[2]],
                ['Total', $values[3]],
            ];
        } catch (Throwable) {
            return self::formatReviewsRowsPhp($reviews);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
