<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\NzbPasswordMetadata;
use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;

/** Immutable bytes prepared without publishing a file or reserving shared capacity. */
final readonly class PreparedManifest
{
    /** Shared declarations keep older libraries on the PHP fallback. */
    private const string CDEF = <<<'C'
        ssize_t nntmux_verification_manifest_identity(const char *, size_t, char *, size_t);
        int32_t nntmux_verification_manifest_name(const char *, size_t);
        int32_t nntmux_verification_manifest_body(const char *, size_t, const char *, size_t, const char *, size_t);
        int32_t nntmux_verification_manifest_consumes(size_t, const char *, size_t, const char *, size_t, size_t, int32_t);
        ssize_t nntmux_verification_publication_keys(int64_t, int64_t, const char *, size_t, const char *, size_t, const char *, size_t, uint8_t, uint8_t, char *, size_t);
        int32_t nntmux_verification_publication_stripe(const char *, size_t);
        int32_t nntmux_verification_article_framing(const char *, size_t);
        int32_t nntmux_verification_collection_inspect(const char *, size_t, uint8_t);
        C;

    public string $name;

    public string $compressed;

    public int $jsonBytes;

    /** @param array<string, mixed> $manifest */
    public function __construct(array $manifest)
    {
        if (array_key_exists('passwords', $manifest) && ! NzbPasswordMetadata::isValid($manifest['passwords'])) {
            throw new RuntimeException('Invalid candidate password metadata.');
        }
        $body = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $compressed = gzencode($body);
        if ($compressed === false) {
            throw new RuntimeException('Cannot compress candidate manifest.');
        }
        $identity = self::identity($body);
        $this->jsonBytes = $identity['bytes'];
        $this->name = $identity['sha256'].'.json.gz';
        $this->compressed = $compressed;
    }

    /** @return array{sha256:string,bytes:int} */
    public static function identity(string $json): array
    {
        // Admission is decided by the paired caller benchmark, never by availability alone.
        return self::identityPhp($json);
    }

    /** @return array{sha256:string,bytes:int} */
    public static function identityPhp(string $json): array
    {
        return ['sha256' => hash('sha256', $json), 'bytes' => strlen($json)];
    }

    /** @return array{sha256:string,bytes:int}|null */
    public static function identityNative(string $json): ?array
    {
        if (! self::useRustFfi() || ($ffi = self::nativeLibrary()) === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_verification_manifest_identity($json, strlen($json), $buffer, $capacity);

        return $written === 64 ? ['sha256' => FFI::string($buffer, 64), 'bytes' => strlen($json)] : null;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::useRustFfi($enabled);
    }

    private static function useRustFfi(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = true;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    /** @internal Shared only by the verification helper candidates. */
    public static function nativeLibrary(): ?FFI
    {
        return RustPipelineLibrary::resolveDomain('nfo', self::CDEF);
    }

    public const string W57_FFI_CDEF = 'int64_t nntmux_w575_manifest_entry_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W57.5 parity test's QUALIFICATION). */
    private const bool W57_RUST_FFI_DEFAULT = true;

    /**
     * Wave 57.5 routing and FFI cache, kept in function-local statics (readonly classes cannot declare static properties). Without $op it reports
     * the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[],
     * capacity]` with room for at least $val words, or null without the library.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w57_5', self::W57_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W57_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW57RustFfi(): bool
    {
        return self::w57RustFfiState() === true;
    }

    /**
     * Runs $callback with manifestEntryPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 57.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w57Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w57RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w575_manifest_entry_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Batch validation of newline-separated `name\tsegments\tbytes` manifest entries (empty lines skipped).
     * Five words per manifest: `[valid, invalid, segments, bytes, digest]`. An entry is valid with exactly
     * three fields, a name that is not empty, `.` or `..` and holds no `/`, 1-18 digit positive segments and
     * 1-18 digit bytes. Totals saturate at PHP_INT_MAX; the digest is the FNV-1a 32 hash of the manifest.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function manifestEntryPlanBatch(array $records): array
    {
        return self::usesW57RustFfi() ? self::manifestEntryPlanBatchNative($records) : self::manifestEntryPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function manifestEntryPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $valid = $invalid = $segments = $bytes = 0;
            foreach (explode("\n", $record) as $line) {
                if ($line === '') {
                    continue;
                }
                $parts = explode("\t", $line);
                if (\count($parts) !== 3) {
                    $invalid++;

                    continue;
                }
                [$name, $seg, $size] = $parts;
                if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')
                    || \strlen($seg) > 18 || ! ctype_digit($seg) || \strlen($size) > 18 || ! ctype_digit($size) || (int) $seg === 0) {
                    $invalid++;

                    continue;
                }
                $valid++;
                $seg = (int) $seg;
                $size = (int) $size;
                $segments = $seg > PHP_INT_MAX - $segments ? PHP_INT_MAX : $segments + $seg;
                $bytes = $size > PHP_INT_MAX - $bytes ? PHP_INT_MAX : $bytes + $size;
            }
            array_push($out, $valid, $invalid, $segments, $bytes, (int) hexdec(hash('fnv1a32', $record)));
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function manifestEntryPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w57Words($count, implode("\0", $records), 5 * $count) ?? self::manifestEntryPlanBatchPhp($records);
    }
}
