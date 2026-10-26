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

    /** C signature of the Wave 64.5 {@see self::w64ArticleFetcherPlanBatch()} helper. */
    private const string W64_ARTICLE_FETCHER_PLAN_FFI_CDEF = 'int64_t nntmux_w645_article_fetcher_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 64.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.92/6.02/13.04/10.91x); see tests/Unit/RustAdditionalFfmpegFrameScorerRankerInspectorFetcherW64ParityTest.php.
     */
    public const bool W64_RUST_FFI_DEFAULT = true;

    /**
     * Wave 64.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w64_5', self::W64_ARTICLE_FETCHER_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W64_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 64.5 plan batch to Rust until disabled or reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Route the Wave 64.5 plan batch to PHP until enabled or reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restore W64_RUST_FFI_DEFAULT routing and drop the cached Wave 64.5 handle and buffer. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('set', null);
        self::w64RustFfiState('reset', null);
    }

    public static function usesW64RustFfi(): bool
    {
        return self::w64RustFfiState() === true;
    }

    /**
     * Runs $callback with w64ArticleFetcherPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 64.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w64Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w64RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w645_article_fetcher_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w64Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch article fetch plan over `from\tmax_bytes\tbudget\tarticle_estimate\tsegments`, where segments are
     * `|`-separated `size[:state]` items (an empty field has none). States: empty or `ok` fetches on the first
     * attempt, `retry` on a retry, `alt` from the alternate provider after both primary attempts fail, and
     * `missing` (or anything else) never arrives. Ten words per record: `[segments, start, end, written, spent,
     * attempts, retries, alternates, reason, flags]`. Like append(), fetching starts at segmentStartPhp() and each
     * article first checks, in order: 0 out of segments, 1 byte limit reached, 2 budget would be exceeded (budget
     * > 0 and spent + estimate > budget). A missing article costs three attempts and stops with reason 3. Spent
     * counts whole articles (capped at 2^62) while written adds each chunkLengthPhp() of the remaining byte
     * limit. Numbers are trimmed 1-15 ASCII digits (else 0); sizes are untrimmed 1-15 digits (else 0). Flags: 1
     * invalid number or size, 2 not exactly five fields, 4 start past the last segment, 8 unknown state.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ArticleFetcherPlanBatch(array $records): array
    {
        return self::usesW64RustFfi() ? self::w64ArticleFetcherPlanBatchNative($records) : self::w64ArticleFetcherPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ArticleFetcherPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w64Words($count, implode("\0", $records), 10 * $count) ?? self::w64ArticleFetcherPlanBatchPhp($records);
    }

    private const int W64_SPENT_CAP = 1 << 62;

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ArticleFetcherPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 5 ? 0 : 2;
            $from = self::w64Number($fields[0], 0, $flags);
            $maxBytes = self::w64Number($fields[1] ?? '', 0, $flags);
            $budget = self::w64Number($fields[2] ?? '', 0, $flags);
            $estimate = self::w64Number($fields[3] ?? '', 0, $flags);
            $sizes = $states = [];
            if (($fields[4] ?? '') !== '') {
                foreach (explode('|', $fields[4]) as $item) {
                    $colon = strpos($item, ':');
                    $size = $colon === false ? $item : substr($item, 0, $colon);
                    if ($size !== '' && \strlen($size) <= 15 && strspn($size, '0123456789') === \strlen($size)) {
                        $sizes[] = (int) $size;
                    } else {
                        $flags |= 1;
                        $sizes[] = 0;
                    }
                    $state = match ($colon === false ? '' : substr($item, $colon + 1)) {
                        '', 'ok' => 0,
                        'retry' => 1,
                        'alt' => 2,
                        'missing' => 3,
                        default => -1,
                    };
                    if ($state < 0) {
                        $flags |= 8;
                        $state = 3;
                    }
                    $states[] = $state;
                }
            }
            $count = \count($sizes);
            $start = $maxBytes > 0 ? $from : $count;
            if ($start > $count) {
                $flags |= 4;
            }
            $index = $start;
            $written = $spent = $attempts = $retries = $alternates = 0;
            while (true) {
                if ($index >= $count) {
                    $reason = 0;

                    break;
                }
                if ($written >= $maxBytes) {
                    $reason = 1;

                    break;
                }
                if ($budget > 0 && $spent + $estimate > $budget) {
                    $reason = 2;

                    break;
                }
                $state = $states[$index];
                $attempts += min($state, 2) + 1;
                if ($state > 0) {
                    $retries++;
                }
                if ($state === 3) {
                    $reason = 3;

                    break;
                }
                if ($state === 2) {
                    $alternates++;
                }
                $spent = min($spent + $sizes[$index], self::W64_SPENT_CAP);
                $written += min($sizes[$index], $maxBytes - $written);
                $index++;
            }
            array_push($out, $count, $start, $index, $written, $spent, $attempts, $retries, $alternates, $reason, $flags);
        }

        return $out;
    }
}
