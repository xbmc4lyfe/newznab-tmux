<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AccountDeleted extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w25_account_auth_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Wave 25.1 paired screen: worst-round helper common mean 0.14x, uncommon p99 0.09x.
    // PHP stays default: neither clears 1.5x (RustAccountAuthMailParityTest).
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
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave25-account-auth-mail', self::FFI_CDEF, ($path = getenv('NNTMUX_PIPELINE_FFI_LIB')) === false || $path === '' ? null : $path) ?? false;
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
            $written = $ffi->nntmux_w25_account_auth_mail($operation, $input, strlen($input), $buffer, $capacity);
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
                $written = $ffi->nntmux_w25_account_auth_mail($operation, $input, strlen($input), $buffer, $capacity);
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

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    /** @return array{subject: string, preheader: string, viewData: array{username: string, site: string, preheader: string}} */
    public static function formatMailData(string $username, string $site): array
    {
        return self::usesRustFfi() ? self::formatMailDataNative($username, $site) : self::formatMailDataPhp($username, $site);
    }

    /** @return array{subject: string, preheader: string, viewData: array{username: string, site: string, preheader: string}} */
    public static function formatMailDataPhp(string $username, string $site): array
    {
        $preheader = "User {$username} deleted their {$site} account.";

        return ['subject' => 'User account deleted', 'preheader' => $preheader, 'viewData' => ['username' => $username, 'site' => $site, 'preheader' => $preheader]];
    }

    /** @return array{subject: string, preheader: string, viewData: array{username: string, site: string, preheader: string}} */
    public static function formatMailDataNative(string $username, string $site): array
    {
        $fields = self::decodeFrames(self::transformNative(0, self::encodeFrames([$username, $site])), 4);
        if ($fields === null || $fields[0] === null || $fields[1] === null || $fields[2] === null || $fields[3] === null) {
            return self::formatMailDataPhp($username, $site);
        }

        return ['subject' => $fields[0], 'preheader' => $fields[1], 'viewData' => ['username' => $fields[2], 'site' => $fields[3], 'preheader' => $fields[1]]];
    }

    public function __construct(mixed $user)
    {
        $this->username = (string) $user->username;
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $this->preheader = self::formatMailData($this->username, $this->site)['preheader'];
    }

    /**
     * @throws \Exception
     */
    public function build(): static
    {
        $copy = self::formatMailData($this->username, $this->site);
        $copy['viewData']['preheader'] = $this->preheader;

        return $this->from($this->siteEmail)
            ->brandedSubject($copy['subject'])
            ->markdown('emails.markdown.accountDeleted', $copy['viewData']);
    }
}
