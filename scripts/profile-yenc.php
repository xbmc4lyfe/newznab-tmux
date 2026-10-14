<?php

declare(strict_types=1);

/**
 * Disposable executed-call workload; never boots Laravel or opens an NNTP connection.
 * php scripts/profile-yenc.php --native=/absolute/library.so --path=strict --size=524288 --density=ordinary --iterations=100
 * Whole-process profiles include autoloading, capability probes, fixture setup, warmup,
 * decoding, exact-output verification and JSON reporting. Instrumented results are not latency evidence.
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

if (defined('NNTMUX_YENC_PROFILE_FUNCTIONS_ONLY')) {
    return;
}

try {
    $options = yencProfileOptions(array_slice($argv ?? [], 1));
    $library = realpath($options['native']);
    if ($library === false || ! is_file($library) || ! is_readable($library)) {
        throw new InvalidArgumentException('--native must name a readable library file.');
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    if ($options['prepare_dir'] !== null) {
        if ($options['size'] === null || $options['density'] === null) {
            throw new InvalidArgumentException('Preparation requires decoder fixture properties.');
        }
        echo json_encode(yencProfilePrepareFixture($options['prepare_dir'], $options['size'], $options['density']), JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $service = new YencService((new DecoderFactory)->make('native', $library));
    $result = yencProfileExecute($service, $options);
    $sourceHashes = [];
    foreach ([YencService::class, DecoderFactory::class, NativePayloadDecoder::class,
        ArticleFrame::class, NNTPService::class,
        YencArticles::class, YencNntpHarness::class, NzbFingerprintAnalyzer::class, NativeMetadataParser::class] as $class) {
        $source = (new ReflectionClass($class))->getFileName();
        $sourceHashes[$class] = $source === false ? null : hash_file('sha256', $source);
    }
    $result['runtime'] = [
        'php' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'php_binary_sha256' => hash_file('sha256', PHP_BINARY),
        'os' => php_uname(), 'sapi' => PHP_SAPI, 'ffi' => ini_get('ffi.enable'),
        'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
        'memory_limit' => ini_get('memory_limit'), 'decoder_name' => $service->decoderName(),
        'decoder_version' => $service->decoderVersion(), 'library' => $library,
        'library_sha256' => hash_file('sha256', $library), 'script_sha256' => hash_file('sha256', __FILE__),
        'composer_lock_sha256' => hash_file('sha256', dirname(__DIR__).'/composer.lock'),
        'vendor_installed_sha256' => hash_file('sha256', dirname(__DIR__).'/vendor/composer/installed.php'),
        'executed_source_sha256' => $sourceHashes,
    ];
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['status' => 'invalid', 'error_class' => $exception::class,
        'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    exit(2);
}

/**
 * @param  list<string>  $arguments
 * @return array{native: string, path: string, size: int|null, density: string|null, iterations: int, prepare_dir: string|null, fixture_dir: string|null}
 */
function yencProfileOptions(array $arguments): array
{
    $values = [];
    foreach ($arguments as $argument) {
        if (! preg_match('/\A--(native|path|size|density|iterations|prepare-dir|fixture-dir)=(.+)\z/s', $argument, $match)
            || isset($values[$match[1]])) {
            throw new InvalidArgumentException('Use unique --name=value workload options.');
        }
        $values[$match[1]] = $match[2];
    }
    $native = $values['native'] ?? '';
    if (! str_starts_with($native, '/') || str_contains($native, "\0")) {
        throw new InvalidArgumentException('An absolute --native library path is required.');
    }
    $path = $values['path'] ?? 'strict';
    foreach (['prepare-dir', 'fixture-dir'] as $name) {
        if (isset($values[$name]) && (! str_starts_with($values[$name], '/') || str_contains($values[$name], "\0"))) {
            throw new InvalidArgumentException('--'.$name.' requires an absolute directory path.');
        }
    }
    if ((isset($values['prepare-dir']) && isset($values['fixture-dir']))
        || ($path === 'metadata' && (isset($values['prepare-dir']) || isset($values['fixture-dir'])))) {
        throw new InvalidArgumentException('Fixture preparation/loading cannot be combined or used with metadata.');
    }
    $density = $values['density'] ?? 'ordinary';
    if (! in_array($path, ['strict', 'tolerant', 'body', 'metadata'], true)
        || ! in_array($density, ['ordinary', 'heavy'], true)) {
        throw new InvalidArgumentException('Invalid workload path or density.');
    }
    if ($path === 'metadata' && (isset($values['size']) || isset($values['density']))) {
        throw new InvalidArgumentException('Metadata uses a fixed RAR5 literal; --size and --density apply only to decoder paths.');
    }
    foreach (['size' => 8388608, 'iterations' => 10000] as $name => $maximum) {
        $value = $values[$name] ?? ($name === 'size' ? '524288' : '100');
        if (! preg_match('/\A[1-9][0-9]*\z/', $value) || strlen($value) > strlen((string) $maximum)
            || (int) $value > $maximum) {
            throw new InvalidArgumentException("--{$name} must be an integer between 1 and {$maximum}.");
        }
        $values[$name] = (int) $value;
    }

    return ['native' => $native, 'path' => $path, 'size' => $path === 'metadata' ? null : $values['size'],
        'density' => $path === 'metadata' ? null : $density, 'iterations' => $values['iterations'],
        'prepare_dir' => $values['prepare-dir'] ?? null, 'fixture_dir' => $values['fixture-dir'] ?? null];
}

