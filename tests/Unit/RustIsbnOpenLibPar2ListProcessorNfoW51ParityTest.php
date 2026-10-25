<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IsbnDbService;
use App\Services\NfoProcessor;
use App\Services\OpenLibraryService;
use App\Services\Par2FileList;
use App\Services\Par2Processor;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Wave 51.4 batch helpers: IsbnDbService token classes, OpenLibraryService edition signals,
 * Par2FileList file name classes, Par2Processor rename candidate classes and NfoProcessor
 * candidate classes. PHP joins each text column with NUL bytes and packs integer scalars with
 * pack('P*'); Rust writes fixed-width int64 words per record that PHP decodes with one
 * unpack('P*'), so a batch costs one FFI crossing.
 *
 * The paired benchmark alternates PHP/Rust order at random per sample (fixed seed), times the
 * public dispatch including packing and decoding, and requires every default-enabled helper to
 * clear 1.5x on common and uncommon mean and p99. Pairs per workload: NNTMUX_W51_BENCH_PAIRS
 * (default 2000, minimum 200). It skips without the native library.
 *
 * Three runs at c1571d003 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 2000 pairs per workload after 64 warmups per mode, load average ~8), minimum
 * speedup per gate (common mean/p99, uncommon mean/p99): isbnTokenBatch 10.57/9.73,
 * 8.08/6.30; editionBatch 2.10/1.94, 2.29/1.87; fileNameBatch 3.30/2.99, 3.60/3.32;
 * renameCandidateBatch 3.61/2.95, 3.44/3.09; nfoCandidateBatch 2.64/1.90, 2.52/2.33. Common
 * batches hold 64 realistic records; uncommon batches hold 192 mixing long, multibyte,
 * whitespace-heavy and malformed values with extreme integers. All five default to Rust.
 */
