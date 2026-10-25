<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Appends consecutive articles of one NZB file to a local file.
 *
 * Every article's yEnc header must say it starts where the previous one ended,
 * so a missing article or out-of-order NZB never produces a file with a hole in it.
 */
class MediaArticleFetcher
{
    public const bool W45_RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_fetcher_safe_witness_name(const char *ptr, size_t len);
        int64_t nntmux_fetcher_chunk_length(int64_t data_len, int64_t length);
        int64_t nntmux_w45_fetcher_chunk_length(int64_t data_len, int64_t length);
        int64_t nntmux_w45_fetcher_segment_start(int64_t count, int64_t from, int64_t max_bytes);
        C;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = self::W45_RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    public static function useW45RustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? self::W45_RUST_FFI_DEFAULT;
    }

    public static function usesW45RustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function __construct(private readonly UsenetDownloadService $downloadService) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  list<string>  $segments  the NZB file's message IDs, in order
     * @param  int|null  $expectedOffset  file offset the first article must start at, or null to accept any
     * @param  float  $deadline  hrtime seconds after which no article is requested
     * @return int bytes appended to $path
     */
    public function append(
        ReleaseProcessingContext $context,
        array $segments,
        int $fromSegment,
        ?int $expectedOffset,
        int $maxBytes,
        string $path,
        MediaByteBudget $budget,
        float $deadline,
        int $articleEstimate,
    ): int {
        $written = 0;

        for ($index = self::segmentStart(count($segments), $fromSegment, $maxBytes); $index < count($segments) && $written < $maxBytes; $index++) {

            if ($context->groupUnavailable || $budget->exhausted() || hrtime(true) / 1e9 >= $deadline || ! $budget->allows($articleEstimate)) {
                break;
            }

            $result = $this->downloadService->downloadArticle(
                DownloadKind::MediaSource,
                (string) $segments[$index],
                $context->releaseGroupName,
                $context->release->id,
            );
            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;

                break;
            }
            if (! $result['success'] || ! is_string($result['data'])) {
                break;
            }

            $budget->spend(strlen($result['data']));
            // A cached copy has no yEnc header; only a file's first article is placed without one.
            $offset = $result['part']?->offset() ?? ($index === 0 && $expectedOffset === 0 ? 0 : null);
            if ($offset === null || ($expectedOffset !== null && $offset !== $expectedOffset)) {
                break;
            }

            $chunk = substr($result['data'], 0, self::chunkLength(strlen($result['data']), $maxBytes - $written));
            if (file_put_contents($path, $chunk, FILE_APPEND) === false) {
                break;
            }
            $part = $result['part'];
            if ($index === 0 && $expectedOffset === 0 && $part !== null && $part->offset() === 0
                && ($part->part === null || $part->part === 1) && $part->size > 0 && strlen($result['data']) <= $part->size
                && $context->nzbSourceDigest !== null && self::isSafeWitnessName($part->name)
                && ! ArchiveExtractionService::hasArchiveSignature($result['data'])
                && ($container = VideoContainer::detect($result['data'])) !== null
                && $container === VideoContainer::fromFileName('video.'.pathinfo($part->name, PATHINFO_EXTENSION))) {
                $context->directVideoWitnesses[hash('sha256', (string) $segments[$index])] = [
                    'name' => $part->name, 'size' => $part->size, 'digest' => $context->nzbSourceDigest,
                ];
            }
            $written += strlen($chunk);
            $expectedOffset = $offset + strlen($result['data']);
        }

        return $written;
    }

    /**
     * A bare, printable UTF-8 file name that may vouch for a directly posted video.
     */
    public static function isSafeWitnessName(string $name): bool
    {
        return (self::$useRustFfi ? self::isSafeWitnessNameNative($name) : null) ?? self::isSafeWitnessNamePhp($name);
    }

    public static function isSafeWitnessNamePhp(string $name): bool
    {
        return $name !== '' && strlen($name) <= 4096
            && mb_check_encoding($name, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1
            && basename($name) === $name && ! str_contains($name, '\\')
            && ! in_array($name[0], ['.', '-'], true);
    }

    public static function isSafeWitnessNameNative(string $name): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_fetcher_safe_witness_name($name, strlen($name));
        } catch (Throwable) {
            return null;
        }

        return match ($code) {
            0 => false,
            1 => true,
            default => null,
        };
    }

    /**
     * Byte length of `substr($data, 0, $length)` for a `$dataLength`-byte string.
     */
    public static function chunkLength(int $dataLength, int $length): int
    {
        return (self::$useRustFfi ? self::chunkLengthNative($dataLength, $length) : null) ?? self::chunkLengthPhp($dataLength, $length);
    }

    public static function chunkLengthPhp(int $dataLength, int $length): int
    {
        if ($dataLength < 0) {
            return 0;
        }

        return $length >= 0 ? min($length, $dataLength) : max(0, $dataLength + $length);
    }

    public static function chunkLengthNative(int $dataLength, int $length): ?int
    {
        // Preserve the existing Native API's unsupported-length sentinel.
        if ($dataLength < 0) {
            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $value = (int) $ffi->nntmux_w45_fetcher_chunk_length($dataLength, $length);
        } catch (Throwable) {
            return null;
        }

        return $value >= 0 ? $value : null;
    }

    /** First segment to inspect; a non-positive byte request does no work. */
    public static function segmentStart(int $count, int $from, int $maxBytes): int
    {
        return (self::$useRustFfi ? self::segmentStartNative($count, $from, $maxBytes) : null)
            ?? self::segmentStartPhp($count, $from, $maxBytes);
    }

    public static function segmentStartPhp(int $count, int $from, int $maxBytes): int
    {
        return $maxBytes > 0 ? max(0, $from) : $count;
    }

    public static function segmentStartNative(int $count, int $from, int $maxBytes): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null || $count < 0) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $start = $ffi->nntmux_w45_fetcher_segment_start($count, $from, $maxBytes);
        } catch (Throwable) {
            return null;
        }

        return is_int($start) && $start >= 0 ? $start : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    // Rust is the default only while the paired W52.6 benchmark clears 1.5x on all four metrics.
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        int32_t nntmux_w526_fetcher_append_plan_batch(const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *ints, size_t ints_len, size_t count, int64_t from, int64_t expected, int32_t has_expected, int64_t max_bytes, int64_t *out, size_t out_count);
        C;

    public const int W52_APPEND_HEADER = 9;

    private const int W52_VALUE_CAP = (1 << 40) - 1;

    private const int W52_MAX_ITEMS = 1_048_576;

    private static ?bool $w52RustFfi = null;

    private static FFI|false|null $w52Ffi = null;

    private static ?CData $w52Out = null;

    private static int $w52Capacity = 0;

    /**
     * Plan `append()`'s acceptance loop for articles that all download, without any I/O.
     *
     * `$lengths` are the articles' data lengths and `$offsets` their yEnc offsets (negative for a
     * cached copy without a yEnc header; missing ones count as such); both, like
     * `$expectedOffset`, are clamped to 0..2^40-1. `$names` are the yEnc file names (missing ones
     * are empty). Layout: `[0]` count, `[1]` first segment, `[2]` first segment not appended,
     * `[3]` bytes appended, `[4]` stop reason (0 out of segments, 1 byte limit, 2 no offset,
     * 3 offset mismatch), `[5]` next expected offset (-1 for none), `[6]` safe witness names,
     * `[7]` 1 when a direct-video witness may be recorded, `[8]` FNV-1a digest; then per article
     * `appended | safe_name << 1 | witness << 2 | chunk << 3`.
     *
     * @param  array<array-key, int>  $lengths
     * @param  array<array-key, int>  $offsets
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function planAppendBatch(array $lengths, array $offsets, array $names, int $fromSegment, ?int $expectedOffset, int $maxBytes): array
    {
        return (self::usesW52RustFfi() ? self::planAppendBatchNative($lengths, $offsets, $names, $fromSegment, $expectedOffset, $maxBytes) : null)
            ?? self::planAppendBatchPhp($lengths, $offsets, $names, $fromSegment, $expectedOffset, $maxBytes);
    }

    /**
     * @param  array<array-key, int>  $lengths
     * @param  array<array-key, int>  $offsets
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function planAppendBatchPhp(array $lengths, array $offsets, array $names, int $fromSegment, ?int $expectedOffset, int $maxBytes): array
    {
        $lengths = array_values($lengths);
        $offsets = array_values($offsets);
        $names = array_values($names);
        $count = \count($lengths);
        $rows = [];
        $safe = 0;
        for ($index = 0; $index < $count; $index++) {
            $isSafe = self::isSafeWitnessNamePhp($names[$index] ?? '');
            $rows[] = $isSafe ? 2 : 0;
            $safe += $isSafe ? 1 : 0;
        }
        $start = self::segmentStartPhp($count, $fromSegment, $maxBytes);
        $expected = $expectedOffset === null ? null : min(max($expectedOffset, 0), self::W52_VALUE_CAP);
        $index = $start;
        $written = $witnessed = 0;
        while (true) {
            if ($index >= $count) {
                $reason = 0;

                break;
            }
            if ($written >= $maxBytes) {
                $reason = 1;

                break;
            }
            $length = min(max($lengths[$index], 0), self::W52_VALUE_CAP);
            $raw = $offsets[$index] ?? -1;
            if ($raw >= 0) {
                $offset = min($raw, self::W52_VALUE_CAP);
            } elseif ($index === 0 && $expected === 0) {
                $offset = 0;
            } else {
                $reason = 2;

                break;
            }
            if ($expected !== null && $offset !== $expected) {
                $reason = 3;

                break;
            }
            $chunk = self::chunkLengthPhp($length, $maxBytes - $written);
            $witness = $index === 0 && $expected === 0 && $raw === 0 && ($rows[0] & 2) !== 0 ? 1 : 0;
            $rows[$index] |= 1 | $witness << 2 | $chunk << 3;
            $witnessed |= $witness;
            $written += $chunk;
            $expected = $offset + $length;
            $index++;
        }
        $digest = 2_166_136_261;
        foreach ($rows as $row) {
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }

        return [$count, $start, $index, $written, $reason, $expected ?? -1, $safe, $witnessed, $digest, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or invalid input).
     *
     * @param  array<array-key, int>  $lengths
     * @param  array<array-key, int>  $offsets
     * @param  array<array-key, string>  $names
     * @return list<int>|null
     */
    public static function planAppendBatchNative(array $lengths, array $offsets, array $names, int $fromSegment, ?int $expectedOffset, int $maxBytes): ?array
    {
        $count = \count($lengths);
        $fields = self::W52_APPEND_HEADER + $count;
        $binding = $count <= self::W52_MAX_ITEMS ? self::w52Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        if (\count($offsets) !== $count) {
            $offsets = array_pad(\array_slice(array_values($offsets), 0, $count), $count, -1);
        }
        if (\count($names) !== $count) {
            $names = array_pad(\array_slice(array_values($names), 0, $count), $count, '');
        }
        $joined = implode("\0", $names);
        $twin = $count > 0 && substr_count($joined, "\0") !== $count - 1 ? implode("\x01", $names) : '';
        $ints = pack('q*', ...array_values($lengths), ...array_values($offsets));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w526_fetcher_append_plan_batch(
                $joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count,
                $fromSegment, $expectedOffset ?? 0, $expectedOffset === null ? 0 : 1, $maxBytes, $out, $fields,
            );
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 52.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w52RustFfi;
        self::$w52RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w52RustFfi = $previous;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        return self::$w52RustFfi ?? self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * The cached Wave 52.6 binding and an output buffer of at least `$fields` values, or null.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w52Binding(int $fields): ?array
    {
        try {
            self::$w52Ffi ??= RustPipelineLibrary::resolveDomain('additional_w52', self::W52_FFI_CDEF) ?? false;
            if (self::$w52Ffi === false) {
                return null;
            }
            if (self::$w52Out === null || self::$w52Capacity < $fields) {
                self::$w52Capacity = max(256, $fields);
                self::$w52Out = self::$w52Ffi->new('int64_t['.self::$w52Capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return [self::$w52Ffi, self::$w52Out];
    }
}
