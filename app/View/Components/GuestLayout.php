<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Facades\Search;
use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchFacadeControllerGuestLayoutParityTest.php.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const string DEFAULT_APP_NAME = 'NNTmux';

    private const int DEFAULT_DESCRIPTION_BYTES = 160;

    private const int MAX_DESCRIPTION_BYTES = 1000;

    /**
     * Keep the static helpers out of the view data so `layouts.guest` sees exactly the variables it
     * saw before they existed.
     *
     * @var array<int, string>
     */
    protected $except = ['useRustFfi', 'usesRustFfi', 'resetRustFfi', 'planGuestLayoutMeta'];

    private static ?bool $useRustFfi = null;

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }

    public static function useRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
    }

    /**
     * Plans guest page meta: title, description and app name collapse whitespace runs; a blank app
     * name becomes "NNTmux". The meta title is "Title - App", or just the app name when the title is
     * blank or equals it (ASCII case-insensitive). Descriptions longer than the byte budget (blank or
     * non-positive means 160, capped at 1000) are cut on a UTF-8 lead byte, right-trimmed of spaces
     * and suffixed with "...".
     *
     * @return array{title: string, meta_title: string, description: string, truncated: bool}
     */
    public static function planGuestLayoutMeta(string $title, string $description = '', string $appName = '', int $maxDescriptionBytes = 160): array
    {
        return self::usesRustFfi()
            ? self::planGuestLayoutMetaNative($title, $description, $appName, $maxDescriptionBytes)
            : self::planGuestLayoutMetaPhp($title, $description, $appName, $maxDescriptionBytes);
    }

    /**
     * @return array{title: string, meta_title: string, description: string, truncated: bool}
     */
    private static function planGuestLayoutMetaNative(string $title, string $description, string $appName, int $maxDescriptionBytes): array
    {
        $fields = Search::evaluateNative(3, [$title, $description, $appName, (string) $maxDescriptionBytes]);
        if ($fields === null || \count($fields) !== 4) {
            return self::planGuestLayoutMetaPhp($title, $description, $appName, $maxDescriptionBytes);
        }

        return ['title' => $fields[0], 'meta_title' => $fields[1], 'description' => $fields[2], 'truncated' => $fields[3] === '1'];
    }

    /**
     * @return array{title: string, meta_title: string, description: string, truncated: bool}
     */
    private static function planGuestLayoutMetaPhp(string $title, string $description, string $appName, int $maxDescriptionBytes): array
    {
        $max = $maxDescriptionBytes < 1 ? self::DEFAULT_DESCRIPTION_BYTES : min($maxDescriptionBytes, self::MAX_DESCRIPTION_BYTES);
        $title = Search::collapseWhitespace($title);
        $app = Search::collapseWhitespace($appName);
        if ($app === '') {
            $app = self::DEFAULT_APP_NAME;
        }
        $metaTitle = $title === '' || strtolower($title) === strtolower($app) ? $app : $title.' - '.$app;

        $description = Search::collapseWhitespace($description);
        $truncated = \strlen($description) > $max;
        if ($truncated) {
            $cut = $max;
            while ($cut > 0 && (\ord($description[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $description = rtrim(substr($description, 0, $cut), ' ').'...';
        }

        return ['title' => $title, 'meta_title' => $metaTitle, 'description' => $description, 'truncated' => $truncated];
    }
}
