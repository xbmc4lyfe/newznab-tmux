<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonInterface;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class RegistrationPeriod extends Model
{
    /** {@see windowState()} bit set when the period is active at the instant. */
    public const WINDOW_ACTIVE = 1;

    /** {@see windowState()} bit set when the period ended before the instant. */
    public const WINDOW_ENDED = 2;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_regperiod_window(int32_t enabled, int32_t has_start, int64_t starts_at, int32_t has_end, int64_t ends_at, int64_t at);
        C;

    /** Largest |epoch seconds| whose microsecond instant fits an int64. */
    private const MAX_EPOCH_SECONDS = 9_000_000_000_000;

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
        'is_enabled',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_enabled' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * @return Builder<self>
     */
    public function scopeActiveAt(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->enabled()
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at);
    }

    /**
     * @return Builder<self>
     */
    public function scopeUpcoming(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('starts_at', '>', $at);
    }

    public function isActiveAt(?CarbonInterface $at = null): bool
    {
        return self::usesRustFfi() ? $this->isActiveAtNative($at) : $this->isActiveAtPhp($at);
    }

    public function isActiveAtPhp(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_enabled
            && $this->starts_at !== null
            && $this->ends_at !== null
            && $this->starts_at->lte($at)
            && $this->ends_at->gte($at);
    }

    public function isActiveAtNative(?CarbonInterface $at = null): bool
    {
        $at ??= now();
        $state = $this->windowStateAt($at);

        return $state === null ? $this->isActiveAtPhp($at) : ($state & self::WINDOW_ACTIVE) !== 0;
    }

    public function hasEndedAt(?CarbonInterface $at = null): bool
    {
        return self::usesRustFfi() ? $this->hasEndedAtNative($at) : $this->hasEndedAtPhp($at);
    }

    public function hasEndedAtPhp(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->ends_at !== null && $this->ends_at->lt($at);
    }

    public function hasEndedAtNative(?CarbonInterface $at = null): bool
    {
        $at ??= now();
        $state = $this->windowStateAt($at);

        return $state === null ? $this->hasEndedAtPhp($at) : ($state & self::WINDOW_ENDED) !== 0;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    /**
     * Epoch microseconds of `$instant`, or null when it does not fit an int64.
     */
    public static function epochMicros(CarbonInterface $instant): ?int
    {
        $seconds = $instant->getTimestamp();
        if ($seconds > self::MAX_EPOCH_SECONDS || $seconds < -self::MAX_EPOCH_SECONDS) {
            return null;
        }

        return $seconds * 1_000_000 + (int) $instant->format('u');
    }

    /**
     * WINDOW_* bits for a period over epoch-microsecond instants: active when enabled and
     * `starts_at <= at <= ends_at`, ended when `ends_at < at`; a null bound never matches.
     */
    public static function windowState(bool $enabled, ?int $startsAt, ?int $endsAt, int $at): int
    {
        return self::usesRustFfi()
            ? self::windowStateNative($enabled, $startsAt, $endsAt, $at)
            : self::windowStatePhp($enabled, $startsAt, $endsAt, $at);
    }

    public static function windowStatePhp(bool $enabled, ?int $startsAt, ?int $endsAt, int $at): int
    {
        $active = $enabled && $startsAt !== null && $endsAt !== null && $startsAt <= $at && $endsAt >= $at;
        $ended = $endsAt !== null && $endsAt < $at;

        return ($active ? self::WINDOW_ACTIVE : 0) | ($ended ? self::WINDOW_ENDED : 0);
    }

    public static function windowStateNative(bool $enabled, ?int $startsAt, ?int $endsAt, int $at): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::windowStatePhp($enabled, $startsAt, $endsAt, $at);
        }
        try {
            // @phpstan-ignore method.notFound
            $state = $ffi->nntmux_regperiod_window($enabled ? 1 : 0, $startsAt === null ? 0 : 1, $startsAt ?? 0, $endsAt === null ? 0 : 1, $endsAt ?? 0, $at);
        } catch (Throwable) {
            return self::windowStatePhp($enabled, $startsAt, $endsAt, $at);
        }

        return \is_int($state) && $state >= 0 && $state <= 3 ? $state : self::windowStatePhp($enabled, $startsAt, $endsAt, $at);
    }

    /**
     * Rust window bits for this period at `$at`, or null when an instant cannot be represented.
     */
    private function windowStateAt(CarbonInterface $at): ?int
    {
        $atMicros = self::epochMicros($at);
        if ($atMicros === null) {
            return null;
        }
        $startsAt = null;
        if ($this->starts_at !== null && ($startsAt = self::epochMicros($this->starts_at)) === null) {
            return null;
        }
        $endsAt = null;
        if ($this->ends_at !== null && ($endsAt = self::epochMicros($this->ends_at)) === null) {
            return null;
        }

        return self::windowStateNative((bool) $this->is_enabled, $startsAt, $endsAt, $atMicros);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
