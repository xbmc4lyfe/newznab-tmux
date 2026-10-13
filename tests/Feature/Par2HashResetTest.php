<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\NntmuxResetDb;
use App\Services\Par2PacketAccumulator;
use App\Services\Releases\Verification\VerificationResetService;
use App\Support\Par2CandidateProgress;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class Par2HashResetTest extends TestCase
{
    use Par2RenameTestDatabase;

    public function test_full_reset_clears_all_identity_evidence_while_cascades_are_disabled(): void
    {
        $this->createPar2RenameSchema();
        Schema::table('usenet_groups', static function (Blueprint $table): void {
            foreach (['first_record', 'last_record'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['first_record_postdate', 'last_record_postdate', 'last_updated'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $release = $this->pendingPar2Release();
        self::assertTrue(Par2CandidateProgress::save(
            ['id' => $release->id, 'guid' => $release->guid, 'name' => $release->name, 'searchname' => $release->searchname],
            str_repeat('a', 64), str_repeat('b', 64), 0, [], (new Par2PacketAccumulator)->exportState(), 1, 10000, static fn (): bool => true,
        ));
        DB::table('par_hashes')->insert(['releases_id' => $release->id, 'hash' => str_repeat('c', 32)]);
        DB::table('par_file_hashes')->insert(['releases_id' => $release->id, 'release_guid' => $release->guid, 'hash16k' => str_repeat('c', 32), 'full_md5' => str_repeat('d', 32), 'file_size' => '18446744073709551615']);
        DB::table('par_file_hash_inventories')->insert([
            'releases_id' => $release->id, 'release_guid' => $release->guid,
            'evidence_last_id' => DB::table('par_file_hashes')->max('id'), 'evidence_count' => 1,
        ]);
        DB::table('par2_hash_retry')->insert(['releases_id' => $release->id, 'release_guid' => $release->guid, 'retry_at' => now()]);
        $tables = ['par_hashes', 'par_file_hashes', 'par_file_hash_inventories', 'par2_hash_retry', 'par2_rename_candidate_progress', 'par2_rename_progress', 'releases'];
        $connection = DB::connection();
        DB::swap(Mockery::mock(DB::getFacadeRoot())->makePartial());
        DB::shouldReceive('statement')->andReturnUsing(static function (string $sql) use ($connection, $tables): bool {
            if (str_contains($sql, 'FOREIGN_KEY_CHECKS = 0')) {
                return $connection->statement('PRAGMA foreign_keys = OFF');
            }
            foreach ($tables as $table) {
                if ($sql === 'TRUNCATE TABLE '.$table) {
                    self::assertSame(0, (int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys);
                    $connection->table($table)->delete();
                }
            }
            if ($sql === 'TRUNCATE TABLE releases') {
                throw new RuntimeException('Bounded reset reached release truncation');
            }

            return true;
        });
        /** @var NntmuxResetDb&MockInterface $command */
        $command = Mockery::mock(NntmuxResetDb::class)->makePartial();
        $command->shouldReceive('confirm')->once()->andReturnTrue();
        $command->shouldReceive('info');
        $command->shouldReceive('error')->once();
        $this->app['env'] = 'local';
        $verificationRoot = sys_get_temp_dir().'/nntmux-verification-reset-'.bin2hex(random_bytes(8));
        config(['release_verification.storage_path' => $verificationRoot]);
        try {
            self::assertSame(NntmuxResetDb::FAILURE, $command->handle(app(VerificationResetService::class)));
            foreach ($tables as $table) {
                self::assertSame(0, $connection->table($table)->count(), $table);
            }
        } finally {
            $this->app['env'] = 'testing';
            $connection->statement('PRAGMA foreign_keys = ON');
            $this->removeVerificationFixture($verificationRoot);
        }
    }

    private function removeVerificationFixture(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                $this->removeVerificationFixture($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
