<?php

declare(strict_types=1);

/**
 * Diagnostic real-caller profile; no production application/configuration boot.
 * php scripts/benchmark-article-processing.php --profile --native=/absolute/libnzbyenc.so
 * [--samples=7] [--size=4096] [--output-dir=/new/directory] [--runtime-manifest=/manifest.json]
 * Every phase uses a fresh process, one untimed reference and one recorded warmup.
 * Results are diagnostic timings only, not paired improvement or capacity evidence.
 */
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Yenc\DecoderFactory;
use App\Services\YencService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Tests\Fixtures\YencArticles;
use Tests\Support\YencNntpHarness;

if (! function_exists('yencBenchmarkRunChild')) {
    if (! defined('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY')) {
        define('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY', true);
    }
    require __DIR__.'/benchmark-yenc.php';
}
if (defined('NNTMUX_ARTICLE_BENCHMARK_FUNCTIONS_ONLY')) {
    return;
}

require dirname(__DIR__).'/vendor/autoload.php';
$options = getopt('', ['profile', 'native:', 'size:', 'samples:', 'worker:', 'fixture-path:', 'output-dir:', 'timeout:', 'runtime-manifest:', 'baseline-dir:', 'result-output:']);
try {
    $validated = articleBenchmarkOptions($options);
    if (isset($options['baseline-dir'])) {
        foreach (articleBenchmarkBaselinePaths() as $path) {
            $source = rtrim((string) $options['baseline-dir'], '/').'/'.$path;
            if (! is_file($source)) {
                throw new InvalidArgumentException('Missing baseline source: '.$source);
            }
            require $source;
        }
    }
    if (isset($options['worker'])) {
        $fixture = unserialize((string) file_get_contents((string) ($options['fixture-path'] ?? '')), ['allowed_classes' => false]);
        if (! is_array($fixture) || ! isset($fixture['data'], $fixture['body'], $fixture['headers'], $fixture['wire'])) {
            throw new InvalidArgumentException('Worker requires a frozen article fixture.');
        }
        $result = articleBenchmarkWorker((string) $options['worker'], $fixture, $validated['native'], $validated['samples'], isset($options['result-output']) ? (string) $options['result-output'] : null);
        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        exit($result['status'] === 'ok' ? 0 : 2);
    }
    if (! isset($options['profile'])) {
        throw new InvalidArgumentException('Choose --profile; paired comparisons are not implemented by this diagnostic harness.');
    }
    exit(articleBenchmarkProfile($options, $validated));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}

/**
 * Preserve the CLI settings whose effects are recorded in the parent manifest.
 *
 * @return list<string>
 */
function articleBenchmarkPhpCommand(): array
{
    $command = [PHP_BINARY];
    foreach (['ffi.enable', 'memory_limit', 'opcache.enable_cli', 'opcache.jit', 'pcre.jit'] as $setting) {
        $value = ini_get($setting);
        if ($value !== false) {
            $command[] = '-d';
            $command[] = $setting.'='.$value;
        }
    }

    return $command;
}

/** @return list<string> */
function articleBenchmarkPhases(): array
{
    return ['full-analyzer', 'framing', 'headers', 'body-metadata', 'relationship', 'raw-body', 'whole-body'];
}

/** @return list<string> */
function articleBenchmarkBaselinePaths(): array
{
    return ['app/Services/NNTP/NNTPService.php', 'app/Services/Releases/Fingerprint/NativeMetadataParser.php',
        'app/Services/Releases/Fingerprint/NzbFingerprintAnalyzer.php'];
}

/**
 * @param  array<string, mixed>  $options
 * @return array{native: string, samples: int, sizes: list<int>, timeout: float}
 */
function articleBenchmarkOptions(array $options): array
{
    $native = $options['native'] ?? '';
    if (! is_string($native) || $native === '') {
        throw new InvalidArgumentException('An explicit --native library is required; no PHP fallback is permitted.');
    }
    $samples = filter_var($options['samples'] ?? 7, FILTER_VALIDATE_INT);
    $size = filter_var($options['size'] ?? 4096, FILTER_VALIDATE_INT);
    $timeout = filter_var($options['timeout'] ?? 30, FILTER_VALIDATE_FLOAT);
    if ($samples === false || $samples < 1 || $samples > 1000) {
        throw new InvalidArgumentException('Samples must be an integer between 1 and 1000.');
    }
    if ($size === false || $size < 1 || $size > 1048576) {
        throw new InvalidArgumentException('Size must be an integer between 1 and 1048576 bytes (including heavy escaping within the metadata budget).');
    }
    if ($timeout === false || ! is_finite($timeout) || $timeout <= 0 || $timeout > 3600) {
        throw new InvalidArgumentException('Timeout must be positive and no greater than 3600 seconds.');
    }

    return ['native' => $native, 'samples' => $samples, 'sizes' => isset($options['size']) ? [$size] : [4096, 524288, 1048576], 'timeout' => $timeout];
}

