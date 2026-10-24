<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Support\PermissionSyncHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Throwable;

class AdminRoleController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_admin_role_normalize_state_nul(
    const char *mode_ptr,
    size_t mode_len,
    const char *action_ptr,
    size_t action_len,
    uint8_t has_id,
    uint8_t has_donation,
    int64_t donation_val,
    uint8_t has_addyears,
    int64_t addyears_val,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

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

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return array{
     *     title: string,
     *     meta_title: string,
     *     is_submit: bool,
     *     should_load_role: bool,
     *     redirect_to: string
     * }
     */
    public static function resolveRoleActionState(
        string $mode,
        string $action,
        bool $hasId = false
    ): array {
        if (self::$useRustFfi) {
            return self::resolveRoleActionStateNative($mode, $action, $hasId);
        }

        return self::resolveRoleActionStatePhp($mode, $action, $hasId);
    }

    /**
     * @return array{
     *     title: string,
     *     meta_title: string,
     *     is_submit: bool,
     *     should_load_role: bool,
     *     redirect_to: string
     * }
     */
    public static function resolveRoleActionStatePhp(
        string $mode,
        string $action,
        bool $hasId = false
    ): array {
        $isSubmit = $action === 'submit';

        if ($mode === 'index') {
            $title = 'User Role List';
            $shouldLoadRole = false;
        } elseif ($mode === 'create') {
            $title = 'Add User Role';
            $shouldLoadRole = false;
        } elseif ($isSubmit) {
            $title = 'Update User Role';
            $shouldLoadRole = false;
        } elseif ($hasId) {
            $title = 'User Roles Edit';
            $shouldLoadRole = true;
        } else {
            $title = 'User Roles';
            $shouldLoadRole = false;
        }

        return [
            'title' => $title,
            'meta_title' => $title,
            'is_submit' => $isSubmit,
            'should_load_role' => $shouldLoadRole,
            'redirect_to' => 'admin/role-list',
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     meta_title: string,
     *     is_submit: bool,
     *     should_load_role: bool,
     *     redirect_to: string
     * }
     */
    public static function resolveRoleActionStateNative(
        string $mode,
        string $action,
        bool $hasId = false
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveRoleActionStatePhp($mode, $action, $hasId);
        }

        try {
            $cap = 128;
            $outScalars = $ffi->new('int64_t[4]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_admin_role_normalize_state_nul(
                $mode,
                strlen($mode),
                $action,
                strlen($action),
                $hasId ? 1 : 0,
                0,
                0,
                0,
                0,
                $outScalars,
                $outBuf,
                $cap
            );
            $isSubmit = $outScalars[0];
            $shouldLoad = $outScalars[1];
            if (! is_int($written) || $written <= 0 || ! is_int($isSubmit) || ! is_int($shouldLoad)) {
                return self::resolveRoleActionStatePhp($mode, $action, $hasId);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::resolveRoleActionStatePhp($mode, $action, $hasId);
            }

            return [
                'title' => $parts[0],
                'meta_title' => $parts[1],
                'is_submit' => $isSubmit === 1,
                'should_load_role' => $shouldLoad === 1,
                'redirect_to' => $parts[2],
            ];
        } catch (Throwable) {
            return self::resolveRoleActionStatePhp($mode, $action, $hasId);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeRoleAttributes(array $input, bool $isEdit = false): array
    {
        if (self::$useRustFfi) {
            return self::normalizeRoleAttributesNative($input, $isEdit);
        }

        return self::normalizeRoleAttributesPhp($input, $isEdit);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeRoleAttributesPhp(array $input, bool $isEdit = false): array
    {
        if ($isEdit) {
            return [
                'name' => $input['name'] ?? null,
                'apirequests' => $input['apirequests'] ?? null,
                'downloadrequests' => $input['downloadrequests'] ?? null,
                'defaultinvites' => $input['defaultinvites'] ?? null,
                'isdefault' => $input['isdefault'] ?? null,
                'donation' => $input['donation'] ?? null,
                'addyears' => $input['addyears'] ?? null,
                'rate_limit' => $input['rate_limit'] ?? null,
            ];
        }

        return [
            'name' => $input['name'] ?? null,
            'apirequests' => $input['apirequests'] ?? null,
            'downloadrequests' => $input['downloadrequests'] ?? null,
            'defaultinvites' => $input['defaultinvites'] ?? null,
            'donation' => $input['donation'] ?? 0,
            'addyears' => $input['addyears'] ?? 0,
            'rate_limit' => $input['rate_limit'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeRoleAttributesNative(array $input, bool $isEdit = false): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::normalizeRoleAttributesPhp($input, $isEdit);
        }

        try {
            $rawDonation = $input['donation'] ?? null;
            $rawAddyears = $input['addyears'] ?? null;
            $hasDonation = $rawDonation !== null;
            $hasAddyears = $rawAddyears !== null;
            $donationInt = is_int($rawDonation) ? $rawDonation : 0;
            $addyearsInt = is_int($rawAddyears) ? $rawAddyears : 0;

            $cap = 128;
            $outScalars = $ffi->new('int64_t[4]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            $mode = $isEdit ? 'edit' : 'create';
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_admin_role_normalize_state_nul(
                $mode,
                strlen($mode),
                'submit',
                6,
                0,
                $hasDonation ? 1 : 0,
                $donationInt,
                $hasAddyears ? 1 : 0,
                $addyearsInt,
                $outScalars,
                $outBuf,
                $cap
            );
            $coercedDonation = $outScalars[2];
            $coercedAddyears = $outScalars[3];
            if (! is_int($written) || $written <= 0 || ! is_int($coercedDonation) || ! is_int($coercedAddyears)) {
                return self::normalizeRoleAttributesPhp($input, $isEdit);
            }

            if ($isEdit) {
                return [
                    'name' => $input['name'] ?? null,
                    'apirequests' => $input['apirequests'] ?? null,
                    'downloadrequests' => $input['downloadrequests'] ?? null,
                    'defaultinvites' => $input['defaultinvites'] ?? null,
                    'isdefault' => $input['isdefault'] ?? null,
                    'donation' => $rawDonation,
                    'addyears' => $rawAddyears,
                    'rate_limit' => $input['rate_limit'] ?? null,
                ];
            }

            return [
                'name' => $input['name'] ?? null,
                'apirequests' => $input['apirequests'] ?? null,
                'downloadrequests' => $input['downloadrequests'] ?? null,
                'defaultinvites' => $input['defaultinvites'] ?? null,
                'donation' => $hasDonation ? (is_int($rawDonation) ? $coercedDonation : $rawDonation) : $coercedDonation,
                'addyears' => $hasAddyears ? (is_int($rawAddyears) ? $coercedAddyears : $rawAddyears) : $coercedAddyears,
                'rate_limit' => $input['rate_limit'] ?? null,
            ];
        } catch (Throwable) {
            return self::normalizeRoleAttributesPhp($input, $isEdit);
        }
    }

    /**
     * @throws \Exception
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $state = self::resolveRoleActionState('index', 'view');
        $meta_title = $state['meta_title'];
        $title = $state['title'];

        // get the user roles
        $userroles = Role::cursor()->remember();

        $this->viewData = array_merge($this->viewData, [
            'userroles' => $userroles,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.roles.index', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function create(Request $request): View|RedirectResponse
    {
        $this->setAdminPrefs();

        $action = $this->formAction($request);
        $state = self::resolveRoleActionState('create', $action);
        $meta_title = $state['meta_title'];
        $title = $state['title'];

        switch ($action) {
            case 'submit':
                $role = Role::create(self::normalizeRoleAttributes($request->all(), false));
                PermissionSyncHelper::grantRolePermissions($role, $request);

                return redirect()->to($state['redirect_to']);
            case 'view':
            default:
                $role = [];
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'yesno_ids' => [1, 0],
            'yesno_names' => ['Yes', 'No'],
            'title' => $title,
            'meta_title' => $meta_title,
            'role' => $role,
        ]);

        return view('admin.roles.add', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $this->setAdminPrefs();

        $action = $this->formAction($request);
        $state = self::resolveRoleActionState('edit', $action, $request->has('id'));
        $meta_title = $state['meta_title'];
        $title = $state['title'];
        $role = null;

        $roles = Role::pluck('name', 'id')->toArray();

        switch ($action) {
            case 'submit':
                $role = Role::find($request->input('id'));
                $role->update(self::normalizeRoleAttributes($request->all(), true));

                PermissionSyncHelper::syncRolePermissions($role, $request);

                return redirect()->to($state['redirect_to']);

            case 'view':
            default:
                if ($state['should_load_role']) {
                    $role = Role::findById($request->input('id'));
                }
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'yesno_ids' => [1, 0],
            'yesno_names' => ['Yes', 'No'],
            'title' => $title,
            'meta_title' => $meta_title,
            'role' => $role,
        ]);

        return view('admin.roles.edit', $this->viewData);
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->has('id')) {
            Role::query()->where('id', $request->input('id'))->delete();
        }

        return redirect()->to($request->server('HTTP_REFERER'));
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
