<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Facades\Search;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ReindexReleaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const string FFI_CDEF = 'ptrdiff_t nntmux_w32_passkey_reindex_proxy_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyReindexProxyMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function __construct(
        private readonly int $releaseId,
    ) {}

    public function handle(): void
    {
        $payload = self::normalizeReindexPayload($this->releaseId, [], $this->attempts());

        Search::updateRelease($payload['release_id']);
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Attempts and max tries floor at 1; backoff doubles from 10s and caps at 300s. Fields keep
     * only non-empty `trim()`med strings, first occurrence wins.
     *
     * @param  array<array-key, mixed>  $fields
     * @return array{release_id: int, fields: list<string>, attempt: int, max_tries: int, remaining_tries: int, backoff_seconds: int, should_retry: bool}
     */
    public static function normalizeReindexPayload(int $releaseId, array $fields, int $attempts = 1, int $maxTries = 3): array
    {
        return self::usesRustFfi()
            ? self::normalizeReindexPayloadNative($releaseId, $fields, $attempts, $maxTries)
            : self::normalizeReindexPayloadPhp($releaseId, $fields, $attempts, $maxTries);
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array{release_id: int, fields: list<string>, attempt: int, max_tries: int, remaining_tries: int, backoff_seconds: int, should_retry: bool}
     */
    public static function normalizeReindexPayloadPhp(int $releaseId, array $fields, int $attempts = 1, int $maxTries = 3): array
    {
        $attempt = max(1, $attempts);
        $max = max(1, $maxTries);

        $normalized = [];
        $seen = [];
        foreach ($fields as $field) {
            if (! is_string($field)) {
                continue;
            }
            $field = trim($field);
            if ($field === '' || isset($seen[$field])) {
                continue;
            }
            $seen[$field] = true;
            $normalized[] = $field;
        }

        return [
            'release_id' => $releaseId,
            'fields' => $normalized,
            'attempt' => $attempt,
            'max_tries' => $max,
            'remaining_tries' => max(0, $max - $attempt),
            'backoff_seconds' => min(300, 10 << min($attempt - 1, 5)),
            'should_retry' => $attempt < $max,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array{release_id: int, fields: list<string>, attempt: int, max_tries: int, remaining_tries: int, backoff_seconds: int, should_retry: bool}
     */
    public static function normalizeReindexPayloadNative(int $releaseId, array $fields, int $attempts = 1, int $maxTries = 3): array
    {
        $items = [(string) $releaseId, (string) $attempts, (string) $maxTries];
        foreach ($fields as $field) {
            if (is_string($field)) {
                $items[] = $field;
            }
        }

        $result = self::nativePacket(4, $items);
        if ($result === null || count($result) < 6) {
            return self::normalizeReindexPayloadPhp($releaseId, $fields, $attempts, $maxTries);
        }

        return [
            'release_id' => (int) $result[0],
            'fields' => array_slice($result, 6),
            'attempt' => (int) $result[1],
            'max_tries' => (int) $result[2],
            'remaining_tries' => (int) $result[3],
            'backoff_seconds' => (int) $result[4],
            'should_retry' => $result[5] === '1',
        ];
    }

    /**
     * Count- and length-prefixed (little-endian u32) packets preserve PHP byte strings.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                $input .= pack('V', strlen($item)).$item;
            }
            if (strlen($input) > 16777216) {
                return null;
            }

            $cap = strlen($input) + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $out = $ffi->new("char[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = (int) $ffi->nntmux_w32_passkey_reindex_proxy_mcp($mode, $input, strlen($input), $out, $cap);
                if ($written >= 4 && $written <= $cap) {
                    return self::unpackNativePacket(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $cap) {
                    return null;
                }
                $cap = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function unpackNativePacket(string $packet): ?array
    {
        $length = strlen($packet);
        $header = unpack('V', $packet);
        $count = is_array($header) ? $header[1] : null;
        if (! is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $prefix = unpack('V', $packet, $offset);
            $size = is_array($prefix) ? $prefix[1] : null;
            $offset += 4;
            if (! is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search',
                self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
