<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ========================================================================
    // WAVE 60.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.52x, p99 4.10x; uncommon mean 4.81x, p99 4.09x); see RustCategorizersAbstractBookConsoleGroupNameReleaseContextW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    public const string W60_RELEASE_CONTEXT_FFI_CDEF = <<<'C'
        ssize_t nntmux_w603_release_context_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w60ReleaseContextBatch(). */
    public const int W60_RELEASE_CONTEXT_TRAILER = 44;

    /** ASCII control bytes (0x00-0x1F and 0x7F). */
    private const string W60_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** shouldCategorize spellings: 1 true, 2 false (anything else non-empty is 3, treated as true). */
    public const array W60_SHOULD_VALUES = ['1' => 1, 'true' => 1, 'yes' => 1, 'on' => 1, '0' => 2, 'false' => 2, 'no' => 2, 'off' => 2];

    /**
     * Feature needles (case-insensitive) for the web, foreign and proper flags.
     *
     * @var array<int, list<string>>
     */
    public const array W60_FEATURE_NEEDLES = [
        2 => ['web-dl', 'webrip', 'web.dl', 'amzn'],
        3 => ['german', 'french', 'spanish', 'italian', 'nordic', 'multi'],
        4 => ['proper', 'repack', 'rerip'],
    ];

    public static function usesW60RustFfi(): bool
    {
        $override = self::w60RustFfiState();

        return \is_bool($override) ? $override : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ReleaseContext Wave 60 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 60.3 release context validation and feature summaries. Each item is
     * `releaseName|groupName|poster|size|categoryId|shouldCategorize` (missing fields are empty, every field is
     * trimmed). Per item `nameState | groupState << 2 | posterState << 4 | sizeState << 6 | sizeBand << 8 |
     * categoryState << 11 | root << 13 | shouldState << 17 | effective << 19 | features << 20 | resolution << 28 |
     * min(nameLength, 1023) << 31 | min(groupLength, 255) << 41`: the name is 0 empty, 3 holding a control byte, 2
     * over 255 bytes, else 1; the group 0 empty, 3 a byte outside `[A-Za-z0-9.+_-]`, 1 a case-insensitive
     * `alt.binaries.` prefix, else 2; the poster 0 empty, 1 one `@` with bytes on both sides, else 2; size follows
     * w60Size() and the category w60Category() (root id / 1000 when valid); shouldCategorize is 0 empty, else its
     * W60_SHOULD_VALUES state (case-insensitive) or 3; effective requires a state other than 2 and a valid name.
     * Feature bits: 1 w60SeasonToken(), 2 an `OF.` prefix or OnlyFans spelling, 4/8/16 W60_FEATURE_NEEDLES web,
     * foreign and proper, 32 `xxx`, 64 an erotica/xxx group and 128 a 19xx/20xx year; resolution is 4 2160p, 3
     * 1080p, 2 720p, 1 480p, else 0. Then four name, four group, three poster, three size, five band, four category
     * and four shouldCategorize state counts, the effective count, eight feature and five resolution counts, the
     * valid size sum and the distinct (case-insensitive) posters and groups.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60ReleaseContextBatch(array $items): array
    {
        return self::usesW60RustFfi() ? self::w60ReleaseContextBatchNative($items) : self::w60ReleaseContextBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60ReleaseContextBatchNative(array $items): array
    {
        return self::w60Invoke($items, \count($items) + self::W60_RELEASE_CONTEXT_TRAILER) ?? self::w60ReleaseContextBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60ReleaseContextBatchPhp(array $items): array
    {
        $out = [];
        $nameStates = $groupStates = $categoryStates = $shouldStates = [0, 0, 0, 0];
        $posterStates = $sizeStates = [0, 0, 0];
        $bands = $resolutions = [0, 0, 0, 0, 0];
        $featureCounts = array_fill(0, 8, 0);
        $effectiveCount = $sizeSum = 0;
        $posters = $groups = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $name = trim($f[0]);
            $group = trim($f[1]);
            $poster = trim($f[2]);
            $nameLength = \strlen($name);
            $groupLength = \strlen($group);
            $nameState = $name === '' ? 0 : (strcspn($name, self::W60_CONTROL_BYTES) !== $nameLength ? 3 : ($nameLength > 255 ? 2 : 1));
            $groupState = match (true) {
                $group === '' => 0,
                strspn($group, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.+-_') !== $groupLength => 3,
                strncasecmp($group, 'alt.binaries.', 13) === 0 => 1,
                default => 2,
            };
            $at = strpos($poster, '@');
            $posterState = $poster === '' ? 0 : ($at !== false && $at > 0 && $at < \strlen($poster) - 1 && substr_count($poster, '@') === 1 ? 1 : 2);
            [$sizeState, $band, $bytes] = self::w60Size(trim($f[3]));
            [$categoryState, $categoryId] = self::w60Category(trim($f[4]));
            $root = $categoryState === 1 ? intdiv($categoryId, 1000) : 0;
            $should = strtolower(trim($f[5]));
            $shouldState = $should === '' ? 0 : (self::W60_SHOULD_VALUES[$should] ?? 3);
            $effective = $shouldState !== 2 && $nameState === 1 ? 1 : 0;
            $lower = strtolower($name);
            $lowerGroup = strtolower($group);
            $features = 0;
            if (self::w60SeasonToken($name)) {
                $features |= 1;
            }
            if (str_starts_with($name, 'OF.') || str_contains($lower, 'onlyfans') || str_contains($lower, 'only-fans') || str_contains($lower, 'only_fans')
                || str_contains($lower, 'only fans')) {
                $features |= 2;
            }
            foreach (self::W60_FEATURE_NEEDLES as $bit => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($lower, $needle)) {
                        $features |= 1 << $bit;

                        break;
                    }
                }
            }
            if (str_contains($lower, 'xxx')) {
                $features |= 32;
            }
            if (str_contains($lowerGroup, 'erotica') || str_contains($lowerGroup, 'xxx')) {
                $features |= 64;
            }
            if (preg_match('/(?<![0-9])(?:19|20)[0-9]{2}(?![0-9])/', $name) === 1) {
                $features |= 128;
            }
            $resolution = str_contains($lower, '2160p') ? 4 : (str_contains($lower, '1080p') ? 3 : (str_contains($lower, '720p') ? 2 : (str_contains($lower, '480p') ? 1 : 0)));
            $nameStates[$nameState]++;
            $groupStates[$groupState]++;
            $posterStates[$posterState]++;
            $sizeStates[$sizeState]++;
            $bands[$band]++;
            $categoryStates[$categoryState]++;
            $shouldStates[$shouldState]++;
            $effectiveCount += $effective;
            $resolutions[$resolution]++;
            for ($b = 0; $b < 8; $b++) {
                $featureCounts[$b] += $features >> $b & 1;
            }
            $sizeSum += $bytes;
            if ($poster !== '') {
                $posters[strtolower($poster)] = true;
            }
            if ($group !== '') {
                $groups[$lowerGroup] = true;
            }
            $out[] = $nameState | $groupState << 2 | $posterState << 4 | $sizeState << 6 | $band << 8 | $categoryState << 11 | $root << 13
                | $shouldState << 17 | $effective << 19 | $features << 20 | $resolution << 28 | min($nameLength, 1023) << 31 | min($groupLength, 255) << 41;
        }
        array_push($out, ...$nameStates, ...$groupStates, ...$posterStates, ...$sizeStates, ...$bands, ...$categoryStates, ...$shouldStates);
        $out[] = $effectiveCount;
        array_push($out, ...$featureCounts, ...$resolutions);
        array_push($out, $sizeSum, \count($posters), \count($groups));

        return $out;
    }

    /**
     * A delimiter-bounded `S` plus 1-3 digits, as STANDALONE_SEASON_TOKEN_REGEX matches it, compared bytewise.
     */
    private static function w60SeasonToken(string $name): bool
    {
        $length = \strlen($name);
        $lower = strtolower($name);
        for ($p = strpos($lower, 's'); $p !== false; $p = $p + 1 < $length ? strpos($lower, 's', $p + 1) : false) {
            if ($p > 0 && ! str_contains('._ -', $name[$p - 1])) {
                continue;
            }
            $k = strspn($name, '0123456789', $p + 1, 4);
            if ($k >= 1 && $k <= 3 && ($p + 1 + $k === $length || str_contains('._ -', $name[$p + 1 + $k]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Size state 0 empty, 1 up to 12 digits and 2 invalid; the band is 0 without a valid size, then 1 below 1 MiB,
     * 2 below 50 MiB, 3 below 500 MiB and 4 larger; then the byte count (0 unless valid).
     *
     * @return array{int, int, int}
     */
    private static function w60Size(string $raw): array
    {
        if ($raw === '') {
            return [0, 0, 0];
        }
        $length = \strlen($raw);
        if ($length > 12 || strspn($raw, '0123456789') !== $length) {
            return [2, 0, 0];
        }
        $bytes = (int) $raw;

        return [1, $bytes < 1_048_576 ? 1 : ($bytes < 52_428_800 ? 2 : ($bytes < 524_288_000 ? 3 : 4)), $bytes];
    }

    /**
     * Category state 0 empty, 1 an id in 1000-8999, 2 another number of up to nine digits and 3 malformed; then the
     * id (0 unless numeric).
     *
     * @return array{int, int}
     */
    private static function w60Category(string $raw): array
    {
        if ($raw === '') {
            return [0, 0];
        }
        $length = \strlen($raw);
        if ($length > 9 || strspn($raw, '0123456789') !== $length) {
            return [3, 0];
        }
        $id = (int) $raw;

        return [$id >= 1000 && $id <= 8999 ? 1 : 2, $id];
    }

    /**
     * Wave 60 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w603', self::W60_RELEASE_CONTEXT_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w60RustFfiState('ffi');
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
                $handle = self::w60RustFfiState('ffi');
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
     * Calls the Wave 60.3 ReleaseContext export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w60Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w60RustFfiState('ffi');
            $out = self::w60RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w60RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W60_RELEASE_CONTEXT_FFI_CDEF.)
            $written = $ffi->nntmux_w603_release_context_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
