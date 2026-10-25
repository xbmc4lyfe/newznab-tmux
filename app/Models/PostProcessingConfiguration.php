<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsSingletonConfiguration;
use App\Support\RustPipelineLibrary;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * @property int $post_threads
 * @property int $post_threads_amazon
 * @property int $post_threads_non
 * @property int $nfo_threads
 * @property int $fix_name_threads
 * @property int $timeout_seconds
 * @property int $release_timeout_seconds
 * @property int $max_timeout_count
 * @property int $max_additional_processed
 * @property int $max_parts_processed
 * @property int $password_check_attempts
 * @property int $fix_names_per_run
 * @property int $max_nested_levels
 * @property bool $extract_using_rar_info
 * @property int $segments_to_download
 * @property int $ffmpeg_duration
 * @property string $inner_file_blacklist
 * @property bool $process_jpg
 * @property bool $process_thumbnails
 * @property bool $process_videos
 * @property bool $save_audio_preview
 * @property int $min_size_to_post_process
 * @property int $max_size_to_post_process
 * @property int $min_size_to_process_nfo
 * @property int $max_size_to_process_nfo
 * @property int $max_nfo_processed
 * @property int $max_nfo_retries
 * @property bool $lookup_nfo
 * @property bool $lookup_par2
 */
final class PostProcessingConfiguration extends Model
{
    use IsSingletonConfiguration;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'extract_using_rar_info' => 'boolean',
            'lookup_nfo' => 'boolean',
            'lookup_par2' => 'boolean',
            'process_jpg' => 'boolean',
            'process_thumbnails' => 'boolean',
            'process_videos' => 'boolean',
            'save_audio_preview' => 'boolean',
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

    /** @return array{threads: int, timeout_seconds: int, max_timeout_count: int} */
    public static function normalizeLimits(int $threads, int $timeoutSeconds, int $maxTimeoutCount): array
    {
        if (self::usesRustFfi() && ($values = self::rustValues(1, [$threads, $timeoutSeconds, $maxTimeoutCount], 3)) !== null) {
            return ['threads' => $values[0], 'timeout_seconds' => $values[1], 'max_timeout_count' => $values[2]];
        }

        return self::normalizeLimitsPhp($threads, $timeoutSeconds, $maxTimeoutCount);
    }

    /** @return array{threads: int, timeout_seconds: int, max_timeout_count: int} */
    public static function normalizeLimitsPhp(int $threads, int $timeoutSeconds, int $maxTimeoutCount): array
    {
        return ['threads' => max(1, $threads), 'timeout_seconds' => max(0, $timeoutSeconds), 'max_timeout_count' => max(0, $maxTimeoutCount)];
    }
}
