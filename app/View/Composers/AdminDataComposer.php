<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\User;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\SiteLogoService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class AdminDataComposer
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_admin_composer_view_state(uint32_t logged_in, uint32_t is_user, uint32_t is_admin, uint32_t is_mod, uint32_t has_theme, const char *theme_ptr, size_t theme_len, uint32_t has_color, const char *color_ptr, size_t color_len, uint32_t *out_flags, size_t *out_lens, char *out_buf, size_t out_cap);
        C;

    private const int DECLINE = -2147483648;

    /** Opt-in only; see the paired measurements in RustSignupRoleQueueEnumComposerParityTest. */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(
        private readonly SiteLogoService $siteLogoService,
        private readonly ConfigurationProvider $configuration,
    ) {}

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Bind lightweight admin data to the view.
     */
    public function compose(View $view): void
    {
        $user = Auth::user();
        $isNntmuxUser = $user instanceof User;
        $site = $this->configuration->site();

        $state = self::normalizeViewState(
            $user !== null,
            $isNntmuxUser,
            $isNntmuxUser && $user->hasRole('Admin'),
            $isNntmuxUser && $user->hasRole('Moderator'),
            $isNntmuxUser ? ($user->theme_preference ?? null) : null,
            $isNntmuxUser ? ($user->color_scheme ?? null) : null,
        );

        $view->with([
            'serverroot' => url('/'),
            'site' => $site,
            'siteLogoUrl' => $this->siteLogoService->url($site->logoPath),
            'userdata' => $user,
            ...$state,
        ]);
    }

    /**
     * @return array{loggedin: bool, isadmin: bool, ismod: bool, userTheme: mixed, userColorScheme: mixed}
     */
    public static function normalizeViewState(bool $loggedIn, bool $isNntmuxUser, bool $isAdmin, bool $isMod, mixed $theme, mixed $colorScheme): array
    {
        return self::$useRustFfi
            ? self::normalizeViewStateNative($loggedIn, $isNntmuxUser, $isAdmin, $isMod, $theme, $colorScheme)
            : self::normalizeViewStatePhp($loggedIn, $isNntmuxUser, $isAdmin, $isMod, $theme, $colorScheme);
    }

    /**
     * @return array{loggedin: bool, isadmin: bool, ismod: bool, userTheme: mixed, userColorScheme: mixed}
     */
    public static function normalizeViewStatePhp(bool $loggedIn, bool $isNntmuxUser, bool $isAdmin, bool $isMod, mixed $theme, mixed $colorScheme): array
    {
        return [
            'loggedin' => $loggedIn,
            'isadmin' => $isNntmuxUser && $isAdmin,
            'ismod' => $isNntmuxUser && $isMod,
            'userTheme' => $isNntmuxUser ? ($theme ?? 'light') : 'light',
            'userColorScheme' => $isNntmuxUser ? ($colorScheme ?? 'blue') : 'blue',
        ];
    }

    /**
     * Non-string preferences are not marshalled; PHP keeps their exact values.
     *
     * @return array{loggedin: bool, isadmin: bool, ismod: bool, userTheme: mixed, userColorScheme: mixed}
     */
    public static function normalizeViewStateNative(bool $loggedIn, bool $isNntmuxUser, bool $isAdmin, bool $isMod, mixed $theme, mixed $colorScheme): array
    {
        $ffi = self::ffi();
        if ($ffi === null || ($theme !== null && ! \is_string($theme)) || ($colorScheme !== null && ! \is_string($colorScheme))) {
            return self::normalizeViewStatePhp($loggedIn, $isNntmuxUser, $isAdmin, $isMod, $theme, $colorScheme);
        }

        $themeStr = $theme ?? '';
        $colorStr = $colorScheme ?? '';

        try {
            $flags = $ffi->new('uint32_t[3]');
            $lens = $ffi->new('size_t[2]');
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($themeStr) + \strlen($colorStr) + 16, $cap);
            if ($scratch !== null) {
                $args = [(int) $loggedIn, (int) $isNntmuxUser, (int) $isAdmin, (int) $isMod, (int) ($theme !== null), $themeStr, \strlen($themeStr), (int) ($colorScheme !== null), $colorStr, \strlen($colorStr), $flags, $lens];
                $written = self::call($ffi, 'nntmux_admin_composer_view_state', ...[...$args, $scratch, $cap]);
                if (\is_int($written) && $written < 0 && $written > self::DECLINE) {
                    $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                    $written = $scratch === null ? null : self::call($ffi, 'nntmux_admin_composer_view_state', ...[...$args, $scratch, $cap]);
                }
                if ($scratch !== null && \is_int($written) && $written >= 0 && $written <= $cap) {
                    $themeLen = (int) $lens[0];
                    $colorLen = (int) $lens[1];
                    if ($themeLen + $colorLen === $written) {
                        $payload = $written === 0 ? '' : FFI::string($scratch, $written);

                        return [
                            'loggedin' => (int) $flags[0] === 1,
                            'isadmin' => (int) $flags[1] === 1,
                            'ismod' => (int) $flags[2] === 1,
                            'userTheme' => substr($payload, 0, $themeLen),
                            'userColorScheme' => substr($payload, $themeLen, $colorLen),
                        ];
                    }
                }
            }
        } catch (Throwable) {
        }

        return self::normalizeViewStatePhp($loggedIn, $isNntmuxUser, $isAdmin, $isMod, $theme, $colorScheme);
    }

    private static function call(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
