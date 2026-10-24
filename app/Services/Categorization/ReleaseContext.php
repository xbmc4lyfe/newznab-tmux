<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Value object containing release information for categorization.
 */
class ReleaseContext
{
    private const string STANDALONE_SEASON_TOKEN_REGEX = '/(?:^|[._ -])S\d{1,3}(?=$|[._ -])/i';

    private const string NATIVE_CDEF = <<<'C'
        int32_t nntmux_release_context_has_standalone_season_token(const char *name, size_t len);
        int32_t nntmux_release_context_has_only_fans_marker(const char *name, size_t len);
        int32_t nntmux_release_context_has_adult_markers(const char *name, size_t len);
        C;

    /** Set by the Rust predicates when a decision examined a byte >= 0x80 that libc classifies. */
    private const int NATIVE_HIGH_BYTE_SEEN = 0x100;

    private static FFI|false|null $nativeFfi = null;

    private static ?string $highBytes = null;

    public function __construct(
        public readonly string $releaseName,
        public readonly int|string $groupId,
        public readonly string $groupName = '',
        public readonly string $poster = '',
        public readonly bool $categorizeForeign = true,
        public readonly bool $catWebDL = true,
    ) {}

    /**
     * Get the release name in lowercase for case-insensitive matching.
     */
    public function getLowerReleaseName(): string
    {
        return strtolower($this->releaseName);
    }

    /**
     * Check if the release name matches a pattern.
     */
    public function matchesPattern(string $pattern): bool
    {
        return (bool) preg_match($pattern, $this->releaseName);
    }

    /**
     * Check if the release name contains a substring (case-insensitive).
     */
    public function containsString(string $needle): bool
    {
        return stripos($this->releaseName, $needle) !== false;
    }

    /**
     * Check if the group name matches a pattern.
     */
    public function groupMatchesPattern(string $pattern): bool
    {
        return (bool) preg_match($pattern, $this->groupName);
    }

    /**
     * Check whether the release name contains a delimiter-bounded season-pack token.
     */
    public function hasStandaloneSeasonToken(): bool
    {
        // Rust hasStandaloneSeasonTokenNative() missed the 1.5x admission gate (parity test benchmark).
        return $this->hasStandaloneSeasonTokenPhp();
    }

    public function hasStandaloneSeasonTokenPhp(): bool
    {
        return preg_match(self::STANDALONE_SEASON_TOKEN_REGEX, $this->releaseName) === 1;
    }

    public function hasOnlyFansMarker(): bool
    {
        // Rust hasOnlyFansMarkerNative() missed the 1.5x admission gate (parity test benchmark).
        return $this->hasOnlyFansMarkerPhp();
    }

    public function hasOnlyFansMarkerPhp(): bool
    {
        return str_starts_with($this->releaseName, 'OF.') ||
            preg_match('/\bOnly[-_ ]?Fans\b/i', $this->releaseName) === 1;
    }

    /**
     * Check if this release has adult/XXX markers.
     */
    public function hasAdultMarkers(): bool
    {
        // Rust hasAdultMarkersNative() missed the 1.5x admission gate (parity test benchmark).
        return $this->hasAdultMarkersPhp();
    }

    public function hasAdultMarkersPhp(): bool
    {
        if ($this->hasOnlyFansMarker()) {
            return true;
        }

        // Check for explicit XXX markers and common adult keywords/studios
        if (preg_match('/\b(XXX|Porn|Anal|Brazzers|BangBros|Bangbros|NaughtyAmerica|RealityKings|Tushy|Vixen|Blacked|OnlyFans|MetArt|JoyMii|Creampie|MP4-XXX|PureTaboo|Lady[._ -]?Lyne|TeamSkeet|GirlsWay|EvilAngel|Kink|FakeHub|FakeTaxi|SexArt|Nubiles|Defloration|Deeper|Bellesa|Twistys|Mofos|MissaX|LegalPorno|AnalVids|JAV|Hentai|RoccoSiffredi|DivineBitches|Device[._ -]?Bondage|Hogtied|Wired[._ -]?Pussy|Fucking[._ -]?Machines|Ultimate[._ -]?Surrender|Public[._ -]?Disgrace|Sex[._ -]?And[._ -]?Submission|Bound[._ -]?Gang[._ -]?Bangs|Electro[._ -]?Sluts|Whipped[._ -]?Ass|TS[._ -]?Seduction|Infernal[._ -]?Restraints|Sexually[._ -]?Broken)\b/i', $this->releaseName)) {
            return true;
        }

        // Check for adult keywords combined with resolution (likely adult clip)
        if (preg_match('/\b(Fuck|Fucked|Fucking|Cock|Dick|Pussy|Cum|Cumshot|Blowjob|Handjob|MILF|Teen|Lesbian|Threesome|Gangbang|Hardcore|Interracial)\b/i', $this->releaseName) &&
            preg_match('/\b(720p|1080p|2160p|4k|mp4)\b/i', $this->releaseName)) {
            return true;
        }

        return false;
    }

    /** null requests the PHP implementation (no library, unsupported LC_CTYPE or PCRE tables). */
    public function hasStandaloneSeasonTokenNative(): ?bool
    {
        $ffi = self::$nativeFfi ??= RustPipelineLibrary::resolveDomain('release_context_markers', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $result = $ffi->nntmux_release_context_has_standalone_season_token($this->releaseName, \strlen($this->releaseName));
        } catch (Throwable) {
            return null;
        }

        return match ($result) {
            0 => false,
            1 => true,
            default => self::flaggedNativeResult($result),
        };
    }

    public function hasOnlyFansMarkerNative(): ?bool
    {
        $ffi = self::$nativeFfi ??= RustPipelineLibrary::resolveDomain('release_context_markers', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $result = $ffi->nntmux_release_context_has_only_fans_marker($this->releaseName, \strlen($this->releaseName));
        } catch (Throwable) {
            return null;
        }

        return match ($result) {
            0 => false,
            1 => true,
            default => self::flaggedNativeResult($result),
        };
    }

    public function hasAdultMarkersNative(): ?bool
    {
        $ffi = self::$nativeFfi ??= RustPipelineLibrary::resolveDomain('release_context_markers', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $result = $ffi->nntmux_release_context_has_adult_markers($this->releaseName, \strlen($this->releaseName));
        } catch (Throwable) {
            return null;
        }

        return match ($result) {
            0 => false,
            1 => true,
            default => self::flaggedNativeResult($result),
        };
    }

    /**
     * Rust checks LC_CTYPE (C, POSIX or C.UTF-8, as PHP's setlocale(LC_CTYPE, '0') reports it)
     * and classifies bytes like PCRE's default ASCII tables. An explicit setlocale() makes PHP
     * rebuild those tables (macOS C.UTF-8 marks Latin-1 letters as \w), so a result whose
     * decisions examined such a byte is trusted only after probing the live tables.
     */
    private static function flaggedNativeResult(int $result): ?bool
    {
        if (($result & ~1) !== self::NATIVE_HIGH_BYTE_SEEN
            || preg_match('/[\w\d]/', self::$highBytes ??= implode('', array_map(chr(...), range(0x80, 0xFF)))) !== 0) {
            return null;
        }

        return $result === (self::NATIVE_HIGH_BYTE_SEEN | 1);
    }
}