/** @return array{data: string, body: string, headers: list<string>, wire: string} */
function articleBenchmarkFixture(int $size, string $density): array
{
    if ($size < 1 || $size > 1048576 || ! in_array($density, ['ordinary', 'heavy'], true)) {
        throw new InvalidArgumentException('Invalid fixture size or density.');
    }
    // Three of four bytes escape; even the maximum fixture stays below 2 MB.
    $seed = $density === 'heavy' ? "\xd6\xe0\xe3A" : YencArticles::bytes();
    $data = substr(str_repeat($seed, (int) ceil($size / strlen($seed))), 0, $size);
    $body = YencArticles::article($data)."\r\n";
    $headers = [
        "From: Fixture Poster <fixture@example.test>\r\n",
        "Subject: fixture file.bin yEnc (1/1)\r\n",
        "Date: Wed, 14 Oct 2026 12:00:00 +0000\r\n",
        "Message-ID: <fixture@example.test>\r\n",
        "Newsgroups: alt.test\r\n",
        'Lines: '.substr_count($body, "\n")."\r\n",
    ];
    $wire = (preg_replace('/^\./m', '..', $body) ?? throw new RuntimeException('Dot stuffing failed.')).".\r\n";

    return ['data' => $data, 'body' => $body, 'headers' => $headers, 'wire' => $wire];
}

function articleBenchmarkResultHash(mixed $result): string
{
    return hash('sha256', serialize($result));
}

/**
 * @param  array{data: string, body: string, headers: list<string>, wire: string}  $fixture
 * @return array<string, mixed>
 */
function articleBenchmarkWorker(string $phase, array $fixture, string $native, int $samples, ?string $resultOutput = null): array
{
    if (! in_array($phase, articleBenchmarkPhases(), true)) {
        throw new InvalidArgumentException('Unknown worker phase: '.$phase);
    }
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['yenc' => ['native_library' => $native]]));
    Container::setInstance($container);
    try {
        return articleBenchmarkMeasure($phase, $fixture, $native, $samples, $resultOutput);
    } finally {
        Container::setInstance($previous);
    }
}

/**
 * @param  array{data: string, body: string, headers: list<string>, wire: string}  $fixture
 * @return array<string, mixed>
 */
