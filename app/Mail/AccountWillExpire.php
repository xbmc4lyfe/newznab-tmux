<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AccountWillExpire extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w24_transactional_mail_verify(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // PHP remains the default: the Wave 24.4 paired screen (worst-round mean/p99 of
    // PHP/Rust latency, see RustTransactionalMailVerifyParityTest) measured
    // resolveRoleExpiryMailState 0.13x/0.13x common, 0.12x/0.12x uncommon.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function transformNative(int $operation, string $input): ?string
    {
        if (strlen($input) > 67_108_864) {
            return null;
        }

        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave24-transactional-mail-verify', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            if ($buffer === null) {
                return null;
            }
            // A PHP empty string supplies a non-null pointer even for zero input length.
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w24_transactional_mail_verify($operation, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $required = -$written;
                if ($required <= $capacity || $required > 67_108_864) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, $required, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w24_transactional_mail_verify($operation, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity || $written > 67_108_864) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<?string> $values */
    private static function encodeFrames(array $values): string
    {
        $input = '';
        foreach ($values as $value) {
            $input .= $value === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /** @return list<?string>|null */
    private static function decodeFrames(?string $output, int $count): ?array
    {
        if ($output === null) {
            return null;
        }
        $offset = 0;
        $size = strlen($output);
        $fields = [];
        for ($i = 0; $i < $count; $i++) {
            if ($size - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', $output, $offset);
            if ($header === false || ! is_int($header['length'] ?? null)) {
                return null;
            }
            $length = $header['length'];
            $offset += 4;
            if ($length === 0xFFFFFFFF) {
                $fields[] = null;

                continue;
            }
            if ($size - $offset < $length) {
                return null;
            }
            $fields[] = substr($output, $offset, $length);
            $offset += $length;
        }

        return $offset === $size ? $fields : null;
    }

    public int $days;

    public string $username;

    public string $account;

    public string $site;

    public ?string $preheader;

    public bool $hasPendingRole;

    public ?string $pendingRoleName;

    public ?string $pendingRoleStartDate;

    private string $siteEmail;

    public function __construct(User $user, int $days)
    {
        $roleExpiryInfo = $user->getRoleExpiryInfo();
        $pendingRole = $roleExpiryInfo['pending_role'];
        $pendingStart = $roleExpiryInfo['pending_start'];

        $state = self::resolveRoleExpiryMailState(
            (string) $user->username,
            (string) ($user->role->name ?? 'User'),
            $days,
            (bool) $roleExpiryInfo['has_pending_role'],
            $pendingRole === null ? null : (string) $pendingRole->name,
            $pendingStart?->toFormattedDateString(),
        );

        $this->days = $state['days'];
        $this->username = $state['username'];
        $this->account = $state['account'];
        $this->hasPendingRole = $state['hasPendingRole'];
        $this->pendingRoleName = $state['pendingRoleName'];
        $this->pendingRoleStartDate = $state['pendingRoleStartDate'];
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $this->preheader = $state['preheader'];
    }

    /**
     * A pending role is shown only when the flag, role name and start date are all present.
     *
     * @return array{username: string, account: string, days: int, hasPendingRole: bool, pendingRoleName: ?string, pendingRoleStartDate: ?string, preheader: string}
     */
    public static function resolveRoleExpiryMailState(string $username, string $account, int $days, bool $hasPendingFlag, ?string $pendingRoleName, ?string $pendingStartDate): array
    {
        return self::usesRustFfi()
            ? self::resolveRoleExpiryMailStateNative($username, $account, $days, $hasPendingFlag, $pendingRoleName, $pendingStartDate)
            : self::resolveRoleExpiryMailStatePhp($username, $account, $days, $hasPendingFlag, $pendingRoleName, $pendingStartDate);
    }

    /** @return array{username: string, account: string, days: int, hasPendingRole: bool, pendingRoleName: ?string, pendingRoleStartDate: ?string, preheader: string} */
    public static function resolveRoleExpiryMailStatePhp(string $username, string $account, int $days, bool $hasPendingFlag, ?string $pendingRoleName, ?string $pendingStartDate): array
    {
        $hasPendingRole = $hasPendingFlag && $pendingRoleName !== null && $pendingStartDate !== null;

        return [
            'username' => $username,
            'account' => $account,
            'days' => $days,
            'hasPendingRole' => $hasPendingRole,
            'pendingRoleName' => $hasPendingRole ? $pendingRoleName : null,
            'pendingRoleStartDate' => $hasPendingRole ? $pendingStartDate : null,
            'preheader' => $hasPendingRole
                ? "Your {$account} role expires in {$days} day(s), then {$pendingRoleName} is scheduled."
                : "Your {$account} role expires in {$days} day(s).",
        ];
    }

    /** @return array{username: string, account: string, days: int, hasPendingRole: bool, pendingRoleName: ?string, pendingRoleStartDate: ?string, preheader: string} */
    public static function resolveRoleExpiryMailStateNative(string $username, string $account, int $days, bool $hasPendingFlag, ?string $pendingRoleName, ?string $pendingStartDate): array
    {
        $fields = self::decodeFrames(self::transformNative(0, self::encodeFrames([$account, (string) $days, $hasPendingFlag ? '1' : '0', $pendingRoleName, $pendingStartDate])), 4);
        if ($fields === null || ! in_array($fields[0], ['0', '1'], true) || $fields[3] === null) {
            return self::resolveRoleExpiryMailStatePhp($username, $account, $days, $hasPendingFlag, $pendingRoleName, $pendingStartDate);
        }

        return [
            'username' => $username,
            'account' => $account,
            'days' => $days,
            'hasPendingRole' => $fields[0] === '1',
            'pendingRoleName' => $fields[1],
            'pendingRoleStartDate' => $fields[2],
            'preheader' => $fields[3],
        ];
    }

    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('Your account is about to expire')
            ->markdown('emails.markdown.accountWillExpire', [
                'username' => $this->username,
                'account' => $this->account,
                'days' => $this->days,
                'hasPendingRole' => $this->hasPendingRole,
                'pendingRoleName' => $this->pendingRoleName,
                'pendingRoleStartDate' => $this->pendingRoleStartDate,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
