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
use App\Services\BlacklistService;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\PerArticleObfuscationCorpus;

const PER_ARTICLE_THRESHOLD = 1.5;
const PER_ARTICLE_MIN_ARTICLES = 16;
const PER_ARTICLE_BOOTSTRAP = 2000;
const PER_ARTICLE_ROUTED_FLOOR = 0.9;
const PER_ARTICLE_TAIL_SAMPLES = 1000;
const PER_ARTICLE_COLD_RUNS = 21;

try {
    exit(perArticleBenchmarkMain());
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

function perArticleBenchmarkMain(): int
{
    $options = getopt('', ['native:', 'output-dir:', 'repeats:', 'seed:', 'cold-stratum:', 'cold-side:']);
    require_once dirname(__DIR__).'/vendor/autoload.php';
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
    ], 'environment' => ['php' => PHP_VERSION, 'machine' => php_uname('m'), 'os' => php_uname('s').' '.php_uname('r'),
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

    // Cold observations: each is the first call in a fresh process, so the Rust side pays for
    // loading the library and running its probe, as a finite indexing command does.
    $coldMarkdown = "| Stratum | Category | Cold side | Cold PHP ms | Cold Rust path ms | Speedup | 95% CI |\n|---|---|---|---:|---:|---:|---|\n";
    foreach (PerArticleObfuscationCorpus::strata() as $name => $stratum) {
        if ($stratum['size'] > 20000 || isset($report['strata'][$name]['parity'])) {
            continue;
        }
        $cold = ['php' => [], 'rust' => []];
        $usedRust = false;
        for ($run = 0; $run < PER_ARTICLE_COLD_RUNS; $run++) {
            foreach ($random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'] as $side) {
                $output = shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'ffi.enable=true', '-d', 'memory_limit=3G', __FILE__,
                    '--cold-stratum='.$name, '--cold-side='.$side, '--native='.$options['native'], '--seed='.$seed])));
                $result = json_decode((string) $output, true, 4, JSON_THROW_ON_ERROR);
                $cold[$side][] = (int) $result['ns'];
                $usedRust = $usedRust || ($side === 'rust' && $result['rust'] === true);
            }
        }
        $coldSpeedup = perArticleQuantile($cold['php'], 0.5) / max(1, perArticleQuantile($cold['rust'], 0.5));
        [$coldLow, $coldHigh] = perArticleBootstrap($cold, 0.5, $random);
        $report['cold'][$name] = ['php_ms' => round(perArticleQuantile($cold['php'], 0.5) / 1e6, 3),
            'rust_ms' => round(perArticleQuantile($cold['rust'], 0.5) / 1e6, 3), 'speedup' => round($coldSpeedup, 2),
            'speedup_ci95' => [round($coldLow, 2), round($coldHigh, 2)], 'samples' => PER_ARTICLE_COLD_RUNS, 'raw' => $cold];
        // A cold first call that took Rust must clear the threshold at its lower bound. One the
        // routing kept on PHP must not regress; with 21 sub-millisecond runs a median is noisy, so it
        // fails only when its whole interval sits below the floor.
        $required = $usedRust ? PER_ARTICLE_THRESHOLD : PER_ARTICLE_ROUTED_FLOOR;
        if ($usedRust ? $coldSpeedup < $required : $coldHigh < $required) {
            $report['status'] = $report['status'] === 'invalid' ? 'invalid' : 'not-qualified';
        } elseif ($usedRust && $coldLow < $required && $report['status'] === 'qualified') {
            $report['status'] = 'inconclusive';
        }
        $report['cold'][$name]['used_rust'] = $usedRust;
        $coldMarkdown .= sprintf("| %s | %s | %s | %.3f | %.3f | %.2fx | %.2f-%.2fx |\n", $name, $stratum['category'], $usedRust ? 'Rust' : 'PHP',
            $report['cold'][$name]['php_ms'], $report['cold'][$name]['rust_ms'], $coldSpeedup, $coldLow, $coldHigh);
    }

    $markdown = "| Stratum | Category | Headers | Marked | Statistic | PHP ms | Rust+FFI ms | Speedup | 95% CI |\n|---|---|---:|---:|---|---:|---:|---:|---|\n";
    foreach ($report['strata'] as $name => $row) {
        $markdown .= isset($row['parity']) ? "| {$name} | parity mismatch ||||||||\n"
            : sprintf("| %s | %s | %d | %d | %s | %.3f | %.3f | %.2fx | %.2f-%.2fx |\n", $name, $row['category'], $row['headers'], $row['marked'],
                $row['statistic'], $row['php_ms'], $row['rust_ms'], $row['speedup'], $row['speedup_ci95'][0], $row['speedup_ci95'][1]);
    }
    $markdown .= "\nStatus: **{$report['status']}** (threshold ".PER_ARTICLE_THRESHOLD."x; {$repeats} paired samples per stratum, ".PER_ARTICLE_TAIL_SAMPLES." for uncommon strata; Rust strata need the 95% lower bound at the threshold).\n";
    $markdown .= "\nCold first call per fresh process (median of ".PER_ARTICLE_COLD_RUNS.", same thresholds):\n\n".$coldMarkdown;
    file_put_contents($directory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/summary.md', $markdown);
    echo $markdown;

    return $report['status'] === 'invalid' ? 2 : 0;
}

/** Time one first call of $side on $name in this fresh process, as JSON nanoseconds. */
function perArticleColdChild(string $name, string $side, string $library, int $seed): string
{
    $headers = PerArticleObfuscationCorpus::stratum($name, $seed);
    $parser = new HeaderParser(new class extends BlacklistService
    {
        public function __construct() {}
    }, false);
    // Built before timing, as HeaderParser builds it with the service; loading the library and
    // its probe still happen inside the first mark().
    $native = new NativePerArticleObfuscation($library);
    $start = hrtime(true);
    $marked = $side === 'rust' ? $native->mark($headers, PER_ARTICLE_MIN_ARTICLES) : null;
    // The set HeaderParser::parse() consults, built on either side.
    $set = $marked === null
        ? ($parser->markPerArticleObfuscated($headers, PER_ARTICLE_MIN_ARTICLES) >= 0 ? array_flip($parser->perArticleObfuscatedNumbers()) : [])
        : array_fill_keys($marked, true);
    $elapsed = hrtime(true) - $start;

    return json_encode(['ns' => $elapsed, 'marked' => count($set), 'rust' => $marked !== null], JSON_THROW_ON_ERROR);
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
