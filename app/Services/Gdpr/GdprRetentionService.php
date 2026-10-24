<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\GdprRequest;
use App\Support\RustPipelineLibrary;
use DateTimeInterface;
use FFI;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GdprRetentionService
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_gdpr_retention_policy_strings_nul(
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_gdpr_retention_resolve_purge_target(
    uint8_t has_disk,
    const char *disk_ptr,
    size_t disk_len,
    uint8_t has_path,
    const char *path_ptr,
    size_t path_len,
    uint8_t has_expires_at,
    int64_t expires_at_epoch,
    int64_t now_epoch,
    int32_t *out_should_purge,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(private readonly GdprDataInventory $inventory = new GdprDataInventory) {}

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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return array<string, mixed>
     */
    public function policy(): array
    {
        return self::buildPolicyPayload($this->inventory->retainedRecords());
    }

    /**
     * @param  array<int|string, mixed>  $retainedRecords
     * @return array<string, mixed>
     */
    public static function buildPolicyPayload(array $retainedRecords): array
    {
        if (self::$useRustFfi) {
            return self::buildPolicyPayloadNative($retainedRecords);
        }

        return self::buildPolicyPayloadPhp($retainedRecords);
    }

    /**
     * @param  array<int|string, mixed>  $retainedRecords
     * @return array<string, mixed>
     */
    public static function buildPolicyPayloadPhp(array $retainedRecords): array
    {
        return [
            'export_files' => '7 days after generation, then safe to purge.',
            'gdpr_requests' => 'Retained as an accountability record.',
            'payments' => 'Retained for accounting, tax, fraud prevention, dispute handling, and legal obligations with direct account identifiers anonymized during erasure where practical.',
            'audit' => 'Retained in anonymized or minimized form for security and administrative accountability.',
            'cookies' => 'Essential cookies/storage only; no marketing or analytics cookies are set by GDPR functionality.',
            'retained_records' => $retainedRecords,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $retainedRecords
     * @return array<string, mixed>
     */
    public static function buildPolicyPayloadNative(array $retainedRecords): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildPolicyPayloadPhp($retainedRecords);
        }

        try {
            $cap = 512;
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_gdpr_retention_policy_strings_nul($out, $cap);
            if (! is_int($written) || $written <= 0) {
                return self::buildPolicyPayloadPhp($retainedRecords);
            }

            $parts = explode("\0", FFI::string($out, $written));
            if (count($parts) < 5) {
                return self::buildPolicyPayloadPhp($retainedRecords);
            }

            return [
                'export_files' => $parts[0],
                'gdpr_requests' => $parts[1],
                'payments' => $parts[2],
                'audit' => $parts[3],
                'cookies' => $parts[4],
                'retained_records' => $retainedRecords,
            ];
        } catch (Throwable) {
            return self::buildPolicyPayloadPhp($retainedRecords);
        }
    }

    /**
     * @return array{should_purge: bool, disk: string, path: string}
     */
    public static function resolveExportPurgeTarget(
        ?string $exportDisk,
        ?string $exportPath,
        ?int $expiresAtEpoch,
        int $nowEpoch
    ): array {
        if (self::$useRustFfi) {
            return self::resolveExportPurgeTargetNative($exportDisk, $exportPath, $expiresAtEpoch, $nowEpoch);
        }

        return self::resolveExportPurgeTargetPhp($exportDisk, $exportPath, $expiresAtEpoch, $nowEpoch);
    }

    /**
     * @return array{should_purge: bool, disk: string, path: string}
     */
    public static function resolveExportPurgeTargetPhp(
        ?string $exportDisk,
        ?string $exportPath,
        ?int $expiresAtEpoch,
        int $nowEpoch
    ): array {
        $disk = ($exportDisk !== null && $exportDisk !== '' && $exportDisk !== '0') ? $exportDisk : 'local';
        $path = $exportPath ?? '';
        $shouldPurge = $path !== '' && $expiresAtEpoch !== null && $expiresAtEpoch < $nowEpoch;

        return [
            'should_purge' => $shouldPurge,
            'disk' => $disk,
            'path' => $path,
        ];
    }

    /**
     * @return array{should_purge: bool, disk: string, path: string}
     */
    public static function resolveExportPurgeTargetNative(
        ?string $exportDisk,
        ?string $exportPath,
        ?int $expiresAtEpoch,
        int $nowEpoch
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveExportPurgeTargetPhp($exportDisk, $exportPath, $expiresAtEpoch, $nowEpoch);
        }

        try {
            $diskStr = $exportDisk ?? '';
            $pathStr = $exportPath ?? '';
            $cap = max(64, strlen($diskStr) + strlen($pathStr) + 32);
            $outShouldPurge = $ffi->new('int32_t[1]');
            $out = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_gdpr_retention_resolve_purge_target(
                $exportDisk !== null ? 1 : 0,
                $diskStr,
                strlen($diskStr),
                $exportPath !== null ? 1 : 0,
                $pathStr,
                strlen($pathStr),
                $expiresAtEpoch !== null ? 1 : 0,
                $expiresAtEpoch ?? 0,
                $nowEpoch,
                $outShouldPurge,
                $out,
                $cap
            );
            $shouldPurgeFlag = $outShouldPurge[0];
            if (! is_int($written) || $written <= 0 || ! is_int($shouldPurgeFlag)) {
                return self::resolveExportPurgeTargetPhp($exportDisk, $exportPath, $expiresAtEpoch, $nowEpoch);
            }

            $parts = explode("\0", FFI::string($out, $written));
            if (count($parts) < 2) {
                return self::resolveExportPurgeTargetPhp($exportDisk, $exportPath, $expiresAtEpoch, $nowEpoch);
            }

            return [
                'should_purge' => $shouldPurgeFlag === 1,
                'disk' => $parts[0],
                'path' => $parts[1],
            ];
        } catch (Throwable) {
            return self::resolveExportPurgeTargetPhp($exportDisk, $exportPath, $expiresAtEpoch, $nowEpoch);
        }
    }

    public function purgeExpiredExports(): int
    {
        if (! Schema::hasTable('gdpr_requests')) {
            return 0;
        }

        $count = 0;

        GdprRequest::query()
            ->where('type', GdprRequest::TYPE_EXPORT)
            ->whereNotNull('export_path')
            ->whereNotNull('export_expires_at')
            ->where('export_expires_at', '<', now())
            ->chunkById(100, function ($requests) use (&$count): void {
                $nowEpoch = now()->getTimestamp();
                foreach ($requests as $request) {
                    $expiresAt = $request->export_expires_at;
                    $expiresAtEpoch = $expiresAt instanceof DateTimeInterface
                        ? $expiresAt->getTimestamp()
                        : (is_string($expiresAt) ? (strtotime($expiresAt) ?: null) : null);
                    $target = self::resolveExportPurgeTarget(
                        is_string($request->export_disk) ? $request->export_disk : null,
                        is_string($request->export_path) ? $request->export_path : null,
                        $expiresAtEpoch,
                        $nowEpoch
                    );
                    Storage::disk($target['disk'])->delete($target['path']);
                    $request->update([
                        'export_path' => null,
                        'export_disk' => null,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
