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

    /**
     * Compute `OriginalFilenameEvidence::canonicalNominationKeys()` via Rust FFI.
     *
     * @return list<string>|null
     */
    public static function originalFilenameNominationKeys(string $basename, ?string $libraryPath = null, bool &$supported = false): ?array
    {
        $supported = false;
        if ($basename === '') {
            $supported = true;

            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($basename);
        $scratch = self::ensureScratch($ffi, max(1024, $len * 4 + 64));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_original_filename_nomination_keys($basename, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -2) {
            return null;
        }
        if ($written === -1) {
            $supported = true;

            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_original_filename_nomination_keys($basename, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written <= 0) {
                return null;
            }
        }

        $supported = true;

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * Compute `PredbIdentityKeys::forRow` via Rust FFI.
     *
     * @return list<array{kind: int, key_digest: string}>|null
     */
    public static function predbIdentityKeysForRow(string $title, string $filename, ?string $libraryPath = null, bool &$supported = false): ?array
    {
        $supported = false;
        if ($title === '') {
            $supported = true;

            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $scratch = self::ensureScratch($ffi, 4096);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_identity_keys_for_row($title, \strlen($title), $filename, \strlen($filename), $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -2) {
            return null;
        }
        if ($written === -1) {
            $supported = true;

            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_identity_keys_for_row($title, \strlen($title), $filename, \strlen($filename), $scratch, self::$scratchCap);
            if (! \is_int($written) || $written <= 0) {
                return null;
            }
        }

        $supported = true;
        $lines = explode("\n", FFI::string($scratch, $written));
        $keys = [];
        foreach ($lines as $line) {
            $keys[] = [
                'kind' => $line[0] === '1' ? 1 : 2,
                'key_digest' => substr($line, 2),
            ];
        }

        return $keys;
    }

    /**
     * Compute `PredbMatchSelector::similarityPercent` via Rust FFI.
     */
    public static function similarityPercent(string $left, string $right, ?string $libraryPath = null): ?float
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_similarity_percent($left, \strlen($left), $right, \strlen($right));

        return \is_float($res) ? $res : null;
    }

    /**
     * Select best matching PreDB hit index (`0..count-1`, or `-1` for none) via Rust FFI.
     *
     * @param  list<array<string, mixed>|object>  $hits
     */
    public static function predbSelectBestMatchIndex(
        string $query,
        bool $hasOrig,
        string $origBasename,
        array $hits,
        ?string $libraryPath = null,
        bool &$supported = false
    ): ?int {
        $supported = false;
        $count = \count($hits);
        if ($count === 0) {
            $supported = true;

            return -1;
        }
        if ($count > 50 || $query === '' || str_contains($query, "\0") || str_contains($origBasename, "\0")) {
            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $flat = [];
        foreach ($hits as $hit) {
            if (\is_array($hit)) {
                $t = (string) ($hit['title'] ?? '');
                $f = (string) ($hit['filename'] ?? '');
            } else {
                $t = (string) ($hit->title ?? '');
                $f = (string) ($hit->filename ?? '');
            }
            if (str_contains($t, "\0") || str_contains($f, "\0")) {
                return null;
            }
            $flat[] = $t;
            $flat[] = $f;
        }
        $joined = implode("\0", $flat);

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_predb_select_best_match_index(
            $query,
            \strlen($query),
            $hasOrig ? 1 : 0,
            $origBasename,
            \strlen($origBasename),
            $joined,
            \strlen($joined),
            $count
        );
        if (! \is_int($res) || $res === -2) {
            return null;
        }

        $supported = true;

        return $res;
    }

    /**
     * Compute `NzbSplitUnwrapper::literalPayload` via Rust FFI.
     */
    public static function nzbsplitLiteralPayload(string $value, ?string $libraryPath = null, bool &$supported = false): ?string
    {
        $supported = false;
        if ($value === '') {
            $supported = true;

            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($value);
        $scratch = self::ensureScratch($ffi, max(256, $len + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzbsplit_literal_payload($value, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written === -3) {
            $supported = true;

            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzbsplit_literal_payload($value, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Compute `NzbSplitUnwrapper::stripLiteralSubjectTrailer` via Rust FFI.
     */
    public static function nzbsplitStripLiteralTrailer(string $value, ?string $libraryPath = null): ?string
    {
        if ($value === '') {
            return '';
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($value);
        // @phpstan-ignore method.notFound
        $kept = $ffi->nntmux_nzbsplit_strip_literal_trailer_len($value, $len);
        if (! \is_int($kept) || $kept < 0 || $kept > $len) {
            return null;
        }

        return $kept === $len ? $value : substr($value, 0, $kept);
    }

    /**
     * Compute `NzbSplitUnwrapper::unwrap` via Rust FFI.
     */
    public static function nzbsplitUnwrap(string $value, ?string $libraryPath = null, bool &$supported = false): ?string
    {
        $supported = false;
        if ($value === '') {
            $supported = true;

            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($value);
        $scratch = self::ensureScratch($ffi, max(256, $len + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzbsplit_unwrap($value, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -2) {
            return null;
        }
        if ($written === -3) {
            $supported = true;

            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzbsplit_unwrap($value, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * Compute `FileNameCleaner::isPlausibleReleaseTitle` via Rust FFI.
     */
    public static function isPlausibleReleaseTitle(string $title, ?string $libraryPath = null): ?bool
    {
        if ($title === '') {
            return false;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_is_plausible_release_title($title, \strlen($title));
        if (! \is_int($res) || $res < 0) {
            return null;
        }

        return $res === 1;
    }

    /**
     * Compute `ReleaseCleaningService::fixerCleaner` via Rust FFI.
     */
    public static function releaseFixerClean(string $name, ?string $libraryPath = null): ?string
    {
        if ($name === '') {
            return '';
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($name);
        $scratch = self::ensureScratch($ffi, max(256, $len + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_release_fixer_clean($name, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -2) {
            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_fixer_clean($name, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Compute `ReleaseCleaningService::releaseNameFromFile` via Rust FFI.
     */
    public static function releaseNameFromFile(string $file, ?string $libraryPath = null, bool &$supported = false): ?string
    {
        $supported = false;
        if ($file === '') {
            $supported = true;

            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($file);
        $scratch = self::ensureScratch($ffi, max(256, $len + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_release_name_from_file($file, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -2) {
            return null;
        }
        if ($written === -3) {
            $supported = true;

            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_name_from_file($file, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * Compute `ReleaseCleaningService` generic subject cleaning via Rust FFI (`0=helper`, `1=counter_file`, `2=generic`, `3=teevee`).
     */
    public static function releaseGenericClean(string $subject, int $mode, ?string $libraryPath = null, bool &$supported = false): ?string
    {
        $supported = false;
        if ($subject === '') {
            $supported = true;

            return $mode === 1 ? null : '';
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($subject);
        $scratch = self::ensureScratch($ffi, max(256, $len + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_release_generic_clean($subject, $len, $mode, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -2) {
            return null;
        }
        if ($written === -3) {
            $supported = true;

            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_generic_clean($subject, $len, $mode, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Compute `PayloadIdentityResolver::resolve` over hint filenames via Rust FFI.
     *
     * @param  list<string>  $filenames
     */
    public static function payloadIdentityResolve(array $filenames, ?string $libraryPath = null, bool &$supported = false): ?string
    {
        $supported = false;
        $count = \count($filenames);
        if ($count === 0) {
            $supported = true;

            return null;
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        foreach ($filenames as $fn) {
            if (str_contains($fn, "\0")) {
                return null;
            }
        }
        $joined = implode("\0", $filenames);
        $len = \strlen($joined);
        $scratch = self::ensureScratch($ffi, 1024);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_payload_identity_resolve_nul($joined, $len, $count, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -2) {
            return null;
        }
        if ($written === -3) {
            $supported = true;

            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_payload_identity_resolve_nul($joined, $len, $count, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * Compute `AbstractTvProvider::parseInfo` via Rust FFI.
     *
     * @return array<string, mixed>|false|null
     */
    public static function tvParseInfo(
        string $relname,
        int $maxTwoDigitYear,
        ?string $libraryPath = null,
        bool &$supported = false
    ): array|false|null {
        $supported = false;
        if ($relname === '') {
            $supported = true;

            return false;
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($relname);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $len * 2 + 64)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(512, $len * 2 + 64));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $packed = $ffi->nntmux_tv_parse_info($relname, $len, $maxTwoDigitYear, $scratch, self::$scratchCap);
        if (! \is_int($packed) || $packed === -1 || $packed === -2) {
            return null;
        }
        if ($packed === -3) {
            $supported = true;

            return false;
        }
        if ($packed < -3) {
            $scratch = self::ensureScratch($ffi, -$packed);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_tv_parse_info($relname, $len, $maxTwoDigitYear, $scratch, self::$scratchCap);
            if (! \is_int($packed) || $packed < 0) {
                return null;
            }
        }

        $written = $packed & 0xFFFF;
        $parts = explode("\0", FFI::string($scratch, $written));
        if (\count($parts) !== 4) {
            return null;
        }

        $supported = true;
        $season = ($packed >> 16) & 0xFFFF;
        $epRaw = ($packed >> 32) & 0xFFFF;
        $year = ($packed >> 48) & 0xFFFF;

        $res = [
            'name' => $parts[0],
            'country' => $parts[1],
            'cleanname' => $parts[2],
            'season' => $season,
            'episode' => $epRaw === 0xFFFF ? 'all' : $epRaw,
        ];
        if ($year > 0) {
            $res['year'] = $year;
        }
        $res['airdate'] = $parts[3];

        return $res;
    }

    /**
     * Compute `AbstractTvProvider::cleanName` via Rust FFI.
     */
    public static function tvCleanName(string $str, ?string $libraryPath = null): ?string
    {
        if ($str === '') {
            return '';
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($str);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $len + 32)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(256, $len + 32));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_tv_clean_name($str, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_clean_name($str, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Compute `MovieService::parseMovieSearchName` via Rust FFI.
     */
    public static function movieParseSearchName(
        string $releaseName,
        string &$title = '',
        string &$year = '',
        ?string $libraryPath = null
    ): ?bool {
        if ($releaseName === '' || $releaseName === '0') {
            return false;
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($releaseName);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $len + 64)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(256, $len + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $packed = $ffi->nntmux_movie_parse_search_name($releaseName, $len, $scratch, self::$scratchCap);
        if (! \is_int($packed) || $packed === -1 || $packed === -2) {
            return null;
        }
        if ($packed === -3) {
            return false;
        }
        if ($packed < -3) {
            $scratch = self::ensureScratch($ffi, -$packed);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_movie_parse_search_name($releaseName, $len, $scratch, self::$scratchCap);
            if (! \is_int($packed) || $packed < 0) {
                return null;
            }
        }

        $written = $packed & 0xFFFF;
        $yearInt = $packed >> 16;
        $title = FFI::string($scratch, $written);
        $year = $yearInt > 0 ? (string) $yearInt : '';

        return true;
    }

    /**
     * Compute `ManticoreSearchDriver::escapeString` via Rust FFI.
     */
    public static function manticoreEscapeString(string $string, ?string $libraryPath = null): ?string
    {
        if ($string === '' || $string === '*' || $string === '0') {
            return '';
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($string);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $len * 3 + 16)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(256, $len * 3 + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_manticore_escape_string($string, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_escape_string($string, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Compute `ManticoreSearchDriver::prepareUserSearchQuery` (`$mode = 0`) or
     * `ManticoreSearchDriver::prepareReleaseFieldSearchQuery('fromname', $query)` (`$mode = 1`) via Rust FFI.
     */
    public static function manticorePrepareQuery(string $query, int $mode = 0, ?string $libraryPath = null): ?string
    {
        if ($query === '' || ($mode === 0 && $query === '*')) {
            return '';
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($query);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $len * 2 + 32)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(256, $len * 2 + 32));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_manticore_prepare_query($query, $len, $mode, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_prepare_query($query, $len, $mode, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Compute `ManticoreSearchDriver::scopeReleaseSearchQuery` via Rust FFI.
     */
    public static function manticoreScopeReleaseQuery(
        string $rawQuery,
        string $preparedQuery,
        string $fieldSelector = '',
        ?string $libraryPath = null
    ): ?string {
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $rawLen = \strlen($rawQuery);
        $prepLen = \strlen($preparedQuery);
        $fieldLen = \strlen($fieldSelector);
        $minCap = ($rawLen + $prepLen + $fieldLen) * 3 + 64;
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $minCap)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(256, $minCap));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_manticore_scope_release_query(
            $rawQuery,
            $rawLen,
            $preparedQuery,
            $prepLen,
            $fieldSelector,
            $fieldLen,
            $scratch,
            self::$scratchCap
        );
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_scope_release_query(
                $rawQuery,
                $rawLen,
                $preparedQuery,
                $prepLen,
                $fieldSelector,
                $fieldLen,
                $scratch,
                self::$scratchCap
            );
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Compute `ManticoreSearchDriver::buildFieldSearchExpression` via Rust FFI.
     *
     * @param  array<string, mixed>  $searchArray
     * @param  list<string>|null  $allowedFields
     */
    public static function manticoreBuildFieldSearchExpression(
        array $searchArray,
        ?array $allowedFields = null,
        ?string $libraryPath = null,
        bool &$supported = false
    ): ?string {
        $supported = false;
        if ($searchArray === []) {
            $supported = true;

            return null;
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $flat = [];
        $pairs = 0;
        foreach ($searchArray as $key => $value) {
            if ($allowedFields !== null && (! \is_string($key) || ! \in_array($key, $allowedFields, true))) {
                continue;
            }
            if ($value !== null && $value !== '') {
                $kStr = (string) $key;
                $vStr = (string) $value;
                if (str_contains($kStr, "\0") || str_contains($vStr, "\0")) {
                    return null;
                }
                $flat[] = $kStr;
                $flat[] = $vStr;
                $pairs++;
            }
        }
        if ($pairs === 0) {
            $supported = true;

            return null;
        }
        $joined = implode("\0", $flat);
        $len = \strlen($joined);
        $minCap = $len * 3 + 64;
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $minCap)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(512, $minCap));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_manticore_build_field_search_expr_nul($joined, $len, $pairs, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written === -3) {
            $supported = true;

            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_build_field_search_expr_nul($joined, $len, $pairs, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * Compute `extractSuggestion` via Rust FFI.
     */
    public static function searchExtractSuggestion(
        string $searchname,
        string $query,
        ?string $libraryPath = null,
        bool &$supported = false
    ): ?string {
        $supported = false;
        if ($searchname === '') {
            $supported = true;

            return null;
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= 256)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, 256);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_search_extract_suggestion(
            $searchname,
            \strlen($searchname),
            $query,
            \strlen($query),
            $scratch,
            self::$scratchCap
        );
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written === -3) {
            $supported = true;

            return null;
        }

        $supported = true;

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Batch-rank autocomplete suggestions via Rust FFI.
     *
     * @param  list<string>  $searchnames
     * @return list<array{suggest: string, distance: int, docs: int}>|null
     */
    public static function searchRankAutocompleteBatch(
        array $searchnames,
        string $query,
        int $maxResults,
        ?string $libraryPath = null
    ): ?array {
        $count = \count($searchnames);
        if ($count === 0 || $maxResults <= 0 || $query === '') {
            return [];
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $joined = implode("\n", $searchnames);
        $len = \strlen($joined);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= 2048)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, 2048);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_search_rank_autocomplete_nul(
            $query,
            \strlen($query),
            $joined,
            $len,
            $count,
            $maxResults,
            $scratch,
            self::$scratchCap
        );
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_search_rank_autocomplete_nul(
                $query,
                \strlen($query),
                $joined,
                $len,
                $count,
                $maxResults,
                $scratch,
                self::$scratchCap
            );
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
        }

        $raw = FFI::string($scratch, $written);
        $lines = explode("\n", rtrim($raw, "\n"));
        $out = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\0", $line);
            if (\count($parts) === 2) {
                $out[] = [
                    'suggest' => $parts[0],
                    'distance' => 0,
                    'docs' => (int) $parts[1],
                ];
            }
        }

        return $out;
    }

    /**
     * Batch-rank Levenshtein spell-fallback suggestions via Rust FFI.
     *
     * @param  list<string>  $searchnames
     * @return list<array{suggest: string, distance: int, docs: int}>|null
     */
    public static function searchSuggestFallbackBatch(
        array $searchnames,
        string $query,
        int $maxResults = 5,
        ?string $libraryPath = null
    ): ?array {
        $count = \count($searchnames);
        if ($count === 0 || $maxResults <= 0 || $query === '') {
            return [];
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $joined = implode("\n", $searchnames);
        $len = \strlen($joined);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= 1024)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, 1024);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_search_suggest_fallback_nul(
            $query,
            \strlen($query),
            $joined,
            $len,
            $count,
            $maxResults,
            $scratch,
            self::$scratchCap
        );
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        if ($written < -2) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_search_suggest_fallback_nul(
                $query,
                \strlen($query),
                $joined,
                $len,
                $count,
                $maxResults,
                $scratch,
                self::$scratchCap
            );
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
        }

        $raw = FFI::string($scratch, $written);
        $lines = explode("\n", rtrim($raw, "\n"));
        $out = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\0", $line);
            if (\count($parts) === 3) {
                $out[] = [
                    'suggest' => $parts[0],
                    'distance' => (int) $parts[1],
                    'docs' => (int) $parts[2],
                ];
            }
        }

        return $out;
    }

    public static function cp437ToUtf8(string $input, ?string $libraryPath = null): ?string
    {
        if ($input === '') {
            return '';
        }

        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($input);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= 8192)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(8192, $len * 3));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_cp437_to_utf8($input, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written === -3) {
            return $input;
        }
        if ($written === -1 || $written === -2) {
            return null;
        }
        if ($written === 0) {
            return '';
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cp437_to_utf8($input, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return '';
            }
        }

        return FFI::string($scratch, $written);
    }

    public static function normalizeNfoEncoding(string $input, ?string $libraryPath = null): ?string
    {
        if ($input === '') {
            return '';
        }

        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($input);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= 8192)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(8192, $len * 3));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_normalize_nfo_encoding($input, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written === -3) {
            return $input;
        }
        if ($written === -1 || $written === -2) {
            return null;
        }
        if ($written === 0) {
            return '';
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_normalize_nfo_encoding($input, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return '';
            }
        }

        return FFI::string($scratch, $written);
    }

    public static function nfoCleanContent(string $input, ?string $libraryPath = null): ?string
    {
        if ($input === '') {
            return '';
        }

        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($input);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= 8192)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(8192, $len * 3));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nfo_clean_content($input, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written === 0) {
            return '';
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nfo_clean_content($input, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return '';
            }
        }

        return FFI::string($scratch, $written);
    }

    public static function nfoIdentityText(string $input, ?string $libraryPath = null): ?string
    {
        if ($input === '') {
            return '';
        }

        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($input);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= 8192)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(8192, $len + 64));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nfo_identity_text($input, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written === 0) {
            return '';
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nfo_identity_text($input, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return '';
            }
        }

        return FFI::string($scratch, $written);
    }

    public static function nfoCheckPreconditions(string $input, ?string $libraryPath = null): ?int
    {
        if ($input === '') {
            return 0;
        }

        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $flags = $ffi->nntmux_nfo_check_preconditions($input, \strlen($input));

        return \is_int($flags) && $flags >= 0 ? $flags : null;
    }

    public static function nfoValidateContent(string $input, ?string $libraryPath = null): ?bool
    {
        if (\strlen($input) < 50) {
            return false;
        }

        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_nfo_validate_content($input, \strlen($input));
        if (! \is_int($res) || $res < 0) {
            return null;
        }

        return $res === 1;
    }

    public static function videoTransportStreamOffset(string $data, ?string $libraryPath = null, bool &$supported = false): ?int
    {
        $supported = false;
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $supported = true;
        if (\strlen($data) <= 752) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $offset = $ffi->nntmux_video_transport_stream_offset($data, \strlen($data));

        return \is_int($offset) && $offset >= 0 ? $offset : null;
    }

    public static function videoContainerDetect(string $head, ?string $libraryPath = null): ?int
    {
        if (\strlen($head) < 12) {
            return 0;
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $code = $ffi->nntmux_video_container_detect($head, \strlen($head));

        return \is_int($code) && $code >= 0 ? $code : null;
    }

    public static function imageCandidateRank(string $name, ?string $libraryPath = null): ?int
    {
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $rank = $ffi->nntmux_image_candidate_rank($name, \strlen($name));

        return \is_int($rank) && $rank >= 0 ? $rank : null;
    }

    public static function archiveCandidateFileName(string $subject, ?string $libraryPath = null): ?string
    {
        if ($subject === '') {
            return '';
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($subject);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $len + 16)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(256, $len + 16));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_archive_candidate_file_name($subject, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_archive_candidate_file_name($subject, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    public static function archiveCandidateVolumeSetKey(string $subject, ?string $libraryPath = null): ?string
    {
        if ($subject === '') {
            return 'file:';
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($subject);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $len + 32)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, max(256, $len + 32));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_archive_candidate_volume_set_key($subject, $len, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_archive_candidate_volume_set_key($subject, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    public static function sevenZipParseListing(string $input, bool $flush, ?string $libraryPath = null): ?string
    {
        if ($input === '') {
            return '';
        }
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($input);
        $minCap = max(4096, $len + 64);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $minCap)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, $minCap);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_sevenzip_parse_listing($input, $len, $flush ? 1 : 0, $scratch, self::$scratchCap);
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written === 0) {
            return '';
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_sevenzip_parse_listing($input, $len, $flush ? 1 : 0, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            if ($written === 0) {
                return '';
            }
        }

        return FFI::string($scratch, $written);
    }

    public static function additionalClassifyTitles(
        string $groupName,
        string $joinedTitles,
        int $count,
        ?string $libraryPath = null
    ): ?string {
        $ffi = ($libraryPath === null && self::$defaultLibrary instanceof FFI)
            ? self::$defaultLibrary
            : self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $groupLen = \strlen($groupName);
        $titlesLen = \strlen($joinedTitles);
        $minCap = max(4096, $titlesLen * 2 + $count * 32 + 64);
        $scratch = (self::$scratchBuf !== null && self::$scratchCap >= $minCap)
            ? self::$scratchBuf
            : self::ensureScratch($ffi, $minCap);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_additional_classify_titles_nul(
            $groupName,
            $groupLen,
            $joinedTitles,
            $titlesLen,
            $count,
            $scratch,
            self::$scratchCap
        );
        if (! \is_int($written) || $written === -1 || $written === -2) {
            return null;
        }
        if ($written < -3) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_additional_classify_titles_nul(
                $groupName,
                $groupLen,
                $joinedTitles,
                $titlesLen,
                $count,
                $scratch,
                self::$scratchCap
            );
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
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
                    ssize_t nntmux_original_filename_nomination_keys(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_predb_identity_keys_for_row(const char *title, size_t title_len, const char *filename, size_t filename_len, char *out_buf, size_t out_cap);
                    double nntmux_similarity_percent(const char *left, size_t left_len, const char *right, size_t right_len);
                    int32_t nntmux_predb_select_best_match_index(const char *query, size_t query_len, uint32_t has_orig, const char *orig_basename, size_t orig_len, const char *hits, size_t hits_len, size_t count);
                    ssize_t nntmux_nzbsplit_literal_payload(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    size_t nntmux_nzbsplit_strip_literal_trailer_len(const char *input, size_t input_len);
                    ssize_t nntmux_nzbsplit_unwrap(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    int32_t nntmux_is_plausible_release_title(const char *input, size_t input_len);
                    ssize_t nntmux_release_fixer_clean(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_release_name_from_file(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_release_generic_clean(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
                    ssize_t nntmux_payload_identity_resolve_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
                    int64_t nntmux_tv_parse_info(const char *input, size_t input_len, uint32_t max_two_digit_year, char *out_buf, size_t out_cap);
                    ssize_t nntmux_tv_clean_name(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_movie_parse_search_name(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_manticore_escape_string(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_manticore_prepare_query(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
                    ssize_t nntmux_manticore_scope_release_query(const char *raw_query, size_t raw_len, const char *prepared_query, size_t prepared_len, const char *field_selector, size_t field_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_manticore_build_field_search_expr_nul(const char *input, size_t input_len, size_t pair_count, char *out_buf, size_t out_cap);
                    ssize_t nntmux_search_extract_suggestion(const char *searchname, size_t searchname_len, const char *query, size_t query_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_search_rank_autocomplete_nul(const char *query, size_t query_len, const char *input, size_t input_len, size_t count, size_t max_results, char *out_buf, size_t out_cap);
                    ssize_t nntmux_search_suggest_fallback_nul(const char *query, size_t query_len, const char *input, size_t input_len, size_t count, size_t max_results, char *out_buf, size_t out_cap);
                    ssize_t nntmux_cp437_to_utf8(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_normalize_nfo_encoding(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_nfo_clean_content(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_nfo_identity_text(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    int32_t nntmux_nfo_check_preconditions(const char *input, size_t input_len);
                    int32_t nntmux_nfo_validate_content(const char *input, size_t input_len);
                    ssize_t nntmux_video_transport_stream_offset(const char *input, size_t input_len);
                    int32_t nntmux_video_container_detect(const char *input, size_t input_len);
                    int32_t nntmux_image_candidate_rank(const char *input, size_t input_len);
                    ssize_t nntmux_archive_candidate_file_name(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_archive_candidate_volume_set_key(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_sevenzip_parse_listing(const char *input, size_t input_len, uint8_t flush, char *out_buf, size_t out_cap);
                    ssize_t nntmux_additional_classify_titles_nul(const char *group, size_t group_len, const char *titles, size_t titles_len, size_t count, char *out_buf, size_t out_cap);
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
