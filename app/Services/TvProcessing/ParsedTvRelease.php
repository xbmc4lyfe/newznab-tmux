<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Show, season, episode and airdate parsed from a release searchname.
 */
final class ParsedTvRelease
{
    public const TV_FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_from_parse_info(const char *ser, size_t ser_len, char *out, size_t cap);
        ssize_t nntmux_tv_title_without_year(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_tv_country_code(const char *input, size_t len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    public static ?bool $useRustFfi = null;

    private static FFI|false|null $tvFfi = null;

    public function __construct(
        public readonly string $name,
        public readonly string $cleanName,
        public readonly string $country,
        public readonly ?int $season,
        public readonly ?int $episode,
        public readonly bool $seasonPack,
        public readonly ?string $airdate,
        public readonly ?int $year,
    ) {}

    public static function tvFfi(): ?FFI
    {
        if (self::$tvFfi === null) {
            self::$tvFfi = RustPipelineLibrary::resolveDomain('metadata', self::TV_FFI_CDEF) ?? false;
        }

        return self::$tvFfi === false ? null : self::$tvFfi;
    }

    /** PCRE `\s` without `/u` follows LC_CTYPE character tables outside the C locales. */
    public static function tvEnvironmentSupported(): bool
    {
        return \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true);
    }

    /**
     * Build from AbstractTvProvider::parseInfo() output; null when it could not parse the name.
     *
     * @param  array<string, mixed>|false  $info
     */
    public static function fromParseInfo(array|false $info): ?self
    {
        return (self::$useRustFfi ?? false)
            ? self::fromParseInfoNative($info)
            : self::fromParseInfoPhp($info);
    }

    /**
     * @param  array<string, mixed>|false  $info
     */
    public static function fromParseInfoNative(array|false $info): ?self
    {
        if ($info === false) {
            return null;
        }
        if (! self::tvEnvironmentSupported() || ($ffi = self::tvFfi()) === null) {
            return self::fromParseInfoPhp($info);
        }
        try {
            $serialized = serialize($info);
            $serLen = \strlen($serialized);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $serLen + 64, $capacity);
            if ($buffer === null) {
                return self::fromParseInfoPhp($info);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_from_parse_info($serialized, $serLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::fromParseInfoPhp($info);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_from_parse_info($serialized, $serLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::fromParseInfoPhp($info);
            }
            if ($written === 0) {
                return null;
            }
            $output = FFI::string($buffer, $written);
            $parts = explode("\0", $output);
            if (\count($parts) !== 9 || $parts[8] !== '') {
                return self::fromParseInfoPhp($info);
            }

            return new self(
                name: $parts[0],
                cleanName: $parts[1],
                country: $parts[2],
                season: $parts[5] !== '' ? (int) $parts[5] : null,
                episode: $parts[6] !== '' ? (int) $parts[6] : null,
                seasonPack: $parts[4] === '1',
                airdate: $parts[3] !== '' ? $parts[3] : null,
                year: $parts[7] !== '' ? (int) $parts[7] : null,
            );
        } catch (Throwable) {
            return self::fromParseInfoPhp($info);
        }
    }

    /**
     * @param  array<string, mixed>|false  $info
     */
    public static function fromParseInfoPhp(array|false $info): ?self
    {
        if ($info === false || empty($info['name']) || ! isset($info['season'], $info['episode'])) {
            return null;
        }

        $episode = $info['episode'];
        if (\is_array($episode)) {
            $episode = $episode[0] ?? 0;
        }
        $seasonPack = $episode === 'all';
        $airdate = (string) ($info['airdate'] ?? '');
        $year = $info['year'] ?? null;

        return new self(
            name: (string) $info['name'],
            cleanName: (string) ($info['cleanname'] ?? $info['name']),
            country: (string) ($info['country'] ?? ''),
            season: is_numeric($info['season']) ? (int) $info['season'] : null,
            episode: ! $seasonPack && is_numeric($episode) ? (int) $episode : null,
            seasonPack: $seasonPack,
            airdate: $airdate !== '' ? $airdate : null,
            year: is_numeric($year) ? (int) $year : null,
        );
    }

    public function hasEpisodeNumbers(): bool
    {
        return $this->season !== null && $this->season >= 0 && $this->episode !== null && $this->episode > 0;
    }

    public function hasAirdate(): bool
    {
        return $this->airdate !== null;
    }

    public function hasEpisodeReference(): bool
    {
        return $this->hasEpisodeNumbers() || $this->hasAirdate();
    }

    /** The clean name without the " (YYYY)" suffix parseInfo() appends. */
    public function titleWithoutYear(): string
    {
        return (self::$useRustFfi ?? false)
            ? $this->titleWithoutYearNative()
            : $this->titleWithoutYearPhp();
    }

    public function titleWithoutYearNative(): string
    {
        if (! self::tvEnvironmentSupported() || ($ffi = self::tvFfi()) === null) {
            return $this->titleWithoutYearPhp();
        }
        try {
            $raw = $this->cleanName;
            $len = \strlen($raw);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $len + 8, $capacity);
            if ($buffer === null) {
                return $this->titleWithoutYearPhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_title_without_year($raw, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->titleWithoutYearPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_title_without_year($raw, $len, $buffer, $capacity);
            }
            if (\is_int($written) && $written >= 0 && $written <= $capacity) {
                return FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return $this->titleWithoutYearPhp();
    }

    public function titleWithoutYearPhp(): string
    {
        return trim((string) preg_replace('/\s*\((19|20)\d{2}\)$/', '', $this->cleanName));
    }

    /** Two-letter country parsed from the name, or ''. */
    public function countryCode(): string
    {
        return (self::$useRustFfi ?? false)
            ? $this->countryCodeNative()
            : $this->countryCodePhp();
    }

    public function countryCodeNative(): string
    {
        if (($ffi = self::tvFfi()) === null) {
            return $this->countryCodePhp();
        }
        try {
            $raw = $this->country;
            $len = \strlen($raw);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(8, $len), $capacity);
            if ($buffer === null) {
                return $this->countryCodePhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_country_code($raw, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->countryCodePhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_country_code($raw, $len, $buffer, $capacity);
            }
            if (\is_int($written) && $written >= 0 && $written <= $capacity) {
                return FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return $this->countryCodePhp();
    }

    public function countryCodePhp(): string
    {
        return \strlen($this->country) === 2 ? $this->country : '';
    }

    /**
     * Wave 47.3 batch name normalization. Defaults to Rust: the paired benchmark cleared 1.5x on
     * common and uncommon mean and p99; useW47ParsedReleaseRustFfi(false) selects PHP.
     */
    public const bool W47_PARSED_RELEASE_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_tv_parsed_release_batch(const char *blob, size_t blob_len, size_t count, char *out, size_t out_cap);
        C;

    /** Values written per name by w47NormalizeBatch(). */
    public const int W47_FIELDS_PER_NAME = 7;

    /** Two-letter country tokens recognised after the show name; the order matches the Rust table. */
    public const array W47_COUNTRIES = [
        'US' => true, 'UK' => true, 'CA' => true, 'AU' => true, 'NZ' => true, 'IE' => true, 'DE' => true,
        'FR' => true, 'NL' => true, 'SE' => true, 'NO' => true, 'DK' => true, 'FI' => true, 'ES' => true,
        'IT' => true, 'JP' => true, 'KR' => true, 'BR' => true, 'MX' => true, 'IN' => true,
    ];

    private static ?bool $w47RustFfiOverride = null;

    private static FFI|false|null $w47RustFfiHandle = null;

    /** Null restores W47_PARSED_RELEASE_RUST_FFI_DEFAULT. */
    public static function useW47ParsedReleaseRustFfi(?bool $enable = true): void
    {
        self::$w47RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w47RustFfiHandle = null;
        }
    }

    public static function usesW47ParsedReleaseRustFfi(): bool
    {
        return self::$w47RustFfiOverride ?? self::W47_PARSED_RELEASE_RUST_FFI_DEFAULT;
    }

    /**
     * Seven values per name: season (-1 none), episode (-1 none, 0 season pack), airdate as
     * YYYYMMDD (0 none), year (0 none), country as two packed bytes (0 none), clean-name token
     * count and the FNV-1a 32 digest of the lowercase, space-joined clean name.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function w47NormalizeBatch(array $names): array
    {
        return self::usesW47ParsedReleaseRustFfi()
            ? self::w47NormalizeBatchNative($names)
            : self::w47NormalizeBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function w47NormalizeBatchNative(array $names): array
    {
        $count = \count($names);
        if ($count === 0) {
            return [];
        }
        $blob = implode("\0", $names);
        if (substr_count($blob, "\0") !== $count - 1 || ($ffi = self::w47RustFfi()) === null) {
            return self::w47NormalizeBatchPhp($names);
        }
        try {
            $bytes = $count * self::W47_FIELDS_PER_NAME * 8;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $bytes, $capacity);
            if ($buffer === null) {
                return self::w47NormalizeBatchPhp($names);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_tv_parsed_release_batch($blob, \strlen($blob), $count, $buffer, $capacity);
            if ($written !== $bytes) {
                return self::w47NormalizeBatchPhp($names);
            }
            $values = unpack('P*', FFI::string($buffer, $bytes));
            if ($values === false) {
                return self::w47NormalizeBatchPhp($names);
            }

            /** @var list<int> */
            return array_values($values);
        } catch (Throwable) {
            return self::w47NormalizeBatchPhp($names);
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function w47NormalizeBatchPhp(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $tokens = preg_split('/[. _\-\[\]()]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens === false) {
                $tokens = [];
            }
            $season = -1;
            $episode = -1;
            $airdate = 0;
            $year = 0;
            $country = 0;
            $nameOpen = true;
            $clean = [];
            $n = \count($tokens);
            for ($i = 0; $i < $n;) {
                $token = $tokens[$i];
                $y = self::w47Year($token);
                if ($y !== null && $airdate === 0 && $i + 2 < $n
                    && ($month = self::w47TwoDigits($tokens[$i + 1], 12)) !== null
                    && ($day = self::w47TwoDigits($tokens[$i + 2], 31)) !== null) {
                    $airdate = $y * 10000 + $month * 100 + $day;
                    $nameOpen = false;
                    $i += 3;

                    continue;
                }
                $i++;
                if ($y !== null && ($clean !== [] || ! $nameOpen)) {
                    if ($year === 0) {
                        $year = $y;
                    }
                    $nameOpen = false;

                    continue;
                }
                if ($season < 0) {
                    if (preg_match('/\A[sS](\d{1,3})[eE](\d{1,4})/', $token, $m) === 1
                        || preg_match('/\A(\d{1,2})[xX](\d{2,3})\z/', $token, $m) === 1) {
                        $season = (int) $m[1];
                        $episode = (int) $m[2];
                        $nameOpen = false;

                        continue;
                    }
                    if (preg_match('/\A[sS](\d{1,3})\z/', $token, $m) === 1) {
                        $season = (int) $m[1];
                        $episode = 0;
                        $nameOpen = false;

                        continue;
                    }
                }
                if ($country === 0 && $clean !== [] && isset(self::W47_COUNTRIES[$token])) {
                    $country = (\ord($token[0]) << 8) | \ord($token[1]);
                    $nameOpen = false;

                    continue;
                }
                if ($nameOpen) {
                    $clean[] = strtolower($token);
                }
            }
            array_push($out, $season, $episode, $airdate, $year, $country, \count($clean), self::w47Fnv(implode(' ', $clean)));
        }

        return $out;
    }

    private static function w47Year(string $token): ?int
    {
        return \strlen($token) === 4 && strspn($token, '0123456789') === 4
            && (str_starts_with($token, '19') || str_starts_with($token, '20'))
            ? (int) $token
            : null;
    }

    private static function w47TwoDigits(string $token, int $max): ?int
    {
        if (\strlen($token) !== 2 || strspn($token, '0123456789') !== 2) {
            return null;
        }
        $value = (int) $token;

        return $value >= 1 && $value <= $max ? $value : null;
    }

    private static function w47Fnv(string $bytes): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('N', hash('fnv1a32', $bytes, true));

        return $digest[1];
    }

    private static function w47RustFfi(): ?FFI
    {
        if (self::$w47RustFfiHandle === null) {
            self::$w47RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w47', self::W47_FFI_CDEF) ?? false;
        }

        return self::$w47RustFfiHandle === false ? null : self::$w47RustFfiHandle;
    }

    // ========================================================================
    // WAVE 62.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.47x, p99 7.27x; uncommon mean 7.63x, p99 5.65x); see RustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_PARSED_TV_RELEASE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w623_parsed_tv_release_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 62.3 batch. */
    public const int W62_PARSED_TV_RELEASE_TRAILER = 20;

    /** C0 control bytes and DEL. */
    private const string W62_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ParsedTvRelease Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.3 parsed-release summaries. Each item is `name|cleanName|season|episode|airdate|country|year`, every
     * field trimmed. Per item `nameState | fromName << 2 | yearSuffix << 3 | seasonState << 4 | episodeState << 6 |
     * airdateState << 8 | countryState << 10 | yearState << 12 | kind << 14 | season << 17 | episode << 27 |
     * (year - 1900) << 41 | countryIndex << 49 | min(titleLength, 255) << 54`: the name is 0 empty, 1 up to 255 bytes
     * without control bytes or 2 invalid; an empty clean name falls back to the name (fromName); a trailing
     * `(19xx)`/`(20xx)` is stripped (yearSuffix) and the rest right-trimmed into the title; the season is 0 empty,
     * 1 a 1-3 digit number or 2 invalid; the episode is 0 empty, 1 a 1-4 digit number, 2 invalid or 3 `all` (any
     * case); the airdate is 0 empty, 1 `YYYY-MM-DD` (`-`, `.` or `/` used twice) in 1900-2099 or 2 invalid; the
     * country is 0 empty, 1 a W47_COUNTRIES code (any case, its 1-based index), 2 another two-letter code or 3
     * invalid; the year is 0 empty, 1 a 19xx/20xx year or 2 invalid. The kind is 0 invalid name, 1 season pack,
     * 2 numbered episode (episode above 0), 3 daily airdate, 4 season only or 5 no episode reference. Then the six
     * kind, three name and four country counts, the distinct titles (case-insensitive, valid names), the title
     * length sum, the highest season and episode, the earliest and latest airdate (YYYYMMDD, 0 none) and the
     * stripped year suffixes.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseSummaryBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62ReleaseSummaryBatchNative($items) : self::w62ReleaseSummaryBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseSummaryBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_PARSED_TV_RELEASE_TRAILER) ?? self::w62ReleaseSummaryBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseSummaryBatchPhp(array $items): array
    {
        $out = [];
        $kinds = [0, 0, 0, 0, 0, 0];
        $names = [0, 0, 0];
        $countries = [0, 0, 0, 0];
        $titles = [];
        $titleSum = $maxSeason = $maxEpisode = $minAir = $maxAir = $suffixes = 0;
        $codes = array_keys(self::W47_COUNTRIES);
        foreach ($items as $item) {
            [$rawName, $rawClean, $rawSeason, $rawEpisode, $rawAir, $rawCountry, $rawYear] = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $name = trim($rawName);
            $nameState = self::w62TextState($name);
            $clean = trim($rawClean);
            $fromName = $clean === '' && $name !== '' ? 1 : 0;
            if ($clean === '') {
                $clean = $name;
            }
            $n = \strlen($clean);
            $suffix = $n >= 6 && $clean[$n - 1] === ')' && $clean[$n - 6] === '(' && self::w62Year(substr($clean, $n - 5, 4)) !== null ? 1 : 0;
            $title = $suffix === 1 ? rtrim(substr($clean, 0, $n - 6)) : $clean;
            $text = trim($rawSeason);
            $value = self::w62Digits($text, 1, 3);
            [$seasonState, $season] = $text === '' ? [0, 0] : ($value === null ? [2, 0] : [1, $value]);
            $text = trim($rawEpisode);
            $value = self::w62Digits($text, 1, 4);
            [$episodeState, $episode] = match (true) {
                $text === '' => [0, 0],
                strtolower($text) === 'all' => [3, 0],
                $value === null => [2, 0],
                default => [1, $value],
            };
            $text = trim($rawAir);
            $air = $text === '' ? 0 : self::w62Airdate($text);
            $airState = $text === '' ? 0 : ($air > 0 ? 1 : 2);
            $text = trim($rawCountry);
            $position = self::w62Country2($text) ? array_search(strtoupper($text), $codes, true) : false;
            [$countryState, $countryIndex] = match (true) {
                $text === '' => [0, 0],
                ! self::w62Country2($text) => [3, 0],
                $position === false => [2, 0],
                default => [1, (int) $position + 1],
            };
            $text = trim($rawYear);
            $year = self::w62Year($text);
            $yearState = $text === '' ? 0 : ($year === null ? 2 : 1);
            $kind = match (true) {
                $nameState !== 1 => 0,
                $episodeState === 3 && $seasonState === 1 => 1,
                $seasonState === 1 && $episodeState === 1 && $episode > 0 => 2,
                $airState === 1 => 3,
                $seasonState === 1 => 4,
                default => 5,
            };
            if ($nameState === 1 && $title !== '') {
                $titles[strtolower($title)] = true;
            }
            $kinds[$kind]++;
            $names[$nameState]++;
            $countries[$countryState]++;
            $titleSum += \strlen($title);
            if ($seasonState === 1) {
                $maxSeason = max($maxSeason, $season);
            }
            if ($episodeState === 1) {
                $maxEpisode = max($maxEpisode, $episode);
            }
            if ($airState === 1) {
                $minAir = $minAir === 0 ? $air : min($minAir, $air);
                $maxAir = max($maxAir, $air);
            }
            $suffixes += $suffix;
            $out[] = $nameState | $fromName << 2 | $suffix << 3 | $seasonState << 4 | $episodeState << 6 | $airState << 8 | $countryState << 10
                | $yearState << 12 | $kind << 14 | min($season, 1023) << 17 | min($episode, 16383) << 27 | ($year !== null ? $year - 1900 : 0) << 41
                | $countryIndex << 49 | min(\strlen($title), 255) << 54;
        }
        array_push($out, ...$kinds, ...$names, ...$countries);
        array_push($out, \count($titles), $titleSum, $maxSeason, $maxEpisode, $minAir, $maxAir, $suffixes);

        return $out;
    }

    /** Two ASCII letters. */
    private static function w62Country2(string $text): bool
    {
        return \strlen($text) === 2 && strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') === 2;
    }

    /** `YYYY-MM-DD` (separator `-`, `.` or `/`, used twice) in 1900-2099 as YYYYMMDD, else 0. */
    private static function w62Airdate(string $text): int
    {
        if (\strlen($text) !== 10) {
            return 0;
        }
        $separator = $text[4];
        if (($separator !== '-' && $separator !== '.' && $separator !== '/') || $text[7] !== $separator) {
            return 0;
        }
        $year = self::w62Digits(substr($text, 0, 4), 4, 4);
        $month = self::w62Digits(substr($text, 5, 2), 2, 2);
        $day = self::w62Digits(substr($text, 8, 2), 2, 2);
        if ($year === null || $month === null || $day === null || $year < 1900 || $year > 2099 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return 0;
        }

        return $year * 10000 + $month * 100 + $day;
    }

    /** 0 empty, 1 up to 255 bytes without control bytes, 2 otherwise. */
    private static function w62TextState(string $text): int
    {
        $length = \strlen($text);

        return $length === 0 ? 0 : ($length <= 255 && strcspn($text, self::W62_CONTROL_CHARS) === $length ? 1 : 2);
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w62Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /** A 19xx/20xx four-digit year, else null. */
    private static function w62Year(string $text): ?int
    {
        return \strlen($text) === 4 && (str_starts_with($text, '19') || str_starts_with($text, '20')) ? self::w62Digits($text, 4, 4) : null;
    }

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w623', self::W62_PARSED_TV_RELEASE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w62RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 62.3 ParsedTvRelease export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_PARSED_TV_RELEASE_FFI_CDEF.)
            $written = $ffi->nntmux_w623_parsed_tv_release_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
