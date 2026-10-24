<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\BackfillGroup;
use App\Console\Commands\GroupsUpdate;
use App\Console\Commands\RecategorizeReleases;
use App\Console\Commands\UpdateBackfill;
use App\Console\Commands\UpdateGroupHeaders;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustRecategorizeGroupsBackfillCommandsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array COMMANDS = [RecategorizeReleases::class, GroupsUpdate::class, BackfillGroup::class, UpdateBackfill::class, UpdateGroupHeaders::class];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
        parent::tearDown();
    }

    public function test_toggles_default_to_php_and_are_independent(): void
    {
        foreach (self::COMMANDS as $class) {
            $this->assertFalse($class::usesRustFfi(), $class.' must keep the PHP production default.');
        }
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            foreach (self::COMMANDS as $sibling) {
                if ($sibling !== $class) {
                    $this->assertFalse($sibling::usesRustFfi(), $sibling.' must not follow '.$class);
                }
            }
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_php_helpers_match_the_specified_and_original_rules(): void
    {
        $this->assertSame([1, 0, -3, 1000, PHP_INT_MAX], RecategorizeReleases::parseCommaSeparatedIntsPhp('1,x, -3,1e3,99999999999999999999'));
        $this->assertSame(['a', 'b c', '', "\xc2\xa0x"], RecategorizeReleases::parseCommaSeparatedGroupNamesPhp(" a ,\t\nb c\x0b\0,,\xc2\xa0x\r"));
        $this->assertSame(['videos_id' => 0, 'tv_episodes_id' => 0, 'imdbid' => null, 'musicinfo_id' => null, 'consoleinfo_id' => null,
            'gamesinfo_id' => 0, 'bookinfo_id' => null, 'anidbid' => null, 'movieinfo_id' => null, 'iscategorized' => 0], RecategorizeReleases::buildResetPayloadPhp());
        $this->assertSame(['N/A', 'HD', 'TV -> HD', 'HD'], [
            RecategorizeReleases::formatCategoryDisplayNamePhp(null, 'TV'), RecategorizeReleases::formatCategoryDisplayNamePhp('HD', null),
            RecategorizeReleases::formatCategoryDisplayNamePhp('HD', 'TV'), RecategorizeReleases::formatCategoryDisplayNamePhp('HD', ''),
        ]);

        // The previous inline rendering, for categories whose parent has a title.
        $category = static fn (?string $title, ?string $parent): ?object => $title === null ? null
            : (object) ['title' => $title, 'parent' => $parent === null ? null : (object) ['title' => $parent]];
        foreach ([[null, null], ['HD', null], ['HD', 'TV'], ["\0é", 'Movies']] as [$title, $parent]) {
            $cat = $category($title, $parent);
            $original = $cat?->parent ? ($cat->parent->title.' -> '.$cat->title) : ($cat->title ?? 'N/A');
            foreach ([false, true] as $enabled) {
                RecategorizeReleases::setUseRustFfi($enabled);
                $this->assertSame($original, RecategorizeReleases::formatCategoryDisplayName($cat?->title, $cat?->parent?->title));
            }
        }

        $server = [['group' => 'a.b', 'first' => '5', 'last' => '9'], ['group' => 'c.d', 'first' => '1', 'last' => '2'], ['group' => 'A.B', 'first' => '0', 'last' => '0']];
        $this->assertSame([$server[0]], GroupsUpdate::filterActiveServerGroupsPhp($server, ['a.b', 'x']));
        $this->assertSame(['name' => 'a.b', 'first_record' => 5, 'last_record' => 9], GroupsUpdate::projectShortGroupRowPhp($server[0]));
        $this->assertSame(['name' => '', 'first_record' => 0, 'last_record' => 0], GroupsUpdate::projectShortGroupRowPhp([]));
        $this->assertSame(['✅ Updated 3 groups', '⏱️  Running time: 1.25 seconds'], GroupsUpdate::formatCompletionLinesPhp(3, '1.25'));

        $this->assertSame([false, true, true, false], array_map(BackfillGroup::isValidBackfillTypePhp(...), [0, 1, 2, 3]));
        $this->assertSame([12, '', 500, 0], [BackfillGroup::resolveQuantityPhp('12', 1, 500), BackfillGroup::resolveQuantityPhp(null, 1, 500),
            BackfillGroup::resolveQuantityPhp(null, 2, 500), BackfillGroup::resolveQuantityPhp('abc', 2, 500)]);
        $this->assertSame('NNTP connection failed: timeout', BackfillGroup::formatNntpErrorMessagePhp('timeout'));

        $this->assertSame(['backfill_all', ''], array_values(array_slice(UpdateBackfill::resolveBackfillDispatchPlanPhp('all', null), 0, 2)));
        $this->assertSame(['action' => 'backfill_date', 'type' => 'date', 'quantity' => 200000], UpdateBackfill::resolveBackfillDispatchPlanPhp('date', 200000));
        $this->assertSame(['action' => 'invalid', 'type' => '', 'quantity' => null], UpdateBackfill::resolveBackfillDispatchPlanPhp('safe', null));

        $this->assertSame([0, 0, 7, 12], array_map(UpdateGroupHeaders::clampMaxHeadersPhp(...), [null, '-5', 7, '12abc']));
        $this->assertSame('Group not found: a.b', UpdateGroupHeaders::formatGroupNotFoundMessagePhp('a.b'));
        $this->assertSame('NNTP connection failed: x', UpdateGroupHeaders::formatNntpConnectionFailedMessagePhp('x'));
    }

    public function test_update_backfill_dispatch_matches_the_previous_match_for_every_mode_and_quantity(): void
    {
        $this->nativeFfi();
        $modes = ['all', 'alph', 'date', 'safe', 'ALL', 'alt.binaries.test', 'a.b', "a.b\n", 'a.', '.a', 'ab', "a.b\r", 'a b.c', '', "a\0.b", 'a.b.c-d+e_f'];
        $quantities = [null, '5', '0', '-1', '1.5', ' 5', '5 ', '1e3', '', 'abc', '0x10', '99999999999999999999'];
        foreach ([false, true] as $enabled) {
            UpdateBackfill::setUseRustFfi($enabled);
            foreach ($modes as $mode) {
                foreach ($quantities as $quantity) {
                    $isGroupName = ! \in_array($mode, ['all', 'alph', 'date', 'safe'], true)
                        && preg_match('/^[a-z0-9+_.-]+\.[a-z0-9+_.-]+$/i', $mode) === 1;
                    $this->assertSame($isGroupName, UpdateBackfill::isGroupNameArgument($mode), json_encode($mode));
                    $original = match (true) {
                        $mode === 'all' && ! isset($quantity) => ['all'],
                        $mode === 'alph' && is_numeric($quantity) => ['groups', '', (int) $quantity, 'normal'],
                        $mode === 'date' && is_numeric($quantity) => ['groups', '', (int) $quantity, 'date'],
                        $mode === 'safe' && is_numeric($quantity) => ['safe', (int) $quantity],
                        $isGroupName && ! isset($quantity) => ['groups', $mode],
                        $isGroupName && is_numeric($quantity) => ['groups', $mode, (int) $quantity],
                        default => ['help'],
                    };
                    // UpdateBackfill::handle(): a non-numeric quantity selects the help text.
                    $plan = $quantity === null || is_numeric($quantity)
                        ? UpdateBackfill::resolveBackfillDispatchPlan($mode, $quantity === null ? null : (int) $quantity)
                        : ['action' => 'invalid', 'type' => '', 'quantity' => null];
                    $dispatched = match ($plan['action']) {
                        'backfill_all' => ['all'],
                        'backfill_alph', 'backfill_date' => ['groups', '', (int) $plan['quantity'], $plan['type']],
                        'backfill_safe' => ['safe', (int) $plan['quantity']],
                        'group_date' => ['groups', $mode],
                        'group_quantity' => ['groups', $mode, (int) $plan['quantity']],
                        default => ['help'],
                    };
                    $this->assertSame($original, $dispatched, json_encode([$mode, $quantity]));
                }
            }
        }
    }

    public function test_backfill_group_rejects_an_invalid_type_identically(): void
    {
        $this->nativeFfi();
        foreach ([false, true] as $enabled) {
            BackfillGroup::setUseRustFfi($enabled);
            $this->artisan('backfill:group', ['group' => 'alt.binaries.test', 'type' => '3'])
                ->expectsOutput('Invalid backfill type. Must be 1 (interval) or 2 (all).')
                ->assertFailed();
        }
    }

    public function test_native_and_dispatch_parity_including_binary_and_uncommon_inputs(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = self::outcome(static fn (): mixed => $class::$php(...$args));
                $label = $class.'::'.$method.' '.json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $this->assertSame($expected, self::outcome(static fn (): mixed => $class::$native(...$args)), $label);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, self::outcome(static fn (): mixed => $class::$method(...$args)), $label);
                }
            }
            $class::setUseRustFfi(false);
        }
    }

    public function test_randomized_int_casts_and_trims_match_php(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(30032026));
        $alphabet = "0123456789+-.eE xX\t\n\r\x0b\x0c\0,a9";
        for ($i = 0; $i < 4000; $i++) {
            $length = $random->getInt(0, 40);
            $csv = $length === 0 ? '' : $random->getBytesFromString($alphabet, $length);
            $this->assertSame(RecategorizeReleases::parseCommaSeparatedIntsPhp($csv), RecategorizeReleases::parseCommaSeparatedIntsNative($csv), json_encode($csv));
            $this->assertSame(RecategorizeReleases::parseCommaSeparatedGroupNamesPhp($csv), RecategorizeReleases::parseCommaSeparatedGroupNamesNative($csv), json_encode($csv));
            $this->assertSame(UpdateGroupHeaders::clampMaxHeadersPhp($csv), UpdateGroupHeaders::clampMaxHeadersNative($csv), json_encode($csv));
            $this->assertSame(BackfillGroup::resolveQuantityPhp($csv, 2, 7), BackfillGroup::resolveQuantityNative($csv, 2, 7), json_encode($csv));
        }
        $digits = '0123456789';
        for ($i = 0; $i < 2000; $i++) {
            $number = ($random->getInt(0, 1) === 1 ? '-' : '').$random->getBytesFromString($digits, $random->getInt(1, 24))
                .($random->getInt(0, 1) === 1 ? '.'.($random->getInt(0, 3) === 0 ? '' : $random->getBytesFromString($digits, $random->getInt(1, 20))) : '')
                .($random->getInt(0, 2) === 0 ? 'e'.$random->getInt(-30, 30) : '');
            $this->assertSame([(int) $number], RecategorizeReleases::parseCommaSeparatedIntsNative($number), $number);
        }
        for ($i = 0; $i < 1000; $i++) {
            $mode = $random->getBytesFromString("aZ9.+_-\n\r é", $random->getInt(1, 8));
            $this->assertSame(UpdateBackfill::isGroupNameArgumentPhp($mode), UpdateBackfill::isGroupNameArgumentNative($mode), json_encode($mode, JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    public function test_native_exports_answer_without_php_fallback(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::COMMANDS as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionMethod($class, 'ffi'))->invoke(null), $class.' must load its own Wave 30.3 declarations.');
        }
        $call = static fn (string $symbol, mixed ...$args): mixed => self::callExport($ffi, 'nntmux_cat_rgb_cmd_'.$symbol, ...$args);
        $this->assertSame([1, 1, 0, 0], [$call('valid_backfill_type', 1), $call('valid_backfill_type', 2), $call('valid_backfill_type', 0), $call('valid_backfill_type', PHP_INT_MIN)]);
        $this->assertSame([1, 0, -1], [$call('is_group_name', "a.b\n", 4), $call('is_group_name', 'all', 3), $call('is_group_name', null, 0)]);
        $this->assertSame([0x105, 0x104, 6, -1], [$call('backfill_plan', 'a.b', 3, 1), $call('backfill_plan', 'a.b', 3, 0) | 0x100, $call('backfill_plan', 'all', 3, 1), $call('backfill_plan', 'all', 3, 2)]);

        $value = $ffi->new('int64_t[1]');
        $this->assertSame(1, $call('clamp_max_headers', 3, 0, ' 1e3', 4, $value));
        $this->assertSame(1000, $value[0]);
        $this->assertSame(-1, $call('clamp_max_headers', 1, 2, '', 0, $value));
        $this->assertSame(-1, $call('clamp_max_headers', 9, 0, '', 0, $value));
        $this->assertSame(0, $call('resolve_quantity', 1, '-4x', 3, 1, 9, $value));
        $this->assertSame(-4, $value[0]);
        $this->assertSame(1, $call('resolve_quantity', 0, '', 0, 1, 9, $value));

        $values = $ffi->new('int64_t[3]');
        FFI::memset($values, 0x5A, 24);
        $this->assertSame(-3, $call('parse_ints', '1,2,3', 5, $values, 2), 'A short array reports the required count.');
        $this->assertSame(str_repeat('Z', 24), FFI::string($values, 24));
        $this->assertSame(3, $call('parse_ints', '1,2,3', 5, $values, 3));
        $this->assertSame([1, 2, 3], [$values[0], $values[1], $values[2]]);

        $row = $ffi->new('int64_t[2]');
        $this->assertSame(1, $call('project_row', 3, 0, '9223372036854775808', 19, 0, 0, '', 0, $row));
        $this->assertSame([PHP_INT_MAX, 0], [$row[0], $row[1]]);
        $this->assertSame(-1, $call('project_row', 4, 0, '', 0, 0, 0, '', 0, $row));
    }

    public function test_direct_abi_short_buffers_do_not_write_and_exact_buffers_have_no_terminator(): void
    {
        $ffi = $this->nativeFfi();
        $long = str_repeat("é\0\xff", 300);
        $blob = static fn (string ...$items): string => implode('', array_map(static fn (string $item): string => pack('P', strlen($item)).$item, $items));
        $firstLength = $ffi->new('size_t[1]');
        $vectors = [
            ['nntmux_cat_rgb_cmd_parse_names', [" a ,\0b\x0b", 7], 'a,b'],
            ['nntmux_cat_rgb_cmd_parse_names', [$long, strlen($long)], $long],
            ['nntmux_cat_rgb_cmd_reset_payload', [], "videos_id=i0\ntv_episodes_id=i0\nimdbid=n\nmusicinfo_id=n\nconsoleinfo_id=n\ngamesinfo_id=i0\nbookinfo_id=n\nanidbid=n\nmovieinfo_id=n\niscategorized=i0"],
            ['nntmux_cat_rgb_cmd_category_display', [1, 'HD', 2, 1, $long, strlen($long)], $long.' -> HD'],
            ['nntmux_cat_rgb_cmd_category_display', [0, '', 0, 1, 'TV', 2], 'N/A'],
            ['nntmux_cat_rgb_cmd_filter_groups', [$blob('a.b', '', 'x', "\0"), 37, $blob('x', "\0"), 18], "\0\0\1\1"],
            ['nntmux_cat_rgb_cmd_filter_groups', [$blob('a'), 9, $blob('a'), 9], "\1"],
            ['nntmux_cat_rgb_cmd_completion_lines', [PHP_INT_MIN, $long, strlen($long), $firstLength], '✅ Updated '.PHP_INT_MIN.' groups⏱️  Running time: '.$long.' seconds'],
            ['nntmux_cat_rgb_cmd_message', [0, $long, strlen($long)], 'NNTP connection failed: '.$long],
            ['nntmux_cat_rgb_cmd_message', [1, '', 0], 'Group not found: '],
        ];
        foreach ($vectors as [$symbol, $args, $expected]) {
            $length = strlen($expected);
            $buffer = $ffi->new('char['.($length + 2).']');
            foreach ([0, 1, $length - 1, $length, $length + 1] as $capacity) {
                FFI::memset($buffer, 0x5A, $length + 2);
                $written = self::callExport($ffi, $symbol, ...[...$args, $buffer, $capacity]);
                if ($capacity < $length) {
                    $this->assertSame($length === 1 ? -2 : -$length, $written, $symbol);
                    $this->assertSame(str_repeat('Z', $length + 2), FFI::string($buffer, $length + 2), $symbol.' must not write a short buffer');
                } else {
                    $this->assertSame($length, $written, $symbol);
                    $this->assertSame($expected.'ZZ', FFI::string($buffer, $length + 2), $symbol);
                }
            }
            $this->assertSame(-1, self::callExport($ffi, $symbol, ...[...$args, null, 64]), $symbol.' must reject a null output buffer');
        }
        $this->assertSame(strlen('✅ Updated '.PHP_INT_MIN.' groups'), $firstLength[0]);

        $buffer = $ffi->new('char[64]');
        FFI::memset($buffer, 0x5A, 64);
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_rgb_cmd_message', 2, 'x', 1, $buffer, 64));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_rgb_cmd_category_display', 2, 'x', 1, 0, '', 0, $buffer, 64));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_rgb_cmd_filter_groups', $blob('a'), 8, $blob('a'), 9, $buffer, 64));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_rgb_cmd_parse_names', null, 0, $buffer, 64));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_rgb_cmd_completion_lines', 1, 'x', 1, null, $buffer, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($buffer, 64));
    }

    public function test_missing_library_and_disabled_extension_fall_back_in_fresh_processes(): void
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $vectors[] = [$class, $method, $common[0]];
            $vectors[] = [$class, $method, $uncommon[0]];
        }
        $encoded = base64_encode(serialize($vectors));
        $script = 'require "vendor/autoload.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class,$method,$args]) {'
            .'$class::setUseRustFfi(true); $php=$method."Php"; $native=$method."Native";'
            .'if ($class::$php(...$args)!==$class::$native(...$args) || $class::$php(...$args)!==$class::$method(...$args)) throw new RuntimeException($method);'
            .'} echo "fallback verified";';
        foreach ([['-d', 'ffi.enable=0'], []] as $options) {
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => '/tmp/w30-3-missing-library.dylib']);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_cached_load_failure_and_call_time_errors_fall_back_for_every_helper(): void
    {
        $this->nativeFfi();
        $missingSymbols = FFI::cdef('uint32_t nntmux_pipeline_abi_version(void);', self::libraryPath());
        foreach ([false, $missingSymbols] as $handle) {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $class::setFfiForTesting($handle);
                $class::setUseRustFfi(true);
                $php = $method.'Php';
                $native = $method.'Native';
                foreach ([$common[0], $uncommon[0]] as $args) {
                    $this->assertSame(self::outcome(static fn (): mixed => $class::$php(...$args)), self::outcome(static fn (): mixed => $class::$native(...$args)), $class.'::'.$method);
                    $this->assertSame(self::outcome(static fn (): mixed => $class::$php(...$args)), self::outcome(static fn (): mixed => $class::$method(...$args)), $class.'::'.$method);
                }
                $class::setFfiForTesting(null);
                $class::setUseRustFfi(false);
            }
        }
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave30-3-recategorize-groups-backfill-commands-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 30032026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including type checks, FFI marshalling, scratch allocation and result reconstruction. Excludes app boot, console rendering, NNTP and database I/O.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w30-3/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustRecategorizeGroupsBackfillCommandsParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'profile' => 'release: opt-level=3, lto=true, codegen-units=1',
            'library' => ['path' => realpath(self::libraryPath()), 'sha256' => hash_file('sha256', self::libraryPath())],
            'results' => [], 'failures' => [],
        ];
        $random = new Randomizer(new Mt19937(30032026));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $inputs = array_values(array_filter($inputs, static fn (array $args): bool => self::outcome(static fn (): mixed => $class::$php(...$args))[0] === 'value'));
                    $expected = array_map(static fn (array $args): string => serialize($class::$php(...$args)), $inputs);
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
                    for ($round = 0; $round < 3; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < 41; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < 200; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            foreach ($random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'] as $side) {
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $raw[$side][] = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                    }
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                // The production default stays PHP for every helper below the gate.
                $class::setUseRustFfi(null);
                $this->assertFalse($class::usesRustFfi());
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            $summary = ["\nWave 30.3 raw benchmark evidence: {$path}"];
            foreach ($report['results'] as $helper => $cells) {
                foreach ($cells as $kind => $cell) {
                    $summary[] = sprintf('%s/%s mean %.2f-%.2fx p99 %.2f-%.2fx %s', $helper, $kind,
                        $cell['round_range']['mean_speedup']['min'], $cell['round_range']['mean_speedup']['max'],
                        $cell['round_range']['p99_speedup']['min'], $cell['round_range']['p99_speedup']['max'],
                        $cell['qualifies'] ? 'QUALIFIES' : 'below gate');
                }
            }
            fwrite(STDERR, implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $cdef = implode("\n", [RecategorizeReleases::FFI_CDEF, GroupsUpdate::FFI_CDEF, BackfillGroup::FFI_CDEF, UpdateBackfill::FFI_CDEF, UpdateGroupHeaders::FFI_CDEF]);
        $cdef = implode("\n", array_unique(array_map('trim', explode("\n", $cdef))));
        $ffi = RustPipelineLibrary::resolveDomain('w30-3-recategorize-groups-backfill-commands-test', $cdef, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library and Wave 30.3 exports must load; native coverage must not silently skip.');

        return $ffi;
    }

    private static function libraryPath(): string
    {
        $explicit = getenv('NNTMUX_PIPELINE_FFI_LIB');

        return $explicit !== false && $explicit !== ''
            ? $explicit
            : base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
    }

    /**
     * The returned value, or the exception class and message, so throwing inputs compare too.
     *
     * @return array{string, mixed}
     */
    private static function outcome(callable $call): array
    {
        try {
            return ['value', $call()];
        } catch (Throwable $error) {
            return ['error', $error::class.': '.$error->getMessage()];
        }
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 512);
        $server = [];
        $active = [];
        for ($i = 0; $i < 200; $i++) {
            $server[] = ['group' => "alt.binaries.group{$i}", 'first' => (string) (1000 * $i), 'last' => (string) (1000 * $i + 999), 'posting' => 'y'];
            if ($i % 7 === 0) {
                $active[] = "alt.binaries.group{$i}";
            }
        }
        $row = ['group' => 'alt.binaries.teevee', 'first' => '1234567', 'last' => '98765432', 'posting' => 'y'];

        return [
            [RecategorizeReleases::class, 'parseCommaSeparatedInts', [['5040'], ['5040,5030,2040'], ['1,2,3,4,5,6,7,8,9,10']],
                [[''], [','], [' 7 , 8 '], ['1e3,0x10,-0,+5,.5,5.'], ['9223372036854775808,-9223372036854775809,1e1000'], ["\0 1,\x0b2,\x0c3"], [str_repeat('123,', 500).'x']]],
            [RecategorizeReleases::class, 'parseCommaSeparatedGroupNames', [['alt.binaries.teevee'], ['alt.binaries.teevee, alt.binaries.hdtv'], ['a.b,c.d,e.f']],
                [[''], [',,'], [" a \t\n\r\x0b\0,\x0cb"], ["\xc2\xa0x ,é\0"], [$long.','.$long]]],
            [RecategorizeReleases::class, 'buildResetPayload', [[]], [[]]],
            [RecategorizeReleases::class, 'formatCategoryDisplayName', [['HD', 'TV'], ['Other', null], ['Blu-ray', 'Movies']],
                [[null, null], [null, 'TV'], ['HD', ''], ['', 'TV'], ['', ''], ["\0", "\0"], [$long, $long]]],
            [GroupsUpdate::class, 'filterActiveServerGroups', [[$server, $active], [array_slice($server, 0, 10), ['alt.binaries.group0']]],
                [[[], []], [$server, []], [[['group' => '', 'x' => 1]], ['']], [[['group' => 5], ['group' => 'a']], ['a']], [[['group' => 5]], [5]],
                    [[['group' => null], ['group' => "a\0"]], ["a\0", '']], [['k' => ['group' => 'a.b']], ['a.b']], [[['first' => 1]], ['a']], [[null], ['a']], [['abc'], ['a']]]],
            [GroupsUpdate::class, 'projectShortGroupRow', [[$row], [['group' => 'a.b', 'first' => '0', 'last' => '0']]],
                [[[]], [['group' => null, 'first' => null, 'last' => null]], [['group' => 'x', 'first' => ' 12abc', 'last' => '1e3']],
                    [['group' => 'x', 'first' => 7, 'last' => '-99999999999999999999']], [['group' => 5, 'first' => 1, 'last' => 2]],
                    [['group' => 'x', 'first' => 1.9, 'last' => true]], [['group' => [], 'first' => 1, 'last' => 2]], [['group' => $long, 'first' => "\0", 'last' => '0x1A']]]],
            [GroupsUpdate::class, 'formatCompletionLines', [[12, '3.25'], [0, '0'], [450, '61.123456']],
                [[PHP_INT_MIN, ''], [PHP_INT_MAX, "\n"], [-1, $long]]],
            [BackfillGroup::class, 'isValidBackfillType', [[1], [2]], [[0], [3], [-1], [PHP_INT_MAX], [PHP_INT_MIN]]],
            [BackfillGroup::class, 'resolveQuantity', [[null, 1, 20000], [null, 2, 20000], ['50000', 1, 20000]],
                [['', 1, 0], ['abc', 2, 5], ['1e3', 1, 0], [' -7', 2, 0], ['99999999999999999999', 1, 0], [null, 0, PHP_INT_MIN], [null, -1, PHP_INT_MAX], ["\0", 2, 1]]],
            [BackfillGroup::class, 'formatNntpErrorMessage', [['Connection timed out'], ['Authentication rejected']], [[''], ["\0é\xff"], [$long]]],
            [UpdateBackfill::class, 'isGroupNameArgument', [['all'], ['alt.binaries.teevee'], ['date']],
                [[''], ["a.b\n"], ["a.b\r"], ['a.'], ['ALT.Binaries+X'], ["a\0.b"], ['é.b'], [$long]]],
            [UpdateBackfill::class, 'resolveBackfillDispatchPlan', [['all', null], ['alph', 200000], ['alt.binaries.teevee', null], ['alt.binaries.teevee', 50000]],
                [['all', 5], ['safe', null], ['safe', PHP_INT_MIN], ['date', 0], ['ALL', null], ["a.b\n", -1], ['', null], [$long, 1]]],
            [UpdateGroupHeaders::class, 'clampMaxHeaders', [['0'], ['20000'], [null]],
                [['-5'], ['1e100'], ['1e1000'], [' 12abc'], [PHP_INT_MIN], [true], [false], [1.9], [-0.5], [[]], [[1]], ["\0"]]],
            [UpdateGroupHeaders::class, 'formatGroupNotFoundMessage', [['alt.binaries.teevee']], [[''], ["\0é\xff"], [$long]]],
            [UpdateGroupHeaders::class, 'formatNntpConnectionFailedMessage', [['Connection timed out']], [[''], ["\0é\xff"], [$long]]],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1]];
    }

    private static function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
