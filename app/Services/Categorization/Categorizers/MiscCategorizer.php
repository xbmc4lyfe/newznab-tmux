<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use App\Traits\DetectsHashedNames;
use FFI;
use Throwable;

/**
 * Categorizer for miscellaneous content and hash detection.
 * This runs FIRST with high priority to detect hashes early and prevent
 * them from being incorrectly categorized by group-based or content-based rules.
 */
class MiscCategorizer extends AbstractCategorizer
{
    use DetectsHashedNames;

    /**
     * Wave 42.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersBookConsoleGroupMiscMovieW42ParityTest.php measures at least 2.0x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W423 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W423_RUST_FFI_DEFAULT = true;

    public const string W423_FFI_CDEF = <<<'C'
        ssize_t nntmux_w423_misc_content_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** @var list<int> */
    public const array W423_HASH_LENGTHS = [32, 40, 64];

    public const string W423_HEX_DIGITS = '0123456789abcdefABCDEF';

    /**
     * Misc content marker substrings, in match order. The first class with a substring of the
     * lowercased, trimmed release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W423_MISC_CONTENT_MARKERS = [
        'archive' => ['.rar', '.zip', '.7z', '.par2', '.tar', '.001'],
        'dataset' => ['dataset', '.csv', '.sql', 'dump', 'backup'],
    ];

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W423_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w423RustFfiOverride = null;

    private static FFI|false|null $w423RustFfiHandle = null;

    /** @var array<int, string> */
    private const array SIGNAL_MARKER_BIT_MAP = [
        1 => 'season_episode',
        2 => 'season_pack',
        4 => 'resolution',
        8 => 'codec',
        16 => 'source',
        32 => 'audio',
        64 => 'scene_tag',
        128 => 'year',
        256 => 'release_group',
        512 => 'known_extension',
    ];

    /** @var array<int, string> */
    private const array MATCHED_BY_NAMES = [
        1 => 'hash_md5',
        2 => 'hash_sha1',
        3 => 'hash_sha256',
        4 => 'hash_generic',
        5 => 'hash_base64_like',
        6 => 'hash_uuid',
        7 => 'hash_hex',
        8 => 'gibberish_zero_vowels',
        9 => 'obfuscated_uppercase',
        10 => 'obfuscated_mixed_alphanumeric',
        11 => 'obfuscated_usenet_filename',
        12 => 'obfuscated_pattern',
        13 => 'gibberish_no_signal',
        14 => 'gibberish_random_transitions',
        15 => 'gibberish_no_word_structure',
        16 => 'gibberish_random_digits',
        17 => 'archive',
        18 => 'dataset',
        19 => 'data_dump',
    ];

    protected int $priority = 1; // Highest priority - run first to catch hashes

    public function getName(): string
    {
        return 'Misc';
    }

    /**
     * Combined signal inspection and categorization in a single pass.
     *
     * @return array{0: array{coreName: string, coreLength: int, signalScore: int, markers: list<string>, lowSignal: bool}, 1: CategorizationResult}
     */
    public function inspectAndCategorize(ReleaseContext|string $context): array
    {
        $name = $context instanceof ReleaseContext ? $context->releaseName : $context;
        $raw = RustPipelineLibrary::miscInspectAndCategorize($name);
        if ($raw !== null) {
            return $this->decodeRustInspectAndCategorize($raw);
        }

        $analysis = $this->inspectSignalsPhp($name);
        $result = $this->categorizePhp($name, $analysis);

        return [$analysis, $result];
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        $name = $context->releaseName;
        $raw = RustPipelineLibrary::miscInspectAndCategorize($name);
        if ($raw !== null) {
            return $this->decodeRustInspectAndCategorize($raw)[1];
        }

        return $this->categorizePhp($name);
    }

    /**
     * Pure-PHP fallback for miscellaneous categorization.
     *
     * @param  array{coreName: string, coreLength: int, signalScore: int, markers: list<string>, lowSignal: bool}|null  $analysis
     */
    public function categorizePhp(string $name, ?array $analysis = null): CategorizationResult
    {
        // Check for hash patterns first
        if ($result = $this->checkHash($name)) {
            return $result;
        }

        $analysis ??= $this->inspectSignalsPhp($name);
        if ($this->isZeroVowelLongToken($analysis['coreName'])) {
            return $this->matched(Category::OTHER_HASHED, 0.78, 'gibberish_zero_vowels');
        }

        // Check for obfuscated/encoded patterns
        if ($result = $this->checkObfuscated($name)) {
            return $result;
        }

        // Check low-signal names that only contain random-looking tokens
        if ($result = $this->checkLowSignal($name)) {
            return $result;
        }

        // Check for gibberish patterns (character-analysis heuristics)
        if ($result = $this->checkGibberish($name)) {
            return $result;
        }

        // Check for archive formats
        if ($result = $this->checkArchive($name)) {
            return $result;
        }

        // Check for dataset/dump patterns
        if ($result = $this->checkDataset($name)) {
            return $result;
        }

        return $this->noMatch();
    }

