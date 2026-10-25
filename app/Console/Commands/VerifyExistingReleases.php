<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Verification\AuditReleaseDeletion;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationLedger;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class VerifyExistingReleases extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w371_fix_names_reset_verify(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustFixReleaseNamesResetVerifyCommandParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int RUST_MAX_INPUT = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private const array AUDIT_SOURCES = ['none', 'fresh', 'retry'];

    protected $signature = 'releases:verify-repairable {--dry-run : Report without deleting}
        {--delete : Delete only proven unrepairable releases} {--limit=100 : Maximum releases}
        {--since-id=0 : Start after this release ID} {--resume : Use persisted audit cursor}';

    protected $description = 'Spot check existing NZBs with bounded article reads; inconclusive results are retained';

    public function handle(NzbService $nzb, NzbManifestReader $reader, ScratchInventory $scratchInventory,
        AuditReleaseDeletion $deletion, ScratchBudget $scratchBudget, YencService $decoder): int
    {
        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root, 0700);
        $scratch = $root.'/scratch';
        if (is_link($scratch) || (file_exists($scratch) && (! is_dir($scratch) || ! is_readable($scratch) || ! is_executable($scratch)))) {
            throw new \RuntimeException('Audit scratch root is inaccessible or linked.');
        }
        if (! $scratchBudget->initialize()) {
            $this->warn('Scratch budget initialization is waiting for active verification workers.');

            return self::SUCCESS;
        }
        $lock = new VerificationWorkerLocks;
        if (! $lock->tryExclusive()) {
            $this->warn('The new-release verifier has priority; retry the audit later.');

            return self::SUCCESS;
        }
        try {
            $scratch = $root.'/scratch';
            if (is_link($scratch) || (file_exists($scratch) && (! is_dir($scratch) || ! is_readable($scratch) || ! is_executable($scratch)))) {
                throw new \RuntimeException('Audit scratch root is inaccessible or linked.');
            }
            if ($this->newCandidatesAreDue()) {
                $this->warn('New candidates are due; audit deferred.');

                return self::SUCCESS;
            }
            $state = DB::table('release_verification_state')->where('id', 1)->first();
            if ($state === null) {
                throw new \RuntimeException('Audit state is unavailable.');
            }
            $resume = (bool) $this->option('resume');
            $cursor = $resume ? (int) $state->audit_cursor : (int) $this->option('since-id');
            $limit = max(1, (int) $this->option('limit'));
            $freshIds = Release::query()->where('id', '>', $cursor)->orderBy('id')->limit($limit)->pluck('id')->all();
            $retryIds = $resume ? DB::table('release_verification_audit_retries')->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')->orderBy('release_id')->limit($limit)->pluck('release_id')->all() : [];
            $retryTurn = (bool) $state->audit_retry_turn;
            $verificationOptions = (array) config('release_verification');
            $source = new NntpArticleSource(app(NNTPService::class), decoder: $decoder,
                maximumBytes: min((int) $verificationOptions['spot_check_bytes'], (int) $verificationOptions['spot_check_article_bytes']),
                allowAlternateFallback: false);
            $attempted = 0;
            while ($attempted < $limit && ($freshIds !== [] || $retryIds !== [])) {
                if ($this->newCandidatesAreDue()) {
                    $this->warn('New candidates are due; audit deferred.');

                    break;
                }
                $isRetry = $retryIds !== [] && ($retryTurn || $freshIds === []);
                $id = (int) ($isRetry ? array_shift($retryIds) : array_shift($freshIds));
                $release = Release::query()->find($id);
                if ($release === null) {
                    DB::transaction(static function () use ($id): void {
                        DB::table('release_verification_audit_retries')->where('release_id', $id)->delete();
                        DB::table('release_verification_scratch')->where('audit_release_id', $id)->update(['terminal' => true]);
                    });

                    continue;
                }
                $attempted++;
                $scratchEntry = $scratchInventory->registerAudit((int) $release->id);
                $directory = $root.'/scratch/'.$scratchInventory->directoryName($scratchEntry);
                File::ensureDirectoryExists($directory, 0700);
                $verifier = new SpotCheckVerifier($source, $verificationOptions, $decoder);
                try {
                    if (Release::hasLateArticleFlag() && $release->getAttribute('late_article_candidate_id') !== null) {
                        // Late articles may still replace its NZB; the flag only clears, so a later retry is stable.
                        throw new \RuntimeException('Partial release is still awaiting late articles.');
                    }
                    $xml = $nzb->readNzbContents($release->guid);
                    if ($xml === false) {
                        throw new \RuntimeException('Release NZB is unavailable.');
                    }
                    // A partial publication's NZB is knowingly incomplete and can never grow; audit what it has,
                    // but only while it still holds every article and file recorded at publication.
                    $manifest = $reader->read($xml, $release);
                    $partial = $release->getAttribute('articles_expected') !== null;
                    $found = $partial ? (new CandidateHeaderInventory)->completeness($manifest) : null;
                    // Fewer than recorded is a truncated NZB. More is an upgrade whose NZB was installed but whose
                    // transaction never committed (an interrupted worker): a verified, fuller NZB, sampled as usual.
                    if ($found !== null && ($found['articles_found'] < (int) $release->getAttribute('articles_found')
                        || $found['files_found'] < (int) $release->getAttribute('files_found'))) {
                        $record = ['id' => $release->id, 'verdict' => 'unrepairable',
                            'reason' => sprintf('Partial release NZB holds %d of %d recorded articles and %d of %d recorded files.',
                                $found['articles_found'], (int) $release->getAttribute('articles_found'),
                                $found['files_found'], (int) $release->getAttribute('files_found'))];
                    } else {
                        $result = $verifier->verify($manifest, static function (): void {}, allowPartial: $partial);
                        $record = ['id' => $release->id, 'verdict' => $result->verdict, 'reason' => $result->reason];
                    }
                } catch (Throwable $error) {
                    $record = ['id' => $release->id, 'verdict' => 'unknown', 'reason' => $error->getMessage()];
                }
                $scratchInventory->recordAudit((int) $scratchEntry->id, $record['verdict'], $record['reason']);
                $delete = ! $this->option('dry-run') && $this->option('delete') && $record['verdict'] === 'unrepairable';
                DB::transaction(function () use ($id, $isRetry, $record, $scratchEntry, $scratchInventory, $delete, $release, $deletion): void {
                    // Deletions serialize with publications that may choose this release as a
                    // duplicate (they share a search key); the shared ledger row is locked last.
                    app(VerificationLedger::class)->enterPublication(VerificationLedger::publicationKeys((int) $release->size,
                        $release->getAttribute('article_fingerprint'), (string) $release->name, (string) $release->searchname,
                        (int) $release->predb_id), 'Audit state is unavailable.');
                    if ($record['verdict'] === 'unknown') {
                        DB::table('release_verification_audit_retries')->updateOrInsert(['release_id' => $id], [
                            'next_attempt_at' => now()->addSeconds(max(1, (int) config('release_verification.retry_seconds'))),
                            'reason' => mb_strcut($record['reason'], 0, 2000, 'UTF-8'),
                        ]);
                    } else {
                        if ($delete) {
                            $deletion->commit($release, (int) $scratchEntry->id);
                        }
                        DB::table('release_verification_audit_retries')->where('release_id', $id)->delete();
                        $scratchInventory->finishAudit((int) $scratchEntry->id);
                    }
                    $current = DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
                    if ($current === null) {
                        throw new \RuntimeException('Audit state is unavailable.');
                    }
                    DB::table('release_verification_state')->where('id', 1)->update([
                        'audit_cursor' => max((int) $current->audit_cursor, $id), 'audit_retry_turn' => ! $isRetry,
                    ]);
                }, 5);
                if ($delete) {
                    $record['deleted'] = true;
                }
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                if (file_put_contents($root.'/audit.jsonl', $line."\n", FILE_APPEND | LOCK_EX) === false) {
                    throw new \RuntimeException('Cannot persist audit report.');
                }
                $retryTurn = ! $isRetry;
                $this->line($line);
            }
        } finally {
            $lock->close();
        }

        return self::SUCCESS;
    }

    private function newCandidatesAreDue(): bool
    {
        return DB::table('release_verification_candidates')->whereNull('release_id')->whereIn('status', ['ready', 'pending', 'unknown', 'verifying'])
            ->where('next_attempt_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))->exists();
    }

    /**
     * Plans a `releases:verify-repairable` run: deletes need `--delete` without `--dry-run`,
     * `--resume` reads the persisted cursor and retry queue, and the fresh and retry queues
     * (each clamped to `0..limit`) interleave as the command loop does, starting with retries on
     * a retry turn. The schedule assumes every queued release still exists.
     *
     * @return array{limit: int, cursor: int, deletes_enabled: bool, mode: string, fresh_attempts: int, retry_attempts: int, attempts: int, final_retry_turn: bool, first_source: string, last_source: string}
     */
    public static function normalizeAuditRunPlan(bool $dryRun, bool $delete, bool $resume, int $limit, int $sinceId, int $stateCursor,
        bool $retryTurn = false, int $freshCount = 0, int $retryCount = 0): array
    {
        return (self::usesRustFfi() ? self::normalizeAuditRunPlanNative($dryRun, $delete, $resume, $limit, $sinceId, $stateCursor, $retryTurn, $freshCount, $retryCount) : null)
            ?? self::normalizeAuditRunPlanPhp($dryRun, $delete, $resume, $limit, $sinceId, $stateCursor, $retryTurn, $freshCount, $retryCount);
    }

    /**
     * @return array{limit: int, cursor: int, deletes_enabled: bool, mode: string, fresh_attempts: int, retry_attempts: int, attempts: int, final_retry_turn: bool, first_source: string, last_source: string}
     */
    private static function normalizeAuditRunPlanPhp(bool $dryRun, bool $delete, bool $resume, int $limit, int $sinceId, int $stateCursor,
        bool $retryTurn, int $freshCount, int $retryCount): array
    {
        $limit = max(1, $limit);
        $fresh = min($limit, max(0, $freshCount));
        $retry = $resume ? min($limit, max(0, $retryCount)) : 0;
        // Alternation starts with the queue chosen by the turn; once one queue empties the other drains alone.
        [$first, $second] = $retryTurn ? [$retry, $fresh] : [$fresh, $retry];
        // Both queues are at most $limit, so $limit - $second cannot overflow.
        $total = $first > $limit - $second ? $limit : $first + $second;
        $pairs = min($first, $second);
        if ($first === 0) {
            [$firstUsed, $secondUsed, $lastIsFirst] = [0, $total, false];
        } elseif ($total <= $pairs || $total - $pairs <= $pairs) {
            [$firstUsed, $secondUsed, $lastIsFirst] = [$total - intdiv($total, 2), intdiv($total, 2), $total % 2 === 1];
        } elseif ($first > $second) {
            [$firstUsed, $secondUsed, $lastIsFirst] = [$total - $pairs, $pairs, true];
        } else {
            [$firstUsed, $secondUsed, $lastIsFirst] = [$pairs, $total - $pairs, false];
        }
        [$retryUsed, $freshUsed] = $retryTurn ? [$firstUsed, $secondUsed] : [$secondUsed, $firstUsed];
        $source = static fn (bool $isFirst): string => $isFirst === $retryTurn ? 'retry' : 'fresh';
        $last = $total === 0 ? 'none' : $source($lastIsFirst);
        $deletes = ! $dryRun && $delete;

        return [
            'limit' => $limit,
            'cursor' => $resume ? $stateCursor : $sinceId,
            'deletes_enabled' => $deletes,
            'mode' => $deletes ? 'delete' : 'report',
            'fresh_attempts' => $freshUsed,
            'retry_attempts' => $retryUsed,
            'attempts' => $total,
            'final_retry_turn' => $total === 0 ? $retryTurn : $last !== 'retry',
            'first_source' => $total === 0 ? 'none' : $source($first !== 0),
            'last_source' => $last,
        ];
    }

    /**
     * @return array{limit: int, cursor: int, deletes_enabled: bool, mode: string, fresh_attempts: int, retry_attempts: int, attempts: int, final_retry_turn: bool, first_source: string, last_source: string}|null
     */
    private static function normalizeAuditRunPlanNative(bool $dryRun, bool $delete, bool $resume, int $limit, int $sinceId, int $stateCursor,
        bool $retryTurn, int $freshCount, int $retryCount): ?array
    {
        $flags = ($dryRun ? 1 : 0) | ($delete ? 2 : 0) | ($resume ? 4 : 0) | ($retryTurn ? 8 : 0);
        $fields = self::unpackRustInts(self::callRustFfi(6, $flags, pack('q5', $limit, $sinceId, $stateCursor, $freshCount, $retryCount)), 8);
        if ($fields === null) {
            return null;
        }
        [$limitOut, $cursor, $deletes, $freshUsed, $retryUsed, $finalTurn, $first, $last] = $fields;
        $firstName = self::AUDIT_SOURCES[$first] ?? null;
        $lastName = self::AUDIT_SOURCES[$last] ?? null;
        if ($firstName === null || $lastName === null || ($deletes | $finalTurn) & ~1 || $freshUsed < 0 || $retryUsed < 0 || $freshUsed > $limitOut - $retryUsed) {
            return null;
        }

        return [
            'limit' => $limitOut,
            'cursor' => $cursor,
            'deletes_enabled' => $deletes === 1,
            'mode' => $deletes === 1 ? 'delete' : 'report',
            'fresh_attempts' => $freshUsed,
            'retry_attempts' => $retryUsed,
            'attempts' => $freshUsed + $retryUsed,
            'final_retry_turn' => $finalTurn === 1,
            'first_source' => $firstName,
            'last_source' => $lastName,
        ];
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    /**
     * @return list<int>|null
     */
    private static function unpackRustInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 37.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function callRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w371', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
