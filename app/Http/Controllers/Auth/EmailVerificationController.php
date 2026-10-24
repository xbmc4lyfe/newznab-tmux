<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class EmailVerificationController extends Controller
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_email_verification_verify_sha1(
    const char *hash_ptr,
    size_t hash_len,
    const char *email_ptr,
    size_t email_len,
    int32_t *out_matches,
    uint8_t *out_hex40,
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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
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
     * @return array{matches: bool, expected_sha1: string}
     */
    public static function verifyEmailSha1Hash(string $providedHash, string $emailForVerification): array
    {
        if (self::$useRustFfi) {
            return self::verifyEmailSha1HashNative($providedHash, $emailForVerification);
        }

        return self::verifyEmailSha1HashPhp($providedHash, $emailForVerification);
    }

    /**
     * @return array{matches: bool, expected_sha1: string}
     */
    public static function verifyEmailSha1HashPhp(string $providedHash, string $emailForVerification): array
    {
        $expected = sha1($emailForVerification);

        return [
            'matches' => hash_equals($providedHash, $expected),
            'expected_sha1' => $expected,
        ];
    }

    /**
     * @return array{matches: bool, expected_sha1: string}
     */
    public static function verifyEmailSha1HashNative(string $providedHash, string $emailForVerification): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::verifyEmailSha1HashPhp($providedHash, $emailForVerification);
        }

        try {
            $outMatches = $ffi->new('int32_t[1]');
            $outHex = $ffi->new('uint8_t[40]');

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_email_verification_verify_sha1(
                $providedHash,
                strlen($providedHash),
                $emailForVerification,
                strlen($emailForVerification),
                $outMatches,
                $outHex,
                40
            );
            $matchesFlag = $outMatches[0];
            if ($written !== 40 || ! is_int($matchesFlag)) {
                return self::verifyEmailSha1HashPhp($providedHash, $emailForVerification);
            }

            return [
                'matches' => $matchesFlag === 1,
                'expected_sha1' => FFI::string($outHex, 40),
            ];
        } catch (Throwable) {
            return self::verifyEmailSha1HashPhp($providedHash, $emailForVerification);
        }
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        return view('auth.verify-email');
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

        $verification = self::verifyEmailSha1Hash($hash, (string) $user->getEmailForVerification());
        if (! $verification['matches']) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()
            ->route('login')
            ->with('success', 'Your email address has been verified. You can now sign in.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'resent');
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
