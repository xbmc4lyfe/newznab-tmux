<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Support\ReleaseNameDisplay;
use App\Support\RustPipelineLibrary;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class RetryArchiveInspection extends Command
{
    public const string W386_FFI_CDEF = 'ssize_t nntmux_w386_tmux_drain_stop_runners(uint8_t operation, uint8_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    // Paired caller benchmarks must qualify before enabling this separate candidate.
    private static ?bool $useRustW386 = null;

    public static function setUseRustW386(?bool $enabled): void
    {
        self::$useRustW386 = $enabled;
    }

    public static function usesRustW386(): bool
    {
        return self::$useRustW386 ?? false;
    }

    private static function w386Transform(int $flags, string $input): ?string
    {
        $length = strlen($input);
        if ($length > 16_777_216) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('additional_w386', self::W386_FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w386_tmux_drain_stop_runners(3, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            if (is_int($written) && $written < -1 && -$written <= 16_777_216) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w386_tmux_drain_stop_runners(3, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            }
            if (! is_int($written) || $written < 0 || $written > min($capacity, 16_777_216)) {
                return null;
            }

            return $written === 0 ? '' : \FFI::string($scratch, $written);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Remaining bounded retry chunk; a nonpositive limit is unlimited. */
    public static function retryChunkPlan(int $limit, int $queued): int
    {
        return (self::usesRustW386() ? self::retryChunkPlanNative($limit, $queued) : null)
            ?? self::retryChunkPlanPhp($limit, $queued);
    }

    public static function retryChunkPlanPhp(int $limit, int $queued): int
    {
        $queued = max(0, $queued);

        return $limit <= 0 ? self::CHUNK_SIZE : ($queued >= $limit ? 0 : min(self::CHUNK_SIZE, $limit - $queued));
    }

    public static function retryChunkPlanNative(int $limit, int $queued): ?int
    {
        $output = self::w386Transform(0, pack('q2', $limit, $queued));
        if ($output === null || strlen($output) !== 8) {
            return null;
        }
        /** @var array{value: int} $decoded */
        $decoded = unpack('qvalue', $output);

        return $decoded['value'];
    }

    public bool $useRustFfi = false;

    private const int CHUNK_SIZE = 1000;

    protected $signature = 'releases:retry-archive-inspection
                            {--limit=0 : Max releases to queue (0 = no limit)}
                            {--dry-run : Count the affected releases by archive type without queueing them}';

    protected $description = 'Queue releases whose archives could not be read (passwordstatus -1, haspreview 0) for one more post-processing pass.';

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));

        if ($this->option('dry-run')) {
            $counts = ['7z' => 0, 'rar' => 0, 'zip' => 0, 'unknown' => 0];
            $this->eligible()->select(['id', 'name'])->lazyById(self::CHUNK_SIZE)->each(
                function (Release $release) use (&$counts, $limit): bool {
                    $counts[$this->archiveType((string) $release->name)]++;

                    return $limit === 0 || array_sum($counts) < $limit;
                },
            );

            $this->table(['Archive type', 'Releases'], array_map(
                static fn (string $type, int $count): array => [$type, $count],
                array_keys($counts),
                $counts,
            ));
            $this->info(sprintf('Dry run: %d release(s) would be queued.', array_sum($counts)));

            return self::SUCCESS;
        }

        $queued = 0;
        do {
            $chunk = $limit > 0 ? min(self::CHUNK_SIZE, $limit - $queued) : self::CHUNK_SIZE;
            $ids = $this->eligible()->orderBy('id')->limit($chunk)->pluck('id')->all();
            if ($ids === []) {
                break;
            }

            $queued += $this->eligible()->whereIn('id', $ids)->update([
                'haspreview' => -1,
                AdditionalCandidateQuery::ARCHIVE_RETRY_COLUMN => now(),
            ]);
        } while ($limit === 0 || $queued < $limit);

        $this->info(sprintf('Queued %d release(s) for one more archive inspection.', $queued));

        return self::SUCCESS;
    }

    /**
     * Releases the worker's candidate query would pick up again once queued:
     * an enabled category and a size within the post-processing bounds.
     *
     * @return Builder<Release>
     */
    private function eligible(): Builder
    {
        $query = Release::query()
            ->where('passwordstatus', -1)
            ->where('haspreview', 0)
            ->where('nzbstatus', 1)
            ->whereNull(AdditionalCandidateQuery::ARCHIVE_RETRY_COLUMN)
            ->whereIn('categories_id', static fn (QueryBuilder $categories) => $categories
                ->select('id')
                ->from('categories')
                ->where('disablepreview', 0));

        $min = AdditionalCandidateQuery::minSizeBytes();
        $max = AdditionalCandidateQuery::maxSizeBytes();
        if ($min > 0) {
            $query->where('size', '>', $min);
        }
        if ($max > 0) {
            $query->where('size', '<', $max);
        }

        return $query;
    }

    private function archiveType(string $name): string
    {
        return $this->useRustFfi ? $this->archiveTypeNative($name) : $this->archiveTypePhp($name);
    }

    public function archiveTypeNative(string $name): string
    {
        return ReleaseNameDisplay::transformNative(4, $name) ?? $this->archiveTypePhp($name);
    }

    public function archiveTypePhp(string $name): string
    {
        return match (true) {
            preg_match('/\.7z(\.\d{3})?\b/i', $name) === 1 => '7z',
            preg_match('/\.(rar|r\d{2,3}|part\d+)\b/i', $name) === 1 => 'rar',
            preg_match('/\.zipx?\b/i', $name) === 1 => 'zip',
            default => 'unknown',
        };
    }
}
