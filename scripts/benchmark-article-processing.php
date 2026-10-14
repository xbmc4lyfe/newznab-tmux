<?php

declare(strict_types=1);

/**
 * Real-caller profile and paired comparison; no production application/configuration boot.
 * php scripts/benchmark-article-processing.php --profile --native=/absolute/libnzbyenc.so
 * [--samples=7] [--size=4096] [--output-dir=/new/directory] [--runtime-manifest=/manifest.json]
 * Every phase uses a fresh process, one untimed reference and one recorded warmup.
 * Profile results are diagnostic only. Paired mode uses --paired --baseline-dir=ROOT
 * --baseline-native=LIB --native=LIB [--samples=31] [--order-seed=20261014].
 * [--candidate-dir=ROOT] selects an independently installed app graph.
 * [--require-combined] qualifies the candidate API in a separate process per fixture.
 * Paired mode currently screens median speedups only; p99/adoption is unqualified.
 */
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Yenc\ArticleFrame;
use App\Services\Yenc\DecoderFactory;
use App\Services\Yenc\NativePayloadDecoder;
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

$options = getopt('', ['profile', 'paired', 'regression-only', 'min-speedup:', 'baseline-native:', 'order-seed:', 'require-combined', 'native:', 'size:', 'samples:', 'worker:', 'fixture-path:', 'output-dir:', 'timeout:', 'runtime-manifest:', 'baseline-dir:', 'candidate-dir:', 'source-dir:', 'result-output:']);
try {
    $validated = articleBenchmarkOptions($options);
    $sourceRoot = realpath((string) (isset($options['worker'])
        ? ($options['source-dir'] ?? $options['candidate-dir'] ?? $options['baseline-dir'] ?? dirname(__DIR__))
        : dirname(__DIR__))) ?: throw new InvalidArgumentException('Selected source directory does not exist.');
    $autoloadPath = realpath($sourceRoot.'/vendor/autoload.php');
    if ($autoloadPath === false || ! str_starts_with($autoloadPath, $sourceRoot.'/') || ! is_file($autoloadPath)) {
        throw new InvalidArgumentException('Selected source directory needs its own installed Composer autoloader.');
    }
    chdir($sourceRoot);
    require $autoloadPath;
    if (($options['worker'] ?? null) === 'inspect-sources') {
        $started = hrtime(true);
        $runtimeSources = articleBenchmarkRuntimeSources($sourceRoot);
        echo json_encode(['status' => 'ok', 'evidence' => 'runtime_inspection',
            'median_ms' => (hrtime(true) - $started) / 1e6, 'runtime_sources' => $runtimeSources], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if (isset($options['worker'])) {
        $fixture = unserialize((string) file_get_contents((string) ($options['fixture-path'] ?? '')), ['allowed_classes' => false]);
        if (! is_array($fixture) || ! isset($fixture['data'], $fixture['body'], $fixture['headers'], $fixture['wire'])) {
            throw new InvalidArgumentException('Worker requires a frozen article fixture.');
        }
        $result = articleBenchmarkWorker((string) $options['worker'], $fixture, $validated['native'], $validated['samples'], isset($options['result-output']) ? (string) $options['result-output'] : null, isset($options['require-combined']));
        $result['runtime_sources'] = articleBenchmarkRuntimeSources($sourceRoot);
        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        exit($result['status'] === 'ok' ? 0 : 2);
    }
    if (isset($options['paired'])) {
        exit(articleBenchmarkPaired($options, $validated));
    }
    if (! isset($options['profile'])) {
        throw new InvalidArgumentException('Choose --profile or --paired.');
    }
    exit(articleBenchmarkProfile($options, $validated));
} catch (Throwable $exception) {
    if (isset($options['worker'], $sourceRoot)) {
        try {
            $runtime = articleBenchmarkRuntimeSources($sourceRoot);
        } catch (Throwable $runtimeError) {
            $runtime = ['error' => $runtimeError->getMessage()];
        }
        echo json_encode(['status' => 'invalid', 'error' => $exception->getMessage(), 'runtime_sources' => $runtime], JSON_THROW_ON_ERROR).PHP_EOL;
    }
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
 * @return array{native: string, samples: int, sizes: list<int>, timeout: float, min_speedup: float}
 */
function articleBenchmarkOptions(array $options): array
{
    if (isset($options['paired']) && isset($options['size'])) {
        throw new InvalidArgumentException('Paired mode uses the complete predeclared six-stratum family.');
    }
    if (isset($options['paired'], $options['profile'])) {
        throw new InvalidArgumentException('Choose one benchmark mode.');
    }
    $native = $options['native'] ?? '';
    if (! is_string($native) || $native === '') {
        throw new InvalidArgumentException('An explicit --native library is required; no PHP fallback is permitted.');
    }
    $samples = filter_var($options['samples'] ?? (isset($options['paired']) ? 31 : 7), FILTER_VALIDATE_INT);
    $size = filter_var($options['size'] ?? 4096, FILTER_VALIDATE_INT);
    $timeout = filter_var($options['timeout'] ?? 30, FILTER_VALIDATE_FLOAT);
    $maximumSamples = isset($options['paired']) ? 1000 : 64;
    if ($samples === false || $samples < 1 || $samples > $maximumSamples) {
        throw new InvalidArgumentException('Samples must be an integer between 1 and '.$maximumSamples.'.');
    }
    if ($size === false || $size < 1 || $size > 1048576) {
        throw new InvalidArgumentException('Size must be an integer between 1 and 1048576 bytes (including heavy escaping within the metadata budget).');
    }
    if ($timeout === false || ! is_finite($timeout) || $timeout <= 0 || $timeout > 3600) {
        throw new InvalidArgumentException('Timeout must be positive and no greater than 3600 seconds.');
    }

    $minSpeedup = filter_var($options['min-speedup'] ?? 1.5, FILTER_VALIDATE_FLOAT);
    if ($minSpeedup === false || ! is_finite($minSpeedup) || $minSpeedup < 1.5 || $minSpeedup > 100) {
        throw new InvalidArgumentException('Minimum speedup must be at least 1.5 and no greater than 100.');
    }

    return ['min_speedup' => $minSpeedup, 'native' => $native, 'samples' => $samples, 'sizes' => isset($options['size']) ? [$size] : [4096, 524288, 1048576], 'timeout' => $timeout];
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
function articleBenchmarkWorker(string $phase, array $fixture, string $native, int $samples, ?string $resultOutput = null, bool $requireCombined = false): array
{
    if (! in_array($phase, [...articleBenchmarkPhases(), 'qualify-combined'], true)) {
        throw new InvalidArgumentException('Unknown worker phase: '.$phase);
    }
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['yenc' => ['native_library' => $native]]));
    Container::setInstance($container);
    try {
        if ($phase === 'qualify-combined') {
            (new DecoderFactory)->make('native', $native);
            $started = hrtime(true);
            articleBenchmarkQualifyCombined(new NativeMetadataParser, implode('', $fixture['headers']), $fixture['body']);

            return ['status' => 'ok', 'evidence' => 'qualification_only', 'median_ms' => (hrtime(true) - $started) / 1e6,
                'combined_available' => true, 'library_sha256' => hash_file('sha256', $native),
                'fixture_sha256' => articleBenchmarkResultHash($fixture)];
        }
        if ($requireCombined) {
            throw new InvalidArgumentException('Combined qualification must run in its own qualify-combined worker.');
        }

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
        $referenceCpuBefore = getrusage();
        $referenceStarted = hrtime(true);
        $reference = $call();
        $referenceElapsed = (hrtime(true) - $referenceStarted) / 1e6;
        $referenceCpuAfter = getrusage();
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
        $bodyBridge = [];
        if ($phase === 'body-metadata') {
            $library = (new ReflectionMethod($parser, 'bodyLibrary'))->invoke($parser);
            if (! $library instanceof FFI) {
                throw new RuntimeException('Standalone body metadata library handle is unavailable.');
            }
            for ($sample = -1; $sample < $samples; $sample++) {
                $bridge = articleBenchmarkBodyBridge($library, $fixture['body']);
                $bridgeHash = articleBenchmarkResultHash($bridge['metadata']);
                $bridgeCorrect = $bridgeHash === $referenceHash;
                $valid = $valid && $bridgeCorrect;
                $bodyBridge[] = ['sample' => $sample, 'warmup' => $sample < 0, 'evidence' => 'diagnostic_only',
                    'bridge' => 'legacy_standalone_parseNntpBody_allocate_copy', 'correct' => $bridgeCorrect,
                    'result_sha256' => $bridgeHash, 'stages_ms' => $bridge['stages_ms']];
            }
        }

        // Decoded data has its own independently generated fixture hash; avoid duplicating its bytes in JSON.
        return ['status' => $valid ? 'ok' : 'incorrect', 'evidence' => 'diagnostic_only', 'phase' => $phase,
            'cold_reference' => ['elapsed_ms' => $referenceElapsed, 'correct' => $referenceValid,
                'process_cpu_before' => $referenceCpuBefore, 'process_cpu_after' => $referenceCpuAfter],
            'combined_required' => false, 'combined_available' => false,
            'median_ms' => yencBenchmarkMedian($times), 'min_ms' => min($times), 'max_ms' => max($times),
            'observations' => $observations, 'body_ffi_breakdown' => $bodyBridge, 'reference_sha256' => $referenceHash,
            'reference_metadata_sha256' => $referenceMetadataHash, 'reference_metadata_path' => $referenceMetadataHash === null ? null : $resultOutput,
            'decoded_fixture_sha256' => hash('sha256', $fixture['data']),
            'library_sha256' => hash_file('sha256', $native), 'decoder_version' => $service->decoderVersion(),
            'php_peak_bytes' => memory_get_peak_usage(true)];
    } finally {
        fclose($stream);
    }
}

/** Require the requested candidate API to exist and return all four metadata arrays. */
function articleBenchmarkQualifyCombined(object $parser, string $headers, string $body): void
{
    $parserReflection = new ReflectionClass($parser);
    if (! $parserReflection->hasMethod('parseArticleMetadata')) {
        throw new RuntimeException('Candidate requires the combined article metadata ABI.');
    }
    $combined = $parserReflection->getMethod('parseArticleMetadata');
    if (! $combined->isPublic()) {
        throw new RuntimeException('Candidate combined article metadata method must be public.');
    }
    $combinedResult = $combined->invoke($parser, $headers, $body);
    if (! is_array($combinedResult)
        || array_keys($combinedResult) !== ['yenc_framing_metadata', 'nntp_header_metadata', 'nntp_body_metadata', 'nntp_lines_body_relationship']
        || ! array_all($combinedResult, static fn (mixed $value): bool => is_array($value))) {
        throw new RuntimeException('Candidate combined article metadata ABI returned unavailable or invalid metadata.');
    }
}

/** @return list<string> */
function articleBenchmarkPairOptions(string $backend, string $baselineSnapshot, bool $requireCombined): array
{
    return ['--source-dir='.$baselineSnapshot];
}

/** Call a symbol from the qualified FFI declaration; FFI exposes C functions dynamically. */
function articleBenchmarkNativeCall(FFI $library, string $function, mixed ...$arguments): mixed
{
    return $library->{$function}(...$arguments);
}

/**
 * Mirror the actual legacy standalone body bridge through its already qualified
 * FFI handle. Diagnostic stage clocks run after all normal caller samples.
 *
 * @return array{metadata: array<string, mixed>|null, stages_ms: array<string, float>}
 */
function articleBenchmarkBodyBridge(FFI $library, string $body): array
{
    $started = hrtime(true);
    $length = strlen($body);
    $buffer = $library->new($length > 0 ? 'unsigned char['.$length.']' : 'unsigned char[1]');
    if ($length > 0) {
        FFI::memcpy($buffer, $body, $length);
    }
    $stages = ['allocate_and_memcpy' => (hrtime(true) - $started) / 1e6];
    $started = hrtime(true);
    $function = 'nntmux_nntp_body_measurement_json';
    $pointer = articleBenchmarkNativeCall($library, $function, $buffer, $length);
    $stages['native_parse_and_serialize'] = (hrtime(true) - $started) / 1e6;
    if (FFI::isNull($pointer)) {
        $stages['ffi_string_and_free'] = 0.0;
        $stages['json_decode'] = 0.0;

        return ['metadata' => null, 'stages_ms' => $stages];
    }
    $started = hrtime(true);
    try {
        $encoded = FFI::string($pointer);
    } finally {
        $free = 'nntmux_metadata_string_free';
        articleBenchmarkNativeCall($library, $free, $pointer);
    }
    $stages['ffi_string_and_free'] = (hrtime(true) - $started) / 1e6;
    $started = hrtime(true);
    $decoded = json_decode($encoded, true, 512, JSON_BIGINT_AS_STRING);
    $stages['json_decode'] = (hrtime(true) - $started) / 1e6;

    return ['metadata' => is_array($decoded) ? $decoded : null, 'stages_ms' => $stages];
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
    foreach (['app/Services/Yenc', 'docker/app/nzb-yenc-ffi/src'] as $directory) {
        if (is_dir($root.'/'.$directory)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $paths[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
    }
    $paths[] = 'docker/app/nzb-yenc-ffi/Cargo.toml';
    $paths[] = 'docker/app/nzb-yenc-ffi/Cargo.lock';
    $paths = array_values(array_unique($paths));
    sort($paths);
    foreach ($paths as $path) {
        $hashes[$path] = is_file($root.'/'.$path) ? hash_file('sha256', $root.'/'.$path) : false;
    }

    return $hashes;
}

/**
 * @param  array<string, mixed>  $options
 * @param  array{native: string, samples: int, sizes: list<int>, timeout: float, min_speedup: float}  $validated
 */
function articleBenchmarkProfile(array $options, array $validated): int
{
    $directory = (string) ($options['output-dir'] ?? sys_get_temp_dir().'/nntmux-article-profile-'.bin2hex(random_bytes(8)));
    if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new: '.$directory);
    }
    $directory = realpath($directory) ?: throw new RuntimeException('Cannot resolve evidence directory.');
    $write = static function (array $row) use ($directory): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents($directory.'/observations.jsonl', $line, FILE_APPEND);
        echo $line;
    };
    try {
        $manifest = isset($options['runtime-manifest']) ? articleBenchmarkReadManifest((string) $options['runtime-manifest']) : null;
        $root = realpath((string) ($options['candidate-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Source directory does not exist.');
        $sources = articleBenchmarkSourceHashes($root);
        $library = articleBenchmarkFreezeLibrary($validated['native'], $directory.'/native.so');
        $snapshot = articleBenchmarkFreezeGraph($root, $directory.'/source');
        file_put_contents($directory.'/source-manifest.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $write(['type' => 'protocol', 'evidence' => 'diagnostic_only', 'samples' => $validated['samples'], 'warmups' => 1,
            'untimed_reference_calls' => 1, 'sizes' => $validated['sizes'], 'densities' => ['ordinary', 'heavy'],
            'phases' => articleBenchmarkPhases(), 'timeout_seconds' => $validated['timeout'], 'output_dir' => $directory,
            'library' => $library, 'sources' => $sources, 'source_manifest_path' => $directory.'/source-manifest.json',
            'instrumentation' => articleBenchmarkInstrumentationHashes(),
            'runtime' => ['php' => PHP_VERSION, 'os' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'),
                'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'), 'ffi' => ini_get('ffi.enable'),
                'extensions' => get_loaded_extensions(), 'fileinfo' => extension_loaded('fileinfo')],
            'runtime_manifest' => $manifest]);
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
                    $command = array_merge(articleBenchmarkPhpCommand(), [__FILE__, '--worker='.$phase, '--native='.$library['path'],
                        '--samples='.$validated['samples'], '--fixture-path='.$fixturePath, '--source-dir='.$snapshot['root'],
                        '--result-output='.$directory.'/'.$size.'-'.$density.'-'.$phase.'.metadata.serialized']);
                    $child = yencBenchmarkRunChild($command, $directory.'/'.$size.'-'.$density.'-'.$phase, $validated['timeout']);
                    $eligible = $child['status'] === 'ok' && ($child['result']['library_sha256'] ?? null) === $library['sha256']
                        && articleBenchmarkRuntimeMatches($child, $snapshot);
                    $invalid += $eligible ? 0 : 1;
                    $write(['type' => 'observation', 'size' => $size, 'density' => $density, 'phase' => $phase, 'eligible' => $eligible, 'command' => $command] + $child);
                }
            }
        }
        $stable = articleBenchmarkGraphStable($snapshot) && articleBenchmarkGraphStable($snapshot, $root)
            && hash_file('sha256', $library['path']) === $library['sha256'] && articleBenchmarkSourceHashes($root) === $sources;
        $write(['type' => 'summary', 'status' => $invalid === 0 && $stable ? 'ok' : 'invalid', 'invalid_observations' => $invalid,
            'source_and_library_identities_stable' => $stable, 'evidence' => 'diagnostic_only', 'performance_advantage_demonstrated' => false]);

        return $invalid === 0 && $stable ? 0 : 2;
    } catch (Throwable $exception) {
        $write(['type' => 'failure', 'status' => 'invalid', 'phase' => 'setup_or_run', 'exception' => $exception::class, 'reason' => $exception->getMessage()]);
        $write(['type' => 'summary', 'status' => 'invalid', 'evidence' => 'diagnostic_only', 'performance_advantage_demonstrated' => false]);

        return 2;
    }
}

