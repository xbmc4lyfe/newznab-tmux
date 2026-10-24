<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ForgottenPassword;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPasswordForgottenEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w27_account_mail_dispatch_jobs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Retain PHP unless every Wave 27.1 common/p99 paired benchmark clears 1.5x.
    // Raw protocol and measured ratios: RustAccountMailDispatchJobsParityTest.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    private static function transformNative(int $operation, string $input): ?string
    {
        if (strlen($input) > 67_108_864) {
            return null;
        }

        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave27-account-mail-dispatch-jobs', self::FFI_CDEF, ($path = getenv('NNTMUX_PIPELINE_FFI_LIB')) === false || $path === '' ? null : $path) ?? false;
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
            $written = $ffi->nntmux_w27_account_mail_dispatch_jobs($operation, $input, strlen($input), $buffer, $capacity);
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
                $written = $ffi->nntmux_w27_account_mail_dispatch_jobs($operation, $input, strlen($input), $buffer, $capacity);
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

    /** @return array{recipient: ?string, resetLink: string, eligible: bool} */
    public static function normalizeDispatch(?string $recipient, string $resetLink): array
    {
        return self::usesRustFfi() ? self::normalizeDispatchNative($recipient, $resetLink) : self::normalizeDispatchPhp($recipient, $resetLink);
    }

    /** @return array{recipient: ?string, resetLink: string, eligible: bool} */
    public static function normalizeDispatchPhp(?string $recipient, string $resetLink): array
    {
        // Legacy jobs always dispatch, including null/empty recipients; Mail owns validation.
        return ['recipient' => $recipient, 'resetLink' => $resetLink, 'eligible' => true];
    }

    /** @return array{recipient: ?string, resetLink: string, eligible: bool} */
    public static function normalizeDispatchNative(?string $recipient, string $resetLink): array
    {
        $fields = self::decodeFrames(self::transformNative(3, self::encodeFrames([$recipient, $resetLink])), 3);
        if ($fields === null || $fields[2] !== '1' || $fields[1] === null) {
            return self::normalizeDispatchPhp($recipient, $resetLink);
        }

        return ['recipient' => $fields[0], 'resetLink' => $fields[1], 'eligible' => true];
    }

    public static function normalizeRecipient(?string $recipient): ?string
    {
        return self::usesRustFfi() ? self::normalizeRecipientNative($recipient) : self::normalizeRecipientPhp($recipient);
    }

    public static function normalizeRecipientPhp(?string $recipient): ?string
    {
        return $recipient;
    }

    public static function normalizeRecipientNative(?string $recipient): ?string
    {
        return self::normalizeDispatchNative($recipient, '')['recipient'];
    }

    public static function evaluateEligibility(?string $recipient, string $resetLink): bool
    {
        return self::usesRustFfi() ? self::evaluateEligibilityNative($recipient, $resetLink) : self::evaluateEligibilityPhp($recipient, $resetLink);
    }

    public static function evaluateEligibilityPhp(?string $recipient, string $resetLink): bool
    {
        return self::normalizeDispatchPhp($recipient, $resetLink)['eligible'];
    }

    public static function evaluateEligibilityNative(?string $recipient, string $resetLink): bool
    {
        return self::normalizeDispatchNative($recipient, $resetLink)['eligible'];
    }

    public static function normalizeResetLink(string $resetLink): string
    {
        return self::usesRustFfi() ? self::normalizeResetLinkNative($resetLink) : self::normalizeResetLinkPhp($resetLink);
    }

    public static function normalizeResetLinkPhp(string $resetLink): string
    {
        return $resetLink;
    }

    public static function normalizeResetLinkNative(string $resetLink): string
    {
        return self::normalizeDispatchNative(null, $resetLink)['resetLink'];
    }

    private string $resetLink;

    private User $user;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, string $resetLink)
    {
        $this->user = $user;
        $this->resetLink = $resetLink;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $dispatch = self::normalizeDispatch($this->user->email, $this->resetLink);
        if ($dispatch['eligible']) {
            Mail::to($dispatch['recipient'])->send(new ForgottenPassword($dispatch['resetLink']));
        }
    }
}
