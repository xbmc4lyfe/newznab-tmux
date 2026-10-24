<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Controller specifically for handling profile security operations
 * like 2FA management with no dependencies on other profile functions
 */
class ProfileSecurityController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_profile_security_disable2fa_state(
        uint8_t password_matches,
        uint8_t has_password_security,
        uint8_t wants_json,
        int32_t *out_meta,
        size_t *out_lens,
        char *out,
        size_t cap
    );
    CDEF;

    public const int DISABLE_PASSWORD_MISMATCH = 0;

    public const int DISABLE_SUCCESS = 1;

    public const int DISABLE_MISSING = 2;

    private const array DISABLE_MESSAGES = [
        self::DISABLE_PASSWORD_MISMATCH => 'Your password does not match. Please try again.',
        self::DISABLE_SUCCESS => '2FA has been successfully disabled.',
        self::DISABLE_MISSING => 'No 2FA configuration found for this user.',
    ];

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustProfileSecurityPrivacyInviteControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Disable 2FA for the authenticated user from the profile page
     * This is separate from the main profile edit functionality
     *
     * @return JsonResponse|RedirectResponse
     */
    public function disable2fa(Request $request)
    {
        // Simple validation - only password is required
        $validated = $request->validate([
            'current_password' => 'required',
        ]);

        // Check if password is correct, then disable 2FA when it is configured
        $passwordMatches = Hash::check($validated['current_password'], Auth::user()->password);
        $hasPasswordSecurity = false;
        if ($passwordMatches) {
            $user = Auth::user();
            if ($user->passwordSecurity) {
                $user->passwordSecurity->google2fa_enable = 0;
                $user->passwordSecurity->save();
                $hasPasswordSecurity = true;
            }
        }

        $state = self::disable2faResponseState($passwordMatches, $hasPasswordSecurity, $request->expectsJson() || $request->ajax());
        if ($state['json']) {
            return response()->json([
                'success' => $state['success'],
                'message' => $state['message'],
            ]);
        }

        return redirect()
            ->to('profileedit#security')
            ->with($state['flash_key'], $state['message']);
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
     * 2FA disable response: password mismatch, disabled, or no 2FA configuration, as JSON or a profile redirect flash.
     *
     * @return array{outcome: int, success: bool, json: bool, flash_key: string, message: string}
     */
    public static function disable2faResponseState(bool $passwordMatches, bool $hasPasswordSecurity, bool $wantsJson): array
    {
        return self::$useRustFfi
            ? self::disable2faResponseStateNative($passwordMatches, $hasPasswordSecurity, $wantsJson)
            : self::disable2faResponseStatePhp($passwordMatches, $hasPasswordSecurity, $wantsJson);
    }

    /**
     * @return array{outcome: int, success: bool, json: bool, flash_key: string, message: string}
     */
    public static function disable2faResponseStatePhp(bool $passwordMatches, bool $hasPasswordSecurity, bool $wantsJson): array
    {
        $outcome = match (true) {
            ! $passwordMatches => self::DISABLE_PASSWORD_MISMATCH,
            $hasPasswordSecurity => self::DISABLE_SUCCESS,
            default => self::DISABLE_MISSING,
        };
        $success = $outcome === self::DISABLE_SUCCESS;

        return [
            'outcome' => $outcome,
            'success' => $success,
            'json' => $wantsJson,
            'flash_key' => $success ? 'success_2fa' : 'error_2fa',
            'message' => self::DISABLE_MESSAGES[$outcome],
        ];
    }

    /**
     * @return array{outcome: int, success: bool, json: bool, flash_key: string, message: string}
     */
    public static function disable2faResponseStateNative(bool $passwordMatches, bool $hasPasswordSecurity, bool $wantsJson): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::disable2faResponseStatePhp($passwordMatches, $hasPasswordSecurity, $wantsJson);
        }

        try {
            $meta = $ffi->new('int32_t[3]');
            $lens = $ffi->new('size_t[2]');
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($out === null) {
                return self::disable2faResponseStatePhp($passwordMatches, $hasPasswordSecurity, $wantsJson);
            }
            $written = self::callFfi(
                $ffi,
                'nntmux_profile_security_disable2fa_state',
                $passwordMatches ? 1 : 0,
                $hasPasswordSecurity ? 1 : 0,
                $wantsJson ? 1 : 0,
                $meta,
                $lens,
                $out,
                $capacity,
            );
            $outcome = $meta[0];
            $keyLength = (int) $lens[0];
            if (! \is_int($written) || $written <= 0 || $written > $capacity || ! isset(self::DISABLE_MESSAGES[$outcome])
                || $keyLength + (int) $lens[1] !== $written) {
                return self::disable2faResponseStatePhp($passwordMatches, $hasPasswordSecurity, $wantsJson);
            }
            $payload = FFI::string($out, $written);

            return [
                'outcome' => $outcome,
                'success' => $meta[1] === 1,
                'json' => $meta[2] === 1,
                'flash_key' => substr($payload, 0, $keyLength),
                'message' => substr($payload, $keyLength),
            ];
        } catch (Throwable) {
            return self::disable2faResponseStatePhp($passwordMatches, $hasPasswordSecurity, $wantsJson);
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
}
