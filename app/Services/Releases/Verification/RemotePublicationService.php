<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Publishes one remote worker's spot-checked candidate on the host holding NZB storage. The
 * worker's lease token must still own the candidate; the publisher revalidates it under lock.
 * The single and the batch endpoints share this per-item logic, so a candidate publishes the
 * same way whichever carried it.
 */
final class RemotePublicationService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w16_publication(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
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
            $written = $ffi->nntmux_w16_publication($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, int>  $singleSizes
     * @return list<array{batch: bool, positions: list<int>}>
     */
    public static function batchPartition(array $singleSizes, int $emptySize, int $limit): array
    {
        return self::batchPartitionNative($singleSizes, $emptySize, $limit) ?? self::batchPartitionPhp($singleSizes, $emptySize, $limit);
    }

    /**
     * @param  array<int, int>  $singleSizes
     * @return list<array{batch: bool, positions: list<int>}>
     */
    public static function batchPartitionPhp(array $singleSizes, int $emptySize, int $limit): array
    {
        $groups = [];
        $current = [];
        $bytes = $emptySize;
        foreach ($singleSizes as $position => $size) {
            if ($size > $limit) {
                if ($current !== []) {
                    $groups[] = ['batch' => true, 'positions' => $current];
                    $current = [];
                    $bytes = $emptySize;
                }
                $groups[] = ['batch' => false, 'positions' => [$position]];

                continue;
            }
            $extra = $size - $emptySize + ($current === [] ? 0 : 1);
            if ($current !== [] && $bytes + $extra > $limit) {
                $groups[] = ['batch' => true, 'positions' => $current];
                $current = [];
                $bytes = $emptySize;
                $extra = $size - $emptySize;
            }
            $current[] = $position;
            $bytes += $extra;
        }
        if ($current !== []) {
            $groups[] = ['batch' => true, 'positions' => $current];
        }

        return $groups;
    }

    /**
     * @param  array<int, int>  $singleSizes
     * @return list<array{batch: bool, positions: list<int>}>|null
     */
    public static function batchPartitionNative(array $singleSizes, int $emptySize, int $limit): ?array
    {
        $result = self::computeNative(0, [$singleSizes, $emptySize, $limit]);

        return is_array($result) ? $result : null;
    }

    /**
     * @return array{status: ?string, release_id: ?int}
     */
    public static function settledOutcome(?int $releaseId, ?string $resolved, bool $replay): array
    {
        return self::settledOutcomeNative($releaseId, $resolved, $replay) ?? self::settledOutcomePhp($releaseId, $resolved, $replay);
    }

    /**
     * @return array{status: ?string, release_id: ?int}
     */
    public static function settledOutcomePhp(?int $releaseId, ?string $resolved, bool $replay): array
    {
        return ['status' => $releaseId !== null ? PublicationOutcome::PUBLISHED : ($replay ? $resolved : null), 'release_id' => $releaseId];
    }

    /**
     * @return array{status: ?string, release_id: ?int}|null
     */
    public static function settledOutcomeNative(?int $releaseId, ?string $resolved, bool $replay): ?array
    {
        $result = self::computeNative(1, [$releaseId, $resolved, $replay]);

        return is_array($result) ? $result : null;
    }

    public static function ledgerAdjustment(int $bytes, bool $retain): int
    {
        return self::ledgerAdjustmentNative($bytes, $retain) ?? self::ledgerAdjustmentPhp($bytes, $retain);
    }

    public static function ledgerAdjustmentPhp(int $bytes, bool $retain): int
    {
        return $retain ? 0 : -$bytes;
    }

    public static function ledgerAdjustmentNative(int $bytes, bool $retain): ?int
    {
        $result = self::computeNative(2, [$bytes, $retain]);

        return is_int($result) ? $result : null;
    }

    public const string STALE_MESSAGE = 'Stale verification result cannot publish.';

    /** Version of the batch request and response; a mismatch makes the worker fall back to single requests. */
    public const int BATCH_PROTOCOL = 1;

    /** No configuration raises the item count above this. */
    public const int BATCH_HARD_MAX_ITEMS = 64;

    /** Seconds past the batch budget that one started item may still run, for set_time_limit(). */
    public const int BATCH_TIME_MARGIN = 60;

    public const string PAUSED_MESSAGE = 'Verification is paused for exclusive maintenance; retry later.';

    /** @param (\Closure(): float)|null $clock Seconds on a monotonic clock; tests inject one, production uses hrtime(). */
    public function __construct(private readonly VerifiedReleasePublisher $publisher, private readonly ManifestStore $store,
        private readonly ?\Closure $clock = null) {}

    /**
     * The validation rules for one publication, as the single endpoint's request and each batch item.
     *
     * @return array<string, list<string>>
     */
    public static function itemRules(): array
    {
        return [
            'claim.id' => ['required', 'integer', 'min:1'],
            'claim.version' => ['required', 'integer', 'min:0'],
            'claim.manifest_hash' => ['required', 'string', 'size:64'],
            'claim.lease_token' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence.articles' => ['required', 'integer', 'min:1', 'max:16'],
            'evidence.bytes' => ['required', 'integer', 'min:1', 'max:1048576'],
            'evidence.sampled_files' => ['required', 'integer', 'min:1', 'max:16'],
            'evidence.verified_file_names' => ['present', 'array', 'max:16'],
            'evidence.verified_file_names.*' => ['string', 'max:1024'],
            'evidence.identity_hints' => ['present', 'array', 'max:16'],
            'evidence.identity_hints.*' => ['string', 'max:1024'],
            'evidence.partial' => ['sometimes', 'boolean'],
            'evidence.parity_file_keys' => ['sometimes', 'array', 'max:16'],
            'evidence.parity_file_keys.*' => ['string', 'max:1024'],
        ];
    }

    /** @return array{items: int, bytes: int, seconds: float} */
    public static function batchLimits(): array
    {
        return [
            'items' => max(1, min(self::BATCH_HARD_MAX_ITEMS, (int) config('release_verification.publish_batch_max_items', 16))),
            'bytes' => max(1, (int) config('release_verification.publish_batch_max_bytes', 1048576)),
            'seconds' => max(0.0, (float) config('release_verification.publish_batch_seconds', 15)),
        ];
    }

    /**
     * Publish a batch one item at a time, each in its own transaction exactly as a single request
     * publishes it. Items are validated independently; one invalid or failed item never affects
     * another. The worker lock is taken once, on the first item that needs it, and kept to the end.
     *
     * Items not started when the time budget ends are answered "retry" without being read, and the
     * first item always starts so a tiny budget cannot starve a worker. The budget is measured on
     * a monotonic clock.
     *
     * @param  list<mixed>  $items  Raw request items.
     * @return list<array{index: int, claim_id: ?int, claim_version: ?int, status: string, release_id: ?int, error: ?string}>
     */
    public function publishBatch(array $items, float $budgetSeconds): array
    {
        $size = count($items);
        $startedAt = $this->clock();
        // One sample decision per batch; each sampled item then reports its own timing.
        $sampled = PublicationTiming::sample() !== null;
        $gate = new PublicationWorkerGate(retain: true);
        $results = [];
        $exhausted = false;
        try {
            foreach ($items as $index => $item) {
                $item = is_array($item) ? $item : [];
                $exhausted = $exhausted || ($index > 0 && $this->clock() - $startedAt >= $budgetSeconds);
                $outcome = $exhausted
                    ? new PublicationOutcome(PublicationOutcome::RETRY, null, 'Batch time budget exhausted; resend this item.')
                    : $this->publishBatchItem($item, $sampled ? PublicationTiming::start() : null, $size, $index, $gate);
                $results[] = ['index' => $index, 'claim_id' => $this->integerAt($item, 'id'), 'claim_version' => $this->integerAt($item, 'version'),
                    'status' => $outcome->status, 'release_id' => $outcome->releaseId, 'error' => $outcome->error];
                unset($outcome);
                // A manifest, its parse and the compressed body are large; none outlives its item.
                gc_collect_cycles();
            }
        } finally {
            $gate->close();
        }

        return $results;
    }

    /** @param array<mixed> $item */
    private function publishBatchItem(array $item, ?PublicationTiming $timing, int $size, int $index, PublicationWorkerGate $gate): PublicationOutcome
    {
        $timing?->setBatch($size, $index);
        $validator = Validator::make($item, self::itemRules());
        if ($validator->fails()) {
            return new PublicationOutcome(PublicationOutcome::INVALID, null, mb_strcut(implode(' ', $validator->errors()->all()), 0, 500, 'UTF-8'));
        }
        $timing?->requestPhase('validation');
        try {
            $outcome = $this->publishItem($validator->validated(), $timing, $gate, replayResolved: true);

            // UNRESOLVED is the single endpoint's bare null; a batch item names it an error.
            return $outcome->status === PublicationOutcome::UNRESOLVED
                ? new PublicationOutcome(PublicationOutcome::ERROR, null, $outcome->error ?? 'Publication produced no release.') : $outcome;
        } catch (Throwable $error) {
            // Even the recovery lookup failed; the item may or may not have published, and a resend decides.
            return new PublicationOutcome(PublicationOutcome::ERROR, null, RemoteReleasePublisher::errorMessage($error->getMessage()));
        }
    }

    /** @param array<mixed> $item */
    private function integerAt(array $item, string $key): ?int
    {
        $value = is_array($item['claim'] ?? null) ? ($item['claim'][$key] ?? null) : null;

        return is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) < 19) ? (int) $value : null;
    }

    private function clock(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1_000_000_000;
    }

    /**
     * The execution limit a batch needs, or null to leave it alone: only ever a raise, so a
     * transaction is never cut short by a limit lower than the host already allows. A limit of 0
     * is already unlimited.
     */
    public static function raisedTimeLimit(int $current, float $budgetSeconds): ?int
    {
        if ($current === 0) {
            return null;
        }
        $needed = (int) ceil($budgetSeconds) + self::BATCH_TIME_MARGIN;

        return $needed > $current ? $needed : null;
    }

    /**
     * Publish one validated item.
     *
     * With $replayResolved (batch items), a claim whose exact generation was already filtered or
     * sent to metadata review answers with that status, so a resent batch is idempotent; the
     * single endpoint keeps reporting such a claim as stale.
     *
     * @param  array<string, mixed>  $data  The item as validated by itemRules().
     */
    public function publishItem(array $data, ?PublicationTiming $timing, PublicationWorkerGate $gate, bool $replayResolved = false): PublicationOutcome
    {
        $claim = (object) ['id' => (int) $data['claim']['id'], 'version' => (int) $data['claim']['version'],
            'manifest_hash' => (string) $data['claim']['manifest_hash'], 'lease_token' => (string) $data['claim']['lease_token']];
        $evidence = ['articles' => (int) $data['evidence']['articles'], 'bytes' => (int) $data['evidence']['bytes'],
            'sampled_files' => (int) $data['evidence']['sampled_files'],
            'verified_file_names' => array_values(array_map('strval', $data['evidence']['verified_file_names'])),
            'identity_hints' => array_values(array_map('strval', $data['evidence']['identity_hints'])),
            'partial' => (bool) ($data['evidence']['partial'] ?? false)];
        if (isset($data['evidence']['parity_file_keys']) && is_array($data['evidence']['parity_file_keys'])) {
            $evidence['parity_file_keys'] = array_values(array_map('strval', $data['evidence']['parity_file_keys']));
        }
        try {
            $settled = $this->settled($claim, $replayResolved);
            $timing?->requestPhase('published_check');
            if ($settled !== null) {
                return $settled;
            }
            $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->first();
            if ($candidate === null || (int) $candidate->version !== $claim->version || $candidate->manifest_hash !== $claim->manifest_hash
                || $candidate->lease_token !== $claim->lease_token || $candidate->manifest_path === '') {
                // A concurrent first attempt may have published and cleared the lease since the check above.
                return $this->settled($claim, $replayResolved) ?? new PublicationOutcome(PublicationOutcome::STALE, null, self::STALE_MESSAGE);
            }
            $timing?->requestPhase('candidate_read');
            $archived = $this->store->read($candidate->manifest_path);
            $evidence['partial'] = (new CandidateHeaderInventory)->assessment($archived)['status'] === 'waiting_upload';
            $manifest = SpotCheckVerifier::withEvidence($archived, $evidence);
            $timing?->requestPhase('manifest_read');
            if (! $gate->enter()) {
                return new PublicationOutcome(PublicationOutcome::PAUSED, null, self::PAUSED_MESSAGE);
            }
            $timing?->requestPhase('worker_lock');
            try {
                $releaseId = $this->publisher->publish($claim, new VerificationResult('sampled', (string) $data['reason'], $manifest),
                    'remote', $timing ?? false, ['path' => (string) $candidate->manifest_path, 'manifest' => $archived]);
            } catch (PartialPublicationDeferredException $deferred) {
                // The sending worker returns the partial to waiting for one more hold window.
                return new PublicationOutcome(PublicationOutcome::DEFERRED, null, $deferred->getMessage());
            } finally {
                $gate->leave();
            }
            if ($releaseId !== null) {
                return new PublicationOutcome(PublicationOutcome::PUBLISHED, $releaseId);
            }
            // No release: policy filtered the candidate, or a covered release lacks its metadata.
            // The single endpoint reports both as a bare null and reads nothing more.
            $status = $replayResolved ? $this->resolvedStatus($claim) : null;

            return new PublicationOutcome($status ?? PublicationOutcome::UNRESOLVED, null,
                $status === null && $replayResolved ? 'Publication produced no release.' : null);
        } catch (Throwable $error) {
            // The worker's retry after a lost response finds the release its first request published.
            // A batch item also settles when an overlapping attempt filtered it or sent it to review.
            $settled = $this->settled($claim, $replayResolved);
            if ($settled !== null) {
                return $settled;
            }
            $message = RemoteReleasePublisher::errorMessage($error->getMessage());

            return new PublicationOutcome(str_starts_with($message, 'Stale verification result cannot')
                ? PublicationOutcome::STALE : PublicationOutcome::ERROR, null, $message);
        }
    }

    /** The release already published for this exact candidate generation, or (when replaying) its terminal status. */
    private function settled(object $claim, bool $replayResolved): ?PublicationOutcome
    {
        $published = $this->publishedRelease($claim);
        $status = $published === null && $replayResolved ? $this->resolvedStatus($claim) : null;
        $settled = self::settledOutcome($published, $status, $replayResolved);

        return $settled['status'] === null ? null : new PublicationOutcome($settled['status'], $settled['release_id']);
    }

    /** The release already published for this exact candidate generation. */
    private function publishedRelease(object $claim): ?int
    {
        $releaseId = DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->whereNotNull('release_id')->value('release_id');

        return $releaseId === null ? null : (int) $releaseId;
    }

    /** filtered or metadata_review when this exact generation ended there without a release. */
    private function resolvedStatus(object $claim): ?string
    {
        $status = DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->whereNull('release_id')
            ->whereIn('status', [PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW])->value('status');

        return is_string($status) ? $status : null;
    }

    public const string W58_FFI_CDEF = 'int64_t nntmux_w585_publication_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Wave 58.5 `[outcome, class]` codes per item status label. */
    private const array W58_OUTCOMES = [
        PublicationOutcome::PUBLISHED => [1, 3], PublicationOutcome::FILTERED => [2, 3], PublicationOutcome::METADATA_REVIEW => [3, 3],
        PublicationOutcome::DEFERRED => [4, 1], PublicationOutcome::PAUSED => [5, 1], PublicationOutcome::RETRY => [6, 1],
        PublicationOutcome::STALE => [7, 0], PublicationOutcome::ERROR => [8, 0], PublicationOutcome::TRANSPORT => [9, 1],
        PublicationOutcome::INVALID => [10, 0], PublicationOutcome::NO_RELEASE => [11, 3],
    ];

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W58.5 parity test's QUALIFICATION). */
    private const bool W58_RUST_FFI_DEFAULT = true;

    /**
     * Wave 58.5 routing and FFI cache, kept in function-local statics (readonly verification classes cannot declare
     * static properties, so every W58 class follows the same shape). Without $op it reports the routing; 'set' swaps
     * the override for $val and returns the previous one; 'reset' replaces the cached handle with $val (null
     * re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]` with room for at least $val words,
     * or null without the library.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w58_5', self::W58_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W58_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        return self::w58RustFfiState() === true;
    }

    /**
     * Runs $callback with publicationPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 58.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w58Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w58RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w585_publication_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch plan over tab-separated `id, version, manifest_hash, lease_token, status` publication items. Five words per
     * record: `[id, version, flags, outcome, class]`. Id and version are 1-18 digits (0 when invalid). Flags: 1 id
     * invalid or zero, 2 version invalid, 4 hash not 64 lowercase hex, 8 lease token empty or over 64 bytes, 16 not
     * exactly five fields. Outcome: a three-digit status is its code; labels map through W58_OUTCOMES; anything else
     * 0. Class: 3 settled (published, filtered, metadata_review, no_release, 2xx), 1 retry (deferred, paused, retry,
     * transport, 408, 429, 503, 504), 2 batch fallback (404, 405, 413), 0 final.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function publicationPlanBatch(array $records): array
    {
        return self::usesW58RustFfi() ? self::publicationPlanBatchNative($records) : self::publicationPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function publicationPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 5 ? 0 : 16;
            $id = \strlen($fields[0]) <= 18 && ctype_digit($fields[0]) ? (int) $fields[0] : 0;
            if ($id === 0) {
                $flags |= 1;
            }
            $version = $fields[1] ?? '';
            if (\strlen($version) <= 18 && ctype_digit($version)) {
                $version = (int) $version;
            } else {
                $flags |= 2;
                $version = 0;
            }
            $hash = $fields[2] ?? '';
            if (\strlen($hash) !== 64 || strspn($hash, '0123456789abcdef') !== 64) {
                $flags |= 4;
            }
            $lease = $fields[3] ?? '';
            if ($lease === '' || \strlen($lease) > 64) {
                $flags |= 8;
            }
            $status = $fields[4] ?? '';
            if (\strlen($status) === 3 && ctype_digit($status)) {
                $outcome = (int) $status;
                $class = match (true) {
                    $outcome >= 200 && $outcome <= 299 => 3,
                    $outcome === 408 || $outcome === 429 || $outcome === 503 || $outcome === 504 => 1,
                    $outcome === 404 || $outcome === 405 || $outcome === 413 => 2,
                    default => 0,
                };
            } else {
                [$outcome, $class] = self::W58_OUTCOMES[$status] ?? [0, 0];
            }
            array_push($out, $id, $version, $flags, $outcome, $class);
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
    public static function publicationPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w58Words($count, implode("\0", $records), 5 * $count) ?? self::publicationPlanBatchPhp($records);
    }
}
