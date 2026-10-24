<?php

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;

final readonly class CandidateAdmission
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w18_verification_metadata_fingerprint(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Readonly classes cannot have static properties; preserve readonly value semantics.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
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

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        if (! self::rustEnabled()) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w18_verification_metadata_fingerprint($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    public function __construct(public int $candidateId, public string $identity, public int $version, public string $manifestHash) {}

    /** @return array{candidate_id: int, identity: string, version: int, manifest_hash: string} */
    public function toArray(): array
    {
        return self::rustEnabled() ? ($this->toArrayNative() ?? $this->toArrayPhp()) : $this->toArrayPhp();
    }

    /** @return array{candidate_id: int, identity: string, version: int, manifest_hash: string} */
    public function toArrayPhp(): array
    {
        return ['candidate_id' => $this->candidateId, 'identity' => $this->identity, 'version' => $this->version, 'manifest_hash' => $this->manifestHash];
    }

    /** @return array{candidate_id: int, identity: string, version: int, manifest_hash: string}|null */
    public function toArrayNative(): ?array
    {
        $result = self::computeNative(6, [$this->candidateId, $this->identity, $this->version, $this->manifestHash])['value'] ?? null;

        return is_array($result) ? $result : null;
    }
}
