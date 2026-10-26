<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Models\ProcessingRuntimeState;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class ProcessingRuntimeStateRepository
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_prs_monitor_mask(const int64_t *lens, size_t count);
        int32_t nntmux_prs_defaults(int64_t *out, size_t out_count);
        C;

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private static ?CData $values = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
            self::$values = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
        self::$values = null;
    }

    public function isTmuxRunning(): bool
    {
        return $this->state()->tmux_running;
    }

    public function setTmuxRunning(bool $running): void
    {
        $this->update(['tmux_running' => $running]);
    }

    /** @phpstan-impure */
    public function stopRequested(): bool
    {
        return $this->state()->stop_requested;
    }

    public function requestStop(bool $requested = true): void
    {
        $this->update(['stop_requested' => $requested]);
    }

    /** @return array{monitor_path: string|null, monitor_path_a: string|null, monitor_path_b: string|null} */
    public function monitorPaths(): array
    {
        $state = $this->state();

        return self::projectMonitorPaths($state->monitor_path, $state->monitor_path_a, $state->monitor_path_b);
    }

    /** @return array{monitor_path: string|null, monitor_path_a: string|null, monitor_path_b: string|null} */
    public static function projectMonitorPaths(?string $monitorPath, ?string $monitorPathA, ?string $monitorPathB): array
    {
        return self::usesRustFfi()
            ? self::projectMonitorPathsNative($monitorPath, $monitorPathA, $monitorPathB)
            : self::projectMonitorPathsPhp($monitorPath, $monitorPathA, $monitorPathB);
    }

    /** @return array{monitor_path: string|null, monitor_path_a: string|null, monitor_path_b: string|null} */
    public static function projectMonitorPathsPhp(?string $monitorPath, ?string $monitorPathA, ?string $monitorPathB): array
    {
        return [
            'monitor_path' => $monitorPath,
            'monitor_path_a' => $monitorPathA,
            'monitor_path_b' => $monitorPathB,
        ];
    }

    /** @return array{monitor_path: string|null, monitor_path_a: string|null, monitor_path_b: string|null} */
    public static function projectMonitorPathsNative(?string $monitorPath, ?string $monitorPathA, ?string $monitorPathB): array
    {
        if (($ffi = self::ffi()) === null || ($values = self::valuesBuffer($ffi)) === null) {
            return self::projectMonitorPathsPhp($monitorPath, $monitorPathA, $monitorPathB);
        }
        try {
            $values[0] = $monitorPath === null ? -1 : \strlen($monitorPath);
            $values[1] = $monitorPathA === null ? -1 : \strlen($monitorPathA);
            $values[2] = $monitorPathB === null ? -1 : \strlen($monitorPathB);
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_prs_monitor_mask($values, 3);
        } catch (Throwable) {
            return self::projectMonitorPathsPhp($monitorPath, $monitorPathA, $monitorPathB);
        }
        if (! \is_int($mask) || $mask < 0) {
            return self::projectMonitorPathsPhp($monitorPath, $monitorPathA, $monitorPathB);
        }

        return [
            'monitor_path' => ($mask & 1) !== 0 ? $monitorPath : null,
            'monitor_path_a' => ($mask & 2) !== 0 ? $monitorPathA : null,
            'monitor_path_b' => ($mask & 4) !== 0 ? $monitorPathB : null,
        ];
    }

    /**
     * Attributes of the in-memory runtime state used before the table exists.
     *
     * @return array{id: int, tmux_running: bool, stop_requested: bool, last_binary_run_at: null, monitor_path: null, monitor_path_a: null, monitor_path_b: null}
     */
    public static function defaultStateAttributes(): array
    {
        return self::usesRustFfi() ? self::defaultStateAttributesNative() : self::defaultStateAttributesPhp();
    }

    /** @return array{id: int, tmux_running: bool, stop_requested: bool, last_binary_run_at: null, monitor_path: null, monitor_path_a: null, monitor_path_b: null} */
    public static function defaultStateAttributesPhp(): array
    {
        return [
            'id' => ProcessingRuntimeState::SINGLETON_ID,
            'tmux_running' => false,
            'stop_requested' => false,
            'last_binary_run_at' => null,
            'monitor_path' => null,
            'monitor_path_a' => null,
            'monitor_path_b' => null,
        ];
    }

    /** @return array{id: int, tmux_running: bool, stop_requested: bool, last_binary_run_at: null, monitor_path: null, monitor_path_a: null, monitor_path_b: null} */
    public static function defaultStateAttributesNative(): array
    {
        if (($ffi = self::ffi()) === null || ($values = self::valuesBuffer($ffi)) === null) {
            return self::defaultStateAttributesPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_prs_defaults($values, 3);
            /** @var int $id */
            $id = $values[0];
            /** @var int $tmuxRunning */
            $tmuxRunning = $values[1];
            /** @var int $stopRequested */
            $stopRequested = $values[2];
        } catch (Throwable) {
            return self::defaultStateAttributesPhp();
        }
        if ($written !== 3) {
            return self::defaultStateAttributesPhp();
        }

        return [
            'id' => $id,
            'tmux_running' => $tmuxRunning !== 0,
            'stop_requested' => $stopRequested !== 0,
            'last_binary_run_at' => null,
            'monitor_path' => null,
            'monitor_path_a' => null,
            'monitor_path_b' => null,
        ];
    }

    /** @param  array{monitor_path?: string|null, monitor_path_a?: string|null, monitor_path_b?: string|null}  $paths */
    public function updateMonitorPaths(array $paths): void
    {
        $this->update($paths);
    }

    public function markBinaryRun(?CarbonImmutable $ranAt = null): void
    {
        $this->update(['last_binary_run_at' => $ranAt ?? now()->toImmutable()]);
    }

    public function state(): ProcessingRuntimeState
    {
        if (! Schema::hasTable('processing_runtime_states')) {
            return (new ProcessingRuntimeState)->forceFill(self::defaultStateAttributes());
        }

        return ProcessingRuntimeState::singleton();
    }

    /** @param  array<string, mixed>  $attributes */
    private function update(array $attributes): void
    {
        $this->ensureAvailable();
        $state = ProcessingRuntimeState::singleton();
        $state->fill($attributes);
        $state->save();
    }

    private function ensureAvailable(): void
    {
        if (! Schema::hasTable('processing_runtime_states')) {
            throw new RuntimeException('The processing runtime state table is not available. Run migrations before starting workers.');
        }
    }

    /** Reusable `int64_t[3]` value buffer for `$ffi`. */
    private static function valuesBuffer(FFI $ffi): ?CData
    {
        try {
            return self::$values ??= $ffi->new('int64_t[3]');
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Enabled: the paired benchmark in the W57 parity test measures 5.551x common mean, 5.39x
     * common p99, 4.859x uncommon mean and 2.781x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = 'ssize_t nntmux_w571_runtime_state_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W57_STATE_WORDS = 3;

    public const array W57_STATES = ['running', 'paused', 'draining', 'drained', 'stopped'];

    /** Allowed `next` states per `state`. */
    public const array W57_TRANSITIONS = [
        'running' => ['paused', 'draining'],
        'paused' => ['running', 'draining'],
        'draining' => ['running', 'drained'],
        'drained' => ['running', 'stopped'],
        'stopped' => ['running'],
    ];

    public const int W57_STATE_RUNNING = 1;

    public const int W57_STATE_STOP = 2;

    public const int W57_STATE_INVALID = 4;

    public const int W57_STATE_UNKNOWN = 8;

    public const int W57_STATE_SHIFT = 4;

    public const int W57_NEXT_SHIFT = 8;

    public const int W57_TRANSITION_SHIFT = 12;

    public const int W57_TRANSITION_NONE = 0;

    public const int W57_TRANSITION_ALLOWED = 1;

    public const int W57_TRANSITION_REJECTED = 2;

    public const int W57_TRANSITION_NOOP = 3;

    public const int W57_STATE_CONFLICT = 16384;

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 57 batch routed to Rust or PHP, then restores the routing. */
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
     * Three words per `;`-separated `key=value` runtime state record (key and value trimmed, the
     * last duplicate winning): W57_STATE_RUNNING/STOP from `tmux_running`/`stop_requested`
     * (1|true|on|yes or 0|false|off|no, any case), W57_STATE_INVALID for an unparseable value,
     * W57_STATE_UNKNOWN for another key or a segment without `=`, the W57_STATES index + 1 of
     * `state` and `next`, the W57_TRANSITIONS verdict and W57_STATE_CONFLICT when `tmux_running`
     * contradicts `state`; then the `pending` and `active` counters (-1 when absent or invalid).
     *
     * @param  array<array-key, string>  $records
     * @return list<int>
     */
    public static function runtimeStateBatch(array $records): array
    {
        return self::usesW57RustFfi() ? self::runtimeStateBatchNative($records) : self::runtimeStateBatchPhp($records);
    }

    /**
     * @param  array<array-key, string>  $records
     * @return list<int>
     */
    public static function runtimeStateBatchPhp(array $records): array
    {
        static $index = null;
        static $allowed = null;
        if ($index === null || $allowed === null) {
            $index = array_flip(self::W57_STATES);
            $allowed = [];
            foreach (self::W57_TRANSITIONS as $from => $targets) {
                foreach ($targets as $to) {
                    $allowed[$index[$from] + 1][$index[$to] + 1] = true;
                }
            }
        }
        $words = [];
        foreach ($records as $record) {
            $running = $stop = $invalid = $unknown = false;
            $state = $next = 0;
            $pending = $active = -1;
            foreach (explode(';', $record) as $segment) {
                if (trim($segment) === '') {
                    continue;
                }
                $eq = strpos($segment, '=');
                if ($eq === false) {
                    $unknown = true;

                    continue;
                }
                $key = trim(substr($segment, 0, $eq));
                $value = trim(substr($segment, $eq + 1));
                if ($key === 'tmux_running' || $key === 'stop_requested') {
                    $flag = match (strtolower($value)) {
                        '1', 'true', 'on', 'yes' => true,
                        '0', 'false', 'off', 'no' => false,
                        default => null,
                    };
                    if ($flag === null) {
                        $invalid = true;
                    } elseif ($key === 'tmux_running') {
                        $running = $flag;
                    } else {
                        $stop = $flag;
                    }
                } elseif ($key === 'state' || $key === 'next') {
                    $position = ($index[strtolower($value)] ?? -1) + 1;
                    $invalid = $invalid || $position === 0;
                    if ($key === 'state') {
                        $state = $position;
                    } else {
                        $next = $position;
                    }
                } elseif ($key === 'pending' || $key === 'active') {
                    $counter = \strlen($value) <= 18 && ctype_digit($value) ? (int) $value : -1;
                    $invalid = $invalid || $counter === -1;
                    if ($key === 'pending') {
                        $pending = $counter;
                    } else {
                        $active = $counter;
                    }
                } else {
                    $unknown = true;
                }
            }
            $transition = match (true) {
                $next === 0 => self::W57_TRANSITION_NONE,
                $state === $next => self::W57_TRANSITION_NOOP,
                isset($allowed[$state][$next]) => self::W57_TRANSITION_ALLOWED,
                default => self::W57_TRANSITION_REJECTED,
            };
            $conflict = $state !== 0 && ($running ? ($state === 4 || $state === 5) : ($state === 1 || $state === 3));
            $words[] = ($running ? self::W57_STATE_RUNNING : 0)
                | ($stop ? self::W57_STATE_STOP : 0)
                | ($invalid ? self::W57_STATE_INVALID : 0)
                | ($unknown ? self::W57_STATE_UNKNOWN : 0)
                | $state << self::W57_STATE_SHIFT
                | $next << self::W57_NEXT_SHIFT
                | $transition << self::W57_TRANSITION_SHIFT
                | ($conflict ? self::W57_STATE_CONFLICT : 0);
            $words[] = $pending;
            $words[] = $active;
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a record
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $records
     * @return list<int>
     */
    public static function runtimeStateBatchNative(array $records): array
    {
        $count = \count($records);
        $words = $count * self::W57_STATE_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w57RustFfiState('out', $words);
        if ($buffer === null) {
            return self::runtimeStateBatchPhp($records);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $records);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w571_runtime_state_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::runtimeStateBatchPhp($records);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 57 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('processing_runtime_state_w57', self::W57_FFI_CDEF) ?? false;
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

    // ---- Wave 67.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 12.616x/10.147x, uncommon 11.494x/8.919x); see RustRuntimeStateConsolesEpisodeHydrationFanartForkingW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_RUNTIME_STATE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w672_runtime_state_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W67_RUNTIME_STATE_TAIL = 25;

    /**
     * Wave 67 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w67', self::W67_RUNTIME_STATE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w67RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w67RuntimeStatePlanBatch() to Rust until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Routes w67RuntimeStatePlanBatch() to PHP until reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w67RuntimeStatePlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67RuntimeStatePlanBatch(array $rows): array
    {
        return self::usesW67RustFfi() ? self::w67RuntimeStatePlanBatchNative($rows) : self::w67RuntimeStatePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67RuntimeStatePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W67_RUNTIME_STATE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w67RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w67RuntimeStatePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W67_RUNTIME_STATE_FFI_CDEF.)
            $written = $ffi->nntmux_w672_runtime_state_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w67RuntimeStatePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w67Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w67Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** 0 empty or 0/false/off/no, 1 for 1/true/on/yes, 2 any other value (trimmed, any case). */
    private static function w67Flag(string $value): int
    {
        return match (strtolower(trim($value))) {
            '', '0', 'false', 'off', 'no' => 0,
            '1', 'true', 'on', 'yes' => 1,
            default => 2,
        };
    }

    /** Wave 57 state positions + 1. */
    private const array W67_RUNTIME_STATES = ['running' => 1, 'paused' => 2, 'draining' => 3, 'drained' => 4, 'stopped' => 5];

    /** The W57_TRANSITIONS table by state position. */
    private const array W67_RUNTIME_ALLOWED = [1 => [2 => true, 3 => true], 2 => [1 => true, 3 => true], 3 => [1 => true, 4 => true], 4 => [1 => true, 5 => true], 5 => [1 => true]];

    /** 0 empty, a W67_RUNTIME_STATES position (trimmed, any case), otherwise false. */
    private static function w67RuntimeState(string $value): int|false
    {
        $value = trim($value);

        return $value === '' ? 0 : (self::W67_RUNTIME_STATES[strtolower($value)] ?? false);
    }

    /**
     * Wave 67.2 runtime state plan. Per `state\trequested\ttmux_running\tstop_requested\tpending\t
     * active\tprevious` row `transition | state << 2 | requested << 5 | effective << 8 | gate << 11
     * | forced << 14 | promoted << 15 | conflict << 16 | changed << 17 | first << 18 | malformed
     * << 19 | running << 20 | stop << 21 | min(load, 2^20 - 1) << 22 | min(pending, 2^20 - 1) <<
     * 42`. States are w67RuntimeState() positions, flags w67Flag() values and counters w67Int()
     * fields of 9 digits; load is pending + active. An empty or unknown state, an unknown
     * requested or previous state, an unparsable flag or counter is malformed. The transition is
     * 0 none, 3 no-op, 1 allowed by W57_TRANSITIONS or 2 rejected. A well-formed row takes the
     * requested state when allowed; a stop request forces running or paused to draining
     * (forced), and draining without load is promoted to drained. The gate is 1 admit
     * (running), 2 hold (paused), 3 wait (draining), 4 idle (drained) or 5 stop (stopped, or
     * drained with a stop request). A conflict is a running tmux in drained/stopped or a stopped
     * tmux in running/draining; changed compares with a non-empty previous state and first marks
     * an empty one. Then four transition, six gate and six effective-state counts, malformed
     * rows, conflicts, changed, forced and promoted rows, total pending, total active, the
     * highest load (well-formed rows) and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67RuntimeStatePlanBatchPhp(array $rows): array
    {
        $out = [];
        $transitions = array_fill(0, 4, 0);
        $gates = array_fill(0, 6, 0);
        $effs = array_fill(0, 6, 0);
        $malformedRows = $conflicts = $changedRows = $forcedRows = $promotedRows = $totalPending = $totalActive = $maxLoad = 0;
        foreach ($rows as $row) {
            [$state, $requested, $running, $stop, $pending, $active, $previous] = explode("\t", (string) $row, 7) + ['', '', '', '', '', '', ''];
            $state = self::w67RuntimeState($state);
            $requested = self::w67RuntimeState($requested);
            $previous = self::w67RuntimeState($previous);
            $running = self::w67Flag($running);
            $stop = self::w67Flag($stop);
            $pending = self::w67Int($pending, 9);
            $active = self::w67Int($active, 9);
            $s = \is_int($state) ? $state : 0;
            $r = \is_int($requested) ? $requested : 0;
            $p = \is_int($previous) ? $previous : 0;
            $malformed = $s === 0 || $requested === false || $previous === false || $running === 2 || $stop === 2 || $pending === false || $active === false;
            $pend = \is_int($pending) ? $pending : 0;
            $act = \is_int($active) ? $active : 0;
            $load = $pend + $act;
            $transition = match (true) {
                $r === 0 => 0,
                $r === $s => 3,
                isset(self::W67_RUNTIME_ALLOWED[$s][$r]) => 1,
                default => 2,
            };
            $eff = $gate = 0;
            $forced = $promoted = $conflict = $changed = $first = false;
            if (! $malformed) {
                $eff = $transition === 1 ? $r : $s;
                if ($stop === 1 && ($eff === 1 || $eff === 2)) {
                    [$eff, $forced] = [3, true];
                }
                if ($eff === 3 && $load === 0) {
                    [$eff, $promoted] = [4, true];
                }
                $gate = match ($eff) {
                    1 => 1,
                    2 => 2,
                    3 => 3,
                    4 => $stop === 1 ? 5 : 4,
                    default => 5,
                };
                $conflict = $running === 1 ? ($eff === 4 || $eff === 5) : ($eff === 1 || $eff === 3);
                $first = $p === 0;
                $changed = $p !== 0 && $p !== $eff;
                $totalPending += $pend;
                $totalActive += $act;
                $maxLoad = max($maxLoad, $load);
            }
            $transitions[$transition]++;
            $gates[$gate]++;
            $effs[$eff]++;
            $malformedRows += (int) $malformed;
            $conflicts += (int) $conflict;
            $changedRows += (int) $changed;
            $forcedRows += (int) $forced;
            $promotedRows += (int) $promoted;
            $out[] = $transition | $s << 2 | $r << 5 | $eff << 8 | $gate << 11 | (int) $forced << 14 | (int) $promoted << 15 | (int) $conflict << 16
                | (int) $changed << 17 | (int) $first << 18 | (int) $malformed << 19 | (int) ($running === 1) << 20 | (int) ($stop === 1) << 21
                | min($load, 0xFFFFF) << 22 | min($pend, 0xFFFFF) << 42;
        }
        array_push($out, ...$transitions, ...$gates, ...$effs);
        array_push($out, $malformedRows, $conflicts, $changedRows, $forcedRows, $promotedRows, $totalPending, $totalActive, $maxLoad);
        $out[] = self::w67Digest($out);

        return $out;
    }
}
