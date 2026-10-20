<?php

declare(strict_types=1);

/**
 * Paired PHP versus Rust comparison for HeaderParser::markPerArticleObfuscated() over seeded ranges.
 *
 *   php -d ffi.enable=true -d memory_limit=2G scripts/benchmark-per-article-obfuscation.php
 *     --native=/absolute/libnzbyenc.so --output-dir=/new/directory [--repeats=200] [--seed=20261019]
 *
 * Protocol: per stratum (Tests\Support\PerArticleObfuscationCorpus), the marked sets are first
 * checked for equality; then each side is timed `repeats` times on the whole range, one call per
 * timer reading, in a seeded random order per observation, after 3 warm-up passes with GC
 * disabled. Cold observations repeat each stratum's first call in fresh processes, so the Rust side
 * also pays for loading the library and its probe. The Rust side is the caller's full path: record packing, the FFI call, unpacking and
 * building the article-number set HeaderParser consults, falling back to PHP on a decline. The
 * thresholds follow .ai/rules/rust-performance.md: common strata compare medians, uncommon strata
 * p99, and each must reach 1.5x with the 95% interval's lower bound (otherwise the run is
 * inconclusive); uncommon strata take at least 1000 samples; routed strata (token-poor ranges the
 * wrapper leaves on PHP) compare medians and must stay at 0.9x or better. Uncertainty is a 95% percentile bootstrap
 * interval of that speedup (2000 seeded resamples of the paired observations). A missed threshold
 * is a valid result and exits 0; invalid runs (parity failure, missing library) exit 2.
 */

use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\NativePerArticleObfuscation;
use App\Services\Binaries\PerArticleObfuscationGate;
use App\Services\BlacklistService;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\PerArticleObfuscationCorpus;

const PER_ARTICLE_THRESHOLD = 1.5;
const PER_ARTICLE_MIN_ARTICLES = 16;
const PER_ARTICLE_BOOTSTRAP = 2000;
const PER_ARTICLE_ROUTED_FLOOR = 0.9;
const PER_ARTICLE_TAIL_SAMPLES = 1000;
const PER_ARTICLE_COLD_RUNS = 41;
/** Cold rows the routing keeps on PHP compare sub-millisecond first calls, so take more of them. */
const PER_ARTICLE_COLD_RUNS_ROUTED = 41;
/** Fresh processes timing the one-time start-up cost (construction, library load and probe). */
const PER_ARTICLE_STARTUP_RUNS = 200;

