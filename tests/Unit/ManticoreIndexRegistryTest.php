<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Services\Search\Support\ManticoreSchemaInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManticoreIndexRegistryTest extends TestCase
{
    #[Test]
    public function it_defines_every_search_table_with_relevance_settings(): void
    {
        $definitions = ManticoreIndexRegistry::definitions();

        self::assertSame(
            ['releases', 'predb', 'movies', 'tvshows', 'music', 'books', 'games', 'console', 'steam', 'anime'],
            array_keys($definitions)
        );

        foreach ($definitions as $definition) {
            self::assertSame(2, $definition['settings']['min_infix_len']);
            self::assertSame(1, $definition['settings']['exact_words']);
            self::assertSame(1, $definition['settings']['index_field_lengths']);
        }

        self::assertSame('bigint', $definitions['releases']['columns']['passwordstatus']['type']);
        self::assertSame('bigint', $definitions['releases']['columns']['haspreview']['type']);
    }

    #[Test]
    public function it_provides_title_first_field_weights(): void
    {
        self::assertGreaterThan(
            ManticoreIndexRegistry::profile('movies')['fields']['plot'],
            ManticoreIndexRegistry::profile('movies')['fields']['title']
        );
        self::assertGreaterThan(
            ManticoreIndexRegistry::profile('releases')['fields']['fromname'],
            ManticoreIndexRegistry::profile('releases')['fields']['searchname']
        );
    }

    #[Test]
    public function it_reports_schema_drift_that_requires_a_rebuild(): void
    {
        $result = ManticoreSchemaInspector::compareColumns(
            [['Field' => 'passwordstatus', 'Type' => 'uint']],
            ['passwordstatus' => ['type' => 'bigint'], 'haspreview' => ['type' => 'bigint']]
        );

        self::assertSame(['haspreview'], $result['missing']);
        self::assertSame('bigint', $result['incompatible']['passwordstatus']['expected']);
    }

    #[Test]
    public function benchmark_fixture_is_versioned_and_covers_every_table(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/Search/manticore-relevance-v1.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $fixture['version']);
        self::assertSame(array_keys(ManticoreIndexRegistry::definitions()), array_values(array_unique(array_column($fixture['queries'], 'index'))));
        self::assertArrayHasKey('top_5_precision', $fixture['thresholds']);
    }
}
