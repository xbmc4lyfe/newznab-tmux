<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Providers\AppServiceProvider;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    // Retain PHP unless complete caller mean and p99 both clear the 1.5x gate.
    public const bool RUST_FFI_DEFAULT = false;

    public static function useRustFfi(?bool $enabled): void
    {
        AppServiceProvider::w376Use(self::class, $enabled);
    }

    public static function usesRustFfi(): bool
    {
        return AppServiceProvider::w376Enabled(self::class);
    }

    public static function resetRustFfi(): void
    {
        self::useRustFfi(null);
    }

    /** @return array{view: string, is_app: bool} */
    public static function summarizeLayoutView(?string $view): array
    {
        return (self::usesRustFfi() ? self::summarizeLayoutViewNative($view) : null)
            ?? self::summarizeLayoutViewPhp($view);
    }

    /** @return array{view: string, is_app: bool} */
    private static function summarizeLayoutViewPhp(?string $view): array
    {
        $view ??= 'layouts.app';

        return ['view' => $view, 'is_app' => $view === 'layouts.app'];
    }

    /** @return array{view: string, is_app: bool}|null */
    private static function summarizeLayoutViewNative(?string $view): ?array
    {
        $frame = AppServiceProvider::w376Frame($view ?? '');
        if ($frame === null) {
            return null;
        }
        $output = AppServiceProvider::w376Call(5, chr((int) ($view !== null)).$frame);
        if ($output === null || strlen($output) < 5 || ord($output[0]) > 1) {
            return null;
        }
        $view = AppServiceProvider::w376ReadString($output, 1);

        return $view === null ? null : ['view' => $view, 'is_app' => $output[0] === "\1"];
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
