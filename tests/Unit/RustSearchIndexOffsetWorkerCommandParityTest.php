<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxCreateESIndexes;
use App\Console\Commands\NntmuxESReindex;
use App\Console\Commands\NntmuxOffsetPopulate;
use App\Console\Commands\NntmuxOffsetWorker;
use App\Console\Commands\NntmuxPopulateSearchIndexes;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib, PHP 8.5 on Apple Silicon Darwin. See the commit message for the measured run; each run prints
 * its ratios to STDERR.
 *
 * Packet marshaling, FFI scratch access and result decoding dominate these small planners, so Rust
 * misses the 1.5x gate and every helper keeps PHP as the default (`usesRustFfi() === false`).
 */
class RustSearchIndexOffsetWorkerCommandParityTest extends TestCase
{
    private const CLASSES = [
        NntmuxCreateESIndexes::class,
        NntmuxESReindex::class,
        NntmuxOffsetPopulate::class,
        NntmuxOffsetWorker::class,
        NntmuxPopulateSearchIndexes::class,
    ];

    private const INDEXES = ['releases', 'predb', 'movies', 'tvshows', 'music', 'books', 'games', 'console', 'steam', 'anime'];

    private const RANDOM_ROUNDS = 1200;

    /** Bytes that exercise trimming, case folding, index-name rules, shell quoting and invalid UTF-8. */
    private const ALPHABET = ['a', 'e', 's', 'Z', '0', '7', '-', '_', '+', '.', '/', '\\', ' ', "\t", "\n", "\0", "\x0B", "\x01", "\x7F", ',', '#', ':', '*', '"', "'", "\xc3", "\xa9", "\xff", 'K', 'm'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse((new ReflectionClassConstant($class, 'RUST_FFI_DEFAULT'))->getValue());
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP");
            $class::useRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::useRustFfi(true);
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
            $class::useRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: array<string, ?string>, 1: list<string>, 2: bool, 3: array<string, int>, 4: array<string, array{0: string, 1: string}>}>
     */
    public static function indexCreationProvider(): array
    {
        return [
            'defaults, nothing exists' => [[], [], false, ['create' => 10, 'recreate' => 0, 'preserve' => 0, 'invalid' => 0, 'duplicate' => 0], ['releases' => ['releases', 'create'], 'anime' => ['anime', 'create']]],
            'existing recreated' => [[], ['predb', 'steam', 'other'], false, ['create' => 8, 'recreate' => 2, 'preserve' => 0, 'invalid' => 0, 'duplicate' => 0], ['predb' => ['predb', 'recreate'], 'steam' => ['steam', 'recreate']]],
            'existing preserved' => [['predb' => 'nntmux_predb'], ['nntmux_predb', 'predb'], true, ['create' => 9, 'recreate' => 0, 'preserve' => 1, 'invalid' => 0, 'duplicate' => 0], ['predb' => ['nntmux_predb', 'preserve']]],
            'null falls back' => [['movies' => null], ['movies'], true, ['create' => 9, 'recreate' => 0, 'preserve' => 1, 'invalid' => 0, 'duplicate' => 0], ['movies' => ['movies', 'preserve']]],
            'invalid names' => [['releases' => 'Releases', 'predb' => '', 'movies' => '_m', 'tvshows' => 'a b', 'music' => '..', 'books' => "x\x01"], [], false, ['create' => 4, 'recreate' => 0, 'preserve' => 0, 'invalid' => 6, 'duplicate' => 0], ['releases' => ['Releases', 'invalid'], 'predb' => ['', 'invalid'], 'books' => ["x\x01", 'invalid']]],
            'duplicates' => [['predb' => 'releases', 'anime' => 'releases', 'steam' => 'Releases'], ['releases'], false, ['create' => 6, 'recreate' => 1, 'preserve' => 0, 'invalid' => 1, 'duplicate' => 2], ['predb' => ['releases', 'duplicate'], 'anime' => ['releases', 'duplicate'], 'steam' => ['Releases', 'invalid']]],
            'invalid does not claim name' => [['releases' => 'r#', 'predb' => 'r#'], [], false, ['create' => 8, 'recreate' => 0, 'preserve' => 0, 'invalid' => 2, 'duplicate' => 0], ['predb' => ['r#', 'invalid']]],
            'boundary length and utf8' => [['releases' => str_repeat('a', 255), 'predb' => str_repeat('a', 256), 'music' => "caf\xc3\xa9"], [], false, ['create' => 9, 'recreate' => 0, 'preserve' => 0, 'invalid' => 1, 'duplicate' => 0], ['music' => ["caf\xc3\xa9", 'create']]],
            'unknown keys ignored' => [['bogus' => 'x', 'releases' => 'r1'], ['x'], false, ['create' => 10, 'recreate' => 0, 'preserve' => 0, 'invalid' => 0, 'duplicate' => 0], ['releases' => ['r1', 'create']]],
        ];
    }

