<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use App\Services\Releases\Verification\ArticleBudgetExceeded;
use App\Services\Releases\Verification\ArticleIntegrityFailure;
use App\Services\Releases\Verification\CandidateAdmissionDeferredException;
use App\Services\Releases\Verification\LeaseLostException;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class RustVerificationBudgetIntegrityExceptionsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        CandidateMetadataBudgetExceeded::class,
        ArticleBudgetExceeded::class,
        ArticleIntegrityFailure::class,
        CandidateAdmissionDeferredException::class,
        LeaseLostException::class,
    ];

    /** Integers around every clamp, limit and overflow boundary the helpers use. */
    private const INTS = [
        PHP_INT_MIN, PHP_INT_MIN + 1, -4294967296, -1, 0, 1, 2, 9, 10, 99, 100, 101, 999, 1000, 1001,
        60_000, 299_999, 300_000, 300_001, 4294967295, 4294967296, 92233720368547758, 92233720368547759,
        PHP_INT_MAX - 1, PHP_INT_MAX,
    ];

    /** Strings that exercise trimming, brackets, message-id syntax, token charset and wire-marker bytes. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        '<>',
        '< >',
        '<abc@news.example>',
        ' <abc@news.example> ',
        'abc@news.example',
        '<<abc@news.example>>',
        'abc@@news',
        '@news',
        'abc@',
        'a b@c',
        "a\0b@c",
        'part1of5.abc$xyz@example.com',
        'candidate:123:abc',
        'tok-1_A',
        'tok 1',
        'tok.1',
        'Ünïcödé@例え.jp',
        "emoji \u{1F3AC}@x",
        "bad \xff@utf8",
        "sep\x1Finside@x",
        "\x1F",
        "\x1F\x1F",
        "null\x1Emark",
        "\x1E",
        "nul\0byte",
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_exceptions_keep_their_runtime_contract(): void
    {
        foreach (self::CLASSES as $class) {
            $previous = new RuntimeException('cause');
            $exception = new $class('message', 7, $previous);
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame('message', $exception->getMessage());
            $this->assertSame(7, $exception->getCode());
            $this->assertSame($previous, $exception->getPrevious());
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'consumed_bytes' => 150,
            'budget_bytes' => 100,
            'file_count' => 3,
            'max_files' => 2,
            'bytes_exceeded' => true,
            'files_exceeded' => true,
            'overrun_bytes' => 50,
            'overrun_files' => 1,
            'usage_percent' => 150,
            'reason' => 'bytes_and_files',
            'exceeded' => true,
        ], CandidateMetadataBudgetExceeded::summarizeBudgetOverrunPhp(150, 100, 3, 2));
        $within = CandidateMetadataBudgetExceeded::summarizeBudgetOverrunPhp(-5, 0, 9, 0);
        $this->assertSame(['within_budget', false, 0, 0], [$within['reason'], $within['exceeded'], $within['consumed_bytes'], $within['usage_percent']]);
        $this->assertSame(1000, CandidateMetadataBudgetExceeded::summarizeBudgetOverrunPhp(PHP_INT_MAX, 1, 0, 0)['usage_percent']);
        $this->assertSame(100, CandidateMetadataBudgetExceeded::summarizeBudgetOverrunPhp(PHP_INT_MAX, PHP_INT_MAX, 0, 0)['usage_percent']);
        $this->assertSame('files', CandidateMetadataBudgetExceeded::summarizeBudgetOverrunPhp(1, 2, 5, 4)['reason']);

        $this->assertSame([
            'articles_read' => 4,
            'max_articles' => 5,
            'bytes_read' => 900,
            'max_bytes' => 800,
            'articles_exceeded' => false,
            'bytes_exceeded' => true,
            'overrun_articles' => 0,
            'overrun_bytes' => 100,
            'remaining_articles' => 1,
            'remaining_bytes' => 0,
            'reason' => 'bytes',
            'exceeded' => true,
            'refetch_can_succeed' => false,
        ], ArticleBudgetExceeded::summarizeArticleBudgetOverrunPhp(4, 5, 900, 800));
        $unlimited = ArticleBudgetExceeded::summarizeArticleBudgetOverrunPhp(PHP_INT_MAX, 0, 1, -3);
        $this->assertSame([-1, -1, 'within_budget', true], [$unlimited['remaining_articles'], $unlimited['remaining_bytes'], $unlimited['reason'], $unlimited['refetch_can_succeed']]);

        $this->assertSame([
            'message_id' => 'abc@news.example',
            'has_message_id' => true,
            'message_id_valid' => true,
            'expected_crc' => 0xDEADBEEF,
            'actual_crc' => 1,
            'expected_hex' => 'deadbeef',
            'actual_hex' => '00000001',
            'crc_in_range' => true,
            'crc_match' => false,
            'decoded_bytes' => 10,
            'kind' => 'crc_mismatch',
            'is_failure' => true,
            'retryable' => true,
        ], ArticleIntegrityFailure::classifyIntegrityFailurePhp(' <abc@news.example> ', 0xDEADBEEF, 1, 10));
        $integrity = ArticleIntegrityFailure::classifyIntegrityFailurePhp(null, -1, 4294967295, 5);
        $this->assertSame([null, false, 4294967295, true, 'invalid_crc', false], [
            $integrity['message_id'], $integrity['has_message_id'], $integrity['expected_crc'], $integrity['crc_match'], $integrity['kind'], $integrity['retryable'],
        ]);
        $this->assertSame('empty_payload', ArticleIntegrityFailure::classifyIntegrityFailurePhp('a@b', 1, 1, 0)['kind']);
        $this->assertSame('none', ArticleIntegrityFailure::classifyIntegrityFailurePhp('a@b', 7, 7, 1)['kind']);
        $this->assertNull(ArticleIntegrityFailure::classifyIntegrityFailurePhp(' <> ', 7, 7, 1)['message_id']);
        $this->assertSame('<a@b>', ArticleIntegrityFailure::classifyIntegrityFailurePhp('<<a@b>>', 7, 7, 1)['message_id']);
        foreach (['a@@b', '@b', 'a@', 'a b@c', "a\0b@c", '<a@b>x'] as $invalid) {
            $this->assertFalse(ArticleIntegrityFailure::classifyIntegrityFailurePhp($invalid, 7, 7, 1)['message_id_valid'], $invalid);
        }

        $this->assertSame([
            'active_workers' => 4,
            'max_workers' => 4,
            'queue_depth' => 50,
            'available_slots' => 0,
            'saturated' => true,
            'should_defer' => true,
            'retry_delay_ms' => 2500,
            'delay_clamped' => false,
            'pressure' => 'backlogged',
        ], CandidateAdmissionDeferredException::summarizeDeferralPolicyPhp(4, 4, 50, 2000));
        $idle = CandidateAdmissionDeferredException::summarizeDeferralPolicyPhp(0, 4, 0, 0);
        $this->assertSame(['idle', 1000, true, 4], [$idle['pressure'], $idle['retry_delay_ms'], $idle['delay_clamped'], $idle['available_slots']]);
        $this->assertSame('available', CandidateAdmissionDeferredException::summarizeDeferralPolicyPhp(1, 4, 9, 500)['pressure']);
        $this->assertSame('saturated', CandidateAdmissionDeferredException::summarizeDeferralPolicyPhp(0, 0, 0, 500)['pressure']);
        $this->assertSame(300_000, CandidateAdmissionDeferredException::summarizeDeferralPolicyPhp(9, 2, PHP_INT_MAX, PHP_INT_MAX)['retry_delay_ms']);

        $this->assertSame([
            'candidate_key' => "cand\x1Fkey",
            'owner_token' => 'tok-1_A',
            'has_candidate' => true,
            'has_owner' => true,
            'token_valid' => true,
            'elapsed_ms' => 70,
            'ttl_ms' => 60,
            'expired' => true,
            'overrun_ms' => 10,
            'remaining_ms' => 0,
            'reason' => 'expired',
            'reclaimable' => true,
        ], LeaseLostException::classifyLeaseLossPhp(" cand\x1Fkey ", 'tok-1_A', 70, 60));
        $this->assertSame('missing_candidate', LeaseLostException::classifyLeaseLossPhp(' ', 'tok', 1, 60)['reason']);
        $this->assertSame('missing_owner', LeaseLostException::classifyLeaseLossPhp('k', null, 1, 60)['reason']);
        $this->assertSame('invalid_owner', LeaseLostException::classifyLeaseLossPhp('k', 'bad tok', 1, 60)['reason']);
        $this->assertSame('invalid_owner', LeaseLostException::classifyLeaseLossPhp('k', str_repeat('a', 129), 1, 60)['reason']);
        $stolen = LeaseLostException::classifyLeaseLossPhp('k', str_repeat('a', 128), 1, 60);
        $this->assertSame(['stolen', 59, false], [$stolen['reason'], $stolen['remaining_ms'], $stolen['reclaimable']]);
        $this->assertSame('stolen', LeaseLostException::classifyLeaseLossPhp('k', 't', PHP_INT_MAX, 0)['reason']);
    }

    public function test_metadata_budget_native_and_dispatch_match_php(): void
    {
        foreach (self::INTS as $a) {
            foreach (self::INTS as $b) {
                $this->assertMetadataParity($a, $b, 3, 2);
                $this->assertMetadataParity(5, 4, $a, $b);
            }
        }
    }

    public function test_article_budget_native_and_dispatch_match_php(): void
    {
        foreach (self::INTS as $a) {
            foreach (self::INTS as $b) {
                $this->assertArticleParity($a, $b, 900, 800);
                $this->assertArticleParity(4, 5, $a, $b);
            }
        }
    }

    public function test_article_integrity_native_and_dispatch_match_php(): void
    {
        foreach ([null, ...self::TEXTS] as $id) {
            foreach (self::INTS as $int) {
                $this->assertIntegrityParity($id, $int, 0xDEADBEEF, 10);
                $this->assertIntegrityParity($id, 1, $int, $int);
            }
        }
        $this->assertIntegrityParity('<'.str_repeat('x', 5000).'@'.str_repeat("\x1F", 3000).'>', 1, 1, 1);
    }

    public function test_admission_deferral_native_and_dispatch_match_php(): void
    {
        foreach (self::INTS as $a) {
            foreach (self::INTS as $b) {
                $this->assertDeferralParity($a, $b, 50, 2000);
                $this->assertDeferralParity(4, 4, $a, $b);
            }
        }
    }

    public function test_lease_loss_native_and_dispatch_match_php(): void
    {
        foreach ([null, ...self::TEXTS] as $key) {
            foreach ([null, ...self::TEXTS] as $token) {
                $this->assertLeaseParity($key, $token, 70, 60);
            }
            foreach (self::INTS as $int) {
                $this->assertLeaseParity($key, 'tok', $int, 60);
                $this->assertLeaseParity('k', $key, 1, $int);
            }
        }
        $this->assertLeaseParity(str_repeat("k\x1F", 4000), str_repeat('a', 128), 5, 9);
        $this->assertLeaseParity(str_repeat("\x1F", 9), str_repeat("\x1F", 9), 5, 9);
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3603));
        $alphabet = ['a', 'Z', '0', '9', '-', '_', '.', '@', '<', '>', '$', ' ', "\t", "\n", "\0", "\x0B", "\x0C", 'é', '日', "\u{1F3AC}", "\x1F", "\x1E", "\xff", "\xc3"];
        $text = static function (int $max = 24) use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 400_000),
            2 => $random->getInt(0, 0x1FFFFFFFF),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $nullable = static fn (string $value): ?string => $random->getInt(0, 4) === 0 ? null : $value;

        for ($i = 0; $i < 2000; $i++) {
            $this->assertMetadataParity($int(), $int(), $int(), $int());
            $this->assertArticleParity($int(), $int(), $int(), $int());
            $id = $random->getInt(0, 1) === 1 ? $text(6).'@'.$text(6) : $text();
            $crc = $int();
            $this->assertIntegrityParity($nullable($random->getInt(0, 1) === 1 ? '<'.$id.'>' : $id), $crc, $random->getInt(0, 1) === 1 ? $crc : $int(), $int());
            $this->assertDeferralParity($int(), $int(), $int(), $int());
            $token = $random->getInt(0, 1) === 1 ? bin2hex($random->getBytes(8)) : $text(10);
            $this->assertLeaseParity($nullable($text()), $nullable($token), $int(), $int());
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $needed = $this->callFfi($ffi, 'nntmux_candidate_metadata_budget_summarize', 150, 100, 3, 2, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_candidate_metadata_budget_summarize', 150, 100, 3, 2, $buffer, -$needed));
        $this->assertSame("150\x1F100\x1F3\x1F2\x1F1\x1F1\x1F50\x1F1\x1F150\x1Fbytes_and_files\x1F1", FFI::string($buffer, -$needed));

        foreach ([
            ['nntmux_article_budget_summarize', [1, 2, 3, 4]],
            ['nntmux_candidate_admission_deferral_summarize', [1, 2, 3, 4]],
            ['nntmux_article_integrity_classify', [1, 'a@b', 3, 1, 2, 3]],
            ['nntmux_lease_lost_classify', [3, 'k', 1, 't', 1, 1, 2]],
        ] as [$symbol, $args]) {
            $needed = $this->callFfi($ffi, $symbol, ...[...$args, $small, 4]);
            $this->assertLessThan(-1, $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
        }

        $out = $ffi->new('char[512]');
        $written = $this->callFfi($ffi, 'nntmux_article_budget_summarize', 4, 5, 900, 800, $out, 512);
        $this->assertSame("4\x1F5\x1F900\x1F800\x1F0\x1F1\x1F0\x1F100\x1F1\x1F0\x1Fbytes\x1F1\x1F0", FFI::string($out, $written));
        $id = ' <abc@news.example> ';
        $written = $this->callFfi($ffi, 'nntmux_article_integrity_classify', 1, $id, strlen($id), 0xDEADBEEF, 1, 10, $out, 512);
        $this->assertSame("3735928559\x1F1\x1Fdeadbeef\x1F00000001\x1F1\x1F0\x1F10\x1Fcrc_mismatch\x1F1\x1F1\x1F1\x1F1\x1Fabc@news.example", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_candidate_admission_deferral_summarize', 4, 4, 50, 2000, $out, 512);
        $this->assertSame("4\x1F4\x1F50\x1F0\x1F1\x1F1\x1F2500\x1F0\x1Fbacklogged", FFI::string($out, $written));
        $key = " cand\x1Fkey ";
        $written = $this->callFfi($ffi, 'nntmux_lease_lost_classify', 3, $key, strlen($key), 'tok-1_A', 7, 70, 60, $out, 512);
        $this->assertSame("1\x1F1\x1F1\x1F70\x1F60\x1F1\x1F10\x1F0\x1Fexpired\x1F1\x1F8\x1Fcand\x1Fkey\x1Ftok-1_A", FFI::string($out, $written));

        // Unknown flag bits, missing pointers for present strings and text for absent strings decline with -1.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_article_integrity_classify', 2, null, 0, 0, 0, 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_article_integrity_classify', 1, null, 0, 0, 0, 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_article_integrity_classify', 0, 'x', 1, 0, 0, 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_lease_lost_classify', 4, null, 0, null, 0, 0, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_lease_lost_classify', 1, null, 0, null, 0, 0, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_lease_lost_classify', 2, null, 0, null, 0, 0, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_lease_lost_classify', 0, 'k', 1, null, 0, 0, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_lease_lost_classify', 0, null, 0, 't', 1, 0, 0, $out, 512));

        // Null output buffers decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_candidate_metadata_budget_summarize', 0, 0, 0, 0, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_article_budget_summarize', 0, 0, 0, 0, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_candidate_admission_deferral_summarize', 0, 0, 0, 0, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_article_integrity_classify', 0, null, 0, 0, 0, 0, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_lease_lost_classify', 0, null, 0, null, 0, 0, 0, null, 64));
        $this->assertGreaterThan(0, $this->callFfi($ffi, 'nntmux_lease_lost_classify', 0, null, 0, null, 0, 0, 0, $out, 512));

        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        CandidateMetadataBudgetExceeded::summarizeBudgetOverrun(1, 2, 3, 4);
        ArticleBudgetExceeded::summarizeArticleBudgetOverrun(1, 2, 3, 4);
        ArticleIntegrityFailure::classifyIntegrityFailure('a@b', 1, 2, 3);
        CandidateAdmissionDeferredException::summarizeDeferralPolicy(1, 2, 3, 4);
        LeaseLostException::classifyLeaseLoss('k', 't', 1, 2);
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $property = static fn (string $class): ReflectionProperty => new ReflectionProperty($class, 'ffi');
        $saved = [];
        foreach (self::CLASSES as $class) {
            $saved[$class] = $property($class)->getValue();
            $property($class)->setValue(null, false);
            $class::setUseRustFfi(true);
        }

        try {
            $this->assertSame(
                CandidateMetadataBudgetExceeded::summarizeBudgetOverrunPhp(150, 100, 3, 2),
                CandidateMetadataBudgetExceeded::summarizeBudgetOverrun(150, 100, 3, 2),
            );
            $this->assertSame(
                ArticleBudgetExceeded::summarizeArticleBudgetOverrunPhp(4, 5, 900, 800),
                ArticleBudgetExceeded::summarizeArticleBudgetOverrun(4, 5, 900, 800),
            );
            $this->assertSame(
                ArticleIntegrityFailure::classifyIntegrityFailurePhp('<a@b>', 1, 2, 3),
                ArticleIntegrityFailure::classifyIntegrityFailure('<a@b>', 1, 2, 3),
            );
            $this->assertSame(
                CandidateAdmissionDeferredException::summarizeDeferralPolicyPhp(4, 4, 50, 2000),
                CandidateAdmissionDeferredException::summarizeDeferralPolicy(4, 4, 50, 2000),
            );
            $this->assertSame(
                LeaseLostException::classifyLeaseLossPhp(' k ', null, 70, 60),
                LeaseLostException::classifyLeaseLoss(' k ', null, 70, 60),
            );
        } finally {
            foreach (self::CLASSES as $class) {
                $property($class)->setValue(null, $saved[$class]);
            }
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers,
        // including the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical budget, CRC, admission and lease checks; uncommon = clamped, overflowing,
        // long, marker-laden and invalid inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 4 rounds):
        // - Round 1: common mean 0.29x / p99 0.30x, uncommon mean 0.25x / p99 0.28x
        // - Round 2: common mean 0.30x / p99 0.41x, uncommon mean 0.26x / p99 0.37x
        // - Round 3: common mean 0.29x / p99 0.32x, uncommon mean 0.26x / p99 0.30x
        // - Round 4: common mean 0.29x / p99 0.34x, uncommon mean 0.26x / p99 0.31x
        // Reproduce:
        // php vendor/bin/phpunit tests/Unit/RustVerificationBudgetIntegrityExceptionsParityTest.php --filter benchmark
        // Microsecond PHP integer/string work is cheaper than the FFI round trip, so every helper stays
        // below the 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3603));
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
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustVerificationBudgetIntegrityExceptions speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::CLASSES as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertMetadataParity(int $consumed, int $budget, int $files, int $maxFiles): void
    {
        $expected = CandidateMetadataBudgetExceeded::summarizeBudgetOverrunPhp($consumed, $budget, $files, $maxFiles);
        $context = json_encode([$consumed, $budget, $files, $maxFiles]) ?: '';
        $this->assertSameAttributes($expected, CandidateMetadataBudgetExceeded::summarizeBudgetOverrunNative($consumed, $budget, $files, $maxFiles), $context);
        CandidateMetadataBudgetExceeded::setUseRustFfi(true);
        $this->assertSameAttributes($expected, CandidateMetadataBudgetExceeded::summarizeBudgetOverrun($consumed, $budget, $files, $maxFiles), $context);
        CandidateMetadataBudgetExceeded::setUseRustFfi(false);
        $this->assertSameAttributes($expected, CandidateMetadataBudgetExceeded::summarizeBudgetOverrun($consumed, $budget, $files, $maxFiles), $context);
        CandidateMetadataBudgetExceeded::setUseRustFfi(null);
    }

    private function assertArticleParity(int $articles, int $maxArticles, int $bytes, int $maxBytes): void
    {
        $expected = ArticleBudgetExceeded::summarizeArticleBudgetOverrunPhp($articles, $maxArticles, $bytes, $maxBytes);
        $context = json_encode([$articles, $maxArticles, $bytes, $maxBytes]) ?: '';
        $this->assertSameAttributes($expected, ArticleBudgetExceeded::summarizeArticleBudgetOverrunNative($articles, $maxArticles, $bytes, $maxBytes), $context);
        ArticleBudgetExceeded::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ArticleBudgetExceeded::summarizeArticleBudgetOverrun($articles, $maxArticles, $bytes, $maxBytes), $context);
        ArticleBudgetExceeded::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ArticleBudgetExceeded::summarizeArticleBudgetOverrun($articles, $maxArticles, $bytes, $maxBytes), $context);
        ArticleBudgetExceeded::setUseRustFfi(null);
    }

    private function assertIntegrityParity(?string $id, int $expectedCrc, int $actualCrc, int $decoded): void
    {
        $expected = ArticleIntegrityFailure::classifyIntegrityFailurePhp($id, $expectedCrc, $actualCrc, $decoded);
        $context = json_encode([$id === null ? null : bin2hex($id), $expectedCrc, $actualCrc, $decoded]) ?: '';
        $this->assertSameAttributes($expected, ArticleIntegrityFailure::classifyIntegrityFailureNative($id, $expectedCrc, $actualCrc, $decoded), $context);
        ArticleIntegrityFailure::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ArticleIntegrityFailure::classifyIntegrityFailure($id, $expectedCrc, $actualCrc, $decoded), $context);
        ArticleIntegrityFailure::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ArticleIntegrityFailure::classifyIntegrityFailure($id, $expectedCrc, $actualCrc, $decoded), $context);
        ArticleIntegrityFailure::setUseRustFfi(null);
    }

    private function assertDeferralParity(int $active, int $max, int $depth, int $delay): void
    {
        $expected = CandidateAdmissionDeferredException::summarizeDeferralPolicyPhp($active, $max, $depth, $delay);
        $context = json_encode([$active, $max, $depth, $delay]) ?: '';
        $this->assertSameAttributes($expected, CandidateAdmissionDeferredException::summarizeDeferralPolicyNative($active, $max, $depth, $delay), $context);
        CandidateAdmissionDeferredException::setUseRustFfi(true);
        $this->assertSameAttributes($expected, CandidateAdmissionDeferredException::summarizeDeferralPolicy($active, $max, $depth, $delay), $context);
        CandidateAdmissionDeferredException::setUseRustFfi(false);
        $this->assertSameAttributes($expected, CandidateAdmissionDeferredException::summarizeDeferralPolicy($active, $max, $depth, $delay), $context);
        CandidateAdmissionDeferredException::setUseRustFfi(null);
    }

    private function assertLeaseParity(?string $key, ?string $token, int $elapsed, int $ttl): void
    {
        $expected = LeaseLostException::classifyLeaseLossPhp($key, $token, $elapsed, $ttl);
        $context = json_encode([$key === null ? null : bin2hex($key), $token === null ? null : bin2hex($token), $elapsed, $ttl]) ?: '';
        $this->assertSameAttributes($expected, LeaseLostException::classifyLeaseLossNative($key, $token, $elapsed, $ttl), $context);
        LeaseLostException::setUseRustFfi(true);
        $this->assertSameAttributes($expected, LeaseLostException::classifyLeaseLoss($key, $token, $elapsed, $ttl), $context);
        LeaseLostException::setUseRustFfi(false);
        $this->assertSameAttributes($expected, LeaseLostException::classifyLeaseLoss($key, $token, $elapsed, $ttl), $context);
        LeaseLostException::setUseRustFfi(null);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameAttributes(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected, $actual, $context);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['metadata', [48_000, 65_536, 12, 64]],
            ['metadata', [70_000, 65_536, 80, 64]],
            ['article', [3, 5, 750_000, 1_048_576]],
            ['article', [5, 5, 1_200_000, 1_048_576]],
            ['integrity', ['<part1of5.abc$xyz@news.example.com>', 0x1A2B3C4D, 0x1A2B3C4D, 768_000]],
            ['integrity', ['<part2of5.abc$xyz@news.example.com>', 0x1A2B3C4D, 0x0BADF00D, 768_000]],
            ['deferral', [8, 8, 42, 1_000]],
            ['lease', ['candidate:123456', 'd41d8cd98f00b204e9800998ecf8427e', 61_000, 60_000]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        return [
            ['metadata', [PHP_INT_MAX, 7, PHP_INT_MIN, -1]],
            ['article', [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX, 1]],
            ['integrity', [" \t<".str_repeat('x', 200).'@@'.str_repeat("\x1F", 40)."> \n", -1, PHP_INT_MAX, 0]],
            ['integrity', [null, 4294967296, 0, 1]],
            ['deferral', [PHP_INT_MAX, 0, PHP_INT_MAX, PHP_INT_MIN]],
            ['deferral', [0, PHP_INT_MAX, 0, 300_001]],
            ['lease', [str_repeat("k\x1F", 100), str_repeat('t', 129), PHP_INT_MAX, PHP_INT_MAX]],
            ['lease', [null, ' bad token ', -5, 0]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'metadata' => CandidateMetadataBudgetExceeded::summarizeBudgetOverrun(...$args),
                'article' => ArticleBudgetExceeded::summarizeArticleBudgetOverrun(...$args),
                'integrity' => ArticleIntegrityFailure::classifyIntegrityFailure(...$args),
                'deferral' => CandidateAdmissionDeferredException::summarizeDeferralPolicy(...$args),
                default => LeaseLostException::classifyLeaseLoss(...$args),
            };
            $count += count($result);
        }

        return $count;
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
        $cdef = implode("\n", [
            CandidateMetadataBudgetExceeded::FFI_CDEF,
            ArticleBudgetExceeded::FFI_CDEF,
            ArticleIntegrityFailure::FFI_CDEF,
            CandidateAdmissionDeferredException::FFI_CDEF,
            LeaseLostException::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('categorize', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the verification exception exports is not available.');
        }

        return $ffi;
    }
}
