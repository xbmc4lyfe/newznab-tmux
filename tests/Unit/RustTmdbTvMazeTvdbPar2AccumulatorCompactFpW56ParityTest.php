<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2PacketAccumulator;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 56.4 batch helpers: TmdbProvider, TvMazeProvider and TvdbProvider show rows (IMDB id
 * spans, air date parsing, title/alias matching and similar_text() scores), Par2PacketAccumulator
 * packet rows (header checks, MD5 and recovery-set deduplication, Main/FileDesc sizes) and
 * CompactFingerprintCollector fingerprint rows (binary/hex normalization and deduplication).
 * The provider exports take NUL-joined text columns; the PAR2 and fingerprint exports take a
 * concatenated blob plus packed int64 lengths. Rust writes fixed-width int64 words per record
 * that PHP decodes with one unpack('P*'), so a batch costs one FFI crossing.
 *
 * Every helper keeps its routing override, cached binding and output buffer in the function
 * static of w56RustFfiState().
 *
 * The paired benchmark (NNTMUX_W564_BENCH=1) times the PHP and native batch methods, including
 * packing and decoding, over interleaved rounds of 320 records in random order per round
 * (fixed seed). Rounds per workload: NNTMUX_W564_BENCH_ROUNDS (default 1000, minimum 15). It
 * requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99, and
 * skips without the native library.
 *
 * Three runs at 78d554b26 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 1000 rounds per workload after 20 warmups, load average ~7), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): tmdbShowRowBatch 6.10/5.14, 77.24/54.09;
 * tvMazeShowRowBatch 4.19/3.69, 32.55/23.47; tvdbShowRowBatch 2.21/2.17, 1.15/1.20;
 * par2PacketRowBatch 2.73/2.24, 2.17/1.93; fingerprintRowBatch 1.35/1.27, 1.25/1.17. Common
 * batches hold realistic records; uncommon batches mix long, multibyte, malformed,
 * control-byte and whitespace-heavy values, oversized PAR2 payloads and extreme integers.
 * tvdbShowRowBatch (aliases holding the 0x1f separator are recomputed in PHP) and
 * fingerprintRowBatch (per-value PHP work is already cheap) default to PHP; the other three
 * default to Rust.
 */
