<?php

declare(strict_types=1);

/**
 * Disposable executed-call workload; never boots Laravel or opens an NNTP connection.
 * php scripts/profile-yenc.php --native=/absolute/library.so --path=strict --size=524288 --density=ordinary --iterations=100
 * Whole-process profiles include autoloading, capability probes, fixture setup, warmup,
 * decoding, exact-output verification and JSON reporting. Instrumented results are not latency evidence.
 */
use App\Services\NNTP\NNTPService;
use App\Services\Yenc\ArticleFrame;
use App\Services\Yenc\DecoderFactory;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\YencService;
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
    $service = new YencService((new DecoderFactory)->make('native', $library));
    $result = yencProfileExecute($service, $options);
    $sourceHashes = [];
    foreach ([YencService::class, DecoderFactory::class, NativePayloadDecoder::class,
        ArticleFrame::class, NNTPService::class,
        YencArticles::class, YencNntpHarness::class] as $class) {
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
 * @return array{native: string, path: string, size: int, density: string, iterations: int}
 */
function yencProfileOptions(array $arguments): array
{
    $values = [];
    foreach ($arguments as $argument) {
        if (! preg_match('/\A--(native|path|size|density|iterations)=(.+)\z/s', $argument, $match)
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
    $density = $values['density'] ?? 'ordinary';
    if (! in_array($path, ['strict', 'tolerant', 'body'], true)
        || ! in_array($density, ['ordinary', 'heavy'], true)) {
        throw new InvalidArgumentException('Invalid workload path or density.');
    }
    foreach (['size' => 8388608, 'iterations' => 10000] as $name => $maximum) {
        $value = $values[$name] ?? ($name === 'size' ? '524288' : '100');
        if (! preg_match('/\A[1-9][0-9]*\z/', $value) || strlen($value) > strlen((string) $maximum)
            || (int) $value > $maximum) {
            throw new InvalidArgumentException("--{$name} must be an integer between 1 and {$maximum}.");
        }
        $values[$name] = (int) $value;
    }

    return ['native' => $native, 'path' => $path, 'size' => $values['size'],
        'density' => $density, 'iterations' => $values['iterations']];
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
 * @param  array{native: string, path: string, size: int, density: string, iterations: int}  $options
 * @return array<string, mixed>
 */
function yencProfileExecute(YencService $service, array $options): array
{
    $fixture = yencProfileFixture($options['size'], $options['density']);
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
            'profile_boundary' => 'Whole process: autoload, native capability probes, fixture setup, warmup, executed decoder calls, exact-output verification and reporting.',
            'claim_limit' => 'Executed-call attribution only; instrumented profiles do not establish latency, comparative speed or production capacity.',
        ];
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
