<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\IndexLogs;
use App\Console\Commands\SteamSearchGames;
use App\Console\Commands\UpdateReleasesIndexSchema;
use App\Console\Commands\UpdateReleasesIndexSchemaES;
use App\Http\Controllers\Api\ApiQueryOptionsController;
use App\Http\Middleware\DecorateHttpQueryResponses;
use App\Services\LogViewer\Index\LogIndex;
use App\Services\LogViewer\Index\LogIndexer;
use App\Services\SteamService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Laravel\Prompts\Prompt;
use Manticoresearch\Client;
use Manticoresearch\Table;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Wave 30.5 caller-inclusive benchmarks:
 * NNTMUX_SEARCH_SCHEMA_LOG_STEAM_BENCH=1 php artisan test --compact tests/Unit/RustSearchIndexSchemaLogSteamCommandsParityTest.php
 * Uses 3 repetitions, 100 warmups and 1001 randomized pairs per workload, with
 * raw per-pair nanoseconds on STDERR. PHP stays default unless every path qualifies.
 *
 * Release build, Apple M5 arm64, PHP 8.5.12, rustc 1.99.1; baseline 72f42a5d16c2.
 * Worst repetition PHP/Rust caller latency (mean / p99), 2026-10-25:
 * Helper                            Common          Uncommon
 * normalizeFileFilter               0.153 / 0.174    0.068 / 0.054
 * formatIndexedSummary              0.153 / 0.157    0.160 / 0.162
 * projectSearchResultRow            0.192 / 0.184    0.159 / 0.150
 * formatFoundCountBanner            0.133 / 0.167    0.137 / 0.130
 * formatAllowedMethodsHeader        0.151 / 0.139    0.039 / 0.038
 * buildOptionsHeaders               0.151 / 0.157    0.041 / 0.040
 * projectManticoreColumnRows         0.119 / 0.129    0.089 / 0.095
 * computeMissingManticoreFields      0.127 / 0.119    0.056 / 0.053
 * resolveManticoreAlterColumnSql     0.137 / 0.134    0.122 / 0.118
 * projectEsPropertyRow              0.107 / 0.111    0.124 / 0.125
 * computeMissingMediaFields         0.108 / 0.105    0.048 / 0.046
 * buildMediaDocPayload              0.104 / 0.094    0.081 / 0.079
 *
 * All fail the 1.5x gate. Raw randomized pairs: /tmp/w30-5-benchmark.jsonl.
 * These small local measurements establish no production performance claim.
 */
