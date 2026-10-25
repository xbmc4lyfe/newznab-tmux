<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2PacketAccumulator;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Verification\Par2ProofIdentity;
use App\Services\Releases\Verification\PayloadIdentityResolver;
use App\Support\FilenameSanitizer;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustPar2PacketMetadataProofPayloadSanitizerW44ParityTest extends TestCase
{
    /** class => helper method; every class exposes the same Wave 44.2 toggle trio. */
    private const array HELPERS = [
        Par2PacketAccumulator::class => 'summarizePacketBatch',
        Par2MetadataParser::class => 'summarizeConflictKeyBatch',
        Par2ProofIdentity::class => 'summarizeProofIdentities',
        PayloadIdentityResolver::class => 'summarizeIdentityHints',
        FilenameSanitizer::class => 'summarizeFilenames',
    ];

    /** class => W442_RUST_FFI_DEFAULT; only helpers clearing every 1.5x gate default to Rust. */
    private const array DEFAULTS = [
        Par2PacketAccumulator::class => false,
        Par2MetadataParser::class => false,
        Par2ProofIdentity::class => false,
        PayloadIdentityResolver::class => false,
        FilenameSanitizer::class => true,
    ];

    private const array FILENAMES = ['Movie.2020.1080p.BluRay.x264-GRP.mkv', 'Show.S01E02.720p.HDTV.part01.rar', 'Album - 01 - Track.flac', 'release.par2',
        'release.vol00+01.par2', 'a/b\\c:d*e?f"g<h>i|j', '  spaced  name  .nfo ', '...hidden', '__a__', '-dash-', 'comma,separated,name', "tab\tname",
        "ctrl\x01\x1F\x7Fname", 'sample.mkv', 'x', '.', '..', '. .', '_-.', "\0\x0B", 'Ünïcödé.mkv', "bad\xFF\xFEutf8", 'ｆｕｌｌｗｉｄｔｈ', 'a⁄b'];

    private const array HINT_NAMES = ['Movie.2020.1080p.mkv', 'Movie.2020.sample.mkv', 'sample.mkv', 'Show-SUBS.rar', 'Show.Subtitles.idx', 'subsample.mkv',
        'thumbs.db', 'thumb', 'Proof.jpg', 'proofs.jpg', 'x_sub_y', "a\fsample", "a\vproof", "a\x85sample", "a\xA0sample", "sample\n", 'SAMPLE', '', 'name with space'];

    private const int SEED = 44022026;

    private const int RANDOMIZED = 1300;

    protected function tearDown(): void
    {
        foreach (array_keys(self::HELPERS) as $class) {
            $class::resetW442RustFfi();
        }
        parent::tearDown();
    }

    public function test_defaults_and_toggles_are_independent(): void
    {
        foreach (self::DEFAULTS as $class => $default) {
            $this->assertSame($default, \constant($class.'::W442_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW442RustFfi(), $class);
            $reflection = new ReflectionClass($class);
            foreach (['useW442RustFfi', 'usesW442RustFfi', 'resetW442RustFfi', self::HELPERS[$class], self::HELPERS[$class].'Php', self::HELPERS[$class].'Native'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
        }
        foreach (array_keys(self::HELPERS) as $selected) {
            foreach (array_keys(self::HELPERS) as $class) {
                $class::useW442RustFfi(false);
            }
            $selected::useW442RustFfi();
            foreach (array_keys(self::HELPERS) as $class) {
                $this->assertSame($selected === $class, $class::usesW442RustFfi(), $selected.' → '.$class);
            }
            $selected::useW442RustFfi(null);
            $this->assertSame(self::DEFAULTS[$selected], $selected::usesW442RustFfi());
            $selected::useW442RustFfi(true);
            $this->assertTrue($selected::usesW442RustFfi());
            foreach (array_keys(self::HELPERS) as $class) {
                $class::resetW442RustFfi();
                $this->assertSame(self::DEFAULTS[$class], $class::usesW442RustFfi(), $class);
            }
        }
    }

    public function test_php_helpers_answer_known_vectors(): void
    {
        $main = self::raw(Par2Info::PACKET_MAIN, "\1", pack('PV', 4, 1).str_repeat("\0", 16));
        $overcount = self::raw(Par2Info::PACKET_MAIN, "\1", pack('PV', 4, 2).str_repeat("\0", 16));
        $badMarker = substr_replace(self::raw(Par2Info::PACKET_FILEDESC, "\1", str_repeat("\0", 56)), '3', 3, 1);
        $oddLength = substr_replace(self::raw(Par2Info::PACKET_FILEVER, "\1", str_repeat("\0", 36)), "\x63", 8, 1);
        $high = substr_replace(self::raw(Par2Info::PACKET_FILEVER, "\1", str_repeat("\0", 36)), "\x80", 15, 1);
        $packets = [$main, $overcount, self::raw(Par2Info::PACKET_FILEDESC, "\2", str_repeat("\0", 56)), self::raw(Par2Info::PACKET_FILEDESC, "\2", str_repeat("\0", 52)),
            self::raw(Par2Info::PACKET_FILEVER, "\2", str_repeat("\0", 36)), self::raw(Par2Info::PACKET_FILEVER, "\2", str_repeat("\0", 32)),
            self::raw(Par2Info::PACKET_RECOVERY, "\3", str_repeat("\0", 4)), self::raw(Par2Info::PACKET_PACKED_RECOVERY, "\3", ''),
            self::raw(Par2Info::PACKET_CREATOR, "\4", str_repeat("\0", 8)), self::raw("PAR 2.0\0Unknown!", "\5", ''), $badMarker, $oddLength, $high, str_repeat("\0", 63), null];
        $this->assertSame(['packets' => 15, 'invalid' => 1, 'truncated' => 1, 'bad_marker' => 1, 'bad_length' => 2, 'main' => 1, 'filedesc' => 1, 'filever' => 1,
            'recovery' => 2, 'auxiliary' => 1, 'unknown' => 1, 'malformed' => 3, 'bytes' => array_sum(array_map(strlen(...), array_filter($packets, is_string(...)))), 'sets' => 4],
            Par2PacketAccumulator::summarizePacketBatchPhp($packets));

        $set = '0123456789abcdef0123456789ABCDEF';
        $lower = strtolower($set);
        $this->assertSame(['rows' => 8, 'invalid' => 1, 'bad_set' => 2, 'bad_file' => 2, 'wildcard' => 1, 'file' => 2, 'distinct' => 2],
            Par2MetadataParser::summarizeConflictKeyBatchPhp([['recovery_set_id' => $set, 'file_id' => $lower], ['recovery_set_id' => $lower, 'file_id' => $set],
                ['recovery_set_id' => $lower], ['recovery_set_id' => $set, 'file_id' => 'abc'], ['recovery_set_id' => $set, 'file_id' => 5],
                ['recovery_set_id' => substr($lower, 1), 'file_id' => null], ['file_id' => $lower], 'row']));
        $this->assertSame(['rows' => 0, 'invalid' => 0, 'bad_set' => 0, 'bad_file' => 0, 'wildcard' => 0, 'file' => 0, 'distinct' => 0],
            Par2MetadataParser::summarizeConflictKeyBatchPhp([]));

        $this->assertSame(['identities' => 7, 'absent' => 1, 'invalid' => 3, 'proofs' => 3, 'distinct' => 2, 'largest_group' => 2],
            Par2ProofIdentity::summarizeProofIdentitiesPhp([str_repeat('a', 64), str_repeat('0', 64), str_repeat('a', 64), str_repeat('A', 64), null, 7, 'abc']));

        $this->assertSame(['manifests' => 5, 'invalid' => 1, 'oversized' => 1, 'named' => 2, 'unnamed' => 1, 'hints' => 5, 'invalid_hints' => 2, 'yenc' => 2,
            'par2' => 1, 'noise' => 1, 'candidates' => 2, 'candidate_bytes' => 10],
            PayloadIdentityResolver::summarizeIdentityHintsPhp([
                ['identity_hints' => [['source' => 'yenc', 'name' => 'Movie.2020'], ['source' => 'par2', 'name' => 'a.sample.'], ['source' => 'nzb', 'name' => 'x']]],
                ['identity_hints' => [['source' => 'yenc', 'name' => 5]]], 'manifest', ['identity_hints' => array_fill(0, 4097, [])],
                ['identity_hints' => [['source' => 'yenc', 'name' => '']]],
            ]));

        $this->assertSame(['names' => 11, 'invalid' => 1, 'empty' => 2, 'non_ascii' => 1, 'fallback' => 2, 'truncated' => 1, 'clean' => 1, 'changed' => 3,
            'bytes' => 46 + 201],
            FilenameSanitizer::summarizeFilenamesPhp(['Movie.2020.mkv', ' a/b:c  d.mkv ', '...', " \t", 'café', str_repeat('x', 201), "\x01\x02", 'a__b', '_a', null, 5]));
    }

    public function test_batch_helpers_agree_with_single_value_helpers(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 3));
        for ($i = 0; $i < 40; $i++) {
            $packets = self::indexPackets($random, $random->getInt(1, 6));
            $summary = Par2PacketAccumulator::summarizePacketBatchPhp($packets);
            $accumulator = new Par2PacketAccumulator(PHP_INT_MAX);
            $accumulator->append(implode('', $packets), useNative: false);
            $this->assertSame(\count($accumulator->descriptors()), $summary['filedesc']);
            $this->assertSame(\count($accumulator->parsersBySet()), $summary['sets']);
            $this->assertSame(\count($packets), $summary['main'] + $summary['filedesc'] + $summary['filever'] + $summary['auxiliary']);
        }
        for ($i = 0; $i < 300; $i++) {
            $conflicts = self::randomArgs(Par2MetadataParser::class, $random)[0];
            $this->assertSame(\count(Par2MetadataParser::sourceDefinitionConflictKeys(['source_definition_conflicts' => $conflicts])),
                Par2MetadataParser::summarizeConflictKeyBatchPhp($conflicts)['distinct']);

            $name = self::FILENAMES[$random->getInt(0, \count(self::FILENAMES) - 1)].($random->getInt(0, 3) === 0 ? str_repeat('y', $random->getInt(150, 250)) : '');
            $summary = FilenameSanitizer::summarizeFilenamesPhp([$name]);
            $sanitized = FilenameSanitizer::sanitizePhp($name, 'zz-fallback');
            if ($summary['non_ascii'] === 0) {
                $this->assertSame($sanitized === $name ? 1 : 0, $summary['clean'], $name);
                $this->assertSame($sanitized === 'zz-fallback' ? 1 : 0, $summary['empty'] + $summary['fallback'], $name);
                if ($summary['truncated'] === 1) {
                    $this->assertLessThanOrEqual(200, mb_strlen($sanitized), $name);
                    $this->assertNotSame($name, $sanitized);
                }
            }
        }
        $packets = [
            ['head_set_id' => 's', 'head_type' => Par2Info::PACKET_MAIN, 'block_size' => 4, 'rec_file_count' => 1, 'rec_file_ids' => [[0, 'f1']], 'head_hash' => 'm'],
            ['head_set_id' => 's', 'head_type' => Par2Info::PACKET_FILEDESC, 'file_id' => 'f1', 'file_name' => 'a.bin', 'file_length' => 4, 'file_hash' => 'h',
                'file_hash_16K' => 'k', 'head_hash' => 'd'],
            ['head_set_id' => 's', 'head_type' => Par2Info::PACKET_FILEVER, 'file_id' => 'f1', 'block_checksums' => [], 'head_hash' => 'v'],
        ];
        $proof = Par2ProofIdentity::fromPackets($packets);
        $this->assertIsString($proof);
        $mixed = Par2ProofIdentity::fromPackets([...$packets, ['head_set_id' => 't', 'head_type' => Par2Info::PACKET_CREATOR]]);
        $this->assertNull($mixed);
        $this->assertSame(['identities' => 3, 'absent' => 1, 'invalid' => 0, 'proofs' => 2, 'distinct' => 1, 'largest_group' => 2],
            Par2ProofIdentity::summarizeProofIdentitiesPhp([$proof, $mixed, Par2ProofIdentity::fromPackets(array_reverse($packets))]));
    }

    public function test_native_matches_php_on_edge_cases_and_randomized_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $class => $method) {
            $cases = [...self::edgeCases($class), ...array_map(static fn (): array => self::randomArgs($class, $random), range(1, self::RANDOMIZED))];
            $this->assertGreaterThanOrEqual(self::RANDOMIZED, \count($cases));
            foreach ($cases as $args) {
                $expected = $class::{$method.'Php'}(...$args);
                $native = $class::{$method.'Native'}(...$args);
                $class::useW442RustFfi(true);
                $viaRust = $class::$method(...$args);
                $class::useW442RustFfi(false);
                $viaPhp = $class::$method(...$args);
                if ($native !== $expected || $viaRust !== $expected || $viaPhp !== $expected) {
                    $this->fail($class.'::'.$method.' diverged for '.self::describe($args).': '.json_encode([$expected, $native, $viaRust, $viaPhp]));
                }
                $this->assertSame($expected, $native);
            }
        }
    }

    public function test_benchmark_qualified_default_dispatches_without_overrides(): void
    {
        $this->nativeFfi();
        foreach (self::HELPERS as $class => $method) {
            $class::resetW442RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW442RustFfi(), $class);
            foreach (self::edgeCases($class) as $args) {
                $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
                $this->assertNotNull($class::{$method.'Native'}(...$args), $class);
            }
        }
    }

    public function test_out_of_contract_inputs_fall_back_to_php(): void
    {
        $this->nativeFfi();
        $valid = [2, 1, [-1, 1], '', 6];
        foreach ([[5, 0, [], '', 1], [-1, ...\array_slice($valid, 1)], [4294967296, ...\array_slice($valid, 1)], [2, -1, [], '', 6], [2, 2, [-1, 1], '', 6],
            [2, 1, [-1, 1], 'x', 6], [2, 1, [-2, 1], '', 6], [0, 1, [3], 'ab', 14], [0, 1, [-2], '', 14], [0, 1, [-1], 'a', 14], [0, 1, [100], str_repeat('a', 77), 14], [1, 1, [1, -1, 1], 'a', 7], [1, 1, [-1, 1, 2], 'a', 7],
            [1, 1, [-1, -1, 3], '', 7], [2, 1, [1, 1], 'a', 6], [2, 1, [-1, 0], '', 6], [3, 1, [1, 1], 'a', 12], [3, 2, [-1, 11, 1, 1], 'a', 12], [3, 1, [-1, 0], '', 12],
            [3, 1, [1, 10], 'a', 12], [4, 1, [1, 1], 'a', 9], [4, 1, [-1, 0], '', 9], [2, 0, [], str_repeat("\0", 16777201), 6]] as $vector) {
            $this->assertNull(Par2PacketAccumulator::evaluateW442Native(...$vector), json_encode(\array_slice($vector, 0, 3)) ?: '');
        }
        $this->assertNull(Par2PacketAccumulator::evaluateW442Native(2, 1, [-1, 1], '', 0));
        // A wrong expected slot count is a contract violation, not a partial answer.
        $this->assertNull(Par2PacketAccumulator::evaluateW442Native(2, 1, [-1, 1], '', 5));
        $this->assertNull(Par2PacketAccumulator::evaluateW442Native(2, 1, [-1, 1], '', 7));
        $this->assertSame([1, 1, 0, 0, 0, 0], Par2PacketAccumulator::evaluateW442Native(...$valid));
        $huge = str_repeat('n', 16777200);
        $oversized = [
            Par2PacketAccumulator::class => [array_fill(0, 2000000, 'x')],
            Par2MetadataParser::class => [[['recovery_set_id' => $huge]]],
            Par2ProofIdentity::class => [[$huge]],
            PayloadIdentityResolver::class => [[['identity_hints' => [['source' => 'par2', 'name' => $huge]]]]],
            FilenameSanitizer::class => [[$huge]],
        ];
        foreach ($oversized as $class => $args) {
            $method = self::HELPERS[$class];
            $this->assertNull($class::{$method.'Native'}(...$args), $class);
            $class::useW442RustFfi(true);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
        }
    }

    public function test_direct_ffi_export_preserves_sentinels_and_capacity_contract(): void
    {
        $ffi = $this->nativeFfi();
        $packet = self::raw(Par2Info::PACKET_CREATOR, "\1", 'abcd');
        $vectors = [[0, pack('q3', 0, 1, \strlen($packet)).$packet, [1, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 68, 1]],
            [1, pack('q5', 0, 1, 32, -1, 0).str_repeat('a', 32), [1, 0, 0, 0, 1, 0, 1]],
            [2, pack('q4', 0, 1, -1, 1), [1, 1, 0, 0, 0, 0]],
            [3, pack('q6', 0, 2, -1, 10, 3, 2).'a.b', [1, 0, 0, 1, 0, 1, 0, 0, 1, 0, 1, 3]],
            [4, pack('q4', 0, 1, 3, 0).'a b', [1, 0, 0, 0, 0, 0, 0, 1, 3]]];
        foreach ($vectors as [$op, $input, $expected]) {
            $length = \count($expected) * 8;
            $wire = pack('q*', ...$expected);
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w442_par2_packet_metadata_proof_payload_sanitizer($op, 0, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2), $op.' capacity '.$capacity);
                if ($written < 0) {
                    // Retry with exactly the reported capacity.
                    $retry = $ffi->new('char['.(-$written).']');
                    // @phpstan-ignore method.notFound
                    $this->assertSame(-$written, $ffi->nntmux_w442_par2_packet_metadata_proof_payload_sanitizer($op, 0, $input, \strlen($input), $retry, -$written));
                    $this->assertSame($wire, FFI::string($retry, -$written));
                }
            }
            // Every flag bit is rejected for every operation.
            foreach ([1, 64, 4294967295] as $flags) {
                // @phpstan-ignore method.notFound
                $this->assertSame(-1, $ffi->nntmux_w442_par2_packet_metadata_proof_payload_sanitizer($op, $flags, $input, \strlen($input), $small, $length + 2));
            }
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q4', 0, 1, -1, 1);
        foreach ([[null, 0, $buffer, 1024], [null, 32, $buffer, 1024], [$input, 32, null, 0], [$input, 32, null, 48], [$input, 16777217, $buffer, 1024],
            [$buffer, 32, $buffer, 1024], [FFI::addr($buffer[8]), 32, $buffer, 1024], [$buffer, 32, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 32);
            FFI::memset(FFI::addr($buffer[32]), 0x5A, 992);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w442_par2_packet_metadata_proof_payload_sanitizer(2, 0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 32).str_repeat('Z', 992), FFI::string($buffer, 1024));
        }
        foreach ([5, 99, 4294967295] as $op) {
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w442_par2_packet_metadata_proof_payload_sanitizer($op, 0, $input, 32, $buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $helpers = unserialize((string) stream_get_contents(STDIN));
            if (App\Services\Par2PacketAccumulator::evaluateW442Native(2, 1, [-1, 1], '', 6) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($helpers as [$class, $method, $cases]) {
                foreach ($cases as $args) {
                    if ($class::{$method.'Native'}(...$args) !== null) {
                        throw new RuntimeException($class.'::'.$method.' answered without a library');
                    }
                    $class::useW442RustFfi(false);
                    $expected = serialize($class::$method(...$args));
                    $class::useW442RustFfi(true);
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
        $missing = '/tmp/w44-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-d', 'memory_limit=1G', '-r', $code, base_path()], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing], serialize($helpers));
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    /**
     * Gate: for every helper, the per-round minimum of common mean, common p99, uncommon mean and uncommon p99
     * speedup (PHP latency / Rust latency, including packing, FFI and unpacking) must be >= 1.5x before its
     * W442_RUST_FFI_DEFAULT may be true. Per-round ranges over 6 runs of 3 rounds (PHP 8.5.12 NTS, opcache CLI off,
     * Apple M5), common mean/p99 then uncommon mean/p99: packet batch 1.55-1.90x/1.31-3.06x, 2.48-3.31x/2.79-3.86x;
     * conflict keys 0.65-0.77x/0.69-1.38x, 0.96-1.20x/0.95-1.32x; proof identities 0.46-0.53x/0.32-0.86x,
     * 0.73-0.87x/0.71-0.97x; identity hints 0.83-0.94x/0.70-1.78x, 0.93-1.05x/0.86-1.18x; filenames
     * 6.07-6.87x/6.14-14.40x, 10.88-11.91x/9.64-14.50x. Only FilenameSanitizer clears every gate, so it alone defaults
     * to Rust: its PHP path runs several regex passes per name, while elsewhere per-row packing costs as much as the PHP
     * work it replaces (packet batch misses common p99 in three of six runs).
     */
    public function test_paired_randomized_benchmarks_gate_rust_defaults(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave44-2-par2-packet-metadata-proof-payload-sanitizer-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including normalization, packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && php artisan test --compact tests/Unit/RustPar2PacketMetadataProofPayloadSanitizerW44ParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'cargo --release (rust/pipeline-ffi/Cargo.toml profile)', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/Par2PacketAccumulator.php',
            'app/Services/Releases/Fingerprint/Par2MetadataParser.php', 'app/Services/Releases/Verification/Par2ProofIdentity.php',
            'app/Services/Releases/Verification/PayloadIdentityResolver.php', 'app/Support/FilenameSanitizer.php',
            'tests/Unit/RustPar2PacketMetadataProofPayloadSanitizerW44ParityTest.php'] as $file) {
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
                            $class::useW442RustFfi($enabled);
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
                                $class::useW442RustFfi($side === 'native');
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
                $class::resetW442RustFfi();
                $this->assertSame(self::DEFAULTS[$class], \constant($class.'::W442_RUST_FFI_DEFAULT'), $name);
                $this->assertSame(self::DEFAULTS[$class], $class::usesW442RustFfi(), $name);
                if (! $qualifies) {
                    $this->assertFalse(\constant($class.'::W442_RUST_FFI_DEFAULT'), $name.' must keep PHP as the default below 1.5x');
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
                if (! isset($cells['common']['round_range'], $cells['uncommon']['round_range'])) {
                    continue;
                }
                $summary[] = sprintf('%s common mean %.2f-%.2fx p99 %.2f-%.2fx; uncommon mean %.2f-%.2fx p99 %.2f-%.2fx', $helper,
                    $cells['common']['round_range']['mean_speedup']['min'], $cells['common']['round_range']['mean_speedup']['max'],
                    $cells['common']['round_range']['p99_speedup']['min'], $cells['common']['round_range']['p99_speedup']['max'],
                    $cells['uncommon']['round_range']['mean_speedup']['min'], $cells['uncommon']['round_range']['mean_speedup']['max'],
                    $cells['uncommon']['round_range']['p99_speedup']['min'], $cells['uncommon']['round_range']['p99_speedup']['max']);
            }
            fwrite(STDERR, "\nWave 44.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(Par2PacketAccumulator::W442_FFI_DOMAIN, Par2PacketAccumulator::W442_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** A packet whose declared length matches its bytes; the header hash is a placeholder. */
    private static function raw(string $type, string $setByte, string $body): string
    {
        return Par2Info::PACKET_MARKER.pack('P', 64 + \strlen($body)).str_repeat("\x11", 16).str_repeat($setByte, 16).$type.$body;
    }

    /** A packet with a valid header MD5 over its set, type and body. */
    private static function signed(string $type, string $set, string $body): string
    {
        $hashed = $set.$type.$body;

        return Par2Info::PACKET_MARKER.pack('P', 64 + \strlen($body)).md5($hashed, true).$hashed;
    }

    /** @return list<string> a checksummed index: Main, Creator and FileDesc/IFSC per file */
    private static function indexPackets(Randomizer $random, int $files, ?string $set = null): array
    {
        $set ??= $random->getBytes(16);
        $ids = [];
        $packets = [];
        for ($i = 0; $i < $files; $i++) {
            $id = $random->getBytes(16);
            $ids[] = $id;
            $name = 'Release.Name.2020.part'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.rar';
            $name .= str_repeat("\0", (4 - \strlen($name) % 4) % 4);
            $packets[] = self::signed(Par2Info::PACKET_FILEDESC, $set, $id.$random->getBytes(32).pack('P', $random->getInt(1, 1 << 40)).$name);
            $packets[] = self::signed(Par2Info::PACKET_FILEVER, $set, $id.$random->getBytes(20 * $random->getInt(1, 4)));
        }
        sort($ids, SORT_STRING);
        array_unshift($packets, self::signed(Par2Info::PACKET_MAIN, $set, pack('PV', 768000, $files).implode('', $ids)));
        $packets[] = self::signed(Par2Info::PACKET_CREATOR, $set, 'Created by ParPar 0.4.5'."\0");

        return $packets;
    }

    /** @param list<mixed> $args */
    private static function describe(array $args): string
    {
        return substr(bin2hex(serialize($args)), 0, 400);
    }

    /** @return list<list<mixed>> */
    private static function edgeCases(string $class): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 11));
        $odd = [null, 5, 1.5, true, 'x', [], new stdClass];
        $hex = bin2hex($random->getBytes(16));
        $sha = hash('sha256', 'proof');
        $hint = static fn (mixed $source, mixed $name): array => ['source' => $source, 'name' => $name];

        return match ($class) {
            Par2PacketAccumulator::class => [[[]], [$odd], [self::indexPackets($random, 3)], [[...self::indexPackets($random, 2), ...self::indexPackets($random, 1)]],
                [[self::raw(Par2Info::PACKET_RECOVERY, "\1", str_repeat("\xAB", 4096)), self::raw(Par2Info::PACKET_INPUT_BLOCK, "\1", ''), self::raw(Par2Info::PACKET_PACKED_RECOVERY, "\2", 'abcd'),
                    self::raw(Par2Info::PACKET_FILENAME_UC, "\1", 'abcd'), self::raw(Par2Info::PACKET_COMMENT_ASCII, "\1", ''), self::raw(Par2Info::PACKET_COMMENT_UC, "\1", ''),
                    self::raw(Par2Info::PACKET_RECOVERY_VER, "\1", ''), self::raw(Par2Info::PACKET_PACKED_MAIN, "\1", ''), self::raw("PAR 2.0\0main\0\0\0\0", "\1", '')]],
                [[self::raw(Par2Info::PACKET_MAIN, "\1", ''), self::raw(Par2Info::PACKET_MAIN, "\1", pack('PV', 1, 0)), self::raw(Par2Info::PACKET_MAIN, "\1", pack('PV', 1, 0).'abcd'),
                    self::raw(Par2Info::PACKET_MAIN, "\1", pack('PV', 1, 4294967295).str_repeat("\0", 16)), self::raw(Par2Info::PACKET_FILEDESC, "\1", str_repeat("\0", 60)),
                    self::raw(Par2Info::PACKET_FILEVER, "\1", str_repeat("\0", 16)), self::raw(Par2Info::PACKET_FILEVER, "\1", str_repeat("\0", 24)),
                    substr(self::raw(Par2Info::PACKET_CREATOR, "\1", 'abcd'), 0, 66), self::raw(Par2Info::PACKET_CREATOR, "\1", 'abcd').'xy', '', 'PAR2', str_repeat("\0", 64),
                    'PAR2'."\0".'PKT'.pack('VV', 64, 0x80000000).str_repeat("\0", 48), 'PAR2'."\0".'PKT'.pack('VV', 66, 0).str_repeat("\0", 50)]],
                [['a' => self::raw(Par2Info::PACKET_CREATOR, "\1", ''), 'b' => self::raw(Par2Info::PACKET_CREATOR, "\2", ''), 'c' => 5]]],
            Par2MetadataParser::class => [[[]], [$odd], [[['recovery_set_id' => $hex, 'file_id' => $hex], ['recovery_set_id' => strtoupper($hex), 'file_id' => strtoupper($hex)],
                ['recovery_set_id' => $hex], ['recovery_set_id' => $hex, 'file_id' => null], ['recovery_set_id' => $hex, 'file_id' => ''], ['recovery_set_id' => $hex.'0'],
                ['recovery_set_id' => substr($hex, 1).'g'], ['recovery_set_id' => $hex."\n"], ['recovery_set_id' => 5], ['recovery_set_id' => $hex, 'file_id' => 5],
                ['recovery_set_id' => $hex, 'file_id' => false], ['recovery_set_id' => $hex, 'file_id' => []], ['recovery_set_id' => null], [], ['file_id' => $hex], 'x', null]],
                [['k' => ['recovery_set_id' => $hex, 'file_id' => $hex], 'l' => ['recovery_set_id' => $hex, 'file_id' => strtoupper($hex)]]]],
            Par2ProofIdentity::class => [[[]], [$odd], [[$sha, $sha, strtoupper($sha), substr($sha, 1), $sha.'0', $sha."\n", '', null, null, 0, hash('sha256', 'other'), $sha]],
                [['a' => $sha, 'b' => null]], [array_fill(0, 50, $sha)]],
            PayloadIdentityResolver::class => [[[]], [$odd], [[['identity_hints' => array_map(static fn (string $name): array => $hint('yenc', $name), self::HINT_NAMES)],
                ['identity_hints' => array_map(static fn (string $name): array => $hint('par2', $name), self::HINT_NAMES)],
                ['identity_hints' => [$hint('nzb', 'x'), $hint('YENC', 'x'), $hint('par2', 5), $hint(null, null), 'hint', 7, ['source' => 'yenc'], ['name' => 'x']]],
                ['identity_hints' => 'none'], ['identity_hints' => null], [], ['other' => 1], ['identity_hints' => array_fill(0, 4096, $hint('yenc', 'a'))],
                ['identity_hints' => array_fill(0, 4097, $hint('yenc', 'a'))], 'manifest', null]],
                [['x' => ['identity_hints' => ['a' => $hint('par2', 'Movie.2020.mkv')]]]]],
            FilenameSanitizer::class => [[[]], [$odd], [self::FILENAMES], [[str_repeat('a', 200), str_repeat('a', 201), str_repeat('a', 199).'.b', str_repeat('a', 199).' b',
                '.'.str_repeat('a', 210), str_repeat(' a', 150), str_repeat('_', 300), "\x7F", '/', ' / ', 'a/', '\\\\server\\share']], [['k' => 'x', 'l' => null]]],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<mixed> */
    private static function randomArgs(string $class, Randomizer $random): array
    {
        $rows = static function (callable $row, int $max = 16) use ($random): array {
            $out = [];
            for ($i = $random->getInt(0, $max); $i > 0; $i--) {
                $out[] = $random->getInt(0, 11) === 0 ? [null, 7, 1.5, true, [], 'x'][$random->getInt(0, 5)] : $row();
            }

            return $out;
        };
        $odd = static fn (): mixed => ['5', 5.0, null, true, [], 7][$random->getInt(0, 5)];
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, \count($values) - 1)];
        $types = [Par2Info::PACKET_MAIN, Par2Info::PACKET_FILEDESC, Par2Info::PACKET_FILEVER, Par2Info::PACKET_RECOVERY, Par2Info::PACKET_CREATOR, Par2Info::PACKET_FILENAME_UC,
            Par2Info::PACKET_COMMENT_ASCII, Par2Info::PACKET_COMMENT_UC, Par2Info::PACKET_INPUT_BLOCK, Par2Info::PACKET_RECOVERY_VER, Par2Info::PACKET_PACKED_MAIN,
            Par2Info::PACKET_PACKED_RECOVERY, "PAR 2.0\0Bogus\0\0\0"];
        $hex = static fn (): string => bin2hex($random->getBytes(16));

        return match ($class) {
            Par2PacketAccumulator::class => [$rows(static function () use ($random, $pick, $types): mixed {
                $packet = self::raw($pick($types), $pick(["\1", "\2", "\3"]), self::bytes($random, 4 * $random->getInt(0, 24)));
                if ($random->getInt(0, 2) === 0) {
                    $packet = substr_replace($packet, pack('PV', $random->getInt(1, 9), $random->getInt(0, 3)), 64, min(12, \strlen($packet) - 64));
                }

                return match ($random->getInt(0, 9)) {
                    0 => substr($packet, 0, $random->getInt(0, \strlen($packet))),
                    1 => substr_replace($packet, \chr($random->getInt(0, 255)), $random->getInt(0, 15), 1),
                    2 => $packet.$random->getBytes($random->getInt(1, 8)),
                    3 => self::bytes($random, $random->getInt(0, 100)),
                    default => $packet,
                };
            })],
            Par2MetadataParser::class => [$rows(static function () use ($random, $odd, $pick, $hex): mixed {
                $set = match ($random->getInt(0, 7)) {
                    0 => $odd(), 1 => strtoupper($hex()), 2 => substr($hex(), 0, $random->getInt(0, 33)), 3 => $pick(['0123456789abcdef0123456789abcdef', '0123456789ABCDEF0123456789abcdef']),
                    default => $pick(['00112233445566778899aabbccddeeff', 'ffeeddccbbaa99887766554433221100', $hex()]),
                };
                $file = match ($random->getInt(0, 6)) {
                    0 => $odd(), 1 => null, 2 => substr($hex(), 1).$pick(['g', 'G', 'z', "\0"]), 3 => strtoupper($pick(['00112233445566778899aabbccddeeff', $hex()])),
                    default => $pick(['00112233445566778899aabbccddeeff', '00112233445566778899AABBCCDDEEFF', $hex()]),
                };
                $row = ['recovery_set_id' => $set, 'file_id' => $file];
                if ($random->getInt(0, 9) === 0) {
                    unset($row[$pick(['recovery_set_id', 'file_id'])]);
                }

                return $row;
            }, 24)],
            Par2ProofIdentity::class => [$rows(static fn (): mixed => match ($random->getInt(0, 9)) {
                0 => $odd(), 1 => null, 2 => strtoupper(hash('sha256', (string) $random->getInt(0, 5))), 3 => substr(hash('sha256', (string) $random->getInt(0, 5)), 0, $random->getInt(0, 65)),
                4 => self::bytes($random, $random->getInt(0, 70)),
                default => hash('sha256', (string) $random->getInt(0, 6)),
            }, 24)],
            PayloadIdentityResolver::class => [$rows(static function () use ($random, $odd, $pick): mixed {
                $hints = [];
                for ($i = $random->getInt(0, 12); $i > 0; $i--) {
                    $name = match ($random->getInt(0, 5)) {
                        0 => $odd(), 1 => self::bytes($random, $random->getInt(0, 16)),
                        2 => $pick(['', '.', '-', '_', ' ', "\t", "\x85", "\xA0"]).$pick(['sample', 'SUBS', 'Proof', 'thumb', 'subtitles', 'subsample']).$pick(['', '.', 's', "\n", "\f", 'x']),
                        default => $pick(self::HINT_NAMES),
                    };
                    $hint = ['source' => $random->getInt(0, 7) === 0 ? $pick(['nzb', 'Yenc', null, 1]) : $pick(['yenc', 'par2']), 'name' => $name];
                    if ($random->getInt(0, 11) === 0) {
                        unset($hint[$pick(['source', 'name'])]);
                    }
                    $hints[] = $random->getInt(0, 15) === 0 ? $odd() : $hint;
                }

                return match ($random->getInt(0, 11)) {
                    0 => ['identity_hints' => $odd()], 1 => ['release' => 'x'],
                    default => ['identity_hints' => $hints],
                };
            }, 8)],
            FilenameSanitizer::class => [$rows(static fn (): mixed => match ($random->getInt(0, 9)) {
                0 => $odd(), 1 => self::bytes($random, $random->getInt(0, 24)),
                2 => implode('', array_map(static fn (): string => $pick([' ', ',', '/', ':', '_', '.', '-', "\t", "\x01", 'a', 'B', '7', '?', '|']), range(0, $random->getInt(0, 12)))),
                3 => str_repeat($pick(['a', '_a', 'ab ', '.']), $random->getInt(60, 120)),
                default => $pick(self::FILENAMES),
            }, 24)],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length < 1 ? '' : $random->getBytes($length);
    }

    /** @return list<array{class-string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $many = static fn (string $class, int $count): array => array_map(static fn (): array => self::randomArgs($class, $random), range(1, $count));
        $index = static fn (int $files): array => self::indexPackets($random, $files);
        $hex = static fn (int $i): string => md5('set-'.$i);
        $conflicts = static fn (int $n): array => array_map(static fn (int $i): array => ['recovery_set_id' => $hex($i % 3), 'file_id' => $i % 4 === 0 ? null : $hex(100 + $i % 9),
            'reason' => 'conflicting-file-description'], range(1, $n));
        $proofs = static fn (int $n): array => array_map(static fn (int $i): ?string => $i % 7 === 0 ? null : hash('sha256', 'proof-'.($i % 5)), range(1, $n));
        $manifest = static fn (int $hints): array => ['identity_hints' => array_map(static fn (int $i): array => ['source' => $i % 3 === 0 ? 'par2' : 'yenc',
            'name' => $i % 10 === 0 ? 'Release.Name.2021.1080p.WEB-DL.sample.mkv' : 'Release.Name.2021.1080p.WEB-DL.H264-GRP.part'.$i.'.rar'], range(1, $hints))];
        $manifests = static fn (int $n, int $hints): array => array_map(static fn (): array => $manifest($hints), range(1, $n));
        $names = static fn (int $n): array => array_map(static fn (int $i): string => match ($i % 4) {
            0 => 'Release.Name.2021.1080p.WEB-DL.H264-GRP.part'.$i.'.rar', 1 => 'Release Name (2021) [1080p] - Part '.$i.'.mkv',
            2 => 'release_name_2021_'.$i.'.nzb', default => 'Some/Bad:Name*'.$i.'?.nfo',
        }, range(1, $n));
        $recovery = static fn (int $n, int $bytes): array => array_map(static fn (int $i): string => self::raw(Par2Info::PACKET_RECOVERY, "\1", pack('V', $i).str_repeat("\xAB", $bytes)), range(1, $n));

        return [
            [Par2PacketAccumulator::class, [[$index(8)], [$index(2)], [$index(20)], [$index(1)]],
                [[[...$index(500), ...$index(400)]], [$recovery(40, 65536)], ...$many(Par2PacketAccumulator::class, 12)]],
            [Par2MetadataParser::class, [[$conflicts(8)], [$conflicts(2)], [$conflicts(24)], [$conflicts(1)]],
                [[$conflicts(3000)], [array_fill(0, 500, ['recovery_set_id' => strtoupper($hex(1)), 'file_id' => strtoupper($hex(2))])], ...$many(Par2MetadataParser::class, 12)]],
            [Par2ProofIdentity::class, [[$proofs(8)], [$proofs(2)], [$proofs(24)], [$proofs(1)]],
                [[$proofs(3000)], [array_fill(0, 500, str_repeat('f', 64))], ...$many(Par2ProofIdentity::class, 12)]],
            [PayloadIdentityResolver::class, [[$manifests(4, 12)], [$manifests(1, 4)], [$manifests(10, 30)], [$manifests(1, 1)]],
                [[$manifests(50, 400)], [[['identity_hints' => array_fill(0, 2000, ['source' => 'par2', 'name' => str_repeat('a.', 200).'sample'])]]], ...$many(PayloadIdentityResolver::class, 12)]],
            [FilenameSanitizer::class, [[$names(12)], [$names(4)], [$names(40)], [$names(1)]],
                [[$names(3000)], [array_fill(0, 300, str_repeat('Long Name, ', 40))], ...$many(FilenameSanitizer::class, 12)]],
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
