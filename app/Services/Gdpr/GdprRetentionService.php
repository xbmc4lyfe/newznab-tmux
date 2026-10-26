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
use InvalidArgumentException;
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

    // ========================================================================
    // WAVE 60.4 BATCH HELPER
    // ========================================================================

    /** Disabled: uncommon p99 reached only 1.33x in the minimum of four paired runs (common mean 1.86x, common p99 1.58x, uncommon mean 1.60x), since the integer arithmetic is cheap in PHP while validation, packing and decoding stay fixed; see RustGdprAuditInventoryErasureExportRetentionServiceW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = false;

    private const string W60_FFI_CDEF = 'ssize_t nntmux_w604_gdpr_retention_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per retentionPlanRowBatch() record. */
    public const int W60_ROW_WORDS = 5;

    /** int64 summary words after the retentionPlanRowBatch() records (none for an empty batch). */
    public const int W60_TRAILER_WORDS = 7;

    /** Upper clamp for epoch-second timestamps. */
    private const int W60_TIME_MAX = 1 << 40;

    /** Record type => [code, retention window in days (0 retained indefinitely)]. */
    private const array W60_RECORD_TYPES = [
        'export_file' => [1, 7],
        'gdpr_request' => [2, 0],
        'payment' => [3, 3650],
        'audit_log' => [4, 2190],
        'user_activity' => [5, 365],
        'session' => [6, 30],
    ];

    private const string W60_DISK_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /**
     * Retention plan rows for [record type, disk, created at, expires at (0 none), now, grace
     * days, purge batch size] items (times are epoch seconds clamped to 0..2^40, grace to 0..365
     * days, batch sizes <= 0 to 100 and above 1000 to 1000).
     *
     * Disks resolve as resolveExportPurgeTargetPhp() does ('' and '0' default to local) and are
     * classed 0 local, 1 s3, 2 other `[A-Za-z0-9_-]{1,64}`, 3 invalid. The cutoff is the expiry
     * or created + window days; purge at is cutoff + grace. Status: 4 unknown type, 5 invalid
     * creation time (0 or more than a day ahead of now), 3 retained indefinitely (no window and
     * no expiry), else 0 retain (now < cutoff), 1 in grace (now < purge at) or 2 purge. Purge
     * rows fill chunks of their row's batch size in order; the last partial chunk counts.
     *
     * Rows [status | type << 4 | disk class << 8 | disk defaulted << 10, cutoff (-1), purge at
     * (-1), seconds until purge (-1 for statuses 3-5), age days], then the trailer [retain,
     * grace, purge, indefinite, invalid, purge chunks, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function retentionPlanRowBatch(array $items): array
    {
        return self::usesW60RustFfi() ? self::retentionPlanRowBatchNative($items) : self::retentionPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function retentionPlanRowBatchPhp(array $items): array
    {
        self::w60Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $fill = 0;
        foreach ($items as [$type, $disk, $created, $expires, $now, $grace, $size]) {
            [$code, $window] = self::W60_RECORD_TYPES[$type] ?? [0, 0];
            $defaulted = $disk === '' || $disk === '0';
            $diskLength = \strlen($disk);
            $diskClass = match (true) {
                $defaulted || $disk === 'local' => 0,
                $disk === 's3' => 1,
                $diskLength <= 64 && strspn($disk, self::W60_DISK_BYTES) === $diskLength => 2,
                default => 3,
            };
            $created = max(0, min(self::W60_TIME_MAX, $created));
            $expires = max(0, min(self::W60_TIME_MAX, $expires));
            $now = max(0, min(self::W60_TIME_MAX, $now));
            $grace = max(0, min(365, $grace)) * 86_400;
            $chunk = $size <= 0 ? 100 : min($size, 1000);
            $cutoff = $purgeAt = -1;
            if ($code === 0) {
                $status = 4;
            } elseif ($created === 0 || $created > $now + 86_400) {
                $status = 5;
            } elseif ($window === 0 && $expires === 0) {
                $status = 3;
            } else {
                $cutoff = $expires > 0 ? $expires : $created + $window * 86_400;
                $purgeAt = $cutoff + $grace;
                $status = $now < $cutoff ? 0 : ($now < $purgeAt ? 1 : 2);
            }
            $until = $status <= 2 ? max(0, $purgeAt - $now) : -1;
            $age = $created <= $now ? intdiv($now - $created, 86_400) : 0;
            $word = $status | $code << 4 | $diskClass << 8 | ($defaulted ? 1024 : 0);
            array_push($out, $word, $cutoff, $purgeAt, $until, $age);
            $summary[min($status, 4)]++;
            if ($status === 2 && ++$fill >= $chunk) {
                $summary[5]++;
                $fill = 0;
            }
            $value = $word ^ $until ^ $age << 24;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        if ($fill > 0) {
            $summary[5]++;
        }
        $summary[6] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function retentionPlanRowBatchNative(array $items): array
    {
        self::w60Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w60Join([array_column($items, 0), array_column($items, 1)], $count);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6));

        return self::w60Call($blob, $twin, $ints, $count, $count * self::W60_ROW_WORDS + self::W60_TRAILER_WORDS)
            ?? self::retentionPlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w60Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W60 retention plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 7 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])) {
                throw new InvalidArgumentException('W60 retention plan batch items must be [string record type, string disk, int created at, int expires at, int now, int grace days, int batch size] lists.');
            }
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W60 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('enabled');
        self::w60RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w60Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w60Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w60RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w60', self::W60_FFI_CDEF) ?? false;
            self::w60RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w60RustFfiState('out', $out);
                self::w60RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W60_FFI_CDEF.)
            $written = $ffi->nntmux_w604_gdpr_retention_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w60RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W60 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
