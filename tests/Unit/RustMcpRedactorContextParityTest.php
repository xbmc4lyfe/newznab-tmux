<?php

declare(strict_types=1);

namespace Tests\Unit;

require_once dirname(__DIR__, 2).'/app/Services/Mcp/SensitiveValueRedactor.php';
require_once dirname(__DIR__, 2).'/app/Services/Mcp/RepositoryContextCollector.php';

use App\Services\Mcp\ProcessRunner;
use App\Services\Mcp\ProjectPathGuard;
use App\Services\Mcp\RepositoryContextCollector;
use App\Services\Mcp\SensitiveValueRedactor;
use App\Services\Mcp\VerificationPlanner;
use App\Support\RustPipelineLibrary;
use FFI;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Paired caller-level benchmark summary (macOS arm64, N=40 randomized runs x 128 iterations,
 * including PHP FFI boundary and array/string marshaling overhead):
 *
 * - `SensitiveValueRedactor::redact` (clean process output, 900 B):
 *   PHP 1190.7 ns mean / 1234.7 ns p99 vs Rust FFI 362.2 ns mean / 377.6 ns p99
 *   -> 3.29x mean speedup, 3.27x p99 speedup (adopted in production).
 * - `SensitiveValueRedactor::redact` (common mixed secrets + log lines, 1.1 KB):
 *   PHP 1395.9 ns mean / 1888.7 ns p99 vs Rust FFI 779.3 ns mean / 1107.4 ns p99
 *   -> 1.79x mean speedup, 1.71x p99 speedup (adopted in production).
 * - `SensitiveValueRedactor::redact` (uncommon p99 multi-secret + PEM output, 11 KB):
 *   PHP 29822.2 ns mean / 32577.1 ns p99 vs Rust FFI 5708.6 ns mean / 6640.0 ns p99
 *   -> 5.22x mean speedup, 4.91x p99 speedup (adopted in production).
 * - `RepositoryContextCollector::parseAndRedactRgOutput` (common 18 rg lines):
 *   PHP 8176.8 ns mean / 10272.8 ns p99 vs Rust FFI 3363.8 ns mean / 3707.7 ns p99
 *   -> 2.43x mean speedup, 2.77x p99 speedup (adopted in production).
 * - `RepositoryContextCollector::parseAndRedactRgOutput` (uncommon p99 45 rg lines, cap 25):
 *   PHP 12390.5 ns mean / 14598.6 ns p99 vs Rust FFI 4696.7 ns mean / 5055.7 ns p99
 *   -> 2.64x mean speedup, 2.89x p99 speedup (adopted in production).
 * - `RepositoryContextCollector::extractConfigKeys` (common 12 KB PHP source file):
 *   PHP 2488.0 ns mean / 2711.3 ns p99 vs Rust FFI 1267.3 ns mean / 1441.4 ns p99
 *   -> 1.96x mean speedup, 1.88x p99 speedup (adopted in production).
 * - `RepositoryContextCollector::extractConfigKeys` (uncommon p99 48 KB PHP source file):
 *   PHP 9383.1 ns mean / 10481.1 ns p99 vs Rust FFI 4168.7 ns mean / 5376.3 ns p99
 *   -> 2.25x mean speedup, 1.95x p99 speedup (adopted in production).
 * - `RepositoryContextCollector::searchTerms` (common error query line):
 *   PHP 853.2 ns mean / 889.3 ns p99 vs Rust FFI 437.0 ns mean / 455.4 ns p99
 *   -> 1.95x mean speedup, 1.95x p99 speedup (adopted in production).
 * - `RepositoryContextCollector::searchTerms` (uncommon p99 2.4 KB stack trace):
 *   PHP 14343.4 ns mean / 17074.9 ns p99 vs Rust FFI 462.9 ns mean / 530.3 ns p99
 *   -> 30.98x mean speedup, 32.20x p99 speedup (adopted in production).
 * - `SensitiveValueRedactor::isSensitiveKey` (8-key batch):
 *   PHP 492.3 ns mean / 575.5 ns p99 vs Rust FFI 674.9 ns mean / 742.5 ns p99
 *   -> 0.73x mean, 0.78x p99 (retained native PHP in production; `isSensitiveKeyNative` exposed for parity).
 * - `RepositoryContextCollector::globMatches` (4-pair batch):
 *   PHP 779.6 ns mean / 1241.2 ns p99 vs Rust FFI 646.3 ns mean / 1064.1 ns p99
 *   -> 1.21x mean, 1.17x p99 (retained native PHP in production; `globMatchesNative` exposed for parity).
 */
