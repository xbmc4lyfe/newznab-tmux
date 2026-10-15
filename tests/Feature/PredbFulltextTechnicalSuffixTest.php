<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PredbFulltextTechnicalSuffixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse()->byDefault();
    }

    #[DataProvider('suffixes')]
    public function test_appended_suffixes_are_validated_without_cleaning_canonical_title(string $titleEnding, string $suffix, bool $accepted, bool $quoted = true): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP'.$titleEnding;
        $candidate = $title.$suffix;
        if ($quoted) {
            $candidate = '[1/2] - "'.$candidate.'" yEnc';
        }
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn([42]);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($accepted ? $this->once() : $this->never())->method('updatePredbCandidate')->with(
            $this->callback(static fn (object $release): bool => (int) $release->releases_id === 42),
            $title,
            7,
            'synthetic',
            false
        )->willReturn(true);
        $updates->expects($this->never())->method('attachPredbId');
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database([[42, $candidate, 'Old.Name']])));

        $this->assertSame($accepted ? 1 : 0, $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']));
    }

    /** @return array<string, array{string, string, bool, 3?: bool}> */
    public static function suffixes(): array
    {
        return [
            'mp3 parity wrapper' => ['', '.mp3.par2', true],
            'flac archive wrapper' => ['', '.flac.rar', true],
            'ts parity wrapper' => ['', '.ts.par2', true],
            'case and stacked wrappers' => ['', '.MP3.part01.RAR.vol01+02.PAR2', true],
            'raw known suffix' => ['', '.ts.par2', true, false],
            'canonical codec preserved' => ['.DTS', '.mp3.par2', true],
            'canonical archive preserved' => ['.rar', '.flac.rar', true],
            'raw unknown suffix' => ['', '.unknown.par2', false, false],
            'wrapped unknown suffix' => ['', '.unknown.rar.par2', false],
            'two payload extensions' => ['', '.mkv.mp3.par2', false],
            'semantic year before wrapper' => ['', '.2025.rar', false],
            'semantic underscore before wrapper' => ['', '_Extended.rar', false],
            'semantic group before wrapper' => ['', '2.mp3.par2', false],
        ];
    }

    /** @param list<array{int, string, string}> $rows */
    private function database(array $rows): SQLiteConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE releases (additional_pack_evidence TEXT DEFAULT NULL, id INTEGER PRIMARY KEY, name TEXT, searchname TEXT, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER, guid TEXT)');
        // This synthetic LIKE models the accent/case equivalence independently observed on MariaDB.
        $pdo->sqliteCreateFunction('like', static function (string $pattern, string $subject, string $escape): int {
            $fold = static fn (string $value): string => mb_strtolower(str_replace(['é', 'É'], ['e', 'E'], $value), 'UTF-8');
            $regex = '';
            $escaped = false;
            foreach (mb_str_split($fold($pattern)) as $character) {
                if (! $escaped && $character === $escape) {
                    $escaped = true;

                    continue;
                }
                $regex .= $escaped ? preg_quote($character, '~') : match ($character) {
                    '%' => '.*',
                    '_' => '.',
                    default => preg_quote($character, '~'),
                };
                $escaped = false;
            }

            return preg_match('~^'.$regex.'$~us', $fold($subject));
        }, 3);
        $insert = $pdo->prepare('INSERT INTO releases (id, name, searchname, fromname, groups_id, categories_id, predb_id, guid) VALUES (?, ?, ?, "poster", 1, 7010, 0, ?)');
        foreach ($rows as $row) {
            $insert->execute([...$row, 'guid-'.$row[0]]);
        }

        return new class($pdo) extends SQLiteConnection
        {
            /**
             * @param  string  $query
             * @param  array<int|string, mixed>  $bindings
             * @param  array<int, mixed>  $fetchUsing
             * @return list<object>
             */
            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                // Preserve the real query; adapt MySQL's SQL literal for one escape character to SQLite.
                $query = str_replace("ESCAPE '\\\\'", "ESCAPE '\\'", $query);

                return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
            }
        };
    }
}
