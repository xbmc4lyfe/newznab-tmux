<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class NfoSourceProvenanceTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const SOURCE = 'Example.Movie.2026.1080p.BluRay.x264-SOURCE';

    private const TARGET = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';

    private EventFake $events;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->useEnvironmentPath(sys_get_temp_dir())->loadEnvironmentFrom('.nntmux-validation-no-env');
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false, 'tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        $this->events = Event::fake([ReleaseNameFixed::class]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provenanceFields(): iterable
    {
        $fields = [
            'Filename control' => ['Filename : '.self::TARGET."\n", self::TARGET],
            'Source only' => ['Source : '.self::SOURCE."\n", 'Hashed.Title'],
            'Source before Filename' => ['Source : '.self::SOURCE."\nFilename : ".self::TARGET."\n", self::TARGET],
            'Source after Filename' => ['Filename : '.self::TARGET."\nSource : ".self::SOURCE."\n", self::TARGET],
            'Release control' => ['Release : '.self::TARGET."\n", self::TARGET],
            'Source continuation' => ["Source :\n    ".self::SOURCE."\n", 'Hashed.Title'],
            'Source indented CRLF' => ['  sOuRcE : '.self::SOURCE."\r\n", 'Hashed.Title'],
        ];
        foreach (['nfo', 'standard', 'checkName'] as $handler) {
            foreach ($fields as $label => [$nfo, $expected]) {
                yield $handler.' '.$label => [$handler, $nfo, $expected];
            }
        }
    }

    #[DataProvider('provenanceFields')]
    public function test_nfo_handlers_use_current_release_fields_and_never_borrow_source_identity(string $handler, string $nfo, string $expected): void
    {
        $this->assertHandlerName($handler, $nfo, $expected);
    }

    /** @return iterable<string, array{string}> */
    public static function handlers(): iterable
    {
        foreach (['nfo', 'standard', 'checkName'] as $handler) {
            yield $handler => [$handler];
        }
    }

    #[DataProvider('handlers')]
    public function test_source_continuation_stops_at_next_field_and_does_not_hide_tv_identity(string $handler): void
    {
        $target = 'Example.Show.S01E02.1080p.HDTV.x264-TARGET';
        $this->assertHandlerName($handler, "Source :\n    Example.Show.S01E02.1080p.HDTV.x264-SOURCE\n    Filename : ".$target."\n", $target);
    }

    #[DataProvider('handlers')]
    public function test_raw_source_metadata_is_still_used_to_enrich_a_genuine_title(string $handler): void
    {
        $this->assertHandlerName($handler, "Example Movie (2026)\nSource : BluRay\nVideo : 1920x1080\nAudio : DTS\n", 'Example.Movie.(2026).1080p.BluRay.DTS-NoGroup');
    }

    #[DataProvider('handlers')]
    public function test_source_is_excluded_from_authoritative_predb_lookup_without_hiding_release_field(string $handler): void
    {
        $target = 'Example.Application.v1.0-TARGET';
        $source = 'Example.Application.v1.0-SOURCE';
        DB::table('predb')->insert([['id' => 6, 'title' => $source], ['id' => 7, 'title' => $target]]);
        $this->assertHandlerName($handler, 'Source : '.$source."\nRelease : ".$target."\n", $target, 7);
    }

    #[DataProvider('handlers')]
    public function test_source_only_cannot_attach_a_real_predb_row(string $handler): void
    {
        $source = 'Example.Application.v1.0-SOURCE';
        DB::table('predb')->insert(['id' => 6, 'title' => $source]);
        $this->assertHandlerName($handler, "Source:\n    ".$source."\n", 'Hashed.Title');
    }

    #[DataProvider('handlers')]
    public function test_blank_line_ends_source_continuation_without_excluding_source_in_a_title(string $handler): void
    {
        $target = 'Source.Code.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->assertHandlerName($handler, 'Source : '.self::SOURCE."\n\n    ".$target."\n", $target);
    }

    #[DataProvider('handlers')]
    public function test_windows_drive_path_remains_a_source_continuation(string $handler): void
    {
        $this->assertHandlerName($handler, "Source :\n    C:\\incoming\\Example.Show.S01E02.1080p.HDTV.x264-SOURCE.mkv\n", 'Hashed.Title');
    }

    #[DataProvider('handlers')]
    public function test_genuine_filename_field_ends_a_drive_path_source_continuation(string $handler): void
    {
        $target = 'Example.Show.S01E02.1080p.HDTV.x264-TARGET';
        $this->assertHandlerName($handler, "Source :\n    C:\\incoming\\Example.Show.S01E02.1080p.HDTV.x264-SOURCE.mkv\n    Filename : D:\\output\\".$target.".mkv\n", $target);
    }

    #[DataProvider('handlers')]
    public function test_stale_source_only_handler_does_not_mark_replacement_processed(string $handler): void
    {
        $this->assertHandlerName($handler, 'Source : '.self::SOURCE."\n", 'Replacement.Identity', replaceDuringExtraction: true);
    }

    #[DataProvider('handlers')]
    public function test_stale_unconfirmed_handler_does_not_rename_or_publish_replacement(string $handler): void
    {
        $this->assertHandlerName($handler, 'Filename : '.self::TARGET."\n", 'Replacement.Identity', replaceDuringExtraction: true);
    }

    #[DataProvider('handlers')]
    public function test_source_only_completion_rejects_replacement_at_the_final_sql_boundary(string $handler): void
    {
        $this->assertHandlerName($handler, 'Source : '.self::SOURCE."\n", 'Replacement.Identity', replaceAtWrite: true);
    }

    #[DataProvider('handlers')]
    public function test_unconfirmed_rename_rejects_replacement_at_the_final_sql_boundary(string $handler): void
    {
        $this->assertHandlerName($handler, 'Filename : '.self::TARGET."\n", 'Replacement.Identity', replaceAtWrite: true);
    }

    private function assertHandlerName(string $handler, string $nfo, string $expected, int $predbId = 0, bool $replaceDuringExtraction = false, bool $replaceAtWrite = false): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'Opaque.Subject', 'searchname' => 'Hashed.Title', 'leftguid' => 'a',
            'passwordstatus' => 0, 'nfostatus' => 1, 'proc_nfo' => 0, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1, 'proc_par2' => 1, 'proc_files' => 1,
        ]);
        DB::table('release_nfos')->insert(['releases_id' => $release->id, 'nfo' => $nfo]);
        $replacedAtWrite = false;
        if ($replaceAtWrite) {
            $releaseId = (int) $release->id;
            DB::connection()->beforeExecuting(static function (string $query) use ($releaseId, &$replacedAtWrite): void {
                if ($replacedAtWrite || ! str_starts_with(strtolower($query), 'update "releases"') || ! str_contains($query, '"proc_nfo"')) {
                    return;
                }
                $replacedAtWrite = true;
                DB::connection()->getPdo()->prepare('UPDATE releases SET guid = ?, name = ?, searchname = ?, proc_nfo = 0 WHERE id = ?')
                    ->execute(['replacement-guid', 'Replacement.Subject', 'Replacement.Identity', $releaseId]);
            });
        }

        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $extractor = $replaceDuringExtraction ? new class((int) $release->id) extends NfoNameExtractor
        {
            public function __construct(private int $releaseId) {}

            public function extractFromNfo(string $nfoContent): ?NameFixResult
            {
                DB::table('releases')->where('id', $this->releaseId)->update([
                    'guid' => 'replacement-guid', 'name' => 'Replacement.Subject',
                    'searchname' => 'Replacement.Identity', 'proc_nfo' => 0,
                ]);

                return parent::extractFromNfo($nfoContent);
            }
        } : null;
        $service = new NameFixingService($updates, nfoExtractor: $extractor);
        if ($handler === 'nfo') {
            $service->fixNamesWithNfo(0, true, 2, true, false);
        } elseif ($handler === 'standard') {
            $service->processStandardBatch('a', 10, false);
        } else {
            $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id, 'relsize' => 5000, 'textstring' => $nfo]);
            $service->checkName($input, true, 'NFO, ', true, false);
        }
        if ($replaceAtWrite) {
            self::assertTrue($replacedAtWrite, 'The race must occur at the actual NFO update statement.');
        }
        if ($replaceDuringExtraction || $replaceAtWrite) {
            $current = $release->fresh();
            self::assertSame([
                'guid' => 'replacement-guid', 'name' => 'Replacement.Subject', 'searchname' => $expected,
                'proc_nfo' => 0, 'isrenamed' => 0, 'predb_id' => 0,
                'fixed' => 0, 'matched' => false, 'events' => 0, 'sync' => [], 'attestation' => null,
            ], [
                'guid' => $current->guid, 'name' => $current->name, 'searchname' => $current->searchname,
                'proc_nfo' => $current->proc_nfo, 'isrenamed' => $current->isrenamed, 'predb_id' => $current->predb_id,
                'fixed' => $updates->fixed, 'matched' => $updates->matched,
                'events' => $this->events->dispatched(ReleaseNameFixed::class)->count(), 'sync' => $published,
                'attestation' => $updates->lastCommittedIdentity(),
            ]);
        }
        self::assertSame($expected, $release->fresh()->searchname);
        self::assertSame($predbId, $release->fresh()->predb_id);
        self::assertSame($nfo, DB::table('release_nfos')->where('releases_id', $release->id)->value('nfo'));
        if ($expected === 'Hashed.Title') {
            Event::assertNotDispatched(ReleaseNameFixed::class);
            self::assertSame(0, $updates->fixed);
        }
    }
}
