<?php

declare(strict_types=1);

/**
 * Host-only benchmark. Each scenario runs in a fresh process; fixture creation is untimed.
 * php scripts/benchmark-yenc.php [--native=/absolute/librapidyenc.so] [--baseline=/snapshot/directory]
 * A baseline directory contains unmodified YencService.php and NNTPService.php snapshots.
 * Paired native mode: --cpp-native=... --rust-native=... [--order-seed=20261013]
 * PHP versus Rust: --php-baseline --rust-native=/absolute/libnzbyenc.so (no C++ library needed).
 * [--output-dir=/new/directory] [--timeout=30] [--min-improvement-percent=5] [--max-regression-percent=5].
 * Paired mode retains fixtures, all raw child outputs, hashes and per-stratum decisions.
 * It compares only yEnc decoding through the same PHP service, not archive metadata or production capacity.
 * Optional --size=524288 restricts the run. Output is JSON Lines, with median/range and Linux peak RSS.
 */
use App\Services\Yenc\DecoderFactory;
use App\Services\YencService;
use Tests\Fixtures\YencArticles;
use Tests\Support\YencNntpHarness;

if (defined('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY')) {
    return;
}

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['native:', 'baseline:', 'size:', 'samples:', 'worker:', 'data-path:', 'article-path:', 'path:', 'paired-worker', 'php-baseline', 'cpp-native:', 'rust-native:', 'order-seed:', 'timeout:', 'output-dir:', 'runtime-manifest:', 'min-improvement-percent:', 'max-regression-percent:']);
$samples = (int) ($options['samples'] ?? 11);
if ($samples < (isset($options['paired-worker']) ? 1 : 3)) {
    throw new InvalidArgumentException('Use at least three samples.');
}

if (! isset($options['worker']) && (isset($options['php-baseline']) || isset($options['cpp-native']) || isset($options['rust-native']))) {
    exit(runPairedYencBenchmark($options, $samples));
}

