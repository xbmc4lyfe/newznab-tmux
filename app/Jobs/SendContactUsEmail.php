<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ContactUs;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendContactUsEmail implements ShouldQueue
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

    /** @return array{recipient: string, mailFrom: string, mailBody: string} */
    public static function normalizeDispatch(string $recipient, string $mailFrom, string $mailBody): array
    {
        return self::usesRustFfi() ? self::normalizeDispatchNative($recipient, $mailFrom, $mailBody) : self::normalizeDispatchPhp($recipient, $mailFrom, $mailBody);
    }

    /** @return array{recipient: string, mailFrom: string, mailBody: string} */
    public static function normalizeDispatchPhp(string $recipient, string $mailFrom, string $mailBody): array
    {
        return ['recipient' => $recipient, 'mailFrom' => $mailFrom, 'mailBody' => $mailBody];
    }

    /** @return array{recipient: string, mailFrom: string, mailBody: string} */
    public static function normalizeDispatchNative(string $recipient, string $mailFrom, string $mailBody): array
    {
        $fields = self::decodeFrames(self::transformNative(4, self::encodeFrames([$recipient, $mailFrom, $mailBody])), 3);
        if ($fields === null || in_array(null, $fields, true)) {
            return self::normalizeDispatchPhp($recipient, $mailFrom, $mailBody);
        }

        return ['recipient' => $fields[0], 'mailFrom' => $fields[1], 'mailBody' => $fields[2]];
    }

    private string $email;

    private string $mailTo;

    private string $mailBody;

    /**
     * SendContactUsEmail constructor.
     */
    public function __construct(string $email, string $mailTo, string $mailBody)
    {
        $this->email = $email;
        $this->mailTo = $mailTo;
        $this->mailBody = $mailBody;
    }

    public function handle(): void
    {
        $dispatch = self::normalizeDispatch($this->mailTo, $this->email, $this->mailBody);
        Mail::to($dispatch['recipient'])->send(new ContactUs($dispatch['recipient'], $dispatch['mailFrom'], $dispatch['mailBody']));
    }
}