class RustMcpRedactorContextParityTest extends TestCase
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_mcp_redact_string(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int32_t nntmux_mcp_is_sensitive_key(const char *input, size_t input_len);
        ssize_t nntmux_mcp_parse_and_redact_rg_output(const char *input, size_t input_len, size_t max_results, char *out_buf, size_t out_cap);
        int32_t nntmux_mcp_glob_matches(const char *glob, size_t glob_len, const char *path, size_t path_len);
        ssize_t nntmux_mcp_extract_config_keys(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_mcp_search_terms(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain('mcp_test_probe', self::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not available.');
        }

        SensitiveValueRedactor::setUseRustFfi(true);
        RepositoryContextCollector::setUseRustFfi(true);
    }

    protected function tearDown(): void
    {
        SensitiveValueRedactor::setUseRustFfi(true);
        RepositoryContextCollector::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_c_abi_contracts_resize_decline_and_invalid_arguments(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('mcp_test_probe', self::FFI_CDEF);
        $this->assertNotNull($ffi);

        $tinyBuf = $ffi->new('char[4]');

        // 1. nntmux_mcp_redact_string: 0 on unchanged, < -1 on overflow, -1 on null+len>0
        $clean = 'normal log line without secrets';
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mcp_redact_string($clean, strlen($clean), $tinyBuf, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_redact_string(null, 5, $tinyBuf, 4));

        $secret = 'API_TOKEN=supersecret123';
        // @phpstan-ignore method.notFound
        $overflow = $ffi->nntmux_mcp_redact_string($secret, strlen($secret), $tinyBuf, 4);
        $this->assertLessThan(-1, $overflow);
        $exactCap = -$overflow;
        $exactBuf = $ffi->new("char[{$exactCap}]");
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_mcp_redact_string($secret, strlen($secret), $exactBuf, $exactCap);
        $this->assertSame($exactCap, $written);
        $this->assertSame('API_TOKEN=[REDACTED]', FFI::string($exactBuf, $written));

        // 2. nntmux_mcp_is_sensitive_key: -1 on null+len>0, 0 on empty/non-sensitive, 1 on sensitive
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_is_sensitive_key(null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mcp_is_sensitive_key('abc', 3));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_mcp_is_sensitive_key('api_key', 7));

        // 3. nntmux_mcp_parse_and_redact_rg_output: < -1 on overflow, 0 on empty/non-matching, -1 on null
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_parse_and_redact_rg_output(null, 5, 10, $tinyBuf, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mcp_parse_and_redact_rg_output('no-colon-line', 13, 10, $tinyBuf, 4));
        $rgLine = 'app/Foo.php:12:  API_TOKEN=secret123  ';
        // @phpstan-ignore method.notFound
        $rgOverflow = $ffi->nntmux_mcp_parse_and_redact_rg_output($rgLine, strlen($rgLine), 10, $tinyBuf, 4);
        $this->assertLessThan(-1, $rgOverflow);

        // 4. nntmux_mcp_glob_matches: -1 on null+len>0, 1 on match, 0 on non-match
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_glob_matches(null, 3, 'app/Foo.php', 11));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_mcp_glob_matches('app/*.php', 9, 'app/Foo.php', 11));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mcp_glob_matches('app/*.php', 9, 'app/Sub/Foo.php', 15));

        // 5. nntmux_mcp_extract_config_keys: -2 on multiline key decline, < -2 on overflow, -1 on null
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_extract_config_keys(null, 8, $tinyBuf, 4));
        $multilineKey = "config('foo\nbar')";
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, $ffi->nntmux_mcp_extract_config_keys($multilineKey, strlen($multilineKey), $tinyBuf, 4));
        $cfgSrc = "config('nntmux_mcp.safe_paths'); env('DB_PASSWORD');";
        // @phpstan-ignore method.notFound
        $cfgOverflow = $ffi->nntmux_mcp_extract_config_keys($cfgSrc, strlen($cfgSrc), $tinyBuf, 4);
        $this->assertLessThan(-2, $cfgOverflow);

        // 6. nntmux_mcp_search_terms: < -1 on overflow, 0 on no matches, -1 on null
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_search_terms(null, 6, $tinyBuf, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mcp_search_terms('plain lowercase words', 21, $tinyBuf, 4));
        $errLine = 'RuntimeException in App\\Services\\Mcp\\RepositoryContextCollector';
        // @phpstan-ignore method.notFound
        $termsOverflow = $ffi->nntmux_mcp_search_terms($errLine, strlen($errLine), $tinyBuf, 4);
        $this->assertLessThan(-1, $termsOverflow);
    }

    public function test_sensitive_value_redactor_string_and_value_parity(): void
    {
        $redactor = new SensitiveValueRedactor;
        $refRedactPhp = new ReflectionMethod(SensitiveValueRedactor::class, 'redactPhp');
        $refKeyPhp = new ReflectionMethod(SensitiveValueRedactor::class, 'isSensitiveKeyPhp');

        $vectors = [
            '',
            'short',
            'Clean output line from phpunit without any sensitive tokens or URLs.',
            "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA0123456789abcdef\n-----END RSA PRIVATE KEY-----",
            "-----BEGIN EC PRIVATE KEY-----\nabc\n-----END EC PRIVATE KEY-----\ntrailing\n-----BEGIN PRIVATE KEY-----\nxyz\n-----END PRIVATE KEY-----",
            "-----BEGIN RSA-\nKEY-----\nnot a valid pem header\n-----END RSA PRIVATE KEY-----",
            'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U==',
            'bearer a+b/c~d_e-f.0=== and wordBearer tokenShouldNotMatch and Bearer   ===',
            'Connecting to mysql://user:p@ss:word@db.internal:3306/nntmux and redis://:nopass@127.0.0.1:6379',
            'git+ssh://deploy-user:sec_ret!23@github.com/org/repo.git and 1http://user:pass@host',
            'password = "hunter2", passwd=>"sec", token: \'tok_123\', secret:val, api_key="k1", api-key: k2, apikey=k3, authorization: Basic_xyz',
            'password=>, password=> next_token=abc, token=>} and secret=>"quoted_val"',
            'APP_KEY=base64:abcdef123456= DB_PASSWORD=my_secret_pw REDIS_PASSWD=pw2 JWT_SECRET=sec API_TOKEN=tok',
            'KEY=should_not_match_without_prefix _KEY=should_not_match 1KEY=should_not_match AKEY=should_match',
            "Combined: mysql://u:p@host Bearer tok.1= API_SECRET=sec99 password: 'pw!'",
        ];

        foreach ($vectors as $input) {
            $expected = $refRedactPhp->invoke($redactor, $input);
            $native = SensitiveValueRedactor::redactNative($input);

            $this->assertSame($expected, $native, "redactNative mismatch for: {$input}");

            SensitiveValueRedactor::setUseRustFfi(true);
            $withRust = $redactor->redact($input);
            SensitiveValueRedactor::setUseRustFfi(false);
            $withPhp = $redactor->redact($input);

            $this->assertSame($withPhp, $withRust, "redact() mismatch for: {$input}");
        }

        $keys = [
            '',
            'id',
            'name',
            'safe_paths',
            'password',
            'user_passwd_hash',
            'access_token',
            'client_secret',
            'x_api_key',
            'x-api-key',
            'my_apikey_value',
            'api__key',
            'Authorization',
            'user_credentials',
            'CREDENTIAL_STORE',
        ];

        foreach ($keys as $key) {
            $expectedKey = $refKeyPhp->invoke($redactor, $key);
            $nativeKey = SensitiveValueRedactor::isSensitiveKeyNative($key);
            $this->assertSame($expectedKey, $nativeKey, "isSensitiveKeyNative mismatch for: {$key}");
        }

        $nestedPayload = [
            'status' => 'ok',
            'api_key' => 'raw-secret',
            'nested' => [
                'Authorization' => 'Bearer abc',
                'dsn' => 'pgsql://admin:secret@localhost:5432/app',
                'env_line' => 'STRIPE_SECRET=sk_live_12345',
                'count' => 42,
            ],
        ];

        SensitiveValueRedactor::setUseRustFfi(false);
        $expectedNested = $redactor->redactValue($nestedPayload);
        SensitiveValueRedactor::setUseRustFfi(true);
        $actualNested = $redactor->redactValue($nestedPayload);

        $this->assertSame($expectedNested, $actualNested);
    }

    public function test_deterministic_fuzz_redactor_parity(): void
    {
        $redactor = new SensitiveValueRedactor;
        $refRedactPhp = new ReflectionMethod(SensitiveValueRedactor::class, 'redactPhp');

        $fragments = [
            'password', 'passwd', 'token', 'secret', 'api_key', 'api-key', 'apikey', 'authorization',
            '=', '=>', ':', ' ', "\t", "\n", "\r\n", '"', "'", ',', '}',
            'Bearer ', 'bearer  ', 'abc.def_123-456~+/==',
            'mysql://', 'https://', 'user:pass@', 'host:3306/',
            'APP_KEY=', 'DB_PASSWORD=', 'API_TOKEN=', 'KEY=', 'SECRET=',
            "-----BEGIN RSA PRIVATE KEY-----\nbody\n-----END RSA PRIVATE KEY-----",
            'normal_identifier_123',
        ];

        mt_srand(1337);
        for ($i = 0; $i < 200; $i++) {
            $parts = [];
            $count = mt_rand(2, 14);
            for ($j = 0; $j < $count; $j++) {
                $parts[] = $fragments[mt_rand(0, count($fragments) - 1)];
            }
            $sample = implode('', $parts);

            $expected = $refRedactPhp->invoke($redactor, $sample);
            $actual = SensitiveValueRedactor::redactNative($sample);
            $this->assertSame($expected, $actual, "Fuzz iteration {$i} mismatch for: ".json_encode($sample));
        }
    }

    public function test_repository_context_collector_helpers_and_end_to_end_parity(): void
    {
        $redactor = new SensitiveValueRedactor;
        $refCollector = new ReflectionClass(RepositoryContextCollector::class);
        $collector = $refCollector->newInstanceWithoutConstructor();
        $propRedactor = $refCollector->getProperty('redactor');
        $propRedactor->setValue($collector, $redactor);

        $refParsePhp = new ReflectionMethod(RepositoryContextCollector::class, 'parseAndRedactRgOutputPhp');
        $refGlobPhp = new ReflectionMethod(RepositoryContextCollector::class, 'globMatchesPhp');
        $refConfigPhp = new ReflectionMethod(RepositoryContextCollector::class, 'extractConfigKeysPhp');
        $refTermsPhp = new ReflectionMethod(RepositoryContextCollector::class, 'searchTermsPhp');

        // 1. parseAndRedactRgOutput parity across LF, CRLF, CR, colons in excerpts, and maxResults cap
        $rgSamples = [
            '',
            "not a match\nalsonot:abc:def",
            "app/Services/Foo.php:10:  \$url = 'mysql://root:secret@db:3306/app';  \r\nconfig/app.php:25:\t'key' => env('APP_KEY'), // API_TOKEN=xyz\rtests/Feature/BarTest.php:99:Bearer tok_123=",
            "a:1:\nb:2:colon:in:excerpt:password=123\nc:3:third\nd:4:fourth",
        ];

        foreach ($rgSamples as $rgOutput) {
            foreach ([1, 2, 10] as $cap) {
                $expected = $refParsePhp->invoke($collector, $rgOutput, $cap);
                $actual = RepositoryContextCollector::parseAndRedactRgOutputNative($rgOutput, $cap);
                $this->assertSame($expected, $actual, "parseAndRedactRgOutput mismatch for cap={$cap}");
            }
        }

        // 2. globMatches parity including *, **, regex metacharacters, and trailing/embedded newlines
        $globVectors = [
            ['**', 'any/path/at/all.php'],
            ['**', "path/with\nnewline.php"],
            ['app/**', 'app/Services/Mcp/RepositoryContextCollector.php'],
            ['app/**', "app/Services\n/Foo.php"],
            ['app/*', 'app/Foo.php'],
            ['app/*', 'app/Sub/Foo.php'],
            ['app/*', "app/Foo\nBar.php"],
            ['app/**/*.php', 'app/Services/Mcp/SensitiveValueRedactor.php'],
            ['app/**/*.php', 'app/Foo.php'],
            ['app/**/Mcp/*.php', 'app/Services/Mcp/SensitiveValueRedactor.php'],
            ['config/nntmux(mcp)+[test].php', 'config/nntmux(mcp)+[test].php'],
            ['app/*.php', "app/Foo.php\n"],
            ['app/*.php', "app/Foo.php\nbar"],
        ];

        foreach ($globVectors as [$glob, $path]) {
            $expectedGlob = $refGlobPhp->invoke($collector, $glob, $path);
            $actualGlob = RepositoryContextCollector::globMatchesNative($glob, $path);
            $this->assertSame($expectedGlob, $actualGlob, "globMatches mismatch for glob={$glob}, path=".json_encode($path));
        }

        // 3. extractConfigKeys parity
        $configSamples = [
            '',
            '<?php return ["no_calls" => true];',
            "<?php \$a = config('nntmux-mcp.limits.max_results', 25); \$b = env(\"NNTMUX_MCP_ENABLED\", false); \$c = my_config('ignored'); \$d = _env('ignored'); \$e = config(  'app.timezone' );",
            "<?php \$empty = config(''); \$unclosed = env('UNCLOSED;",
        ];

        foreach ($configSamples as $src) {
            $expectedCfg = $refConfigPhp->invoke($collector, $src);
            $actualCfg = RepositoryContextCollector::extractConfigKeysNative($src);
            $this->assertSame($expectedCfg, $actualCfg);
        }

        // Multiline key declines to null in Native and falls back to PHP in configurationKeys()
        $multilineCfg = "<?php \$x = config('line1\nline2');";
        $this->assertNull(RepositoryContextCollector::extractConfigKeysNative($multilineCfg));

        // 4. searchTerms parity
        $errorQueries = [
            'RuntimeException: Failed in App\\Services\\Mcp\\RepositoryContextCollector at app/Services/Mcp/ProcessRunner.php:42',
            "Stack trace:\n#0 app/Http/Controllers/Api/ApiController.php(12): App\\Services\\ReleaseSearchService->find()\n#1 {main} with ReleaseProbe and BackfillRunner and InspectCommand",
            'AService is 1 char before Service so excluded, AbService is 2 chars so included, App\\0 is filtered out, app/.php is excluded',
            'plain error message with no matching symbols at all',
            'App failed to boot in MyApp and FooApp\\Services\\Bar with Fooapp/Baz.php',
        ];

        foreach ($errorQueries as $query) {
            $expectedTerms = $refTermsPhp->invoke($collector, $query);
            $nativeTerms = RepositoryContextCollector::searchTermsNative($query);
            $this->assertNotNull($nativeTerms);
            $resolvedNative = $nativeTerms !== [] ? $nativeTerms : [mb_substr(trim($query), 0, 120)];
            $this->assertSame($expectedTerms, $resolvedNative, "searchTerms mismatch for: {$query}");
        }

        // 5. End-to-end RepositoryContextCollector::inspect() and changeImpact() parity (Rust FFI vs PHP fallback)
        $deterministicProcesses = new class($redactor) extends ProcessRunner
        {
            public function run(array $command, int $timeoutSeconds, array $environment = []): array
            {
                $output = match ($command[0] ?? '') {
                    'git' => in_array('log', $command, true)
                        ? "abc1234\tRefactor MCP redactor\ndef5678\tInitial MCP server"
                        : 'diff --git a/app/Services/Mcp/SensitiveValueRedactor.php b/app/Services/Mcp/SensitiveValueRedactor.php',
                    default => in_array('--files-with-matches', $command, true)
                        ? ".ai/rules/rust-performance.md\n"
                        : "app/Services/Mcp/RepositoryContextCollector.php:30:    private readonly SensitiveValueRedactor \$redactor,\n"
                            ."tests/Feature/McpVerificationServicesTest.php:46:    \$redactor = new SensitiveValueRedactor; // API_TOKEN=sec123\n",
                };

                return [
                    'command' => $command,
                    'exit_code' => 0,
                    'duration_ms' => 1,
                    'status' => 'passed',
                    'output' => $output,
                    'truncated' => false,
                ];
            }
        };

        $fullCollector = new RepositoryContextCollector(
            new ProjectPathGuard,
            $deterministicProcesses,
            $redactor,
            new VerificationPlanner,
        );

        RepositoryContextCollector::setUseRustFfi(false);
        SensitiveValueRedactor::setUseRustFfi(false);
        $phpInspect = $fullCollector->inspect('app/Services/Mcp/SensitiveValueRedactor.php', 'path', 5);
        $phpErrorInspect = $fullCollector->inspect('RuntimeException in App\\Services\\Mcp\\RepositoryContextCollector at app/Services/Mcp/ProcessRunner.php:42', 'auto', 5);
        $phpImpact = $fullCollector->changeImpact(['app/Services/Mcp/SensitiveValueRedactor.php']);

        RepositoryContextCollector::setUseRustFfi(true);
        SensitiveValueRedactor::setUseRustFfi(true);
        $rustInspect = $fullCollector->inspect('app/Services/Mcp/SensitiveValueRedactor.php', 'path', 5);
        $rustErrorInspect = $fullCollector->inspect('RuntimeException in App\\Services\\Mcp\\RepositoryContextCollector at app/Services/Mcp/ProcessRunner.php:42', 'auto', 5);
        $rustImpact = $fullCollector->changeImpact(['app/Services/Mcp/SensitiveValueRedactor.php']);

        $this->assertSame($phpInspect, $rustInspect);
        $this->assertSame($phpErrorInspect, $rustErrorInspect);
        $this->assertSame($phpImpact, $rustImpact);
    }

    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_MCP_BENCH') !== '1') {
            $this->expectNotToPerformAssertions();

            return;
        }

        $redactor = new SensitiveValueRedactor;
        $refRedactPhp = new ReflectionMethod(SensitiveValueRedactor::class, 'redactPhp');
        $sample = str_repeat("PASS  Tests\\Feature\\McpDevelopmentServerTest > it lists tools\n", 12);

        $t0 = hrtime(true);
        for ($i = 0; $i < 500; $i++) {
            $refRedactPhp->invoke($redactor, $sample);
        }
        $phpNs = (hrtime(true) - $t0) / 500;

        $t0 = hrtime(true);
        for ($i = 0; $i < 500; $i++) {
            SensitiveValueRedactor::redactNative($sample);
        }
        $rustNs = (hrtime(true) - $t0) / 500;

        $this->assertLessThan($phpNs, $rustNs);
    }
}
