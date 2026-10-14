<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\YencService;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArticleProcessingBenchmarkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('NNTMUX_ARTICLE_BENCHMARK_FUNCTIONS_ONLY')) {
            define('NNTMUX_ARTICLE_BENCHMARK_FUNCTIONS_ONLY', true);
            require dirname(__DIR__, 2).'/scripts/benchmark-article-processing.php';
        }
    }

    public function test_profile_options_are_bounded_and_keep_default_fixture_family(): void
    {
        $options = articleBenchmarkOptions(['profile' => false, 'native' => '/native.so']);
        self::assertSame([4096, 524288, 1048576], $options['sizes']);
        self::assertSame(7, $options['samples']);
        self::assertSame(30.0, $options['timeout']);
        self::assertSame([64], articleBenchmarkOptions(['native' => '/native.so', 'size' => '64'])['sizes']);
    }

    public function test_profile_and_worker_sample_limits_keep_child_json_within_reader_budget(): void
    {
        self::assertSame(64, articleBenchmarkOptions(['profile' => false, 'native' => '/library.so', 'samples' => 64])['samples']);
        self::assertSame(1000, articleBenchmarkOptions(['paired' => false, 'native' => '/library.so', 'samples' => 1000])['samples']);
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['profile' => false, 'native' => '/library.so', 'samples' => 65]);
    }

    public function test_profile_bad_manifest_retains_structured_failure_and_invalid_summary(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-profile-manifest-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/bad.json', '{malformed');
        file_put_contents($directory.'/fake.so', 'invalid native library');
        ob_start();
        try {
            $options = ['profile' => false, 'native' => $directory.'/fake.so', 'size' => 64,
                'runtime-manifest' => $directory.'/bad.json', 'output-dir' => $directory.'/evidence'];
            self::assertSame(2, articleBenchmarkProfile($options, articleBenchmarkOptions($options)));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/evidence/observations.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertSame('failure', $rows[0]['type']);
            self::assertSame('invalid', $rows[array_key_last($rows)]['status']);
            self::assertFalse($rows[array_key_last($rows)]['performance_advantage_demonstrated']);
            $options['runtime-manifest'] = $directory.'/missing.json';
            $options['output-dir'] = $directory.'/missing-evidence';
            self::assertSame(2, articleBenchmarkProfile($options, articleBenchmarkOptions($options)));
            $missing = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/missing-evidence/observations.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertSame('failure', $missing[0]['type']);
            self::assertStringContainsString('missing or unreadable', $missing[0]['reason']);
            self::assertSame('invalid', $missing[array_key_last($missing)]['status']);
        } finally {
            ob_end_clean();
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_invalid_sizes_cannot_escape_metadata_parser_budget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['native' => '/native.so', 'size' => '1048577']);
    }

    public function test_non_numeric_samples_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['native' => '/native.so', 'samples' => 'seven']);
    }

    public function test_native_mode_cannot_silently_fall_back(): void
    {
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['profile' => false]);
    }

    public function test_fixture_lines_and_independent_decoded_bytes_are_consistent(): void
    {
        foreach (['ordinary', 'heavy'] as $density) {
            $fixture = articleBenchmarkFixture(4096, $density);
            self::assertSame(4096, strlen($fixture['data']));
            self::assertSame('Lines: '.substr_count($fixture['body'], "\n")."\r\n", $fixture['headers'][5]);
            self::assertStringEndsWith("\r\n.\r\n", $fixture['wire']);
            $service = new YencService(new PhpPayloadDecoder);
            self::assertSame($fixture['data'], $service->decode($fixture['body']));
        }
    }

    public function test_maximum_heavy_fixture_stays_within_both_native_body_budgets(): void
    {
        $fixture = articleBenchmarkFixture(1048576, 'heavy');
        self::assertLessThanOrEqual(2000000, strlen($fixture['body']));
        self::assertSame(1048576, strlen($fixture['data']));
    }

    public function test_failed_native_initialization_restores_previous_container(): void
    {
        $previous = Container::getInstance();
        try {
            articleBenchmarkWorker('full-analyzer', articleBenchmarkFixture(64, 'ordinary'), '/missing/native-library.so', 1);
            self::fail('Strict native initialization unexpectedly succeeded.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Native yEnc is unavailable', $exception->getMessage());
        }
        self::assertSame($previous, Container::getInstance());
    }

    public function test_exact_result_hash_preserves_array_order_and_value_types(): void
    {
        self::assertNotSame(articleBenchmarkResultHash(['first' => 1, 'second' => 2]), articleBenchmarkResultHash(['second' => 2, 'first' => 1]));
        self::assertNotSame(articleBenchmarkResultHash(['value' => 1]), articleBenchmarkResultHash(['value' => '1']));
        self::assertSame(articleBenchmarkResultHash(['bytes' => "\xff\0"]), articleBenchmarkResultHash(['bytes' => "\xff\0"]));
    }

    public function test_profile_retains_every_failed_worker_and_frozen_binary_without_claiming_an_advantage(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-article-failure-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        self::createSyntheticSource($directory.'/source', 'profile');
        file_put_contents($directory.'/fake.so', 'invalid native library');
        ob_start();
        try {
            $options = ['native' => $directory.'/fake.so', 'candidate-dir' => $directory.'/source', 'samples' => 1, 'size' => 64, 'output-dir' => $directory.'/evidence'];
            self::assertSame(2, articleBenchmarkProfile($options, articleBenchmarkOptions($options)));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/evidence/observations.jsonl', FILE_IGNORE_NEW_LINES));
            $summary = $rows[array_key_last($rows)];
            self::assertSame('invalid', $summary['status']);
            self::assertSame(14, $summary['invalid_observations']);
            self::assertFalse($summary['performance_advantage_demonstrated']);
            self::assertCount(14, glob($directory.'/evidence/*.stderr'));
            self::assertCount(14, glob($directory.'/evidence/*.stdout'));
            self::assertSame(hash_file('sha256', $directory.'/fake.so'), hash_file('sha256', $directory.'/evidence/native.so'));
            foreach ($rows as $row) {
                if ($row['type'] === 'observation') {
                    self::assertContains('--native='.$rows[0]['library']['path'], $row['command']);
                }
            }
            foreach (glob($directory.'/evidence/*.stderr') as $path) {
                self::assertStringContainsString('Native yEnc is unavailable', (string) file_get_contents($path));
            }
        } finally {
            ob_end_clean();
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_median_screen_requires_one_and_a_half_speedup_and_never_claims_p99(): void
    {
        $failed = articleBenchmarkScreenStratum(array_fill(0, 31, 0.8), 0, 123, 1.5);
        self::assertSame('inconclusive', $failed['decision']);
        self::assertSame(1.25, $failed['paired_median_latency_speedup']);
        self::assertTrue($failed['adoption_not_qualified_p99_missing']);
        self::assertSame('useful_improvement', articleBenchmarkScreenStratum(array_fill(0, 31, 2 / 3), 0, 123, 1.5)['decision']);
        self::assertSame('regression', articleBenchmarkScreenStratum(array_fill(0, 31, 1.2), 0, 123, 1.5)['decision']);
        self::assertSame('invalid', articleBenchmarkScreenStratum(array_fill(0, 31, 0.5), 1, 123, 1.5)['decision']);
    }

    public function test_paired_targets_require_useful_effect_while_small_controls_may_be_inconclusive(): void
    {
        $strata = [];
        foreach ([4096, 524288, 1048576] as $size) {
            foreach (['ordinary', 'heavy'] as $density) {
                $strata[] = ['size' => $size, 'density' => $density]
                    + yencBenchmarkDecision(array_fill(0, 31, $size === 4096 ? 1.02 : 0.8), 0, 5, 5, 123, 6, 'baseline');
            }
        }
        self::assertSame('useful_improvement', articleBenchmarkPairedDecision($strata, true, 31)['status']);
        self::assertTrue(articleBenchmarkPairedDecision($strata, true, 31)['screen_median_criterion_met']);
        self::assertFalse(articleBenchmarkPairedDecision($strata, true, 31)['performance_advantage_demonstrated']);
        self::assertTrue(articleBenchmarkPairedDecision($strata, true, 31)['adoption_not_qualified_p99_missing']);
        $strata[2]['decision'] = 'inconclusive';
        self::assertSame('inconclusive', articleBenchmarkPairedDecision($strata, true, 31)['status']);
        $regressionGate = articleBenchmarkPairedDecision($strata, true, 31, true);
        self::assertTrue($regressionGate['acceptance_criteria_met']);
        self::assertFalse($regressionGate['performance_advantage_demonstrated']);
        self::assertSame('regression_only', $regressionGate['comparison_mode']);
        self::assertFalse(articleBenchmarkPairedDecision($strata, true, 31)['acceptance_criteria_met']);
        self::assertFalse(articleBenchmarkPairedDecision($strata, true, 31)['screen_median_criterion_met']);
        $strata[0]['decision'] = 'regression';
        self::assertSame('regression', articleBenchmarkPairedDecision($strata, true, 31)['status']);
        self::assertFalse(articleBenchmarkPairedDecision($strata, false, 31)['performance_advantage_demonstrated']);
        self::assertSame('invalid', articleBenchmarkPairedDecision($strata, false, 31)['status']);
    }

    public function test_paired_screen_or_missing_strata_cannot_support_an_advantage_claim(): void
    {
        $strata = [];
        foreach ([4096, 524288, 1048576] as $size) {
            foreach (['ordinary', 'heavy'] as $density) {
                $strata[] = ['size' => $size, 'density' => $density, 'decision' => 'useful_improvement'];
            }
        }
        self::assertSame('invalid', articleBenchmarkPairedDecision($strata, true, 3)['status']);
        self::assertSame('invalid', articleBenchmarkPairedDecision(array_slice($strata, 1), true, 31)['status']);
        $strata[2]['decision'] = 'invalid';
        self::assertSame('invalid', articleBenchmarkPairedDecision($strata, true, 31)['status']);
    }

    public function test_paired_mode_freezes_the_complete_six_stratum_family(): void
    {
        $options = articleBenchmarkOptions(['paired' => false, 'native' => '/candidate.so']);
        self::assertSame(31, $options['samples']);
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['paired' => false, 'native' => '/candidate.so', 'size' => 4096]);
    }

    public function test_pair_eligibility_rejects_bad_decoding_metadata_library_and_combined_fallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nntmux-paired-metadata-');
        file_put_contents($path, serialize(['first' => 1, 'second' => 'two']));
        $result = ['library_sha256' => 'library', 'decoded_fixture_sha256' => 'decoded',
            'cold_reference' => ['correct' => true], 'reference_metadata_path' => $path,
            'reference_metadata_sha256' => hash_file('sha256', $path), 'reference_sha256' => 'result',
            'combined_required' => true, 'combined_available' => true,
            'observations' => array_map(static fn (int $sample): array => ['sample' => $sample, 'correct' => true,
                'result_sha256' => 'result', 'decoded_sha256' => 'decoded'], [-1, 0])];
        try {
            $child = ['status' => 'ok', 'result' => $result];
            self::assertTrue(articleBenchmarkPairedEligible($child, 'decoded', 'library', $path, true));
            self::assertFalse(articleBenchmarkPairedEligible($child, 'different-decoded', 'library', $path, true));
            self::assertFalse(articleBenchmarkPairedEligible($child, 'decoded', 'different-library', $path, true));
            $child['result']['combined_available'] = false;
            self::assertFalse(articleBenchmarkPairedEligible($child, 'decoded', 'library', $path, true));
            self::assertTrue(articleBenchmarkPairedEligible($child, 'decoded', 'library', $path, false));
            file_put_contents($path, serialize(['second' => 'two', 'first' => 1]));
            self::assertFalse(articleBenchmarkPairedEligible($child, 'decoded', 'library', $path, false));
        } finally {
            unlink($path);
        }
    }

    public function test_paired_combined_qualification_is_explicit_and_candidate_only(): void
    {
        self::assertSame(['--source-dir=/candidate'], articleBenchmarkPairOptions('candidate', '/candidate', false));
        self::assertSame(['--source-dir=/candidate'], articleBenchmarkPairOptions('candidate', '/candidate', true));
        self::assertSame(['--source-dir=/baseline'], articleBenchmarkPairOptions('baseline', '/baseline', true));
    }

    public function test_requested_missing_combined_method_cannot_silently_fall_back(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Candidate requires the combined article metadata ABI');
        articleBenchmarkQualifyCombined(new \stdClass, 'headers', 'body');
    }

    public function test_requested_unavailable_combined_result_cannot_silently_fall_back(): void
    {
        $parser = new class
        {
            public function parseArticleMetadata(string $headers, string $body): null
            {
                return null;
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Candidate combined article metadata ABI returned unavailable or invalid metadata');
        articleBenchmarkQualifyCombined($parser, 'headers', 'body');
    }

    public function test_workers_load_complete_selected_graphs_and_project_autoload_files(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-source-isolation-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        self::createSyntheticSource($directory.'/baseline', 'baseline');
        self::createSyntheticSource($directory.'/candidate', 'candidate');
        file_put_contents($directory.'/baseline/.env', 'private fixture');
        file_put_contents($directory.'/baseline/auth.json', 'private fixture');
        articleBenchmarkGit($directory.'/baseline', ['add', '.env', 'auth.json']);
        try {
            $baseline = articleBenchmarkFreezeGraph($directory.'/baseline', $directory.'/baseline-frozen');
            $candidate = articleBenchmarkFreezeGraph($directory.'/candidate', $directory.'/candidate-frozen');
            self::assertFileDoesNotExist($baseline['root'].'/.env');
            self::assertFileDoesNotExist($baseline['root'].'/auth.json');
            self::assertDirectoryDoesNotExist($baseline['root'].'/.git');
            foreach (['baseline' => $baseline, 'candidate' => $candidate] as $backend => $snapshot) {
                $child = yencBenchmarkRunChild(array_merge(articleBenchmarkPhpCommand(), [dirname(__DIR__, 2).'/scripts/benchmark-article-processing.php',
                    '--worker=inspect-sources', '--native=/unused.so', '--source-dir='.$snapshot['root']]), $directory.'/'.$backend.'-inspection', 30);
                self::assertSame('ok', $child['status'], (string) file_get_contents($child['stderr_path']));
                self::assertTrue(articleBenchmarkRuntimeMatches($child, $snapshot));
                $files = $child['result']['runtime_sources']['files'];
                self::assertSame($snapshot['files']['app/Services/YencService.php'], $files['app/Services/YencService.php']);
                self::assertSame($snapshot['files']['app/Services/Yenc/ArticleFrame.php'], $files['app/Services/Yenc/ArticleFrame.php']);
                self::assertArrayHasKey('app/Extensions/helper/helpers.php', $files);
                self::assertArrayHasKey('vendor/autoload.php', $files);
            }
            self::assertNotSame($baseline['files']['app/Services/YencService.php'], $candidate['files']['app/Services/YencService.php']);
            self::assertNotSame($baseline['files']['app/Services/Yenc/ArticleFrame.php'], $candidate['files']['app/Services/Yenc/ArticleFrame.php']);
            file_put_contents($directory.'/candidate/app/Services/YencService.php', '<?php changed source');
            self::assertTrue(articleBenchmarkGraphStable($candidate));
            self::assertFalse(articleBenchmarkGraphStable($candidate, $directory.'/candidate'));
        } finally {
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_worker_rejects_project_autoload_files_outside_selected_graph(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-autoload-escape-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        self::createSyntheticSource($directory.'/source', 'source');
        file_put_contents($directory.'/outside.php', '<?php');
        file_put_contents($directory.'/source/vendor/autoload.php', str_replace('return true;',
            'require '.var_export($directory.'/outside.php', true).'; return true;', (string) file_get_contents($directory.'/source/vendor/autoload.php')));
        try {
            $child = yencBenchmarkRunChild(array_merge(articleBenchmarkPhpCommand(), [dirname(__DIR__, 2).'/scripts/benchmark-article-processing.php',
                '--worker=inspect-sources', '--native=/unused.so', '--source-dir='.$directory.'/source']), $directory.'/escape', 30);
            self::assertSame('child_failed', $child['status']);
            self::assertStringContainsString('Included file escaped selected runtime', (string) file_get_contents($child['stderr_path']));
        } finally {
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_archive_only_graph_rejection_retains_setup_failure(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-archive-rejection-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        self::createSyntheticSource($directory.'/source', 'archive');
        self::removeOwnedDirectory($directory.'/source/.git');
        file_put_contents($directory.'/fake.so', 'invalid native library');
        ob_start();
        try {
            $options = ['paired' => false, 'samples' => 1, 'baseline-dir' => $directory.'/source', 'candidate-dir' => $directory.'/source',
                'native' => $directory.'/fake.so', 'baseline-native' => $directory.'/fake.so', 'output-dir' => $directory.'/evidence'];
            self::assertSame(2, articleBenchmarkPaired($options, articleBenchmarkOptions($options)));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/evidence/observations.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertSame('failure', $rows[0]['type']);
            self::assertStringContainsString('Git tracked-file manifest', $rows[0]['reason']);
            self::assertSame('invalid', $rows[array_key_last($rows)]['status']);
        } finally {
            ob_end_clean();
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_snapshot_rejects_tracked_symlinks_to_external_source(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-symlink-escape-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        self::createSyntheticSource($directory.'/source', 'source');
        file_put_contents($directory.'/outside.php', '<?php');
        symlink($directory.'/outside.php', $directory.'/source/escaped.php');
        articleBenchmarkGit($directory.'/source', ['add', 'escaped.php']);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Tracked source escapes selected source');
            articleBenchmarkFreezeGraph($directory.'/source', $directory.'/snapshot');
        } finally {
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_measured_worker_cannot_run_candidate_only_qualification(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Combined qualification must run in its own');
        articleBenchmarkWorker('full-analyzer', articleBenchmarkFixture(64, 'ordinary'), '/unused.so', 1, null, true);
    }

    public function test_frozen_binary_survives_atomic_replacement_of_the_supplied_path(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-library-freeze-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/native.so', 'first binary');
        try {
            $snapshot = articleBenchmarkFreezeLibrary($directory.'/native.so', $directory.'/frozen.so');
            file_put_contents($directory.'/replacement.so', 'replacement binary');
            rename($directory.'/replacement.so', $directory.'/native.so');
            self::assertSame('first binary', file_get_contents($snapshot['path']));
            self::assertSame($snapshot['sha256'], hash_file('sha256', $snapshot['path']));
            self::assertNotSame($snapshot['sha256'], hash_file('sha256', $directory.'/native.so'));
        } finally {
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_requested_qualification_runs_before_pairs_in_its_own_process(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-qualification-order-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        self::createSyntheticSource($directory.'/source', 'qualification');
        file_put_contents($directory.'/fake.so', 'invalid native library');
        ob_start();
        try {
            $options = ['paired' => false, 'require-combined' => false, 'samples' => 1,
                'baseline-dir' => $directory.'/source', 'candidate-dir' => $directory.'/source',
                'native' => $directory.'/fake.so', 'baseline-native' => $directory.'/fake.so', 'output-dir' => $directory.'/evidence'];
            self::assertSame(2, articleBenchmarkPaired($options, articleBenchmarkOptions($options)));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/evidence/observations.jsonl', FILE_IGNORE_NEW_LINES));
            $qualified = [];
            foreach ($rows as $row) {
                if ($row['type'] === 'qualification') {
                    self::assertContains('--worker=qualify-combined', $row['command']);
                    $qualified[$row['size'].'-'.$row['density']] = true;
                } elseif ($row['type'] === 'observation') {
                    self::assertArrayHasKey($row['size'].'-'.$row['density'], $qualified);
                    self::assertContains('--worker=full-analyzer', $row['command']);
                    self::assertNotContains('--require-combined', $row['command']);
                    self::assertFalse($row['eligible']);
                }
            }
            self::assertCount(6, $qualified);
            self::assertSame('invalid', $rows[array_key_last($rows)]['status']);
        } finally {
            ob_end_clean();
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_build_manifest_requires_matching_source_and_library_identities(): void
    {
        $manifest = ['baseline_library_sha256' => 'old-library', 'candidate_library_sha256' => 'new-library',
            'baseline_source_sha256' => ['source.php' => 'old-source'], 'candidate_source_sha256' => ['source.php' => 'new-source']];
        $libraries = ['baseline' => ['path' => '/old.so', 'sha256' => 'old-library'], 'candidate' => ['path' => '/new.so', 'sha256' => 'new-library']];
        $sources = ['baseline' => ['source.php' => 'old-source'], 'candidate' => ['source.php' => 'new-source']];
        articleBenchmarkVerifyManifest($manifest, $libraries, $sources);
        $sources['candidate']['source.php'] = 'changed-after-build';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source changed since recorded candidate build');
        articleBenchmarkVerifyManifest($manifest, $libraries, $sources);
    }

    public function test_paired_run_retains_all_failed_pairs_and_frozen_setup_evidence(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-article-paired-failure-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fake-library.so', 'deliberately invalid native library');
        self::createSyntheticSource($directory.'/baseline', 'baseline');
        self::createSyntheticSource($directory.'/candidate', 'candidate');
        ob_start();
        try {
            $options = ['paired' => false, 'native' => $directory.'/fake-library.so', 'baseline-native' => $directory.'/fake-library.so',
                'baseline-dir' => $directory.'/baseline', 'candidate-dir' => $directory.'/candidate', 'samples' => 3, 'order-seed' => 123, 'output-dir' => $directory.'/paired'];
            self::assertSame(2, articleBenchmarkPaired($options, articleBenchmarkOptions($options)));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/paired/observations.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertSame(6, $rows[0]['confidence_family_size']);
            self::assertSame(1, $rows[0]['timed_samples_per_worker']);
            self::assertFalse($rows[0]['candidate_combined_required']);
            foreach ($rows as $row) {
                if ($row['type'] === 'observation' && $row['backend'] === 'candidate') {
                    self::assertNotContains('--require-combined', $row['command']);
                }
            }
            self::assertFalse($rows[array_key_last($rows)]['performance_advantage_demonstrated']);
            self::assertSame('invalid', $rows[array_key_last($rows)]['status']);
            self::assertCount(36, glob($directory.'/paired/*.stderr'));
            self::assertCount(36, glob($directory.'/paired/*.stdout'));
            self::assertCount(6, glob($directory.'/paired/*.fixture.serialized'));
            $pairs = array_filter($rows, static fn (array $row): bool => $row['type'] === 'pair');
            self::assertCount(18, $pairs);
            foreach ($pairs as $pair) {
                self::assertFalse($pair['eligible']);
                self::assertNull($pair['candidate_over_baseline']);
            }
            self::assertSame(hash_file('sha256', $directory.'/fake-library.so'), hash_file('sha256', $directory.'/paired/baseline.so'));
            self::assertSame(hash_file('sha256', $directory.'/fake-library.so'), hash_file('sha256', $directory.'/paired/candidate.so'));
        } finally {
            ob_end_clean();
            self::removeOwnedDirectory($directory);
        }
    }

    public function test_real_native_worker_checks_every_phase_and_retains_cpu_observations(): void
    {
        $library = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! is_string($library) || ! is_file($library)) {
            self::markTestSkipped('Set NNTMUX_TEST_NATIVE_LIBRARY on Linux for the real native caller test.');
        }
        $directory = sys_get_temp_dir().'/nntmux-article-worker-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $cases = array_map(static fn (string $phase): array => [$phase, 4096, 'ordinary', 1], articleBenchmarkPhases());
            $cases[] = ['full-analyzer', 1048576, 'heavy', 1];
            $cases[] = ['body-metadata', 1048576, 'heavy', 64];
            foreach ($cases as [$phase, $size, $density, $samples]) {
                $prefix = $directory.'/'.$phase.'-'.$size;
                file_put_contents($prefix.'.fixture', serialize(articleBenchmarkFixture($size, $density)));
                $child = yencBenchmarkRunChild(array_merge(articleBenchmarkPhpCommand(), [dirname(__DIR__, 2).'/scripts/benchmark-article-processing.php',
                    '--worker='.$phase, '--native='.$library, '--samples='.$samples, '--fixture-path='.$prefix.'.fixture',
                    '--result-output='.$prefix.'.metadata']), $prefix, 30);
                self::assertSame('ok', $child['status'], (string) file_get_contents($child['stderr_path']));
                self::assertFalse($child['stdout_truncated_for_json']);
                $result = $child['result'];
                if ($result['reference_metadata_path'] !== null) {
                    self::assertSame($prefix.'.metadata', $result['reference_metadata_path']);
                    self::assertSame($result['reference_metadata_sha256'], hash_file('sha256', $prefix.'.metadata'));
                    self::assertIsArray(unserialize((string) file_get_contents($prefix.'.metadata'), ['allowed_classes' => false]));
                }
                self::assertSame('diagnostic_only', $result['evidence']);
                self::assertTrue($result['cold_reference']['correct']);
                self::assertGreaterThan(0, $result['cold_reference']['elapsed_ms']);
                self::assertCount($samples + 1, $result['observations']);
                if ($phase === 'body-metadata') {
                    self::assertCount($samples + 1, $result['body_ffi_breakdown']);
                    foreach ($result['body_ffi_breakdown'] as $bridge) {
                        self::assertTrue($bridge['correct']);
                        self::assertSame($result['reference_sha256'], $bridge['result_sha256']);
                        self::assertSame(['allocate_and_memcpy', 'native_parse_and_serialize', 'ffi_string_and_free', 'json_decode'], array_keys($bridge['stages_ms']));
                    }
                }
                foreach ($result['observations'] as $observation) {
                    self::assertTrue($observation['correct']);
                    self::assertSame($result['reference_sha256'], $observation['result_sha256']);
                    self::assertArrayHasKey('ru_utime.tv_usec', $observation['process_cpu_before']);
                    self::assertArrayHasKey('ru_stime.tv_usec', $observation['process_cpu_after']);
                }
            }
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private static function createSyntheticSource(string $root, string $marker): void
    {
        mkdir($root, 0700);
        $classes = [
            'App\\Services\\NNTP\\NNTPService' => '',
            'App\\Services\\Releases\\Fingerprint\\NzbFingerprintAnalyzer' => '',
            'App\\Services\\Releases\\Fingerprint\\NativeMetadataParser' => '',
            'App\\Services\\YencService' => 'public const MARKER = '.var_export($marker, true).';',
            'App\\Services\\Yenc\\DecoderFactory' => 'public function make(string $mode, string $path): object { throw new \\RuntimeException("Native yEnc is unavailable: synthetic invalid library"); }',
            'App\\Services\\Yenc\\NativePayloadDecoder' => '',
            'App\\Services\\Yenc\\ArticleFrame' => 'public const MARKER = '.var_export($marker, true).';',
        ];
        foreach ($classes as $class => $body) {
            $separator = strrpos($class, '\\');
            $namespace = substr($class, 0, $separator);
            $name = substr($class, $separator + 1);
            $path = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, '<?php namespace '.$namespace.'; class '.$name.' { '.$body.' }');
        }
        mkdir($root.'/app/Extensions/helper', 0700, true);
        file_put_contents($root.'/app/Extensions/helper/helpers.php', '<?php const SYNTHETIC_SOURCE_MARKER = '.var_export($marker, true).';');
        mkdir($root.'/tests/Support', 0700, true);
        copy(dirname(__DIR__).'/Support/YencNntpHarness.php', $root.'/tests/Support/YencNntpHarness.php');
        mkdir($root.'/vendor', 0700);
        file_put_contents($root.'/vendor/framework.php', <<<'PHP'
<?php
namespace Illuminate\Container;
class Container {
    private static ?self $instance = null;
    public static function getInstance(): self { return self::$instance ??= new self; }
    public static function setInstance(?self $value): void { self::$instance = $value; }
    public function instance(string $key, mixed $value): void {}
}
namespace Illuminate\Config;
class Repository { public function __construct(array $values) {} }
PHP);
        file_put_contents($root.'/vendor/autoload.php', <<<'PHP'
<?php
require __DIR__.'/framework.php';
require dirname(__DIR__).'/app/Extensions/helper/helpers.php';
spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__);
    foreach (['App\\' => '/app/', 'Tests\\' => '/tests/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $path = $root.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) { require $path; }
        }
    }
});
return true;
PHP);
        file_put_contents($root.'/composer.json', '{}');
        file_put_contents($root.'/composer.lock', '{}');
        articleBenchmarkGit($root, ['init', '--quiet']);
        $paths = ['app', 'tests', 'composer.json', 'composer.lock'];
        articleBenchmarkGit($root, ['add', ...$paths]);
    }

    private static function removeOwnedDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
