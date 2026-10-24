<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate and audit scratch are registered under the worker lock before creation.
 * Unregistered external directories are never discovered or deleted here, and each worker
 * host only measures, cleans or forgets the entries it created.
 */
final class ScratchInventory
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w16_scratch(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Candidate helpers retain PHP defaults until every paired mean/p99 gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialize() can invoke user code; bound cycles and nesting. */
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

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w16_scratch($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function scratchName(int|string|null $auditReleaseId, int|string $candidateId, int|string $version, int|string $id): string
    {
        return self::scratchNameNative($auditReleaseId, $candidateId, $version, $id) ?? self::scratchNamePhp($auditReleaseId, $candidateId, $version, $id);
    }

    public static function scratchNamePhp(int|string|null $auditReleaseId, int|string $candidateId, int|string $version, int|string $id): string
    {
        return $auditReleaseId === null ? $candidateId.'-'.$version : 'audit-'.$auditReleaseId.'-'.$id;
    }

    public static function scratchNameNative(int|string|null $auditReleaseId, int|string $candidateId, int|string $version, int|string $id): ?string
    {
        $result = self::computeNative(0, [$auditReleaseId, $candidateId, $version, $id]);

        return is_string($result) ? $result : null;
    }

    public static function filenameClass(string $filename): string
    {
        return self::filenameClassNative($filename) ?? self::filenameClassPhp($filename);
    }

    public static function filenameClassPhp(string $filename): string
    {
        return preg_match('/^audit-[0-9]+-[0-9]+$/D', $filename) === 1 ? 'audit' : (preg_match('/^[0-9]+-[0-9]+$/D', $filename) === 1 ? 'candidate' : 'unknown');
    }

    public static function filenameClassNative(string $filename): ?string
    {
        $result = self::computeNative(1, [$filename]);

        return is_string($result) ? $result : null;
    }

    public static function expired(int $modified, int $now, int $maxAge): bool
    {
        return self::expiredNative($modified, $now, $maxAge) ?? self::expiredPhp($modified, $now, $maxAge);
    }

    public static function expiredPhp(int $modified, int $now, int $maxAge): bool
    {
        return $now - $modified > max(0, $maxAge);
    }

    public static function expiredNative(int $modified, int $now, int $maxAge): ?bool
    {
        $result = self::computeNative(2, [$modified, $now, $maxAge]);

        return is_bool($result) ? $result : null;
    }

    /**
     * @param  list<array{audit_release_id: ?int, terminal: bool}>  $entries
     * @return array{total: int, candidate: int, audit: int, terminal: int}
     */
    public static function summary(array $entries): array
    {
        return self::summaryNative($entries) ?? self::summaryPhp($entries);
    }

    /**
     * @param  list<array{audit_release_id: ?int, terminal: bool}>  $entries
     * @return array{total: int, candidate: int, audit: int, terminal: int}
     */
    public static function summaryPhp(array $entries): array
    {
        $summary = ['total' => count($entries), 'candidate' => 0, 'audit' => 0, 'terminal' => 0];
        foreach ($entries as $entry) {
            $summary[$entry['audit_release_id'] === null ? 'candidate' : 'audit']++;
            $summary['terminal'] += (int) $entry['terminal'];
        }

        return $summary;
    }

    /**
     * @param  list<array{audit_release_id: ?int, terminal: bool}>  $entries
     * @return array{total: int, candidate: int, audit: int, terminal: int}|null
     */
    public static function summaryNative(array $entries): ?array
    {
        $result = self::computeNative(3, [$entries]);

        return is_array($result) ? $result : null;
    }

    /** The worker host owning scratch created here; the primary host is ''. */
    public static function host(): string
    {
        return (string) config('release_verification.host', '');
    }

    public function register(int $candidateId, int $version): void
    {
        DB::table('release_verification_scratch')->insertOrIgnore(['host' => self::host(), 'candidate_id' => $candidateId, 'version' => $version]);
    }

    /** This host's inventory entry for a candidate version. */
    public function entryId(int $candidateId, int $version): int
    {
        return (int) DB::table('release_verification_scratch')->where('host', self::host())->where('candidate_id', $candidateId)
            ->where('version', $version)->value('id');
    }

    public function registerAudit(int $releaseId): \stdClass
    {
        $active = DB::table('release_verification_scratch')->where('host', self::host())->where('audit_release_id', $releaseId)
            ->where('terminal', false)->first();
        if ($active !== null) {
            return $active;
        }
        $id = DB::table('release_verification_scratch')->insertGetId(['host' => self::host(), 'audit_release_id' => $releaseId]);

        return DB::table('release_verification_scratch')->where('id', $id)->sole();
    }

    public function finishAudit(int $id): void
    {
        DB::table('release_verification_scratch')->where('id', $id)->whereNotNull('audit_release_id')->update(['terminal' => true]);
    }

    public function recordAudit(int $id, string $verdict, string $reason): void
    {
        DB::table('release_verification_scratch')->where('id', $id)->whereNotNull('audit_release_id')->update([
            'audit_verdict' => $verdict, 'audit_reason' => mb_strcut($reason, 0, 2000, 'UTF-8'),
        ]);
    }

    public function directoryName(object $entry): string
    {
        return $entry->audit_release_id === null
            ? self::scratchName(null, $entry->candidate_id, $entry->version, 0)
            : self::scratchName($entry->audit_release_id, 0, 0, $entry->id);
    }

    /** @return Collection<int, \stdClass> */
    public function olderVersions(int $candidateId, int $version): Collection
    {
        return DB::table('release_verification_scratch')->where('host', self::host())->where('candidate_id', $candidateId)->where('version', '<', $version)
            ->orderBy('version')->limit(100)->get();
    }

    public function forget(int $id): void
    {
        if (Schema::hasTable('release_verification_scratch_budget')) {
            app(ScratchBudget::class)->forget($id);
        } else {
            DB::table('release_verification_scratch')->where('id', $id)->delete();
        }
    }
}
