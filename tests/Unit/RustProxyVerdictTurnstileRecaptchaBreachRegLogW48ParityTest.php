<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PasswordBreachService;
use App\Services\RecaptchaService;
use App\Services\RegistrationFailureLogService;
use App\Services\Security\ProxyVerdict;
use App\Services\TurnstileService;
use Closure;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustProxyVerdictTurnstileRecaptchaBreachRegLogW48ParityTest extends TestCase
{
    /** Class => toggle stem of its `useW48*RustFfi()` / `usesW48*RustFfi()` / `resetW48*RustFfi()` methods. */
    private const STEMS = [
        ProxyVerdict::class => 'Aggregate',
        TurnstileService::class => 'Siteverify',
        RecaptchaService::class => 'Assessment',
        PasswordBreachService::class => 'BreachRange',
        RegistrationFailureLogService::class => 'FailureDigest',
    ];

    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ProxyVerdict::class => 'aggregateVerdicts',
        TurnstileService::class => 'evaluateSiteverify',
        RecaptchaService::class => 'assessResponses',
        PasswordBreachService::class => 'lookupBreachCounts',
        RegistrationFailureLogService::class => 'summarizeFailures',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of four runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 48, 200 pairs
     * per workload after 20 warmups, randomized call order, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS (shared host, load ~4-10). Timings include dispatch, NUL joining, the FFI
     * call and unpack('P*'). A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ProxyVerdict::class => [5.028, 3.322, 5.718, 4.641],
        TurnstileService::class => [2.375, 1.691, 2.341, 2.191],
        RecaptchaService::class => [2.867, 2.393, 2.434, 1.968],
        PasswordBreachService::class => [2.469, 2.319, 2.586, 2.530],
        RegistrationFailureLogService::class => [2.612, 1.973, 2.414, 2.305],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    private const REASON_NAMES = ['ratio', 'burst', 'agent', 'referer', 'subnet', 'asn', 'tor', 'datacenter', 'api_rate', 'grab_ratio'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::STEMS) as $class) {
            $this->reset($class);
        }
        parent::tearDown();
    }

    public function test_wave_methods_exist(): void
    {
        foreach (self::STEMS as $class => $stem) {
            $reflection = new ReflectionClass($class);
            $entry = self::ENTRIES[$class];
            foreach (["useW48{$stem}RustFfi", "usesW48{$stem}RustFfi", "resetW48{$stem}RustFfi", $entry, $entry.'Php', $entry.'Native', 'w48Ffi'] as $method) {
                self::assertTrue((new ReflectionClass($class))->hasMethod($method), $class.'::'.$method);
            }
            self::assertTrue($reflection->hasConstant('W48_FFI_CDEF'), $class);
            self::assertStringContainsString('nntmux_w48_', (string) $reflection->getConstant('W48_FFI_CDEF'));
            self::assertTrue($reflection->hasConstant('W48_'.strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $stem)).'_RUST_FFI_DEFAULT'), $class);
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        $defaults = $this->defaults();
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $defaults[$class], $class);
        }
    }

    public function test_default_routing_toggles_and_reset(): void
    {
        $defaults = $this->defaults();
        foreach (array_keys(self::STEMS) as $class) {
            $this->reset($class);
            self::assertSame($defaults[$class], $this->uses($class), $class);
            $this->toggle($class, ! $defaults[$class]);
            self::assertSame(! $defaults[$class], $this->uses($class), $class);
            $this->toggle($class, null);
            self::assertSame($defaults[$class], $this->uses($class), $class);
            $this->toggle($class, true);
            $this->reset($class);
            self::assertSame($defaults[$class], $this->uses($class), $class);
        }
        // The Wave 48 toggles are independent of the earlier Rust switches.
        self::assertFalse(TurnstileService::usesRustFfi());
        self::assertFalse(RecaptchaService::usesRustFfi());
        self::assertFalse(PasswordBreachService::usesRustFfi());
        self::assertTrue(RegistrationFailureLogService::usesRustFfi());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        foreach ([true, false] as $native) {
            ProxyVerdict::useW48AggregateRustFfi($native);
            self::assertSame(ProxyVerdict::aggregateVerdictsPhp(['10.0.0.1'], ['a:60'], 50), ProxyVerdict::aggregateVerdicts(['10.0.0.1'], ['a:60'], 50));
            TurnstileService::useW48SiteverifyRustFfi($native);
            self::assertSame(TurnstileService::evaluateSiteverifyPhp(['t'], ['{"success":true}']), TurnstileService::evaluateSiteverify(['t'], ['{"success":true}']));
            RecaptchaService::useW48AssessmentRustFfi($native);
            self::assertSame(RecaptchaService::assessResponsesPhp(['{"success":true,"score":0.9}'], 0.5), RecaptchaService::assessResponses(['{"success":true,"score":0.9}'], 0.5));
            PasswordBreachService::useW48BreachRangeRustFfi($native);
            self::assertSame(PasswordBreachService::lookupBreachCountsPhp(['pw'], ['']), PasswordBreachService::lookupBreachCounts(['pw'], ['']));
            RegistrationFailureLogService::useW48FailureDigestRustFfi($native);
            self::assertSame(
                RegistrationFailureLogService::summarizeFailuresPhp(['1.1.1.1'], ['a@b.co'], ['x'], ['curl/8'], [1], 60, 2),
                RegistrationFailureLogService::summarizeFailures(['1.1.1.1'], ['a@b.co'], ['x'], ['curl/8'], [1], 60, 2),
            );
        }
    }

    public function test_proxy_verdict_parity(): void
    {
        $this->requireBindings();
        $ips = ['10.0.0.1', '10.0.0.9', 'bad', '2001:db8::1'];
        $specs = ['ratio:40, burst:25', 'ratio:5,ratio:-5,x', '', 'agent:60,ua:60'];
        $words = [
            65 | 1 << 10 | 100 << 11 | 2 << 18 | 10040 << 34, 1 << 48 | 10 << 16, 2 | 1 << 16 | crc32('ratio') << 32,
            1 << 18 | 1 << 26 | 9995 << 34, 1 << 48 | 10 << 16, 2 | 1 << 16 | crc32('ratio') << 32,
            10000 << 34, -1, 0,
            120 | 1 << 10 | 100 << 11 | 2 << 18 | 10060 << 34, 2 << 48 | 0x20010DB80000, 1 | 1 << 16 | crc32('agent') << 32,
        ];
        self::assertSame([...$words, $this->digest($words)], ProxyVerdict::aggregateVerdictsPhp($ips, $specs, 50));
        $this->assertVerdictParity($ips, $specs, 50);
        $this->assertVerdictParity([], [], 50);
        $this->assertVerdictParity([''], [''], 0);
        $this->assertVerdictParity(['1.1.1.1', '1.1.1.2'], ['a:1'], 1);
        $this->assertVerdictParity(['1.1.1.1'], ["a:1\0"], 1);
        $this->assertVerdictParity(['1.1.1.1', '1.1.1.1', '::ffff:1.1.1.1'], ['a:-9999', 'b:9999,c:9999', str_repeat('n', 32).':1,'.str_repeat('n', 33).':1,123:5,0123:6,a:+1,a:,:5,a:12345,a:-,a:-0012'], -5);
        $this->assertVerdictParity(['8.8.8.8', '8.8.4.4'], ['tie:5,first:9,second:9', ' z : 1 ,z:1'], PHP_INT_MAX);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertVerdictParity(...$this->verdictBatch(new Randomizer(new Mt19937(4810 + $i)), $uncommon));
            }
        }
    }

    public function test_turnstile_siteverify_parity(): void
    {
        $this->requireBindings();
        $tokens = ['0.abc-DEF_1', 'bad token', 'x', 'y', 'z'];
        $bodies = [
            '{"success":true,"hostname":"Example.COM","action":"login","error-codes":[]}',
            '{"success":true}',
            'nope',
            '{"success":false,"error-codes":["timeout-or-duplicate","?",7]}',
            '{"success":true,"hostname":"other.org","action":"login"}',
        ];
        $words = [
            1 << 12 | 1 << 13 | 1 << 14 | 1 << 15 | 11 << 16 | 1 << 36 | 1 << 37, crc32('example.com'),
            1 | 9 << 16 | 1 << 36 | 1 << 37, -1,
            2 | 1 << 16, -1,
            3 | (1 << 5 | 1 << 7) << 4 | 1 << 16 | 2 << 28 | 1 << 36, -1,
            4 | 1 << 12 | 1 << 14 | 1 << 15 | 1 << 16 | 1 << 36 | 1 << 37, crc32('other.org'),
        ];
        self::assertSame([...$words, $this->digest($words)], TurnstileService::evaluateSiteverifyPhp($tokens, $bodies, 'example.com', 'login'));
        $this->assertSiteverifyParity($tokens, $bodies, 'example.com', 'login');
        $this->assertSiteverifyParity($tokens, $bodies, '', '');
        $this->assertSiteverifyParity([], [], '', '');
        $this->assertSiteverifyParity(['t', 'u'], ['{}'], '', '');
        $this->assertSiteverifyParity(['t'], ["{}\0"], '', '');
        $edge = $this->jsonEdgeCases();
        $this->assertSiteverifyParity(array_fill(0, \count($edge), 'tok'), $edge, 'a.com', 'login');
        $this->assertSiteverifyParity(['', str_repeat('a', 2048), str_repeat('a', 2049), "a\x80", 'a:b'], array_fill(0, 5, '{"success":true}'), '', '');
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertSiteverifyParity(...$this->turnstileBatch(new Randomizer(new Mt19937(4820 + $i)), $uncommon));
            }
        }
    }

    public function test_recaptcha_assessment_parity(): void
    {
        $this->requireBindings();
        $bodies = [
            '{"success":true,"score":0.3,"action":"login","hostname":"example.com"}',
            '{"success":true,"score":0.9,"action":"login","hostname":"EXAMPLE.com"}',
            '{"success":true,"score":"1"}',
            '{"success":true}',
            '{"success":false,"error-codes":["invalid-input-response"]}',
            '[]',
        ];
        $words = [
            3 | 2 << 12 | 1 << 14 | 1 << 15 | 1 << 24 | 1 << 25 | 1 << 26 | 1 << 27, 300000,
            2 << 12 | 1 << 14 | 1 << 15 | 1 << 24 | 1 << 25 | 1 << 26 | 1 << 27, 900000,
            6 | 2 << 12 | 1 << 24 | 1 << 25, -2,
            5 | 1 << 12 | 1 << 24 | 1 << 25, -1,
            2 | 1 << 3 << 4 | 1 << 12 | 1 << 16 | 1 << 24, -1,
            2 | 1 << 12 | 1 << 24, -1,
        ];
        self::assertSame([...$words, $this->digest($words)], RecaptchaService::assessResponsesPhp($bodies, 0.5, 'login', 'example.com'));
        $this->assertAssessmentParity($bodies, 0.5, 'login', 'example.com');
        $this->assertAssessmentParity($bodies, 0.3, '', '');
        $this->assertAssessmentParity([], 0.5, '', '');
        $this->assertAssessmentParity(["{}\0"], 0.5, '', '');
        $scores = ['0', '1', '-0', '-0.0', '1.0', '1.0000000000000002', '0.30000000000000004', '1e-7', '5E-1', '0.4999999999999999999', '1e400', '-1e-400', '123456789012345678901234567890', '9223372036854775807', 'null', 'true', '[]', '{}', '"0.5"'];
        $this->assertAssessmentParity(array_map(static fn (string $s): string => '{"success":true,"score":'.$s.'}', $scores), 0.5, '', '');
        $this->assertAssessmentParity(array_map(static fn (string $s): string => '{"success":true,"score":'.$s.'}', $scores), NAN, 'x', '');
        $this->assertAssessmentParity(array_map(static fn (string $s): string => '{"success":true,"score":'.$s.'}', $scores), -INF, '', '');
        $this->assertAssessmentParity($this->jsonEdgeCases(), 0.5, 'login', 'a.com');
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertAssessmentParity(...$this->recaptchaBatch(new Randomizer(new Mt19937(4830 + $i)), $uncommon));
            }
        }
    }

    public function test_password_breach_range_parity(): void
    {
        $this->requireBindings();
        $suffix = substr(strtoupper(sha1('password')), 5);
        $body = "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n".strtolower($suffix).":3861493\r\nZZ:1\n003D68EB55068C33ACE09247EE4C639306B:0\n\n".$suffix.':7';
        $words = [3861493 | 1 << 40 | 1 << 41, 0x5BAA6 | 4 << 20 | 1 << 36 | 1 << 52, 0, (int) hexdec(substr(strtoupper(sha1('')), 0, 5))];
        self::assertSame([...$words, $this->digest($words)], PasswordBreachService::lookupBreachCountsPhp(['password', ''], [$body, ''], 1));
        $this->assertBreachParity(['password', ''], [$body, ''], 1);
        $this->assertBreachParity(['password'], [$body], 3861493);
        $this->assertBreachParity(['password'], [$body], -1);
        $this->assertBreachParity([], [], 0);
        $this->assertBreachParity(['a', 'b'], [''], 0);
        $this->assertBreachParity(["a\0b"], [''], 0);
        $this->assertBreachParity(['password', 'password', 'password', 'password', 'password'], [
            $suffix.":\n".$suffix.':12345678901'."\n".$suffix.":99\n",
            " \t".$suffix.":0000000042\x0B\r",
            $suffix.'0:5'."\n".substr($suffix, 1).":5\n".$suffix.": 5\n".$suffix.":5 \n:\n:::\n".$suffix.':5:6',
            str_repeat("\n", 100),
            '5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8:1',
        ], 0);
        $this->assertBreachParity(array_map(static fn (int $n): string => str_repeat('x', $n), [55, 56, 63, 64, 65, 119, 120, 1000]), array_fill(0, 8, ''), 0);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertBreachParity(...$this->breachBatch(new Randomizer(new Mt19937(4840 + $i)), $uncommon));
            }
        }
    }

    public function test_registration_failure_parity(): void
    {
        $this->requireBindings();
        $args = [
            ['10.0.0.1', '10.0.0.1', 'bad', '10.0.0.1'],
            [' User@Example.com ', 'a@@b.com', '', 'x@y.z'],
            ['Captcha Failed', '--weird reason--', '', 'RATE_LIMITED'],
            ['Mozilla/5.0', "curl/8\x01", '', 'Googlebot'],
            [100, 150, 160, 400],
            60,
            2,
        ];
        $words = [
            1 | 1 << 2 | 1 << 3 | 1 << 4 | 3 << 8 | 11 << 20 | 16 << 28, crc32('example.com') | crc32('captcha_failed') << 32, 1 | 3 << 16,
            1 | 1 << 2 | 15 << 4 | 2 << 8 | 1 << 12 | 6 << 20 | 8 << 28 | 1 << 38, crc32('b.com') | crc32('weird_reason') << 32, 2 | 3 << 16,
            0, 0, 0,
            1 | 1 << 2 | 1 << 3 | 8 << 4 | 1 << 8 | 9 << 20 | 5 << 28, crc32('y.z') | crc32('rate_limited') << 32, 1 | 3 << 16,
        ];
        self::assertSame([...$words, $this->digest($words)], RegistrationFailureLogService::summarizeFailuresPhp(...$args));
        $this->assertFailureParity(...$args);
        $this->assertFailureParity([], [], [], [], [], 60, 2);
        $this->assertFailureParity(['1.1.1.1'], [''], [''], [''], [1, 2], 60, 2);
        $this->assertFailureParity(['1.1.1.1'], ["a\0"], [''], [''], [1], 60, 2);
        $this->assertFailureParity(['1.1.1.1', '1.1.1.1'], ['a@b.co', 'c@d.co'], ['x', 'y'], ["\0curl/\0", "\0"], [1, 2], 60, 2);
        $this->assertFailureParity(
            ['::1', '0:0::1', 'fe80::1', '100.64.0.1', '172.32.0.1', '::ffff:10.0.0.1', '10.0.0.1', '2001:db8::1'],
            ['A@B', ' ', 'u@-x..com', 'local part@x.com', str_repeat('a', 65).'@x.com', 'u@x.com.', 'u@.x.com', "u\x7f@x.com"],
            ['  ', '__', 'Ünïcode reason', '123', 'password-breached!!', "proxy\tdetected", 'email_taken_', '_a__b_'],
            [str_repeat("\x01", 300), str_repeat('z', 256), str_repeat('z', 255)."\x7fbot", 'python-requests/2', 'Java/17', 'Mozilla/5.0 (compatible; bingbot/2.0)', "\x00", 'SomeAgent'],
            [PHP_INT_MAX, PHP_INT_MIN, -5, 0, 1 << 41, 1 << 40, 1 << 40, 7],
            PHP_INT_MAX,
            PHP_INT_MIN,
        );
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertFailureParity(...$this->failureBatch(new Randomizer(new Mt19937(4850 + $i)), $uncommon));
            }
        }
    }

    /**
     * Seeded fuzz over truncated and byte-mutated siteverify bodies, which exercises the
     * json_decode() mirror (UTF-8, escapes, surrogates, numbers, depth, duplicate keys).
     */
    public function test_randomized_malformed_json_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(4899));
        $noise = ['{', '}', '[', ']', '"', ',', ':', '\\', '\\u', '\\ud83d', '\\ude00', '\\uDC00', '0', '-', '.', 'e', 'E', '+', ' ', "\t", "\n", "\x01", "\x7f", "\x80", "\xc3\xa9", "\xed\xa0\x80", "\xf4\x90\x80\x80", 'true', 'nul', 'fals'];
        for ($i = 0; $i < 300; $i++) {
            [, $bodies] = $this->turnstileBatch($random, $random->getInt(0, 1) === 1);
            $bodies = \array_slice($bodies, 0, 16);
            foreach ($bodies as &$body) {
                for ($m = $random->getInt(0, 3); $m > 0; $m--) {
                    $at = $random->getInt(0, \strlen($body));
                    $body = match ($random->getInt(0, 2)) {
                        0 => substr($body, 0, $at),
                        1 => substr($body, 0, $at).$this->pick($random, $noise).substr($body, $at),
                        default => substr($body, 0, $at).substr($body, $at + 1),
                    };
                }
            }
            unset($body);
            $bodies = array_map(static fn (string $b): string => str_replace("\0", '', $b), $bodies);
            $this->assertSiteverifyParity(array_fill(0, \count($bodies), 'tok'), $bodies, 'www.example.com', 'login');
            $this->assertAssessmentParity($bodies, 0.5, 'login', 'www.example.com');
        }
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture in random order. Common batches hold 64
     * typical items, Uncommon batches 128 long, escaped, malformed or adversarial ones.
     * Nearest-rank p99. Every pair must agree; raw pairs are written outside the checkout.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(48));
        $summary = $records = [];
        foreach (array_keys(self::STEMS) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $this->toggle($class, $native);
                        $args = $fixtures[$variant];
                        $call = $this->entry($class);
                        $start = hrtime(true);
                        $results[(int) $native] = $call(...$args);
                        $elapsed[(int) $native] = hrtime(true) - $start;
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0], $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(\count($php) * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
            $this->reset($class);
        }
        $artifact = sys_get_temp_dir().'/w481-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'seed' => 48, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW48.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertCount(5, $summary);
    }

    /** @return array<class-string, bool> */
    private function defaults(): array
    {
        return [
            ProxyVerdict::class => ProxyVerdict::W48_AGGREGATE_RUST_FFI_DEFAULT,
            TurnstileService::class => TurnstileService::W48_SITEVERIFY_RUST_FFI_DEFAULT,
            RecaptchaService::class => RecaptchaService::W48_ASSESSMENT_RUST_FFI_DEFAULT,
            PasswordBreachService::class => PasswordBreachService::W48_BREACH_RANGE_RUST_FFI_DEFAULT,
            RegistrationFailureLogService::class => RegistrationFailureLogService::W48_FAILURE_DIGEST_RUST_FFI_DEFAULT,
        ];
    }

    /** @param  key-of<self::STEMS>  $class */
    private function toggle(string $class, ?bool $enabled): void
    {
        match ($class) {
            ProxyVerdict::class => ProxyVerdict::useW48AggregateRustFfi($enabled),
            TurnstileService::class => TurnstileService::useW48SiteverifyRustFfi($enabled),
            RecaptchaService::class => RecaptchaService::useW48AssessmentRustFfi($enabled),
            PasswordBreachService::class => PasswordBreachService::useW48BreachRangeRustFfi($enabled),
            RegistrationFailureLogService::class => RegistrationFailureLogService::useW48FailureDigestRustFfi($enabled),
        };
    }

    /** @param  key-of<self::STEMS>  $class */
    private function uses(string $class): bool
    {
        return match ($class) {
            ProxyVerdict::class => ProxyVerdict::usesW48AggregateRustFfi(),
            TurnstileService::class => TurnstileService::usesW48SiteverifyRustFfi(),
            RecaptchaService::class => RecaptchaService::usesW48AssessmentRustFfi(),
            PasswordBreachService::class => PasswordBreachService::usesW48BreachRangeRustFfi(),
            RegistrationFailureLogService::class => RegistrationFailureLogService::usesW48FailureDigestRustFfi(),
        };
    }

    /** @param  key-of<self::STEMS>  $class */
    private function reset(string $class): void
    {
        match ($class) {
            ProxyVerdict::class => ProxyVerdict::resetW48AggregateRustFfi(),
            TurnstileService::class => TurnstileService::resetW48SiteverifyRustFfi(),
            RecaptchaService::class => RecaptchaService::resetW48AssessmentRustFfi(),
            PasswordBreachService::class => PasswordBreachService::resetW48BreachRangeRustFfi(),
            RegistrationFailureLogService::class => RegistrationFailureLogService::resetW48FailureDigestRustFfi(),
        };
    }

    /** @param  key-of<self::STEMS>  $class */
    private function entry(string $class): Closure
    {
        return match ($class) {
            ProxyVerdict::class => ProxyVerdict::aggregateVerdicts(...),
            TurnstileService::class => TurnstileService::evaluateSiteverify(...),
            RecaptchaService::class => RecaptchaService::assessResponses(...),
            PasswordBreachService::class => PasswordBreachService::lookupBreachCounts(...),
            RegistrationFailureLogService::class => RegistrationFailureLogService::summarizeFailures(...),
        };
    }

    /**
     * @param  key-of<self::STEMS>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            ProxyVerdict::class => $this->verdictBatch($random, $uncommon),
            TurnstileService::class => $this->turnstileBatch($random, $uncommon),
            RecaptchaService::class => $this->recaptchaBatch($random, $uncommon),
            PasswordBreachService::class => $this->breachBatch($random, $uncommon),
            RegistrationFailureLogService::class => $this->failureBatch($random, $uncommon),
        };
    }

    /**
     * Each Wave 48 export must resolve and accept a one-item batch, so parity runs Rust.
     */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            ProxyVerdict::class => ['nntmux_w48_proxy_verdict_aggregate_batch', ['a', 1, 'b', 1, 1, 0], 32],
            TurnstileService::class => ['nntmux_w48_turnstile_siteverify_batch', ['a', 1, 'b', 1, 1, '', 0, '', 0], 24],
            RecaptchaService::class => ['nntmux_w48_recaptcha_assess_batch', ['b', 1, 1, 0.5, '', 0, '', 0], 24],
            PasswordBreachService::class => ['nntmux_w48_password_breach_range_batch', ['a', 1, 'b', 1, 1, 0], 24],
            RegistrationFailureLogService::class => ['nntmux_w48_registration_failure_batch', ['a', 1, 'b', 1, 'c', 1, 'd', 1, pack('V', 1), 4, pack('P', 1), 8, 1, 60, 2], 32],
        ];
        foreach ($calls as $class => [$symbol, $args, $bytes]) {
            $this->reset($class);
            $ffi = (new ReflectionMethod($class, 'w48Ffi'))->invoke(null);
            self::assertInstanceOf(FFI::class, $ffi, $class.' must load the cleaning_w48 binding');
            $out = $ffi->new("char[{$bytes}]");
            self::assertSame($bytes, $this->invoke($ffi, $symbol, [...$args, $out, $bytes]), $symbol);
        }
    }

    /**
     * Calls an export declared by a class's W48_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $specs
     */
    private function assertVerdictParity(array $ips, array $specs, int $threshold): void
    {
        $php = ProxyVerdict::aggregateVerdictsPhp($ips, $specs, $threshold);
        self::assertCount(3 * \count($ips) + 1, $php);
        self::assertSame($php, ProxyVerdict::aggregateVerdictsNative($ips, $specs, $threshold), json_encode([$ips, $specs, $threshold], JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $bodies
     */
    private function assertSiteverifyParity(array $tokens, array $bodies, string $host, string $action): void
    {
        $php = TurnstileService::evaluateSiteverifyPhp($tokens, $bodies, $host, $action);
        self::assertCount(2 * \count($tokens) + 1, $php);
        self::assertSame($php, TurnstileService::evaluateSiteverifyNative($tokens, $bodies, $host, $action), json_encode([$tokens, $bodies], JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /** @param  list<string>  $bodies */
    private function assertAssessmentParity(array $bodies, float $threshold, string $action, string $host): void
    {
        $php = RecaptchaService::assessResponsesPhp($bodies, $threshold, $action, $host);
        self::assertCount(2 * \count($bodies) + 1, $php);
        self::assertSame($php, RecaptchaService::assessResponsesNative($bodies, $threshold, $action, $host), json_encode([$bodies, $threshold], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    /**
     * @param  list<string>  $passwords
     * @param  list<string>  $bodies
     */
    private function assertBreachParity(array $passwords, array $bodies, int $threshold): void
    {
        $php = PasswordBreachService::lookupBreachCountsPhp($passwords, $bodies, $threshold);
        self::assertCount(2 * \count($passwords) + 1, $php);
        self::assertSame($php, PasswordBreachService::lookupBreachCountsNative($passwords, $bodies, $threshold), json_encode([$passwords, $threshold], JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $emails
     * @param  list<string>  $reasons
     * @param  list<string>  $agents
     * @param  list<int>  $stamps
     */
    private function assertFailureParity(array $ips, array $emails, array $reasons, array $agents, array $stamps, int $window, int $limit): void
    {
        $php = RegistrationFailureLogService::summarizeFailuresPhp($ips, $emails, $reasons, $agents, $stamps, $window, $limit);
        self::assertCount(3 * \count($ips) + 1, $php);
        self::assertSame($php, RegistrationFailureLogService::summarizeFailuresNative($ips, $emails, $reasons, $agents, $stamps, $window, $limit), json_encode([$ips, $emails, $reasons, $agents, $stamps, $window, $limit], JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /**
     * Hand-picked json_decode() edge cases: depth limits, escapes, surrogates, UTF-8 and
     * duplicate keys at the top level and inside `error-codes`.
     *
     * @return list<string>
     */
    private function jsonEdgeCases(): array
    {
        $deep = static fn (int $n): string => '{"success":true,"m":'.str_repeat('[', $n).str_repeat(']', $n).'}';

        return [
            '', ' ', 'null', 'true', '1', '"x"', '[]', '{}', '[1,2]', '{"success":true,}', '{"success":true}x', "\xEF\xBB\xBF{}",
            $deep(510), $deep(511), str_repeat('[', 511).str_repeat(']', 511), str_repeat('[', 512).str_repeat(']', 512),
            '{"success":true,"success":false}', '{"success":false,"success":true}', '{"success":1}', '{"success":"true"}', '{"success":TRUE}',
            '{"suc\u0063ess":true,"host\u006eame":"A.COM","action":"log\u0069n"}', '{"success":true,"hostname":"\u0041.com"}',
            '{"success":true,"hostname":null,"action":["login"]}', '{"success":true,"hostname":"a.com","hostname":7}',
            '{"error-codes":{"a":"bad-request","a":1,"b":"timeout-or-duplicate","c":"x"},"success":false}',
            '{"error-codes":"bad-request"}', '{"error-codes":[["bad-request"],{"x":"bad-request"},"bad-request","bad-request"]}',
            '{"error-codes":["bad-request"],"error-codes":[]}', '{"error\u002dcodes":["internal-error"]}',
            '["\ud83d\ude00"]', '["\ud83d"]', '["\ude00"]', '["\ud83d\u0041"]', '["\uD83D\uDE00"]', '["\u00"]', '["\x"]', "[\"\x01\"]", "[\"\x7f\"]",
            "[\"\xc3\xa9\"]", "[\"\xc3\"]", "[\"\xed\xa0\x80\"]", "[\"\xf4\x90\x80\x80\"]", "[\"\xe0\x80\xaf\"]", "\xc3\xa9",
            '[01]', '[1.]', '[.5]', '[-]', '[1e]', '[1e+]', '[-0]', '[1E+2]', '[0.5e-3]', '[1,]', '[,1]', '{"a"}', '{"a":}', '{1:2}', "[1]\n\t\r ",
            '{"success":true,"hostname":"a.com","action":"login","score":0.7}', '{"success":true,"score":0.7,"score":0.1}',
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function noise(Randomizer $random, string $alphabet, int $max): string
    {
        $length = $random->getInt(0, $max);

        return $length === 0 ? '' : $random->getBytesFromString($alphabet, $length);
    }

    private function v6(Randomizer $random): string
    {
        return $this->pick($random, ['2400:cb00', '2606:4700', '2a06:98c0', '2001:db8', 'fe80', 'fd12']).':'.dechex($random->getInt(0, 65535)).'::'.dechex($random->getInt(1, 65535));
    }

    private function ipish(Randomizer $random): string
    {
        return match ($random->getInt(0, 3)) {
            0 => implode('.', [$random->getInt(0, 300), $random->getInt(0, 300), $random->getInt(0, 300), $random->getInt(0, 300)]),
            1 => $this->noise($random, '0123456789abcdefABCDEF:.', 22),
            2 => $this->v6($random),
            default => $this->pick($random, ['::1', '::', 'fe80::1', '::ffff:1.2.3.4', '1.2.3.4 ', '01.2.3.4', '', 'localhost']),
        };
    }

    /** @return array{list<string>, list<string>, int} */
    private function verdictBatch(Randomizer $random, bool $uncommon): array
    {
        $ips = $specs = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            if ($uncommon) {
                $ips[] = $random->getInt(0, 2) === 0 ? $this->ipish($random) : $this->v6($random);
                $parts = [];
                for ($k = $random->getInt(5, 40); $k > 0; $k--) {
                    $name = $this->pick($random, self::REASON_NAMES);
                    $parts[] = $this->pick($random, [
                        $name.':'.$random->getInt(-9999, 9999), $name.':'.$random->getInt(0, 99), ' '.$name.' : 5', 'x', '', $name.':',
                        'UPPER:5', $name.':+5', str_repeat('n', $random->getInt(30, 34)).':1', $name.':12345', "\t".$name.":-0012\n", $random->getInt(0, 999).':3',
                    ]);
                }
                $specs[] = implode($this->pick($random, [',', ', ', ',,']), $parts);
            } else {
                $ips[] = $this->pick($random, ['198.51.100.', '203.0.113.', '192.0.2.', '10.1.2.']).$random->getInt(1, 254);
                $parts = [];
                for ($k = $random->getInt(1, 5); $k > 0; $k--) {
                    $parts[] = $this->pick($random, self::REASON_NAMES).':'.$random->getInt(0, 60);
                }
                $specs[] = implode(',', $parts);
            }
        }

        return [$ips, $specs, $uncommon ? 75 : 50];
    }

    private function token(Randomizer $random, int $min, int $max): string
    {
        return '0.'.$random->getBytesFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', $random->getInt($min, $max)).'.'.bin2hex($random->getBytes(16));
    }

    /**
     * A siteverify body with optional score; uncommon bodies vary encoding and shape.
     */
    private function siteverifyBody(Randomizer $random, bool $uncommon, bool $scored): string
    {
        $success = $random->getInt(0, 4) !== 0;
        $result = [
            'success' => $success,
            'challenge_ts' => gmdate('Y-m-d\TH:i:s.000\Z', $random->getInt(1600000000, 1800000000)),
            'hostname' => $this->pick($random, ['www.example.com', 'www.example.com', 'WWW.Example.COM', 'evil.example.net']),
            'error-codes' => $success ? [] : [$this->pick($random, ['timeout-or-duplicate', 'invalid-input-response', 'internal-error'])],
            'action' => $this->pick($random, ['login', 'login', 'register']),
        ];
        if ($scored) {
            $result['score'] = $random->getInt(0, 10) / 10;
        } else {
            $result['cdata'] = '';
            $result['metadata'] = ['interactive' => $random->getInt(0, 1) === 1];
        }
        if (! $uncommon) {
            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $result['cdata'] = 'sess/'.str_repeat('é✓', $random->getInt(10, 200)).'"\\'.$this->noise($random, "abc\t\n", 40);
        $nested = ['k' => [1, 2.5e3, -0.0, null, true]];
        for ($d = $random->getInt(1, 60); $d > 0; $d--) {
            $nested = [$nested];
        }
        $result['metadata'] = ['nested' => $nested, 'ip' => $this->v6($random)];
        if ($scored) {
            $result['score'] = $this->pick($random, ['0.1', '0.9', '1', '0', '1.5', 'x']) === 'x' ? 'x' : $random->getInt(0, 1000) / 997;
        }
        if ($random->getInt(0, 3) === 0) {
            $result['error-codes'] = ['a' => 'bad-request', 'b' => 7, 'c' => $this->noise($random, 'xyz', 8)];
        }
        $body = json_encode($result, ($random->getInt(0, 1) === 1 ? JSON_PRETTY_PRINT : 0) | JSON_THROW_ON_ERROR);

        return match ($random->getInt(0, 9)) {
            0 => substr($body, 0, $random->getInt(0, \strlen($body))),
            1 => '<html><body>502 Bad Gateway</body></html>',
            2 => '['.$body.']',
            3 => substr($body, 0, -1).',"success":'.$this->pick($random, ['true', 'false', '"true"', 'null']).',"hostname":"www.example.com"}',
            4 => str_repeat(' ', $random->getInt(0, 9)).$body."\n",
            default => $body,
        };
    }

    /** @return array{list<string>, list<string>, string, string} */
    private function turnstileBatch(Randomizer $random, bool $uncommon): array
    {
        $tokens = $bodies = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $tokens[] = ! $uncommon ? $this->token($random, 300, 700) : match ($random->getInt(0, 4)) {
                0 => '',
                1 => $this->token($random, 2040, 2100),
                2 => $this->token($random, 10, 100).$this->pick($random, [' ', '+', '/', '=', "\x80"]),
                default => $this->token($random, 100, 1500),
            };
            $bodies[] = $this->siteverifyBody($random, $uncommon, false);
        }

        return [$tokens, $bodies, 'www.example.com', $uncommon ? '' : 'login'];
    }

    /** @return array{list<string>, float, string, string} */
    private function recaptchaBatch(Randomizer $random, bool $uncommon): array
    {
        $bodies = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $bodies[] = $this->siteverifyBody($random, $uncommon, ! $uncommon || $random->getInt(0, 2) !== 0);
        }

        return [$bodies, 0.5, 'login', $uncommon ? 'www.example.com' : ''];
    }

    private function hashSuffix(Randomizer $random): string
    {
        return substr(strtoupper(bin2hex($random->getBytes(18))), 0, 35);
    }

    /** @return array{list<string>, list<string>, int} */
    private function breachBatch(Randomizer $random, bool $uncommon): array
    {
        $passwords = $bodies = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $password = $uncommon
                ? $this->pick($random, ['', str_repeat('x', $random->getInt(50, 200)), "pässwörd\x01\xff", 'password'.$random->getInt(0, 99)])
                : $this->pick($random, ['Summer', 'Winter', 'Hunter', 'Dragon', 'Correct-Horse']).$random->getInt(1, 9999).$this->pick($random, ['!', '', '#', '2024']);
            $suffix = substr(strtoupper(sha1($password)), 5);
            $lines = [];
            for ($n = $uncommon ? $random->getInt(0, 600) : $random->getInt(200, 400); $n > 0; $n--) {
                $lines[] = $this->hashSuffix($random).':'.$random->getInt(1, 50000);
            }
            if ($random->getInt(0, 2) === 0) {
                $lines[$random->getInt(0, max(0, \count($lines) - 1))] = $suffix.':'.$random->getInt(1, 5000000);
            }
            if ($uncommon) {
                foreach ($lines as &$line) {
                    $line = match ($random->getInt(0, 9)) {
                        0 => strtolower($line),
                        1 => $this->hashSuffix($random).':0',
                        2 => $this->pick($random, ['', ' ', 'garbage', substr($line, 1), $line.'x', ':12', $line.':9']),
                        3 => " \t".$line." \x0B",
                        default => $line,
                    };
                }
                unset($line);
                $bodies[] = implode($this->pick($random, ["\r\n", "\n", "\n\n"]), $lines);
            } else {
                $bodies[] = implode("\r\n", $lines);
            }
            $passwords[] = $password;
        }

        return [$passwords, $bodies, $uncommon ? $random->getInt(0, 10) : 0];
    }

    /** @return array{list<string>, list<string>, list<string>, list<string>, list<int>, int, int} */
    private function failureBatch(Randomizer $random, bool $uncommon): array
    {
        $ips = $emails = $reasons = $agents = $stamps = [];
        $browsers = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
        ];
        $known = ['Captcha failed', 'captcha_failed', 'Email taken', 'Invalid invite', 'Disposable email', 'Proxy detected', 'rate-limited', 'Validation failed'];
        $stamp = 1700000000;
        $pool = $uncommon ? ['203.0.113.7', '203.0.113.7', '2001:db8::5', '10.0.0.3'] : array_map(static fn (int $n): string => '198.51.100.'.$n, range(1, 24));
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $stamp += $random->getInt(0, $uncommon ? 5 : 40);
            if ($uncommon) {
                $ips[] = $random->getInt(0, 4) === 0 ? $this->ipish($random) : $this->pick($random, $pool);
                $emails[] = $this->pick($random, ['A@B', ' ', 'x@@y.com', 'local part@x.com', str_repeat('a', 70).'@x.com', 'u@-x..com', " MiXeD@Example.ORG\t", 'u@'.str_repeat('d', 250).'.com', "u\x01@x.com", 'ünï@çödé.com', 'user'.$random->getInt(1, 999).'@mail.example.net']);
                $reasons[] = $random->getInt(0, 2) === 0 ? $this->pick($random, $known) : $this->noise($random, "abcAB_- \t\x80!", 40);
                $agents[] = match ($random->getInt(0, 4)) {
                    0 => str_repeat($this->pick($random, $browsers), $random->getInt(2, 6)),
                    1 => "curl/8.4\x01\x02\x7f".$this->noise($random, "\x00\x1f ab", 30),
                    2 => $this->pick($random, ['Googlebot/2.1', 'python-requests/2.32', 'Go-http-client/2.0', 'okhttp/4.12', '', 'Java/21']),
                    default => $this->noise($random, "abcABC/.;() \x09", 300),
                };
                $stamps[] = $random->getInt(0, 9) === 0 ? [-1, 0, PHP_INT_MAX, 1 << 41][$random->getInt(0, 3)] : ($random->getInt(0, 3) === 0 ? $stamp - $random->getInt(0, 600) : $stamp);
            } else {
                $ips[] = $this->pick($random, $pool);
                $emails[] = 'user'.$random->getInt(1, 99999).'@'.$this->pick($random, ['gmail.com', 'example.org', 'mail.example.net', 'outlook.com']);
                $reasons[] = $this->pick($random, $known);
                $agents[] = $this->pick($random, $browsers);
                $stamps[] = $stamp;
            }
        }

        return [$ips, $emails, $reasons, $agents, $stamps, $uncommon ? $random->getInt(0, 1000) : 300, $uncommon ? 3 : 5];
    }
}