/**
 * @param  list<float>  $ratios
 * @return array<string, mixed>
 */
function articleBenchmarkScreenStratum(array $ratios, int $invalidPairs, int $seed, float $minSpeedup): array
{
    $decision = yencBenchmarkDecision($ratios, $invalidPairs, 100 * (1 - 1 / $minSpeedup), 5, $seed, 6, 'baseline');
    if ($decision['decision'] !== 'invalid') {
        [$lower, $upper] = $decision['bootstrap_95_percent_interval'];
        $decision['decision'] = $lower > 1.05 ? 'regression' : ($upper <= 1 / $minSpeedup ? 'useful_improvement' : 'inconclusive');
        $decision['paired_median_latency_speedup'] = 1 / $decision['median_rust_over_baseline'];
        $decision['latency_speedup_95_percent_interval'] = [1 / $upper, 1 / $lower];
    }
    $decision['min_latency_speedup'] = $minSpeedup;
    $decision['evidence'] = 'paired_median_screen';
    $decision['adoption_not_qualified_p99_missing'] = true;

    return $decision;
}

/**
 * @param  list<array<string, mixed>>  $strata
 * @return array<string, mixed>
 */
function articleBenchmarkPairedDecision(array $strata, bool $identitiesStable, int $pairs, bool $regressionOnly = false): array
{
    $family = [];
    foreach ($strata as $stratum) {
        $family[] = ($stratum['size'] ?? '').'-'.($stratum['density'] ?? '');
    }
    sort($family);
    $expected = ['1048576-heavy', '1048576-ordinary', '4096-heavy', '4096-ordinary', '524288-heavy', '524288-ordinary'];
    $invalid = ! $identitiesStable || $pairs < 31 || $family !== $expected
        || array_any($strata, static fn (array $row): bool => ($row['decision'] ?? 'invalid') === 'invalid');
    $regressed = array_any($strata, static fn (array $row): bool => ($row['decision'] ?? null) === 'regression');
    $targetsImproved = array_all($strata, static fn (array $row): bool => ($row['size'] ?? 0) === 4096
        || ($row['decision'] ?? null) === 'useful_improvement');
    $advantage = ! $invalid && ! $regressed && $targetsImproved;

    return ['type' => 'run_summary', 'status' => $invalid ? 'invalid' : ($regressed ? 'regression' : ($advantage ? 'useful_improvement' : 'inconclusive')),
        'comparison_mode' => $regressionOnly ? 'regression_only' : 'median_screen',
        'acceptance_criteria_met' => ! $invalid && ! $regressed && ($regressionOnly || $advantage),
        'evidence' => 'paired_median_screen', 'adoption_not_qualified_p99_missing' => true,
        'all_strata_median_criterion_met' => ! $invalid && array_all($strata, static fn (array $row): bool => ($row['decision'] ?? null) === 'useful_improvement'),
        'screen_median_criterion_met' => $advantage, 'performance_advantage_demonstrated' => false, 'source_library_and_fixture_identities_stable' => $identitiesStable,
        'pairs_per_stratum' => $pairs, 'strata' => count($strata),
        'claim_limit' => 'Paired median screening of warm full PHP analyzer for fixed synthetic fixtures; p99 is unmeasured, so Rust adoption is not qualified and no production capacity is proved.'];
}

