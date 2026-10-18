<?php

declare(strict_types=1);

/**
 * Paired PHP versus Rust comparison for HeaderFilenameIdentity::matches over a synthetic corpus.
 *
 *   php -d ffi.enable=true scripts/benchmark-filename-identity.php --native=/absolute/libnzbyenc.so
 *     --output-dir=/new/directory [--pairs=400] [--repeats=50] [--batches=3000] [--seed=20261018]
 *     [--source-dir=ROOT] [--runtime-manifest=/absolute/runtime.json]
 *
 * Protocol (frozen by this file, see the printed `protocol` record): per stratum, every pair is
 * first checked for identical verdicts (Rust declines fall back to PHP exactly as production
 * would); then each pair is timed `repeats` times, one call per timer reading, with a seeded
 * random call order inside every observation so neither side is systematically first. The Rust
 * side is the guarded NativeHeaderFilenameIdentity wrapper plus the PHP fallback, so FFI, string
 * marshalling, result mapping and declines are all inside the measurement. A release-batch
 * stratum times 16 consecutive calls per reading, the shape SpotCheckVerifier produces. The
 * verdict thresholds follow .ai/rules/rust-performance.md: common median and uncommon p99 must
 * each reach 1.5x. A missed threshold is a valid result and exits 0; invalid runs exit 2.
 */

use App\Services\Releases\Verification\HeaderFilenameIdentity;
use App\Services\Releases\Verification\NativeHeaderFilenameIdentity;
use Tests\Support\HeaderFilenameIdentityCorpus;

const FILENAME_IDENTITY_BATCH_SIZE = 16;
const FILENAME_IDENTITY_THRESHOLD = 1.5;

if (! defined('NNTMUX_FILENAME_IDENTITY_FUNCTIONS_ONLY')) {
    try {
        exit(filenameIdentityBenchmarkMain());
    } catch (Throwable $exception) {
        fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
        exit(2);
    }
}

function filenameIdentityBenchmarkMain(): int
{
    $options = getopt('', ['native:', 'output-dir:', 'pairs:', 'repeats:', 'batches:', 'seed:', 'source-dir:', 'runtime-manifest:']);
    $root = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Source directory is missing.');
    require_once $root.'/vendor/autoload.php';
    $pairs = filter_var($options['pairs'] ?? 400, FILTER_VALIDATE_INT);
    $repeats = filter_var($options['repeats'] ?? 50, FILTER_VALIDATE_INT);
    $batches = filter_var($options['batches'] ?? 3000, FILTER_VALIDATE_INT);
    $seed = filter_var($options['seed'] ?? 20261018, FILTER_VALIDATE_INT);
    if ($pairs === false || $pairs < 50 || $pairs > 5000 || $repeats === false || $repeats < 5 || $repeats > 500
        || $batches === false || $batches < 200 || $batches > 100000 || $seed === false || $seed < 1) {
        throw new InvalidArgumentException('Invalid protocol parameters.');
    }
    $directory = (string) ($options['output-dir'] ?? '');
    if ($directory === '' || file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new.');
    }
    $native = new NativeHeaderFilenameIdentity((string) ($options['native'] ?? ''));
    $environment = filenameIdentityEnvironment($root, (string) ($options['native'] ?? ''), (string) ($options['runtime-manifest'] ?? ''));
    if (! $native->available()) {
        $report = ['status' => 'invalid', 'reason' => 'Native library is unavailable or failed its known-answer probe '
            .'(requires a Linux CLI with FFI).', 'environment' => $environment];
        filenameIdentityWrite($directory, $report, '', []);
        fwrite(STDERR, json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);

        return 2;
    }
    $php = static fn (string $subject, string $actual): bool => HeaderFilenameIdentity::matches($subject, $actual);
    $rust = static fn (string $subject, string $actual): bool => $native->matches($subject, $actual) ?? HeaderFilenameIdentity::matches($subject, $actual);
    $declines = static fn (string $subject, string $actual): bool => $native->matches($subject, $actual) === null;
    $report = filenameIdentityRun($php, $rust, $declines, $pairs, $repeats, $batches, $seed);
    $report['environment'] = $environment;
    $markdown = filenameIdentityMarkdown($report);
    filenameIdentityWrite($directory, $report, $markdown, $report['raw'] ?? []);
    echo $markdown;

    return $report['status'] === 'invalid' ? 2 : 0;
}

