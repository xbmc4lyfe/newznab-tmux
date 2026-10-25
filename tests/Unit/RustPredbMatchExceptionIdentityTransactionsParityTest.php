<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\FindSizeMismatchedReleases;
use App\Exceptions\IncompletePredbMatchException;
use App\Http\Controllers\Admin\AdminCategoryRegexesController;
use App\Http\Controllers\Admin\AdminCollectionRegexesController;
use App\Services\Categorization\Pipes\MiscSafetyNetPipe;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityPolicy;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\Predb\PredbIdentityWriter;
use App\Support\RustPipelineLibrary;
use App\Traits\OwnsPredbIdentityTransactions;
use FFI;
use Illuminate\Console\Command;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class RustPredbMatchExceptionIdentityTransactionsParityTest extends TestCase
{
    /**
     * Toggle names per target; classes that already own a `useRustFfi` toggle use distinct names.
     * MiscSafetyNetPipe hosts the OwnsPredbIdentityTransactions plans so the pinned trait is unchanged.
     *
     * @var array<class-string, array{use: string, uses: string, reset: string, handle: string, default: string, cdef: string}>
     */
    private const TARGETS = [
        IncompletePredbMatchException::class => ['use' => 'useRustFfi', 'uses' => 'usesRustFfi', 'reset' => 'resetRustFfi', 'handle' => 'rustFfiHandle', 'default' => 'RUST_FFI_DEFAULT', 'cdef' => 'FFI_CDEF'],
        MiscSafetyNetPipe::class => ['use' => 'usePredbTransactionRustFfi', 'uses' => 'usesPredbTransactionRustFfi', 'reset' => 'resetPredbTransactionRustFfi', 'handle' => 'predbTransactionRustFfiHandle', 'default' => 'PREDB_TRANSACTION_RUST_FFI_DEFAULT', 'cdef' => 'PREDB_TRANSACTION_FFI_CDEF'],
        FindSizeMismatchedReleases::class => ['use' => 'useSizeMismatchRustFfi', 'uses' => 'usesSizeMismatchRustFfi', 'reset' => 'resetSizeMismatchRustFfi', 'handle' => 'sizeMismatchRustFfiHandle', 'default' => 'RUST_FFI_DEFAULT', 'cdef' => 'FFI_CDEF'],
        AdminCategoryRegexesController::class => ['use' => 'useRegexListRustFfi', 'uses' => 'usesRegexListRustFfi', 'reset' => 'resetRegexListRustFfi', 'handle' => 'regexListRustFfiHandle', 'default' => 'RUST_FFI_DEFAULT', 'cdef' => 'FFI_CDEF'],
        AdminCollectionRegexesController::class => ['use' => 'useRegexPatternRustFfi', 'uses' => 'usesRegexPatternRustFfi', 'reset' => 'resetRegexPatternRustFfi', 'handle' => 'regexPatternRustFfiHandle', 'default' => 'RUST_FFI_DEFAULT', 'cdef' => 'FFI_CDEF'],
    ];

    private const PREFIX = 'PreDB fulltext matching is incomplete: ';

    /** Strings that exercise trimming, signs, zeros, separators, LIKE wildcards and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', '0', '00', '000', '7', '007', '+7', '-7', '1e3', '1.5', ' 12 ', "\t42\n", "\0 9 \x0B\x0C", '1000000',
        '1000001', '0001000000', '99999999999999999999999', 'bigger', 'smaller', 'any', 'Bigger', 'any ', 'a_b', '100%',
        '\\', '\\%', 'x\\_y', "x\x1Fy", "\x1F", "\x1F\x1F", "nul\0byte", 'Ünïcödé', "bad \xff utf8", "\xc3", '/a/i',
        '#^(?P<grp>a.b)$#iu', '{x{y}z}m', '<a>', '(?<x>', "(?'n'x)", '(?<=x)(?<!y)', '\\(?<z>', 'abc/', '\\a', "\0/",
        " \t/x/", "\xA0/x/", '//', '/a\\', 'PreDB identity cleanup did not finish.',
        self::PREFIX, self::PREFIX.'the 210-candidate scan budget was reached.', self::PREFIX.'x',
    ];

    /** Integers around every boundary the helpers use. */
    private const INTS = [PHP_INT_MIN, -281474976710657, -5, -1, 0, 1, 2, 3, 99, 100, 10_000, 100_000_000, 100_000_001, 281474976710656, 281474976710657, PHP_INT_MAX];

    /** @var array<string, ReflectionMethod> */
    private array $methods = [];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class => $names) {
            $class::{$names['reset']}();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::TARGETS as $class => $names) {
            $this->assertFalse(constant($class.'::'.$names['default']), $class);
            $this->assertFalse($class::{$names['uses']}(), $class);
            $class::{$names['use']}(true);
            $this->assertTrue($class::{$names['uses']}(), $class);
            $class::{$names['use']}(false);
            $this->assertFalse($class::{$names['uses']}(), $class);
            $class::{$names['use']}(true);
            $class::{$names['reset']}();
            $this->assertFalse($class::{$names['uses']}(), $class);
            $class::{$names['use']}(null);
            $this->assertFalse($class::{$names['uses']}(), $class);
        }

        // The PreDB transaction toggle never touches the pipe's own categorization toggle.
        MiscSafetyNetPipe::usePredbTransactionRustFfi(true);
        $this->assertFalse(MiscSafetyNetPipe::$useRustFfi);
        MiscSafetyNetPipe::resetPredbTransactionRustFfi();

        AdminCategoryRegexesController::useRegexListRustFfi(true);
        AdminCollectionRegexesController::useRegexPatternRustFfi(true);
        $this->assertFalse(AdminCategoryRegexesController::usesRustFfi());
        $this->assertFalse(AdminCollectionRegexesController::usesRustFfi());
        FindSizeMismatchedReleases::useSizeMismatchRustFfi(true);
        $this->assertFalse((new FindSizeMismatchedReleases)->useRustFfi);
    }

    public function test_existing_contracts_and_policy_pin_are_unchanged(): void
    {
        $exception = new IncompletePredbMatchException('m', 3);
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertTrue((new ReflectionClass(IncompletePredbMatchException::class))->isFinal());
        $this->assertSame(['m', 3], [$exception->getMessage(), $exception->getCode()]);

        $command = new FindSizeMismatchedReleases;
        $this->assertInstanceOf(Command::class, $command);
        $this->assertSame('nntmux:find-size-mismatches', $command->getName());
        $this->assertSame('', $command->stripDomainFromStringPhp('www.example.com'));

        $this->assertSame(['error' => '', 'input' => ['id' => '4'], 'id' => 4], AdminCategoryRegexesController::validateForm('view', ['id' => '4']));
        $this->assertSame(['group' => 'g', 'regex' => 'r', 'limit' => 50], AdminCollectionRegexesController::normalizeTestParameters(['group' => ' g ', 'regex' => ' r ']));
        $this->assertSame('', AdminCollectionRegexesController::defaultRegex()['regex']);

        $policy = new PredbIdentityPolicy;
        $this->assertTrue($policy->definitionIsCurrent(OwnsPredbIdentityTransactions::class, 'PREDB_OWNED_TRANSACTION_POLICY'));
        $this->assertNotNull($policy->fingerprint());
        foreach ([PredbIdentityRebuild::class, PredbIdentityWriter::class, ReleaseUpdateService::class] as $class) {
            $this->assertTrue(method_exists($class, 'runOwnedPredbTransaction'), $class);
            $this->assertFalse(method_exists($class, 'planPredbTransactionRetry'), $class);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['incomplete' => true, 'reason' => 'scan_budget', 'detail' => 'the 210-candidate scan budget was reached.'],
            IncompletePredbMatchException::summarizeIncompleteMatch(self::PREFIX.'the 210-candidate scan budget was reached.'),
        );
        $this->assertSame(['incomplete' => true, 'reason' => 'other', 'detail' => "x\x1Fy"], IncompletePredbMatchException::summarizeIncompleteMatch(self::PREFIX."x\x1Fy"));
        $this->assertSame(['incomplete' => false, 'reason' => 'foreign', 'detail' => ''], IncompletePredbMatchException::summarizeIncompleteMatch('the 210-candidate scan budget was reached.'));
        foreach (IncompletePredbMatchException::REASONS as $reason => $detail) {
            $this->assertSame($reason, IncompletePredbMatchException::summarizeIncompleteMatch(self::PREFIX.$detail)['reason']);
        }

        $this->assertSame(['action' => 'rethrow_committed', 'cleanup' => false, 'next_attempt' => 1, 'prefer_body_failure' => false], MiscSafetyNetPipe::planPredbTransactionRetry(1, 3, true, true, true));
        $this->assertSame(['action' => 'retry', 'cleanup' => true, 'next_attempt' => 2, 'prefer_body_failure' => true], MiscSafetyNetPipe::planPredbTransactionRetry(1, 3, false, true, true));
        $this->assertSame(['action' => 'rethrow', 'cleanup' => true, 'next_attempt' => 3, 'prefer_body_failure' => false], MiscSafetyNetPipe::planPredbTransactionRetry(3, 3, false, true, false));
        $this->assertSame(['action' => 'rethrow', 'cleanup' => true, 'next_attempt' => 1, 'prefer_body_failure' => false], MiscSafetyNetPipe::planPredbTransactionRetry(1, 3, false, false, false));
        $this->assertSame(['owned' => true, 'reason' => 'connection_unusable', 'discard' => true], MiscSafetyNetPipe::classifyPredbTransactionFailure('PreDB identity connection cleanup failed; discard this context.'));
        $this->assertSame(['owned' => false, 'reason' => 'foreign', 'discard' => false], MiscSafetyNetPipe::classifyPredbTransactionFailure('PreDB identity cleanup did not finish'));
        // Every message OwnsPredbIdentityTransactions throws is classified.
        $source = (string) file_get_contents((string) (new ReflectionClass(OwnsPredbIdentityTransactions::class))->getFileName());
        preg_match_all("/new RuntimeException\\('([^']+)'/", $source, $thrown);
        $this->assertNotEmpty($thrown[1]);
        foreach ($thrown[1] as $message) {
            $this->assertTrue(MiscSafetyNetPipe::classifyPredbTransactionFailure($message)['owned'], $message);
        }

        $this->assertSame(
            ['threshold_valid' => true, 'threshold' => 20, 'limit_valid' => true, 'limit' => 100, 'limited' => true, 'direction' => 'any', 'direction_known' => true, 'order_column' => 'diff_percent', 'order_direction' => 'desc'],
            FindSizeMismatchedReleases::planSizeMismatchQuery(null, null, null, false),
        );
        $this->assertSame(
            ['threshold_valid' => false, 'threshold' => 20, 'limit_valid' => true, 'limit' => 0, 'limited' => false, 'direction' => 'any', 'direction_known' => false, 'order_column' => 'releases.id', 'order_direction' => 'asc'],
            FindSizeMismatchedReleases::planSizeMismatchQuery('-5', ' 000 ', 'up', true),
        );
        $this->assertSame(1_000_000, FindSizeMismatchedReleases::planSizeMismatchQuery('99999999999', null, 'bigger', false)['threshold']);
        $this->assertSame(
            ['valid' => true, 'measurable' => true, 'diff' => 300, 'basis_points' => 3000, 'sign' => 'bigger', 'mismatch' => true],
            FindSizeMismatchedReleases::classifySizeDifference(1000, 700, 2000, 'any'),
        );
        $this->assertSame(
            ['valid' => true, 'measurable' => true, 'diff' => -1, 'basis_points' => -3333, 'sign' => 'smaller', 'mismatch' => true],
            FindSizeMismatchedReleases::classifySizeDifference(3, 4, 3000, 'smaller'),
        );
        $this->assertFalse(FindSizeMismatchedReleases::classifySizeDifference(3, 4, 3000, 'bigger')['mismatch']);
        $this->assertFalse(FindSizeMismatchedReleases::classifySizeDifference(0, 4, 0, 'any')['measurable']);
        $this->assertSame('none', FindSizeMismatchedReleases::classifySizeDifference(-1, 4, 0, 'any')['sign']);

        $this->assertSame(
            ['filtered' => true, 'has_wildcards' => true, 'length' => 5, 'pattern' => '%a_b%\\%', 'escaped_pattern' => '%a\\_b\\%\\\\%'],
            AdminCategoryRegexesController::planRegexListFilter('a_b%\\'),
        );
        $this->assertSame(['filtered' => false, 'has_wildcards' => false, 'length' => 0, 'pattern' => '', 'escaped_pattern' => ''], AdminCategoryRegexesController::planRegexListFilter(''));

        $this->assertSame(
            ['delimited' => true, 'delimiter' => '/', 'closed' => true, 'body_length' => 44, 'modifiers_valid' => true, 'named_groups' => 3, 'modifiers' => 'iu'],
            AdminCollectionRegexesController::summarizeRegexPattern('/^(?P<name>a)(?<x>b)(?\'y\'c)(?<=d)(?<!e)\\(?<z>/iu'),
        );
        $this->assertSame(
            ['delimited' => true, 'delimiter' => '{', 'closed' => true, 'body_length' => 5, 'modifiers_valid' => true, 'named_groups' => 0, 'modifiers' => 'x '],
            AdminCollectionRegexesController::summarizeRegexPattern('  {a{b}c}x '),
        );
        $this->assertFalse(AdminCollectionRegexesController::summarizeRegexPattern('(a)e')['modifiers_valid']);
        $this->assertFalse(AdminCollectionRegexesController::summarizeRegexPattern('#a\\#b')['closed']);
        $this->assertFalse(AdminCollectionRegexesController::summarizeRegexPattern('abc')['delimited']);
        // A structurally valid summary agrees with PHP's own delimiter handling.
        foreach (['/a/i', '#^(?P<g>x)$#u', '{a{b}c}', '~x~imsxuADSUJn'] as $pattern) {
            $this->assertTrue(AdminCollectionRegexesController::summarizeRegexPattern($pattern)['modifiers_valid'], $pattern);
            $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        }
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $messages = [...self::TEXTS, ...array_map(static fn (string $d): string => self::PREFIX.$d, IncompletePredbMatchException::REASONS)];
        foreach (MiscSafetyNetPipe::PREDB_TRANSACTION_FAILURES as [$message]) {
            $messages[] = $message;
            $messages[] = $message.' ';
            $messages[] = strtolower($message);
        }
        foreach ($messages as $message) {
            $this->assertParity(IncompletePredbMatchException::class, 'summarizeIncompleteMatch', [$message]);
            $this->assertParity(MiscSafetyNetPipe::class, 'classifyPredbTransactionFailure', [$message]);
            $this->assertParity(AdminCategoryRegexesController::class, 'planRegexListFilter', [$message]);
            $this->assertParity(AdminCollectionRegexesController::class, 'summarizeRegexPattern', [$message]);
        }
        foreach (self::INTS as $attempt) {
            foreach ([PHP_INT_MIN, 0, 1, 3, $attempt, PHP_INT_MAX] as $attempts) {
                foreach (range(0, 7) as $flags) {
                    $this->assertParity(MiscSafetyNetPipe::class, 'planPredbTransactionRetry', [$attempt, $attempts, ($flags & 1) !== 0, ($flags & 2) !== 0, ($flags & 4) !== 0]);
                }
            }
        }
        $options = [null, ...self::TEXTS];
        foreach ($options as $index => $value) {
            $other = $options[($index * 7 + 3) % count($options)];
            foreach ([null, 'bigger', 'smaller', 'any', $value] as $direction) {
                $this->assertParity(FindSizeMismatchedReleases::class, 'planSizeMismatchQuery', [$value, $other, $direction, $index % 2 === 0]);
                $this->assertParity(FindSizeMismatchedReleases::class, 'planSizeMismatchQuery', [$other, $value, $direction, $index % 2 === 1]);
            }
        }
        foreach (self::INTS as $release) {
            foreach (self::INTS as $files) {
                foreach ([0, 2000, 100_000_000, PHP_INT_MIN, 100_000_001] as $threshold) {
                    foreach (['bigger', 'smaller', 'any', 'Any', ''] as $direction) {
                        $this->assertParity(FindSizeMismatchedReleases::class, 'classifySizeDifference', [$release, $files, $threshold, $direction]);
                    }
                }
            }
        }
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3831));
        $alphabet = ['a', 'Z', '0', '7', ' ', "\t", "\n", "\x0B", "\x0C", "\0", "\x1F", '%', '_', '\\', '/', '#', '~', '(', ')', '[', ']', '{', '}',
            '<', '>', '?', 'P', "'", '=', '!', '-', '+', '.', 'i', 'u', 'x', 'e', "\xff", "\xc3\xa9", "\x80"];
        $text = static function (int $max = 24) use ($random, $alphabet): string {
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
            1 => $random->getInt(-3, 12),
            2 => $random->getInt(0, 281474976710656),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $digits = static function () use ($random, $text, $pick): string {
            return match ($random->getInt(0, 3)) {
                0 => (string) $random->getInt(0, 2_000_000),
                1 => $pick(['', ' ', "\t", '0', '00']).str_repeat('0', $random->getInt(0, 4)).$random->getInt(0, PHP_INT_MAX).$pick(['', ' ', "\n"]),
                2 => $pick(['-', '+', '', '1e', '.']).$random->getInt(0, 999),
                default => $text(10),
            };
        };
        $failures = array_column(MiscSafetyNetPipe::PREDB_TRANSACTION_FAILURES, 0);
        $delimiters = ['/', '#', '~', '(', '[', '{', '<', 'a', '\\', "\0", "\x1F", "\xff", '!', '%'];
        $closers = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

        for ($i = 0; $i < 1200; $i++) {
            $message = match ($random->getInt(0, 4)) {
                0 => self::PREFIX.$pick(array_values(IncompletePredbMatchException::REASONS)),
                1 => self::PREFIX.$text(),
                2 => substr(self::PREFIX, 0, $random->getInt(0, strlen(self::PREFIX))).$text(8),
                3 => $pick($failures).$pick(['', ' ', '.', $text(3)]),
                default => $text(40),
            };
            $this->assertParity(IncompletePredbMatchException::class, 'summarizeIncompleteMatch', [$message]);
            $this->assertParity(MiscSafetyNetPipe::class, 'classifyPredbTransactionFailure', [$random->getInt(0, 1) === 1 ? $pick($failures) : $message]);
            $this->assertParity(MiscSafetyNetPipe::class, 'planPredbTransactionRetry', [
                $int(), $random->getInt(0, 1) === 1 ? $random->getInt(-2, 6) : $int(),
                $random->getInt(0, 3) === 0, $random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1,
            ]);
            $this->assertParity(FindSizeMismatchedReleases::class, 'planSizeMismatchQuery', [
                $random->getInt(0, 4) === 0 ? null : $digits(),
                $random->getInt(0, 4) === 0 ? null : $digits(),
                $random->getInt(0, 4) === 0 ? null : $pick(['bigger', 'smaller', 'any', 'Bigger', ' any', $text(6)]),
                $random->getInt(0, 1) === 1,
            ]);
            $release = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 1 << 40);
            $files = $random->getInt(0, 3) === 0 || abs($release) > 1 << 50 ? $int() : max(0, $release + $random->getInt(-(1 << 30), 1 << 30));
            $this->assertParity(FindSizeMismatchedReleases::class, 'classifySizeDifference', [
                $release, $files, $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 10_000), $pick(['bigger', 'smaller', 'any', 'any', $text(4)]),
            ]);
            $this->assertParity(AdminCategoryRegexesController::class, 'planRegexListFilter', [$text(32)]);
            $delimiter = $pick($delimiters);
            $pattern = $pick(['', ' ', "\t\n", "\x0B"]).$delimiter
                .$text(12).$pick(['', '(?<n>', '(?P<n>', "(?'n'", '(?<=', '\\(?<x>', $delimiter]).$text(8)
                .($random->getInt(0, 3) === 0 ? '' : ($closers[$delimiter] ?? $delimiter))
                .$pick(['', 'i', 'iu', 'msx', "u\n", 'e', 'ADSUXJnr', $text(3)]);
            $this->assertParity(AdminCollectionRegexesController::class, 'summarizeRegexPattern', [$pattern]);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        foreach ([
            ['nntmux_w383_incomplete_match_summary', [self::PREFIX.'x', strlen(self::PREFIX) + 1], "1\x1Fother\x1Fx"],
            ['nntmux_w383_predb_transaction_retry', [2, 1, 3], "retry\x1F1\x1F2\x1F0"],
            ['nntmux_w383_predb_transaction_failure', ['PreDB identity cleanup did not finish.', 38], "1\x1Fcleanup_incomplete\x1F0"],
            ['nntmux_w383_size_mismatch_plan', [15, ' 007 ', 5, '0', 1, 'smaller', 7], "1\x1F7\x1F1\x1F0\x1F0\x1Fsmaller\x1F1\x1Freleases.id\x1Fasc"],
            ['nntmux_w383_size_difference', ['any', 3, 1000, 700, 2000], "1\x1F1\x1F300\x1F3000\x1Fbigger\x1F1"],
            ['nntmux_w383_regex_list_filter', ["a_\x1F", 3], "1\x1F1\x1F3\x1F5\x1F%a_\x1F%\x1F%a\\_\x1F%"],
            ['nntmux_w383_regex_pattern_summary', [' #(?<n>a)#i', 11], "1\x1F35\x1F1\x1F7\x1F1\x1F1\x1Fi"],
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
        // Unknown flag bits, null input pointers, missing pointers for present strings and text for
        // absent strings decline.
        foreach (['nntmux_w383_incomplete_match_summary', 'nntmux_w383_predb_transaction_failure', 'nntmux_w383_regex_list_filter', 'nntmux_w383_regex_pattern_summary'] as $symbol) {
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, null, 0, $out, 256), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, '', 0, $out, 256), $symbol);
        }
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w383_predb_transaction_retry', 8, 1, 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w383_size_mismatch_plan', 16, null, 0, null, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w383_size_mismatch_plan', 1, null, 0, null, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w383_size_mismatch_plan', 4, null, 0, null, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w383_size_mismatch_plan', 0, null, 0, 'x', 1, null, 0, $out, 256));
        $this->assertGreaterThan(0, $this->callFfi($ffi, 'nntmux_w383_size_mismatch_plan', 8, null, 0, null, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w383_size_difference', null, 0, 1, 1, 1, $out, 256));

        foreach (self::TARGETS as $class => $names) {
            $class::{$names['use']}(true);
        }
        IncompletePredbMatchException::summarizeIncompleteMatch('x');
        MiscSafetyNetPipe::planPredbTransactionRetry(1, 1, false, false, false);
        FindSizeMismatchedReleases::planSizeMismatchQuery(null, null, null, false);
        AdminCategoryRegexesController::planRegexListFilter('x');
        AdminCollectionRegexesController::summarizeRegexPattern('/x/');
        foreach (self::TARGETS as $class => $names) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, $names['handle']))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::TARGETS as $class => $names) {
            (new ReflectionProperty($class, $names['handle']))->setValue(null, false);
            $class::{$names['use']}(true);
        }
        $cases = [
            [IncompletePredbMatchException::class, 'summarizeIncompleteMatch', [self::PREFIX.'x']],
            [MiscSafetyNetPipe::class, 'planPredbTransactionRetry', [1, 3, false, true, false]],
            [MiscSafetyNetPipe::class, 'classifyPredbTransactionFailure', ['PreDB identity cleanup did not finish.']],
            [FindSizeMismatchedReleases::class, 'planSizeMismatchQuery', [' 5 ', null, 'bigger', true]],
            [FindSizeMismatchedReleases::class, 'classifySizeDifference', [10, 5, 100, 'any']],
            [AdminCategoryRegexesController::class, 'planRegexListFilter', ['a%']],
            [AdminCollectionRegexesController::class, 'summarizeRegexPattern', ['/(?<n>x)/i']],
        ];
        foreach ($cases as [$class, $method, $args]) {
            $this->assertNull($this->method($class, $method.'Native')->invoke(null, ...$args), $class);
            $this->assertSame($this->method($class, $method.'Php')->invoke(null, ...$args), $class::$method(...$args), $class);
        }
        foreach (self::TARGETS as $class => $names) {
            $class::{$names['reset']}();
            $this->assertNull((new ReflectionProperty($class, $names['handle']))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the seven helpers through their public dispatchers,
        // including the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical incomplete-match messages, retry decisions, PreDB transaction failures, default
        // command options, ordinary size rows, short group filters and simple collection regexes;
        // uncommon = foreign/long messages, extreme integers, clamped/invalid options, invalid
        // sizes, long wildcard-laden filters and long nested/escaped patterns.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.62x / p99 0.59x, uncommon mean 0.70x / p99 0.70x
        // - Round 2: common mean 0.62x / p99 0.65x, uncommon mean 0.69x / p99 0.68x
        // - Round 3: common mean 0.62x / p99 0.60x, uncommon mean 0.69x / p99 0.71x
        // Reproduce:
        // php vendor/bin/phpunit tests/Unit/RustPredbMatchExceptionIdentityTransactionsParityTest.php --filter benchmark
        // Microsecond PHP string work is cheaper than the FFI round trip, so every helper stays below
        // the 1.5x bar in .ai/rules/rust-performance.md and keeps the defaults false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3832));
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
            foreach (self::TARGETS as $class => $names) {
                $class::{$names['use']}(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
                'samples' => $samples,
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustPredbMatchExceptionIdentityTransactions speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::TARGETS as $class => $names) {
                $this->assertFalse(constant($class.'::'.$names['default']), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::{$names['uses']}(), $class.' must keep PHP below 1.5x: '.$summary);
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
        $names = self::TARGETS[$class];
        $context = $class.'::'.$method.' '.(json_encode($this->describe($args)) ?: '');
        $class::{$names['use']}(false);
        $expected = $class::$method(...$args);
        $this->assertSame($expected, $this->method($class, $method.'Php')->invoke(null, ...$args), $context);
        $native = $this->method($class, $method.'Native')->invoke(null, ...$args);
        $this->assertNotNull($native, 'native declined: '.$context);
        $this->assertSame($expected, $native, $context);
        $class::{$names['use']}(true);
        $this->assertSame($expected, $class::$method(...$args), $context);
        $class::{$names['use']}(null);
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
    private function method(string $class, string $method): ReflectionMethod
    {
        return $this->methods[$class.'::'.$method] ??= new ReflectionMethod($class, $method);
    }

    /**
     * @return list<array{class-string, string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            [IncompletePredbMatchException::class, 'summarizeIncompleteMatch', [self::PREFIX.'the 210-candidate scan budget was reached.']],
            [MiscSafetyNetPipe::class, 'planPredbTransactionRetry', [1, 3, false, true, true]],
            [MiscSafetyNetPipe::class, 'classifyPredbTransactionFailure', ['PreDB identity connection ownership changed.']],
            [FindSizeMismatchedReleases::class, 'planSizeMismatchQuery', ['20', '100', 'any', false]],
            [FindSizeMismatchedReleases::class, 'classifySizeDifference', [4_294_967_296, 3_221_225_472, 2000, 'bigger']],
            [AdminCategoryRegexesController::class, 'planRegexListFilter', ['alt.binaries.teevee']],
            [AdminCollectionRegexesController::class, 'summarizeRegexPattern', ['/^(?P<match0>.+?)(\.part\d+)?(\.rar)?$/i']],
        ];
    }

    /**
     * @return list<array{class-string, string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        return [
            [IncompletePredbMatchException::class, 'summarizeIncompleteMatch', [str_repeat("SQLSTATE[HY000] secret \x1F", 20)]],
            [MiscSafetyNetPipe::class, 'planPredbTransactionRetry', [PHP_INT_MIN, PHP_INT_MAX, false, true, false]],
            [MiscSafetyNetPipe::class, 'classifyPredbTransactionFailure', [str_repeat('PreDB identity cleanup did not finish. ', 10)]],
            [FindSizeMismatchedReleases::class, 'planSizeMismatchQuery', [" \t+0099999999999999999999 \n", '0001000001', 'Bigger', true]],
            [FindSizeMismatchedReleases::class, 'classifySizeDifference', [281474976710657, -1, PHP_INT_MAX, 'sideways']],
            [AdminCategoryRegexesController::class, 'planRegexListFilter', [str_repeat("a_b%\\\x1F\xff", 40)]],
            [AdminCollectionRegexesController::class, 'summarizeRegexPattern', [" \t{".str_repeat('(?<n>a{1}\\}\\{)(?P<m>[^}]+)', 12).'}imsxuADSUXJn']],
        ];
    }

    /**
     * @param  list<array{class-string, string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::TARGETS as $class => $names) {
            $class::{$names['use']}($useRust);
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
        $cdef = implode("\n", array_map(static fn (string $class, array $names): string => constant($class.'::'.$names['cdef']), array_keys(self::TARGETS), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w383', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 38.3 exports is not available.');
        }

        return $ffi;
    }
}
