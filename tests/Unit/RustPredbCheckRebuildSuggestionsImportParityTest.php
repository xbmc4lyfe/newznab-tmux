<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\PredbCheck;
use App\Console\Commands\PredbIdentityRebuildCommand;
use App\Console\Commands\ReleaseIdentitySuggestions;
use App\Http\Controllers\Admin\AdminPredbController;
use App\Models\PredbImport;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\View\View;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustPredbCheckRebuildSuggestionsImportParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        PredbCheck::class,
        PredbIdentityRebuildCommand::class,
        ReleaseIdentitySuggestions::class,
        AdminPredbController::class,
        PredbImport::class,
    ];

    private const GENERATION = '0123456789abcdef0123456789abcdef';

    /** Strings that exercise trimming, signs, zeros, separators, invalid UTF-8 and wire markers. */
    private const TEXTS = [
        '', ' ', '0', '00', '000', '7', '007', '+7', '++7', '-7', '-0', '+', '-', '1e3', '1.5', '0x1A', ' 12 ',
        "\t42\n", "\0 9 \x0B\x0C", '1000000', '1000001', '0001000000', '99999999999999999999999', '128', '256',
        '257', '064', 'start', 'chunk', 'certify', 'Start', 'chunk ', self::GENERATION, 'Some.Show.S01E01.720p-GRP',
        'Some Show - Group', 'a-', '-a', 'a-b-c', "a \t\n b-c", "x\x1Fy", "\x1F", "\x1F\x1F", "nul\0byte", "ctl\x07x",
        "del\x7Fx", 'Ünïcödé.Tïtle-GRP', "emoji \u{1F3AC}", "bad \xff utf8", "\xc3", "\xed\xa0\x80", "\xf4\x90\x80\x80",
        'PreDB rebuild inventory changed.', 'PreDB rebuild inventory changed. ',
    ];

    /** Integers around every boundary the helpers use. */
    private const INTS = [PHP_INT_MIN, -4294967296, -5, -1, 0, 1, 2, 3, 4, 5, 12, 25, 255, 256, 4294967296, PHP_INT_MAX];

    /** @var array<string, ReflectionMethod> */
    private array $natives = [];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse(constant($class.'::RUST_FFI_DEFAULT'), $class);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(true);
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_existing_command_controller_and_model_contracts_are_unchanged(): void
    {
        $this->assertInstanceOf(Command::class, new PredbCheck);
        $this->assertSame('predb:check', (new PredbCheck)->getName());
        $this->assertSame('predb:identity-rebuild', (new PredbIdentityRebuildCommand)->getName());
        $this->assertSame('releases:identity-suggestions', (new ReleaseIdentitySuggestions)->getName());
        $this->assertSame(View::class, (string) (new ReflectionMethod(AdminPredbController::class, 'index'))->getReturnType());
        $this->assertFalse((new PredbImport)->timestamps);

        $this->artisan('predb:check', ['limit' => 'abc'])->expectsOutput('Limit must be a number')->assertExitCode(1);
        foreach ([
            [['action' => 'stop'], 'invalid_action'],
            [['action' => 'start', '--generation' => self::GENERATION], 'start_generation'],
            [['action' => 'chunk', '--generation' => 'ABC'], 'invalid_generation'],
            [['action' => 'chunk', '--generation' => self::GENERATION, '--chunk' => '257'], 'invalid_chunk'],
            [['action' => 'certify', '--generation' => self::GENERATION, '--chunk' => '64'], 'custom_chunk'],
        ] as [$arguments, $error]) {
            $plan = PredbIdentityRebuildCommand::validateRebuildArguments(
                $arguments['action'],
                $arguments['--generation'] ?? null,
                isset($arguments['--generation']),
                $arguments['--chunk'] ?? '128',
            );
            $this->assertSame($error, $plan['error']);
            $this->artisan('predb:identity-rebuild', $arguments)->expectsOutput((string) $plan['message'])->assertExitCode(1);
        }
        $plan = ReleaseIdentitySuggestions::planSuggestionRequest('x', null, array_map('strval', range(1, 51)));
        $this->artisan('releases:identity-suggestions', ['query' => 'x', '--candidate-id' => range(1, 51)])
            ->expectsOutput((string) $plan['message'])->assertExitCode(1);
        $plan = ReleaseIdentitySuggestions::planSuggestionRequest('x', null, ['0']);
        $this->artisan('releases:identity-suggestions', ['query' => 'x', '--candidate-id' => ['0']])
            ->expectsOutput((string) $plan['message'])->assertExitCode(1);
        $plan = ReleaseIdentitySuggestions::planSuggestionRequest(str_repeat('a', 513), null, []);
        $this->artisan('releases:identity-suggestions', ['query' => str_repeat('a', 513)])
            ->expectsOutput((string) $plan['message'])->assertExitCode(1);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['provided' => false, 'canonical' => false, 'valid' => true, 'limit' => 0, 'clamped' => false, 'mode' => 'all', 'reason' => 'absent'],
            PredbCheck::planCheckLimit(null),
        );
        $this->assertSame(
            ['provided' => true, 'canonical' => false, 'valid' => true, 'limit' => 25, 'clamped' => false, 'mode' => 'bounded', 'reason' => 'ok'],
            PredbCheck::planCheckLimit(' +025 '),
        );
        $this->assertSame([1_000_000, true, 'clamped'], array_values(array_intersect_key(PredbCheck::planCheckLimit('99999999999999999999'), array_flip(['clamped', 'limit', 'reason']))));
        $this->assertSame('zero', PredbCheck::planCheckLimit('000')['reason']);
        $this->assertSame('negative', PredbCheck::planCheckLimit('-5')['reason']);
        $this->assertSame('not_integer', PredbCheck::planCheckLimit('1e3')['reason']);
        $this->assertSame('empty', PredbCheck::planCheckLimit(" \t")['reason']);
        $this->assertTrue(PredbCheck::planCheckLimit('10')['canonical']);

        $this->assertSame(
            ['valid' => true, 'error' => 'none', 'message' => null, 'action' => 'chunk', 'chunk' => 64, 'requires_generation' => true, 'certifies' => false],
            PredbIdentityRebuildCommand::validateRebuildArguments('chunk', self::GENERATION, true, '64'),
        );
        $this->assertSame('start_generation', PredbIdentityRebuildCommand::validateRebuildArguments('start', null, true, '128')['error']);
        $this->assertSame('none', PredbIdentityRebuildCommand::validateRebuildArguments('start', null, false, '128')['error']);
        $this->assertSame('invalid_chunk', PredbIdentityRebuildCommand::validateRebuildArguments('chunk', self::GENERATION, true, '064')['error']);
        $this->assertSame('invalid_action', PredbIdentityRebuildCommand::validateRebuildArguments(null, null, false, null)['error']);
        $this->assertSame(
            ['safe' => true, 'index' => 4, 'message' => 'PreDB rebuild inventory changed.'],
            PredbIdentityRebuildCommand::classifyRebuildFailure('PreDB rebuild inventory changed.'),
        );
        $this->assertSame(PredbIdentityRebuildCommand::GENERIC_FAILURE, PredbIdentityRebuildCommand::classifyRebuildFailure('SQLSTATE secret')['message']);

        $this->assertSame([
            'query_source' => 'argument',
            'query_bytes' => 16,
            'query_valid' => true,
            'candidate_count' => 3,
            'candidate_scope' => 'explicit',
            'status' => 'ok',
            'message' => null,
            'candidate_ids' => ['7', '12'],
        ], ReleaseIdentitySuggestions::planSuggestionRequest('Some.Release-GRP', null, ['007', '7', '12']));
        $this->assertSame('needs_release_lookup', ReleaseIdentitySuggestions::planSuggestionRequest(null, '42', [])['status']);
        $this->assertSame('invalid_query', ReleaseIdentitySuggestions::planSuggestionRequest('', '4a', [])['status']);
        $this->assertSame('invalid_query', ReleaseIdentitySuggestions::planSuggestionRequest("\xff", null, [])['status']);
        $this->assertSame('invalid_candidate', ReleaseIdentitySuggestions::planSuggestionRequest('q', null, ["1\x1F2"])['status']);

        $this->assertSame(
            ['search' => '', 'filtered' => false, 'bytes' => 1, 'trimmed_bytes' => 1, 'blank' => false, 'valid_utf8' => true, 'terms' => 1],
            AdminPredbController::planPredbBrowse('0'),
        );
        $this->assertSame(
            ['search' => ' foo  bar', 'filtered' => true, 'bytes' => 9, 'trimmed_bytes' => 8, 'blank' => false, 'valid_utf8' => true, 'terms' => 2],
            AdminPredbController::planPredbBrowse(' foo  bar'),
        );
        $this->assertTrue(AdminPredbController::planPredbBrowse(" \0 ")['blank']);

        $this->assertSame(
            ['nuked' => 2, 'label' => 'nuked', 'is_nuked' => true, 'was_nuked' => true, 'reason' => 'bad.rip', 'has_reason' => true, 'consistent' => true],
            PredbImport::summarizeNukeStatus(2, ' bad.rip '),
        );
        $this->assertFalse(PredbImport::summarizeNukeStatus(0, 'x')['consistent']);
        $this->assertSame('unknown', PredbImport::summarizeNukeStatus(9, null)['label']);
        $this->assertSame(
            ['title' => 'Some.Show.S01E01.720p-GRP', 'bytes' => 25, 'valid_utf8' => true, 'has_control' => false, 'truncated' => false, 'group' => 'GRP'],
            PredbImport::normalizeImportTitle("  Some.Show.S01E01.720p-GRP \n"),
        );
        $this->assertSame(['a b', true, null], array_values(array_intersect_key(PredbImport::normalizeImportTitle("a \t\n b-c", 3), array_flip(['title', 'truncated', 'group']))));
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $texts = [null, ...self::TEXTS];
        foreach ($texts as $text) {
            $this->assertParity(PredbCheck::class, 'planCheckLimit', [$text]);
            $this->assertParity(AdminPredbController::class, 'planPredbBrowse', [$text ?? '']);
            $this->assertParity(PredbIdentityRebuildCommand::class, 'classifyRebuildFailure', [$text ?? '']);
            foreach ([null, '', '42', '4a'] as $release) {
                $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', [$text, $release, []]);
            }
            $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', ['q', null, $text === null ? [] : [$text, '7']]);
            foreach (self::INTS as $int) {
                $this->assertParity(PredbImport::class, 'summarizeNukeStatus', [$int, $text]);
                if ($text !== null) {
                    $this->assertParity(PredbImport::class, 'normalizeImportTitle', [$text, $int]);
                }
            }
            foreach (['start', 'chunk', 'certify', 'stop', null] as $action) {
                foreach ([true, false] as $option) {
                    $this->assertParity(PredbIdentityRebuildCommand::class, 'validateRebuildArguments', [$action, $text, $option, '128']);
                    $this->assertParity(PredbIdentityRebuildCommand::class, 'validateRebuildArguments', [$action, self::GENERATION, $option, $text]);
                    $this->assertParity(PredbIdentityRebuildCommand::class, 'validateRebuildArguments', [$action, null, $option, $text]);
                }
            }
        }
        foreach (PredbIdentityRebuildCommand::SAFE_FAILURES as $message) {
            $this->assertParity(PredbIdentityRebuildCommand::class, 'classifyRebuildFailure', [$message]);
        }
        foreach ([0, 1, 49, 50, 51, 200] as $count) {
            $ids = array_map(static fn (int $i): string => (string) ($i % 7 + 1), range(1, max(1, $count)));
            $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', ['q', null, $count === 0 ? [] : array_slice($ids, 0, $count)]);
            $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', [null, '9', $count === 0 ? [] : array_slice($ids, 0, $count)]);
        }
        $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', ['q', null, ['']]);
        $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', ['q', null, ['', '']]);
        $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', [str_repeat('é', 256), null, ['1']]);
        $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', [str_repeat('é', 256).'a', null, ['1']]);
        $long = str_repeat("Long.Title\x1F-Grp \t", 3000);
        $this->assertParity(AdminPredbController::class, 'planPredbBrowse', [$long]);
        $this->assertParity(PredbImport::class, 'normalizeImportTitle', [$long, 0]);
        $this->assertParity(PredbImport::class, 'normalizeImportTitle', [$long, 9999]);
        $this->assertParity(PredbImport::class, 'summarizeNukeStatus', [3, $long]);
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3730));
        $alphabet = ['a', 'Z', '0', '1', '7', '9', '+', '-', '.', '_', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", 'é', '日', "\u{1F3AC}", "\x1F", "\x1E", "\x07", "\x7F", "\xff", "\xc3", 'f', 'A'];
        $text = static function (int $max = 24) use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $digits = static fn (int $max = 22): string => implode('', array_map(static fn (): string => (string) $random->getInt(0, 9), range(0, $random->getInt(0, $max))));
        $pad = static fn (string $value): string => match ($random->getInt(0, 4)) {
            0 => ' '.$value,
            1 => $value."\n",
            2 => "\t+".$value,
            3 => '-'.$value,
            default => $value,
        };
        $nullable = static fn (string $value): ?string => $random->getInt(0, 5) === 0 ? null : $value;
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-3, 6),
            1 => $random->getInt(0, 64),
            2 => $random->getInt(-1_000_000, 1_000_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $hex = static fn (int $length): string => substr(bin2hex($random->getBytes(intdiv($length + 1, 2))), 0, $length);
        $candidate = static fn (): string => match ($random->getInt(0, 6)) {
            0 => $text(4),
            1 => '0',
            2 => '00'.$random->getInt(1, 99),
            default => (string) $random->getInt(1, 500),
        };

        for ($i = 0; $i < 1500; $i++) {
            $this->assertParity(PredbCheck::class, 'planCheckLimit', [$nullable($random->getInt(0, 1) === 1 ? $pad($digits()) : $text(10))]);

            $action = [null, 'start', 'chunk', 'certify', 'Start', 'chunk ', $text(6)][$random->getInt(0, 6)];
            $generation = [null, $hex(32), strtoupper($hex(32)), $hex(31), $hex(33), $text(32)][$random->getInt(0, 5)];
            $chunk = [null, '128', (string) $random->getInt(0, 400), '0'.$random->getInt(1, 99), $text(3), $digits(5)][$random->getInt(0, 5)];
            $this->assertParity(PredbIdentityRebuildCommand::class, 'validateRebuildArguments', [$action, $generation, $random->getInt(0, 1) === 1, $chunk]);

            $safe = PredbIdentityRebuildCommand::SAFE_FAILURES[$random->getInt(0, count(PredbIdentityRebuildCommand::SAFE_FAILURES) - 1)];
            $failure = match ($random->getInt(0, 3)) {
                0 => $safe,
                1 => $safe.$text(2),
                2 => strtolower($safe),
                default => $text(40),
            };
            $this->assertParity(PredbIdentityRebuildCommand::class, 'classifyRebuildFailure', [$failure]);

            $count = $random->getInt(0, 9) === 0 ? $random->getInt(45, 60) : $random->getInt(0, 6);
            $ids = $count === 0 ? [] : array_map(static fn (): string => $candidate(), range(1, $count));
            $query = match ($random->getInt(0, 5)) {
                0 => null,
                1 => '',
                2 => str_repeat('x', $random->getInt(500, 520)),
                default => $text(40),
            };
            $release = [null, '', (string) $random->getInt(1, 99999), $text(4)][$random->getInt(0, 3)];
            $this->assertParity(ReleaseIdentitySuggestions::class, 'planSuggestionRequest', [$query, $release, $ids]);

            $this->assertParity(AdminPredbController::class, 'planPredbBrowse', [$random->getInt(0, 9) === 0 ? '0' : $text(40)]);
            $this->assertParity(PredbImport::class, 'summarizeNukeStatus', [$int(), $nullable($text(20))]);
            $title = $random->getInt(0, 1) === 1 ? $text(12).'-'.$text(5) : $text(40);
            $this->assertParity(PredbImport::class, 'normalizeImportTitle', [$title, $random->getInt(0, 2) === 0 ? 0 : $int()]);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        foreach ([
            ['nntmux_w373_predb_check_limit_plan', [1, ' +025 ', 6], "1\x1F0\x1F1\x1F25\x1F0\x1Fbounded\x1Fok"],
            ['nntmux_w373_predb_rebuild_validate', [15, 'chunk', 5, self::GENERATION, 32, '64', 2], "1\x1F0\x1F1\x1F64\x1F1\x1F0"],
            ['nntmux_w373_predb_rebuild_failure', ['PreDB rebuild inventory changed.', 32], "1\x1F4"],
            ['nntmux_w373_release_identity_plan', [1, 'Some.Release-GRP', 16, null, 0, "007\x1F7\x1F12", 8, 3], "0\x1F16\x1F1\x1F3\x1F1\x1F0\x1F2\x1F7\x1F12"],
            ['nntmux_w373_admin_predb_browse', [" foo\x1Fbar ", 9], "1\x1F9\x1F7\x1F0\x1F1\x1F1\x1F foo\x1Fbar "],
            ['nntmux_w373_predb_import_summarize', [0, 1, ' bad.rip ', 9, 2], "2\x1Fnuked\x1F1\x1F1\x1F1\x1F1\x1Fbad.rip"],
            ['nntmux_w373_predb_import_summarize', [1, 1, ' Show.S01-GRP ', 14, 0], "12\x1F1\x1F0\x1F0\x1F1\x1F12\x1FShow.S01-GRP\x1FGRP"],
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
        // Unknown ops or flag bits, missing pointers for present strings and text for absent strings decline.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_check_limit_plan', 2, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_check_limit_plan', 1, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_check_limit_plan', 0, 'x', 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_rebuild_validate', 16, null, 0, null, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_rebuild_validate', 1, null, 0, null, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_rebuild_validate', 0, null, 0, 'x', 1, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_rebuild_failure', null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_admin_predb_browse', null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_release_identity_plan', 4, null, 0, null, 0, '', 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_release_identity_plan', 0, null, 0, null, 0, null, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_release_identity_plan', 2, null, 0, null, 0, '', 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_release_identity_plan', 0, null, 0, null, 0, '1', 1, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_release_identity_plan', 0, null, 0, null, 0, '1', 1, 2, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_import_summarize', 2, 1, 'x', 1, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_import_summarize', 0, 2, null, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_import_summarize', 1, 0, null, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w373_predb_import_summarize', 0, 1, null, 0, 0, $out, 256));
        $this->assertGreaterThan(0, $this->callFfi($ffi, 'nntmux_w373_predb_import_summarize', 0, 0, null, 0, 0, $out, 256));

        foreach (self::CLASSES as $class) {
            $class::useRustFfi(true);
        }
        PredbCheck::planCheckLimit('1');
        PredbIdentityRebuildCommand::validateRebuildArguments('start', null, false, '128');
        ReleaseIdentitySuggestions::planSuggestionRequest('q', null, []);
        AdminPredbController::planPredbBrowse('q');
        PredbImport::summarizeNukeStatus(2, 'x');
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'rustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'rustFfiHandle'))->setValue(null, false);
            $class::useRustFfi(true);
        }
        $cases = [
            [PredbCheck::class, 'planCheckLimit', [' +025 ']],
            [PredbIdentityRebuildCommand::class, 'validateRebuildArguments', ['chunk', self::GENERATION, true, '64']],
            [PredbIdentityRebuildCommand::class, 'classifyRebuildFailure', ['boom']],
            [ReleaseIdentitySuggestions::class, 'planSuggestionRequest', ['q', null, ['007', '7']]],
            [AdminPredbController::class, 'planPredbBrowse', [' foo ']],
            [PredbImport::class, 'summarizeNukeStatus', [2, ' x ']],
            [PredbImport::class, 'normalizeImportTitle', [' a-b ', 0]],
        ];
        foreach ($cases as [$class, $method, $args]) {
            $this->assertNull($this->nativeMethod($class, $method)->invoke(null, ...$args), $class);
            $this->assertSame($this->phpMethod($class, $method)->invoke(null, ...$args), $class::$method(...$args), $class);
        }
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
            $this->assertNull((new ReflectionProperty($class, 'rustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the seven helpers through their public dispatchers,
        // including the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical limits, rebuild arguments, suggestion requests, browse terms and import
        // rows; uncommon = clamped, invalid, 50-candidate, long, marker-laden and invalid UTF-8 inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.36x / p99 0.39x, uncommon mean 0.93x / p99 0.86x
        // - Round 2: common mean 0.37x / p99 0.39x, uncommon mean 0.93x / p99 1.05x
        // - Round 3: common mean 0.36x / p99 0.35x, uncommon mean 0.86x / p99 0.61x
        // Reproduce:
        // php vendor/bin/phpunit tests/Unit/RustPredbCheckRebuildSuggestionsImportParityTest.php --filter benchmark
        // Microsecond PHP string work is cheaper than the FFI round trip, so every helper stays below
        // the 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3731));
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
        fwrite(STDERR, "\nRustPredbCheckRebuildSuggestionsImport speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::CLASSES as $class) {
                $this->assertFalse(constant($class.'::RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, string $method, array $args): void
    {
        $context = $class.'::'.$method.' '.(json_encode($this->describe($args)) ?: '');
        $class::useRustFfi(false);
        $expected = $class::$method(...$args);
        $this->assertSame($expected, $this->phpMethod($class, $method)->invoke(null, ...$args), $context);
        $native = $this->nativeMethod($class, $method)->invoke(null, ...$args);
        $this->assertNotNull($native, 'native declined: '.$context);
        $this->assertSame($expected, $native, $context);
        $class::useRustFfi(true);
        $this->assertSame($expected, $class::$method(...$args), $context);
        $class::useRustFfi(null);
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
     * @param  class-string  $class
     */
    private function nativeMethod(string $class, string $method): ReflectionMethod
    {
        return $this->natives[$class.'::'.$method.'Native'] ??= new ReflectionMethod($class, $method.'Native');
    }

    /**
     * @param  class-string  $class
     */
    private function phpMethod(string $class, string $method): ReflectionMethod
    {
        return $this->natives[$class.'::'.$method.'Php'] ??= new ReflectionMethod($class, $method.'Php');
    }

    /**
     * @return list<array{class-string, string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            [PredbCheck::class, 'planCheckLimit', ['500']],
            [PredbIdentityRebuildCommand::class, 'validateRebuildArguments', ['chunk', self::GENERATION, true, '128']],
            [PredbIdentityRebuildCommand::class, 'classifyRebuildFailure', ['PreDB rebuild inventory changed.']],
            [ReleaseIdentitySuggestions::class, 'planSuggestionRequest', ['Some.Show.S01E01.720p.WEB.h264-GRP', null, ['1234', '5678']]],
            [AdminPredbController::class, 'planPredbBrowse', ['some show 720p']],
            [PredbImport::class, 'summarizeNukeStatus', [2, 'mislabeled.rip']],
            [PredbImport::class, 'normalizeImportTitle', ['Some.Show.S01E01.720p.WEB.h264-GRP', 255]],
        ];
    }

    /**
     * @return list<array{class-string, string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $ids = array_map(static fn (int $i): string => '00'.$i, range(1, 50));

        return [
            [PredbCheck::class, 'planCheckLimit', [" \t+0099999999999999999999 \n"]],
            [PredbIdentityRebuildCommand::class, 'validateRebuildArguments', ['certify', strtoupper(self::GENERATION), true, '064']],
            [PredbIdentityRebuildCommand::class, 'classifyRebuildFailure', [str_repeat('SQLSTATE[HY000] secret ', 20)]],
            [ReleaseIdentitySuggestions::class, 'planSuggestionRequest', [str_repeat('Ünïcödé.', 60), null, $ids]],
            [ReleaseIdentitySuggestions::class, 'planSuggestionRequest', [null, '42', [...$ids, "1\x1F2"]]],
            [AdminPredbController::class, 'planPredbBrowse', [str_repeat(" term\x1F\t\xff ", 60)]],
            [PredbImport::class, 'summarizeNukeStatus', [PHP_INT_MIN, str_repeat(" \x1F reason \0", 30)]],
            [PredbImport::class, 'normalizeImportTitle', [str_repeat("Long \t Title.\x07-", 40), 300]],
        ];
    }

    /**
     * @param  list<array{class-string, string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::CLASSES as $class) {
            $class::useRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$class, $method, $args]) {
            $count += count($class::$method(...$args));
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
        $cdef = implode("\n", array_map(static fn (string $class): string => $class::FFI_CDEF, self::CLASSES));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w373', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 37.3 exports is not available.');
        }

        return $ffi;
    }
}
