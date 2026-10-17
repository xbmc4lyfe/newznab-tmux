<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ManifestCompressionProbe;
use Tests\Support\CapturedUploadFixture;

class ExistingImportPasswordEnrichmentTest extends ReleaseVerificationCandidatesTest
{
    use CapturedUploadFixture;

    public static function scenarios(): array
    {
        return array_map(static fn ($mode) => [$mode], ['exact', 'exact-spot', 'appeared-after-advisory', 'appeared-changed-after-advisory', 'capacity', 'password-order', 'changed-date', 'changed-mode', 'changed-subject', 'changed-subject-after-advisory', 'changed-groups', 'changed-bytes', 'changed-file-key', 'conflict', 'stored-conflict', 'corrupt']);
    }

    #[DataProvider('scenarios')]
    public function test_actual_public_import_preserves_assertion_on_same_identity(string $mode): void
    {
        config(['logging.default' => 'stderr']);
        $blacklist = $this->createStub(BlacklistService::class);
        $blacklist->method('isBlackListed')->willReturn(false);
        $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);
        $service = new class($blacklist) extends NzbImportService
        {
            public function __construct($blacklist)
            {
                parent::__construct(['Browser' => true]);
                $this->blacklistService = $blacklist;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }
        };
        $path = config('release_verification.storage_path').'/incoming.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        $xml = '<nzb><head>%s</head><file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
        $head = $mode === 'exact-spot' ? '<meta type="nntmux:verification-mode">spot-check</meta>' : '';
        file_put_contents($path, sprintf($xml, $head));
        $statuses = [];
        $callback = static function (array $r) use (&$statuses) {
            $statuses[] = $r['status'];
        };
        $service->beginImport([$path], resultCallback: $callback);
        $first = DB::table('release_verification_candidates')->first();
        self::assertNotNull($first);
        $store = app(ManifestStore::class);
        $original = $store->read($first->manifest_path);
        $stored = $original;
        if ($mode === 'password-order') {
            $stored['passwords'] = ['second assertion', 'synthetic assertion'];
        }
        if ($mode === 'changed-date') {
            $stored['date'] = '2001-01-01 00:00:00';
        }
        if ($mode === 'changed-mode') {
            $stored['verification_mode'] = 'spot_check';
        }
        if (in_array($mode, ['changed-subject', 'changed-subject-after-advisory', 'appeared-changed-after-advisory'], true)) {
            $stored['subject'] = 'different fixture';
        }
        if ($mode === 'changed-groups') {
            $stored['groups'] = ['alt.other'];
        }
        if ($mode === 'changed-bytes') {
            $stored['files'][0]['segments'][0]['bytes'] = 6;
        }
        if ($mode === 'changed-file-key') {
            $stored['files'][0]['key'] = 'different';
        }
        if ($mode === 'stored-conflict') {
            $stored['conflict'] = true;
        }
        $storedPath = $store->write($stored);
        $storedBytes = $store->size($storedPath);
        DB::table('release_verification_candidates')->where('id', $first->id)->update(['manifest_path' => $storedPath, 'manifest_hash' => substr($storedPath, 0, 64), 'manifest_bytes' => $storedBytes, 'lease_token' => 'fixture-lease', 'lease_until' => now()->addHour()]);
        DB::table('release_verification_state')->update(['pending_bytes' => $storedBytes]);
        if ($mode === 'corrupt') {
            file_put_contents($store->path($storedPath), 'broken');
        }
        if ($mode === 'capacity') {
            config(['release_verification.pending_bytes' => $storedBytes]);
        }
        if ($mode === 'conflict') {
            $xml = str_replace('</segments>', '<segment bytes="5" number="1">fixture@example.test</segment></segments>', $xml);
        }
        file_put_contents($path, sprintf($xml, $head.'<meta type="password">synthetic assertion</meta>'));
        $capacityReads = 0;
        $compressions = 0;
        $lockedCompressions = 0;
        $preparations = 0;
        $swapped = false;
        $appeared = false;
        $restoreCandidate = (array) DB::table('release_verification_candidates')->first();
        if (in_array($mode, ['appeared-after-advisory', 'appeared-changed-after-advisory'], true)) {
            DB::table('release_verification_candidates')->delete();
        }
        if ($mode === 'changed-subject-after-advisory') {
            DB::table('release_verification_candidates')->where('id', $first->id)->update([
                'manifest_path' => $first->manifest_path, 'manifest_hash' => $first->manifest_hash,
                'manifest_bytes' => $first->manifest_bytes,
            ]);
            DB::table('release_verification_state')->update(['pending_bytes' => $first->manifest_bytes]);
        }
        DB::listen(static function (QueryExecuted $query) use (&$capacityReads, &$swapped, &$appeared, $restoreCandidate, $mode, $first, $storedPath, $storedBytes): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_state')) {
                $capacityReads++;
                if (in_array($mode, ['appeared-after-advisory', 'appeared-changed-after-advisory'], true) && ! $appeared) {
                    $appeared = true;
                    DB::table('release_verification_candidates')->insert($restoreCandidate);
                }
                if ($mode === 'changed-subject-after-advisory' && ! $swapped) {
                    $swapped = true;
                    // Deterministic interleaving at the real repository's shared-row read.
                    // SQLite proves current-row selection, not concurrent lock behavior.
                    DB::table('release_verification_candidates')->where('id', $first->id)->update([
                        'manifest_path' => $storedPath, 'manifest_hash' => substr($storedPath, 0, 64),
                        'manifest_bytes' => $storedBytes, 'version' => $first->version,
                    ]);
                    DB::table('release_verification_state')->update(['pending_bytes' => $storedBytes]);
                }
            }
        });
        ManifestCompressionProbe::$onCompress = static function () use (&$compressions, &$lockedCompressions, &$capacityReads, &$preparations): void {
            $compressions++;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === ManifestStore::class && ($frame['function'] ?? null) === 'prepare') {
                    $preparations++;
                }
            }
            if ($capacityReads > 0 && DB::transactionLevel() > 0) {
                $lockedCompressions++;
            }
        };
        try {
            $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        } finally {
            ManifestCompressionProbe::$onCompress = null;
        }
        $expectedCompressions = 1;
        self::assertSame($expectedCompressions, $preparations, 'Preparation calls must not create unused comparison pairs.');
        self::assertSame($expectedCompressions, $compressions, 'Incoming assertions are prepared once before authoritative admission.');
        self::assertSame(0, $lockedCompressions, 'Admission must prepare outside shared capacity ownership.');
        if ($mode === 'changed-subject-after-advisory') {
            self::assertTrue($swapped);
        }
        if (in_array($mode, ['appeared-after-advisory', 'appeared-changed-after-advisory'], true)) {
            self::assertTrue($appeared);
        }
        $expected = in_array($mode, ['exact', 'exact-spot', 'appeared-after-advisory'], true) ? NzbImportStatus::Queued : (in_array($mode, ['capacity', 'corrupt'], true) ? NzbImportStatus::MetadataDeferred : NzbImportStatus::MetadataIgnored);
        self::assertSame([NzbImportStatus::Queued, $expected], $statuses);
        self::assertSame(1, DB::table('release_verification_candidates')->count());
        $candidate = DB::table('release_verification_candidates')->first();
        if ($mode === 'corrupt') {
            self::assertFileExists($path);
            self::assertSame($storedPath, $candidate->manifest_path);
            self::assertSame($storedBytes, (int) DB::table('release_verification_state')->value('pending_bytes'));

            return;
        }
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        if (in_array($mode, ['exact', 'exact-spot', 'appeared-after-advisory'], true)) {
            self::assertSame(['synthetic assertion'], $manifest['passwords'] ?? [], 'Accepted replay must preserve newly asserted uploader metadata before deletion.');
            self::assertFileExists($path);
            self::assertFileDoesNotExist($store->path($storedPath), 'Old artifact cleanup follows the committed candidate swap.');
            self::assertSame((int) $first->version + 1, (int) $candidate->version);
            self::assertNull($candidate->lease_token);
            self::assertNull($candidate->lease_until);
            $assessment = (new CandidateHeaderInventory)->assessment($manifest);
            self::assertSame($assessment['status'], $candidate->status);
            self::assertSame($assessment['reason'], $candidate->reason);
            self::assertSame($store->size($candidate->manifest_path), (int) DB::table('release_verification_state')->value('pending_bytes'));
        } else {
            self::assertFileExists($path);
            self::assertSame($stored, $manifest);
            self::assertSame($storedBytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
            if ($mode === 'capacity') {
                self::assertSame([$store->path($storedPath)], glob(config('release_verification.storage_path').'/manifests/*.json.gz'), 'Rejected reservation leaves no enriched artifact orphan.');
                self::assertSame('metadata_deferred', $candidate->status);
                self::assertNull($candidate->lease_token);
                self::assertNull($candidate->lease_until);
                self::assertSame((int) $first->version + 1, (int) $candidate->version);
                self::assertNull(app(CandidateRepository::class)->claim());
                app(CandidateRepository::class)->expire();
                self::assertSame($storedPath, DB::table('release_verification_candidates')->value('manifest_path'));
                config(['release_verification.pending_bytes' => 1000000]);
                $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
                self::assertSame(NzbImportStatus::Queued, end($statuses));
                $replayed = DB::table('release_verification_candidates')->first();
                self::assertSame(['synthetic assertion'], $store->read($replayed->manifest_path)['passwords']);
            } else {
                self::assertSame((int) $first->version, (int) $candidate->version);
            }
        }
    }

    public static function orderedReplayScenarios(): array
    {
        return [
            'identical repeated ordered list' => [['first assertion', 'first assertion', 'second assertion'], NzbImportStatus::Queued],
            'reversed same length' => [['second assertion', 'first assertion', 'first assertion'], NzbImportStatus::MetadataIgnored],
            'dropped duplicate' => [['first assertion', 'second assertion'], NzbImportStatus::MetadataIgnored],
        ];
    }

    /** @param list<string> $incoming */
    #[DataProvider('orderedReplayScenarios')]
    public function test_actual_import_requires_exact_order_and_multiplicity(array $incoming, NzbImportStatus $expected): void
    {
        $service = $this->offlineImportService();
        $path = config('release_verification.storage_path').'/ordered-replay.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        $originalPasswords = ['first assertion', 'first assertion', 'second assertion'];
        file_put_contents($path, $this->passwordFixture($originalPasswords));
        $statuses = [];
        $callback = static function (array $result) use (&$statuses): void {
            $statuses[] = $result['status'];
        };
        $service->beginImport([$path], resultCallback: $callback);
        DB::table('release_verification_candidates')->update(['lease_token' => 'ordered-replay-lease', 'lease_until' => now()->addHour()]);
        $before = DB::table('release_verification_candidates')->first();
        $store = app(ManifestStore::class);
        $retained = $store->read($before->manifest_path);
        self::assertSame($originalPasswords, $retained['passwords']);
        $retainedBytes = file_get_contents($store->path($before->manifest_path));
        $pendingBytes = DB::table('release_verification_state')->value('pending_bytes');
        $source = $this->passwordFixture($incoming);
        file_put_contents($path, $source);
        $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        self::assertSame([NzbImportStatus::Queued, $expected], $statuses);
        self::assertSame((array) $before, (array) DB::table('release_verification_candidates')->first(), 'A metadata replay must preserve manifest hash, version, and current lease.');
        self::assertSame($retained, $store->read($before->manifest_path));
        self::assertSame($retainedBytes, file_get_contents($store->path($before->manifest_path)));
        self::assertSame($pendingBytes, DB::table('release_verification_state')->value('pending_bytes'));
        self::assertSame([$store->path($before->manifest_path)], glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
        self::assertSame($source, file_get_contents($path), 'Unpublished or rejected metadata retains its exact incoming source.');
    }

    public static function stalePublicationScenarios(): array
    {
        return ['successful enrichment' => [false], 'capacity deferred' => [true]];
    }

    #[DataProvider('stalePublicationScenarios')]
    public function test_actual_publisher_rejects_claim_invalidated_by_password_enrichment(bool $capacityDeferred): void
    {
        $service = $this->offlineImportService();
        $path = config('release_verification.storage_path').'/stale-publication.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $this->passwordFixture([]));
        $statuses = [];
        $callback = static function (array $result) use (&$statuses): void {
            $statuses[] = $result['status'];
        };
        $service->beginImport([$path], resultCallback: $callback);
        $claim = app(CandidateRepository::class)->claim();
        self::assertNotNull($claim);
        $store = app(ManifestStore::class);
        $verifiedManifest = $store->read($claim->manifest_path);
        if ($capacityDeferred) {
            config(['release_verification.pending_bytes' => $claim->manifest_bytes]);
        }
        file_put_contents($path, $this->passwordFixture(['synthetic assertion']));
        $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        self::assertSame([NzbImportStatus::Queued, $capacityDeferred ? NzbImportStatus::MetadataDeferred : NzbImportStatus::Queued], $statuses);
        $candidate = DB::table('release_verification_candidates')->first();
        self::assertSame((int) $claim->version + 1, (int) $candidate->version);
        self::assertNull($candidate->lease_token);
        self::assertNull($candidate->lease_until);
        if ($capacityDeferred) {
            self::assertSame('metadata_deferred', $candidate->status);
            self::assertSame($claim->manifest_hash, $candidate->manifest_hash);
            self::assertFileExists($path);
        } else {
            self::assertNotSame($claim->manifest_hash, $candidate->manifest_hash);
            self::assertFileExists($path);
        }
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        // The actual publisher must reject at its custody gate before metadata or final NZB writing.
        $nzb = $this->createMock(NzbService::class);
        $nzb->expects(self::never())->method('getNzbPath');
        $metadata = $this->createMock(ReleaseMetadataResolver::class);
        $metadata->expects(self::never())->method('resolve');
        $publisher = new VerifiedReleasePublisher(new VerifiedNzbWriter($nzb), $metadata, new ReleaseDuplicateFinder);
        $before = (array) $candidate;
        $artifacts = glob(config('release_verification.storage_path').'/manifests/*.json.gz');
        $pendingBytes = DB::table('release_verification_state')->value('pending_bytes');
        try {
            $publisher->publish($claim, new VerificationResult('complete', 'Synthetic offline verified result.', $verifiedManifest));
            self::fail('The old claim must not publish after metadata invalidates its lease.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Stale verification result cannot publish.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('releases')->count());
        self::assertSame($before, (array) DB::table('release_verification_candidates')->first());
        self::assertSame($pendingBytes, DB::table('release_verification_state')->value('pending_bytes'));
        self::assertSame($artifacts, glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
    }

    public function test_enriching_a_defective_import_before_its_first_check_keeps_it_claimable_once(): void
    {
        $repository = app(CandidateRepository::class);
        $manifest = $this->privateRollbackManifest();
        $manifest['files'][0]['totalparts'] = 2;
        $manifest['files'][0]['segments'][] = ['number' => 2] + $manifest['files'][0]['segments'][0];
        self::assertSame('header_rejected', (new CandidateHeaderInventory)->assessment($manifest)['status']);
        self::assertTrue($repository->enqueue($manifest));
        self::assertSame('unknown', DB::table('release_verification_candidates')->value('status'));

        self::assertTrue($repository->enqueue($manifest + ['passwords' => ['secret']]));

        $enriched = DB::table('release_verification_candidates')->sole();
        self::assertSame(2, (int) $enriched->version);
        self::assertSame('unknown', $enriched->status);
        self::assertNotNull($repository->claim());
    }

    public function test_accepted_enrichment_preserves_expiry_and_fences_old_claim_before_ordered_reclaim(): void
    {
        $repository = app(CandidateRepository::class);
        $store = app(ManifestStore::class);
        $manifest = $this->privateRollbackManifest();
        self::assertTrue($repository->enqueue($manifest));
        $claim = $repository->claim();
        self::assertNotNull($claim);
        $expiry = DB::table('release_verification_candidates')->value('last_unique_at');
        $this->travel(1)->hours();
        $passwords = ['first assertion', '', 'first assertion', 'second assertion'];
        self::assertTrue($repository->enqueue($manifest + ['passwords' => $passwords]));
        $replacement = (array) DB::table('release_verification_candidates')->first();
        self::assertSame($expiry, $replacement['last_unique_at']);
        self::assertSame((int) $claim->version + 1, (int) $replacement['version']);
        self::assertNull($replacement['lease_token']);
        self::assertNull($replacement['lease_until']);
        self::assertNotSame($claim->manifest_path, $replacement['manifest_path']);
        $state = (array) DB::table('release_verification_state')->first();
        $leaseLost = false;
        try {
            $repository->heartbeat($claim->id, $claim->lease_token);
        } catch (\RuntimeException $exception) {
            $leaseLost = true;
            self::assertSame('Verification lease was lost.', $exception->getMessage());
        }
        self::assertTrue($leaseLost, 'The superseded claim cannot renew the replacement lease.');
        $repository->finish($claim, new VerificationResult('unrepairable', 'Synthetic stale completion.'));
        self::assertSame($replacement, (array) DB::table('release_verification_candidates')->first());
        self::assertSame($state, (array) DB::table('release_verification_state')->first());
        $fresh = $repository->claim();
        self::assertNotNull($fresh);
        self::assertSame($replacement['version'], $fresh->version);
        self::assertNotSame($claim->lease_token, $fresh->lease_token);
        self::assertSame($passwords, $store->read($fresh->manifest_path)['passwords']);
        self::assertSame($manifest['files'], $store->read($fresh->manifest_path)['files']);
        self::assertSame($expiry, $fresh->last_unique_at);
    }

    private function offlineImportService(): NzbImportService
    {
        $blacklist = $this->createStub(BlacklistService::class);
        $blacklist->method('isBlackListed')->willReturn(false);
        $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);

        return new class($blacklist) extends NzbImportService
        {
            public function __construct($blacklist)
            {
                parent::__construct(['Browser' => true]);
                $this->blacklistService = $blacklist;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }
        };
    }

    /** @param list<string> $passwords */
    private function passwordFixture(array $passwords): string
    {
        $head = implode('', array_map(static fn (string $password): string => '<meta type="password">'.$password.'</meta>', $passwords));

        return '<nzb><head>'.$head.'</head><file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
    }

    public function test_password_metadata_enrichment_rolls_back_manifest_and_capacity_with_outer_transaction(): void
    {
        $this->assertPrivatePasswordRollback(false);
    }

    public function test_password_metadata_update_failure_rolls_back_new_storage_and_capacity(): void
    {
        $this->assertPrivatePasswordRollback(true);
    }

    private function assertPrivatePasswordRollback(bool $injectFailure): void
    {
        $repository = app(CandidateRepository::class);
        $store = app(ManifestStore::class);
        $manifest = $this->privateRollbackManifest();
        self::assertTrue($repository->enqueue($manifest));
        DB::table('release_verification_candidates')->update([
            'status' => 'verifying', 'lease_token' => 'private-rollback-lease',
            'lease_until' => now()->addHour(),
        ]);
        $before = (array) DB::table('release_verification_candidates')->first();
        $state = (array) DB::table('release_verification_state')->first();
        $oldBytes = file_get_contents($store->path($before['manifest_path']));
        $incoming = $manifest + ['passwords' => ['synthetic assertion']];
        $prepared = $store->prepare($incoming);
        $newPath = $prepared->name;
        self::assertNotSame($before['manifest_path'], $newPath);
        self::assertFileDoesNotExist($store->path($newPath));
        $injected = false;
        if ($injectFailure) {
            DB::listen(static function (QueryExecuted $query) use (&$injected, $store, $newPath): void {
                if (! $injected && str_starts_with($query->sql, 'update "release_verification_candidates"')) {
                    self::assertFileExists($store->path($newPath), 'The fault follows real manifest installation.');
                    $injected = true;
                    throw new \RuntimeException('Synthetic candidate update failure.');
                }
            });
        } else {
            DB::beginTransaction();
        }
        try {
            self::assertTrue($repository->enqueue($incoming));
            self::assertFalse($injectFailure, 'Candidate update fault must propagate.');
            $during = DB::table('release_verification_candidates')->first();
            self::assertSame($newPath, $during->manifest_path);
            self::assertSame($before['version'] + 1, $during->version);
            self::assertNull($during->lease_token);
            self::assertNull($during->lease_until);
            self::assertSame($incoming, $store->read($during->manifest_path));
            self::assertSame($during->manifest_bytes, DB::table('release_verification_state')->value('pending_bytes'));
            self::assertFileExists($store->path($before['manifest_path']));
            self::assertFileExists($store->path($newPath));
        } catch (\RuntimeException $exception) {
            self::assertTrue($injectFailure);
            self::assertSame('Synthetic candidate update failure.', $exception->getMessage());
        } finally {
            if (! $injectFailure) {
                DB::rollBack();
            }
        }
        self::assertSame($injectFailure, $injected);
        self::assertSame(0, DB::transactionLevel());
        self::assertSame($before, (array) DB::table('release_verification_candidates')->first());
        self::assertSame($state, (array) DB::table('release_verification_state')->first());
        self::assertSame($oldBytes, file_get_contents($store->path($before['manifest_path'])));
        self::assertSame($manifest, $store->read($before['manifest_path']));
        self::assertFileDoesNotExist($store->path($newPath));
        self::assertSame([$store->path($before['manifest_path'])], glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
    }

    public function test_public_import_outer_transaction_guard_retains_source_before_reading(): void
    {
        $service = new class extends NzbImportService
        {
            public function __construct()
            {
                parent::__construct(['Browser' => true]);
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }
        };
        app('files')->ensureDirectoryExists(config('release_verification.storage_path'));
        $source = config('release_verification.storage_path').'/private-outer.nzb';
        // Deliberately malformed: the outer guard must run before even opening this source.
        $bytes = 'synthetic malformed input retained for retry';
        file_put_contents($source, $bytes);
        $state = (array) DB::table('release_verification_state')->first();
        $filesystem = File::getFacadeRoot();
        File::partialMock()->shouldReceive('isFile')->never();
        File::shouldReceive('get')->never();
        File::shouldReceive('delete')->with($source)->never();
        $results = [];
        DB::beginTransaction();
        try {
            $service->beginImport([$source], delete: true, deleteFailed: true,
                resultCallback: static function (array $result) use (&$results): void {
                    $results[] = $result;
                });
            self::assertCount(1, $results);
            self::assertSame('metadata_deferred', $results[0]['status']->value);
            self::assertNull($results[0]['candidate_identity']);
            self::assertSame(1, DB::transactionLevel());
            self::assertSame($bytes, file_get_contents($source));
            self::assertSame(0, DB::table('release_verification_candidates')->count());
            self::assertSame($state, (array) DB::table('release_verification_state')->first());
        } finally {
            DB::rollBack();
            File::swap($filesystem);
        }
        self::assertSame($bytes, file_get_contents($source));
        self::assertSame(0, DB::table('release_verification_candidates')->count());
        self::assertSame($state, (array) DB::table('release_verification_state')->first());
    }

    /** @return array<string, mixed> */
    private function privateRollbackManifest(): array
    {
        $xml = '<nzb><file subject="Fixture [1/1] &quot;fixture.rar&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
        $manifest = app(NzbManifestReader::class)->read($xml, (object) [
            'groups_id' => 1, 'searchname' => 'Fixture', 'fromname' => 'fixture poster',
            'postdate' => '2026-10-11 12:00:00', 'metadata_source' => 'import',
        ]);

        return $manifest + ['identity' => sha1('private rollback fixture')];
    }

    public static function callbackOrders(): array
    {
        return ['queued first' => [false], 'deferred before delayed queued' => [true]];
    }

    #[DataProvider('callbackOrders')]
    public function test_actual_admission_deferral_preserves_identity_and_allows_exact_retry(bool $delayQueued): void
    {
        $service = $this->offlineImportService();
        $directory = config('release_verification.storage_path').'/upload';
        app('files')->ensureDirectoryExists($directory);
        $path = $directory.'/incoming.nzb';
        file_put_contents($path, $this->passwordFixture([]));
        $manifests = app(NzbUploadManifestService::class);
        $manifests->create($directory, 'private-retry', 'incoming.nzb', null);
        $statuses = [];
        $savedQueued = null;
        $holdQueued = $delayQueued;
        $callback = static function (array $r) use ($manifests, &$statuses, &$savedQueued, &$holdQueued): void {
            $statuses[] = $r['status'];
            if ($holdQueued && $r['status'] === NzbImportStatus::Queued) {
                $savedQueued = $r;

                return;
            } $manifests->recordNzbOutcome($r['path'], $r['status'], $r['release_id'], $r['release_guid'], candidateIdentity: $r['candidate_identity'], inputCapture: $r['input_capture']);
        };
        $service->beginImport([$path], resultCallback: $callback);
        self::assertSame([NzbImportStatus::Queued], $statuses);
        $candidate = DB::table('release_verification_candidates')->first();
        self::assertNotNull($candidate);
        $identity = $candidate->identity;
        config(['release_verification.pending_bytes' => $candidate->manifest_bytes]);
        file_put_contents($path, $this->passwordFixture(['synthetic assertion']));
        $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        self::assertSame([NzbImportStatus::Queued, NzbImportStatus::MetadataDeferred], $statuses);
        if ($savedQueued !== null) {
            $manifests->recordNzbOutcome($savedQueued['path'], $savedQueued['status'], null, null, candidateIdentity: $savedQueued['candidate_identity']);
        }
        $holdQueued = false;
        self::assertSame('metadata_deferred', DB::table('release_verification_candidates')->value('status'));
        $manifest = $manifests->read($directory.'/'.NzbUploadManifestService::FILENAME);
        self::assertSame($identity, $manifest['candidate_identity']);
        self::assertSame('nzb_pending', $manifest['state']);
        self::assertFileExists($path);
        Schema::create('releases', static function (Blueprint $t): void {
            $t->id();
            $t->string('guid');
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        config(['release_verification.pending_bytes' => 1048576]);
        self::assertNull(app(CandidateRepository::class)->claim());
        self::assertTrue($manifests->shouldImportNzb($path), 'Once capacity returns, retained source must be eligible for the exact metadata replay required by the held candidate.');
        self::assertSame($identity, $manifests->read($directory.'/'.NzbUploadManifestService::FILENAME)['candidate_identity']);
        $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        self::assertSame(NzbImportStatus::Queued, end($statuses));
        self::assertNotSame('metadata_deferred', DB::table('release_verification_candidates')->value('status'));
        self::assertSame($identity, DB::table('release_verification_candidates')->value('identity'));
        self::assertFileExists($path);
    }

    public function test_identity_backed_deferral_reconciles_review_and_later_publication(): void
    {
        $identity = sha1('import:held-metadata');
        DB::table('release_verification_candidates')->insert(['identity' => $identity, 'source_identity' => $identity, 'groups_id' => 1,
            'manifest_path' => '', 'manifest_hash' => str_repeat('a', 64), 'manifest_bytes' => 0, 'status' => 'metadata_deferred',
            'last_unique_at' => now(), 'next_attempt_at' => now()]);
        Schema::create('releases', static function (Blueprint $t): void {
            $t->id();
            $t->string('guid');
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        $directory = config('release_verification.storage_path').'/projection';
        app('files')->ensureDirectoryExists($directory);
        $path = $directory.'/incoming.nzb';
        file_put_contents($path, $this->passwordFixture([]));
        $manifests = app(NzbUploadManifestService::class);
        $manifests->create($directory, 'private-projection', 'incoming.nzb', null);
        $manifestPath = $directory.'/'.NzbUploadManifestService::FILENAME;
        $this->recordCapturedOutcome($manifests, $path, NzbImportStatus::Queued, null, null, candidateIdentity: $identity);
        self::assertTrue($manifests->shouldImportNzb($path));
        $held = $manifests->read($manifestPath);
        self::assertSame('metadata_deferred', $held['state']);
        self::assertSame($identity, $held['candidate_identity']);
        foreach ([NzbImportStatus::MetadataIgnored, NzbImportStatus::MetadataDeferred] as $late) {
            $this->recordCapturedOutcome($manifests, $path, $late, null, null);
            self::assertSame($held, $manifests->read($manifestPath));
        }
        DB::table('release_verification_candidates')->where('identity', $identity)->update(['status' => 'metadata_review']);
        self::assertFalse($manifests->shouldImportNzb($path));
        self::assertSame($identity, $manifests->read($manifestPath)['candidate_identity']);
        DB::table('releases')->insert(['id' => 7, 'guid' => 'private-publication']);
        DB::table('release_verification_candidates')->where('identity', $identity)->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
        $this->relinquishCapturedSource($manifests, $path);
        $published = $manifests->reconcilePending($manifestPath);
        self::assertSame('nzb_imported', $published['state']);
        self::assertSame(7, $published['release_id']);
        self::assertSame($identity, $published['candidate_identity']);
    }

    public function test_late_advisory_preserves_terminal_and_malformed_pending_states(): void
    {
        $directory = config('release_verification.storage_path').'/states';
        app('files')->ensureDirectoryExists($directory);
        $path = $directory.'/incoming.nzb';
        $manifests = app(NzbUploadManifestService::class);
        $manifests->create($directory, 'private-states', 'incoming.nzb', null);
        $manifestPath = $directory.'/'.NzbUploadManifestService::FILENAME;
        $base = $manifests->read($manifestPath);
        foreach (['nzb_imported', 'nfo_imported', 'nfo_failed', 'nzb_rejected', 'nzb_duplicate', 'nzb_pending'] as $state) {
            foreach ([null, 'invalid'] as $identity) {
                $before = $base;
                $before['state'] = $state;
                $before['candidate_identity'] = $identity;
                file_put_contents($manifestPath, json_encode($before));
                foreach ([NzbImportStatus::MetadataIgnored, NzbImportStatus::MetadataDeferred] as $late) {
                    $manifests->recordNzbOutcome($path, $late, null, null);
                    self::assertSame($before, $manifests->read($manifestPath));
                }
            }
        }
    }
}
