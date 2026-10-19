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
 * disabled. The Rust side is the caller's full path: record packing, the FFI call, unpacking and
 * building the article-number set HeaderParser consults, falling back to PHP on a decline. The
 * thresholds follow .ai/rules/rust-performance.md: common strata compare medians, uncommon strata
 * p99, and each must reach 1.5x on the point estimate. Uncertainty is a 95% percentile bootstrap
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

try {
    exit(perArticleBenchmarkMain());
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

function perArticleBenchmarkMain(): int
{
    $options = getopt('', ['native:', 'output-dir:', 'repeats:', 'seed:']);
    require_once dirname(__DIR__).'/vendor/autoload.php';
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
        for ($i = 0; $i < $repeats; $i++) {
            $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
            foreach ($order as $side) {
                $start = hrtime(true);
                ($side === 'php' ? $php : $rust)($headers);
                $samples[$side][] = hrtime(true) - $start;
            }
        }
        gc_enable();
        $quantile = $stratum['category'] === 'common' ? 0.5 : 0.99;
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
            'samples' => $repeats];
        if ($speedup < PER_ARTICLE_THRESHOLD && $report['status'] === 'qualified') {
            $report['status'] = 'not-qualified';
        }
        $report['strata'][$name] = $row;
        $report['raw'][$name] = $samples;
    }

    $markdown = "| Stratum | Category | Headers | Marked | Statistic | PHP ms | Rust+FFI ms | Speedup | 95% CI |\n|---|---|---:|---:|---|---:|---:|---:|---|\n";
    foreach ($report['strata'] as $name => $row) {
        $markdown .= isset($row['parity']) ? "| {$name} | parity mismatch ||||||||\n"
            : sprintf("| %s | %s | %d | %d | %s | %.3f | %.3f | %.2fx | %.2f-%.2fx |\n", $name, $row['category'], $row['headers'], $row['marked'],
                $row['statistic'], $row['php_ms'], $row['rust_ms'], $row['speedup'], $row['speedup_ci95'][0], $row['speedup_ci95'][1]);
    }
    $markdown .= "\nStatus: **{$report['status']}** (threshold ".PER_ARTICLE_THRESHOLD."x; {$repeats} paired samples per stratum).\n";
    file_put_contents($directory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/summary.md', $markdown);
    echo $markdown;

    return $report['status'] === 'invalid' ? 2 : 0;
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
