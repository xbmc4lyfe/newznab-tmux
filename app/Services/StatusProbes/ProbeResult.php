<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ProbeResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $responseTimeMs,
        public readonly ?IncidentImpactEnum $impact,
        public readonly string $reason,
        public readonly array $metadata = [],
    ) {}

    public const string FFI_CDEF = 'ssize_t nntmux_w346_configuration_status(uint8_t operation, const int64_t *input, size_t input_count, int64_t *output, size_t output_capacity);';

    // These small computations retain PHP until caller-inclusive benchmarks qualify Rust.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * @param  list<int>  $input
     * @return list<int>|null
     */
    private static function rustValues(int $operation, array $input, int $count): ?array
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('w346', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $length = count($input);
            $source = $ffi->new("int64_t[{$length}]");
            $output = $ffi->new("int64_t[{$count}]");
            foreach ($input as $index => $value) {
                $source[$index] = $value;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w346_configuration_status($operation, $source, $length, $output, $count);
            if ($written !== $count) {
                return null;
            }
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                $values[] = (int) $output[$index];
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{ok: bool, response_time_ms: int, impact: string|null, reason: string, metadata: array<string, mixed>} */
    public function summary(): array
    {
        if (self::usesRustFfi()) {
            $impactCode = $this->impact === null ? -1 : array_search($this->impact, IncidentImpactEnum::cases(), true);
            if ($impactCode !== false && ($values = self::rustValues(5, [(int) $this->ok, $this->responseTimeMs, $impactCode], 3)) !== null) {
                $impacts = [null, 'none', 'minor', 'major', 'critical'];
                if (array_key_exists($values[2] + 1, $impacts)) {
                    return ['ok' => (bool) $values[0], 'response_time_ms' => $values[1], 'impact' => $impacts[$values[2] + 1], 'reason' => $this->reason, 'metadata' => $this->metadata];
                }
            }
        }

        return $this->summaryPhp();
    }

    /** @return array{ok: bool, response_time_ms: int, impact: string|null, reason: string, metadata: array<string, mixed>} */
    public function summaryPhp(): array
    {
        return ['ok' => $this->ok, 'response_time_ms' => max(0, $this->responseTimeMs), 'impact' => $this->impact?->value, 'reason' => $this->reason, 'metadata' => $this->metadata];
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W50 parity test). */
    private const W50_RUST_FFI_DEFAULT = true;

    private const string W50_FFI_CDEF = 'ssize_t nntmux_w501_probe_result_summarize_batch(const char *oks, size_t oks_len, const char *response_ms, size_t response_ms_len, const char *impacts, size_t impacts_len, const char *nulls, size_t nulls_len, const char *reasons, size_t reasons_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW50RustFfi(): bool
    {
        $enabled = self::w50RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W50_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 50 batch routed to Rust or PHP, then restores the routing. */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('set', $previous);
        }
    }

    /**
     * This result's Wave 50 word: `ok | impact << 1 | flag << 4 | min(2047, strlen(reason)) << 5
     * | responseTimeMs << 16` (impact codes: null 0, none 1, minor 2, major 3, critical 4).
     */
    public function w50Word(bool $flag = false): int
    {
        $impact = match ($this->impact) {
            null => 0,
            IncidentImpactEnum::None => 1,
            IncidentImpactEnum::Minor => 2,
            IncidentImpactEnum::Major => 3,
            IncidentImpactEnum::Critical => 4,
        };

        return (int) $this->ok | $impact << 1 | (int) $flag << 4 | min(2047, \strlen($this->reason)) << 5 | $this->responseTimeMs << 16;
    }

    /**
     * Batch status-page summaries of result columns. One word per result: `ok | impact << 1 |
     * truncated << 4 | min(2047, strlen(Str::limit(reason, 120))) << 5 | clamp(responseTimeMs,
     * 0, 2^47 - 1) << 16` (impact codes: null 0, none 1, minor 2, major 3, critical 4, any
     * other value 7), plus a trailing `failing | worstImpact << 32` word (worst of codes 0-4).
     *
     * @param  list<bool>  $ok
     * @param  list<int>  $responseTimeMs
     * @param  list<string|null>  $impacts  IncidentImpactEnum values; null means no impact.
     * @param  list<string>  $reasons
     * @return list<int>
     */
    public static function summarizeBatch(array $ok, array $responseTimeMs, array $impacts, array $reasons): array
    {
        return self::usesW50RustFfi()
            ? self::summarizeBatchNative($ok, $responseTimeMs, $impacts, $reasons)
            : self::summarizeBatchPhp($ok, $responseTimeMs, $impacts, $reasons);
    }

    /**
     * @param  list<bool>  $ok
     * @param  list<int>  $responseTimeMs
     * @param  list<string|null>  $impacts
     * @param  list<string>  $reasons
     * @return list<int>
     */
    public static function summarizeBatchPhp(array $ok, array $responseTimeMs, array $impacts, array $reasons): array
    {
        self::w50Count($ok, $responseTimeMs, $impacts, $reasons);
        $words = [];
        $failing = $worst = 0;
        foreach ($ok as $i => $passed) {
            $impact = self::w50ImpactCode($impacts[$i]);
            $failing += (int) ! $passed;
            if ($impact <= 4) {
                $worst = max($worst, $impact);
            }
            $words[] = self::w50SummaryWord($passed, $responseTimeMs[$i], $impact, $reasons[$i]);
        }
        $words[] = $failing | $worst << 32;

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a string holds a NUL byte,
     * and computes rows with non-ASCII reasons (multibyte `Str::limit()` widths) in PHP.
     *
     * @param  list<bool>  $ok
     * @param  list<int>  $responseTimeMs
     * @param  list<string|null>  $impacts
     * @param  list<string>  $reasons
     * @return list<int>
     */
    public static function summarizeBatchNative(array $ok, array $responseTimeMs, array $impacts, array $reasons): array
    {
        $count = self::w50Count($ok, $responseTimeMs, $impacts, $reasons);
        $ffi = $count === 0 ? null : self::w50Ffi();
        if ($ffi === null) {
            return self::summarizeBatchPhp($ok, $responseTimeMs, $impacts, $reasons);
        }
        $flags = pack('C*', ...$ok);
        $times = pack('P*', ...$responseTimeMs);
        $codes = implode("\0", $impacts);
        $nulls = pack('V*', ...array_keys($impacts, null, true));
        $packed = implode("\0", $reasons);
        try {
            $words = self::w50Words($count + 1, static fn (CData $out, int $cap): mixed => $ffi->nntmux_w501_probe_result_summarize_batch( // @phpstan-ignore method.notFound
                $flags, \strlen($flags), $times, \strlen($times), $codes, \strlen($codes), $nulls, \strlen($nulls), $packed, \strlen($packed), $count, $out, $cap));
        } catch (Throwable) {
            $words = null;
        }
        if ($words === null) {
            return self::summarizeBatchPhp($ok, $responseTimeMs, $impacts, $reasons);
        }
        if (\in_array(PHP_INT_MIN, $words, true)) {
            foreach (array_keys($words, PHP_INT_MIN, true) as $i) {
                $words[$i] = self::w50SummaryWord($ok[$i], $responseTimeMs[$i], self::w50ImpactCode($impacts[$i]), $reasons[$i]);
            }
        }

        return $words;
    }

    private static function w50ImpactCode(?string $impact): int
    {
        return match ($impact) {
            null => 0,
            'none' => 1,
            'minor' => 2,
            'major' => 3,
            'critical' => 4,
            default => 7,
        };
    }

    private static function w50SummaryWord(bool $ok, int $responseTimeMs, int $impact, string $reason): int
    {
        return (int) $ok
            | $impact << 1
            | (int) (mb_strwidth($reason, 'UTF-8') > 120) << 4
            | min(2047, \strlen(Str::limit($reason, 120))) << 5
            | max(0, min($responseTimeMs, 0x7FFF_FFFF_FFFF)) << 16;
    }

    /**
     * @param  array<mixed>  ...$columns
     */
    private static function w50Count(array ...$columns): int
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count) {
                throw new InvalidArgumentException('Status probe batch columns must have the same length.');
            }
        }

        return $count;
    }

    private static function w50Ffi(): ?FFI
    {
        $ffi = self::w50RustFfiState('ffi');

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * Wave 50 routing override (`set`), lazily resolved binding (`ffi`) and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('status_probes_w50', self::W50_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$out, $outCap];
            default:
                return $enabled;
        }
    }

    /**
     * Copies `$words` output words out of the reusable buffer after `$call` filled it.
     *
     * @param  callable(CData, int): mixed  $call  Receives the buffer and its capacity in words.
     * @return list<int>|null
     */
    private static function w50Words(int $words, callable $call): ?array
    {
        $buffer = self::w50RustFfiState('out', $words);
        if (! \is_array($buffer) || ! $buffer[0] instanceof CData || $call($buffer[0], $buffer[1]) !== $words) {
            return null;
        }
        $decoded = unpack('P*', FFI::string($buffer[0], $words * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Enabled: the paired benchmark in the W56 parity test measures 4.587x common mean, 4.465x
     * common p99, 2.638x uncommon mean and 2.484x uncommon p99 (per-metric minimum of three runs),
     * above the 1.5x rule.
     */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = 'ssize_t nntmux_w561_probe_detail_code_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W56_DETAIL_HEALTHY = 0;

    public const int W56_DETAIL_DEGRADED = 1;

    public const int W56_DETAIL_UNHEALTHY = 2;

    public const int W56_DETAIL_LONG = 4;

    public const int W56_DETAIL_NON_ASCII = 8;

    public const int W56_DETAIL_LENGTH_SHIFT = 8;

    /** Reason words (matched ASCII case-insensitively) that classify a detail as unhealthy. */
    public const array W56_UNHEALTHY_WORDS = ['unreachable', 'refused', 'failed', 'error', 'timed out', 'timeout'];

    /** Reason words that classify a detail as degraded when no unhealthy word matches. */
    public const array W56_DEGRADED_WORDS = ['slow', 'degraded', 'lagging', 'behind', 'warning', 'skipping'];

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 56 batch routed to Rust or PHP, then restores the routing. */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /**
     * One word per probe reason, over its detail `preg_replace('/\s+/', ' ', trim($reason))`:
     * the `W56_DETAIL_HEALTHY` / `_DEGRADED` / `_UNHEALTHY` class from the reason words, then
     * `W56_DETAIL_LONG` (over 120 bytes) and `W56_DETAIL_NON_ASCII` flags and the detail length
     * `<< W56_DETAIL_LENGTH_SHIFT`.
     *
     * @param  array<array-key, string>  $reasons
     * @return list<int>
     */
    public static function detailCodes(array $reasons): array
    {
        return self::usesW56RustFfi() ? self::detailCodesNative($reasons) : self::detailCodesPhp($reasons);
    }

    /**
     * @param  array<array-key, string>  $reasons
     * @return list<int>
     */
    public static function detailCodesPhp(array $reasons): array
    {
        $codes = [];
        foreach ($reasons as $reason) {
            $detail = (string) preg_replace('/\s+/', ' ', trim($reason));
            $lower = strtolower($detail);
            $class = self::W56_DETAIL_HEALTHY;
            foreach (self::W56_UNHEALTHY_WORDS as $word) {
                if (str_contains($lower, $word)) {
                    $class = self::W56_DETAIL_UNHEALTHY;

                    break;
                }
            }
            if ($class === self::W56_DETAIL_HEALTHY) {
                foreach (self::W56_DEGRADED_WORDS as $word) {
                    if (str_contains($lower, $word)) {
                        $class = self::W56_DETAIL_DEGRADED;

                        break;
                    }
                }
            }
            $length = \strlen($detail);
            $codes[] = $class
                | ($length > 120 ? self::W56_DETAIL_LONG : 0)
                | (preg_match('/[\x80-\xff]/', $detail) === 1 ? self::W56_DETAIL_NON_ASCII : 0)
                | $length << self::W56_DETAIL_LENGTH_SHIFT;
        }

        return $codes;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a row
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $reasons
     * @return list<int>
     */
    public static function detailCodesNative(array $reasons): array
    {
        $count = \count($reasons);
        $words = $count;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w56RustFfiState('out', $words);
        if ($buffer === null) {
            return self::detailCodesPhp($reasons);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $reasons);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w561_probe_detail_code_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::detailCodesPhp($reasons);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 56 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('probe_result_w56', self::W56_FFI_CDEF) ?? false;
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
}
