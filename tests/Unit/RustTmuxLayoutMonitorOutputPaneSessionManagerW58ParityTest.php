<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TmuxPaneRole;
use App\Services\Tmux\TmuxLayoutBuilder;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\Tmux\TmuxOutput;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use App\Support\RustPipelineLibrary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 58.4 batch helpers: TmuxLayoutBuilder layout rows (tmux window_layout parsing, checksum,
 * split geometry and the planned pane count per window), TmuxMonitorService monitor cycle rows
 * (saturating deltas, rate, ETA, percent and a health score), TmuxOutput console line rows (CSI
 * and SGR scanning, visible width, progress percent/bar and duration), TmuxPaneManager pane
 * health rows (listPanes() line parsing, reap/respawn eligibility, role conflicts) and
 * TmuxSessionManager session readiness rows (list-sessions/list-windows line parsing and name
 * validation). Each export takes a concatenated blob plus packed int64 words; Rust writes
 * fixed-width int64 rows and a batch trailer that PHP decodes with one unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call
 * count in the function static of w58RustFfiState().
 *
 * The paired benchmark (NNTMUX_W584_BENCH=1) times the PHP and native batch methods, including
 * packing and decoding, over interleaved rounds of 320 records in random order per round
 * (fixed seed). Rounds per workload: NNTMUX_W584_BENCH_ROUNDS (default 200, minimum 60). It
 * requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99, and
 * skips without the native library.
 *
 * Three runs at b58c7f325 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 200 rounds per workload after 10 warmups, load average ~3), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): layoutPlanRowBatch 8.29/10.67, 15.40/15.60;
 * monitorCycleRowBatch 1.64/1.48, 1.59/1.59; consoleLineRowBatch 4.39/3.58, 8.07/8.04;
 * paneHealthRowBatch 3.13/2.61, 2.90/2.74; sessionLineRowBatch 2.99/2.78, 1.40/1.45. Common
 * batches hold valid layouts, realistic counters, monitor-style ANSI lines, listPanes() lines and
 * list-sessions/list-windows lines; uncommon batches mix mutated, truncated, random-byte, deeply
 * nested and overlong-number inputs with extreme ints. The layout, console line and pane health
 * helpers default to Rust; monitorCycleRowBatch (packing six int columns costs about as much as
 * the PHP arithmetic) and sessionLineRowBatch (malformed lines fail fast in PHP) default to PHP.
 */
