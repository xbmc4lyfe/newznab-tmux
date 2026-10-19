<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Support\ReleaseArchiveNamingEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CleanNzbStagedGenerationTest extends TestCase
{
    private string $directory;

    private string $guid;

    private string $canonical;

    private string $staged;

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nzbclean-staged-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/a', 0777, true);
        config(['nntmux_settings.path_to_nzbs' => $this->directory]);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();

        // Stale in the command's one-second view; the minimum --temp-age-hours of 1 is made effective below.
        $this->app->instance(NzbService::class, new class(app(CollectionCleanupService::class)) extends NzbService
        {
            public function findStaleTemporaryNzbPaths(int $olderThanSeconds = 86400): array
            {
                return parent::findStaleTemporaryNzbPaths(1);
            }

            public function isStaleStagedNzb(string $path, int $olderThanSeconds = 86400): bool
            {
                return parent::isStaleStagedNzb($path, 1);
            }
        });

        $this->guid = str_repeat('a', 36);
        DB::table('releases')->insert(['id' => 1, 'guid' => $this->guid]);
        // Evidence names the staged generation: its upgrade committed but never swapped it in.
        DB::table('release_archive_naming_evidence')->insert([
            'releases_id' => 1,
            'release_guid' => $this->guid,
            'source_sha256' => NzbSourceGuard::digest('upgraded'),
            'proof_version' => ReleaseArchiveNamingEvidence::PROOF_VERSION,
            'scope' => 'unknown',
            'episode_files' => '[]',
            'updated_at' => now(),
        ]);
        $this->canonical = $this->directory.'/a/'.$this->guid.'.nzb.gz';
        $this->staged = $this->canonical.'.c3-'.str_repeat('b', 16).'.next';
        $this->temporary = $this->directory.'/a/'.str_repeat('c', 36).'.nzb.gz.tmp.123.'.str_repeat('d', 12);
        file_put_contents($this->canonical, gzencode('original'));
        file_put_contents($this->staged, gzencode('upgraded'));
        file_put_contents($this->temporary, 'partial');
        sleep(2);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_temps_dry_run_reports_without_changing_files(): void
    {
        $output = $this->clean(['--temps' => true, '--temp-age-hours' => 1]);

        self::assertStringContainsString('Would apply staged NZB generation: '.$this->staged, $output);
        self::assertStringContainsString('Would delete stale temporary NZB: '.$this->temporary, $output);
        self::assertStringContainsString('Checked (dry run) stale temporary NZBs: 1 deleted, 1 applied, 0 kept.', $output);
        self::assertFileExists($this->staged);
        self::assertFileExists($this->temporary);
        self::assertSame('original', $this->decoded($this->canonical));
    }

    public function test_temps_delete_applies_a_committed_generation_and_removes_the_temporary(): void
    {
        $output = $this->clean(['--temps' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Applied staged NZB generation: '.$this->staged, $output);
        self::assertStringContainsString('Cleaned stale temporary NZBs: 1 deleted, 1 applied, 0 kept.', $output);
        self::assertFileDoesNotExist($this->staged);
        self::assertFileDoesNotExist($this->temporary);
        self::assertSame('upgraded', $this->decoded($this->canonical));
    }

    public function test_temps_dry_run_reports_deleting_a_rolled_back_generation(): void
    {
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('original')]);

        $output = $this->clean(['--temps' => true, '--temp-age-hours' => 1]);

        self::assertStringContainsString('Would delete staged NZB generation: '.$this->staged, $output);
        self::assertStringContainsString('Checked (dry run) stale temporary NZBs: 2 deleted, 0 applied, 0 kept.', $output);
        self::assertFileExists($this->staged);
        self::assertSame('original', $this->decoded($this->canonical));
    }

    public function test_temps_deletes_a_generation_whose_upgrade_rolled_back(): void
    {
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('original')]);

        $output = $this->clean(['--temps' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Deleted staged NZB generation: '.$this->staged, $output);
        self::assertStringContainsString('Cleaned stale temporary NZBs: 2 deleted, 0 applied, 0 kept.', $output);
        self::assertFileDoesNotExist($this->staged);
        self::assertSame('original', $this->decoded($this->canonical));
    }

    public function test_temps_keeps_a_generation_that_matches_neither_source(): void
    {
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('newer')]);

        $output = $this->clean(['--temps' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Kept staged NZB generation for review: '.$this->staged, $output);
        self::assertStringContainsString('Cleaned stale temporary NZBs: 1 deleted, 0 applied, 1 kept.', $output);
        self::assertFileExists($this->staged);
        self::assertSame('original', $this->decoded($this->canonical));
    }

    public function test_notindb_dry_run_reports_the_swap_and_leaves_the_temporary_to_temps(): void
    {
        $output = $this->clean(['--notindb' => true, '--temp-age-hours' => 1]);

        self::assertStringContainsString('Would apply staged NZB generation: '.$this->staged, $output);
        self::assertStringNotContainsString('orphan', strtolower($output), 'Side files are never GUID orphans.');
        self::assertFileExists($this->staged);
        self::assertFileExists($this->temporary);
        self::assertSame('original', $this->decoded($this->canonical));
    }

    public function test_notindb_delete_applies_a_committed_generation(): void
    {
        $output = $this->clean(['--notindb' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Applied staged NZB generation: '.$this->staged, $output);
        self::assertFileDoesNotExist($this->staged);
        self::assertFileExists($this->temporary);
        self::assertSame('upgraded', $this->decoded($this->canonical));
    }

    public function test_notindb_deletes_the_generation_of_a_deleted_release(): void
    {
        DB::table('release_archive_naming_evidence')->delete();
        DB::table('releases')->delete();

        $output = $this->clean(['--notindb' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Deleted staged NZB generation: '.$this->staged, $output);
        self::assertFileDoesNotExist($this->staged);
    }

    public function test_notindb_reports_a_generation_kept_for_review(): void
    {
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('newer')]);

        $output = $this->clean(['--notindb' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Kept staged NZB generation for review: '.$this->staged, $output);
        self::assertFileExists($this->staged);
        self::assertSame('original', $this->decoded($this->canonical));
    }

    public function test_notondisk_installs_a_committed_staged_generation_instead_of_deleting_the_release(): void
    {
        $this->missingCanonicalWithStagedGeneration();

        $output = $this->clean(['--notondisk' => true, '--delete' => true]);

        self::assertStringNotContainsString('Deleted: Movie', $output);
        self::assertSame(1, DB::table('releases')->count(), 'Its committed NZB was only staged.');
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->canonical)));
        self::assertFileDoesNotExist($this->staged);
    }

    public function test_notondisk_keeps_a_release_whose_staged_generation_cannot_be_put_in_place(): void
    {
        $this->missingCanonicalWithStagedGeneration();
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('newer')]);

        $output = $this->clean(['--notondisk' => true, '--delete' => true]);

        self::assertStringContainsString('Kept staged NZB generation for review: '.$this->staged, $output);
        self::assertStringNotContainsString('Deleted: Movie', $output);
        self::assertSame(1, DB::table('releases')->count(), 'The staged file may be its only committed source.');
        self::assertFileExists($this->staged);
    }

    public function test_a_notondisk_dry_run_reports_the_staged_generation_without_installing_it(): void
    {
        $this->missingCanonicalWithStagedGeneration();

        $output = $this->clean(['--notondisk' => true]);

        self::assertStringContainsString('Would apply staged NZB generation: '.$this->staged, $output);
        self::assertStringNotContainsString('Deleted: Movie', $output);
        self::assertFileDoesNotExist($this->canonical);
        self::assertFileExists($this->staged);
    }

    public function test_a_notondisk_dry_run_keeps_a_release_whose_staged_generation_would_be_kept(): void
    {
        $this->missingCanonicalWithStagedGeneration();
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('newer')]);

        $output = $this->clean(['--notondisk' => true]);

        self::assertStringContainsString('Kept staged NZB generation for review: '.$this->staged, $output);
        self::assertStringNotContainsString('Deleted: Movie', $output, 'The dry run reports what --delete would do.');
        self::assertFileExists($this->staged);
    }

    public function test_a_stray_sibling_visited_first_never_defers_a_snapshot_once_the_committed_one_is_in_place(): void
    {
        $this->missingCanonicalWithStagedGeneration();
        // Sorted before the committed generation, and matching neither the committed source nor the canonical file.
        $stray = dirname($this->staged).'/'.$this->guid.'.nzb.gz.c1-'.str_repeat('e', 16).'.next';
        file_put_contents($stray, gzencode('stray'));

        $source = app(NzbSourceGuard::class)->snapshot($this->guid, beginNamingInspection: true);

        self::assertNotNull($source);
        self::assertSame(NzbSourceGuard::digest('upgraded'), $source['digest']);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->canonical)));
        self::assertFileExists($stray, 'Kept for review.');
    }

    public function test_notondisk_finds_a_staged_generation_beside_a_vanished_copy_on_another_search_path(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            $table->integer('nzbstatus')->default(1);
            $table->string('searchname')->default('Movie');
        });
        // Staged beside a copy found at an older split level, which then vanished before its swap ran.
        unlink($this->canonical);
        $older = $this->canonical;
        $this->app->instance(NzbService::class, new class(app(CollectionCleanupService::class), $older) extends NzbService
        {
            public function __construct(CollectionCleanupService $cleanup, private readonly string $older)
            {
                parent::__construct($cleanup);
            }

            public function nzbPathCandidates(string $releaseGuid): array
            {
                return [$this->older, ...parent::nzbPathCandidates($releaseGuid)];
            }
        });

        $output = $this->clean(['--notondisk' => true, '--delete' => true]);

        self::assertStringNotContainsString('Deleted: Movie', $output);
        self::assertSame(1, DB::table('releases')->count());
        self::assertSame('upgraded', gzdecode((string) file_get_contents($older)));
        self::assertFileDoesNotExist($this->staged);
    }

    private function missingCanonicalWithStagedGeneration(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            $table->integer('nzbstatus')->default(1);
            $table->string('searchname')->default('Movie');
        });
        // The canonical file was gone when the upgrade committed, and its swap never ran; both live at the installation path.
        unlink($this->canonical);
        $this->canonical = app(NzbService::class)->getNzbPath($this->guid, createIfNotExist: true);
        $staged = $this->canonical.'.c3-'.str_repeat('b', 16).'.next';
        rename($this->staged, $staged);
        $this->staged = $staged;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function clean(array $arguments): string
    {
        self::assertSame(0, Artisan::call('nntmux:nzbclean', $arguments));

        return Artisan::output();
    }

    private function decoded(string $path): string
    {
        return (string) gzdecode((string) file_get_contents($path));
    }
}
