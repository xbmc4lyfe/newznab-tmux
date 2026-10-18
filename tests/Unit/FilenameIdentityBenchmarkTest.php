<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\HeaderFilenameIdentity;
use PHPUnit\Framework\TestCase;
use Tests\Support\HeaderFilenameIdentityCorpus;

final class FilenameIdentityBenchmarkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('NNTMUX_FILENAME_IDENTITY_FUNCTIONS_ONLY')) {
            define('NNTMUX_FILENAME_IDENTITY_FUNCTIONS_ONLY', true);
        }
        require_once dirname(__DIR__, 2).'/scripts/benchmark-filename-identity.php';
    }

    public function test_every_stratum_is_deterministic_bounded_and_not_degenerate(): void
    {
        foreach (HeaderFilenameIdentityCorpus::strata() as $name => $stratum) {
            $first = HeaderFilenameIdentityCorpus::stratum($name, 60, 11);
            self::assertSame($first, HeaderFilenameIdentityCorpus::stratum($name, 60, 11), $name);
            self::assertCount(60, $first);
            foreach ($first as [$subject, $actual]) {
                self::assertLessThan(4096, strlen($subject), $name);
                self::assertLessThan(4096, strlen($actual), $name);
            }
            self::assertContains($stratum['category'], ['common', 'uncommon']);
        }
        $invalid = HeaderFilenameIdentityCorpus::stratum('invalid-utf8', 40, 3);
        foreach ($invalid as [$subject]) {
            self::assertFalse(mb_check_encoding($subject, 'UTF-8'));
        }
        foreach (HeaderFilenameIdentityCorpus::stratum('unquoted', 40, 3) as [$subject]) {
            self::assertStringNotContainsString('"', $subject);
        }
        $lengths = array_map(static fn (array $pair): int => strlen($pair[0]), HeaderFilenameIdentityCorpus::stratum('long-subject', 20, 3));
        self::assertGreaterThan(600, min($lengths));
    }

    public function test_quantiles_use_nearest_rank_with_ordered_intervals(): void
    {
        $values = range(1, 1000);
        $median = filenameIdentityQuantile($values, 0.5);
        self::assertSame(500.0, $median['estimate']);
        self::assertLessThan($median['estimate'], $median['low']);
        self::assertGreaterThan($median['estimate'], $median['high']);
        $tail = filenameIdentityQuantile($values, 0.99);
        self::assertSame(990.0, $tail['estimate']);
        self::assertLessThanOrEqual(1000.0, $tail['high']);
    }

    public function test_speedup_is_baseline_over_candidate_and_needs_the_lower_bound_to_qualify(): void
    {
        $php = array_fill(0, 500, 3000);
        $fast = array_fill(0, 500, 1000);
        $block = filenameIdentityQuantileBlock($php, $fast, 0.5);
        self::assertEqualsWithDelta(3.0, $block['speedup'], 1e-9);
        self::assertTrue($block['qualifies']);
        $slow = array_fill(0, 500, 2500);
        self::assertFalse(filenameIdentityQuantileBlock($php, $slow, 0.5)['qualifies']);
        $mixed = [...array_fill(0, 250, 1000), ...array_fill(0, 250, 3000)];
        self::assertFalse(filenameIdentityQuantileBlock($php, $mixed, 0.5)['qualifies'], 'A noisy candidate must not qualify on its point estimate alone.');
    }

    public function test_a_full_run_records_parity_and_never_qualifies_identical_implementations(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $php = static fn (string $subject, string $actual): bool => HeaderFilenameIdentity::matches($subject, $actual);
            $report = filenameIdentityRun($php, $php, static fn (): bool => false, 40, 3, 60, 5);
        } finally {
            restore_error_handler();
        }
        self::assertSame('not_qualified', $report['status']);
        self::assertSame([], $report['invalid']);
        self::assertCount(count(HeaderFilenameIdentityCorpus::strata()), $report['strata']);
        foreach ($report['strata'] as $row) {
            self::assertSame(0, $row['parity_mismatches']);
            self::assertSame(40 * 3, $row['observations']);
        }
        self::assertSame(60, $report['batch']['batches']);
        self::assertCount(60, $report['raw']['release-batch-16']['php']);
        self::assertStringContainsString('not_qualified', filenameIdentityMarkdown($report + ['environment' => []]));
    }

    public function test_a_verdict_mismatch_invalidates_the_run_instead_of_being_timed(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $php = static fn (string $subject, string $actual): bool => HeaderFilenameIdentity::matches($subject, $actual);
            $wrong = static fn (string $subject, string $actual): bool => ! HeaderFilenameIdentity::matches($subject, $actual);
            $report = filenameIdentityRun($php, $wrong, static fn (): bool => false, 40, 3, 60, 5);
        } finally {
            restore_error_handler();
        }
        self::assertSame('invalid', $report['status']);
        self::assertNotSame([], $report['invalid']);
        self::assertArrayNotHasKey('readable-quoted', $report['raw']);
    }
}
