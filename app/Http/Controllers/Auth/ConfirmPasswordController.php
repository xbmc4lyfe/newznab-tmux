<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginLoginRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ConfirmPasswordController extends Controller
{
    private const int REDIRECT_MAX_BYTES = 2048;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustAuthRequestsConfirmPasswordParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function show(): View
    {
        return view('auth.passwords.confirm');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']]);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();

        return redirect()->to(route('profileedit').'#security');
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * The trimmed intended path when it is a same-origin absolute path (`/` but not `//` or `/\`, no
     * backslashes or control bytes, at most 2048 bytes); otherwise the trimmed default when that is
     * safe, else `/`.
     *
     * @return array{path: string, used_default: bool, has_fragment: bool}
     */
    public static function normalizeRedirectDestination(?string $intendedPath, string $defaultPath = '/'): array
    {
        return self::usesRustFfi()
            ? self::normalizeRedirectDestinationNative($intendedPath, $defaultPath)
            : self::normalizeRedirectDestinationPhp($intendedPath, $defaultPath);
    }

    /**
     * @return array{path: string, used_default: bool, has_fragment: bool}
     */
    public static function normalizeRedirectDestinationPhp(?string $intendedPath, string $defaultPath = '/'): array
    {
        $intended = $intendedPath === null ? null : trim($intendedPath);
        if ($intended !== null && self::isLocalPath($intended)) {
            [$path, $usedDefault] = [$intended, false];
        } else {
            $default = trim($defaultPath);
            [$path, $usedDefault] = [self::isLocalPath($default) ? $default : '/', true];
        }

        return ['path' => $path, 'used_default' => $usedDefault, 'has_fragment' => str_contains($path, '#')];
    }

    /**
     * @return array{path: string, used_default: bool, has_fragment: bool}
     */
    public static function normalizeRedirectDestinationNative(?string $intendedPath, string $defaultPath = '/'): array
    {
        $result = LoginLoginRequest::evaluateNative(5, [...LoginLoginRequest::nullableParts($intendedPath), $defaultPath]);
        if ($result !== null && \count($result) === 3 && LoginLoginRequest::allFlags([$result[1], $result[2]])
            && self::isLocalPath($result[0])) {
            return ['path' => $result[0], 'used_default' => $result[1] === '1', 'has_fragment' => $result[2] === '1'];
        }

        return self::normalizeRedirectDestinationPhp($intendedPath, $defaultPath);
    }

    private static function isLocalPath(string $path): bool
    {
        return $path !== '' && \strlen($path) <= self::REDIRECT_MAX_BYTES && $path[0] === '/'
            && ($path[1] ?? '') !== '/' && preg_match('/[\x00-\x1F\x7F\\\\]/', $path) !== 1;
    }
}
