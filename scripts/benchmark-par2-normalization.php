<?php

declare(strict_types=1);

use Tests\Fixtures\ArchiveParsingBenchmarkFixtures;

/** Compare two frozen native PHP callers, including their FFI/JSON/normalization costs. */
if (! defined('NNTMUX_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY')) {
    define('NNTMUX_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY', true);
}
require_once __DIR__.'/benchmark-archive-parsing.php';

/** @param array<string,mixed> $options */
function par2NormalizationBenchmark(array $options): int
{
    $mode = (string) ($options['mode'] ?? 'screen');
    $samples = filter_var($options['samples'] ?? 31, FILTER_VALIDATE_INT);
    if (! in_array($mode, ['screen', 'qualification'], true) || $samples === false || $samples < 1 || $samples > 10000
        || ($mode === 'qualification' && $samples < 1000)) {
        throw new InvalidArgumentException('Qualification needs at least 1000 independent pairs.');
    }
    $directory = (string) ($options['output-dir'] ?? '');
    if ($directory === '' || file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new.');
    }
    $directory = realpath($directory) ?: throw new RuntimeException('Missing output directory.');
    $write = static function (array $row) use ($directory): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($directory.'/observations.jsonl', $line, FILE_APPEND) === false) {
            throw new RuntimeException('Cannot retain observation.');
        }
        echo $line;
    };
    $instrumentation = archiveBenchmarkInstrumentation();
    $instrumentation['scripts/benchmark-par2-normalization.php'] = hash_file('sha256', __FILE__);
    $write(['type' => 'protocol', 'protocol' => 'par2-native-normalization-v1', 'mode' => $mode, 'samples' => $samples,
        'order_seed' => 20261015, 'instrumentation' => $instrumentation,
        'comparison' => 'Frozen incumbent native public PHP caller versus candidate native public PHP caller; exact complete serialized result parity, not a shared projection.',
        'timing' => 'Fresh PHP process per backend/pair; cold caller, one warmup, one warm caller. FFI, allocation, JSON and PHP policy included. Serialization/proof outside timers; raw process CPU/RSS/throttling retained.',
        'native_proof' => 'Candidate must load optional normalized ABI and never load legacy ABI. Pinned native-only caller returns Rust engine; any fallback invalidates observation.',
        'rule' => 'Every common median and uncommon p99, cold and warm: simultaneous conservative 95% lower speedup >=1.5; >=1000 pairs, no invalidity and immutable sources/inputs/results. Screen never qualifies.']);
    try {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            throw new RuntimeException('Real native admission requires Linux CLI FFI.');
        }
        $graphs = [];
        $libraries = [];
        $origins = [];
        $sources = [];
        foreach (['incumbent', 'candidate'] as $backend) {
            $origins[$backend] = realpath((string) ($options[$backend.'-source'] ?? '')) ?: throw new InvalidArgumentException('Missing source graph.');
            $sources[$backend] = archiveBenchmarkSources($origins[$backend]);
            $libraries[$backend] = articleBenchmarkFreezeLibrary((string) ($options[$backend.'-native'] ?? ''), $directory.'/'.$backend.'.so');
            $receipt = articleBenchmarkReadManifest((string) ($options[$backend.'-manifest'] ?? ''));
            sampleBenchmarkVerifyManifest($receipt, $sources[$backend], $libraries[$backend]['sha256']);
            file_put_contents($directory.'/'.$backend.'-build.json', json_encode($receipt, JSON_THROW_ON_ERROR));
            $graphs[$backend] = articleBenchmarkFreezeGraph($origins[$backend], $directory.'/'.$backend.'-source');
        }
        // The baseline must be the original caller, without the new ABI probe.
        if (str_contains((string) file_get_contents($graphs['incumbent']['root'].'/app/Services/Releases/Fingerprint/Par2MetadataParser.php'), 'parsePar2WithIntegerRanges')) {
            throw new RuntimeException('Incumbent source already contains the candidate capability.');
        }
        foreach ($instrumentation as $path => $hash) {
            $target = $directory.'/instrumentation/'.$path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            if (! copy(dirname(__DIR__).'/'.$path, $target) || hash_file('sha256', $target) !== $hash) {
                throw new RuntimeException('Cannot freeze instrumentation.');
            }
        }
        $childInstrumentation = archiveBenchmarkInstrumentation();
        $write(['type' => 'setup', 'graphs' => $graphs, 'libraries' => $libraries, 'build_sources' => $sources]);
        $state = 20261015;
        $invalidTotal = 0;
        $qualified = true;
        $hashes = [];
        $decisions = [];
        foreach (ArchiveParsingBenchmarkFixtures::strata() as $name => $definition) {
            if (! $definition['paired']) {
                continue;
            }
            $fixture = ArchiveParsingBenchmarkFixtures::make($name, $graphs['candidate']['root']);
            $fixturePath = $directory.'/'.$name.'.fixture.serialized';
            file_put_contents($fixturePath, serialize($fixture));
            $hashes[$fixturePath] = hash_file('sha256', $fixturePath);
            $write(['type' => 'fixture', 'stratum' => $name, 'sha256' => $hashes[$fixturePath], 'input_sha256' => $fixture['input_sha256'], 'oracle' => $fixture['oracle']]);
            $latencies = ['cold' => ['incumbent' => [], 'candidate' => []], 'warm' => ['incumbent' => [], 'candidate' => []]];
            $invalid = 0;
            for ($pair = 0; $pair < $samples; $pair++) {
                $order = yencBenchmarkRandom($state) % 2 === 0 ? ['incumbent', 'candidate'] : ['candidate', 'incumbent'];
                $eligible = true;
                $children = [];
                $outputs = [];
                foreach ($order as $backend) {
                    $prefix = $directory.'/'.$name.'-'.$pair.'-'.$backend;
                    $output = $prefix.'.serialized';
                    $command = [...articleBenchmarkPhpCommand(), '-d', 'ffi.enable=true',
                        $directory.'/instrumentation/scripts/benchmark-archive-parsing.php', '--worker=native',
                        '--source-dir='.$graphs[$backend]['root'], '--native='.$libraries[$backend]['path'],
                        '--fixture-path='.$fixturePath, '--result-output='.$output];
                    $child = yencBenchmarkRunChild($command, $prefix, 60);
                    $good = hash_file('sha256', $fixturePath) === $hashes[$fixturePath]
                        && archiveBenchmarkEligible($child, 'native', $fixture, $libraries[$backend]['sha256'], $output, $graphs[$backend], $childInstrumentation);
                    if ($backend === 'candidate') {
                        foreach (['cold', 'warmup', 'warm'] as $phase) {
                            $good = $good && ($child['result']['observations'][$phase]['integer_ranges_backend_proven'] ?? false) === true;
                        }
                    }
                    $children[$backend] = $child;
                    $outputs[$backend] = is_file($output) ? archiveBenchmarkReadResults($output) : null;
                    if (is_file($output)) {
                        $hashes[$output] = hash_file('sha256', $output);
                    }
                    $eligible = $eligible && $good;
                    $write(['type' => 'observation', 'stratum' => $name, 'pair' => $pair, 'backend' => $backend, 'eligible' => $good, 'command' => $command, 'child' => $child]);
                }
                $fullParity = par2NormalizationFullParity($outputs['incumbent'], $outputs['candidate']);
                $eligible = $eligible && $fullParity;
                if ($eligible) {
                    foreach (['cold', 'warm'] as $phase) {
                        foreach ($order as $backend) {
                            $latencies[$phase][$backend][] = $children[$backend]['result']['observations'][$phase]['elapsed_ms'];
                        }
                    }
                } else {
                    $invalid++;
                    $invalidTotal++;
                }
                $write(['type' => 'pair', 'stratum' => $name, 'pair' => $pair, 'order' => $order, 'eligible' => $eligible, 'full_serialized_parity' => $fullParity]);
            }
            foreach (['cold', 'warm'] as $phase) {
                $decision = sampleBenchmarkDecision($latencies[$phase]['incumbent'], $latencies[$phase]['candidate'], $invalid, $definition['category'], $mode);
                // The shared helper's php/native labels denote its two inputs only.
                $decisions[$name][$phase] = $decision;
                $qualified = $qualified && $decision['qualifies'];
            }
            $write(['type' => 'stratum', 'stratum' => $name, 'category' => $definition['category'], 'decision' => $decisions[$name]]);
        }
        $stable = true;
        foreach (['incumbent', 'candidate'] as $backend) {
            $stable = $stable && articleBenchmarkGraphStable($graphs[$backend], $origins[$backend]) && articleBenchmarkGraphStable($graphs[$backend])
                && archiveBenchmarkSources($origins[$backend]) === $sources[$backend] && hash_file('sha256', $libraries[$backend]['path']) === $libraries[$backend]['sha256'];
        }
        foreach ($instrumentation as $path => $hash) {
            $stable = $stable && hash_file('sha256', dirname(__DIR__).'/'.$path) === $hash && hash_file('sha256', $directory.'/instrumentation/'.$path) === $hash;
        }
        foreach ($hashes as $path => $hash) {
            $stable = $stable && hash_file('sha256', $path) === $hash;
        }
        $stable = $stable && archiveBenchmarkStoresStable($directory);
        $pass = $qualified && $stable && $invalidTotal === 0;
        $write(['type' => 'summary', 'status' => ! $stable || $invalidTotal > 0 ? 'invalid' : ($pass ? 'qualified' : 'inconclusive'),
            'identities_stable' => $stable, 'invalid_pairs' => $invalidTotal, 'strata' => $decisions, 'acceptance_criteria_met' => $pass]);

        return ! $stable || $invalidTotal > 0 ? 2 : ($mode === 'qualification' && ! $pass ? 1 : 0);
    } catch (Throwable $exception) {
        $write(['type' => 'summary', 'status' => 'invalid', 'reason' => $exception->getMessage(), 'acceptance_criteria_met' => false]);

        return 2;
    }
}

function par2NormalizationFullParity(mixed $incumbent, mixed $candidate): bool
{
    if (! is_array($incumbent) || ! is_array($candidate)) {
        return false;
    }
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        if (! is_array($incumbent[$phase] ?? null) || ! is_array($candidate[$phase] ?? null)
            || serialize($incumbent[$phase]) !== serialize($candidate[$phase])) {
            return false;
        }
    }

    return true;
}

if (defined('NNTMUX_PAR2_NORMALIZATION_FUNCTIONS_ONLY')) {
    return;
}
try {
    exit(par2NormalizationBenchmark(getopt('', ['incumbent-source:', 'candidate-source:', 'incumbent-native:', 'candidate-native:',
        'incumbent-manifest:', 'candidate-manifest:', 'output-dir:', 'samples:', 'mode:'])));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}