/**
 * @param  callable(string,string):bool  $php
 * @param  callable(string,string):bool  $rust
 * @param  callable(string,string):bool  $declines
 * @return array<string,mixed>
 */
function filenameIdentityRun(callable $php, callable $rust, callable $declines, int $pairs, int $repeats, int $batches, int $seed): array
{
    $report = ['protocol' => [
        'name' => 'filename-identity-v1', 'seed' => $seed, 'pairs_per_stratum' => $pairs, 'repeats_per_pair' => $repeats,
        'batches' => $batches, 'batch_size' => FILENAME_IDENTITY_BATCH_SIZE, 'threshold' => FILENAME_IDENTITY_THRESHOLD,
        'timing' => 'hrtime(true) around each call; random PHP/Rust order per observation; pairs shuffled per repeat; '
            .'3 warm-up passes; GC disabled while timing',
        'rust_path' => 'NativeHeaderFilenameIdentity::matches() including FFI call, string marshalling and result mapping, '
            .'falling back to the PHP rule when the port declines',
        'decision' => 'common strata: median speedup; uncommon strata: p99 speedup; qualified only when every point estimate '
            .'and its lower 95% interval bound reach the threshold, in the per-call strata and the release batch',
        'interval' => 'normal-approximation order-statistic ranks; repeats of one pair are correlated, so intervals are descriptive',
    ], 'strata' => [], 'raw' => [], 'status' => 'qualified', 'invalid' => []];
    $timer = [];
    for ($i = 0; $i < 20000; $i++) {
        $start = hrtime(true);
        $timer[] = hrtime(true) - $start;
    }
    sort($timer);
    $report['timer_overhead_ns_median'] = $timer[intdiv(count($timer), 2)];
    $pools = [];
    foreach (HeaderFilenameIdentityCorpus::strata() as $name => $stratum) {
        $set = HeaderFilenameIdentityCorpus::stratum($name, $pairs, $seed);
        $pools[$name] = $set;
        $mismatch = 0;
        $declined = 0;
        $accepted = 0;
        $bytes = 0;
        foreach ($set as [$subject, $actual]) {
            $expected = $php($subject, $actual);
            $mismatch += (int) ($expected !== $rust($subject, $actual));
            $declined += (int) $declines($subject, $actual);
            $accepted += (int) $expected;
            $bytes += strlen($subject) + strlen($actual);
        }
        $row = ['category' => $stratum['category'], 'description' => $stratum['description'], 'pairs' => count($set),
            'accepted' => $accepted, 'rejected' => count($set) - $accepted, 'declined_to_php' => $declined,
            'mean_input_bytes' => intdiv($bytes, max(1, count($set))), 'parity_mismatches' => $mismatch];
        if ($mismatch > 0) {
            $report['invalid'][] = $name.': '.$mismatch.' verdict mismatches';
            $report['strata'][$name] = $row;

            continue;
        }
        $raw = filenameIdentityMeasure($php, $rust, $set, $repeats, $seed);
        $report['raw'][$name] = $raw;
        $row += filenameIdentityStatistics($raw['php'], $raw['rust'], $stratum['category'] === 'common' ? 0.5 : 0.99);
        $report['strata'][$name] = $row;
    }
    $mixed = [...array_slice($pools['readable-quoted'], 0, (int) ($pairs * 0.5)), ...array_slice($pools['obfuscated-quoted'], 0, (int) ($pairs * 0.4)),
        ...array_slice($pools['unquoted'], 0, (int) ($pairs * 0.1))];
    $raw = filenameIdentityBatch($php, $rust, $mixed, $batches, $seed);
    $report['raw']['release-batch-16'] = $raw;
    $report['batch'] = ['description' => '16 consecutive calls (50% readable, 40% obfuscated, 10% unquoted), the per-release shape of SpotCheckVerifier',
        'batches' => $batches] + filenameIdentityStatistics($raw['php'], $raw['rust'], 0.5);
    $report['batch']['p99'] = filenameIdentityQuantileBlock($raw['php'], $raw['rust'], 0.99);
    foreach ($report['strata'] as $name => $row) {
        if (isset($row['speedup_lower']) && ! ($row['qualifies'] ?? false)) {
            $report['status'] = 'not_qualified';
        }
    }
    if (! ($report['batch']['qualifies'] ?? false)) {
        $report['status'] = 'not_qualified';
    }
    if ($report['invalid'] !== []) {
        $report['status'] = 'invalid';
    }

    return $report;
}

