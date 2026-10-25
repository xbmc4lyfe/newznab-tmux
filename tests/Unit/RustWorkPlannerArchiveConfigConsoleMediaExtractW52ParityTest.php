<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 52.4 batch helpers: AdditionalWorkPlanner archive volume classes, ArchiveExtractionService
 * extracted entry audits, ProcessingConfiguration limit snapshots, ConsoleOutputService line
 * measurements and MediaExtractionService audio tag validation. PHP joins each text column with
 * NUL bytes (adding a 0x01-joined twin buffer when a value holds a NUL byte) or
 * packs integer columns with pack('P*'); Rust writes fixed-width int64 words per record that PHP
 * decodes with one unpack('P*'), so a batch costs one FFI crossing.
 *
 * AdditionalWorkPlanner and ProcessingConfiguration are readonly classes, which cannot declare
 * static properties, so every helper keeps its routing override and cached binding in the
 * function static of w52RustFfiState().
 *
 * The paired benchmark alternates PHP/Rust order at random per sample (fixed seed), times the
 * public dispatch including packing and decoding, and requires every default-enabled helper to
 * clear 1.5x on common and uncommon mean and p99. Pairs per workload: NNTMUX_W52_BENCH_PAIRS
 * (default 2000, minimum 200). It skips without the native library.
 *
 * Three runs at 746d186da + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 2000 pairs per workload after 64 warmups per mode, load average ~7), minimum speedup
 * per gate (common mean/p99, uncommon mean/p99): archiveVolumeBatch 2.23/2.04, 2.13/2.01;
 * extractedEntryBatch 3.48/2.58, 4.22/2.91; normalizeLimitsBatch 2.85/2.66, 3.50/3.83;
 * consoleLineBatch 2.62/1.79, 2.02/1.74; audioTagBatch 2.53/1.91, 2.17/1.80. Common batches
 * hold 64 realistic records; uncommon batches hold 192 mixing long, multibyte, malformed and
 * NUL-holding values with extreme integers. All five default to Rust.
 */
