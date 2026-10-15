<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\ExperimentalStrictArticleDecoder;
use App\Services\YencService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Fixtures\YencArticles;

use function Nntmux\Benchmark\StrictArticle\fixtures;
use function Nntmux\Benchmark\StrictArticle\measure;
use function Nntmux\Benchmark\StrictArticle\options;
use function Nntmux\Benchmark\StrictArticle\regularFile;
use function Nntmux\Benchmark\StrictArticle\sourcePaths;
use function Nntmux\Benchmark\StrictArticle\verifyFixture;
use function Nntmux\Benchmark\StrictArticle\verifyFixtureBinding;
use function Nntmux\Benchmark\StrictArticle\verifyProtocol;
use function Nntmux\Benchmark\StrictArticle\verifySourceClosure;

final class YencStrictArticleWorkerTest extends TestCase
{
    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/scripts/yenc-strict-article-worker.php';
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir() && ! $file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function directory(): string
    {
        if ($this->directory === null) {
            $this->directory = sys_get_temp_dir().'/nntmux-strict-worker-'.bin2hex(random_bytes(8));
            mkdir($this->directory, 0700);
            $resolved = realpath($this->directory);
            self::assertNotFalse($resolved);
            $this->directory = $resolved;
        }

        return $this->directory;
    }

