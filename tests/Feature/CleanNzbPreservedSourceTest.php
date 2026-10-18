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

final class CleanNzbPreservedSourceTest extends TestCase
{
    private string $directory;

    private string $guid;

    private string $canonical;

    private string $preserved;

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nzbclean-preserved-'.bin2hex(random_bytes(8));
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

            public function isStalePreservedNzb(string $path, int $olderThanSeconds = 86400): bool
            {
                return parent::isStalePreservedNzb($path, 1);
            }
        });

        $this->guid = str_repeat('a', 36);
        DB::table('releases')->insert(['id' => 1, 'guid' => $this->guid]);
        // Evidence names the original source, so the replacement rolled back and the backup is restored.
        DB::table('release_archive_naming_evidence')->insert([
            'releases_id' => 1,
            'release_guid' => $this->guid,
            'source_sha256' => NzbSourceGuard::digest('original'),
            'proof_version' => ReleaseArchiveNamingEvidence::PROOF_VERSION,
            'scope' => 'unknown',
            'episode_files' => '[]',
            'updated_at' => now(),
        ]);
        $this->canonical = $this->directory.'/a/'.$this->guid.'.nzb.gz';
        $this->preserved = $this->canonical.'.c3-'.str_repeat('b', 16).'.prev';
        $this->temporary = $this->directory.'/a/'.str_repeat('c', 36).'.nzb.gz.tmp.123.'.str_repeat('d', 12);
        file_put_contents($this->canonical, gzencode('upgraded'));
        file_put_contents($this->preserved, gzencode('original'));
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
        $output = $this->clean('nntmux:nzbclean', ['--temps' => true, '--temp-age-hours' => 1]);

        self::assertStringContainsString('Would restore replaced NZB source: '.$this->preserved, $output);
        self::assertStringContainsString('Would delete stale temporary NZB: '.$this->temporary, $output);
        self::assertStringContainsString('Checked (dry run) stale temporary NZBs: 1 deleted, 1 restored, 0 kept.', $output);
        self::assertFileExists($this->preserved);
        self::assertFileExists($this->temporary);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->canonical)));
    }

    public function test_temps_delete_restores_the_backup_and_removes_the_temporary(): void
    {
        $output = $this->clean('nntmux:nzbclean', ['--temps' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Restored replaced NZB source: '.$this->preserved, $output);
        self::assertStringContainsString('Cleaned stale temporary NZBs: 1 deleted, 1 restored, 0 kept.', $output);
        self::assertFileDoesNotExist($this->preserved);
        self::assertFileDoesNotExist($this->temporary);
        self::assertSame('original', gzdecode((string) file_get_contents($this->canonical)));
    }

    public function test_temps_deletes_a_backup_whose_replacement_committed(): void
    {
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('upgraded')]);

        $output = $this->clean('nntmux:nzbclean', ['--temps' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Deleted replaced NZB source: '.$this->preserved, $output);
        self::assertStringContainsString('Cleaned stale temporary NZBs: 2 deleted, 0 restored, 0 kept.', $output);
        self::assertFileDoesNotExist($this->preserved);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->canonical)));
    }

    public function test_temps_keeps_a_backup_that_matches_neither_source(): void
    {
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('newer')]);

        $output = $this->clean('nntmux:nzbclean', ['--temps' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Kept replaced NZB source for review: '.$this->preserved, $output);
        self::assertStringContainsString('Cleaned stale temporary NZBs: 1 deleted, 0 restored, 1 kept.', $output);
        self::assertFileExists($this->preserved);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->canonical)));
    }

    public function test_notindb_dry_run_reports_the_restore_and_leaves_the_temporary_to_temps(): void
    {
        $output = $this->clean('nntmux:nzbclean', ['--notindb' => true, '--temp-age-hours' => 1]);

        self::assertStringContainsString('Would restore replaced NZB source: '.$this->preserved, $output);
        self::assertStringNotContainsString('orphan', strtolower($output), 'Side files are never GUID orphans.');
        self::assertFileExists($this->preserved);
        self::assertFileExists($this->temporary);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->canonical)));
    }

    public function test_notindb_delete_restores_the_backup(): void
    {
        $output = $this->clean('nntmux:nzbclean', ['--notindb' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Restored replaced NZB source: '.$this->preserved, $output);
        self::assertFileDoesNotExist($this->preserved);
        self::assertFileExists($this->temporary);
        self::assertSame('original', gzdecode((string) file_get_contents($this->canonical)));
    }

    public function test_notindb_reports_a_backup_kept_for_review(): void
    {
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('newer')]);

        $output = $this->clean('nntmux:nzbclean', ['--notindb' => true, '--temp-age-hours' => 1, '--delete' => true]);

        self::assertStringContainsString('Kept replaced NZB source for review: '.$this->preserved, $output);
        self::assertFileExists($this->preserved);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->canonical)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function clean(string $command, array $arguments): string
    {
        self::assertSame(0, Artisan::call($command, $arguments));

        return Artisan::output();
    }
}
