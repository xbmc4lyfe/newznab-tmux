<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminGroupListRequest;
use App\Models\UsenetGroup;
use App\Support\RustPipelineLibrary;
use App\Support\SizeUnit;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AdminGroupController extends BasePageController
{
    public const string W381_FFI_CDEF = <<<'C'
        ssize_t nntmux_w381_obfuscation_gate_router_admin_names(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustObfuscationGateRouterAdminNamesParityTest.php is below 1.5x.
     */
    public const bool W381_RUST_FFI_DEFAULT = false;

    private const int W381_RUST_MAX_INPUT = 16_777_216;

    private static ?bool $w381UseRustFfi = null;

    private static FFI|false|null $w381Ffi = null;

    /**
     * @throws \Exception
     */
    public function index(AdminGroupListRequest $request): mixed
    {
        return $this->groupList($request, null, 'Group List');
    }

    /**
     * @throws \Exception
     */
    public function createBulk(Request $request): mixed
    {
        // set the current action
        $action = $this->formAction($request);
        $groupmsglist = '';

        if ($action === 'submit') {
            $groupFilter = $request->input('groupfilter');

            if (is_string($groupFilter) && $groupFilter !== '') {
                $active = $request->has('active') ? $request->integer('active') : 1;
                $backfill = $request->has('backfill') ? $request->integer('backfill') : 1;

                $groupmsglist = UsenetGroup::addBulk($groupFilter, $active, $backfill);
            }
        }

        $title = 'Bulk Add Newsgroups';

        return view('admin.groups.bulk', compact('title', 'groupmsglist'));
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        // Set the current action.
        $action = $this->formAction($request);

        $group = self::defaultGroup();
        $title = self::editTitle($action, $request->has('id'));

        switch ($action) {
            case 'submit':
                // Convert the size + unit pair to bytes; a blank input stays blank
                // so the model stores null and the site-wide setting applies.
                $minSizeInput = $request->input('minsizetoformrelease');
                if ($minSizeInput !== null && $minSizeInput !== '') {
                    $request->merge([
                        'minsizetoformrelease' => SizeUnit::toBytes($minSizeInput, $request->input('minsizetoformrelease_unit', 'MB')),
                    ]);
                }
                $request->request->remove('minsizetoformrelease_unit');

                if (empty($request->input('id'))) {
                    // Add a new group.
                    $request->merge(['name' => UsenetGroup::isValidGroup($request->input('name'))]);
                    if ($request->input('name') !== false) {
                        UsenetGroup::addGroup($request->all());
                    }
                } else {
                    // Update an existing group.
                    UsenetGroup::updateGroup($request->all());
                }

                return redirect()->to('admin/group-list');

            case 'view':
            default:
                if ($request->has('id')) {
                    $id = $request->input('id');
                    $group = UsenetGroup::getGroupByID($id);
                }
                break;
        }

        $groupMinSize = SizeUnit::fromBytes($group['minsizetoformrelease'] ?? 0);

        return view('admin.groups.edit', compact('title', 'group', 'groupMinSize') + ['sizeUnits' => SizeUnit::UNITS]);
    }

    /**
     * @throws \Exception
     */
    public function active(AdminGroupListRequest $request): mixed
    {
        return $this->groupList($request, true, 'Active Groups');
    }

    /**
     * @throws \Exception
     */
    public function inactive(AdminGroupListRequest $request): mixed
    {
        return $this->groupList($request, false, 'Inactive Groups');
    }

    private function groupList(AdminGroupListRequest $request, ?bool $active, string $title): View
    {
        $groupname = $request->groupName();
        $filters = $request->listOptions();
        $query = self::filterQuery($groupname, $filters, $active);
        $filters = $query['filters'];
        $listQuery = $query['listQuery'];
        $grouplist = UsenetGroup::getGroupsRange($groupname, $active, true, $filters)->appends($listQuery);

        return view('admin.groups.index', compact('title', 'groupname', 'grouplist', 'filters', 'listQuery'));
    }

    // PHP stays the default until every paired common/p99 benchmark clears 1.5x.
    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    /** @return array<string, mixed> */
    public static function defaultGroup(): array
    {
        return self::usesRustFfi() ? self::defaultGroupNative() : self::defaultGroupPhp();
    }

    /** @return array<string, mixed> */
    public static function defaultGroupPhp(): array
    {
        return ['id' => '', 'name' => '', 'description' => '', 'minfilestoformrelease' => 0, 'active' => 0, 'backfill' => 0, 'minsizetoformrelease' => 0, 'first_record' => 0, 'last_record' => 0, 'backfill_target' => 0];
    }

    /** @return array<string, mixed> */
    public static function defaultGroupNative(): array
    {
        return AdminBlacklistController::wave28Native(9, []) ?? self::defaultGroupPhp();
    }

    /** @param array<array-key, mixed> $filters
     * @return array{filters: array<array-key, mixed>, listQuery: array<array-key, mixed>}
     */
    public static function filterQuery(string $groupname, array $filters, ?bool $active): array
    {
        return self::usesRustFfi() ? self::filterQueryNative($groupname, $filters, $active) : self::filterQueryPhp($groupname, $filters, $active);
    }

    /** @param array<array-key, mixed> $filters
     * @return array{filters: array<array-key, mixed>, listQuery: array<array-key, mixed>}
     */
    public static function filterQueryPhp(string $groupname, array $filters, ?bool $active): array
    {
        if ($active !== null) {
            $filters['active'] = $active ? '1' : '0';
        }

        return ['filters' => $filters, 'listQuery' => ['groupname' => $groupname] + $filters];
    }

    /** @param array<array-key, mixed> $filters
     * @return array{filters: array<array-key, mixed>, listQuery: array<array-key, mixed>}
     */
    public static function filterQueryNative(string $groupname, array $filters, ?bool $active): array
    {
        return AdminBlacklistController::wave28Native(10, ['groupname' => $groupname, 'filters' => $filters, 'active' => $active]) ?? self::filterQueryPhp($groupname, $filters, $active);
    }

    public static function editTitle(string $action, bool $hasId): string
    {
        return self::usesRustFfi() ? self::editTitleNative($action, $hasId) : self::editTitlePhp($action, $hasId);
    }

    public static function editTitlePhp(string $action, bool $hasId): string
    {
        return $action === 'submit' ? 'Group Edit' : ($hasId ? 'Newsgroup Edit' : 'Newsgroup Add');
    }

    public static function editTitleNative(string $action, bool $hasId): string
    {
        $result = AdminBlacklistController::wave28Native(11, ['action' => $action, 'has_id' => $hasId]);

        return $result !== null && is_string($result['title'] ?? null) ? $result['title'] : self::editTitlePhp($action, $hasId);
    }

    /**
     * Plans `createBulk()` for a request: whether it submits, whether it reaches
     * UsenetGroup::addBulk() (a nonempty string filter), whether addBulk() would reject the list as
     * blank (any empty or whitespace-only line) and the active/backfill flags, defaulting to 1.
     *
     * @return array{submit: bool, runs: bool, blank_list: bool, active: int, backfill: int}
     */
    public static function normalizeBulkAddPlan(string $action, mixed $groupFilter, ?int $active, ?int $backfill): array
    {
        return (self::usesW381RustFfi() ? self::normalizeBulkAddPlanNative($action, $groupFilter, $active, $backfill) : null)
            ?? self::normalizeBulkAddPlanPhp($action, $groupFilter, $active, $backfill);
    }

    /**
     * @return array{submit: bool, runs: bool, blank_list: bool, active: int, backfill: int}
     */
    private static function normalizeBulkAddPlanPhp(string $action, mixed $groupFilter, ?int $active, ?int $backfill): array
    {
        $submit = $action === 'submit';

        return ['submit' => $submit, 'runs' => $submit && is_string($groupFilter) && $groupFilter !== '',
            'blank_list' => is_string($groupFilter) && preg_match('/^\s*$/m', $groupFilter) === 1, 'active' => $active ?? 1, 'backfill' => $backfill ?? 1];
    }

    /**
     * @return array{submit: bool, runs: bool, blank_list: bool, active: int, backfill: int}|null
     */
    private static function normalizeBulkAddPlanNative(string $action, mixed $groupFilter, ?int $active, ?int $backfill): ?array
    {
        $flags = (is_string($groupFilter) ? 1 : 0) | ($active !== null ? 2 : 0) | ($backfill !== null ? 4 : 0);
        $input = self::w381PackString($action).self::w381PackString(is_string($groupFilter) ? $groupFilter : '').pack('q2', $active ?? 0, $backfill ?? 0);
        $fields = self::w381UnpackInts(self::w381CallRustFfi(5, $flags, $input), 5);
        if ($fields === null || ($fields[0] | $fields[1] | $fields[2]) & ~1) {
            return null;
        }

        return ['submit' => $fields[0] === 1, 'runs' => $fields[1] === 1, 'blank_list' => $fields[2] === 1, 'active' => $fields[3], 'backfill' => $fields[4]];
    }

    public static function useW381RustFfi(?bool $enabled): void
    {
        self::$w381UseRustFfi = $enabled;
    }

    public static function usesW381RustFfi(): bool
    {
        return self::$w381UseRustFfi ?? self::W381_RUST_FFI_DEFAULT;
    }

    public static function resetW381RustFfi(): void
    {
        self::$w381UseRustFfi = null;
        self::$w381Ffi = null;
    }

    private static function w381PackString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function w381UnpackInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 38.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function w381CallRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::W381_RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$w381Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w381', self::W381_FFI_CDEF) ?? false;
            if (self::$w381Ffi === false) {
                return null;
            }
            $ffi = self::$w381Ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::W381_RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
