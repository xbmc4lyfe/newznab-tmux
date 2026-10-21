<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\InstallNntmux;
use App\Console\Commands\NntmuxPopulateSearchIndexes;
use App\Console\Commands\NntmuxResetDb;
use App\Console\Commands\PostProcessGuid;
use App\Console\Commands\ProcessReleasesCommand;
use App\Console\Commands\UpdatePerGroup;
use App\Console\Commands\UpdatePostProcess;
use App\Facades\Search;
use App\Services\Categorization\CategorizationPipeline;
use App\Services\Par2PacketAccumulator;
use App\Services\PostProcessService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\Verification\VerificationResetService;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Support\Par2CandidateProgress;
use App\Support\Par2FileHashEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
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
            'proof_version' => Par2FileHashEvidence::INVENTORY_PROOF_VERSION,
        ]);
        DB::table('par2_descriptor_evidence')->insert([
            'releases_id' => $release->id, 'release_guid' => $release->guid,
            'packet_checksum' => str_repeat('e', 32), 'recovery_set_id' => str_repeat('f', 32),
            'file_id' => str_repeat('a', 32), 'full_md5' => str_repeat('d', 32), 'hash16k' => str_repeat('c', 32),
            'file_size' => '18446744073709551615', 'raw_filename_base64' => base64_encode("raw\xff\0\0"),
            'descriptor_proof_version' => 1, 'classification' => 'unknown',
        ]);
        DB::table('par2_hash_retry')->insert(['releases_id' => $release->id, 'release_guid' => $release->guid, 'retry_at' => now()]);
        $tables = ['par_hashes', 'par_file_hashes', 'par_file_hash_inventories', 'par2_descriptor_evidence', 'par2_hash_retry', 'par2_rename_candidate_progress', 'par2_rename_progress', 'releases'];
        $truncatedTables = [];
        $connection = DB::connection();
        DB::swap(Mockery::mock(DB::getFacadeRoot())->makePartial());
        DB::shouldReceive('statement')->andReturnUsing(static function (string $sql) use ($connection, $tables, &$truncatedTables): bool {
            if (str_contains($sql, 'FOREIGN_KEY_CHECKS = 0')) {
                return $connection->statement('PRAGMA foreign_keys = OFF');
            }
            if (str_starts_with($sql, 'TRUNCATE TABLE ')) {
                $truncatedTables[] = substr($sql, strlen('TRUNCATE TABLE '));
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
            foreach ([
                'collection_groups',
                'videos_aliases',
                'bookinfo',
                'consoleinfo',
                'gamesinfo',
                'release_regexes',
                'release_unique',
                'release_reports',
                'release_nzb_creation_failures',
                'release_nzb_passwords',
            ] as $requiredTable) {
                self::assertContains($requiredTable, $truncatedTables);
            }
        } finally {
            $this->app['env'] = 'testing';
            $connection->statement('PRAGMA foreign_keys = ON');
            $this->removeVerificationFixture($verificationRoot);
        }
    }

    public function test_resetdb_truncates_manticore_release_indexes_without_wiping_predb_rt(): void
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

        $connection = DB::connection();
        DB::swap(Mockery::mock(DB::getFacadeRoot())->makePartial());
        DB::shouldReceive('statement')->andReturnTrue();

        $truncatedIndexes = [];
        $search = Mockery::mock(SearchServiceInterface::class);
        $search->shouldReceive('truncateIndex')->once()->andReturnUsing(static function (string|array $indexes) use (&$truncatedIndexes): void {
            $truncatedIndexes = (array) $indexes;
        });
        Search::swap($search);

        $nzbRoot = sys_get_temp_dir().'/nntmux-reset-nzb-'.bin2hex(random_bytes(8));
        $verificationRoot = sys_get_temp_dir().'/nntmux-verification-reset-'.bin2hex(random_bytes(8));
        mkdir($nzbRoot, 0777, true);
        config([
            'search.default' => 'manticore',
            'nntmux_settings.path_to_nzbs' => $nzbRoot,
            'release_verification.storage_path' => $verificationRoot,
        ]);

        /** @var NntmuxResetDb&MockInterface $command */
        $command = Mockery::mock(NntmuxResetDb::class)->makePartial();
        $command->shouldReceive('confirm')->once()->andReturnTrue();
        $command->shouldReceive('info');
        $command->shouldReceive('call')->with('cache:clear')->once()->andReturn(0);

        $this->app['env'] = 'local';
        try {
            self::assertSame(NntmuxResetDb::SUCCESS, $command->handle(app(VerificationResetService::class)));
            self::assertContains('releases_rt', $truncatedIndexes);
            self::assertNotContains('predb_rt', $truncatedIndexes);
        } finally {
            $this->app['env'] = 'testing';
            $this->removeVerificationFixture($nzbRoot);
            $this->removeVerificationFixture($verificationRoot);
        }
    }

    public function test_install_update_paths_reads_nntmux_tmp_unzip_path_config_key(): void
    {
        $tempRoot = sys_get_temp_dir().'/nntmux-install-paths-'.bin2hex(random_bytes(8));
        $coversPath = $tempRoot.'/covers';
        $nzbPath = $tempRoot.'/nzbs';
        $unzipPath = $tempRoot.'/unzip';
        $unrarPath = $tempRoot.'/unrar';
        foreach ([$coversPath, $nzbPath, $unzipPath] as $dir) {
            mkdir($dir, 0777, true);
        }

        config([
            'nntmux_settings.covers_path' => $coversPath,
            'nntmux_settings.path_to_nzbs' => $nzbPath,
            'nntmux_settings.tmp_unzip_path' => null,
            'nntmux.tmp_unzip_path' => $unzipPath,
            'nntmux.tmp_unrar_path' => $unrarPath,
        ]);

        /** @var InstallNntmux&MockInterface $command */
        $command = Mockery::mock(InstallNntmux::class)->makePartial();
        $command->shouldReceive('info');
        $command->shouldNotReceive('warn');

        $method = new ReflectionMethod(InstallNntmux::class, 'updatePaths');
        try {
            self::assertTrue($method->invoke($command));
            self::assertDirectoryExists($unrarPath);
        } finally {
            $this->removeVerificationFixture($tempRoot);
        }
    }

    public function test_populate_search_indexes_optimize_database_sets_only_session_variables(): void
    {
        $executedStatements = [];
        DB::swap(Mockery::mock(DB::getFacadeRoot())->makePartial());
        DB::shouldReceive('statement')->andReturnUsing(static function (string $sql) use (&$executedStatements): bool {
            if (str_contains($sql, 'innodb_buffer_pool_size')) {
                throw new RuntimeException('Variable innodb_buffer_pool_size is a GLOBAL variable');
            }
            $executedStatements[] = $sql;

            return true;
        });

        /** @var NntmuxPopulateSearchIndexes&MockInterface $command */
        $command = Mockery::mock(NntmuxPopulateSearchIndexes::class)->makePartial();
        $command->shouldReceive('option')->with('disable-keys')->andReturnTrue();
        $command->shouldReceive('info');
        $command->shouldNotReceive('warn');

        $method = new ReflectionMethod(NntmuxPopulateSearchIndexes::class, 'optimizeDatabase');
        $method->invoke($command);

        self::assertSame([
            'SET FOREIGN_KEY_CHECKS = 0',
            'SET UNIQUE_CHECKS = 0',
            'SET AUTOCOMMIT = 0',
            'SET SESSION bulk_insert_buffer_size = 268435456',
            'SET SESSION read_buffer_size = 2097152',
            'SET SESSION sort_buffer_size = 16777216',
        ], $executedStatements);
    }

    public function test_processing_console_commands_resolve_when_database_is_unreachable(): void
    {
        config([
            'database.default' => 'unreachable_mysql',
            'database.connections.unreachable_mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'nntmux',
                'username' => 'nntmux',
                'password' => 'secret',
            ],
        ]);
        DB::purge('unreachable_mysql');

        $this->app->forgetInstance(PostProcessService::class);
        $this->app->forgetInstance(ReleaseProcessingService::class);
        $this->app->forgetInstance(CategorizationPipeline::class);

        self::assertInstanceOf(UpdatePostProcess::class, $this->app->make(UpdatePostProcess::class));
        self::assertInstanceOf(ProcessReleasesCommand::class, $this->app->make(ProcessReleasesCommand::class));
        self::assertInstanceOf(UpdatePerGroup::class, $this->app->make(UpdatePerGroup::class));
        self::assertInstanceOf(PostProcessGuid::class, $this->app->make(PostProcessGuid::class));
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
