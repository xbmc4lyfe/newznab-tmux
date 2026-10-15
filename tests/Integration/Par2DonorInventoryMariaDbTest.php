<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\NameFixing\DonorMatchSelector;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\Par2PacketAccumulator;
use App\Support\Par2DescriptorEvidence;
use App\Support\Par2FileHashEvidence;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class Par2DonorInventoryMariaDbTest extends TestCase
{
    private ?PDO $administrator = null;

    private string $database = '';

    private bool $databaseCreated = false;

    private bool $legacyGuids = false;

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['database.default', 'database.connections.donor_qualification', 'cache.default'] as $key) {
            $this->originalConfig[$key] = config($key);
        }
        $host = (string) getenv('PAR2_DONOR_TEST_HOST');
        if ($host === '') {
            $this->markTestSkipped('Set PAR2_DONOR_TEST_HOST to an isolated MariaDB test server.');
        }
        try {
            $port = (int) (getenv('PAR2_DONOR_TEST_PORT') ?: 3306);
            $username = (string) (getenv('PAR2_DONOR_TEST_USERNAME') ?: 'root');
            $password = (string) getenv('PAR2_DONOR_TEST_PASSWORD');
            $this->administrator = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password);
            $this->database = 'nntmux_donor_test_'.bin2hex(random_bytes(8));
            $this->administrator->exec('CREATE DATABASE `'.$this->database.'`');
            $this->databaseCreated = true;
            $this->administrator->exec('USE `'.$this->database.'`');
            $this->administrator->exec($this->app->make('files')->get(database_path('schema/mariadb-schema.sql')));
            config([
                'database.default' => 'donor_qualification',
                'database.connections.donor_qualification' => [
                    'driver' => 'mysql', 'host' => $host, 'port' => $port,
                    'database' => $this->database, 'username' => $username, 'password' => $password,
                    'url' => null, 'unix_socket' => '', 'prefix' => '', 'strict' => true,
                    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                ],
                'cache.default' => 'array',
            ]);
            DB::purge('donor_qualification');
            DB::statement('SET SESSION max_statement_time = 60');
            if (Artisan::call('migrate', ['--database' => 'donor_qualification', '--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Could not migrate owned donor fixture: '.Artisan::output());
            }
            DB::table('root_categories')->insertOrIgnore(['id' => 2000, 'title' => 'Movies']);
            DB::table('categories')->insertOrIgnore(['id' => 2040, 'title' => 'HD', 'root_categories_id' => 2000]);
            DB::table('usenet_groups')->insertOrIgnore(['id' => 1, 'name' => 'alt.binaries.donor.test']);
            DB::table('predb')->insertOrIgnore(['id' => 1, 'title' => 'Correct.Scene.Release.2026-GRP']);
        } catch (\Throwable $exception) {
            $this->cleanupDatabase();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupDatabase();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupDatabase(): void
    {
        try {
            if ($this->databaseCreated && $this->administrator !== null && preg_match('/^nntmux_donor_test_[a-f0-9]{16}$/D', $this->database) === 1) {
                DB::purge('donor_qualification');
                $this->administrator->exec('DROP DATABASE `'.$this->database.'`');
                $this->databaseCreated = false;
            }
        } finally {
            config($this->originalConfig);
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function isolationLevels(): iterable
    {
        yield 'read committed' => ['READ COMMITTED'];
        yield 'repeatable read' => ['REPEATABLE READ'];
    }

    #[DataProvider('isolationLevels')]
    public function test_descriptor_provenance_preserves_large_binary_names_and_exact_guid_generations(string $isolation): void
    {
        $this->isolation($isolation);
        $this->legacyGuids = true;
        $this->inventory(42, 1, donor: false);
        $name = "raw\xff\0".str_repeat('long-name.', 8_000);
        $body = md5('descriptor identity', true).md5('whole payload', true).md5('first prefix', true)
            .pack('V2', 0xFFFFFFFF, 0xFFFFFFFF).$name;
        $body = str_pad($body, (int) (ceil(strlen($body) / 4) * 4), "\0");
        $hashed = md5('recovery set', true).Par2Info::PACKET_FILEDESC.$body;
        $packet = Par2Info::PACKET_MARKER.pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed;
        $inventory = new Par2PacketAccumulator;
        $inventory->append($packet);
        $snapshot = ['id' => 42, 'guid' => $this->guid(42), 'name' => 'obfuscated',
            'searchname' => 'obfuscated', 'predb_id' => 0, 'isrenamed' => 0];

        self::assertTrue(Par2DescriptorEvidence::remember($inventory, $snapshot));
        $stored = DB::table('par2_descriptor_evidence')->first();
        self::assertNotNull($stored);
        self::assertSame(substr($packet, 120), base64_decode($stored->raw_filename_base64, true));
        self::assertGreaterThan(65_535, strlen($stored->raw_filename_base64));
        self::assertSame('18446744073709551615', $stored->file_size);
        self::assertSame('unknown', $stored->classification);
        self::assertSame(0, (int) $stored->classification_version);
        self::assertSame('unclassified', $stored->classification_provenance);
        self::assertTrue(Par2DescriptorEvidence::remember($inventory, $snapshot));
        self::assertSame([$stored->id], DB::table('par2_descriptor_evidence')->pluck('id')->all());

        $replacement = strtoupper($snapshot['guid']);
        self::assertNotSame($snapshot['guid'], $replacement);
        DB::table('releases')->where('id', 42)->update(['guid' => $replacement]);
        self::assertFalse(Par2DescriptorEvidence::remember($inventory, $snapshot));
        self::assertSame($snapshot['guid'], DB::table('par2_descriptor_evidence')->value('release_guid'));
        $snapshot['guid'] = $replacement;
        self::assertTrue(Par2DescriptorEvidence::remember($inventory, $snapshot));
        self::assertSame([$replacement], DB::table('par2_descriptor_evidence')->pluck('release_guid')->all());
    }

    #[DataProvider('isolationLevels')]
    public function test_sparse_query_requires_symmetric_current_byte_exact_inventories(string $isolation): void
    {
        $this->isolation($isolation);
        $this->inventory(42, 2, donor: false);
        $this->inventory(20, 2);
        $this->inventory(21, 1); // A partial donor shares one support tuple.
        $this->inventory(22, 3); // A superset has an unmatched source.
        $this->inventory(23, 2);
        DB::table('par_file_hashes')->where('releases_id', 23)->where('full_md5', md5('content:1'))->update(['full_md5' => md5('different tail')]);
        $this->inventory(24, 2);
        DB::table('par_file_hashes')->where('releases_id', 24)->where('full_md5', md5('content:1'))->update(['file_size' => '20002']);
        $this->inventory(25, 2);
        DB::table('par_file_hash_inventories')->where('releases_id', 25)->update(['proof_version' => 0]);
        $this->inventory(26, 2);
        DB::table('releases')->where('id', 26)->update(['guid' => strtoupper($this->guid(26))]);
        $this->inventory(27, 2);
        DB::table('par_file_hash_inventories')->where('releases_id', 27)->decrement('evidence_count');
        $this->inventory(28, 2);
        DB::table('par_file_hashes')->where('releases_id', 28)->where('full_md5', md5('content:1'))->update(['full_md5' => strtoupper(md5('content:1'))]);
        $this->inventory(29, 2);
        DB::table('par_file_hashes')->where('releases_id', 29)->where('full_md5', md5('content:1'))->update(['file_size' => '20001 ']);
        $this->assertSame([20], $this->donorIds($this->lookup()));

        DB::table('par_file_hash_inventories')->where('releases_id', 42)->update(['proof_version' => 0]);
        $this->assertSame([], $this->donorIds($this->lookup()));
        $this->certify(42);
        DB::table('par_file_hashes')->where('releases_id', 42)->where('full_md5', md5('content:1'))->delete();
        $this->assertSame([], $this->donorIds($this->lookup()));
    }

    #[DataProvider('isolationLevels')]
    public function test_duplicate_prefixes_do_not_make_one_content_tuple_independent(string $isolation): void
    {
        $this->isolation($isolation);
        foreach ([42, 20] as $id) {
            $this->inventory($id, 1, donor: $id !== 42);
            $this->insertEvidence($id, 1, md5('different prefix'));
            $this->certify($id);
        }
        $result = $this->lookup();
        $this->assertTrue($result['known']);
        $this->assertTrue($result['usable']);
        $this->assertFalse($result['overflow']);
        $this->assertSame([], $result['donors']);
    }

    #[DataProvider('isolationLevels')]
    public function test_legacy_40_character_guids_match_without_truncating_proof_identity(string $isolation): void
    {
        $this->isolation($isolation);
        $this->legacyGuids = true;
        $this->inventory(42, 2, donor: false);
        $this->inventory(20, 2);
        $result = $this->lookup();
        $this->assertSame([20], $this->donorIds($result));
        $this->assertSame($this->guid(20), $result['donors'][0]->donor_guid);
        DB::table('releases')->where('id', 20)->update(['guid' => strtoupper($this->guid(20))]);
        $this->assertSame([], $this->donorIds($this->lookup()));
    }

    #[DataProvider('isolationLevels')]
    public function test_complete_donors_with_different_canonical_titles_remain_ambiguous(string $isolation): void
    {
        $this->isolation($isolation);
        $this->inventory(42, 2, donor: false);
        $this->inventory(20, 2);
        $this->inventory(21, 2);
        DB::table('predb')->insert(['id' => 2, 'title' => 'Different.Scene.Release.2026-GRP']);
        DB::table('releases')->where('id', 21)->update(['predb_id' => 2, 'searchname' => 'Different.Scene.Release.2026-GRP']);
        $result = $this->lookup();
        $this->assertSame([20, 21], $this->donorIds($result));
        $titles = [];
        foreach ($result['donors'] as $donor) {
            $titles[(int) $donor->releases_id] = $donor->canonical_title;
        }
        ksort($titles);
        $this->assertSame([20 => 'Correct.Scene.Release.2026-GRP', 21 => 'Different.Scene.Release.2026-GRP'], $titles);
        $this->assertNull((new DonorMatchSelector)->selectCanonical($result['donors']));
    }

    #[DataProvider('isolationLevels')]
    public function test_dense_query_preserves_the_257th_donor_flood_sentinel(string $isolation): void
    {
        $this->isolation($isolation);
        $this->inventory(42, 2, donor: false);
        foreach (range(100, 355) as $id) {
            $this->inventory($id, 2);
        }
        $result = $this->lookup();
        $this->assertSame(range(100, 355), $this->donorIds($result));
        $this->assertFalse($result['overflow']);
        $this->assertSame(100, (int) (new DonorMatchSelector)->selectCanonical($result['donors'])?->releases_id);
        $this->inventory(356, 2);
        $overflow = $this->lookup();
        $this->assertSame(range(100, 356), $this->donorIds($overflow));
        $this->assertTrue($overflow['overflow']);
        $this->assertNull((new DonorMatchSelector)->selectCanonical($overflow['donors']));
    }

    #[DataProvider('isolationLevels')]
    public function test_large_inventory_checks_the_last_descriptor_without_page_truncation(string $isolation): void
    {
        $this->isolation($isolation);
        // Above the descriptor cardinality possible in one default 8 MiB
        // JSON/base64 metadata state. This is a SQL fixture,
        // not a parser limit, a global inventory bound, or a throughput benchmark.
        $descriptors = 65_536;
        $this->inventory(42, $descriptors, donor: false);
        $this->inventory(20, $descriptors);
        $this->inventory(21, $descriptors - 1);
        $result = $this->lookup();
        $this->assertTrue($result['known']);
        $this->assertTrue($result['usable']);
        $this->assertFalse($result['overflow']);
        $this->assertSame([20], $this->donorIds($result));
        $this->assertStringEndsWith(':65536', $result['generation']);
        DB::table('par_file_hashes')->where('releases_id', 20)->where('full_md5', md5('content:65536'))->delete();
        $this->certify(20);
        $this->assertSame([], $this->donorIds($this->lookup()));
    }

    private function isolation(string $level): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$level);
    }

    private function guid(int $id): string
    {
        if ($this->legacyGuids) {
            return sha1('legacy-donor:'.$id);
        }

        return sprintf('abcdef00-0000-0000-0000-%012d', $id);
    }

    private function inventory(int $id, int $descriptors, bool $donor = true): void
    {
        $title = $donor ? 'Correct.Scene.Release.2026-GRP' : 'obfuscated';
        DB::table('releases')->insert([
            'id' => $id, 'guid' => $this->guid($id), 'leftguid' => 'a',
            'name' => $title, 'searchname' => $title, 'predb_id' => $donor ? 1 : 0,
            'groups_id' => 1, 'categories_id' => 2040,
        ]);
        $rows = [];
        for ($index = 1; $index <= $descriptors; $index++) {
            $rows[] = $this->evidence($id, $index);
            if (count($rows) === 1000) {
                DB::table('par_file_hashes')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('par_file_hashes')->insert($rows);
        }
        $this->certify($id);
    }

    /** @return array<string, int|string> */
    private function evidence(int $id, int $index): array
    {
        return ['releases_id' => $id, 'release_guid' => $this->guid($id),
            'full_md5' => md5('content:'.$index), 'hash16k' => md5('prefix:'.$index),
            'file_size' => (string) (20_000 + $index)];
    }

    private function insertEvidence(int $id, int $index, string $prefix): void
    {
        DB::table('par_file_hashes')->insert(array_replace($this->evidence($id, $index), ['hash16k' => $prefix]));
    }

    private function certify(int $id): void
    {
        $row = DB::table('releases')->where('id', $id)->first();
        if ($row === null || ! Par2FileHashEvidence::markInventoryComplete($id, [
            'id' => $id, 'guid' => $row->guid, 'name' => $row->name, 'searchname' => $row->searchname,
        ])) {
            throw new RuntimeException('Could not certify owned donor fixture.');
        }
    }

    /** @return array{known: bool, usable: bool, overflow: bool, donors: list<object>, generation: string} */
    private function lookup(): array
    {
        return DB::transaction(fn (): array => (new NameFixingQueryService(DB::connection()))->fullHashDonorsForRelease(42, $this->guid(42)));
    }

    /** @param array{donors: list<object>} $result
     * @return list<int>
     */
    private function donorIds(array $result): array
    {
        $ids = array_map(static fn (object $donor): int => (int) $donor->releases_id, $result['donors']);
        sort($ids);

        return $ids;
    }
}
