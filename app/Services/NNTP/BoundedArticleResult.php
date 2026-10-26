<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\Yenc\YencPart;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/** A complete bounded article, or a failure with no usable body or metadata. */
final readonly class BoundedArticleResult
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w19_verification_prereqs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    /**
     * Enabled: the paired benchmark in the W58 parity test measures 3.321x common mean, 2.653x
     * common p99, 2.923x uncommon mean and 2.284x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_BOUNDED_ARTICLE_FFI_CDEF = 'ssize_t nntmux_w581_bounded_article_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W58_ARTICLE_WORDS = 3;

    public const int W58_ARTICLE_FAILURE = 0;

    public const int W58_ARTICLE_SUCCESS = 1;

    public const int W58_ARTICLE_OVERSIZED = 2;

    public const int W58_ARTICLE_IS_SUCCESS = 4;

    public const int W58_ARTICLE_METADATA = 8;

    public const int W58_ARTICLE_EXCEEDS = 16;

    public const int W58_ARTICLE_MALFORMED = 32;

    public const int W58_ARTICLE_TRUNCATED = 64;

    public const int W58_ARTICLE_ERROR_SHIFT = 8;

    public const int W58_ERROR_NONE = 0;

    public const int W58_ERROR_TIMEOUT = 1;

    public const int W58_ERROR_MISSING = 2;

    public const int W58_ERROR_CONNECTION = 3;

    public const int W58_ERROR_OVERSIZED = 4;

    public const int W58_ERROR_DECODE = 5;

    public const int W58_ERROR_AUTH = 6;

    public const int W58_ERROR_OTHER = 7;

    public const int W58_ERROR_EMPTY = 8;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::useRustFfi($enabled);
    }

    private static function useRustFfi(?bool $enabled = null): bool
    {
        // Single-call candidates stay off until all four performance gates pass.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    /** @param list<mixed> $arguments
     * @return array{value: mixed}|null
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        try {
            foreach ($arguments as $value) {
                if ($value !== null && ! is_scalar($value)) {
                    return null;
                }
                if (is_string($value) && strlen($value) > 64 * 1024 * 1024) {
                    return null;
                }
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
            $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 58 batch routed to Rust or PHP, then restores the routing. */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Three words per `body_len\tlimit\toversized\thas_part[\terror]` row (`-` for a null body; no
     * fifth field for a null error): the outcome() code with the IS_SUCCESS, METADATA, EXCEEDS
     * (body longer than the limit) and TRUNCATED (oversized with a body) flags plus the error
     * class `<< W58_ARTICLE_ERROR_SHIFT`; then the body bytes (-1 without a body) and the
     * error's leading three-digit NNTP status (or 0). A malformed row yields
     * `[W58_ARTICLE_MALFORMED, -1, 0]`.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function articleSummaryBatch(array $rows): array
    {
        return self::usesW58RustFfi() ? self::articleSummaryBatchNative($rows) : self::articleSummaryBatchPhp($rows);
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function articleSummaryBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $fields = explode("\t", $row, 5);
            if (\count($fields) < 4
                || ($fields[0] !== '-' && preg_match('/\A[0-9]{1,18}\z/', $fields[0]) !== 1)
                || preg_match('/\A-?[0-9]{1,18}\z/', $fields[1]) !== 1
                || ($fields[2] !== '0' && $fields[2] !== '1')
                || ($fields[3] !== '0' && $fields[3] !== '1')) {
                array_push($words, self::W58_ARTICLE_MALFORMED, -1, 0);

                continue;
            }
            $hasBody = $fields[0] !== '-';
            $bytes = $hasBody ? (int) $fields[0] : -1;
            $error = $fields[4] ?? null;
            $oversized = $fields[2] === '1';
            $status = $error !== null && preg_match('/\A([0-9]{3})(?:[ \t]|\z)/', $error, $match) === 1 ? (int) $match[1] : 0;
            // Mirrors outcome(), isSuccess(), hasMetadata() and exceedsByteLimit().
            $success = $hasBody && $error === null;
            $words[] = ($success ? self::W58_ARTICLE_SUCCESS : ($oversized ? self::W58_ARTICLE_OVERSIZED : self::W58_ARTICLE_FAILURE))
                | ($success ? self::W58_ARTICLE_IS_SUCCESS : 0)
                | ($fields[3] === '1' ? self::W58_ARTICLE_METADATA : 0)
                | ($hasBody && $bytes > (int) $fields[1] ? self::W58_ARTICLE_EXCEEDS : 0)
                | ($oversized && $hasBody ? self::W58_ARTICLE_TRUNCATED : 0)
                | self::w58ErrorClass($error, $status) << self::W58_ARTICLE_ERROR_SHIFT;
            $words[] = $bytes;
            $words[] = $status;
        }

        return $words;
    }

    private static function w58ErrorClass(?string $error, int $status): int
    {
        return match (true) {
            $error === null => self::W58_ERROR_NONE,
            $error === '' => self::W58_ERROR_EMPTY,
            \in_array($status, [420, 423, 430], true) => self::W58_ERROR_MISSING,
            \in_array($status, [480, 481, 482], true) => self::W58_ERROR_AUTH,
            stripos($error, 'timed out') !== false || stripos($error, 'timeout') !== false => self::W58_ERROR_TIMEOUT,
            stripos($error, 'no such article') !== false => self::W58_ERROR_MISSING,
            stripos($error, 'connection') !== false || stripos($error, 'socket') !== false || stripos($error, 'eof') !== false => self::W58_ERROR_CONNECTION,
            stripos($error, 'too large') !== false || stripos($error, 'exceed') !== false || stripos($error, 'limit') !== false => self::W58_ERROR_OVERSIZED,
            stripos($error, 'yenc') !== false || stripos($error, 'crc') !== false || stripos($error, 'decode') !== false => self::W58_ERROR_DECODE,
            stripos($error, 'auth') !== false => self::W58_ERROR_AUTH,
            default => self::W58_ERROR_OTHER,
        };
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function articleSummaryBatchNative(array $rows): array
    {
        $count = \count($rows);
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w58RustFfiState('out', $count * self::W58_ARTICLE_WORDS);
        if ($buffer === null) {
            return self::articleSummaryBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w581_bounded_article_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        $words = $written === $count ? unpack('q*', FFI::string($out, $count * self::W58_ARTICLE_WORDS * 8)) : false;
        if (! \is_array($words)) {
            return self::articleSummaryBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($words);
    }

    /**
     * Wave 58 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('bounded_article_w58', self::W58_BOUNDED_ARTICLE_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    private function __construct(
        public ?string $body,
        public ?YencPart $part,
        public ?string $error,
        public bool $oversized,
    ) {}

    public static function success(string $body, ?YencPart $part = null): self
    {
        return new self($body, $part, null, false);
    }

    public static function failure(string $error, bool $oversized = false): self
    {
        return new self(null, null, $error, $oversized);
    }

    public function outcome(): string
    {
        return self::useRustFfi() ? ($this->outcomeNative() ?? $this->outcomePhp()) : $this->outcomePhp();
    }

    public function outcomePhp(): string
    {
        return $this->body !== null && $this->error === null ? 'success' : ($this->oversized ? 'oversized' : 'failure');
    }

    public function outcomeNative(): ?string
    {
        $value = self::computeNative(10, [$this->body !== null, $this->error, $this->oversized])['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    public static function exceedsByteLimit(string $body, int $limit): bool
    {
        return self::useRustFfi() ? (self::exceedsByteLimitNative($body, $limit) ?? self::exceedsByteLimitPhp($body, $limit)) : self::exceedsByteLimitPhp($body, $limit);
    }

    public static function exceedsByteLimitPhp(string $body, int $limit): bool
    {
        return strlen($body) > $limit;
    }

    public static function exceedsByteLimitNative(string $body, int $limit): ?bool
    {
        $value = self::computeNative(11, [strlen($body), $limit])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function isSuccess(): bool
    {
        return self::useRustFfi() ? ($this->isSuccessNative() ?? $this->isSuccessPhp()) : $this->isSuccessPhp();
    }

    public function isSuccessPhp(): bool
    {
        return $this->body !== null && $this->error === null;
    }

    public function isSuccessNative(): ?bool
    {
        $value = self::computeNative(12, [$this->body !== null, $this->error])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function hasMetadata(): bool
    {
        return self::useRustFfi() ? ($this->hasMetadataNative() ?? $this->hasMetadataPhp()) : $this->hasMetadataPhp();
    }

    public function hasMetadataPhp(): bool
    {
        return $this->part !== null;
    }

    public function hasMetadataNative(): ?bool
    {
        $value = self::computeNative(13, [$this->part !== null])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }
}