if (isset($options['worker'])) {
    $mode = $options['worker'];
    if ($mode === 'baseline') {
        require $options['baseline'].'/YencService.php';
        require $options['baseline'].'/NNTPService.php';
        $service = new YencService;
    } else {
        $service = new YencService((new DecoderFactory)->make($mode, (string) ($options['native'] ?? '')));
    }
    $data = file_get_contents($options['data-path']);
    $article = file_get_contents($options['article-path']);
    $path = $options['path'];
    $times = [];
    $observations = [];
    $stream = null;
    $harness = null;
    if ($path === 'body') {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, preg_replace('/^\./m', '..', $article)."\r\n.\r\n");
        $harness = new YencNntpHarness($stream, $service);
    }
    try {
        for ($sample = -1; $sample < $samples; $sample++) {
            $input = $article;
            if ($stream !== null) {
                rewind($stream);
            }
            $cpuBefore = getrusage();
            $start = hrtime(true);
            $result = match ($path) {
                'strict' => $service->decode($input),
                'body' => $harness->fetch(),
                default => $service->decodeIgnore($input),
            };
            $elapsed = (hrtime(true) - $start) / 1e6;
            $cpuAfter = getrusage();
            $observations[] = [
                'sample' => $sample, 'warmup' => $sample < 0, 'elapsed_ms' => $elapsed,
                'process_cpu_before' => $cpuBefore, 'process_cpu_after' => $cpuAfter,
                'correct' => $result === $data, 'decoded_bytes' => is_string($result) ? strlen($result) : null,
                'decoded_sha256' => is_string($result) ? hash('sha256', $result) : null,
            ];
            if ($result !== $data) {
                echo json_encode(['status' => 'incorrect', 'pcre_error' => preg_last_error_msg(), 'observations' => $observations], JSON_THROW_ON_ERROR).PHP_EOL;
                exit(0);
            }
            if ($sample >= 0) {
                $times[] = $elapsed;
            }
            unset($input, $result);
        }
        sort($times);
        $rss = null;
        if (is_readable('/proc/self/status')) {
            preg_match('/^VmHWM:\s+(\d+) kB/m', file_get_contents('/proc/self/status'), $match);
            $rss = isset($match[1]) ? (int) $match[1] * 1024 : null;
        }
        echo json_encode([
            'observations' => $observations,
            'decoder_version' => $mode === 'baseline' ? null : $service->decoderVersion(),
            'library_sha256' => $mode === 'native' ? hash_file('sha256', $options['native']) : null,
            'status' => 'ok', 'median_ms' => $times[intdiv($samples, 2)],
            'min_ms' => $times[0], 'max_ms' => $times[$samples - 1],
            'peak_rss_bytes' => $rss, 'php_peak_bytes' => memory_get_peak_usage(true),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        if ($stream !== null) {
            fclose($stream);
        }
    }
    exit(0);
}

$sizes = isset($options['size']) ? [(int) $options['size']] : [4096, 524288, 1048576, 8388608];
if (min($sizes) < 1 || max($sizes) > 8388608) {
    throw new InvalidArgumentException('Benchmark sizes must be between 1 and 8388608 bytes.');
}
$modes = isset($options['baseline']) ? ['baseline', 'php'] : ['php'];
if (isset($options['native'])) {
    $modes[] = 'native';
}
$directory = sys_get_temp_dir().'/nntmux-yenc-bench-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$dataPath = $directory.'/data';
$articlePath = $directory.'/article';
echo json_encode([
    'php' => PHP_VERSION, 'os' => php_uname(), 'samples' => $samples, 'warmups' => 1,
    'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
    'memory_limit' => ini_get('memory_limit'), 'ffi' => ini_get('ffi.enable'),
    'baseline_sha256' => isset($options['baseline']) ? hash_file('sha256', $options['baseline'].'/YencService.php') : null,
], JSON_THROW_ON_ERROR).PHP_EOL;
try {
    foreach ($sizes as $size) {
        foreach (['ordinary', 'heavy'] as $density) {
            $seed = $density === 'heavy' ? "\xd6\xe0\xe3\x13" : YencArticles::bytes();
            $data = substr(str_repeat($seed, (int) ceil($size / strlen($seed))), 0, $size);
            file_put_contents($dataPath, $data);
            file_put_contents($articlePath, YencArticles::article($data));
            unset($data);
            foreach ($modes as $mode) {
                foreach (['tolerant', 'strict', 'body'] as $path) {
                    $command = [PHP_BINARY, __FILE__, '--worker='.$mode, '--path='.$path,
                        '--samples='.$samples, '--data-path='.$dataPath, '--article-path='.$articlePath];
                    foreach (['native', 'baseline'] as $option) {
                        if (isset($options[$option])) {
                            $command[] = '--'.$option.'='.$options[$option];
                        }
                    }
                    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start benchmark child.');
                    }
                    fclose($pipes[0]);
                    $output = stream_get_contents($pipes[1]);
                    $error = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $code = proc_close($process);
                    if ($code !== 0 || $error !== '') {
                        throw new RuntimeException("Benchmark child failed ({$code}): {$error}");
                    }
                    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                    if ($mode !== 'baseline' && $result['status'] !== 'ok') {
                        throw new RuntimeException("Incorrect {$mode} decoding for {$size} bytes ({$density}, {$path}).");
                    }
                    echo json_encode(['size' => $size, 'density' => $density, 'backend' => $mode, 'path' => $path] + $result, JSON_THROW_ON_ERROR).PHP_EOL;
                }
            }
        }
    }
} finally {
    foreach ([$dataPath, $articlePath] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}

/** @param list<float> $values */
function yencBenchmarkMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
}

/** Deterministic xorshift32, independent of PHP's global random-number state. */
function yencBenchmarkRandom(int &$state): int
{
    $state = ($state ^ ($state << 13)) & 0xFFFFFFFF;
    $state = ($state ^ ($state >> 17)) & 0xFFFFFFFF;
    $state = ($state ^ ($state << 5)) & 0xFFFFFFFF;

    return $state;
}

/**
 * @param  list<float>  $ratios  Correct matched observations only, Rust time / baseline time.
 * @return array<string, mixed>
 */
