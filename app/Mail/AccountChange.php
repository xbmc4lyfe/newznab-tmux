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

class AccountChange extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w24_transactional_mail_verify(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // PHP remains the default: the Wave 24.4 paired screen (worst-round mean/p99 of
    // PHP/Rust latency, see RustTransactionalMailVerifyParityTest) measured
    // formatAccountChangePreheader 0.15x/0.16x common, 0.14x/0.10x uncommon.
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

    public string $username;

    public string $account;

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    public function __construct(User $user)
    {
        $roleName = $user->role->name ?? null;
        $this->username = (string) $user->username;
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $copy = self::formatAccountChangePreheader($this->site, $roleName === null ? null : (string) $roleName);
        $this->account = $copy['account'];
        $this->preheader = $copy['preheader'];
    }

    /** @return array{account: string, preheader: string} */
    public static function formatAccountChangePreheader(string $site, ?string $roleName): array
    {
        return self::usesRustFfi() ? self::formatAccountChangePreheaderNative($site, $roleName) : self::formatAccountChangePreheaderPhp($site, $roleName);
    }

    /** @return array{account: string, preheader: string} */
    public static function formatAccountChangePreheaderPhp(string $site, ?string $roleName): array
    {
        $account = $roleName ?? 'User';

        return ['account' => $account, 'preheader' => "Your {$site} account level was updated to {$account}."];
    }

    /** @return array{account: string, preheader: string} */
    public static function formatAccountChangePreheaderNative(string $site, ?string $roleName): array
    {
        $fields = self::decodeFrames(self::transformNative(4, self::encodeFrames([$site, $roleName])), 2);
        if ($fields === null || $fields[0] === null || $fields[1] === null) {
            return self::formatAccountChangePreheaderPhp($site, $roleName);
        }

        return ['account' => $fields[0], 'preheader' => $fields[1]];
    }

    /**
     * @throws \Exception
     */
    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('Account level changed')
            ->markdown('emails.markdown.accountChange', [
                'username' => $this->username,
                'account' => $this->account,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
