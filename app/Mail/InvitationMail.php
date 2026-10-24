<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\Invitation;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

class InvitationMail extends Mailable implements ShouldQueue
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w24_transactional_mail_verify(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // PHP remains the default: the Wave 24.4 paired screen (worst-round mean/p99 of
    // PHP/Rust latency, see RustTransactionalMailVerifyParityTest) measured
    // formatInvitationSubject 0.19x/0.12x common, 0.18x/0.16x uncommon;
    // resolveInvitationMailContext 0.13x/0.12x common, 0.14x/0.11x uncommon.
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

    public Invitation $invitation;

    public function __construct(Invitation $invitation)
    {
        $this->invitation = $invitation;
        $this->onQueue((string) config('mail.brand.queue', 'emails'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: (string) config('mail.from.address'),
            subject: self::formatInvitationSubject((string) config('mail.brand.subject_prefix', ''), (string) config('app.name')),
        );
    }

    public function content(): Content
    {
        $siteName = (string) config('app.name');
        $inviterUsername = $this->invitation->invitedBy->username ?? null;
        $context = self::resolveInvitationMailContext($inviterUsername === null ? null : (string) $inviterUsername, $siteName);

        return new Content(
            view: 'emails.invitation',
            text: 'emails.text.invitation',
            with: [
                'invitation' => $this->invitation,
                'invitedBy' => $this->invitation->invitedBy,
                'invitedByName' => $context['invitedByName'],
                'registerUrl' => route('register', ['token' => $this->invitation->token]),
                'expiresAt' => $this->invitation->expires_at,
                'siteName' => $siteName,
                'preheader' => $context['preheader'],
            ],
        );
    }

    public static function formatInvitationSubject(string $prefix, string $siteName): string
    {
        return self::usesRustFfi() ? self::formatInvitationSubjectNative($prefix, $siteName) : self::formatInvitationSubjectPhp($prefix, $siteName);
    }

    public static function formatInvitationSubjectPhp(string $prefix, string $siteName): string
    {
        return $prefix.'You\'re invited to join '.$siteName;
    }

    public static function formatInvitationSubjectNative(string $prefix, string $siteName): string
    {
        return self::transformNative(1, self::encodeFrames([$prefix, $siteName])) ?? self::formatInvitationSubjectPhp($prefix, $siteName);
    }

    /** @return array{invitedByName: string, preheader: string} */
    public static function resolveInvitationMailContext(?string $inviterUsername, string $siteName): array
    {
        return self::usesRustFfi()
            ? self::resolveInvitationMailContextNative($inviterUsername, $siteName)
            : self::resolveInvitationMailContextPhp($inviterUsername, $siteName);
    }

    /** @return array{invitedByName: string, preheader: string} */
    public static function resolveInvitationMailContextPhp(?string $inviterUsername, string $siteName): array
    {
        $invitedByName = $inviterUsername ?? 'A member';

        return ['invitedByName' => $invitedByName, 'preheader' => "{$invitedByName} has invited you to join {$siteName}."];
    }

    /** @return array{invitedByName: string, preheader: string} */
    public static function resolveInvitationMailContextNative(?string $inviterUsername, string $siteName): array
    {
        $fields = self::decodeFrames(self::transformNative(2, self::encodeFrames([$inviterUsername, $siteName])), 2);
        if ($fields === null || $fields[0] === null || $fields[1] === null) {
            return self::resolveInvitationMailContextPhp($inviterUsername, $siteName);
        }

        return ['invitedByName' => $fields[0], 'preheader' => $fields[1]];
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