final class RustWorkPlannerArchiveConfigConsoleMediaExtractW52ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, input values per record]. */
    private const array HELPERS = [
        'planner' => [AdditionalWorkPlanner::class, 'archiveVolumeBatch', 4, 1],
        'archive' => [ArchiveExtractionService::class, 'extractedEntryBatch', 2, 1],
        'config' => [ProcessingConfiguration::class, 'normalizeLimitsBatch', 15, ProcessingConfiguration::LIMIT_COUNT],
        'console' => [ConsoleOutputService::class, 'consoleLineBatch', 3, 1],
        'media' => [MediaExtractionService::class, 'audioTagBatch', 4, 1],
    ];

    private const int SEED = 5242026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => $helper) {
            $cases[$name] = $helper;
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w52RustFfiState'))->invoke(null, ...$args);
    }

    private static function ffi(string $class): ?FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W52_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w52', $cdef);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $values): array
    {
        $result = $class::withW52RustFfi($native, static fn (): array => $class::{$method}($values));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $values */
    private static function assertParity(string $class, string $method, int $words, int $per, array $values, string $label): void
    {
        $php = self::dispatch($class, $method, false, $values);
        self::assertSame($php, $class::{$method.'Php'}($values), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $values), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($values), $label.': native method');
        self::assertCount(intdiv(count($values), $per) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w52_switch_api_and_default(string $class, string $method, int $words, int $per): void
    {
        self::assertGreaterThan(0, $words * $per);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W52_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPrivate());
        self::assertIsBool($constant->getValue());
        foreach (['usesW52RustFfi', 'withW52RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w52RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());

        self::assertSame($constant->getValue(), $class::usesW52RustFfi());
        self::assertFalse($class::withW52RustFfi(false, static fn (): bool => $class::usesW52RustFfi()));
        self::assertTrue($class::withW52RustFfi(true, static fn (): bool => $class::usesW52RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW52RustFfi(), 'withW52RustFfi restores the default');

        $nested = $class::withW52RustFfi(false, static fn (): array => [
            $class::usesW52RustFfi(),
            $class::withW52RustFfi(true, static fn (): bool => $class::usesW52RustFfi()),
            $class::usesW52RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW52RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW52RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        self::assertTrue((new ReflectionClass(AdditionalWorkPlanner::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(ProcessingConfiguration::class))->isReadOnly());
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w52', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW52RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0], self::state($class));
        }
        // Each class keeps its own state.
        AdditionalWorkPlanner::withW52RustFfi(false, static function (): void {
            self::assertFalse(AdditionalWorkPlanner::usesW52RustFfi());
            self::assertNull(self::state(ProcessingConfiguration::class, 'enabled'));
            self::assertNull(self::state(MediaExtractionService::class, 'enabled'));
        });
    }

    public function test_earlier_wave_switches_are_independent(): void
    {
        $earlier = static fn (): array => [
            AdditionalWorkPlanner::usesW406RustFfi(),
            ArchiveExtractionService::usesW406RustFfi(),
            MediaExtractionService::usesW406RustFfi(),
            ConsoleOutputService::usesW416RustFfi(),
            ConsoleOutputService::usesRustFfi(),
            ProcessingConfiguration::usesW426RustFfi(),
        ];
        $before = $earlier();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                self::assertSame($before, $class::withW52RustFfi($enabled, $earlier), $class);
            }
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $per): void
    {
        self::assertGreaterThan(0, $words);
        $random = new Randomizer(new Mt19937(self::SEED));
        $values = self::batch($class, $random, 4, false);
        $cases = ['non-list' => array_combine(range(1, count($values)), $values)];
        if ($per > 1) {
            $cases['partial snapshot'] = array_slice($values, 1);
        }
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
            foreach ($cases as $label => $input) {
                foreach ([$method, $method.'Php', $method.'Native'] as $entry) {
                    try {
                        $class::withW52RustFfi($native, static fn (): array => $class::{$entry}($input));
                        self::fail($label.' must be rejected by '.$entry.'.');
                    } catch (InvalidArgumentException) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Hand-calculated contracts
    // ---------------------------------------------------------------------

    private static function span(int $offset, int $length): int
    {
        return ($offset << 32) | $length;
    }

    public function test_archive_volume_hand_cases(): void
    {
        $titles = [
            'Show.S01E01.part01.rar yEnc (1/50)',
            '[1/9] - "Show.S01E01.PART02.RAR" yEnc (1/50)',
            'Movie.2024.rar',
            'Movie.2024.r00 yEnc',
            'Movie.2024.r1234',
            '"name.r17"',
            'a b.part3.rar',
            "x/y.rar\n",
            '"" Movie.mkv',
            'Show.part007.mkv',
            "\"broken\nname.rar\" z.rar",
            'Big.part99999999999999999999999.rar',
            '',
        ];
        $expected = [
            1, 1, self::span(0, 11), 1,
            7, 2, self::span(9, 11), 2,
            5, 0, self::span(0, 10), -1,
            1, 1, self::span(0, 10), -1,
            0, -1, -1, -1,
            7, 18, self::span(1, 4), -1,
            5, 3, self::span(2, 1), 3,
            5, 0, self::span(2, 1), -1,
            4, -1, -1, -1,
            4, -1, -1, 7,
            5, 0, self::span(8, 4), -1,
            5, PHP_INT_MAX, self::span(0, 3), PHP_INT_MAX,
            0, -1, -1, -1,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(AdditionalWorkPlanner::class, 'archiveVolumeBatch', $native, $titles));
        }
    }

    public function test_archive_volume_matches_planner_private_helpers(): void
    {
        $config = (new ReflectionClass(ProcessingConfiguration::class))->newInstanceWithoutConstructor();
        $planner = new AdditionalWorkPlanner($config);
        $rarVolume = new ReflectionMethod($planner, 'rarVolume');
        $hasExtension = new ReflectionMethod($planner, 'hasFileExtension');
        $random = new Randomizer(new Mt19937(self::SEED));
        $titles = [...self::subjects($random, 300, false), ...self::subjects($random, 300, true)];
        $words = AdditionalWorkPlanner::archiveVolumeBatch($titles);
        foreach ($titles as $index => $title) {
            [$flags, $order, $span] = array_slice($words, $index * 4, 3);
            $volume = $rarVolume->invoke($planner, $title);
            self::assertSame($volume !== null, ($flags & AdditionalWorkPlanner::W52_VOLUME_RAR) !== 0, $title);
            if ($volume !== null) {
                self::assertSame($volume, [strtolower(substr($title, $span >> 32, $span & 0xFFFFFFFF)), $order], $title);
            }
            self::assertSame($hasExtension->invoke($planner, $title), ($flags & AdditionalWorkPlanner::W52_VOLUME_EXTENSION) !== 0, $title);
        }
    }

    public function test_extracted_entry_hand_cases_match_safety_summary(): void
    {
        $paths = ['Movie/movie.mkv', '/etc/passwd', 'a\\..\\b.EXE', 'inner.part01.RAR', 'group.nfo', 'x.r05', '', 'dir/', "nul\0.sh", '...', 'a/../', 'x.0001'];
        $expected = [
            0, self::span(6, 9),
            2, self::span(5, 6),
            4 | 16, self::span(5, 5),
            32, self::span(0, 16),
            64, self::span(0, 9),
            32, self::span(0, 5),
            1, 0,
            0, self::span(4, 0),
            8 | 16, self::span(0, 7),
            0, self::span(0, 3),
            4, self::span(5, 0),
            0, self::span(0, 6),
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(ArchiveExtractionService::class, 'extractedEntryBatch', $native, $paths));
        }

        $random = new Randomizer(new Mt19937(self::SEED));
        $paths = [...$paths, ...self::entries($random, 300, false), ...self::entries($random, 300, true)];
        $words = ArchiveExtractionService::extractedEntryBatch($paths);
        foreach ($paths as $index => $path) {
            $flags = $words[$index * 2];
            $summary = ArchiveExtractionService::summarizeExtractedEntriesSafetyPhp([$path]);
            self::assertSame($summary['traversal_rejected'], (int) (($flags & 15) !== 0), $path);
            self::assertSame($summary['executable'], (int) (($flags & ArchiveExtractionService::W52_ENTRY_EXECUTABLE) !== 0), $path);
        }
    }

    public function test_limit_snapshot_hand_cases_match_normalize_limits(): void
    {
        $defaults = [3, 3, 30, 2, 2, 16_777_216, 8_388_608, 3_145_728, 104_857_600, 8_388_608, 30, 30, 4, 1];
        $extreme = [-1, 99, 5, 0, -5, -1, 0, 0, -1, 0, -1, 0, 99, PHP_INT_MIN];
        $expected = [...$defaults, 0, 0, 50, 11, 1, 0, 0, 1_048_576, 262_144, 0, 1_048_576, 0, 1, 10, 1, 0x3FFF];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(ProcessingConfiguration::class, 'normalizeLimitsBatch', $native, [...$defaults, ...$extreme]));
        }

        $random = new Randomizer(new Mt19937(self::SEED));
        $raw = [...self::snapshots($random, 100, false), ...self::snapshots($random, 100, true)];
        $words = ProcessingConfiguration::normalizeLimitsBatch($raw);
        foreach (array_chunk($raw, ProcessingConfiguration::LIMIT_COUNT) as $index => $snapshot) {
            self::assertSame(ProcessingConfiguration::normalizeLimitsPhp($snapshot), array_slice($words, $index * 15, 14));
        }
    }

    public function test_console_line_hand_cases(): void
    {
        $lines = [
            "\e[1;31mError\e[0m: broken\n",
            "\e[ no final \e",
            "progress 10%\r",
            " \t ",
            "Warning\nsecond\n",
            ConsoleOutputService::formatStatusPhp(ConsoleOutputService::STATUS_RELEASE_START, 42, 1_073_741_824),
            "\e[?25l\e[2K\e[",
            '',
        ];
        $expected = [
            2 | 8 | 16, 14, 1,
            2, 9, 0,
            4, 13, 0,
            1, 3, 0,
            8 | 32, 15, 2,
            0, strlen($lines[5]), substr_count($lines[5], "\n"),
            2, 2, 0,
            1, 0, 0,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(ConsoleOutputService::class, 'consoleLineBatch', $native, $lines));
        }
    }

    public function test_audio_tag_hand_cases_match_audio_tag_text(): void
    {
        $tags = ["  Artist Name \n", "caf\u{e9}", "bad\xC3", "tab\tinside", " \x0B ", "\xED\xA0\x80", "\xC0\x80", "\xF4\x90\x80\x80", "\u{1F3B5} Song", "form\x0Cfeed", "del\x7F", '1999-01-01', 'c. 18992021', '205x 1987'];
        $expected = [
            1, self::span(2, 11), 11, -1,
            1, self::span(0, 5), 4, -1,
            4, self::span(0, 4), -1, -1,
            8, self::span(0, 10), 10, -1,
            2, self::span(3, 0), 0, -1,
            4, self::span(0, 3), -1, -1,
            4, self::span(0, 2), -1, -1,
            4, self::span(0, 4), -1, -1,
            1, self::span(0, 9), 6, -1,
            8, self::span(0, 9), 9, -1,
            8, self::span(0, 4), 4, -1,
            1, self::span(0, 10), 10, 1999,
            1, self::span(0, 11), 11, 2021,
            1, self::span(0, 9), 9, 1987,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(MediaExtractionService::class, 'audioTagBatch', $native, $tags));
        }

        $random = new Randomizer(new Mt19937(self::SEED));
        $tags = [...$tags, ...self::tags($random, 300, false), ...self::tags($random, 300, true)];
        $words = MediaExtractionService::audioTagBatch($tags);
        foreach ($tags as $index => $tag) {
            [$flags, $span, $chars, $year] = array_slice($words, $index * 4, 4);
            $text = MediaExtractionService::audioTagTextPhp($tag);
            self::assertSame($text !== null, $flags === MediaExtractionService::W52_TAG_VALID, bin2hex($tag));
            self::assertSame(trim($tag), substr($tag, $span >> 32, $span & 0xFFFFFFFF), bin2hex($tag));
            self::assertSame(mb_check_encoding(trim($tag), 'UTF-8') ? mb_strlen(trim($tag), 'UTF-8') : -1, $chars, bin2hex($tag));
            // The year is the one audioReleaseTitlePhp() appends for this date.
            $title = MediaExtractionService::audioReleaseTitlePhp('A', 'B', $tag, 'flac');
            self::assertSame('A - B'.($year === -1 ? '' : ' ('.$year.')').' FLAC', $title, bin2hex($tag));
        }
    }

    // ---------------------------------------------------------------------
    // Native execution and fallbacks
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_rust_dispatch_executes_natively(string $class, string $method, int $words, int $per): void
    {
        $ffi = self::ffi($class);
        if ($ffi === null) {
            self::markTestSkipped('Native pipeline library with W52 symbols is not built.');
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        $values = self::batch($class, $random, 64, false);
        $expected = $class::{$method.'Php'}($values);
        self::state($class, 'reset');
        self::assertSame($expected, $class::{$method.'Native'}($values));
        self::assertInstanceOf(FFI::class, self::state($class, 'ffi'));
        self::assertCount(64 * $words, $expected);

        // The raw export produces the same words without the PHP wrapper.
        $text = $per === 1 ? implode("\0", $values) : '';
        $ints = $per === 1 ? '' : pack('P*', ...$values);
        $out = $ffi->new('int64_t['.(64 * $words).']');
        $symbol = self::symbol($class);
        self::assertSame(64 * $words, $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 64, $out, 64 * $words));
        self::assertSame($expected, array_values(unpack('P*', FFI::string($out, 64 * $words * 8)) ?: []));
        // A short buffer reports the needed words.
        self::assertSame(-(64 * $words), $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 64, $out, 64 * $words - 1));
    }

    private static function symbol(string $class): string
    {
        $cdef = (new ReflectionClass($class))->getConstant('W52_FFI_CDEF');
        self::assertIsString($cdef);
        self::assertSame(1, preg_match('/\b(nntmux_w524_\w+)\(/', $cdef, $match));

        return $match[1];
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_use_length_encoding_and_missing_library_falls_back(string $class, string $method, int $words, int $per): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $values = self::batch($class, $random, 16, true);
        if ($per === 1) {
            $withNul = $values;
            $withNul[3] = "a\0b";
            $withNul[7] = "\0";
            $withNul[9] = "\0".$withNul[9]."\0";
            self::assertParity($class, $method, $words, $per, $withNul, 'NUL byte');
            $ffi = self::ffi($class);
            if ($ffi !== null) {
                // Values holding NUL bytes travel with a 0x01-joined twin buffer.
                $text = implode("\0", $withNul);
                $twin = implode("\x01", $withNul);
                $out = $ffi->new('int64_t['.(16 * $words).']');
                self::assertSame(16 * $words, $ffi->{self::symbol($class)}($text, strlen($text), $twin, strlen($twin), 16, $out, 16 * $words));
                // Without the twin the extra separators are rejected.
                self::assertSame(-1, $ffi->{self::symbol($class)}($text, strlen($text), '', 0, 16, $out, 16 * $words));
                self::assertSame($class::{$method.'Php'}($withNul), array_values(unpack('P*', FFI::string($out, 16 * $words * 8)) ?: []));
            }
        }

        self::state($class, 'ffi', false);
        self::assertSame($class::{$method.'Php'}($values), $class::{$method.'Native'}($values));
        self::assertFalse(self::state($class, 'ffi'));
    }

    #[DataProvider('helperProvider')]
    public function test_realistic_and_uncommon_dataset_parity(string $class, string $method, int $words, int $per): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 64, false), 'common');
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 192, true), 'uncommon');
        // Batches beyond the cached output buffer grow it.
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 1500, true), 'large');
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 1, true), 'single');
    }

    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method, int $words, int $per): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($class)));
        for ($round = 0; $round < 60; $round++) {
            self::assertParity($class, $method, $words, $per, self::batch($class, $random, $random->getInt(1, 256), $round % 2 === 1), 'round '.$round);
        }
    }

    // ---------------------------------------------------------------------
    // Datasets
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, count($values) - 1)];
    }

    /** Random bytes biased toward the characters each classifier inspects. */
    private static function noise(Randomizer $random, int $max): string
    {
        $alphabet = " \t\n\r\x0B\x0C\"/\\.-+()[];?@~_0123456789aArRpPtTsSeEwWoOnNfF\x1B\x80\x85\xA0\xC3\xA9\xE2\x82\xAC\xED\xF0\x7F";
        $out = '';
        $length = $random->getInt(0, $max);
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** @return list<mixed> */
    private static function batch(string $class, Randomizer $random, int $count, bool $uncommon): array
    {
        return match ($class) {
            AdditionalWorkPlanner::class => self::subjects($random, $count, $uncommon),
            ArchiveExtractionService::class => self::entries($random, $count, $uncommon),
            ProcessingConfiguration::class => self::snapshots($random, $count, $uncommon),
            ConsoleOutputService::class => self::lines($random, $count, $uncommon),
            MediaExtractionService::class => self::tags($random, $count, $uncommon),
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<string> */
    private static function subjects(Randomizer $random, int $count, bool $uncommon): array
    {
        $titles = [];
        for ($i = 0; $i < $count; $i++) {
            $name = (string) self::pick($random, ['Show.Name.S0'.$random->getInt(1, 9).'E'.sprintf('%02d', $random->getInt(1, 24)).'.1080p.WEB.H264-GRP', 'Movie.Title.2024.2160p.UHD.BluRay.x265-TEAM', bin2hex($random->getBytes(16)), 'Some Album - 2019 [FLAC]']);
            $total = $random->getInt(2, 99);
            $volume = (string) self::pick($random, ['.part'.sprintf('%02d', $random->getInt(1, $total)).'.rar', '.rar', '.r'.sprintf('%02d', $random->getInt(0, 99)), '.7z.'.sprintf('%03d', $random->getInt(1, 20)), '.mkv.'.sprintf('%03d', $random->getInt(1, 20)), '.par2', '.vol07+08.par2', '.nfo', '.mkv', '.'.$random->getInt(10, 99)]);
            if ($uncommon) {
                $titles[] = (string) self::pick($random, [
                    '"'.$name."\r\n".$volume.'" yEnc (1/'.$total.')',
                    '"'.$name.$volume.'" "'.$name.'.rar" yEnc',
                    "\t".strtoupper($name.$volume)."\x0B yEnc (1/2)",
                    $name.$volume."\n",
                    $name.$volume."\n\n",
                    'dir/'.$name.$volume.')-x',
                    $name.'.part'.str_repeat('9', $random->getInt(15, 30)).'.rar',
                    $name.'.r'.str_repeat('1', $random->getInt(1, 5)).' yEnc',
                    '"" '.$name.$volume,
                    '""'.$name.'.rar""',
                    "caf\u{e9} ".$name.$volume.' -',
                    str_repeat('x.part1.rar.', $random->getInt(1, 8)).'rar',
                    '"'.str_repeat('a', $random->getInt(1, 300)).'.RaR"',
                    self::noise($random, 90),
                    '',
                ]);
            } else {
                $titles[] = (string) self::pick($random, [
                    '[01/'.$total.'] - "'.$name.$volume.'" yEnc (1/'.$random->getInt(1, 400).')',
                    $name.$volume.' yEnc ('.$random->getInt(1, 50).'/50)',
                    '"'.$name.$volume.'"',
                    'Poster ['.$random->getInt(1, 9).'/9] - "'.$name.$volume.'" - '.$random->getInt(100, 999).' MB yEnc',
                ]);
            }
        }

        return $titles;
    }

    /** @return list<string> */
    private static function entries(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = ['movie.mkv', 'sample.mkv', 'release.nfo', 'file_id.diz', 'eng.srt', 'inner.rar', 'inner.r00', 'cover.jpg', 'setup.exe', 'install.sh', 'archive.7z.001', 'readme.txt'];
        $dirs = ['', 'Release/', 'Release/Sample/', 'Subs/', 'CD1/', 'Proof/'];
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $name = (string) self::pick($random, $names);
            $dir = (string) self::pick($random, $dirs);
            if ($uncommon) {
                $paths[] = (string) self::pick($random, [
                    '/'.$dir.$name,
                    '\\'.strtr($dir, '/', '\\').strtoupper($name),
                    $dir.'../'.$name,
                    $dir.'..\\..\\'.$name,
                    $dir.'....'.$name,
                    $dir.'..',
                    '..',
                    $dir,
                    $dir.'.hidden',
                    $dir.$name.'.',
                    $dir.'x.R'.sprintf('%02d', $random->getInt(0, 99)),
                    $dir.'x.'.sprintf('%0'.$random->getInt(2, 4).'d', $random->getInt(0, 99)),
                    $dir."\u{e9}t\u{e9}.".self::pick($random, ['CPL', 'Jar', 'PS1', 'vbs', 'scr', 'msi', 'com', 'bat', 'cmd', 'js', 'dll']),
                    str_repeat('deep/', $random->getInt(1, 60)).$name,
                    self::noise($random, 80),
                    '',
                ]);
            } else {
                $paths[] = $dir.$name;
            }
        }

        return $paths;
    }

    /** @return list<int> */
    private static function snapshots(Randomizer $random, int $count, bool $uncommon): array
    {
        $defaults = [3, 3, 30, 2, 2, 16_777_216, 8_388_608, 3_145_728, 104_857_600, 8_388_608, 30, 30, 4, 1];
        $raw = [];
        for ($i = 0; $i < $count; $i++) {
            foreach ($defaults as $default) {
                $raw[] = $uncommon
                    ? (int) self::pick($random, [PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1, 10, 11, 50, 51, 200, 201, 262_144, 1_048_576, $random->getInt(-1_000_000, 1_000_000), $default])
                    : ($random->getInt(0, 3) === 0 ? $default : $random->getInt(0, $default * 2 + 1));
            }
        }

        return $raw;
    }

    /** @return list<string> */
    private static function lines(Randomizer $random, int $count, bool $uncommon): array
    {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $random->getInt(1, 9_999_999);
            $status = ConsoleOutputService::formatStatusPhp($random->getInt(0, 6), $id, $random->getInt(0, 1 << 40), (string) self::pick($random, ['par2', 'archive', 'sample']));
            if ($uncommon) {
                $lines[] = (string) self::pick($random, [
                    "\e[".$random->getInt(0, 107).'m'.$status."\e[0m",
                    "\r\e[2K".'Progress '.$random->getInt(0, 100).'%',
                    "\e[?25l\e[1;".$random->getInt(1, 80).'H'.$status,
                    "\e[ \e[;;\e[".str_repeat('9', $random->getInt(1, 40)),
                    'ERROR: '.$status.PHP_EOL,
                    'wArNiNg: release '.$id."\n\n",
                    "caf\u{e9} \u{2014} ".$status,
                    str_repeat($status, $random->getInt(2, 20)),
                    " \t\x0B\0 ",
                    self::noise($random, 120),
                    '',
                ]);
            } else {
                $lines[] = (string) self::pick($random, [
                    $status,
                    "\e[32m".$status."\e[0m",
                    'Release '.$id.' processed'.PHP_EOL,
                    "\e[33mWarning\e[0m: ".$status,
                ]);
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function tags(Randomizer $random, int $count, bool $uncommon): array
    {
        $artists = ['The Band', 'Artist Name', "Bj\u{f6}rk", 'Various Artists', "Sigur R\u{f3}s", 'AC/DC'];
        $albums = ['Greatest Hits', 'Live at the Venue (Remastered)', "Caf\u{e9} del Mar", 'Vol. 2', '1999'];
        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $date = sprintf('%04d-%02d-%02d', $random->getInt(1950, 2026), $random->getInt(1, 12), $random->getInt(1, 28));
            $tag = (string) self::pick($random, [...$artists, ...$albums, (string) $random->getInt(1950, 2026), $date, 'UTC '.$date.' 12:00:00']);
            if ($uncommon) {
                $tags[] = (string) self::pick($random, [
                    " \t".$tag."\r\n",
                    $tag."\x01",
                    $tag."\t".$tag,
                    "\x0C".$tag,
                    $tag."\xC3",
                    "\xC0\xAF".$tag,
                    $tag."\xED\xA0\x80",
                    "\xF4\x90\x80\x80".$tag,
                    "\u{1F3B5}".str_repeat($tag, $random->getInt(1, 40)),
                    " \x0B\0 ",
                    self::noise($random, 60),
                    '',
                ]);
            } else {
                $tags[] = $random->getInt(0, 4) === 0 ? ' '.$tag.' ' : $tag;
            }
        }

        return $tags;
    }

    // ---------------------------------------------------------------------
    // Benchmark
    // ---------------------------------------------------------------------

    /**
     * @param  list<int>  $samples
     * @return array{mean: float, p99: int}
     */
    private static function statistics(array $samples): array
    {
        sort($samples);

        return ['mean' => array_sum($samples) / count($samples), 'p99' => $samples[(int) floor(0.99 * (count($samples) - 1))]];
    }

    public function test_paired_randomized_benchmark_matches_defaults(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (self::ffi($class) === null) {
                self::markTestSkipped('Native pipeline library with W52 symbols is not built.');
            }
        }
        $pairs = max(200, (int) (getenv('NNTMUX_W52_BENCH_PAIRS') ?: 2000));
        $random = new Randomizer(new Mt19937(self::SEED));
        // Cycle collection would land on whichever sample crosses the root threshold.
        gc_collect_cycles();
        gc_disable();
        try {
            $this->runBenchmark($pairs, $random);
        } finally {
            gc_enable();
        }
        fwrite(STDERR, "\n");
    }

    private function runBenchmark(int $pairs, Randomizer $random): void
    {
        foreach (self::HELPERS as $name => [$class, $method]) {
            $ratios = [];
            foreach (['common' => [false, 64], 'uncommon' => [true, 192]] as $workload => [$uncommon, $count]) {
                $values = self::batch($class, $random, $count, $uncommon);
                $expected = $class::{$method.'Php'}($values);
                $timings = ['php' => [], 'rust' => []];
                foreach ([false, true] as $native) {
                    $class::withW52RustFfi($native, static function () use ($class, $method, $values, $expected): void {
                        for ($i = 0; $i < 64; $i++) {
                            self::assertSame($expected, $class::{$method}($values));
                        }
                    });
                }
                for ($sample = 0; $sample < $pairs; $sample++) {
                    foreach ($random->getInt(0, 1) === 1 ? [false, true] : [true, false] as $native) {
                        $elapsed = $class::withW52RustFfi($native, static function () use ($class, $method, $values, $expected): int {
                            $start = hrtime(true);
                            $actual = $class::{$method}($values);
                            $elapsed = hrtime(true) - $start;
                            self::assertSame($expected, $actual);

                            return $elapsed;
                        });
                        $timings[$native ? 'rust' : 'php'][] = $elapsed;
                    }
                }
                $php = self::statistics($timings['php']);
                $rust = self::statistics($timings['rust']);
                $ratios[$workload.'_mean'] = round($php['mean'] / max(1.0, $rust['mean']), 2);
                $ratios[$workload.'_p99'] = round($php['p99'] / max(1, $rust['p99']), 2);
                $ratios[$workload.'_us'] = [round($php['mean'] / 1000, 2), round($rust['mean'] / 1000, 2)];
            }
            $qualified = min($ratios['common_mean'], $ratios['common_p99'], $ratios['uncommon_mean'], $ratios['uncommon_p99']) >= 1.5;
            fwrite(STDERR, sprintf("\nW52.4 %s %s", $method, json_encode($ratios + ['qualified' => $qualified], JSON_THROW_ON_ERROR)));
            self::assertSame($qualified, $class::usesW52RustFfi(), $name.' '.$method.' default must match the 1.5x mean/p99 gates: '.json_encode($ratios, JSON_THROW_ON_ERROR));
        }
    }
}