final class RustTmdbTvMazeTvdbPar2AccumulatorCompactFpW56ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record]. */
    private const array HELPERS = [
        'tmdb' => [TmdbProvider::class, 'tmdbShowRowBatch', 4],
        'tvmaze' => [TvMazeProvider::class, 'tvMazeShowRowBatch', 5],
        'tvdb' => [TvdbProvider::class, 'tvdbShowRowBatch', 5],
        'par2' => [Par2PacketAccumulator::class, 'par2PacketRowBatch', 5],
        'fingerprint' => [CompactFingerprintCollector::class, 'fingerprintRowBatch', 4],
    ];

    private const int SEED = 5642026;

    private const array WORDS = ['the', 'office', 'breaking', 'bad', 'doctor', 'who', 'game', 'of', 'thrones', 'house',
        'lost', 'true', 'detective', 'mr', 'robot', 'star', 'trek', 'next', 'generation', 'shogun', 'severance', 'fargo'];

    private const array SPICE = ['é', 'ß', 'ü', 'Σ', '日本', '—', "\t", '  ', ':', '!', '&', '(', ')', "'", '<', '>', "\n", "\x1f", "\xff"];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words]) {
            $cases[$name] = [$class, $method, $words, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w56RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W56_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w56', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $args): array
    {
        $result = $class::withW56RustFfi($native, static fn (): array => $class::{$method}(...$args));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $args */
    private static function assertParity(string $class, string $method, int $words, array $args, string $label): void
    {
        $php = $class::{$method.'Php'}(...$args);
        self::assertSame($php, self::dispatch($class, $method, false, $args), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $args), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}(...$args), $label.': native method');
        self::assertIsArray($args[0]);
        self::assertCount(\count($args[0]) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** getBytes()/getBytesFromString() that also allow an empty result. */
    private static function bytes(Randomizer $random, int $length, ?string $alphabet = null): string
    {
        if ($length <= 0) {
            return '';
        }

        return $alphabet === null ? $random->getBytes($length) : $random->getBytesFromString($alphabet, $length);
    }

    private static function title(Randomizer $random, bool $uncommon): string
    {
        $count = $uncommon ? $random->getInt(1, 40) : $random->getInt(1, 4);
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $word = self::WORDS[$random->getInt(0, \count(self::WORDS) - 1)];
            $parts[] = $random->getInt(0, 3) === 0 ? ucfirst($word) : $word;
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $parts[] = self::SPICE[$random->getInt(0, \count(self::SPICE) - 1)];
            }
        }
        $title = implode(' ', $parts);
        if ($uncommon && $random->getInt(0, 3) === 0) {
            $title = str_repeat(' ', $random->getInt(1, 5)).$title.str_repeat("\t", $random->getInt(0, 3));
        }

        return $title;
    }

    /** A related name: the same title in another case, trimmed or edited. */
    private static function ours(Randomizer $random, string $title, bool $uncommon): string
    {
        return match ($random->getInt(0, $uncommon ? 5 : 3)) {
            0 => strtolower($title),
            1 => strtoupper(trim($title)),
            2 => trim($title).' '.self::WORDS[$random->getInt(0, \count(self::WORDS) - 1)],
            3 => substr($title, 0, max(1, intdiv(\strlen($title), 2))),
            4 => '',
            default => " \t ".$title.' ',
        };
    }

    private static function imdb(Randomizer $random, bool $uncommon): string
    {
        $digits = (string) $random->getInt(100000, 99999999);
        if (! $uncommon) {
            return $random->getInt(0, 4) === 0 ? '' : 'tt'.str_pad($digits, 7, '0', STR_PAD_LEFT);
        }

        return match ($random->getInt(0, 7)) {
            0 => 'TT'.$digits."\n",
            1 => 'tt'.$digits."\n\n",
            2 => 'tt'.substr($digits, 0, 5),
            3 => 'https://www.imdb.com/title/tt'.$digits,
            4 => 'tt'.$digits.'/',
            5 => 'xtTt'.$digits,
            6 => '0',
            default => self::bytes($random, $random->getInt(0, 20), "tT0123456789 \n"),
        };
    }

    private static function date(Randomizer $random, bool $uncommon): string
    {
        $date = \sprintf('%04d-%02d-%02d', $random->getInt(1950, 2026), $random->getInt(1, 12), $random->getInt(1, 28));
        if (! $uncommon) {
            return $random->getInt(0, 9) === 0 ? '' : $date;
        }

        return match ($random->getInt(0, 7)) {
            0 => $date.'T20:00:00+00:00',
            1 => \sprintf('%04d-%02d-%02d', $random->getInt(0, 9999), $random->getInt(0, 99), $random->getInt(0, 99)),
            2 => (string) $random->getInt(1900, 2100),
            3 => substr($date, 0, $random->getInt(0, 9)),
            4 => ' '.$date,
            5 => $date."\n",
            6 => self::bytes($random, $random->getInt(0, 14), '0123456789-'),
            default => $date,
        };
    }

    private static function summary(Randomizer $random, bool $uncommon): string
    {
        $text = self::title($random, $uncommon).'. '.self::title($random, $uncommon).'.';
        if (! $uncommon) {
            return $random->getInt(0, 4) === 0 ? $text : '<p>'.$text.'</p>';
        }
        $tags = ['<b>', '</b>', '<i>', '<br />', '<a href="x>y">', '<', '>', '&amp;', '<<', '<p class="c">'];
        $out = '';
        $pieces = $random->getInt(1, 20);
        for ($i = 0; $i < $pieces; $i++) {
            $out .= $tags[$random->getInt(0, \count($tags) - 1)].self::title($random, $random->getInt(0, 3) === 0);
        }

        return $out;
    }

    /** @return list<string> */
    private static function aliases(Randomizer $random, string $title, bool $uncommon): array
    {
        $aliases = [];
        $count = $random->getInt(0, $uncommon ? 8 : 3);
        for ($i = 0; $i < $count; $i++) {
            $aliases[] = $random->getInt(0, 3) === 0 ? self::ours($random, $title, $uncommon) : self::title($random, $uncommon);
        }

        return $aliases;
    }

    private static function packet(string $type, string $body, string $set, ?string $digest = null): string
    {
        return Par2Info::PACKET_MARKER.pack('P', 64 + \strlen($body)).($digest ?? md5($set.$type.$body, true)).$set.$type.$body;
    }

    private static function par2(Randomizer $random, bool $uncommon, string $set): string
    {
        $name = self::title($random, false).'.mkv';
        $name .= str_repeat("\0", (4 - \strlen($name) % 4) % 4);
        $files = $random->getInt(1, 6);
        $mainBody = pack('P', $random->getInt(1, 4096) * 4096).pack('V', $files).self::bytes($random, 16 * $files);
        $common = [
            static fn (): string => self::packet(Par2Info::PACKET_MAIN, $mainBody, $set),
            static fn (): string => self::packet(Par2Info::PACKET_FILEDESC, self::bytes($random, 48).pack('P', $random->getInt(0, PHP_INT_MAX)).$name, $set),
            static fn (): string => self::packet(Par2Info::PACKET_FILEVER, self::bytes($random, 16 + 20 * $random->getInt(0, 8)), $set),
            static fn (): string => self::packet(Par2Info::PACKET_RECOVERY, self::bytes($random, 4 * $random->getInt(1, 64)), $set),
            static fn (): string => self::packet(Par2Info::PACKET_CREATOR, 'ParPar v0.4.5'."\0\0\0", $set),
        ];
        if (! $uncommon) {
            return $common[$random->getInt(0, \count($common) - 1)]();
        }
        $valid = $common[$random->getInt(0, \count($common) - 1)]();

        return match ($random->getInt(0, 11)) {
            0 => substr($valid, 0, $random->getInt(0, 63)),
            1 => 'PAR3'.substr($valid, 4),
            2 => substr($valid, 0, 8).pack('VV', \strlen($valid) + 4, 0).substr($valid, 16),
            3 => substr($valid, 0, 8).pack('VV', \strlen($valid), 0x80000000).substr($valid, 16),
            4 => self::packet(Par2Info::PACKET_MAIN, pack('P', 4096).pack('V', 9).self::bytes($random, 16), $set),
            5 => self::packet(Par2Info::PACKET_FILEVER, self::bytes($random, 20), $set),
            6 => self::packet(Par2Info::PACKET_FILEDESC, self::bytes($random, 52), $set),
            7 => self::packet('PAR 2.0'."\0".'Unknown!', self::bytes($random, 8), $set),
            8 => self::packet(Par2Info::PACKET_RECOVERY, self::bytes($random, 4 * $random->getInt(256, 4096)), $set),
            9 => self::packet(Par2Info::PACKET_COMMENT_ASCII, self::bytes($random, 4 * $random->getInt(0, 64)), $set, str_repeat("\x11", 16)),
            10 => self::bytes($random, $random->getInt(0, 200)),
            default => self::packet(Par2Info::PACKET_MAIN, pack('P', -1).pack('V', 0), $set),
        };
    }

    private static function fingerprint(Randomizer $random, bool $uncommon): string
    {
        $raw = self::bytes($random, 16);
        if (! $uncommon) {
            return $random->getInt(0, 2) === 0 ? $raw : bin2hex($raw);
        }

        return match ($random->getInt(0, 9)) {
            0 => strtoupper(bin2hex($raw)),
            1 => '0x'.bin2hex($raw),
            2 => ' 0X'.strtoupper(bin2hex($raw))."\n",
            3 => "\t".bin2hex($raw)."\0",
            4 => substr(bin2hex($raw), 0, $random->getInt(0, 31)),
            5 => bin2hex($raw).'0',
            6 => substr_replace(bin2hex($raw), 'g', $random->getInt(0, 31), 1),
            7 => self::bytes($random, $random->getInt(0, 64)),
            8 => '0x'.substr(bin2hex($raw), 2),
            default => $raw,
        };
    }

    /**
     * $count records for the helper, with earlier records repeated at random so the
     * deduplicating helpers see duplicates.
     *
     * @return list<mixed>
     */
    private static function batch(string $key, Randomizer $random, int $count, bool $uncommon): array
    {
        $columns = [];
        $sets = [self::bytes($random, 16), self::bytes($random, 16), self::bytes($random, 16)];
        for ($i = 0; $i < $count; $i++) {
            $title = self::title($random, $uncommon);
            $row = match ($key) {
                'tmdb' => [self::imdb($random, $uncommon), self::date($random, $uncommon), $title, self::ours($random, $title, $uncommon)],
                'tvmaze' => [self::summary($random, $uncommon), self::imdb($random, $uncommon),
                    ['US', 'GB', 'JP', 'us', 'USA', '', 'C1'][$random->getInt(0, $uncommon ? 6 : 2)],
                    ['HBO', 'BBC One', '', '  ', 'Netflix'][$random->getInt(0, 4)], $title, self::ours($random, $title, $uncommon)],
                'tvdb' => [self::imdb($random, $uncommon), self::date($random, $uncommon), $title, self::ours($random, $title, $uncommon),
                    self::aliases($random, $title, $uncommon),
                    $uncommon ? $random->getInt(-5, 20000) : $random->getInt(0, 30),
                    $uncommon ? [$random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-3, 1200000)][$random->getInt(0, 1)] : $random->getInt(1, 24)],
                'par2' => [self::par2($random, $uncommon, $sets[$random->getInt(0, 2)])],
                'fingerprint' => [self::fingerprint($random, $uncommon)],
                default => throw new RuntimeException('Unknown helper '.$key),
            };
            if ($i > 0 && $random->getInt(0, 9) === 0) {
                $j = $random->getInt(0, $i - 1);
                $row = array_map(static fn (array $column): mixed => $column[$j], $columns);
            }
            foreach ($row as $c => $value) {
                $columns[$c][] = $value;
            }
        }

        return $count === 0 ? array_fill(0, \count(self::emptyArgs($key)), []) : $columns;
    }

    /** @return list<array<never, never>> */
    private static function emptyArgs(string $key): array
    {
        return array_fill(0, ['tmdb' => 4, 'tvmaze' => 6, 'tvdb' => 7, 'par2' => 1, 'fingerprint' => 1][$key], []);
    }

    /**
     * Hand-picked single records per helper.
     *
     * @return list<list<mixed>>
     */
    private static function edgeRecords(string $key): array
    {
        $set = str_repeat("\x07", 16);
        $main = self::packet(Par2Info::PACKET_MAIN, pack('P', 768000).pack('V', 1).str_repeat("\x01", 16), $set);

        return match ($key) {
            'tmdb' => [
                ['tt0903747', '2008-01-20', 'Breaking Bad', 'breaking bad'],
                ["TT1234567\n", '2008', ' The Office ', 'the office'],
                ["tt1234567\n\n", '2008-1-2', '', ''],
                ['tt12345', '19', 'Café', 'cafe'],
                ['xtt123456', 'abcd-01-01', 'Doctor Who', 'Doctor Who (2005)'],
                ['', '2020-13-99', str_repeat('a', 255), str_repeat('A', 255)],
                ['nm0001', "2020-01-01\n", str_repeat('a', 256), 'a'],
                ['tt'.str_repeat('9', 30), '2020-01-01 ', "\x0b\t ", ' '],
            ],
            'tvmaze' => [
                ['<p>Hi &amp; <b>bye</b></p>', 'tt7654321', 'US', ' HBO ', 'Show', 'show'],
                ['a<b<c>d<e', '', 'usa', '', '', ''],
                [str_repeat('<', 300), 'tt123456', 'U', "\t", str_repeat('<', 300), str_repeat('<', 300)],
                ['no tags', "tt1234567\n", 'GB', 'BBC', 'Ünïcödé', 'ünïcödé'],
                ['<>', 'TT000000', 'us', 'x', ' x ', 'X'],
                ['', '', '', '', '', ''],
            ],
            'tvdb' => [
                ['tt1234567', '2020-02-30T00:00', 'Doctor Who', ' doctor who ', ['Dr Who', ' DOCTOR WHO '], 3, 7],
                ['', '2020-13-01', '', '', [], -1, 0],
                ['tt7654321', '0000-01-01', 'Shogun', 'SHOGUN', ['shogun'], 9999, 999999],
                ['', '2020-01-00', 'x', 'y', ['', 'y'], 10000, 1],
                ['', '2020-01-31', 'Name', 'Alias', ["Ali\x1fas", 'Alias'], 0, 1000000],
                ['', '1999-12-31', 'A', 'b', ['b', 'b'], PHP_INT_MAX, PHP_INT_MIN],
            ],
            'par2' => [
                [$main], [''], [substr($main, 0, 63)], ['PAR2'.substr($main, 4)],
                [self::packet(Par2Info::PACKET_FILEDESC, str_repeat("\xaa", 48).pack('P', -5).'name', $set)],
                [self::packet(Par2Info::PACKET_MAIN, 'x', $set)],
                [self::packet(Par2Info::PACKET_PACKED_MAIN, '', $set)],
                [self::packet(Par2Info::PACKET_INPUT_BLOCK, str_repeat("\0", 1024), $set)],
            ],
            'fingerprint' => [
                [md5('a', true)], [md5('a')], [' 0X'.strtoupper(md5('a'))."\n"], ['zz'], [str_repeat('g', 32)], [''],
                ["\0".str_repeat(' ', 15)], ['0x'.md5('c')], ['0x'.substr(md5('c'), 2)], [str_repeat('f', 32)],
                ["\x00\x0b".md5('d')."\t\n"],
            ],
            default => throw new RuntimeException('Unknown helper '.$key),
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w56_switch_api_and_default(string $class, string $method, int $words, string $key): void
    {
        self::assertGreaterThan(0, $words);
        self::assertNotSame('', $key);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W56_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        foreach (['usesW56RustFfi', 'withW56RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w56RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW56RustFfi());
        self::assertFalse($class::withW56RustFfi(false, static fn (): bool => $class::usesW56RustFfi()));
        self::assertTrue($class::withW56RustFfi(true, static fn (): bool => $class::usesW56RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW56RustFfi(), 'withW56RustFfi restores the default');

        $nested = $class::withW56RustFfi(false, static fn (): array => [
            $class::usesW56RustFfi(),
            $class::withW56RustFfi(true, static fn (): bool => $class::usesW56RustFfi()),
            $class::usesW56RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW56RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW56RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w56', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW56RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0], self::state($class));
        }
        // Each class keeps its own state, including the three providers sharing a parent.
        TvMazeProvider::withW56RustFfi(false, static function (): void {
            self::assertFalse(TvMazeProvider::usesW56RustFfi());
            self::assertNull(self::state(TmdbProvider::class, 'enabled'));
            self::assertNull(self::state(TvdbProvider::class, 'enabled'));
            self::assertNull(self::state(Par2PacketAccumulator::class, 'enabled'));
            self::assertNull(self::state(CompactFingerprintCollector::class, 'enabled'));
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        $expected = [
            TmdbProvider::class => true,
            TvMazeProvider::class => true,
            TvdbProvider::class => false,
            Par2PacketAccumulator::class => true,
            CompactFingerprintCollector::class => false,
        ];
        foreach ($expected as $class => $enabled) {
            self::assertSame($enabled, $class::W56_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW56RustFfi(), $class);
        }
    }

    public function test_earlier_wave_switches_are_independent(): void
    {
        $earlier = static fn (): array => [
            TmdbProvider::usesW444RustFfi(),
            TvMazeProvider::usesW394RustFfi(),
            TvMazeProvider::usesW50RustFfi(),
            TvdbProvider::usesW394RustFfi(),
            TvdbProvider::usesW50RustFfi(),
            Par2PacketAccumulator::usesW442RustFfi(),
        ];
        $before = $earlier();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                self::assertSame($before, $class::withW56RustFfi($enabled, $earlier), $class);
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
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, string $key): void
    {
        self::assertGreaterThan(0, $words);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, self::emptyArgs($key)));
        }
        $args = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $shapes = [];
        foreach (array_keys($args) as $c) {
            if (\count($args) > 1) {
                $short = $args;
                array_pop($short[$c]);
                $shapes[] = $short;
            }
            $keyed = $args;
            $keyed[$c] = array_combine(['a', 'b', 'c'], $keyed[$c]);
            $shapes[] = $keyed;
        }
        foreach ($shapes as $bad) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}(...$bad);
                    self::fail($method.$suffix.' must reject mismatched columns');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W56', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $args = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $args, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeRecords($key);
        $columns = [];
        foreach ($edges as $i => $record) {
            self::assertParity($class, $method, $words, array_map(static fn (mixed $value): array => [$value], $record), $key.' edge '.$i);
            foreach ($record as $c => $value) {
                $columns[$c][] = $value;
            }
        }
        self::assertParity($class, $method, $words, $columns, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
    }

    public function test_hand_checked_rows(): void
    {
        foreach ([false, true] as $native) {
            self::assertSame(
                [13, (2 << 32) | 7, 2008, 12, 2, -1, -1, 0],
                self::dispatch(TmdbProvider::class, 'tmdbShowRowBatch', $native, [['tt0903747', 'tt1'], ['2008-01-20', ''], ['Breaking Bad', ''], [' breaking bad ', '']]),
            );
            self::assertSame(
                [63, (2 << 32) | 7, 12, 4, 4],
                self::dispatch(TvMazeProvider::class, 'tvMazeShowRowBatch', $native, [['<p>Hi &amp; <b>bye</b></p>'], ['tt7654321'], ['US'], [' HBO '], ['Show'], ['show']]),
            );
            self::assertSame(
                [31, (2 << 32) | 7, 20200230, 1, 3000007],
                self::dispatch(TvdbProvider::class, 'tvdbShowRowBatch', $native, [['tt1234567'], ['2020-02-30T00:00'], ['Doctor Who'], [' doctor who '], [['Dr Who', ' DOCTOR WHO ']], [3], [7]]),
            );
            $hex = md5('a');
            [, $hi, $lo] = unpack('J2', md5('a', true));
            self::assertSame(
                [2, $hi, $lo, 0, 1, $hi, $lo, 0, 0, 0, 0, -1],
                self::dispatch(CompactFingerprintCollector::class, 'fingerprintRowBatch', $native, [[$hex, md5('a', true), 'nope']]),
            );
        }
    }

    public function test_nul_and_separator_values_fall_back_to_php(): void
    {
        $tmdb = [["tt1234567\0"], ['2020-01-01'], ["a\0b"], ['a']];
        self::assertSame(TmdbProvider::tmdbShowRowBatchPhp(...$tmdb), TmdbProvider::tmdbShowRowBatchNative(...$tmdb));
        $tvmaze = [["<b>\0</b>"], [''], ['US'], [''], ['x'], ['x']];
        self::assertSame(TvMazeProvider::tvMazeShowRowBatchPhp(...$tvmaze), TvMazeProvider::tvMazeShowRowBatchNative(...$tvmaze));
        $tvdb = [[''], [''], ['Name'], ['Alias'], [["Ali\x1fas", "\x1f", 'Alias']], [1], [1]];
        $php = TvdbProvider::tvdbShowRowBatchPhp(...$tvdb);
        self::assertSame(2, $php[3]);
        self::assertSame($php, TvdbProvider::tvdbShowRowBatchNative(...$tvdb));
    }

    // ---------------------------------------------------------------------
    // Wired production paths
    // ---------------------------------------------------------------------

    public function test_format_show_info_imdb_capture_matches_preg_match(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $values = ['tt0903747', "TT1234567\n", "tt1234567\n\n", 'tt12345', 'xtt123456', '', '0', "tt123456\0", 'https://imdb.com/title/tt7654321'];
        for ($i = 0; $i < 300; $i++) {
            $values[] = self::imdb($random, $i % 2 === 0);
        }
        $methods = [TmdbProvider::class => 'w56TmdbImdbId', TvMazeProvider::class => 'w56TvMazeImdbId', TvdbProvider::class => 'w56TvdbImdbId'];
        foreach ($methods as $class => $name) {
            $method = new ReflectionMethod($class, $name);
            foreach ($values as $value) {
                preg_match('/tt(?P<imdbid>\d{6,})$/i', $value, $imdb);
                $expected = (string) ($imdb['imdbid'] ?? '');
                foreach ([false, true] as $native) {
                    self::assertSame($expected, $class::withW56RustFfi($native, static fn (): mixed => $method->invoke(null, $value)), $class.' '.json_encode($value));
                }
            }
        }
    }

    public function test_summarize_packet_batch_through_w56_rows_matches_php(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $uncommon) {
            foreach ([0, 1, 5, 64, 320] as $size) {
                $packets = $size === 0 ? [] : self::batch('par2', $random, $size, $uncommon)[0];
                $packets[] = 42;
                $packets['key'] = null;
                $expected = Par2PacketAccumulator::summarizePacketBatchPhp($packets);
                foreach ([false, true] as $w56) {
                    Par2PacketAccumulator::useW442RustFfi(false);
                    self::assertSame($expected, Par2PacketAccumulator::withW56RustFfi($w56, static fn (): array => Par2PacketAccumulator::summarizePacketBatch($packets)));
                }
                Par2PacketAccumulator::resetW442RustFfi();
            }
        }
    }

    public function test_fingerprint_sql_parameters_dedupe_in_first_seen_order(): void
    {
        $a = md5('a', true);
        $b = md5('b', true);
        $input = [bin2hex($a), $a, 'bogus', strtoupper(bin2hex($b)), '0x'.bin2hex($a), '', $b];
        foreach ([false, true] as $native) {
            $params = CompactFingerprintCollector::withW56RustFfi($native, static fn (): array => CompactFingerprintCollector::fingerprintSqlParameters($input));
            self::assertSame(['placeholders' => '?,?', 'bindings' => [$a, $b], 'invalid' => 2], $params);
        }
        self::assertSame(['placeholders' => '', 'bindings' => [], 'invalid' => 0], CompactFingerprintCollector::fingerprintSqlParameters([]));
        $random = new Randomizer(new Mt19937(self::SEED));
        $batch = self::batch('fingerprint', $random, 320, true)[0];
        self::assertSame(
            CompactFingerprintCollector::withW56RustFfi(false, static fn (): array => CompactFingerprintCollector::fingerprintSqlParameters($batch)),
            CompactFingerprintCollector::withW56RustFfi(true, static fn (): array => CompactFingerprintCollector::fingerprintSqlParameters($batch)),
        );
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_W564_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W564_BENCH=1 to run the paired benchmark.');
        }
        $rounds = max(15, (int) (getenv('NNTMUX_W564_BENCH_ROUNDS') ?: 1000));
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
                for ($round = -20; $round < $rounds; $round++) {
                    $args = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}(...$args);
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
            if ($class::W56_RUST_FFI_DEFAULT && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2);
            }
        }
        fwrite(STDERR, "\nW56.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
