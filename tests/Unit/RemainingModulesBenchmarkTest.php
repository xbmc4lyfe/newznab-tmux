<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\RemainingMetadataBenchmarkFixtures;

final class RemainingModulesBenchmarkTest extends TestCase
{
    private function loadHarness(): void
    {
        $path = dirname(__DIR__, 2).'/scripts/benchmark-remaining-modules.php';
        $this->assertFileExists($path);
        if (! defined('NNTMUX_REMAINING_BENCHMARK_FUNCTIONS_ONLY')) {
            define('NNTMUX_REMAINING_BENCHMARK_FUNCTIONS_ONLY', true);
        }
        require_once $path;
    }

    public function test_cpp_date_plan_uses_only_date_strata_and_an_honest_native_identity(): void
    {
        $this->loadHarness();
        $this->assertTrue(function_exists('remainingBenchmarkStrata'));
        $strata = remainingBenchmarkStrata('date-cpp');
        $this->assertSame(['dates-ordinary', 'dates-folded', 'dates-uncommon-duplicates', 'dates-uncommon-malformed'], array_keys($strata));
        $this->assertSame(['article_dates'], array_values(array_unique(array_column($strata, 'module'))));
        $this->assertCount(12, remainingBenchmarkStrata('metadata-modules'));
        $this->assertSame(['cache' => 'dateLibraries', 'engine' => 'nntmux-cpp-nzbget-date-reference'], remainingBenchmarkNativeIdentity('article_dates', 'cpp'));
        $summary = remainingBenchmarkAcceptance(true, 0, true, 'date-cpp');
        $this->assertSame('cpp_nzbget_restricted_date_complete_metadata', $summary['baseline_kind']);
        $this->assertFalse($summary['adoption_qualified']);
    }

    public function test_full_date_parity_rejects_metadata_discarded_by_the_projection(): void
    {
        $this->loadHarness();
        self::assertTrue(function_exists('remainingBenchmarkDateFullParity'));
        $fixture = RemainingMetadataBenchmarkFixtures::make('dates-ordinary');
        $left = RemainingMetadataBenchmarkFixtures::expectedFullDates($fixture)
            + ['parser_engine' => 'cpp', 'syntax_profile' => 'restricted'];
        $right = array_replace($left, ['parser_engine' => 'rust', 'syntax_profile' => 'broader']);
        self::assertTrue(remainingBenchmarkDateFullParity($fixture, $left, $right));
        foreach (['raw_field_hex', 'unfolded_value_hex', 'timezone_token', 'timezone_offset_minutes',
            'timezone_local_offset_unknown', 'seconds_present', 'leap_second', 'weekday_matches_calendar', 'epoch_basis'] as $key) {
            $changed = $right;
            $changed['date']['candidates'][0][$key] = 'mutation';
            self::assertFalse(remainingBenchmarkDateFullParity($fixture, $left, $changed), $key);
            self::assertFalse(remainingBenchmarkDateFullParity($fixture, $changed, $changed), 'shared wrong '.$key);
        }
        $changed = $right;
        $changed['unrecognized_metadata'] = true;
        self::assertFalse(remainingBenchmarkDateFullParity($fixture, $left, $changed));
    }

