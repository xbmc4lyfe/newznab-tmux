<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use FFI;
use Throwable;

/** Optional native binding, independent of the older metadata-parser symbols. */
class NativeSevenZipListing
{
    private const int MAX_SAMPLE_BYTES = 16_777_216;

    /** @var array<string, FFI|null> */
    private static array $libraries = [];

    /** @return array<string, mixed>|null */
    public function listing(string $head, ?string $tail, int $knownReleaseSize): ?array
    {
        $tail ??= '';
        $headLength = strlen($head);
        $tailLength = strlen($tail);
        if ($headLength > self::MAX_SAMPLE_BYTES
            || $tailLength > self::MAX_SAMPLE_BYTES - $headLength
            || $knownReleaseSize < 0
        ) {
            return null;
        }
        $library = $this->library();
        if ($library === null) {
            return null;
        }

        try {
            $headBuffer = $library->new('unsigned char['.max(1, $headLength).']');
            $tailBuffer = $library->new('unsigned char['.max(1, $tailLength).']');
            if ($headLength > 0) {
                FFI::memcpy($headBuffer, $head, $headLength);
            }
            if ($tailLength > 0) {
                FFI::memcpy($tailBuffer, $tail, $tailLength);
            }
            $pointer = $library->nntmux_seven_zip_listing_v1_json($headBuffer, $headLength, $tailBuffer, $tailLength, $knownReleaseSize); // @phpstan-ignore method.notFound
            if (FFI::isNull($pointer)) {
                return null;
            }
            try {
                $encoded = FFI::string($pointer);
            } finally {
                $library->nntmux_metadata_string_free($pointer); // @phpstan-ignore method.notFound
            }
            if (strlen($encoded) > 4_194_304) {
                return null;
            }
            $decoded = json_decode($encoded, true, 64, JSON_BIGINT_AS_STRING);

            return is_array($decoded) && ($decoded['schema_version'] ?? null) === 1
                && in_array($decoded['status'] ?? null, ['complete', 'incomplete', 'unsupported', 'missing_bytes', 'invalid', 'encrypted'], true)
                && in_array($decoded['encryption'] ?? null, ['encrypted', 'unencrypted', 'unknown'], true)
                && is_bool($decoded['listing_complete'] ?? null)
                && is_array($decoded['files'] ?? null)
                && count($decoded['files']) <= 1000
                ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function library(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        $resolved = realpath((string) config('yenc.native_library', ''));
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        if (array_key_exists($key, self::$libraries)) {
            return self::$libraries[$key];
        }
        try {
            $definitions = <<<'C'
            char* nntmux_seven_zip_listing_v1_json(const unsigned char* head, size_t head_length, const unsigned char* tail, size_t tail_length, uint64_t release_size);
            void nntmux_metadata_string_free(char* value);
            C;
            self::$libraries[$key] = FFI::cdef($definitions, $resolved);
        } catch (Throwable) {
            self::$libraries[$key] = null;
        }

        return self::$libraries[$key];
    }
}