    /**
     * Inspect a release name for media signal markers used by the safety-net pipe.
     *
     * @return array{coreName: string, coreLength: int, signalScore: int, markers: list<string>, lowSignal: bool}
     */
    public function inspectSignals(ReleaseContext|string $context): array
    {
        $name = $context instanceof ReleaseContext ? $context->releaseName : $context;
        $raw = RustPipelineLibrary::miscInspectAndCategorize($name);
        if ($raw !== null) {
            return $this->decodeRustInspectAndCategorize($raw)[0];
        }

        return $this->inspectSignalsPhp($name);
    }

    /**
     * Pure-PHP fallback for signal marker inspection.
     *
     * @return array{coreName: string, coreLength: int, signalScore: int, markers: list<string>, lowSignal: bool}
     */
    public function inspectSignalsPhp(ReleaseContext|string $context): array
    {
        $name = $context instanceof ReleaseContext ? $context->releaseName : $context;
        $cleaned = $this->stripExtensionsForAnalysis($name);
        $coreName = $this->getCoreNameWithoutSeparators($cleaned);

        $patterns = [
            'season_episode' => '/\bS\d{1,3}[._ -]?E\d{1,4}\b/i',
            'season_pack' => '/\bS\d{1,3}\b/i',
            'resolution' => '/\b(480p|576p|720p|1080[pi]?|2160p|4k|uhd)\b/i',
            'codec' => '/\b(x264|x265|h\.?264|h\.?265|hevc|xvid|av1)\b/i',
            'source' => '/\b(bluray|bdrip|brrip|hdtv|web[._ -]?dl|web[._ -]?rip|dvdrip|remux)\b/i',
            'audio' => '/\b(aac|ac3|ddp|dts|flac|mp3)\b/i',
            'scene_tag' => '/\b(proper|repack|internal|limited|complete|dubbed|subbed|readnfo)\b/i',
            'year' => '/\b(19|20)\d{2}\b/',
            'release_group' => '/-[A-Za-z0-9][A-Za-z0-9._-]{1,20}$/',
            'known_extension' => '/\.(mkv|avi|mp4|mp3|flac|iso|epub|pdf|exe|nzb|rar|7z)$/i',
        ];

        $markers = [];
        foreach ($patterns as $marker => $pattern) {
            if (preg_match($pattern, $name)) {
                $markers[] = $marker;
            }
        }

        $signalScore = count($markers);
        $isCoreToken = preg_match('/^[A-Za-z0-9+\/_=-]+$/', $coreName) === 1;
        $lowSignal = $signalScore === 0
            && $isCoreToken
            && strlen($coreName) >= 12
            && ! $this->hasStrongWordStructure($name, $coreName);

        return [
            'coreName' => $coreName,
            'coreLength' => strlen($coreName),
            'signalScore' => $signalScore,
            'markers' => $markers,
            'lowSignal' => $lowSignal,
        ];
    }

