<?php

declare(strict_types=1);

use App\Models\Predb;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\RegexService;
use App\Services\ReleaseCleaningService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseCleanerCollationIdentityTest extends TestCase
{
    #[DataProvider('identities')]
    public function test_actual_cleaner_respects_literal_identity(string $candidate, string $stored, bool $accepted): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $previousResolver = Predb::getConnectionResolver();
        try {
            $app = new Application(sys_get_temp_dir());
            $app->instance('config', new Repository);
            Container::setInstance($app);
            Facade::setFacadeApplication($app);
            $db = new Capsule($app);
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db->bootEloquent();
            $app->instance('db', $db->getDatabaseManager());
            $pdo = $db->getConnection()->getPdo();
            $pdo->createCollation('mysql_unicode_ci', static function (string $a, string $b): int {
                $fold = static fn (string $value): string => rtrim(mb_strtolower(str_replace(['é', 'É', 'ß', 'ẞ'], ['e', 'E', 'ss', 'SS'], $value), 'UTF-8'), ' ');

                return strcmp($fold($a), $fold($b));
            });
            $pdo->exec('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT COLLATE mysql_unicode_ci)');
            $pdo->prepare('INSERT INTO predb (id, title) VALUES (7, ?)')->execute([$stored]);
            $reflection = new ReflectionClass(ReleaseCleaningService::class);
            $cleaner = new ReleaseCleaningService;
            $regexes = $this->createMock(RegexService::class);
            $regexes->method('tryRegex')->willReturn('');
            $reflection->getProperty('_regexes')->setValue($cleaner, $regexes);
            $reflection->getProperty('nzbSplitUnwrapper')->setValue($cleaner, new NzbSplitUnwrapper);
            $result = $cleaner->releaseCleaner('"'.$candidate.'.mkv" yEnc', 'synthetic@example.invalid', 'alt.binaries.test', true);
            if (! $accepted) {
                self::assertFalse($result, 'Collation aliases must not bypass importer matchPre with a positive cleaner PreDB ID.');
            } else {
                self::assertSame([
                    'cleansubject' => $stored,
                    'properlynamed' => true,
                    'increment' => false,
                    'predb' => 7,
                    'requestid' => false,
                ], $result);
            }
            $ordinaryResult = $cleaner->releaseCleaner('"'.$candidate.'.mkv" yEnc', 'synthetic@example.invalid', 'alt.binaries.test');
            self::assertSame($accepted ? $result : $cleaner->generic(), $ordinaryResult, 'Rejected identities must preserve the legacy generic-cleaning fallback.');
        } finally {
            if ($previousResolver === null) {
                Predb::unsetConnectionResolver();
            } else {
                Predb::setConnectionResolver($previousResolver);
            }
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previousFacade);
        }
    }

    public static function identities(): iterable
    {
        yield 'accent alias' => ['Cafe.Synthetic.2026-GRP', 'Café.Synthetic.2026-GRP', false];
        yield 'eszett alias' => ['Strasse.Synthetic.2026-GRP', 'Straße.Synthetic.2026-GRP', false];
        yield 'canonical trailing blank' => ['Synthetic.Movie.2026-GRP', 'Synthetic.Movie.2026-GRP ', false];
        yield 'case only' => ['SYNTHETIC.MOVIE.2026-GRP', 'Synthetic.Movie.2026-GRP', true];
        yield 'exact' => ['Synthetic.Movie.2026-GRP', 'Synthetic.Movie.2026-GRP', true];
    }
}