    /**
     * @param  array<string, ?string>  $configured
     * @param  list<string>  $existing
     * @param  array<string, int>  $counts
     * @param  array<string, array{0: string, 1: string}>  $rows
     */
    #[DataProvider('indexCreationProvider')]
    public function test_index_creation_parity(array $configured, array $existing, bool $createMissing, array $counts, array $rows): void
    {
        $this->requireFfi();

        $php = $this->both(NntmuxCreateESIndexes::class, static fn () => NntmuxCreateESIndexes::planIndexCreation($configured, $existing, $createMissing));
        $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(1, self::indexCreationParts($configured, $existing, $createMissing)));
        foreach ($counts as $key => $count) {
            $this->assertSame($count, $php[$key], $key);
        }
        $byLogical = array_column($php['indexes'], null, 'logical');
        $this->assertSame(self::INDEXES, array_keys($byLogical));
        foreach ($rows as $logical => [$name, $action]) {
            $this->assertSame($name, $byLogical[$logical]['name']);
            $this->assertSame($action, $byLogical[$logical]['action']);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: array{valid: bool, targets: list<string>, skipped: int, commands: list<string>}}>
     */
    public static function reindexProvider(): array
    {
        $create = "php '/srv/app/../misc/elasticsearch/create_es_indexes.php'";
        $populate = "php '/srv/app/../misc/elasticsearch/populate_es_indexes.php'";

        return [
            'defaults' => ['/srv/app', [], ['valid' => true, 'targets' => ['releases', 'predb'], 'skipped' => 0, 'commands' => [$create, "{$populate} releases", "{$populate} predb"]]],
            'trailing slashes' => ['/srv/app//', ['predb'], ['valid' => true, 'targets' => ['predb'], 'skipped' => 0, 'commands' => [$create, "{$populate} predb"]]],
            'normalized and deduplicated' => ['/srv/app', [' PREDB ', "releases\n", 'predb', 'bogus', ''], ['valid' => true, 'targets' => ['predb', 'releases'], 'skipped' => 2, 'commands' => [$create, "{$populate} predb", "{$populate} releases"]]],
            'only unknown' => ['/srv/app', ['movies'], ['valid' => true, 'targets' => [], 'skipped' => 1, 'commands' => [$create]]],
            'quote escaped' => ["/it's", ['releases'], ['valid' => true, 'targets' => ['releases'], 'skipped' => 0, 'commands' => ["php '/it'\\''s/../misc/elasticsearch/create_es_indexes.php'", "php '/it'\\''s/../misc/elasticsearch/populate_es_indexes.php' releases"]]],
            'blank path' => [" \t", [], ['valid' => false, 'targets' => ['releases', 'predb'], 'skipped' => 0, 'commands' => []]],
            'nul path' => ["/a\0b", ['predb'], ['valid' => false, 'targets' => ['predb'], 'skipped' => 0, 'commands' => []]],
            'root only' => ['///', [], ['valid' => true, 'targets' => ['releases', 'predb'], 'skipped' => 0, 'commands' => ["php '/../misc/elasticsearch/create_es_indexes.php'", "php '/../misc/elasticsearch/populate_es_indexes.php' releases", "php '/../misc/elasticsearch/populate_es_indexes.php' predb"]]],
        ];
    }

