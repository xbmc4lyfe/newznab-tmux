<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central view of which external metadata sources are usable, so callers can
 * skip unconfigured ones silently and the reason is logged once per start/day.
 */
final class MetadataSources
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_metadata_sources_missing_key(const char *val, size_t val_len, const char *env, size_t env_len, char *out, size_t cap);
        ssize_t nntmux_metadata_sources_disabled(const char *val, size_t val_len, const char *env, size_t env_len, char *out, size_t cap);
        ssize_t nntmux_metadata_sources_summary_lines(const char *ser, size_t ser_len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvPipelineAndIgdbDataNodeParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public const string TMDB = 'tmdb';

    public const string OMDB = 'omdb';

    public const string TVDB = 'tvdb';

    public const string FANARTTV = 'fanarttv';

    public const string TRAKT = 'trakt';

    public const string IMDB_SCRAPER = 'imdb_scraper';

    public const string IMDBAPI_DEV = 'imdbapi_dev';

    public const string IGDB = 'igdb';

    public const string ISBNDB = 'isbndb';

    private const string SUMMARY_CACHE_PREFIX = 'metadata_sources:summary:';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TMDB => 'TMDB',
            self::OMDB => 'OMDb',
            self::TVDB => 'TVDB',
            self::FANARTTV => 'Fanart.tv',
            self::TRAKT => 'Trakt',
            self::IMDB_SCRAPER => 'IMDb scraping',
            self::IMDBAPI_DEV => 'imdbapi.dev',
            self::IGDB => 'IGDB',
            self::ISBNDB => 'ISBNdb',
        ];
    }

    /**
     * Why a source is unavailable, or null when it can be used.
     */
    public static function unavailableReason(string $source): ?string
    {
        return match ($source) {
            self::TMDB => self::missingKey('tmdb.api_key', 'TMDB_APIKEY'),
            self::OMDB => self::missingKey('nntmux_api.omdb_api_key', 'OMDB_APIKEY'),
            self::TVDB => self::missingKey('tvdb.api_key', 'TVDB_APIKEY'),
            self::FANARTTV => self::missingKey('nntmux_api.fanarttv_api_key', 'FANARTTV_APIKEY'),
            self::TRAKT => self::missingKey('nntmux_api.trakttv_api_key', 'TRAKTTV_APIKEY'),
            self::IMDB_SCRAPER => self::disabled('nntmux_api.imdb_scraper_enabled', 'IMDB_SCRAPER_ENABLED'),
            self::IMDBAPI_DEV => self::disabled('nntmux_api.imdbapi_dev_enabled', 'IMDBAPI_DEV_ENABLED'),
            self::IGDB => self::missingKey('igdb.credentials.client_id', 'TWITCH_CLIENT_ID')
                ?? self::missingKey('igdb.credentials.client_secret', 'TWITCH_CLIENT_SECRET'),
            self::ISBNDB => self::missingKey('nntmux_api.isbndb_api_key', 'ISBNDB_API_KEY'),
            default => throw new \InvalidArgumentException('Unknown metadata source: '.$source),
        };
    }

    public static function isAvailable(string $source): bool
    {
        return self::unavailableReason($source) === null;
    }

    /**
     * @return array<string, array{label: string, available: bool, reason: string|null}>
     */
    public static function all(): array
    {
        $sources = [];
        foreach (self::labels() as $source => $label) {
            $reason = self::unavailableReason($source);
            $sources[$source] = ['label' => $label, 'available' => $reason === null, 'reason' => $reason];
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    public static function summaryLines(): array
    {
        return self::usesRustFfi() ? self::summaryLinesNative() : self::summaryLinesPhp();
    }

    /**
     * @return list<string>
     */
    public static function summaryLinesNative(): array
    {
        return self::formatSummaryLinesNative(self::all());
    }

    /**
     * @return list<string>
     */
    public static function summaryLinesPhp(): array
    {
        return self::formatSummaryLinesPhp(self::all());
    }

    /**
     * @param  array<string, array{label: string, available: bool, reason: string|null}>  $sources
     * @return list<string>
     */
    public static function formatSummaryLines(array $sources): array
    {
        return self::usesRustFfi()
            ? self::formatSummaryLinesNative($sources)
            : self::formatSummaryLinesPhp($sources);
    }

    /**
     * @param  array<string, array{label: string, available: bool, reason: string|null}>  $sources
     * @return list<string>
     */
    public static function formatSummaryLinesNative(array $sources): array
    {
        if ($sources === []) {
            return [];
        }
        if (($ffi = self::ffi()) === null) {
            return self::formatSummaryLinesPhp($sources);
        }
        try {
            $ser = serialize($sources);
            $len = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 2), $capacity);
            if ($buffer === null) {
                return self::formatSummaryLinesPhp($sources);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_metadata_sources_summary_lines($ser, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatSummaryLinesPhp($sources);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_metadata_sources_summary_lines($ser, $len, $buffer, $capacity);
            }
            if ($written === 0) {
                return [];
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::formatSummaryLinesPhp($sources);
            }

            return explode("\0", FFI::string($buffer, $written));
        } catch (Throwable) {
            return self::formatSummaryLinesPhp($sources);
        }
    }

    /**
     * @param  array<string, array{label: string, available: bool, reason: string|null}>  $sources
     * @return list<string>
     */
    public static function formatSummaryLinesPhp(array $sources): array
    {
        $lines = [];
        foreach ($sources as $source) {
            if (! $source['available']) {
                $lines[] = 'Metadata source '.$source['label'].': '.$source['reason'].', lookups disabled';
            }
        }

        return $lines;
    }

    /**
     * Log the summary unconditionally, e.g. when processing starts.
     *
     * @return list<string>
     */
    public static function logSummary(): array
    {
        Cache::put(self::summaryCacheKey(), true, now()->addDay());

        $lines = self::summaryLines();
        foreach ($lines as $line) {
            Log::info($line);
        }

        return $lines;
    }

    /**
     * Log the summary the first time this is called on a given day.
     */
    public static function logDailySummary(): bool
    {
        // Best effort: the tmux monitor calls this every cycle, and a cache outage must not stop it.
        try {
            if (! Cache::add(self::summaryCacheKey(), true, now()->addDay())) {
                return false;
            }
        } catch (Throwable $e) {
            Log::debug('Metadata source summary skipped: '.$e->getMessage());

            return false;
        }

        foreach (self::summaryLines() as $line) {
            Log::info($line);
        }

        return true;
    }

    private static function summaryCacheKey(): string
    {
        return self::SUMMARY_CACHE_PREFIX.now()->toDateString();
    }

    public static function missingKey(string $configKey, string $env): ?string
    {
        return self::usesRustFfi()
            ? self::missingKeyNative($configKey, $env)
            : self::missingKeyPhp($configKey, $env);
    }

    public static function missingKeyNative(string $configKey, string $env): ?string
    {
        $raw = config($configKey, '');
        if (! \is_scalar($raw) && $raw !== null && ! $raw instanceof \Stringable) {
            return self::missingKeyPhp($configKey, $env);
        }
        $val = (string) $raw;
        if (($ffi = self::ffi()) === null) {
            return self::missingKeyPhp($configKey, $env);
        }
        $valLen = \strlen($val);
        $envLen = \strlen($env);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $envLen + 32), $capacity);
        if ($buffer === null) {
            return self::missingKeyPhp($configKey, $env);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_metadata_sources_missing_key($val, $valLen, $env, $envLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::missingKeyPhp($configKey, $env);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_metadata_sources_missing_key($val, $valLen, $env, $envLen, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::missingKeyPhp($configKey, $env);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::missingKeyPhp($configKey, $env);
        }
    }

    public static function missingKeyPhp(string $configKey, string $env): ?string
    {
        return trim((string) config($configKey, '')) === '' ? 'no API key ('.$env.')' : null;
    }

    public static function disabled(string $configKey, string $env): ?string
    {
        return self::usesRustFfi()
            ? self::disabledNative($configKey, $env)
            : self::disabledPhp($configKey, $env);
    }

    public static function disabledNative(string $configKey, string $env): ?string
    {
        $raw = config($configKey, true);
        if (! \is_bool($raw) && ! \is_int($raw) && ! \is_string($raw) && $raw !== null) {
            return self::disabledPhp($configKey, $env);
        }
        $val = (string) $raw;
        if (($ffi = self::ffi()) === null) {
            return self::disabledPhp($configKey, $env);
        }
        $valLen = \strlen($val);
        $envLen = \strlen($env);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $envLen + 32), $capacity);
        if ($buffer === null) {
            return self::disabledPhp($configKey, $env);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_metadata_sources_disabled($val, $valLen, $env, $envLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::disabledPhp($configKey, $env);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_metadata_sources_disabled($val, $valLen, $env, $envLen, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::disabledPhp($configKey, $env);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::disabledPhp($configKey, $env);
        }
    }

    public static function disabledPhp(string $configKey, string $env): ?string
    {
        return filter_var(config($configKey, true), FILTER_VALIDATE_BOOL) ? null : 'disabled by '.$env;
    }
}
