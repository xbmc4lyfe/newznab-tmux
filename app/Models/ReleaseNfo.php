<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * App\Models\ReleaseNfo.
 *
 * @property int $releases_id FK to releases.id
 * @property mixed|null $nfo
 * @property-read Release $release
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNfo whereNfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNfo whereReleasesId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNfo query()
 */
class ReleaseNfo extends Model
{
    // Paired common/uncommon mean and p99 must all reach 1.5x before adoption.
    private static bool $useRustFfi = false;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    protected $primaryKey = 'releases_id';

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /**
     * @return Model|null|static
     */
    public static function getReleaseNfo(mixed $id, bool $getNfoString = true)
    {
        $projection = self::queryProjection($getNfoString);
        $nfo = self::query()->where('releases_id', $id)->whereNotNull('nfo')->select($projection['columns']);
        if ($projection['raw'] !== null) {
            $nfo->selectRaw($projection['raw']);
        }

        return $nfo->first();
    }

    /** @return array{columns: list<string>, raw: ?string} */
    public static function queryProjection(bool $getNfoString): array
    {
        return (self::$useRustFfi ? self::queryProjectionNative($getNfoString) : null) ?? self::queryProjectionPhp($getNfoString);
    }

    /** @return array{columns: list<string>, raw: ?string} */
    public static function queryProjectionPhp(bool $getNfoString): array
    {
        return ['columns' => ['releases_id'], 'raw' => $getNfoString ? 'UNCOMPRESS(nfo) AS nfo' : null];
    }

    /** @return array{columns: list<string>, raw: ?string}|null */
    public static function queryProjectionNative(bool $getNfoString): ?array
    {
        $result = self::computeNative(0, [$getNfoString]);

        return is_array($result) && isset($result['columns']) && is_array($result['columns'])
            && array_key_exists('raw', $result) && ($result['raw'] === null || is_string($result['raw'])) ? $result : null;
    }

    public const string FFI_CDEF = 'intptr_t nntmux_w21_nfo_fingerprint_failures_queue(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    /**
     * Shared bounded bridge for these five scalar-only helpers. Objects, resources,
     * arrays and references are declined before serialization can invoke user code.
     *
     * @param  list<null|bool|string>  $arguments
     * @return array<array-key, mixed>|bool|null Null means unavailable/declined.
     */
    public static function computeNative(int $operation, array $arguments): array|bool|null
    {
        try {
            if (! array_is_list($arguments) || count($arguments) > 4) {
                return null;
            }
            $bytes = 0;
            foreach ($arguments as $key => $value) {
                if (\ReflectionReference::fromArrayElement($arguments, $key) !== null
                    || ($value !== null && ! is_bool($value) && ! is_string($value))) {
                    return null;
                }
                $bytes += is_string($value) ? strlen($value) : 0;
                if ($bytes > 16 * 1024 * 1024) {
                    return null;
                }
            }
            $input = serialize($arguments);
            $ffi = RustPipelineLibrary::resolveDomain('wave21-nfo-fingerprint-failures-queue', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(1024, strlen($input) + 256), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w21_nfo_fingerprint_failures_queue($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -(32 * 1024 * 1024)) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
                $written = $ffi->nntmux_w21_nfo_fingerprint_failures_queue($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 2 || $written > $capacity || $written > 32 * 1024 * 1024) {
                return null;
            }
            $result = unserialize(FFI::string($output, $written), ['allowed_classes' => false]);

            return is_array($result) || is_bool($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }
}