/**
 * @param  callable(string,string):bool  $php
 * @param  callable(string,string):bool  $rust
 * @param  list<array{string,string}>  $pairs
 * @return array{php:list<int>,rust:list<int>,native_first:int}
 */
function filenameIdentityMeasure(callable $php, callable $rust, array $pairs, int $repeats, int $seed): array
{
    for ($warm = 0; $warm < 3; $warm++) {
        foreach ($pairs as [$subject, $actual]) {
            $php($subject, $actual);
            $rust($subject, $actual);
        }
    }
    $state = $seed;
    $random = static function (int $limit) use (&$state): int {
        $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;

        return (int) (($state >> 8) % $limit);
    };
    $order = array_keys($pairs);
    $phpTimes = [];
    $rustTimes = [];
    $nativeFirst = 0;
    gc_collect_cycles();
    gc_disable();
    try {
        for ($round = 0; $round < $repeats; $round++) {
            for ($i = count($order) - 1; $i > 0; $i--) {
                $j = $random($i + 1);
                [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            }
            foreach ($order as $index) {
                [$subject, $actual] = $pairs[$index];
                if ($random(2) === 0) {
                    $start = hrtime(true);
                    $rust($subject, $actual);
                    $rustTimes[] = hrtime(true) - $start;
                    $start = hrtime(true);
                    $php($subject, $actual);
                    $phpTimes[] = hrtime(true) - $start;
                    $nativeFirst++;
                } else {
                    $start = hrtime(true);
                    $php($subject, $actual);
                    $phpTimes[] = hrtime(true) - $start;
                    $start = hrtime(true);
                    $rust($subject, $actual);
                    $rustTimes[] = hrtime(true) - $start;
                }
            }
        }
    } finally {
        gc_enable();
    }

    return ['php' => $phpTimes, 'rust' => $rustTimes, 'native_first' => $nativeFirst];
}

/**
 * @param  callable(string,string):bool  $php
 * @param  callable(string,string):bool  $rust
 * @param  list<array{string,string}>  $pool
 * @return array{php:list<int>,rust:list<int>,native_first:int}
 */
function filenameIdentityBatch(callable $php, callable $rust, array $pool, int $batches, int $seed): array
{
    $state = $seed ^ 0x5BD1E995;
    $random = static function (int $limit) use (&$state): int {
        $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;

        return (int) (($state >> 8) % $limit);
    };
    $sets = [];
    for ($b = 0; $b < $batches; $b++) {
        $set = [];
        for ($i = 0; $i < FILENAME_IDENTITY_BATCH_SIZE; $i++) {
            $set[] = $pool[$random(count($pool))];
        }
        $sets[] = $set;
    }
    foreach (array_slice($sets, 0, 200) as $set) {
        foreach ($set as [$subject, $actual]) {
            $php($subject, $actual);
            $rust($subject, $actual);
        }
    }
    $runner = static function (callable $call, array $set): int {
        $start = hrtime(true);
        foreach ($set as [$subject, $actual]) {
            $call($subject, $actual);
        }

        return hrtime(true) - $start;
    };
    $phpTimes = [];
    $rustTimes = [];
    $nativeFirst = 0;
    gc_collect_cycles();
    gc_disable();
    try {
        foreach ($sets as $set) {
            if ($random(2) === 0) {
                $rustTimes[] = $runner($rust, $set);
                $phpTimes[] = $runner($php, $set);
                $nativeFirst++;
            } else {
                $phpTimes[] = $runner($php, $set);
                $rustTimes[] = $runner($rust, $set);
            }
        }
    } finally {
        gc_enable();
    }

    return ['php' => $phpTimes, 'rust' => $rustTimes, 'native_first' => $nativeFirst];
}

/**
 * @param  list<int|float>  $values
 * @return array{estimate:float,low:float,high:float,samples:int}
 */
function filenameIdentityQuantile(array $values, float $quantile): array
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        throw new InvalidArgumentException('No observations.');
    }
    $rank = max(1, (int) ceil($quantile * $n));
    $half = 1.96 * sqrt($n * $quantile * (1 - $quantile));

    return ['estimate' => (float) $values[$rank - 1],
        'low' => (float) $values[max(1, (int) floor($rank - $half)) - 1],
        'high' => (float) $values[min($n, (int) ceil($rank + $half)) - 1], 'samples' => $n];
}

/**
 * @param  list<int|float>  $php
 * @param  list<int|float>  $rust
 * @return array<string,mixed>
 */
