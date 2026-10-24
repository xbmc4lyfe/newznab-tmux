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
}
