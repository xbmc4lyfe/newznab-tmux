<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateThemeRequest;
use App\Jobs\SendAccountDeletedEmail;
use App\Models\GdprRequest;
use App\Models\ReleaseComment;
use App\Models\RootCategory;
use App\Models\User;
use App\Models\UserDownload;
use App\Models\UserRequest;
use App\Rules\ValidEmailDomain;
use App\Services\Gdpr\GdprErasureService;
use App\Support\PermissionSyncHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use PragmaRX\Google2FALaravel\Facade as Google2FA;
use Throwable;

class ProfileController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_profile_lookup_params(
        const char *id,
        size_t id_len,
        const char *name,
        size_t name_len,
        int64_t *out_id
    );
    int32_t nntmux_profile_theme_allowed(uint32_t kind, const char *value, size_t value_len);
    int32_t nntmux_profile_numeric_classify(
        const char *payload,
        size_t payload_len,
        const size_t *lens,
        size_t count,
        uint8_t *out_kinds,
        int64_t *out_values,
        size_t out_cap
    );
    int32_t nntmux_profile_cache_key(uint32_t kind, int64_t user_id, char *out, size_t cap);
    CDEF;

    public const int LOOKUP_NONE = 0;

    public const int LOOKUP_BY_ID = 1;

    public const int LOOKUP_BY_NAME = 2;

    public const int THEME_PREFERENCE = 0;

    public const int COLOR_SCHEME = 1;

    public const int CACHE_COMPOSER_USER = 0;

    public const int CACHE_CATEGORY_EXCLUSIONS = 1;

    private const array THEME_PREFERENCES = ['light', 'dark', 'system'];

    private const array COLOR_SCHEMES = ['blue', 'indigo', 'cyan', 'teal', 'emerald', 'violet', 'pink', 'rose', 'red', 'orange', 'amber'];

    private const array CACHE_PREFIXES = [self::CACHE_COMPOSER_USER => 'composer_user_', self::CACHE_CATEGORY_EXCLUSIONS => 'user_category_exclusions_'];

    private const int NUMERIC_DROP = 0;

    private const int NUMERIC_INT = 1;

    private const int NUMERIC_DEFER = 2;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustProfileSecurityPrivacyInviteControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @throws Throwable
     */
    public function show(Request $request): mixed
    {

        $userID = $this->userdata->id;
        $privileged = $this->userdata->hasRole('Admin') || $this->userdata->hasRole('Moderator');
        $privateProfiles = config('nntmux_settings.private_profiles');
        $publicView = false;

        if ($privileged || ! $privateProfiles) {
            $altUsername = $this->scalarInput($request, 'name');
            $lookup = self::profileLookupParameters($this->scalarInput($request, 'id'), $altUsername);

            // If both 'id' and 'name' are specified, 'id' should take precedence.
            if ($lookup['mode'] === self::LOOKUP_BY_NAME) {
                $user = User::findByUsername($altUsername);
                if ($user) {
                    $this->userdata = $user;
                    $userID = $user['id'];
                }
            } elseif ($lookup['mode'] === self::LOOKUP_BY_ID) {
                $altID = (int) $lookup['id'];
                $user = User::find($altID);
                if ($user) {
                    $this->userdata = $user;
                    $userID = $altID;
                    $publicView = true;
                }
            }
        }

        if ($this->userdata === null) { // @phpstan-ignore identical.alwaysFalse
            return $this->show404('No such user!');
        }

        // Check if the user selected a theme.
        if (! isset($this->userdata->style) || $this->userdata->style === 'None') {
            $this->userdata->style = 'Using the admin selected theme.';
        }

        // Get 24-hour hourly data for downloads and API requests
        $downloadsHourly = UserDownload::getHourlyDownloads($userID);
        $apiRequestsHourly = UserRequest::getHourlyApiRequests($userID);

        // Get role limits
        $userRole = $this->userdata->roles->first();
        $downloadLimit = $userRole->downloadrequests ?? 0;
        $apiLimit = $userRole->apirequests ?? 0;

        $this->viewData = array_merge($this->viewData, [
            'downloadlist' => UserDownload::getDownloadRequestsForUser($userID),
            'apirequests' => UserRequest::getApiRequests($userID),
            'grabstoday' => UserDownload::getDownloadRequests($userID),
            'userinvitedby' => $this->userdata->invitedby ? User::find($this->userdata->invitedby) : null,
            'user' => $this->userdata,
            'privateprofiles' => $privateProfiles,
            'publicview' => $publicView,
            'privileged' => $privileged,
            'isadmin' => $this->userdata->hasRole('Admin'),
            'commentslist' => ReleaseComment::getCommentsForUserRange($userID),
            'downloadsHourly' => $downloadsHourly,
            'apiRequestsHourly' => $apiRequestsHourly,
            'downloadLimit' => $downloadLimit,
            'apiLimit' => $apiLimit,
            'meta_title' => 'View User Profile',
            'meta_keywords' => 'view,profile,user,details',
            'meta_description' => 'View User Profile for '.$this->userdata->username,
        ]);

        return view('profile.index', $this->viewData);
    }

    /**
     * @return Factory|\Illuminate\View\View|View|RedirectResponse
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {

        $action = $request->isMethod('post') ? ($request->input('action') ?? 'view') : 'view';

        $userid = $this->userdata->id;
        $this->userdata->loadMissing('passkeys');

        $errorStr = '';
        $success_2fa = $request->session()->get('success');
        $error_2fa = $request->session()->get('error');

        // Generate 2FA QR code URL if 2FA is set up but not enabled
        $google2fa_url = '';
        if ($this->userdata->passwordSecurity()->exists() && ! $this->userdata->passwordSecurity->google2fa_enable) {
            $google2fa_url = Google2FA::getQRCodeInline(
                config('app.name'),
                $this->userdata->email,
                $this->userdata->passwordSecurity->google2fa_secret
            );
        }

        switch ($action) {
            case 'newapikey':
                User::updateRssKey($userid);

                return redirect()->to('profile');
            case 'clearcookies':
                return redirect()->to('profileedit');
            case 'submit':
                $validator = Validator::make($request->all(), $this->profileValidationRules($userid));

                if ($validator->fails()) {
                    $errorStr = implode('', Arr::collapse($validator->errors()->toArray()));
                } else {
                    User::updateUser(
                        $userid,
                        $this->userdata->username,
                        $request->input('email'),
                        $this->userdata->grabs,
                        $this->userdata->roles_id,
                        $this->userdata->notes,
                        $this->userdata->invites,
                        $request->has('movieview') ? 1 : 0,
                        $request->has('musicview') ? 1 : 0,
                        $request->has('gameview') ? 1 : 0,
                        $this->userdata->xxxview ? 1 : 0,
                        $request->has('consoleview') ? 1 : 0,
                        $request->has('bookview') ? 1 : 0,
                        'None',
                    );

                    // Update theme preference
                    if ($request->has('theme_preference')) {
                        $themeValue = $request->input('theme_preference');
                        if (self::isAllowedThemeValue(self::THEME_PREFERENCE, $themeValue)) {
                            User::where('id', $userid)->update(['theme_preference' => $themeValue]);
                        }
                    }

                    // Update color scheme
                    if ($request->has('color_scheme')) {
                        $schemeValue = $request->input('color_scheme');
                        if (self::isAllowedThemeValue(self::COLOR_SCHEME, $schemeValue)) {
                            User::where('id', $userid)->update(['color_scheme' => $schemeValue]);
                        }
                    }

                    // Keep the composer's cached per-user data in sync with theme changes
                    if ($request->hasAny(['theme_preference', 'color_scheme'])) {
                        Cache::forget(self::cacheKey(self::CACHE_COMPOSER_USER, $userid));
                    }

                    // Update timezone preference
                    if ($request->has('timezone')) {
                        $timezoneValue = $request->input('timezone');
                        $validTimezones = array_merge(['UTC'], ...array_values(getAvailableTimezones()));
                        if (in_array($timezoneValue, $validTimezones, true)) {
                            User::where('id', $userid)->update(['timezone' => $timezoneValue]);
                        }
                    }

                    // Update excluded subcategories; keep numeric values as integers
                    $excludedCategories = self::excludedCategoryIds($request->input('excluded_categories', []));
                    $this->userdata->syncExcludedCategories($excludedCategories);

                    Cache::forget(self::cacheKey(self::CACHE_CATEGORY_EXCLUSIONS, $userid));

                    PermissionSyncHelper::syncUserPermissions($this->userdata, $request);

                    if ($request->has('password') && ! empty($request->input('password'))) {
                        User::updatePassword($userid, $request->input('password'));
                    }

                    if (! $this->userdata->hasRole('Admin')) {
                        if (! empty($request->input('email')) && $this->userdata->email !== $request->input('email')) {
                            $this->userdata->email = $request->input('email');
                            $this->userdata->resetEmailVerification();
                            $this->userdata->sendEmailVerificationNotification();

                            Auth::logout();

                            return redirect()->to('login')->with('info', 'You will be able to login after you verify your new email address');
                        }
                    }

                    return redirect()->to('profile')->with('success', 'Profile changes saved');
                }
                break;

            case 'view':
            default:
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'error' => $errorStr,
            'user' => $this->userdata,
            'userexccat' => User::getCachedCategoryExclusionById($userid),
            'userExcludedCategories' => $this->userdata->excludedCategories()->pluck('categories_id')->toArray(),
            'success_2fa' => $success_2fa,
            'error_2fa' => $error_2fa,
            'google2fa_url' => $google2fa_url,
            'yesno_ids' => [1, 0],
            'yesno_names' => ['Yes', 'No'],
            'publicview' => false,
            'privileged' => $this->userdata->hasRole('Admin') || $this->userdata->hasRole('Moderator'),
            'userinvitedby' => $this->userdata->invitedby ? User::find($this->userdata->invitedby) : null,
            'categoriesWithSubs' => RootCategory::with(['categories' => function ($query) {
                $query->where('status', 1)->orderBy('title');
            }])->where('status', 1)->orderBy('title')->get(),
            'meta_title' => 'Edit User Profile',
            'meta_keywords' => 'edit,profile,user,details',
            'meta_description' => 'Edit User Profile for '.$this->userdata->username,
        ]);

        return view('profile.edit', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function destroy(Request $request, GdprErasureService $erasureService): Application|View|Factory|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        $userId = $this->integerInput($request, 'id');

        if ($userId > 0 && $userId === $this->userdata->id && ! $this->userdata->hasRole('Admin')) {
            $user = User::find($userId);
            if ($user === null) {
                return redirect()->to('profile');
            }

            $gdprRequest = GdprRequest::create([
                'user_id' => $user->id,
                'requester_username' => $user->username,
                'requester_email' => $user->email,
                'type' => GdprRequest::TYPE_ERASURE,
                'status' => GdprRequest::STATUS_PROCESSING,
                'request_payload' => [
                    'requested_by' => 'profile_delete',
                    'confirmation' => 'legacy_profile_delete_link',
                ],
            ]);

            SendAccountDeletedEmail::dispatch($user);
            $erasureService->eraseForAccountDeletion($user, $user, $gdprRequest);
            Auth::logout();

            return redirect()->to('/')->with('success', 'Your account has been deleted. Retained payment and audit records have been anonymized or minimized where practical.');
        }

        if ($this->userdata->hasRole('Admin')) {

            return redirect()->to('profile');
        }

        return view('errors.503')->with('warning', 'Dont try to delete another user account!');
    }

    public function updateTheme(UpdateThemeRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
        }

        if ($request->has('theme_preference')) {
            $user->theme_preference = $request->input('theme_preference');
        }
        if ($request->has('color_scheme')) {
            $user->color_scheme = $request->input('color_scheme');
        }
        $user->save();

        // Keep the composer's cached per-user data in sync with theme changes
        Cache::forget(self::cacheKey(self::CACHE_COMPOSER_USER, (int) $user->id));

        return response()->json([
            'success' => true,
            'theme_preference' => $user->theme_preference,
            'color_scheme' => $user->color_scheme,
        ]);
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
     * Alternate profile lookup: a `/^\d+$/` id takes precedence over a non-empty name.
     *
     * @return array{mode: int, id: int|null}
     */
    public static function profileLookupParameters(string $id, string $name): array
    {
        return self::$useRustFfi
            ? self::profileLookupParametersNative($id, $name)
            : self::profileLookupParametersPhp($id, $name);
    }

    /**
     * @return array{mode: int, id: int|null}
     */
    public static function profileLookupParametersPhp(string $id, string $name): array
    {
        if ($id !== '' && preg_match('/^\d+$/', $id) === 1) {
            return ['mode' => self::LOOKUP_BY_ID, 'id' => (int) $id];
        }

        return ['mode' => $name !== '' ? self::LOOKUP_BY_NAME : self::LOOKUP_NONE, 'id' => null];
    }

    /**
     * @return array{mode: int, id: int|null}
     */
    public static function profileLookupParametersNative(string $id, string $name): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::profileLookupParametersPhp($id, $name);
        }

        try {
            $out = $ffi->new('int64_t[1]');
            $mode = self::callFfi($ffi, 'nntmux_profile_lookup_params', $id, \strlen($id), $name, \strlen($name), $out);

            return match ($mode) {
                self::LOOKUP_BY_ID => ['mode' => self::LOOKUP_BY_ID, 'id' => (int) $out[0]],
                self::LOOKUP_BY_NAME, self::LOOKUP_NONE => ['mode' => $mode, 'id' => null],
                default => self::profileLookupParametersPhp($id, $name),
            };
        } catch (Throwable) {
            return self::profileLookupParametersPhp($id, $name);
        }
    }

    /**
     * Theme preference (`light|dark|system`) or color scheme whitelist check; strict like `in_array(..., true)`.
     */
    public static function isAllowedThemeValue(int $kind, mixed $value): bool
    {
        return self::$useRustFfi
            ? self::isAllowedThemeValueNative($kind, $value)
            : self::isAllowedThemeValuePhp($kind, $value);
    }

    public static function isAllowedThemeValuePhp(int $kind, mixed $value): bool
    {
        return match ($kind) {
            self::THEME_PREFERENCE => \in_array($value, self::THEME_PREFERENCES, true),
            self::COLOR_SCHEME => \in_array($value, self::COLOR_SCHEMES, true),
            default => false,
        };
    }

    public static function isAllowedThemeValueNative(int $kind, mixed $value): bool
    {
        $ffi = self::ffi();
        if ($ffi === null || ! \is_string($value) || ($kind !== self::THEME_PREFERENCE && $kind !== self::COLOR_SCHEME)) {
            return self::isAllowedThemeValuePhp($kind, $value);
        }

        try {
            $allowed = self::callFfi($ffi, 'nntmux_profile_theme_allowed', $kind, $value, \strlen($value));

            return match ($allowed) {
                0 => false,
                1 => true,
                default => self::isAllowedThemeValuePhp($kind, $value),
            };
        } catch (Throwable) {
            return self::isAllowedThemeValuePhp($kind, $value);
        }
    }

    /**
     * Keep `is_numeric()` excluded category values as integers, preserving keys.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, int>
     */
    public static function excludedCategoryIds(array $values): array
    {
        return self::$useRustFfi
            ? self::excludedCategoryIdsNative($values)
            : self::excludedCategoryIdsPhp($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, int>
     */
    public static function excludedCategoryIdsPhp(array $values): array
    {
        return array_map('intval', array_filter($values, 'is_numeric'));
    }

    /**
     * Strings are classified natively; integers and floats are numeric and other types are not.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, int>
     */
    public static function excludedCategoryIdsNative(array $values): array
    {
        $strings = array_values(array_filter($values, 'is_string'));
        $count = \count($strings);
        $ffi = self::ffi();
        if ($ffi === null || $count === 0) {
            return self::excludedCategoryIdsPhp($values);
        }

        try {
            $lens = $ffi->new("size_t[{$count}]");
            foreach ($strings as $index => $string) {
                $lens[$index] = \strlen($string);
            }
            $payload = implode('', $strings);
            $kinds = $ffi->new("uint8_t[{$count}]");
            $integers = $ffi->new("int64_t[{$count}]");
            $classified = self::callFfi($ffi, 'nntmux_profile_numeric_classify', $payload, \strlen($payload), $lens, $count, $kinds, $integers, $count);
            if ($classified !== $count) {
                return self::excludedCategoryIdsPhp($values);
            }

            $result = [];
            $index = 0;
            foreach ($values as $key => $value) {
                if (\is_string($value)) {
                    $kind = $kinds[$index];
                    $integer = $integers[$index];
                    $index++;
                    if ($kind === self::NUMERIC_INT) {
                        $result[$key] = (int) $integer;
                    } elseif ($kind === self::NUMERIC_DEFER) {
                        $result[$key] = (int) $value;
                    } elseif ($kind !== self::NUMERIC_DROP) {
                        return self::excludedCategoryIdsPhp($values);
                    }
                } elseif (\is_int($value) || \is_float($value)) {
                    $result[$key] = (int) $value;
                }
            }

            return $result;
        } catch (Throwable) {
            return self::excludedCategoryIdsPhp($values);
        }
    }

    /**
     * `composer_user_{id}` or `user_category_exclusions_{id}` cache key.
     *
     * @throws InvalidArgumentException for an unknown kind
     */
    public static function cacheKey(int $kind, int $userId): string
    {
        return self::$useRustFfi
            ? self::cacheKeyNative($kind, $userId)
            : self::cacheKeyPhp($kind, $userId);
    }

    /**
     * @throws InvalidArgumentException for an unknown kind
     */
    public static function cacheKeyPhp(int $kind, int $userId): string
    {
        if (! isset(self::CACHE_PREFIXES[$kind])) {
            throw new InvalidArgumentException("Unknown profile cache key kind {$kind}.");
        }

        return self::CACHE_PREFIXES[$kind].$userId;
    }

    /**
     * @throws InvalidArgumentException for an unknown kind
     */
    public static function cacheKeyNative(int $kind, int $userId): string
    {
        $ffi = self::ffi();
        if ($ffi === null || ! isset(self::CACHE_PREFIXES[$kind])) {
            return self::cacheKeyPhp($kind, $userId);
        }

        try {
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
            if ($out === null) {
                return self::cacheKeyPhp($kind, $userId);
            }
            $written = self::callFfi($ffi, 'nntmux_profile_cache_key', $kind, $userId, $out, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::cacheKeyPhp($kind, $userId);
            }

            return FFI::string($out, $written);
        } catch (Throwable) {
            return self::cacheKeyPhp($kind, $userId);
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

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function profileValidationRules(int $userId): array
    {
        return [
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email,'.$userId, new ValidEmailDomain],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/'],
        ];
    }
}
