<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Providers\AppServiceProvider;

trait RedirectsUsers
{
    // Retain PHP unless complete caller mean and p99 both clear the 1.5x gate.
    public const bool RUST_FFI_DEFAULT = false;

    public static function useRedirectRustFfi(?bool $enabled): void
    {
        AppServiceProvider::w376Use(static::class.':redirects_users', $enabled);
    }

    public static function usesRedirectRustFfi(): bool
    {
        return AppServiceProvider::w376Enabled(static::class.':redirects_users');
    }

    public static function resetRedirectRustFfi(): void
    {
        self::useRedirectRustFfi(null);
    }

    /** Resolve already-evaluated values; never call a user method from FFI. */
    public static function resolveRedirectPlan(bool $hasMethod, string $methodPath, ?string $propertyPath): string
    {
        return (self::usesRedirectRustFfi() ? self::resolveRedirectPlanNative($hasMethod, $methodPath, $propertyPath) : null)
            ?? self::resolveRedirectPlanPhp($hasMethod, $methodPath, $propertyPath);
    }

    private static function resolveRedirectPlanPhp(bool $hasMethod, string $methodPath, ?string $propertyPath): string
    {
        return $hasMethod ? $methodPath : ($propertyPath ?? '/home');
    }

    private static function resolveRedirectPlanNative(bool $hasMethod, string $methodPath, ?string $propertyPath): ?string
    {
        $frame = AppServiceProvider::w376Frame($methodPath, $propertyPath ?? '');
        if ($frame === null) {
            return null;
        }
        $output = AppServiceProvider::w376Call(4, chr((int) $hasMethod).chr((int) ($propertyPath !== null)).$frame);

        return $output === null ? null : AppServiceProvider::w376ReadString($output);
    }

    /**
     * Get the post register / login redirect path.
     */
    public function redirectPath(): string
    {
        if (method_exists($this, 'redirectTo')) {
            return $this->redirectTo();
        }

        return isset($this->redirectTo) ? $this->redirectTo : '/home';
    }
}