function articleBenchmarkMeasure(string $phase, array $fixture, string $native, int $samples, ?string $resultOutput = null): array
{
    $service = new YencService((new DecoderFactory)->make('native', $native));
    $analyzer = new NzbFingerprintAnalyzer($service);
    $parser = new NativeMetadataParser;
    $headers = implode('', $fixture['headers']);
    $stream = fopen('php://memory', 'w+');
    if ($stream === false) {
        throw new RuntimeException('Cannot create isolated BODY stream.');
    }
    fwrite($stream, $fixture['wire']);
    $harness = new YencNntpHarness($stream, $service);
    $readRaw = Closure::bind(static fn (NNTPService $nntp): mixed => $nntp->readRawBody(), null, NNTPService::class);
    if ($readRaw === null) {
        throw new RuntimeException('Cannot bind real raw BODY caller.');
    }
    $call = static fn (): mixed => match ($phase) {
        'full-analyzer' => $analyzer->analyzeArticleAndDecodedBytes($fixture['headers'], $fixture['body']),
        'framing' => $parser->parseYencFraming($fixture['body']),
        'headers' => $parser->parseNntpHeaders($headers),
        'body-metadata' => $parser->parseNntpBody($fixture['body']),
        'relationship' => $parser->compareNntpLinesToBody($headers, $fixture['body']),
        'raw-body' => $readRaw($harness),
        'whole-body' => $harness->fetch(),
        default => throw new InvalidArgumentException('Unknown worker phase: '.$phase),
    };
    $observations = [];
    $times = [];
    try {
        rewind($stream);
        $reference = $call();
        $referenceValid = articleBenchmarkCorrect($phase, $reference, $fixture);
        $referenceHash = articleBenchmarkResultHash($reference);
        // Retain exact serialized metadata; hashes and comparisons preserve keys, types and order.
        $referenceMetadata = in_array($phase, ['whole-body', 'raw-body'], true) ? null
            : serialize($phase === 'full-analyzer' ? $reference['metadata'] ?? null : $reference);
        if ($resultOutput !== null && $referenceMetadata !== null) {
            $output = fopen($resultOutput, 'xb');
            if ($output === false) {
                throw new RuntimeException('Result artifact must be new: '.$resultOutput);
            }
            try {
                if (fwrite($output, $referenceMetadata) !== strlen($referenceMetadata)) {
                    throw new RuntimeException('Unable to write exact metadata artifact.');
                }
            } finally {
                fclose($output);
            }
        }
        $referenceMetadataHash = $referenceMetadata === null ? null : hash('sha256', $referenceMetadata);
        unset($referenceMetadata);
        unset($reference);
        for ($sample = -1; $sample < $samples; $sample++) {
            rewind($stream);
            $harness->commands = [];
            $cpuBefore = getrusage();
            $started = hrtime(true);
            $result = $call();
            $elapsed = (hrtime(true) - $started) / 1e6;
            $cpuAfter = getrusage();
            $hash = articleBenchmarkResultHash($result);
            $correct = $referenceValid && $hash === $referenceHash && articleBenchmarkCorrect($phase, $result, $fixture);
            $decoded = $phase === 'full-analyzer' ? ($result['decoded_bytes'] ?? null) : ($phase === 'whole-body' ? $result : null);
            $observations[] = ['sample' => $sample, 'warmup' => $sample < 0, 'elapsed_ms' => $elapsed,
                'process_cpu_before' => $cpuBefore, 'process_cpu_after' => $cpuAfter,
                'correct' => $correct, 'result_sha256' => $hash,
                'decoded_sha256' => is_string($decoded) ? hash('sha256', $decoded) : null];
            unset($result, $decoded);
            if ($sample >= 0) {
                $times[] = $elapsed;
            }
        }
        $valid = ! in_array(false, array_column($observations, 'correct'), true);

        // Decoded data has its own independently generated fixture hash; avoid duplicating its bytes in JSON.
        return ['status' => $valid ? 'ok' : 'incorrect', 'evidence' => 'diagnostic_only', 'phase' => $phase,
            'median_ms' => yencBenchmarkMedian($times), 'min_ms' => min($times), 'max_ms' => max($times),
            'observations' => $observations, 'reference_sha256' => $referenceHash,
            'reference_metadata_sha256' => $referenceMetadataHash, 'reference_metadata_path' => $referenceMetadataHash === null ? null : $resultOutput,
            'decoded_fixture_sha256' => hash('sha256', $fixture['data']),
            'library_sha256' => hash_file('sha256', $native), 'decoder_version' => $service->decoderVersion(),
            'php_peak_bytes' => memory_get_peak_usage(true)];
    } finally {
        fclose($stream);
    }
}

/**
 * Repetition stability is checked separately. Metadata validity is presence of
 * the actual native result, not a complete independent metadata oracle.
 *
 * @param  array{data: string, body: string, headers: list<string>, wire: string}  $fixture
 */
function articleBenchmarkCorrect(string $phase, mixed $result, array $fixture): bool
{
    return match ($phase) {
        'full-analyzer' => is_array($result) && ($result['decoded_bytes'] ?? null) === $fixture['data']
            && is_array($result['metadata'] ?? null)
            && NativeMetadataParser::hasCurrentNntpHeaderSchema($result['metadata']['nntp_header_metadata'] ?? null)
            && ($result['metadata']['nntp_body_metadata']['body_line_count'] ?? null) === substr_count($fixture['body'], "\n")
            && ($result['metadata']['nntp_lines_body_relationship']['matches'] ?? null) === true
            && is_array($result['metadata']['yenc_framing_metadata'] ?? null),
        'raw-body' => $result === $fixture['body'],
        'whole-body' => $result === $fixture['data'],
        'headers' => is_array($result) && NativeMetadataParser::hasCurrentNntpHeaderSchema($result),
        'body-metadata' => is_array($result) && ($result['body_line_count'] ?? null) === substr_count($fixture['body'], "\n"),
        'relationship' => is_array($result) && ($result['matches'] ?? null) === true,
        'framing' => is_array($result) && $result !== [],
        default => false,
    };
}

