<?php

declare(strict_types=1);

namespace Nntmux\Benchmark\StrictArticle;

use App\Services\Yenc\ExperimentalStrictArticleDecoder;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\YencService;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Fixtures\YencArticles;
use Tests\Support\YencNntpHarness;
use Throwable;

const MAX_ARTICLE_BYTES = 67_108_864;

/** @return iterable<array<string, mixed>> */
function fixtures(): iterable
{
    foreach ([4096, 524288, 1048576, 8388608] as $size) {
        foreach (['ordinary', 'heavy'] as $density) {
            $seed = $density === 'heavy' ? "\xd6\xe0\xe3\x13" : YencArticles::bytes();
            $data = substr(str_repeat($seed, (int) ceil($size / strlen($seed))), 0, $size);
            $article = YencArticles::article($data);
            foreach (['strict', 'tolerant', 'body'] as $path) {
                yield ['id' => "single-{$size}-{$density}-{$path}", 'family' => 'common', 'path' => $path,
                    'article' => $article, 'data' => $data, 'exception' => null,
                    'admission' => $path === 'strict' ? 'supported' : 'unused'];
            }
        }
    }
    foreach ([4096, 524288, 1048576, 8388608] as $size) {
        foreach (['ordinary', 'heavy'] as $density) {
            $seed = $density === 'heavy' ? "\xd6\xe0\xe3\x13" : YencArticles::bytes();
            $data = substr(str_repeat($seed, (int) ceil($size / strlen($seed))), 0, $size);
            // Use the independent fixture writer's complete escape pairs and
            // 128-column wrapping, without the production encoder or parser.
            $single = YencArticles::article($data);
            $payloadStart = strpos($single, "\n") + 1;
            $payloadEnd = strrpos($single, "\n") + 1;
            $article = '=ybegin part=2 total=3 line=128 size='.($size * 3)." name=fixture file.bin\r\n"
                .'=ypart begin='.($size + 1).' end='.($size * 2)."\r\n"
                .substr($single, $payloadStart, $payloadEnd - $payloadStart)
                .'=yend size='.$size.($density === 'ordinary' ? ' part=2' : '').' pcrc32='.hash('crc32b', $data);
            yield ['id' => "multipart-{$size}-{$density}-strict", 'family' => 'common', 'path' => 'strict',
                'article' => $article, 'data' => $data, 'exception' => null, 'admission' => 'supported'];
        }
    }
    $single = "=ybegin line=128 size=3 name=x\nklm\n";
    $multi = "=ybegin part=1 total=1 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n";
    $crcError = 'CRC32 checksums do not match. The file is probably corrupt.';
    foreach ([
        ['rare-empty', "=ybegin line=128 size=0 name=empty\n=yend size=0 crc32=0", '', null, 'supported'],
        ['rare-multipart-split', "=ybegin part=1 total=1 line=128 size=4 name=x\r\n=ypart begin=1 end=4\r\nk=\r\n@lm\r\n=yend size=4 pcrc32=a672946b", "A\xd6BC", null, 'supported'],
        ['rare-duplicate-fallback', "=ybegin line=128 size=9 size=3 name=x\nklm\n=yend size=3 crc32=a3830348", 'ABC', null, 'fallback'],
        ['rare-trailer-name-crc-fallback', $single.'=yend size=3 name=ignored crc32=deadbeef', null, $crcError, 'fallback'],
        ['rare-dangling-before-range', "=ybegin part=0 total=0 line=1 size=1 name=x\n=ypart begin=0 end=2\nklm=\r\n=yend size=99 pcrc32=bad", null, 'Unmatched yEnc escape marker. The file is probably corrupt.', 'supported'],
        ['rare-invalid-range', "=ybegin part=1 total=1 line=128 size=3 name=x\n=ypart begin=2 end=1\nklm\n=yend size=3 pcrc32=a3830348", null, 'Invalid yEnc multipart range or part number.', 'supported'],
        ['rare-missing-pcrc-before-size', $multi.'=yend size=99 crc32=bad', null, 'Missing yEnc part checksum.', 'supported'],
        ['rare-size-before-crc', "=ybegin line=128 size=2 name=x\nklm\n=yend size=3 crc32=bad", null, 'Declared and decoded yEnc sizes do not match. The file is probably corrupt.', 'supported'],
        ['rare-crc-mismatch', $single.'=yend size=3 crc32=deadbeef', null, $crcError, 'supported'],
        ['rare-numeric-overflow-fallback', "=ybegin line=128 size=9223372036854775808 name=x\nklm\n=yend size=3", null, 'Invalid or missing yEnc size.', 'fallback'],
    ] as [$id, $article, $data, $message, $admission]) {
        yield ['id' => $id, 'family' => 'uncommon', 'path' => 'strict', 'article' => $article,
            'data' => $data, 'exception' => $message === null ? null : ['class' => RuntimeException::class, 'message' => $message],
            'admission' => $admission];
    }
}

