<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class Prerequisites
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w19_verification_prereqs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
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
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -(64 * 1024 * 1024)) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
                $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 2 || $written > $capacity || $written > 64 * 1024 * 1024) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    public function check(bool $required, string $par2Binary): void
    {
        if (! $required) {
            return;
        }
        $available = self::isExplicitPath($par2Binary)
            ? is_file($par2Binary) && is_executable($par2Binary)
            : (new ExecutableFinder)->find($par2Binary) !== null;
        if (! $available) {
            throw new RuntimeException('Release verification requires an executable PAR2 binary. Install par2 or configure RELEASE_VERIFICATION_PAR2_BINARY.');
        }
    }

    public static function isExplicitPath(string $binary): bool
    {
        return self::$useRustFfi ? (self::isExplicitPathNative($binary) ?? self::isExplicitPathPhp($binary)) : self::isExplicitPathPhp($binary);
    }

    public static function isExplicitPathPhp(string $binary): bool
    {
        return str_contains($binary, '/') || str_contains($binary, '\\');
    }

    public static function isExplicitPathNative(string $binary): ?bool
    {
        $result = self::computeNative(20, [$binary])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    public const string W57_FFI_CDEF = 'int64_t nntmux_w575_readiness_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

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
     * Runs $callback with readinessPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
            $written = $ffi->nntmux_w575_readiness_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch readiness of comma-separated `key=value` prerequisite flags (items, keys and values trimmed, keys
     * ASCII case-insensitive, later pairs win). Four words per record: `[missing, satisfied, flags, ready]`.
     * Requirement bits: 1 `par2` (non-empty binary), 2 `nntp`, 4 `database`, 8 `storage`, 16 `queue`, 32
     * `scratch` (truthy `1`/`true`/`yes`/`on`); `required` (default true) gates readiness. Ready is 1 when
     * not required or nothing is missing. Flags: 1 explicit PAR2 path, 2 unknown key, 4 duplicate key, 8
     * item without `=`, 16 not required.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function readinessPlanBatch(array $records): array
    {
        return self::usesW57RustFfi() ? self::readinessPlanBatchNative($records) : self::readinessPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function readinessPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $have = $seen = $flags = 0;
            $required = true;
            $explicit = false;
            foreach (explode(',', $record) as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                $eq = strpos($item, '=');
                if ($eq === false) {
                    $flags |= 8;

                    continue;
                }
                $value = trim(substr($item, $eq + 1));
                $bit = match (strtolower(trim(substr($item, 0, $eq)))) {
                    'par2' => 1,
                    'nntp' => 2,
                    'database' => 4,
                    'storage' => 8,
                    'queue' => 16,
                    'scratch' => 32,
                    'required' => 64,
                    default => 0,
                };
                if ($bit === 0) {
                    $flags |= 2;

                    continue;
                }
                if (($seen & $bit) !== 0) {
                    $flags |= 4;
                }
                $seen |= $bit;
                if ($bit === 64) {
                    $required = \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);

                    continue;
                }
                if ($bit === 1) {
                    $explicit = self::isExplicitPathPhp($value);
                    $on = $value !== '';
                } else {
                    $on = \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
                }
                $have = $on ? $have | $bit : $have & ~$bit;
            }
            $missing = 63 & ~$have;
            $flags |= ($required ? 0 : 16) | ($explicit ? 1 : 0);
            array_push($out, $missing, substr_count(decbin($have), '1'), $flags, ! $required || $missing === 0 ? 1 : 0);
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
    public static function readinessPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w57Words($count, implode("\0", $records), 4 * $count) ?? self::readinessPlanBatchPhp($records);
    }
}
