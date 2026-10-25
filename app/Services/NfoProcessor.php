<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

class NfoProcessor
{
    public const string FFI_CDEF = CandidateRepository::FFI_CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private NfoService $nfo;

    public function __construct(NfoService $nfo)
    {
        $this->nfo = $nfo;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Process NFO files if enabled by settings.
     */
    public function process(NNTPService $nntp, string $groupID = '', string $guidChar = ''): void
    {
        if ((int) app(ConfigurationProvider::class)->postProcessing()->lookupNfo === 1) {
            $metadata = app(ConfigurationProvider::class)->metadata();
            $lookups = self::lookupFlags($metadata->movieLookup->value, $metadata->tvLookup->value);
            $this->nfo->processNfoFiles($nntp, $groupID, $guidChar, $lookups['imdb'], $lookups['tv']);
        }
    }

    /**
     * Which metadata lookups NFO processing runs: any enabled lookup mode (non-zero) turns its lookup on.
     *
     * @return array{imdb: bool, tv: bool}
     */
    public static function lookupFlags(int $movieLookup, int $tvLookup): array
    {
        return self::usesRustFfi() ? self::lookupFlagsNative($movieLookup, $tvLookup) : self::lookupFlagsPhp($movieLookup, $tvLookup);
    }

    /** @return array{imdb: bool, tv: bool} */
    public static function lookupFlagsPhp(int $movieLookup, int $tvLookup): array
    {
        return ['imdb' => (bool) $movieLookup, 'tv' => (bool) $tvLookup];
    }

    /** @return array{imdb: bool, tv: bool} */
    public static function lookupFlagsNative(int $movieLookup, int $tvLookup): array
    {
        $slots = CandidateRepository::nativeSlots(CandidateRepository::evaluateNative(6, pack('q2', $movieLookup, $tvLookup)), 1);
        if ($slots !== null && $slots[0] >= 0 && $slots[0] <= 3) {
            return ['imdb' => ($slots[0] & 1) === 1, 'tv' => ($slots[0] & 2) === 2];
        }

        return self::lookupFlagsPhp($movieLookup, $tvLookup);
    }

    /** Disabled: common p99 stays below 1.5x in five paired runs (1.42x, 1.00x, 1.03x, 1.23x, 1.39x; see the W42 parity test). */
    public const bool W424_RUST_FFI_DEFAULT = false;

    public const string W424_FFI_CDEF = <<<'C'
        ssize_t nntmux_w424_nfo_candidate_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W424_SUMMARY_KEYS = ['total_files', 'empty_name_count', 'nfo_count', 'diz_count', 'txt_count', 'sample_proof_count', 'nested_path_count'];

    private static ?bool $w424UseRustFfi = null;

    private static FFI|false|null $w424Ffi = null;

    public static function useW424RustFfi(bool $enable = true): void
    {
        self::$w424UseRustFfi = $enable;
    }

    public static function usesW424RustFfi(): bool
    {
        return self::$w424UseRustFfi ?? self::W424_RUST_FFI_DEFAULT;
    }

    public static function resetW424RustFfi(): void
    {
        self::$w424UseRustFfi = null;
        self::$w424Ffi = null;
    }

    /**
     * Summarize NFO candidate file names: case-insensitive nfo, diz and
     * txt extensions after the last dot, SAMPLE/PROOF alphanumeric tokens
     * and names containing a forward or back slash.
     * Entries come from strings, integers, or the filename or name array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeNfoCandidateBatch(array $entries): array
    {
        return self::usesW424RustFfi()
            ? self::summarizeNfoCandidateBatchNative($entries)
            : self::summarizeNfoCandidateBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeNfoCandidateBatchNative(array $entries): array
    {
        return self::w424Call(self::w424Entries($entries)) ?? self::summarizeNfoCandidateBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeNfoCandidateBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W424_SUMMARY_KEYS, 0);
        foreach (self::w424Entries($entries) as $entry) {
            $summary['total_files']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_name_count']++;

                continue;
            }
            $dot = strrpos($entry, '.');
            $extension = $dot === false ? '' : strtolower(substr($entry, $dot + 1));
            $summary['nfo_count'] += (int) ($extension === 'nfo');
            $summary['diz_count'] += (int) ($extension === 'diz');
            $summary['txt_count'] += (int) ($extension === 'txt');
            $summary['sample_proof_count'] += (int) (preg_match('/(?<![A-Za-z0-9])(?:SAMPLE|PROOF)(?![A-Za-z0-9])/i', $entry) === 1);
            $summary['nested_path_count'] += (int) (strpbrk($entry, '/\\') !== false);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w424Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['filename'] ?? $item['name'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w424Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w424Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w424', self::W424_FFI_CDEF) ?? false;
            $ffi = self::$w424Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
            $written = $ffi->nntmux_w424_nfo_candidate_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
                $written = $ffi->nntmux_w424_nfo_candidate_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W424_SUMMARY_KEYS || $result['total_files'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W424_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 51.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.64x, p99 1.90x; uncommon mean 2.52x, p99 2.33x); see RustIsbnOpenLibPar2ListProcessorNfoW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w514_nfo_candidate_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Candidate flags returned by nfoCandidateBatch(). */
    public const int W51_EMPTY_NAME = 1;

    public const int W51_NFO = 2;

    public const int W51_DIZ = 4;

    public const int W51_TXT = 8;

    public const int W51_SAMPLE_PROOF = 16;

    public const int W51_NESTED_PATH = 32;

    private static ?bool $useW51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$useW51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W51 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW51RustFfi;
        self::$useW51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW51RustFfi = $previous;
        }
    }

    /**
     * Classify NFO candidate file names, two ints each: [flags, index of the first earlier name
     * equal after trim() and strtolower(), or -1]. Flags follow summarizeNfoCandidateBatchPhp()
     * on the trimmed name: 1 empty (never a duplicate), 2 nfo, 4 diz, 8 txt extension, 16
     * SAMPLE/PROOF token, 32 slash path.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function nfoCandidateBatch(array $names): array
    {
        return self::usesW51RustFfi()
            ? self::nfoCandidateBatchNative($names)
            : self::nfoCandidateBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function nfoCandidateBatchNative(array $names): array
    {
        self::w51AssertList($names);
        if ($names === []) {
            return [];
        }

        return self::w51Call(implode("\0", $names), '', \count($names))
            ?? self::nfoCandidateBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function nfoCandidateBatchPhp(array $names): array
    {
        self::w51AssertList($names);
        $out = [];
        $seen = [];
        foreach ($names as $i => $raw) {
            $entry = trim($raw);
            if ($entry === '') {
                array_push($out, self::W51_EMPTY_NAME, -1);

                continue;
            }
            $first = $seen[strtolower($entry)] ??= $i;
            $dot = strrpos($entry, '.');
            $flags = match ($dot === false ? '' : strtolower(substr($entry, $dot + 1))) {
                'nfo' => self::W51_NFO,
                'diz' => self::W51_DIZ,
                'txt' => self::W51_TXT,
                default => 0,
            };
            if (preg_match('/(?<![A-Za-z0-9])(?:SAMPLE|PROOF)(?![A-Za-z0-9])/i', $entry) === 1) {
                $flags |= self::W51_SAMPLE_PROOF;
            }
            if (strpbrk($entry, '/\\') !== false) {
                $flags |= self::W51_NESTED_PATH;
            }
            array_push($out, $flags, $first === $i ? -1 : $first);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined names, two int64 words back per record. Null when
     * the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w51Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w51', self::W51_FFI_CDEF) ?? false;
            $ffi = self::$w51Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 2;
            $out = self::$w51Out;
            if ($out === null || self::$w51OutCap < $need) {
                self::$w51OutCap = max(1024, $need);
                $out = self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w514_nfo_candidate_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w51OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w51AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('W51 batch input must be a list.');
        }
    }
}
