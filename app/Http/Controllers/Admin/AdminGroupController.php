<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminGroupListRequest;
use App\Models\UsenetGroup;
use App\Support\SizeUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminGroupController extends BasePageController
{
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
}