    /** @return array<string, mixed> */
    private function protocol(): array
    {
        $sources = [];
        foreach (sourcePaths() as $path) {
            $sources[$path] = hash_file('sha256', dirname(__DIR__, 2).'/'.$path);
        }
        $directory = $this->directory();
        file_put_contents($directory.'/cpp.so', 'distinct cpp fixture bytes');
        file_put_contents($directory.'/rust.so', 'distinct rust fixture bytes');
        $fixtures = ['unit' => ['id' => 'unit', 'family' => 'common', 'path' => 'strict', 'admission' => 'supported',
            'article_sha256' => hash('sha256', 'article'), 'data_sha256' => hash('sha256', 'ABC'), 'exception' => null]];
        $protocol = ['source_sha256' => $sources, 'fixtures' => $fixtures,
            'fixtures_sha256' => hash('sha256', json_encode($fixtures, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'libraries' => [
                'cpp' => ['path' => $directory.'/cpp.so', 'sha256' => hash_file('sha256', $directory.'/cpp.so')],
                'rust' => ['path' => $directory.'/rust.so', 'sha256' => hash_file('sha256', $directory.'/rust.so')],
            ]];

        return $this->sign($protocol);
    }

    /** @param array<string, mixed> $protocol
     * @return array<string, mixed>
     */
    private function sign(array $protocol): array
    {
        unset($protocol['protocol_sha256']);
        $protocol['protocol_sha256'] = hash('sha256', json_encode($protocol, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $protocol;
    }

    public function test_exact_fixture_inventory_preserves_original_common_articles(): void
    {
        $ids = [];
        $common = 0;
        $rare = 0;
        foreach (fixtures() as $fixture) {
            self::assertArrayNotHasKey($fixture['id'], $ids);
            $ids[$fixture['id']] = true;
            if ($fixture['family'] === 'common') {
                $common++;
                if (str_starts_with($fixture['id'], 'single-')) {
                    self::assertSame(YencArticles::article($fixture['data']), $fixture['article']);
                    self::assertSame($fixture['path'] === 'strict' ? 'supported' : 'unused', $fixture['admission']);
                }
            } else {
                $rare++;
            }
        }
        self::assertCount(42, $ids);
        self::assertSame(32, $common);
        self::assertSame(10, $rare);
        self::assertArrayHasKey('single-8388608-heavy-body', $ids);
        self::assertArrayHasKey('multipart-4096-ordinary-strict', $ids);
        self::assertArrayHasKey('rare-numeric-overflow-fallback', $ids);
    }

    public function test_literal_rare_and_small_common_fixtures_match_the_real_php_service(): void
    {
        $count = 0;
        foreach (fixtures() as $fixture) {
            if ($fixture['family'] !== 'uncommon' && ! str_contains($fixture['id'], '-4096-')) {
                continue;
            }
            $result = measure(new YencService, $fixture, $fixture['article'], $fixture['data']);
            self::assertSame('ok', $result['status']);
            self::assertTrue($result['correct']);
            self::assertTrue($result['input_unchanged']);
            self::assertSame(2, $result['verified_calls']);
            self::assertGreaterThan(0, $result['median_ms']);
            $count++;
        }
        self::assertSame(18, $count);
    }

    public function test_exactly_one_warmup_and_one_measured_call_are_verified(): void
    {
        $service = new class extends YencService
        {
            public int $calls = 0;

            public function decode(string &$text, bool $ignore = false): string
            {
                $this->calls++;

                return 'ABC';
            }
        };
        $fixture = ['id' => 'unit', 'path' => 'strict', 'exception' => null];
        self::assertTrue(measure($service, $fixture, 'immutable', 'ABC')['correct']);
        self::assertSame(2, $service->calls);
    }

    public function test_incorrect_exception_and_input_mutation_are_rejected(): void
    {
        foreach ([false, true] as $mutate) {
            $service = new class($mutate) extends YencService
            {
                public function __construct(private readonly bool $mutate) {}

                public function decode(string &$text, bool $ignore = false): string
                {
                    if ($this->mutate) {
                        $text = 'changed';

                        return 'ABC';
                    }
                    throw new RuntimeException('Wrong exception.');
                }
            };
            try {
                measure($service, ['id' => 'unit', 'path' => 'strict', 'exception' => null], 'immutable', 'ABC');
                self::fail('Incorrect call was accepted.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('verification', $exception->getMessage());
            }
        }
    }

    public function test_cli_options_require_exact_roles_absolute_files_and_no_duplicates(): void
    {
        $base = ['--backend=cpp', '--library=/library.so', '--fixture-json=/fixture.json',
            '--article-path=/article', '--protocol-json=/protocol.json'];
        self::assertSame('cpp', options($base)['backend']);
        foreach ([['--backend=php', ...array_slice($base, 1)], [...$base, '--backend=cpp'],
            [...$base, '--unknown=x'], ['--backend=cpp'], ['--backend=cpp', '--library=relative', ...array_slice($base, 2)],
            ['--backend=cpp', '--library=/'.str_repeat('x', 4096), ...array_slice($base, 2)]] as $arguments) {
            try {
                options($arguments);
                self::fail('Invalid options were accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_protocol_binds_complete_source_both_libraries_and_actual_role(): void
    {
        $protocol = $this->protocol();
        verifyProtocol($protocol, 'cpp', $protocol['libraries']['cpp']['path']);
        $this->addToAssertionCount(1);
        foreach (['digest', 'source', 'missing', 'library', 'role', 'traversal'] as $case) {
            $bad = $protocol;
            $library = $protocol['libraries']['cpp']['path'];
            switch ($case) {
                case 'digest':
                    $bad['protocol_sha256'] = str_repeat('0', 64);
                    break;
                case 'source':
                    $bad['source_sha256']['scripts/yenc-strict-article-worker.php'] = str_repeat('0', 64);
                    break;
                case 'missing':
                    unset($bad['source_sha256']['app/Services/Yenc/ExperimentalStrictArticleDecoder.php']);
                    break;
                case 'library':
                    $bad['libraries']['rust']['sha256'] = str_repeat('0', 64);
                    break;
                case 'role':
                    $library = $protocol['libraries']['rust']['path'];
                    break;
                case 'traversal':
                    $bad['source_sha256']['../secret'] = str_repeat('0', 64);
                    break;
            }
            if ($case !== 'digest') {
                $bad = $this->sign($bad);
            }
            $rejected = false;
            try {
                verifyProtocol($bad, 'cpp', $library);
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Unbound protocol accepted: '.$case);
        }
        self::assertContains('scripts/benchmark-yenc-strict-article.php', sourcePaths());
        self::assertContains('vendor/composer/installed.json', sourcePaths());
        file_put_contents($protocol['libraries']['rust']['path'], 'changed other-role library');
        $this->expectException(RuntimeException::class);
        verifyProtocol($protocol, 'cpp', $protocol['libraries']['cpp']['path']);
    }

    public function test_vendor_source_bytes_are_mandatory_even_when_installed_metadata_is_unchanged(): void
    {
        $checkout = dirname(__DIR__, 2);
        $root = $this->directory().'/source';
        mkdir($root, 0700);
        foreach (sourcePaths() as $path) {
            if (! is_dir(dirname($root.'/'.$path))) {
                mkdir(dirname($root.'/'.$path), 0700, true);
            }
            file_put_contents($root.'/'.$path, 'isolated source fixture: '.$path);
        }
        $prefix = 'vendor/dariusiii/net_nntp/src/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($checkout.'/'.$prefix, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($checkout) + 1);
            if (! is_dir(dirname($root.'/'.$relative))) {
                mkdir(dirname($root.'/'.$relative), 0700, true);
            }
            copy($file->getPathname(), $root.'/'.$relative);
        }
        copy($checkout.'/vendor/composer/installed.json', $root.'/vendor/composer/installed.json');
        $paths = sourcePaths($root);
        self::assertContains($prefix.'Error.php', $paths);
        self::assertContains($prefix.'Protocol/ResponseCode.php', $paths);
        self::assertContains($prefix.'Client.php', $paths);
        self::assertContains($prefix.'Protocol/Client.php', $paths);
        $sources = [];
        foreach ($paths as $path) {
            $sources[$path] = hash_file('sha256', $root.'/'.$path);
        }
        verifySourceClosure($sources, $root);
        $installed = hash_file('sha256', $root.'/vendor/composer/installed.json');
        $errorPath = $root.'/'.$prefix.'Error.php';
        $original = file_get_contents($errorPath);
        self::assertIsString($original);
        try {
            file_put_contents($errorPath, $original."\n// Private consumed-source custody mutation.\n");
            $rejected = false;
            try {
                verifySourceClosure($sources, $root);
            } catch (RuntimeException $error) {
                $rejected = true;
                self::assertStringContainsString('identity mismatch', $error->getMessage());
            }
            self::assertTrue($rejected, 'Changed Net_NNTP bytes were admitted with unchanged installed metadata.');
            self::assertSame($installed, hash_file('sha256', $root.'/vendor/composer/installed.json'));
        } finally {
            file_put_contents($errorPath, $original);
        }
        verifySourceClosure($sources, $root);
        self::assertSame(hash('sha256', $original), hash_file('sha256', $errorPath));
        unset($sources[$prefix.'Error.php']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incomplete worker source closure.');
        verifySourceClosure($sources, $root);
    }

    public function test_fixture_and_file_bounds_reject_aliases_stale_hashes_and_unknown_admission(): void
    {
        $fixture = ['id' => 'unit', 'family' => 'common', 'path' => 'strict', 'admission' => 'supported',
            'article_sha256' => hash('sha256', 'article'), 'data_sha256' => hash('sha256', 'ABC'), 'exception' => null];
        verifyFixture($fixture, 'article', 'ABC');
        $this->addToAssertionCount(1);
        foreach (['hash', 'data', 'admission', 'exception'] as $case) {
            $bad = $fixture;
            match ($case) {
                'hash' => $bad['article_sha256'] = str_repeat('0', 64),
                'data' => $bad['data_sha256'] = str_repeat('0', 64),
                'admission' => $bad['admission'] = 'unused',
                'exception' => $bad['exception'] = ['class' => 'Other', 'message' => 'wrong'],
            };
            $rejected = false;
            try {
                verifyFixture($bad, 'article', 'ABC');
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Invalid fixture was accepted.');
        }
        $path = $this->directory().'/input';
        file_put_contents($path, 'abcd');
        self::assertSame('abcd', regularFile($path, 4));
        symlink($path, $this->directory().'/alias');
        foreach ([[$path, 3], [$this->directory().'/alias', 4], [$this->directory(), 99]] as [$candidate, $cap]) {
            $rejected = false;
            try {
                regularFile($candidate, $cap);
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Invalid bounded file was accepted.');
        }
    }

    public function test_fixture_expectations_and_admission_are_bound_to_the_protocol(): void
    {
        $protocol = $this->protocol();
        $fixture = $protocol['fixtures']['unit'];
        verifyFixtureBinding($protocol, $fixture);
        $this->addToAssertionCount(1);
        foreach (['admission', 'exception', 'aggregate', 'missing'] as $case) {
            $badProtocol = $protocol;
            $badFixture = $fixture;
            match ($case) {
                'admission' => $badFixture['admission'] = 'fallback',
                'exception' => $badFixture['exception'] = ['class' => RuntimeException::class, 'message' => 'forged expected error'],
                'aggregate' => $badProtocol['fixtures_sha256'] = str_repeat('0', 64),
                'missing' => $badFixture['id'] = 'absent',
            };
            $rejected = false;
            try {
                verifyFixtureBinding($badProtocol, $badFixture);
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Fixture custody mismatch was accepted.');
        }
    }

    /** @param list<string> $command
     * @return array{int, string, string}
     */
    private function child(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function test_helper_require_is_silent_without_autoload_or_cli_side_effects(): void
    {
        $path = dirname(__DIR__, 2).'/scripts/yenc-strict-article-worker.php';
        [$code, $out, $err] = $this->child([PHP_BINARY, '-r', 'require '.var_export($path, true).'; echo "helper-only";']);
        self::assertSame([0, 'helper-only', ''], [$code, $out, $err]);
    }

    public function test_actual_cli_retains_missing_input_and_stale_protocol_failure_as_invalid_json(): void
    {
        $script = dirname(__DIR__, 2).'/scripts/yenc-strict-article-worker.php';
        [$code, $out, $err] = $this->child([PHP_BINARY, $script, '--backend=php']);
        self::assertSame(2, $code);
        self::assertSame('', $err);
        self::assertSame('invalid', json_decode($out, true, flags: JSON_THROW_ON_ERROR)['status']);
        $protocol = $this->protocol();
        $protocol['protocol_sha256'] = str_repeat('0', 64);
        $path = $this->directory().'/protocol.json';
        file_put_contents($path, json_encode($protocol, JSON_THROW_ON_ERROR));
        [$code, $out, $err] = $this->child([PHP_BINARY, $script, '--backend=cpp', '--library='.$protocol['libraries']['cpp']['path'],
            '--protocol-json='.$path, '--fixture-json='.$this->directory().'/absent-fixture.json', '--article-path='.$this->directory().'/absent-article']);
        self::assertSame(2, $code);
        self::assertSame('', $err);
        $result = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invalid', $result['status']);
        self::assertFalse($result['correct']);
        self::assertStringContainsString('protocol hash', $result['error']);
        self::assertArrayNotHasKey('median_ms', $result);
    }

    public function test_only_absent_optional_strict_capability_allows_native_integration_skip(): void
    {
        foreach ([
            [false, 'symbol_or_ffi_unavailable', true],
            [false, 'known_answer_failed', false],
            [false, 'unsupported_runtime', false],
            [false, 'library_absent', false],
            [false, null, false],
            [true, null, false],
            [true, 'symbol_or_ffi_unavailable', false],
        ] as [$available, $reason, $expected]) {
            self::assertSame($expected, self::strictCapabilityMayBeSkipped($available, $reason));
        }
    }

    private static function strictCapabilityMayBeSkipped(bool $available, ?string $reason): bool
    {
        return ! $available && $reason === 'symbol_or_ffi_unavailable';
    }

    public function test_linux_native_cli_uses_both_roles_for_supported_fallback_unused_and_expected_error(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            self::markTestSkipped('Actual NativePayloadDecoder worker integration requires Linux CLI FFI.');
        }
        $root = dirname(__DIR__, 2);
        $rust = realpath(getenv('YENC_NATIVE_LIBRARY') ?: $root.'/docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so');
        $cpp = realpath($root.'/storage/app/rapidyenc/librapidyenc.so');
        if ($rust === false || $cpp === false) {
            self::markTestSkipped('Build pinned C++ and experimental Rust libraries before native worker integration.');
        }
        $experimental = new ExperimentalStrictArticleDecoder($rust);
        if (self::strictCapabilityMayBeSkipped($experimental->isAvailable(), $experimental->unavailabilityReason())) {
            self::markTestSkipped('Optional experimental-strict-article capability is absent or CLI FFI is unavailable.');
        }
        self::assertTrue($experimental->isAvailable(), 'Experimental strict capability must qualify: '.($experimental->unavailabilityReason() ?? 'unknown'));
        $protocol = $this->protocol();
        $protocol['libraries'] = [
            'cpp' => ['path' => $cpp, 'sha256' => hash_file('sha256', $cpp)],
            'rust' => ['path' => $rust, 'sha256' => hash_file('sha256', $rust)],
        ];
        $selected = array_fill_keys(['single-4096-ordinary-strict', 'single-4096-ordinary-tolerant', 'single-4096-ordinary-body',
            'multipart-4096-heavy-strict', 'rare-multipart-split', 'rare-duplicate-fallback', 'rare-trailer-name-crc-fallback', 'rare-crc-mismatch'], true);
        $verified = 0;
        foreach (fixtures() as $fixture) {
            if (! isset($selected[$fixture['id']])) {
                continue;
            }
            $metadata = array_diff_key($fixture, ['article' => true, 'data' => true]);
            $metadata['article_sha256'] = hash('sha256', $fixture['article']);
            $metadata['data_sha256'] = $fixture['data'] === null ? null : hash('sha256', $fixture['data']);
            $protocol['fixtures'] = [$fixture['id'] => $metadata];
            $protocol['fixtures_sha256'] = hash('sha256', json_encode($protocol['fixtures'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $protocol = $this->sign($protocol);
            $directory = $this->directory();
            file_put_contents($directory.'/protocol.json', json_encode($protocol, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/fixture.json', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/article', $fixture['article']);
            if ($fixture['data'] !== null) {
                file_put_contents($directory.'/data', $fixture['data']);
            }
            foreach (['cpp', 'rust'] as $backend) {
                $command = [PHP_BINARY, '-d', 'ffi.enable=true', $root.'/scripts/yenc-strict-article-worker.php',
                    '--backend='.$backend, '--library='.$protocol['libraries'][$backend]['path'],
                    '--protocol-json='.$directory.'/protocol.json', '--fixture-json='.$directory.'/fixture.json', '--article-path='.$directory.'/article'];
                if ($fixture['data'] !== null) {
                    $command[] = '--data-path='.$directory.'/data';
                }
                [$code, $out, $err] = $this->child($command);
                self::assertSame(0, $code, $out.$err);
                self::assertSame('', $err);
                $result = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('ok', $result['status']);
                self::assertSame($backend, $result['backend']);
                self::assertSame($fixture['id'], $result['id']);
                self::assertSame($protocol['libraries'][$backend]['sha256'], $result['library_sha256']);
                self::assertSame($protocol['protocol_sha256'], $result['protocol_sha256']);
                self::assertTrue($result['correct']);
                self::assertTrue($result['input_unchanged']);
                self::assertSame($fixture['admission'], $result['admission']);
                self::assertSame(match ($fixture['path']) {
                    'strict' => 'strict_unchanged', 'tolerant' => 'tolerant_decoded_in_place', 'body' => 'body_stream_source_unchanged',
                    default => throw new RuntimeException('Invalid generated fixture path.'),
                }, $result['input_contract']);
                self::assertGreaterThan(0, $result['elapsed_ns']);
                self::assertSame($result['elapsed_ms'], $result['median_ms']);
                self::assertLessThan(262144, strlen($out));
                $verified++;
            }
        }
        self::assertSame(16, $verified);
    }
}