function yencBenchmarkDecision(array $ratios, int $invalidPairs, float $improvementPercent, float $regressionPercent, int $seed, int $familySize = 1, string $baseline = 'cpp'): array
{
    if ($invalidPairs > 0 || count($ratios) < 3) {
        return ['decision' => 'invalid', 'eligible_pairs' => count($ratios), 'invalid_pairs' => $invalidPairs,
            'invalid_reason' => $invalidPairs > 0 ? 'failed_or_incorrect_pair_retained' : 'insufficient_matching_pairs'];
    }
    $state = $seed ?: 1;
    $medians = [];
    for ($replicate = 0; $replicate < 10000; $replicate++) {
        $resample = [];
        foreach ($ratios as $_) {
            $resample[] = $ratios[yencBenchmarkRandom($state) % count($ratios)];
        }
        $medians[] = yencBenchmarkMedian($resample);
    }
    sort($medians, SORT_NUMERIC);
    $tail = 0.025 / max(1, $familySize);
    $lower = $medians[(int) floor(count($medians) * $tail)];
    $upper = $medians[(int) ceil(count($medians) * (1 - $tail)) - 1];
    $decision = $lower > 1 + $regressionPercent / 100 ? 'regression'
        : ($upper < 1 - $improvementPercent / 100 ? 'useful_improvement' : 'inconclusive');

    return [
        'decision' => $decision, 'eligible_pairs' => count($ratios), 'invalid_pairs' => 0,
        'median_rust_over_'.$baseline => yencBenchmarkMedian($ratios),
        'bootstrap_95_percent_interval' => [$lower, $upper],
        'bootstrap_replicates' => 10000,
        'confidence_family_size' => $familySize,
        'confidence_scope' => 'Bonferroni simultaneous 95 percent intervals across the predeclared stratum family',
    ];
}

/**
 * @param  array<string, mixed>  $options
 * @return array{baseline: string, library_options: array<string, string>}
 */
function yencBenchmarkBackends(array $options): array
{
    if (isset($options['php-baseline'])) {
        if (isset($options['cpp-native'])) {
            throw new InvalidArgumentException('Choose either --php-baseline or --cpp-native.');
        }

        return ['baseline' => 'php', 'library_options' => ['rust' => 'rust-native']];
    }

    return ['baseline' => 'cpp', 'library_options' => ['cpp' => 'cpp-native', 'rust' => 'rust-native']];
}

/**
 * @param  list<string>  $command
 * @return array<string, mixed>
 */
