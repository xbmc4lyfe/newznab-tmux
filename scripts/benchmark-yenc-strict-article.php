<?php

declare(strict_types=1);

namespace Nntmux\Benchmark\StrictArticle\Controller;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** @return list<string> */
function expectedFixtureIds(): array
{
    $ids = [];
    foreach ([4096, 524288, 1048576, 8388608] as $size) {
        foreach (['ordinary', 'heavy'] as $density) {
            foreach (['tolerant', 'strict', 'body'] as $path) {
                $ids[] = "single-{$size}-{$density}-{$path}";
            }
            $ids[] = "multipart-{$size}-{$density}-strict";
        }
    }

    return [...$ids, 'rare-empty', 'rare-multipart-split', 'rare-duplicate-fallback',
        'rare-trailer-name-crc-fallback', 'rare-dangling-before-range', 'rare-invalid-range',
        'rare-missing-pcrc-before-size', 'rare-size-before-crc', 'rare-crc-mismatch', 'rare-numeric-overflow-fallback'];
}

/** @param array<string, mixed> $observation
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $protocol
 */
function eligibleObservation(array $observation, string $backend, array $fixture, array $protocol): bool
{
    $result = $observation['result'] ?? [];
    if (! is_array($result)) {
        return false;
    }
    $duration = $result['median_ms'] ?? null;
    $contract = match ($fixture['path'] ?? null) {
        'strict' => 'strict_unchanged', 'tolerant' => 'tolerant_decoded_in_place', 'body' => 'body_stream_source_unchanged',
        default => null,
    };

    return ($observation['status'] ?? null) === 'ok' && ($observation['exit_code'] ?? null) === 0
        && ($result['status'] ?? null) === 'ok'
        && ($result['backend'] ?? null) === $backend && ($result['id'] ?? null) === $fixture['id']
        && ($result['protocol_sha256'] ?? null) === $protocol['protocol_sha256']
        && ($result['library_sha256'] ?? null) === $protocol['libraries'][$backend]['sha256']
        && ($result['correct'] ?? null) === true && ($result['input_unchanged'] ?? null) === true
        && ($result['admission'] ?? null) === $fixture['admission']
        && ($result['elapsed_ms'] ?? null) === $duration
        && $contract !== null && ($result['input_contract'] ?? null) === $contract
    && (is_float($duration) || is_int($duration)) && is_finite((float) $duration) && $duration > 0;
}

function json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function writeJsonNew(string $path, mixed $value): void
{
    writeBytesNew($path, json($value)."\n");
}

function writeBytesNew(string $path, string $bytes): void
{
    $file = fopen($path, 'x');
    if ($file === false) {
        throw new RuntimeException('Evidence output already exists or cannot be created.');
    }
    try {
        for ($offset = 0; $offset < strlen($bytes); $offset += $count) {
            $count = fwrite($file, substr($bytes, $offset));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Evidence output write failed.');
            }
        }
    } finally {
        fclose($file);
    }
}

/** Clear Git overrides and prove the physical checkout before querying its state.
 *
 * @phpstan-impure Reads mutable process environment and Git state.
 *
 * @param  list<string>  $arguments
 */
function gitAtRoot(string $root, array $arguments): string
{
    $environment = [];
    foreach (array_keys(getenv() + $_ENV + $_SERVER) as $key) {
        if (str_starts_with($key, 'GIT_')) {
            $environment[$key] = false;
        }
    }
    // Explicit assignments override cleared values for the policy keys.
    foreach (['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
        'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_LAZY_FETCH' => '1'] as $key => $value) {
        $environment[$key] = $value;
    }
    $git = ['git', '--no-replace-objects', '--no-lazy-fetch', '-c', 'core.fsmonitor=false'];
    $actualRoot = trim((new Process([...$git, 'rev-parse', '--show-toplevel'], $root, $environment))->setTimeout(10)->mustRun()->getOutput());
    if (realpath($actualRoot) !== realpath($root)) {
        throw new RuntimeException('Git source root differs from the physical experiment checkout.');
    }

    return (new Process([...$git, ...$arguments], $root, $environment))->setTimeout(10)->mustRun()->getOutput();
}

