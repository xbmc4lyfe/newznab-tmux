<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\BlockAbusiveServices;
use App\Http\Middleware\ValidateHttpQueryInput;
use App\Services\Api\ApiInputCanonicalizer;
use App\Services\Mcp\ProjectPathGuard;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

class RustMcpPathGuardQueryValidatorParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain('cleaning', ProjectPathGuard::FFI_CDEF) === null) {
            self::markTestSkipped('Rust cleaning FFI domain is not available.');
        }

        ProjectPathGuard::setUseRustFfi(true);
        ValidateHttpQueryInput::setUseRustFfi(true);
        BlockAbusiveServices::setUseRustFfi(true);
        NzbImportService::setUseRustFfi(true);
        NzbService::setUseRustFfi(true);
    }

    protected function tearDown(): void
    {
        ProjectPathGuard::setUseRustFfi(true);
        ValidateHttpQueryInput::setUseRustFfi(true);
        BlockAbusiveServices::setUseRustFfi(true);
        NzbImportService::setUseRustFfi(true);
        NzbService::setUseRustFfi(true);

        parent::tearDown();
    }

    public function test_c_abi_null_and_resize_contracts(): void
    {
        $cdef = ProjectPathGuard::FFI_CDEF."\n"
            .ValidateHttpQueryInput::FFI_CDEF."\n"
            .BlockAbusiveServices::FFI_CDEF."\n"
            .NzbImportService::FFI_CDEF."\n"
            .NzbService::FFI_CDEF;

        $ffi = RustPipelineLibrary::resolveDomain('cleaning', $cdef);
        $this->assertNotNull($ffi);

        // 1. nntmux_mcp_check_not_sensitive
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_check_not_sensitive(null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mcp_check_not_sensitive('app/Models/User.php', 19));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_mcp_check_not_sensitive('.env', 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(2, $ffi->nntmux_mcp_check_not_sensitive('config/id_rsa', 13));

        // 2. nntmux_mcp_check_allowed_location
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_check_allowed_location(null, 5, 1, null, 0, null, 0, null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mcp_check_allowed_location('app/Models/User.php', 19, 1, null, 0, null, 0, null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_mcp_check_allowed_location('docker/Dockerfile', 17, 1, null, 0, null, 0, null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(2, $ffi->nntmux_mcp_check_allowed_location('app/vendor/foo.php', 18, 1, null, 0, null, 0, null, 0));

        // 3. nntmux_mcp_normalize_relative_path resize and null contract
        $status = $ffi->new('int32_t');
        $smallBuf = $ffi->new('char[4]');
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_mcp_normalize_relative_path(null, 5, 1, null, 0, null, 0, null, 0, FFI::addr($status), $smallBuf, 4));
        // @phpstan-ignore method.notFound
        $resize = $ffi->nntmux_mcp_normalize_relative_path('app//Models/User.php', 20, 1, null, 0, null, 0, null, 0, FFI::addr($status), $smallBuf, 4);
        $this->assertSame(-19, $resize);

        // 4. nntmux_http_is_json_media_type
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_http_is_json_media_type(null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_http_is_json_media_type('application/json; charset=utf-8', 31));

        // 5. nntmux_http_validate_query resize and null contract
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_http_validate_query(null, 0, 0, '{}', 2, 8192, null, 0, 0, null, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        $resizeQuery = $ffi->nntmux_http_validate_query('text/plain', 10, 1, '{}', 2, 8192, null, 0, 0, FFI::addr($status), $smallBuf, 4);
        $this->assertLessThan(-1, $resizeQuery);

        // 6. nntmux_http_matches_user_agent_patterns_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_http_matches_user_agent_patterns_nul(null, 5, 0, 'bot', 3, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_http_matches_user_agent_patterns_nul('My Stremio Client', 17, 1, null, 0, 0));

        // 7. nntmux_http_redact_sensitive_query_keys_nul resize
        // @phpstan-ignore method.notFound
        $resizeRedact = $ffi->nntmux_http_redact_sensitive_query_keys_nul("apikey\0t\0id\0token\0passkey", 25, 5, $smallBuf, 4);
        $this->assertSame(-5, $resizeRedact);

        // 8. nntmux_nzb_derive_release_name_span
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_nzb_derive_release_name_span(null, 5));

        // 9. nntmux_nzb_normalize_imported_subject resize
        // @phpstan-ignore method.notFound
        $resizeSubj = $ffi->nntmux_nzb_normalize_imported_subject('Some.Release.Name (1/10) yEnc', 29, $smallBuf, 4);
        $this->assertLessThan(-1, $resizeSubj);

        // 10. nntmux_nzb_groups_from_xref resize
        // @phpstan-ignore method.notFound
        $resizeXref = $ffi->nntmux_nzb_groups_from_xref('news.example.com alt.binaries.teevee:12345', 42, $smallBuf, 4);
        $this->assertSame(-19, $resizeXref);

        // 11. nntmux_nzb_is_temporary_path_name
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_nzb_is_temporary_path_name(null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_nzb_is_temporary_path_name('test.nzb.gz.tmp.1234.0123456789ab', 33));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_nzb_is_temporary_path_name('test.nzb.gz', 11));
    }

    public function test_project_path_guard_assert_not_sensitive_parity(): void
    {
        $guard = new ProjectPathGuard;

        $paths = [
            '.env.example',
            'README.md',
            'app/Services/Mcp/ProjectPathGuard.php',
            'config/app.php',
            '.env',
            '.ENV',
            '.env.local',
            '.env.production',
            'app/.env',
            'app/.env.testing',
            'app/.env.example',
            'config/credentials.json',
            'config/USER_CREDENTIAL_FILE.php',
            'storage/private_key.pem',
            'storage/private-key.txt',
            'storage/privatekey.dat',
            'app/keys/id_rsa',
            'app/keys/id_rsa.pub',
            'app/keys/id_ed25519',
            'app/certs/server.pem',
            'app/certs/server.PEM',
            'app/certs/bundle.p12',
            'app/certs/bundle.pfx',
            'app/certs/pem_helper.php',
            'app/certs/p12_parser.php',
            'app/certs/pfx_loader.php',
        ];

        foreach ($paths as $path) {
            $phpException = null;
            try {
                $guard->assertNotSensitivePhp($path);
            } catch (InvalidArgumentException $e) {
                $phpException = $e->getMessage();
            }

            $nativeException = null;
            try {
                $handled = ProjectPathGuard::assertNotSensitiveNative($path);
                $this->assertTrue($handled, "Expected Rust FFI to handle assertNotSensitive for [{$path}]");
            } catch (InvalidArgumentException $e) {
                $nativeException = $e->getMessage();
            }

            $prodException = null;
            try {
                $guard->assertNotSensitive($path);
            } catch (InvalidArgumentException $e) {
                $prodException = $e->getMessage();
            }

            $this->assertSame($phpException, $nativeException, "Mismatch on assertNotSensitive for [{$path}]");
            $this->assertSame($phpException, $prodException, "Mismatch on production assertNotSensitive for [{$path}]");
        }
    }

    public function test_project_path_guard_assert_allowed_location_parity(): void
    {
        $guard = new ProjectPathGuard;

        $paths = [
            '.env.example',
            'AGENTS.md',
            'README.md',
            'composer.json',
            'vite.config.js',
            'app/Models/User.php',
            'bootstrap/app.php',
            'config/nntmux-mcp.php',
            'database/migrations/0001.php',
            'resources/views/welcome.blade.php',
            'routes/api.php',
            'tests/TestCase.php',
            'docker/Dockerfile',
            'rust/pipeline-ffi/Cargo.toml',
            'unknown_root.txt',
            '.git',
            '.git/config',
            'app/.git/HEAD',
            'vendor/autoload.php',
            'app/vendor/package.php',
            'node_modules/pkg/index.js',
            'resources/node_modules/pkg/index.js',
            'public/build/manifest.json',
            'resources/public/build/app.js',
            'storage/logs/laravel.log',
            'app/storage/cache.php',
            'storage-runtime/data',
            'app/storage-runtime/file.php',
        ];

        foreach ($paths as $path) {
            $phpException = null;
            try {
                $guard->assertAllowedLocationPhp($path);
            } catch (InvalidArgumentException $e) {
                $phpException = $e->getMessage();
            }

            $nativeException = null;
            try {
                $handled = $guard->assertAllowedLocationNative($path);
                $this->assertTrue($handled, "Expected Rust FFI to handle assertAllowedLocation for [{$path}]");
            } catch (InvalidArgumentException $e) {
                $nativeException = $e->getMessage();
            }

            $prodException = null;
            try {
                $guard->assertAllowedLocation($path);
            } catch (InvalidArgumentException $e) {
                $prodException = $e->getMessage();
            }

            $this->assertSame($phpException, $nativeException, "Mismatch on assertAllowedLocation for [{$path}]");
            $this->assertSame($phpException, $prodException, "Mismatch on production assertAllowedLocation for [{$path}]");
        }

        // Custom config override test
        $originalConfig = config('nntmux-mcp');
        try {
            config([
                'nntmux-mcp.allowed_root_files' => ['custom.txt'],
                'nntmux-mcp.allowed_roots' => ['custom_dir'],
                'nntmux-mcp.denied_segments' => ['secret/nested'],
            ]);

            foreach (['custom.txt', 'README.md', 'custom_dir/ok.php', 'custom_dir/secret/nested/no.php', 'app/Models/User.php'] as $path) {
                $phpException = null;
                try {
                    $guard->assertAllowedLocationPhp($path);
                } catch (InvalidArgumentException $e) {
                    $phpException = $e->getMessage();
                }

                $nativeException = null;
                try {
                    $handled = $guard->assertAllowedLocationNative($path);
                    $this->assertTrue($handled);
                } catch (InvalidArgumentException $e) {
                    $nativeException = $e->getMessage();
                }

                $this->assertSame($phpException, $nativeException, "Custom config mismatch for [{$path}]");
            }
        } finally {
            config(['nntmux-mcp' => $originalConfig]);
        }
    }

    public function test_project_path_guard_normalize_parity(): void
    {
        $guard = new ProjectPathGuard;

        $paths = [
            '.env.example',
            'README.md',
            'composer.json',
            'app/Services/Mcp/ProjectPathGuard.php',
            '  app\\Services//Mcp///ProjectPathGuard.php  ',
            'config/app.php',
            'tests/TestCase.php',
            '',
            '   ',
            "app/Models/\0User.php",
            '/etc/passwd',
            '\\Windows\\System32',
            'C:/Windows/System32',
            'd:\\temp\\file.txt',
            '.',
            '..',
            './app/Models/User.php',
            'app/../config/app.php',
            'app/./Models/User.php',
            'app/Models/..',
            'docker/Dockerfile',
            'app/vendor/foo.php',
            'resources/public/build/manifest.json',
            '.env',
            'app/.env.local',
            'config/credentials.php',
            'app/private_key.pem',
            'app/Services/Mcp/DoesNotExistFile99999.php',
        ];

        foreach ($paths as $path) {
            $phpResult = null;
            $phpException = null;
            try {
                $phpResult = $guard->normalizePhp($path);
            } catch (InvalidArgumentException $e) {
                $phpException = $e->getMessage();
            }

            $nativeResult = null;
            $nativeException = null;
            try {
                $nativeResult = $guard->normalizeNative($path);
            } catch (InvalidArgumentException $e) {
                $nativeException = $e->getMessage();
            }

            $prodResult = null;
            $prodException = null;
            try {
                $prodResult = $guard->normalize($path);
            } catch (InvalidArgumentException $e) {
                $prodException = $e->getMessage();
            }

            $this->assertSame($phpException, $nativeException, "Exception mismatch on normalize for [{$path}]");
            $this->assertSame($phpResult, $nativeResult, "Result mismatch on normalize for [{$path}]");
            $this->assertSame($phpException, $prodException, "Production exception mismatch on normalize for [{$path}]");
            $this->assertSame($phpResult, $prodResult, "Production result mismatch on normalize for [{$path}]");
        }

        ProjectPathGuard::setUseRustFfi(false);
        $this->assertFalse(ProjectPathGuard::usesRustFfi());
        $this->assertNull($guard->normalizeNative('README.md'));
        $this->assertSame('README.md', $guard->normalize('README.md'));
    }

    public function test_validate_http_query_input_is_json_media_type_parity(): void
    {
        $middleware = new ValidateHttpQueryInput(new ApiInputCanonicalizer);

        $cases = [
            null,
            '',
            '   ',
            'application/json',
            'APPLICATION/JSON',
            '  application/json  ',
            'application/json; charset=utf-8',
            'application/json; CHARSET=UTF-8',
            'application/json; charset="utf-8"',
            'application/json; charset="UTF-8"; ',
            'application/json; ; charset=utf-8',
            'application/json; charset=utf-8; charset=utf-8',
            'application/json; charset=iso-8859-1',
            'application/json; charset=',
            'application/json; charset',
            'application/json; version=2',
            'application/problem+json',
            'text/json',
            'text/plain; charset=utf-8',
        ];

        foreach ($cases as $contentType) {
            $php = $middleware->isJsonMediaTypePhp($contentType);
            $native = ValidateHttpQueryInput::isJsonMediaTypeNative($contentType);
            $prod = $middleware->isJsonMediaType($contentType);

            $label = var_export($contentType, true);
            $this->assertSame($php, $native, "Native mismatch for Content-Type {$label}");
            $this->assertSame($php, $prod, "Production mismatch for Content-Type {$label}");
        }
    }

    public function test_validate_http_query_input_validate_parity(): void
    {
        $middleware = new ValidateHttpQueryInput(new ApiInputCanonicalizer);

        $requests = [
            'valid_empty_object' => $this->makeQueryRequest([], '{}'),
            'valid_scalars_and_lists' => $this->makeQueryRequest(
                ['apikey' => 'abc123', 'cat' => ['2000', '2040']],
                '{"t":"movie","limit":50,"offset":0,"minsize":1.5,"cat":["2000","2040"]}'
            ),
            'valid_numeric_string_key' => $this->makeQueryRequest([], '{"0":1,"1":"two","_ok_32_chars_long_key_1234567890":0}'),
            'valid_ascii_escapes' => $this->makeQueryRequest([], '{"q":"hello \\"world\\" \\/ \\b \\f \\n \\r \\t"}'),
            'valid_unicode_escapes' => $this->makeQueryRequest([], '{"q":"hello \\u0041 \\uD83D\\uDE00"}'),
            'valid_exponent_numbers' => $this->makeQueryRequest([], '{"a":0,"b":-0,"c":-12.34e+5,"d":1E-3}'),
            'missing_content_type' => $this->makeQueryRequest([], '{}', null),
            'wrong_content_type' => $this->makeQueryRequest([], '{}', 'text/plain'),
            'charset_utf8_content_type' => $this->makeQueryRequest([], '{"t":"search"}', 'application/json; charset="UTF-8"'),
            'wrong_charset_content_type' => $this->makeQueryRequest([], '{"t":"search"}', 'application/json; charset=ascii'),
            'oversized_body' => $this->makeQueryRequest([], '{"q":"'.str_repeat('a', 8200).'"}'),
            'empty_body' => $this->makeQueryRequest([], ''),
            'malformed_json_trailing' => $this->makeQueryRequest([], '{"t":"search"} trailing'),
            'malformed_json_comma' => $this->makeQueryRequest([], '{"t":"search",}'),
            'malformed_number_leading_zero' => $this->makeQueryRequest([], '{"limit":01}'),
            'malformed_number_trailing_dot' => $this->makeQueryRequest([], '{"limit":1.}'),
            'malformed_number_incomplete_exp' => $this->makeQueryRequest([], '{"limit":1e+}'),
            'malformed_unescaped_control' => $this->makeQueryRequest([], "{\"q\":\"line1\nline2\"}"),
            'malformed_lonely_high_surrogate' => $this->makeQueryRequest([], '{"q":"\uD800"}'),
            'malformed_lonely_low_surrogate' => $this->makeQueryRequest([], '{"q":"\uDC00"}'),
            'nested_object_in_object' => $this->makeQueryRequest([], '{"a":{"b":1}}'),
            'nested_array_in_array' => $this->makeQueryRequest([], '{"cat":[[2000]]}'),
            'nested_object_in_array' => $this->makeQueryRequest([], '{"cat":[{"a":1}]}'),
            'json_array_root' => $this->makeQueryRequest([], '[1,2,3]'),
            'json_string_root' => $this->makeQueryRequest([], '"hello"'),
            'json_number_root' => $this->makeQueryRequest([], '42'),
            'json_bool_root' => $this->makeQueryRequest([], 'true'),
            'json_null_root' => $this->makeQueryRequest([], 'null'),
            'invalid_body_key_hyphen' => $this->makeQueryRequest([], '{"bad-key":"val"}'),
            'invalid_body_key_empty' => $this->makeQueryRequest([], '{"":"val"}'),
            'invalid_body_key_too_long' => $this->makeQueryRequest([], '{"'.str_repeat('a', 33).'":"val"}'),
            'invalid_url_key_dot' => $this->makeQueryRequest(['bad.key' => 'val'], '{}'),
            'duplicate_url_and_body_key' => $this->makeQueryRequest(['t' => 'search'], '{"t":"movie"}'),
            'duplicate_url_and_escaped_body_key' => $this->makeQueryRequest(['t' => 'search'], '{"\\u0074":"movie"}'),
            'duplicate_keys_inside_json_body' => $this->makeQueryRequest([], '{"t":[1,2],"t":"search"}'),
            'duplicate_keys_inside_json_body_turned_invalid' => $this->makeQueryRequest([], '{"t":"search","t":[1,2]}'),
            'unsupported_body_bool' => $this->makeQueryRequest([], '{"extended":true}'),
            'unsupported_body_null' => $this->makeQueryRequest([], '{"q":null}'),
            'unsupported_body_array_on_scalar_key' => $this->makeQueryRequest([], '{"q":["a","b"]}'),
            'unsupported_body_bool_in_list_key' => $this->makeQueryRequest([], '{"cat":["2000",false]}'),
            'unsupported_url_array_on_scalar_key' => $this->makeQueryRequest(['q' => ['a', 'b']], '{"t":"search"}'),
            'unsupported_url_nested_array_on_list_key' => $this->makeQueryRequest(['cat' => [['2000']]], '{"t":"search"}'),
        ];

        // 64 parameters (allowed) vs 65 parameters (rejected)
        $params64 = [];
        for ($i = 1; $i <= 64; $i++) {
            $params64["p{$i}"] = $i;
        }
        $requests['max_64_params_ok'] = $this->makeQueryRequest([], (string) json_encode($params64));
        $params65 = $params64;
        $params65['p65'] = 65;
        $requests['max_65_params_rejected'] = $this->makeQueryRequest([], (string) json_encode($params65));
        $requests['max_65_split_url_and_body_rejected'] = $this->makeQueryRequest(['url_p' => '1'], (string) json_encode($params64));
        $params67InvalidKey = $params65;
        $params67InvalidKey['p66'] = 66;
        $params67InvalidKey['bad-key'] = 67;
        $requests['max_67_params_with_invalid_67th_key'] = $this->makeQueryRequest([], (string) json_encode($params67InvalidKey));

        $declinedCases = [
            'valid_unicode_escapes',
            'valid_exponent_numbers',
            'malformed_number_incomplete_exp',
            'malformed_lonely_high_surrogate',
            'malformed_lonely_low_surrogate',
            'duplicate_url_and_escaped_body_key',
            'duplicate_keys_inside_json_body',
            'duplicate_keys_inside_json_body_turned_invalid',
            'max_67_params_with_invalid_67th_key',
        ];

        foreach ($requests as $name => $request) {
            $php = $middleware->validatePhp($request);
            $supported = false;
            $native = $middleware->validateNative($request, $supported);
            $prod = $middleware->validate($request);

            if (! in_array($name, $declinedCases, true)) {
                $this->assertTrue($supported, "Expected Rust FFI to support case [{$name}]");
                $this->assertSame($php, $native, "Native mismatch on ValidateHttpQueryInput case [{$name}]");
            } elseif ($supported) {
                $this->assertSame($php, $native, "Native mismatch on ValidateHttpQueryInput case [{$name}]");
            }
            $this->assertSame($php, $prod, "Production mismatch on ValidateHttpQueryInput case [{$name}]");
        }

        ValidateHttpQueryInput::setUseRustFfi(false);
        $this->assertFalse(ValidateHttpQueryInput::usesRustFfi());
        $supported = true;
        $this->assertNull($middleware->validateNative($requests['valid_empty_object'], $supported));
        $this->assertFalse($supported);
    }

    public function test_block_abusive_services_parity(): void
    {
        $ref = new ReflectionClass(BlockAbusiveServices::class);
        $middleware = $ref->newInstanceWithoutConstructor();

        $isBlockedPhp = $ref->getMethod('isBlockedUserAgentPhp');
        $isBlockedNative = $ref->getMethod('isBlockedUserAgentNative');
        $isBlockedProd = $ref->getMethod('isBlockedUserAgent');

        $matchesPhp = $ref->getMethod('matchesAnyUserAgentPatternPhp');
        $matchesNative = $ref->getMethod('matchesAnyUserAgentPatternNative');
        $matchesProd = $ref->getMethod('matchesAnyUserAgentPattern');

        $redactPhp = $ref->getMethod('redactSensitiveQueryParametersPhp');
        $redactNative = $ref->getMethod('redactSensitiveQueryParametersNative');
        $redactProd = $ref->getMethod('redactSensitiveQueryParameters');

        $patterns = ['curl/', 'python-requests', 'badbot'];
        $userAgents = [
            '',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'curl/8.4.0',
            'CURL/7.88.1',
            'my-python-requests-client/1.0',
            'BadBot/42 (scraper)',
            'AIOStreams/1.0',
            'UsenetStreamer/2.0',
            'Stremio/4.4',
            'goodbot/42',
        ];

        foreach ($userAgents as $ua) {
            $expectedMatch = $matchesPhp->invoke($middleware, $ua, $patterns);
            $nativeMatch = $matchesNative->invoke(null, $ua, $patterns);
            $prodMatch = $matchesProd->invoke($middleware, $ua, $patterns);

            $this->assertSame($expectedMatch, $nativeMatch, "matchesAnyUserAgentPattern mismatch for [{$ua}]");
            $this->assertSame($expectedMatch, $prodMatch, "matchesAnyUserAgentPattern prod mismatch for [{$ua}]");

            $expectedDefaultBlocked = $isBlockedPhp->invoke($middleware, $ua);
            $nativeDefaultBlocked = $isBlockedNative->invoke($middleware, $ua);
            $prodDefaultBlocked = $isBlockedProd->invoke($middleware, $ua);

            $this->assertSame($expectedDefaultBlocked, $nativeDefaultBlocked, "isBlockedUserAgent default mismatch for [{$ua}]");
            $this->assertSame($expectedDefaultBlocked, $prodDefaultBlocked, "isBlockedUserAgent default prod mismatch for [{$ua}]");

            $expectedBlocked = $isBlockedPhp->invoke($middleware, $ua, ['curl/', 'BadBot']);
            $nativeBlocked = $isBlockedNative->invoke($middleware, $ua, ['curl/', 'BadBot']);
            $prodBlocked = $isBlockedProd->invoke($middleware, $ua, ['curl/', 'BadBot']);

            $this->assertSame($expectedBlocked, $nativeBlocked, "isBlockedUserAgent custom mismatch for [{$ua}]");
            $this->assertSame($expectedBlocked, $prodBlocked, "isBlockedUserAgent custom prod mismatch for [{$ua}]");
        }

        $queryArrays = [
            [],
            ['t' => 'search', 'q' => 'ubuntu', 'cat' => '2000'],
            ['t' => 'get', 'id' => '12345', 'apikey' => 'secret123'],
            ['APIKEY' => 'secret123', 'Api_Token' => 'tok456', 'r' => 'rsskey', 'i' => '1', 'passkey' => 'pk', 'token' => 'tk'],
            ['t' => 'search', 'nested' => ['apikey' => 'inner_secret', 'ok' => 'val']],
        ];

        foreach ($queryArrays as $idx => $params) {
            $expected = $redactPhp->invoke($middleware, $params);
            $native = $redactNative->invoke($middleware, $params);
            $prod = $redactProd->invoke($middleware, $params);

            $this->assertSame($expected, $native, "redactSensitiveQueryParameters mismatch for case #{$idx}");
            $this->assertSame($expected, $prod, "redactSensitiveQueryParameters prod mismatch for case #{$idx}");
        }
    }

    public function test_nzb_import_service_and_nzb_service_parity(): void
    {
        $importRef = new ReflectionClass(NzbImportService::class);
        $derivePhp = $importRef->getMethod('deriveReleaseNameFromNzbPathPhp');
        $deriveProd = $importRef->getMethod('deriveReleaseNameFromNzbPath');
        $normPhp = $importRef->getMethod('normalizeImportedSubjectPhp');
        $normProd = $importRef->getMethod('normalizeImportedSubject');

        $importService = $importRef->newInstanceWithoutConstructor();

        $paths = [
            '/var/nzb/Ubuntu.24.04.LTS.x64.nzb',
            '/var/nzb/Ubuntu.24.04.LTS.x64.nzb.gz',
            'C:\\nzbs\\Some.Movie.2026.1080p.NZB.GZ',
            'relative/path/Release.Name.NZB',
            'just_filename.txt',
            '.nzb',
            '.nzb.gz',
            '',
        ];

        foreach ($paths as $path) {
            $expected = $derivePhp->invoke($importService, $path);
            $native = NzbImportService::deriveReleaseNameFromNzbPathNative($path);
            $prod = $deriveProd->invoke($importService, $path);

            $this->assertSame($expected, $native, "deriveReleaseNameFromNzbPath mismatch for [{$path}]");
            $this->assertSame($expected, $prod, "deriveReleaseNameFromNzbPath prod mismatch for [{$path}]");
        }

        $subjects = [
            '[12345] "My.Release.2026.1080p.WEB-DL-GRP.par2" yEnc (1/15)',
            'Some.Show.S01E01.720p.HDTV.x264-GRP (1/45) (2/45) yenc (123456)',
            "  Clean\tSubject\r\nWith\x00Control\x07Chars (01/10) YENC ",
            'Subject+With-Plus-Hyphen (1/5) yEnc',
            'Subject+ABCD-Base64Like (1/5) yEnc',
            'Subject&Imap-Modified (1/5) yEnc',
            "UTF8.M\xC3\xBCnchen.Release (1/3) yEnc",
            "Latin1.Caf\xE9.Release (1/3) yEnc",
            'NoYencOrCountersJustPlainTitle',
            '',
        ];

        foreach ($subjects as $subject) {
            $expected = $normPhp->invoke($importService, $subject);
            $prod = $normProd->invoke($importService, $subject);
            $native = NzbImportService::normalizeImportedSubjectNative($subject);

            $this->assertSame($expected, $prod, 'normalizeImportedSubject prod mismatch for '.bin2hex($subject));
            if ($native !== null) {
                $this->assertSame($expected, $native, 'normalizeImportedSubject native mismatch for '.bin2hex($subject));
            }
        }

        $nzbRef = new ReflectionClass(NzbService::class);
        $xrefPhp = $nzbRef->getMethod('groupsFromXrefPhp');
        $xrefProd = $nzbRef->getMethod('groupsFromXref');
        $tmpPhp = $nzbRef->getMethod('isTemporaryNzbPathNamePhp');
        $tmpProd = $nzbRef->getMethod('isTemporaryNzbPathName');

        $nzbService = $nzbRef->newInstanceWithoutConstructor();

        $xrefs = [
            '',
            '   ',
            'news.newshosting.com alt.binaries.teevee:12345678',
            "news.example.com\talt.binaries.boneless:111\nalt.binaries.moovee:222  alt.binaries.hdtv:333",
            'news.example.com malformed_token alt.binaries.games:999 :emptygroup',
        ];

        foreach ($xrefs as $xref) {
            $expected = $xrefPhp->invoke($nzbService, $xref);
            $native = NzbService::groupsFromXrefNative($xref);
            $prod = $xrefProd->invoke($nzbService, $xref);

            $this->assertSame($expected, $native, "groupsFromXref mismatch for [{$xref}]");
            $this->assertSame($expected, $prod, "groupsFromXref prod mismatch for [{$xref}]");
        }

        $tmpNames = [
            '/path/to/file.nzb.gz',
            '/path/to/file.nzb.tmp',
            '/path/to/file.nzb.TMP',
            '/path/to/file.nzb.tmp.12345',
            '/path/to/file.nzb.part.987',
            '/path/to/file.nzb.part.abc',
            '/path/to/file.tmp.extra.dots',
            '',
        ];

        foreach ($tmpNames as $pathName) {
            $expected = $tmpPhp->invoke($nzbService, $pathName);
            $native = NzbService::isTemporaryNzbPathNameNative($pathName);
            $prod = $tmpProd->invoke($nzbService, $pathName);

            $this->assertSame($expected, $native, "isTemporaryNzbPathName mismatch for [{$pathName}]");
            $this->assertSame($expected, $prod, "isTemporaryNzbPathName prod mismatch for [{$pathName}]");
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function makeQueryRequest(array $query, string $rawBody, ?string $contentType = 'application/json'): Request
    {
        $server = [];
        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
            $server['HTTP_CONTENT_TYPE'] = $contentType;
        }

        return Request::create('/api/v2/search', 'QUERY', $query, [], [], $server, $rawBody);
    }
}