function filenameIdentityQuantileBlock(array $php, array $rust, float $quantile): array
{
    $baseline = filenameIdentityQuantile($php, $quantile);
    $candidate = filenameIdentityQuantile($rust, $quantile);
    $speedup = $candidate['estimate'] > 0 ? $baseline['estimate'] / $candidate['estimate'] : INF;
    $lower = $candidate['high'] > 0 ? $baseline['low'] / $candidate['high'] : INF;
    $upper = $candidate['low'] > 0 ? $baseline['high'] / $candidate['low'] : INF;

    return ['quantile' => $quantile, 'php' => $baseline, 'rust' => $candidate, 'speedup' => $speedup,
        'speedup_lower' => $lower, 'speedup_upper' => $upper,
        'qualifies' => $speedup >= FILENAME_IDENTITY_THRESHOLD && $lower >= FILENAME_IDENTITY_THRESHOLD];
}

/**
 * Required metric first (median for common strata, p99 for uncommon), then the other as context.
 *
 * @param  list<int|float>  $php
 * @param  list<int|float>  $rust
 * @return array<string,mixed>
 */
function filenameIdentityStatistics(array $php, array $rust, float $requiredQuantile): array
{
    if (count($php) !== count($rust) || $php === []) {
        throw new InvalidArgumentException('Paired observations required.');
    }
    $median = filenameIdentityQuantileBlock($php, $rust, 0.5);
    $tail = filenameIdentityQuantileBlock($php, $rust, 0.99);
    $required = $requiredQuantile === 0.5 ? $median : $tail;

    return ['observations' => count($php), 'median' => $median, 'p99' => $tail,
        'required_metric' => $requiredQuantile === 0.5 ? 'median_latency' : 'p99_latency',
        'speedup' => $required['speedup'], 'speedup_lower' => $required['speedup_lower'], 'qualifies' => $required['qualifies']];
}

