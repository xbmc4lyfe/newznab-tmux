<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;

/**
 * What became of one remotely published candidate. The status names are the batch endpoint's
 * wire vocabulary; the primary and the worker share this type so neither invents its own.
 */
final class PublicationOutcome
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w18_verification_metadata_fingerprint(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Enable only after paired common/uncommon mean and p99 all reach 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
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
        if (! self::$useRustFfi) {
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

    public const string PUBLISHED = 'published';

    /** Policy rejected the candidate; the row is terminal and holds no release. */
    public const string FILTERED = 'filtered';

    /** A covered release lacks the supplied metadata; the candidate is retained for review. */
    public const string METADATA_REVIEW = 'metadata_review';

    /** A partial upload failed a policy more articles may still satisfy: the worker returns it to waiting. */
    public const string DEFERRED = 'deferred';

    /** The claim no longer owns the candidate generation. */
    public const string STALE = 'stale';

    /** An exclusive maintenance holder or a durable pause forbids publication; retry later. */
    public const string PAUSED = 'paused';

    /** Not started (the batch ran out of time); nothing was read or written for it. */
    public const string RETRY = 'retry';

    public const string INVALID = 'invalid';

    public const string ERROR = 'error';

    /** Primary only: publication returned no release and the row names none, as the single endpoint reports it. */
    public const string UNRESOLVED = 'unresolved';

    /**
     * Worker only: the primary could not be reached, or did not answer in time. Nothing is known about
     * the item; unlike an error response, which the primary gave, sending it again is safe and expected.
     */
    public const string TRANSPORT = 'transport';

    /** Worker only: nothing was sent, or the single endpoint answered without a release; read the row for why. */
    public const string NO_RELEASE = 'no_release';

    public function __construct(public readonly string $status, public readonly ?int $releaseId = null, public readonly ?string $error = null) {}

    public function published(): bool
    {
        return self::$useRustFfi ? ($this->publishedNative() ?? $this->publishedPhp()) : $this->publishedPhp();
    }

    public function publishedPhp(): bool
    {
        return $this->status === self::PUBLISHED && $this->releaseId !== null;
    }

    public function publishedNative(): ?bool
    {
        $result = self::computeNative(2, [$this->status, $this->releaseId])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    /** Validate wire vocabulary without changing permissive construction of existing outcomes. */
    public static function isValidStatus(mixed $status): bool
    {
        return self::$useRustFfi ? (self::isValidStatusNative($status) ?? self::isValidStatusPhp($status)) : self::isValidStatusPhp($status);
    }

    public static function isValidStatusPhp(mixed $status): bool
    {
        return in_array($status, [self::PUBLISHED, self::FILTERED, self::METADATA_REVIEW, self::DEFERRED,
            self::STALE, self::PAUSED, self::RETRY, self::INVALID, self::ERROR, self::UNRESOLVED,
            self::TRANSPORT, self::NO_RELEASE], true);
    }

    public static function isValidStatusNative(mixed $status): ?bool
    {
        $result = self::computeNative(3, [$status])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    /** @return array{status: string, release_id: int|null, error: string|null} */
    public function toArray(): array
    {
        return self::$useRustFfi ? ($this->toArrayNative() ?? $this->toArrayPhp()) : $this->toArrayPhp();
    }

    /** @return array{status: string, release_id: int|null, error: string|null} */
    public function toArrayPhp(): array
    {
        return ['status' => $this->status, 'release_id' => $this->releaseId, 'error' => $this->error];
    }

    /** @return array{status: string, release_id: int|null, error: string|null}|null */
    public function toArrayNative(): ?array
    {
        $result = self::computeNative(4, [$this->status, $this->releaseId, $this->error])['value'] ?? null;

        return is_array($result) ? $result : null;
    }
}
