<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Adds a configurable, site-wide subject prefix (default "[App Name] ") to
 * mailables, so transactional emails read consistently in users' inboxes.
 *
 * Concrete mailables call `$this->brandedSubject('Welcome')` instead of
 * `$this->subject('Welcome')`. The prefix can be overridden globally via
 * `MAIL_SUBJECT_PREFIX` (see `config/mail.php` -> `brand.subject_prefix`)
 * or disabled per call by passing `prefix: false`.
 */
trait HasBrandedSubject
{
    public const string BRANDED_SUBJECT_FFI_CDEF = 'int32_t nntmux_w26_email_dispatch_jobs_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Wave 26.1 paired common/p99 measurements miss 1.5x; retain PHP by default.
    // Raw protocol and measured ratios: RustEmailDispatchJobsMailParityTest.
    private static bool $useBrandedSubjectRustFfi = false;

    private static FFI|false|null $brandedSubjectFfi = null;

    public static function usesBrandedSubjectRustFfi(): bool
    {
        return self::$useBrandedSubjectRustFfi;
    }

    public static function setUseBrandedSubjectRustFfi(bool $useRustFfi): void
    {
        self::$useBrandedSubjectRustFfi = $useRustFfi;
    }

    private static function brandedSubjectTransformNative(int $operation, string $input): ?string
    {
        if (strlen($input) > 67_108_864) {
            return null;
        }

        try {
            self::$brandedSubjectFfi ??= RustPipelineLibrary::resolveDomain('wave26-email-dispatch-jobs-mail', self::BRANDED_SUBJECT_FFI_CDEF, ($path = getenv('NNTMUX_PIPELINE_FFI_LIB')) === false || $path === '' ? null : $path) ?? false;
            if (self::$brandedSubjectFfi === false) {
                return null;
            }
            $brandedSubjectFfi = self::$brandedSubjectFfi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($brandedSubjectFfi, 4096, $capacity);
            if ($buffer === null) {
                return null;
            }
            // A PHP empty string supplies a non-null pointer even for zero input length.
            // @phpstan-ignore method.notFound
            $written = $brandedSubjectFfi->nntmux_w26_email_dispatch_jobs_mail($operation, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $required = -$written;
                if ($required <= $capacity || $required > 67_108_864) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($brandedSubjectFfi, $required, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $brandedSubjectFfi->nntmux_w26_email_dispatch_jobs_mail($operation, $input, strlen($input), $buffer, $capacity);
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
    private static function brandedSubjectEncodeFrames(array $values): string
    {
        $input = '';
        foreach ($values as $value) {
            $input .= $value === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /** @return list<?string>|null */
    private static function brandedSubjectDecodeFrames(?string $output, int $count): ?array
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

    public static function formatBrandedSubject(string $subject, string $prefixValue, bool $prefix = true): string
    {
        return self::usesBrandedSubjectRustFfi() ? self::formatBrandedSubjectNative($subject, $prefixValue, $prefix) : self::formatBrandedSubjectPhp($subject, $prefixValue, $prefix);
    }

    public static function formatBrandedSubjectPhp(string $subject, string $prefixValue, bool $prefix = true): string
    {
        return ($prefix ? $prefixValue : '').$subject;
    }

    public static function formatBrandedSubjectNative(string $subject, string $prefixValue, bool $prefix = true): string
    {
        $fields = self::brandedSubjectDecodeFrames(self::brandedSubjectTransformNative(1, self::brandedSubjectEncodeFrames([$subject, $prefixValue, $prefix ? '1' : '0'])), 1);

        return $fields !== null && $fields[0] !== null ? $fields[0] : self::formatBrandedSubjectPhp($subject, $prefixValue, $prefix);
    }

    protected function brandedSubject(string $subject, bool $prefix = true): static
    {
        $prefixValue = $prefix
            ? (string) config('mail.brand.subject_prefix', '')
            : '';

        return $this->subject(self::formatBrandedSubject($subject, $prefixValue, $prefix));
    }
}