/** @return array{data: string, article: string} */
function yencProfileFixture(int $size, string $density): array
{
    if ($size < 1 || $size > 8388608 || ! in_array($density, ['ordinary', 'heavy'], true)) {
        throw new InvalidArgumentException('Invalid fixture size or density.');
    }
    $seed = $density === 'heavy' ? "\xd6\xe0\xe3\x13" : YencArticles::bytes();
    $data = substr(str_repeat($seed, (int) ceil($size / strlen($seed))), 0, $size);

    return ['data' => $data, 'article' => YencArticles::article($data)];
}

/**
 * @param  array{native: string, path: string, size: int|null, density: string|null, iterations: int, prepare_dir: string|null, fixture_dir: string|null}  $options
 * @return array<string, mixed>
 */
function yencProfileExecute(YencService $service, array $options): array
{
    if ($options['path'] === 'metadata') {
        return yencProfileExecuteMetadata($service, $options['native'], $options['iterations']);
    }
    if ($options['size'] === null || $options['density'] === null) {
        throw new InvalidArgumentException('Decoder paths require fixture size and density.');
    }
    $fixture = $options['fixture_dir'] === null ? yencProfileFixture($options['size'], $options['density'])
        : yencProfileLoadFixture($options['fixture_dir'], $options['size'], $options['density']);
    $stream = null;
    $harness = null;
    $wire = null;
    try {
        if ($options['path'] === 'body') {
            $wire = preg_replace('/^\./m', '..', $fixture['article']);
            if ($wire === null) {
                throw new RuntimeException('Unable to dot-stuff fixture.');
            }
            $wire .= "\r\n.\r\n";
            $stream = fopen('php://memory', 'w+');
            if ($stream === false) {
                throw new RuntimeException('Unable to open fixture memory stream.');
            }
            if (fwrite($stream, $wire) !== strlen($wire)) {
                throw new RuntimeException('Unable to write complete fixture.');
            }
            $harness = new YencNntpHarness($stream, $service);
        }
        for ($iteration = -1; $iteration < $options['iterations']; $iteration++) {
            $input = $fixture['article'];
            if (is_resource($stream) && ! rewind($stream)) {
                throw new RuntimeException('Unable to rewind fixture.');
            }
            $result = match ($options['path']) {
                'strict' => $service->decode($input),
                'tolerant' => $service->decodeIgnore($input),
                'body' => $harness->fetch(),
                default => throw new InvalidArgumentException('Invalid workload path.'),
            };
            if ($result !== $fixture['data']) {
                throw new RuntimeException('Incorrect output at '.($iteration < 0 ? 'warmup' : 'iteration '.$iteration).'.');
            }
            unset($input, $result);
        }

        return [
            'status' => 'ok', 'path' => $options['path'], 'density' => $options['density'],
            'size' => $options['size'], 'iterations' => $options['iterations'], 'warmups' => 1,
            'verified_calls' => $options['iterations'] + 1, 'input_bytes' => strlen($fixture['article']),
            'input_sha256' => hash('sha256', $fixture['article']),
            'wire_sha256' => $wire === null ? null : hash('sha256', $wire),
            'output_sha256' => hash('sha256', $fixture['data']),
            'fixture_source' => $options['fixture_dir'] === null ? 'in_memory_encoding' : 'prepared_files',
            'profile_boundary' => 'Whole process: autoload, native capability probes, '.($options['fixture_dir'] === null ? 'fixture encoding' : 'prepared fixture loading and hash verification').', stream setup, warmup, executed decoder calls, exact-output verification and reporting.',
            'claim_limit' => 'Executed-call attribution only; instrumented profiles do not establish latency, comparative speed or production capacity.',
        ];
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

/** @return array{data: string, article: string, headers: list<string>} */
function yencProfileMetadataFixture(): array
{
    $data = hex2bin('526172211a070100c51a333203010000af35bf2f2902011b0200000100000000010178'
        .'1a031f01000000020000000300000004000000050000000600000019b23a3503050000');
    if ($data === false) {
        throw new RuntimeException('Invalid fixed RAR5 fixture.');
    }
    $article = YencArticles::article($data)."\r\n";

    return ['data' => $data, 'article' => $article, 'headers' => [
        "Message-ID: <metadata-profile@example.test>\r\n",
        "Subject: fixture.rar (1/1)\r\n",
        "Date: Wed, 14 Oct 2026 00:00:00 +0000\r\n",
        "Newsgroups: alt.test\r\n",
        "Lines: 3\r\n",
    ]];
}

/** @return array<string, mixed> */
function yencProfilePrepareFixture(string $directory, int $size, string $density): array
{
    if (! str_starts_with($directory, '/') || str_contains($directory, "\0") || file_exists($directory) || is_link($directory)) {
        throw new InvalidArgumentException('Preparation requires a new absolute fixture directory.');
    }
    $fixture = yencProfileFixture($size, $density);
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to reserve fixture directory.');
    }
    $created = [];
    $complete = false;
    try {
        $manifest = ['schema_version' => 1, 'size' => $size, 'density' => $density,
            'data_bytes' => strlen($fixture['data']), 'article_bytes' => strlen($fixture['article']),
            'data_sha256' => hash('sha256', $fixture['data']), 'article_sha256' => hash('sha256', $fixture['article'])];
        foreach (['data' => $fixture['data'], 'article' => $fixture['article'],
            'manifest.json' => json_encode($manifest, JSON_THROW_ON_ERROR).PHP_EOL] as $name => $contents) {
            $path = $directory.'/'.$name;
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException('Unable to create owned fixture file.');
            }
            $created[] = $path;
            try {
                if (fwrite($handle, $contents) !== strlen($contents)) {
                    throw new RuntimeException('Incomplete fixture write.');
                }
            } finally {
                fclose($handle);
            }
        }
        $complete = true;

        return ['status' => 'ok', 'mode' => 'prepare', 'fixture_dir' => $directory, 'manifest' => $manifest];
    } finally {
        if (! $complete) {
            foreach ($created as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}

/** @return array{data: string, article: string} */
function yencProfileLoadFixture(string $directory, int $size, string $density): array
{
    if (! str_starts_with($directory, '/') || str_contains($directory, "\0") || ! is_dir($directory) || is_link($directory)
        || $size < 1 || $size > 8388608 || ! in_array($density, ['ordinary', 'heavy'], true)) {
        throw new RuntimeException('Invalid prepared fixture directory or requested properties.');
    }
    $contents = [];
    foreach (['manifest.json' => 4096, 'data' => $size, 'article' => $size * 2 + (int) ceil($size / 64) * 2 + 256] as $name => $maximum) {
        $path = $directory.'/'.$name;
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Prepared fixture requires regular readable owned files.');
        }
        $content = file_get_contents($path, length: $maximum + 1);
        if ($content === false || strlen($content) > $maximum) {
            throw new RuntimeException('Prepared fixture file exceeds its bounded budget.');
        }
        $contents[$name] = $content;
    }
    $manifest = json_decode($contents['manifest.json'], true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1
        || ($manifest['size'] ?? null) !== $size || ($manifest['density'] ?? null) !== $density
        || ($manifest['data_bytes'] ?? null) !== $size || strlen($contents['data']) !== $size
        || ($manifest['article_bytes'] ?? null) !== strlen($contents['article'])
        || ($manifest['data_sha256'] ?? null) !== hash('sha256', $contents['data'])
        || ($manifest['article_sha256'] ?? null) !== hash('sha256', $contents['article'])) {
        throw new RuntimeException('Prepared fixture manifest properties, lengths or hashes do not match.');
    }

    return ['data' => $contents['data'], 'article' => $contents['article']];
}

/**
 * @param  array{metadata: array<string, mixed>, decoded_bytes: ?string}  $analysis
 * @return array<string, mixed>
 */
function yencProfileVerifyMetadata(array $analysis, string $expected): array
{
    if ($analysis['decoded_bytes'] !== $expected) {
        throw new RuntimeException('Metadata workload decoded incorrect fixture bytes.');
    }
    $metadata = $analysis['metadata'];
    foreach (['decoded_format_metadata' => 'nntmux-rust-ffi', 'yenc_framing_metadata' => 'nntmux-rust-ffi',
        'nntp_header_metadata' => 'nntmux-rust-nntp-headers', 'nntp_body_metadata' => 'nntmux-rust-nntp-body',
        'nntp_lines_body_relationship' => 'nntmux-rust-nntp-body'] as $key => $engine) {
        if (($metadata[$key]['parser_engine'] ?? null) !== $engine) {
            throw new RuntimeException('Native metadata output unavailable for '.$key.'.');
        }
    }
    $extras = $metadata['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1]['file_header']['file_extra_records'] ?? null;
    $times = $extras['time']['times'] ?? [];
    if (($extras['time_record_count'] ?? null) !== 1
        || ($extras['base_header_mtime_seconds_matches'] ?? null) !== true
        || ($extras['mtime_comparison_status'] ?? null) !== 'declared_base_seconds_consistency_only'
        || ($extras['time']['interpretation_status'] ?? null) !== 'validated_header_declaration'
        || ($extras['time']['source_payload_offset'] ?? null) !== 37
        || ! is_array($times) || array_column($times, 'base_value') !== [1, 2, 3]
        || array_column($times, 'nanoseconds') !== [4, 5, 6]
        || array_column($times, 'filetime_epoch_relationship_status') !== array_fill(0, 3, 'not_applicable_unix_seconds')) {
        throw new RuntimeException('Native RAR5 timestamp output differs from independent literal expectations.');
    }

    return ['base_values' => [1, 2, 3], 'nanoseconds' => [4, 5, 6], 'source_payload_offset' => 37];
}

/** @return array<string, mixed> */
function yencProfileExecuteMetadata(YencService $service, string $native, int $iterations): array
{
    if ($iterations < 1 || $iterations > 10000) {
        throw new InvalidArgumentException('Metadata iterations must be between 1 and 10000.');
    }
    $fixture = yencProfileMetadataFixture();
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['yenc' => ['native_library' => $native]]));
    Container::setInstance($container);
    try {
        $analyzer = new NzbFingerprintAnalyzer($service);
        for ($iteration = -1; $iteration < $iterations; $iteration++) {
            $analysis = $analyzer->analyzeArticleAndDecodedBytes($fixture['headers'], $fixture['article']);
            $timestamps = yencProfileVerifyMetadata($analysis, $fixture['data']);
        }

        return [
            'status' => 'ok', 'path' => 'metadata', 'fixture' => 'fixed_literal_rar5_unix_seconds_and_nanoseconds',
            'size' => strlen($fixture['data']), 'density' => null, 'iterations' => $iterations, 'warmups' => 1,
            'verified_calls' => $iterations + 1, 'input_bytes' => strlen($fixture['article']),
            'input_sha256' => hash('sha256', $fixture['article']),
            'headers_sha256' => hash('sha256', implode('', $fixture['headers'])),
            'wire_sha256' => null, 'output_sha256' => hash('sha256', $fixture['data']), 'verified_rar5_timestamps' => $timestamps,
            'profile_boundary' => 'Whole process: autoload, native capability probes, fixed fixture and inert config container setup, warmup, NzbFingerprintAnalyzer and NativeMetadataParser calls, exact bytes and literal timestamp verification and reporting.',
            'claim_limit' => 'Rust-only fixed-fixture executed-call attribution; no C++ equivalent or speed comparison. Instrumentation does not establish latency or production capacity.',
        ];
    } finally {
        Container::setInstance($previous);
    }
}
