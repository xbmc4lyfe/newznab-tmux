<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ReleasesCleanSubjectNames;
use App\Http\Controllers\Admin\AdminBlacklistController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminReleaseNamingRegexesController;
use App\Http\Controllers\Admin\AdminReleasesController;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustAdminRegexCategoryGroupReleaseW39ParityTest extends TestCase
{
    private const CLASSES = [
        AdminReleaseNamingRegexesController::class,
        AdminCategoryController::class,
        AdminReleasesController::class,
        AdminBlacklistController::class,
        ReleasesCleanSubjectNames::class,
    ];

    /** Helper name => owning class. */
    private const HELPERS = [
        'summarizeNamingRegexAudit' => AdminReleaseNamingRegexesController::class,
        'planCategoryHierarchySummary' => AdminCategoryController::class,
        'summarizeReleaseAdminBatch' => AdminReleasesController::class,
        'summarizeBlacklistRuleHealth' => AdminBlacklistController::class,
        'planCleanSubjectDryRunSummary' => ReleasesCleanSubjectNames::class,
    ];

    /** Helpers whose public entry point replaces a non-positive `now` argument (index 1) with time(). */
    private const CLOCKED = ['summarizeReleaseAdminBatch' => 2, 'summarizeBlacklistRuleHealth' => 1];

    private const RANDOM_CASES = 1_200;

    private const NOW = 1_792_931_696;

    private const PATTERNS = [
        null, 5, '', '/', '//', '/abc/i', '/abc/e', '/abc/g', '#a\\#b#imsxADSUXJun', "/a/ i\n\r", '/a', '/a\\/', '/a\\', 'a/b/', '\\a\\', ' /a/', "\0a\0",
        '(a(b)c)i', '(a(b)c', '{a\\}}', '[a]]', '<a>', "\xffa\xff", '~^(?P<name>.+)~', '/(?P<reqid>\\d+)(?<parts>x)(?\'name\'y)/',
        '/(?P<reqids>\\d+)(?<part>x)(?P\'name\'y)/', '/^\[(?P<reqid>\d+)\]-\[(?P<parts>\d+\/\d+)\] "(?P<name>.+)"/i',
    ];

    private const GROUPS = [null, 3, '', ' ', '*', '.*', ' .* ', ".+\n", '^.*$', '^.*', '.*$', "\0.*\x0b", '.*?', 'alt.binaries.*', '^alt\\.binaries\\.teevee$', "\x0c.*"];

    private const INTS = [
        null, true, false, 0, 1, 2, 3, -1, 7, 100, PHP_INT_MAX, PHP_INT_MIN, 1.0, 99.5, '', '0', '1', '2', '-1', '-0', '007', '1.0', ' 1', '1 ', '+1', '1e3', '0x1',
        '999999999999999999', '1234567890123456789', '-', 'abc',
    ];

    private const COMPLETIONS = [
        null, 0, 99, 100, -5, 99.999, 100.0, 100.5, NAN, INF, -INF, '99.5', '0099.5', '100', '100.0', '0', '99.', '.5', '-1', '1e1', ' 99', 'x', true,
    ];

    private const DATES = [
        null, 0, 5, -1, self::NOW, self::NOW - 3_600, self::NOW - 604_800, self::NOW - 604_801, PHP_INT_MAX, 1.5, '', '1970-01-01 00:00:00', '1969-12-31',
        '0000-00-00 00:00:00', '2026-10-25 12:34:56', '2026-10-18 12:34:56', '2026-10-18 12:34:57', '2026-07-27', '2024-02-31', '2026-13-01', '2026-10-32',
        '2026-10-25T12:34:56', '2026-10-25 24:00:00', '2026/10/25', '2026-1-251', '2026-10-25 12:34', '9999-12-31 23:59:59',
    ];

    private const NAMES = [
        null, 5, '', 'A', 'a', 'Same', 'sAME', 'Release.Name.1080p', '0123456789ABCDEF', '0123456789ABCDE', '0123456789abcdef0123', 'abcdefghijklmnopqrstuvwx',
        'Abcdefghijklmnopqrstuvw1', '0123456789abcdef-', '[1/2] - "A.rar" yEnc', 'ÄBC',
    ];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW391RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_follow_the_benchmark_and_toggle_independently(): void
    {
        // Only the blacklist rule health summary cleared 1.5x on every required workload; see the benchmark below.
        $defaults = [];
        foreach (self::CLASSES as $class) {
            $defaults[$class] = $class::W391_RUST_FFI_DEFAULT;
        }
        $this->assertSame([AdminReleaseNamingRegexesController::class => false, AdminCategoryController::class => false, AdminReleasesController::class => false,
            AdminBlacklistController::class => true, ReleasesCleanSubjectNames::class => false], $defaults);
        foreach (self::CLASSES as $class) {
            $default = $defaults[$class];
            $this->assertSame($default, $class::usesW391RustFfi(), $class);
            $class::useW391RustFfi(! $default);
            $this->assertSame(! $default, $class::usesW391RustFfi(), $class);
            foreach ($defaults as $other => $otherDefault) {
                if ($other !== $class) {
                    $this->assertSame($otherDefault, $other::usesW391RustFfi(), $other.' must not follow '.$class);
                }
            }
            $class::useW391RustFfi($default);
            $this->assertSame($default, $class::usesW391RustFfi(), $class);
            $class::useW391RustFfi(! $default);
            $class::resetW391RustFfi();
            $this->assertSame([$default, null], [$class::usesW391RustFfi(), (new ReflectionProperty($class, 'w391Ffi'))->getValue()], $class);
        }

        // The Wave 39.1 toggles are separate from the earlier helpers' toggles on these classes.
        foreach ([AdminReleaseNamingRegexesController::class, AdminCategoryController::class, AdminReleasesController::class, AdminBlacklistController::class] as $class) {
            $class::useW391RustFfi(true);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $class::useW391RustFfi(false);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::setUseRustFfi(null);
        }
        ReleasesCleanSubjectNames::useW391RustFfi(true);
        $this->assertFalse((new ReflectionProperty(ReleasesCleanSubjectNames::class, 'useRustFfi'))->getValue());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'rows' => 5, 'audited' => 3, 'truncated' => true, 'invalid_rows' => 1, 'active' => 2, 'valid_delimiters' => 1, 'invalid_delimiters' => 1,
            'named_reqid' => 1, 'named_parts' => 0, 'named_name' => 0, 'ordinal_duplicates' => 0, 'ordinal_gaps' => 1, 'wildcard_groups' => 2,
        ], AdminReleaseNamingRegexesController::summarizeNamingRegexAudit([
            ['status' => 1, 'regex' => '/(?<reqid>\d+)/i', 'group_regex' => ' .* ', 'ordinal' => 5],
            'not-a-row',
            ['status' => '1', 'regex' => 'bad', 'ordinal' => '7'],
            ['status' => 1, 'regex' => '/x/', 'group_regex' => 'alt', 'ordinal' => 5],
            [],
        ], 3));
        $ordinals = array_map(fn (int $o): array => ['group_regex' => 'alt', 'ordinal' => $o], [5, 5, -1, 0, 1, 3, 3, 9, PHP_INT_MAX, PHP_INT_MIN]);
        $audit = AdminReleaseNamingRegexesController::summarizeNamingRegexAudit($ordinals);
        $this->assertSame([2, 4, 0, false], [$audit['ordinal_duplicates'], $audit['ordinal_gaps'], $audit['wildcard_groups'], $audit['truncated']]);
        $this->assertSame([1, 0, true], [AdminReleaseNamingRegexesController::summarizeNamingRegexAudit([[]], -4)['rows'],
            AdminReleaseNamingRegexesController::summarizeNamingRegexAudit([[]], -4)['audited'], AdminReleaseNamingRegexesController::summarizeNamingRegexAudit([[]], 0)['truncated']]);

        $this->assertSame([
            'categories' => 5, 'invalid_rows' => 1, 'missing_ids' => 1, 'roots' => 1, 'children' => 3, 'active' => 1, 'disabled' => 2, 'size_anomalies' => 2,
            'duplicate_titles' => 1, 'parent_ids' => [1000, 2000], 'child_counts' => [1, 2],
        ], AdminCategoryController::planCategoryHierarchySummary([
            ['id' => 10, 'title' => 'Other', 'root_categories_id' => null, 'status' => 1],
            ['id' => '2010', 'title' => 'Foreign', 'root_categories_id' => '2000', 'status' => 0, 'minsizetoformrelease' => 500, 'maxsizetoformrelease' => 100],
            ['id' => 2020, 'title' => 'FOREIGN', 'root_categories_id' => 2000, 'status' => 2, 'minsizetoformrelease' => 500, 'maxsizetoformrelease' => 0],
            ['title' => 'foreign', 'root_categories_id' => 1000, 'status' => 'x', 'minsizetoformrelease' => '5', 'maxsizetoformrelease' => '4'],
            null,
        ]));

        $this->assertSame([
            'releases' => 4, 'invalid_rows' => 1, 'total_bytes' => PHP_INT_MAX, 'passworded' => 1, 'low_completion' => 1, 'missing_category' => 2,
            'unprocessed' => 2, 'stale_unprocessed' => 1,
        ], AdminReleasesController::summarizeReleaseAdminBatch([
            ['size' => PHP_INT_MAX, 'passwordstatus' => -1, 'completion' => 99.0, 'categories_id' => 0, 'adddate' => '2026-10-01 00:00:00'],
            ['size' => '10', 'passwordstatus' => '1', 'completion' => '100', 'categories_id' => '5000'],
            ['size' => -5, 'passwordstatus' => -1, 'adddate' => self::NOW - 3_600],
            7,
        ], 168, self::NOW));

        $this->assertSame([
            'rules' => 4, 'invalid_rows' => 1, 'active' => 1, 'disabled' => 2, 'subject' => 1, 'poster' => 1, 'message_id' => 0, 'unknown_column' => 1,
            'invalid_regex' => 2, 'stale' => 1, 'never_active' => 1,
        ], AdminBlacklistController::summarizeBlacklistRuleHealth([
            ['status' => 1, 'msgcol' => 1, 'regex' => '^foo', 'last_activity' => '2026-01-01 00:00:00'],
            ['status' => '0', 'msgcol' => '2', 'regex' => ''],
            ['status' => 2, 'msgcol' => 7, 'regex' => 'a/b', 'last_activity' => self::NOW],
            'rule',
        ], self::NOW));

        $this->assertSame([
            'candidates' => 6, 'invalid_rows' => 1, 'unchanged' => 2, 'changed' => 3, 'shortened_bytes' => 14, 'hash_like' => 2, 'sampled' => 2,
            'sample_ids' => [1, 4],
        ], ReleasesCleanSubjectNames::planCleanSubjectDryRunSummary([
            ['id' => 1, 'searchname' => '[1/2] - "A.rar"', 'name' => 'A'],
            ['id' => 2, 'searchname' => 'Same', 'name' => 'sAME'],
            ['id' => 3, 'searchname' => 'x', 'name' => null],
            ['id' => '4', 'searchname' => 'short', 'name' => '0123456789abcdef0123'],
            ['searchname' => 'x', 'name' => 'Abcdefghijklmnopqrstuvw1'],
            ['id' => 6, 'name' => 'a'],
        ], 2));

        // A non-positive `now` uses the clock: a just-added unprocessed release is fresh, a 1970 one is stale.
        $this->assertSame(1, AdminReleasesController::summarizeReleaseAdminBatch([['passwordstatus' => -1, 'adddate' => 1], ['passwordstatus' => -1, 'adddate' => time() + 60]])['stale_unprocessed']);
        $this->assertSame(1, AdminBlacklistController::summarizeBlacklistRuleHealth([['last_activity' => 1], ['last_activity' => time() + 60]])['stale']);
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $rows = [];
        foreach (self::PATTERNS as $i => $pattern) {
            $rows[] = ['status' => self::INTS[$i % count(self::INTS)], 'regex' => $pattern, 'group_regex' => self::GROUPS[$i % count(self::GROUPS)],
                'ordinal' => self::INTS[($i * 7) % count(self::INTS)]];
        }
        $rows[] = 'x';
        $rows[] = [];
        foreach ([-1, 0, 1, 5, 500, PHP_INT_MAX, PHP_INT_MIN] as $max) {
            $this->assertHelperParity('summarizeNamingRegexAudit', [$rows, $max]);
        }
        $this->assertHelperParity('summarizeNamingRegexAudit', [[], 500]);
        $this->assertHelperParity('summarizeNamingRegexAudit', [['a' => ['regex' => '/x/'], 9 => null], 500]);

        $categories = [];
        foreach (self::INTS as $i => $value) {
            $categories[] = ['id' => $value, 'title' => self::NAMES[$i % count(self::NAMES)], 'root_categories_id' => self::INTS[($i * 3) % count(self::INTS)],
                'status' => self::INTS[($i * 5) % count(self::INTS)], 'minsizetoformrelease' => self::INTS[($i * 11) % count(self::INTS)],
                'maxsizetoformrelease' => $value];
        }
        $categories[] = 5;
        $this->assertHelperParity('planCategoryHierarchySummary', [$categories]);
        $this->assertHelperParity('planCategoryHierarchySummary', [[]]);

        $releases = [];
        foreach (self::DATES as $i => $date) {
            $releases[] = ['size' => self::INTS[$i % count(self::INTS)], 'passwordstatus' => [-1, '-1', 0, 1, '2', null][$i % 6],
                'completion' => self::COMPLETIONS[$i % count(self::COMPLETIONS)], 'categories_id' => self::INTS[($i * 3) % count(self::INTS)], 'adddate' => $date];
        }
        foreach (self::COMPLETIONS as $completion) {
            $releases[] = ['completion' => $completion, 'size' => PHP_INT_MAX];
        }
        $releases[] = 'row';
        foreach ([[168, self::NOW], [0, self::NOW], [-5, 1], [PHP_INT_MAX, self::NOW], [1, PHP_INT_MAX], [168, -1], [intdiv(PHP_INT_MAX, 3_600) + 1, 0]] as [$hours, $now]) {
            $this->assertHelperParity('summarizeReleaseAdminBatch', [$releases, $hours, $now]);
        }

        $rules = [];
        foreach (self::DATES as $i => $date) {
            $rules[] = ['status' => self::INTS[$i % count(self::INTS)], 'msgcol' => self::INTS[($i * 3) % count(self::INTS)],
                'regex' => ['abc', 'a\\/b', 'a/b', 'a\\', 'a\\\\', '', null, 5, '^(x|y)$', "\0/"][$i % 10], 'last_activity' => $date];
        }
        $rules[] = false;
        foreach ([[self::NOW, 90], [self::NOW, 0], [1, -3], [PHP_INT_MAX, PHP_INT_MAX], [-1, 90], [self::NOW, intdiv(PHP_INT_MAX, 86_400) + 1]] as [$now, $days]) {
            $this->assertHelperParity('summarizeBlacklistRuleHealth', [$rules, $now, $days]);
        }

        $candidates = [];
        foreach (self::NAMES as $i => $from) {
            foreach (self::NAMES as $j => $to) {
                $candidates[] = ['id' => self::INTS[($i + $j) % count(self::INTS)], 'searchname' => $from, 'name' => $to];
            }
        }
        $candidates[] = null;
        foreach ([-1, 0, 1, 25, PHP_INT_MAX] as $limit) {
            $this->assertHelperParity('planCleanSubjectDryRunSummary', [$candidates, $limit]);
        }
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3911));
        $pick = fn (array $pool): mixed => $pool[$random->getInt(0, count($pool) - 1)];
        $bytes = fn (int $max): string => ($length = $random->getInt(0, $max)) === 0 ? '' : $random->getBytes($length);
        $int = fn (): mixed => match ($random->getInt(0, 3)) {
            0 => $pick(self::INTS),
            1 => $random->getInt(-5, 200),
            2 => (string) $random->getInt(-5, 99999),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $pieces = ['/', '\\', '#', '~', '(', ')', '[', ']', '{', '}', '<', '>', '?', 'P', "'", 'reqid', 'parts', 'name', '\d+', '.*', ' ', "\n", "\0", 'i', 'u', 'e',
            'x', 'A', '1', "\xff", '^', '$'];
        $text = function (int $max) use ($random, $pick, $pieces): string {
            $value = '';
            for ($n = $random->getInt(0, $max); $n > 0; $n--) {
                $value .= $pick($pieces);
            }

            return $value;
        };
        $pattern = fn (): mixed => match ($random->getInt(0, 7)) {
            0 => $pick(self::PATTERNS),
            1 => $bytes(12),
            2 => $pick(['/', '#', '~', '(', '{', '[', '<']).$text(10).$pick(['/', '#', '~', ')', '}', ']', '>']).$pick(['', 'i', 'iu', 'e', ' ', 'g']),
            default => $text(12),
        };
        $date = fn (): mixed => match ($random->getInt(0, 4)) {
            0 => $pick(self::DATES),
            1 => self::NOW - $random->getInt(-10_000, 20_000_000),
            2 => gmdate('Y-m-d H:i:s', $random->getInt(0, 4_102_444_800)),
            3 => gmdate('Y-m-d', $random->getInt(0, 4_102_444_800)),
            default => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $random->getInt(1960, 2100), $random->getInt(0, 13), $random->getInt(0, 32), $random->getInt(0, 25),
                $random->getInt(0, 61), $random->getInt(0, 61)),
        };
        $name = fn (): mixed => match ($random->getInt(0, 5)) {
            0 => $pick(self::NAMES),
            1 => $bytes(30),
            2 => bin2hex($bytes(20)),
            3 => base64_encode($bytes(24)),
            default => $text(8),
        };
        $rows = function (Closure $row) use ($random): array {
            $list = [];
            for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                $list[] = $random->getInt(0, 19) === 0 ? null : $row();
            }

            return $list;
        };

        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $this->assertHelperParity('summarizeNamingRegexAudit', [$rows(fn (): array => ['status' => $int(), 'regex' => $pattern(), 'group_regex' => $random->getInt(0, 1) === 0
                ? $pick(self::GROUPS) : $text(4), 'ordinal' => $int()]), $random->getInt(-2, 40)]);
            $this->assertHelperParity('planCategoryHierarchySummary', [$rows(fn (): array => ['id' => $int(), 'title' => $pick(['Other', 'OTHER', 'HD', 'hd', 'SD', 'Foreign']),
                'root_categories_id' => $pick([null, 0, 1000, '2000', 2000, 3000, -1]), 'status' => $int(), 'minsizetoformrelease' => $int(), 'maxsizetoformrelease' => $int()])]);
            $this->assertHelperParity('summarizeReleaseAdminBatch', [$rows(fn (): array => ['size' => $int(), 'passwordstatus' => $pick([-1, '-1', 0, 1, 2, null, '1']),
                'completion' => $random->getInt(0, 2) === 0 ? $pick(self::COMPLETIONS) : $random->getInt(0, 10_000) / 100, 'categories_id' => $int(), 'adddate' => $date()]),
                $pick([0, 1, 24, 168, -1, PHP_INT_MAX]), self::NOW + $random->getInt(-5, 5)]);
            $this->assertHelperParity('summarizeBlacklistRuleHealth', [$rows(fn (): array => ['status' => $int(), 'msgcol' => $pick([1, 2, 3, '1', '2', '3', 0, 4, null]),
                'regex' => $random->getInt(0, 1) === 0 ? $text(6) : $bytes(8), 'last_activity' => $date()]), self::NOW + $random->getInt(-5, 5), $pick([0, 1, 30, 90, -1])]);
            $this->assertHelperParity('planCleanSubjectDryRunSummary', [$rows(function () use ($random, $name, $int): array {
                $from = $name();

                return ['id' => $int(), 'searchname' => $from, 'name' => $random->getInt(0, 3) === 0 && is_string($from) ? strtoupper($from) : $name()];
            }), $random->getInt(-1, 12)]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[96]');
        FFI::memset($out, ord('Z'), 96);
        $input = pack('q2', 1, 0);
        $length = strlen($input);

        $this->assertSame(-80, $this->callFfi($ffi, 2, 0, $input, $length, $out, 79));
        $this->assertSame(-80, $this->callFfi($ffi, 2, 0, $input, $length, $out, 0));
        foreach ([[0, 0], [6, 0], [2, 1], [1, 2]] as [$operation, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $flags, $input, $length, $out, 96), "op {$operation} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, null, $length, $out, 96));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, $input, $length, null, 96));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, $input, $length - 1, $out, 96));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, $input, 16_777_217, $out, 96));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q', PHP_INT_MAX), 8, $out, 96));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q3', 2, 1, 2), 24, $out, 96));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q2', 1, 0).'x', 17, $out, 96));
        $this->assertSame(-1, $this->callFfi($ffi, 5, 0, pack('q6', 5, 0, 1, 1, -1, 5), 48, $out, 96));
        $this->assertSame(str_repeat('Z', 96), FFI::string($out, 96));
        $this->assertSame(80, $this->callFfi($ffi, 2, 0, $input, $length, $out, 96));
        $this->assertSame(pack('q10', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0).str_repeat('Z', 16), FFI::string($out, 96));

        // A short buffer reports the exact size; retrying with that capacity succeeds without overrunning it.
        $candidates = [];
        for ($i = 0; $i < 20; $i++) {
            $candidates[] = ['id' => $i, 'searchname' => 'From.'.$i, 'name' => 'To.'.$i];
        }
        $words = [100, count($candidates)];
        $blob = [];
        foreach ($candidates as $row) {
            AdminBlacklistController::w391PackRow($words, $blob, [$row['id'], $row['searchname'], $row['name']]);
        }
        $frame = pack('q', count($words)).pack('q*', ...$words).implode('', $blob);
        FFI::memset($out, ord('Z'), 96);
        $required = $this->callFfi($ffi, 5, 0, $frame, strlen($frame), $out, 96);
        $this->assertSame(-(8 + 20) * 8, $required);
        $this->assertSame(str_repeat('Z', 96), FFI::string($out, 96));
        $large = $ffi->new('char[256]');
        $this->assertSame(224, $this->callFfi($ffi, 5, 0, $frame, strlen($frame), $large, -$required));
        $this->assertSame(pack('q8', 20, 0, 0, 20, 40, 0, 20, 20).pack('q*', ...range(0, 19)), FFI::string($large, 224));
        // The public helper decodes the same variable-length sample list.
        ReleasesCleanSubjectNames::useW391RustFfi(true);
        $this->assertSame(range(0, 19), ReleasesCleanSubjectNames::planCleanSubjectDryRunSummary($candidates, 100)['sample_ids']);

        foreach (self::CLASSES as $class) {
            $class::useW391RustFfi(true);
        }
        AdminReleaseNamingRegexesController::summarizeNamingRegexAudit([['regex' => '/x/']]);
        AdminCategoryController::planCategoryHierarchySummary([['id' => 1]]);
        AdminReleasesController::summarizeReleaseAdminBatch([['size' => 1]]);
        AdminBlacklistController::summarizeBlacklistRuleHealth([['regex' => 'x']]);
        ReleasesCleanSubjectNames::planCleanSubjectDryRunSummary([['searchname' => 'a', 'name' => 'b']]);
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w391Ffi'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $cases = [
            'summarizeNamingRegexAudit' => [[['status' => 1, 'regex' => '/(?<reqid>x)/', 'ordinal' => 1]], 500],
            'planCategoryHierarchySummary' => [[['id' => 1, 'title' => 'A', 'root_categories_id' => 1000]]],
            'summarizeReleaseAdminBatch' => [[['size' => 5, 'passwordstatus' => -1, 'adddate' => 1]], 168, self::NOW],
            'summarizeBlacklistRuleHealth' => [[['status' => 1, 'msgcol' => 1, 'regex' => 'a/b']], self::NOW, 90],
            'planCleanSubjectDryRunSummary' => [[['id' => 1, 'searchname' => 'a', 'name' => 'b']], 25],
        ];
        foreach (self::CLASSES as $class) {
            $class::useW391RustFfi(true);
            (new ReflectionProperty($class, 'w391Ffi'))->setValue(null, false);
        }
        foreach ($cases as $helper => $args) {
            $class = self::HELPERS[$helper];
            $this->assertNull($this->helper($class, $helper.'Native')(...$args), $helper);
            $this->assertSame($this->helper($class, $helper.'Php')(...$args), $class::$helper(...$args), $helper);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // frame packing, the FFI call and decoding (release dylib, 5 warmups, 201 samples x 20
        // iterations). Common = a 120-row naming-regex page, the ~150-row category list, a 50-row
        // admin release page, 40 blacklist rules and a 1,000-row clean-subject chunk; uncommon = 500
        // long regexes, 2,000 categories with string fields, a 400-row page with string/float fields,
        // 600 rules with long regexes and 5,000 candidates with long hashed names.
        // Speedup = PHP latency / Rust latency per workload pass, common mean/p99 then uncommon mean/p99
        // (PHP 8.5.12 NTS, Apple M5, release dylib, 3 rounds):
        // - Round 1: naming 2.53x/2.45x, 1.27x/1.28x; category 0.68x/0.69x, 1.49x/1.23x; releases 1.00x/0.96x, 1.72x/1.73x;
        //   blacklist 2.28x/2.12x, 1.85x/1.77x; dry run 0.80x/0.88x, 0.68x/0.68x
        // - Round 2: naming 2.51x/2.47x, 1.28x/1.28x; category 0.66x/0.65x, 1.55x/1.52x; releases 1.01x/0.99x, 1.73x/1.67x;
        //   blacklist 2.25x/2.15x, 1.78x/1.73x; dry run 0.79x/0.80x, 0.68x/0.68x
        // - Round 3: naming 2.49x/2.38x, 1.28x/1.26x; category 0.67x/0.67x, 1.51x/1.51x; releases 1.00x/0.97x, 1.71x/1.67x;
        //   blacklist 2.28x/1.89x, 1.81x/1.81x; dry run 0.79x/0.79x, 0.67x/0.65x
        // The PHP baselines skip with strcspn()/one PCRE match rather than stepping through every byte.
        // Only the blacklist rule health summary clears the 1.5x bar in .ai/rules/rust-performance.md on
        // every workload, so AdminBlacklistController::W391_RUST_FFI_DEFAULT is true. The naming audit
        // misses on its uncommon long-regex workload and the other three do too little per-row work to
        // repay frame packing and the FFI round trip, so they keep PHP as the default.
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustAdminRegexCategoryGroupReleaseW39ParityTest.php --filter benchmark
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3912));
        $workloads = ['common' => $this->commonWorkload($random), 'uncommon' => $this->uncommonWorkload($random)];
        $warmup = 5;
        $samples = 201;
        $iterations = 20;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => $class) {
            foreach ($workloads as $name => $workload) {
                $args = $workload[$helper];
                $this->assertHelperParity($helper, $args);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $class::useW391RustFfi($useRust);
                        $sink += count($class::$helper(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                        $class::useW391RustFfi($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$helper(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::useW391RustFfi(null);
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustAdminRegexCategoryGroupReleaseW39 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => $class) {
            $speedup = $speedups[$helper];
            if (min($speedup['common']['mean'], $speedup['common']['p99'], $speedup['uncommon']['mean'], $speedup['uncommon']['p99']) < 1.5) {
                $this->assertFalse($class::W391_RUST_FFI_DEFAULT, $class.' must keep PHP below 1.5x: '.$summary);
            }
            foreach ($speedup as $name => $values) {
                $this->assertGreaterThan(0.0, $values['mean'], $helper.' '.$name);
                $this->assertGreaterThan(0.0, $values['p99'], $helper.' '.$name);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertHelperParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        $expected = $this->helper($class, $helper.'Php')(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $this->assertSame($expected, $this->helper($class, $helper.'Native')(...$args), $context);
        if (isset(self::CLOCKED[$helper]) && ($args[self::CLOCKED[$helper]] ?? 0) <= 0) {
            return;
        }
        foreach ([true, false] as $useRust) {
            $class::useW391RustFfi($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::useW391RustFfi(null);
    }

    private function helper(string $class, string $method): Closure
    {
        return $this->closures[$class.'::'.$method] ??= (new ReflectionMethod($class, $method))->getClosure(null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonWorkload(Randomizer $random): array
    {
        $regexes = [];
        for ($i = 0; $i < 120; $i++) {
            $regexes[] = ['id' => $i + 1, 'group_regex' => $i % 10 === 0 ? '.*' : 'alt.binaries.group'.$i, 'status' => $i % 7 === 0 ? 0 : 1, 'ordinal' => $i * 2,
                'regex' => '/^\[(?P<reqid>\d+)\]-\[(?P<parts>\d+\/\d+)\] - "(?P<name>.+?)\.'.$i.'" yEnc$/i', 'description' => 'Regex '.$i];
        }
        $categories = [];
        for ($i = 0; $i < 150; $i++) {
            $categories[] = ['id' => 1000 + $i * 10, 'title' => ['Other', 'HD', 'SD', 'Foreign', 'UHD'][$i % 5].($i % 3 === 0 ? '' : ' '.$i),
                'root_categories_id' => $i % 15 === 0 ? null : 1000 * intdiv($i, 15), 'status' => $i % 9 === 0 ? 2 : 1,
                'minsizetoformrelease' => $i % 11 === 0 ? 5000 : 0, 'maxsizetoformrelease' => $i % 13 === 0 ? 100 : 0, 'description' => 'Category '.$i];
        }
        $releases = [];
        for ($i = 0; $i < 50; $i++) {
            $releases[] = ['id' => $i, 'searchname' => 'Release.'.$i, 'size' => $random->getInt(1, 50_000_000_000), 'passwordstatus' => [-1, 0, 0, 1][$i % 4],
                'completion' => [100.0, 98.5, 100.0][$i % 3], 'categories_id' => $i % 17 === 0 ? 0 : 5040, 'adddate' => gmdate('Y-m-d H:i:s', self::NOW - $i * 20_000)];
        }
        $rules = [];
        for ($i = 0; $i < 40; $i++) {
            $rules[] = ['id' => $i, 'groupname' => 'alt.binaries.*', 'status' => $i % 5 === 0 ? 0 : 1, 'msgcol' => $i % 3 + 1, 'optype' => 1,
                'regex' => '(?i)(sample|proof|\bpw\b)'.$i.($i % 10 === 0 ? '/x' : ''), 'last_activity' => $i % 4 === 0 ? null : gmdate('Y-m-d H:i:s', self::NOW - $i * 400_000)];
        }
        $candidates = [];
        for ($i = 0; $i < 1_000; $i++) {
            $file = 'Some.Show.S01E'.($i % 30).'.1080p.WEB.h264-GRP';
            $candidates[] = ['id' => 10_000 + $i, 'searchname' => '[01/'.($i % 50 + 1).'] - "'.$file.'.mkv" yEnc', 'name' => match ($i % 5) {
                0 => null,
                1 => bin2hex($random->getBytes(16)),
                default => $file,
            }];
        }

        return [
            'summarizeNamingRegexAudit' => [$regexes, 500],
            'planCategoryHierarchySummary' => [$categories],
            'summarizeReleaseAdminBatch' => [$releases, 168, self::NOW],
            'summarizeBlacklistRuleHealth' => [$rules, self::NOW, 90],
            'planCleanSubjectDryRunSummary' => [$candidates, 25],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function uncommonWorkload(Randomizer $random): array
    {
        $regexes = [];
        for ($i = 0; $i < 600; $i++) {
            $regexes[] = $i % 50 === 49 ? 'row' : ['group_regex' => $i % 4 === 0 ? ' ^.*$ ' : '^alt\.binaries\.(teevee|hdtv|x264)'.$i.'$', 'status' => (string) ($i % 2),
                'ordinal' => (string) ($i % 97 * 3), 'regex' => ['#', '~', '(', '/'][$i % 4].str_repeat('(?:[a-z0-9]+[._-])*\\'.['#', '~', ')', '/'][$i % 4], 12)
                    .'(?<name>.+)'.['#', '~', ')', '/'][$i % 4].['iu', 'e', '', 'imsx'][$i % 4]];
        }
        $categories = [];
        for ($i = 0; $i < 2_000; $i++) {
            $categories[] = $i % 100 === 99 ? null : ['id' => (string) $i, 'title' => 'Title '.($i % 700), 'root_categories_id' => (string) (1000 * ($i % 40)),
                'status' => (string) ($i % 3), 'minsizetoformrelease' => (string) ($i * 7), 'maxsizetoformrelease' => (string) ($i * 5)];
        }
        $releases = [];
        for ($i = 0; $i < 400; $i++) {
            $releases[] = $i % 40 === 39 ? 'not-a-row' : ['size' => (string) $random->getInt(1, PHP_INT_MAX >> 4), 'passwordstatus' => (string) ($i % 3 - 1),
                'completion' => [(string) ($i % 101).'.5', 99.25, '100', null][$i % 4], 'categories_id' => (string) ($i % 9),
                'adddate' => [gmdate('Y-m-d H:i:s', self::NOW - $i * 9_000), '0000-00-00 00:00:00', gmdate('Y-m-d', self::NOW - $i * 50_000), null][$i % 4]];
        }
        $rules = [];
        for ($i = 0; $i < 600; $i++) {
            $rules[] = ['status' => (string) ($i % 2), 'msgcol' => (string) ($i % 4 + 1), 'regex' => str_repeat('(?:\\/[a-z]+|\\\\)', 30).($i % 7 === 0 ? '/' : ''),
                'last_activity' => [gmdate('Y-m-d H:i:s', self::NOW - $i * 100_000), null, 'bogus'][$i % 3]];
        }
        $candidates = [];
        for ($i = 0; $i < 5_000; $i++) {
            $hash = bin2hex($random->getBytes(32));
            $candidates[] = ['id' => (string) $i, 'searchname' => '[0001/0'.($i % 900 + 100).'] - "'.$hash.'.part'.$i.'.rar" yEnc (1/'.($i % 300 + 1).')',
                'name' => [$hash, strtoupper($hash), base64_encode($random->getBytes(30)), null][$i % 4]];
        }

        return [
            'summarizeNamingRegexAudit' => [$regexes, 500],
            'planCategoryHierarchySummary' => [$categories],
            'summarizeReleaseAdminBatch' => [$releases, 24, self::NOW],
            'summarizeBlacklistRuleHealth' => [$rules, self::NOW, 30],
            'planCleanSubjectDryRunSummary' => [$candidates, 25],
        ];
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

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w391_admin_regex_category_release_blacklist(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w391', AdminBlacklistController::W391_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 39.1 admin regex/category/release/blacklist export is not available.');
        }

        return $ffi;
    }
}
