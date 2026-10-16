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
            self::assertSame($definition['module'] === 'article_dates' ? 'bounded-reference-date-clock-projection-v2' : 'bounded-reference-shared-projection-v1', $definition['comparison_scope']);
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

    public function test_date_reference_preserves_every_reported_clock_relation(): void
    {
        $fixture = RemainingMetadataBenchmarkFixtures::make('dates-ordinary');
        $projection = RemainingMetadataBenchmarkFixtures::baseline($fixture);
        self::assertArrayHasKey('reported_clock_relations', $projection);
        $pairs = ['date_to_injection_date' => [3600, 'before'], 'date_to_legacy_nntp_posting_date' => [0, 'equal'],
            'injection_date_to_legacy_nntp_posting_date' => [-3600, 'after']];
        foreach ($pairs as $pair => [$delta, $order]) {
            self::assertSame(['comparison_status' => 'compared', 'lhs_parse_status' => 'valid', 'rhs_parse_status' => 'valid',
                'rhs_minus_lhs_seconds' => $delta, 'lhs_order_relative_to_rhs' => $order], $projection['reported_clock_relations'][$pair]);
            foreach (['comparison_status', 'lhs_parse_status', 'rhs_parse_status', 'rhs_minus_lhs_seconds', 'lhs_order_relative_to_rhs'] as $field) {
                $mutated = $projection;
                $mutated['reported_clock_relations'][$pair][$field] = 'mutated';
                self::assertFalse(RemainingMetadataBenchmarkFixtures::correct($fixture, $mutated));
                unset($mutated['reported_clock_relations'][$pair][$field]);
                self::assertFalse(RemainingMetadataBenchmarkFixtures::correct($fixture, $mutated));
            }
        }
    }

    public function test_fixed_date_fixtures_have_independent_normalized_complete_output_oracles(): void
    {
        foreach (['dates-ordinary', 'dates-folded', 'dates-uncommon-duplicates', 'dates-uncommon-malformed'] as $name) {
            $fixture = RemainingMetadataBenchmarkFixtures::make($name);
            $expected = RemainingMetadataBenchmarkFixtures::expectedFullDates($fixture);
            self::assertSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($expected), $name);
            self::assertSame(2, $expected['schema_version'], $name);
            self::assertSame('Absolute header timestamps describe reported composition or injection clocks; they do not measure uploader bandwidth or establish uploader location. A -0000 zone gives UTC time without information about the originating local timezone.', $expected['interpretation'], $name);
        }

        $ordinary = RemainingMetadataBenchmarkFixtures::expectedFullDates(RemainingMetadataBenchmarkFixtures::make('dates-ordinary'));
        self::assertSame(bin2hex("Date: Mon, 01 Jan 2024 00:00:00 +0000\r\n"), $ordinary['date']['candidates'][0]['raw_field_hex']);
        self::assertSame(bin2hex(' Mon, 01 Jan 2024 00:00:00 +0000'), $ordinary['date']['candidates'][0]['unfolded_value_hex']);
        self::assertSame('+0000', $ordinary['date']['candidates'][0]['timezone_token']);
        self::assertSame('valid', $ordinary['date']['candidates'][0]['parse_status']);
        self::assertSame(1704067200, $ordinary['date']['candidates'][0]['utc_epoch_seconds']);
        self::assertTrue($ordinary['date']['candidates'][0]['weekday_matches_calendar']);

        $folded = RemainingMetadataBenchmarkFixtures::expectedFullDates(RemainingMetadataBenchmarkFixtures::make('dates-folded'));
        self::assertSame(bin2hex("dAtE: 01 Jan 2024\r\n\t00:00 -0000\r\n"), $folded['date']['candidates'][0]['raw_field_hex']);
        self::assertSame(bin2hex(" 01 Jan 2024\t00:00 -0000"), $folded['date']['candidates'][0]['unfolded_value_hex']);
        self::assertSame([2, 3], $folded['date']['candidates'][0]['source_line_indices']);

        $duplicates = RemainingMetadataBenchmarkFixtures::expectedFullDates(RemainingMetadataBenchmarkFixtures::make('dates-uncommon-duplicates'));
        self::assertSame('budget_exceeded', $duplicates['date']['parse_status']);
        self::assertCount(256, $duplicates['date']['candidates']);
        self::assertSame([1], $duplicates['date']['candidates'][0]['source_line_indices']);
        self::assertSame([256], $duplicates['date']['candidates'][255]['source_line_indices']);
    }

    public function test_complete_date_normalizer_ignores_only_top_level_engine_and_syntax(): void
    {
        $expected = RemainingMetadataBenchmarkFixtures::expectedFullDates(RemainingMetadataBenchmarkFixtures::make('dates-ordinary'));
        $identified = $expected + ['parser_engine' => 'different implementation', 'syntax_profile' => 'restricted reference grammar'];
        self::assertSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($identified));

        $nestedIdentification = $expected;
        $nestedIdentification['date']['parser_engine'] = 'nested field must remain';
        self::assertNotSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($nestedIdentification));

        $reordered = ['interpretation' => $expected['interpretation'], 'reported_clock_relations' => $expected['reported_clock_relations'],
            'legacy_nntp_posting_date' => $expected['legacy_nntp_posting_date'], 'injection_date' => $expected['injection_date'],
            'date' => $expected['date'], 'schema_version' => $expected['schema_version']];
        self::assertSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($reordered));

        $reordered['date']['candidates'][0]['source_line_indices'] = [2, 1];
        self::assertNotSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($reordered));
    }

    public function test_every_rich_date_field_and_unknown_key_changes_the_complete_oracle(): void
    {
        $expected = RemainingMetadataBenchmarkFixtures::expectedFullDates(RemainingMetadataBenchmarkFixtures::make('dates-ordinary'));
        $paths = [
            ['schema_version'],
            ['interpretation'],
            ['date', 'occurrence_count_basis'],
            ['date', 'candidates', 0, 'source_line_indices'],
            ['date', 'candidates', 0, 'raw_field_hex'],
            ['date', 'candidates', 0, 'unfolded_value_hex'],
            ['date', 'candidates', 0, 'parse_status'],
            ['date', 'candidates', 0, 'utc_epoch_seconds'],
            ['date', 'candidates', 0, 'timezone_token'],
            ['date', 'candidates', 0, 'timezone_offset_minutes'],
            ['date', 'candidates', 0, 'timezone_local_offset_unknown'],
            ['date', 'candidates', 0, 'seconds_present'],
            ['date', 'candidates', 0, 'leap_second'],
            ['date', 'candidates', 0, 'weekday_matches_calendar'],
            ['date', 'candidates', 0, 'epoch_basis'],
        ];
        foreach ($paths as $path) {
            $changed = $expected;
            $reference = &$changed;
            foreach (array_slice($path, 0, -1) as $key) {
                $reference = &$reference[$key];
            }
            $key = $path[array_key_last($path)];
            $value = $reference[$key];
            $reference[$key] = is_int($value) ? $value + 1 : (is_bool($value) ? ! $value : (is_string($value) ? $value.'!' : 'changed'));
            unset($reference);
            self::assertNotSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($changed), implode('.', $path));
        }

        $changed = $expected;
        $changed['unexpected_top_level'] = 'must be retained';
        self::assertNotSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($changed));
        $changed = $expected;
        $changed['date']['candidates'][0]['unexpected_candidate_field'] = 'must be retained';
        self::assertNotSame($expected, RemainingMetadataBenchmarkFixtures::normalizeFullDates($changed));
    }

    public function test_complete_date_oracle_rejects_mutated_fixture_bytes(): void
    {
        $fixture = RemainingMetadataBenchmarkFixtures::make('dates-folded');
        $fixture['raw_headers'][2] = "\t00:01 -0000\r\n";
        $this->expectException(\InvalidArgumentException::class);
        RemainingMetadataBenchmarkFixtures::expectedFullDates($fixture);
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
                if ($definition['module'] === 'article_dates') {
                    self::assertTrue(NativeMetadataParser::hasCurrentArticleDatesSchema($result['full']));
                    self::assertSame(RemainingMetadataBenchmarkFixtures::expectedFullDates($fixture),
                        RemainingMetadataBenchmarkFixtures::normalizeFullDates($result['full']), $name);
                }
                self::assertNotSame($result['full'], $result['projection']);
            }
        } finally {
            Container::setInstance($previous);
        }
    }
}
