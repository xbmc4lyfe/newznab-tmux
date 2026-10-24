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
}
