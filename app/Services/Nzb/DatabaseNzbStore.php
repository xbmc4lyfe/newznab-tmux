<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Support\ReleaseArchiveNamingEvidence;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Published sources and their fingerprint work commit with the release, without filesystem staging. */
final class DatabaseNzbStore
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w16_nzb(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Candidate helpers retain PHP defaults until every paired mean/p99 gate passes.
    private static bool $useRustFfi = false;

    /**
     * Default for the Wave 40.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbServiceParserManifestWriteContentsW40ParityTest.php is below 1.5x.
     */
    public const bool W402_RUST_FFI_DEFAULT = false;

    private static ?bool $useW402RustFfi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialize() can invoke user code; bound cycles and nesting. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w16_nzb($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{source_digest: string, decoded_bytes: int}
     */
    public static function payloadMetadata(string $xml): array
    {
        return self::payloadMetadataNative($xml) ?? self::payloadMetadataPhp($xml);
    }

    /**
     * @return array{source_digest: string, decoded_bytes: int}
     */
    public static function payloadMetadataPhp(string $xml): array
    {
        return ['source_digest' => NzbSourceGuard::digest($xml), 'decoded_bytes' => strlen($xml)];
    }

    /**
     * @return array{source_digest: string, decoded_bytes: int}|null
     */
    public static function payloadMetadataNative(string $xml): ?array
    {
        $result = self::computeNative(0, [$xml]);

        return is_array($result) ? $result : null;
    }

    public static function gzipHeader(string $body): bool
    {
        return self::gzipHeaderNative($body) ?? self::gzipHeaderPhp($body);
    }

    public static function gzipHeaderPhp(string $body): bool
    {
        return str_starts_with($body, "\x1f\x8b");
    }

    public static function gzipHeaderNative(string $body): ?bool
    {
        $result = self::computeNative(1, [$body]);

        return is_bool($result) ? $result : null;
    }

    public static function chunkFits(int $decoded, int $chunk, int $maximum): bool
    {
        return self::chunkFitsNative($decoded, $chunk, $maximum) ?? self::chunkFitsPhp($decoded, $chunk, $maximum);
    }

    public static function chunkFitsPhp(int $decoded, int $chunk, int $maximum): bool
    {
        return $decoded >= 0 && $chunk >= 0 && $decoded <= $maximum && $chunk <= $maximum - $decoded;
    }

    public static function chunkFitsNative(int $decoded, int $chunk, int $maximum): ?bool
    {
        $result = self::computeNative(2, [$decoded, $chunk, $maximum]);

        return is_bool($result) ? $result : null;
    }

    public static function validPayload(string $xml, string $expectedDigest, int $maximum): bool
    {
        return self::validPayloadNative($xml, $expectedDigest, $maximum) ?? self::validPayloadPhp($xml, $expectedDigest, $maximum);
    }

    public static function validPayloadPhp(string $xml, string $expectedDigest, int $maximum): bool
    {
        return strlen($xml) <= $maximum && hash_equals($expectedDigest, self::payloadMetadataPhp($xml)['source_digest']);
    }

    public static function validPayloadNative(string $xml, string $expectedDigest, int $maximum): ?bool
    {
        $result = self::computeNative(3, [$xml, $expectedDigest, $maximum]);

        return is_bool($result) ? $result : null;
    }

    public const MAX_COMPRESSED_BYTES = 33554432;

    public const MAX_DECODED_BYTES = 268435456;

    public static function enabled(): bool
    {
        return config('nntmux.nzb_storage', 'database') === 'database';
    }

    public function read(string $guid, int $maxCompressed = self::MAX_COMPRESSED_BYTES, int $maxDecoded = self::MAX_DECODED_BYTES): string|false
    {
        $row = DB::table('release_nzb_contents')->where('guid', $guid)
            ->where('decoded_bytes', '<=', $maxDecoded)->first(['body', 'source_digest']);
        if ($row === null || ! is_string($row->body) || strlen($row->body) > $maxCompressed) {
            return false;
        }
        $xml = self::decode($row->body, $maxDecoded);
        if ($xml === false || ! self::validPayload($xml, $row->source_digest, $maxDecoded)) {
            throw new RuntimeException('Database NZB integrity check failed.');
        }

        return $xml;
    }

    public function exists(string $guid): bool
    {
        return DB::table('release_nzb_contents')->where('guid', $guid)->exists();
    }

    /** Requires the caller's release lock; lock order is release, content, job. */
    public function putLocked(object $release, string $compressed, ?string $expectedDigest = null, bool $enqueueWork = true): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Database NZB installation requires a transaction.');
        }
        $current = DB::table('releases')->where('id', $release->id)->where('guid', $release->guid)->lockForUpdate()->first(['id']);
        if ($current === null) {
            throw new RuntimeException('Release changed before database NZB installation.');
        }
        if (strlen($compressed) > self::MAX_COMPRESSED_BYTES || ($xml = self::decode($compressed, self::MAX_DECODED_BYTES)) === false) {
            throw new RuntimeException('Invalid or oversized compressed NZB.');
        }
        $previous = DB::table('release_nzb_contents')->where('guid', $release->guid)->lockForUpdate()->first();
        if ($expectedDigest !== null && ($previous === null || ! hash_equals($previous->source_digest, $expectedDigest))) {
            throw new RuntimeException('NZB source changed before replacement.');
        }
        $metadata = self::payloadMetadata($xml);
        $digest = $metadata['source_digest'];
        $now = now('UTC');
        $values = ['releases_id' => $release->id, 'body' => $compressed, 'source_digest' => $digest,
            'generation' => $previous !== null && hash_equals($previous->source_digest, $digest) ? $previous->generation : bin2hex(random_bytes(16)),
            'decoded_bytes' => $metadata['decoded_bytes'], 'updated_at' => $now];
        if ($previous === null) {
            DB::table('release_nzb_contents')->insert(['guid' => $release->guid, 'created_at' => $now] + $values);
        } else {
            DB::table('release_nzb_contents')->where('guid', $release->guid)->update($values);
        }
        app(ReleaseArchiveNamingEvidence::class)->sourceReplacedLocked($release, $digest);
        if ($enqueueWork) {
            app(DatabaseFingerprintQueue::class)->enqueueLocked($release, $digest);
        }

        return $digest;
    }

    public function put(object $release, string $xml): string
    {
        $body = gzencode($xml);
        if ($body === false) {
            throw new RuntimeException('Cannot compress NZB.');
        }

        return DB::transaction(fn (): string => $this->putLocked($release, $body));
    }

    /** Reject truncated streams, concatenated members and decompression bombs. */
    public static function decode(string $body, int $maxDecoded): string|false
    {
        if ($maxDecoded < 1 || ! self::gzipHeader($body)) {
            return false;
        }
        $inflater = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) {
            return false;
        }
        $xml = '';
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += 256) {
            $chunk = @inflate_add($inflater, substr($body, $offset, 256), $offset + 256 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($chunk === false || ! self::chunkFits(strlen($xml), strlen($chunk), $maxDecoded)) {
                return false;
            }
            $xml .= $chunk;
            if (inflate_get_status($inflater) === ZLIB_STREAM_END) {
                break;
            }
        }

        return $xml !== '' && inflate_get_status($inflater) === ZLIB_STREAM_END && inflate_get_read_len($inflater) === $length ? $xml : false;
    }

    public static function useW402RustFfi(?bool $use): void
    {
        self::$useW402RustFfi = $use;
    }

    public static function usesW402RustFfi(): bool
    {
        return self::$useW402RustFfi ?? self::W402_RUST_FFI_DEFAULT;
    }

    public static function resetW402RustFfi(): void
    {
        self::$useW402RustFfi = null;
    }

    /**
     * Summarize write results (`status`, `bytes`, `latency_ms`). Status `written` or `skipped_existing` (exact strings)
     * count as such and anything else as failed. Integer bytes of written results sum, negatives as zero, saturating at
     * PHP_INT_MAX; any result whose integer latency exceeds max(0, $warnLatencyMs) is slow.
     *
     * @param  array<array-key, mixed>  $results
     * @return array{results: int, written: int, skipped_existing: int, failed: int, slow_writes: int, bytes_written: int}
     */
    public static function summarizeWriteBatchOutcomes(array $results, int $warnLatencyMs = 1000): array
    {
        return (self::usesW402RustFfi() ? self::summarizeWriteBatchOutcomesNative($results, $warnLatencyMs) : null)
            ?? self::summarizeWriteBatchOutcomesPhp($results, $warnLatencyMs);
    }

    /**
     * @param  array<array-key, mixed>  $results
     * @return array{results: int, written: int, skipped_existing: int, failed: int, slow_writes: int, bytes_written: int}
     */
    private static function summarizeWriteBatchOutcomesPhp(array $results, int $warnLatencyMs): array
    {
        $warn = max(0, $warnLatencyMs);
        $counts = [0, 0, 0, 0];
        $total = 0;
        foreach ($results as $result) {
            [$status, $bytes, $latency] = self::writeResultFields($result);
            $counts[match ($status) {
                'written' => 0,
                'skipped_existing' => 1,
                default => 2,
            }]++;
            if ($status === 'written') {
                $bytes = max(0, $bytes);
                $total = $total > PHP_INT_MAX - $bytes ? PHP_INT_MAX : $total + $bytes;
            }
            $counts[3] += $latency > $warn ? 1 : 0;
        }

        return self::writeBatchSummary(\count($results), $counts, $total);
    }

    /**
     * @param  array<array-key, mixed>  $results
     * @return array{results: int, written: int, skipped_existing: int, failed: int, slow_writes: int, bytes_written: int}|null
     */
    private static function summarizeWriteBatchOutcomesNative(array $results, int $warnLatencyMs): ?array
    {
        $size = 16 + 24 * \count($results);
        foreach ($results as $result) {
            $status = self::writeResultFields($result)[0];
            $size += $status === null ? 0 : \strlen($status);
        }
        if ($size > NzbService::W402_MAX_INPUT) {
            return null;
        }
        $words = [$warnLatencyMs, \count($results)];
        $tail = '';
        foreach ($results as $result) {
            [$status, $bytes, $latency] = self::writeResultFields($result);
            array_push($words, $status === null ? -1 : \strlen($status), $bytes, $latency);
            $tail .= $status ?? '';
        }
        $slots = NzbService::evaluateW402Native(4, pack('q*', ...$words).$tail, 6);
        if ($slots === null) {
            return null;
        }
        [$count, $written, $skipped, $failed, $slow, $total] = $slots;
        if ($count !== \count($results) || min($written, $skipped, $failed, $slow, $total) < 0 || $written + $skipped + $failed !== $count || $slow > $count) {
            return null;
        }

        return self::writeBatchSummary($count, [$written, $skipped, $failed, $slow], $total);
    }

    /** @return array{string|null, int, int} the status (null unless a string), bytes and latency (each 0 unless an integer) */
    private static function writeResultFields(mixed $result): array
    {
        if (! \is_array($result)) {
            return [null, 0, 0];
        }
        $status = $result['status'] ?? null;
        $bytes = $result['bytes'] ?? 0;
        $latency = $result['latency_ms'] ?? 0;

        return [\is_string($status) ? $status : null, \is_int($bytes) ? $bytes : 0, \is_int($latency) ? $latency : 0];
    }

    /**
     * @param  array{int, int, int, int}  $counts  written, skipped existing, failed, slow
     * @return array{results: int, written: int, skipped_existing: int, failed: int, slow_writes: int, bytes_written: int}
     */
    private static function writeBatchSummary(int $results, array $counts, int $total): array
    {
        return ['results' => $results, 'written' => $counts[0], 'skipped_existing' => $counts[1], 'failed' => $counts[2], 'slow_writes' => $counts[3],
            'bytes_written' => $total];
    }

    /** Wave 55.2 paired benchmarks decide this default; see RustNfoServiceDbNzbStoreNfoImportNzbContentsParserW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    private const string W55_FFI_CDEF = <<<'C'
        intptr_t nntmux_w552_nzb_store_integrity_plan_batch(const char *stored, size_t stored_len, const char *computed, size_t computed_len, const char *ints, size_t ints_len, size_t count, int64_t max_compressed, int64_t max_decoded, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 55 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w55', self::W55_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w55RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $enabled = self::w55RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planPayloadIntegrityBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /**
     * Batch integrity plan over stored rows, as read() filters a row and validPayload() checks
     * its decoded payload. One word per row: `flags | crc32(stored digest) << 32`. Flags: 1
     * readable (compressed bytes in 0..=$maxCompressed, decoded bytes at most $maxDecoded), 2 the
     * stored digest equals the computed one, 4 the stored digest is 64 lowercase hex, 8 valid
     * (readable, matching, decoded bytes not negative), 16 a negative size, 32 an empty stored
     * digest. Readable, matching, valid and malformed-digest rows, the readable compressed bytes,
     * the valid decoded bytes (both saturating at PHP_INT_MAX), the largest valid decoded size and
     * the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $storedDigests
     * @param  list<string>  $computedDigests
     * @param  list<int>  $compressedBytes
     * @param  list<int>  $decodedBytes
     * @return list<int>
     */
    public static function planPayloadIntegrityBatch(array $storedDigests, array $computedDigests, array $compressedBytes, array $decodedBytes,
        int $maxCompressed = self::MAX_COMPRESSED_BYTES, int $maxDecoded = self::MAX_DECODED_BYTES): array
    {
        return self::usesW55RustFfi()
            ? self::planPayloadIntegrityBatchNative($storedDigests, $computedDigests, $compressedBytes, $decodedBytes, $maxCompressed, $maxDecoded)
            : self::planPayloadIntegrityBatchPhp($storedDigests, $computedDigests, $compressedBytes, $decodedBytes, $maxCompressed, $maxDecoded);
    }

    /**
     * @param  list<string>  $storedDigests
     * @param  list<string>  $computedDigests
     * @param  list<int>  $compressedBytes
     * @param  list<int>  $decodedBytes
     * @return list<int>
     */
    public static function planPayloadIntegrityBatchPhp(array $storedDigests, array $computedDigests, array $compressedBytes, array $decodedBytes,
        int $maxCompressed = self::MAX_COMPRESSED_BYTES, int $maxDecoded = self::MAX_DECODED_BYTES): array
    {
        [$computedDigests, $compressedBytes, $decodedBytes] = [array_values($computedDigests), array_values($compressedBytes), array_values($decodedBytes)];
        $out = [];
        $readableCount = $matchedCount = $validCount = $malformed = $compressedTotal = $decodedTotal = $largest = 0;
        foreach (array_values($storedDigests) as $i => $stored) {
            $compressed = $compressedBytes[$i] ?? 0;
            $decoded = $decodedBytes[$i] ?? 0;
            $readable = $compressed >= 0 && $compressed <= $maxCompressed && $decoded <= $maxDecoded;
            $matched = hash_equals($stored, $computedDigests[$i] ?? '');
            $wellFormed = \strlen($stored) === 64 && strspn($stored, '0123456789abcdef') === 64;
            $valid = $readable && $matched && $decoded >= 0;
            $readableCount += (int) $readable;
            $matchedCount += (int) $matched;
            $validCount += (int) $valid;
            $malformed += (int) ! $wellFormed;
            if ($readable) {
                $compressedTotal = $compressedTotal > PHP_INT_MAX - $compressed ? PHP_INT_MAX : $compressedTotal + $compressed;
            }
            if ($valid) {
                $decodedTotal = $decodedTotal > PHP_INT_MAX - $decoded ? PHP_INT_MAX : $decodedTotal + $decoded;
                $largest = max($largest, $decoded);
            }
            $out[] = ($readable ? 1 : 0) | ($matched ? 2 : 0) | ($wellFormed ? 4 : 0) | ($valid ? 8 : 0)
                | ($compressed < 0 || $decoded < 0 ? 16 : 0) | ($stored === '' ? 32 : 0) | crc32($stored) << 32;
        }
        array_push($out, $readableCount, $matchedCount, $validCount, $malformed, $compressedTotal, $decodedTotal, $largest);
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a digest holds a NUL byte, or
     * the columns differ in length or are not lists.
     *
     * @param  list<string>  $storedDigests
     * @param  list<string>  $computedDigests
     * @param  list<int>  $compressedBytes
     * @param  list<int>  $decodedBytes
     * @return list<int>
     */
    public static function planPayloadIntegrityBatchNative(array $storedDigests, array $computedDigests, array $compressedBytes, array $decodedBytes,
        int $maxCompressed = self::MAX_COMPRESSED_BYTES, int $maxDecoded = self::MAX_DECODED_BYTES): array
    {
        $count = \count($storedDigests);
        $words = $count + 8;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w55RustFfiState('out', $words);
        if ($state === null || \count($computedDigests) !== $count || \count($compressedBytes) !== $count || \count($decodedBytes) !== $count
            || ! array_is_list($storedDigests) || ! array_is_list($computedDigests) || ! array_is_list($compressedBytes) || ! array_is_list($decodedBytes)) {
            return self::planPayloadIntegrityBatchPhp($storedDigests, $computedDigests, $compressedBytes, $decodedBytes, $maxCompressed, $maxDecoded);
        }
        $stored = implode("\0", $storedDigests);
        $computed = implode("\0", $computedDigests);
        $ints = pack('P*', ...$compressedBytes, ...$decodedBytes);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W55_FFI_CDEF.)
            $written = $ffi->nntmux_w552_nzb_store_integrity_plan_batch($stored, \strlen($stored), $computed, \strlen($computed), $ints, \strlen($ints), $count, $maxCompressed, $maxDecoded, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planPayloadIntegrityBatchPhp($storedDigests, $computedDigests, $compressedBytes, $decodedBytes, $maxCompressed, $maxDecoded);
    }
}
