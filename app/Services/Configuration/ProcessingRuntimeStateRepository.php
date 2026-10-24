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
}
