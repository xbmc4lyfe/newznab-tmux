<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