final class RustSearchIndexSchemaLogSteamCommandsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [
        IndexLogs::class, SteamSearchGames::class, ApiQueryOptionsController::class,
        UpdateReleasesIndexSchema::class, UpdateReleasesIndexSchemaES::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
        parent::tearDown();
    }

    public function test_toggles_keep_php_by_default_and_reset_to_php(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $this->assertTrue($class::useRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::useRustFfi());
        }
    }

    /** @return iterable<array{class-string, string, list<mixed>, mixed}> */
    public static function cases(): iterable
    {
        $stringable = new class implements Stringable
        {
            public function __toString(): string
            {
                return "object\0é";
            }
        };
        foreach ([null, false, true, '', "é\0.log", -12, 2.5, ['a' => false, 'b' => true, 3 => null, 9 => "é\0"], [$stringable], (object) ['first' => 'a', 'second' => -2]] as $files) {
            yield [IndexLogs::class, 'normalizeFileFilter', [$files], array_values(array_map('strval', (array) $files))];
        }
        foreach ([0, -1, 12345, PHP_INT_MIN, PHP_INT_MAX] as $n) {
            yield [IndexLogs::class, 'formatIndexedSummary', [$n, "1\0é KiB", $n, $n], sprintf('Indexed %d entries (%s) from %d file(s); %d file(s) purged.', $n, "1\0é KiB", $n, $n)];
            yield [SteamSearchGames::class, 'formatFoundCountBanner', [$n], "Found {$n} result(s):"];
        }
        foreach ([0.0, -0.0, -0.01, -0.05, 0.05, 1.15, -1.15, 99.999, 999.94, 999.95, 1000.0, -1234567.85, INF, -INF, NAN, 1.14999999999999] as $score) {
            $name = str_repeat('é', 29)."a€\0";
            yield [SteamSearchGames::class, 'projectSearchResultRow', [-42, $name, $score], [-42, substr($name, 0, 60), number_format($score, 1).'%', 'https://store.steampowered.com/app/-42']];
        }
        foreach ([PHP_INT_MIN, PHP_INT_MAX, 0] as $id) {
            yield [SteamSearchGames::class, 'projectSearchResultRow', [$id, "\0\xff", 75.3], [$id, "\0\xff", '75.3%', 'https://store.steampowered.com/app/'.$id]];
        }
        foreach ([[], ['GET', 'HEAD', 'QUERY', 'GET', 'OPTIONS'], ['a' => '', 'b' => "\0", 'c' => 'é', 'd' => 'é'], [true, 1, '1', false, '', null, 0, '0', 1.0], [$stringable, $stringable]] as $methods) {
            $allow = implode(', ', array_unique($methods));
            yield [ApiQueryOptionsController::class, 'formatAllowedMethodsHeader', [$methods], $allow];
            yield [ApiQueryOptionsController::class, 'buildOptionsHeaders', [$methods, "0\0é"], ['Allow' => $allow, 'Accept-Query' => "0\0é"]];
        }
        yield [ApiQueryOptionsController::class, 'buildOptionsHeaders', [[]], ['Allow' => '', 'Accept-Query' => '1']];
        foreach ([[], ['id' => ['Type' => 'bigint'], 'name' => ['Type' => 'text', 'Properties' => ['indexed', 'stored']]], [7 => ['Type' => false, 'Properties' => ''], "é\0" => ['Properties' => [false, true, 0, -2, null, "\0é"]]], ['x' => ['Properties' => (object) ['x' => 'stored']]], ['x' => ['Properties' => [$stringable]]]] as $columns) {
            $rows = [];
            foreach ($columns as $field => $props) {
                $rows[] = [$field, $props['Type'] ?? 'unknown', isset($props['Properties']) ? implode(', ', (array) $props['Properties']) : ''];
            }
            yield [UpdateReleasesIndexSchema::class, 'projectManticoreColumnRows', [$columns], $rows];
        }
        $expected = ['id' => null, 'a' => ['type' => 'text'], 'b' => false, 5 => $stringable, "é\0" => ['type' => 'keyword']];
        foreach ([[], ['id' => 0, 'a' => null, 'b' => false, 5 => '', "é\0" => []], array_fill_keys(array_keys($expected), true)] as $existing) {
            $missing = $esMissing = [];
            foreach ($expected as $field => $config) {
                if (! isset($existing[$field])) {
                    $esMissing[$field] = $config;
                    if ($field !== 'id') {
                        $missing[$field] = $config;
                    }
                }
            }
            yield [UpdateReleasesIndexSchema::class, 'computeMissingManticoreFields', [$expected, $existing], $missing];
            yield [UpdateReleasesIndexSchemaES::class, 'computeMissingMediaFields', [$expected, $existing], $esMissing];
        }
        foreach (['text' => 'text', 'int' => 'uint', 'bigint' => 'bigint', 'string' => 'string', '' => null, 'INT' => null, 'integer' => null, "int\0" => null] as $type => $sqlType) {
            yield [UpdateReleasesIndexSchema::class, 'resolveManticoreAlterColumnSql', ["table\0é", 'field', $type], $sqlType === null ? null : "ALTER TABLE table\0é ADD COLUMN field {$sqlType}"];
        }
        foreach ([[], ['type' => 'text', 'analyzer' => "é\0", 'index' => false, 'fields' => ['raw' => []]], ['type' => false, 'analyzer' => 0, 'index' => 0, 'fields' => []], ['type' => null, 'analyzer' => false, 'index' => '', 'fields' => '0'], ['analyzer' => $stringable]] as $config) {
            $details = [];
            if (isset($config['analyzer'])) {
                $details[] = "analyzer: {$config['analyzer']}";
            }
            if (($config['index'] ?? null) === false) {
                $details[] = 'not indexed';
            }
            if (! empty($config['fields'])) {
                $details[] = 'multi-field';
            }
            yield [UpdateReleasesIndexSchemaES::class, 'projectEsPropertyRow', ["field\0é", $config], ["field\0é", $config['type'] ?? 'unknown', implode(', ', $details)]];
        }
        foreach ([null, '', false, true, 0, -3, 3.25, "tt\0é", $stringable] as $imdbid) {
            yield [UpdateReleasesIndexSchemaES::class, 'buildMediaDocPayload', [$imdbid, PHP_INT_MIN, PHP_INT_MAX], ['imdbid' => (string) ($imdbid ?? ''), 'tmdbid' => PHP_INT_MIN, 'traktid' => PHP_INT_MAX]];
        }
    }

    public function test_all_helpers_have_parity_with_forced_rust_and_unavailable_library(): void
    {
        foreach ([false, true] as $available) {
            foreach (self::CLASSES as $class) {
                $class::setFfiForTesting($available ? $this->library() : false);
            }
            foreach (self::cases() as [$class, $method, $args, $expected]) {
                $this->assertSame($expected, $class::{$method.'Php'}(...$args), $method.' PHP');
                $this->assertSame($expected, $class::{$method.'Native'}(...$args), $method.' native');
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $method.' dispatch');
                }
            }
        }
    }

    public function test_randomized_binary_names_and_score_parity(): void
    {
        SteamSearchGames::setFfiForTesting($this->library());
        mt_srand(3005);
        for ($i = 0; $i < 1500; $i++) {
            $name = '';
            for ($j = 0, $length = mt_rand(0, 130); $j < $length; $j++) {
                $name .= chr(mt_rand(0, 255));
            }
            $score = mt_rand(-9999999, 9999999) / 10000;
            $this->assertSame(SteamSearchGames::projectSearchResultRowPhp(-$i, $name, $score), SteamSearchGames::projectSearchResultRowNative(-$i, $name, $score));
        }
    }

    public function test_non_scalar_fallback_preserves_php_errors_and_warnings(): void
    {
        $cases = [
            [IndexLogs::class, 'normalizeFileFilter', [[['nested']]]],
            [IndexLogs::class, 'normalizeFileFilter', [[new \stdClass]]],
            [ApiQueryOptionsController::class, 'formatAllowedMethodsHeader', [[['nested'], ['other']]]],
            [UpdateReleasesIndexSchemaES::class, 'buildMediaDocPayload', [[], 0, 0]],
            [UpdateReleasesIndexSchemaES::class, 'buildMediaDocPayload', [new \stdClass, 0, 0]],
        ];
        foreach ($cases as [$class, $method, $args]) {
            $class::setFfiForTesting($this->library());
            $outcomes = [];
            foreach (['Php', 'Native'] as $suffix) {
                $warnings = [];
                set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                    $warnings[] = [$severity, $message];

                    return true;
                });
                try {
                    $outcomes[] = ['result' => $class::{$method.$suffix}(...$args), 'warnings' => $warnings];
                } catch (Throwable $e) {
                    $outcomes[] = ['error' => $e::class, 'message' => $e->getMessage(), 'warnings' => $warnings];
                } finally {
                    restore_error_handler();
                }
            }
            $this->assertSame($outcomes[0], $outcomes[1], $method);
        }
    }

    public function test_real_ffi_packet_and_buffer_failure_contracts(): void
    {
        $ffi = $this->library();
        $call = static fn (mixed ...$args): mixed => self::callFfi($ffi, 'nntmux_search_schema_log_steam', ...$args);
        $packet = pack('V', 1).pack('V', 3)."a\0\xff";
        $out = $ffi->new('char[128]');
        FFI::memset($out, 90, 128);
        $this->assertSame(strlen($packet), $call(1, $packet, strlen($packet), $out, 128));
        $this->assertSame($packet, FFI::string($out, strlen($packet)));
        FFI::memset($out, 90, 128);
        $this->assertSame(-strlen($packet), $call(1, $packet, strlen($packet), $out, 4));
        $this->assertSame(str_repeat('Z', 128), FFI::string($out, 128));
        foreach ([[99, $packet, strlen($packet), $out, 128], [1, null, 1, $out, 128], [1, $packet, strlen($packet), null, 128], [1, $packet, -1, $out, 128], [1, $packet, strlen($packet), $out, -1], [1, substr($packet, 0, -1), strlen($packet) - 1, $out, 128]] as $args) {
            $this->assertSame(PHP_INT_MIN, $call(...$args));
            $this->assertSame(str_repeat('Z', 128), FFI::string($out, 128));
        }
        // Each supported fixture must cross the real ABI successfully, so fallback
        // cannot make an absent/stale symbol appear to pass the parity suite.
        $pack = static function (array $items): string {
            $result = pack('V', count($items));
            foreach ($items as $item) {
                $result .= pack('V', strlen($item)).$item;
            }

            return $result;
        };
        foreach ([1 => ['x'], 2 => ['-1', "é\0", '2', '3'], 3 => ['4', str_repeat('é', 40), pack('e', 75.3)], 4 => ['3'], 5 => ['GET', 'GET', 'QUERY'], 6 => ['1', 'GET'], 7 => ['field', 'text', '2', 'indexed', 'stored'], 8 => ['id', '0', '0', 'name', '0', '1'], 9 => ['releases_rt', 'name', 'text'], 10 => ['name', 'text', '1', 'standard', '1', '1'], 11 => ['id', '0', '0'], 12 => ["tt\0", '-3', '4']] as $mode => $items) {
            $input = $pack($items);
            $this->assertGreaterThanOrEqual(4, $call($mode, $input, strlen($input), $out, 128), 'mode '.$mode);
        }
    }

    public function test_wired_log_command_uses_helpers(): void
    {
        foreach ([false, true] as $enabled) {
            IndexLogs::setFfiForTesting($this->library());
            IndexLogs::setUseRustFfi($enabled);
            $index = $this->createMock(LogIndex::class);
            $index->method('isEnabled')->willReturn(true);
            $index->method('isAvailable')->willReturn(true);
            $indexer = $this->createMock(LogIndexer::class);
            $indexer->expects($this->once())->method('run')->with(['laravel.log'])->willReturn(['entries' => 12345, 'bytes' => 0, 'files' => 1, 'purged' => 0]);
            $command = new IndexLogs;
            $input = new ArrayInput(['--file' => ['laravel.log']], $command->getDefinition());
            $buffer = new BufferedOutput;
            $output = new OutputStyle($input, $buffer);
            $command->setInput($input);
            $command->setOutput($output);
            $this->assertSame(0, $command->handle($index, $indexer));
            $this->assertStringContainsString('Indexed 12345 entries', $buffer->fetch());
        }
    }

    public function test_options_controller_wires_deduplicated_headers(): void
    {
        $original = Container::getInstance();
        try {
            foreach ([false, true] as $enabled) {
                ApiQueryOptionsController::setFfiForTesting($this->library());
                ApiQueryOptionsController::setUseRustFfi($enabled);
                $container = new Container;
                Container::setInstance($container);
                $factory = $this->createMock(ResponseFactory::class);
                $factory->expects($this->once())->method('noContent')->willReturn(new Response('', 204));
                $container->instance(ResponseFactory::class, $factory);
                $router = new Router(new Dispatcher($container), $container);
                $router->get('api/test', static fn () => '');
                $router->match(['GET', 'QUERY'], 'api/test', static fn () => '');
                $route = $router->options('api/test', ApiQueryOptionsController::class);
                $request = Request::create('/api/test', 'OPTIONS');
                $request->setRouteResolver(static fn () => $route);
                $response = (new ApiQueryOptionsController)($request, $router);
                $this->assertSame(204, $response->getStatusCode());
                $this->assertSame('GET, HEAD, QUERY, OPTIONS', $response->headers->get('Allow'));
                $this->assertSame(DecorateHttpQueryResponses::ACCEPT_QUERY, $response->headers->get('Accept-Query'));
            }
        } finally {
            Container::setInstance($original);
        }
    }

    public function test_steam_command_wires_projected_rows_and_banner(): void
    {
        $buffer = new BufferedOutput;
        $prompt = new \ReflectionClass(Prompt::class);
        $outputProperty = $prompt->getProperty('output');
        $oldOutput = $outputProperty->isInitialized() ? $outputProperty->getValue() : new ConsoleOutput;
        $interactiveProperty = $prompt->getProperty('interactive');
        $wasInitialized = $interactiveProperty->isInitialized();
        $oldInteractive = $wasInitialized ? $interactiveProperty->getValue() : null;
        try {
            Prompt::setOutput($buffer);
            Prompt::interactive(false);
            foreach ([false, true] as $enabled) {
                SteamSearchGames::setFfiForTesting($this->library());
                SteamSearchGames::setUseRustFfi($enabled);
                $service = $this->createMock(SteamService::class);
                $service->expects($this->once())->method('searchMultiple')->with('Portal', 10)->willReturn(collect([['appid' => 400, 'name' => 'Portal', 'score' => 92.3]]));
                $command = new SteamSearchGames;
                $command->setInput(new ArrayInput(['query' => 'Portal'], $command->getDefinition()));
                $this->assertSame(0, $command->handle($service));
                $content = $buffer->fetch();
                $this->assertStringContainsString('Found 1 result(s):', $content);
                $this->assertStringContainsString('92.3%', $content);
                $this->assertStringContainsString('https://store.steampowered.com/app/400', $content);
            }
        } finally {
            $outputProperty->setValue(null, $oldOutput);
            // Prompts defaults to noninteractive in this isolated console test.
            $interactiveProperty->setValue(null, $oldInteractive ?? false);
        }
    }

    public function test_manticore_command_wires_missing_fields_and_alter_sql(): void
    {
        foreach ([false, true] as $enabled) {
            UpdateReleasesIndexSchema::setFfiForTesting($this->library());
            UpdateReleasesIndexSchema::setUseRustFfi($enabled);
            $columns = array_fill_keys(['tmdbid', 'traktid', 'tvdb', 'tvmaze', 'tvrage', 'videos_id', 'movieinfo_id', 'size', 'postdate_ts', 'adddate_ts', 'totalpart', 'grabs', 'passwordstatus', 'groups_id', 'nzbstatus', 'haspreview'], ['Type' => 'int']);
            $table = $this->createMock(Table::class);
            $table->method('describe')->willReturn($columns);
            $table->expects($this->once())->method('alter')->with('add', 'imdbid', 'int')->willReturn([]);
            $client = $this->createMock(Client::class);
            $client->method('table')->with('releases_rt')->willReturn($table);
            $command = $this->getMockBuilder(UpdateReleasesIndexSchema::class)->onlyMethods(['confirm'])->getMock();
            $command->method('confirm')->willReturn(true);
            $input = new ArrayInput([], $command->getDefinition());
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, new BufferedOutput));
            (new \ReflectionProperty(UpdateReleasesIndexSchema::class, 'client'))->setValue($command, $client);
            $method = new \ReflectionMethod(UpdateReleasesIndexSchema::class, 'addNewFields');
            $this->assertSame(0, $method->invoke($command));
        }
    }

    public function test_es_command_wires_media_doc_payload_without_changing_other_ids(): void
    {
        $release = (object) ['imdbid' => "tt\0é", 'tmdbid' => 0, 'video_tmdb' => 42, 'traktid' => -4, 'video_trakt' => 99, 'tvdb' => 1, 'tvmaze' => 2, 'tvrage' => 3, 'videos_id' => 4, 'movieinfo_id' => 5];
        foreach ([false, true] as $enabled) {
            UpdateReleasesIndexSchemaES::setFfiForTesting($this->library());
            UpdateReleasesIndexSchemaES::setUseRustFfi($enabled);
            $method = new \ReflectionMethod(UpdateReleasesIndexSchemaES::class, 'prepareMediaData');
            $this->assertSame(['imdbid' => "tt\0é", 'tmdbid' => 42, 'traktid' => -4, 'tvdb' => 1, 'tvmaze' => 2, 'tvrage' => 3, 'videos_id' => 4, 'movieinfo_id' => 5], $method->invoke(new UpdateReleasesIndexSchemaES, $release));
        }
    }

    public function test_paired_caller_benchmarks(): void
    {
        if (getenv('NNTMUX_SEARCH_SCHEMA_LOG_STEAM_BENCH') !== '1') {
            $this->markTestSkipped('Opt-in caller-inclusive benchmark.');
        }
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting($this->library());
        }
        fwrite(STDERR, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'seed' => 3005, 'warmups' => 100, 'samples' => 1001, 'repetitions' => 3]).PHP_EOL);
        $workloads = [
            [IndexLogs::class, 'normalizeFileFilter', [['a.log', 'b.log']], [array_fill(0, 100, str_repeat('é', 80))]],
            [IndexLogs::class, 'formatIndexedSummary', [42, '2 KiB', 2, 0], [PHP_INT_MAX, str_repeat("é\0", 200), PHP_INT_MIN, -4]],
            [SteamSearchGames::class, 'projectSearchResultRow', [42, 'Game', 92.3], [-42, str_repeat('é', 1000), -0.01]],
            [SteamSearchGames::class, 'formatFoundCountBanner', [5], [PHP_INT_MIN]],
            [ApiQueryOptionsController::class, 'formatAllowedMethodsHeader', [['GET', 'HEAD', 'QUERY', 'OPTIONS']], [array_fill(0, 200, 'QUERY')]],
            [ApiQueryOptionsController::class, 'buildOptionsHeaders', [['GET', 'QUERY']], [array_fill(0, 200, 'QUERY'), str_repeat('x', 500)]],
            [UpdateReleasesIndexSchema::class, 'projectManticoreColumnRows', [['x' => ['Type' => 'text', 'Properties' => ['stored']]]], [array_fill_keys(range(1, 100), ['Type' => 'text', 'Properties' => ['stored', 'indexed']])]],
            [UpdateReleasesIndexSchema::class, 'computeMissingManticoreFields', [['id' => [], 'x' => []], []], [array_fill_keys(range(1, 100), ['type' => 'int']), []]],
            [UpdateReleasesIndexSchema::class, 'resolveManticoreAlterColumnSql', ['releases_rt', 'imdbid', 'int'], [str_repeat('table', 100), str_repeat('field', 100), 'text']],
            [UpdateReleasesIndexSchemaES::class, 'projectEsPropertyRow', ['name', ['type' => 'text']], [str_repeat('x', 500), ['analyzer' => str_repeat('é', 200), 'index' => false, 'fields' => ['raw' => []]]]],
            [UpdateReleasesIndexSchemaES::class, 'computeMissingMediaFields', [['id' => [], 'x' => []], []], [array_fill_keys(range(1, 100), ['type' => 'int']), []]],
            [UpdateReleasesIndexSchemaES::class, 'buildMediaDocPayload', ['tt123', 12, 34], [str_repeat("é\0", 500), PHP_INT_MIN, PHP_INT_MAX]],
        ];
        mt_srand(3005);
        foreach ($workloads as [$class, $method, $common, $uncommon]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $args) {
                for ($rep = 0; $rep < 3; $rep++) {
                    $class::setUseRustFfi(false);
                    $expected = $class::$method(...$args);
                    $class::setUseRustFfi(true);
                    $this->assertSame($expected, $class::$method(...$args));
                    foreach ([false, true] as $enabled) {
                        $class::setUseRustFfi($enabled);
                        for ($i = 0; $i < 100; $i++) {
                            $class::$method(...$args);
                        }
                    }
                    $times = [[], []];
                    for ($sample = 0; $sample < 1001; $sample++) {
                        foreach (mt_rand(0, 1) ? [true, false] : [false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            $start = hrtime(true);
                            $class::$method(...$args);
                            $times[(int) $enabled][] = hrtime(true) - $start;
                        }
                    }
                    fwrite(STDERR, json_encode(['method' => $class.'::'.$method, 'workload' => $kind, 'rep' => $rep, 'php_ns' => $times[0], 'rust_ns' => $times[1]]).PHP_EOL);
                    sort($times[0]);
                    sort($times[1]);
                    fwrite(STDERR, json_encode(['summary' => $method, 'workload' => $kind, 'rep' => $rep, 'mean_speedup' => array_sum($times[0]) / array_sum($times[1]), 'p99_speedup' => $times[0][990] / $times[1][990]]).PHP_EOL);
                }
            }
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function library(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search-schema-log-steam-parity', IndexLogs::FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        $this->assertInstanceOf(FFI::class, $ffi, 'Build the release library before running native parity tests.');

        return $ffi;
    }
}