/**
 * @param  array<string, mixed>  $child
 */
function articleBenchmarkPairedEligible(array $child, string $decodedHash, string $libraryHash, string $metadataPath, bool $requireCombined): bool
{
    $result = $child['result'] ?? null;
    if (($child['status'] ?? null) !== 'ok' || ! is_array($result)
        || ($result['library_sha256'] ?? null) !== $libraryHash
        || ($result['decoded_fixture_sha256'] ?? null) !== $decodedHash
        || ($result['cold_reference']['correct'] ?? false) !== true
        || ($result['reference_metadata_path'] ?? null) !== $metadataPath
        || ! is_file($metadataPath)
        || ($result['reference_metadata_sha256'] ?? null) !== hash_file('sha256', $metadataPath)
        || ($requireCombined && (($result['combined_required'] ?? false) !== true || ($result['combined_available'] ?? false) !== true))) {
        return false;
    }
    $observations = $result['observations'] ?? null;
    if (! is_array($observations) || count($observations) !== 2 || array_column($observations, 'sample') !== [-1, 0]) {
        return false;
    }

    return array_all($observations, static fn (mixed $observation): bool => is_array($observation)
        && ($observation['correct'] ?? false) === true
        && ($observation['result_sha256'] ?? null) === ($result['reference_sha256'] ?? null)
        && ($observation['decoded_sha256'] ?? null) === $decodedHash);
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  array<string, array{path: string, sha256: string|false}>  $libraries
 * @param  array<string, array<string, string|false>>  $sources
 */
function articleBenchmarkVerifyManifest(array $manifest, array $libraries, array $sources): void
{
    foreach (['baseline', 'candidate'] as $backend) {
        if (($manifest[$backend.'_library_sha256'] ?? null) !== $libraries[$backend]['sha256']) {
            throw new RuntimeException('Build manifest does not match frozen '.$backend.' library.');
        }
        $sourceMap = $manifest[$backend.'_source_sha256'] ?? null;
        if (! is_array($sourceMap) || $sourceMap === []) {
            throw new RuntimeException('Build manifest requires '.$backend.'_source_sha256.');
        }
        foreach ($sourceMap as $path => $digest) {
            if (! is_string($digest) || ($sources[$backend][$path] ?? null) !== $digest) {
                throw new RuntimeException('Source changed since recorded '.$backend.' build: '.$path);
            }
        }
    }
}

/**
 * @param  array<string, mixed>  $options
 * @param  array{native: string, samples: int, sizes: list<int>, timeout: float, min_speedup: float}  $validated
 */
function articleBenchmarkPaired(array $options, array $validated): int
{
    $seed = filter_var($options['order-seed'] ?? 20261014, FILTER_VALIDATE_INT);
    if ($seed === false || $seed < 1 || $seed > 2147483647) {
        throw new InvalidArgumentException('Order seed must be an integer between 1 and 2147483647.');
    }
    $directory = (string) ($options['output-dir'] ?? sys_get_temp_dir().'/nntmux-article-paired-'.bin2hex(random_bytes(8)));
    if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new: '.$directory);
    }
    $directory = realpath($directory) ?: throw new RuntimeException('Cannot resolve output directory.');
    $emit = static function (array $row) use ($directory): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents($directory.'/observations.jsonl', $line, FILE_APPEND);
        echo $line;
    };
    try {
        $root = realpath((string) ($options['candidate-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Candidate source directory does not exist.');
        $baselineRoot = realpath((string) ($options['baseline-dir'] ?? '')) ?: throw new InvalidArgumentException('Baseline source directory does not exist.');
        $sources = ['baseline' => articleBenchmarkSourceHashes($baselineRoot), 'candidate' => articleBenchmarkSourceHashes($root)];
        $libraries = [];
        foreach (['baseline' => $options['baseline-native'] ?? '', 'candidate' => $validated['native']] as $backend => $library) {
            if (! is_string($library)) {
                throw new InvalidArgumentException('Paired mode requires named native libraries.');
            }
            $libraries[$backend] = articleBenchmarkFreezeLibrary($library, $directory.'/'.$backend.'.so');
        }
        $snapshots = ['baseline' => articleBenchmarkFreezeGraph($baselineRoot, $directory.'/baseline-source'),
            'candidate' => articleBenchmarkFreezeGraph($root, $directory.'/candidate-source')];
        $snapshotEvidence = [];
        foreach ($snapshots as $backend => $snapshot) {
            $path = $directory.'/'.$backend.'-source-manifest.json';
            file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR));
            $snapshotEvidence[$backend] = ['root' => $snapshot['root'], 'git_head' => $snapshot['git_head'], 'git_tree' => $snapshot['git_tree'],
                'manifest_path' => $path, 'manifest_sha256' => hash_file('sha256', $path)];
        }
        $runtimeManifest = null;
        if (isset($options['runtime-manifest'])) {
            $manifestPath = (string) $options['runtime-manifest'];
            $manifest = articleBenchmarkReadManifest($manifestPath);
            articleBenchmarkVerifyManifest($manifest, $libraries, $sources);
            copy($manifestPath, $directory.'/runtime-manifest.json');
            $runtimeManifest = ['path' => $directory.'/runtime-manifest.json', 'sha256' => hash_file('sha256', $manifestPath), 'contents' => $manifest];
        }
        $requireCombined = isset($options['require-combined']);
        $protocol = ['type' => 'protocol', 'version' => 'paired-article-processing-v1', 'phase' => 'full-analyzer',
            'comparison_mode' => isset($options['regression-only']) ? 'regression_only' : 'median_screen',
            'pairs_per_stratum' => $validated['samples'], 'warmups_per_worker' => 1, 'timed_samples_per_worker' => 1,
            'cold_reference_calls_per_worker' => 1, 'cold_reference_scope' => 'first real analyzer call after decoder construction; reported separately from warm comparison',
            'candidate_combined_required' => $requireCombined,
            'combined_qualification' => $requireCombined ? 'separate fresh qualification worker per fixture; measured workers have identical reference/warmup sequences' : 'not_requested',
            'sizes' => [4096, 524288, 1048576], 'densities' => ['ordinary', 'heavy'], 'confidence_family_size' => 6,
            'target_minimum_size' => 524288, 'min_latency_speedup' => $validated['min_speedup'], 'max_regression_percent' => 5,
            'evidence' => 'paired_median_screen', 'adoption_not_qualified_p99_missing' => true,
            'decision_rule' => 'Four target strata screen for simultaneous median speedup >= declared threshold; small controls may be inconclusive but no stratum may regress. Zero invalid pairs and at least 31 pairs required. No p99/adoption qualification is possible in this screen.',
            'order_seed' => $seed, 'timeout_seconds' => $validated['timeout'], 'output_dir' => $directory,
            'source_build_identity_verified' => $runtimeManifest !== null,
            'source_sha256' => $sources, 'source_snapshots' => $snapshotEvidence, 'instrumentation' => articleBenchmarkInstrumentationHashes(), 'libraries' => $libraries,
            'runtime' => ['php' => PHP_VERSION, 'php_binary_sha256' => hash_file('sha256', PHP_BINARY), 'os' => php_uname(),
                'extensions' => get_loaded_extensions(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
                'pcre_jit' => ini_get('pcre.jit'), 'memory_limit' => ini_get('memory_limit'), 'ffi' => ini_get('ffi.enable'), 'build_manifest' => $runtimeManifest],
            'timed_boundary' => 'real full analyzer call including fileinfo, metadata FFI/JSON, decoding and allocations; fixture/read/verification outside window; qualification runs in another process'];
        $protocol['protocol_sha256'] = hash('sha256', json_encode($protocol, JSON_THROW_ON_ERROR));
        $emit($protocol);
        $state = $seed;
        $strata = [];
        $fixtureHashes = [];
        foreach ([4096, 524288, 1048576] as $size) {
            foreach (['ordinary', 'heavy'] as $density) {
                $fixture = articleBenchmarkFixture($size, $density);
                $fixturePath = $directory.'/'.$size.'-'.$density.'.fixture.serialized';
                file_put_contents($fixturePath, serialize($fixture));
                $fixtureHashes[$fixturePath] = hash_file('sha256', $fixturePath);
                $decodedHash = hash('sha256', $fixture['data']);
                $emit(['type' => 'fixture', 'size' => $size, 'density' => $density, 'path' => $fixturePath,
                    'serialized_sha256' => $fixtureHashes[$fixturePath], 'decoded_sha256' => $decodedHash,
                    'body_sha256' => hash('sha256', $fixture['body']), 'headers_sha256' => hash('sha256', serialize($fixture['headers'])),
                    'wire_sha256' => hash('sha256', $fixture['wire']), 'body_bytes' => strlen($fixture['body'])]);
                unset($fixture);
                $qualificationEligible = true;
                $qualificationPath = null;
                if ($requireCombined) {
                    $qualificationPath = $directory.'/'.$size.'-'.$density.'-qualification';
                    $qualificationCommand = array_merge(articleBenchmarkPhpCommand(), [__FILE__, '--worker=qualify-combined', '--samples=1',
                        '--native='.$libraries['candidate']['path'], '--fixture-path='.$fixturePath, '--source-dir='.$snapshots['candidate']['root']]);
                    $qualification = yencBenchmarkRunChild($qualificationCommand, $qualificationPath, $validated['timeout']);
                    $qualificationEligible = $qualification['status'] === 'ok'
                        && ($qualification['result']['combined_available'] ?? false) === true
                        && ($qualification['result']['library_sha256'] ?? null) === $libraries['candidate']['sha256']
                        && ($qualification['result']['fixture_sha256'] ?? null) === $fixtureHashes[$fixturePath]
                        && articleBenchmarkRuntimeMatches($qualification, $snapshots['candidate']);
                    $emit(['type' => 'qualification', 'size' => $size, 'density' => $density, 'eligible' => $qualificationEligible,
                        'command' => $qualificationCommand] + $qualification);
                }
                $ratios = [];
                $invalidPairs = 0;
                for ($pair = 0; $pair < $validated['samples']; $pair++) {
                    $order = yencBenchmarkRandom($state) & 1 ? ['baseline', 'candidate'] : ['candidate', 'baseline'];
                    $results = [];
                    foreach ($order as $position => $backend) {
                        $prefix = $directory.'/'.$size.'-'.$density.'-'.$pair.'-'.$backend;
                        $metadataPath = $prefix.'.metadata.serialized';
                        $command = array_merge(articleBenchmarkPhpCommand(), [__FILE__, '--worker=full-analyzer', '--samples=1',
                            '--native='.$libraries[$backend]['path'], '--fixture-path='.$fixturePath, '--result-output='.$metadataPath]);
                        array_push($command, ...articleBenchmarkPairOptions($backend, $snapshots[$backend]['root'], false));
                        $child = yencBenchmarkRunChild($command, $prefix, $validated['timeout']);
                        $eligible = articleBenchmarkPairedEligible($child, $decodedHash, $libraries[$backend]['sha256'], $metadataPath, false)
                            && articleBenchmarkRuntimeMatches($child, $snapshots[$backend])
                            && ($backend !== 'candidate' || $qualificationEligible);
                        $results[$backend] = ['eligible' => $eligible, 'child' => $child, 'metadata_path' => $metadataPath];
                        $emit(['type' => 'observation', 'size' => $size, 'density' => $density, 'pair' => $pair,
                            'backend' => $backend, 'order' => $order, 'position' => $position, 'command' => $command,
                            'qualification_evidence_prefix' => $backend === 'candidate' ? $qualificationPath : null, 'eligible' => $eligible] + $child);
                    }
                    $eligible = $results['baseline']['eligible'] && $results['candidate']['eligible']
                        && $results['baseline']['child']['result']['reference_sha256'] === $results['candidate']['child']['result']['reference_sha256']
                        && file_get_contents($results['baseline']['metadata_path']) === file_get_contents($results['candidate']['metadata_path'])
                        && hash_file('sha256', $fixturePath) === $fixtureHashes[$fixturePath];
                    $ratio = $eligible ? $results['candidate']['child']['result']['median_ms'] / $results['baseline']['child']['result']['median_ms'] : null;
                    if ($ratio !== null) {
                        $ratios[] = $ratio;
                    } else {
                        $invalidPairs++;
                    }
                    $emit(['type' => 'pair', 'size' => $size, 'density' => $density, 'pair' => $pair, 'order' => $order,
                        'eligible' => $eligible, 'candidate_over_baseline' => $ratio,
                        'invalid_reason' => $eligible ? null : 'failed_worker_or_fixture_identity_or_exact_result_parity']);
                }
                $decision = articleBenchmarkScreenStratum($ratios, $invalidPairs, ($seed ^ crc32($size.'-'.$density)) & 0xFFFFFFFF, $validated['min_speedup']);
                if (isset($decision['median_rust_over_baseline'])) {
                    $decision['median_candidate_over_baseline'] = $decision['median_rust_over_baseline'];
                    unset($decision['median_rust_over_baseline']);
                }
                $stratum = ['type' => 'stratum_summary', 'size' => $size, 'density' => $density,
                    'role' => $size >= 524288 ? 'useful_effect_target' : 'regression_control'] + $decision;
                $strata[] = $stratum;
                $emit($stratum);
            }
        }
        $identitiesStable = articleBenchmarkSourceHashes($root) === $sources['candidate']
            && articleBenchmarkSourceHashes($baselineRoot) === $sources['baseline'];
        foreach ($snapshots as $backend => $snapshot) {
            $identitiesStable = $identitiesStable && articleBenchmarkGraphStable($snapshot)
                && articleBenchmarkGraphStable($snapshot, $backend === 'candidate' ? $root : $baselineRoot);
        }
        foreach ($libraries as $library) {
            $identitiesStable = $identitiesStable && hash_file('sha256', $library['path']) === $library['sha256'];
        }
        foreach ($fixtureHashes as $path => $digest) {
            $identitiesStable = $identitiesStable && hash_file('sha256', $path) === $digest;
        }
        $summary = articleBenchmarkPairedDecision($strata, $identitiesStable, $validated['samples'], isset($options['regression-only']));
        $emit($summary);

        return $summary['status'] === 'invalid' ? 2 : ($summary['acceptance_criteria_met'] ? 0 : 1);
    } catch (Throwable $exception) {
        $emit(['type' => 'failure', 'status' => 'invalid', 'phase' => 'setup_or_run', 'exception' => $exception::class, 'reason' => $exception->getMessage()]);
        $emit(['type' => 'run_summary', 'status' => 'invalid', 'evidence' => 'paired_median_screen', 'adoption_not_qualified_p99_missing' => true, 'performance_advantage_demonstrated' => false]);

        return 2;
    }
}

