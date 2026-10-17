<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\ManifestStore;
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
        (require database_path('migrations/2026_10_17_000000_create_release_verification_manifests_table.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (['release_verification_manifests', 'release_verification_candidates', 'release_verification_state'] as $table) {
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
            ->expectsOutputToContain('imported=1 already=0 missing=0 invalid=1')
            ->assertExitCode(0);

        $this->assertSame($valid->compressed, DB::table('release_verification_manifests')->where('name', $valid->name)->value('body'));
        $this->assertFileDoesNotExist($store->path($valid->name));
        $this->assertSame(0, DB::table('release_verification_manifests')->where('name', $corrupt->name)->count());
        $this->assertFileExists($store->path($corrupt->name));
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