function yencBenchmarkRunChild(array $command, string $prefix, float $timeout): array
{
    $stdoutPath = $prefix.'.stdout';
    $stderrPath = $prefix.'.stderr';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']], $pipes);
    if (! is_resource($process)) {
        return ['status' => 'spawn_failed', 'stdout_path' => $stdoutPath, 'stderr_path' => $stderrPath];
    }
    fclose($pipes[0]);
    $started = hrtime(true);
    $timedOut = false;
    do {
        $state = proc_get_status($process);
        if (! $state['running']) {
            break;
        }
        if ((hrtime(true) - $started) / 1e9 > $timeout) {
            $timedOut = true;
            proc_terminate($process);
            usleep(100000);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(10000);
    } while (true);
    $closeCode = proc_close($process);
    $exitCode = $state['exitcode'] >= 0 ? $state['exitcode'] : $closeCode;
    $stdout = file_get_contents($stdoutPath, length: 262144);
    $stderr = file_get_contents($stderrPath, length: 262144);
    $result = null;
    $parseError = null;
    try {
        $result = json_decode($stdout ?: '', true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $parseError = $exception->getMessage();
    }
    $validResult = is_array($result) && ($result['status'] ?? null) === 'ok'
        && is_numeric($result['median_ms'] ?? null) && $result['median_ms'] > 0;

    return [
        'status' => $timedOut ? 'timeout' : ($exitCode !== 0 ? 'child_failed'
            : ($stderr !== '' ? 'child_stderr' : ($validResult ? 'ok' : 'invalid_child_result'))),
        'exit_code' => $exitCode, 'timed_out' => $timedOut,
        'process_elapsed_ms' => (hrtime(true) - $started) / 1e6,
        'stdout_path' => $stdoutPath, 'stderr_path' => $stderrPath,
        'stdout_truncated_for_json' => filesize($stdoutPath) > 262144,
        'parse_error' => $parseError, 'result' => $result,
    ];
}

/** @return array<string, string|null> */
function yencBenchmarkSourceHashes(string $root): array
{
    $paths = [
        'scripts/benchmark-yenc.php', 'scripts/benchmark-cpu.py', 'scripts/build-rapidyenc.sh', 'composer.lock',
        'app/Services/YencService.php', 'app/Services/NNTP/NNTPService.php',
        'tests/Fixtures/YencArticles.php', 'tests/Support/YencNntpHarness.php',
        'docker/app/nzb-yenc-ffi/Cargo.toml', 'docker/app/nzb-yenc-ffi/Cargo.lock',
    ];
    foreach (['app/Services/Yenc', 'docker/app/nzb-yenc-ffi/src'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($paths);
    $hashes = [];
    foreach ($paths as $path) {
        $hashes[$path] = is_file($root.'/'.$path) ? hash_file('sha256', $root.'/'.$path) : null;
    }

    return $hashes;
}

/**
 * @param  array<string, mixed>  $options
 * @return int 1: demonstrated regression; 2: invalid run; 0: improvement or inconclusive.
 */
function runPairedYencBenchmark(array $options, int $samples): int
{
    $backends = yencBenchmarkBackends($options);
    $baseline = $backends['baseline'];
    foreach ($backends['library_options'] as $name) {
        if (! isset($options[$name]) || ! is_readable($options[$name]) || ! is_file($options[$name])) {
            throw new InvalidArgumentException('Paired mode requires a readable --'.$name.' library.');
        }
    }
    $seed = (int) ($options['order-seed'] ?? random_int(1, 2147483647));
    $timeout = (float) ($options['timeout'] ?? 30);
    $improvement = (float) ($options['min-improvement-percent'] ?? 5);
    $regression = (float) ($options['max-regression-percent'] ?? 5);
    if ($seed < 1 || $seed > 2147483647 || $timeout <= 0 || $timeout > 300
        || $improvement < 0 || $improvement >= 100 || $regression < 0 || $regression > 100) {
        throw new InvalidArgumentException('Invalid paired benchmark seed, timeout or effect criterion.');
    }
    $sizes = isset($options['size']) ? [(int) $options['size']] : [4096, 524288, 1048576, 8388608];
    if (min($sizes) < 1 || max($sizes) > 8388608) {
        throw new InvalidArgumentException('Benchmark sizes must be between 1 and 8388608 bytes.');
    }
    $directory = (string) ($options['output-dir'] ?? sys_get_temp_dir().'/nntmux-yenc-paired-'.bin2hex(random_bytes(8)));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Paired output directory must be a new writable directory.');
    }
    $directory = realpath($directory);
    $log = fopen($directory.'/observations.jsonl', 'x');
    $emit = static function (array $record) use ($log): void {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
        fwrite($log, $line);
        fflush($log);
        echo $line;
    };
    $root = dirname(__DIR__);
    try {
        $libraries = [];
        foreach ($backends['library_options'] as $backend => $option) {
            $frozenPath = $directory.'/'.$backend.'.so';
            if (! copy($options[$option], $frozenPath)) {
                throw new RuntimeException('Unable to freeze native library '.$backend);
            }
            $libraries[$backend] = ['path' => $frozenPath, 'sha256' => hash_file('sha256', $frozenPath)];
        }
        if ($baseline === 'cpp' && $libraries['cpp']['sha256'] === $libraries['rust']['sha256']) {
            throw new InvalidArgumentException('The named C++ and Rust libraries must contain different bytes.');
        }
        $runtimeManifest = null;
        if (isset($options['runtime-manifest'])) {
            $runtimeManifest = [
                'sha256' => hash_file('sha256', $options['runtime-manifest']),
                'contents' => json_decode(file_get_contents($options['runtime-manifest']), true, flags: JSON_THROW_ON_ERROR),
            ];
            copy($options['runtime-manifest'], $directory.'/runtime-manifest.json');
        }
        $sourceHashes = yencBenchmarkSourceHashes($root);
        if ($runtimeManifest !== null) {
            $manifest = $runtimeManifest['contents'];
            foreach (array_keys($libraries) as $backend) {
                if (($manifest[$backend.'_library_sha256'] ?? null) !== $libraries[$backend]['sha256']) {
                    throw new RuntimeException('Build manifest does not match frozen '.$backend.' library.');
                }
            }
            foreach (($manifest['rust_source_sha256'] ?? []) as $path => $digest) {
                if (($sourceHashes[$path] ?? null) !== $digest) {
                    throw new RuntimeException('Rust source changed since the recorded build: '.$path);
                }
            }
        }
        $runtime = [
            'php_version' => PHP_VERSION, 'php_binary_sha256' => hash_file('sha256', PHP_BINARY),
            'os' => php_uname(), 'extensions' => get_loaded_extensions(),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'memory_limit' => ini_get('memory_limit'), 'ffi' => ini_get('ffi.enable'),
            'pcre_jit' => ini_get('pcre.jit'), 'manifest' => $runtimeManifest,
        ];
        $protocol = [
            'type' => 'protocol', 'version' => $baseline === 'php' ? 'paired-yenc-php-v1' : 'paired-yenc-v1',
            'baseline' => $baseline, 'candidate' => 'rust',
            'scope' => 'identical yEnc fixtures through YencService; strict CRC, tolerant decoding and memory-backed NNTP BODY',
            'claim_limit' => 'this fixed decoder fixture matrix only; no archive metadata or production capacity claim',
            'output_directory' => $directory, 'samples' => $samples, 'warmups_per_worker' => 1,
            'timed_samples_per_worker' => 1, 'order_seed' => $seed, 'timeout_seconds_per_worker' => $timeout,
            'sizes' => $sizes, 'densities' => ['ordinary', 'heavy'], 'paths' => ['tolerant', 'strict', 'body'],
            'min_improvement_percent' => $improvement, 'max_regression_percent' => $regression,
            'decision_rule' => 'paired median Rust/'.$baseline.' ratios; 10000-resample simultaneous 95% bootstrap intervals across all predeclared strata; regression only if entire interval exceeds regression margin; useful improvement only if entire interval beats useful-effect margin; otherwise inconclusive',
            'timed_boundary' => 'service decode/fetch call only; initialization, fixture creation, rewind and independent byte/hash verification outside timing',
            'resource_counter_scope' => 'getrusage around each service call; workflow OS/cgroup snapshots bracket the whole comparison including worker setup and verification',
            'source_sha256' => $sourceHashes, 'libraries' => $libraries, 'runtime' => $runtime,
            'library_source_parity' => 'library bytes frozen; source-build correspondence must be established by the build manifest',
        ];
        $protocol['protocol_sha256'] = hash('sha256', json_encode($protocol, JSON_THROW_ON_ERROR));
        $emit($protocol);
        $state = $seed;
        $summaries = [];
        foreach ($sizes as $size) {
            foreach (['ordinary', 'heavy'] as $density) {
                $fixtureSeed = $density === 'heavy' ? "\xd6\xe0\xe3\x13" : YencArticles::bytes();
                $data = substr(str_repeat($fixtureSeed, (int) ceil($size / strlen($fixtureSeed))), 0, $size);
                $dataPath = $directory.'/'.$size.'-'.$density.'.data';
                $articlePath = $directory.'/'.$size.'-'.$density.'.article';
                file_put_contents($dataPath, $data);
                file_put_contents($articlePath, YencArticles::article($data));
                $fixture = ['decoded_sha256' => hash_file('sha256', $dataPath), 'article_sha256' => hash_file('sha256', $articlePath),
                    'decoded_bytes' => $size, 'article_bytes' => filesize($articlePath)];
                $emit(['type' => 'fixture', 'size' => $size, 'density' => $density, 'data_path' => $dataPath, 'article_path' => $articlePath] + $fixture);
                unset($data);
                foreach (['tolerant', 'strict', 'body'] as $path) {
                    $ratios = [];
                    $invalidPairs = 0;
                    for ($pair = 0; $pair < $samples; $pair++) {
                        $order = yencBenchmarkRandom($state) & 1 ? [$baseline, 'rust'] : ['rust', $baseline];
                        $pairResults = [];
                        foreach ($order as $position => $backend) {
                            $command = [PHP_BINARY, '-d', 'ffi.enable='.ini_get('ffi.enable'), '-d', 'memory_limit='.ini_get('memory_limit'),
                                '-d', 'opcache.enable_cli='.ini_get('opcache.enable_cli'), '-d', 'opcache.jit='.ini_get('opcache.jit'),
                                '-d', 'pcre.jit='.ini_get('pcre.jit'), __FILE__, '--worker='.($backend === 'php' ? 'php' : 'native'), '--paired-worker', '--samples=1',
                                '--path='.$path, '--data-path='.$dataPath, '--article-path='.$articlePath];
                            if ($backend !== 'php') {
                                $command[] = '--native='.$libraries[$backend]['path'];
                            }
                            $prefix = $directory.'/'.$size.'-'.$density.'-'.$path.'-'.$pair.'-'.$backend;
                            $observation = yencBenchmarkRunChild($command, $prefix, $timeout);
                            $result = $observation['result'] ?? null;
                            $raw = is_array($result) && is_array($result['observations'] ?? null) ? $result['observations'] : [];
                            $correct = count($raw) === 2 && array_all($raw, static fn (mixed $sample): bool => is_array($sample) && ($sample['correct'] ?? false)
                                && ($sample['decoded_sha256'] ?? null) === $fixture['decoded_sha256'] && ($sample['decoded_bytes'] ?? null) === $size);
                            $eligible = $observation['status'] === 'ok' && $correct
                                && ($result['library_sha256'] ?? null) === ($libraries[$backend]['sha256'] ?? null);
                            $pairResults[$backend] = ['eligible' => $eligible, 'median_ms' => $result['median_ms'] ?? null];
                            $emit(['type' => 'observation', 'size' => $size, 'density' => $density, 'path' => $path,
                                'pair' => $pair, 'position' => $position, 'backend' => $backend, 'order' => $order,
                                'fixture' => $fixture, 'eligible' => $eligible] + $observation);
                        }
                        $eligible = $pairResults[$baseline]['eligible'] && $pairResults['rust']['eligible'];
                        $ratio = $eligible ? $pairResults['rust']['median_ms'] / $pairResults[$baseline]['median_ms'] : null;
                        if ($eligible) {
                            $ratios[] = $ratio;
                        } else {
                            $invalidPairs++;
                        }
                        $emit(['type' => 'pair', 'size' => $size, 'density' => $density, 'path' => $path,
                            'pair' => $pair, 'eligible' => $eligible, 'rust_over_'.$baseline => $ratio]);
                    }
                    $decisionSeed = ($seed ^ crc32($size.'-'.$density.'-'.$path)) & 0xFFFFFFFF;
                    $summary = ['type' => 'stratum_summary', 'size' => $size, 'density' => $density, 'path' => $path]
                        + yencBenchmarkDecision($ratios, $invalidPairs, $improvement, $regression, $decisionSeed, count($sizes) * 6, $baseline);
                    $summaries[] = $summary;
                    $emit($summary);
                }
            }
        }
        $invalid = array_any($summaries, static fn (array $summary): bool => $summary['decision'] === 'invalid');
        $regressed = array_any($summaries, static fn (array $summary): bool => $summary['decision'] === 'regression');
        $allImproved = array_all($summaries, static fn (array $summary): bool => $summary['decision'] === 'useful_improvement');
        $emit(['type' => 'run_summary', 'status' => $invalid ? 'invalid' : ($regressed ? 'regression' : ($allImproved ? 'useful_improvement' : 'inconclusive')),
            'rust_speed_advantage_demonstrated_for_every_stratum' => ! $invalid && $allImproved,
            'strata' => count($summaries), 'claim_limit' => $protocol['claim_limit']]);

        return $invalid ? 2 : ($regressed ? 1 : 0);
    } catch (Throwable $exception) {
        $emit(['type' => 'run_failure', 'status' => 'invalid', 'error_class' => $exception::class, 'error' => $exception->getMessage()]);

        return 2;
    } finally {
        fclose($log);
    }
}
