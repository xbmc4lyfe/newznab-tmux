<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use FFI\CData;
use Throwable;

/**
 * Shared FFI loader and zero-allocation buffer bridge for `libnntmux_pipeline`.
 */
final class RustPipelineLibrary
{
    private const int INITIAL_SCRATCH_BYTES = 262144;

    /** @var array<string, FFI|false> */
    private static array $libraries = [];

    private static FFI|false|null $defaultLibrary = null;

    private static ?CData $scratchBuf = null;

    private static int $scratchCap = 0;

    public static function available(?string $libraryPath = null): bool
    {
        return self::resolve($libraryPath) !== null;
    }

    /**
     * @return list<string>|null
     */
    public static function xrefExtractTokens(string $xref, ?string $libraryPath = null): ?array
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        if ($xref === '' || $xref === '0') {
            return [];
        }

        $len = \strlen($xref);
        $scratch = self::ensureScratch($ffi, max($len, 256));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_extract_tokens($xref, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < 0) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_extract_tokens($xref, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return [];
        }

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * @return list<string>|null
     */
    public static function xrefExtractGroups(string $xref, ?string $libraryPath = null): ?array
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        if ($xref === '' || $xref === '0') {
            return [];
        }

        $len = \strlen($xref);
        $scratch = self::ensureScratch($ffi, max($len, 256));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_extract_groups($xref, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < 0) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_extract_groups($xref, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return [];
        }

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * @return list<string>|null
     */
    public static function xrefDiffTokens(string $existingXref, string $headerXref, ?string $libraryPath = null): ?array
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        if ($headerXref === '' || $headerXref === '0') {
            return [];
        }

        $existingLen = \strlen($existingXref);
        $incomingLen = \strlen($headerXref);
        $scratch = self::ensureScratch($ffi, max($incomingLen, 256));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_diff_tokens($existingXref, $existingLen, $headerXref, $incomingLen, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < 0) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_diff_tokens($existingXref, $existingLen, $headerXref, $incomingLen, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return [];
        }

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * Batch extract Xref tokens and/or deduplicated group names for a list of Xref strings.
     *
     * @param  list<string>  $xrefs
     * @return list<string>|null Raw newline-split lines per input item, or null if unavailable/declined
     */
    public static function xrefExtractBatchLines(array $xrefs, int $mode, ?string $libraryPath = null): ?array
    {
        $count = \count($xrefs);
        if ($count === 0) {
            return [];
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $joined = implode("\0", $xrefs);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }

        $len = \strlen($joined);
        $needed = $mode === 3 ? ($len * 2 + $count * 2 + 64) : ($len + $count + 64);
        $scratch = self::ensureScratch($ffi, $needed);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_extract_batch_nul($joined, $len, $mode, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < -1) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_extract_batch_nul($joined, $len, $mode, $scratch, self::$scratchCap);
        }
        if (! \is_int($written) || $written < 0) {
            return null;
        }
        if ($written === 0) {
            return array_fill(0, $count, '');
        }

        $lines = explode("\n", FFI::string($scratch, $written));