/** @return array<string,mixed> */
function filenameIdentityEnvironment(string $root, string $library, string $manifest): array
{
    $cpu = '';
    $arm = [];
    foreach (is_readable('/proc/cpuinfo') ? (file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        $value = trim(explode(':', $line, 2)[1] ?? '');
        if (str_starts_with($line, 'model name') || str_starts_with($line, 'Hardware')) {
            $cpu = $value;
            break;
        }
        if (str_starts_with($line, 'CPU implementer') || str_starts_with($line, 'CPU part')) {
            $arm[$line[4] === 'i' ? 'implementer' : 'part'] = $value;
        }
    }
    if ($cpu === '' && $arm !== []) {
        $cpu = 'ARM implementer '.($arm['implementer'] ?? '?').' part '.($arm['part'] ?? '?').' ('.php_uname('m').')';
    }
    $sha = getenv('GITHUB_SHA');
    $environment = ['php' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'pcre_jit' => ini_get('pcre.jit'), 'os' => php_uname(), 'cpu' => $cpu,
        'logical_cpus' => is_readable('/proc/cpuinfo') ? substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1 : null,
        'ffi_enable' => ini_get('ffi.enable'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
        'xdebug' => extension_loaded('xdebug'), 'source_sha' => is_string($sha) && $sha !== '' ? $sha : null,
        'library_sha256' => is_file($library) ? hash_file('sha256', $library) : null, 'runner_image' => getenv('ImageVersion') ?: null,
        'source_sha256' => ['app/Services/Releases/Verification/HeaderFilenameIdentity.php' => @hash_file('sha256', $root.'/app/Services/Releases/Verification/HeaderFilenameIdentity.php') ?: null,
            'app/Services/Releases/Verification/NativeHeaderFilenameIdentity.php' => @hash_file('sha256', $root.'/app/Services/Releases/Verification/NativeHeaderFilenameIdentity.php') ?: null,
            'docker/app/nzb-yenc-ffi/src/header_filename_identity.rs' => @hash_file('sha256', $root.'/docker/app/nzb-yenc-ffi/src/header_filename_identity.rs') ?: null]];
    if ($manifest !== '' && is_file($manifest)) {
        $decoded = json_decode((string) file_get_contents($manifest), true);
        if (is_array($decoded)) {
            $environment['runtime_manifest'] = array_intersect_key($decoded, array_flip(['rustc', 'cargo', 'build_profile', 'architecture', 'cpu_model', 'runner_image_version']));
        }
    }

    return $environment;
}

/**
 * @param  array<string,mixed>  $report
 * @param  array<string,mixed>  $raw
 */
function filenameIdentityWrite(string $directory, array $report, string $markdown, array $raw): void
{
    unset($report['raw']);
    file_put_contents($directory.'/summary.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
    if ($markdown !== '') {
        file_put_contents($directory.'/summary.md', $markdown);
    }
    file_put_contents($directory.'/observations-ns.json', json_encode($raw, JSON_THROW_ON_ERROR));
}

/** @param array<string,mixed> $report */
function filenameIdentityMarkdown(array $report): string
{
    $ns = static fn (float $value): string => $value >= 1000 ? number_format($value / 1000, 2).' us' : number_format($value, 0).' ns';
    $x = static fn (float $value): string => is_infinite($value) ? 'inf' : number_format($value, 2).'x';
    $env = $report['environment'] ?? [];
    $lines = ['## HeaderFilenameIdentity: PHP versus Rust (including FFI)', '',
        sprintf('Status: **%s**. Adoption threshold %sx on common-path median and uncommon-path p99, per-call and per release batch. '
            .'Production keeps the PHP rule unless this reads `qualified` on every runner.', $report['status'], FILENAME_IDENTITY_THRESHOLD), ''];
    if ($report['invalid'] !== []) {
        $lines[] = 'Invalid: '.implode('; ', $report['invalid']);
        $lines[] = '';
    }
    $lines[] = '| Stratum | Class | Pairs x repeats | Declined | Required metric | PHP | Rust+FFI | Speedup (95% lower) | PHP median / p99 | Rust median / p99 |';
    $lines[] = '|---|---|---:|---:|---|---:|---:|---:|---|---|';
    foreach ($report['strata'] as $name => $row) {
        if (! isset($row['median'])) {
            $lines[] = sprintf('| %s | %s | %d | - | invalid | - | - | - | - | - |', $name, $row['category'], $row['pairs']);

            continue;
        }
        $key = $row['required_metric'] === 'median_latency' ? 'median' : 'p99';
        $lines[] = sprintf('| %s | %s | %d x %d | %d | %s | %s | %s | %s (%s) | %s / %s | %s / %s |', $name, $row['category'], $row['pairs'],
            intdiv($row['observations'], max(1, $row['pairs'])), $row['declined_to_php'], $row['required_metric'],
            $ns($row[$key]['php']['estimate']), $ns($row[$key]['rust']['estimate']), $x($row['speedup']), $x($row['speedup_lower']),
            $ns($row['median']['php']['estimate']), $ns($row['p99']['php']['estimate']),
            $ns($row['median']['rust']['estimate']), $ns($row['p99']['rust']['estimate']));
    }
    if (isset($report['batch'])) {
        $batch = $report['batch'];
        $lines[] = '';
        $lines[] = sprintf('Release batch (%d calls per reading, %d readings): median PHP %s, Rust+FFI %s, speedup %s (lower %s); '
            .'p99 PHP %s, Rust+FFI %s, speedup %s (lower %s). Absolute median saving per 16-call batch: %s.',
            FILENAME_IDENTITY_BATCH_SIZE, $batch['observations'], $ns($batch['median']['php']['estimate']), $ns($batch['median']['rust']['estimate']),
            $x($batch['median']['speedup']), $x($batch['median']['speedup_lower']), $ns($batch['p99']['php']['estimate']),
            $ns($batch['p99']['rust']['estimate']), $x($batch['p99']['speedup']), $x($batch['p99']['speedup_lower']),
            $ns($batch['median']['php']['estimate'] - $batch['median']['rust']['estimate']));
    }
    $lines[] = '';
    $lines[] = sprintf('Timer overhead (hrtime pair, included in every per-call reading): median %s. Protocol: %s', $ns((float) ($report['timer_overhead_ns_median'] ?? 0)),
        $report['protocol']['timing']);
    $lines[] = sprintf('Environment: PHP %s, PCRE %s (jit=%s), opcache.enable_cli=%s, jit=%s, %s, %s logical CPUs, `%s`.', $env['php'] ?? '?', $env['pcre'] ?? '?',
        $env['pcre_jit'] ?? '?', $env['opcache_cli'] ?? '?', $env['jit'] ?? '?', $env['cpu'] ?? 'unknown CPU', $env['logical_cpus'] ?? '?', $env['os'] ?? '?');
    $lines[] = '';

    return implode("\n", $lines);
}
