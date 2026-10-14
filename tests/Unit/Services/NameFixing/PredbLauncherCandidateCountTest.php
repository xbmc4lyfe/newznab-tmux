<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\NameFixingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SQLiteConnection;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PredbLauncherCandidateCountTest extends TestCase
{
    private SQLiteConnection $database;

    private int $predicateEvaluations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        $pdo = new Sqlite('sqlite::memory:');
        $pdo->createFunction('regexp', function (string $pattern, string $title): int {
            $this->predicateEvaluations++;

            return (int) preg_match('/'.$pattern.'/', $title);
        }, 2);
        $pdo->createFunction('mod', static fn (int $id, int $workers): int => $id % $workers, 2);
        $this->database = new SQLiteConnection($pdo, ':memory:');
        $this->database->statement('CREATE TABLE predb (
            id INTEGER PRIMARY KEY, title TEXT NOT NULL, source TEXT NOT NULL,
            searched INTEGER NOT NULL, predate TEXT NULL, imported_at TEXT NULL
        )');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('candidatePopulations')]
    public function test_it_counts_only_the_population_needed_to_size_the_launcher(int $population, int $expected): void
    {
        for ($id = 1; $id <= $population; $id++) {
            $this->insertPredb($id);
        }

        $this->assertSame($expected, (new NameFixingQueryService($this->database))->countPredbLauncherCandidates(4, 16));
    }

    /** @return array<string, array{int, int}> */
    public static function candidatePopulations(): array
    {
        return [
            'empty' => [0, 0],
            'sub-batch' => [3, 3],
            'one batch' => [4, 4],
            'next worker' => [5, 5],
            'below saturation' => [63, 63],
            'saturation boundary' => [64, 64],
            'above saturation' => [65, 64],
            'large population' => [200, 64],
        ];
    }

    /** @param array<string, int|string|null> $attributes */
    #[DataProvider('eligibilityCases')]
    public function test_it_preserves_the_worker_eligibility_rules(array $attributes, int $expected): void
    {
        $this->insertPredb(1, $attributes);
        $service = new NameFixingQueryService($this->database);

        $this->assertSame($expected, $service->countPredbLauncherCandidates(4, 16));
        $this->assertCount($expected, $service->predbBatch(1, 1, 4));
    }

    /** @return array<string, array{array<string, int|string|null>, int}> */
    public static function eligibilityCases(): array
    {
        return [
            'old dated row' => [[], 1],
            'minimum title length' => [['title' => str_repeat('a', 15)], 1],
            'searched' => [['searched' => 1], 0],
            'short title' => [['title' => str_repeat('a', 14)], 0],
            'quote' => [['title' => 'Synthetic.Title."One'], 0],
            'left angle bracket' => [['title' => 'Synthetic.Title.<One'], 0],
            'right angle bracket' => [['title' => 'Synthetic.Title.>One'], 0],
            'space' => [['title' => 'Synthetic.Title One'], 0],
            'new dated row despite old import' => [['predate' => '2026-10-01 11:00:00'], 0],
            'cutoff dated row' => [['predate' => '2026-09-30 12:00:00'], 0],
            'future dated row' => [['predate' => '2026-10-02 12:00:00'], 0],
            'old undated import' => [['predate' => null], 1],
            'new undated import' => [['predate' => null, 'imported_at' => '2026-10-01 11:00:00'], 0],
            'cutoff undated import' => [['predate' => null, 'imported_at' => '2026-09-30 12:00:00'], 0],
            'future undated import' => [['predate' => null, 'imported_at' => '2026-10-02 12:00:00'], 0],
            'unknown age' => [['predate' => null, 'imported_at' => null], 0],
            'old dated row with new import' => [['imported_at' => '2026-10-01 11:00:00'], 1],
        ];
    }

    public function test_mixed_fixture_count_matches_worker_candidates_and_remains_capped(): void
    {
        foreach (array_values(self::eligibilityCases()) as $offset => [$attributes]) {
            $this->insertPredb($offset + 1, $attributes);
        }
        $service = new NameFixingQueryService($this->database);
        $candidates = $service->predbBatch(1, 1, 100);

        $this->assertSame([1, 2, 12, 17], array_map(static fn (object $row): int => (int) $row->predb_id, $candidates));
        $this->assertSame(count($candidates), $service->countPredbLauncherCandidates(4, 16));
        $this->assertSame(3, $service->countPredbLauncherCandidates(1, 3));
    }

    public function test_saturation_stops_evaluating_later_titles(): void
    {
        for ($id = 1; $id <= 200; $id++) {
            $this->insertPredb($id);
        }

        $this->assertSame(64, (new NameFixingQueryService($this->database))->countPredbLauncherCandidates(4, 16));
        $this->assertSame(64, $this->predicateEvaluations);
        $this->assertSame(200, $this->database->table('predb')->count());
        $this->assertSame(0, $this->database->table('predb')->where('searched', '!=', 0)->count());
    }

    #[DataProvider('nonPositiveParameters')]
    public function test_non_positive_parameters_return_zero_without_querying(int $maxPerRun, int $maxWorkers): void
    {
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects($this->never())->method('select');

        $this->assertSame(0, (new NameFixingQueryService($database))->countPredbLauncherCandidates($maxPerRun, $maxWorkers));
    }

    /** @return list<array{int, int}> */
    public static function nonPositiveParameters(): array
    {
        return [[0, 16], [-1, 16], [4, 0], [4, -1], [PHP_INT_MIN, PHP_INT_MAX]];
    }

    #[DataProvider('largeCaps')]
    public function test_large_caps_remain_integers_without_overflow(int $maxPerRun, int $maxWorkers, int $expectedCap): void
    {
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects($this->once())->method('select')
            ->with($this->isType('string'), ['2026-09-30 12:00:00', '2026-09-30 12:00:00', $expectedCap])
            ->willReturn([(object) ['num' => 2]]);

        $this->assertSame(2, (new NameFixingQueryService($database))->countPredbLauncherCandidates($maxPerRun, $maxWorkers));
    }

    /** @return list<array{int, int, int}> */
    public static function largeCaps(): array
    {
        return [
            [PHP_INT_MAX, 1, PHP_INT_MAX],
            [PHP_INT_MAX, 2, PHP_INT_MAX],
            [2, PHP_INT_MAX, PHP_INT_MAX],
            [intdiv(PHP_INT_MAX, 2), 2, PHP_INT_MAX - 1],
        ];
    }

    /** @param array<string, int|string|null> $attributes */
    private function insertPredb(int $id, array $attributes = []): void
    {
        $this->database->table('predb')->insert(array_replace([
            'id' => $id,
            'title' => 'Synthetic.Catalogue.Title.'.$id,
            'source' => 'fixture',
            'searched' => 0,
            'predate' => '2026-09-29 12:00:00',
            'imported_at' => '2026-09-29 12:00:00',
        ], $attributes));
    }
}
