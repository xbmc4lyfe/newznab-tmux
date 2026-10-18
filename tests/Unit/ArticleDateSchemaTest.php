<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use PHPUnit\Framework\TestCase;

final class ArticleDateSchemaTest extends TestCase
{
    public function test_current_date_schema_accepts_compared_and_unavailable_relations(): void
    {
        self::assertTrue(method_exists(NativeMetadataParser::class, 'hasCurrentArticleDatesSchema'));
        self::assertTrue(NativeMetadataParser::hasCurrentArticleDatesSchema(self::metadata()));
        self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema(null));
    }

    public function test_stale_or_inconsistent_relations_are_not_current_evidence(): void
    {
        self::assertTrue(method_exists(NativeMetadataParser::class, 'hasCurrentArticleDatesSchema'));
        $metadata = self::metadata();
        $metadata['schema_version'] = 1;
        self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
        foreach (['comparison_status' => 'source_unavailable', 'lhs_parse_status' => 'missing',
            'rhs_minus_lhs_seconds' => true, 'lhs_order_relative_to_rhs' => 'after'] as $key => $value) {
            $metadata = self::metadata();
            $metadata['reported_clock_relations']['date_to_injection_date'][$key] = $value;
            self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata), $key);
        }
        $metadata = self::metadata();
        unset($metadata['reported_clock_relations']['date_to_legacy_nntp_posting_date']);
        self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
    }

    public function test_malformed_containers_and_unknown_source_statuses_are_rejected(): void
    {
        foreach ([null, false, 'bad', new \stdClass] as $container) {
            $metadata = self::metadata();
            $metadata['reported_clock_relations'] = $container;
            self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
        }
        $metadata = self::metadata();
        $metadata['legacy_nntp_posting_date']['parse_status'] = 'invented';
        foreach (['date_to_legacy_nntp_posting_date', 'injection_date_to_legacy_nntp_posting_date'] as $pair) {
            $metadata['reported_clock_relations'][$pair]['rhs_parse_status'] = 'invented';
        }
        self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
    }

    public function test_collector_requires_current_date_evidence_for_every_observation(): void
    {
        self::assertTrue(method_exists(ReleaseFingerprintCollector::class, 'articleDateParserCoverageComplete'));
        $method = new \ReflectionMethod(ReleaseFingerprintCollector::class, 'articleDateParserCoverageComplete');
        $valid = ['metadata' => ['article_dates' => self::metadata()]];
        self::assertTrue($method->invoke(null, [$valid]));
        self::assertFalse($method->invoke(null, []));
        foreach ([null, ['parser_engine' => null, 'schema_version' => 2], array_replace(self::metadata(), ['schema_version' => 1])] as $invalid) {
            $observation = ['metadata' => ['article_dates' => $invalid]];
            self::assertFalse($method->invoke(null, [$observation]));
            self::assertFalse($method->invoke(null, [$valid, $observation]));
        }
        self::assertSame(40, ReleaseFingerprintCollector::SCHEMA_VERSION);
    }

    public function test_missing_or_inconsistent_candidate_provenance_is_not_current(): void
    {
        foreach (['occurrence_count', 'occurrence_count_basis', 'candidates'] as $key) {
            $metadata = self::metadata();
            unset($metadata['date'][$key]);
            self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata), $key);
        }
        $metadata = self::metadata();
        $metadata['date']['occurrence_count'] = 99;
        self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
        $metadata = self::metadata();
        $metadata['date']['candidates'][0]['utc_epoch_seconds'] = 102;
        self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
        $metadata = self::metadata();
        $metadata['date']['candidates'][0]['source_line_indices'] = [];
        self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
    }

    public function test_budget_exhaustion_is_well_formed_but_collection_remains_partial(): void
    {
        $metadata = self::metadata();
        $metadata['date']['parse_status'] = 'budget_exceeded';
        $metadata['date']['utc_epoch_seconds'] = null;
        $metadata['date']['occurrence_count'] = 256;
        $metadata['date']['candidates'] = [];
        for ($line = 1; $line <= 256; $line++) {
            $metadata['date']['candidates'][] = ['parse_status' => 'valid', 'utc_epoch_seconds' => 100, 'source_line_indices' => [$line]];
        }
        foreach (['date_to_injection_date', 'date_to_legacy_nntp_posting_date', 'date_to_expires'] as $pair) {
            $metadata['reported_clock_relations'][$pair]['comparison_status'] = 'source_unavailable';
            $metadata['reported_clock_relations'][$pair]['lhs_parse_status'] = 'budget_exceeded';
            $metadata['reported_clock_relations'][$pair]['rhs_minus_lhs_seconds'] = null;
            $metadata['reported_clock_relations'][$pair]['lhs_order_relative_to_rhs'] = null;
        }
        self::assertTrue(NativeMetadataParser::hasCurrentArticleDatesSchema($metadata));
        foreach ([1, 255] as $count) {
            $invalid = $metadata;
            $invalid['date']['occurrence_count'] = $count;
            $invalid['date']['candidates'] = array_slice($metadata['date']['candidates'], 0, $count);
            self::assertFalse(NativeMetadataParser::hasCurrentArticleDatesSchema($invalid));
        }
        $empty = $metadata;
        $empty['date']['occurrence_count'] = 0;
        $empty['date']['candidates'] = [];
        self::assertTrue(NativeMetadataParser::hasCurrentArticleDatesSchema($empty));
        $method = new \ReflectionMethod(ReleaseFingerprintCollector::class, 'articleDateParserCoverageComplete');
        self::assertFalse($method->invoke(null, [['metadata' => ['article_dates' => $metadata]]]));
    }

    /** @return array<string, mixed> */
    private static function metadata(): array
    {
        $unavailable = ['comparison_status' => 'source_unavailable', 'lhs_parse_status' => 'valid',
            'rhs_parse_status' => 'missing', 'rhs_minus_lhs_seconds' => null, 'lhs_order_relative_to_rhs' => null];
        $bothMissing = ['comparison_status' => 'source_unavailable', 'lhs_parse_status' => 'missing',
            'rhs_parse_status' => 'missing', 'rhs_minus_lhs_seconds' => null, 'lhs_order_relative_to_rhs' => null];

        $metadata = ['parser_engine' => 'nntmux-rust-article-dates', 'schema_version' => 2,
            'date' => ['parse_status' => 'valid', 'utc_epoch_seconds' => 100],
            'injection_date' => ['parse_status' => 'valid', 'utc_epoch_seconds' => 101],
            'legacy_nntp_posting_date' => ['parse_status' => 'missing', 'utc_epoch_seconds' => null],
            'expires' => ['parse_status' => 'missing', 'utc_epoch_seconds' => null],
            'reported_clock_relations' => [
                'date_to_injection_date' => ['comparison_status' => 'compared', 'lhs_parse_status' => 'valid',
                    'rhs_parse_status' => 'valid', 'rhs_minus_lhs_seconds' => 1, 'lhs_order_relative_to_rhs' => 'before'],
                'date_to_legacy_nntp_posting_date' => $unavailable,
                'injection_date_to_legacy_nntp_posting_date' => $unavailable,
                'date_to_expires' => $unavailable,
                'injection_date_to_expires' => $unavailable,
                'legacy_nntp_posting_date_to_expires' => $bothMissing,
            ]];
        foreach (['date', 'injection_date', 'legacy_nntp_posting_date', 'expires'] as $index => $source) {
            $value = &$metadata[$source];
            $value['occurrence_count_basis'] = 'retained candidates; total may be larger when budget_exceeded';
            $value['candidates'] = $value['parse_status'] === 'missing' ? [] : [[
                'parse_status' => $value['parse_status'], 'utc_epoch_seconds' => $value['utc_epoch_seconds'],
                'source_line_indices' => [$index + 1],
            ]];
            $value['occurrence_count'] = count($value['candidates']);
            unset($value);
        }

        return $metadata;
    }
}
