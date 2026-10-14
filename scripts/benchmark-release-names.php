<?php

declare(strict_types=1);

// Run with the presentation header and wrapper preloaded, as in the web runtime.
// This compares full batch preparation (including routes), FFI and HTML rendering.
use App\Support\AdminReleaseNames;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.test');
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'testing', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app->make(Kernel::class)->bootstrap();
if (! function_exists('nntmux_render_release_names') || nntmux_render_release_names('{"url_prefix":"/details/","url_suffix":"","releases":[]}') !== []) {
    throw new RuntimeException('Preload the real Rust presentation library; fallback is not eligible.');
}

$options = getopt('', ['samples:', 'iterations:', 'seed:']);
$samples = max(11, (int) ($options['samples'] ?? 21));
$iterations = max(10, (int) ($options['iterations'] ?? 100));
$seed = (int) ($options['seed'] ?? 20261014);
mt_srand($seed);
$renderer = new AdminReleaseNames;
$sources = [];
foreach (['app/Support/AdminReleaseNames.php', 'app/Support/ReleaseNameDisplay.php', 'docker/app/nzb-yenc-ffi/src/release_names.rs', 'docker/app/nzb-yenc-ffi/src/lib.rs', 'docker/app/nzb-yenc-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.json', 'docker/app/php/release-presentation-preload.php', 'scripts/benchmark-release-names.php'] as $source) {
    $sources[$source] = hash_file('sha256', dirname(__DIR__).'/'.$source);
}
$preloadHeader = file_get_contents((string) ini_get('ffi.preload'));
if (preg_match('/^#define FFI_LIB "([^"]+)"$/m', $preloadHeader, $match) !== 1) {
    throw new RuntimeException('Unable to identify the preloaded native binary.');
}
$libraryHash = hash_file('sha256', $match[1]);
$allPassed = true;
foreach (['clean', 'wrapped', 'escaped'] as $scenario) {
    foreach ([1, 50, 100] as $count) {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $name = match ($scenario) {
                'clean' => 'Some.Movie.2026.1080p.BluRay.x264-GROUP',
                'wrapped' => '[01/10] - "Unknown.File.part01.rar" yEnc (1/20)',
                'escaped' => '[1/2] &quot;日本語 & <Movie> \' title.rar&quot;',
            };
            $rows[] = (object) ['guid' => hash('md5', (string) $i), 'searchname' => $name, 'name' => $name];
        }
        $php = array_map(strval(...), $renderer->render($rows, false));
        $rust = array_map(strval(...), $renderer->render($rows, true));
        $inputRows = array_map(static fn (object $row): array => (array) $row, $rows);
        $direct = nntmux_render_release_names(json_encode(['url_prefix' => str_replace('__GUID__', '', route('details', ['guid' => '__GUID__'])), 'url_suffix' => '', 'releases' => $inputRows], JSON_THROW_ON_ERROR));
        if ($direct !== $php) {
            throw new RuntimeException('Real native output failed; fallback is not eligible.');
        }
        if ($php !== $rust) {
            throw new RuntimeException('Correctness gate failed for '.$scenario.'/'.$count);
        }
        $observations = [];
        $times = ['php' => [], 'rust' => []];
        // One untimed warmup per backend, followed by randomized paired measurements.
        foreach ([false, true] as $native) {
            for ($i = 0; $i < $iterations; $i++) {
                $renderer->render($rows, $native);
            }
        }
        for ($sample = 0; $sample < $samples; $sample++) {
            $order = mt_rand(0, 1) === 1 ? ['php', 'rust'] : ['rust', 'php'];
            foreach ($order as $backend) {
                $start = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $renderer->render($rows, $backend === 'rust');
                }
                $elapsed = (hrtime(true) - $start) / $iterations;
                $times[$backend][] = $elapsed;
                $observations[] = ['pair' => $sample, 'backend' => $backend, 'ns_per_batch' => $elapsed];
            }
        }
        foreach ($times as &$values) {
            sort($values);
        }
        unset($values);
        $phpMedian = $times['php'][intdiv($samples, 2)];
        $rustMedian = $times['rust'][intdiv($samples, 2)];
        $improvement = 100 * ($phpMedian - $rustMedian) / $phpMedian;
        // Predeclared useful effect: >=5% at realistic page sizes; no >5% regression at one row.
        $passed = $count === 1 ? $improvement >= -5 : $improvement >= 5;
        $allPassed = $allPassed && $passed;
        echo json_encode([
            'scenario' => $scenario, 'rows' => $count, 'samples' => $samples, 'iterations' => $iterations,
            'order_seed' => $seed, 'php_version' => PHP_VERSION, 'os' => PHP_OS_FAMILY,
            'source_sha256' => $sources, 'library_sha256' => $libraryHash,
            'candidate_backend' => $count === 1 ? 'php-single-row' : 'rust',
            'php_median_ns' => $phpMedian, 'rust_median_ns' => $rustMedian,
            'improvement_percent' => $improvement, 'gate_passed' => $passed,
            'output_sha256' => hash('sha256', implode('', $rust)), 'observations' => $observations,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
exit($allPassed ? 0 : 1);
