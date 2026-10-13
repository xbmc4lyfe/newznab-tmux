<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\ReleaseDuplicateFinder;
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
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class ReleaseVerificationCandidatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.required' => true, 'nntmux.echocli' => false, 'release_verification.storage_path' => storage_path('framework/testing/verify-'.bin2hex(random_bytes(6)))]);
        $this->configureVerificationDatabase();
        $migration = require database_path('migrations/2026_10_11_154028_create_release_verification_candidates_table.php');
        $migration->up();
        (require database_path('migrations/2026_10_12_004819_create_release_verification_scratch_table.php'))->up();
        (require database_path('migrations/2026_10_12_053000_add_shared_verification_scratch_budget.php'))->up();
        (require database_path('migrations/2026_10_11_175742_create_release_verification_audit_retries_table.php'))->up();
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->binary('collectionhash');
            $table->string('subject');
            $table->string('fromname');
            $table->integer('totalfiles');
            $table->integer('groups_id');
            $table->integer('collection_regexes_id')->default(0);
            $table->dateTime('date');
            $table->integer('filecheck')->default(3);
            $table->integer('filesize')->default(5);
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->id();
            $table->integer('collections_id');
            $table->binary('binaryhash');
            $table->string('name');
            $table->integer('totalparts');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->integer('binaries_id');
            $table->integer('partnumber');
            $table->string('messageid');
            $table->integer('size');
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
    }

    protected function configureVerificationDatabase(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
    }

    protected function tearDown(): void
    {
        $path = (string) config('release_verification.storage_path');
        if (is_dir($path)) {
            app('files')->deleteDirectory($path);
        }
        parent::tearDown();
    }

    public function test_archive_preserves_declared_counts_before_removing_hot_rows(): void
    {
        $id = $this->collection('a@b');
        app(CandidateRepository::class)->archive($id);
        $candidate = DB::table('release_verification_candidates')->first();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame(2, $manifest['expected_files']);
        $this->assertSame('a@b', $manifest['files'][0]['segments'][0]['messageid']);
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_snapshot_batches_part_reads_and_preserves_empty_files_and_segment_order(): void
    {
        $id = $this->collection('seed@article');
        DB::table('parts')->delete();
        DB::table('binaries')->delete();
        $expected = [];
        for ($file = 0; $file < 101; $file++) {
            $key = md5('file:'.$file);
            $binaryId = DB::table('binaries')->insertGetId(['collections_id' => $id,
                'binaryhash' => hex2bin($key), 'name' => 'file'.$file.'.rar', 'totalparts' => 2]);
            $segments = [];
            if ($file !== 50) {
                foreach ([2, 1] as $number) {
                    $messageId = 'file'.$file.'.part'.$number.'@article';
                    DB::table('parts')->insert(['binaries_id' => $binaryId, 'partnumber' => $number,
                        'messageid' => $messageId, 'size' => $number * 5]);
                    $segments[$number] = ['number' => $number, 'messageid' => $messageId, 'bytes' => $number * 5];
                }
                ksort($segments);
            }
            $expected[$key] = ['key' => $key, 'subject' => 'file'.$file.'.rar', 'totalparts' => 2,
                'segments' => array_values($segments)];
        }
        ksort($expected);
        $partReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$partReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "parts"')) {
                $partReads++;
            }
        });

        $manifest = (new ReflectionMethod(CandidateRepository::class, 'snapshot'))->invoke(
            app(CandidateRepository::class), DB::table('collections')->where('id', $id)->first());

        $this->assertSame(array_values($expected), $manifest['files']);
        $this->assertSame(2, $partReads);
    }

    public function test_batched_snapshot_rejects_oversized_metadata_without_removing_headers(): void
    {
        $id = $this->collection('seed@article');
        DB::table('parts')->update(['messageid' => str_repeat('x', 16777216)]);
        try {
            app(CandidateRepository::class)->archive($id);
            $this->fail('Oversized metadata must not be archived.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Candidate exceeds metadata budget; headers retained.', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_late_segments_invalidate_a_claim_without_losing_earlier_articles(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collection('a@b'));
        $claim = $repository->claim();
        $repository->archive($this->collection('c@d', 2));
        $candidate = DB::table('release_verification_candidates')->first();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertCount(2, $manifest['files'][0]['segments']);
        $this->assertNotSame($claim->version, $candidate->version);
        $this->expectException(RuntimeException::class);
        $repository->heartbeat($claim->id, $claim->lease_token);
    }

    public function test_duplicate_arrivals_do_not_extend_expiry(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collection('a@b'));
        $before = DB::table('release_verification_candidates')->value('last_unique_at');
        $this->travel(1)->hours();
        $repository->archive($this->collection('a@b'));
        $this->assertSame($before, DB::table('release_verification_candidates')->value('last_unique_at'));
    }

    public function test_published_collection_hash_does_not_discard_a_different_repost(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collection('old@article'));
        DB::table('release_verification_candidates')->update(['release_id' => 123, 'status' => 'complete']);
        $repository->archive($this->collection('new@article'));
        $this->assertSame(2, DB::table('release_verification_candidates')->count());
        $pending = DB::table('release_verification_candidates')->whereNull('release_id')->first();
        $manifest = app(ManifestStore::class)->read($pending->manifest_path);
        $this->assertSame('new@article', $manifest['files'][0]['segments'][0]['messageid']);
    }

    public function test_import_queues_without_creating_a_public_release(): void
    {
        config(['release_verification.required' => true]);
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
        });
        $xml = '<nzb><file subject="Release [1/1] &quot;movie.mkv&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $service = app(NzbImportService::class);
        $status = (new ReflectionMethod($service, 'insertNZB'))->invoke($service, ['verificationXml' => $xml,
            'groups_id' => 1, 'useFName' => 'Movie', 'subject' => 'Movie', 'from' => 'poster', 'postDate' => '2026-10-11 12:00:00', 'articleFingerprint' => sha1('a@b')]);
        $this->assertSame(NzbImportStatus::Queued, $status);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
    }

    #[DataProvider('invalidFreshAdmissionContexts')]
    public function test_fresh_admission_rejects_invalid_password_metadata_without_mutation(mixed $values, string $context): void
    {
        $repository = app(CandidateRepository::class);
        $manifest = $this->queuedImportManifest();
        if ($context !== 'empty') {
            $seed = $manifest;
            if ($context === 'other candidate') {
                $seed['identity'] = sha1('unrelated fixture');
            }
            $repository->enqueue($seed);
            if ($context === 'deleted publication') {
                Schema::create('releases', static fn (Blueprint $table) => $table->id());
                DB::table('release_verification_candidates')->update(['release_id' => 42, 'status' => 'complete']);
            }
        }
        $before = DB::table('release_verification_state')->first();
        $rows = DB::table('release_verification_candidates')->get()->all();
        $artifacts = glob(config('release_verification.storage_path').'/manifests/*');
        $this->assertFalse($repository->enqueue($manifest + ['passwords' => $values]));
        $this->assertEquals($rows, DB::table('release_verification_candidates')->get()->all());
        $this->assertEquals($before, DB::table('release_verification_state')->first());
        $this->assertSame($artifacts, glob(config('release_verification.storage_path').'/manifests/*'));
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidFreshAdmissionContexts(): array
    {
        $cases = [];
        foreach (self::invalidFreshPasswordMetadata() as $name => [$value]) {
            foreach (['empty', 'other candidate', 'deleted publication'] as $context) {
                $cases[$name.' '.$context] = [$value, $context];
            }
        }

        return $cases;
    }

    /** @return array<string, array{mixed}> */
    public static function invalidFreshPasswordMetadata(): array
    {
        return ReleaseVerificationCommandsTest::invalidWriterPasswordMetadata() + [
            'null' => [null], 'scalar' => ['fixture'], 'sparse' => [[1 => 'fixture']],
            'surrogate' => [["\xED\xA0\x80"]], 'noncharacter FFFE' => [["\u{FFFE}"]],
        ];
    }

    #[DataProvider('invalidFreshPasswordMetadata')]
    public function test_manifest_preparation_rejects_invalid_password_metadata_before_json_substitution(mixed $values): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid candidate password metadata.');
        app(ManifestStore::class)->prepare(['passwords' => $values]);
    }

    public function test_valid_fresh_password_metadata_preserves_exact_supported_values(): void
    {
        $values = ['', " \t\n\r ", '<fixture> & "quote"', "fixture\u{1F680}", str_repeat('é', 2048)];
        $values = array_pad($values, 64, 'repeated fixture');
        $manifest = $this->queuedImportManifest() + ['passwords' => $values];
        $this->assertTrue(app(CandidateRepository::class)->enqueue($manifest));
        $stored = app(ManifestStore::class)->read(DB::table('release_verification_candidates')->value('manifest_path'));
        $this->assertSame($manifest, $stored);
        $xml = (new VerifiedNzbWriter(app(NzbService::class)))->document($stored);
        $roundTrip = app(NzbManifestReader::class)->read($xml, (object) ['groups_id' => 1, 'searchname' => 'Fixture', 'fromname' => 'poster', 'postdate' => '2026-10-13 12:00:00']);
        $this->assertSame($values, $roundTrip['passwords']);
    }

    public function test_exact_import_replay_retains_later_supplied_password_metadata(): void
    {
        $first = $this->queuedImportManifest();
        $repository = app(CandidateRepository::class);
        $repository->enqueue($first);
        $before = DB::table('release_verification_candidates')->first();
        $this->travel(1)->hours();
        $second = $first + ['passwords' => ['  synthetic <tag> & value  ', 'second synthetic value']];
        $repository->enqueue($second);
        $after = DB::table('release_verification_candidates')->first();
        $stored = app(ManifestStore::class)->read($after->manifest_path);

        $this->assertSame($second, $stored);
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
        $this->assertSame($before->identity, $after->identity);
        $this->assertSame($before->last_unique_at, $after->last_unique_at);
        $this->assertSame($before->version + 1, $after->version);
        $this->assertNotSame($before->manifest_hash, $after->manifest_hash);
        $this->assertSame($after->manifest_bytes, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertFileDoesNotExist(app(ManifestStore::class)->path($before->manifest_path));

        $this->assertTrue($repository->enqueue($second));
        $this->assertEquals($after, DB::table('release_verification_candidates')->first());
        $this->assertFalse($repository->enqueue($first + ['passwords' => ['different synthetic value']]));
        $this->assertEquals($after, DB::table('release_verification_candidates')->first());
    }

    public function test_metadata_enrichment_revokes_stale_verification_without_extending_article_age(): void
    {
        $repository = app(CandidateRepository::class);
        $manifest = $this->queuedImportManifest();
        $repository->enqueue($manifest);
        $claim = $repository->claim();
        $this->assertNotNull($claim);
        $writer = new VerifiedNzbWriter($this->createStub(NzbService::class));
        $publisher = new VerifiedReleasePublisher(
            $writer,
            new ReleaseMetadataResolver(new ReleaseCleaningService),
            new ReleaseDuplicateFinder,
        );
        $this->travel(1)->hours();
        $repository->enqueue($manifest + ['passwords' => ['synthetic replay value']]);
        $replacement = DB::table('release_verification_candidates')->first();
        $this->assertSame('pending', $replacement->status);
        $this->assertNull($replacement->lease_token);
        $this->assertNull($replacement->lease_until);
        $this->assertSame($claim->last_unique_at, $replacement->last_unique_at);
        $this->assertGreaterThan($claim->version, $replacement->version);
        try {
            $repository->heartbeat($claim->id, $claim->lease_token);
            $this->fail('Old worker must lose its claim.');
        } catch (RuntimeException $error) {
            $this->assertSame('Verification lease was lost.', $error->getMessage());
        }
        $repository->finish($claim, new VerificationResult('unrepairable', 'stale fixture'));
        $this->assertEquals($replacement, DB::table('release_verification_candidates')->first());
        try {
            $publisher->publish($claim,
                new VerificationResult('complete', 'fixture', $manifest));
            $this->fail('Old worker must not publish.');
        } catch (RuntimeException $error) {
            $this->assertSame('Stale verification result cannot publish.', $error->getMessage());
        }
        $fresh = $repository->claim();
        $this->assertSame($replacement->version, $fresh->version);
        $stored = app(ManifestStore::class)->read($fresh->manifest_path);
        $xml = $writer->document($stored);
        $roundTrip = app(NzbManifestReader::class)->read($xml, (object) [
            'groups_id' => 1, 'searchname' => 'Fixture', 'fromname' => 'fixture poster', 'postdate' => '2026-10-11 12:00:00',
        ]);
        $this->assertSame($stored['passwords'], $roundTrip['passwords']);
    }

    #[DataProvider('ineligibleMetadataReplays')]
    public function test_ineligible_metadata_replay_preserves_candidate_and_capacity(string $change): void
    {
        $repository = app(CandidateRepository::class);
        $original = $this->queuedImportManifest();
        if ($change === 'audit original') {
            $original['metadata_source'] = 'audit';
        }
        $repository->enqueue($original);
        $incoming = $original + ['passwords' => ['synthetic replay value']];
        switch ($change) {
            case 'verification mode':
                $incoming['verification_mode'] = 'spot_check';
                break;
            case 'declared inventory':
                $incoming['expected_files']++;
                break;
            case 'different article':
                $incoming['files'][0]['segments'][0]['messageid'] = 'different@example.test';
                break;
            case 'different subject':
                $incoming['subject'] = 'Different fixture';
                break;
            case 'different group':
                $incoming['groups'] = ['alt.other'];
                break;
            case 'unknown metadata':
                $incoming['extra'] = true;
                break;
            case 'conflict':
                $incoming['conflict'] = true;
                break;
            case 'audit incoming':
                $incoming['metadata_source'] = 'audit';
                break;
            case 'filtered':
            case 'unrepairable':
            case 'expired':
            case 'complete':
                DB::table('release_verification_candidates')->update(['status' => $change]);
                break;
            case 'published':
                Schema::create('releases', static fn (Blueprint $table) => $table->id());
                DB::table('releases')->insert(['id' => 42]);
                DB::table('release_verification_candidates')->update(['release_id' => 42, 'status' => 'complete']);
                break;
            case 'invalid list':
                $incoming['passwords'] = ['named' => 'synthetic value'];
                break;
            case 'overlong value':
                $incoming['passwords'] = [str_repeat('x', 4097)];
                break;
            case 'invalid utf8':
                $incoming['passwords'] = ["fixture\xFFvalue"];
                break;
            case 'xml control':
                $incoming['passwords'] = ["fixture\0value"];
                break;
        }
        $before = DB::table('release_verification_candidates')->first();
        $budget = DB::table('release_verification_state')->value('pending_bytes');
        $this->assertFalse($repository->enqueue($incoming));
        $this->assertEquals($before, DB::table('release_verification_candidates')->first());
        $this->assertSame($budget, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame($original, app(ManifestStore::class)->read($before->manifest_path));
    }

    /** @return array<string, array{string}> */
    public static function ineligibleMetadataReplays(): array
    {
        $cases = ['verification mode', 'declared inventory', 'different article', 'different subject', 'different group', 'unknown metadata', 'conflict',
            'audit original', 'audit incoming', 'filtered', 'unrepairable', 'expired', 'complete', 'published', 'invalid list', 'overlong value', 'invalid utf8', 'xml control'];

        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }

    public function test_password_metadata_enrichment_rolls_back_manifest_and_capacity_with_outer_transaction(): void
    {
        $repository = app(CandidateRepository::class);
        $manifest = $this->queuedImportManifest();
        $repository->enqueue($manifest);
        $before = DB::table('release_verification_candidates')->first();
        $budget = DB::table('release_verification_state')->value('pending_bytes');
        DB::beginTransaction();
        try {
            $repository->enqueue($manifest + ['passwords' => ['synthetic replay value']]);
            $newPath = DB::table('release_verification_candidates')->value('manifest_path');
            $this->assertNotSame($before->manifest_path, $newPath);
            $this->assertFileExists(app(ManifestStore::class)->path($before->manifest_path));
            $this->assertFileExists(app(ManifestStore::class)->path($newPath));
        } finally {
            DB::rollBack();
        }
        $this->assertEquals($before, DB::table('release_verification_candidates')->first());
        $this->assertSame($budget, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertFileExists(app(ManifestStore::class)->path($before->manifest_path));
        $this->assertFileDoesNotExist(app(ManifestStore::class)->path($newPath));
    }

    public function test_password_metadata_enrichment_capacity_failure_retains_original_manifest(): void
    {
        $repository = app(CandidateRepository::class);
        $manifest = $this->queuedImportManifest();
        $repository->enqueue($manifest);
        $before = DB::table('release_verification_candidates')->first();
        $budget = DB::table('release_verification_state')->value('pending_bytes');
        config(['release_verification.pending_bytes' => 1]);
        $this->assertFalse($repository->enqueue($manifest + ['passwords' => ['synthetic replay value']]));
        $this->assertEquals($before, DB::table('release_verification_candidates')->first());
        $this->assertSame($budget, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame([$before->manifest_path], array_map('basename', glob(config('release_verification.storage_path').'/manifests/*.json.gz')));
    }

    public function test_importer_replay_preserves_supplied_metadata_through_the_existing_reader_and_writer(): void
    {
        $baseXml = '<nzb><file subject="Fixture [1/1] &quot;fixture.rar&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
        $details = ['verificationXml' => $baseXml, 'groups_id' => 1, 'useFName' => 'Fixture', 'subject' => 'Fixture',
            'from' => 'fixture poster', 'postDate' => '2026-10-11 12:00:00', 'articleFingerprint' => sha1('fixture@example.test')];
        $importer = app(NzbImportService::class);
        $insert = new ReflectionMethod($importer, 'insertNZB');
        $this->assertSame(NzbImportStatus::Queued, $insert->invoke($importer, $details));
        $values = ['  synthetic <tag> & value  ', 'repeated fixture', 'repeated fixture'];
        $head = '<head>';
        foreach ($values as $value) {
            $head .= '<meta type="password">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</meta>';
        }
        $head .= '</head>';
        $details['verificationXml'] = str_replace('<nzb>', '<nzb>'.$head, $baseXml);
        $before = DB::table('release_verification_candidates')->first();
        $budget = DB::table('release_verification_state')->value('pending_bytes');
        $capacity = config('release_verification.pending_bytes');
        config(['release_verification.pending_bytes' => 1]);
        try {
            $this->assertSame(NzbImportStatus::MetadataIgnored, $insert->invoke($importer, $details));
            $this->assertEquals($before, DB::table('release_verification_candidates')->first());
            $this->assertSame($budget, DB::table('release_verification_state')->value('pending_bytes'));
            $this->assertSame([$before->manifest_path], array_map('basename', glob(config('release_verification.storage_path').'/manifests/*.json.gz')));
        } finally {
            config(['release_verification.pending_bytes' => $capacity]);
        }
        $this->assertSame(NzbImportStatus::Queued, $insert->invoke($importer, $details));
        $candidate = DB::table('release_verification_candidates')->first();
        $stored = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame($values, $stored['passwords']);
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
        $xml = (new VerifiedNzbWriter(app(NzbService::class)))->document($stored);
        $document = simplexml_load_string($xml);
        $this->assertNotFalse($document);
        $actual = [];
        foreach ($document->head->meta as $metadata) {
            if ((string) $metadata['type'] === 'password') {
                $actual[] = (string) $metadata;
            }
        }
        $this->assertSame($values, $actual);
        $before = DB::table('release_verification_candidates')->first();
        $details['verificationXml'] = str_replace('<nzb>', '<nzb><head><meta type="password">conflicting synthetic metadata</meta></head>', $baseXml);
        $this->assertSame(NzbImportStatus::MetadataIgnored, $insert->invoke($importer, $details));
        $this->assertEquals($before, DB::table('release_verification_candidates')->first());
    }

    public function test_password_metadata_update_failure_rolls_back_new_storage_and_capacity(): void
    {
        $repository = app(CandidateRepository::class);
        $manifest = $this->queuedImportManifest();
        $repository->enqueue($manifest);
        $before = DB::table('release_verification_candidates')->first();
        $budget = DB::table('release_verification_state')->value('pending_bytes');
        $injected = false;
        DB::listen(static function (QueryExecuted $query) use (&$injected): void {
            if (! $injected && str_starts_with($query->sql, 'update "release_verification_candidates"')) {
                $injected = true;
                throw new RuntimeException('Synthetic candidate update failure.');
            }
        });
        try {
            $repository->enqueue($manifest + ['passwords' => ['synthetic replay value']]);
            $this->fail('Candidate update fault must not escape rollback.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic candidate update failure.', $error->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertEquals($before, DB::table('release_verification_candidates')->first());
        $this->assertSame($budget, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame([$before->manifest_path], array_map('basename', glob(config('release_verification.storage_path').'/manifests/*.json.gz')));
    }

    /** @return array<string, mixed> */
    private function queuedImportManifest(): array
    {
        $xml = '<nzb><file subject="Fixture [1/1] &quot;fixture.rar&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
        $manifest = app(NzbManifestReader::class)->read($xml, (object) [
            'groups_id' => 1, 'searchname' => 'Fixture', 'fromname' => 'fixture poster',
            'postdate' => '2026-10-11 12:00:00', 'metadata_source' => 'import',
        ]);

        return $manifest + ['identity' => sha1('fixture import articles')];
    }

    public function test_duplicate_hot_articles_do_not_invalidate_a_running_verification(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collection('a@b'));
        $claim = $repository->claim();
        $this->travel(1)->hours();
        $id = $this->collection('a@b');
        DB::transaction(fn () => $repository->invalidateHotCollections([$id]));
        $candidate = DB::table('release_verification_candidates')->first();
        $this->assertSame($claim->lease_token, $candidate->lease_token);
        $this->assertSame($claim->last_unique_at, $candidate->last_unique_at);
    }

    public function test_new_hot_article_invalidates_lease_and_is_eligible_for_immediate_archival(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collection('a@b'));
        $claim = $repository->claim();
        $id = $this->collection('new@article', 2);
        DB::table('collections')->where('id', $id)->update(['filecheck' => 0]);
        DB::transaction(fn () => $repository->invalidateHotCollections([$id]));
        $this->assertSame(3, (int) DB::table('collections')->where('id', $id)->value('filecheck'));
        $this->assertNull(DB::table('release_verification_candidates')->value('lease_token'));
        $repository->archive($id);
        $this->assertNotNull($repository->claim());
    }

    public function test_conflicting_declared_part_count_invalidates_lease_without_a_new_article(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collection('a@b'));
        $claim = $repository->claim();
        $id = $this->collection('a@b');
        DB::table('binaries')->where('collections_id', $id)->update(['totalparts' => 3]);
        DB::transaction(fn () => $repository->invalidateHotCollections([$id]));
        $this->assertNull(DB::table('release_verification_candidates')->value('lease_token'));
        $this->assertGreaterThan($claim->version, DB::table('release_verification_candidates')->value('version'));
    }

    private function collection(string $messageId, int $part = 1): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('test', true), 'subject' => 'Movie', 'fromname' => 'poster',
            'totalfiles' => 2, 'groups_id' => 1, 'date' => '2026-10-11 12:00:00']);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5('file:1', true), 'name' => 'movie.mkv', 'totalparts' => 2]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => $messageId, 'size' => 5]);

        return $id;
    }
}
