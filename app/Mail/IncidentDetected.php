<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\ServiceIncident;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IncidentDetected extends Mailable implements ShouldQueue
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w24_transactional_mail_verify(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // PHP remains the default: the Wave 24.4 paired screen (worst-round mean/p99 of
    // PHP/Rust latency, see RustTransactionalMailVerifyParityTest) measured
    // formatIncidentMailCopy 0.14x/0.14x common, 0.13x/0.12x uncommon.
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

    public function __construct(
        public readonly ServiceIncident $incident,
        public readonly bool $resolved = false,
    ) {
        // Incident notifications take priority over routine user mail so they
        // are routed onto a dedicated high-priority queue (overridable via env).
        $this->onQueue((string) config('mail.brand.incident_queue', 'incidents'));
    }

    public function build(): static
    {
        $site = (string) config('app.name');
        $services = (string) $this->incident->services->pluck('name')->join(', ');
        $copy = self::formatIncidentMailCopy($this->resolved, $this->incident->impact->value, $services);

        return $this->from((string) config('mail.from.address'))
            ->brandedSubject($copy['subject'])
            ->view('emails.incidentDetected')
            ->text('emails.text.incidentDetected')
            ->with([
                'incident' => $this->incident,
                'services' => $services,
                'resolved' => $this->resolved,
                'site' => $site,
                'preheader' => $copy['preheader'],
                'statusUrl' => url('/admin/status'),
            ]);
    }

    /** @return array{status: string, impact: string, subject: string, preheader: string} */
    public static function formatIncidentMailCopy(bool $resolved, string $impactValue, string $servicesCsv): array
    {
        return self::usesRustFfi()
            ? self::formatIncidentMailCopyNative($resolved, $impactValue, $servicesCsv)
            : self::formatIncidentMailCopyPhp($resolved, $impactValue, $servicesCsv);
    }

    /** @return array{status: string, impact: string, subject: string, preheader: string} */
    public static function formatIncidentMailCopyPhp(bool $resolved, string $impactValue, string $servicesCsv): array
    {
        $status = $resolved ? 'Resolved' : 'Detected';
        $impact = ucfirst($impactValue);

        return [
            'status' => $status,
            'impact' => $impact,
            'subject' => "Service incident {$status}: {$servicesCsv}",
            'preheader' => $resolved
                ? "{$impact} incident on {$servicesCsv} has been resolved."
                : "{$impact} impact incident detected on {$servicesCsv}.",
        ];
    }

    /** @return array{status: string, impact: string, subject: string, preheader: string} */
    public static function formatIncidentMailCopyNative(bool $resolved, string $impactValue, string $servicesCsv): array
    {
        $fields = self::decodeFrames(self::transformNative(3, self::encodeFrames([$resolved ? '1' : '0', $impactValue, $servicesCsv])), 4);
        if ($fields === null || in_array(null, $fields, true)) {
            return self::formatIncidentMailCopyPhp($resolved, $impactValue, $servicesCsv);
        }

        return ['status' => (string) $fields[0], 'impact' => (string) $fields[1], 'subject' => (string) $fields[2], 'preheader' => (string) $fields[3]];
    }
}