    public function test_cpp_inputs_are_required_only_for_the_explicit_date_family(): void
    {
        $this->loadHarness();
        $this->assertTrue(function_exists('remainingBenchmarkStrata'));
        $valid = ['family' => 'date-cpp', 'baseline-native' => '/baseline.so', 'baseline-runtime-manifest' => '/baseline.json'];
        $this->assertSame('date-cpp', remainingBenchmarkOptions($valid)['family']);
        foreach ([['family' => 'date-cpp'], ['family' => 'date-cpp', 'baseline-native' => '/baseline.so'],
            array_replace($valid, ['family' => 'metadata-modules'])] as $invalid) {
            try {
                remainingBenchmarkOptions($invalid);
                $this->fail('Invalid baseline options were accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cpp_worker_cannot_escape_its_family_or_module(): void
    {
        $this->loadHarness();
        $this->assertTrue(function_exists('remainingBenchmarkValidateWorker'));
        remainingBenchmarkValidateWorker('date-cpp', 'cpp', 'article_dates');
        foreach ([['metadata-modules', 'cpp', 'article_dates'], ['archive-modules', 'cpp', 'article_dates'],
            ['date-cpp', 'cpp', 'article_identity'], ['date-cpp', 'php', 'article_dates']] as $arguments) {
            try {
                remainingBenchmarkValidateWorker(...$arguments);
                $this->fail('Invalid worker role was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cpp_receipt_binds_its_library_sources_and_genuine_parser_separately(): void
    {
        $this->loadHarness();
        $this->assertTrue(function_exists('remainingBenchmarkVerifyCppManifest'));
        $sources = ['scripts/benchmark-date-cpp.cpp' => str_repeat('a', 64), 'caller.php' => str_repeat('b', 64)];
        $manifest = ['baseline_library_sha256' => str_repeat('c', 64), 'baseline_source_sha256' => $sources,
            'baseline_kind' => 'cpp_nzbget_restricted_date_complete_metadata',
            'compiler' => ['path' => '/usr/bin/clang++', 'sha256' => str_repeat('d', 64), 'version' => 'clang test',
                'flags' => ['-std=c++20', '-O3', '-DNDEBUG', '-fPIC', '-shared']],
            'upstream' => ['commit' => '8f2f409d4e263ecac3313404ed4685e7dca3d9ec',
                'source_sha256' => '14a1c4c82c6c551806b142b3f9ac177a39af48dbaa92bceb763f98a21f0390d8']];
        remainingBenchmarkVerifyCppManifest($manifest, $sources, str_repeat('c', 64));
        foreach (['baseline_library_sha256', 'baseline_source_sha256', 'compiler', 'upstream', 'baseline_kind'] as $key) {
            $invalid = $manifest;
            unset($invalid[$key]);
            try {
                remainingBenchmarkVerifyCppManifest($invalid, $sources, str_repeat('c', 64));
                $this->fail('An incomplete C++ receipt was accepted.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['-O0', '-DNZBGET_USE_RUST', '-fno-inline'] as $flag) {
            $invalid = $manifest;
            $invalid['compiler']['flags'][] = $flag;
            try {
                remainingBenchmarkVerifyCppManifest($invalid, $sources, str_repeat('c', 64));
                $this->fail('An undeclared C++ build flag was accepted.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (array_keys($sources) as $path) {
            $invalid = $manifest;
            $invalid['baseline_source_sha256'][$path] = str_repeat('e', 64);
            try {
                remainingBenchmarkVerifyCppManifest($invalid, $sources, str_repeat('c', 64));
                $this->fail('Changed baseline source was accepted.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_tail_screen_cannot_qualify_or_invent_a_finite_upper_bound(): void
    {
        $this->loadHarness();
        $decision = remainingBenchmarkDecision(array_fill(0, 31, 3.0), array_fill(0, 31, 1.0), 0, 'uncommon', 'screen', 12);
        $this->assertFalse($decision['qualifies']);
        $this->assertNull($decision['simultaneous_95_percent_speedup_interval'][0]);
        $this->assertSame('p99_latency', $decision['metric']);
    }

    public function test_performance_gate_needs_the_conservative_lower_bound_and_zero_invalid_pairs(): void
    {
        $this->loadHarness();
        $baseline = array_fill(0, 1000, 3.0);
        $candidate = array_fill(0, 1000, 1.0);
        $positive = remainingBenchmarkDecision($baseline, $candidate, 0, 'uncommon', 'qualification', 12);
        $this->assertTrue($positive['qualifies']);
        $this->assertSame([3.0, 3.0], $positive['simultaneous_95_percent_speedup_interval']);
        $this->assertEqualsWithDelta(0.05 / 48, $positive['marginal_alpha'], 1e-12);
        $negative = remainingBenchmarkDecision($candidate, $baseline, 0, 'common', 'qualification', 12);
        $this->assertFalse($negative['qualifies']);
        $invalid = remainingBenchmarkDecision($baseline, $candidate, 1, 'common', 'qualification', 12);
        $this->assertFalse($invalid['qualifies']);
        $this->assertSame('invalid', $invalid['status']);
    }

    public function test_unknown_family_and_nonfinite_protocol_values_are_rejected(): void
    {
        $this->loadHarness();
        $this->expectException(\InvalidArgumentException::class);
        remainingBenchmarkOptions(['family' => 'metadata-modules', 'timeout' => 'INF']);
    }

    public function test_reference_comparison_never_qualifies_production_adoption(): void
    {
        $this->loadHarness();
        $summary = remainingBenchmarkAcceptance(true, 0, true);
        $this->assertTrue($summary['performance_gates_passed']);
        $this->assertFalse($summary['adoption_qualified']);
        $this->assertSame('independent_php_reference_projection', $summary['baseline_kind']);
        $invalid = remainingBenchmarkAcceptance(false, 0, true);
        $this->assertFalse($invalid['performance_gates_passed']);
    }

    public function test_actual_reference_worker_retains_output_and_rejects_a_changed_object(): void
    {
        $this->loadHarness();
        $directory = sys_get_temp_dir().'/remaining-worker-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $fixture = RemainingMetadataBenchmarkFixtures::make('dates-ordinary');
            $root = dirname(__DIR__, 2);
            $fixturePath = $directory.'/fixture.serialized';
            file_put_contents($fixturePath, serialize($fixture));
            $child = yencBenchmarkRunChild([...articleBenchmarkPhpCommand(), $root.'/scripts/benchmark-remaining-modules.php',
                '--family=metadata-modules', '--worker=php', '--source-dir='.$root, '--native=/no/native.so',
                '--fixture-path='.$fixturePath, '--result-store='.$directory], $directory.'/child', 15.0);
            $this->assertSame('ok', $child['status'], $child['parse_error'] ?? $child['status']);
            $result = $child['result'];
            foreach (['cold', 'warmup', 'warm'] as $phase) {
                foreach (['projection', 'full'] as $key) {
                    $this->assertSame($result['observations'][$phase][$key]['sha256'], hash_file('sha256', $result['observations'][$phase][$key]['path']));
                }
            }
            $journal = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($result['phase_journal']['path'], FILE_IGNORE_NEW_LINES));
            $this->assertSame(['cold', 'warmup', 'warm'], array_column($journal, 'phase'));
            $this->assertSame($fixture['expected'], unserialize(file_get_contents($result['projection']['path']), ['allowed_classes' => false]));
            $snapshot = ['root' => $root, 'files' => $result['runtime_sources']['files']];
            $args = ['php', 'article_dates', hash('sha256', serialize($fixture)), str_repeat('a', 64), $directory, $snapshot, remainingBenchmarkInstrumentation()];
            $this->assertTrue(remainingBenchmarkEligible($child, ...$args));
            $contaminated = $child;
            $contaminated['result']['observations']['cold']['selected_library_cached'] = true;
            $this->assertFalse(remainingBenchmarkEligible($contaminated, ...$args));
            file_put_contents($result['projection']['path'], 'changed');
            $this->assertFalse(remainingBenchmarkEligible($child, ...$args));
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_phase_journal_preserves_earlier_distinct_outputs_when_later_operation_fails(): void
    {
        $this->loadHarness();
        $directory = sys_get_temp_dir().'/remaining-phase-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $journal = $directory.'/phases.jsonl';
            $cold = remainingBenchmarkRetainPhase($directory, $journal, 'cold', ['full' => ['value' => 1], 'projection' => ['value' => 1]], ['correct' => true]);
            $warmup = remainingBenchmarkRetainPhase($directory, $journal, 'warmup', ['full' => ['value' => 2], 'projection' => ['value' => 2]], ['correct' => false]);
            remainingBenchmarkJournal($journal, ['phase' => 'warm', 'status' => 'invalid', 'error' => 'Later operation failed.']);
            $this->assertNotSame($cold['full']['sha256'], $warmup['full']['sha256']);
            $this->assertSame(['value' => 1], unserialize(file_get_contents($cold['full']['path']), ['allowed_classes' => false]));
            $this->assertSame(['value' => 2], unserialize(file_get_contents($warmup['full']['path']), ['allowed_classes' => false]));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($journal, FILE_IGNORE_NEW_LINES));
            $this->assertSame(['cold', 'warmup', 'warm'], array_column($rows, 'phase'));
            $this->assertFalse($rows[1]['correct']);
            $this->assertSame('invalid', $rows[2]['status']);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
