<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AccountWillExpire;
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

class SendAccountWillExpireEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w26_email_dispatch_jobs_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Wave 26.1 paired common/p99 measurements miss 1.5x; retain PHP by default.
    // Raw protocol and measured ratios: RustEmailDispatchJobsMailParityTest.
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
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave26-email-dispatch-jobs-mail', self::FFI_CDEF, ($path = getenv('NNTMUX_PIPELINE_FFI_LIB')) === false || $path === '' ? null : $path) ?? false;
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
            $written = $ffi->nntmux_w26_email_dispatch_jobs_mail($operation, $input, strlen($input), $buffer, $capacity);
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
                $written = $ffi->nntmux_w26_email_dispatch_jobs_mail($operation, $input, strlen($input), $buffer, $capacity);
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

    /** @return array{recipient: ?string, days: int} */
    public static function normalizeDispatch(?string $recipient, int $days): array
    {
        return self::usesRustFfi() ? self::normalizeDispatchNative($recipient, $days) : self::normalizeDispatchPhp($recipient, $days);
    }

    /** @return array{recipient: ?string, days: int} */
    public static function normalizeDispatchPhp(?string $recipient, int $days): array
    {
        return ['recipient' => $recipient, 'days' => $days];
    }

    /** @return array{recipient: ?string, days: int} */
    public static function normalizeDispatchNative(?string $recipient, int $days): array
    {
        $fields = self::decodeFrames(self::transformNative(3, self::encodeFrames([$recipient, (string) $days])), 2);
        if ($fields === null || $fields[1] !== (string) $days) {
            return self::normalizeDispatchPhp($recipient, $days);
        }

        return ['recipient' => $fields[0], 'days' => $days];
    }

    private int $days;

    private User $user;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, int $days)
    {
        $this->user = $user;
        $this->days = $days;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $dispatch = self::normalizeDispatch($this->user->email, $this->days);
        Mail::to($dispatch['recipient'])->send(new AccountWillExpire($this->user, $dispatch['days']));
    }
}