/** @param list<string> $arguments
 * @return array<string, string>
 */
function options(array $arguments): array
{
    $values = [];
    if (count($arguments) > 6) {
        throw new InvalidArgumentException('Too many worker options.');
    }
    foreach ($arguments as $argument) {
        if (strlen($argument) > 4128 || ! preg_match('/\A--(backend|library|fixture-json|article-path|data-path|protocol-json)=(.+)\z/D', $argument, $match)
            || array_key_exists($match[1], $values)) {
            throw new InvalidArgumentException('Invalid, unknown, or duplicate worker option.');
        }
        $values[$match[1]] = $match[2];
    }
    if (! in_array($values['backend'] ?? null, ['cpp', 'rust'], true)) {
        throw new InvalidArgumentException('Explicit cpp or rust backend required.');
    }
    foreach (['library', 'fixture-json', 'article-path', 'protocol-json'] as $key) {
        if (! isset($values[$key])) {
            throw new InvalidArgumentException('Missing required worker path.');
        }
    }
    foreach ($values as $key => $value) {
        if ($key !== 'backend' && (strlen($value) > 4096 || ! str_starts_with($value, '/') || str_contains($value, "\0")
            || in_array('..', explode('/', $value), true))) {
            throw new InvalidArgumentException('Worker paths must be absolute without parent components.');
        }
    }

    return $values;
}

/** Sorted mandatory source closure shared with the experimental controller.
 * @return list<string>
 */
function sourcePaths(?string $root = null): array
{
    $root ??= dirname(__DIR__);
    $paths = ['scripts/benchmark-yenc-strict-article.php', 'scripts/yenc-strict-article-worker.php',
        'scripts/benchmark-yenc.php', 'scripts/build-rapidyenc.sh', 'composer.lock', 'vendor/composer/installed.json',
        'app/Services/YencService.php', 'app/Services/NNTP/NNTPService.php',
        'tests/Fixtures/YencArticles.php', 'tests/Support/YencNntpHarness.php',
        'docker/app/nzb-yenc-ffi/Cargo.toml', 'docker/app/nzb-yenc-ffi/Cargo.lock'];
    foreach (['app/Services/Yenc', 'docker/app/nzb-yenc-ffi/src', 'vendor/dariusiii/net_nntp/src'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Symlink in worker source closure.');
            }
            if ($file->isFile()) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
            if (count($paths) > 512) {
                throw new RuntimeException('Worker source closure exceeds file budget.');
            }
        }
    }
    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}

function regularFile(string $path, int $cap): string
{
    $real = realpath($path);
    $size = @filesize($path);
    if ($real === false || $real !== $path || is_link($path) || ! is_file($path) || ! is_readable($path)
        || $size === false || $size > $cap) {
        throw new RuntimeException('Worker input must be a bounded regular canonical file.');
    }
    $bytes = @file_get_contents($path, false, null, 0, $cap + 1);
    if ($bytes === false || strlen($bytes) > $cap) {
        throw new RuntimeException('Unable to read bounded worker input.');
    }

    return $bytes;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $decoded = json_decode(regularFile($path, 1_048_576), true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('Worker JSON must be an object.');
    }

    return $decoded;
}

function sha256(mixed $value): bool
{
    return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
}

/** @param array<string, mixed> $protocol
 * @param  array<string, mixed>  $fixture
 */