final class RustIsbnOpenLibPar2ListProcessorNfoW51ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record]. */
    private const array HELPERS = [
        'isbndb' => [IsbnDbService::class, 'isbnTokenBatch', 3],
        'openlibrary' => [OpenLibraryService::class, 'editionBatch', 2],
        'par2filelist' => [Par2FileList::class, 'fileNameBatch', 2],
        'par2processor' => [Par2Processor::class, 'renameCandidateBatch', 2],
        'nfo' => [NfoProcessor::class, 'nfoCandidateBatch', 2],
    ];

    private const int SEED = 5142026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            (new ReflectionProperty($class, 'useW51RustFfi'))->setValue(null, null);
            (new ReflectionProperty($class, 'w51Ffi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => $helper) {
            $cases[$name] = $helper;
        }

        return $cases;
    }

    private static function ffi(string $class): ?FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W51_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w51', $cdef);
    }

    /**
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $args): array
    {
        $result = $class::withW51RustFfi($native, static fn (): array => $class::{$method}(...$args));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $args */
    private static function rows(array $args): int
    {
        $last = $args[array_key_last($args)];
        self::assertIsArray($last);

        return count($last);
    }

    /** @param list<mixed> $args */
    private static function assertParity(string $class, string $method, int $words, array $args, string $label): void
    {
        $php = self::dispatch($class, $method, false, $args);
        self::assertSame($php, $class::{$method.'Php'}(...$args), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $args), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}(...$args), $label.': native method');
        self::assertCount(self::rows($args) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    /**
     * @param  list<int>  $words
     * @return list<list<int>>
     */
    private static function records(array $words, int $width): array
    {
        return array_chunk($words, $width);
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w51_switch_api_and_default(string $class, string $method, int $words): void
    {
        self::assertGreaterThan(0, $words);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W51_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPrivate());
        self::assertIsBool($constant->getValue());
        self::assertTrue($reflection->getProperty('useW51RustFfi')->isPrivate());
        self::assertTrue($reflection->getProperty('useW51RustFfi')->isStatic());
        foreach (['usesW51RustFfi', 'withW51RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }

        self::assertSame($constant->getValue(), $class::usesW51RustFfi());
        self::assertFalse($class::withW51RustFfi(false, static fn (): bool => $class::usesW51RustFfi()));
        self::assertTrue($class::withW51RustFfi(true, static fn (): bool => $class::usesW51RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW51RustFfi(), 'withW51RustFfi restores the default');

        $nested = $class::withW51RustFfi(false, static fn (): array => [
            $class::usesW51RustFfi(),
            $class::withW51RustFfi(true, static fn (): bool => $class::usesW51RustFfi()),
            $class::usesW51RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW51RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW51RustFfi(), 'restored after an exception');
    }

    public function test_earlier_wave_switches_are_independent(): void
    {
        $earlier = static fn (): array => [
            IsbnDbService::usesW394RustFfi(),
            OpenLibraryService::usesW394RustFfi(),
            Par2FileList::usesW424RustFfi(),
            Par2Processor::usesW424RustFfi(),
            NfoProcessor::usesW424RustFfi(),
        ];
        $before = $earlier();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                self::assertSame($before, $class::withW51RustFfi($enabled, $earlier), $class);
            }
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words): void
    {
        self::assertGreaterThan(0, $words);
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, self::batch($class, $random, 0, false)));
            foreach (self::malformed($class, self::batch($class, $random, 4, false)) as $label => $args) {
                try {
                    self::dispatch($class, $method, $native, $args);
                    self::fail($label.' must be rejected.');
                } catch (InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, list<mixed>>
     */
    private static function malformed(string $class, array $args): array
    {
        $cases = [];
        $columns = array_keys(array_filter($args, 'is_array'));
        foreach ($columns as $index) {
            $short = $args;
            $short[$index] = array_slice($short[$index], 1);
            $cases['short column '.$index] = $short;
            $holes = $args;
            $holes[$index] = array_combine(range(1, count($holes[$index])), $holes[$index]);
            $cases['non-list column '.$index] = $holes;
        }
        if ($class !== OpenLibraryService::class) {
            unset($cases['short column 0']);
        }

        return $cases;
    }

    // ---------------------------------------------------------------------
    // Hand-calculated contracts
    // ---------------------------------------------------------------------

    public function test_isbn_token_hand_cases_match_batch_candidates(): void
    {
        $tokens = ['978-0-306-40615-7', '0-306-40615-2', '9780306406157', '080442957x', '080442957X', '9780306406158', '97803064061570', '979 10 90636 07 1', "\f9780306406157\v", ''];
        $expected = [
            13, 9780306406157, -1,
            10, 9780306406157, 0,
            13, 9780306406157, 0,
            10, 9780804429573, -1,
            10, 9780804429573, 3,
            0, 0, -1,
            0, 0, -1,
            13, 9791090636071, -1,
            13, 9780306406157, 0,
            0, 0, -1,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(IsbnDbService::class, 'isbnTokenBatch', $native, [$tokens]));
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $uncommon) {
            [$batch] = self::batch(IsbnDbService::class, $random, 128, $uncommon);
            self::assertIsArray($batch);
            $records = self::records(IsbnDbService::isbnTokenBatchPhp($batch), 3);
            $summary = IsbnDbService::normalizeIsbnBatchCandidatesPhp($batch);
            $kinds = array_count_values(array_column($records, 0));
            $first = '';
            foreach ($records as $record) {
                if ($record[0] !== 0) {
                    $first = (string) $record[1];
                    break;
                }
            }
            self::assertSame([
                'valid_isbn13_count' => $kinds[13] ?? 0,
                'valid_isbn10_count' => $kinds[10] ?? 0,
                'invalid_count' => $kinds[0] ?? 0,
                'deduplicated_count' => count(array_filter($records, static fn (array $r): bool => $r[0] !== 0 && $r[2] === -1)),
                'first_canonical_isbn13' => $first,
            ], $summary);
        }
    }

    public function test_openlibrary_edition_hand_cases_match_work_summary(): void
    {
        $dates = ['March 2004', '12345 1999', 'c1987, 2001', '', '1987', '0000'];
        $isbns = ['9780306406157', " \t", '', 'x', '', '1'];
        $covers = [5, 0, -1, PHP_INT_MAX, 7, 0];
        $expected = [2004, 1 | 2, 1999, 0, 1987, 4 | 8, 0, 1 | 2, 1987, 2 | 4, 0, 1];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(OpenLibraryService::class, 'editionBatch', $native, [1987, $covers, $dates, $isbns]));
            $noPreference = self::dispatch(OpenLibraryService::class, 'editionBatch', $native, [0, $covers, $dates, $isbns]);
            self::assertSame([2004, 1 | 2, 1999, 0, 1987, 8, 0, 1 | 2, 1987, 2, 0, 1], $noPreference);
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $uncommon) {
            [$preferred, $coverIds, $publishDates, $isbn13s] = self::batch(OpenLibraryService::class, $random, 128, $uncommon);
            self::assertIsInt($preferred);
            self::assertIsArray($coverIds);
            self::assertIsArray($publishDates);
            self::assertIsArray($isbn13s);
            $records = self::records(OpenLibraryService::editionBatchPhp($preferred, $coverIds, $publishDates, $isbn13s), 2);
            $editions = array_map(static fn (int $cover, string $date, string $isbn): array => ['isbn_13' => [$isbn], 'covers' => [$cover], 'publish_date' => $date], $coverIds, $publishDates, $isbn13s);
            $earliest = array_values(array_filter($records, static fn (array $r): bool => ($r[1] & 8) !== 0));
            self::assertSame([
                'total_editions' => 128,
                'with_isbn13_count' => count(array_filter($records, static fn (array $r): bool => ($r[1] & 1) !== 0)),
                'with_cover_count' => count(array_filter($records, static fn (array $r): bool => ($r[1] & 2) !== 0)),
                'exact_year_match_count' => count(array_filter($records, static fn (array $r): bool => ($r[1] & 4) !== 0)),
                'earliest_publication_year' => $earliest === [] ? 0 : $earliest[0][0],
            ], OpenLibraryService::summarizeOpenLibraryWorkEditionsPhp($editions, $preferred));
        }
    }

    public function test_par2_file_name_hand_cases_match_w424_summary(): void
    {
        $names = [' Show.S01E01.mkv ', 'show.R01', 'archive.7Z', 'movie.sample.mp4', 'dir/file.PAR2', 'x.123', "\t", 'samples/a.rar', 'noext'];
        $span = static fn (int $offset, int $length): int => ($offset << 32) | $length;
        $expected = [
            16, $span(13, 3),
            4, $span(5, 3),
            8, $span(8, 2),
            16 | 32, $span(13, 3),
            2 | 64, $span(9, 4),
            8, $span(2, 3),
            1, -1,
            4 | 64, $span(10, 3),
            0, -1,
        ];
        foreach ([false, true] as $native) {
            $words = self::dispatch(Par2FileList::class, 'fileNameBatch', $native, [$names]);
            self::assertSame($expected, $words);
            foreach (self::records($words, 2) as $i => [, $extension]) {
                if ($extension !== -1) {
                    self::assertSame(strtolower(pathinfo(trim($names[$i]), PATHINFO_EXTENSION)), strtolower(substr($names[$i], $extension >> 32, $extension & 0xFFFFFFFF)));
                }
            }
        }
        $keys = ['empty_name_count' => 1, 'par2_count' => 2, 'rar_volume_count' => 4, 'split_archive_count' => 8, 'video_count' => 16, 'sample_count' => 32, 'path_count' => 64];
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $uncommon) {
            [$batch] = self::batch(Par2FileList::class, $random, 128, $uncommon);
            self::assertIsArray($batch);
            $summary = ['total_files' => 128];
            foreach ($keys as $key => $flag) {
                $summary[$key] = count(array_filter(self::records(Par2FileList::fileNameBatchPhp($batch), 2), static fn (array $r): bool => ($r[0] & $flag) !== 0));
            }
            self::assertSame(Par2FileList::summarizePar2FileNameBatchPhp($batch), $summary);
        }
    }

    public function test_par2_rename_candidate_hand_cases_match_w424_summary(): void
    {
        $names = ['d41d8cd98f00b204e9800998ecf8427e.mkv', ' dir\\Abcdefgh12345678 ', 'Some.Release.Name-GRP.rar', 'x.vol00+01.PAR2', 'setup.EXE', 'x.vol+01.par2', '  ', 'a/b/.hidden'];
        $span = static fn (int $offset, int $length): int => ($offset << 32) | $length;
        $expected = [
            2 | 4, $span(0, 32),
            4, $span(5, 16),
            8, $span(0, 21),
            16, $span(0, 10),
            32, $span(0, 5),
            0, $span(0, 8),
            1, $span(2, 0),
            0, $span(4, 0),
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(Par2Processor::class, 'renameCandidateBatch', $native, [$names]));
        }
        $keys = ['empty_name_count' => 1, 'hex_hash_name_count' => 2, 'obfuscated_name_count' => 4, 'release_like_count' => 8, 'par2_volume_count' => 16, 'executable_count' => 32];
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $uncommon) {
            [$batch] = self::batch(Par2Processor::class, $random, 128, $uncommon);
            self::assertIsArray($batch);
            $summary = ['total_files' => 128];
            foreach ($keys as $key => $flag) {
                $summary[$key] = count(array_filter(self::records(Par2Processor::renameCandidateBatchPhp($batch), 2), static fn (array $r): bool => ($r[0] & $flag) !== 0));
            }
            self::assertSame(Par2Processor::summarizePar2RenameCandidateBatchPhp($batch), $summary);
        }
    }

    public function test_nfo_candidate_hand_cases_match_w424_summary(): void
    {
        $names = [' Release.NFO ', 'release.nfo', 'file_id.diz', 'proof/readme.txt', '', 'Sample-Info.nfo2', 'RELEASE.NFO'];
        $expected = [2, -1, 2, 0, 4, -1, 8 | 16 | 32, -1, 1, -1, 16, -1, 2, 0];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(NfoProcessor::class, 'nfoCandidateBatch', $native, [$names]));
        }
        $keys = ['empty_name_count' => 1, 'nfo_count' => 2, 'diz_count' => 4, 'txt_count' => 8, 'sample_proof_count' => 16, 'nested_path_count' => 32];
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $uncommon) {
            [$batch] = self::batch(NfoProcessor::class, $random, 128, $uncommon);
            self::assertIsArray($batch);
            $summary = ['total_files' => 128];
            foreach ($keys as $key => $flag) {
                $summary[$key] = count(array_filter(self::records(NfoProcessor::nfoCandidateBatchPhp($batch), 2), static fn (array $r): bool => ($r[0] & $flag) !== 0));
            }
            self::assertSame(NfoProcessor::summarizeNfoCandidateBatchPhp($batch), $summary);
        }
    }

    // ---------------------------------------------------------------------
    // Native execution and fallbacks
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_rust_dispatch_executes_natively(string $class, string $method, int $words): void
    {
        if (self::ffi($class) === null) {
            self::markTestSkipped('Native pipeline library with W51 symbols is not built.');
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 64, false);
        $expected = $class::{$method.'Php'}(...$args);
        // A library that cannot be resolved must not be what produced the native result.
        $property = new ReflectionProperty($class, 'w51Ffi');
        $property->setValue(null, null);
        self::assertSame($expected, $class::{$method.'Native'}(...$args));
        self::assertInstanceOf(FFI::class, $property->getValue());
        self::assertCount(64 * $words, $expected);
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_and_missing_library_fall_back_to_php(string $class, string $method, int $words): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 16, true);
        $textColumn = array_key_last($args);
        $withNul = $args;
        self::assertIsArray($withNul[$textColumn]);
        $withNul[$textColumn][3] = "a\0b";
        self::assertParity($class, $method, $words, $withNul, 'NUL byte');

        $property = new ReflectionProperty($class, 'w51Ffi');
        $property->setValue(null, false);
        self::assertSame($class::{$method.'Php'}(...$args), $class::{$method.'Native'}(...$args));
        $property->setValue(null, null);
    }

    #[DataProvider('helperProvider')]
    public function test_realistic_and_uncommon_dataset_parity(string $class, string $method, int $words): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        self::assertParity($class, $method, $words, self::batch($class, $random, 64, false), 'common');
        self::assertParity($class, $method, $words, self::batch($class, $random, 192, true), 'uncommon');
        // Batches beyond the cached output buffer grow it.
        self::assertParity($class, $method, $words, self::batch($class, $random, 1500, true), 'large');
        self::assertParity($class, $method, $words, self::batch($class, $random, 1, true), 'single');
    }

    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method, int $words): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($class)));
        for ($round = 0; $round < 60; $round++) {
            self::assertParity($class, $method, $words, self::batch($class, $random, $random->getInt(1, 256), $round % 2 === 1), 'round '.$round);
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
        $alphabet = " \t\n\r\x0B\x0C.-+/\\_0123456789aAfFxXrRvVoOlLpPsSeE\x80\xC3\xA9\xE2\x82\xAC\x7F";
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
            IsbnDbService::class => [self::isbnTokens($random, $count, $uncommon)],
            OpenLibraryService::class => self::editionBatch($random, $count, $uncommon),
            Par2FileList::class => [self::fileNames($random, $count, $uncommon)],
            Par2Processor::class => [self::renameCandidates($random, $count, $uncommon)],
            NfoProcessor::class => [self::nfoNames($random, $count, $uncommon)],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function isbn13(Randomizer $random, string $prefix = '978'): string
    {
        $body = $prefix.sprintf('%09d', $random->getInt(0, 999_999_999));
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (ord($body[$i]) - 48) * ($i % 2 === 0 ? 1 : 3);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    private static function isbn10(Randomizer $random): string
    {
        $body = sprintf('%09d', $random->getInt(0, 999_999_999));
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (ord($body[$i]) - 48) * (10 - $i);
        }
        $check = (11 - $sum % 11) % 11;

        return $body.($check === 10 ? 'X' : (string) $check);
    }

    /** @return list<string> */
    private static function isbnTokens(Randomizer $random, int $count, bool $uncommon): array
    {
        $pool = [];
        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            if ($pool !== [] && $random->getInt(0, 5) === 0) {
                $tokens[] = self::pick($random, $pool);

                continue;
            }
            if ($uncommon) {
                $isbn = self::isbn13($random, (string) self::pick($random, ['978', '979']));
                $token = self::pick($random, [
                    substr($isbn, 0, 3).'-'.substr($isbn, 3, 1).'-'.substr($isbn, 4, 4).'-'.substr($isbn, 8, 4).'-'.substr($isbn, 12),
                    " \t".strtolower(self::isbn10($random))."\r\n",
                    "\x0B".implode(' ', str_split(self::isbn10($random), 3))."\x0C",
                    substr($isbn, 0, 12).(string) ((((int) $isbn[12]) + 1) % 10),
                    '977'.substr($isbn, 3),
                    $isbn.'0',
                    'X'.substr(self::isbn10($random), 1),
                    str_repeat('-', $random->getInt(0, 40)).$isbn,
                    "978\u{2010}".substr($isbn, 3),
                    self::noise($random, 30),
                    '',
                ]);
            } else {
                $token = $random->getInt(0, 3) === 0 ? self::isbn10($random) : self::isbn13($random);
                if ($random->getInt(0, 4) === 0) {
                    $token = substr($token, 0, 3).'-'.substr($token, 3);
                }
            }
            $pool[] = $token;
            $tokens[] = $token;
        }

        return $tokens;
    }

    /** @return list<mixed> */
    private static function editionBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $covers = [];
        $dates = [];
        $isbns = [];
        $months = ['January', 'March', 'June', 'Sep', 'December'];
        for ($i = 0; $i < $count; $i++) {
            $year = $random->getInt(1900, 2026);
            if ($uncommon) {
                $covers[] = (int) self::pick($random, [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX, $random->getInt(1, 15_000_000)]);
                $dates[] = (string) self::pick($random, [
                    'c'.$year.', '.($year + 3),
                    '12345 '.$year,
                    $year.'0101',
                    "\u{0661}\u{0669}\u{0668}\u{0667} ".$year,
                    '0000',
                    str_repeat('9', $random->getInt(1, 8)),
                    self::pick($random, $months).' '.$random->getInt(1, 28).', '.$year,
                    self::noise($random, 40),
                    '',
                ]);
                $isbns[] = (string) self::pick($random, ['', " \t\x0B", self::isbn13($random), ' '.self::isbn13($random).' ', self::noise($random, 20)]);
            } else {
                $covers[] = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 15_000_000);
                $dates[] = (string) self::pick($random, [(string) $year, self::pick($random, $months).' '.$year, self::pick($random, $months).' '.$random->getInt(1, 28).', '.$year, '']);
                $isbns[] = $random->getInt(0, 4) === 0 ? '' : self::isbn13($random);
            }
        }
        $preferred = $uncommon ? (int) self::pick($random, [0, -1, 1987, 2001, PHP_INT_MAX]) : $random->getInt(1990, 2020);

        return [$preferred, $covers, $dates, $isbns];
    }

    /** @return list<string> */
    private static function fileNames(Randomizer $random, int $count, bool $uncommon): array
    {
        $stems = ['Show.Name.S01E0'.$random->getInt(1, 9).'.1080p.WEB.H264-GRP', 'Movie.2024.2160p.UHD.BluRay.x265-TEAM', 'Some.Album-(2019)-FLAC', 'a1b2c3d4e5f6'];
        $extensions = ['mkv', 'par2', 'vol00+01.par2', 'rar', 'r00', 'r17', 'nfo', 'sfv', '001', '7z', 'zip', 'mp4', 'nzb'];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $stem = (string) self::pick($random, $stems);
            if ($uncommon) {
                $names[] = (string) self::pick($random, [
                    "  \t".strtoupper($stem).'.'.strtoupper((string) self::pick($random, $extensions))." \r\n",
                    'Sample/'.$stem.'-sample.mkv',
                    $stem.'.SAMPLES.avi',
                    'dir\\sub/'.$stem.'.M2TS',
                    $stem.'.r1',
                    $stem.'.r123',
                    $stem.'.0001',
                    $stem.'.',
                    '.hidden',
                    "caf\u{e9}.mkv",
                    str_repeat($stem.'.', $random->getInt(1, 12)).'ts',
                    self::noise($random, 80),
                    '',
                ]);
            } else {
                $names[] = $stem.'.'.self::pick($random, $extensions);
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function renameCandidates(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $hex = bin2hex($random->getBytes(16));
            $release = 'Some.Release.Name.'.$random->getInt(2000, 2026).'.1080p-GRP';
            if ($uncommon) {
                $names[] = (string) self::pick($random, [
                    ' '.strtoupper(bin2hex($random->getBytes(20))).'.EXE ',
                    'folder\\'.$hex.'.mkv',
                    'a/b\\c/'.$release.'.vol'.$random->getInt(0, 999).'+'.$random->getInt(1, 999).'.PAR2',
                    $release.'.vol+1.par2',
                    $release.'.vol1+.par2',
                    $release.'.Vol01+02.par2.bak',
                    substr($hex, 0, 31).'g.rar',
                    str_repeat('Ab3', $random->getInt(5, 60)).'.ps1',
                    'setup.'.self::pick($random, ['exe', 'BAT', 'cmd', 'scr', 'com', 'vbs', 'js', 'PS1', 'msi', 'lnk', 'dll']),
                    "\u{e9}t\u{e9}.".$release.'.mkv',
                    'dir/',
                    self::noise($random, 70),
                    "\t\n",
                ]);
            } else {
                $names[] = (string) self::pick($random, [$hex.'.mkv', $release.'.mkv', $release.'.vol0'.$random->getInt(0, 9).'+0'.$random->getInt(1, 9).'.par2', 'a8Bc9dEf0gH1iJ2k.rar', $release.'.nfo']);
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function nfoNames(Randomizer $random, int $count, bool $uncommon): array
    {
        $pool = ['Release.Name.2024.1080p-GRP.nfo', 'file_id.diz', 'readme.txt', 'Release.Name.2024.1080p-GRP.mkv', 'Proof/proof.jpg', 'Sample/sample.mkv', 'info.nfo'];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $name = (string) self::pick($random, $pool);
            if ($uncommon) {
                $name = (string) self::pick($random, [
                    '  '.strtoupper($name)." \t",
                    'nested\\dir/'.$name,
                    'SampleProof.nfo',
                    'x-PROOF-y.txt',
                    $name.'.nfo2',
                    str_repeat('a', $random->getInt(10, 300)).'.NFO',
                    "r\u{e9}sum\u{e9}.diz",
                    self::noise($random, 60),
                    '',
                ]);
            } elseif ($random->getInt(0, 3) === 0) {
                $name = strtolower($name);
            }
            $names[] = $name;
        }

        return $names;
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
                self::markTestSkipped('Native pipeline library with W51 symbols is not built.');
            }
        }
        $pairs = max(200, (int) (getenv('NNTMUX_W51_BENCH_PAIRS') ?: 2000));
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
                $args = self::batch($class, $random, $count, $uncommon);
                $expected = $class::{$method.'Php'}(...$args);
                $timings = ['php' => [], 'rust' => []];
                foreach ([false, true] as $native) {
                    $class::withW51RustFfi($native, static function () use ($class, $method, $args, $expected): void {
                        for ($i = 0; $i < 64; $i++) {
                            self::assertSame($expected, $class::{$method}(...$args));
                        }
                    });
                }
                for ($sample = 0; $sample < $pairs; $sample++) {
                    foreach ($random->getInt(0, 1) === 1 ? [false, true] : [true, false] as $native) {
                        $elapsed = $class::withW51RustFfi($native, static function () use ($class, $method, $args, $expected): int {
                            $start = hrtime(true);
                            $actual = $class::{$method}(...$args);
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
            fwrite(STDERR, sprintf("\nW51.4 %s %s", $method, json_encode($ratios + ['qualified' => $qualified], JSON_THROW_ON_ERROR)));
            self::assertSame($qualified, $class::usesW51RustFfi(), $name.' '.$method.' default must match the 1.5x mean/p99 gates: '.json_encode($ratios, JSON_THROW_ON_ERROR));
        }
    }
}