/** @return array<string, string|false> */
function articleBenchmarkSourceHashes(string $root): array
{
    $hashes = [];
    $paths = array_merge(articleBenchmarkBaselinePaths(), ['scripts/benchmark-article-processing.php', 'scripts/benchmark-yenc.php',
        'app/Services/YencService.php', 'app/Services/Yenc/NativePayloadDecoder.php', 'app/Services/Yenc/ArticleFrame.php',
        'app/Services/Yenc/DecoderFactory.php', 'tests/Fixtures/YencArticles.php', 'tests/Support/YencNntpHarness.php',
        'composer.lock', 'vendor/composer/installed.json']);
    foreach ($paths as $path) {
        $hashes[$path] = is_file($root.'/'.$path) ? hash_file('sha256', $root.'/'.$path) : false;
    }

    return $hashes;
}

/**
 * @param  array<string, mixed>  $options
 * @param  array{native: string, samples: int, sizes: list<int>, timeout: float}  $validated
 */
function articleBenchmarkProfile(array $options, array $validated): int
{
    $directory = (string) ($options['output-dir'] ?? sys_get_temp_dir().'/nntmux-article-profile-'.bin2hex(random_bytes(8)));
    if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new: '.$directory);
    }
    $write = static function (array $row) use ($directory): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents($directory.'/observations.jsonl', $line, FILE_APPEND);
        echo $line;
    };
    $write(['type' => 'protocol', 'evidence' => 'diagnostic_only', 'samples' => $validated['samples'], 'warmups' => 1,
        'untimed_reference_calls' => 1, 'sizes' => $validated['sizes'], 'densities' => ['ordinary', 'heavy'],
        'phases' => articleBenchmarkPhases(), 'timeout_seconds' => $validated['timeout'], 'output_dir' => $directory,
        'library_sha256' => is_file($validated['native']) ? hash_file('sha256', $validated['native']) : null,
        'sources' => articleBenchmarkSourceHashes(dirname(__DIR__)),
        'baseline_sources' => isset($options['baseline-dir']) ? articleBenchmarkSourceHashes((string) $options['baseline-dir']) : null,
        'runtime' => ['php' => PHP_VERSION, 'os' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'),
            'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'), 'ffi' => ini_get('ffi.enable'),
            'extensions' => get_loaded_extensions(), 'fileinfo' => extension_loaded('fileinfo')],
        'runtime_manifest' => isset($options['runtime-manifest']) ? json_decode((string) file_get_contents((string) $options['runtime-manifest']), true, flags: JSON_THROW_ON_ERROR) : null]);
    $invalid = 0;
    foreach ($validated['sizes'] as $size) {
        foreach (['ordinary', 'heavy'] as $density) {
            $fixture = articleBenchmarkFixture($size, $density);
            $fixturePath = $directory.'/fixture-'.$size.'-'.$density.'.serialized';
            file_put_contents($fixturePath, serialize($fixture));
            $write(['type' => 'fixture', 'size' => $size, 'density' => $density, 'path' => $fixturePath,
                'serialized_sha256' => hash_file('sha256', $fixturePath), 'decoded_sha256' => hash('sha256', $fixture['data']),
                'body_sha256' => hash('sha256', $fixture['body']), 'headers_sha256' => hash('sha256', serialize($fixture['headers'])),
                'wire_sha256' => hash('sha256', $fixture['wire']), 'body_bytes' => strlen($fixture['body'])]);
            unset($fixture);
            foreach (articleBenchmarkPhases() as $phase) {
                $command = array_merge(articleBenchmarkPhpCommand(), [__FILE__, '--worker='.$phase, '--native='.$validated['native'],
                    '--samples='.$validated['samples'], '--fixture-path='.$fixturePath,
                    '--result-output='.$directory.'/'.$size.'-'.$density.'-'.$phase.'.metadata.serialized']);
                if (isset($options['baseline-dir'])) {
                    $command[] = '--baseline-dir='.$options['baseline-dir'];
                }
                $child = yencBenchmarkRunChild($command, $directory.'/'.$size.'-'.$density.'-'.$phase, $validated['timeout']);
                $invalid += $child['status'] === 'ok' ? 0 : 1;
                $write(['type' => 'observation', 'size' => $size, 'density' => $density, 'phase' => $phase] + $child);
            }
        }
    }
    $write(['type' => 'summary', 'status' => $invalid === 0 ? 'ok' : 'invalid', 'invalid_observations' => $invalid,
        'evidence' => 'diagnostic_only', 'performance_advantage_demonstrated' => false]);

    return $invalid === 0 ? 0 : 2;
}
