<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\ReleaseCleaningService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `[01/10] - "file.ext" yEnc` subjects that no group regular expression matches must still yield a clean
 * release name. Subjects are real ones from alt.binaries.multimedia.rail and similar groups.
 */
class CounterSubjectNamingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge();
        DB::reconnect();
        $pdo = DB::connection()->getPdo();
        if ($pdo instanceof \PDO && method_exists($pdo, 'sqliteCreateFunction')) {
            // `subject REGEXP pattern` calls regexp(pattern, subject) in SQLite.
            $pdo->sqliteCreateFunction('REGEXP', static fn (?string $pattern, ?string $value): int => preg_match('/'.$pattern.'/i', (string) $value));
        }
        DB::statement('CREATE TABLE release_naming_regexes (id INTEGER PRIMARY KEY, group_regex VARCHAR(255), regex VARCHAR(255), status INTEGER DEFAULT 1, ordinal INTEGER DEFAULT 0)');
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title VARCHAR(255), filename VARCHAR(255))');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, name VARCHAR(255), searchname VARCHAR(255), fromname VARCHAR(255), groups_id INTEGER, categories_id INTEGER, isrenamed INTEGER DEFAULT 0)');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.multimedia.rail']);
        Search::shouldReceive('matchPredbExact')->andReturn(null)->byDefault();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function subjects(): iterable
    {
        yield 'par2' => ['[1/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.par2" yEnc', 'Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR'];
        yield 'hyphenated par2 volume' => ['[3/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.vol01-03.par2" yEnc', 'Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR'];
        yield 'mkv' => ['[09/10] - "Dark.Phoenix.2019.UHD.BluRay.2160p.TrueHD.Atmos.7.1.HEVC.REMUX-FraMeSToR.mkv" yEnc', 'Dark.Phoenix.2019.UHD.BluRay.2160p.TrueHD.Atmos.7.1.HEVC.REMUX-FraMeSToR'];
        yield 'rar part with segment counter' => ['[05/40] - "Some.Show.S01E02.1080p.WEB.h264-GRP.part04.rar" yEnc (1/50)', 'Some.Show.S01E02.1080p.WEB.h264-GRP'];
        yield 'split 7z' => ['[2/9] - "Some.Movie.2020.1080p.BluRay.x264-GRP.7z.003" yEnc', 'Some.Movie.2020.1080p.BluRay.x264-GRP'];
        yield 'par2 of a rar' => ['[02/10] - "Some.App.v6.41.Multilingual-GRP.rar.par2" yEnc', 'Some.App.v6.41.Multilingual-GRP'];
        yield 'tar.zst' => ['[1/6] - "Some.Show.S01E01.1080p.WEB.h264-GRP.tar.zst" yEnc', 'Some.Show.S01E01.1080p.WEB.h264-GRP'];
        yield 'spaced p2p name' => ['[01/11] - "Love Island US S08E07 720p AMZN WEB-DL DDP2 0 H 264-RAWR.mkv" yEnc', 'Love Island US S08E07 720p AMZN WEB-DL DDP2 0 H 264-RAWR'];
    }

    #[Test]
    #[DataProvider('subjects')]
    public function counter_and_file_subjects_get_the_file_based_release_name(string $subject, string $expected): void
    {
        $meta = (new ReleaseCleaningService)->releaseCleaner($subject, 'poster@example.com', 'alt.binaries.multimedia.rail');

        $this->assertSame($expected, $meta['cleansubject']);
    }

    #[Test]
    public function hashed_file_names_keep_the_subject_for_the_name_fixing_passes(): void
    {
        $meta = (new ReleaseCleaningService)->releaseCleaner('[1/7] - "a3f9c1d8e2b7a6f50d4c3b2a1908f7e6.par2" yEnc', 'x@y.z', 'alt.binaries.multimedia.rail');

        $this->assertSame('[1/7] - "a3f9c1d8e2b7a6f50d4c3b2a1908f7e6.par2"', $meta['cleansubject']);
    }

    #[Test]
    public function a_bare_name_left_after_stripping_stacked_extensions_keeps_the_subject(): void
    {
        // Without `.rar` faking a group suffix, a name with no group, year or quality tag is not plausible.
        $meta = (new ReleaseCleaningService)->releaseCleaner('[2/5] - "WinRAR 7.23 (x64) Final.rar.par2" yEnc', 'x@y.z', 'alt.binaries.multimedia.rail');

        $this->assertSame('[2/5] - "WinRAR 7.23 (x64) Final.rar.par2"', $meta['cleansubject']);
    }

    #[Test]
    public function the_fixer_cleaner_strips_hyphenated_par2_volumes(): void
    {
        $this->assertSame('Some.Movie-GRP', (new ReleaseCleaningService)->fixerCleaner('Some.Movie-GRP.vol01-03.par2'));
        $this->assertSame('Some.Movie-GRP', (new ReleaseCleaningService)->fixerCleaner('Some.Movie-GRP.vol00+01.par2'));
    }

    #[Test]
    public function the_backlog_command_renames_raw_subject_releases_through_the_name_fixer(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'name' => '[1/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.par2" yEnc', 'searchname' => '[1/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.par2"', 'fromname' => 'a@b.c', 'groups_id' => 1, 'categories_id' => 2040, 'isrenamed' => 0],
            ['id' => 2, 'name' => '[1/7] - "a3f9c1d8e2b7a6f50d4c3b2a1908f7e6.par2" yEnc', 'searchname' => '[1/7] - "a3f9c1d8e2b7a6f50d4c3b2a1908f7e6.par2"', 'fromname' => 'a@b.c', 'groups_id' => 1, 'categories_id' => 10, 'isrenamed' => 0],
            ['id' => 3, 'name' => '[1/2] - "Already.Fixed.2020.1080p.WEB.h264-GRP.mkv" yEnc', 'searchname' => '[1/2] - "Already.Fixed.2020.1080p.WEB.h264-GRP.mkv"', 'fromname' => 'a@b.c', 'groups_id' => 1, 'categories_id' => 10, 'isrenamed' => 1],
            ['id' => 4, 'name' => '[2/9] - "Dark.Phoenix.2019.UHD.BluRay.2160p.TrueHD.Atmos.7.1.HEVC.REMUX-FraMeSToR.vol00-01.par2" yEnc', 'searchname' => '[2/9] - "Dark.Phoenix.2019.UHD.BluRay.2160p.TrueHD.Atmos.7.1.HEVC.REMUX-FraMeSToR.vol00-01.par2"', 'fromname' => 'a@b.c', 'groups_id' => 1, 'categories_id' => 2045, 'isrenamed' => 0],
        ]);
        DB::table('predb')->insert(['id' => 77, 'title' => 'Dark.Phoenix.2019.UHD.BluRay.2160p.TrueHD.Atmos.7.1.HEVC.REMUX-FraMeSToR', 'filename' => '']);
        Search::shouldReceive('matchPredbExact')->with('Dark.Phoenix.2019.UHD.BluRay.2160p.TrueHD.Atmos.7.1.HEVC.REMUX-FraMeSToR')->andReturn(['id' => 77]);

        $calls = [];
        $updater = Mockery::mock(ReleaseUpdateService::class);
        $updater->shouldReceive('updateRelease')->andReturnUsing(function (object $release, string $name, string $method, bool $echo, string $type, bool $nameStatus, bool $show, int $preId) use (&$calls, $updater): void {
            $calls[] = [(int) $release->id, $name, $preId, $nameStatus];
            $updater->fixed++;
        });
        $this->app->instance(ReleaseUpdateService::class, $updater);

        $this->artisan('releases:clean-subject-names', ['--chunk' => 2])
            ->expectsOutputToContain('Renamed 2 of 3 releases (1 linked to PreDB).')
            ->assertSuccessful();

        // The hashed release is skipped, and the already-renamed one is never read.
        $this->assertSame([
            [1, 'Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR', 0, false],
            [4, 'Dark.Phoenix.2019.UHD.BluRay.2160p.TrueHD.Atmos.7.1.HEVC.REMUX-FraMeSToR', 77, false],
        ], $calls);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        DB::table('releases')->insert(['id' => 1, 'name' => '[1/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.par2" yEnc', 'searchname' => '[1/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.par2"', 'fromname' => 'a@b.c', 'groups_id' => 1, 'categories_id' => 2040, 'isrenamed' => 0]);
        $updater = Mockery::mock(ReleaseUpdateService::class);
        $updater->shouldNotReceive('updateRelease');
        $this->app->instance(ReleaseUpdateService::class, $updater);

        $this->artisan('releases:clean-subject-names', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: would rename 1 of 1 releases')
            ->assertSuccessful();
    }
}