    /**
     * @param  list<string>  $targets
     * @param  array{valid: bool, targets: list<string>, skipped: int, commands: list<string>}  $expected
     */
    #[DataProvider('reindexProvider')]
    public function test_reindex_commands_parity(string $path, array $targets, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, $this->both(NntmuxESReindex::class, static fn () => NntmuxESReindex::planReindexCommands($path, $targets)));
        $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(2, [$path, ...$targets]));
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: array<string, mixed>, 2: list<array{offset: int, limit: int}>}>
     */
    public static function offsetRangesProvider(): array
    {
        $huge = intdiv(PHP_INT_MAX, 512) + 1;

        return [
            'even split' => [[' Manticore ', 'RELEASES', 10, 3, 0, ' 512m ', true], ['valid' => true, 'engine' => 'manticore', 'index' => 'releases', 'parallel' => 3, 'per_process' => 4, 'batch_size' => 10000, 'memory_limit' => '512M'], [['offset' => 0, 'limit' => 4], ['offset' => 4, 'limit' => 4], ['offset' => 8, 'limit' => 2]]],
            'more workers than rows' => [['elastic', 'predb', 2, 8, 5, '08G', false], ['parallel' => 8, 'per_process' => 1, 'batch_size' => 5, 'memory_limit' => '4G'], [['offset' => 0, 'limit' => 1], ['offset' => 1, 'limit' => 1]]],
            'empty total' => [['elastic', 'predb', -5, 0, 5, '-1', false], ['valid' => true, 'total' => 0, 'parallel' => 1, 'per_process' => 0, 'memory_limit' => '-1'], []],
            'unsupported engine' => [['es', 'predb', 100, 4, 5, '1G', false], ['valid' => false, 'engine' => null, 'index' => 'predb', 'per_process' => 25], []],
            'null index' => [['manticore', null, 100, 4, 5, '1G', false], ['valid' => false, 'engine' => 'manticore', 'index' => null], []],
            'max total clamps parallel' => [['elastic', 'predb', PHP_INT_MAX, 9999, 1, '1234567890', false], ['parallel' => 512, 'per_process' => $huge, 'memory_limit' => '4G'], []],
            'min int parallel' => [['elastic', 'predb', 7, PHP_INT_MIN, PHP_INT_MIN, "1g\n", false], ['parallel' => 1, 'per_process' => 7, 'batch_size' => 10000, 'memory_limit' => '1G'], [['offset' => 0, 'limit' => 7]]],
        ];
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $expected
     * @param  list<array{offset: int, limit: int}>  $ranges
     */
    #[DataProvider('offsetRangesProvider')]
    public function test_offset_ranges_parity(array $args, array $expected, array $ranges): void
    {
        $this->requireFfi();

        $plan = $this->both(NntmuxOffsetPopulate::class, static fn () => NntmuxOffsetPopulate::planOffsetRanges(...$args));
        $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(3, self::offsetRangesParts(...$args)));
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $plan[$key], $key);
        }
        if ($ranges !== []) {
            $this->assertSame($ranges, array_map(static fn (array $range): array => ['offset' => $range['offset'], 'limit' => $range['limit']], $plan['ranges']));
        }
        $covered = array_sum(array_column($plan['ranges'], 'limit'));
        $this->assertSame($plan['valid'] ? $plan['total'] : 0, $covered);
        foreach ($plan['ranges'] as $i => $range) {
            $this->assertSame($i, $range['worker_id']);
            $this->assertStringContainsString("--offset={$range['offset']} --limit={$range['limit']} --worker-id={$i}", $range['arguments']);
        }
    }

    public function test_offset_range_arguments_match_worker_command(): void
    {
        $this->requireFfi();

        $plan = $this->both(NntmuxOffsetPopulate::class, static fn () => NntmuxOffsetPopulate::planOffsetRanges('manticore', 'releases', 10, 3, 0, ' 512m ', true));
        $this->assertSame('--manticore --releases --offset=8 --limit=2 --worker-id=2 --batch-size=10000 --memory-limit=512M --disable-keys', $plan['ranges'][2]['arguments']);
        $plan = $this->both(NntmuxOffsetPopulate::class, static fn () => NntmuxOffsetPopulate::planOffsetRanges('elastic', 'predb', PHP_INT_MAX, 512));
        $this->assertCount(512, $plan['ranges']);
        $this->assertSame(PHP_INT_MAX, $plan['ranges'][511]['offset'] + $plan['ranges'][511]['limit']);
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: array{valid: bool, engine: ?string, index: ?string, offset: int, limit: int, end_offset: int, worker_id: int, batch_size: int, batches: int, last_batch: int, start_message: string}}>
     */
    public static function workerRunProvider(): array
    {
        return [
            'manticore wins' => [[true, true, false, true, 100, 25001, 3, 10000], ['valid' => true, 'engine' => 'manticore', 'index' => 'predb', 'offset' => 100, 'limit' => 25001, 'end_offset' => 25101, 'worker_id' => 3, 'batch_size' => 10000, 'batches' => 3, 'last_batch' => 5001, 'start_message' => 'Worker 3 starting: processing 25001 records from offset 100']],
            'exact batches' => [[false, true, true, true, 0, 20, 1, 10], ['valid' => true, 'engine' => 'elastic', 'index' => 'releases', 'offset' => 0, 'limit' => 20, 'end_offset' => 20, 'worker_id' => 1, 'batch_size' => 10, 'batches' => 2, 'last_batch' => 10, 'start_message' => 'Worker 1 starting: processing 20 records from offset 0']],
            'nothing selected' => [[false, false, false, false, -4, -1, -2, 0], ['valid' => false, 'engine' => null, 'index' => null, 'offset' => 0, 'limit' => 0, 'end_offset' => 0, 'worker_id' => 0, 'batch_size' => 10000, 'batches' => 0, 'last_batch' => 0, 'start_message' => 'Worker 0 starting: processing 0 records from offset 0']],
            'saturating end' => [[true, false, true, false, PHP_INT_MAX, 5, PHP_INT_MAX, PHP_INT_MIN], ['valid' => true, 'engine' => 'manticore', 'index' => 'releases', 'offset' => PHP_INT_MAX, 'limit' => 5, 'end_offset' => PHP_INT_MAX, 'worker_id' => PHP_INT_MAX, 'batch_size' => 10000, 'batches' => 1, 'last_batch' => 5, 'start_message' => 'Worker '.PHP_INT_MAX.' starting: processing 5 records from offset '.PHP_INT_MAX]],
            'max limit max batch' => [[true, false, false, true, 1, PHP_INT_MAX, 0, PHP_INT_MAX], ['valid' => true, 'engine' => 'manticore', 'index' => 'predb', 'offset' => 1, 'limit' => PHP_INT_MAX, 'end_offset' => PHP_INT_MAX, 'worker_id' => 0, 'batch_size' => PHP_INT_MAX, 'batches' => 1, 'last_batch' => PHP_INT_MAX, 'start_message' => 'Worker 0 starting: processing '.PHP_INT_MAX.' records from offset 1']],
        ];
    }

    /**
     * @param  list<mixed>  $args
     * @param  array{valid: bool, engine: ?string, index: ?string, offset: int, limit: int, end_offset: int, worker_id: int, batch_size: int, batches: int, last_batch: int, start_message: string}  $expected
     */
    #[DataProvider('workerRunProvider')]
    public function test_worker_run_parity(array $args, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, $this->both(NntmuxOffsetWorker::class, static fn () => NntmuxOffsetWorker::planWorkerRun(...$args)));
        $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(4, self::workerRunParts(...$args)));
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: array{mode: string, engine: ?string, indexes: list<string>, methods: list<string>, unknown: int, chunk_size: int, batch_size: int}}>
     */
    public static function populationProvider(): array
    {
        return [
            'ordered and normalized' => [[false, true, [' TVShows ', 'anime', 'nope', 'anime', 'RELEASES'], false, 0, -3, false], ['mode' => 'populate', 'engine' => 'elastic', 'indexes' => ['releases', 'tvshows', 'anime'], 'methods' => ['elasticReleases', 'elasticTvshows', 'elasticAnime'], 'unknown' => 1, 'chunk_size' => 5000, 'batch_size' => 5000]],
            'all' => [[true, true, ['bogus'], true, 200, 300, false], ['mode' => 'populate', 'engine' => 'manticore', 'indexes' => self::INDEXES, 'methods' => array_map(static fn (string $i): string => 'manticore'.ucfirst($i), self::INDEXES), 'unknown' => 1, 'chunk_size' => 200, 'batch_size' => 300]],
            'optimize wins' => [[false, false, [], true, 1, 1, true], ['mode' => 'optimize', 'engine' => null, 'indexes' => self::INDEXES, 'methods' => [], 'unknown' => 0, 'chunk_size' => 1, 'batch_size' => 1]],
            'no index' => [[true, false, [''], false, PHP_INT_MAX, PHP_INT_MIN, false], ['mode' => 'invalid', 'engine' => 'manticore', 'indexes' => [], 'methods' => [], 'unknown' => 1, 'chunk_size' => PHP_INT_MAX, 'batch_size' => 5000]],
            'no engine' => [[false, false, ['predb'], false, 10, 10, false], ['mode' => 'invalid', 'engine' => null, 'indexes' => ['predb'], 'methods' => [], 'unknown' => 0, 'chunk_size' => 10, 'batch_size' => 10]],
        ];
    }

    /**
     * @param  list<mixed>  $args
     * @param  array{mode: string, engine: ?string, indexes: list<string>, methods: list<string>, unknown: int, chunk_size: int, batch_size: int}  $expected
     */
    #[DataProvider('populationProvider')]
    public function test_population_parity(array $args, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, $this->both(NntmuxPopulateSearchIndexes::class, static fn () => NntmuxPopulateSearchIndexes::planPopulation(...$args)));
        $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(5, self::populationParts(...$args)));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();

        mt_srand(3705);
        $names = [...self::INDEXES, 'nntmux_releases', 'Releases', '', '.', '_x', 'a b', 'r#'];
        $engines = [' Manticore ', 'elastic', "ELASTIC\t", 'es', ''];
        $indexes = ['releases', ' PREDB ', 'movies', ''];
        $memory = ['4G', '512m', ' 1g ', '-1', '0', '08G', '1234567890', '123456789K', 'abc', '', "2M\n"];
        $targets = ['releases', 'predb', ' PREDB ', "Releases\n", 'movies', ''];
        $requested = [...self::INDEXES, ' TVShows ', 'ANIME', 'nope', ''];
        $pick = static fn (array $values): mixed => $values[mt_rand(0, \count($values) - 1)];
        $mixed = fn (array $values, int $max): string => mt_rand(0, 2) === 0 ? self::randomBytes($max) : $pick($values);

        for ($i = 0; $i < self::RANDOM_ROUNDS; $i++) {
            $configured = [];
            foreach (self::INDEXES as $logical) {
                match (mt_rand(0, 4)) {
                    0 => null,
                    1 => $configured[$logical] = null,
                    default => $configured[$logical] = $mixed($names, 12),
                };
            }
            $existing = [];
            for ($e = mt_rand(0, 6); $e > 0; $e--) {
                $existing[] = $mixed($names, 8);
            }
            $creation = [$configured, $existing, mt_rand(0, 1) === 1];
            $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(1, self::indexCreationParts(...$creation)));
            $this->assertParity(NntmuxCreateESIndexes::class, static fn () => NntmuxCreateESIndexes::planIndexCreation(...$creation), $creation);

            $list = [];
            for ($t = mt_rand(0, 5); $t > 0; $t--) {
                $list[] = $mixed($targets, 10);
            }
            $reindex = [mt_rand(0, 3) === 0 ? self::randomBytes(16) : '/srv/'.self::randomBytes(12), $list];
            $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(2, [$reindex[0], ...$list]));
            $this->assertParity(NntmuxESReindex::class, static fn () => NntmuxESReindex::planReindexCommands(...$reindex), $reindex);

            $offset = [
                mt_rand(0, 4) === 0 ? null : $mixed($engines, 10),
                mt_rand(0, 4) === 0 ? null : $mixed($indexes, 10),
                mt_rand(0, 2) === 0 ? self::randomInt() : mt_rand(0, 100_000),
                mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(-2, 40),
                self::randomInt(),
                $mixed($memory, 6),
                mt_rand(0, 1) === 1,
            ];
            $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(3, self::offsetRangesParts(...$offset)));
            $this->assertParity(NntmuxOffsetPopulate::class, static fn () => NntmuxOffsetPopulate::planOffsetRanges(...$offset), $offset);

            $worker = [mt_rand(0, 1) === 1, mt_rand(0, 1) === 1, mt_rand(0, 1) === 1, mt_rand(0, 1) === 1, self::randomInt(), self::randomInt(), self::randomInt(), self::randomInt()];
            $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(4, self::workerRunParts(...$worker)));
            $this->assertParity(NntmuxOffsetWorker::class, static fn () => NntmuxOffsetWorker::planWorkerRun(...$worker), $worker);

            $wanted = [];
            for ($r = mt_rand(0, 12); $r > 0; $r--) {
                $wanted[] = $mixed($requested, 10);
            }
            $population = [mt_rand(0, 1) === 1, mt_rand(0, 1) === 1, $wanted, mt_rand(0, 4) === 0, self::randomInt(), self::randomInt(), mt_rand(0, 4) === 0];
            $this->assertNotNull(NntmuxPopulateSearchIndexes::evaluateNative(5, self::populationParts(...$population)));
            $this->assertParity(NntmuxPopulateSearchIndexes::class, static fn () => NntmuxPopulateSearchIndexes::planPopulation(...$population), $population);
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['10', '0', '0', '0', '0', 'releases', '1', '0', 'create'], \array_slice(NntmuxPopulateSearchIndexes::evaluateNative(1, self::indexCreationParts([], [], false)) ?? [], 0, 9));
        $this->assertSame(['1', '0', '1', 'predb', "php '/a/../misc/elasticsearch/create_es_indexes.php'", "php '/a/../misc/elasticsearch/populate_es_indexes.php' predb"], NntmuxPopulateSearchIndexes::evaluateNative(2, ['/a', 'predb']));
        $this->assertSame(['1', '1', 'elastic', '1', 'predb', '1', '1', '1', '10000', '4G', '1', '0', '1', '0', '--elastic --predb --offset=0 --limit=1 --worker-id=0 --batch-size=10000 --memory-limit=4G'], NntmuxPopulateSearchIndexes::evaluateNative(3, self::offsetRangesParts('elastic', 'predb', 1, 1, 0, '', false)));
        $this->assertSame('Worker 2 starting: processing 3 records from offset 1', (NntmuxPopulateSearchIndexes::evaluateNative(4, self::workerRunParts(true, false, true, false, 1, 3, 2, 0)) ?? [])[12] ?? null);
        $this->assertSame(['populate', '1', 'elastic', '0', '5000', '5000', '1', 'steam', 'elasticSteam'], NntmuxPopulateSearchIndexes::evaluateNative(5, self::populationParts(false, true, ['steam'], false, 0, 0, false)));
        $this->assertNull(NntmuxPopulateSearchIndexes::evaluateNative(-1, ['x']));
        $this->assertNull(NntmuxPopulateSearchIndexes::evaluateNative(0, ['x']));
        $this->assertNull(NntmuxPopulateSearchIndexes::evaluateNative(6, ['x']));
        $this->assertNull(NntmuxPopulateSearchIndexes::evaluateNative(5, array_fill(0, 4097, '0')));
    }

    public function test_native_helpers_fall_back_to_php(): void
    {
        foreach (self::CLASSES as $class) {
            $class::useRustFfi(true);
        }
        $reflection = new ReflectionClass(NntmuxPopulateSearchIndexes::class);
        $reflection->setStaticPropertyValue('ffi', false);

        $this->assertNull(NntmuxPopulateSearchIndexes::evaluateNative(2, ['/a']));
        $this->assertSame('create', NntmuxCreateESIndexes::planIndexCreation([], [], false)['indexes'][0]['action']);
        $this->assertSame(['releases', 'predb'], NntmuxESReindex::planReindexCommands('/a')['targets']);
        $this->assertCount(2, NntmuxOffsetPopulate::planOffsetRanges('elastic', 'predb', 4, 2)['ranges']);
        $this->assertSame(2, NntmuxOffsetWorker::planWorkerRun(true, false, true, false, 0, 11, 0, 10)['batches']);
        $this->assertSame('populate', NntmuxPopulateSearchIndexes::planPopulation(true, false, ['predb'])['mode']);

        // Over the 4096-part bridge limit Rust is never called and PHP answers.
        NntmuxPopulateSearchIndexes::resetRustFfi();
        NntmuxPopulateSearchIndexes::useRustFfi(true);
        $many = array_fill(0, 5000, 'predb');
        $this->assertSame(['predb'], NntmuxPopulateSearchIndexes::planPopulation(false, true, $many)['indexes']);
        $this->assertSame(NntmuxCreateESIndexes::planIndexCreation([], $many, true)['preserve'], 1);

        $missing = RustPipelineLibrary::resolveDomain('search_w375', NntmuxPopulateSearchIndexes::FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['/srv/app']);
        $buf = $ffi->new('char[1024]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w37_search_index_offset_worker_command($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(2, $valid, strlen($valid), $buf, 1024);
        $this->assertGreaterThan(8, $written);
        $expected = NntmuxPopulateSearchIndexes::evaluateNative(2, ['/srv/app']);
        $this->assertNotNull($expected);
        $this->assertSame(self::packet($expected), FFI::string($buf, $written));

        // Tiny capacity: -required without touching the buffer, then a retry with that capacity succeeds.
        $this->assertSame(-$written, $call(2, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(2, $valid, strlen($valid), null, 0));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $exact = $ffi->new("char[{$written}]");
        $this->assertSame($written, $call(2, $valid, strlen($valid), $exact, $written));
        $this->assertSame(self::packet($expected), FFI::string($exact, $written));

        // Null pointers with non-zero lengths, malformed packets and unknown modes.
        $this->assertSame(-1, $call(2, null, 4, $buf, 1024));
        $this->assertSame(-1, $call(2, $valid, strlen($valid), null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $valid, strlen($valid), $small, 8));
        }
        $this->assertSame(-1, $call(2, '', 0, $small, 8));
        $this->assertSame(-1, $call(2, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(2, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(2, $valid."\0", strlen($valid) + 1, $small, 8));
        $this->assertSame(-1, $callParts(2, []));

        // Invalid flags, non-canonical integers and wrong field counts.
        $this->assertSame(-1, $callParts(1, ['2', ...array_fill(0, 20, '')]));
        $this->assertSame(-1, $callParts(1, ['0', 'x', ...array_fill(0, 19, '')]));
        $this->assertSame(-1, $callParts(1, array_fill(0, 20, '0')));
        $this->assertSame(-1, $callParts(3, ['1', 'x', '1', 'y', '1', '1', '1', '1', '2']));
        $this->assertSame(-1, $callParts(3, ['0', 'x', '1', 'y', '1', '1', '1', '1', '0']));
        $this->assertSame(-1, $callParts(3, ['1', 'x', '1', 'y', '01', '1', '1', '1', '0']));
        $this->assertSame(-1, $callParts(3, ['1', 'x', '1', 'y', '+1', '1', '1', '1', '0']));
        $this->assertSame(-1, $callParts(3, ['1', 'x', '1', 'y', '1', '1', '1', '1']));
        $this->assertSame(-1, $callParts(4, ['1', '0', '0', '2', '0', '0', '0', '0']));
        $this->assertSame(-1, $callParts(4, ['1', '0', '0', '0', '0', '0', '0', '1.0']));
        $this->assertSame(-1, $callParts(4, ['1', '0', '0', '0', '0', '0', '0']));
        $this->assertSame(-1, $callParts(5, ['1', '0', '0', 'x', '1', '1']));
        $this->assertSame(-1, $callParts(5, ['1', '0', '0', '0', '1']));
        $this->assertSame(-1, $callParts(5, ['1', '0', '0', '0', '99999999999999999999', '1']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $common = static function (): void {
            NntmuxCreateESIndexes::planIndexCreation(['releases' => 'releases', 'predb' => 'predb'], ['releases', 'predb'], true);
            NntmuxESReindex::planReindexCommands('/var/www/nntmux/app');
            NntmuxOffsetPopulate::planOffsetRanges('manticore', 'releases', 2_500_000, 8, 10000, '4G', false);
            NntmuxOffsetWorker::planWorkerRun(true, false, true, false, 312500, 312500, 1, 10000);
            NntmuxPopulateSearchIndexes::planPopulation(true, false, ['releases', 'predb']);
        };
        $existing = [];
        for ($i = 0; $i < 400; $i++) {
            $existing[] = "nntmux_index_{$i}";
        }
        $configured = array_combine(self::INDEXES, array_map(static fn (string $i): string => "nntmux_{$i}_v2", self::INDEXES));
        $requested = [];
        for ($i = 0; $i < 300; $i++) {
            $requested[] = $i % 3 === 0 ? ' '.strtoupper(self::INDEXES[$i % 10])." \n" : "unknown_{$i}";
        }
        $uncommon = static function () use ($existing, $configured, $requested): void {
            NntmuxCreateESIndexes::planIndexCreation($configured, $existing, false);
            NntmuxESReindex::planReindexCommands("/srv/it's/".str_repeat('deep/', 60), [' PREDB ', 'bogus', "releases\n", 'predb', 'movies']);
            NntmuxOffsetPopulate::planOffsetRanges(" Elastic\t", 'PREDB', PHP_INT_MAX, 512, PHP_INT_MIN, ' 999m ', true);
            NntmuxOffsetWorker::planWorkerRun(false, true, false, true, PHP_INT_MAX - 7, PHP_INT_MAX, PHP_INT_MAX, 3);
            NntmuxPopulateSearchIndexes::planPopulation(false, true, $requested, false, -1, 0);
        };

        $setAll = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::useRustFfi($rust);
            }
        };

        $results = [];
        foreach (['common' => $common, 'uncommon' => $uncommon] as $name => $workload) {
            for ($w = 0; $w < 20; $w++) {
                foreach ([false, true] as $rust) {
                    $setAll($rust);
                    $workload();
                }
            }

            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < 300; $s++) {
                $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAll($rust);
                    $t0 = hrtime(true);
                    for ($i = 0; $i < 20; $i++) {
                        $workload();
                    }
                    $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / 20_000;
                }
            }

            $results[$name] = [
                'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                'php_mean_us' => self::mean($times['php']),
                'rust_mean_us' => self::mean($times['rust']),
                'php_p99_us' => self::p99($times['php']),
                'rust_p99_us' => self::p99($times['rust']),
            ];
        }

        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 37.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus) | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
            $results['common']['mean'],
            $results['common']['php_mean_us'],
            $results['common']['rust_mean_us'],
            $results['common']['p99'],
            $results['common']['php_p99_us'],
            $results['common']['rust_p99_us'],
            $results['uncommon']['mean'],
            $results['uncommon']['php_mean_us'],
            $results['uncommon']['rust_mean_us'],
            $results['uncommon']['p99'],
            $results['uncommon']['php_p99_us'],
            $results['uncommon']['rust_p99_us'],
        ));

        $qualifies = $results['common']['mean'] >= 1.5 && $results['common']['p99'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach (self::CLASSES as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesRustFfi());
            }
        }
    }

    /**
     * Runs $plan with PHP then Rust, asserts identical results and returns the PHP result.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): T  $plan
     * @return T
     */
    private function both(string $class, callable $plan): mixed
    {
        $class::useRustFfi(false);
        $php = $plan();
        $class::useRustFfi(true);
        $rust = $plan();
        $class::resetRustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useRustFfi(false);
        $php = $plan();
        $class::useRustFfi(true);
        $rust = $plan();
        $class::resetRustFfi();
        $this->assertSame($php, $rust, bin2hex(serialize($args)));
    }

    /**
     * @param  array<string, ?string>  $configured
     * @param  list<string>  $existing
     * @return list<string>
     */
    private static function indexCreationParts(array $configured, array $existing, bool $createMissing): array
    {
        $parts = [$createMissing ? '1' : '0'];
        foreach (self::INDEXES as $logical) {
            $name = $configured[$logical] ?? null;
            $parts[] = $name === null ? '0' : '1';
            $parts[] = $name ?? '';
        }

        return [...$parts, ...$existing];
    }

    /** @return list<string> */
    private static function offsetRangesParts(?string $engine, ?string $index, int $total, int $parallel, int $batchSize = 10000, string $memoryLimit = '4G', bool $disableKeys = false): array
    {
        return [$engine === null ? '0' : '1', $engine ?? '', $index === null ? '0' : '1', $index ?? '', (string) $total, (string) $parallel, (string) $batchSize, $memoryLimit, $disableKeys ? '1' : '0'];
    }

    /** @return list<string> */
    private static function workerRunParts(bool $manticore, bool $elastic, bool $releases, bool $predb, int $offset, int $limit, int $workerId = 0, int $batchSize = 10000): array
    {
        return [$manticore ? '1' : '0', $elastic ? '1' : '0', $releases ? '1' : '0', $predb ? '1' : '0', (string) $offset, (string) $limit, (string) $workerId, (string) $batchSize];
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private static function populationParts(bool $manticore, bool $elastic, array $requested, bool $all = false, int $count = 5000, int $batchSize = 5000, bool $optimize = false): array
    {
        return [$manticore ? '1' : '0', $elastic ? '1' : '0', $all ? '1' : '0', $optimize ? '1' : '0', (string) $count, (string) $batchSize, ...$requested];
    }

    private static function randomBytes(int $maxLength): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $out .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
        }

        return $out;
    }

    private static function randomInt(): int
    {
        return match (mt_rand(0, 3)) {
            0 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            1 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 1, PHP_INT_MAX - 1][mt_rand(0, 5)],
            default => mt_rand(-2, 50_000),
        };
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        $packet = pack('V', count($parts));
        foreach ($parts as $part) {
            $packet .= pack('V', strlen($part)).$part;
        }

        return $packet;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(0.99 * count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w375', NntmuxPopulateSearchIndexes::FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
