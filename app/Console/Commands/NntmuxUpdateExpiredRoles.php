<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxUpdateExpiredRoles extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w32_table_account_gdpr_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /** User::updateExpiredRoles() does not report how many users it updated. */
    public const int UNKNOWN_COUNT = -1;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:update-expired-roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update users who\'s user role has expired';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $plan = self::normalizeRoleExpirationPlan(null, UserRole::USER->value);
        foreach ($plan['messages'] as $message) {
            $this->info($message);
        }
        User::updateExpiredRoles();
        $this->info(self::formatExpiredRolesSummary(self::UNKNOWN_COUNT));
    }

    /**
     * Validates an optional `YYYY-MM-DD` run date (blank means now) and the downgrade role id.
     *
     * @return array{valid: bool, run_date: ?string, default_role_id: int, warning_periods: list<string>, messages: list<string>}
     */
    public static function normalizeRoleExpirationPlan(?string $dateInput, int $defaultRoleId): array
    {
        return self::usesRustFfi()
            ? self::normalizeRoleExpirationPlanNative($dateInput, $defaultRoleId)
            : self::normalizeRoleExpirationPlanPhp($dateInput, $defaultRoleId);
    }

    /**
     * @return array{valid: bool, run_date: ?string, default_role_id: int, warning_periods: list<string>, messages: list<string>}
     */
    public static function normalizeRoleExpirationPlanPhp(?string $dateInput, int $defaultRoleId): array
    {
        $date = $dateInput === null ? '' : trim($dateInput);
        $dateValid = true;
        $runDate = null;
        if ($date !== '') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $runDate = $date;
            } else {
                $dateValid = false;
            }
        }

        return [
            'valid' => $dateValid && $defaultRoleId > 0,
            'run_date' => $runDate,
            'default_role_id' => max(0, $defaultRoleId),
            'warning_periods' => ['day', 'week', 'month'],
            'messages' => [
                'Updating expired roles.',
                'Updating users that will have their roles expire or are already expired',
            ],
        ];
    }

    /**
     * @return array{valid: bool, run_date: ?string, default_role_id: int, warning_periods: list<string>, messages: list<string>}
     */
    public static function normalizeRoleExpirationPlanNative(?string $dateInput, int $defaultRoleId): array
    {
        if (self::callNative(['update_expired_roles.normalize_plan', $dateInput, $defaultRoleId], $result)
            && is_array($result)
            && array_keys($result) === ['valid', 'run_date', 'default_role_id', 'warning_periods', 'messages']
            && is_bool($result['valid'])
            && ($result['run_date'] === null || is_string($result['run_date']))
            && is_int($result['default_role_id'])
            && self::isStringList($result['warning_periods'])
            && self::isStringList($result['messages'])) {
            /** @var array{valid: bool, run_date: ?string, default_role_id: int, warning_periods: list<string>, messages: list<string>} $result */
            return $result;
        }

        return self::normalizeRoleExpirationPlanPhp($dateInput, $defaultRoleId);
    }

    /**
     * Completion line; a negative count (UNKNOWN_COUNT) keeps the generic message.
     */
    public static function formatExpiredRolesSummary(int $updatedCount): string
    {
        return self::usesRustFfi() ? self::formatExpiredRolesSummaryNative($updatedCount) : self::formatExpiredRolesSummaryPhp($updatedCount);
    }

    public static function formatExpiredRolesSummaryPhp(int $updatedCount): string
    {
        return $updatedCount < 0 ? 'Expired roles updated.' : "Expired roles updated for {$updatedCount} user(s).";
    }

    public static function formatExpiredRolesSummaryNative(int $updatedCount): string
    {
        if (self::callNative(['update_expired_roles.format_summary', $updatedCount], $result) && is_string($result)) {
            return $result;
        }

        return self::formatExpiredRolesSummaryPhp($updatedCount);
    }

    private static function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w32_table_account_gdpr_cleanup_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
