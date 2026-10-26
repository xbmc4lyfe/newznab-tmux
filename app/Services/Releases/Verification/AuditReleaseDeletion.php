<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Committed audit deletions retain bounded artifact cleanup work until it succeeds. */
final class AuditReleaseDeletion
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w19_verification_prereqs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Enable only after paired common/uncommon mean and p99 all reach 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -(64 * 1024 * 1024)) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
                $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 2 || $written > $capacity || $written > 64 * 1024 * 1024) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    public function __construct(private NzbService $nzb, private ReleaseImageService $images) {}

    /** Called inside the transaction that marks this audit terminal and advances its cursor. */
    public function commit(Release $release, int $scratchId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Audit deletion requires a database transaction.');
        }
        $current = Release::query()->whereKey($release->id)->where('guid', $release->guid)->lockForUpdate()->first();
        // The verdict holds only for the generation audited: a partial upgraded (or fenced for late articles)
        // meanwhile has another NZB.
        $generation = ['late_article_candidate_id', 'articles_found', 'articles_expected', 'files_found', 'files_expected', 'size'];
        if ($current === null || array_any($generation, static fn (string $column): bool => $current->getAttribute($column) != $release->getAttribute($column))) {
            throw new RuntimeException('Audited release changed before deletion.');
        }
        if (DatabaseNzbStore::enabled() && ! DB::table('release_nzb_contents')->where('guid', $current->guid)->lockForUpdate()->exists()) {
            throw new RuntimeException('Unimported database NZB cannot qualify a release for audit deletion.');
        }
        $receipt = (new NzbSourceGuard($this->nzb))->captureDeletionAnchor($current);
        $paths = [$receipt['path'], ...$this->images->releaseFiles($release->guid)];
        $updated = DB::table('release_verification_scratch')->where('id', $scratchId)->where('audit_release_id', $release->id)
            ->where('terminal', false)->where('audit_verdict', 'unrepairable')->update([
                'delete_guid' => $release->guid,
                'delete_paths' => json_encode(self::inventoryPayload($receipt, $paths), JSON_THROW_ON_ERROR),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Proven audit deletion state is unavailable.');
        }
        // Match deleteSingle's mass-delete semantics and database cascades; filesystem observers must not run before commit.
        Release::query()->whereKey($release->id)->where('guid', $release->guid)->delete();
    }

    /** Invoked under worker.lock only for a durable terminal audit row. */
    public function cleanup(object $entry): void
    {
        if (! self::isTerminalEligible($entry->terminal, $entry->audit_release_id, $entry->audit_verdict)) {
            throw new RuntimeException('Audit deletion is not committed.');
        }
        if (Release::query()->whereKey($entry->audit_release_id)->exists()
            || Release::query()->where('guid', $entry->delete_guid)->exists()) {
            throw new RuntimeException('Audit cleanup cannot remove a current release.');
        }
        $inventory = json_decode((string) $entry->delete_paths, true, flags: JSON_THROW_ON_ERROR);
        $reason = self::inventoryReason($inventory, (string) $entry->delete_guid);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
        $paths = $inventory['paths'];
        $receipt = $inventory['nzb_receipt'];
        $nzbPath = $paths[0];
        $nzbParent = dirname($nzbPath);
        if (($receipt['kind'] ?? null) !== 'database' && file_exists($nzbParent) && (! is_dir($nzbParent) || ! is_readable($nzbParent) || ! is_executable($nzbParent))) {
            throw new RuntimeException('Audit artifact directory is inaccessible.');
        }
        if (! (new NzbSourceGuard($this->nzb))->deleteAnchoredSource($receipt)) {
            throw new RuntimeException('Audit NZB artifact deletion will retry.');
        }
        foreach (array_unique(array_slice($paths, 1)) as $path) {
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Audit artifact path is invalid.');
            }
            $parent = dirname($path);
            if (file_exists($parent) && (! is_dir($parent) || ! is_readable($parent) || ! is_executable($parent))) {
                throw new RuntimeException('Audit artifact directory is inaccessible.');
            }
            if ((file_exists($path) || is_link($path)) && ! File::delete($path)) {
                throw new RuntimeException('Audit artifact deletion will retry.');
            }
        }
        Search::deleteRelease((int) $entry->audit_release_id);
    }

    /**
     * @param  array<array-key, mixed>  $receipt
     * @param  array<array-key, mixed>  $paths
     * @return array{version: int, nzb_receipt: array<array-key, mixed>, paths: array<array-key, mixed>}
     */
    public static function inventoryPayload(array $receipt, array $paths): array
    {
        return self::$useRustFfi ? (self::inventoryPayloadNative($receipt, $paths) ?? self::inventoryPayloadPhp($receipt, $paths)) : self::inventoryPayloadPhp($receipt, $paths);
    }

    /**
     * @param  array<array-key, mixed>  $receipt
     * @param  array<array-key, mixed>  $paths
     * @return array{version: int, nzb_receipt: array<array-key, mixed>, paths: array<array-key, mixed>}
     */
    public static function inventoryPayloadPhp(array $receipt, array $paths): array
    {
        return ['version' => 1, 'nzb_receipt' => $receipt, 'paths' => $paths];
    }

    /**
     * @param  array<array-key, mixed>  $receipt
     * @param  array<array-key, mixed>  $paths
     * @return array{version: int, nzb_receipt: array<array-key, mixed>, paths: array<array-key, mixed>}|null
     */
    public static function inventoryPayloadNative(array $receipt, array $paths): ?array
    {
        $result = self::computeNative(40, [$receipt, $paths])['value'] ?? null;

        return is_array($result) ? $result : null;
    }

    public static function inventoryReason(mixed $inventory, string $guid): ?string
    {
        return self::$useRustFfi ? (self::inventoryReasonNative($inventory, $guid) ?? self::inventoryReasonPhp($inventory, $guid)) : self::inventoryReasonPhp($inventory, $guid);
    }

    public static function inventoryReasonPhp(mixed $inventory, string $guid): ?string
    {
        if (is_array($inventory) && array_is_list($inventory)) {
            return 'Legacy audit artifact ownership is unqualified; retaining it for review.';
        }
        if (! is_array($inventory) || ($inventory['version'] ?? null) !== 1 || ! is_array($inventory['nzb_receipt'] ?? null)
            || ! is_array($inventory['paths'] ?? null) || ! array_is_list($inventory['paths']) || count($inventory['paths']) !== 9) {
            return 'Audit artifact inventory is unavailable.';
        }
        $paths = $inventory['paths'];
        $receipt = $inventory['nzb_receipt'];
        if (($receipt['guid'] ?? null) !== $guid || ($receipt['path'] ?? null) !== $paths[0]) {
            return 'Audit NZB receipt does not match its retained inventory.';
        }
        if (! is_string($paths[0]) || $paths[0] === '') {
            return 'Audit NZB artifact path is invalid.';
        }

        return null;
    }

    public static function inventoryReasonNative(mixed $inventory, string $guid): ?string
    {
        $result = self::computeNative(41, [$inventory, $guid])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    public static function isTerminalEligible(mixed $terminal, mixed $releaseId, mixed $verdict): bool
    {
        return self::$useRustFfi ? (self::isTerminalEligibleNative($terminal, $releaseId, $verdict) ?? self::isTerminalEligiblePhp($terminal, $releaseId, $verdict)) : self::isTerminalEligiblePhp($terminal, $releaseId, $verdict);
    }

    public static function isTerminalEligiblePhp(mixed $terminal, mixed $releaseId, mixed $verdict): bool
    {
        return (bool) $terminal && $releaseId !== null && $verdict === 'unrepairable';
    }

    public static function isTerminalEligibleNative(mixed $terminal, mixed $releaseId, mixed $verdict): ?bool
    {
        $result = self::computeNative(42, [$terminal, $releaseId, $verdict])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    public const string W57_FFI_CDEF = 'int64_t nntmux_w575_audit_record_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W57.5 parity test's QUALIFICATION). */
    private const bool W57_RUST_FFI_DEFAULT = true;

    /**
     * Wave 57.5 routing and FFI cache, kept in function-local statics (readonly classes cannot declare static properties). Without $op it reports
     * the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[],
     * capacity]` with room for at least $val words, or null without the library.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w57_5', self::W57_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W57_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW57RustFfi(): bool
    {
        return self::w57RustFfiState() === true;
    }

    /**
     * Runs $callback with auditRecordPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 57.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w57Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w57RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w575_audit_record_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Batch validation of `guid\tverdict\tcaller\tpath...` deletion audit records. Four words per record:
     * `[verdict, caller, paths, flags]`. Verdict: 0 empty, 1 `unrepairable`, 2 `repairable`, 3
     * `inconclusive`, 4 other. Caller: 0 empty, 1 `commit`, 2 `cleanup`, 3 `audit`, 4 other. Flags: 1 guid
     * is not 40 lowercase hex bytes, 2 empty path, 4 relative path, 8 `..` segment, 16 duplicate paths, 32
     * first (NZB) path lacks the guid, 64 not exactly nine paths, 128 path with a control byte.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function auditRecordPlanBatch(array $records): array
    {
        return self::usesW57RustFfi() ? self::auditRecordPlanBatchNative($records) : self::auditRecordPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function auditRecordPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $guid = $fields[0];
            $verdict = match ($fields[1] ?? '') {
                '' => 0,
                'unrepairable' => 1,
                'repairable' => 2,
                'inconclusive' => 3,
                default => 4,
            };
            $caller = match ($fields[2] ?? '') {
                '' => 0,
                'commit' => 1,
                'cleanup' => 2,
                'audit' => 3,
                default => 4,
            };
            $paths = \array_slice($fields, 3);
            $flags = \strlen($guid) === 40 && strspn($guid, '0123456789abcdef') === 40 ? 0 : 1;
            foreach ($paths as $path) {
                if ($path === '') {
                    $flags |= 2;

                    continue;
                }
                if ($path[0] !== '/') {
                    $flags |= 4;
                }
                if (\in_array('..', explode('/', $path), true)) {
                    $flags |= 8;
                }
                if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
                    $flags |= 128;
                }
            }
            if ($paths !== [] && ($guid === '' || ! str_contains($paths[0], $guid))) {
                $flags |= 32;
            }
            $total = \count($paths);
            if ($total !== 9) {
                $flags |= 64;
            }
            if ($total > 1 && \count(array_unique($paths)) !== $total) {
                $flags |= 16;
            }
            array_push($out, $verdict, $caller, $total, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function auditRecordPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w57Words($count, implode("\0", $records), 4 * $count) ?? self::auditRecordPlanBatchPhp($records);
    }
}