final class RustTmuxLayoutMonitorOutputPaneSessionManagerW58ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'layout' => [TmuxLayoutBuilder::class, 'layoutPlanRowBatch', 11, 4],
        'monitor' => [TmuxMonitorService::class, 'monitorCycleRowBatch', 9, 4],
        'output' => [TmuxOutput::class, 'consoleLineRowBatch', 12, 4],
        'pane' => [TmuxPaneManager::class, 'paneHealthRowBatch', 10, 7],
        'session' => [TmuxSessionManager::class, 'sessionLineRowBatch', 6, 4],
    ];

    /** W58_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        TmuxLayoutBuilder::class => true,
        TmuxMonitorService::class => false,
        TmuxOutput::class => true,
        TmuxPaneManager::class => true,
        TmuxSessionManager::class => false,
    ];

    private const int SEED = 5842026;

    private const array SPICE = ['é', 'ß', '日本', "\t", '  ', ':', '.', ' (', ')', '[', ']', '{', '}', ',', 'x', "\n", "\r", "\0",
        "\x1f", "\x7f", "\xff", "\x80", "\e", "\e[", "\e[0m", "\e[1;31m", "\e[38;5;208m", "\e[?25l", "\e[31", '%', '@', '*', '-', '#'];

    private const array ROLES = ['monitor', 'binaries', 'backfill', 'releases', 'sequential', 'fix_names', 'remove_crap',
        'post_additional', 'post_tv', 'post_metadata', 'post_movies', 'irc_scraper', 'htop', 'redis', 'console'];

    private const array WINDOWS = ['Monitor', 'Utils', 'Post', 'IRC', 'htop', 'nmon', 'vnstat', 'Console', 'bash', 'Redis'];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words, $trailer]) {
            $cases[$name] = [$class, $method, $words, $trailer, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w58RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W58_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w58', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW58RustFfi($native, static fn (): array => $class::{$method}($items));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $items */
    private static function assertParity(string $class, string $method, int $words, int $trailer, array $items, string $label): void
    {
        $php = $class::{$method.'Php'}($items);
        self::assertSame($php, self::dispatch($class, $method, false, $items), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $items), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($items), $label.': native method');
        self::assertCount($items === [] ? 0 : \count($items) * $words + $trailer, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length, ?string $alphabet = null): string
    {
        if ($length <= 0) {
            return '';
        }

        return $alphabet === null ? $random->getBytes($length) : $random->getBytesFromString($alphabet, $length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 29, 30, 31, 59, 60, 600, 601, 3599, 3600, 3601, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1,
            PHP_INT_MIN + 1, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 1000), $random->getInt(0, 1 << 40)]);
    }

    /** Insert, replace, delete or truncate a few bytes. */
    private static function mutate(Randomizer $random, string $value): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $length = \strlen($value);
            $at = $length === 0 ? 0 : $random->getInt(0, $length - 1);
            $value = match ($random->getInt(0, 3)) {
                0 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at),
                1 => substr($value, 0, $at).self::bytes($random, 1, '0123456789x,{}[]:() %@*-sabc').substr($value, $at + 1),
                2 => substr($value, 0, $at).substr($value, $at + 1),
                default => substr($value, 0, $at),
            };
        }

        return $value;
    }

    private static function layoutChecksum(string $body): int
    {
        $sum = 0;
        foreach (str_split($body) as $char) {
            $sum = ($sum >> 1) + (($sum & 1) << 15) + \ord($char);
            $sum &= 0xFFFF;
        }

        return $sum;
    }

    /** A geometrically consistent layout cell with increasing pane IDs. */
    private static function layoutCell(Randomizer $random, int $w, int $h, int $x, int $y, int $depth, int $maxDepth, int &$id): string
    {
        $head = "{$w}x{$h},{$x},{$y}";
        $horizontal = $random->getInt(0, 1) === 0;
        $size = $horizontal ? $w : $h;
        $children = $random->getInt(2, 3);
        if ($depth >= $maxDepth || $size < 2 * $children + 1 || $random->getInt(0, 2) === 0) {
            return $head.','.$id++;
        }
        $cells = [];
        $offset = $horizontal ? $x : $y;
        $left = $size - ($children - 1);
        for ($i = 0; $i < $children; $i++) {
            $part = $i === $children - 1 ? $left : $random->getInt(1, $left - ($children - 1 - $i));
            $left -= $part;
            $cells[] = $horizontal
                ? self::layoutCell($random, $part, $h, $offset, $y, $depth + 1, $maxDepth, $id)
                : self::layoutCell($random, $w, $part, $x, $offset, $depth + 1, $maxDepth, $id);
            $offset += $part + 1;
        }

        return $head.($horizontal ? '{'.implode(',', $cells).'}' : '['.implode(',', $cells).']');
    }

    private static function layout(Randomizer $random, int $maxDepth, ?int $w = null, ?int $h = null): string
    {
        $id = $random->getInt(0, 40);
        $body = self::layoutCell($random, $w ?? $random->getInt(40, 320), $h ?? $random->getInt(12, 90), 0, 0, 0, $maxDepth, $id);

        return \sprintf('%04x,', self::layoutChecksum($body)).$body;
    }

    /** @return array{0: string, 1: int, 2: int} */
    private static function layoutItem(Randomizer $random, bool $uncommon): array
    {
        if (! $uncommon) {
            return [self::layout($random, $random->getInt(0, 3)), $random->getInt(0, 2), $random->getInt(0, 3)];
        }
        $valid = self::layout($random, $random->getInt(0, 9), $random->getInt(1, 2000), $random->getInt(1, 2000));
        $layout = match ($random->getInt(0, 8)) {
            0 => self::mutate($random, $valid),
            1 => strtoupper(substr($valid, 0, 4)).substr($valid, 4),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => self::bytes($random, $random->getInt(0, 60), '0123456789abcdefx,{}[]'),
            4 => sprintf('%04x', $random->getInt(0, 0xFFFF)).substr($valid, 4),
            5 => '0000,'.str_repeat('80x24,0,0{', $random->getInt(60, 70)).'80x24,0,0,1'.str_repeat('}', 65),
            6 => '0000,1234567890x24,0,0,1',
            7 => str_replace(',0,0,', ',0,1,', $valid),
            default => $valid,
        };

        return [$layout, self::extremeInt($random), self::pick($random, [-1, 0, 1, 2, 3, 4, PHP_INT_MAX, PHP_INT_MIN])];
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} */
    private static function monitorItem(Randomizer $random, bool $uncommon): array
    {
        if (! $uncommon) {
            $start = $random->getInt(0, 50_000_000);
            $backlog = $random->getInt(0, 2_000_000);

            return [$start + $random->getInt(0, 20_000), $start, $random->getInt(1, 900), $backlog,
                max(0, $backlog + $random->getInt(-5000, 5000)), $random->getInt(0, 100_000)];
        }

        return [self::extremeInt($random), self::extremeInt($random), self::extremeInt($random), self::extremeInt($random),
            self::extremeInt($random), self::extremeInt($random)];
    }

    private static function ansi(string $text, string $colour): string
    {
        return "\e[{$colour}m{$text}\e[0m";
    }

    /** @return array{0: string, 1: int, 2: int, 3: int} */
    private static function outputItem(Randomizer $random, bool $uncommon): array
    {
        if (! $uncommon) {
            $label = self::pick($random, ['Collections', 'Binaries', 'Parts', 'Releases', 'Monitor Running', 'TV', 'Movies', 'Activated']);
            $value = number_format($random->getInt(0, 90_000_000)).'('.$random->getInt(0, 99).'%)';
            $line = match ($random->getInt(0, 3)) {
                0 => self::ansi(\sprintf('%-18s', $label), '0;33').' '.self::ansi(\sprintf('%-60.60s', $value), '0;33').PHP_EOL,
                1 => self::ansi(\sprintf('%-16.16s', $label), '0;32').' '.self::ansi(\sprintf('%25.25s %25.25s', $value, $value), '0;33').PHP_EOL,
                2 => self::ansi($label.' = ', '0;32').self::ansi($value, '0;33'),
                default => \sprintf('%-16.16s %25.25s %25.25s', $label, $value, $value).PHP_EOL,
            };
            $total = $random->getInt(1, 1_000_000);

            return [$line, $random->getInt(0, $total), $total, $random->getInt(0, 200_000)];
        }
        $parts = [];
        for ($i = $random->getInt(0, $random->getInt(0, 1) === 0 ? 12 : 400); $i > 0; $i--) {
            $parts[] = match ($random->getInt(0, 5)) {
                0 => self::pick($random, self::SPICE),
                1 => "\e[".self::bytes($random, $random->getInt(0, 14), '0123456789;:<=>? !"#/').self::bytes($random, $random->getInt(0, 1), '@ABmHJKhl~`'),
                2 => "\e[".implode(';', array_map(static fn (): string => (string) $random->getInt(0, 120), range(0, $random->getInt(0, 6)))).'m',
                3 => self::bytes($random, $random->getInt(0, 12)),
                4 => "\e[".str_repeat('9', $random->getInt(8, 12)).'m',
                default => self::ansi(self::bytes($random, $random->getInt(0, 8), 'abc é'), (string) $random->getInt(0, 99)),
            };
        }

        return [implode('', $parts), self::extremeInt($random), self::extremeInt($random), self::extremeInt($random)];
    }

    /** @return array{0: string, 1: int, 2: int} */
    private static function paneItem(Randomizer $random, bool $uncommon): array
    {
        $dead = $random->getInt(0, 3) === 0;
        $exit = $dead ? self::pick($random, ['', '', '0', '1', '137', '255']) : '';
        $signal = $dead && $exit === '' ? self::pick($random, ['', '9', '15']) : '';
        $fields = ['%'.$random->getInt(0, 300), $random->getInt(0, 5) === 0 ? '' : self::pick($random, self::ROLES), $dead ? '1' : '0',
            $exit, (string) $random->getInt(100, 4_000_000), $random->getInt(0, 1) === 0 ? '1' : '0', '@'.$random->getInt(0, 20), $signal,
            (string) $random->getInt(1, 99_999)];
        $now = $random->getInt(1_700_000_000, 1_800_000_000);
        $lastReap = $random->getInt(0, 1) === 0 ? PHP_INT_MIN : $now - $random->getInt(0, 60);
        if (! $uncommon) {
            return [implode("\t", $fields), $now, $lastReap];
        }
        $at = $random->getInt(0, 8);
        $fields[$at] = match ($random->getInt(0, 7)) {
            0 => '',
            1 => self::pick($random, ['%', '%x', '12', '%%1', '% 1', '%-1', '@1', '-1', '+5', ' 1', '1 ', '01', 'bash']),
            2 => str_repeat('9', $random->getInt(17, 22)),
            3 => '%'.str_repeat('7', $random->getInt(17, 22)),
            4 => self::bytes($random, $random->getInt(0, 10)),
            5 => self::pick($random, ['monitor', 'MONITOR', 'unknown', 'bandwidth_monitor', 'mytop', 'nmon', 'vnstat', 'tcptrack']),
            6 => self::pick($random, self::SPICE),
            default => (string) $random->getInt(0, 2),
        };
        $line = implode("\t", $fields);
        $line = match ($random->getInt(0, 4)) {
            0 => implode("\t", \array_slice($fields, 0, $random->getInt(0, 8))),
            1 => $line.str_repeat("\t".self::bytes($random, 2, 'ab1'), $random->getInt(1, 3)),
            2 => self::mutate($random, $line),
            default => $line,
        };

        return [$line, self::extremeInt($random), self::pick($random, [PHP_INT_MIN, PHP_INT_MAX, self::extremeInt($random)])];
    }

    /** @return array{0: string, 1: string, 2: int, 3: int} */
    private static function sessionItem(Randomizer $random, bool $uncommon): array
    {
        $kind = $random->getInt(0, 1);
        $name = $kind === 0 ? self::pick($random, ['nntmux', 'NNTmux', 'main', 'work-2', 'a', 'dev_box']) : self::pick($random, self::WINDOWS);
        $count = $random->getInt(1, 12);
        $line = $kind === 0
            ? "{$name}: {$count} window".($count === 1 ? '' : 's').' (created Mon Oct '.$random->getInt(1, 31).' 03:19:04 2026)'
                .($random->getInt(0, 3) === 0 ? ' (group g'.$random->getInt(0, 9).')' : '').($random->getInt(0, 1) === 0 ? ' (attached)' : '')
            : $random->getInt(0, 30).": {$name}".self::pick($random, ['', '', '*', '-', '#', '*Z', '!', '~', '*-'])." ({$count} pane".($count === 1 ? '' : 's').')'
                .($random->getInt(0, 4) === 0 ? '' : ' ['.$random->getInt(20, 400).'x'.$random->getInt(10, 120).'] [layout b25d,80x24,0,0,1] @'.$random->getInt(0, 9))
                .($random->getInt(0, 2) === 0 ? ' (active)' : '');
        $expected = $random->getInt(0, 3) === 0 ? self::pick($random, ['nntmux', 'Post', 'other', '']) : $name;
        if (! $uncommon) {
            return [$line, $expected, $kind, $random->getInt(0, 8)];
        }
        $line = match ($random->getInt(0, 6)) {
            0, 1 => self::mutate($random, $line),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => self::pick($random, ['a.b: 1 windows (created x)', 'a:b: 2 windows (created x)', ': 1 windows (created x)', "a\x01: 1 window (created x)",
                str_repeat('n', 256).': 1 window (created x)', 'n: 1234567890 windows (created x)', 'n: 1 windows (created x', '1234567890: w (1 panes)',
                '1: (1 panes)', '1: * (1 panes)', '1: w (1 pane) [80x]', '1: w (1 pane) [80x24', '1: w (1 panes) [1234567890x1]', '1: w (01 panes)']),
            4 => str_replace(': ', ':  ', $line),
            default => $line,
        };

        return [$line, $random->getInt(0, 3) === 0 ? self::bytes($random, 4) : $expected, self::pick($random, [0, 1, 0, 1, 2, -1, PHP_INT_MAX]),
            self::extremeInt($random)];
    }

    /** @return list<mixed> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'layout' => self::layoutItem($random, $uncommon),
                'monitor' => self::monitorItem($random, $uncommon),
                'output' => self::outputItem($random, $uncommon),
                'pane' => self::paneItem($random, $uncommon),
                default => self::sessionItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<mixed> */
    private static function edgeItems(string $key): array
    {
        $ints = [0, 1, -1, PHP_INT_MAX, PHP_INT_MIN];
        $items = [];
        switch ($key) {
            case 'layout':
                foreach (['', '0000', '0000,', 'b25d,80x24,0,0,1', 'B25D,80x24,0,0,1', 'zzzz,80x24,0,0,1', '0000,80x24,0,0', '0000,80x24,0,0,',
                    '0000,80x24,0,0{', '0000,80x24,0,0{}', '0000,80x24,0,0{40x24,0,0,1}', '0000,80x24,0,0{40x24,0,0,1,39x24,41,0,2]',
                    '0000,80x24,0,0[80x12,0,0,1,80x11,0,13,2]', '0000,80x24,0,0,1,', '0000,80x24,0,0,1 ', '0000,999999999x999999999,0,0,1',
                    '0000,1x1,0,0,0', '0000,80x24,0,0{40x24,0,0,5,39x24,41,0,5}', '0000,80x24,0,0{40x24,0,0,1,40x24,41,0,2}',
                    '0000,80x24,1,1,1', '0000,80x24,0,0{40x24,0,0[40x12,0,0,1,40x11,0,13,2],39x24,41,0,3}'] as $layout) {
                    foreach ([[0, 0], [1, 2], [2, 2], [3, 3], [PHP_INT_MIN, PHP_INT_MAX]] as [$mode, $window]) {
                        $items[] = [$layout, $mode, $window];
                    }
                }
                foreach ([0, 1, 2, 3, 4, -1, 99] as $mode) {
                    foreach ([-1, 0, 1, 2, 3, 4] as $window) {
                        $items[] = ['bad', $mode, $window];
                    }
                }
                $items[] = ['0000,'.str_repeat('80x24,0,0{', 64).'80x24,0,0,1'.str_repeat('}', 64), 0, 0];
                $items[] = ['0000,'.str_repeat('80x24,0,0{', 65).'80x24,0,0,1'.str_repeat('}', 65), 0, 0];
                break;
            case 'monitor':
                foreach ($ints as $a) {
                    foreach ($ints as $b) {
                        $items[] = [$a, $b, 1, $a, $b, $a];
                        $items[] = [$a, $b, -1, $b, $a, $b];
                    }
                }
                array_push($items, [10, 10, 60, 5, 5, 1], [0, 0, 0, 0, 0, 0], [1, 0, 60, 3600, 0, 1], [100, 0, 1, PHP_INT_MAX, 0, 0],
                    [PHP_INT_MAX, 0, 1, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX], [7, 0, 3, 1, 2, 5], [1, 0, 100, 601, 600, 2]);
                break;
            case 'output':
                foreach (['', "\n", "\r\n", "\e", "\e[", "\e[m", "\e[;m", "\e[0m", "\e[31m", "\e[31;0m", "\e[0;31m", "\e[39m", "\e[90m", "\e[97m",
                    "\e[98m", "\e[1;2;3;4;5;6;7;8;9;30m", "\e[0000000031m", "\e[000000031m", "\e[31:1m", "\e[?25l", "\e[ m", "\e[1 q", "\e[31\x80",
                    "\e[31\e[32m", "\e\e[33m", "\e]0;title\x07", "abc\e[2Jdef", 'é日本', "\x80\xbf\xc0", "\x7f\x00\x1f", str_repeat("\e[0;33mx\e[0m", 300),
                    str_repeat('é', 2000), "\e[38;5;208mX", "\e[48;2;1;2;3mY\e[49m"] as $line) {
                    $items[] = [$line, 1, 2, 0];
                }
                foreach ([[0, 0], [1, 0], [5, -1], [50, 100], [100, 100], [101, 100], [-1, 100], [1, 3], [2, 3], [PHP_INT_MAX, 1], [PHP_INT_MIN, 1],
                    [1, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MAX]] as [$part, $total]) {
                    foreach ([0, 59, 60, 3599, 3600, 86399, -1, PHP_INT_MAX, PHP_INT_MIN] as $elapsed) {
                        $items[] = ['x', $part, $total, $elapsed];
                    }
                }
                break;
            case 'pane':
                foreach (["%1\tmonitor\t0\t\t1\t0\t@0\t\t9", "%1\tmonitor\t1\t\t1\t0\t@0\t\t9", "%1\tmonitor\t1\t0\t1\t0\t@0\t\t9",
                    "%1\tmonitor\t1\t1\t1\t0\t@0\t\t9", "%1\tmonitor\t1\t\t1\t0\t@0\t9\t9", "%1\tmonitor\t1\t0\t1\t0\t@0\t9\t9", "%1\t\t0\t\t1\t1\t@0\t\t9",
                    "%1\t\t1\t\t1\t1\t@0\t\t9", '%1', "%1\t", "%1\tmonitor", "%1\tmonitor\t1\t\t1\t0\t@0", "%1\tmonitor\t1\t\t1\t0\t@0\t\t9\t",
                    "%1\tmonitor\t1\t\t1\t0\t@0\t\t9\tx\ty", '', "\t", '%', '%a', '1', '%01', "%1\n", ' %1', "%999999999999999999\tx", "%9999999999999999999\tx",
                    "%1\tMonitor\t0", "%1\tbandwidth_monitor\t0", "%1\tconsole\t0", "%1\tx\t1\tabc\t-1\t1\tw\t-9\t", "%1\tx\t1\t\t\t\t@\t\t",
                    "%1\tx\t1\t999999999999999999\t1", "%1\tx\t1\t1000000000000000000\t1", "%1\tx\t11\t\t1", "%1\tx\t 1\t\t1"] as $line) {
                    foreach ([[100, 70], [100, 71], [100, 69], [0, PHP_INT_MIN], [PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MIN], [-1, PHP_INT_MAX]] as [$now, $last]) {
                        $items[] = [$line, $now, $last];
                    }
                }
                foreach (TmuxPaneRole::cases() as $i => $role) {
                    $items[] = ["%{$i}\t{$role->value}\t0\t\t1\t0\t@1\t\t2", 0, 0];
                    $items[] = ["%{$i}\t{$role->value}\t1\t\t1\t0\t@1\t\t2", 0, 0];
                }
                break;
            default:
                foreach (['nntmux: 1 window (created Mon Oct 26 03:19:04 2026)', 'nntmux: 3 windows (created x) (group g) (attached)',
                    'nntmux: 3 windows (created x)(attached)', 'nntmux: 3 windows (created )', 'nntmux: 3 windowss (created x)',
                    'nntmux: 3 windows (created x', 'nntmux:3 windows (created x)', 'nn: tmux: 3 windows (created x)', ': 3 windows (created x)',
                    'a.b: 1 window (created x)', 'a:b: 1 window (created x)', "a\tb: 1 window (created x)", str_repeat('n', 255).': 1 window (created x)',
                    str_repeat('n', 256).': 1 window (created x)', 'nntmux: 000000003 windows (created x)', 'nntmux: 1234567890 windows (created x)',
                    '0: Monitor* (4 panes) [80x24] [layout b25d,80x24,0,0,1] @0 (active)', '1: Utils- (1 pane)', '2: Post#!~ (2 panes) [1x1]',
                    '3: IRC (1 panes) [80x24', '3: IRC (1 panes) [x24]', '3: IRC (1 panes) [80x]', '3: IRC (1 panes)[80x24]', '4: * (1 panes)',
                    '5:  (1 panes)', '6: a b c (2 panes)', '7: w (panes)', '7: w (2 pane', '7: w (2 panes', '123456789: w (1 pane)', '1234567890: w (1 pane)',
                    'x: w (1 pane)', '-1: w (1 pane)', '8: (w) (1 pane)', '9: w (1 panes) [999999999x999999999]', '', ':', ': ', 'a: ', 'é: 1 window (created x)'] as $line) {
                    $name = explode(': ', $line, 2)[0];
                    $window = preg_replace('/^\d+: (.*?)[*\-#!~]* \(.*/', '$1', $line);
                    foreach ([0, 1, 2, -1] as $kind) {
                        $items[] = [$line, $name, $kind, 1];
                        $items[] = [$line, (string) $window, $kind, PHP_INT_MIN];
                        $items[] = [$line, '', $kind, PHP_INT_MAX];
                    }
                }
        }

        return $items;
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w58_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertNotSame('', $key);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W58_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        self::assertSame($words, $reflection->getConstant('W58_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W58_TRAILER_WORDS'));
        foreach (['usesW58RustFfi', 'withW58RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w58RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW58RustFfi());
        self::assertFalse($class::withW58RustFfi(false, static fn (): bool => $class::usesW58RustFfi()));
        self::assertTrue($class::withW58RustFfi(true, static fn (): bool => $class::usesW58RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW58RustFfi(), 'withW58RustFfi restores the default');

        $nested = $class::withW58RustFfi(false, static fn (): array => [
            $class::usesW58RustFfi(),
            $class::withW58RustFfi(true, static fn (): bool => $class::usesW58RustFfi()),
            $class::usesW58RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW58RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW58RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w58', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW58RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        TmuxOutput::withW58RustFfi(true, static function (): void {
            self::assertTrue(TmuxOutput::usesW58RustFfi());
            foreach ([TmuxLayoutBuilder::class, TmuxMonitorService::class, TmuxPaneManager::class, TmuxSessionManager::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W58_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW58RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $before = [TmuxLayoutBuilder::usesRustFfi(), TmuxMonitorService::usesRustFfi(), TmuxOutput::usesRustFfi(),
            TmuxPaneManager::usesRustFfi(), TmuxSessionManager::usesRustFfi()];
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW58RustFfi($enabled, static function () use ($before): void {
                    self::assertSame($before, [TmuxLayoutBuilder::usesRustFfi(), TmuxMonitorService::usesRustFfi(), TmuxOutput::usesRustFfi(),
                        TmuxPaneManager::usesRustFfi(), TmuxSessionManager::usesRustFfi()]);
                    self::assertCount(11, TmuxLayoutBuilder::planCoreLayoutStepsPhp(0));
                });
            }
        }
    }

    public function test_native_library_exports_every_symbol(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            self::assertTrue(self::native($class), $class);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words + $trailer);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
        }
        $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $first = $items[0];
        self::assertIsArray($first);
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W58', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $items = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $trailer, $items, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeItems($key);
        foreach ($edges as $i => $item) {
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    public function test_hand_checked_rows(): void
    {
        foreach ([false, true] as $native) {
            self::assertSame(
                [1, 80, 24, 2, 1, 1, 0, 936, 1, 2, 1 | 4 | 8 | 16, 1, 0, 1, 2],
                self::dispatch(TmuxLayoutBuilder::class, 'layoutPlanRowBatch', $native, [['b25d,80x24,0,0{40x24,0,0,1,39x24,41,0,2}', 0, 1]]),
            );
            $body = '80x24,0,0[80x12,0,0,1,80x11,0,13{40x11,0,13,2,39x11,41,13,3}]';
            self::assertSame(
                [0, 80, 24, 3, 2, 1, 1, 429, 3, 4, 2 | 4 | 8 | 16, 1, 1, 0, 3],
                self::dispatch(TmuxLayoutBuilder::class, 'layoutPlanRowBatch', $native, [[\sprintf('%04x,', self::layoutChecksum($body)).$body, 9, 2]]),
            );
            self::assertSame(
                [50, 100, 50, 120, 20, 1, 2, 20, 4, 50, 0, 120, 20],
                self::dispatch(TmuxMonitorService::class, 'monitorCycleRowBatch', $native, [[150, 100, 30, 200, 150, 3]]),
            );
            self::assertSame(
                [0, 0, -5, -1, 0, -1, 1 | 4, 50, 2, 0, 1, -1, 50],
                self::dispatch(TmuxMonitorService::class, 'monitorCycleRowBatch', $native, [[5, 5, 60, 10, 15, 1]]),
            );
            self::assertSame(
                [5, 4, 3, 2, 1, -1, 2, 1 | 16, 33, 6, 1, 205, 5, 4, 1, 1],
                self::dispatch(TmuxOutput::class, 'consoleLineRowBatch', $native, [["\e[0;33mHi \xc3\xa9\e[0m\e[31\n", 1, 3, 3725]]),
            );
            self::assertSame(
                [3, 0, (3 << 32) | 7, (19 << 32) | 2, 3, -1, -1, 42, 7, 1 | 4 | 8 | 128,
                    4, 0, (3 << 32) | 7, (19 << 32) | 2, 0, -1, -1, 43, 7, 2 | 64 | 128,
                    -1, -1, -1, -1, -1, -1, -1, -1, -1, 0,
                    2, 1, 1, 1, 1, 1, 7],
                self::dispatch(TmuxPaneManager::class, 'paneHealthRowBatch', $native, [
                    ["%3\tmonitor\t1\t\t42\t0\t@1\t\t7", 100, 60],
                    ["%4\tmonitor\t0\t\t43\t1\t@1\t\t7\textra", 0, PHP_INT_MIN],
                    ['x', 0, 0],
                ]),
            );
            self::assertSame(
                [0, -1, 6, 4, (27 << 32) | 24, 1 | 4 | 8 | 16 | 32, 1, 2, (3 << 32) | 4, 4, (80 << 32) | 24, 1 | 2 | 4 | 8, -1, -1, -1, 0, -1, 0, 2, 2, 1, 1],
                self::dispatch(TmuxSessionManager::class, 'sessionLineRowBatch', $native, [
                    ['nntmux: 4 windows (created Mon Oct 26 03:19:04 2026) (attached)', 'nntmux', 0, 4],
                    ['2: Post*- (4 panes) [80x24] [layout b25d] @2 (active)', 'Post', 1, 5],
                    ['x', '', 3, 0],
                ]),
            );
        }
    }

    /** Rust's role table and pane counts must follow the PHP enums and core layout plans. */
    public function test_role_and_plan_tables_follow_the_php_sources(): void
    {
        $items = [];
        foreach (TmuxPaneRole::cases() as $i => $role) {
            $items[] = ["%{$i}\t{$role->value}\t0", 0, 0];
        }
        foreach ([false, true] as $native) {
            $rows = self::dispatch(TmuxPaneManager::class, 'paneHealthRowBatch', $native, $items);
            foreach (TmuxPaneRole::cases() as $i => $role) {
                self::assertSame($i, $rows[$i * 10 + 1], $role->value);
            }
            foreach ([0, 1, 2] as $mode) {
                $plan = TmuxLayoutBuilder::planCoreLayoutStepsPhp($mode);
                foreach ([0, 1, 2, 3] as $window) {
                    $expected = \count(array_filter($plan, static fn (array $step): bool => $step['window'] === $window));
                    $row = self::dispatch(TmuxLayoutBuilder::class, 'layoutPlanRowBatch', $native, [['', $mode, $window]]);
                    self::assertSame($expected, $row[9], "mode {$mode} window {$window}");
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_W584_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W584_BENCH=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('NNTMUX_W584_BENCH_ROUNDS') ?: 200));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        $failures = [];
        foreach (self::HELPERS as $key => [$class, $method]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $php = [];
                $rust = [];
                for ($round = -10; $round < $rounds; $round++) {
                    $items = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}($items);
                        $times[$suffix] = hrtime(true) - $start;
                    }
                    if ($round >= 0) {
                        $php[] = $times['Php'];
                        $rust[] = $times['Native'];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(0.99 * $rounds) - 1;
                $ratios[$workload.' mean'] = array_sum($php) / max(1, array_sum($rust));
                $ratios[$workload.' p99'] = $php[$p99] / max(1, $rust[$p99]);
            }
            $report[] = \sprintf('%s::%s %s', $key, $method, implode(', ', array_map(
                static fn (string $label, float $ratio): string => \sprintf('%s %.2fx', $label, $ratio), array_keys($ratios), $ratios)));
            if ($class::W58_RUST_FFI_DEFAULT && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2);
            }
        }
        fwrite(STDERR, "\nW58.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
