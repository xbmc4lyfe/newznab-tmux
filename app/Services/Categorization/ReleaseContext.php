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

    /**
     * Wave 44.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44ParityTest.php measures at
     * least 3.8x (mean and p99, common and uncommon batches). PHP stays the fallback when the
     * library is unavailable.
     * Named W443 so it stays distinct from the existing marker predicates.
     */
    public const bool W443_RUST_FFI_DEFAULT = true;

    public const string W443_FFI_CDEF = <<<'C'
        ssize_t nntmux_w443_release_context_marker_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Release context marker substrings, in match order. The first class with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W443_CONTEXT_MARKERS = [
        'adult' => ['xxx', 'onlyfans', 'porn', 'brazzers', 'milf'],
        'pack' => ['complete', 'season', 'collection', 'boxset'],
        'web' => ['web-dl', 'webrip', 'web.dl', 'amzn'],
        'foreign' => ['german', 'french', 'spanish', 'italian', 'nordic', 'multi'],
    ];

    /** @var list<string> */
    public const array W443_PROPER_MARKERS = ['proper', 'repack', 'rerip'];

    /** Exactly 7 0x1F-separated decimals of 1-19 digits. */
    private const string W443_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){6}\d{1,19}\z/';

    private static FFI|false|null $nativeFfi = null;

    private static ?string $highBytes = null;

    private static ?bool $w443RustFfiOverride = null;

    private static FFI|false|null $w443RustFfiHandle = null;

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
     * Enables (true) or disables (false) the Wave 44.3 Rust helper; null restores
     * W443_RUST_FFI_DEFAULT.
     */
    public static function useW443RustFfi(?bool $enable = true): void
    {
        self::$w443RustFfiOverride = $enable;
    }

    public static function usesW443RustFfi(): bool
    {
        return self::$w443RustFfiOverride ?? self::W443_RUST_FFI_DEFAULT;
    }

    public static function resetW443RustFfi(): void
    {
        self::$w443RustFfiOverride = null;
        self::$w443RustFfiHandle = null;
    }

    /**
     * Context marker summary for a batch of release names. Each lowercased name adds to its first
     * W443_CONTEXT_MARKERS class, else to unknown, and separately to proper when it holds any
     * W443_PROPER_MARKERS entry. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, adult: int, pack: int, web: int, foreign: int, unknown: int, proper: int}
     */
    public static function summarizeContextMarkerBatch(array $releaseNames): array
    {
        return (self::usesW443RustFfi() ? self::summarizeContextMarkerBatchNative($releaseNames) : null)
            ?? self::summarizeContextMarkerBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, adult: int, pack: int, web: int, foreign: int, unknown: int, proper: int}
     */
    public static function summarizeContextMarkerBatchPhp(array $releaseNames): array
    {
        $counts = ['adult' => 0, 'pack' => 0, 'web' => 0, 'foreign' => 0];
        $unknown = 0;
        $proper = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W443_PROPER_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $proper++;

                    break;
                }
            }
            foreach (self::W443_CONTEXT_MARKERS as $class => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $counts[$class]++;

                        continue 3;
                    }
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'adult' => $counts['adult'],
            'pack' => $counts['pack'],
            'web' => $counts['web'],
            'foreign' => $counts['foreign'],
            'unknown' => $unknown,
            'proper' => $proper,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, adult: int, pack: int, web: int, foreign: int, unknown: int, proper: int}|null
     */
    public static function summarizeContextMarkerBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $fields = self::callW443Native(implode('', $releaseNames), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'adult' => (int) $fields[1],
            'pack' => (int) $fields[2],
            'web' => (int) $fields[3],
            'foreign' => (int) $fields[4],
            'unknown' => (int) $fields[5],
            'proper' => (int) $fields[6],
        ];
    }

    /**
     * Calls this class's Wave 44.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W443_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW443Native(string $blob, string $lens): ?array
    {
        $ffi = self::w443RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w443_release_context_marker_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w443_release_context_marker_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W443_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w443RustFfi(): ?FFI
    {
        if (self::$w443RustFfiHandle === null) {
            self::$w443RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w443', self::W443_FFI_CDEF) ?? false;
        }

        return self::$w443RustFfiHandle === false ? null : self::$w443RustFfiHandle;
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