    protected function checkHash(string $name): ?CategorizationResult
    {
        // MD5 hash (32 hex characters)
        if ($this->isBoundedMd5Hash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_md5');
        }

        // SHA-1 hash (40 hex characters)
        if ($this->isBoundedSha1Hash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_sha1');
        }

        // SHA-256 hash (64 hex characters)
        if ($this->isBoundedSha256Hash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_sha256');
        }

        // Generic long hex hash (32-128 chars)
        if ($this->isBoundedGenericHash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_generic');
        }

        if ($this->isBase64LikeToken($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.9, 'hash_base64_like');
        }

        // Strip extensions and separators for core-name checks
        $cleaned = $this->stripExtensionsForAnalysis($name);
        $coreName = $this->getCoreNameWithoutSeparators($cleaned);

        // UUID pattern
        if ($this->isUuidPattern($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_uuid');
        }

        // Pure hex string (≥16 chars)
        if ($this->isPureHexString($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_hex');
        }

        return null;
    }

    protected function checkObfuscated(string $name): ?CategorizationResult
    {
        // Release names consisting only of uppercase letters and numbers
        if ($this->isObfuscatedUppercaseString($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.7, 'obfuscated_uppercase');
        }

        // Mixed-case alphanumeric strings without separators
        if ($this->isObfuscatedMixedAlphanumeric($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.7, 'obfuscated_mixed_alphanumeric');
        }

        // Obfuscated filename embedded in usenet subject line format
        if ($this->isObfuscatedUsenetFilename($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.85, 'obfuscated_usenet_filename');
        }

        // Only punctuation and numbers with no clear structure
        if ($this->isObfuscatedPunctuation($name)) {
            $analysis = $this->inspectSignals($name);
            $hashLike = $this->isBase64LikeToken($name)
                || $this->isBoundedGenericHash($name)
                || $this->isZeroVowelLongToken($analysis['coreName'], 12)
                || $analysis['lowSignal'];

            return $this->matched(
                $hashLike ? Category::OTHER_HASHED : Category::OTHER_MISC,
                $hashLike ? 0.75 : 0.5,
                'obfuscated_pattern',
                ['signal_score' => $analysis['signalScore'], 'markers' => $analysis['markers']]
            );
        }

        return null;
    }

    /**
     * Check for gibberish patterns using character-analysis heuristics.
     * These are patterns ported from FileNameCleaner that were previously
     * missing from the categorization pipeline.
     */
    protected function checkGibberish(string $name): ?CategorizationResult
    {
        $cleaned = $this->stripExtensionsForAnalysis($name);
        $coreName = $this->getCoreNameWithoutSeparators($cleaned);

        // High character-transition rate suggests randomness
        if ($this->isRandomByCharacterAnalysis($coreName, $name)) {
            return $this->matched(Category::OTHER_HASHED, 0.75, 'gibberish_random_transitions');
        }

        // Long alphanumeric but lacks word-like letter sequences
        if ($this->hasInsufficientWordStructure($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.75, 'gibberish_no_word_structure');
        }

        // Random-looking patterns dominated by digits
        if ($this->isRandomDigitPattern($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.7, 'gibberish_random_digits');
        }

        if ($this->isZeroVowelLongToken($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.78, 'gibberish_zero_vowels');
        }

        return null;
    }

    protected function checkLowSignal(string $name): ?CategorizationResult
    {
        $analysis = $this->inspectSignals($name);

        if ($this->hasStrongWordStructure($name, $analysis['coreName'])) {
            return null;
        }

        if ($this->isZeroVowelLongToken($analysis['coreName'])) {
            return null;
        }

        if ($analysis['lowSignal'] && $analysis['coreLength'] >= 20) {
            return $this->matched(
                Category::OTHER_HASHED,
                0.8,
                'gibberish_no_signal',
                [
                    'signal_score' => $analysis['signalScore'],
                    'markers' => $analysis['markers'],
                    'core_length' => $analysis['coreLength'],
                ]
            );
        }

        return null;
    }

    protected function hasStrongWordStructure(string $name, string $coreName): bool
    {
        return $this->getMaxConsecutiveLetters($coreName) >= 5
            && $this->hasNormalVowelRatio($coreName)
            && $this->countAlphabeticWordTokens($name) >= 2;
    }

    protected function countAlphabeticWordTokens(string $name): int
    {
        $tokens = preg_split('/[.\s_-]+/', $this->stripExtensionsForAnalysis($name)) ?: [];

        return count(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match('/[a-z]{3,}/i', $token) === 1
        ));
    }

    protected function checkArchive(string $name): ?CategorizationResult
    {
        if (preg_match('/\.(zip|rar|7z|tar|gz|bz2|xz|tgz|tbz2|cab|iso|img|dmg|pkg|archive)$/i', $name)) {
            return $this->matched(Category::OTHER_MISC, 0.5, 'archive');
        }

        return null;
    }

    protected function checkDataset(string $name): ?CategorizationResult
    {
        // Dataset/dump patterns that aren't media
        if (preg_match('/\b(sql|csv|dump|backup|dataset|collection)\b/i', $name) &&
            ! preg_match('/\b(movie|tv|show|audio|video|book|game)\b/i', $name)) {
            return $this->matched(Category::OTHER_MISC, 0.6, 'dataset');
        }

        // Data leaks/dumps (be careful with these)
        if (preg_match('/\b(leak|breach|data|database)\b/i', $name) &&
            preg_match('/\b(dump|export|backup)\b/i', $name) &&
            ! preg_match('/\b(movie|tv|show|audio|video|book|game)\b/i', $name)) {
            return $this->matched(Category::OTHER_MISC, 0.6, 'data_dump');
        }

        return null;
    }

    /**
     * @return array{0: array{coreName: string, coreLength: int, signalScore: int, markers: list<string>, lowSignal: bool}, 1: CategorizationResult}
     */
    private function decodeRustInspectAndCategorize(string $raw): array
    {
        $markersMask = \ord($raw[4]) | (\ord($raw[5]) << 8);
        $markers = [];
        while ($markersMask !== 0) {
            $lsb = $markersMask & -$markersMask;
            if (isset(self::SIGNAL_MARKER_BIT_MAP[$lsb])) {
                $markers[] = self::SIGNAL_MARKER_BIT_MAP[$lsb];
            }
            $markersMask ^= $lsb;
        }

        $signalScore = \count($markers);
        $coreName = substr($raw, 7);
        $coreLength = \strlen($coreName);
        $lowSignal = \ord($raw[6]) === 1;

        $analysis = [
            'coreName' => $coreName,
            'coreLength' => $coreLength,
            'signalScore' => $signalScore,
            'markers' => $markers,
            'lowSignal' => $lowSignal,
        ];

        $matchedByCode = \ord($raw[3]);
        if ($matchedByCode === 0) {
            return [$analysis, $this->noMatch()];
        }

        $rawCat = \ord($raw[0]) | (\ord($raw[1]) << 8);
        $categoryId = $rawCat === Category::OTHER_HASHED
            ? Category::OTHER_HASHED
            : Category::OTHER_MISC;
        $confidence = \ord($raw[2]) / 100.0;
        $matchedBy = self::MATCHED_BY_NAMES[$matchedByCode] ?? 'no_match';
        $debug = match ($matchedByCode) {
            12 => ['signal_score' => $signalScore, 'markers' => $markers],
            13 => [
                'signal_score' => $signalScore,
                'markers' => $markers,
                'core_length' => $coreLength,
            ],
            default => [],
        };

        return [$analysis, $this->matched($categoryId, $confidence, $matchedBy, $debug)];
    }

    public static function useW423RustFfi(bool $enable = true): void
    {
        self::$w423RustFfiOverride = $enable;
    }

    public static function usesW423RustFfi(): bool
    {
        return self::$w423RustFfiOverride ?? self::W423_RUST_FFI_DEFAULT;
    }

    public static function resetW423RustFfi(): void
    {
        self::$w423RustFfiOverride = null;
        self::$w423RustFfiHandle = null;
    }

    /**
     * Misc content summary for a batch of release names. Each trim()med name adds to hash when it
     * is all hex digits of a W423_HASH_LENGTHS length, else to its first W423_MISC_CONTENT_MARKERS
     * class, else to other, and separately to empty when it trims to ''. Non-strings count as
     * empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, hash: int, archive: int, dataset: int, other: int, empty: int}
     */
    public static function summarizeMiscContentBatch(array $releaseNames): array
    {
        return (self::usesW423RustFfi() ? self::summarizeMiscContentBatchNative($releaseNames) : null)
            ?? self::summarizeMiscContentBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, hash: int, archive: int, dataset: int, other: int, empty: int}
     */
    public static function summarizeMiscContentBatchPhp(array $releaseNames): array
    {
        $classes = ['archive' => 0, 'dataset' => 0];
        $hash = 0;
        $other = 0;
        $empty = 0;
        foreach ($releaseNames as $releaseName) {
            $name = trim(\is_string($releaseName) ? $releaseName : '');
            $length = \strlen($name);
            if ($length === 0) {
                $empty++;
            }
            if (\in_array($length, self::W423_HASH_LENGTHS, true) && strspn($name, self::W423_HEX_DIGITS) === $length) {
                $hash++;

                continue;
            }
            $lower = strtolower($name);
            foreach (self::W423_MISC_CONTENT_MARKERS as $class => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($lower, $needle)) {
                        $classes[$class]++;

                        continue 3;
                    }
                }
            }
            $other++;
        }

        return [
            'total' => \count($releaseNames),
            'hash' => $hash,
            'archive' => $classes['archive'],
            'dataset' => $classes['dataset'],
            'other' => $other,
            'empty' => $empty,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, hash: int, archive: int, dataset: int, other: int, empty: int}|null
     */
    public static function summarizeMiscContentBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $releaseNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW423Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'hash' => (int) $fields[1],
            'archive' => (int) $fields[2],
            'dataset' => (int) $fields[3],
            'other' => (int) $fields[4],
            'empty' => (int) $fields[5],
        ];
    }

    /**
     * Calls this class's Wave 42.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W423_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW423Native(string $blob, string $lens): ?array
    {
        $ffi = self::w423RustFfi();
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
            $written = $ffi->nntmux_w423_misc_content_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w423_misc_content_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W423_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w423RustFfi(): ?FFI
    {
        if (self::$w423RustFfiHandle === null) {
            self::$w423RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w423', self::W423_FFI_CDEF) ?? false;
        }

        return self::$w423RustFfiHandle === false ? null : self::$w423RustFfiHandle;
    }
}
