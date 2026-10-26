<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\RustPipelineLibrary;
use BackedEnum;
use FFI;
use FFI\CData;
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

    // ========================================================================
    // WAVE 59.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.37x, p99 6.24x; uncommon mean 7.29x, p99 5.43x); see RustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_HORIZON_SERVICE_PROVIDER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w593_horizon_access_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Known application environments, indexed from 1 (0 absent, 5 other). */
    public const array W59_HORIZON_ENVIRONMENTS = ['local' => 1, 'production' => 2, 'staging' => 3, 'testing' => 4];

    /** Trailer words after the per-item words of w59HorizonAccessBatch(). */
    public const int W59_HORIZON_TRAILER = 19;

    public static function usesW59RustFfi(): bool
    {
        $override = self::w59RustFfiState();

        return \is_bool($override) ? $override : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 59 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 59.3 Horizon access checks. Each item is `environment|userId|roles|email|ip|waitSeconds` (missing
     * fields are empty, every field is trimmed). Per item `env | uidState << 3 | admin << 5 | adminCaseMismatch
     * << 6 | min(roles, 15) << 7 | emailState << 11 | internalEmail << 13 | ipClass << 14 | waitClass << 18 | gate
     * << 21 | allowed << 22 | reason << 23 | min(wait, 2^20 - 1) << 25 | min(uidLength, 31) << 45`: env is 0
     * empty, 1-4 W59_HORIZON_ENVIRONMENTS (exact) or 5 other; uid state is 0 guest, 1 up to 19 digits
     * (authenticated) and 2 malformed (guest); roles are comma separated, admin needs the exact `Admin` role
     * and the mismatch flag marks another spelling; email state is 0 empty, 1 one `@` after a non-empty local
     * part, a dotted domain not starting or ending with `.` and printable ASCII only, else 2, with internal
     * marking a `.local`, `.lan` or `.internal` domain; ip class is w59HorizonIpClass(); the wait class is 0
     * empty, 1 below 60 seconds, 2 below 300, 3 longer and 4 invalid (over nine digits or non-numeric). The gate
     * (viewHorizon) passes an authenticated admin and Horizon also allows the exact `local` environment; reason
     * is 0 denied guest, 1 denied non-admin, 2 allowed by the gate and 3 allowed only as local. Then four reason,
     * eight ip class and five wait class counts, the largest valid wait and the distinct allowed user IDs.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59HorizonAccessBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::w59HorizonAccessBatchNative($items) : self::w59HorizonAccessBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59HorizonAccessBatchNative(array $items): array
    {
        return self::w59Invoke($items, \count($items) + self::W59_HORIZON_TRAILER) ?? self::w59HorizonAccessBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59HorizonAccessBatchPhp(array $items): array
    {
        $out = [];
        $reasons = [0, 0, 0, 0];
        $ips = array_fill(0, 8, 0);
        $metrics = [0, 0, 0, 0, 0];
        $maxWait = 0;
        $uids = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $env = trim($f[0]);
            $envIdx = $env === '' ? 0 : (self::W59_HORIZON_ENVIRONMENTS[$env] ?? 5);
            $uid = trim($f[1]);
            $uidState = $uid === '' ? 0 : (\strlen($uid) <= 19 && strspn($uid, '0123456789') === \strlen($uid) ? 1 : 2);
            $admin = $mismatch = false;
            $roles = 0;
            foreach (explode(',', $f[2]) as $role) {
                $role = trim($role);
                if ($role === '') {
                    continue;
                }
                $roles++;
                if ($role === 'Admin') {
                    $admin = true;
                } elseif (strtolower($role) === 'admin') {
                    $mismatch = true;
                }
            }
            $email = trim($f[3]);
            $emailState = 0;
            $internal = false;
            if ($email !== '') {
                $at = strpos($email, '@');
                $domain = $at === false ? '' : strtolower(substr($email, $at + 1));
                $valid = $at !== false && $at > 0 && substr_count($email, '@') === 1 && $domain !== '' && str_contains($domain, '.')
                    && $domain[0] !== '.' && $domain[-1] !== '.' && preg_match('/\A[\x21-\x7e]+\z/', $email) === 1;
                $emailState = $valid ? 1 : 2;
                $internal = $valid && (str_ends_with($domain, '.local') || str_ends_with($domain, '.lan') || str_ends_with($domain, '.internal'));
            }
            $ip = self::w59HorizonIpClass(trim($f[4]));
            $wait = trim($f[5]);
            if ($wait === '') {
                [$metric, $waitValue] = [0, 0];
            } elseif (\strlen($wait) <= 9 && strspn($wait, '0123456789') === \strlen($wait)) {
                $waitValue = (int) $wait;
                $metric = $waitValue < 60 ? 1 : ($waitValue < 300 ? 2 : 3);
            } else {
                [$metric, $waitValue] = [4, 0];
            }
            $gate = $uidState === 1 && $admin;
            $allowed = $gate || $env === 'local';
            $reason = $allowed ? ($gate ? 2 : 3) : ($uidState === 1 ? 1 : 0);
            $reasons[$reason]++;
            $ips[$ip]++;
            $metrics[$metric]++;
            if ($metric >= 1 && $metric <= 3) {
                $maxWait = max($maxWait, $waitValue);
            }
            if ($allowed && $uidState === 1) {
                $uids[$uid] = true;
            }
            $out[] = $envIdx | $uidState << 3 | ($admin ? 1 : 0) << 5 | ($mismatch ? 1 : 0) << 6 | min($roles, 15) << 7 | $emailState << 11
                | ($internal ? 1 : 0) << 13 | $ip << 14 | $metric << 18 | ($gate ? 1 : 0) << 21 | ($allowed ? 1 : 0) << 22 | $reason << 23
                | min($waitValue, (1 << 20) - 1) << 25 | min(\strlen($uid), 31) << 45;
        }
        array_push($out, ...$reasons);
        array_push($out, ...$ips);
        array_push($out, ...$metrics);
        array_push($out, $maxWait, \count($uids));

        return $out;
    }

    /**
     * 0 empty; dotted-quad IPv4 as 1 loopback (127/8), 2 private (10/8, 172.16/12, 192.168/16), 3 link local
     * (169.254/16) or 4 public; IPv6 (`:` plus hex digits, `:` and `.`, at most 45 bytes and one `::`) as 1 `::1`,
     * 3 `fe80:`, 2 `fc`/`fd` or 6 other; 7 invalid.
     */
    private static function w59HorizonIpClass(string $ip): int
    {
        if ($ip === '') {
            return 0;
        }
        $length = \strlen($ip);
        if (strspn($ip, '0123456789.') === $length) {
            $parts = explode('.', $ip);
            if (\count($parts) !== 4) {
                return 7;
            }
            foreach ($parts as $part) {
                if ($part === '' || \strlen($part) > 3 || (int) $part > 255) {
                    return 7;
                }
            }
            [$a, $b] = [(int) $parts[0], (int) $parts[1]];

            return match (true) {
                $a === 127 => 1,
                $a === 10 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168) => 2,
                $a === 169 && $b === 254 => 3,
                default => 4,
            };
        }
        $lower = strtolower($ip);
        if (! str_contains($lower, ':') || $length > 45 || strspn($lower, '0123456789abcdef:.') !== $length || substr_count($lower, '::') > 1) {
            return 7;
        }

        return match (true) {
            $lower === '::1' => 1,
            str_starts_with($lower, 'fe80:') => 3,
            str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') => 2,
            default => 6,
        };
    }

    /**
     * Wave 59 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w593', self::W59_HORIZON_SERVICE_PROVIDER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w59RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w59RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 59.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w59Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w59RustFfiState('ffi');
            $out = self::w59RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w59RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W59_HORIZON_SERVICE_PROVIDER_FFI_CDEF.)
            $written = $ffi->nntmux_w593_horizon_access_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
