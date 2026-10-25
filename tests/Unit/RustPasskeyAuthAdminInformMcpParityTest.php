<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Passkeys\FindPasskeyToAuthenticateAction;
use App\Http\Controllers\Admin\AdminSiteController;
use App\Http\Controllers\AjaxController;
use App\Http\Controllers\Api\ApiInformController;
use App\Mcp\Servers\NewznabTmuxServer;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib, PHP 8.5 on Apple Silicon Darwin. Measured run:
 *
 * - Common (all five helpers, typical inputs): PHP mean 0.87 us, Rust mean 7.00 us -> 0.12x; p99 0.19x.
 * - Uncommon (1023-byte credential, padded hosts, blank messages, saturating counts): mean 0.16x; p99 0.18x
 *   (PHP 4.09 us / Rust 22.62 us).
 *
 * Packet marshaling, FFI buffer allocation and decoding dominate these sub-microsecond helpers, so
 * Rust misses the 1.5x gate on both the common mean and the uncommon p99 and every helper keeps PHP
 * as the default (`usesRustFfi() === false`). Each run prints its ratios to STDERR.
 */
class RustPasskeyAuthAdminInformMcpParityTest extends TestCase
{
    private const CDEF = 'ptrdiff_t nntmux_w33_passkey_auth_admin_inform_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    private const CLASSES = [
        FindPasskeyToAuthenticateAction::class,
        AdminSiteController::class,
        AjaxController::class,
        ApiInformController::class,
        NewznabTmuxServer::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            (new ReflectionClass($class))->setStaticPropertyValue('ffi', null);
        }

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function credentialProvider(): array
    {
        return [
            'empty' => [''],
            'one byte' => ['f'],
            'two bytes' => ['fo'],
            'three bytes' => ['foo'],
            'url-unsafe alphabet' => ["\xfb\xff\xfe\x00\x3e\x3f"],
            'fifteen bytes' => [str_repeat("\x01", 15)],
            'sixteen bytes' => [str_repeat("\xab", 16)],
            'typical 32 random' => [hex2bin('8f3a1c9e0b7d4f2a6e5c3b1a09f8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6') ?: ''],
            'max 1023' => [str_repeat("\x7f", 1023)],
            'over 1024' => [str_repeat("\x00", 1024)],
            'nul and high bytes' => ["\0\0\xff\xfe\x80 abc"],
        ];
    }

    #[DataProvider('credentialProvider')]
    public function test_credential_lookup_id_parity(string $raw): void
    {
        $this->requireFfi();
        $php = FindPasskeyToAuthenticateAction::normalizeCredentialLookupIdPhp($raw);

        $this->assertSame(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), $php['credential_id']);
        $this->assertSame(strlen($raw), $php['byte_length']);
        $this->assertSame(strlen($raw) >= 16 && strlen($raw) <= 1023, $php['valid']);
        $this->assertSame($php, FindPasskeyToAuthenticateAction::normalizeCredentialLookupIdNative($raw));
        FindPasskeyToAuthenticateAction::setUseRustFfi(true);
        $this->assertSame($php, FindPasskeyToAuthenticateAction::normalizeCredentialLookupId($raw));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function nntpProvider(): array
    {
        return [
            'secure host' => ['news.example.com', '1', true, 'secure'],
            'plaintext host' => ['news.example.com', '0', false, 'plaintext'],
            'mixed case padded' => ["  News.Example.COM \n", ' YES ', true, 'secure'],
            'blank disabled' => ["\t\0\x0B ", 'on', false, 'disabled'],
            'empty disabled' => ['', '', true, 'disabled'],
            'localhost' => ['LocalHost', 'true', false, 'local'],
            'loopback v4' => ['127.0.0.1', '', true, 'local'],
            'loopback v6' => ['::1', 'TRUE', false, 'local'],
            'bracketed v6' => ['[::1]', 'nope', true, 'local'],
            'compression words off' => ['h', 'enabled', true, 'secure'],
            'non ascii host' => ["\xc3\x89H.example", '', false, 'plaintext'],
            'non-breaking space kept' => ["\xc2\xa0host", '1', false, 'plaintext'],
        ];
    }

    #[DataProvider('nntpProvider')]
    public function test_alternate_nntp_classification_parity(string $host, string $compression, bool $ssl, string $classification): void
    {
        $this->requireFfi();
        $php = AdminSiteController::classifyAlternateNntpConfigurationPhp($host, $compression, $ssl);

        $this->assertSame($classification, $php['classification']);
        $this->assertSame($ssl ? 563 : 119, $php['default_port']);
        $this->assertSame($ssl ? 'ssl' : 'plain', $php['transport']);
        $this->assertSame($php, AdminSiteController::classifyAlternateNntpConfigurationNative($host, $compression, $ssl));
        AdminSiteController::setUseRustFfi(true);
        $this->assertSame($php, AdminSiteController::classifyAlternateNntpConfiguration($host, $compression, $ssl));
    }

    public function test_alternate_nntp_classification_values(): void
    {
        $this->assertSame([
            'configured' => true,
            'host' => 'news.example.com',
            'transport' => 'ssl',
            'default_port' => 563,
            'compression' => true,
            'classification' => 'secure',
        ], AdminSiteController::classifyAlternateNntpConfigurationPhp('  News.Example.COM ', ' Yes', true));
        $this->assertSame("\xc3\x89h", AdminSiteController::classifyAlternateNntpConfigurationPhp("\xc3\x89H", '', true)['host']);
    }

    public function test_profile_action_response_parity_and_existing_messages(): void
    {
        $this->requireFfi();
        $link = 'Invite sent. Alternatively paste them following link to register - https://nn.test/register?invitecode=abc';
        $cases = [
            [true, $link, ''],
            [false, '', 'Invite not sent.'],
            [false, 'ignored', 'Invite not sent.'],
            [false, 'x', " \n"],
            [true, '', 'x'],
            [true, "\t\0", ''],
            [true, " keep \n", ''],
            [false, '', ''],
            [true, "\xff\xfe binary", ''],
        ];
        foreach ($cases as [$ok, $success, $error]) {
            $php = AjaxController::normalizeProfileActionResponsePhp($ok, $success, $error);
            $this->assertSame($ok, $php['success']);
            $this->assertSame($ok ? 'success' : 'error', $php['status']);
            $this->assertSame($php, AjaxController::normalizeProfileActionResponseNative($ok, $success, $error));
            AjaxController::setUseRustFfi(true);
            $this->assertSame($php, AjaxController::normalizeProfileActionResponse($ok, $success, $error));
            AjaxController::setUseRustFfi(null);
        }

        // Messages previously echoed by AjaxController::profile() pass through verbatim.
        $this->assertSame($link, AjaxController::normalizeProfileActionResponse(true, $link)['message']);
        $this->assertSame('Invite not sent.', AjaxController::normalizeProfileActionResponse(false, '', 'Invite not sent.')['message']);
        $this->assertSame('Request failed.', AjaxController::normalizeProfileActionResponsePhp(false, 'x')['message']);
        $this->assertSame('Request completed.', AjaxController::normalizeProfileActionResponsePhp(true, ' ')['message']);
        $this->assertSame(' keep ', AjaxController::normalizeProfileActionResponsePhp(true, ' keep ')['message']);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string}>
     */
    public static function informProvider(): array
    {
        return [
            'complete' => ['Old.Name-GRP', 'New.Name.1080p-GRP', 'abc123'],
            'nulls' => [null, null, null],
            'empty strings' => ['', '', ''],
            'zero strings' => ['0', '0', '0'],
            'mixed zero' => ['0', 'New', 'tok'],
            'double zero kept' => ['00', '0.0', ' 0'],
            'whitespace kept' => [' ', "\t", "\n"],
            'null token' => ['a', 'b', null],
            'binary' => ["\0", "\xff", "tok\0"],
        ];
    }

    #[DataProvider('informProvider')]
    public function test_inform_request_params_parity(?string $relO, ?string $relP, ?string $token): void
    {
        $this->requireFfi();
        $php = ApiInformController::normalizeInformRequestParamsPhp($relO, $relP, $token);

        // Same semantics as the original `has() && ! empty(input()) ? input() : ''` expressions.
        $legacy = static fn (?string $value): string => ! empty($value) ? $value : '';
        $this->assertSame($legacy($relO), $php['relo']);
        $this->assertSame($legacy($relP), $php['relp']);
        $this->assertSame($legacy($token), $php['api_token']);
        $this->assertSame(! empty($legacy($relO)) && ! empty($legacy($relP)) && ! empty($legacy($token)), $php['complete']);
        $this->assertSame($php, ApiInformController::normalizeInformRequestParamsNative($relO, $relP, $token));
        ApiInformController::setUseRustFfi(true);
        $this->assertSame($php, ApiInformController::normalizeInformRequestParams($relO, $relP, $token));
    }

    public function test_capability_summary_parity(): void
    {
        $this->requireFfi();
        $cases = [[5, 2, 2], [0, 0, 0], [1, 1, 1], [1, 0, 2], [-3, 0, PHP_INT_MIN], [PHP_INT_MAX, PHP_INT_MAX, 1], [PHP_INT_MAX, 0, 0], [12, -1, 7]];
        foreach ($cases as [$t, $r, $p]) {
            $php = NewznabTmuxServer::summarizeRegisteredCapabilitiesPhp($t, $r, $p);
            $this->assertSame($php, NewznabTmuxServer::summarizeRegisteredCapabilitiesNative($t, $r, $p));
            NewznabTmuxServer::setUseRustFfi(true);
            $this->assertSame($php, NewznabTmuxServer::summarizeRegisteredCapabilities($t, $r, $p));
            NewznabTmuxServer::setUseRustFfi(null);
        }

        $this->assertSame([
            'tools' => 5,
            'resources' => 2,
            'prompts' => 2,
            'total' => 9,
            'empty' => false,
            'summary' => '5 tools, 2 resources, 2 prompts',
        ], NewznabTmuxServer::summarizeRegisteredCapabilitiesPhp(5, 2, 2));
        $this->assertSame('1 tool, 1 resource, 0 prompts', NewznabTmuxServer::summarizeRegisteredCapabilitiesPhp(1, 1, -4)['summary']);
        $this->assertSame(PHP_INT_MAX, NewznabTmuxServer::summarizeRegisteredCapabilitiesPhp(PHP_INT_MAX, 1, 1)['total']);
        $this->assertTrue(NewznabTmuxServer::summarizeRegisteredCapabilitiesPhp(-1, 0, 0)['empty']);
    }

    public function test_registered_capability_summary_counts_server_arrays(): void
    {
        $server = (new ReflectionClass(NewznabTmuxServer::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(NewznabTmuxServer::class);
        $counts = array_map(
            static fn (string $property): int => count($reflection->getProperty($property)->getValue($server)),
            ['tools', 'resources', 'prompts'],
        );

        $summary = $server->registeredCapabilitySummary();
        $this->assertSame($counts, [$summary['tools'], $summary['resources'], $summary['prompts']]);
        $this->assertSame(array_sum($counts), $summary['total']);
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        foreach (self::CLASSES as $class) {
            (new ReflectionClass($class))->setStaticPropertyValue('ffi', false);
        }

        $this->assertSame(FindPasskeyToAuthenticateAction::normalizeCredentialLookupIdPhp('abc'), FindPasskeyToAuthenticateAction::normalizeCredentialLookupIdNative('abc'));
        $this->assertSame(AdminSiteController::classifyAlternateNntpConfigurationPhp(' H ', 'on', true), AdminSiteController::classifyAlternateNntpConfigurationNative(' H ', 'on', true));
        $this->assertSame(AjaxController::normalizeProfileActionResponsePhp(false, '', ''), AjaxController::normalizeProfileActionResponseNative(false, '', ''));
        $this->assertSame(ApiInformController::normalizeInformRequestParamsPhp('0', 'b', null), ApiInformController::normalizeInformRequestParamsNative('0', 'b', null));
        $this->assertSame(NewznabTmuxServer::summarizeRegisteredCapabilitiesPhp(1, 2, 3), NewznabTmuxServer::summarizeRegisteredCapabilitiesNative(1, 2, 3));
    }

    public function test_direct_c_abi_declines_malformed_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['5', '2', '2']);
        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w33_passkey_auth_admin_inform_mcp($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound

        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(5, $valid, strlen($valid), $buf, 256);
        $this->assertGreaterThan(8, $written);
        $this->assertSame(-$written, $call(5, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(5, $valid, strlen($valid), null, 0));
        $this->assertSame(-1, $call(5, null, 4, $buf, 256));
        $this->assertSame(-1, $call(5, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(0, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(6, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(5, '', 0, $small, 8));
        $this->assertSame(-1, $call(5, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(5, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(5, $valid."\0", strlen($valid) + 1, $small, 8));
        $this->assertSame(-1, $callParts(5, ['+1', '0', '0']));
        $this->assertSame(-1, $callParts(5, ['1', '0']));
        $this->assertSame(-1, $callParts(1, []));
        $this->assertSame(-1, $callParts(2, ['h', '', '2']));
        $this->assertSame(-1, $callParts(3, ['true', '', '']));
        $this->assertSame(-1, $callParts(4, ['a', 'b']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $credential = hex2bin('8f3a1c9e0b7d4f2a6e5c3b1a09f8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6') ?: '';
        $common = static function () use ($credential): void {
            FindPasskeyToAuthenticateAction::normalizeCredentialLookupId($credential);
            AdminSiteController::classifyAlternateNntpConfiguration('news.example.com', '1', true);
            AjaxController::normalizeProfileActionResponse(false, '', 'Invite not sent.');
            ApiInformController::normalizeInformRequestParams('Old.Name-GRP', 'New.Name.1080p-GRP', 'abc123');
            NewznabTmuxServer::summarizeRegisteredCapabilities(5, 2, 2);
        };
        $longCredential = str_repeat("\xfb\xff\x3e", 341);
        $longLink = 'Invite sent. Alternatively paste them following link to register - https://nn.test/register?invitecode='.str_repeat('a', 512);
        $uncommon = static function () use ($longCredential, $longLink): void {
            FindPasskeyToAuthenticateAction::normalizeCredentialLookupId($longCredential);
            AdminSiteController::classifyAlternateNntpConfiguration("  \t[::1] \n", ' TRUE ', false);
            AjaxController::normalizeProfileActionResponse(true, $longLink);
            AjaxController::normalizeProfileActionResponse(false, '', " \n");
            ApiInformController::normalizeInformRequestParams('0', null, str_repeat('t', 64));
            NewznabTmuxServer::summarizeRegisteredCapabilities(PHP_INT_MAX, PHP_INT_MAX, -1);
        };

        $setAll = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
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
            $class::setUseRustFfi(null);
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 33.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
            $results['common']['mean'],
            $results['common']['php_mean_us'],
            $results['common']['rust_mean_us'],
            $results['common']['p99'],
            $results['uncommon']['mean'],
            $results['uncommon']['p99'],
            $results['uncommon']['php_p99_us'],
            $results['uncommon']['rust_p99_us'],
        ));

        $qualifies = $results['common']['mean'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach (self::CLASSES as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesRustFfi());
            }
        }
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
        $ffi = RustPipelineLibrary::resolveDomain('search', self::CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
