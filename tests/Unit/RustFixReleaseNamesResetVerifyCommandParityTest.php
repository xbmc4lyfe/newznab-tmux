<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\FixReleaseNames;
use App\Console\Commands\NntmuxResetDb;
use App\Console\Commands\ProcessFixRelNames;
use App\Console\Commands\ReleasesFixNamesGroup;
use App\Console\Commands\VerifyExistingReleases;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustFixReleaseNamesResetVerifyCommandParityTest extends TestCase
{
    /** @var list<class-string> */
    private const TOGGLED = [
        FixReleaseNames::class,
        ReleasesFixNamesGroup::class,
        ProcessFixRelNames::class,
        NntmuxResetDb::class,
        VerifyExistingReleases::class,
    ];

    /** Helper name => owning class. */
    private const HELPERS = [
        'normalizeFixNamesPlan' => FixReleaseNames::class,
        'normalizeGroupRunPlan' => ReleasesFixNamesGroup::class,
        'planPredbSearchedUpdate' => ReleasesFixNamesGroup::class,
        'normalizeForkType' => ProcessFixRelNames::class,
        'normalizeResetPlan' => NntmuxResetDb::class,
        'normalizeAuditRunPlan' => VerifyExistingReleases::class,
    ];

    private const RANDOM_CASES = 1_200;

    private const INTS = [PHP_INT_MIN, PHP_INT_MIN + 1, -4294967296, -100, -6, -2, -1, 0, 1, 2, 3, 7, 99, 100, 1000, 4294967296, PHP_INT_MAX - 1, PHP_INT_MAX];

    private const METHODS = [
        '', '0', '1', '2', '3', '4', '7', '8', '9', '10', '17', '19', '20', '21', '99', '03', '07', '007', ' 7', '7 ', "7\n", '+7', '-3',
        '1e1', '7.0', '0x7', 'abc', '٣', "\x00", '100', '3a',
    ];

    private const CATEGORIES = [null, '', 'other', 'all', 'predb_id', 'ALL', ' all', 'all ', 'predb', 'predb_id ', "all\0", 'ünïcode'];

    private const TYPES = [
        null, '', 'standard', 'predbft', 'Standard', 'PREDBFT', ' standard', "predbft\n", "\x0bstandard\0", "\x0cpredbft", "\tPredbFT\r",
        'standrd', 'standard predbft', 'predbft2', 'ståndard', "standard\x00x",
    ];

    private const GUID_CHARS = [null, '', '0', '9', 'a', 'f', 'A', 'F', 'g', 'z', 'ab', ' ', "\x00", 'é', '-'];

    private const ENVIRONMENTS = ['local', 'Local', 'LOCAL', ' local', 'local ', 'production', 'testing', '', "local\0"];

    private const DRIVERS = ['elasticsearch', 'manticore', 'Elasticsearch', ' elasticsearch', '', 'sphinx', "elasticsearch\n"];

    /** The table list truncated by `nntmux:resetdb`. */
    private const RESET_TABLES = [
        'binaries', 'collections', 'collection_groups', 'parts', 'missed_parts', 'videos', 'videos_aliases', 'tv_episodes', 'tv_info',
        'release_nfos', 'nfo_cleanup_commits', 'release_comments', 'users_releases', 'user_movies', 'user_series', 'movieinfo', 'musicinfo',
        'bookinfo', 'consoleinfo', 'gamesinfo', 'release_files', 'audio_data', 'release_subtitles', 'video_data', 'media_infos', 'par_hashes',
        'par_file_hashes', 'par_file_hash_inventories', 'par2_descriptor_evidence', 'par2_hash_retry', 'par2_rename_candidate_progress',
        'par2_rename_progress', 'release_regexes', 'release_unique', 'release_reports', 'release_nzb_creation_failures', 'release_nzb_passwords',
        'releases', 'anidb_titles', 'anidb_info', 'releases_groups',
    ];

    private const TABLE_NAMES = [
        'releases', 'RELEASES', ' releases ', "releases\n", '_ok9', 'a', 'A_b_C', '1bad', 'bad-name', 'bad name', '', ' ', 'drop;table',
        'tbl`x', 'ünïcode', "nul\0x", 'x.y',
    ];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::TOGGLED as $class) {
            $class::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles_independently(): void
    {
        foreach (self::TOGGLED as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            foreach (array_diff(self::TOGGLED, [$class]) as $other) {
                $this->assertFalse($other::usesRustFfi(), $other.' must not follow '.$class);
            }
            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(true);
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
            $this->assertNull((new ReflectionProperty($class, 'ffi'))->getValue(), $class);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['method' => 7, 'valid' => true, 'source' => 'par2', 'pass' => 1, 'requires_nntp' => true, 'other' => 2, 'category' => 'all',
                'limit' => 1, 'update' => true, 'set_status' => false, 'show' => true],
            FixReleaseNames::normalizeFixNamesPlan('7', 'all', -5, true, false, true),
        );
        $plan = FixReleaseNames::normalizeFixNamesPlan('20', 'predb_id', null);
        $this->assertSame(['crc', 2, 3, null, false], [$plan['source'], $plan['pass'], $plan['other'], $plan['limit'], $plan['requires_nntp']]);
        foreach (['', '2', '21', '07', ' 7', '7 ', "7\n", '1e1', '+7', '٣'] as $bad) {
            $plan = FixReleaseNames::normalizeFixNamesPlan($bad, 'ALL', 10);
            $this->assertSame([0, false, '', 0, false, 1, 'other'], [$plan['method'], $plan['valid'], $plan['source'], $plan['pass'],
                $plan['requires_nntp'], $plan['other'], $plan['category']], bin2hex($bad));
        }
        $sources = [];
        foreach (range(3, 20) as $method) {
            $sources[] = FixReleaseNames::normalizeFixNamesPlan((string) $method, null, null)['source'];
        }
        $this->assertSame(['nfo', 'nfo', 'files', 'files', 'par2', 'par2', 'media', 'media', 'xxx_files', 'xxx_files', 'srr', 'srr',
            'par_hash', 'par_hash', 'media_movie_name', 'media_movie_name', 'crc', 'crc'], $sources);

        $this->assertSame(
            ['type' => 'standard', 'valid' => false, 'error' => 'missing_guid_char', 'guid_char' => null, 'guid_valid' => false,
                'limit' => 0, 'limit_positive' => false, 'thread' => 1, 'workers' => 1, 'thread_in_range' => true],
            ReleasesFixNamesGroup::normalizeGroupRunPlan('standard', null, 0),
        );
        $this->assertTrue(ReleasesFixNamesGroup::normalizeGroupRunPlan('standard', 'F', 5)['guid_valid']);
        $predbft = ReleasesFixNamesGroup::normalizeGroupRunPlan('predbft', null, 10, 3, 2);
        $this->assertSame(['predbft', true, null, false], [$predbft['type'], $predbft['valid'], $predbft['error'], $predbft['thread_in_range']]);
        $this->assertSame('invalid_type', ReleasesFixNamesGroup::normalizeGroupRunPlan('Standard', 'a', 1)['error']);

        $this->assertSame(['searched' => 1, 'outcome' => 'matched', 'requires_unsearched' => false], ReleasesFixNamesGroup::planPredbSearchedUpdate(true, -1, 7));
        $this->assertSame(['searched' => -6, 'outcome' => 'exhausted', 'requires_unsearched' => true], ReleasesFixNamesGroup::planPredbSearchedUpdate(false, -1, 7));
        $this->assertSame(['searched' => 1, 'outcome' => 'decrement', 'requires_unsearched' => false], ReleasesFixNamesGroup::planPredbSearchedUpdate(false, 0, 2));
        $this->assertSame(PHP_INT_MIN, ReleasesFixNamesGroup::planPredbSearchedUpdate(false, 0, PHP_INT_MIN)['searched']);

        $this->assertSame(['type' => 'standard', 'valid' => true, 'exact' => true], ProcessFixRelNames::normalizeForkType('standard'));
        $this->assertSame(['type' => 'predbft', 'valid' => true, 'exact' => false], ProcessFixRelNames::normalizeForkType(" PredbFT\n"));
        $this->assertSame(['type' => null, 'valid' => false, 'exact' => false], ProcessFixRelNames::normalizeForkType("\x0cpredbft"));
        $this->assertSame(['type' => null, 'valid' => false, 'exact' => false], ProcessFixRelNames::normalizeForkType(null));

        $reset = NntmuxResetDb::normalizeResetPlan('local', true, 'manticore', self::RESET_TABLES);
        $this->assertSame(['reset', 'manticore', self::RESET_TABLES, 0, 0], array_values($reset));
        $this->assertSame(
            ['action' => 'refuse_environment', 'search_backend' => 'elasticsearch', 'tables' => ['releases', '_ok9'], 'invalid' => 5, 'duplicates' => 1],
            NntmuxResetDb::normalizeResetPlan('Local', true, 'elasticsearch', [' releases ', 'RELEASES', 7, '1bad', 'bad-name', '_ok9', '', str_repeat('a', 65)]),
        );
        $this->assertSame('abort', NntmuxResetDb::normalizeResetPlan('local', false, '', [])['action']);

        $this->assertSame(
            ['limit' => 1, 'cursor' => 22, 'deletes_enabled' => true, 'mode' => 'delete', 'fresh_attempts' => 0, 'retry_attempts' => 1,
                'attempts' => 1, 'final_retry_turn' => false, 'first_source' => 'retry', 'last_source' => 'retry'],
            VerifyExistingReleases::normalizeAuditRunPlan(false, true, true, 0, 11, 22, false, 0, 5),
        );
        $this->assertSame(
            ['limit' => 100, 'cursor' => 11, 'deletes_enabled' => false, 'mode' => 'report', 'fresh_attempts' => 0, 'retry_attempts' => 0,
                'attempts' => 0, 'final_retry_turn' => true, 'first_source' => 'none', 'last_source' => 'none'],
            VerifyExistingReleases::normalizeAuditRunPlan(true, true, false, 100, 11, 22, true, 0, 9),
        );
    }

    public function test_audit_schedule_matches_the_command_loop(): void
    {
        foreach (range(0, 15) as $bits) {
            foreach (range(-1, 8) as $limit) {
                foreach (range(-1, 9) as $fresh) {
                    foreach (range(-1, 9) as $retry) {
                        $args = [(bool) ($bits & 1), (bool) ($bits & 2), (bool) ($bits & 4), $limit, 11, 22, (bool) ($bits & 8), $fresh, $retry];
                        $this->assertSame($this->simulateAudit(...$args), VerifyExistingReleases::normalizeAuditRunPlan(...$args), json_encode($args) ?: '');
                    }
                }
            }
        }
        $huge = VerifyExistingReleases::normalizeAuditRunPlan(false, true, true, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, true, PHP_INT_MAX, intdiv(PHP_INT_MAX, 2));
        $this->assertSame([PHP_INT_MAX, intdiv(PHP_INT_MAX, 2) + 1, intdiv(PHP_INT_MAX, 2), PHP_INT_MAX, 'retry', 'fresh'],
            [$huge['cursor'], $huge['fresh_attempts'], $huge['retry_attempts'], $huge['attempts'], $huge['first_source'], $huge['last_source']]);
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        foreach (self::METHODS as $i => $method) {
            foreach (self::CATEGORIES as $j => $category) {
                $limit = [null, ...self::INTS][($i + $j) % (count(self::INTS) + 1)];
                $this->assertHelperParity('normalizeFixNamesPlan', [$method, $category, $limit, $i % 2 === 0, $j % 2 === 0, ($i + $j) % 3 === 0]);
            }
        }
        foreach (self::TYPES as $i => $type) {
            foreach (self::GUID_CHARS as $j => $guid) {
                $this->assertHelperParity('normalizeGroupRunPlan', [$type ?? 'standard', $guid, self::INTS[$i % count(self::INTS)],
                    self::INTS[$j % count(self::INTS)], self::INTS[($i * 3 + $j) % count(self::INTS)]]);
            }
            $this->assertHelperParity('normalizeForkType', [$type]);
        }
        foreach (self::INTS as $ftmatched) {
            foreach (self::INTS as $searched) {
                $this->assertHelperParity('planPredbSearchedUpdate', [$ftmatched === 0, $ftmatched, $searched]);
                $this->assertHelperParity('planPredbSearchedUpdate', [false, $ftmatched, $searched]);
            }
        }
        foreach (self::ENVIRONMENTS as $environment) {
            foreach (self::DRIVERS as $driver) {
                $this->assertHelperParity('normalizeResetPlan', [$environment, true, $driver, self::RESET_TABLES]);
                $this->assertHelperParity('normalizeResetPlan', [$environment, false, $driver, [...self::TABLE_NAMES, null, 7, 1.5, ['x'], true]]);
            }
        }
        $this->assertHelperParity('normalizeResetPlan', ['local', true, 'manticore', []]);
        $this->assertHelperParity('normalizeResetPlan', ['local', true, 'manticore', ['a' => 'releases', 9 => 'parts', 'x' => 'RELEASES']]);
        foreach (range(0, 15) as $bits) {
            foreach ([-1, 0, 1, 100, PHP_INT_MAX] as $limit) {
                foreach ([PHP_INT_MIN, -1, 0, 1, 99, PHP_INT_MAX] as $count) {
                    $this->assertHelperParity('normalizeAuditRunPlan', [(bool) ($bits & 1), (bool) ($bits & 2), (bool) ($bits & 4), $limit,
                        PHP_INT_MIN, PHP_INT_MAX, (bool) ($bits & 8), $count, PHP_INT_MAX - ($count & 7)]);
                }
            }
        }
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3711));
        $int = fn (): int => match ($random->getInt(0, 3)) {
            0 => self::INTS[$random->getInt(0, count(self::INTS) - 1)],
            1 => $random->getInt(-3, 25),
            2 => $random->getInt(-1_000_000, 1_000_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $pick = fn (array $pool): mixed => $pool[$random->getInt(0, count($pool) - 1)];
        $bytes = fn (int $max): string => ($length = $random->getInt(0, $max)) === 0 ? '' : $random->getBytes($length);
        $mutate = fn (?string $value): ?string => match ($random->getInt(0, 5)) {
            0 => $bytes(6),
            1 => $value === null ? null : $pick([' ', "\t", "\n", "\0", "\x0b", "\x0c", '']).$value.$pick([' ', "\r", "\0", '', 'x']),
            2 => $value === null ? null : strtoupper($value),
            3 => $value === null ? null : ucfirst($value),
            default => $value,
        };

        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $method = $random->getInt(0, 2) === 0 ? (string) $random->getInt(-5, 120) : (string) $mutate($pick(self::METHODS));
            $this->assertHelperParity('normalizeFixNamesPlan', [$method, $mutate($pick(self::CATEGORIES)),
                $random->getInt(0, 3) === 0 ? null : $int(), (bool) $random->getInt(0, 1), (bool) $random->getInt(0, 1), (bool) $random->getInt(0, 1)]);
            $this->assertHelperParity('normalizeGroupRunPlan', [(string) $mutate($pick(self::TYPES)), $mutate($pick(self::GUID_CHARS)), $int(), $int(), $int()]);
            $this->assertHelperParity('planPredbSearchedUpdate', [(bool) $random->getInt(0, 1), $int(), $int()]);
            $this->assertHelperParity('normalizeForkType', [$mutate($pick(self::TYPES))]);
            $tables = [];
            for ($t = $random->getInt(0, 60); $t > 0; $t--) {
                $tables[] = match ($random->getInt(0, 9)) {
                    0 => $random->getInt(0, 9),
                    1 => null,
                    2 => $bytes(12),
                    3 => str_repeat('t', $random->getInt(60, 70)),
                    4, 5 => $mutate($pick(self::TABLE_NAMES)),
                    default => $mutate($pick(self::RESET_TABLES)),
                };
            }
            $this->assertHelperParity('normalizeResetPlan', [(string) $mutate($pick(self::ENVIRONMENTS)), (bool) $random->getInt(0, 1),
                (string) $mutate($pick(self::DRIVERS)), $tables]);
            $this->assertHelperParity('normalizeAuditRunPlan', [(bool) $random->getInt(0, 1), (bool) $random->getInt(0, 1), (bool) $random->getInt(0, 1),
                $int(), $int(), $int(), (bool) $random->getInt(0, 1), $int(), $int()]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[64]');
        FFI::memset($out, ord('Z'), 64);
        $input = pack('q2', 0, 5);

        $this->assertSame(-24, $this->callFfi($ffi, 3, 0, $input, 16, $out, 23));
        $this->assertSame(-24, $this->callFfi($ffi, 3, 0, $input, 16, $out, 0));
        foreach ([[0, 0], [7, 0], [3, 2], [1, 16], [6, 16], [4, 2]] as [$operation, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $flags, $input, 16, $out, 64), "op {$operation} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, null, 16, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 16, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 15, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 16_777_217, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 5, 0, pack('q3', 0, 0, PHP_INT_MAX), 24, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q', 1).'x'.pack('q', 1).'a'.pack('q3', 1, 1, 1), 42, $out, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));

        // A short buffer reports the exact size; retrying with that capacity succeeds without overrunning it.
        $reset = pack('q', 5).'local'.pack('q', 9).'manticore'.pack('q', 2).pack('q', 8).'releases'.pack('q', 5).'parts';
        $required = $this->callFfi($ffi, 5, 1, $reset, strlen($reset), $out, 8);
        $this->assertSame(-69, $required);
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));
        $large = $ffi->new('char[96]');
        FFI::memset($large, ord('Z'), 96);
        $this->assertSame(69, $this->callFfi($ffi, 5, 1, $reset, strlen($reset), $large, -$required));
        $this->assertSame(pack('q5', 3, 2, 0, 0, 2).pack('q', 8).'releases'.pack('q', 5).'parts'.str_repeat('Z', 27), FFI::string($large, 96));

        $this->assertSame(24, $this->callFfi($ffi, 3, 0, $input, 16, $out, 64));
        $this->assertSame(pack('q3', 4, 3, 1), FFI::string($out, 24));

        foreach (self::TOGGLED as $class) {
            $class::useRustFfi(true);
        }
        FixReleaseNames::normalizeFixNamesPlan('3', null, null);
        ReleasesFixNamesGroup::planPredbSearchedUpdate(true, 0, 0);
        ProcessFixRelNames::normalizeForkType('standard');
        NntmuxResetDb::normalizeResetPlan('local', true, 'manticore', ['releases']);
        VerifyExistingReleases::normalizeAuditRunPlan(false, false, false, 1, 0, 0);
        foreach (self::TOGGLED as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $cases = [
            'normalizeFixNamesPlan' => ['7', 'all', 5, true, true, false],
            'normalizeGroupRunPlan' => ['standard', 'a', 10, 1, 1],
            'planPredbSearchedUpdate' => [false, -1, 3],
            'normalizeForkType' => [' Standard '],
            'normalizeResetPlan' => ['local', true, 'manticore', ['releases', 'RELEASES', 'bad-name']],
            'normalizeAuditRunPlan' => [false, true, true, 10, 1, 2, true, 4, 7],
        ];
        foreach (self::TOGGLED as $class) {
            $class::useRustFfi(true);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, false);
        }
        foreach ($cases as $helper => $args) {
            $class = self::HELPERS[$helper];
            $this->assertNull($this->helper($class, $helper.'Native')(...$args), $helper);
            $this->assertSame($this->helper($class, $helper.'Php')(...$args), $class::$helper(...$args), $helper);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the six helpers through their public dispatchers,
        // including frame packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x
        // 40 iterations). Common = canonical CLI invocations, the 41-table reset list and a 100-row
        // audit; uncommon = malformed/padded arguments, extreme integers, a 400-entry mixed table
        // list and PHP_INT_MAX audit queues.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.31x / p99 0.33x, uncommon mean 0.45x / p99 0.45x
        // - Round 2: common mean 0.32x / p99 0.36x, uncommon mean 0.46x / p99 0.48x
        // - Round 3: common mean 0.32x / p99 0.33x, uncommon mean 0.46x / p99 0.48x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustFixReleaseNamesResetVerifyCommandParityTest.php --filter benchmark
        // The FFI round trip and frame packing cost more than these short PHP computations, so every
        // helper stays below the 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3712));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::TOGGLED as $class) {
                $class::useRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
                'samples' => $samples,
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustFixReleaseNamesResetVerifyCommand speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::TOGGLED as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertHelperParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        $expected = $this->helper($class, $helper.'Php')(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $this->assertSame($expected, $this->helper($class, $helper.'Native')(...$args), $context);
        foreach ([true, false] as $useRust) {
            $class::useRustFfi($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::useRustFfi(null);
    }

    private function helper(string $class, string $method): Closure
    {
        return $this->closures[$class.'::'.$method] ??= (new ReflectionMethod($class, $method))->getClosure(null);
    }

    /**
     * Literal transcription of the `releases:verify-repairable` loop, assuming every queued release exists.
     *
     * @return array<string, mixed>
     */
    private function simulateAudit(bool $dryRun, bool $delete, bool $resume, int $limit, int $sinceId, int $stateCursor,
        bool $retryTurn, int $freshCount, int $retryCount): array
    {
        $limit = max(1, $limit);
        $freshIds = $freshCount > 0 ? range(1, min($limit, $freshCount)) : [];
        $retryIds = $resume && $retryCount > 0 ? range(1, min($limit, $retryCount)) : [];
        $attempted = 0;
        $used = ['fresh' => 0, 'retry' => 0];
        $first = 'none';
        $last = 'none';
        while ($attempted < $limit && ($freshIds !== [] || $retryIds !== [])) {
            $isRetry = $retryIds !== [] && ($retryTurn || $freshIds === []);
            if ($isRetry) {
                array_shift($retryIds);
            } else {
                array_shift($freshIds);
            }
            $attempted++;
            $last = $isRetry ? 'retry' : 'fresh';
            $first = $first === 'none' ? $last : $first;
            $used[$last]++;
            $retryTurn = ! $isRetry;
        }
        $deletes = ! $dryRun && $delete;

        return [
            'limit' => $limit,
            'cursor' => $resume ? $stateCursor : $sinceId,
            'deletes_enabled' => $deletes,
            'mode' => $deletes ? 'delete' : 'report',
            'fresh_attempts' => $used['fresh'],
            'retry_attempts' => $used['retry'],
            'attempts' => $attempted,
            'final_retry_turn' => $retryTurn,
            'first_source' => $first,
            'last_source' => $last,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commonWorkload(): array
    {
        return [
            'fix' => [['3', 'other', 1000], ['4', 'all', null], ['7', 'other', 500], ['18', 'predb_id', 1000]],
            'group' => [['standard', 'a', 1000, 1, 1], ['standard', '7', 1000, 1, 1], ['predbft', null, 1000, 2, 4], ['predbft', null, 500, 4, 4]],
            'predb' => [[true, 1, 0], [false, 0, 0], [false, -1, -2], [false, 0, -1]],
            'fork' => ['standard', 'predbft', 'standard', 'predbft'],
            'reset' => ['local', true, 'manticore', self::RESET_TABLES],
            'audit' => [[true, false, false, 100, 0, 0, false, 100, 0], [false, true, true, 100, 0, 5000, true, 100, 12],
                [false, true, true, 100, 0, 5000, false, 40, 3], [true, false, true, 50, 0, 900, true, 0, 0]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uncommonWorkload(): array
    {
        $tables = [];
        foreach (range(0, 399) as $i) {
            $tables[] = match ($i % 8) {
                0 => ' '.self::RESET_TABLES[$i % 41].' ',
                1 => strtoupper(self::RESET_TABLES[$i % 41]),
                2 => 'bad-name-'.$i,
                3 => $i,
                4 => str_repeat('t', 64 + $i % 3),
                default => self::RESET_TABLES[$i % 41].'_'.$i,
            };
        }

        return [
            'fix' => [[' 7', 'ALL', PHP_INT_MIN], ['007', null, PHP_INT_MAX], [str_repeat('9', 40), "predb_id\0", 0], ['20', ' all', -1]],
            'group' => [['Standard', 'G', PHP_INT_MIN, 0, 0], ['standard', null, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN], [str_repeat('x', 300), 'é', -1, -1, -1],
                ['predbft', 'ab', 0, PHP_INT_MAX, PHP_INT_MAX]],
            'predb' => [[false, 0, PHP_INT_MIN], [false, PHP_INT_MIN, PHP_INT_MAX], [true, PHP_INT_MAX, PHP_INT_MIN], [false, PHP_INT_MAX, 1]],
            'fork' => [" PredbFT\n", "\x0bstandard\0", null, str_repeat(' ', 200).'standard'],
            'reset' => [' local', false, 'Elasticsearch', $tables],
            'audit' => [[false, true, true, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, true, PHP_INT_MAX, PHP_INT_MAX - 1],
                [true, true, false, PHP_INT_MIN, -1, -1, false, PHP_INT_MIN, PHP_INT_MAX], [false, false, true, 3, 0, 0, true, 9, 9],
                [false, true, true, 1, 0, 0, false, 0, PHP_INT_MAX]],
        ];
    }

    /**
     * @param  array<string, mixed>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::TOGGLED as $class) {
            $class::useRustFfi($useRust);
        }
        $sink = 0;
        foreach ($workload['fix'] as $i => [$method, $category, $limit]) {
            $sink += FixReleaseNames::normalizeFixNamesPlan($method, $category, $limit, $i % 2 === 0, true, false)['pass'];
            $sink += (int) ReleasesFixNamesGroup::normalizeGroupRunPlan(...$workload['group'][$i])['valid'];
            $sink += ReleasesFixNamesGroup::planPredbSearchedUpdate(...$workload['predb'][$i])['searched'] & 0xFF;
            $sink += (int) ProcessFixRelNames::normalizeForkType($workload['fork'][$i])['valid'];
            $sink += VerifyExistingReleases::normalizeAuditRunPlan(...$workload['audit'][$i])['attempts'] & 0xFF;
        }
        $sink += count(NntmuxResetDb::normalizeResetPlan(...$workload['reset'])['tables']);

        return $sink;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w371_fix_names_reset_verify(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w371', FixReleaseNames::FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 37.1 fix-names/reset/verify export is not available.');
        }

        return $ffi;
    }
}