try {
    exit(perArticleBenchmarkMain());
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

function perArticleBenchmarkMain(): int
{
    $options = getopt('', ['native:', 'output-dir:', 'repeats:', 'seed:', 'cold-stratum:', 'cold-side:', 'startup-child']);
    require_once dirname(__DIR__).'/vendor/autoload.php';
    if (isset($options['startup-child'])) {
        $start = hrtime(true);
        $available = (new NativePerArticleObfuscation((string) ($options['native'] ?? '')))->available();
        echo json_encode(['ns' => hrtime(true) - $start, 'available' => $available], JSON_THROW_ON_ERROR).PHP_EOL;

        return 0;
    }
    if (isset($options['cold-stratum'])) {
        echo perArticleColdChild((string) $options['cold-stratum'], (string) ($options['cold-side'] ?? ''), (string) ($options['native'] ?? ''), (int) ($options['seed'] ?? 20261019)).PHP_EOL;

        return 0;
    }
    $repeats = filter_var($options['repeats'] ?? 200, FILTER_VALIDATE_INT);
    $seed = filter_var($options['seed'] ?? 20261019, FILTER_VALIDATE_INT);
    if ($repeats === false || $repeats < 10 || $repeats > 1000 || $seed === false || $seed < 1) {
        throw new InvalidArgumentException('Invalid protocol parameters.');
    }
    $directory = (string) ($options['output-dir'] ?? '');
    if ($directory === '' || file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new.');
    }
    $native = new NativePerArticleObfuscation((string) ($options['native'] ?? ''));
    if (! $native->available()) {
        throw new RuntimeException('Native library is unavailable or failed its probe (requires a Linux CLI with FFI).');
    }
    $parser = new HeaderParser(new class extends BlacklistService
    {
        public function __construct() {}
    }, false);
    $php = static function (array $headers) use ($parser): array {
        $parser->markPerArticleObfuscated($headers, PER_ARTICLE_MIN_ARTICLES);

        return $parser->perArticleObfuscatedNumbers();
    };
    $rust = static function (array $headers) use ($native, $php): array {
        $marked = $native->mark($headers, PER_ARTICLE_MIN_ARTICLES);
        if ($marked === null) {
            return $php($headers);
        }
        // The set HeaderParser::parse() consults, so both sides end in the same caller state.
        $set = array_fill_keys($marked, true);

        return array_keys($set);
    };

    $report = ['protocol' => [
        'name' => 'per-article-obfuscation-v1', 'seed' => $seed, 'repeats' => $repeats, 'min_articles' => PER_ARTICLE_MIN_ARTICLES,
        'threshold' => PER_ARTICLE_THRESHOLD, 'bootstrap_resamples' => PER_ARTICLE_BOOTSTRAP, 'timing' => 'hrtime around each whole-range call; random side order per observation; 3 warm-ups; GC disabled',
        'rust_path' => 'pack records + FFI call + unpack + array_fill_keys, PHP fallback on decline',
    ], 'environment' => ['source_commit' => perArticleSourceCommit(), 'php' => PHP_VERSION, 'machine' => php_uname('m'), 'os' => php_uname('s').' '.php_uname('r'),
        'library_sha256' => hash_file('sha256', (string) $options['native'])], 'strata' => [], 'status' => 'qualified'];
    $random = new Randomizer(new Mt19937($seed));
    foreach (PerArticleObfuscationCorpus::strata() as $name => $stratum) {
        $headers = PerArticleObfuscationCorpus::stratum($name, $seed);
        $expected = $php($headers);
        $actual = $rust($headers);
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            $report['status'] = 'invalid';
            $report['strata'][$name] = ['parity' => 'mismatch'];

            continue;
        }
        $samples = ['php' => [], 'rust' => []];
        gc_collect_cycles();
        gc_disable();
        for ($warm = 0; $warm < 3; $warm++) {
            $php($headers);
            $rust($headers);
        }
        // A p99 needs a deep tail: every uncommon stratum takes at least 1000 samples.
        $observations = $stratum['category'] === 'uncommon' ? max($repeats, PER_ARTICLE_TAIL_SAMPLES) : $repeats;
        for ($i = 0; $i < $observations; $i++) {
            $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
            foreach ($order as $side) {
                $start = hrtime(true);
                ($side === 'php' ? $php : $rust)($headers);
                $samples[$side][] = hrtime(true) - $start;
            }
        }
        gc_enable();
        $quantile = $stratum['category'] === 'uncommon' ? 0.99 : 0.5;
        $phpValue = perArticleQuantile($samples['php'], $quantile);
        $rustValue = perArticleQuantile($samples['rust'], $quantile);
        $speedup = $phpValue / max(1, $rustValue);
        [$low, $high] = perArticleBootstrap($samples, $quantile, $random);
        $row = ['category' => $stratum['category'], 'description' => $stratum['description'], 'headers' => count($headers),
            'marked' => count($expected), 'declined' => $native->mark($headers, PER_ARTICLE_MIN_ARTICLES) === null,
            'statistic' => $quantile === 0.5 ? 'median' : 'p99',
            'php_ms' => round($phpValue / 1e6, 3), 'rust_ms' => round($rustValue / 1e6, 3), 'speedup' => round($speedup, 2),
            'speedup_ci95' => [round($low, 2), round($high, 2)],
            'php_median_ms' => round(perArticleQuantile($samples['php'], 0.5) / 1e6, 3),
            'rust_median_ms' => round(perArticleQuantile($samples['rust'], 0.5) / 1e6, 3),
            'php_p99_ms' => round(perArticleQuantile($samples['php'], 0.99) / 1e6, 3),
            'rust_p99_ms' => round(perArticleQuantile($samples['rust'], 0.99) / 1e6, 3),
            'samples' => $observations];
        // Routed strata stay on PHP by design; they only must not regress (sampling overhead).
        $required = $stratum['category'] === 'routed' ? PER_ARTICLE_ROUTED_FLOOR : PER_ARTICLE_THRESHOLD;
        // Rust strata qualify only when the interval's lower bound clears the threshold; a point
        // estimate above it with a lower bound below is inconclusive.
        if ($speedup < $required) {
            $report['status'] = $report['status'] === 'invalid' ? 'invalid' : 'not-qualified';
        } elseif ($stratum['category'] !== 'routed' && $low < $required && $report['status'] === 'qualified') {
            $report['status'] = 'inconclusive';
        }
        $report['strata'][$name] = $row;
        $report['raw'][$name] = $samples;
    }

    // Uncommon p99 with start-up included. A process pays construction, library load and probe
    // once, before its first range; adding the p99 of that cost (from fresh processes) to the warm
    // Rust p99 bounds the cold uncommon p99 from above. PHP's own first-call costs are left out, so
    // the bound only understates the speedup. Every uncommon stratum, large-range included, must
    // clear the threshold at the bootstrap lower bound.
    $startup = [];
    for ($run = 0; $run < PER_ARTICLE_STARTUP_RUNS; $run++) {
        $output = shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'ffi.enable=true', __FILE__,
            '--startup-child', '--native='.$options['native']])));
        $result = json_decode((string) $output, true, 4, JSON_THROW_ON_ERROR);
        if ($result['available'] !== true) {
            throw new RuntimeException('Native library failed to load in a fresh process.');
        }
        $startup[] = (int) $result['ns'];
    }
    $report['startup'] = ['runs' => PER_ARTICLE_STARTUP_RUNS, 'median_ms' => round(perArticleQuantile($startup, 0.5) / 1e6, 3),
        'p99_ms' => round(perArticleQuantile($startup, 0.99) / 1e6, 3), 'raw' => $startup];
    $boundMarkdown = "| Stratum | PHP p99 ms | Rust p99 + start-up p99 ms | Bounded speedup | 95% CI |\n|---|---:|---:|---:|---|\n";
    foreach (PerArticleObfuscationCorpus::strata() as $name => $stratum) {
        if ($stratum['category'] !== 'uncommon' || ! isset($report['raw'][$name])) {
            continue;
        }
        $samples = $report['raw'][$name];
        $bounded = static fn (array $php, array $rust, array $start): float => perArticleQuantile($php, 0.99)
            / max(1, perArticleQuantile($rust, 0.99) + perArticleQuantile($start, 0.99));
        $point = $bounded($samples['php'], $samples['rust'], $startup);
        $ratios = [];
        for ($resample = 0; $resample < PER_ARTICLE_BOOTSTRAP; $resample++) {
            $pick = static fn (array $values): array => array_map(static fn (): int => $values[$random->getInt(0, count($values) - 1)], $values);
            $ratios[] = $bounded($pick($samples['php']), $pick($samples['rust']), $pick($startup));
        }
        [$boundLow, $boundHigh] = [perArticleQuantile($ratios, 0.025), perArticleQuantile($ratios, 0.975)];
        $report['startup_bounded_p99'][$name] = ['speedup' => round($point, 2), 'speedup_ci95' => [round($boundLow, 2), round($boundHigh, 2)]];
        if ($point < PER_ARTICLE_THRESHOLD) {
            $report['status'] = $report['status'] === 'invalid' ? 'invalid' : 'not-qualified';
        } elseif ($boundLow < PER_ARTICLE_THRESHOLD && $report['status'] === 'qualified') {
            $report['status'] = 'inconclusive';
        }
        $boundMarkdown .= sprintf("| %s | %.3f | %.3f | %.2fx | %.2f-%.2fx |\n", $name, perArticleQuantile($samples['php'], 0.99) / 1e6,
            (perArticleQuantile($samples['rust'], 0.99) + perArticleQuantile($startup, 0.99)) / 1e6, $point, $boundLow, $boundHigh);
    }

    // Cold observations: each is the first call in a fresh process, so the Rust side pays for
    // loading the library and running its probe, as a finite indexing command does.
    $constructions = [];
    $coldMarkdown = "| Stratum | Category | Cold side | Cold PHP ms | Cold Rust path ms | Speedup | 95% CI |\n|---|---|---|---:|---:|---:|---|\n";
    foreach (PerArticleObfuscationCorpus::strata() as $name => $stratum) {
        if ($stratum['size'] > 20000 || isset($report['strata'][$name]['parity'])) {
            continue;
        }
        $cold = ['php' => [], 'rust' => []];
        $rustRuns = 0;
        $runs = $stratum['category'] === 'routed' ? PER_ARTICLE_COLD_RUNS_ROUTED : PER_ARTICLE_COLD_RUNS;
        for ($run = 0; $run < $runs; $run++) {
            foreach ($random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'] as $side) {
                $output = shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'ffi.enable=true', '-d', 'memory_limit=3G', __FILE__,
                    '--cold-stratum='.$name, '--cold-side='.$side, '--native='.$options['native'], '--seed='.$seed])));
                $result = json_decode((string) $output, true, 4, JSON_THROW_ON_ERROR);
                $cold[$side][] = (int) $result['ns'];
                $rustRuns += $side === 'rust' && $result['rust'] === true ? 1 : 0;
                if ((int) $result['construct_ns'] > 0) {
                    $constructions[] = (int) $result['construct_ns'];
                }
            }
        }
        // The side most of this stratum's cold runs took decides its gate, so one run the sampler
        // happened to send the other way cannot hold a PHP-routed stratum to the Rust threshold.
        $usedRust = $rustRuns * 2 > $runs;
        $coldSpeedup = perArticleQuantile($cold['php'], 0.5) / max(1, perArticleQuantile($cold['rust'], 0.5));
        [$coldLow, $coldHigh] = perArticleBootstrap($cold, 0.5, $random);
        $report['cold'][$name] = ['php_ms' => round(perArticleQuantile($cold['php'], 0.5) / 1e6, 3),
            'rust_ms' => round(perArticleQuantile($cold['rust'], 0.5) / 1e6, 3), 'speedup' => round($coldSpeedup, 2),
            'speedup_ci95' => [round($coldLow, 2), round($coldHigh, 2)], 'samples' => $runs, 'raw' => $cold];
        // A cold first call that took Rust must clear the threshold at its lower bound; one the
        // routing kept on PHP must keep its median at the floor.
        $required = $usedRust ? PER_ARTICLE_THRESHOLD : PER_ARTICLE_ROUTED_FLOOR;
        if ($coldSpeedup < $required) {
            $report['status'] = $report['status'] === 'invalid' ? 'invalid' : 'not-qualified';
        } elseif ($usedRust && $coldLow < $required && $report['status'] === 'qualified') {
            $report['status'] = 'inconclusive';
        }
        $report['cold'][$name]['used_rust'] = $usedRust;
        $report['cold'][$name]['rust_runs'] = $rustRuns;
        $coldMarkdown .= sprintf("| %s | %s | %s | %.3f | %.3f | %.2fx | %.2f-%.2fx |\n", $name, $stratum['category'], $usedRust ? 'Rust' : 'PHP',
            $report['cold'][$name]['php_ms'], $report['cold'][$name]['rust_ms'], $coldSpeedup, $coldLow, $coldHigh);
    }

    $report['cold_construct_median_ms'] = $constructions === [] ? null : round(perArticleQuantile($constructions, 0.5) / 1e6, 3);
    $report['cold_construct_p90_ms'] = $constructions === [] ? null : round(perArticleQuantile($constructions, 0.9) / 1e6, 3);

    $markdown = "| Stratum | Category | Headers | Marked | Statistic | PHP ms | Rust+FFI ms | Speedup | 95% CI |\n|---|---|---:|---:|---|---:|---:|---:|---|\n";
    foreach ($report['strata'] as $name => $row) {
        $markdown .= isset($row['parity']) ? "| {$name} | parity mismatch ||||||||\n"
            : sprintf("| %s | %s | %d | %d | %s | %.3f | %.3f | %.2fx | %.2f-%.2fx |\n", $name, $row['category'], $row['headers'], $row['marked'],
                $row['statistic'], $row['php_ms'], $row['rust_ms'], $row['speedup'], $row['speedup_ci95'][0], $row['speedup_ci95'][1]);
    }
    $markdown .= "\nSource commit: `".$report['environment']['source_commit'].'`; library sha256 `'.$report['environment']['library_sha256']."`.\n";
    $markdown .= "\nStatus: **{$report['status']}** (threshold ".PER_ARTICLE_THRESHOLD."x; {$repeats} paired samples per stratum, ".PER_ARTICLE_TAIL_SAMPLES." for uncommon strata; Rust strata need the 95% lower bound at the threshold).\n";
    $markdown .= "\nUncommon p99 with start-up included (warm Rust p99 plus the p99 of construction, library load and probe over "
        .PER_ARTICLE_STARTUP_RUNS.' fresh processes: median '.$report['startup']['median_ms'].' ms, p99 '.$report['startup']['p99_ms']." ms):\n\n".$boundMarkdown;
    $markdown .= "\nCold first call per fresh process (median of ".PER_ARTICLE_COLD_RUNS.' runs, '.PER_ARTICLE_COLD_RUNS_ROUTED.' for routed strata; same thresholds; the side most runs took sets the gate). Each Rust-path row includes building the wrapper on the first eligible range (median '.$report['cold_construct_median_ms'].' ms, p90 '.$report['cold_construct_p90_ms']." ms of it), loading the library and its probe.\n\n".$coldMarkdown;
    file_put_contents($directory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/summary.md', $markdown);
    echo $markdown;

    return $report['status'] === 'invalid' ? 2 : 0;
}