/** @param list<string> $arguments */
function articleBenchmarkGit(string $root, array $arguments, bool $required = true): ?string
{
    $process = proc_open(['git', '-C', $root, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot inspect selected Git source tree.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        if ($required) {
            throw new RuntimeException('Selected source needs its own Git tracked-file manifest: '.trim($error));
        }

        return null;
    }

    return $output;
}

function articleBenchmarkPrivatePath(string $path): bool
{
    foreach (explode('/', $path) as $component) {
        if ($component === '.git' || $component === 'auth.json' || $component === '.env'
            || (str_starts_with($component, '.env.') && $component !== '.env.example')) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function articleBenchmarkGraphFiles(string $root): array
{
    $root = realpath($root) ?: throw new InvalidArgumentException('Selected source root does not exist.');
    if (trim((string) articleBenchmarkGit($root, ['rev-parse', '--show-toplevel'])) !== $root) {
        throw new InvalidArgumentException('Selected source needs its own Git tracked-file manifest.');
    }
    foreach (['composer.json', 'composer.lock', 'vendor/autoload.php'] as $path) {
        if (! is_file($root.'/'.$path)) {
            throw new InvalidArgumentException('Selected source needs its own installed Composer graph: '.$path);
        }
    }
    $paths = array_values(array_filter(explode("\0", (string) articleBenchmarkGit($root, ['ls-files', '-z'])),
        static fn (string $path): bool => $path !== '' && ! articleBenchmarkPrivatePath($path)));
    $pending = [['path' => 'vendor', 'ancestors' => []]];
    while ($pending !== []) {
        $entry = array_pop($pending);
        $realDirectory = realpath($root.'/'.$entry['path']);
        if ($realDirectory === false || ! str_starts_with($realDirectory, $root.'/') || in_array($realDirectory, $entry['ancestors'], true)) {
            throw new RuntimeException('Vendor directory escapes selected source or contains a symlink cycle.');
        }
        $names = scandir($root.'/'.$entry['path']);
        if ($names === false) {
            throw new RuntimeException('Cannot inspect selected vendor graph.');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $entry['path'].'/'.$name;
            if (articleBenchmarkPrivatePath($path)) {
                continue;
            }
            $resolved = realpath($root.'/'.$path);
            if ($resolved === false || ! str_starts_with($resolved, $root.'/')) {
                throw new RuntimeException('Source symlink escapes selected source: '.$path);
            }
            if (is_dir($root.'/'.$path)) {
                $pending[] = ['path' => $path, 'ancestors' => [...$entry['ancestors'], $realDirectory]];
            } elseif (is_file($root.'/'.$path)) {
                $paths[] = $path;
            }
        }
    }
    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}

/**
 * @return array{root: string, files: array<string, string>, git_head: ?string, git_tree: ?string}
 */
function articleBenchmarkFreezeGraph(string $root, string $destination): array
{
    $root = realpath($root) ?: throw new InvalidArgumentException('Selected source root does not exist.');
    $paths = articleBenchmarkGraphFiles($root);
    if (! mkdir($destination, 0700)) {
        throw new RuntimeException('Source snapshot must be new.');
    }
    $hashes = [];
    foreach ($paths as $path) {
        $resolved = realpath($root.'/'.$path);
        if ($resolved === false || ! str_starts_with($resolved, $root.'/') || ! is_file($resolved)) {
            throw new RuntimeException('Tracked source escapes selected source or is not a file: '.$path);
        }
        $target = $destination.'/'.$path;
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        $digest = hash_file('sha256', $resolved);
        if ($digest === false || ! copy($resolved, $target) || hash_file('sha256', $target) !== $digest) {
            throw new RuntimeException('Unable to freeze source graph file: '.$path);
        }
        $hashes[$path] = $digest;
    }

    return ['root' => realpath($destination) ?: $destination, 'files' => $hashes,
        'git_head' => trim((string) articleBenchmarkGit($root, ['rev-parse', '--verify', 'HEAD'], false)) ?: null,
        'git_tree' => trim((string) articleBenchmarkGit($root, ['rev-parse', '--verify', 'HEAD^{tree}'], false)) ?: null];
}

/** @return array{root: string, files: array<string, string|false>, classes: array<string, string>} */
function articleBenchmarkRuntimeSources(string $root): array
{
    $root = realpath($root) ?: throw new InvalidArgumentException('Selected runtime root does not exist.');
    $classes = [];
    foreach ([NNTPService::class, NzbFingerprintAnalyzer::class, NativeMetadataParser::class, YencService::class,
        DecoderFactory::class, NativePayloadDecoder::class, ArticleFrame::class, YencNntpHarness::class] as $class) {
        $path = (new ReflectionClass($class))->getFileName();
        $resolved = $path === false ? false : realpath($path);
        if ($resolved === false || ! str_starts_with($resolved, $root.'/')) {
            throw new RuntimeException('Application class escaped selected runtime: '.$class);
        }
        $classes[$class] = substr($resolved, strlen($root) + 1);
    }
    $allowed = [realpath(__FILE__), realpath(__DIR__.'/benchmark-yenc.php')];
    $files = [];
    foreach (get_included_files() as $path) {
        $resolved = realpath($path);
        if (in_array($resolved, $allowed, true)) {
            continue;
        }
        if ($resolved === false || ! str_starts_with($resolved, $root.'/')) {
            throw new RuntimeException('Included file escaped selected runtime: '.$path);
        }
        $files[substr($resolved, strlen($root) + 1)] = hash_file('sha256', $resolved);
    }
    ksort($files);

    return ['root' => $root, 'files' => $files, 'classes' => $classes];
}

/**
 * @param  array<string, mixed>  $child
 * @param  array{root: string, files: array<string, string>, git_head: ?string, git_tree: ?string}  $snapshot
 */
function articleBenchmarkRuntimeMatches(array $child, array $snapshot): bool
{
    $runtime = $child['result']['runtime_sources'] ?? null;
    if (! is_array($runtime) || ($runtime['root'] ?? null) !== $snapshot['root'] || ! is_array($runtime['files'] ?? null)) {
        return false;
    }
    foreach ($runtime['files'] as $path => $digest) {
        if (($snapshot['files'][$path] ?? null) !== $digest) {
            return false;
        }
    }

    return $runtime['files'] !== [];
}

/** @return array<string, mixed> */
function articleBenchmarkReadManifest(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new InvalidArgumentException('Runtime manifest is missing or unreadable: '.$path);
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read runtime manifest: '.$path);
    }
    $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($manifest)) {
        throw new InvalidArgumentException('Runtime manifest must be an object.');
    }

    return $manifest;
}

/** @return array{path: string, sha256: string} */
function articleBenchmarkFreezeLibrary(string $source, string $destination): array
{
    if (! is_file($source) || ! is_readable($source)) {
        throw new InvalidArgumentException('A readable native library is required: '.$source);
    }
    $digest = hash_file('sha256', $source);
    if ($digest === false || ! copy($source, $destination) || hash_file('sha256', $destination) !== $digest
        || hash_file('sha256', $source) !== $digest) {
        throw new RuntimeException('Unable to freeze native library bytes.');
    }

    return ['path' => $destination, 'sha256' => $digest];
}

/** @return array<string, string|false> */
function articleBenchmarkInstrumentationHashes(): array
{
    return ['benchmark-article-processing.php' => hash_file('sha256', __FILE__),
        'benchmark-yenc.php' => hash_file('sha256', __DIR__.'/benchmark-yenc.php'),
        'YencArticles.php' => hash_file('sha256', dirname(__DIR__).'/tests/Fixtures/YencArticles.php')];
}

/** @param array{root: string, files: array<string, string>, git_head: ?string, git_tree: ?string} $snapshot */
function articleBenchmarkGraphStable(array $snapshot, ?string $origin = null): bool
{
    $root = $origin ?? $snapshot['root'];
    foreach ($snapshot['files'] as $path => $digest) {
        if (! is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $digest) {
            return false;
        }
    }
    if ($origin !== null && articleBenchmarkGraphFiles($origin) !== array_keys($snapshot['files'])) {
        return false;
    }

    return true;
}
