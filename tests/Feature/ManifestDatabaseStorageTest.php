<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\VerificationResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ManifestDatabaseStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', config('database.default'));
        $this->directory = sys_get_temp_dir().'/manifest-db-'.bin2hex(random_bytes(12));
        mkdir($this->directory.'/manifests', 0700, true);
        config(['release_verification.storage_path' => $this->directory, 'release_verification.manifest_storage' => 'database']);
        (require database_path('migrations/2026_10_11_154028_create_release_verification_candidates_table.php'))->up();
        (require database_path('migrations/2026_10_17_220000_add_header_check_pending_to_release_verification_candidates.php'))->up();
        (require database_path('migrations/2026_10_17_000000_create_release_verification_manifests_table.php'))->up();
        (require database_path('migrations/2026_10_17_120000_create_release_verification_locks_table.php'))->up();
        (require database_path('migrations/2026_10_17_180000_add_release_verification_publication_stripes.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (['release_verification_locks', 'release_verification_manifests', 'release_verification_candidates', 'release_verification_state'] as $table) {
            Schema::dropIfExists($table);
        }
        app('files')->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_write_stores_the_compressed_manifest_in_the_database_without_a_file(): void
    {
        $store = new ManifestStore;
        $manifest = $this->manifest('stored');
        $prepared = $store->prepare($manifest);

        $name = $store->write($manifest);

        $this->assertSame($prepared->name, $name);
        $this->assertSame($prepared->compressed, DB::table('release_verification_manifests')->where('name', $name)->value('body'));
        $this->assertFileDoesNotExist($store->path($name));
        $this->assertSame($manifest, $store->read($name));
        $this->assertSame(strlen($prepared->compressed), $store->size($name));
    }

    public function test_a_rolled_back_admission_leaves_no_manifest_behind(): void
    {
        $store = new ManifestStore;
        $name = $store->prepare($this->manifest('rolled back'))->name;

        try {
            DB::transaction(function () use ($store): void {
                $store->write($this->manifest('rolled back'));
                throw new RuntimeException('admission failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('release_verification_manifests')->where('name', $name)->count());
        $this->assertFileDoesNotExist($store->path($name));
    }

    public function test_read_and_size_fall_back_to_a_legacy_file_until_it_is_imported(): void
    {
        $store = new ManifestStore;
        $manifest = $this->manifest('legacy');
        $prepared = $store->prepare($manifest);
        file_put_contents($store->path($prepared->name), $prepared->compressed);

        $this->assertSame($manifest, $store->read($prepared->name));
        $this->assertSame(strlen($prepared->compressed), $store->size($prepared->name));
    }

    public function test_an_occupied_row_with_equivalent_gzip_is_reused_unchanged(): void
    {
        $store = new ManifestStore;
        $manifest = $this->manifest('equivalent');
        $prepared = $store->prepare($manifest);
        $body = gzencode(json_encode($manifest, JSON_THROW_ON_ERROR), 1);
        DB::table('release_verification_manifests')->insert(['name' => $prepared->name, 'body' => $body, 'bytes' => strlen($body)]);

        $this->assertSame($prepared->name, $store->writePrepared($prepared));

        $this->assertSame($body, DB::table('release_verification_manifests')->where('name', $prepared->name)->value('body'));
        $this->assertSame($manifest, $store->read($prepared->name));
    }

    public function test_an_occupied_row_with_different_contents_is_rejected_and_preserved(): void
    {
        $store = new ManifestStore;
        $prepared = $store->prepare($this->manifest('expected'));
        $other = gzencode(json_encode($this->manifest('tampered'), JSON_THROW_ON_ERROR));
        DB::table('release_verification_manifests')->insert(['name' => $prepared->name, 'body' => $other, 'bytes' => strlen($other)]);

        try {
            $store->writePrepared($prepared);
            $this->fail('A conflicting occupied manifest must be rejected.');
        } catch (RuntimeException $error) {
            $this->assertSame('Manifest integrity check failed.', $error->getMessage());
        }

        $this->assertSame($other, DB::table('release_verification_manifests')->where('name', $prepared->name)->value('body'));
    }

    public function test_only_unreferenced_manifests_are_deleted_with_their_legacy_files(): void
    {
        $store = new ManifestStore;
        $kept = $store->write($this->manifest('referenced'));
        $removed = $store->write($this->manifest('unreferenced'));
        file_put_contents($store->path($removed), 'stale legacy copy');
        $this->candidate($kept);

        $store->deleteIfUnreferenced($kept);
        $store->deleteIfUnreferenced($removed);

        $this->assertSame(1, DB::table('release_verification_manifests')->where('name', $kept)->count());
        $this->assertSame(0, DB::table('release_verification_manifests')->where('name', $removed)->count());
        $this->assertFileDoesNotExist($store->path($removed));
    }

    public function test_import_copies_referenced_legacy_files_and_removes_them_only_after_verification(): void
    {
        $store = new ManifestStore;
        $valid = $store->prepare($this->manifest('import me'));
        file_put_contents($store->path($valid->name), $valid->compressed);
        $this->candidate($valid->name);
        $corrupt = $store->prepare($this->manifest('corrupt on disk'));
        file_put_contents($store->path($corrupt->name), gzencode('{"not":"the same manifest"}'));
        $this->candidate($corrupt->name);

        $this->artisan('verification:import-manifests', ['--delete-files' => true])
            ->expectsOutputToContain('imported=1 already=0 missing=0 invalid=1 conflict=0 unreferenced=0')
            ->assertExitCode(0);

        $this->assertSame($valid->compressed, DB::table('release_verification_manifests')->where('name', $valid->name)->value('body'));
        $this->assertFileDoesNotExist($store->path($valid->name));
        $this->assertSame(0, DB::table('release_verification_manifests')->where('name', $corrupt->name)->count());
        $this->assertFileExists($store->path($corrupt->name));
    }

    public function test_import_crosses_chunk_boundaries_and_imports_every_referenced_file(): void
    {
        $store = new ManifestStore;
        $names = [];
        for ($i = 0; $i < 61; $i++) {
            $prepared = $store->prepare($this->manifest('chunked '.$i));
            file_put_contents($store->path($prepared->name), $prepared->compressed);
            $this->candidate($prepared->name);
            $names[] = $prepared->name;
        }

        $counts = $store->importLegacyFiles($names, true);

        $this->assertSame(61, $counts['imported']);
        $this->assertSame(61, DB::table('release_verification_manifests')->count());
        foreach ($names as $name) {
            $this->assertFileDoesNotExist($store->path($name));
        }
    }

    public function test_import_refuses_to_run_inside_an_open_transaction(): void
    {
        $store = new ManifestStore;
        $prepared = $store->prepare($this->manifest('ambient transaction'));
        file_put_contents($store->path($prepared->name), $prepared->compressed);
        $this->candidate($prepared->name);

        try {
            DB::transaction(static fn () => $store->importLegacyFiles([$prepared->name], true));
            $this->fail('Import inside a transaction must be refused.');
        } catch (RuntimeException $error) {
            $this->assertSame('Manifest import cannot run inside an open transaction.', $error->getMessage());
        }

        $this->assertFileExists($store->path($prepared->name));
        $this->assertSame(0, DB::table('release_verification_manifests')->count());
    }

    public function test_import_keeps_the_legacy_file_when_the_stored_row_is_corrupt(): void
    {
        $store = new ManifestStore;
        $valid = $store->prepare($this->manifest('row is corrupt'));
        file_put_contents($store->path($valid->name), $valid->compressed);
        $this->candidate($valid->name);
        $corrupt = gzencode('{"different":"bytes"}');
        DB::table('release_verification_manifests')->insert(['name' => $valid->name, 'body' => $corrupt, 'bytes' => strlen($corrupt)]);

        $counts = $store->importLegacyFiles([$valid->name], true);

        $this->assertSame(1, $counts['conflict']);
        $this->assertFileExists($store->path($valid->name));
        $this->assertSame($corrupt, DB::table('release_verification_manifests')->where('name', $valid->name)->value('body'));
    }

    public function test_import_does_not_recreate_a_manifest_that_no_candidate_references(): void
    {
        $store = new ManifestStore;
        $orphan = $store->prepare($this->manifest('cleanup already removed its candidate'));
        file_put_contents($store->path($orphan->name), $orphan->compressed);

        $counts = $store->importLegacyFiles([$orphan->name], true);

        $this->assertSame(1, $counts['unreferenced']);
        $this->assertSame(0, DB::table('release_verification_manifests')->where('name', $orphan->name)->count());
        $this->assertFileExists($store->path($orphan->name));
    }

    public function test_verification_reset_removes_database_manifests(): void
    {
        $store = new ManifestStore;
        $name = $store->write($this->manifest('reset me'));
        $this->candidate($name);

        app(VerificationResetService::class)->run(static function (): void {});

        $this->assertSame(0, DB::table('release_verification_manifests')->count());
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
    }

    public function test_files_mode_still_reads_sizes_and_deletes_database_manifests_after_a_rollback(): void
    {
        $manifest = $this->manifest('written before rolling back to files');
        $name = (new ManifestStore)->write($manifest);
        config(['release_verification.manifest_storage' => 'files']);
        $store = new ManifestStore;

        $this->assertFileDoesNotExist($store->path($name));
        $this->assertSame($manifest, $store->read($name));
        $this->assertSame(strlen($store->prepare($manifest)->compressed), $store->size($name));

        $store->deleteIfUnreferenced($name);

        $this->assertSame(0, DB::table('release_verification_manifests')->where('name', $name)->count());
    }

    public function test_import_counts_a_malformed_reference_as_invalid_and_keeps_going(): void
    {
        $store = new ManifestStore;
        $valid = $store->prepare($this->manifest('after a malformed reference'));
        file_put_contents($store->path($valid->name), $valid->compressed);
        $this->candidate('../not-a-manifest');
        $this->candidate($valid->name);

        $this->artisan('verification:import-manifests', ['--delete-files' => true])
            ->expectsOutputToContain('imported=1 already=0 missing=0 invalid=1 conflict=0 unreferenced=0')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('release_verification_manifests')->where('name', $valid->name)->count());
    }

    public function test_files_mode_keeps_writing_manifest_files(): void
    {
        config(['release_verification.manifest_storage' => 'files']);
        $store = new ManifestStore;
        $manifest = $this->manifest('file mode');

        $name = $store->write($manifest);

        $this->assertFileExists($store->path($name));
        $this->assertSame(0, DB::table('release_verification_manifests')->count());
        $this->assertSame($manifest, $store->read($name));
    }

    /** @return array<string, mixed> */
    private function manifest(string $note): array
    {
        return ['identity' => 'manifest-db-test', 'files' => [], 'note' => $note.' '.str_repeat('compressible ', 50)];
    }

    private function candidate(string $manifest): void
    {
        DB::table('release_verification_candidates')->insert([
            'identity' => substr(sha1($manifest), 0, 40), 'groups_id' => 1, 'manifest_path' => $manifest, 'manifest_bytes' => 1,
            'manifest_hash' => str_repeat('0', 64), 'status' => 'waiting_upload', 'last_unique_at' => now(), 'next_attempt_at' => now(),
        ]);
    }
}
