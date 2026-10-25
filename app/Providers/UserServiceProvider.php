<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\UserAccessedApi;
use App\Models\User;
use App\Observers\UserActivityObserver;
use App\Observers\UserApiCacheObserver;
use App\Observers\UserServiceObserver;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\ServiceProvider;
use Throwable;

class UserServiceProvider extends ServiceProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w361_user_gdpr_auth(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /** PHP class names: identifier segments (ASCII letters, digits, underscore or bytes >= 0x80) joined by backslashes. */
    private const string CLASS_NAME_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D';

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustUserGdprAuthEventsProviderParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $plan = self::normalizeObserverRegistrationPlan([
            UserServiceObserver::class,
            UserActivityObserver::class,
            UserApiCacheObserver::class,
        ]);
        foreach ($plan['observers'] as $observer) {
            User::observe($observer);
        }
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Trims names and leading backslashes, counts non-strings and malformed names as invalid,
     * and keeps the first spelling of each case-insensitive class name in input order.
     * Disabled plans register no observers but still report the counts.
     *
     * @param  array<mixed>  $modelClassNames
     * @return array{enabled: bool, observers: list<string>, invalid: int, duplicates: int}
     */
    public static function normalizeObserverRegistrationPlan(array $modelClassNames, bool $enableObservers = true): array
    {
        return (self::usesRustFfi() ? self::normalizeObserverRegistrationPlanNative($modelClassNames, $enableObservers) : null)
            ?? self::normalizeObserverRegistrationPlanPhp($modelClassNames, $enableObservers);
    }

    /**
     * @param  array<mixed>  $modelClassNames
     * @return array{enabled: bool, observers: list<string>, invalid: int, duplicates: int}
     */
    public static function normalizeObserverRegistrationPlanPhp(array $modelClassNames, bool $enableObservers = true): array
    {
        $observers = [];
        $seen = [];
        $invalid = 0;
        $duplicates = 0;
        foreach ($modelClassNames as $name) {
            if (! is_string($name)) {
                $invalid++;

                continue;
            }
            $name = ltrim(trim($name), '\\');
            if (preg_match(self::CLASS_NAME_PATTERN, $name) !== 1) {
                $invalid++;

                continue;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                $duplicates++;

                continue;
            }
            $seen[$key] = true;
            $observers[] = $name;
        }

        return [
            'enabled' => $enableObservers,
            'observers' => $enableObservers ? $observers : [],
            'invalid' => $invalid,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @param  array<mixed>  $modelClassNames
     * @return array{enabled: bool, observers: list<string>, invalid: int, duplicates: int}|null
     */
    public static function normalizeObserverRegistrationPlanNative(array $modelClassNames, bool $enableObservers = true): ?array
    {
        $input = pack('q2', (int) $enableObservers, count($modelClassNames));
        foreach ($modelClassNames as $name) {
            // Non-strings travel as null so Rust counts them as invalid.
            $input .= UserAccessedApi::packString(is_string($name) ? $name : null);
        }
        $bytes = self::rust(5, $input);
        if ($bytes === null || strlen($bytes) < 32) {
            return null;
        }
        /** @var array{1: int, 2: int, 3: int, 4: int} $header */
        $header = unpack('q4', $bytes);
        if (($header[1] !== 0 && $header[1] !== 1) || $header[2] < 0 || $header[3] < 0 || $header[4] < 0 || $header[4] > (strlen($bytes) - 32) / 8) {
            return null;
        }
        $fields = UserAccessedApi::unpackFrame(substr($bytes, 32), 0, $header[4]);
        $observers = [];
        foreach ($fields ?? [null] as $field) {
            if (! is_string($field)) {
                return null;
            }
            $observers[] = $field;
        }

        return [
            'enabled' => $header[1] === 1,
            'observers' => $observers,
            'invalid' => $header[2],
            'duplicates' => $header[3],
        ];
    }

    private static function rust(int $operation, string $input): ?string
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch(self::$ffi, strlen($input) + 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w361_user_gdpr_auth($operation, $input, strlen($input), $buffer, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
