<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * App\Models\DnzbFailure.
 *
 * @property int $release_id
 * @property int $users_id
 * @property int $failed
 * @property-read Release $release
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DnzbFailure whereFailed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DnzbFailure whereReleaseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DnzbFailure whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DnzbFailure newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DnzbFailure newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DnzbFailure query()
 */
class DnzbFailure extends Model
{
    use HasCompositePrimaryKey;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_wave21_dnzb_failed_count(uint32_t mode, const char *input, size_t len);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;

            return self::$ffi === false ? null : self::$ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    public static function failedCountValue(mixed $value): mixed
    {
        $native = self::$useRustFfi ? self::failedCountValueNative($value) : null;

        return $native !== null ? $native['value'] : self::failedCountValuePhp($value);
    }

    public static function failedCountValuePhp(mixed $value): mixed
    {
        return ! empty($value) ? $value : false;
    }

    /**
     * @return array{value: mixed}|null
     */
    public static function failedCountValueNative(mixed $value): ?array
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        try {
            $ffi = self::ffi();
            if ($ffi === null) {
                return null;
            }

            $mode = is_int($value) ? 0 : 1;
            $input = (string) $value;
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_wave21_dnzb_failed_count($mode, $input, strlen($input));

            return match ($result) {
                0 => ['value' => false],
                1 => ['value' => $value],
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @var string
     */
    protected $dateFormat = false;

    /**
     * @var string
     */
    protected $primaryKey = 'release_id';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['release_id', 'users_id'];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * Read failed downloads count for requested release_id.
     *
     *
     * @return bool|mixed
     */
    public static function getFailedCount(mixed $relId)
    {
        $result = self::query()->where('release_id', $relId)->value('failed');

        return self::failedCountValue($result);
    }

    public static function getCount(): int
    {
        return self::query()->count('release_id');
    }
}
