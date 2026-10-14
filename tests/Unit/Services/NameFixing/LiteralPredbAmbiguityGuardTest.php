<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Facades\Search;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class LiteralPredbAmbiguityGuardTest extends TestCase
{
    private ?Application $previousFacadeApplication;

    /** @var array<string, mixed>|null */
    private ?array $previousResolvedInstances;

    private ReflectionProperty $resolvedInstancesProperty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->resolvedInstancesProperty = new ReflectionProperty(Facade::class, 'resolvedInstance');
        $this->previousResolvedInstances = $this->resolvedInstancesProperty->getValue();
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication($this->previousFacadeApplication);
        $this->resolvedInstancesProperty->setValue(null, $this->previousResolvedInstances);
        parent::tearDown();
    }

    /**
     * @param  list<array{id: int, title: string, filename: string}>  $hits
     */
    #[DataProvider('matchingCases')]
    public function test_real_selector_preserves_acceptance_and_only_cleans_alias_ambiguity(
        string $query,
        array $hits,
        ?int $expectedId,
        int $expectedAmbiguityCalls,
    ): void {
        $search = new LiteralPredbSearchStub($hits);
        Search::swap($search);
        $cleaner = new LiteralPredbCountingCleaner;
        $service = new LiteralPredbMethodProbe($cleaner);

        self::assertSame($expectedId, $service->match($query)['id'] ?? null);
        self::assertSame($expectedAmbiguityCalls, $cleaner->calls);
        self::assertSame([NameFixingService::predbSearchText($query)], $search->queries);

        self::assertSame($expectedId, $service->match($query)['id'] ?? null);
        self::assertSame($expectedAmbiguityCalls, $cleaner->calls, 'Cached acceptance and rejection must avoid repeated ambiguity work.');
        self::assertCount(1, $search->queries, 'The unchanged query must use the match cache.');
    }

    /**
     * @return iterable<string, array{string, list<array{id: int, title: string, filename: string}>, ?int, int}>
     */
    public static function matchingCases(): iterable
    {
        $literal = 'Synthetic.Movie.2026.1080p-GROUP';
        $alias = 'Synthetic.Alias.2026.1080p-GROUP';
        $foreign = 'Foreign.Movie.2025.720p-OTHER';
        yield 'literal title' => [$literal, [self::hit(1, $literal, $literal.'.mkv')], 1, 0];
        yield 'same title distinct IDs' => [$literal, [self::hit(1, $literal), self::hit(2, $literal)], null, 0];
        yield 'foreign filenames do not conflict with literal title' => [$literal, [self::hit(1, $literal), self::hit(2, $foreign, $alias.'.mkv')], 1, 0];
        yield 'unique alias' => [$alias, [self::hit(1, $literal, $alias.'.mkv')], 1, 1];
        $lateAliasConflict = array_fill(0, 50, self::hit(1, $literal, $alias.'.mkv'));
        $lateAliasConflict[] = self::hit(2, $foreign, $alias.'.mkv');
        yield 'alias conflict beyond selector scoring window' => [$alias, $lateAliasConflict, null, 51];
        $lateTitleConflict = array_fill(0, 50, self::hit(1, $literal));
        $lateTitleConflict[] = self::hit(2, $literal);
        yield 'duplicate ID conflict beyond selector scoring window' => [$literal, $lateTitleConflict, null, 0];
        yield 'ambiguous alias' => [$alias, [self::hit(1, $literal, $alias.'.mkv'), self::hit(2, $foreign, $alias.'.mkv')], null, 0];
        yield 'punctuation differs' => [$literal, [self::hit(1, str_replace('.', ' ', $literal))], null, 0];
        yield 'numeric title' => ['100.Synthetic.Movie.2026.1080p-GROUP', [self::hit(1, '100.Synthetic.Movie.2026.1080p-GROUP')], 1, 0];
        yield 'unicode case identity' => ['École.Synthetic.2026.1080p-GROUP', [self::hit(1, 'école.synthetic.2026.1080p-group')], 1, 0];
        yield 'literal punctuation' => ['Synthetic.Movie_(2026).1080p-GROUP', [self::hit(1, 'Synthetic.Movie_(2026).1080p-GROUP')], 1, 0];
    }

    public function test_match_cache_keys_preserve_the_original_query(): void
    {
        $title = 'Synthetic.Movie.2026.1080p-GROUP';
        $search = new LiteralPredbSearchStub([self::hit(1, $title)]);
        Search::swap($search);
        $cleaner = new LiteralPredbCountingCleaner;
        $service = new LiteralPredbMethodProbe($cleaner);

        self::assertSame(1, $service->match($title)['id'] ?? null);
        self::assertSame(1, $service->match(strtolower($title))['id'] ?? null);
        self::assertSame(1, $service->match($title)['id']);
        self::assertSame([$title, strtolower($title)], $search->queries);
        self::assertSame(0, $cleaner->calls);
    }

    /** @return array{id: int, title: string, filename: string} */
    private static function hit(int $id, string $title, string $filename = ''): array
    {
        return ['id' => $id, 'title' => $title, 'filename' => $filename];
    }
}

final class LiteralPredbCountingCleaner extends FileNameCleaner
{
    public int $calls = 0;

    public function cleanForMatching(string $fileName, bool $preserveLeadingNumber = false): string|false
    {
        $this->calls++;

        return parent::cleanForMatching($fileName, $preserveLeadingNumber);
    }
}

final class LiteralPredbMethodProbe extends NameFixingService
{
    public function __construct(LiteralPredbCountingCleaner $ambiguityCleaner)
    {
        $this->fileNameCleaner = $ambiguityCleaner;
        $this->predbMatchSelector = new PredbMatchSelector(new FileNameCleaner);
    }

    /** @return array<string, mixed>|null */
    public function match(string $query): ?array
    {
        return $this->findBestPredbMatch($query);
    }
}

final class LiteralPredbSearchStub
{
    /** @var list<string> */
    public array $queries = [];

    /** @param list<array{id: int, title: string, filename: string}> $hits */
    public function __construct(private array $hits) {}

    /** @return list<array{id: int, title: string, filename: string}> */
    public function searchPredb(string $query): array
    {
        $this->queries[] = $query;

        return $this->hits;
    }
}
