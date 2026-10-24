<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Support\ReleaseArchiveNamingEvidence;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
