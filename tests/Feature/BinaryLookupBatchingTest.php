<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinaryHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BinaryLookupBatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY, binaryhash TEXT NOT NULL, name TEXT NOT NULL,
            collections_id INT NOT NULL, totalparts INT NOT NULL, currentparts INT NOT NULL,
            filenumber INT NOT NULL, partsize INT NOT NULL, partcheck INT DEFAULT 0,
            UNIQUE(collections_id, binaryhash)
        )');
    }

    #[DataProvider('lookupShapes')]
    public function test_existing_binary_lookup_is_bounded_across_collections(int $count, bool $dense, int $chunkSize): void
    {
        $records = [];
        $expected = [];
        for ($index = 0; $index < $count; $index++) {
            $collectionId = $dense ? 1 : $index + 1;
            $fileNumber = $dense ? $index + 1 : 1;
            DB::table('binaries')->insert([
                'id' => $index + 1, 'binaryhash' => md5('file:'.$fileNumber),
                'name' => 'retained', 'collections_id' => $collectionId,
                'totalparts' => 2, 'currentparts' => 1, 'filenumber' => $fileNumber, 'partsize' => 50,
            ]);
            $records[$index] = $this->record($collectionId, $fileNumber);
            $expected[$index] = $index + 1;
        }
        $lookups = [];
        DB::listen(static function (QueryExecuted $query) use (&$lookups): void {
            if (str_starts_with($query->sql, 'SELECT id, ')) {
                $lookups[] = $query;
            }
        });

        $handler = new BinaryHandler($chunkSize);
        $this->assertSame($expected, $handler->getOrCreateBinaries($records, 1));
        $this->assertSame([], $handler->getInsertedIds());
        $this->assertSame($count, DB::table('binaries')->count());
        $this->assertSame($count * 50, (int) DB::table('binaries')->sum('partsize'));
        $this->assertCount((int) ceil($count / $chunkSize), $lookups,
            'Lookup round trips must depend on SQL chunk size, rather than collection count.');
        foreach ($lookups as $lookup) {
            $this->assertLessThanOrEqual($chunkSize * 2, count($lookup->bindings));
        }

        $this->assertSame($expected, $handler->getOrCreateBinaries($records, 1));
        $this->assertCount((int) ceil($count / $chunkSize), $lookups, 'Cached resolution must perform no additional lookups.');
    }

    /** @return array<string, array{int, bool, int}> */
    public static function lookupShapes(): array
    {
        return [
            'small sparse' => [7, false, 3],
            'dense across maximum SQL boundary' => [1001, true, 1000],
            'sparse across maximum SQL boundary' => [1001, false, 1000],
        ];
    }

    public function test_binary_lookup_preserves_collection_hash_pairs_and_resolves_new_rows(): void
    {
        foreach ([[11, 1, 1], [12, 1, 2], [21, 2, 1], [22, 2, 2]] as [$id, $collection, $file]) {
            DB::table('binaries')->insert([
                'id' => $id, 'binaryhash' => md5('file:'.$file), 'name' => 'retained',
                'collections_id' => $collection, 'totalparts' => 2, 'currentparts' => 1,
                'filenumber' => $file, 'partsize' => 50,
            ]);
        }
        $handler = new BinaryHandler(2);
        $records = [4 => $this->record(1, 1), 9 => $this->record(2, 2),
            20 => $this->record(3, 1), 33 => $this->record(1, 1)];
        $ids = $handler->getOrCreateBinaries($records, 1);

        $this->assertSame(11, $ids[4]);
        $this->assertSame(22, $ids[9]);
        $this->assertSame(11, $ids[33]);
        $this->assertSame(3, (int) DB::table('binaries')->where('id', $ids[20])->value('collections_id'));
        $this->assertSame([$ids[20]], $handler->getInsertedIds());
        $this->assertSame(5, DB::table('binaries')->count());
        $this->assertSame(200, (int) DB::table('binaries')->sum('partsize'));
        $handler->reset();
        $this->assertSame($ids, $handler->getOrCreateBinaries($records, 1));
        $this->assertSame([], $handler->getInsertedIds());
    }

    /** @return array{header: array<string, mixed>, collection_id: int, file_number: int} */
    private function record(int $collectionId, int $fileNumber): array
    {
        return ['header' => ['matches' => [1 => 'candidate', 3 => 2]],
            'collection_id' => $collectionId, 'file_number' => $fileNumber];
    }
}
