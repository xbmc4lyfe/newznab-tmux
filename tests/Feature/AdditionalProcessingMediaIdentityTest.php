<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Support\Par2FileHashEvidence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Builder\MediaInfoContainerBuilder;
use Mhor\MediaInfo\MediaInfo;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

final class AdditionalProcessingMediaIdentityTest extends TestCase
{
    use CreatesProcessingConfiguration;
    use Par2RenameTestDatabase;

    private string $audioFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        Schema::table('releases', static function (Blueprint $table): void {
            $table->integer('proc_pp')->default(0);
            foreach (['haspreview', 'jpgstatus', 'videostatus'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->string('additional_pp_claim_token')->nullable();
            $table->string('additional_pp_claimed_at')->nullable();
        });
        Schema::table('release_files', static function (Blueprint $table): void {
            $table->bigInteger('size')->default(0);
            $table->string('created_at')->nullable();
            $table->string('updated_at')->nullable();
            $table->integer('passworded')->default(0);
        });
        Schema::create('audio_data', static function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('audioid');
            foreach (['audioformat', 'audiomode', 'audiobitratemode', 'audiobitrate', 'audiochannels', 'audiosamplerate', 'audiolibrary', 'audiolanguage', 'audiotitle'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $this->audioFile = tempnam(sys_get_temp_dir(), 'media-identity-');
        file_put_contents($this->audioFile, 'external tool is mocked');
        config(['nntmux.echocli' => false]);
    }

    protected function tearDown(): void
    {
        @unlink($this->audioFile);
        parent::tearDown();
    }

    public function test_real_scalar_tags_preserve_unicode_quotes_and_attest_same_cycle_evidence(): void
    {
        $context = $this->context();
        $service = $this->service();
        Search::shouldReceive('updateRelease')->atLeast()->once()->with($context->release->id);

        self::assertTrue($service->getAudioInfo($this->audioFile, 'flac', $context, sys_get_temp_dir().'/')['audioInfo']);

        $title = "ROSALÍA - L'Album (2026) FLAC";
        self::assertSame($title, DB::table('releases')->value('searchname'));
        self::assertSame($title, $context->fullPar2EvidenceSnapshot()['searchname']);
        self::assertSame(Category::MUSIC_LOSSLESS, (int) DB::table('releases')->value('categories_id'));
        self::assertSame(1, DB::table('audio_data')->count());
        self::assertTrue(Par2FileHashEvidence::remember($context->release->id, str_repeat('a', 32), str_repeat('b', 32), '1024', $context->fullPar2EvidenceSnapshot()));
        self::assertSame(1, DB::table('par_file_hashes')->count());
    }

    public function test_stale_unlinked_context_enriches_current_linked_identity_without_tag_rename(): void
    {
        $context = $this->context();
        DB::table('releases')->where('id', $context->release->id)->update(['predb_id' => 7]);
        Search::shouldReceive('updateRelease')->once()->with($context->release->id);

        self::assertTrue($this->service()->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);

        self::assertSame('Original.Canonical-GRP', DB::table('releases')->value('searchname'));
        self::assertSame(7, (int) DB::table('releases')->value('predb_id'));
        self::assertSame(1, DB::table('audio_data')->count());
    }

    #[DataProvider('identityRaces')]
    public function test_tool_time_identity_changes_reject_all_metadata(string $column, mixed $value): void
    {
        $context = $this->context();
        $service = $this->service(static function () use ($context, $column, $value): void {
            if ($column === 'alias') {
                $context->release->id = 99;
            } else {
                DB::table('releases')->where('id', $context->release->id)->update([$column => $value]);
            }
        });
        Search::shouldReceive('updateRelease')->never();

        self::assertFalse($service->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertSame(0, DB::table('audio_data')->count());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function identityRaces(): iterable
    {
        yield 'name' => ['searchname', 'Other.Identity-GRP'];
        yield 'guid case' => ['guid', 'GUID-1'];
        yield 'claim owner' => ['additional_pp_claim_token', 'replacement'];
        yield 'processing status' => ['proc_pp', 1];
        yield 'claim expiry' => ['additional_pp_claimed_at', '2000-01-01 00:00:00'];
        yield 'caller alias' => ['alias', null];
    }

    public function test_claim_loss_after_metadata_insert_rolls_back_name_metadata_and_publication(): void
    {
        $context = $this->context();
        DB::listen(static function (QueryExecuted $query) use ($context): void {
            if (str_starts_with($query->sql, 'insert or ignore into "audio_data"')) {
                DB::table('releases')->where('id', $context->release->id)->update(['additional_pp_claim_token' => 'replacement']);
            }
        });
        Search::shouldReceive('updateRelease')->never();

        self::assertFalse($this->service()->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertSame('Original.Canonical-GRP', DB::table('releases')->value('searchname'));
        self::assertSame('Original.Canonical-GRP', $context->fullPar2EvidenceSnapshot()['searchname']);
        self::assertSame(0, DB::table('audio_data')->count());
    }

    public function test_stringable_tags_and_numeric_year_use_the_installed_track_api(): void
    {
        $context = $this->context();
        Search::shouldReceive('updateRelease')->atLeast()->once()->with($context->release->id);
        $service = $this->service(tags: [
            'Performer' => new class implements \Stringable
            {
                public function __toString(): string
                {
                    return 'Björk';
                }
            },
            'Album' => 'Debut', 'Recorded_Date' => 1993,
        ]);

        self::assertTrue($service->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertSame('Björk - Debut (1993) MP3', DB::table('releases')->value('searchname'));
    }

    public function test_overlength_title_cannot_be_truncated_into_a_different_identity(): void
    {
        $context = $this->context();
        Search::shouldReceive('updateRelease')->once()->with($context->release->id);

        self::assertTrue($this->service(tags: ['Performer' => 'Artist', 'Album' => str_repeat('é', 256)])
            ->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertSame('Original.Canonical-GRP', DB::table('releases')->value('searchname'));
        self::assertSame(1, DB::table('audio_data')->count());
        self::assertFalse($context->fullPar2EvidenceDeferred);
    }

    #[DataProvider('pendingEvidenceKinds')]
    public function test_ordinary_tool_failure_does_not_block_other_metadata(bool $strong): void
    {
        $context = $this->context();
        $manager = $this->manager();
        self::assertTrue($manager->addFileInfo(['name' => 'queued.mp3', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        if ($strong) {
            $context->pendingFullParHashes[] = ['full_md5' => str_repeat('a', 32), 'hash16k' => str_repeat('b', 32), 'size' => '1024'];
        }
        $media = $this->service(static function (): void {
            throw new \RuntimeException('External media tool unavailable');
        });
        Search::shouldReceive('updateRelease')->once()->with($context->release->id);

        self::assertFalse($media->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertFalse($context->fullPar2EvidenceDeferred);
        $manager->finalizeRelease($context, false);

        self::assertSame(1, DB::table('release_files')->count());
        self::assertSame($strong ? 1 : 0, DB::table('par_file_hashes')->count());
        self::assertNull(DB::table('releases')->value('additional_pp_claim_token'));
    }

    /** @return iterable<string, array{bool}> */
    public static function pendingEvidenceKinds(): iterable
    {
        yield 'legacy metadata' => [false];
        yield 'full hash metadata' => [true];
    }

    public function test_media_rejected_claim_prevents_legacy_finalization_and_discards_pending_index(): void
    {
        $context = $this->context();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $sync->beginReleaseScope($context->release->id);
        $sync->request($context->release->id);
        $manager = $this->manager($sync);
        self::assertTrue($manager->addFileInfo(['name' => 'queued.mp3', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        self::assertSame([], $context->pendingFullParHashes);
        Search::shouldReceive('updateRelease')->never();
        $media = $this->service(static function () use ($context): void {
            DB::table('releases')->where('id', $context->release->id)->update(['additional_pp_claim_token' => 'replacement-owner']);
        });

        self::assertFalse($media->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertTrue($context->fullPar2EvidenceDeferred);
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();

        self::assertSame('replacement-owner', DB::table('releases')->value('additional_pp_claim_token'));
        self::assertSame(0, DB::table('release_files')->count());
    }

    public function test_tool_exception_does_not_hide_a_concurrent_claim_change(): void
    {
        $context = $this->context();
        Search::shouldReceive('updateRelease')->never();
        $media = $this->service(static function () use ($context): void {
            DB::table('releases')->where('id', $context->release->id)->update(['additional_pp_claim_token' => 'replacement-owner']);
            throw new \RuntimeException('Tool failed after ownership changed');
        });

        self::assertFalse($media->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertTrue($context->fullPar2EvidenceDeferred);
        self::assertSame(0, DB::table('audio_data')->count());
    }

    #[DataProvider('pendingEvidenceKinds')]
    public function test_already_processed_audio_does_not_block_valid_queued_metadata(bool $strong): void
    {
        $context = $this->context();
        DB::table('releases')->where('id', $context->release->id)->update(['proc_pp' => 1]);
        $manager = $this->manager();
        self::assertTrue($manager->addFileInfo(['name' => 'queued.mp3', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        if ($strong) {
            $context->pendingFullParHashes[] = ['full_md5' => str_repeat('a', 32), 'hash16k' => str_repeat('b', 32), 'size' => '1024'];
        }
        Search::shouldReceive('updateRelease')->once()->with($context->release->id);

        self::assertFalse($this->service(toolExpected: false)->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertFalse($context->fullPar2EvidenceDeferred);
        $manager->finalizeRelease($context, false);

        self::assertSame(1, DB::table('release_files')->count());
        self::assertSame($strong ? 1 : 0, DB::table('par_file_hashes')->count());
        self::assertNull(DB::table('releases')->value('additional_pp_claim_token'));
    }

    public function test_invalid_optional_tag_name_preserves_claim_and_allows_other_metadata(): void
    {
        $context = $this->context();
        $manager = $this->manager();
        self::assertTrue($manager->addFileInfo(['name' => 'queued.mp3', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        $context->pendingFullParHashes[] = ['full_md5' => str_repeat('a', 32), 'hash16k' => str_repeat('b', 32), 'size' => '1024'];
        Search::shouldReceive('updateRelease')->twice()->with($context->release->id);
        $media = $this->service(tags: ['Performer' => str_repeat('é', 150), 'Album' => str_repeat('á', 150)]);

        self::assertTrue($media->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertFalse($context->fullPar2EvidenceDeferred);
        self::assertSame('claim', DB::table('releases')->value('additional_pp_claim_token'));
        self::assertSame('Original.Canonical-GRP', DB::table('releases')->value('searchname'));
        $manager->finalizeRelease($context, false);

        self::assertSame(1, DB::table('release_files')->count());
        self::assertSame(1, DB::table('par_file_hashes')->count());
    }

    #[DataProvider('postMediaFinalizationCases')]
    public function test_committed_media_admission_guards_legacy_finalization(string $race): void
    {
        $context = $this->context();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $sync->beginReleaseScope($context->release->id);
        $manager = $this->manager($sync);
        self::assertTrue($manager->addFileInfo(['name' => 'queued.mp3', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        self::assertSame([], $context->pendingFullParHashes);
        $publications = 0;
        Search::shouldReceive('updateRelease')->andReturnUsing(static function () use (&$publications, $context, $race): void {
            $publications++;
            if ($publications === 1 && str_starts_with($race, 'after-commit-')) {
                DB::table('releases')->where('id', $context->release->id)->update(
                    $race === 'after-commit-guid' ? ['guid' => 'GUID-1'] : ['additional_pp_claim_token' => 'replacement-owner'],
                );
            }
        });
        self::assertTrue($this->service(sharedSync: $sync)->getAudioInfo($this->audioFile, 'flac', $context, sys_get_temp_dir().'/')['audioInfo']);
        self::assertTrue($context->audioMetadataCommitted);
        DB::listen(static function (QueryExecuted $query) use ($context, $race): void {
            if ($race === 'file-insert-owner' && str_starts_with($query->sql, 'insert or ignore into "release_files"')) {
                DB::table('releases')->where('id', $context->release->id)->update(['additional_pp_claim_token' => 'replacement-owner']);
            }
            if ($race === 'terminal-timestamp' && str_starts_with($query->sql, 'update "releases" set "haspreview"')) {
                DB::table('releases')->where('id', $context->release->id)->update(['additional_pp_claimed_at' => '2000-01-01 00:00:00']);
            }
        });
        $failed = false;
        try {
            $manager->finalizeRelease($context, false);
        } catch (\RuntimeException) {
            $failed = true;
        }
        $sync->finishReleaseScope();

        self::assertSame(in_array($race, ['file-insert-owner', 'terminal-timestamp'], true), $failed);
        self::assertSame($race === 'stable' ? 1 : 0, DB::table('release_files')->count());
        self::assertSame($race === 'stable' ? 2 : 1, $publications);
        self::assertSame(1, DB::table('audio_data')->count());
        self::assertSame("ROSALÍA - L'Album (2026) FLAC", DB::table('releases')->value('searchname'));
        self::assertSame($race === 'stable' ? null : ($race === 'after-commit-owner' ? 'replacement-owner' : 'claim'), DB::table('releases')->value('additional_pp_claim_token'));
    }

    /** @return iterable<string, array{string}> */
    public static function postMediaFinalizationCases(): iterable
    {
        foreach (['stable', 'after-commit-owner', 'after-commit-guid', 'file-insert-owner', 'terminal-timestamp'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_media_admission_marker_distinguishes_disabled_flags_and_outer_rollback(): void
    {
        $context = $this->context();
        $context->initializeFromConfig(false, false, false, false, false, false);
        $context->markFound('audioInfo');
        self::assertTrue($context->foundAudioInfo);
        self::assertFalse($context->audioMetadataCommitted);
        Search::shouldReceive('updateRelease')->never();
        DB::beginTransaction();
        try {
            $this->service()->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/');
            self::assertFalse($context->audioMetadataCommitted);
        } finally {
            DB::rollBack();
        }
        self::assertFalse($context->audioMetadataCommitted);
        self::assertSame(0, DB::table('audio_data')->count());
    }

    public function test_context_reset_cancels_pending_media_admission_marker(): void
    {
        $context = $this->context();
        Search::shouldReceive('updateRelease')->twice()->with($context->release->id);
        DB::beginTransaction();
        $this->service()->getAudioInfo($this->audioFile, 'mp3', $context, sys_get_temp_dir().'/');
        $context->resetCounters();
        DB::commit();

        self::assertFalse($context->audioMetadataCommitted);
        self::assertSame(1, DB::table('audio_data')->count());
    }

    private function manager(?ReleaseSearchSyncCoordinator $sync = null): ReleaseFileManager
    {
        /** @var NameFixingService&MockInterface $names */
        $names = Mockery::mock(NameFixingService::class);
        $names->shouldReceive('matchPreDbFileNames')->once()->andReturn(0);
        $names->shouldReceive('reset')->andReturnNull();
        $names->shouldReceive('lastCommittedIdentity')->andReturnNull();
        /** @var CategorizationService&MockInterface $category */
        $category = Mockery::mock(CategorizationService::class);
        /** @var NfoService&MockInterface $nfo */
        $nfo = Mockery::mock(NfoService::class);
        /** @var NzbService&MockInterface $nzb */
        $nzb = Mockery::mock(NzbService::class);

        return new ReleaseFileManager($this->makeConfig(), new ReleaseImageService, $nfo, $nzb, $names,
            new ReleaseUpdateService($category), searchSyncCoordinator: $sync);
    }

    private function context(): ReleaseProcessingContext
    {
        return new ReleaseProcessingContext($this->pendingPar2Release([
            'guid' => 'guid-1', 'name' => 'Original.Canonical-GRP', 'searchname' => 'Original.Canonical-GRP',
            'categories_id' => Category::OTHER_MISC, 'additional_pp_claim_token' => 'claim',
            'additional_pp_claimed_at' => now()->format('Y-m-d H:i:s'),
        ]));
    }

    /** @param array<string, mixed>|null $tags */
    private function service(?callable $duringTool = null, ?array $tags = null, bool $toolExpected = true, ?ReleaseSearchSyncCoordinator $sharedSync = null): MediaExtractionService
    {
        $builder = new MediaInfoContainerBuilder;
        $builder->addTrackType('General', []);
        $builder->addTrackType('Audio', $tags ?? ['Performer' => 'ROSALÍA', 'Album' => "L'Album", 'Recorded_Date' => '2026-04-01', 'Title' => 'First track']);
        $media = Mockery::mock(MediaInfo::class);
        $media->shouldReceive('getInfo')->times($toolExpected ? 1 : 0)->andReturnUsing(static function () use ($duringTool, $builder) {
            if ($duringTool !== null) {
                $duringTool();
            }

            return $builder->build();
        });
        /** @var CategorizationService&MockInterface $category */
        $category = Mockery::mock(CategorizationService::class);
        /** @var ReleaseImageService&MockInterface $image */
        $image = Mockery::mock(ReleaseImageService::class);
        $sync = $sharedSync ?? new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $service = new MediaExtractionService(
            $this->makeConfig(['processAudioInfo' => true, 'renameMusicMediaInfo' => true]),
            $image, new ReleaseExtraService, $category, $sync,
        );
        (new ReflectionProperty(MediaExtractionService::class, 'mediaInfo'))->setValue($service, $media);

        return $service;
    }
}
