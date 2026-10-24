<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class DownloadStat extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    protected $guarded = [];

    // Opt-in experiment: retain PHP unless every caller-inclusive performance gate qualifies.
    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public const string FFI_CDEF = 'int32_t nntmux_w26_stat_aggregation_models(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    /**
     * @internal Scalar-only transport shared by this slice; never serializes models or dates.
     *
     * @param  list<mixed>  $args
     */
    public static function transformNative(int $operation, array $args): mixed
    {
        $maximum = 67_108_864;
        try {
            if (! array_is_list($args)) {
                return null;
            }
            foreach ($args as $arg) {
                if ($arg !== null && ! is_scalar($arg)) {
                    return null;
                }
            }
            $input = serialize($args);
            if (strlen($input) > $maximum) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('w26-stat-aggregation-models', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, min($maximum, max(256, strlen($input) + 256)), $capacity);
            if ($scratch === null) {
                return null;
            }
            $capacity = min($capacity, $maximum);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w26_stat_aggregation_models($operation, $input, strlen($input), $scratch, $capacity);
            if (is_int($written) && $written < -1 && -$written > $capacity && -$written <= $maximum) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                $capacity = min($capacity, $maximum);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w26_stat_aggregation_models($operation, $input, strlen($input), $scratch, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $wire = FFI::string($scratch, $written);
            $result = @unserialize($wire, ['allowed_classes' => false]);

            return serialize($result) === $wire ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{searchname: mixed, guid: mixed, adddate: mixed, grabs: mixed} */
    public static function buildRow(mixed $searchname, mixed $guid, mixed $adddate, mixed $grabs): array
    {
        return self::$useRustFfi ? self::buildRowNative($searchname, $guid, $adddate, $grabs) : self::buildRowPhp($searchname, $guid, $adddate, $grabs);
    }

    /** @return array{searchname: mixed, guid: mixed, adddate: mixed, grabs: mixed} */
    public static function buildRowPhp(mixed $searchname, mixed $guid, mixed $adddate, mixed $grabs): array
    {
        return ['searchname' => $searchname, 'guid' => $guid, 'adddate' => $adddate, 'grabs' => $grabs];
    }

    /** @return array{searchname: mixed, guid: mixed, adddate: mixed, grabs: mixed} */
    public static function buildRowNative(mixed $searchname, mixed $guid, mixed $adddate, mixed $grabs): array
    {
        $result = DownloadStat::transformNative(0, [$searchname, $guid, $adddate, $grabs]);

        return is_array($result) && array_keys($result) === ['searchname', 'guid', 'adddate', 'grabs']
            ? $result : self::buildRowPhp($searchname, $guid, $adddate, $grabs);
    }

    public static function insertTopDownloads(): void
    {
        $releases = Release::query()
            ->where('grabs', '>', 0)
            ->select(['id', 'searchname', 'guid', 'adddate'])
            ->selectRaw('SUM(grabs) as grabs')
            ->groupBy('id', 'searchname', 'adddate')
            ->havingRaw('SUM(grabs) > 0')
            ->orderByDesc('grabs')
            ->limit(10)
            ->get();

        foreach ($releases as $release) {
            self::updateOrCreate(self::buildRow($release->searchname, $release->guid, $release->adddate, $release->grabs));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getTopDownloads(): array
    {
        return self::query()->select(['searchname', 'guid', 'adddate', 'grabs'])->get()->toArray();
    }
}