/** @param array<string, mixed> $manifest */
function optimizedBuildReceiptMatches(array $manifest): bool
{
    $receipt = $manifest['rust_build_receipt'] ?? [];
    if (! is_array($receipt) || ! is_array($receipt['environment'] ?? null)
        || ($receipt['exit_code'] ?? null) !== 0 || ($receipt['argv'] ?? null) !== [
            'cargo', '+1.98.0', 'build', '--release', '--locked', '--features', 'experimental-strict-article',
            '--manifest-path', 'docker/app/nzb-yenc-ffi/Cargo.toml',
        ]) {
        return false;
    }
    foreach (['RUSTFLAGS', 'CARGO_ENCODED_RUSTFLAGS', 'CARGO_BUILD_RUSTFLAGS', 'CARGO_PROFILE_RELEASE_OPT_LEVEL',
        'CARGO_PROFILE_RELEASE_LTO', 'CARGO_PROFILE_RELEASE_CODEGEN_UNITS', 'CARGO_PROFILE_RELEASE_DEBUG',
        'CARGO_PROFILE_RELEASE_DEBUG_ASSERTIONS', 'CARGO_BUILD_TARGET', 'CARGO_TARGET_DIR', 'RUSTC',
        'RUSTC_WRAPPER', 'RUSTC_WORKSPACE_WRAPPER', 'CARGO_PROFILE_RELEASE_PANIC'] as $key) {
        if (! array_key_exists($key, $receipt['environment'] ?? []) || ! in_array($receipt['environment'][$key], [null, ''], true)) {
            return false;
        }
    }

    return true;
}

/** Retain process diagnostics even when Symfony throws on timeout. */
function runStatistics(Process $process, string $directory): int
{
    $failure = null;
    try {
        $process->run();

        return $process->getExitCode() ?? throw new RuntimeException('Statistics process has no exit code.');
    } catch (Throwable $exception) {
        $failure = $exception;
        throw $exception;
    } finally {
        writeBytesNew($directory.'/statistics.stdout', $process->isStarted() ? $process->getOutput() : '');
        writeBytesNew($directory.'/statistics.stderr', $process->isStarted() ? $process->getErrorOutput() : '');
        writeJsonNew($directory.'/statistics-process.json', ['started' => $process->isStarted(), 'exit_code' => $process->getExitCode(),
            'timed_out' => $failure instanceof ProcessTimedOutException, 'error_class' => $failure === null ? null : $failure::class,
            'error' => $failure?->getMessage()]);
    }
}

/** @param callable(list<string>, string, float): array<string, mixed> $execute
 * @param  list<string>  $command
 * @return array<string, mixed>|null
 */
function scheduledChild(callable $execute, array $command, string $prefix, float $timeout, int $deadline, ?string &$stopReason): ?array
{
    if ($stopReason !== null) {
        return null;
    }
    $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
    if ($remaining <= 0) {
        $stopReason = 'worker_scheduling_budget_exhausted';

        return null;
    }
    $observation = $execute($command, $prefix, min($timeout, $remaining));
    if (($observation['timed_out'] ?? null) === true) {
        $stopReason = 'worker_timeout';
    }

    return $observation;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    if (! is_file($path) || ! is_readable($path) || filesize($path) > 8_388_608) {
        throw new RuntimeException('Missing or oversized JSON input.');
    }
    $value = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('JSON input must contain an object.');
    }

    return $value;
}

/** @param array<string, mixed> $options */
function integerOption(array $options, string $key, int $default, int $minimum, int $maximum): int
{
    $raw = (string) ($options[$key] ?? $default);
    if (! preg_match('/\A[0-9]{1,10}\z/', $raw) || (int) $raw < $minimum || (int) $raw > $maximum) {
        throw new InvalidArgumentException('Invalid experimental '.$key.' option.');
    }

    return (int) $raw;
}

/** @param array<string, mixed> $summary
 * @param  list<array<string, mixed>>  $strata
 */
