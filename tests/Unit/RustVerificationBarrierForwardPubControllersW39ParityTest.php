<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ForwardVerifiedPublications;
use App\Console\Commands\ReleaseVerificationBarrier;
use App\Http\Controllers\ReleaseVerificationPublicationBatchController;
use App\Http\Controllers\ReleaseVerificationPublicationController;
use App\Services\Categorization\Pipes\MiscPipe;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustVerificationBarrierForwardPubControllersW39ParityTest extends TestCase
{
    /** Every Wave 39.3 target shares the W393 toggle names and keeps its existing toggle separate. */
    private const TARGETS = [
        ForwardVerifiedPublications::class,
        ReleaseVerificationBarrier::class,
        ReleaseVerificationPublicationBatchController::class,
        ReleaseVerificationPublicationController::class,
        MiscPipe::class,
    ];

    /** Production defaults: only MiscPipe's helper clears 1.5x on every benchmark measure. */
    private const DEFAULTS = [
        ForwardVerifiedPublications::class => false,
        ReleaseVerificationBarrier::class => false,
        ReleaseVerificationPublicationBatchController::class => false,
        ReleaseVerificationPublicationController::class => false,
        MiscPipe::class => true,
    ];

    /** Helper per target: [class, method]. */
    private const HELPERS = [
        'forwarding' => [ForwardVerifiedPublications::class, 'summarizeForwardingCycle'],
        'barrier' => [ReleaseVerificationBarrier::class, 'evaluateBarrierReleaseQuorum'],
        'envelope' => [ReleaseVerificationPublicationBatchController::class, 'summarizeBatchEnvelopeValidation'],
        'response' => [ReleaseVerificationPublicationController::class, 'classifyPublicationResponseCode'],
        'misc' => [MiscPipe::class, 'summarizeMiscPipeDecisionMetrics'],
    ];

    private const STATUSES = [
        PublicationOutcome::PUBLISHED, PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW, PublicationOutcome::DEFERRED,
        PublicationOutcome::STALE, PublicationOutcome::PAUSED, PublicationOutcome::RETRY, PublicationOutcome::INVALID, PublicationOutcome::ERROR,
        PublicationOutcome::UNRESOLVED, PublicationOutcome::TRANSPORT, PublicationOutcome::NO_RELEASE,
    ];

    /** Strings that exercise trimming, case, separators, NUL bytes and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\x1F", 'published ', 'Published', ' published', "published\0", 'stale', 'x', '0123456789abcdef', '0123456789ABCDEF ',
        "\t0123456789abcdef0\n", '0123456789abcdeg', '0123456789abcde', 'abcdefgh', 'ABCDEFGH', 'ABCDefgh', 'ABCDEFGh', 'ABCd', 'AB12CD34',
        "\x0B\x0C ABCDEFGHIJ", 'The.Movie.2024.1080p', 'THE.MOVIE.2024.1080P', 'Ünïcödé.Sübjéct', "bad \xff utf8", "\xc3", "\x0C\x0C\x0C\x0C\x0C\x0C\x0C\x0C",
    ];

    /** Integers around every boundary the helpers use. */
    private const INTS = [PHP_INT_MIN, -281474976710657, -5, -1, 0, 1, 2, 3, 7, 8, 9, 10, 15, 16, 17, 199, 200, 201, 250, 251, 3600, 3601, 281474976710656, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW393RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, $class::usesW393RustFfi(), $class);
            $class::useW393RustFfi(true);
            $this->assertTrue($class::usesW393RustFfi(), $class);
            $class::useW393RustFfi(false);
            $this->assertFalse($class::usesW393RustFfi(), $class);
            $class::useW393RustFfi(! $default);
            $class::resetW393RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w393RustFfiOverride'))->getValue(), $class);
            $class::useW393RustFfi(! $default);
            $class::useW393RustFfi(null);
            $this->assertSame($default, $class::usesW393RustFfi(), $class);
        }

        // The W393 toggles never touch the classes' existing Rust FFI toggles.
        foreach (self::TARGETS as $class) {
            $class::useW393RustFfi(true);
        }
        $this->assertFalse(MiscPipe::$useRustFfi);
        foreach ([ForwardVerifiedPublications::class, ReleaseVerificationBarrier::class, ReleaseVerificationPublicationBatchController::class, ReleaseVerificationPublicationController::class] as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_existing_contracts_are_unchanged(): void
    {
        $this->assertSame('deployment', ReleaseVerificationBarrier::resolveReleaseScopeReason(false));
        $this->assertSame(1, ReleaseVerificationBarrier::clampTimeoutSeconds('-3'));
        $this->assertTrue(ReleaseVerificationBarrier::shouldCheckLiveness(10, 5));
        $this->assertSame(30.0, ForwardVerifiedPublications::computeRetryBackoffSeconds(9));
        $this->assertSame(['status' => 200, 'body' => ['release_id' => 4]], ReleaseVerificationPublicationController::classifySinglePublicationResponse(4, PublicationOutcome::PUBLISHED, null));
        $this->assertSame(422, ReleaseVerificationPublicationBatchController::classifyBatchResponseStatus(false, 0)['status']);
        $this->assertSame('Misc', (new MiscPipe)->getName());

        // Every outcome code's status agrees with the single endpoint's existing mapping.
        foreach (ReleaseVerificationPublicationController::PUBLICATION_OUTCOME_CODES as $code => $status) {
            $this->assertSame(
                ReleaseVerificationPublicationController::classifySinglePublicationResponse(1, $status, 'm')['status'],
                ReleaseVerificationPublicationController::classifyPublicationResponseCode($code, false)['status'],
                $status,
            );
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 9, 'forwarded' => 1, 'duplicates' => 1, 'settled' => 1, 'transient_errors' => 3, 'failures' => 3, 'consecutive_failures' => 4, 'longest_failure_streak' => 4, 'halt_recommended' => true],
            ForwardVerifiedPublications::summarizeForwardingCycle(['published', 'transport', 'error', 'stale', 'filtered', 'paused', 'bogus', 'retry', '']),
        );
        $this->assertFalse(ForwardVerifiedPublications::summarizeForwardingCycle(['error', 'error', 'error'], 0)['halt_recommended']);
        $this->assertSame(2, ForwardVerifiedPublications::summarizeForwardingCycle([null, 7, 'published'])['failures']);
        $this->assertSame(0, ForwardVerifiedPublications::summarizeForwardingCycle([])['total']);

        $this->assertSame(
            ['total' => 4, 'ready' => 2, 'failed_workers' => 0, 'pending' => 2, 'quorum_met' => false, 'timed_out' => false, 'failed' => false, 'wait_ms' => 50, 'state' => 'waiting'],
            ReleaseVerificationBarrier::evaluateBarrierReleaseQuorum(4, 2, 0, 850, 900),
        );
        $this->assertSame('quorum', ReleaseVerificationBarrier::evaluateBarrierReleaseQuorum(4, 4, 0, 10, 900)['state']);
        $this->assertSame('quorum', ReleaseVerificationBarrier::evaluateBarrierReleaseQuorum(-1, 9, 9, -5, -5)['state']);
        $this->assertSame('timed_out', ReleaseVerificationBarrier::evaluateBarrierReleaseQuorum(4, 2, 0, 900, 900)['state']);
        $this->assertSame(['failed_workers' => 2, 'state' => 'failed'], array_intersect_key(ReleaseVerificationBarrier::evaluateBarrierReleaseQuorum(4, 2, 9, 0, 900), ['failed_workers' => 0, 'state' => 0]));
        $this->assertSame(200, ReleaseVerificationBarrier::evaluateBarrierReleaseQuorum(4, 2, 0, 0, 900)['wait_ms']);

        $item = fn (int|string $id): array => ['claim' => ['id' => $id, 'version' => 0, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 't']];
        $this->assertSame(
            ['total' => 6, 'valid' => 3, 'duplicates' => 1, 'malformed' => 3, 'oversized' => false, 'accepted' => 2, 'max_items' => 250],
            ReleaseVerificationPublicationBatchController::summarizeBatchEnvelopeValidation([$item(1), $item(2), $item(2), $item('3'), $item(0), 'x']),
        );
        $this->assertSame(['oversized' => true, 'accepted' => 0, 'max_items' => 1], array_intersect_key(
            ReleaseVerificationPublicationBatchController::summarizeBatchEnvelopeValidation([$item(1), $item(2)], -4), ['oversized' => 0, 'accepted' => 0, 'max_items' => 0]));

        $this->assertSame(
            ['status' => 200, 'retry_after' => 0, 'idempotent' => true, 'metric_code' => 16, 'known' => true, 'metric' => 'published_replay'],
            ReleaseVerificationPublicationController::classifyPublicationResponseCode(0, true, 9),
        );
        $this->assertSame(
            ['status' => 409, 'retry_after' => 1, 'idempotent' => false, 'metric_code' => 5, 'known' => true, 'metric' => 'paused'],
            ReleaseVerificationPublicationController::classifyPublicationResponseCode(5, true),
        );
        $this->assertSame(3600, ReleaseVerificationPublicationController::classifyPublicationResponseCode(3, false, PHP_INT_MAX)['retry_after']);
        $this->assertSame(
            ['status' => 500, 'retry_after' => 0, 'idempotent' => false, 'metric_code' => 255, 'known' => false, 'metric' => 'unknown'],
            ReleaseVerificationPublicationController::classifyPublicationResponseCode(10, true, 5),
        );

        $this->assertSame(
            ['total' => 7, 'too_short' => 2, 'hash_like' => 2, 'uppercase_heavy' => 1, 'candidates' => 3],
            MiscPipe::summarizeMiscPipeDecisionMetrics(['short', '  0123456789abcdef0123  ', 'THE.MOVIE.NAME.2024', 'Some.Show.S01E02.720p', "\0\t\n\x0B  ", '0123456789ABCDEF', '0123456789abcde']),
        );
        $this->assertSame(1, MiscPipe::summarizeMiscPipeDecisionMetrics([['x'], 'abcdefghij'])['too_short']);
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $statuses = [...self::STATUSES, ...self::TEXTS];
        foreach ($statuses as $index => $status) {
            $window = array_slice([...$statuses, ...$statuses], $index, 1 + $index % 9);
            foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, 4, 9, PHP_INT_MAX] as $max) {
                $this->assertParity('forwarding', [$window, $max]);
            }
        }
        $this->assertParity('forwarding', [[], 3]);
        $this->assertParity('forwarding', [array_fill(0, 40, PublicationOutcome::TRANSPORT), 40]);

        foreach (self::INTS as $total) {
            foreach ([PHP_INT_MIN, -1, 0, 1, 2, $total, PHP_INT_MAX] as $ready) {
                foreach ([PHP_INT_MIN, 0, 1, PHP_INT_MAX] as $failed) {
                    foreach ([[0, 0], [0, 900], [850, 900], [900, 900], [PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MIN], [5, 199]] as [$elapsed, $timeout]) {
                        $this->assertParity('barrier', [$total, $ready, $failed, $elapsed, $timeout]);
                    }
                }
            }
        }

        $hash = str_repeat('f', 64);
        $items = [];
        foreach ([...self::INTS, '1', 1.0, null, true] as $id) {
            $items[] = ['claim' => ['id' => $id, 'version' => 0, 'manifest_hash' => $hash, 'lease_token' => 'token']];
        }
        $items = [...$items, ...$items, null, 'x', [], ['claim' => 'x'], ['claim' => ['id' => 5]],
            ['claim' => ['id' => 5, 'version' => -1, 'manifest_hash' => $hash, 'lease_token' => 't']],
            ['claim' => ['id' => 5, 'version' => 0, 'manifest_hash' => $hash.'0', 'lease_token' => 't']],
            ['claim' => ['id' => 5, 'version' => 0, 'manifest_hash' => $hash, 'lease_token' => '']],
            ['claim' => ['id' => 5, 'version' => 0, 'manifest_hash' => $hash, 'lease_token' => str_repeat('t', 65)]],
            ['claim' => ['id' => 5, 'version' => 0, 'manifest_hash' => $hash, 'lease_token' => str_repeat('t', 64)]]];
        foreach ([PHP_INT_MIN, 0, 1, 16, 63, 64, 250, PHP_INT_MAX] as $max) {
            for ($n = 0; $n <= count($items); $n += 7) {
                $this->assertParity('envelope', [array_slice($items, 0, $n), $max]);
            }
            $this->assertParity('envelope', [['a' => $items[1], 'b' => $items[1]], $max]);
        }

        foreach ([...self::INTS, 4, 5, 6, 11, 16, 26] as $code) {
            foreach ([false, true] as $replay) {
                foreach (self::INTS as $retry) {
                    $this->assertParity('response', [$code, $replay, $retry]);
                }
            }
        }

        foreach ([PHP_INT_MIN, -1, 0, 1, 8, 15, 16, 17, 64, PHP_INT_MAX] as $min) {
            $this->assertParity('misc', [self::TEXTS, $min]);
            foreach (self::TEXTS as $text) {
                $this->assertParity('misc', [[$text], $min]);
            }
            $this->assertParity('misc', [[], $min]);
            $this->assertParity('misc', [['abcdefghij', null, 3], $min]);
        }
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3931));
        $alphabet = ['a', 'f', 'g', 'z', 'A', 'F', 'G', 'Z', '0', '9', '.', '-', '_', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\x1F", "\xff", "\xc3\xa9"];
        $text = static function (int $max = 32) use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $pick(self::INTS),
            1 => $random->getInt(-3, 20),
            2 => $random->getInt(0, 100_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $hex = static fn (int $length): string => $length === 0 ? '' : substr(bin2hex($random->getBytes(intdiv($length + 1, 2))), 0, $length);

        for ($i = 0; $i < 1000; $i++) {
            $cycle = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $cycle[] = $random->getInt(0, 5) === 0 ? $text(10) : $pick(self::STATUSES);
            }
            $this->assertParity('forwarding', [$cycle, $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 6)]);

            $total = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 64);
            $this->assertParity('barrier', [
                $total,
                $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, max(0, $total)),
                $random->getInt(0, 1) === 0 ? 0 : ($random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 3)),
                $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 1_000_000),
                $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 1_000_000),
            ]);

            $items = [];
            for ($n = $random->getInt(0, 80); $n > 0; $n--) {
                // One roll per item; each decimal digit independently makes one field rare (1 in 10).
                $roll = $random->getInt(0, 99_999);
                $items[] = $roll % 10 === 0 ? $pick([null, 'x', [], ['claim' => null]]) : ['claim' => [
                    'id' => intdiv($roll, 10) % 10 === 0 ? $int() : $random->getInt(1, 60),
                    'version' => intdiv($roll, 100) % 10 === 0 ? -1 : $random->getInt(0, 9),
                    'manifest_hash' => $hex(intdiv($roll, 1_000) % 10 === 0 ? 63 : 64),
                    'lease_token' => $hex(intdiv($roll, 10_000) === 0 ? 0 : 32),
                ]];
            }
            $this->assertParity('envelope', [$items, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(1, 100)]);

            $this->assertParity('response', [$random->getInt(0, 3) === 0 ? $int() : $random->getInt(-1, 11), $random->getInt(0, 1) === 1, $int()]);

            $subjects = [];
            for ($n = $random->getInt(0, 60); $n > 0; $n--) {
                $subjects[] = match ($random->getInt(0, 4)) {
                    0 => $pick(['', ' ', "\t"]).$hex($random->getInt(8, 40)).$pick(['', ' ', "\n"]),
                    1 => strtoupper($text(40)),
                    2 => $pick(self::TEXTS),
                    default => $text(48),
                };
            }
            $this->assertParity('misc', [$subjects, $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 20)]);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        foreach ([
            ['nntmux_w393_forwarding_cycle_summary', ['publishedtransport', 18, pack('V2', 9, 9), 8, 1], "2\x1F1\x1F0\x1F0\x1F1\x1F0\x1F1\x1F1\x1F1"],
            ['nntmux_w393_barrier_release_quorum', [4, 2, 0, 850, 900], "4\x1F2\x1F0\x1F2\x1F0\x1F0\x1F0\x1F50\x1Fwaiting"],
            ['nntmux_w393_batch_envelope_summary', [pack('P3', 5, 5, 0), 24, 250], "3\x1F2\x1F1\x1F1\x1F0\x1F1\x1F250"],
            ['nntmux_w393_publication_response_code', [6, 0, 30], "409\x1F30\x1F0\x1F6\x1F1\x1Fretry"],
            ['nntmux_w393_misc_pipe_decision_metrics', ['short0123456789abcdef', 21, pack('V2', 5, 16), 8, 8], "2\x1F1\x1F1\x1F0\x1F0"],
        ] as [$symbol, $args, $expected]) {
            // Tiny capacity reports -required without writing, then a retry at that size succeeds.
            FFI::memset($small, ord('Z'), 4);
            $needed = $this->callFfi($ffi, $symbol, ...[...$args, $small, 2]);
            $this->assertSame(-strlen($expected), $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
            $buffer = $ffi->new('char['.(-$needed).']');
            $this->assertSame(-$needed, $this->callFfi($ffi, $symbol, ...[...$args, $buffer, -$needed]), $symbol);
            $this->assertSame($expected, FFI::string($buffer, -$needed), $symbol);
            // Null output buffers decline instead of crashing.
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, ...[...$args, null, 64]), $symbol);
        }

        $out = $ffi->new('char[256]');
        // Null inputs, inconsistent lengths and unknown flag bits decline; empty lists succeed.
        foreach (['nntmux_w393_forwarding_cycle_summary', 'nntmux_w393_misc_pipe_decision_metrics'] as $symbol) {
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, null, 0, '', 0, 3, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, '', 0, null, 0, 3, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 2), 4, 3, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'xy', 2, pack('V', 1), 4, 3, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 1).'z', 5, 3, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 0xFFFFFFFF), 4, 3, $out, 256), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, '', 0, '', 0, 3, $out, 256), $symbol);
        }
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w393_batch_envelope_summary', null, 0, 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w393_batch_envelope_summary', 'abc', 3, 1, $out, 256));
        $this->assertGreaterThan(0, $this->callFfi($ffi, 'nntmux_w393_batch_envelope_summary', '', 0, 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w393_publication_response_code', 0, 2, 0, $out, 256));

        // An output buffer overlapping an input declines without writing to it.
        $shared = $ffi->new('char[64]');
        FFI::memcpy($shared, 'published'.pack('V', 9), 13);
        $input = FFI::addr($shared[0]);
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w393_forwarding_cycle_summary', $input, 9, FFI::addr($shared[9]), 4, 3, FFI::addr($shared[4]), 40));
        $this->assertSame('published', FFI::string($shared, 9));
        $this->assertGreaterThan(0, $this->callFfi($ffi, 'nntmux_w393_forwarding_cycle_summary', $input, 9, FFI::addr($shared[9]), 4, 3, FFI::addr($shared[13]), 40));

        foreach (self::TARGETS as $class) {
            $class::useW393RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            $this->assertNotNull($class::{$method.'Native'}(...$this->sampleArgs($method)), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w393RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::TARGETS as $class) {
            (new ReflectionProperty($class, 'w393RustFfiHandle'))->setValue(null, false);
            $class::useW393RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            $args = $this->sampleArgs($method);
            $this->assertNull($class::{$method.'Native'}(...$args), $class);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
        }
        foreach (self::TARGETS as $class) {
            $class::resetW393RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w393RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side encoding, FFI call and decoding (release dylib, 5 warmups, 301 samples x 40
        // iterations per helper and workload). Common = a 16-item forwarding cycle, a waiting barrier,
        // a 16-item batch envelope, a published response and 32 ordinary subjects; uncommon = a
        // 250-item failure-heavy cycle, extreme barrier counts, a 250-item batch with duplicates and
        // malformed items, an unknown/clamped response code and 250 long, hash-heavy subjects.
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5.12 NTS, Apple M5):
        // - forwarding: common mean 0.48-0.50x / p99 0.50-0.54x, uncommon mean 1.10-1.15x / p99 0.91-1.21x
        // - barrier:    common mean 0.28-0.29x / p99 0.37-0.42x, uncommon mean 0.24-0.26x / p99 0.30-0.43x
        // - envelope:   common mean 0.59-0.61x / p99 0.56-0.66x, uncommon mean 0.75x / p99 0.70-0.88x
        // - response:   common mean 0.25x / p99 0.23-0.38x, uncommon mean 0.17-0.18x / p99 0.20-0.22x
        // - misc:       common mean 4.03-4.26x / p99 2.99-3.88x, uncommon mean 6.70-6.79x / p99 5.56-6.70x
        // The PHP MiscPipe reference counts letters with preg_replace(), the fastest of preg_match_all,
        // preg_replace, strtr and count_chars measured on the common batch.
        // Reproduce:
        // php vendor/bin/phpunit tests/Unit/RustVerificationBarrierForwardPubControllersW39ParityTest.php --filter benchmark
        // Only MiscPipe clears 1.5x on both workloads' mean and p99, so it alone defaults to Rust; the
        // scalar and small-list helpers are cheaper in PHP than the FFI round trip and keep PHP per
        // .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3932));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            foreach (['common' => $this->commonWorkload($helper), 'uncommon' => $this->uncommonWorkload($helper)] as $name => $args) {
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($class, $method, $args, false) + $this->runWorkload($class, $method, $args, true);
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += $this->runWorkload($class, $method, $args, $useRust);
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::useW393RustFfi(null);
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustVerificationBarrierForwardPubControllersW39 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W393_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW393RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W393_RUST_FFI_DEFAULT')) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $helper, array $args): void
    {
        [$class, $method] = self::HELPERS[$helper];
        $expected = $class::{$method.'Php'}(...$args);
        $native = $class::{$method.'Native'}(...$args);
        $nativeEligible = match ($helper) {
            'forwarding', 'misc' => array_filter($args[0], static fn (mixed $value): bool => ! is_string($value)) === [],
            default => true,
        };
        if ($nativeEligible) {
            $this->assertNotNull($native, $helper.' declined: '.json_encode($this->describe($args)));
            $this->assertSame($expected, $native, $helper.': '.json_encode($this->describe($args)));
        } else {
            $this->assertNull($native, $helper);
        }
        $class::useW393RustFfi(true);
        $this->assertSame($expected, $class::$method(...$args), $helper.' dispatcher');
        $class::useW393RustFfi(null);
    }

    private function describe(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => bin2hex($value),
            is_array($value) => array_map($this->describe(...), $value),
            default => $value,
        };
    }

    /**
     * @return list<mixed>
     */
    private function sampleArgs(string $method): array
    {
        return match ($method) {
            'summarizeForwardingCycle' => [['published', 'transport', 'stale'], 3],
            'evaluateBarrierReleaseQuorum' => [4, 2, 1, 10, 900],
            'summarizeBatchEnvelopeValidation' => [[['claim' => ['id' => 1, 'version' => 0, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 't']], 'x'], 16],
            'classifyPublicationResponseCode' => [5, false, 30],
            default => [['THE.MOVIE.2024.1080P', '0123456789abcdef0123', 'x'], 8],
        };
    }

    /**
     * @return list<mixed>
     */
    private function commonWorkload(string $helper): array
    {
        $hash = str_repeat('c', 64);

        return match ($helper) {
            'forwarding' => [[...array_fill(0, 13, PublicationOutcome::PUBLISHED), PublicationOutcome::STALE, PublicationOutcome::FILTERED, PublicationOutcome::TRANSPORT], 3],
            'barrier' => [6, 4, 0, 1_500, 900_000],
            'envelope' => [array_map(static fn (int $id): array => ['claim' => ['id' => $id, 'version' => 1, 'manifest_hash' => $hash, 'lease_token' => 'lease-'.$id]], range(1000, 1015)), 16],
            'response' => [0, false, 0],
            default => [array_map(static fn (int $i): string => match ($i % 4) {
                0 => 'Some.Show.S01E'.sprintf('%02d', $i).'.720p.WEB.h264-GRP',
                1 => 'Movie.Title.'.(1990 + $i).'.1080p.BluRay.x264-GRP',
                2 => 'Artist - Album ('.(2000 + $i).') [FLAC]',
                default => 'Some.Documentary.Part'.$i.'.HDTV',
            }, range(1, 32)), 8],
        };
    }

    /**
     * @return list<mixed>
     */
    private function uncommonWorkload(string $helper): array
    {
        $hash = str_repeat('u', 64);

        return match ($helper) {
            'forwarding' => [array_map(static fn (int $i): string => self::STATUSES[($i * 7) % count(self::STATUSES)].($i % 11 === 0 ? ' ' : ''), range(1, 250)), 5],
            'barrier' => [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX],
            'envelope' => [array_map(static fn (int $i): mixed => $i % 9 === 0 ? 'x' : ['claim' => ['id' => $i % 50 + 1, 'version' => 0, 'manifest_hash' => $hash, 'lease_token' => str_repeat('t', 64)]], range(1, 250)), 64],
            'response' => [PHP_INT_MAX, true, PHP_INT_MIN],
            default => [array_map(static fn (int $i): string => match ($i % 3) {
                0 => " \t".hash('sha256', (string) $i)."\n",
                1 => strtoupper(str_repeat('Long.Obfuscated.Subject.', 6)).$i,
                default => str_repeat("\xc3\xa9x", 40).' '.$i,
            }, range(1, 250)), 8],
        };
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function runWorkload(string $class, string $method, array $args, bool $useRust): int
    {
        $class::useW393RustFfi($useRust);

        return count($class::$method(...$args));
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

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W393_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w393', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 39.3 exports is not available.');
        }

        return $ffi;
    }
}