        return \count($lines) === $count ? $lines : null;
    }

    /**
     * Compute a single binary identity MD5 hex hash via Rust FFI, or null when declined/unavailable.
     */
    public static function binaryIdentityHash(string $subject, string $poster, int $fileNumber, ?string $libraryPath = null): ?string
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $scratch = self::ensureScratch($ffi, 64);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_binary_identity_hash(
            $subject,
            \strlen($subject),
            $poster,
            \strlen($poster),
            $fileNumber,
            $scratch
        );

        return $written === 32 ? FFI::string($scratch, 32) : null;
    }

    /**
     * Batch compute binary identity MD5 hex hashes for `count` pairs of `(first, second)` strings.
     *
     * @param  list<string>  $flatPairs
     * @return list<string>|null 32-char hex string per record (or '' when declined to PHP fallback), or null
     */
    public static function binaryIdentityHashesBatch(array $flatPairs, int $count, ?string $libraryPath = null, bool &$allResolved = false): ?array
    {
        $allResolved = false;
        if ($count === 0) {
            $allResolved = true;

            return [];
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $joined = implode("\0", $flatPairs);
        $needed = $count * 33 + 64;
        $scratch = self::ensureScratch($ffi, $needed);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_binary_identity_hash_batch_nul($joined, \strlen($joined), $count, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < -1) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_binary_identity_hash_batch_nul($joined, \strlen($joined), $count, $scratch, self::$scratchCap);
        }
        if (! \is_int($written) || $written < 0) {
            return null;
        }
        if ($written === 0) {
            return array_fill(0, $count, '');
        }

        $allResolved = $written === ($count * 33 - 1);
        $lines = explode("\n", FFI::string($scratch, $written));

        return \count($lines) === $count ? $lines : null;
    }

    /**
     * Detect NZB file extension from subject via Rust FFI, or null if FFI unavailable.
     */
    public static function nzbDetectFileExtension(string $subject, ?string $libraryPath = null): ?string
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $scratch = self::ensureScratch($ffi, 16);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_detect_file_extension($subject, \strlen($subject), $scratch);
        if (! \is_int($written) || $written < 0) {
            return null;
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Parse NZB `<file>` nodes into raw `\n`-separated summary records via Rust FFI, or null when declined/unavailable.
     *
     * @return list<string>|null
     */
    public static function nzbParseFileLines(string $nzb, ?string $libraryPath = null): ?array
    {
        $len = \strlen($nzb);
        if ($len === 0) {
            return [];
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $scratch = self::ensureScratch($ffi, max(self::INITIAL_SCRATCH_BYTES, intdiv($len, 2) + 256));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_parse_files($nzb, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < -1) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_parse_files($nzb, $len, $scratch, self::$scratchCap);
        }
        if (! \is_int($written) || $written < 0) {
            return null;
        }
        if ($written === 0) {
            return [];
        }

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * Scans and validates a PAR2 packet stream via Rust FFI (`nntmux_par2_scan_packets`).
     *
     * @return array{status: int, payload: string}|null
     */
    public static function par2ScanPackets(
        string $existingConcat,
        string $tail,
        int $skipRemaining,
        int $maxStateBytes,
        string $incoming,
        ?string $libraryPath = null
    ): ?array {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $exLen = \strlen($existingConcat);
        $tailLen = \strlen($tail);
        $inLen = \strlen($incoming);
        $estBytes = max(1024, $tailLen + $inLen + 64);
        $scratch = self::ensureScratch($ffi, $estBytes);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_par2_scan_packets(
            $existingConcat,
            $exLen,
            $tail,
            $tailLen,
            $skipRemaining,
            $maxStateBytes,
            $incoming,
            $inLen,
            $scratch,
            self::$scratchCap
        );
        if (! \is_int($written)) {
            return null;
        }
        if ($written <= -16) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_par2_scan_packets(
                $existingConcat,
                $exLen,
                $tail,
                $tailLen,
                $skipRemaining,
                $maxStateBytes,
                $incoming,
                $inLen,
                $scratch,
                self::$scratchCap
            );
        }
        if (! \is_int($written)) {
            return null;
        }
        if ($written === -2 || $written === -3) {
            return ['status' => $written, 'payload' => ''];
        }
        if ($written < 16) {
            return null;
        }

        return ['status' => 0, 'payload' => FFI::string($scratch, $written)];
    }

    /**
     * Check whether a single release or file name is hashed or gibberish via Rust FFI.
     */
    public static function isHashedOrGibberish(string $name, ?string $libraryPath = null): ?bool
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_is_hashed_or_gibberish($name, \strlen($name));

        return \is_int($res) ? ($res === 1) : null;
    }

    /**
     * Batch check whether release or file names are hashed or gibberish via Rust FFI.
     *
     * @param  list<string>  $names
     * @return list<bool>|null
     */
    public static function isHashedOrGibberishBatch(array $names, ?string $libraryPath = null): ?array
    {
        $count = \count($names);
        if ($count === 0) {
            return [];
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $joined = implode("\0", $names);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }

        $scratch = self::ensureScratch($ffi, $count + 64);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_is_hashed_or_gibberish_batch_nul($joined, \strlen($joined), $count, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written !== $count) {
            return null;
        }

        $raw = FFI::string($scratch, $written);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $raw[$i] === '1';
        }

        return $out;
    }

    /**
     * Inspect signal markers and categorize a release name for `MiscCategorizer` / `MiscPipe`.
     *
     *Returns the 7-byte header + coreName payload, or null when FFI is unavailable.
     */
    public static function miscInspectAndCategorize(string $name, ?string $libraryPath = null): ?string
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $len = \strlen($name);
        $scratch = self::ensureScratch($ffi, max(256, $len + 32));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_misc_inspect_and_categorize($name, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < -7) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_misc_inspect_and_categorize($name, $len, $scratch, self::$scratchCap);
        }
        if (! \is_int($written) || $written < 7) {
            return null;
        }

        return FFI::string($scratch, $written);
    }

    /**
     * Compute the truncated prefix byte length for `FileNameCleaner::stripKnownFileSuffixes`,
     * or null when declined/unavailable.
     */
    public static function stripKnownFileSuffixesLen(string $fileName, bool $preserveCodecAfterWrapper, ?string $libraryPath = null): ?int
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_strip_known_file_suffixes($fileName, \strlen($fileName), $preserveCodecAfterWrapper ? 1 : 0);

        return \is_int($res) && $res >= 0 ? $res : null;
    }

    /**
     * Compute `FileNameCleaner::computeForMatching` as a packed `(start << 32) | len` span (`>= 0`),
     * `-1` for `false`, or `null` when declined to PHP fallback / FFI unavailable.
     */
    public static function cleanForMatchingSpan(string $fileName, bool $preserveLeadingNumber, ?string $libraryPath = null): ?int
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_clean_for_matching_span($fileName, \strlen($fileName), $preserveLeadingNumber ? 1 : 0);

        return \is_int($res) && $res >= -1 ? $res : null;
    }

    /**
     * Compute `SeasonPackDetector` boolean flags (`1` = episode name, `2` = season pack, `0` = neither),
     * or null when declined/unavailable.
     */
    public static function seasonPackFlags(string $name, ?string $libraryPath = null): ?int
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_season_pack_flags($name, \strlen($name));

        return \is_int($res) && $res >= 0 ? $res : null;
    }

    /**
     * Compute `SeasonPackDetector::episodeIdentity` via Rust FFI.
     */
    public static function seasonPackEpisodeIdentity(string $name, ?string $libraryPath = null, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($name);
        $scratch = self::ensureScratch($ffi, max(256, $len + 32));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_season_pack_episode_identity($name, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_season_pack_episode_identity($name, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * Batch check `SeasonPackDetector::hasNonSingleEpisodeEvidence` via Rust FFI.
     *
     * @param  list<string>  $names
     */
    public static function hasNonSingleEpisodeEvidence(array $names, ?string $libraryPath = null): ?bool
    {
        $count = \count($names);
        if ($count === 0) {
            return false;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $joined = implode("\0", $names);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_has_non_single_episode_evidence_nul($joined, \strlen($joined), $count);

        return \is_int($res) && $res >= 0 ? ($res === 1) : null;
    }

    /**
     * Compute `SeasonPackDetector::seasonNameFromEpisode` via Rust FFI.
     */
    public static function seasonNameFromEpisode(string $name, ?string $libraryPath = null, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($name);
        $scratch = self::ensureScratch($ffi, max(256, $len + 32));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_season_name_from_episode($name, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_season_name_from_episode($name, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * Compute `SeasonPackDetector::seasonNameFromEpisodes` and `isMultiEpisodeSet` via Rust FFI.
     *
     * @param  list<string>  $names
     */
    public static function seasonNameFromEpisodes(array $names, ?string $libraryPath = null, bool &$supported = false, bool &$isMultiEpisode = false): ?string
    {
        $supported = false;
        $isMultiEpisode = false;
        $count = \count($names);
        if ($count < 2) {
            $supported = true;

            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $joined = implode("\0", $names);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        $len = \strlen($joined);
        $scratch = self::ensureScratch($ffi, max(256, $len + 64));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_season_name_from_episodes_nul($joined, $len, $count, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_season_name_from_episodes_nul($joined, $len, $count, $scratch, self::$scratchCap);
            if (! \is_int($written) || ($written < 0 && $written !== -2)) {
                return null;
            }
        }

        $supported = true;
        if ($written === 0) {
            return null;
        }
        $isMultiEpisode = true;

        return $written === -2 ? null : FFI::string($scratch, $written);
    }

    public static function resolve(?string $libraryPath = null): ?FFI
    {
        if ($libraryPath === null && self::$defaultLibrary !== null) {
            return self::$defaultLibrary === false ? null : self::$defaultLibrary;
        }
        if (! \in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || ! \extension_loaded('FFI')) {
            if ($libraryPath === null) {
                self::$defaultLibrary = false;
            }

            return null;
        }

        $cacheKey = $libraryPath ?? 'default';
        if (\array_key_exists($cacheKey, self::$libraries)) {
            $cached = self::$libraries[$cacheKey];

            return $cached === false ? null : $cached;
        }

        $root = \dirname(__DIR__, 2);
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates = $libraryPath !== null ? [$libraryPath] : [
            $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix,
            $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix,
            '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix,
        ];

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
                continue;
            }
            try {
                $ffi = FFI::cdef(<<<'C'
                    uint32_t nntmux_pipeline_abi_version(void);
                    ssize_t nntmux_xref_extract_tokens(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_xref_extract_groups(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_xref_diff_tokens(const char *existing, size_t existing_len, const char *incoming, size_t incoming_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_xref_extract_batch_nul(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
                    ssize_t nntmux_binary_identity_hash(const char *subject, size_t subject_len, const char *poster, size_t poster_len, int64_t file_number, char *out_hex32);
                    ssize_t nntmux_binary_identity_hash_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
                    ssize_t nntmux_nzb_detect_file_extension(const char *subject, size_t subject_len, char *out_buf);
                    ssize_t nntmux_nzb_parse_files(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_par2_scan_packets(const char *existing, size_t existing_len, const char *tail, size_t tail_len, int64_t skip_remaining, int64_t max_state_bytes, const char *incoming, size_t incoming_len, char *out_buf, size_t out_cap);
                    int32_t nntmux_is_hashed_or_gibberish(const char *input, size_t input_len);
                    ssize_t nntmux_is_hashed_or_gibberish_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
                    ssize_t nntmux_misc_inspect_and_categorize(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_strip_known_file_suffixes(const char *input, size_t input_len, uint32_t preserve_codec);
                    int64_t nntmux_clean_for_matching_span(const char *input, size_t input_len, uint32_t preserve_leading_number);
                    int32_t nntmux_season_pack_flags(const char *input, size_t input_len);
                    ssize_t nntmux_season_pack_episode_identity(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    int32_t nntmux_has_non_single_episode_evidence_nul(const char *input, size_t input_len, size_t count);
                    ssize_t nntmux_season_name_from_episode(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_season_name_from_episodes_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
                    C, $resolved);

                // @phpstan-ignore method.notFound
                if ($ffi->nntmux_pipeline_abi_version() !== 1) {
                    continue;
                }

                self::$libraries[$cacheKey] = $ffi;
                if ($libraryPath === null) {
                    self::$defaultLibrary = $ffi;
                }

                return $ffi;
            } catch (Throwable) {
                continue;
            }
        }

        self::$libraries[$cacheKey] = false;
        if ($libraryPath === null) {
            self::$defaultLibrary = false;
        }

        return null;
    }

    private static function ensureScratch(FFI $ffi, int $minBytes): ?CData
    {
        if (self::$scratchCap >= $minBytes && self::$scratchBuf !== null) {
            return self::$scratchBuf;
        }
        $required = max(self::INITIAL_SCRATCH_BYTES, $minBytes);
        if (self::$scratchBuf !== null && self::$scratchCap >= $required) {
            return self::$scratchBuf;
        }
        try {
            $cap = $required > self::INITIAL_SCRATCH_BYTES ? $required * 2 : self::INITIAL_SCRATCH_BYTES;
            self::$scratchBuf = $ffi->new("char[{$cap}]");
            self::$scratchCap = $cap;

            return self::$scratchBuf;
        } catch (Throwable) {
            return null;
        }
    }
}
