<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BlacklistConstants;
use App\Facades\Search;
use App\Models\DnzbFailure;
use App\Models\Predb;
use App\Models\ReleaseComment;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Services\Search\SearchService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Wave 21.1: native parity, real callers, C ABI safety, and performance gate.
 * Run the reproducible benchmark outside normal PHPUnit execution:
 * php -r 'require "tests/bootstrap.php"; Tests\Unit\RustPredbCommentsDnzbBlacklistFeedsParityTest::benchmark($argv[1]);' /tmp/wave21-benchmark.json
 * Protocol: 3 repetitions x 41 randomized paired samples x 200 inner calls;
 * 200 warmups per side. Per-call mean and nearest-rank p99 include the public
 * wrapper, input conversion, FFI crossing, allocation and result decoding.
 * Raw observations, order, inputs, hashes and host/tool identity are retained.
 * These pure CPU timings exclude database/I/O; callers are tested separately.
 *
 * Measured on baseline d5acab6d2ea1b8d37086bc8b953143188bda0475;
 * PHP 8.5.12 (CLI opcache off/JIT disabled), rustc/cargo 1.99.1 release
 * opt-level=3/LTO/one codegen unit; Darwin arm64 Apple M5 Mac17,3,
 * 10 CPUs, 32 GiB RAM, shared host without imposed caps. No failed pairs.
 * Worst-repetition PHP/Rust latency ratios (common mean/p99; uncommon mean/p99):
 * exactName:        0.386/0.191; 1.138/1.369
 * commentHost:      0.176/0.120; 0.166/0.126
 * failedCountValue: 0.320/0.336; 0.281/0.288
 * resolveConstant:  0.408/0.402; 0.394/0.333
 * availableKeys:    0.074/0.096; 0.073/0.092
 * isAvailableKey:   0.514/0.598; 0.846/0.799
 * No helper meets all four 1.5x thresholds, so every class defaults to PHP.
 * Raw report: /tmp/wave21-slice21-1-benchmark.json, SHA256
 * 06e130a8054483e6820cc46d5e119766df8e1c537f3826d44ee851dd48c32007.
 * Source hashes identify measured executable bodies before this evidence comment.
 */