function statisticsMatches(array $summary, array $strata, string $protocolHash, string $inputHash, int $seed): bool
{
    foreach (['schema_version' => 1, 'bootstrap_replicates' => 10000, 'simultaneous_confidence' => 0.95,
        'adoption_qualified' => false, 'maximum_rust_over_cpp' => 2 / 3, 'per_tail_probability' => 0.025 / 42,
        'interval_lower_index' => 5, 'interval_upper_index' => 9994, 'rng' => 'local_splitmix64_rejection_indices',
        'multiple_comparison_method' => 'Bonferroni across all declared strata including invalid/insufficient strata',
        'interval_method' => 'percentile; floor/ceil of p*(B-1), zero-based',
        'p99_method' => 'nearest rank ceil(99*n/100), independently for CPP and Rust within paired-index resamples'] as $key => $value) {
        if (! array_key_exists($key, $summary) || $summary[$key] !== $value) {
            return false;
        }
    }
    if (($summary['protocol_sha256'] ?? null) !== $protocolHash || ($summary['input_sha256'] ?? null) !== $inputHash
        || ($summary['seed'] ?? null) !== $seed || ($summary['input_complete'] ?? null) !== true
        || ($summary['declared_strata'] ?? null) !== 42 || count($strata) !== 42
        || ($summary['required_speedup'] ?? null) !== 1.5 || ! is_array($summary['strata'] ?? null)
        || count($summary['strata']) !== 42 || ! is_bool($summary['performance_thresholds_met'] ?? null)) {
        return false;
    }
    $expected = array_column($strata, null, 'id');
    if (count($expected) !== 42 || array_diff(expectedFixtureIds(), array_keys($expected)) !== []) {
        return false;
    }
    $seen = [];
    $allQualified = true;
    foreach ($summary['strata'] as $row) {
        $id = $row['id'] ?? '';
        if (! is_string($id) || ! isset($expected[$id]) || isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
        if (($row['family'] ?? null) !== $expected[$id]['family']
            || ($row['pairs'] ?? null) !== count($expected[$id]['pairs'])
            || ($row['invalid_pairs'] ?? null) !== $expected[$id]['invalid_pairs'] || ! is_bool($row['qualified'] ?? null)) {
            return false;
        }
        $rare = $row['family'] === 'uncommon';
        $minimum = $rare ? 2000 : 31;
        if (($row['minimum_pairs'] ?? null) !== $minimum || ($row['method'] ?? null) !== ($rare
            ? 'paired_index_bootstrap_of_separate_nearest_rank_p99_ratio' : 'paired_bootstrap_median_of_rust_cpp_ratios')) {
            return false;
        }
        if ($row['invalid_pairs'] !== 0 || $row['pairs'] < $minimum) {
            if ($row['qualified'] || ($row['bootstrap_replicates'] ?? null) !== 0
                || ($row['status'] ?? null) !== ($row['invalid_pairs'] !== 0 ? 'invalid_pairs' : 'insufficient_pairs')) {
                return false;
            }
            foreach (['interval', 'rust_over_cpp', 'observed_speedup', 'cpp_p99_ms', 'rust_p99_ms'] as $key) {
                if (! array_key_exists($key, $row) || $row[$key] !== null) {
                    return false;
                }
            }
        } else {
            $lower = $row['interval']['lower'] ?? null;
            $upper = $row['interval']['upper'] ?? null;
            foreach ([$lower, $upper, $row['rust_over_cpp'] ?? null, $row['observed_speedup'] ?? null,
                ...($rare ? [$row['cpp_p99_ms'] ?? null, $row['rust_p99_ms'] ?? null] : [])] as $number) {
                if ((! is_int($number) && ! is_float($number)) || ! is_finite((float) $number) || $number <= 0) {
                    return false;
                }
            }
            // Relative tolerance absorbs floating-point division rounding only.
            $ratio = (float) $row['rust_over_cpp'];
            $speedup = (float) $row['observed_speedup'];
            $reciprocal = 1 / $ratio;
            $p99Ratio = $rare ? $row['rust_p99_ms'] / $row['cpp_p99_ms'] : $ratio;
            if (! is_finite($reciprocal) || $reciprocal <= 0 || ! is_finite($p99Ratio) || $p99Ratio <= 0
                || abs($speedup - $reciprocal) > 1e-12 * max($speedup, $reciprocal)
                || abs($ratio - $p99Ratio) > 1e-12 * max($ratio, $p99Ratio)) {
                return false;
            }
            if ($lower > $upper || ($row['bootstrap_replicates'] ?? null) !== 10000 || $row['qualified'] !== ($upper <= 2 / 3)
                || ($row['status'] ?? null) !== ($row['qualified'] ? 'qualified' : ($lower > 2 / 3 ? 'threshold_not_met' : 'inconclusive'))) {
                return false;
            }
        }
        $allQualified = $allQualified && $row['qualified'];
    }

    return $allQualified === $summary['performance_thresholds_met'];
}

/** @param array<string, mixed> $options */
function run(array $options): int
{
    $directory = (string) ($options['output-dir'] ?? '');
    $owned = false;
    $log = null;
    $emit = static function (array $record) use (&$log): void {
        $line = json($record)."\n";
        if (is_resource($log) && fwrite($log, $line) !== strlen($line)) {
            throw new RuntimeException('Observation write failed.');
        }
        echo $line;
    };
    try {
        if ($directory === '' || file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700)) {
            throw new RuntimeException('Experimental evidence requires a new output directory.');
        }
        $owned = true;
        $directory = realpath($directory);
        writeJsonNew($directory.'/owned.json', ['owner_pid' => getmypid(), 'protocol' => 'strict-article-experiment-v1']);
        $log = fopen($directory.'/observations.jsonl', 'x');
        if ($log === false) {
            throw new RuntimeException('Cannot create the observation log.');
        }
        $allowed = ['output-dir', 'cpp-native', 'rust-native', 'runtime-manifest', 'statistics-bin', 'samples', 'p99-samples', 'seed', 'timeout'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported experimental option; the complete fixed matrix is required.');
        }
        $samples = integerOption($options, 'samples', 31, 31, 1000);
        $p99Samples = integerOption($options, 'p99-samples', 2000, 2000, 10000);
        $seed = integerOption($options, 'seed', 20261013, 1, 2147483647);
        $timeout = (float) ($options['timeout'] ?? 30);
        if (! is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new InvalidArgumentException('Invalid experimental timeout.');
        }
        $root = dirname(__DIR__);
        require_once $root.'/vendor/autoload.php';
        require_once $root.'/scripts/yenc-strict-article-worker.php';
        if (! defined('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY')) {
            define('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY', true);
        }
        require_once __DIR__.'/benchmark-yenc.php';
        $head = trim(gitAtRoot($root, ['rev-parse', 'HEAD']));
        gitAtRoot($root, ['diff', '--exit-code', 'HEAD', '--']);
        $paths = \Nntmux\Benchmark\StrictArticle\sourcePaths();
        gitAtRoot($root, ['ls-files', '--error-unmatch', '--', ...array_filter($paths, static fn (string $path): bool => ! str_starts_with($path, 'vendor/'))]);
        $sourceHashes = [];
        foreach ($paths as $path) {
            if (! is_file($root.'/'.$path) || ! is_readable($root.'/'.$path)) {
                throw new RuntimeException('Required source path is unavailable.');
            }
            $sourceHashes[$path] = hash_file('sha256', $root.'/'.$path);
        }
        $manifest = readJson((string) ($options['runtime-manifest'] ?? ''));
        if (($manifest['git_head'] ?? null) !== $head
            || ! optimizedBuildReceiptMatches($manifest)
            || ($manifest['rust_features'] ?? null) !== ['experimental-strict-article']
            || ($manifest['rust_payload_mode'] ?? null) !== 'incumbent'
            || ($manifest['rust_strict_article_mode'] ?? null) !== 'experimental'
            || ($manifest['cargo_toml_sha256'] ?? null) !== $sourceHashes['docker/app/nzb-yenc-ffi/Cargo.toml']
            || ($manifest['cargo_lock_sha256'] ?? null) !== $sourceHashes['docker/app/nzb-yenc-ffi/Cargo.lock']
            || ($manifest['cpp_build_script_sha256'] ?? null) !== hash_file('sha256', $root.'/scripts/build-rapidyenc.sh')) {
            throw new RuntimeException('Build receipt does not match the committed experiment.');
        }
        $rustSources = array_filter($sourceHashes, static fn (string $path): bool => str_starts_with($path, 'docker/app/nzb-yenc-ffi/src/'), ARRAY_FILTER_USE_KEY);
        $receiptSources = $manifest['rust_source_sha256'] ?? [];
        ksort($rustSources);
        ksort($receiptSources);
        if ($rustSources !== $receiptSources) {
            throw new RuntimeException('Rust source/build receipt differs.');
        }
        $archives = [];
        foreach (glob($root.'/storage/app/rapidyenc/rapidyenc-*.tar.gz') as $archive) {
            $archives[basename($archive)] = hash_file('sha256', $archive);
        }
        $receiptArchives = $manifest['cpp_source_archive_sha256'] ?? [];
        ksort($archives);
        ksort($receiptArchives);
        if ($archives !== ['rapidyenc-480bd7b5896f8b3edecc721d23f1384d767ffe2f.tar.gz' => 'bd5eff1e978672af4ebaacde837270a679808bbc12dc15bd6acf7c83d94baa16']
            || $archives !== $receiptArchives || ($manifest['cpp_source_revision'] ?? null) !== '480bd7b5896f8b3edecc721d23f1384d767ffe2f') {
            throw new RuntimeException('Pinned C++ archive receipt differs.');
        }
        $libraries = [];
        foreach (['cpp', 'rust'] as $backend) {
            $source = (string) ($options[$backend.'-native'] ?? '');
            $path = $directory.'/'.$backend.'.so';
            if (! is_file($source) || ! is_readable($source) || ! copy($source, $path)) {
                throw new RuntimeException('Cannot freeze '.$backend.' library.');
            }
            $hash = hash_file('sha256', $path);
            if ($hash !== ($manifest[$backend.'_library_sha256'] ?? null)) {
                throw new RuntimeException('Frozen library differs from build receipt.');
            }
            $libraries[$backend] = ['path' => $path, 'sha256' => $hash];
        }
        if ($libraries['cpp']['sha256'] === $libraries['rust']['sha256']) {
            throw new RuntimeException('C++ and Rust libraries must be different artifacts.');
        }
        $statisticsSource = (string) ($options['statistics-bin'] ?? '');
        if (! is_file($statisticsSource) || ! is_executable($statisticsSource) || ! copy($statisticsSource, $directory.'/statistics')) {
            throw new RuntimeException('Cannot freeze the optimized statistics executable.');
        }
        chmod($directory.'/statistics', 0700);
        if (hash_file('sha256', $directory.'/statistics') !== ($manifest['statistics_binary_sha256'] ?? null)) {
            throw new RuntimeException('Statistics binary differs from its build receipt.');
        }
        $fixtures = [];
        $orders = [];
        $state = $seed;
        foreach (\Nntmux\Benchmark\StrictArticle\fixtures() as $fixture) {
            $id = $fixture['id'];
            if (! in_array($id, expectedFixtureIds(), true) || isset($fixtures[$id])) {
                throw new RuntimeException('Unknown or duplicate fixture identity.');
            }
            $common = ! str_starts_with($id, 'rare-');
            if ($fixture['family'] !== ($common ? 'common' : 'uncommon')) {
                throw new RuntimeException('Fixture family differs from the fixed protocol.');
            }
            $articlePath = $directory.'/'.$id.'.article';
            $dataPath = $directory.'/'.$id.'.data';
            if (file_put_contents($articlePath, $fixture['article']) !== strlen($fixture['article'])) {
                throw new RuntimeException('Cannot freeze article fixture.');
            }
            $metadata = array_diff_key($fixture, ['article' => true, 'data' => true]);
            $metadata['article_sha256'] = hash_file('sha256', $articlePath);
            $metadata['data_sha256'] = null;
            if (is_string($fixture['data'])) {
                if (file_put_contents($dataPath, $fixture['data']) !== strlen($fixture['data'])) {
                    throw new RuntimeException('Cannot freeze decoded fixture.');
                }
                $metadata['data_sha256'] = hash_file('sha256', $dataPath);
            }
            writeJsonNew($directory.'/'.$id.'.json', $metadata);
            $fixtures[$id] = $metadata;
            $count = $common ? $samples : $p99Samples;
            for ($pair = 0; $pair < $count; $pair++) {
                $orders[$id][] = \yencBenchmarkRandom($state) & 1 ? ['cpp', 'rust'] : ['rust', 'cpp'];
            }
        }
        if (count($fixtures) !== 42 || array_diff(expectedFixtureIds(), array_keys($fixtures)) !== []) {
            throw new RuntimeException('The complete 42-stratum fixture family is required.');
        }
        $protocol = [
            'version' => 'strict-article-experiment-v1', 'git_head' => $head,
            'source_sha256' => $sourceHashes, 'libraries' => $libraries,
            'statistics_sha256' => hash_file('sha256', $directory.'/statistics'),
            'runtime_manifest' => $manifest, 'runtime_manifest_sha256' => hash_file('sha256', $options['runtime-manifest']),
            'samples_common' => $samples, 'samples_p99' => $p99Samples, 'seed' => $seed,
            'fixture_ids' => array_keys($fixtures), 'required_fixture_ids' => expectedFixtureIds(),
            'fixtures' => $fixtures,
            'fixtures_sha256' => hash('sha256', json($fixtures)),
            'order_sha256' => hash('sha256', json($orders)), 'timeout_seconds' => $timeout,
            'warmups_per_process' => 1, 'timed_calls_per_fresh_process' => 1,
            'timed_boundary' => 'Actual service call including expected exception handling; setup, warmup, custody and correctness verification excluded.',
            'input_contracts' => ['strict' => 'argument unchanged', 'tolerant' => 'argument decoded in place', 'body' => 'memory stream; no live NNTP'],
            'invalidity_policy' => 'Retain the first invalid pair and stop that stratum; any worker timeout or exhausted scheduling budget stops all later workers. Record all unattempted pairs; frozen orders do not change.',
            'required_speedup' => 1.5, 'statistics' => 'Paired median ratios for common; separate backend nearest-rank p99 ratio for uncommon; simultaneous bootstrap intervals.',
            'resource_qualification' => 'Workflow CPU endpoints are raw diagnostics; hardened controller qualification remains pending.',
            'startup_qualification' => 'Not performed; process_elapsed_ms is retained as a diagnostic including harness setup, not an application startup benchmark.',
            // Leave at least 300s inside the workflow's 4500s outer deadline
            // for fixture setup, final identity checks and diagnostic writes.
            'worker_scheduling_budget_seconds' => 3600, 'statistics_timeout_seconds' => 600,
            'adoption_qualified' => false,
        ];
        $protocol['protocol_sha256'] = hash('sha256', json($protocol));
        writeJsonNew($directory.'/protocol.json', $protocol);
        writeJsonNew($directory.'/orders.json', $orders);
        $emit(['type' => 'protocol'] + $protocol);
        $strata = [];
        $stopReason = null;
        $deadline = hrtime(true) + 3600 * 1_000_000_000;
        foreach ($fixtures as $id => $fixture) {
            $pairs = [];
            $invalid = 0;
            $attempted = 0;
            foreach ($orders[$id] as $pair => $order) {
                if ($stopReason !== null) {
                    break;
                }
                $results = [];
                foreach ($order as $position => $backend) {
                    $command = [PHP_BINARY, '-d', 'ffi.enable=true', '-d', 'memory_limit='.ini_get('memory_limit'),
                        '-d', 'opcache.enable_cli='.ini_get('opcache.enable_cli'), '-d', 'opcache.jit='.ini_get('opcache.jit'),
                        '-d', 'pcre.jit='.ini_get('pcre.jit'), $root.'/scripts/yenc-strict-article-worker.php',
                        '--backend='.$backend, '--library='.$libraries[$backend]['path'],
                        '--fixture-json='.$directory.'/'.$id.'.json', '--article-path='.$directory.'/'.$id.'.article',
                        '--protocol-json='.$directory.'/protocol.json'];
                    if ($fixture['data_sha256'] !== null) {
                        $command[] = '--data-path='.$directory.'/'.$id.'.data';
                    }
                    $observation = scheduledChild(\yencBenchmarkRunChild(...), $command, $directory.'/'.$id.'-'.$pair.'-'.$backend, $timeout, $deadline, $stopReason);
                    if ($observation === null) {
                        break;
                    }
                    $eligible = eligibleObservation($observation, $backend, $fixture, $protocol);
                    $results[$backend] = ['eligible' => $eligible, 'milliseconds' => $observation['result']['median_ms'] ?? null];
                    $emit(['type' => 'observation', 'id' => $id, 'family' => $fixture['family'], 'pair' => $pair,
                        'backend' => $backend, 'position' => $position, 'order' => $order, 'eligible' => $eligible] + $observation);
                    if ($stopReason !== null) {
                        break;
                    }
                }
                if ($results === []) {
                    $emit(['type' => 'pair_skipped_before_launch', 'id' => $id, 'pair' => $pair, 'global_stop_reason' => $stopReason]);
                    break;
                }
                $attempted++;
                $eligible = ($results['cpp']['eligible'] ?? false) && ($results['rust']['eligible'] ?? false);
                $emit(['type' => 'pair', 'id' => $id, 'pair' => $pair, 'eligible' => $eligible,
                    'unattempted_backends' => array_values(array_diff(['cpp', 'rust'], array_keys($results))), 'global_stop_reason' => $stopReason]);
                if (! $eligible) {
                    $invalid++;
                    break;
                }
                $pairs[] = ['pair' => $pair, 'cpp_ms' => $results['cpp']['milliseconds'], 'rust_ms' => $results['rust']['milliseconds']];
            }
            $strata[] = ['id' => $id, 'family' => $fixture['family'], 'invalid_pairs' => $invalid, 'pairs' => $pairs];
            $emit(['type' => 'stratum_execution', 'id' => $id, 'attempted_pairs' => $attempted,
                'eligible_pairs' => count($pairs), 'invalid_pairs' => $invalid, 'unattempted_pairs' => count($orders[$id]) - $attempted,
                'global_stop_reason' => $stopReason]);
        }
        writeJsonNew($directory.'/statistics-input.json', ['protocol_sha256' => $protocol['protocol_sha256'], 'seed' => $seed, 'strata' => $strata]);
        $statistics = new Process([$directory.'/statistics', '--input='.$directory.'/statistics-input.json', '--output='.$directory.'/statistics.json'], $root);
        $statistics->setTimeout(600);
        $statisticsExit = runStatistics($statistics, $directory);
        if (! in_array($statisticsExit, [0, 1], true)) {
            throw new RuntimeException('Statistics failed; raw observations retained.');
        }
        $summary = readJson($directory.'/statistics.json');
        if (! statisticsMatches($summary, $strata, $protocol['protocol_sha256'], hash_file('sha256', $directory.'/statistics-input.json'), $seed)
            || hash_file('sha256', $directory.'/statistics') !== $protocol['statistics_sha256']) {
            throw new RuntimeException('Statistics result or executable identity differs.');
        }
        foreach ($sourceHashes as $path => $hash) {
            if (hash_file('sha256', $root.'/'.$path) !== $hash) {
                throw new RuntimeException('Source changed during experiment.');
            }
        }
        if (trim(gitAtRoot($root, ['rev-parse', 'HEAD'])) !== $head) {
            throw new RuntimeException('Source commit changed during experiment.');
        }
        gitAtRoot($root, ['diff', '--exit-code', 'HEAD', '--']);
        $met = ($summary['performance_thresholds_met'] ?? null) === true && $statisticsExit === 0 && $stopReason === null;
        $status = ['type' => 'run_summary', 'status' => $met ? 'performance_thresholds_met' : 'not_qualified',
            'source_unchanged' => true, 'declared_strata' => 42, 'performance_thresholds_met' => $met,
            'global_stop_reason' => $stopReason,
            'adoption_qualified' => false, 'reason' => 'Default-off experiment; independent review and hardened resource qualification remain required.'];
        writeJsonNew($directory.'/status.json', $status);
        $emit($status);

        return $met ? 0 : 1;
    } catch (Throwable $exception) {
        $failure = ['type' => 'run_failure', 'status' => 'invalid', 'adoption_qualified' => false,
            'error_class' => $exception::class, 'error' => $exception->getMessage()];
        if ($owned) {
            if (! file_exists($directory.'/status.json')) {
                writeJsonNew($directory.'/status.json', $failure);
            }
        }
        $emit($failure);

        return 2;
    } finally {
        if (is_resource($log)) {
            fclose($log);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(run(getopt('', ['cpp-native:', 'rust-native:', 'runtime-manifest:', 'statistics-bin:', 'output-dir:',
        'samples:', 'p99-samples:', 'seed:', 'timeout:', 'size:', 'php-baseline', 'baseline:'])));
}
