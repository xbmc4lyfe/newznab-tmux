<?php

declare(strict_types=1);

/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:.
 *
 * @link      <http://www.gnu.org/licenses/>.
 *
 * @author    niel
 * @copyright 2016 nZEDb
 */

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * App\Models\ReleasesGroups.
 *
 * @property int $releases_id FK to releases.id
 * @property int $groups_id FK to groups.id
 * @property-read Release $release
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleasesGroups whereGroupsId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleasesGroups whereReleasesId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleasesGroups newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleasesGroups newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleasesGroups query()
 */
class ReleasesGroups extends Model
{
    use HasCompositePrimaryKey;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_releases_groups_normalize_mapping(
            int64_t release_id,
            int64_t group_id,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustCategoryGroupParhashModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int UNSIGNED_INT_MAX = 4294967295;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $primaryKey = 'releases_id';

    /**
     * @var bool
     */
    public $timestamps = false;

    /** @phpstan-ignore property.defaultValue */
    public $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['releases_id', 'groups_id'];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /**
     * @return BelongsTo<UsenetGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(UsenetGroup::class, 'groups_id');
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a `releases_groups` mapping: clamps both ids to the unsigned INT columns and
     * builds the `releases_id:groups_id` composite key.
     *
     * @return array{releases_id: int, groups_id: int, composite_key: string, is_valid: bool}
     */
    public static function normalizeCompositeMapping(int $releaseId, int $groupId): array
    {
        return self::usesRustFfi()
            ? self::normalizeCompositeMappingNative($releaseId, $groupId)
            : self::normalizeCompositeMappingPhp($releaseId, $groupId);
    }

    /**
     * @return array{releases_id: int, groups_id: int, composite_key: string, is_valid: bool}
     */
    public static function normalizeCompositeMappingNative(int $releaseId, int $groupId): array
    {
        $fields = self::callNative('nntmux_releases_groups_normalize_mapping', [$releaseId, $groupId]);
        if ($fields === null || count($fields) !== 4 || in_array(null, $fields, true)) {
            return self::normalizeCompositeMappingPhp($releaseId, $groupId);
        }

        return [
            'releases_id' => (int) $fields[0],
            'groups_id' => (int) $fields[1],
            'composite_key' => (string) $fields[2],
            'is_valid' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{releases_id: int, groups_id: int, composite_key: string, is_valid: bool}
     */
    public static function normalizeCompositeMappingPhp(int $releaseId, int $groupId): array
    {
        $release = max(0, min($releaseId, self::UNSIGNED_INT_MAX));
        $group = max(0, min($groupId, self::UNSIGNED_INT_MAX));

        return [
            'releases_id' => $release,
            'groups_id' => $group,
            'composite_key' => $release.':'.$group,
            'is_valid' => $releaseId >= 1 && $releaseId <= self::UNSIGNED_INT_MAX
                && $groupId >= 1 && $groupId <= self::UNSIGNED_INT_MAX,
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
