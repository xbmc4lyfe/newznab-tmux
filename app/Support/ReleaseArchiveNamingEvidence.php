<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\SeasonPackDetector;
use App\Services\Nzb\NzbSourceGuard;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Durable archive scope; processing statuses and retained file rows are not completeness proof. */
final class ReleaseArchiveNamingEvidence
{
    public const PROOF_VERSION = 1;

    public const MAX_WITNESSES = 256;

    private const TABLE = 'release_archive_naming_evidence';

    private const SCOPES = ['unknown', 'incomplete', 'complete_single', 'multi_episode'];

    private const int DECODE_NAMES_RUST_MIN_JSON_BYTES = 256;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_archive_witness_bounded_names_nul(const char *input, size_t input_len, size_t count, uint8_t *overflow_out, char *out_buf, size_t out_cap);
        ssize_t nntmux_archive_witness_decode_names_json(const char *json, size_t json_len, char *out_buf, size_t out_cap);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $overflowOut = null;

    private static bool $useRustFfi = true;

    public function __construct(private readonly NzbSourceGuard $sources) {}

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Call inside the same guarded transaction as inventory/status writes. False
     * rejects the entire stale finalization, not only its naming evidence.
     * Authoritative means every archive in the current NZB was fully accounted
     * for, without a listing/download/cap omission; a subset is never sufficient.
     * Evidence omitted means an observed filename was dropped, not merely that
     * a retry downloaded an incomplete listing of previously inspected content.
     *
     * @param  list<string>  $filenames
     * @param  (callable(): void)|null  $persist
     */
    public function recordLocked(object $release, string $sourceDigest, string $scope, array $filenames, bool $authoritativeComplete = false, ?callable $persist = null, bool $evidenceOmitted = false): bool
    {
        $this->validateDigest($sourceDigest);
        if (! in_array($scope, self::SCOPES, true)) {
            throw new RuntimeException('Invalid archive naming scope.');
        }

        return $this->sources->withLockedSource($release, function (?array $source) use ($release, $sourceDigest, $scope, $filenames, $authoritativeComplete, $persist, $evidenceOmitted): bool {
            if ($source === null || ! hash_equals($sourceDigest, $source['digest'])) {
                return false;
            }
            $previous = $this->loadLocked($release);
            [$names, $overflow] = $this->boundedNames($filenames);
            $overflow = $overflow || $evidenceOmitted;
            if (SeasonPackDetector::hasNonSingleEpisodeEvidence($names)) {
                $scope = 'multi_episode';
            } elseif ($scope !== 'multi_episode' && ($overflow || ($scope === 'complete_single' && (! $authoritativeComplete || $names === [])))) {
                $scope = 'incomplete';
            }
            if ($previous !== null && hash_equals($previous->source_sha256, $sourceDigest)) {
                if ($previous->scope === 'multi_episode') {
                    if ($persist !== null) {
                        $persist();
                    }

                    return true;
                }
                // Evidence from disjoint attempts must not erase a witnessed pack.
                $observed = array_values(array_unique([...$this->decodeNames($previous->episode_files), ...$names]));
                if (SeasonPackDetector::hasNonSingleEpisodeEvidence($observed)) {
                    $scope = 'multi_episode';
                } elseif (! $overflow && $previous->scope === 'complete_single' && in_array($scope, ['unknown', 'incomplete'], true)) {
                    if ($persist !== null) {
                        $persist();
                    }

                    return true;
                }
                if (in_array($scope, ['unknown', 'incomplete', 'multi_episode'], true)) {
                    [$names] = $this->boundedNames($observed);
                }
            }
            $this->store($release, $sourceDigest, $scope, $names);
            if ($persist !== null) {
                $persist();
            }

            return true;
        });
    }

    /**
     * Called by the source installer while release and source locks are held;
     * fresh publication inserts its still-uncommitted release before installation.
     * Same decoded bytes preserve stronger evidence, even after recompression.
     */
    public function sourceReplacedLocked(object $release, string $digest): void
    {
        $this->requireTransaction();
        $this->validateDigest($digest);
        $previous = $this->loadLocked($release);
        if ($previous !== null && hash_equals($previous->source_sha256, $digest)) {
            return;
        }
        $this->store($release, $digest, 'unknown', []);
    }

