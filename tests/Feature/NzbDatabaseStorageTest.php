<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\GetNzbController;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Support\ReleaseArchiveNamingEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class NzbDatabaseStorageTest extends TestCase
{
    private string $directory;

    private string $guid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nzb-db-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
        config(['nntmux_settings.path_to_nzbs' => $this->directory, 'nntmux_settings.nzb_storage' => 'database']);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        (require base_path('database/migrations/2026_10_20_120000_create_release_nzbs_table.php'))->up();
        $this->guid = str_repeat('a', 40);
        DB::table('releases')->insert(['id' => 1, 'guid' => $this->guid]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_verified_nzb_is_stored_as_a_row_without_a_file(): void
    {
        $writer = app(VerifiedNzbWriter::class);
        $manifest = $this->manifest('first.rar');

        $writer->write($manifest, $this->guid);

        $document = $writer->document($manifest);
        $body = DB::table('release_nzbs')->where('releases_id', 1)->value('body');
        self::assertIsString($body);
        self::assertSame($document, gzdecode($body));
        self::assertSame(strlen($body), (int) DB::table('release_nzbs')->where('releases_id', 1)->value('bytes'));
        self::assertSame(NzbSourceGuard::digest($document), DB::table('release_nzbs')->where('releases_id', 1)->value('source_sha256'));
        self::assertSame(NzbSourceGuard::digest($document), $this->committedDigest());
        self::assertFileDoesNotExist($this->nzb()->getNzbPath($this->guid));
        self::assertFalse($this->nzb()->nzbPath($this->guid));
        self::assertTrue($this->nzb()->hasNzb($this->guid));
        self::assertSame($document, $this->nzb()->readNzbContents($this->guid));
        self::assertSame($body, $this->nzb()->readNzbCompressed($this->guid));
        self::assertTrue($writer->matches($manifest, $this->guid));
    }

    public function test_a_rolled_back_installation_leaves_no_row(): void
    {
        $writer = app(VerifiedNzbWriter::class);

        $failure = null;
        try {
            DB::transaction(function () use ($writer): void {
                $release = DB::table('releases')->where('id', 1)->lockForUpdate()->first(['id', 'guid']);
                $receipt = $writer->writeWithReceipt($this->manifest('first.rar'), $this->guid, $release);
                DB::afterRollBack(static fn () => $receipt->rollback());
                DB::afterCommit(static fn () => $receipt->close());
                self::assertTrue(DB::table('release_nzbs')->where('releases_id', 1)->exists());

                throw new RuntimeException('publication failed');
            });
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        self::assertSame('publication failed', $failure);

        self::assertFalse(DB::table('release_nzbs')->exists());
        self::assertNull($this->committedDigest());
        self::assertFalse($this->nzb()->readNzbContents($this->guid));
    }

    public function test_an_upgrade_replaces_the_source_in_its_transaction_and_removes_the_legacy_file(): void
    {
        $legacy = $this->legacyFile('legacy');
        $writer = app(VerifiedNzbWriter::class);
        $manifest = $this->manifest('upgraded.rar');

        $receipt = DB::transaction(function () use ($writer, $manifest) {
            $release = DB::table('releases')->where('id', 1)->lockForUpdate()->first(['id', 'guid']);
            $receipt = $writer->writeWithReceipt($manifest, $this->guid, $release, replaceFor: 7);
            DB::afterRollBack(static fn () => $receipt->rollback());
            DB::afterCommit(static fn () => $receipt->close());

            return $receipt;
        });

        self::assertNull($receipt->staged);
        self::assertFileDoesNotExist($legacy);
        self::assertSame([], glob($this->directory.'/*/*.next') ?: []);
        self::assertSame($writer->document($manifest), $this->nzb()->readNzbContents($this->guid));
        self::assertSame(NzbSourceGuard::digest($writer->document($manifest)), $this->committedDigest());
    }

    public function test_legacy_files_stay_readable_until_imported_and_import_moves_them(): void
    {
        $legacy = $this->legacyFile('<nzb>legacy</nzb>');
        $raw = (string) file_get_contents($legacy);

        self::assertSame('<nzb>legacy</nzb>', $this->nzb()->readNzbContents($this->guid));
        self::assertSame($raw, $this->nzb()->readNzbCompressed($this->guid));
        self::assertSame('<nzb>legacy</nzb>', $this->nzb()->readNzbContentsBounded($this->guid, 1024, 1024));
        self::assertTrue($this->nzb()->hasNzb($this->guid));

        Artisan::call('nzb:import-files', ['--delete-files' => true]);

        self::assertStringContainsString('imported=1 already=0 missing=0 unresolved=0 conflict=0 failed=0 last_release_id=1', Artisan::output());
        self::assertSame($raw, DB::table('release_nzbs')->where('releases_id', 1)->value('body'));
        self::assertFileDoesNotExist($legacy);
        self::assertSame('<nzb>legacy</nzb>', $this->nzb()->readNzbContents($this->guid));

        Artisan::call('nzb:import-files');

        self::assertStringContainsString('imported=0 already=1', Artisan::output());
    }

    public function test_import_keeps_a_file_that_differs_from_the_recorded_source(): void
    {
        $legacy = $this->legacyFile('<nzb>legacy</nzb>');
        $this->recordDigest('<nzb>something else</nzb>');

        Artisan::call('nzb:import-files', ['--delete-files' => true]);

        self::assertStringContainsString('imported=0 already=0 missing=0 unresolved=0 conflict=1', Artisan::output());
        self::assertFileExists($legacy);
        self::assertFalse(DB::table('release_nzbs')->exists());
    }

    public function test_import_takes_the_committed_generation_still_staged_beside_its_file(): void
    {
        $legacy = $this->legacyFile('original');
        $staged = $legacy.'.c3-'.str_repeat('b', 16).'.next';
        file_put_contents($staged, gzencode('upgraded'));
        $this->recordDigest('upgraded');

        Artisan::call('nzb:import-files', ['--delete-files' => true]);

        self::assertStringContainsString('imported=1', Artisan::output());
        self::assertSame('upgraded', $this->nzb()->readNzbContents($this->guid));
        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($staged);
    }

    public function test_import_counts_releases_without_any_nzb_as_missing(): void
    {
        Artisan::call('nzb:import-files');

        self::assertStringContainsString('imported=0 already=0 missing=1', Artisan::output());
    }

    public function test_import_refuses_to_run_in_files_mode(): void
    {
        config(['nntmux_settings.nzb_storage' => 'files']);

        self::assertSame(1, Artisan::call('nzb:import-files'));
    }

    public function test_deleting_an_nzb_removes_its_row_and_legacy_file(): void
    {
        app(VerifiedNzbWriter::class)->write($this->manifest('first.rar'), $this->guid);
        $legacy = $this->legacyFile('stale');

        self::assertTrue($this->nzb()->deleteNzb($this->guid));

        self::assertFalse(DB::table('release_nzbs')->exists());
        self::assertFileDoesNotExist($legacy);
        self::assertFalse($this->nzb()->hasNzb($this->guid));
    }

    public function test_deleting_a_release_cascades_to_its_row(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');
        app(VerifiedNzbWriter::class)->write($this->manifest('first.rar'), $this->guid);

        DB::table('releases')->where('id', 1)->delete();

        self::assertFalse(DB::table('release_nzbs')->exists());
    }

    public function test_bounded_reads_refuse_a_row_over_the_compressed_limit_without_loading_it(): void
    {
        app(VerifiedNzbWriter::class)->write($this->manifest('first.rar'), $this->guid);

        self::assertFalse($this->nzb()->readNzbContentsBounded($this->guid, 8, 1_000_000, $used));
        self::assertSame(1_000_000, $used);
        $document = app(VerifiedNzbWriter::class)->document($this->manifest('first.rar'));
        self::assertSame($document, $this->nzb()->readNzbContentsBounded($this->guid, 1_000_000, 1_000_000, $used));
        self::assertSame(strlen($document), $used);
    }

    public function test_a_parser_repair_compares_and_replaces_the_row(): void
    {
        $writer = app(VerifiedNzbWriter::class);
        $writer->write($this->manifest('first.rar'), $this->guid);
        $guard = new NzbSourceGuard($this->nzb());
        $current = NzbSourceGuard::digest($writer->document($this->manifest('first.rar')));

        DB::transaction(function () use ($guard, $current): void {
            $release = DB::table('releases')->where('id', 1)->lockForUpdate()->first(['id', 'guid']);
            $guard->installStored($release, (string) gzencode('<nzb>repaired</nzb>'), $current);
        });

        self::assertSame('<nzb>repaired</nzb>', $this->nzb()->readNzbContents($this->guid));
        self::assertSame(NzbSourceGuard::digest('<nzb>repaired</nzb>'), $this->committedDigest());

        $this->expectExceptionMessage('NZB source changed before replacement.');
        DB::transaction(function () use ($guard, $current): void {
            $release = DB::table('releases')->where('id', 1)->lockForUpdate()->first(['id', 'guid']);
            $guard->installStored($release, (string) gzencode('<nzb>stale repair</nzb>'), $current);
        });
    }

    public function test_files_mode_installation_drops_a_row_that_would_shadow_its_file(): void
    {
        app(VerifiedNzbWriter::class)->write($this->manifest('first.rar'), $this->guid);
        config(['nntmux_settings.nzb_storage' => 'files']);
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $path = $nzb->getNzbPath($this->guid, createIfNotExist: true);
        $temporary = $path.'.tmp.1.'.str_repeat('c', 12);
        file_put_contents($temporary, gzencode('<nzb>file</nzb>'));

        DB::transaction(function () use ($nzb, $temporary, $path): void {
            $release = DB::table('releases')->where('id', 1)->lockForUpdate()->first(['id', 'guid']);
            $receipt = (new NzbSourceGuard($nzb))->install($release, $temporary, $path);
            DB::afterCommit(static fn () => $receipt->close());
        });

        self::assertFalse(DB::table('release_nzbs')->exists());
        self::assertSame('<nzb>file</nzb>', $nzb->readNzbContents($this->guid));
    }

    public function test_decoded_chunks_follow_concatenated_gzip_members_and_stop_at_damage(): void
    {
        $twoMembers = gzencode('first ').gzencode('second');
        self::assertSame('first second', implode('', iterator_to_array(NzbService::decodedChunks($twoMembers, 3), false)));
        self::assertSame('', implode('', iterator_to_array(NzbService::decodedChunks('not gzip'), false)));
    }

    public function test_downloads_tag_every_poster_from_a_stored_row(): void
    {
        $document = '<nzb><file poster="a@b" date="1"/><file poster="c@d" date="2"/></nzb>';
        $controller = (new \ReflectionClass(GetNzbController::class))->newInstanceWithoutConstructor();
        $stream = new \ReflectionMethod($controller, 'streamModifiedNzbContent');

        ob_start();
        $stream->invoke($controller, gzencode($document), 42);
        $output = (string) ob_get_clean();

        self::assertSame('<nzb><file poster="42-a@b" date="1"/><file poster="42-c@d" date="2"/></nzb>', $output);
    }

    private function nzb(): NzbService
    {
        return app(NzbService::class);
    }

    private function legacyFile(string $contents): string
    {
        $path = $this->nzb()->getNzbPath($this->guid, createIfNotExist: true);
        file_put_contents($path, gzencode($contents));

        return $path;
    }

    private function recordDigest(string $contents): void
    {
        DB::table('release_archive_naming_evidence')->insert([
            'releases_id' => 1,
            'release_guid' => $this->guid,
            'source_sha256' => NzbSourceGuard::digest($contents),
            'proof_version' => ReleaseArchiveNamingEvidence::PROOF_VERSION,
            'scope' => 'unknown',
            'episode_files' => '[]',
            'updated_at' => now(),
        ]);
    }

    private function committedDigest(): ?string
    {
        $digest = DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('source_sha256');

        return is_string($digest) ? $digest : null;
    }

    /** @return array<string, mixed> */
    private function manifest(string $subject): array
    {
        return [
            'fromname' => 'poster@example.com',
            'date' => '2026-10-01 00:00:00',
            'groups' => ['alt.binaries.test'],
            'files' => [[
                'subject' => $subject,
                'totalparts' => 2,
                'segments' => [
                    ['number' => 1, 'bytes' => 100, 'messageid' => '<one@example.com>'],
                    ['number' => 2, 'bytes' => 50, 'messageid' => '<two@example.com>'],
                ],
            ]],
        ];
    }
}
