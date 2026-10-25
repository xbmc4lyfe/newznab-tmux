<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Providers\AppServiceProvider;
use Illuminate\Http\JsonResponse;

class RssHealthController extends Controller
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

    /** @return array{status: string, service: string} */
    public static function summarizeRssHealth(bool $healthy): array
    {
        return (self::usesRustFfi() ? self::summarizeRssHealthNative($healthy) : null)
            ?? self::summarizeRssHealthPhp($healthy);
    }

    /** @return array{status: string, service: string} */
    private static function summarizeRssHealthPhp(bool $healthy): array
    {
        return ['status' => $healthy ? 'ok' : 'error', 'service' => 'rss'];
    }

    /** @return array{status: string, service: string}|null */
    private static function summarizeRssHealthNative(bool $healthy): ?array
    {
        $output = AppServiceProvider::w376Call(2, chr((int) $healthy));

        return $output === "\0" || $output === "\1"
            ? ['status' => $output === "\1" ? 'ok' : 'error', 'service' => 'rss'] : null;
    }

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'rss',
        ]);
    }
}
