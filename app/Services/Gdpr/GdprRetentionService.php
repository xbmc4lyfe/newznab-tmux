<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\GdprRequest;
use App\Support\RustPipelineLibrary;
use DateTimeInterface;
use FFI;
use FFI\CData;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W49 parity test). */
    private const W49_RUST_FFI_DEFAULT = true;

    private const string W49_FFI_CDEF = 'ssize_t nntmux_w49_gdpr_retention_purge_batch(const char *disks, size_t disks_len, const char *paths, size_t paths_len, const char *expires, size_t expires_len, const char *null_expires, size_t null_len, size_t count, int64_t now, int64_t *out, size_t out_cap);';

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49 batch routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /** Paths formatExportPath() produces; group 1 is the user id. */
    public const string W49_EXPORT_PATH = '#^gdpr/exports/user-([1-9][0-9]{0,17})-[0-9]{14}-[A-Za-z0-9]{12}\.json$#D';

    /**
     * Batch purge evaluation for export requests, mirroring resolveExportPurgeTargetPhp() and
     * checking each path against W49_EXPORT_PATH. Two words per request: `shouldPurge |
     * diskDefaulted << 1 | diskIsLocal << 2 | hasPath << 3 | hasExpiry << 4 | canonicalPath << 5
     * | safePurge << 6 | dotDotSegment << 7 | pathLength << 8 | diskLength << 24`, then the
     * user id from a canonical path or -1. Lengths saturate at 0xFFFF and 255.
     *
     * @param  list<?string>  $disks
     * @param  list<?string>  $paths
     * @param  list<?int>  $expiresAt  Expiry epochs, parallel to $paths.
     * @return list<int>
     */
    public static function evaluatePurgeBatch(array $disks, array $paths, array $expiresAt, int $nowEpoch): array
    {
        return self::usesW49RustFfi()
            ? self::evaluatePurgeBatchNative($disks, $paths, $expiresAt, $nowEpoch)
            : self::evaluatePurgeBatchPhp($disks, $paths, $expiresAt, $nowEpoch);
    }

    /**
     * @param  list<?string>  $disks
     * @param  list<?string>  $paths
     * @param  list<?int>  $expiresAt
     * @return list<int>
     */
    public static function evaluatePurgeBatchPhp(array $disks, array $paths, array $expiresAt, int $nowEpoch): array
    {
        $out = [];
        foreach ($paths as $i => $path) {
            $disk = $disks[$i] ?? null;
            $expires = $expiresAt[$i] ?? null;
            $path ??= '';
            $defaulted = $disk === null || $disk === '' || $disk === '0';
            $resolved = $defaulted ? 'local' : $disk;
            $purge = $path !== '' && $expires !== null && $expires < $nowEpoch;
            $canonical = preg_match(self::W49_EXPORT_PATH, $path, $match) === 1;
            $out[] = (int) $purge | (int) $defaulted << 1 | (int) ($resolved === 'local') << 2 | (int) ($path !== '') << 3
                | (int) ($expires !== null) << 4 | (int) $canonical << 5 | (int) ($purge && $canonical) << 6
                | (int) str_contains('/'.$path.'/', '/../') << 7 | min(0xFFFF, \strlen($path)) << 8 | min(255, \strlen($resolved)) << 24;
            $out[] = $canonical ? (int) $match[1] : -1;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a disk or path holds a NUL byte.
     *
     * @param  list<?string>  $disks
     * @param  list<?string>  $paths
     * @param  list<?int>  $expiresAt
     * @return list<int>
     */
    public static function evaluatePurgeBatchNative(array $disks, array $paths, array $expiresAt, int $nowEpoch): array
    {
        $count = \count($paths);
        $ffi = $count === 0 ? null : self::w49Ffi();
        $packedDisks = implode("\0", $disks);
        $packedPaths = implode("\0", $paths);
        if ($ffi === null || \count($disks) !== $count || \count($expiresAt) !== $count) {
            return self::evaluatePurgeBatchPhp($disks, $paths, $expiresAt, $nowEpoch);
        }
        $epochs = pack('P*', ...$expiresAt);
        $nulls = pack('V*', ...array_keys($expiresAt, null, true));
        try {
            $words = self::w49Words($ffi, $count * 2, static fn (CData $out, int $cap): mixed => $ffi->nntmux_w49_gdpr_retention_purge_batch( // @phpstan-ignore method.notFound
                $packedDisks, \strlen($packedDisks), $packedPaths, \strlen($packedPaths), $epochs, \strlen($epochs),
                $nulls, \strlen($nulls), $count, $nowEpoch, $out, $cap));
        } catch (Throwable) {
            $words = null;
        }

        return $words ?? self::evaluatePurgeBatchPhp($disks, $paths, $expiresAt, $nowEpoch);
    }

    private static function w49Ffi(): ?FFI
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('gdpr_w49', self::W49_FFI_CDEF) ?? false;

        return self::$w49Ffi === false ? null : self::$w49Ffi;
    }

    /**
     * Copies `$words` output words out of a reusable `int64_t` buffer after `$call` filled it.
     *
     * @param  callable(CData, int): mixed  $call  Receives the buffer and its capacity in words.
     * @return list<int>|null
     */
    private static function w49Words(FFI $ffi, int $words, callable $call): ?array
    {
        $buffer = self::$w49Out;
        if ($buffer === null || self::$w49OutCap < $words) {
            $cap = max(512, $words * 2);
            $buffer = self::$w49Out = $ffi->new("int64_t[{$cap}]");
            self::$w49OutCap = $cap;
        }
        if ($call($buffer, self::$w49OutCap) !== $words) {
            return null;
        }
        $decoded = unpack('P*', FFI::string($buffer, $words * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }
}
