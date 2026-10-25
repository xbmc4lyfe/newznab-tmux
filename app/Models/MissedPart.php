<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\MissedPart.
 *
 * @property int $id
 * @property int $numberid
 * @property int $groups_id FK to groups.id
 * @property bool $attempts
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MissedPart whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MissedPart whereGroupsId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MissedPart whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MissedPart whereNumberid($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MissedPart newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MissedPart newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MissedPart query()
 */
class MissedPart extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w33_missed_part_normalize_retry_state(
            int64_t number_id,
            int64_t group_id,
            int64_t attempts,
            int64_t max_attempts,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustBinaryPartCollectionModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int ATTEMPTS_MAX = 255;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Clamps a missed part's retry counters and builds its `groups_id:numberid` key.
     *
     * @return array{numberid: int, groups_id: int, attempts: int, max_attempts: int, remaining_attempts: int, should_retry: bool, next_attempts: int, composite_key: string}
     */
    public static function normalizeMissedPartRetryState(int $numberId, int $groupId, int $attempts, int $maxAttempts = 5): array
    {
        return self::usesRustFfi()
            ? self::normalizeMissedPartRetryStateNative($numberId, $groupId, $attempts, $maxAttempts)
            : self::normalizeMissedPartRetryStatePhp($numberId, $groupId, $attempts, $maxAttempts);
    }

    /**
     * @return array{numberid: int, groups_id: int, attempts: int, max_attempts: int, remaining_attempts: int, should_retry: bool, next_attempts: int, composite_key: string}
     */
    public static function normalizeMissedPartRetryStateNative(int $numberId, int $groupId, int $attempts, int $maxAttempts = 5): array
    {
        $fields = self::callNative('nntmux_w33_missed_part_normalize_retry_state', [$numberId, $groupId, $attempts, $maxAttempts]);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizeMissedPartRetryStatePhp($numberId, $groupId, $attempts, $maxAttempts);
        }

        return [
            'numberid' => (int) $fields[0],
            'groups_id' => (int) $fields[1],
            'attempts' => (int) $fields[2],
            'max_attempts' => (int) $fields[3],
            'remaining_attempts' => (int) $fields[4],
            'should_retry' => $fields[5] === '1',
            'next_attempts' => (int) $fields[6],
            'composite_key' => $fields[1].':'.$fields[0],
        ];
    }

    /**
     * @return array{numberid: int, groups_id: int, attempts: int, max_attempts: int, remaining_attempts: int, should_retry: bool, next_attempts: int, composite_key: string}
     */
    public static function normalizeMissedPartRetryStatePhp(int $numberId, int $groupId, int $attempts, int $maxAttempts = 5): array
    {
        $numberId = max(0, $numberId);
        $groupId = max(0, $groupId);
        $attempts = max(0, min($attempts, self::ATTEMPTS_MAX));
        $maxAttempts = max(1, min($maxAttempts, self::ATTEMPTS_MAX));

        return [
            'numberid' => $numberId,
            'groups_id' => $groupId,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'remaining_attempts' => max(0, $maxAttempts - $attempts),
            'should_retry' => $attempts < $maxAttempts,
            'next_attempts' => min($attempts + 1, self::ATTEMPTS_MAX),
            'composite_key' => $groupId.':'.$numberId,
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
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

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
