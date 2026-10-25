<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\NzbUploadException;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbSourceInstallation;
use App\Services\Nzb\NzbUploadStagingService;
use App\Services\Nzb\NzbXmlElements;
use App\Services\Nzb\Par2CandidateRecovery;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest extends TestCase
{
    /** class => helper method; every class exposes the same Wave 42.2 toggle trio. */
    private const array HELPERS = [
        NzbSourceGuard::class => 'summarizeStagedNameBatch',
        NzbSourceInstallation::class => 'summarizeSourcePathPairs',
        NzbUploadStagingService::class => 'summarizeUploadFilenames',
        NzbXmlElements::class => 'summarizeMetadataTypes',
        Par2CandidateRecovery::class => 'summarizePartOffsets',
    ];

    private const array NAMES = ['abc.nzb.gz.c12-0123456789abcdef.next', 'a.b.nzb.gz.c1.nzb.gz.c7-ffffffffffffffff.next', 'abc.nzb.gz', 'abc.nzb',
        'abc.nzb.gz.c12-0123456789ABCDEF.next', "a\nb.nzb.gz.c1-0123456789abcdef.next", '', '.nzb.gz.c1-0123456789abcdef.next',
        '0123456789012345678901234567890123456789.nzb', '01234567890123456789012345678901234567890.nzb', 'abc.nzb.gz.c-0123456789abcdef.next',
        'abc.nzb.gz.c1-0123456789abcdef.next\n', "abc.nzb.gz.c1-0123456789abcdef.next\n", 'abc.nzb.gz.c1-0123456789abcde.next', 'a-b_c.nzb', 'a b.nzb',
        'abc.NZB', "\0.nzb", 'abc.nzb.gz.c1٣-0123456789abcdef.next', 'ä.nzb.gz.c9-0000000000000000.next', 'x.nzb.gz.c00-0123456789abcdef.next'];

    private const array PATHS = ['', '/', '/a/x.nzb.gz', '/a/x.nzb.gz.c1-0123456789abcdef.next', '/b/x.nzb.gz', 'x', 'y', 'a/', 'a/b', '//', '/a//x',
        "/a\0/x", '/ä/x', '/a/', '/a'];

    private const array FILENAMES = ['movie.nzb', 'movie.NZB', 'a.b.nzb', '.nzb', " \t.nzb", 'movie.nfo', 'nzb', '', '.', '..', 'a/b.nzb', 'a\\b.nzb',
        "a\0.nzb", 'a.', 'a', '...nzb', '. .nzb', "\x0b.nzb", 'Ä.nzb', 'movie.nZb', 'movie.nzb ', ' movie.nzb', 'x.tar.nzb', 'x..nzb', '-.nfo', 'movie.Nfo'];

    private const array TYPES = ['password', 'Password', 'PASSWORD', '', 'title', 'password ', ' password', 'pass', "password\0", 'category', 'tag',
        'pAsSwOrD', 'passwörd', 'name'];

    /** class => W422_RUST_FFI_DEFAULT; only helpers clearing every 1.5x gate default to Rust. */
    private const array DEFAULTS = [
        NzbSourceGuard::class => false,
        NzbSourceInstallation::class => false,
        NzbUploadStagingService::class => true,
        NzbXmlElements::class => false,
        Par2CandidateRecovery::class => false,
    ];

    private const int SEED = 42022026;

    private const int RANDOMIZED = 1300;

    protected function tearDown(): void
    {
        foreach (array_keys(self::HELPERS) as $class) {
            $class::resetW422RustFfi();
        }
        parent::tearDown();
    }

    public function test_defaults_and_toggles_are_independent(): void
    {
        $earlier = self::earlierToggles();
        $this->assertCount(5, $earlier);
        foreach (self::DEFAULTS as $class => $default) {
            $this->assertSame($default, \constant($class.'::W422_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW422RustFfi(), $class);
        }
        foreach (array_keys(self::HELPERS) as $selected) {
            foreach (array_keys(self::HELPERS) as $class) {
                $class::useW422RustFfi(false);
            }
            $selected::useW422RustFfi();
            foreach (array_keys(self::HELPERS) as $class) {
                $this->assertSame($selected === $class, $class::usesW422RustFfi(), $selected.' → '.$class);
            }
            $this->assertSame($earlier, self::earlierToggles(), $selected.' leaves the earlier toggles alone');
            $selected::useW422RustFfi(false);
            $this->assertFalse($selected::usesW422RustFfi());
            $selected::useW422RustFfi(true);
            $this->assertTrue($selected::usesW422RustFfi());
            foreach (array_keys(self::HELPERS) as $class) {
                $class::resetW422RustFfi();
                $this->assertSame(self::DEFAULTS[$class], $class::usesW422RustFfi(), $class);
            }
        }
        // The earlier toggles leave the Wave 42.2 toggles alone.
        NzbSourceGuard::setUseRustFfi(! NzbSourceGuard::usesRustFfi());
        NzbSourceInstallation::setUseRustFfi(! NzbSourceInstallation::usesRustFfi());
        NzbUploadStagingService::setUseRustFfi(! NzbUploadStagingService::usesRustFfi());
        NzbXmlElements::setUseRustFfi(true);
        Par2CandidateRecovery::setUseRustFfi(! Par2CandidateRecovery::usesRustFfi());
        try {
            foreach (self::DEFAULTS as $class => $default) {
                $this->assertSame($default, $class::usesW422RustFfi(), $class);
            }
        } finally {
            foreach ($earlier as $class => $value) {
                (new ReflectionClass($class))->getProperty('useRustFfi')->setValue(null, $value);
            }
        }
        $this->assertSame($earlier, self::earlierToggles());
    }

    public function test_php_helpers_answer_known_vectors(): void
    {
        $this->assertSame(['names' => 10, 'invalid' => 1, 'staged' => 2, 'canonical' => 2, 'other' => 5, 'guid_bytes' => 16, 'max_guid_bytes' => 13],
            NzbSourceGuard::summarizeStagedNameBatch(['abc.nzb.gz.c12-0123456789abcdef.next', 'a.b.nzb.gz.c1.nzb.gz.c7-ffffffffffffffff.next', 'abc.nzb.gz',
                'abc.nzb', 'abc.nzb.gz.c12-0123456789ABCDEF.next', "a\nb.nzb.gz.c1-0123456789abcdef.next", '', null,
                '0123456789012345678901234567890123456789x.nzb', "abc.nzb.gz.c1-0123456789abcdef.next\n"]));

        $this->assertSame(['pairs' => 8, 'invalid' => 2, 'unchanged' => 2, 'in_place' => 2, 'relocated' => 2, 'sibling' => 3, 'empty_paths' => 2],
            NzbSourceInstallation::summarizeSourcePathPairs([['source' => '/a/x.nzb.gz', 'destination' => '/a/x.nzb.gz'],
                ['source' => '/a/x.nzb.gz', 'destination' => '/a/x.next'], ['source' => '/a/x.nzb.gz', 'destination' => '/b/x.next'],
                ['source' => 'x', 'destination' => 'y'], ['source' => '', 'destination' => 'y'], ['source' => '', 'destination' => ''],
                ['destination' => 'y'], 'pair']));

        $this->assertSame(['filenames' => 13, 'invalid' => 1, 'unsafe' => 5, 'wrong_extension' => 4, 'accepted' => 3, 'basename_bytes' => 13],
            NzbUploadStagingService::summarizeUploadFilenames(['movie.nzb', 'movie.NZB', 'a.b.nzb', '.nzb', " \t.nzb", 'movie.nfo', 'nzb', '', '..',
                'a/b.nzb', 'a\\b.nzb', "a\0.nzb", 7]));
        $this->assertSame([0, 1], self::pick(NzbUploadStagingService::summarizeUploadFilenames(['a.NZB'], 'NZB'), ['accepted', 'wrong_extension']));
        $this->assertSame([2, 2], self::pick(NzbUploadStagingService::summarizeUploadFilenames(['a.', 'a'], ''), ['accepted', 'basename_bytes']));

        $this->assertSame(['types' => 7, 'invalid' => 1, 'password' => 1, 'password_like' => 2, 'empty' => 1, 'other' => 2, 'rejected' => 1],
            NzbXmlElements::summarizeMetadataTypes(['password', 'Password', 'PASSWORD', '', 'title', 'password ', null], 2));
        $this->assertSame(0, NzbXmlElements::summarizeMetadataTypes(['password', 'password'], 1)['rejected']);

        $this->assertSame(['parts' => 9, 'invalid' => 2, 'contiguous' => 3, 'gaps' => 1, 'overlaps' => 1, 'out_of_range' => 2, 'decoded_offset' => 10,
            'missing_bytes' => 0], Par2CandidateRecovery::summarizePartOffsets([['begin' => 1, 'end' => 4], ['begin' => 3, 'end' => 5],
                ['begin' => 5, 'end' => 6], ['begin' => 8, 'end' => 9], ['begin' => '7', 'end' => 10], ['begin' => 7, 'end' => 11], ['begin' => 7, 'end' => 10],
                ['begin' => 0, 'end' => 0], 'part'], 10));
        $this->assertSame([1, PHP_INT_MAX, 0], self::pick(Par2CandidateRecovery::summarizePartOffsets([['begin' => 1, 'end' => PHP_INT_MAX],
            ['begin' => PHP_INT_MAX, 'end' => PHP_INT_MAX]], PHP_INT_MAX), ['overlaps', 'decoded_offset', 'missing_bytes']));
        $this->assertSame([1, 0], self::pick(Par2CandidateRecovery::summarizePartOffsets([['begin' => 1, 'end' => 1]], -5), ['out_of_range', 'missing_bytes']));
    }

    public function test_php_helpers_agree_with_the_single_value_validators(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 3));
        $names = [...self::NAMES, ...array_map(static fn (): string => self::randomName($random), range(1, 400))];
        foreach ($names as $name) {
            $guid = NzbSourceGuard::stagedGuidPhp($name);
            $summary = NzbSourceGuard::summarizeStagedNameBatchPhp([$name]);
            $this->assertSame($guid === null ? 0 : 1, $summary['staged'], bin2hex($name));
            $this->assertSame($guid === null ? 0 : \strlen($guid), $summary['guid_bytes'], bin2hex($name));
        }
        $filenames = [...self::FILENAMES, ...array_map(static fn (): string => self::randomFilename($random), range(1, 400))];
        foreach (['nzb', 'nfo', 'NZB', ''] as $extension) {
            foreach ($filenames as $filename) {
                try {
                    $expected = ['accepted' => 1, 'basename_bytes' => \strlen(NzbUploadStagingService::validateFilenamePhp($filename, $extension))];
                } catch (NzbUploadException $exception) {
                    $expected = [str_starts_with($exception->getMessage(), 'Unsafe') ? 'unsafe' : 'wrong_extension' => 1];
                }
                $summary = NzbUploadStagingService::summarizeUploadFilenamesPhp([$filename], $extension);
                $this->assertSame($expected, array_intersect_key($summary, $expected), $extension.' '.bin2hex($filename));
            }
        }
    }

    public function test_native_matches_php_on_edge_cases_and_randomized_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $class => $method) {
            $cases = [...self::edgeCases($class), ...array_map(static fn (): array => self::randomArgs($class, $random), range(1, self::RANDOMIZED))];
            $this->assertGreaterThanOrEqual(1300, \count($cases));
            foreach ($cases as $args) {
                $expected = $class::{$method.'Php'}(...$args);
                $native = $class::{$method.'Native'}(...$args);
                $this->assertNotNull($native, $class.'::'.$method.' declined '.self::describe($args));
                $this->assertSame($expected, $native, $class.'::'.$method.' '.self::describe($args));
                $class::useW422RustFfi(true);
                $viaRust = $class::$method(...$args);
                $class::useW422RustFfi(false);
                $viaPhp = $class::$method(...$args);
                $this->assertSame([$expected, $expected], [$viaRust, $viaPhp], $class.'::'.$method.' dispatch');
            }
        }
    }

    public function test_benchmark_qualified_default_dispatches_without_overrides(): void
    {
        $this->nativeFfi();
        foreach (self::HELPERS as $class => $method) {
            $class::resetW422RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW422RustFfi(), $class);
            foreach (self::edgeCases($class) as $args) {
                $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
            }
        }
        $this->assertNotNull(NzbUploadStagingService::summarizeUploadFilenamesNative(self::FILENAMES));
    }

    public function test_out_of_contract_inputs_fall_back_to_php(): void
    {
        $this->nativeFfi();
        $valid = pack('q4', 10, 1, 1, 10);
        foreach ([[5, pack('q', 0)], [-1, $valid], [4294967296, $valid], [0, ''], [0, 'short'], [0, pack('q', 0)], [0, pack('q2', 1, 0)],
            [0, pack('q3', 0, 1, 3).'ab'], [0, pack('q3', 0, 1, -2)], [0, pack('q2', 0, 0).'x'],
            [1, pack('q3', 0, 1, 1).'a'], [1, pack('q4', 0, 1, 1, 1).'a'], [1, pack('q2', 1, 0)],
            [2, pack('q2', 4, 0).'nzb'], [2, pack('q2', -1, 0)], [2, pack('q2', 3, 0).'nzbx'], [2, pack('q3', 3, 1, 2).'nzba'],
            [3, pack('q3', 2, 1, 8).'passwor'], [3, pack('q', 2)],
            [4, pack('q', 7).'x'], [4, pack('q4', 7, 2, 1, 1)], [4, pack('q3', 7, 1, 1)], [4, str_repeat("\0", 16777224)]] as [$op, $input]) {
            $this->assertNull(NzbSourceGuard::evaluateW422Native($op, $input, 8), $op.' '.bin2hex(substr($input, 0, 48)));
        }
        $this->assertNull(NzbSourceGuard::evaluateW422Native(4, $valid, 0));
        // A wrong expected slot count is a contract violation, not a partial answer.
        $this->assertNull(NzbSourceGuard::evaluateW422Native(4, $valid, 7));
        $this->assertNull(NzbSourceGuard::evaluateW422Native(4, $valid, 9));
        $this->assertSame([1, 0, 1, 0, 0, 0, 10, 0], NzbSourceGuard::evaluateW422Native(4, $valid, 8));
        $this->assertNull(NzbSourceGuard::packW422Texts(0, ['a'], '', 2));
        $this->assertNull(NzbSourceGuard::packW422Texts(0, ['a'], '', 0));
        $huge = str_repeat('n', 16777200);
        $oversized = [
            NzbSourceGuard::class => [[$huge]],
            NzbSourceInstallation::class => [[['source' => $huge, 'destination' => 'x']]],
            NzbUploadStagingService::class => [['a.nzb'], $huge],
            NzbXmlElements::class => [[$huge], 2],
            Par2CandidateRecovery::class => [array_fill(0, 699051, ['begin' => 1, 'end' => 1]), 10],
        ];
        foreach ($oversized as $class => $args) {
            $method = self::HELPERS[$class];
            $this->assertNull($class::{$method.'Native'}(...$args), $class);
            $class::useW422RustFfi(true);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
        }
    }

    public function test_direct_ffi_export_preserves_sentinels_and_capacity_contract(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, pack('q3', 0, 1, 7).'abc.nzb', [1, 0, 0, 1, 0, 0, 0]],
            [1, pack('q4', 0, 1, 2, 2).'/a/b', [1, 0, 0, 1, 0, 1, 0]],
            [2, pack('q3', 3, 1, 5).'nzba.nzb', [1, 0, 0, 0, 1, 1]],
            [3, pack('q3', 2, 1, 8).'password', [1, 0, 1, 0, 0, 0, 1]],
            [4, pack('q4', 10, 1, 1, 10), [1, 0, 1, 0, 0, 0, 10, 0]]];
        foreach ($vectors as [$op, $input, $expected]) {
            $length = \count($expected) * 8;
            $wire = pack('q*', ...$expected);
            $this->assertSame($expected, NzbSourceGuard::evaluateW422Native($op, $input, \count($expected)));
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w422_nzb_source_guard_install_staging_xml_par2($op, 0, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2), $op.' capacity '.$capacity);
                if ($written < 0) {
                    // Retry with exactly the reported capacity.
                    $retry = $ffi->new('char['.(-$written).']');
                    // @phpstan-ignore method.notFound
                    $this->assertSame(-$written, $ffi->nntmux_w422_nzb_source_guard_install_staging_xml_par2($op, 0, $input, \strlen($input), $retry, -$written));
                    $this->assertSame($wire, FFI::string($retry, -$written));
                }
            }
            // Every flag bit is rejected for every operation.
            foreach ([1, 64, 4294967295] as $flags) {
                // @phpstan-ignore method.notFound
                $this->assertSame(-1, $ffi->nntmux_w422_nzb_source_guard_install_staging_xml_par2($op, $flags, $input, \strlen($input), $small, $length + 2));
            }
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q4', 10, 1, 1, 10);
        foreach ([[null, 0, $buffer, 1024], [null, 32, $buffer, 1024], [$input, 32, null, 0], [$input, 32, null, 64], [$input, 16777217, $buffer, 1024],
            [$buffer, 32, $buffer, 1024], [FFI::addr($buffer[8]), 32, $buffer, 1024], [$buffer, 32, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 32);
            FFI::memset(FFI::addr($buffer[32]), 0x5A, 992);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w422_nzb_source_guard_install_staging_xml_par2(4, 0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 32).str_repeat('Z', 992), FFI::string($buffer, 1024));
        }
        foreach ([5, 99, 4294967295] as $op) {
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w422_nzb_source_guard_install_staging_xml_par2($op, 0, $input, 32, $buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $helpers = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Services\Nzb\NzbSourceGuard::evaluateW422Native(4, pack('q4', 10, 1, 1, 10), 8) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($helpers as [$class, $method, $cases]) {
                foreach ($cases as $args) {
                    if ($class::{$method.'Native'}(...$args) !== null) {
                        throw new RuntimeException($class.'::'.$method.' answered without a library');
                    }
                    $class::useW422RustFfi(false);
                    $expected = serialize($class::$method(...$args));
                    $class::useW422RustFfi(true);
                    if ($expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $helpers = [];
        foreach (self::HELPERS as $class => $method) {
            $helpers[] = [$class, $method, self::edgeCases($class)];
        }
        $missing = '/tmp/w42-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize($helpers))], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    /**
     * Per-run minimum speedups over 3 rounds, two final runs (PHP 8.5.12 NTS, opcache CLI off, Apple M5; PHP latency /
     * Rust latency), common mean/p99 then uncommon mean/p99: staged names 1.16-1.21x/1.12-1.18x, 1.58-1.61x/1.51x; path
     * pairs 1.49-1.50x/1.40-1.51x, 1.54-1.55x/1.67x; upload filenames 1.90-2.06x/1.72-1.91x, 2.17x/2.39-2.43x; metadata
     * types 1.00-1.03x/0.82-1.00x, 1.30-1.37x/1.47-1.53x; part offsets 0.68-0.69x/0.59-0.60x, 0.79-0.80x/0.84-0.85x.
     * Upload filenames cleared every gate in 8 of 9 earlier full runs too (the first, cold run after the release build
     * measured a 1.06x common mean), so only NzbUploadStagingService::W422_RUST_FFI_DEFAULT is true.
     */
    public function test_paired_randomized_benchmarks_gate_rust_defaults(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave42-2-nzb-source-guard-install-staging-xml-par2-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including normalization, packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && php artisan test --compact tests/Unit/RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/Nzb/NzbSourceGuard.php', 'app/Services/Nzb/NzbSourceInstallation.php',
            'app/Services/Nzb/NzbUploadStagingService.php', 'app/Services/Nzb/NzbXmlElements.php', 'app/Services/Nzb/Par2CandidateRecovery.php',
            'tests/Unit/RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        try {
            foreach (self::workloads() as [$class, $common, $uncommon]) {
                $method = self::HELPERS[$class];
                $name = $class.'::'.$method;
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::{$method.'Php'}(...$args));
                        $this->assertSame(end($expected), serialize($class::{$method.'Native'}(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'inputs' => \count($inputs), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::useW422RustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % \count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < $pairs; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < $calls; $i++) {
                                $indices[] = $random->getInt(0, \count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            foreach ($order as $side) {
                                $class::useW422RustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / max(1.0, $nativeStats['mean_ns']), 'p99_speedup' => $phpStats['p99_ns'] / max(1, $nativeStats['p99_ns'])];
                    }
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $qualifies = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $report['results'][$name]['qualifies'] = $qualifies;
                $class::resetW422RustFfi();
                $this->assertSame(self::DEFAULTS[$class], \constant($class.'::W422_RUST_FFI_DEFAULT'), $name);
                if (! $qualifies) {
                    $this->assertFalse(\constant($class.'::W422_RUST_FFI_DEFAULT'), $name.' must keep PHP as the default below 1.5x');
                }
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            $summary = [];
            foreach ($report['results'] as $helper => $cells) {
                $summary[] = sprintf('%s common mean %.2fx p99 %.2fx; uncommon mean %.2fx p99 %.2fx', $helper,
                    $cells['common']['round_range']['mean_speedup']['min'], $cells['common']['round_range']['p99_speedup']['min'],
                    $cells['uncommon']['round_range']['mean_speedup']['min'], $cells['uncommon']['round_range']['p99_speedup']['min']);
            }
            fwrite(STDERR, "\nWave 42.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(NzbSourceGuard::W422_FFI_DOMAIN, NzbSourceGuard::W422_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return array<class-string, mixed> the earlier-wave `useRustFfi` toggle of every helper class */
    private static function earlierToggles(): array
    {
        $toggles = [];
        foreach (array_keys(self::HELPERS) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->hasProperty('useRustFfi')) {
                $toggles[$class] = $reflection->getProperty('useRustFfi')->getValue();
            }
        }

        return $toggles;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private static function pick(array $result, array $keys): array
    {
        return array_map(static fn (string $key): mixed => $result[$key], $keys);
    }

    /** @param list<mixed> $args */
    private static function describe(array $args): string
    {
        return substr(bin2hex(serialize($args)), 0, 400);
    }

    /** @return list<list<mixed>> */
    private static function edgeCases(string $class): array
    {
        $pair = static fn (mixed $source, mixed $destination): array => ['source' => $source, 'destination' => $destination];
        $part = static fn (mixed $begin, mixed $end): array => ['begin' => $begin, 'end' => $end];
        $allPairs = [];
        foreach (self::PATHS as $source) {
            foreach (self::PATHS as $destination) {
                $allPairs[] = $pair($source, $destination);
            }
        }

        return match ($class) {
            NzbSourceGuard::class => [[[]], [['x', null, 5, [], 1.5, true]], [self::NAMES], [['a' => 'abc.nzb', 'b' => 'abc.nzb.gz.c1-0123456789abcdef.next']],
                [[str_repeat('g', 4096).'.nzb.gz.c'.str_repeat('9', 40).'-0123456789abcdef.next', str_repeat('a', 40).'.nzb.gz']]],
            NzbSourceInstallation::class => [[[]], [['x', null, 5, [], ['source' => 'a'], ['destination' => 'a'], $pair(1, 'a'), $pair('a', null)]],
                [$allPairs], [['k' => $pair('/a/x', '/a/y'), 'l' => $pair('/a/x', '/a/x')]]],
            NzbUploadStagingService::class => [[[], 'nzb'], [['x', null, 5, []], 'nzb'], [self::FILENAMES, 'nzb'], [self::FILENAMES, 'nfo'],
                [self::FILENAMES, 'NZB'], [self::FILENAMES, ''], [self::FILENAMES, 'tar.nzb'], [['a.b' => 'x.nzb', 'c' => 'y.nzb'], 'nzb']],
            NzbXmlElements::class => [[[], 1], [[], PHP_INT_MIN], [['x', null, 5, []], 2], [self::TYPES, 0], [self::TYPES, 1], [self::TYPES, 2],
                [self::TYPES, PHP_INT_MAX], [self::TYPES, PHP_INT_MIN], [['a' => 'password', 'b' => 'password'], 3]],
            Par2CandidateRecovery::class => [[[], 0], [[], PHP_INT_MIN], [[], PHP_INT_MAX], [['x', null, 5, [], $part('1', 2), $part(1.0, 2), $part(1, null)], 10],
                [[$part(1, 4), $part(3, 5), $part(5, 6), $part(8, 9), $part('7', 10), $part(7, 11), $part(7, 10), $part(0, 0), 'part'], 10],
                [[$part(1, PHP_INT_MAX), $part(PHP_INT_MAX, PHP_INT_MAX), $part(PHP_INT_MIN, PHP_INT_MAX)], PHP_INT_MAX],
                [[$part(1, 1), $part(2, 1), $part(PHP_INT_MIN, PHP_INT_MIN)], -5],
                [[$part(1, PHP_INT_MAX - 1), $part(PHP_INT_MAX, PHP_INT_MAX)], PHP_INT_MAX],
                [['a' => $part(1, 1), 'b' => $part(2, 2)], 2]],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function randomName(Randomizer $random): string
    {
        $guid = $random->getInt(0, 3) === 0 ? self::bytes($random, $random->getInt(0, 6)) : bin2hex(self::bytes($random, $random->getInt(0, 24)));
        $hex = bin2hex($random->getBytes(8));

        return match ($random->getInt(0, 7)) {
            0, 1 => $guid.'.nzb.gz.c'.$random->getInt(0, 9999).'-'.$hex.'.next',
            2 => $guid.'.nzb.gz',
            3 => $guid.'.nzb',
            4 => $guid.'.nzb.gz.c'.$random->getInt(0, 99).'-'.($random->getInt(0, 1) === 0 ? strtoupper($hex) : substr($hex, 1)).'.next',
            5 => $guid.['.nzb.gz.c1-', '.nzb.c1-', '.nzb.gz.c-', '.nzb.gz.cx-'][$random->getInt(0, 3)].$hex.['.next', '.nex', ".next\n", ''][$random->getInt(0, 3)],
            6 => self::NAMES[$random->getInt(0, \count(self::NAMES) - 1)],
            default => self::bytes($random, $random->getInt(0, 48)),
        };
    }

    private static function randomFilename(Randomizer $random): string
    {
        $stem = match ($random->getInt(0, 4)) {
            0 => '', 1 => [' ', "\t", "\n", "\r", "\x0b", ' . ', '..'][$random->getInt(0, 6)],
            2 => $random->getBytes($random->getInt(1, 8)),
            default => 'Release.Name-'.$random->getInt(0, 9999),
        };
        $extension = ['nzb', 'NZB', 'Nzb', 'nfo', 'NFO', '', 'nzb ', 'tar.nzb', 'gz'][$random->getInt(0, 8)];

        return match ($random->getInt(0, 5)) {
            0 => $stem,
            1 => $stem.'.'.$extension.'.'.$extension,
            2 => self::FILENAMES[$random->getInt(0, \count(self::FILENAMES) - 1)],
            default => $stem.'.'.$extension,
        };
    }

    /** @return list<mixed> */
    private static function randomArgs(string $class, Randomizer $random): array
    {
        $rows = static function (callable $row, int $max = 12) use ($random): array {
            $out = [];
            for ($i = $random->getInt(0, $max); $i > 0; $i--) {
                $out[] = $random->getInt(0, 9) === 0 ? [null, 7, 1.5, true, []][$random->getInt(0, 4)] : $row();
            }

            return $out;
        };
        $odd = static fn (): mixed => ['5', 5.0, null, true, [], 7][$random->getInt(0, 5)];
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, \count($values) - 1)];
        $path = static fn (): string => match ($random->getInt(0, 3)) {
            0 => $pick(self::PATHS),
            1 => '/data/nzb/'.bin2hex($random->getBytes(1)).'/'.bin2hex($random->getBytes(3)).'.nzb.gz',
            2 => self::bytes($random, $random->getInt(0, 12)),
            default => '/data/nzb/'.$pick(['a', 'b']).'/'.$pick(['x.nzb.gz', 'x.nzb.gz.c1-0123456789abcdef.next', 'y.nzb.gz']),
        };

        return match ($class) {
            NzbSourceGuard::class => [$rows(static fn (): mixed => $random->getInt(0, 9) === 0 ? $odd() : self::randomName($random), 16)],
            NzbSourceInstallation::class => [$rows(static function () use ($random, $odd, $path): mixed {
                $out = [];
                foreach (['source', 'destination'] as $key) {
                    $present = $random->getInt(0, 11);
                    $kind = $random->getInt(0, 11);
                    if ($present !== 0) {
                        $out[$key] = $kind === 0 ? $odd() : $path();
                    }
                }
                if ($random->getInt(0, 2) === 0 && isset($out['source'])) {
                    $out['destination'] = $out['source'];
                }

                return $out;
            }, 16)],
            NzbUploadStagingService::class => [$rows(static fn (): mixed => $random->getInt(0, 9) === 0 ? $odd() : self::randomFilename($random), 16),
                $pick(['nzb', 'nzb', 'nfo', 'NZB', '', 'gz'])],
            NzbXmlElements::class => [$rows(static fn (): mixed => match ($random->getInt(0, 9)) {
                0 => $odd(), 1 => self::bytes($random, $random->getInt(0, 10)), default => $pick(self::TYPES),
            }, 16), $random->getInt(0, 3) === 0 ? self::int($random) : $random->getInt(-1, 4)],
            Par2CandidateRecovery::class => (static function () use ($random, $odd): array {
                $size = $random->getInt(0, 3) === 0 ? self::int($random) : $random->getInt(-2, 4000);
                $cursor = 0;
                $parts = [];
                for ($i = $random->getInt(0, 16); $i > 0; $i--) {
                    $choice = $random->getInt(0, 11);
                    if ($choice === 0) {
                        $parts[] = [null, 'part', 1.5][$random->getInt(0, 2)];

                        continue;
                    }
                    $begin = match (true) {
                        $choice <= 6 => $cursor + 1, $choice === 7 => $cursor + $random->getInt(2, 50), $choice === 8 => $cursor - $random->getInt(0, 50),
                        default => self::int($random),
                    };
                    $end = $random->getInt(0, 7) === 0 ? self::int($random) : $begin + $random->getInt(-1, 400);
                    if ($choice <= 6) {
                        $cursor = $end;
                    }
                    $part = ['begin' => $begin, 'end' => $end];
                    if ($random->getInt(0, 15) === 0) {
                        $part[$random->getInt(0, 1) === 0 ? 'begin' : 'end'] = $odd();
                    }
                    $parts[] = $part;
                }

                return [$parts, $size];
            })(),
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length < 1 ? '' : $random->getBytes($length);
    }

    private static function int(Randomizer $random): int
    {
        return match ($random->getInt(0, 5)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-3, 12),
            2 => $random->getInt(PHP_INT_MAX - 5, PHP_INT_MAX),
            3 => $random->getInt(PHP_INT_MIN, PHP_INT_MIN + 5),
            4 => $random->getInt(990, 1010),
            default => $random->getInt(0, 1000000),
        };
    }

    /** @return list<array{class-string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $many = static fn (string $class, int $count): array => array_map(static fn (): array => self::randomArgs($class, $random), range(1, $count));
        $names = static fn (int $n): array => array_map(static fn (int $i): string => sha1('release-'.$i).($i % 7 === 0
            ? '.nzb.gz.c'.$i.'-'.substr(md5((string) $i), 0, 16).'.next' : '.nzb.gz'), range(1, $n));
        $paths = static fn (int $n): array => array_map(static fn (int $i): array => ['source' => '/data/nzb/'.dechex($i % 16).'/'.sha1((string) $i).'.nzb.gz',
            'destination' => '/data/nzb/'.dechex($i % 16).'/'.sha1((string) $i).($i % 3 === 0 ? '.nzb.gz.c'.$i.'-0123456789abcdef.next' : '.nzb.gz')], range(1, $n));
        $files = static fn (int $n): array => array_map(static fn (int $i): string => 'Some.Release.Name.S01E'.($i % 30).'.1080p.WEB-GROUP'.($i % 11 === 0 ? '.nfo' : '.nzb'),
            range(1, $n));
        $types = static fn (int $n): array => array_map(static fn (int $i): string => ['title', 'category', 'tag', 'name', 'password'][$i % 5], range(1, $n));
        $parts = static fn (int $n, int $chunk): array => array_map(static fn (int $i): array => ['begin' => ($i - 1) * $chunk + 1, 'end' => $i * $chunk], range(1, $n));

        return [
            [NzbSourceGuard::class, [[$names(50)], [$names(10)], [$names(200)], [$names(1)]],
                [[$names(3000)], [array_fill(0, 300, str_repeat('x', 300).'.nzb.gz.c1-0123456789abcdef.next')], ...$many(NzbSourceGuard::class, 12)]],
            [NzbSourceInstallation::class, [[$paths(50)], [$paths(10)], [$paths(200)], [$paths(1)]],
                [[$paths(3000)], [array_fill(0, 300, ['source' => str_repeat('/deep', 80).'/x', 'destination' => 5])], ...$many(NzbSourceInstallation::class, 12)]],
            [NzbUploadStagingService::class, [[$files(50), 'nzb'], [$files(10), 'nzb'], [$files(200), 'nzb'], [$files(1), 'nzb']],
                [[$files(3000), 'nzb'], [array_fill(0, 300, str_repeat(' ', 200).'.nzb'), 'nzb'], ...$many(NzbUploadStagingService::class, 12)]],
            [NzbXmlElements::class, [[$types(50), 1], [$types(10), 1], [$types(200), 2], [$types(1), 1]],
                [[$types(3000), 2], [array_fill(0, 300, 'PASSWORD'), PHP_INT_MAX], ...$many(NzbXmlElements::class, 12)]],
            [Par2CandidateRecovery::class, [[$parts(50, 768000), 50 * 768000], [$parts(10, 768000), 10 * 768000], [$parts(200, 384000), 200 * 384000],
                [$parts(1, 500), 500]],
                [[$parts(3000, 768000), 3000 * 768000], [array_fill(0, 300, ['begin' => 1, 'end' => '5']), PHP_INT_MIN], ...$many(Par2CandidateRecovery::class, 12)]],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = \count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1],
            'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