function verifyFixtureBinding(array $protocol, array $fixture): void
{
    $fixtures = $protocol['fixtures'] ?? null;
    if (! is_array($fixtures) || array_is_list($fixtures) || count($fixtures) > 42
        || ! sha256($protocol['fixtures_sha256'] ?? null)
        || ! hash_equals($protocol['fixtures_sha256'], hash('sha256', json_encode($fixtures, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))
        || ! is_string($fixture['id'] ?? null) || ($fixtures[$fixture['id']] ?? null) !== $fixture) {
        throw new RuntimeException('Worker fixture does not match protocol custody.');
    }
}

/** Verify actual source bytes, including consumed vendor code, within one root. */
function verifySourceClosure(mixed $sources, string $root): void
{
    if (! is_array($sources) || array_is_list($sources) || count($sources) > 512
        || array_diff(sourcePaths($root), array_keys($sources)) !== []) {
        throw new RuntimeException('Incomplete worker source closure.');
    }
    $total = 0;
    foreach ($sources as $path => $digest) {
        if (! is_string($path) || strlen($path) > 256 || ! preg_match('~\A[a-zA-Z0-9_./-]+\z~D', $path)
            || str_starts_with($path, '/') || in_array('..', explode('/', $path), true) || ! sha256($digest)) {
            throw new RuntimeException('Invalid worker source identity.');
        }
        $bytes = regularFile($root.'/'.$path, 8_388_608);
        $total += strlen($bytes);
        if ($total > 67_108_864 || ! hash_equals($digest, hash('sha256', $bytes))) {
            throw new RuntimeException('Worker source identity mismatch or budget exceeded.');
        }
    }
}

/** @param array<string, mixed> $protocol */
function verifyProtocol(array $protocol, string $backend, string $library): void
{
    $declared = $protocol['protocol_sha256'] ?? null;
    unset($protocol['protocol_sha256']);
    if (! sha256($declared) || ! hash_equals($declared, hash('sha256', json_encode($protocol, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))) {
        throw new RuntimeException('Worker protocol hash mismatch.');
    }
    $fixtures = $protocol['fixtures'] ?? null;
    if (! is_array($fixtures) || $fixtures === [] || ! is_array(reset($fixtures))) {
        throw new RuntimeException('Missing worker fixture custody.');
    }
    verifyFixtureBinding($protocol, reset($fixtures));
    // Production custody is always rooted at this worker's checkout. The CLI
    // has no source-root override; the helper also supports isolated test trees.
    verifySourceClosure($protocol['source_sha256'] ?? null, dirname(__DIR__));
    $libraries = $protocol['libraries'] ?? null;
    if (! is_array($libraries) || count($libraries) !== 2) {
        throw new RuntimeException('Both worker library identities required.');
    }
    foreach (['cpp', 'rust'] as $role) {
        $entry = $libraries[$role] ?? null;
        if (! is_array($entry) || ! is_string($entry['path'] ?? null) || ! sha256($entry['sha256'] ?? null)) {
            throw new RuntimeException('Invalid worker library identity.');
        }
        $bytes = regularFile($entry['path'], 134_217_728);
        if (! hash_equals($entry['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('Worker library identity mismatch.');
        }
    }
    if ($libraries['cpp']['path'] === $libraries['rust']['path'] || $libraries['cpp']['sha256'] === $libraries['rust']['sha256']
        || ($libraries[$backend]['path'] ?? null) !== $library) {
        throw new RuntimeException('Worker role does not match distinct frozen libraries.');
    }
}

/** @param array<string, mixed> $fixture */
function verifyFixture(array $fixture, string $article, ?string $data): void
{
    if (! is_string($fixture['id'] ?? null) || ! preg_match('/\A[a-z0-9][a-z0-9-]{0,127}\z/D', $fixture['id'])
        || ! in_array($fixture['family'] ?? null, ['common', 'uncommon'], true)
        || ! in_array($fixture['path'] ?? null, ['strict', 'tolerant', 'body'], true)
        || ! in_array($fixture['admission'] ?? null, ['supported', 'fallback', 'unused'], true)
        || (($fixture['path'] === 'strict') === ($fixture['admission'] === 'unused'))
        || ! sha256($fixture['article_sha256'] ?? null) || ! hash_equals($fixture['article_sha256'], hash('sha256', $article))) {
        throw new RuntimeException('Invalid worker fixture identity.');
    }
    $exception = $fixture['exception'] ?? null;
    if ($exception === null) {
        if ($data === null || ! sha256($fixture['data_sha256'] ?? null) || ! hash_equals($fixture['data_sha256'], hash('sha256', $data))) {
            throw new RuntimeException('Invalid expected worker data.');
        }
    } elseif (! is_array($exception) || array_keys($exception) !== ['class', 'message']
        || ($exception['class'] ?? null) !== RuntimeException::class || ! is_string($exception['message'] ?? null)
        || strlen($exception['message']) > 4096 || $data !== null || $fixture['path'] !== 'strict') {
        throw new RuntimeException('Invalid expected worker exception.');
    }
}

/** @param array<string, mixed> $fixture */
function verifyAdmission(ExperimentalStrictArticleDecoder $decoder, array $fixture, string $article, ?string $data): void
{
    if (! $decoder->isAvailable()) {
        throw new RuntimeException('Experimental strict capability unavailable: '.$decoder->unavailabilityReason());
    }
    if ($fixture['admission'] === 'unused') {
        return;
    }
    $actual = null;
    $exception = null;
    try {
        $actual = $decoder->decode($article);
    } catch (Throwable $error) {
        $exception = ['class' => $error::class, 'message' => $error->getMessage()];
    }
    $valid = $fixture['admission'] === 'fallback'
        ? $actual === null && $exception === null
        : (($fixture['exception'] ?? null) !== null
            ? $exception === $fixture['exception']
            : $exception === null && $actual !== null && $actual === $data);
    if (! $valid) {
        throw new RuntimeException('Experimental fixture admission mismatch.');
    }
}

/** @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function measure(YencService $service, array $fixture, string $article, ?string $data): array
{
    $sourceHash = hash('sha256', $article);
    $stream = null;
    $harness = null;
    if ($fixture['path'] === 'body') {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to create isolated BODY fixture.');
        }
        $wire = (preg_replace('/^\./m', '..', $article) ?? throw new RuntimeException('Dot stuffing failed.'))."\r\n.\r\n";
        if (fwrite($stream, $wire) !== strlen($wire)) {
            fclose($stream);
            throw new RuntimeException('Unable to write isolated BODY fixture.');
        }
        $harness = new YencNntpHarness($stream, $service);
    }
    $timed = [];
    try {
        for ($sample = 0; $sample < 2; $sample++) {
            $input = $article;
            if ($stream !== null) {
                rewind($stream);
            }
            $actual = null;
            $exception = null;
            $before = getrusage();
            $start = hrtime(true);
            try {
                $actual = match ($fixture['path']) {
                    'strict' => $service->decode($input),
                    'tolerant' => $service->decodeIgnore($input),
                    'body' => $harness?->fetch(),
                    default => throw new RuntimeException('Invalid worker path.'),
                };
            } catch (Throwable $error) {
                $exception = ['class' => $error::class, 'message' => $error->getMessage()];
            }
            $elapsed = hrtime(true) - $start;
            $after = getrusage();
            $expectedException = $fixture['exception'] ?? null;
            $correct = $expectedException === null ? $exception === null && is_string($actual) && $actual === $data
                : $exception === $expectedException;
            $argumentCorrect = $fixture['path'] === 'tolerant' ? $input === $data : $input === $article;
            if (! $correct || ! $argumentCorrect || hash('sha256', $article) !== $sourceHash || ! is_int($elapsed) || $elapsed <= 0) {
                throw new RuntimeException('Worker call verification failed.');
            }
            if ($sample === 1) {
                $timed = ['status' => 'ok', 'correct' => true, 'input_unchanged' => true,
                    'input_contract' => match ($fixture['path']) {
                        'strict' => 'strict_unchanged', 'tolerant' => 'tolerant_decoded_in_place', 'body' => 'body_stream_source_unchanged',
                        default => throw new RuntimeException('Invalid worker path.'),
                    },
                    'verified_calls' => 2, 'elapsed_ns' => $elapsed, 'elapsed_ms' => $elapsed / 1_000_000,
                    'median_ms' => $elapsed / 1_000_000, 'cpu_before' => $before, 'cpu_after' => $after,
                    'outcome' => $exception === null ? 'bytes' : 'expected_exception', 'adoption_qualified' => false];
            }
        }
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    return $timed;
}

/** @param array<string, string> $options
 * @return array<string, mixed>
 */
function run(array $options): array
{
    $protocolBytes = regularFile($options['protocol-json'], 1_048_576);
    $protocol = readJson($options['protocol-json']);
    verifyProtocol($protocol, $options['backend'], $options['library']);
    $fixtureBytes = regularFile($options['fixture-json'], 1_048_576);
    $fixture = readJson($options['fixture-json']);
    verifyFixtureBinding($protocol, $fixture);
    $article = regularFile($options['article-path'], MAX_ARTICLE_BYTES);
    $data = isset($options['data-path']) ? regularFile($options['data-path'], MAX_ARTICLE_BYTES) : null;
    verifyFixture($fixture, $article, $data);
    $native = new NativePayloadDecoder($options['library']);
    $strict = $options['backend'] === 'rust' ? new ExperimentalStrictArticleDecoder($options['library']) : null;
    if ($strict !== null) {
        verifyAdmission($strict, $fixture, $article, $data);
    }
    $result = measure(new YencService($native, $strict), $fixture, $article, $data);
    verifyProtocol($protocol, $options['backend'], $options['library']);
    if ($protocolBytes !== regularFile($options['protocol-json'], 1_048_576)
        || $fixtureBytes !== regularFile($options['fixture-json'], 1_048_576)
        || $article !== regularFile($options['article-path'], MAX_ARTICLE_BYTES)
        || ($data !== null && $data !== regularFile($options['data-path'], MAX_ARTICLE_BYTES))) {
        throw new RuntimeException('Worker frozen input changed.');
    }

    return $result + ['backend' => $options['backend'], 'id' => $fixture['id'],
        'library_sha256' => $protocol['libraries'][$options['backend']]['sha256'],
        'protocol_sha256' => $protocol['protocol_sha256'], 'admission' => $fixture['admission'],
        'article_sha256' => $fixture['article_sha256'], 'fixture_sha256' => hash('sha256', $fixtureBytes)];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        require dirname(__DIR__).'/vendor/autoload.php';
        $result = run(options(array_slice($argv ?? [], 1)));
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        echo json_encode(['status' => 'invalid', 'correct' => false, 'adoption_qualified' => false,
            'error_class' => $error::class, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
        exit(2);
    }
}
