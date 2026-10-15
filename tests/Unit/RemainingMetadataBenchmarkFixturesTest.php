<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\RemainingMetadataBenchmarkFixtures;

final class RemainingMetadataBenchmarkFixturesTest extends TestCase
{
    public function test_independent_references_match_frozen_oracles_and_reject_changed_results(): void
    {
        $counts = [];
        foreach (RemainingMetadataBenchmarkFixtures::strata() as $name => $definition) {
            $fixture = RemainingMetadataBenchmarkFixtures::make($name);
            self::assertSame($fixture, RemainingMetadataBenchmarkFixtures::make($name), $name);
            self::assertSame('bounded-reference-shared-projection-v1', $definition['comparison_scope']);
            $projection = RemainingMetadataBenchmarkFixtures::baseline($fixture);
            self::assertSame($fixture['expected'], $projection, $name);
            self::assertTrue(RemainingMetadataBenchmarkFixtures::correct($fixture, $projection), $name);
            self::assertFalse(RemainingMetadataBenchmarkFixtures::correct($fixture, []), $name);
            $projection['unexpected'] = true;
            self::assertFalse(RemainingMetadataBenchmarkFixtures::correct($fixture, $projection), $name);
            $counts[$definition['module']][$definition['category']] = ($counts[$definition['module']][$definition['category']] ?? 0) + 1;
        }
        self::assertCount(3, $counts);
        foreach ($counts as $count) {
            self::assertSame(['common' => 2, 'uncommon' => 2], $count);
        }
    }

    public function test_reference_reads_raw_input_and_ignores_oracle_and_fixture_name(): void
    {
        foreach (['dates-ordinary', 'identity-ordinary', 'xref-ordinary'] as $name) {
            $fixture = RemainingMetadataBenchmarkFixtures::make($name);
            $before = RemainingMetadataBenchmarkFixtures::baseline($fixture);
            $fixture['expected'] = ['poison' => true];
            $fixture['name'] = 'not-a-real-stratum';
            self::assertSame($before, RemainingMetadataBenchmarkFixtures::baseline($fixture));
            $fixture['raw_headers'] = [];
            self::assertNotSame($before, RemainingMetadataBenchmarkFixtures::baseline($fixture));
        }
    }

    public function test_adversarial_fixtures_exercise_real_limits_with_bounded_cost(): void
    {
        $dates = RemainingMetadataBenchmarkFixtures::make('dates-uncommon-duplicates');
        self::assertSame(65536, strlen(implode('', $dates['raw_headers'])));
        self::assertSame(256, $dates['expected']['date']['occurrence_count']);
        self::assertSame('budget_exceeded', $dates['expected']['date']['parse_status']);
        $identity = RemainingMetadataBenchmarkFixtures::make('identity-uncommon-boundary');
        self::assertSame(250, strlen($identity['requested_message_id']));
        self::assertSame(65536, strlen(implode('', $identity['raw_headers'])));
        self::assertSame('malformed_article_number', $identity['expected']['body_response']['parse_status']);
        $xref = RemainingMetadataBenchmarkFixtures::make('xref-uncommon-limits');
        self::assertSame(65536, strlen(implode('', $xref['raw_headers'])));
        self::assertCount(65, $xref['overview_rows']);
        self::assertSame(64, $xref['expected']['head']['occurrence_count']);
        self::assertCount(512, $xref['expected']['head']['candidates'][0]['locations']);
        self::assertSame('missing_or_budget_exceeded', $xref['expected']['overview']['parse_status']);
    }

    public function test_source_inventory_exists_and_covers_native_caller_and_reference(): void
    {
        $paths = RemainingMetadataBenchmarkFixtures::sourcePaths();
        self::assertContains('app/Services/Releases/Fingerprint/NativeMetadataParser.php', $paths);
        self::assertContains('tests/Fixtures/RemainingMetadataBenchmarkFixtures.php', $paths);
        foreach ($paths as $path) {
            self::assertFileExists(dirname(__DIR__, 2).'/'.$path);
        }
    }

    public function test_unavailable_native_wrapper_is_an_error_instead_of_a_reference_fallback(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => ['native_library' => '/nonexistent/offline-benchmark.so']]));
        Container::setInstance($container);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Native metadata wrapper returned null');
            RemainingMetadataBenchmarkFixtures::native(new NativeMetadataParser, RemainingMetadataBenchmarkFixtures::make('dates-ordinary'));
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_real_linux_native_wrappers_match_all_projection_oracles_and_retain_full_metadata(): void
    {
        $library = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! extension_loaded('FFI') || ! is_string($library) || $library === '') {
            self::markTestSkipped('Set NNTMUX_TEST_NATIVE_LIBRARY on Linux for actual native execution.');
        }
        self::assertFileExists($library);
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => ['native_library' => $library]]));
        Container::setInstance($container);
        try {
            $parser = new NativeMetadataParser;
            foreach (RemainingMetadataBenchmarkFixtures::strata() as $name => $definition) {
                $fixture = RemainingMetadataBenchmarkFixtures::make($name);
                $result = RemainingMetadataBenchmarkFixtures::native($parser, $fixture);
                self::assertSame($fixture['expected'], $result['projection'], $name);
                self::assertTrue(RemainingMetadataBenchmarkFixtures::correct($fixture, $result['projection']), $name);
                self::assertSame('nntmux-rust-'.str_replace('_', '-', $definition['module']), $result['full']['parser_engine']);
                self::assertArrayHasKey('interpretation', $result['full']);
                self::assertNotSame($result['full'], $result['projection']);
            }
        } finally {
            Container::setInstance($previous);
        }
    }
}