class RustPredbCommentsDnzbBlacklistFeedsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [Predb::class, ReleaseComment::class, DnzbFailure::class, BlacklistConstants::class, PredbFeedSourceFactory::class];

    private const string CDEF = <<<'C'
        ssize_t nntmux_wave21_predb_exact_name(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_wave21_comment_host(uint32_t mode, const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_wave21_dnzb_failed_count(uint32_t mode, const char *input, size_t len);
        ssize_t nntmux_wave21_blacklist_constant(const char *input, size_t len);
        ssize_t nntmux_wave21_feed_keys(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_wave21_feed_key_index(const char *input, size_t len);
        C;

    /** @var list<string> */
    private const array FEED_KEYS = ['predb_club', 'predb_net', 'predb_me', 'predb_org', 'predatabase', 'srrdb', 'xrel', 'xrel_p2p', 'scnsrc_me', 'eztv', 'predb_ovh', 'apibay', 'nfodb', 'one_techno', 'one_gabba', 'ddlvalley', 'hdencode', 'hdencode_tv', 'mp3scene', 'nima4k', 'gload', 'hdworld', 'g4u', 'nswdb', '3dsdb', 'gabber', 'movieblog', 'scnlog', 'knaben', 'crowdnfo', 'seriousaboutsound', 'dats_pciso_1998', 'nzedb_archive', 'scene_archive_sql'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
            self::assertFalse((new ReflectionProperty($class, 'useRustFfi'))->getValue(), $class);
            self::assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_native_exports_are_built(): void
    {
        $ffi = $this->native();
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_pipeline_abi_version());
    }

    public function test_native_can_be_enabled_disabled_and_reset(): void
    {
        $this->native();
        foreach (self::CLASSES as $class) {
            $class::enableRustFfi();
            self::assertTrue($class::usesRustFfi(), $class);
            $class::disableRustFfi();
            self::assertFalse($class::usesRustFfi(), $class);
            $class::enableRustFfi();
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi(), $class);
            self::assertNull((new ReflectionProperty($class, 'ffi'))->getValue(), $class);
        }
    }

    public function test_predb_ascii_normalization_and_unicode_decline_preserve_php(): void
    {
        $this->native();
        foreach (['', 'Movie.2026.1080p.WEB-DL-GROUP', " A\0B\tC\r\n", implode('', array_map(chr(...), range(0, 127))), str_repeat('ABC.DeF.', 512)] as $name) {
            self::assertSame(mb_strtolower($name, 'UTF-8'), Predb::exactNameNative($name));
            foreach ([false, true] as $enabled) {
                self::toggle(Predb::class, $enabled);
                self::assertSame(Predb::exactNamePhp($name), Predb::exactName($name));
            }
        }
        foreach (["\xff", 'ÉTÉ.İ.Σ', "A\xc3", '日本語', "\xc4\xb0"] as $name) {
            self::assertNull(Predb::exactNameNative($name));
            Predb::enableRustFfi();
            self::assertSame(Predb::exactNamePhp($name), Predb::exactName($name));
        }
    }

    public function test_comment_host_strict_false_and_null_parity(): void
    {
        $this->native();
        foreach ([null, '', '127.0.0.1', '2001:db8::1', "host\0\xff", str_repeat('h', 2048)] as $host) {
            foreach ([false, true, null, 0, 1, '0', 'false', [], new \stdClass] as $setting) {
                $expected = $setting === false ? '' : $host;
                self::assertSame($expected, ReleaseComment::commentHostPhp($host, $setting));
                self::assertSame(is_bool($setting) ? ['host' => $expected] : null, ReleaseComment::commentHostNative($host, $setting));
                foreach ([false, true] as $enabled) {
                    self::toggle(ReleaseComment::class, $enabled);
                    self::assertSame($expected, ReleaseComment::commentHost($host, $setting));
                }
            }
        }
    }

    public function test_failed_count_preserves_empty_semantics_types_and_identity(): void
    {
        $this->native();
        foreach ([null, false, true, 0, 1, -1, PHP_INT_MIN, PHP_INT_MAX, '', '0', '00', '0.0', '+0', '-0', ' 0', "\0", "\xff", '9223372036854775808', 0.0, 1.5, [], [0], new \stdClass] as $value) {
            $expected = empty($value) ? false : $value;
            $native = DnzbFailure::failedCountValueNative($value);
            self::assertSame(is_int($value) || is_string($value) ? ['value' => $expected] : null, $native);
            foreach ([false, true] as $enabled) {
                self::toggle(DnzbFailure::class, $enabled);
                $actual = DnzbFailure::failedCountValue($value);
                self::assertSame($expected, $actual);
            }
        }
    }

    public function test_blacklist_constant_names_are_exact(): void
    {
        $this->native();
        $constants = ['OPTYPE_BLACKLIST' => 1, 'OPTYPE_WHITELIST' => 2, 'BLACKLIST_ENABLED' => 1, 'BLACKLIST_FIELD_SUBJECT' => 1, 'BLACKLIST_FIELD_FROM' => 2, 'BLACKLIST_FIELD_MESSAGEID' => 3];
        foreach ($constants + ['' => null, 'optype_blacklist' => null, ' BLACKLIST_ENABLED' => null, "BLACKLIST_ENABLED\0" => null, '未知' => null] as $name => $expected) {
            self::assertSame($expected, BlacklistConstants::resolveConstantPhp($name));
            self::assertSame($name === '未知' ? null : ['value' => $expected], BlacklistConstants::resolveConstantNative($name));
            foreach ([false, true] as $enabled) {
                self::toggle(BlacklistConstants::class, $enabled);
                self::assertSame($expected, BlacklistConstants::resolveConstant($name));
            }
        }
    }

    public function test_feed_key_order_and_exact_lookup_parity(): void
    {
        $this->native();
        $factory = new PredbFeedSourceFactory;
        self::assertSame(self::FEED_KEYS, $factory->availableKeysPhp());
        self::assertSame(self::FEED_KEYS, $factory->availableKeysNative());
        foreach ([...self::FEED_KEYS, '', 'PREDB_CLUB', ' predb_club', "predb_club\0", '未知', str_repeat('x', 4096)] as $key) {
            $expected = in_array($key, self::FEED_KEYS, true);
            self::assertSame($expected, $factory->isAvailableKeyPhp($key));
            self::assertSame($key === '未知' ? null : $expected, $factory->isAvailableKeyNative($key));
            foreach ([false, true] as $enabled) {
                self::toggle(PredbFeedSourceFactory::class, $enabled);
                self::assertSame(self::FEED_KEYS, $factory->availableKeys());
                self::assertSame($expected, $factory->isAvailableKey($key));
            }
        }
    }

    public function test_unavailable_and_stale_libraries_fall_back_cleanly(): void
    {
        // An unrelated FFI scope represents an older library missing the new symbols.
        foreach ([false, FFI::cdef('')] as $ffi) {
            foreach (self::CLASSES as $class) {
                $class::enableRustFfi();
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $ffi);
            }
            self::assertSame('movie.é', Predb::exactName('MOVIE.É'));
            self::assertNull(ReleaseComment::commentHost(null, true));
            self::assertSame('', ReleaseComment::commentHost('private', false));
            self::assertSame('00', DnzbFailure::failedCountValue('00'));
            self::assertSame(2, BlacklistConstants::resolveConstant('OPTYPE_WHITELIST'));
            self::assertNull(BlacklistConstants::resolveConstant('unknown'));
            $factory = new PredbFeedSourceFactory;
            self::assertSame(self::FEED_KEYS, $factory->availableKeys());
            self::assertTrue($factory->isAvailableKey('predb_club'));
            self::assertFalse($factory->isAvailableKey('unknown'));
            foreach (self::CLASSES as $class) {
                $class::disableRustFfi();
                self::assertFalse($class::usesRustFfi());
                $class::resetRustFfi();
                self::assertNull((new ReflectionProperty($class, 'ffi'))->getValue());
            }
        }
    }

    public function test_real_callers_keep_storage_lookup_and_error_behavior(): void
    {
        $this->native();
        $this->withDatabase(function (): void {
            $search = $this->createMock(SearchService::class);
            $search->method('matchPredbExact')->willReturnCallback(static fn (string $name): ?array => match ($name) {
                'missing' => null,
                'zero' => ['id' => 0],
                'gone' => ['id' => 999],
                'mismatch' => ['id' => 2],
                default => ['id' => 1],
            });
            Search::swap($search);
            DB::table('predb')->insert([['id' => 1, 'title' => 'MOVIE.É', 'filename' => 'FILE.Name'], ['id' => 2, 'title' => 'different', 'filename' => 'other']]);
            DB::table('users')->insert(['id' => 7, 'username' => 'test-user']);
            foreach ([false, true] as $enabled) {
                foreach (self::CLASSES as $class) {
                    self::toggle($class, $enabled);
                }
                self::assertSame(['title' => 'MOVIE.É', 'predb_id' => 1], Predb::matchPre('movie.é'));
                self::assertSame(['title' => 'MOVIE.É', 'predb_id' => 1], Predb::matchPre('file.name'));
                foreach (['', '  ', 'missing', 'zero', 'gone', 'mismatch'] as $name) {
                    self::assertFalse(Predb::matchPre($name));
                }
                DB::table('release_comments')->delete();
                DB::table('releases')->updateOrInsert(['id' => 1], ['comments' => 0]);
                foreach ([false, true, null, 0, 'false'] as $setting) {
                    config(['nntmux:settings.store_user_ip' => $setting]);
                    $id = ReleaseComment::addComment(1, "text\0bytes", 7, '192.0.2.1');
                    $comment = DB::table('release_comments')->where('id', $id)->first();
                    self::assertNotNull($comment);
                    self::assertSame($setting === false ? '' : '192.0.2.1', $comment->host);
                    self::assertSame("text\0bytes", $comment->text);
                    self::assertSame('test-user', $comment->username);
                }
                config(['nntmux:settings.store_user_ip' => true]);
                $id = ReleaseComment::addComment(1, 'anonymous', 999, null);
                self::assertSame('ANON', DB::table('release_comments')->where('id', $id)->value('username'));
                self::assertNull(DB::table('release_comments')->where('id', $id)->value('host'));
                self::assertSame(6, DB::table('releases')->value('comments'));
                ReleaseComment::deleteComment($id);
                self::assertSame(5, ReleaseComment::getCommentCount());
                ReleaseComment::deleteComment($id);
                self::assertSame(5, DB::table('releases')->value('comments'));
                DB::table('dnzb_failures')->delete();
                self::assertFalse(DnzbFailure::getFailedCount(1));
                foreach (['0', '00', '7', '-2'] as $value) {
                    DB::table('dnzb_failures')->updateOrInsert(['release_id' => 1, 'users_id' => 7], ['failed' => $value]);
                    self::assertSame(empty($value) ? false : $value, DnzbFailure::getFailedCount(1));
                }
                $factory = new PredbFeedSourceFactory;
                foreach (self::FEED_KEYS as $key) {
                    self::assertSame($key, $factory->makeOne($key)->key());
                }
                self::assertCount(2, $factory->make(['predb_club', 'predb_club', 'predb_net']));
                config(['predb_feeds.sources' => ['predb_club', 'predb_club']]);
                self::assertCount(1, $factory->make());
                try {
                    $factory->makeOne('PREDB_CLUB');
                    self::fail('Unknown feed must retain its exception.');
                } catch (InvalidArgumentException $exception) {
                    self::assertSame('Unknown PreDB feed source [PREDB_CLUB]. Available: '.implode(', ', self::FEED_KEYS), $exception->getMessage());
                }
            }
        });
    }

    public function test_byte_exports_reject_short_capacity_without_partial_writes(): void
    {
        $ffi = $this->native();
        $cases = [
            ['nntmux_wave21_predb_exact_name', ['ABC', 3], 'abc'],
            ['nntmux_wave21_comment_host', [1, "host\0bytes", 10], "\x01host\0bytes"],
            ['nntmux_wave21_feed_keys', ['', 0], implode("\0", self::FEED_KEYS)],
        ];
        foreach ($cases as [$symbol, $args, $expected]) {
            $size = strlen($expected);
            $out = $ffi->new('char['.($size + 1).']');
            FFI::memset($out, 90, $size + 1);
            // @phpstan-ignore method.notFound, method.notFound, method.notFound
            self::assertSame(-2, $ffi->$symbol(...[...$args, $out, $size - 1]));
            self::assertSame(str_repeat('Z', $size + 1), FFI::string($out, $size + 1));
            // @phpstan-ignore method.notFound, method.notFound, method.notFound
            self::assertSame($size, $ffi->$symbol(...[...$args, $out, $size]));
            self::assertSame($expected.'Z', FFI::string($out, $size + 1));
            // @phpstan-ignore method.notFound, method.notFound, method.notFound
            self::assertSame(-1, $ffi->$symbol(...[...$args, null, $size]));
        }
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_wave21_predb_exact_name(null, 0, $ffi->new('char[1]'), 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_wave21_dnzb_failed_count(0, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_wave21_blacklist_constant(null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_wave21_feed_key_index(null, 0));
    }

    public function test_integer_abi_declines_noncanonical_values_and_unknown_modes(): void
    {
        $ffi = $this->native();
        foreach (['', '+1', '-0', '01', ' 1', '1 ', '1.0', "1\0", '9223372036854775808', '-9223372036854775809'] as $input) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_wave21_dnzb_failed_count(0, $input, strlen($input)));
        }
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_wave21_dnzb_failed_count(2, '1', 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_wave21_comment_host(3, '', 0, $ffi->new('char[1]'), 1));
    }

    private static function toggle(string $class, bool $enabled): void
    {
        $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
    }

    private function native(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('Build the release pipeline library to exercise Wave21 native parity.');
        }

        return $ffi;
    }

    /** Private in-memory fixture, with no application boot or production connections. */
    private function withDatabase(callable $test): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApp = Facade::getFacadeApplication();
        $resolved = new ReflectionProperty(Facade::class, 'resolvedInstance');
        $previousResolved = $resolved->getValue();
        $previousResolver = Model::getConnectionResolver();
        $previousDispatcher = Model::getEventDispatcher();
        $booted = new ReflectionProperty(Model::class, 'booted');
        $previousBooted = $booted->getValue();
        $capsule = null;
        try {
            $app = new Application(dirname(__DIR__, 2));
            $app->instance('config', new Repository);
            $app->instance('date', new DateFactory);
            Container::setInstance($app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($app);
            Model::unsetEventDispatcher();
            $capsule = new Manager($app);
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $capsule->bootEloquent();
            $app->instance('db', $capsule->getDatabaseManager());
            $capsule->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
            DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, filename TEXT)');
            DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, deleted_at TEXT)');
            DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, comments INTEGER DEFAULT 0, updated_at TEXT)');
            DB::statement('CREATE TABLE release_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, releases_id INTEGER, text TEXT, users_id INTEGER, created_at TEXT, updated_at TEXT, host TEXT, username TEXT, isvisible INTEGER DEFAULT 1)');
            DB::statement('CREATE TABLE dnzb_failures (release_id INTEGER, users_id INTEGER, failed TEXT, PRIMARY KEY (release_id, users_id))');
            $test();
        } finally {
            $capsule?->getConnection()->disconnect();
            $previousResolver !== null ? Model::setConnectionResolver($previousResolver) : Model::unsetConnectionResolver();
            $previousDispatcher !== null ? Model::setEventDispatcher($previousDispatcher) : Model::unsetEventDispatcher();
            $booted->setValue(null, $previousBooted);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApp);
            $resolved->setValue(null, $previousResolved);
            Container::setInstance($previousContainer);
        }
    }

    /**
     * Run manually, preserving raw paired observations and all failures.
     * The default decision is per class: every exposed helper must qualify.
     */
    public static function benchmark(string $outputPath): void
    {
        $root = dirname(__DIR__, 2);
        $report = [
            'protocol' => '3 repetitions x 41 randomized paired batches x 200 inner calls; identical randomized inputs per pair; 200 warmups/side; hrtime per-call ns; nearest-rank p99 over 8200 calls/repetition. Public wrapper including FFI/serialization/allocation, database/I/O excluded. Shared host, no imposed resource caps. Warm library load; startup separately recorded.',
            'seed' => 2101, 'repetitions' => 3, 'paired_samples' => 41, 'inner_loops' => 200,
            'baseline_commit' => trim((string) shell_exec('git rev-parse HEAD')),
            'php' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'os' => php_uname(),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'rustc' => trim((string) shell_exec('rustc --version')), 'cargo' => trim((string) shell_exec('cargo --version')),
            'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.model hw.ncpu hw.memsize' : 'uname -m')),
            'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml; opt-level=3,lto=true,codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['app/Models/Predb.php', 'app/Models/ReleaseComment.php', 'app/Models/DnzbFailure.php', 'app/Enums/BlacklistConstants.php', 'app/Services/Predb/Feeds/PredbFeedSourceFactory.php', 'tests/Unit/RustPredbCommentsDnzbBlacklistFeedsParityTest.php', 'app/Support/RustPipelineLibrary.php', 'rust/pipeline-ffi/src/cleaning.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $path) {
            $report['source_sha256'][$path] = hash_file('sha256', $root.'/'.$path);
        }
        $factory = new PredbFeedSourceFactory;
        // Static tables have no input-dependent uncommon path: repeat the table
        // workload, and also measure its actual known/unknown membership caller.
        $cases = [
            'Predb::exactName' => [Predb::class, [Predb::class, 'exactName'], [Predb::class, 'exactNameNative'], [['Movie.2026.1080p.WEB-DL-GROUP'], ['Another.Release-GRP']], [[str_repeat('ABC.D', 51)], ["A\0B\tC"]]],
            'ReleaseComment::commentHost' => [ReleaseComment::class, [ReleaseComment::class, 'commentHost'], [ReleaseComment::class, 'commentHostNative'], [['192.0.2.1', true], ['192.0.2.1', false]], [[null, true], [str_repeat('host', 256)."\0\xff", true]]],
            'DnzbFailure::failedCountValue' => [DnzbFailure::class, [DnzbFailure::class, 'failedCountValue'], [DnzbFailure::class, 'failedCountValueNative'], [[1], ['7'], [0]], [[PHP_INT_MIN], [PHP_INT_MAX], ['00'], ['0']]],
            'BlacklistConstants::resolveConstant' => [BlacklistConstants::class, [BlacklistConstants::class, 'resolveConstant'], [BlacklistConstants::class, 'resolveConstantNative'], [['BLACKLIST_FIELD_SUBJECT'], ['OPTYPE_BLACKLIST']], [[str_repeat('UNKNOWN', 64)], ["BLACKLIST_ENABLED\0"]]],
            'PredbFeedSourceFactory::availableKeys' => [PredbFeedSourceFactory::class, [$factory, 'availableKeys'], [$factory, 'availableKeysNative'], [[]], [[]]],
            'PredbFeedSourceFactory::isAvailableKey' => [PredbFeedSourceFactory::class, [$factory, 'isAvailableKey'], [$factory, 'isAvailableKeyNative'], [['predb_club'], ['srrdb']], [['scene_archive_sql'], [str_repeat('unknown', 64)], ['PREDB_CLUB']]],
        ];
        $random = new Randomizer(new Mt19937(2101));
        try {
            $start = hrtime(true);
            if (RustPipelineLibrary::resolveDomain('cleaning', self::CDEF) === null) {
                throw new RuntimeException('Benchmark invalid: missing Wave21 exports.');
            }
            $report['library_load_ns'] = hrtime(true) - $start;
            foreach ($cases as $name => [$class, $call, $native, $common, $uncommon]) {
                $worst = [];
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    foreach ($inputs as $args) {
                        self::toggle($class, false);
                        $expected = $call(...$args);
                        $raw = $native(...$args);
                        if ($raw === null) {
                            throw new RuntimeException($name.' native path declined: invalid benchmark.');
                        }
                        self::toggle($class, true);
                        self::assertSame($expected, $call(...$args));
                    }
                    for ($rep = 0; $rep < 3; $rep++) {
                        foreach ([false, true] as $enabled) {
                            self::toggle($class, $enabled);
                            for ($i = 0; $i < 200; $i++) {
                                $call(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $times = ['php' => [], 'rust' => []];
                        $orders = $inputIndices = [];
                        for ($pair = 0; $pair < 41; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < 200; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                            $orders[] = $order;
                            $inputIndices[] = $indices;
                            foreach ($order as $side) {
                                self::toggle($class, $side === 'rust');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $call(...$args);
                                    $times[$side][] = hrtime(true) - $start;
                                    unset($result);
                                }
                            }
                        }
                        $stats = [];
                        foreach ($times as $side => $observations) {
                            sort($observations);
                            $stats[$side] = ['mean_ns' => array_sum($observations) / count($observations), 'p99_ns' => $observations[(int) ceil(count($observations) * 0.99) - 1]];
                        }
                        $mean = $stats['php']['mean_ns'] / $stats['rust']['mean_ns'];
                        $p99 = $stats['php']['p99_ns'] / $stats['rust']['p99_ns'];
                        $worst[$kind.'_mean'] = min($worst[$kind.'_mean'] ?? INF, $mean);
                        $worst[$kind.'_p99'] = min($worst[$kind.'_p99'] ?? INF, $p99);
                        $report['results'][$name][$kind][] = ['stats' => $stats, 'mean_speedup' => $mean, 'p99_speedup' => $p99, 'samples_ns' => $times, 'paired_order' => $orders, 'input_indices' => $inputIndices, 'inputs_php_serialize_base64' => base64_encode(serialize($inputs))];
                    }
                }
                $report['gate'][$name] = $worst + ['qualified' => min($worst) >= 1.5];
                echo $name.' '.json_encode($report['gate'][$name], JSON_THROW_ON_ERROR).PHP_EOL;
            }
        } catch (Throwable $exception) {
            $report['failures'][] = ['case' => $name ?? 'startup', 'class' => $exception::class, 'message' => $exception->getMessage()];
            throw $exception;
        } finally {
            file_put_contents($outputPath, json_encode($report, JSON_THROW_ON_ERROR));
            foreach (self::CLASSES as $class) {
                $class::resetRustFfi();
            }
        }
    }
}
