<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\RustPipelineLibrary;
use BackedEnum;
use FFI;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Throwable;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w366_tmux_horizon_failed_jobs(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    // The complete caller must clear the 1.5x mean and p99 performance gate.
    private const bool RUST_FFI_DEFAULT = false;

    /** @var array<class-string, bool> Companion storage: PHP enums cannot have properties. */
    private static array $rustFfiToggles = [];

    /** @var array<class-string, FFI|false> A failed resolution is cached until reset. */
    private static array $rustFfiHandles = [];

    /** @param class-string $owner */
    public static function rustFfiToggle(string $owner): ?bool
    {
        return self::$rustFfiToggles[$owner] ?? null;
    }

    /** @param class-string $owner */
    public static function setRustFfiToggle(string $owner, ?bool $use): void
    {
        if ($use === null) {
            unset(self::$rustFfiToggles[$owner], self::$rustFfiHandles[$owner]);
        } else {
            self::$rustFfiToggles[$owner] = $use;
        }
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::setRustFfiToggle(self::class, $use);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * @internal Shared additional-domain bridge, including enum companion handles.
     *
     * @param  class-string  $owner
     */
    public static function callRustFfi(string $owner, int $operation, string $input): ?string
    {
        if (strlen($input) > 16_777_216) {
            return null;
        }
        try {
            $ffi = self::$rustFfiHandles[$owner] ??= RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 128;
            $output = $ffi->new('char[128]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w366_tmux_horizon_failed_jobs($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16_777_216) {
                $capacity = -$written;
                $output = $ffi->new("char[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w366_tmux_horizon_failed_jobs($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Frame tags: 0 unsupported PHP value, 1 native-endian i64, 2 u32 LE + bytes.
     * Only instances of the requested enum are unwrapped; other objects are never cast.
     *
     * @internal
     *
     * @param  class-string<BackedEnum>  $enum
     * @return array{value: int|string|null, is_valid: bool}|null
     */
    public static function normalizeEnumNative(string $enum, int $operation, mixed $value): ?array
    {
        if ($value instanceof $enum) {
            $value = $value->value;
        }
        if (is_string($value)) {
            if (strlen($value) > 16_777_211) {
                return null;
            }
            $input = "\2".pack('V', strlen($value)).$value;
        } else {
            $input = is_int($value) ? "\1".pack('q', $value) : "\0";
        }
        $output = self::callRustFfi($enum, $operation, $input);
        if ($output === null || strlen($output) !== 9 || ord($output[8]) > 1) {
            return null;
        }
        $index = unpack('qindex', $output)['index'];
        $valid = $output[8] === "\1";
        $cases = $enum::cases();
        if ($operation === 1 && ! $valid) {
            return $index === -1 ? ['value' => null, 'is_valid' => false] : null;
        }
        if (! isset($cases[$index]) || (! $valid && $index !== 0)) {
            return null;
        }

        return ['value' => $cases[$index]->value, 'is_valid' => $valid];
    }

    /**
     * The gate requires an authenticated admin. Horizon itself also allows the
     * exact "local" environment (see HorizonApplicationServiceProvider).
     * User IDs are presence markers; zero/negative IDs are not coerced to guests.
     *
     * @return array{authenticated: bool, is_admin: bool, is_local: bool, gate_allowed: bool, allowed: bool}
     */
    public static function summarizeHorizonAccessDecision(?int $userId, bool $isAdmin, ?string $environment): array
    {
        return (self::usesRustFfi() ? self::summarizeHorizonAccessDecisionNative($userId, $isAdmin, $environment) : null)
            ?? self::summarizeHorizonAccessDecisionPhp($userId, $isAdmin, $environment);
    }

    /** @return array{authenticated: bool, is_admin: bool, is_local: bool, gate_allowed: bool, allowed: bool} */
    public static function summarizeHorizonAccessDecisionPhp(?int $userId, bool $isAdmin, ?string $environment): array
    {
        $authenticated = $userId !== null;
        $local = $environment === 'local';
        $gateAllowed = $authenticated && $isAdmin;

        return ['authenticated' => $authenticated, 'is_admin' => $isAdmin, 'is_local' => $local, 'gate_allowed' => $gateAllowed, 'allowed' => $gateAllowed || $local];
    }

    /** @return array{authenticated: bool, is_admin: bool, is_local: bool, gate_allowed: bool, allowed: bool}|null */
    public static function summarizeHorizonAccessDecisionNative(?int $userId, bool $isAdmin, ?string $environment): ?array
    {
        $environment ??= '';
        if (strlen($environment) > 16_777_202) {
            return null;
        }
        $input = chr((int) ($userId !== null)).chr((int) $isAdmin).pack('qV', $userId ?? 0, strlen($environment)).$environment;
        $output = self::callRustFfi(self::class, 4, $input);
        if ($output === null || strlen($output) !== 5) {
            return null;
        }
        $flags = array_map(ord(...), str_split($output));
        if (max($flags) > 1) {
            return null;
        }

        return ['authenticated' => $flags[0] === 1, 'is_admin' => $flags[1] === 1, 'is_local' => $flags[2] === 1, 'gate_allowed' => $flags[3] === 1, 'allowed' => $flags[4] === 1];
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user && $user->hasRole('Admin');
        });
    }
}
