<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsSingletonConfiguration;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * @property bool $tmux_running
 * @property bool $stop_requested
 * @property CarbonImmutable|null $last_binary_run_at
 * @property string|null $monitor_path
 * @property string|null $monitor_path_a
 * @property string|null $monitor_path_b
 */
final class ProcessingRuntimeState extends Model
{
    use IsSingletonConfiguration;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tmux_running' => 'boolean',
            'stop_requested' => 'boolean',
            'last_binary_run_at' => 'immutable_datetime',
        ];
    }

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

    /**
     * Project a flag update without persistence. Null updates preserve existing operator pauses.
     *
     * @return array{tmux_running: bool, stop_requested: bool, accepting_work: bool}
     */
    public static function transitionFlags(bool $running, bool $stopRequested, ?bool $runningUpdate = null, ?bool $stopUpdate = null): array
    {
        if (self::usesRustFfi() && ($values = self::rustValues(3, [(int) $running, (int) $stopRequested, $runningUpdate === null ? -1 : (int) $runningUpdate, $stopUpdate === null ? -1 : (int) $stopUpdate], 3)) !== null) {
            return ['tmux_running' => (bool) $values[0], 'stop_requested' => (bool) $values[1], 'accepting_work' => (bool) $values[2]];
        }

        return self::transitionFlagsPhp($running, $stopRequested, $runningUpdate, $stopUpdate);
    }

    /** @return array{tmux_running: bool, stop_requested: bool, accepting_work: bool} */
    public static function transitionFlagsPhp(bool $running, bool $stopRequested, ?bool $runningUpdate = null, ?bool $stopUpdate = null): array
    {
        $running = $runningUpdate ?? $running;
        $stopRequested = $stopUpdate ?? $stopRequested;

        return ['tmux_running' => $running, 'stop_requested' => $stopRequested, 'accepting_work' => $running && ! $stopRequested];
    }
}