    /**
     * Canonical callers pass their already validated filename evidence. Generic
     * callers must propose a title derived from a current complete witness.
     * This predicate also applies to same-name attachments and previews.
     */
    public function allowsEpisodeLocked(object $release, string $candidate, ?string $filenameEvidence = null): bool
    {
        if (! SeasonPackDetector::isEpisodeName($candidate)) {
            return true;
        }
        $candidateIdentity = SeasonPackDetector::episodeIdentity($candidate);
        if ($candidateIdentity === null) {
            return false;
        }
        $this->requireTransaction();
        // The release lock serializes both observations and generation changes.
        $actual = DB::table('releases')->where('id', $release->id)->where('guid', $release->guid)->lockForUpdate()->first(['id']);
        if ($actual === null) {
            return false;
        }
        $evidence = $this->loadLocked($release);
        if ($evidence === null) {
            $names = DB::table('release_files')->where('releases_id', $release->id)->orderBy('name')
                ->limit(self::MAX_WITNESSES + 1)->sharedLock()->pluck('name')->all();

            // Historical singleton listings cannot be certified retroactively.
            // Preserve legacy compatibility, but reject positively known packs.
            return count($names) <= self::MAX_WITNESSES && ! SeasonPackDetector::hasNonSingleEpisodeEvidence($names);
        }
        $names = $this->decodeNames($evidence->episode_files);
        if ($evidence->scope !== 'complete_single' || SeasonPackDetector::hasNonSingleEpisodeEvidence($names)) {
            return false;
        }

        return $this->sources->withLockedSource($release, function (?array $source) use ($evidence, $names, $candidate, $candidateIdentity, $filenameEvidence): bool {
            if ($source === null || ! hash_equals($evidence->source_sha256, $source['digest'])) {
                return false;
            }
            $cleaner = new FileNameCleaner;
            $query = $filenameEvidence === null ? false : $cleaner->cleanForMatching($filenameEvidence, preserveLeadingNumber: true);
            $resolvedOpaquePayload = false;
            foreach ($names as $name) {
                $identity = SeasonPackDetector::episodeIdentity($name);
                if ($identity !== null && $identity !== $candidateIdentity) {
                    return false;
                }
                if ($identity === null && ! SeasonPackDetector::isAncillaryName($name)) {
                    // A canonical filename mapping resolves one opaque payload,
                    // regardless of its extension, not an unclassified sibling.
                    if ($resolvedOpaquePayload || $query === false || ! $cleaner->matchesPredbFilenameEvidence($name, $query)) {
                        return false;
                    }
                    $resolvedOpaquePayload = true;
                }
            }
            foreach ($names as $name) {
                if ($filenameEvidence !== null) {
                    if ($query !== false && $cleaner->matchesPredbFilenameEvidence($name, $query)) {
                        return true;
                    }
                } else {
                    $title = $cleaner->stripFileSuffixes($cleaner->extractFilenameFromPath($name));
                    if (PredbSearchDocument::exactValue($cleaner->normalizeCandidateTitle($title))
                        === PredbSearchDocument::exactValue($cleaner->normalizeCandidateTitle($candidate))) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    /**
     * The digest of the source last installed for this release in a committed transaction: every
     * installation records it in its own transaction. Null when none ever was.
     */
    public function committedSourceDigestLocked(object $release): ?string
    {
        return $this->loadLocked($release)?->source_sha256;
    }

    private function loadLocked(object $release): ?object
    {
        $this->requireTransaction();
        // Missing tables/columns intentionally raise; they are never legacy absence.
        $row = DB::table(self::TABLE)->where('releases_id', $release->id)->lockForUpdate()->first();
        if ($row === null) {
            return null;
        }
        if ((string) $row->release_guid !== (string) $release->guid
            || (int) $row->proof_version !== self::PROOF_VERSION
            || ! in_array($row->scope, self::SCOPES, true)) {
            throw new RuntimeException('Malformed archive naming evidence.');
        }
        $this->validateDigest($row->source_sha256);
        $this->decodeNames($row->episode_files);

        return $row;
    }

    /** @param list<string> $names */
    private function store(object $release, string $digest, string $scope, array $names): void
    {
        DB::table(self::TABLE)->updateOrInsert(['releases_id' => $release->id], [
            'release_guid' => $release->guid,
            'source_sha256' => $digest,
            'proof_version' => self::PROOF_VERSION,
            'scope' => $scope,
            'episode_files' => json_encode($names, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $names
     * @return array{list<string>, bool}
     */
    private function boundedNames(array $names): array
    {
        // Retain native PHP path in production: marshaling a PHP array<string> via implode("\0")
        // and explode("\0") across FFI achieves 0.51x-0.77x vs in-engine array_unique().
        return self::boundedNamesPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return array{list<string>, bool}|null
     */
    public static function boundedNamesNative(array $names): ?array
    {
        $count = count($names);
        if ($count === 0) {
            return [[], false];
        }
        $ffi = self::ffi();
        if ($ffi === null || self::$overflowOut === null) {
            return null;
        }
        $joined = implode("\0", $names);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        $len = strlen($joined);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(512, $len + 64), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_archive_witness_bounded_names_nul(
                $joined,
                $len,
                $count,
                FFI::addr(self::$overflowOut),
                $scratch,
                $cap,
            );
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_archive_witness_bounded_names_nul(
                    $joined,
                    $len,
                    $count,
                    FFI::addr(self::$overflowOut),
                    $scratch,
                    $cap,
                );
                if (! is_int($written) || $written < 0) {
                    return null;
                }
            }
            // @phpstan-ignore property.notFound
            $overflow = ((int) self::$overflowOut->cdata) === 1;
            $bounded = $written === 0 ? [] : explode("\0", FFI::string($scratch, $written));

            return [$bounded, $overflow];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $names
     * @return array{list<string>, bool}
     */
    private static function boundedNamesPhp(array $names): array
    {
        $bounded = [];
        $overflow = false;
        $bytes = 0;
        foreach (array_unique($names) as $name) {
            if ($name === '' || strlen($name) > 4096 || ! mb_check_encoding($name, 'UTF-8')) {
                $overflow = true;

                continue;
            }
            $bytes += strlen($name);
            if (count($bounded) >= self::MAX_WITNESSES || $bytes > 32768) {
                $overflow = true;

                continue;
            }
            $bounded[] = $name;
        }

        return [$bounded, $overflow];
    }

    /** @return list<string> */
    private function decodeNames(string $json): array
    {
        if (self::$useRustFfi && strlen($json) >= self::DECODE_NAMES_RUST_MIN_JSON_BYTES) {
            $native = self::decodeNamesNative($json);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->decodeNamesPhp($json);
    }

    /**
     * @return list<string>|null
     */
    public static function decodeNamesNative(string $json): ?array
    {
        $len = strlen($json);
        if ($len === 0) {
            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(512, $len + 64), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_archive_witness_decode_names_json($json, $len, $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_archive_witness_decode_names_json($json, $len, $scratch, $cap);
                if (! is_int($written) || $written < 0) {
                    return null;
                }
            }

            return $written === 0 ? [] : explode("\0", FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function decodeNamesPhp(string $json): array
    {
        if (! str_starts_with(ltrim($json), '[')) {
            throw new RuntimeException('Malformed archive filename witnesses.');
        }
        $names = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($names) || ! array_is_list($names)) {
            throw new RuntimeException('Malformed archive filename witnesses.');
        }
        foreach ($names as $name) {
            if (! is_string($name)) {
                throw new RuntimeException('Malformed archive filename witness.');
            }
        }
        [$bounded, $overflow] = $this->boundedNames($names);
        if ($overflow || $bounded !== $names) {
            throw new RuntimeException('Unbounded archive filename witnesses.');
        }

        return $names;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            $ffi = RustPipelineLibrary::resolveDomain('support_archive_witness', self::FFI_CDEF);
            if ($ffi !== null) {
                try {
                    self::$overflowOut = $ffi->new('uint8_t');
                    self::$ffi = $ffi;
                } catch (Throwable) {
                    self::$ffi = null;
                }
            }
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    private function validateDigest(string $digest): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new RuntimeException('Invalid NZB source digest.');
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Archive naming evidence requires the caller transaction.');
        }
    }
}
