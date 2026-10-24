<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SendNewRegisteredAccountMail;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

class UserServiceObserver
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_service_observer_evaluate_registration(
    uint8_t has_mail_from,
    const char *mail_from_ptr,
    size_t mail_from_len,
    uint8_t has_install_lock,
    const char *base_path_ptr,
    size_t base_path_len,
    int32_t *out_should_dispatch,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
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
     * Handle the user "created" event.
     */
    public function created(User $user): void
    {
        $roleData = Role::query()->where('id', $user->roles_id);
        $rateLimit = $roleData->value('rate_limit');
        $roleName = $roleData->value('name');
        $user->syncRoles([$roleName]);
        $user->update(
            [
                'api_token' => md5(Str::random(40)),
                'rate_limit' => $rateLimit,
            ]
        );
        $basePath = base_path();
        $sideEffects = self::evaluateRegistrationSideEffects(
            config('mail.from.address'),
            File::isFile($basePath.'/_install/install.lock'),
            $basePath
        );
        if ($sideEffects['should_dispatch_mail']) {
            SendNewRegisteredAccountMail::dispatch($user)->onQueue($sideEffects['new_account_queue']);
            SendWelcomeEmail::dispatch($user)->onQueue($sideEffects['welcome_email_queue']);
            $user->sendEmailVerificationNotification();
        }
    }

    /**
     * @return array{
     *     install_lock_path: string,
     *     should_dispatch_mail: bool,
     *     new_account_queue: string,
     *     welcome_email_queue: string
     * }
     */
    public static function evaluateRegistrationSideEffects(
        mixed $mailFromAddress,
        bool $hasInstallLock,
        string $basePath
    ): array {
        if (self::$useRustFfi) {
            return self::evaluateRegistrationSideEffectsNative($mailFromAddress, $hasInstallLock, $basePath);
        }

        return self::evaluateRegistrationSideEffectsPhp($mailFromAddress, $hasInstallLock, $basePath);
    }

    /**
     * @return array{
     *     install_lock_path: string,
     *     should_dispatch_mail: bool,
     *     new_account_queue: string,
     *     welcome_email_queue: string
     * }
     */
    public static function evaluateRegistrationSideEffectsPhp(
        mixed $mailFromAddress,
        bool $hasInstallLock,
        string $basePath
    ): array {
        return [
            'install_lock_path' => $basePath.'/_install/install.lock',
            'should_dispatch_mail' => ! empty($mailFromAddress) && $hasInstallLock,
            'new_account_queue' => 'newreg',
            'welcome_email_queue' => 'welcomeemails',
        ];
    }

    /**
     * @return array{
     *     install_lock_path: string,
     *     should_dispatch_mail: bool,
     *     new_account_queue: string,
     *     welcome_email_queue: string
     * }
     */
    public static function evaluateRegistrationSideEffectsNative(
        mixed $mailFromAddress,
        bool $hasInstallLock,
        string $basePath
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluateRegistrationSideEffectsPhp($mailFromAddress, $hasInstallLock, $basePath);
        }

        if ($mailFromAddress === null || $mailFromAddress === false) {
            $hasMailFrom = 0;
            $mailFromStr = '';
        } elseif (is_string($mailFromAddress)) {
            $hasMailFrom = 1;
            $mailFromStr = $mailFromAddress;
        } elseif (is_int($mailFromAddress) || is_float($mailFromAddress) || $mailFromAddress === true) {
            $hasMailFrom = ! empty($mailFromAddress) ? 1 : 0;
            $mailFromStr = $hasMailFrom === 1 ? '1' : '';
        } else {
            return self::evaluateRegistrationSideEffectsPhp($mailFromAddress, $hasInstallLock, $basePath);
        }

        try {
            $cap = max(96, strlen($basePath) + 64);
            $outShouldDispatch = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_service_observer_evaluate_registration(
                $hasMailFrom,
                $mailFromStr,
                strlen($mailFromStr),
                $hasInstallLock ? 1 : 0,
                $basePath,
                strlen($basePath),
                $outShouldDispatch,
                $outBuf,
                $cap
            );
            $dispatchFlag = $outShouldDispatch[0];
            if (! is_int($written) || $written <= 0 || ! is_int($dispatchFlag)) {
                return self::evaluateRegistrationSideEffectsPhp($mailFromAddress, $hasInstallLock, $basePath);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::evaluateRegistrationSideEffectsPhp($mailFromAddress, $hasInstallLock, $basePath);
            }

            return [
                'install_lock_path' => $parts[0],
                'should_dispatch_mail' => $dispatchFlag === 1,
                'new_account_queue' => $parts[1],
                'welcome_email_queue' => $parts[2],
            ];
        } catch (Throwable) {
            return self::evaluateRegistrationSideEffectsPhp($mailFromAddress, $hasInstallLock, $basePath);
        }
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
