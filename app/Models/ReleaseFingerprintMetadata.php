<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $releases_id
 * @property string $status
 * @property array<string, mixed>|null $metadata
 * @property string|null $last_error
 * @property Carbon|null $collected_at
 * @property-read Release $release
 */
final class ReleaseFingerprintMetadata extends Model
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

    protected $primaryKey = 'releases_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var array<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return self::castPayload();
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /** @return array<string, string> */
    public static function castPayload(): array
    {
        return (self::$useRustFfi ? self::castPayloadNative() : null) ?? self::castPayloadPhp();
    }

    /** @return array<string, string> */
    public static function castPayloadPhp(): array
    {
        return ['metadata' => 'array', 'collected_at' => 'datetime'];
    }

    /** @return array<string, string>|null */
    public static function castPayloadNative(): ?array
    {
        $result = ReleaseNfo::computeNative(1, []);

        return is_array($result) ? $result : null;
    }
}
