<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\GdprRequest;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class GdprRequestStatusMail extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w23_gdpr_export_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // PHP remains the default until the native candidate meets the performance gates.
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
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave23-gdpr-export-mail', self::FFI_CDEF) ?? false;
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
            $written = $ffi->nntmux_w23_gdpr_export_mail($operation, $input, strlen($input), $buffer, $capacity);
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
                $written = $ffi->nntmux_w23_gdpr_export_mail($operation, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity || $written > 67_108_864) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public string $site;

    public string $requestType;

    public string $status;

    public string $heading;

    public string $body;

    public string $requester;

    public string $requestedAt;

    public ?string $completedAt;

    public ?string $adminNotes;

    public ?string $actionUrl;

    public ?string $actionText;

    public ?string $preheader;

    private string $siteEmail;

    private string $subjectLine;

    public function __construct(GdprRequest $gdprRequest, string $event, bool $adminCopy = false)
    {
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $this->requestType = self::formatLabel($gdprRequest->type);
        $this->status = self::formatLabel($gdprRequest->status);
        $this->requester = self::formatRequester($gdprRequest->requester_username, $gdprRequest->requester_email);
        $this->requestedAt = $gdprRequest->created_at === null ? 'N/A' : Carbon::parse($gdprRequest->created_at)->format('Y-m-d H:i T');
        $this->completedAt = $gdprRequest->completed_at === null ? null : Carbon::parse($gdprRequest->completed_at)->format('Y-m-d H:i T');
        $this->adminNotes = $gdprRequest->admin_notes;

        [$this->subjectLine, $this->heading, $this->body, $this->actionText, $this->actionUrl] = $this->contentFor($gdprRequest, $event, $adminCopy);
        $this->preheader = $this->body;
    }

    /**
     * @throws \Exception
     */
    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject($this->subjectLine)
            ->markdown('emails.markdown.gdprRequestStatus', [
                'site' => $this->site,
                'requestType' => $this->requestType,
                'status' => $this->status,
                'heading' => $this->heading,
                'body' => $this->body,
                'requester' => $this->requester,
                'requestedAt' => $this->requestedAt,
                'completedAt' => $this->completedAt,
                'adminNotes' => $this->adminNotes,
                'actionUrl' => $this->actionUrl,
                'actionText' => $this->actionText,
                'preheader' => $this->preheader,
            ]);
    }

    public static function formatLabel(string $value): string
    {
        return self::usesRustFfi() ? self::formatLabelNative($value) : self::formatLabelPhp($value);
    }

    public static function formatLabelPhp(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    public static function formatLabelNative(string $value): string
    {
        return self::transformNative(11, self::encodeFrames([$value])) ?? self::formatLabelPhp($value);
    }

    public static function formatRequester(?string $username, ?string $email): string
    {
        return self::usesRustFfi() ? self::formatRequesterNative($username, $email) : self::formatRequesterPhp($username, $email);
    }

    public static function formatRequesterPhp(?string $username, ?string $email): string
    {
        return trim(($username ?: 'Unknown user').' <'.($email ?: 'unknown email').'>');
    }

    public static function formatRequesterNative(?string $username, ?string $email): string
    {
        return self::transformNative(12, self::encodeFrames([$username, $email])) ?? self::formatRequesterPhp($username, $email);
    }

    /** @return array{string, string, string, ?string, ?string} */
    public static function resolveMailContent(string $requestType, string $event, bool $adminCopy, ?string $actionUrl): array
    {
        return self::usesRustFfi()
            ? self::resolveMailContentNative($requestType, $event, $adminCopy, $actionUrl)
            : self::resolveMailContentPhp($requestType, $event, $adminCopy, $actionUrl);
    }

    /** @return array{string, string, string, ?string, ?string} */
    public static function resolveMailContentNative(string $requestType, string $event, bool $adminCopy, ?string $actionUrl): array
    {
        $output = self::transformNative(13, self::encodeFrames([$requestType, $event, $adminCopy ? '1' : '0', $actionUrl]));
        $fields = $output === null ? null : self::decodeContent($output);

        return $fields ?? self::resolveMailContentPhp($requestType, $event, $adminCopy, $actionUrl);
    }

    /** @return array{string, string, string, ?string, ?string} */
    public static function resolveMailContentPhp(string $requestType, string $event, bool $adminCopy, ?string $actionUrl): array
    {
        if ($adminCopy) {
            return [
                'New GDPR '.$requestType.' request',
                'New GDPR request submitted',
                'A GDPR '.$requestType.' request was submitted and may require administrator review.',
                'Review request',
                $actionUrl,
            ];
        }

        return match ($event) {
            'submitted' => [
                'GDPR '.$requestType.' request received',
                'We received your GDPR request',
                'Your GDPR '.$requestType.' request has been received and recorded.',
                'Open Privacy Center',
                $actionUrl,
            ],
            'export_ready' => [
                'Your GDPR data export is ready',
                'Your data export is ready',
                'Your GDPR data export has been generated and is available for download until its listed expiry time.',
                'Download export',
                $actionUrl,
            ],
            'erasure_completed' => [
                'Your GDPR erasure request is complete',
                'Your account erasure is complete',
                'Your GDPR erasure request has been completed. Account data was removed or anonymized, and retained payment/audit records were anonymized or minimized where practical.',
                null,
                null,
            ],
            'rejected' => [
                'Your GDPR request was rejected',
                'Your GDPR request was rejected',
                'Your GDPR '.$requestType.' request was reviewed and rejected. See the notes below for the reason provided by the administrator.',
                'Open Privacy Center',
                $actionUrl,
            ],
            default => [
                'GDPR request update',
                'GDPR request update',
                'There has been an update to your GDPR '.$requestType.' request.',
                'Open Privacy Center',
                $actionUrl,
            ],
        };
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

    /** @return array{string, string, string, ?string, ?string}|null */
    private static function decodeContent(string $output): ?array
    {
        $offset = 0;
        $size = strlen($output);
        $fields = [];
        for ($i = 0; $i < 5; $i++) {
            if ($size - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', $output, $offset);
            if ($header === false || ! is_int($header['length'])) {
                return null;
            }
            $length = $header['length'];
            $offset += 4;
            if ($length === 0xFFFFFFFF) {
                if ($i < 3) {
                    return null;
                }
                $fields[] = null;
            } else {
                if ($length > $size - $offset) {
                    return null;
                }
                $fields[] = substr($output, $offset, $length);
                $offset += $length;
            }
        }
        if ($offset !== $size || ! is_string($fields[0]) || ! is_string($fields[1]) || ! is_string($fields[2])) {
            return null;
        }

        return [$fields[0], $fields[1], $fields[2], $fields[3], $fields[4]];
    }

    /** @return array{string, string, string, ?string, ?string} */
    private function contentFor(GdprRequest $gdprRequest, string $event, bool $adminCopy): array
    {
        // Resolve only the route selected by the original branch; erasure has no action.
        $actionUrl = $adminCopy
            ? $this->safeRoute('admin.gdpr-requests.show', [$gdprRequest])
            : match ($event) {
                'erasure_completed' => null,
                'export_ready' => $gdprRequest->isDownloadableExport()
                    ? $this->safeRoute('privacy-center.export.download', [$gdprRequest])
                    : $this->safeRoute('privacy-center.index'),
                default => $this->safeRoute('privacy-center.index'),
            };

        return self::resolveMailContent($this->requestType, $event, $adminCopy, $actionUrl);
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    private function safeRoute(string $name, array $parameters = []): ?string
    {
        try {
            return route($name, $parameters);
        } catch (Throwable) {
            return null;
        }
    }
}
