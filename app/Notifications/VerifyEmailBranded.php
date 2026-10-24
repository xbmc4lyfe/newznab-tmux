<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Branded replacement for Laravel's framework `VerifyEmail` notification.
 *
 * Renders through our themed Markdown mail components (see
 * `resources/views/vendor/mail/*` and `resources/views/emails/markdown/verify.blade.php`)
 * so verification emails match the rest of the transactional email branding
 * instead of falling back to the default Laravel notification look.
 */
class VerifyEmailBranded extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public const string FFI_CDEF = 'int32_t nntmux_w24_transactional_mail_verify(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // PHP remains the default: the Wave 24.4 paired screen (worst-round mean/p99 of
    // PHP/Rust latency, see RustTransactionalMailVerifyParityTest) measured
    // normalizeAppUrl 0.18x/0.17x common, 0.21x/0.21x uncommon;
    // appendVerificationSignature 1.69x/1.33x common, 3.94x/2.96x uncommon (common p99 misses).
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

    public function __construct()
    {
        $this->onQueue((string) config('mail.brand.queue', 'emails'));
    }

    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);
        $site = (string) config('app.name');
        $prefix = (string) config('mail.brand.subject_prefix', '');
        $username = (string) ($notifiable->username ?? $notifiable->name ?? '');

        return (new MailMessage)
            ->subject($prefix.'Verify your email address')
            ->markdown('emails.markdown.verify', [
                'url' => $url,
                'site' => $site,
                'username' => $username,
                'preheader' => "Confirm your email address to activate your {$site} account.",
            ]);
    }

    protected function verificationUrl($notifiable)
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable);
        }

        $origin = $this->normalizedAppUrl();

        if ($origin === null) {
            return parent::verificationUrl($notifiable);
        }

        $parameters = [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
            'expires' => Carbon::now()
                ->addMinutes((int) Config::get('auth.verification.expire', 60))
                ->getTimestamp(),
        ];

        ksort($parameters);

        $unsignedUrl = $origin.URL::route('verification.verify', $parameters, false);
        $key = Config::get('app.key');

        return self::appendVerificationSignature($unsignedUrl, is_array($key) ? (string) $key[0] : (string) $key);
    }

    private function normalizedAppUrl(): ?string
    {
        return self::normalizeAppUrl((string) Config::get('app.url', ''));
    }

    public static function normalizeAppUrl(string $rawUrl): ?string
    {
        return self::usesRustFfi() ? self::normalizeAppUrlNative($rawUrl) : self::normalizeAppUrlPhp($rawUrl);
    }

    public static function normalizeAppUrlPhp(string $rawUrl): ?string
    {
        $url = trim($rawUrl);

        if ($url === '') {
            return null;
        }

        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    public static function normalizeAppUrlNative(string $rawUrl): ?string
    {
        $fields = self::decodeFrames(self::transformNative(5, self::encodeFrames([$rawUrl])), 1);

        return $fields === null ? self::normalizeAppUrlPhp($rawUrl) : $fields[0];
    }

    /** Appends Laravel's `signature` query parameter: HMAC-SHA256 of the unsigned absolute URL. */
    public static function appendVerificationSignature(string $unsignedUrl, string $signingKey): string
    {
        return self::usesRustFfi()
            ? self::appendVerificationSignatureNative($unsignedUrl, $signingKey)
            : self::appendVerificationSignaturePhp($unsignedUrl, $signingKey);
    }

    public static function appendVerificationSignaturePhp(string $unsignedUrl, string $signingKey): string
    {
        $signature = hash_hmac('sha256', $unsignedUrl, $signingKey);

        return $unsignedUrl.(str_contains($unsignedUrl, '?') ? '&' : '?').'signature='.$signature;
    }

    public static function appendVerificationSignatureNative(string $unsignedUrl, string $signingKey): string
    {
        return self::transformNative(6, self::encodeFrames([$unsignedUrl, $signingKey])) ?? self::appendVerificationSignaturePhp($unsignedUrl, $signingKey);
    }
}