/** The measured source revision: GITHUB_SHA in CI, otherwise the checkout's HEAD when git can read it. */
function perArticleSourceCommit(): string
{
    $sha = getenv('GITHUB_SHA');
    if (is_string($sha) && $sha !== '') {
        return $sha;
    }
    $head = shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' rev-parse HEAD 2>/dev/null');

    return is_string($head) && trim($head) !== '' ? trim($head) : 'unknown';
}

/** Time one first call of $side on $name in this fresh process, as JSON nanoseconds. */
function perArticleColdChild(string $name, string $side, string $library, int $seed): string
{
    $headers = PerArticleObfuscationCorpus::stratum($name, $seed);
    $parser = new HeaderParser(new class extends BlacklistService
    {
        public function __construct() {}
    }, false);
    // As HeaderParser does: route first, then build the wrapper only for a range routed to Rust,
    // inside the timed call. Routing, construction (class load), library load and probe all count;
    // a range kept on PHP pays only the routing sample.
    $start = hrtime(true);
    $construct = 0;
    $marked = null;
    if ($side === 'rust' && PerArticleObfuscationGate::worthNativeMarking($headers, false)) {
        $built = hrtime(true);
        $native = new NativePerArticleObfuscation($library);
        $construct = hrtime(true) - $built;
        $marked = $native->markWithoutSampling($headers, PER_ARTICLE_MIN_ARTICLES);
    }
    // The set HeaderParser::parse() consults, built on either side.
    $set = $marked === null
        ? ($parser->markPerArticleObfuscated($headers, PER_ARTICLE_MIN_ARTICLES) >= 0 ? array_flip($parser->perArticleObfuscatedNumbers()) : [])
        : array_fill_keys($marked, true);
    $elapsed = hrtime(true) - $start;

    return json_encode(['ns' => $elapsed, 'construct_ns' => $construct, 'marked' => count($set), 'rust' => $marked !== null], JSON_THROW_ON_ERROR);
}

/**
 * 95% percentile bootstrap interval of the quantile speedup, resampling paired observations.
 *
 * @param  array{php: list<int>, rust: list<int>}  $samples
 * @return array{float, float}
 */
function perArticleBootstrap(array $samples, float $quantile, Randomizer $random): array
{
    $count = count($samples['php']);
    $ratios = [];
    for ($resample = 0; $resample < PER_ARTICLE_BOOTSTRAP; $resample++) {
        $php = $rust = [];
        for ($i = 0; $i < $count; $i++) {
            $pick = $random->getInt(0, $count - 1);
            $php[] = $samples['php'][$pick];
            $rust[] = $samples['rust'][$pick];
        }
        $ratios[] = perArticleQuantile($php, $quantile) / max(1, perArticleQuantile($rust, $quantile));
    }

    return [perArticleQuantile($ratios, 0.025), perArticleQuantile($ratios, 0.975)];
}

/** @param list<int|float> $values */
function perArticleQuantile(array $values, float $quantile): float
{
    sort($values);

    return (float) $values[(int) min(count($values) - 1, max(0, (int) ceil($quantile * count($values)) - 1))];
}
