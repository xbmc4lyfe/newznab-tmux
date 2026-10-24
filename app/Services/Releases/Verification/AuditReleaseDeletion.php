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
}
