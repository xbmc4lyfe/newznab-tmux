<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\UsenetGroup;
use App\Services\BlacklistService;
use App\Services\RegexService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class AdminAjaxController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_admin_ctl_ajax_action_kind(const char *action_ptr, size_t action_len);
    ssize_t nntmux_admin_ctl_ajax_regex_table(
        const char *action_ptr,
        size_t action_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_ctl_ajax_group_ids(
        const char *json_ptr,
        size_t json_len,
        int64_t *out_ids,
        size_t out_cap
    );
    int32_t nntmux_admin_ctl_ajax_status_flag(
        uint8_t primary_state,
        const char *primary_ptr,
        size_t primary_len,
        uint8_t secondary_state,
        const char *secondary_ptr,
        size_t secondary_len,
        int64_t *out_value
    );
    ssize_t nntmux_admin_ctl_ajax_message(uint8_t kind, int64_t value, char *out_buf, size_t out_cap);
    CDEF;

    public const string ACTION_BLACKLIST_DELETE = 'binary_blacklist_delete';

    public const string ACTION_REGEX_DELETE = 'regex_delete';

    public const string ACTION_PURGE_ALL = 'purge_all';

    public const string ACTION_RESET_ALL = 'reset_all';

    public const string ACTION_PURGE_GROUP = 'purge_group';

    public const string ACTION_RESET_GROUP = 'reset_group';

    public const string ACTION_RESET_SELECTED = 'reset_selected';

    public const string ACTION_DELETE_GROUP = 'delete_group';

    public const string ACTION_TOGGLE_ACTIVE = 'toggle_active';

    public const string ACTION_TOGGLE_BACKFILL = 'toggle_backfill';

    public const string ACTION_UNKNOWN = 'unknown';

    public const int MESSAGE_BLACKLIST_DELETED = 0;

    public const int MESSAGE_REGEX_DELETED = 1;

    public const int MESSAGE_ALL_PURGED = 2;

    public const int MESSAGE_ALL_RESET = 3;

    public const int MESSAGE_GROUP_PURGED = 4;

    public const int MESSAGE_GROUP_RESET = 5;

    public const int MESSAGE_GROUPS_RESET = 6;

    public const int MESSAGE_GROUP_DELETED = 7;

    /** Rust kind codes, in `AJAX_*` order. */
    private const array ACTION_KINDS = [
        self::ACTION_BLACKLIST_DELETE,
        self::ACTION_REGEX_DELETE,
        self::ACTION_PURGE_ALL,
        self::ACTION_RESET_ALL,
        self::ACTION_PURGE_GROUP,
        self::ACTION_RESET_GROUP,
        self::ACTION_RESET_SELECTED,
        self::ACTION_DELETE_GROUP,
        self::ACTION_TOGGLE_ACTIVE,
        self::ACTION_TOGGLE_BACKFILL,
        self::ACTION_UNKNOWN,
    ];

    private const int VALUE_ABSENT = 0;

    private const int VALUE_STRING = 1;

    private const int VALUE_NULL = 2;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminCategoryReleaseContentControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @throws Throwable
     */
    public function ajaxAction(Request $request): mixed
    {
        if ($request->missing('action')) {
            return response()->json(['success' => false, 'message' => 'No action specified'], 400);
        }

        try {
            $action = $request->input('action');
            switch (self::actionKind($action)) {
                case self::ACTION_BLACKLIST_DELETE:
                    $id = (int) $request->input('row_id');
                    (new BlacklistService)->deleteBlacklist($id);
                    echo self::responseMessage(self::MESSAGE_BLACKLIST_DELETED, $id);
                    break;

                case self::ACTION_REGEX_DELETE:
                    $id = (int) $request->input('row_id');
                    (new RegexService((string) self::regexTable((string) $action)))->deleteRegex($id);
                    echo self::responseMessage(self::MESSAGE_REGEX_DELETED, $id);
                    break;

                case self::ACTION_PURGE_ALL:
                    UsenetGroup::purge();

                    return response()->json(['success' => true, 'message' => self::responseMessage(self::MESSAGE_ALL_PURGED)]);

                case self::ACTION_RESET_ALL:
                    UsenetGroup::resetall();

                    return response()->json(['success' => true, 'message' => self::responseMessage(self::MESSAGE_ALL_RESET)]);

                case self::ACTION_PURGE_GROUP:
                    $id = (int) $request->input('group_id');
                    UsenetGroup::purge($id);

                    return response()->json(['success' => true, 'message' => self::responseMessage(self::MESSAGE_GROUP_PURGED, $id)]);

                case self::ACTION_RESET_GROUP:
                    $id = (int) $request->input('group_id');
                    UsenetGroup::reset($id);

                    return response()->json(['success' => true, 'message' => self::responseMessage(self::MESSAGE_GROUP_RESET, $id)]);

                case self::ACTION_RESET_SELECTED:
                    $groupIds = self::selectedGroupIds($request->input('group_ids', '[]'));
                    if ($groupIds === null) {
                        return response()->json(['success' => false, 'message' => 'No groups specified'], 400);
                    }

                    $count = 0;
                    foreach ($groupIds as $id) {
                        UsenetGroup::reset($id);
                        $count++;
                    }

                    return response()->json([
                        'success' => true,
                        'message' => self::responseMessage(self::MESSAGE_GROUPS_RESET, $count),
                    ]);

                case self::ACTION_DELETE_GROUP:
                    $id = (int) $request->input('group_id');
                    UsenetGroup::deleteGroup($id);

                    return response()->json(['success' => true, 'message' => self::responseMessage(self::MESSAGE_GROUP_DELETED, $id)]);

                case self::ACTION_TOGGLE_ACTIVE:
                    $groupId = (int) $request->input('group_id');
                    $status = self::activeStatusFlag($request->has('group_status'), $request->input('group_status'));

                    $message = UsenetGroup::updateGroupStatus($groupId, 'active', $status);

                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'newStatus' => $status,
                    ]);

                case self::ACTION_TOGGLE_BACKFILL:
                    $groupId = (int) $request->input('group_id');
                    $status = self::backfillStatusFlag(
                        $request->has('backfill_status'),
                        $request->input('backfill_status'),
                        $request->has('backfill'),
                        $request->input('backfill')
                    );
                    $message = UsenetGroup::updateGroupStatus($groupId, 'backfill', $status);

                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'newStatus' => $status,
                    ]);

                default:
                    return response()->json(['success' => false, 'message' => 'Unknown action'], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return null;
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Canonical `ACTION_*` kind for a request action, using the original loose `switch` comparison.
     */
    public static function actionKind(mixed $action): string
    {
        return self::$useRustFfi ? self::actionKindNative($action) : self::actionKindPhp($action);
    }

    public static function actionKindPhp(mixed $action): string
    {
        switch ($action) {
            case 'binary_blacklist_delete':
                return self::ACTION_BLACKLIST_DELETE;
            case 'category_regex_delete':
            case 'collection_regex_delete':
            case 'release_naming_regex_delete':
                return self::ACTION_REGEX_DELETE;
            case 'group_edit_purge_all':
            case 'purge_all_groups':
                return self::ACTION_PURGE_ALL;
            case 'group_edit_reset_all':
            case 'reset_all_groups':
                return self::ACTION_RESET_ALL;
            case 'group_edit_purge_single':
            case 'purge_group':
                return self::ACTION_PURGE_GROUP;
            case 'group_edit_reset_single':
            case 'reset_group':
                return self::ACTION_RESET_GROUP;
            case 'reset_selected_groups':
                return self::ACTION_RESET_SELECTED;
            case 'group_edit_delete_single':
            case 'delete_group':
                return self::ACTION_DELETE_GROUP;
            case 'toggle_group_active_status':
                return self::ACTION_TOGGLE_ACTIVE;
            case 'toggle_group_backfill_status':
            case 'toggle_group_backfill':
                return self::ACTION_TOGGLE_BACKFILL;
            default:
                return self::ACTION_UNKNOWN;
        }
    }

    public static function actionKindNative(mixed $action): string
    {
        $ffi = self::ffi();
        if ($ffi === null || ! \is_string($action)) {
            return self::actionKindPhp($action);
        }

        try {
            $code = self::callFfi($ffi, 'nntmux_admin_ctl_ajax_action_kind', $action, \strlen($action));

            return \is_int($code) && isset(self::ACTION_KINDS[$code]) ? self::ACTION_KINDS[$code] : self::actionKindPhp($action);
        } catch (Throwable) {
            return self::actionKindPhp($action);
        }
    }

    /**
     * Regex table targeted by a regex delete action; `null` for any other action.
     */
    public static function regexTable(string $action): ?string
    {
        return self::$useRustFfi ? self::regexTableNative($action) : self::regexTablePhp($action);
    }

    public static function regexTablePhp(string $action): ?string
    {
        return match ($action) {
            'category_regex_delete' => 'category_regexes',
            'collection_regex_delete' => 'collection_regexes',
            'release_naming_regex_delete' => 'release_naming_regexes',
            default => null,
        };
    }

    public static function regexTableNative(string $action): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::regexTablePhp($action);
        }

        $table = self::callForString($ffi, 'nntmux_admin_ctl_ajax_regex_table', 32, $action, \strlen($action));
        if ($table === null) {
            return self::regexTablePhp($action);
        }

        return $table === '' ? null : $table;
    }

    /**
     * Integer ids from the `group_ids` JSON array; `null` when the payload is empty or not an array.
     *
     * @return list<int>|null
     */
    public static function selectedGroupIds(string $json): ?array
    {
        return self::$useRustFfi ? self::selectedGroupIdsNative($json) : self::selectedGroupIdsPhp($json);
    }

    /**
     * @return list<int>|null
     */
    public static function selectedGroupIdsPhp(string $json): ?array
    {
        $groupIds = json_decode($json, true);
        if (empty($groupIds) || ! is_array($groupIds)) {
            return null;
        }

        $ids = [];
        foreach ($groupIds as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return list<int>|null
     */
    public static function selectedGroupIdsNative(string $json): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::selectedGroupIdsPhp($json);
        }

        try {
            // Each id needs at least one digit and a separator.
            $capacity = intdiv(\strlen($json), 2) + 1;
            $ids = $ffi->new('int64_t['.$capacity.']');
            $count = self::callFfi($ffi, 'nntmux_admin_ctl_ajax_group_ids', $json, \strlen($json), $ids, $capacity);
            if (! \is_int($count) || $count < 0 || $count > $capacity) {
                return self::selectedGroupIdsPhp($json);
            }
            if ($count === 0) {
                return null;
            }

            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $result[] = (int) $ids[$i];
            }

            return $result;
        } catch (Throwable) {
            return self::selectedGroupIdsPhp($json);
        }
    }

    /**
     * `(int)` of `group_status` when present, otherwise `0`.
     */
    public static function activeStatusFlag(bool $hasStatus, mixed $status): int
    {
        return self::$useRustFfi ? self::activeStatusFlagNative($hasStatus, $status) : self::activeStatusFlagPhp($hasStatus, $status);
    }

    public static function activeStatusFlagPhp(bool $hasStatus, mixed $status): int
    {
        return $hasStatus ? (int) $status : 0;
    }

    public static function activeStatusFlagNative(bool $hasStatus, mixed $status): int
    {
        return self::statusFlagNative($hasStatus, $status, false, null)
            ?? self::activeStatusFlagPhp($hasStatus, $status);
    }

    /**
     * `(int)` of `backfill_status`, else of `backfill`, else `0`.
     */
    public static function backfillStatusFlag(bool $hasBackfillStatus, mixed $backfillStatus, bool $hasBackfill, mixed $backfill): int
    {
        return self::$useRustFfi
            ? self::backfillStatusFlagNative($hasBackfillStatus, $backfillStatus, $hasBackfill, $backfill)
            : self::backfillStatusFlagPhp($hasBackfillStatus, $backfillStatus, $hasBackfill, $backfill);
    }

    public static function backfillStatusFlagPhp(bool $hasBackfillStatus, mixed $backfillStatus, bool $hasBackfill, mixed $backfill): int
    {
        return $hasBackfillStatus
            ? (int) $backfillStatus
            : ($hasBackfill ? (int) $backfill : 0);
    }

    public static function backfillStatusFlagNative(bool $hasBackfillStatus, mixed $backfillStatus, bool $hasBackfill, mixed $backfill): int
    {
        return self::statusFlagNative($hasBackfillStatus, $backfillStatus, $hasBackfill, $backfill)
            ?? self::backfillStatusFlagPhp($hasBackfillStatus, $backfillStatus, $hasBackfill, $backfill);
    }

    /**
     * Response text for a `MESSAGE_*` kind; `$value` is the row/group id or the reset count.
     */
    public static function responseMessage(int $kind, int $value = 0): string
    {
        return self::$useRustFfi ? self::responseMessageNative($kind, $value) : self::responseMessagePhp($kind, $value);
    }

    public static function responseMessagePhp(int $kind, int $value = 0): string
    {
        return match ($kind) {
            self::MESSAGE_BLACKLIST_DELETED => "Blacklist $value deleted.",
            self::MESSAGE_REGEX_DELETED => "Regex $value deleted.",
            self::MESSAGE_ALL_PURGED => 'All groups purged successfully',
            self::MESSAGE_ALL_RESET => 'All groups reset successfully',
            self::MESSAGE_GROUP_PURGED => "Group $value purged successfully",
            self::MESSAGE_GROUP_RESET => "Group $value reset successfully",
            self::MESSAGE_GROUPS_RESET => "$value group(s) reset successfully",
            self::MESSAGE_GROUP_DELETED => "Group $value deleted successfully",
            default => throw new InvalidArgumentException('Unknown admin AJAX message kind '.$kind.'.'),
        };
    }

    public static function responseMessageNative(int $kind, int $value = 0): string
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8 || $kind < self::MESSAGE_BLACKLIST_DELETED || $kind > self::MESSAGE_GROUP_DELETED) {
            return self::responseMessagePhp($kind, $value);
        }

        return self::callForString($ffi, 'nntmux_admin_ctl_ajax_message', 64, $kind, $value)
            ?? self::responseMessagePhp($kind, $value);
    }

    /**
     * The flag from the first present value, or `null` when PHP must cast the value.
     */
    private static function statusFlagNative(bool $hasPrimary, mixed $primary, bool $hasSecondary, mixed $secondary): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return null;
        }
        if ($hasPrimary) {
            $hasSecondary = false;
        }
        if (($hasPrimary && $primary !== null && ! \is_string($primary)) || ($hasSecondary && $secondary !== null && ! \is_string($secondary))) {
            return null;
        }

        try {
            $primaryText = $hasPrimary && \is_string($primary) ? $primary : '';
            $secondaryText = $hasSecondary && \is_string($secondary) ? $secondary : '';
            $out = $ffi->new('int64_t[1]');
            $result = self::callFfi(
                $ffi,
                'nntmux_admin_ctl_ajax_status_flag',
                self::valueState($hasPrimary, $primary),
                $primaryText,
                \strlen($primaryText),
                self::valueState($hasSecondary, $secondary),
                $secondaryText,
                \strlen($secondaryText),
                $out
            );

            return $result === 1 ? (int) $out[0] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function valueState(bool $present, mixed $value): int
    {
        if (! $present) {
            return self::VALUE_ABSENT;
        }

        return $value === null ? self::VALUE_NULL : self::VALUE_STRING;
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('w28-admin-category-release-content-controllers', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
