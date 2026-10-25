<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\Pipes\XxxPipe;
use App\Services\IGDB\Client;
use App\Services\IGDB\Models\Company;
use App\Services\IGDB\Models\Game;
use App\Services\IGDB\Models\Platform;
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
 * Wave 54.4 batch helpers: XxxPipe adult signal rows, IGDB Client request rows and the IGDB
 * Company, Game and Platform record rows. PHP joins every text column with NUL bytes (column
 * after column) and packs int columns with one pack('P*') (column after column); Rust writes
 * fixed-width int64 words per record that PHP decodes with one unpack('P*'), so a batch costs
 * one FFI crossing. A value holding a NUL byte adds a field, which the export rejects, and the
 * batch falls back to PHP.
 *
 * Every helper keeps its routing override, cached binding and output buffer in the function
 * static of w54RustFfiState().
 *
 * The paired benchmark alternates PHP/Rust order at random per sample (fixed seed), times the
 * public dispatch including packing and decoding, and requires every default-enabled helper to
 * clear 1.5x on common and uncommon mean and p99. Pairs per workload: NNTMUX_W54_BENCH_PAIRS
 * (default 2000, minimum 200). It skips without the native library.
 *
 * Three runs at 88138ba6a + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 2000 pairs per workload after 64 warmups per mode, load average ~7-8), minimum speedup
 * per gate (common mean/p99, uncommon mean/p99): adultSignalRowBatch 4.05/2.24, 4.02/2.89;
 * requestRowBatch 2.85/1.85, 2.79/2.02; companyRecordRowBatch 5.49/3.36, 5.16/4.32;
 * gameLookupRowBatch 3.91/2.25, 4.36/3.06; platformRecordRowBatch 4.80/2.75, 4.78/3.52.
 * Common batches hold 64 realistic records; uncommon batches hold 192 mixing long, multibyte,
 * malformed and whitespace-heavy values with extreme integers. All five default to Rust.
 */
final class RustXxxPipeIgdbClientCompanyGamePlatformW54ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, index of a text argument]. */
    private const array HELPERS = [
        'xxx' => [XxxPipe::class, 'adultSignalRowBatch', 4, 0],
        'client' => [Client::class, 'requestRowBatch', 4, 0],
        'company' => [Company::class, 'companyRecordRowBatch', 4, 1],
        'game' => [Game::class, 'gameLookupRowBatch', 5, 0],
        'platform' => [Platform::class, 'platformRecordRowBatch', 4, 1],
    ];

    private const int SEED = 5442026;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

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
        return (new ReflectionMethod($class, 'w54RustFfiState'))->invoke(null, ...$args);
    }

    private static function ffi(string $class): ?FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W54_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w54', $cdef);
    }

    private static function symbol(string $class): string
    {
        $cdef = (new ReflectionClass($class))->getConstant('W54_FFI_CDEF');
        self::assertIsString($cdef);
        self::assertSame(1, preg_match('/\b(nntmux_w544_\w+)\(/', $cdef, $match));

        return $match[1];
    }

    /**
     * @param  list<list<mixed>>  $args
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $args): array
    {
        $result = $class::withW54RustFfi($native, static fn (): array => $class::{$method}(...$args));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<list<mixed>> $args */
    private static function assertParity(string $class, string $method, int $words, array $args, string $label): void
    {
        $php = self::dispatch($class, $method, false, $args);
        self::assertSame($php, $class::{$method.'Php'}(...$args), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $args), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}(...$args), $label.': native method');
        self::assertCount(count($args[0]) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    /**
     * Text and int buffers exactly as the native method builds them.
     *
     * @param  list<list<mixed>>  $args
     * @return array{0: string, 1: string}
     */
    private static function encode(string $class, array $args): array
    {
        return match ($class) {
            XxxPipe::class => [implode("\0", $args[0]), ''],
            Client::class => [implode("\0", $args[0])."\0".implode("\0", $args[1]), pack('P*', ...$args[2])],
            Company::class => [implode("\0", $args[1]), pack('P*', ...$args[0], ...$args[2])],
            Game::class => [implode("\0", $args[0]), pack('P*', ...$args[1], ...$args[2])],
            Platform::class => [implode("\0", $args[1])."\0".implode("\0", $args[2]), pack('P*', ...$args[0])],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function span(int $offset, int $length): int
    {
        return ($offset << 32) | $length;
    }

    /** @return array{0: int, 1: int} */
    private static function unspan(int $word): array
    {
        return [$word >> 32, $word & 0xFFFFFFFF];
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w54_switch_api_and_default(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThan(0, $words);
        self::assertGreaterThanOrEqual(0, $textArg);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W54_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPrivate());
        self::assertIsBool($constant->getValue());
        foreach (['usesW54RustFfi', 'withW54RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w54RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());

        self::assertSame($constant->getValue(), $class::usesW54RustFfi());
        self::assertFalse($class::withW54RustFfi(false, static fn (): bool => $class::usesW54RustFfi()));
        self::assertTrue($class::withW54RustFfi(true, static fn (): bool => $class::usesW54RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW54RustFfi(), 'withW54RustFfi restores the default');

        $nested = $class::withW54RustFfi(false, static fn (): array => [
            $class::usesW54RustFfi(),
            $class::withW54RustFfi(true, static fn (): bool => $class::usesW54RustFfi()),
            $class::usesW54RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW54RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW54RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w54', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW54RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0], self::state($class));
        }
        // Each class keeps its own state, including the three BaseModel subclasses.
        Company::withW54RustFfi(false, static function (): void {
            self::assertFalse(Company::usesW54RustFfi());
            self::assertNull(self::state(Game::class, 'enabled'));
            self::assertNull(self::state(Platform::class, 'enabled'));
            self::assertNull(self::state(XxxPipe::class, 'enabled'));
        });
    }

    public function test_earlier_wave_switches_are_independent(): void
    {
        $earlier = static fn (): array => [
            XxxPipe::usesRustFfi(),
            XxxPipe::usesW433RustFfi(),
            Client::usesRustFfi(),
            Client::usesW47RequestRustFfi(),
            Company::usesRustFfi(),
            Company::usesW48CompanyRoleRustFfi(),
            Game::usesRustFfi(),
            Game::usesW48GameQualityRustFfi(),
            Platform::usesRustFfi(),
            Platform::usesW48PlatformFamilyRustFfi(),
        ];
        $before = $earlier();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                self::assertSame($before, $class::withW54RustFfi($enabled, $earlier), $class);
            }
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThan(0, $words);
        self::assertGreaterThanOrEqual(0, $textArg);
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 4, false);
        $empty = array_fill(0, count($args), []);
        $cases = [];
        foreach (array_keys($args) as $k) {
            $nonList = $args;
            $nonList[$k] = array_combine(range(1, count($args[$k])), $args[$k]);
            $cases['non-list '.$k] = $nonList;
        }
        if (count($args) > 1) {
            $short = $args;
            $short[2] = array_slice($short[2], 1);
            $cases['short column'] = $short;
            $long = $args;
            $long[1][] = $long[1][0];
            $cases['long column'] = $long;
        }
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, $empty));
            foreach ($cases as $label => $input) {
                foreach ([$method, $method.'Php', $method.'Native'] as $entry) {
                    try {
                        $class::withW54RustFfi($native, static fn (): array => $class::{$entry}(...$input));
                        self::fail($label.' must be rejected by '.$entry.'.');
                    } catch (InvalidArgumentException) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Hand-calculated contracts and agreement with the scalar helpers
    // ---------------------------------------------------------------------

    public function test_xxx_adult_signal_rows_match_summary(): void
    {
        $names = ['Brazzers.XXX.1080p', 'Some.MILF.and.Tushy', 'FC2-PPV-123456', 'Movie.2024.1080p', 'xxXxx porn', '', 'HEYZO 1pondo', "\xC3\x89Lesbian"];
        $expected = [
            1 | 4, 0, 0, 9,
            1, 6, 14, -1,
            2, 5, 0, -1,
            0, -1, -1, -1,
            3 | 4, 0, 6, 0,
            0, -1, -1, -1,
            2, 0, 6, -1,
            3, 3, 2, -1,
        ];
        $classes = [1 => 'studio', 2 => 'jav', 3 => 'keyword'];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(XxxPipe::class, 'adultSignalRowBatch', $native, [$names]));
            $random = new Randomizer(new Mt19937(self::SEED));
            [$batch] = self::batch(XxxPipe::class, $random, 192, $native);
            $rows = array_chunk(self::dispatch(XxxPipe::class, 'adultSignalRowBatch', $native, [$batch]), 4);
            $counts = ['total' => count($batch), 'studio' => 0, 'jav' => 0, 'keyword' => 0, 'unknown' => 0, 'xxx' => 0];
            foreach ($rows as $i => [$flags, $index, $at, $xxx]) {
                $class = $flags & 3;
                $counts[$classes[$class] ?? 'unknown']++;
                $counts['xxx'] += ($flags >> 2) & 1;
                $lower = strtolower($batch[$i]);
                self::assertSame($xxx === -1 ? false : $xxx, strpos($lower, 'xxx'));
                if ($class === 0) {
                    self::assertSame([-1, -1], [$index, $at]);
                } else {
                    $marker = XxxPipe::W433_ADULT_SIGNAL_MARKERS[$classes[$class]][$index];
                    self::assertSame($at, strpos($lower, $marker));
                }
            }
            self::assertSame(XxxPipe::summarizeAdultSignalBatchPhp($batch), $counts);
        }
    }

    public function test_client_request_rows_match_request_summary(): void
    {
        $endpoints = ['/games/', ' release_dates ', '', '/v4/Games', 'x/y', '//'];
        $expires = ['3600', '30', '', '1234567890', '12a', '0'];
        $statuses = [200, 429, 0, 503, 404, 1000];
        $expected = [
            self::span(1, 5), 11, 3540, 4 | 200 << 8,
            self::span(1, 13), 17, 60, 1 | 16 | 429 << 8,
            self::span(0, 0), 32, 3540, 16,
            self::span(1, 8), 64 | 128, 3540, 2 | 16 | 503 << 8,
            self::span(0, 3), 64, 3540, 3 | 404 << 8,
            self::span(2, 0), 32, 60, 16,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(Client::class, 'requestRowBatch', $native, [$endpoints, $expires, $statuses]));
            $random = new Randomizer(new Mt19937(self::SEED));
            [$paths, $ttls, $codes] = self::batch(Client::class, $random, 192, $native);
            $rows = array_chunk(self::dispatch(Client::class, 'requestRowBatch', $native, [$paths, $ttls, $codes]), 4);
            $summary = array_fill_keys(['empty_endpoint_count', 'known_endpoint_count', 'nested_endpoint_count', 'invalid_endpoint_count', 'token_ttl_sum', 'short_token_count', 'rate_limited_count', 'server_error_count', 'client_error_count', 'success_count'], 0);
            $categories = [1 => 'rate_limited_count', 2 => 'server_error_count', 3 => 'client_error_count', 4 => 'success_count'];
            $requests = [];
            foreach ($rows as $i => [$span, $flags, $ttl, $status]) {
                [$offset, $length] = self::unspan($span);
                self::assertSame(trim($paths[$i], " \t\n\r\0\x0B/"), substr($paths[$i], $offset, $length));
                $summary['empty_endpoint_count'] += ($flags >> 5) & 1;
                $summary['known_endpoint_count'] += (int) (($flags & 31) !== 0);
                $summary['nested_endpoint_count'] += ($flags >> 6) & 1;
                $summary['invalid_endpoint_count'] += ($flags >> 7) & 1;
                $summary['token_ttl_sum'] += $ttl;
                $summary['short_token_count'] += (int) ($ttl === 60);
                if (isset($categories[$status & 15])) {
                    $summary[$categories[$status & 15]]++;
                }
                $requests[] = ['endpoint' => $paths[$i], 'expires_in' => $ttls[$i], 'status' => $codes[$i]];
            }
            $reference = Client::summarizeRequestBatchPhp($requests);
            self::assertSame(count($requests), $reference['total_requests']);
            self::assertSame($summary, array_intersect_key($reference, $summary));
        }
    }

    public function test_company_rows_match_normalize_company_record(): void
    {
        $ids = [1, -3, 5, 0, 840];
        $names = ["  Nintendo  Co.,\tLtd. ", 'Square Enix Holdings', 'Inc', '', "Ubisoft\xC3\xA9 GmbH Inc"];
        $countries = [2, 0, 392, 1000, 1];
        $fnv = static fn (string $s): int => unpack('J', hash('fnv1a64', $s, true))[1];
        $expected = [
            self::span(2, 18), 15 | 8 << 32, $fnv('nintendo'), 9 | 32 | 64 | 128 | 256,
            self::span(0, 20), 20 | 20 << 32, $fnv('square-enix-holdings'), 12,
            self::span(0, 3), 3 | 3 << 32, $fnv('inc'), 64 | 128 | 256,
            self::span(0, 0), 0, $fnv(''), 0,
            self::span(0, 18), 16 | 7 << 32, $fnv('ubisoft'), 15 | 32 | 64 | 128 | 256,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(Company::class, 'companyRecordRowBatch', $native, [$ids, $names, $countries]));
            $random = new Randomizer(new Mt19937(self::SEED));
            [$batchIds, $batchNames, $batchCountries] = self::batch(Company::class, $random, 192, $native);
            $rows = array_chunk(self::dispatch(Company::class, 'companyRecordRowBatch', $native, [$batchIds, $batchNames, $batchCountries]), 4);
            $publishers = 0;
            $stripped = 0;
            foreach ($rows as $i => [$span, $lengths, , $flags]) {
                $record = Company::normalizeCompanyRecordPhp($batchIds[$i], $batchNames[$i], $batchCountries[$i]);
                [$offset, $length] = self::unspan($span);
                self::assertSame(strlen($record['name']), $length);
                self::assertSame(strspn($batchNames[$i], self::WHITESPACE), $offset);
                self::assertSame(strlen($record['slug']), $lengths & 0xFFFFFFFF);
                self::assertLessThanOrEqual($lengths & 0xFFFFFFFF, $lengths >> 32);
                self::assertSame($record['country'] !== null, ($flags & 64) !== 0);
                self::assertSame($record['id'] !== null, ($flags & 128) !== 0);
                self::assertSame($record['is_valid'], ($flags & 256) !== 0);
                $publishers += (int) (($flags & 31) !== 0);
                $stripped += ($flags >> 5) & 1;
            }
            $roles = Company::classifyCompanyRoleBatchPhp($batchNames);
            self::assertSame($roles['known_publisher_count'], $publishers);
            self::assertSame($roles['suffix_stripped_count'], $stripped);
        }
    }

    public function test_game_rows_match_normalize_game_lookup_spec(): void
    {
        $titles = ["  Zelda \"BotW\"\t\\ x ", '', 'Halo', "\x0C"];
        $limits = [10, 900, 6, 1234567];
        $platforms = [6, -5, 0, PHP_INT_MAX];
        $expected = [
            self::span(2, 16), 19, 10, 6, 72,
            self::span(0, 0), 0, 500, -1, 31,
            self::span(0, 4), 4, 6, -1, 33,
            self::span(1, 0), 0, 500, PHP_INT_MAX, 72,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(Game::class, 'gameLookupRowBatch', $native, [$titles, $limits, $platforms]));
            $random = new Randomizer(new Mt19937(self::SEED));
            [$batchTitles, $batchLimits, $batchPlatforms] = self::batch(Game::class, $random, 192, $native);
            $rows = array_chunk(self::dispatch(Game::class, 'gameLookupRowBatch', $native, [$batchTitles, $batchLimits, $batchPlatforms]), 5);
            foreach ($rows as $i => [$span, $escaped, $limit, $platform, $query]) {
                $spec = Game::normalizeGameLookupSpecPhp($batchTitles[$i], $batchLimits[$i], $batchPlatforms[$i]);
                [$offset, $length] = self::unspan($span);
                self::assertSame([strlen($spec['search']), strlen($spec['escaped']), $spec['limit'], $spec['platform_id'] ?? -1, strlen($spec['query'])], [$length, $escaped, $limit, $platform, $query]);
                self::assertSame(strspn($batchTitles[$i], self::WHITESPACE), $offset);
            }
        }
    }

    public function test_platform_rows_match_normalize_platform_record_and_family(): void
    {
        $ids = [7, 0, 3, 9];
        $names = [' PlayStation  Portable ', 'Nintendo Switch', 'Unknown Box', ''];
        $abbreviations = [null, ' ns ', '  ', 'Super-Nintendo Entertainment System!'];
        $expected = [
            self::span(1, 20), 20, -1, 1 | 7 << 4 | 256 | 2 << 9 | 2048 | 4096,
            self::span(0, 15), 15, self::span(1, 2), 3 | 8 << 4 | 256 | 1 << 9,
            self::span(0, 11), 11, -1, 2048 | 4096,
            self::span(0, 0), 0, self::span(0, 36), 3 | 4 << 4 | 1 << 9 | 2048,
        ];
        $families = [1 => 'sony_count', 2 => 'microsoft_count', 3 => 'nintendo_count', 4 => 'sega_count', 5 => 'pc_count', 6 => 'mobile_count'];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(Platform::class, 'platformRecordRowBatch', $native, [$ids, $names, $abbreviations]));
            $random = new Randomizer(new Mt19937(self::SEED));
            [$batchIds, $batchNames, $batchAbbreviations] = self::batch(Platform::class, $random, 192, $native);
            $rows = array_chunk(self::dispatch(Platform::class, 'platformRecordRowBatch', $native, [$batchIds, $batchNames, $batchAbbreviations]), 4);
            $summary = array_fill_keys([...array_values($families), 'unmatched_count', 'handheld_count', 'generation_sum'], 0);
            $platforms = [];
            foreach ($rows as $i => [$span, $slug, $abbreviation, $flags]) {
                $record = Platform::normalizePlatformRecordPhp($batchIds[$i], $batchNames[$i], $batchAbbreviations[$i]);
                self::assertSame(strlen($record['name']), self::unspan($span)[1]);
                self::assertSame(strlen($record['slug']), $slug);
                self::assertSame($record['abbreviation'] === null ? -1 : self::span(strspn((string) $batchAbbreviations[$i], self::WHITESPACE), strlen($record['abbreviation'])), $abbreviation);
                self::assertSame($record['id'] !== null, ($flags & 2048) !== 0);
                self::assertSame($record['is_valid'], ($flags & 4096) !== 0);
                if (($flags & 15) === 0) {
                    $summary['unmatched_count']++;
                    self::assertSame(0, ($flags >> 9) & 3);
                } else {
                    $summary[$families[$flags & 15]]++;
                    self::assertContains(($flags >> 9) & 3, [1, 2]);
                }
                $summary['handheld_count'] += ($flags >> 8) & 1;
                $summary['generation_sum'] += ($flags >> 4) & 15;
                $platforms[] = ['name' => $batchNames[$i], 'abbreviation' => $batchAbbreviations[$i]];
            }
            self::assertSame($summary, array_intersect_key(Platform::mapPlatformFamilyBatchPhp($platforms), $summary));
        }
    }

    // ---------------------------------------------------------------------
    // Native execution and fallbacks
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_rust_dispatch_executes_natively(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThanOrEqual(0, $textArg);
        $ffi = self::ffi($class);
        if ($ffi === null) {
            self::markTestSkipped('Native pipeline library with W54 symbols is not built.');
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 64, false);
        self::assertCount(64, $args[0]);
        $expected = $class::{$method.'Php'}(...$args);
        self::state($class, 'reset');
        self::assertSame($expected, $class::{$method.'Native'}(...$args));
        self::assertInstanceOf(FFI::class, self::state($class, 'ffi'));
        self::assertCount(64 * $words, $expected);

        // The raw export produces the same words without the PHP wrapper.
        [$text, $ints] = self::encode($class, $args);
        $out = $ffi->new('int64_t['.(64 * $words).']');
        $symbol = self::symbol($class);
        self::assertSame(64 * $words, $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 64, $out, 64 * $words));
        self::assertSame($expected, array_values(unpack('P*', FFI::string($out, 64 * $words * 8)) ?: []));
        // A short buffer reports the needed words; a wrong record count is rejected.
        self::assertSame(-(64 * $words), $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 64, $out, 64 * $words - 1));
        self::assertSame(-1, $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 63, $out, 64 * $words));
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_and_missing_library_fall_back(string $class, string $method, int $words, int $textArg): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 16, true);
        $withNul = $args;
        $withNul[$textArg][3] = "a\0b";
        $withNul[$textArg][7] = "\0";
        $withNul[$textArg][9] = "\0".$withNul[$textArg][9]."\0";
        self::assertParity($class, $method, $words, $withNul, 'NUL byte');
        $ffi = self::ffi($class);
        if ($ffi !== null) {
            [$encoded, $ints] = self::encode($class, $withNul);
            $out = $ffi->new('int64_t['.(16 * $words).']');
            self::assertSame(-1, $ffi->{self::symbol($class)}($encoded, strlen($encoded), $ints, strlen($ints), 16, $out, 16 * $words));
        }

        self::state($class, 'ffi', false);
        self::assertSame($class::{$method.'Php'}(...$args), $class::{$method.'Native'}(...$args));
        self::assertFalse(self::state($class, 'ffi'));
    }

    #[DataProvider('helperProvider')]
    public function test_realistic_and_uncommon_dataset_parity(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThanOrEqual(0, $textArg);
        $random = new Randomizer(new Mt19937(self::SEED));
        self::assertParity($class, $method, $words, self::batch($class, $random, 64, false), 'common');
        self::assertParity($class, $method, $words, self::batch($class, $random, 192, true), 'uncommon');
        // Batches beyond the cached output buffer grow it.
        self::assertParity($class, $method, $words, self::batch($class, $random, 1500, true), 'large');
        self::assertParity($class, $method, $words, self::batch($class, $random, 1, true), 'single');
    }

    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThanOrEqual(0, $textArg);
        $random = new Randomizer(new Mt19937(self::SEED + crc32($class)));
        for ($round = 0; $round < 80; $round++) {
            self::assertParity($class, $method, $words, self::batch($class, $random, $random->getInt(1, 256), $round % 2 === 1), 'round '.$round);
        }
    }

    // ---------------------------------------------------------------------
    // Datasets
    // ---------------------------------------------------------------------

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, count($values) - 1)];
    }

    /** Random bytes biased toward the characters each helper inspects. */
    private static function noise(Randomizer $random, int $max): string
    {
        $alphabet = " \t\n\r\x0B\x0C-_./\\\"',:;!()&+0123456789aAbBcCeEgGiIlLmMnNoOpPsStTxXyYzZ\x80\x85\xA0\xC3\xA9\xE2\x82\xAC\x7F";
        $out = '';
        $length = $random->getInt(0, $max);
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** @return list<list<mixed>> */
    private static function batch(string $class, Randomizer $random, int $count, bool $uncommon): array
    {
        return match ($class) {
            XxxPipe::class => [self::releaseNames($random, $count, $uncommon)],
            Client::class => self::requests($random, $count, $uncommon),
            Company::class => self::companies($random, $count, $uncommon),
            Game::class => self::games($random, $count, $uncommon),
            Platform::class => self::platforms($random, $count, $uncommon),
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<string> */
    private static function releaseNames(Randomizer $random, int $count, bool $uncommon): array
    {
        $studios = ['Brazzers', 'RealityKings', 'NaughtyAmerica', 'BangBros', 'Vixen', 'Blacked', 'Tushy', 'EvilAngel', 'TeamSkeet', 'MOFOS', '1Pondo', 'Caribbeancom', 'HEYZO', 'TokyoHot', '10musume', 'FC2-PPV', 'Private', 'Digital.Playground', 'Movie.Night', 'Some.Show'];
        $middles = ['.23.05.17.', '.Hardcore.', '.MILF.Next.Door.', ' - Lesbian Tales - ', '.Threesome.', '.Scene.', '-', '.Creampie.', '.Episode.12.', '.', ' '];
        $tails = ['XXX.1080p.MP4-WRB', 'XXX.2160p.WEB-DL', '1080p.HEVC.x265-PRT', 'XxX.720p', 'Porn.SD', '480p', 'S01E02.1080p.WEB', ''];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $name = self::pick($random, $studios).self::pick($random, $middles).self::pick($random, $tails);
            if ($uncommon) {
                $name = match ($random->getInt(0, 5)) {
                    0 => self::noise($random, 120),
                    1 => str_repeat('x', $random->getInt(0, 4)).str_repeat('Some.Very.Long.Release.Title.', $random->getInt(1, 20)).self::pick($random, $tails),
                    2 => 'Ünïcödé タイトル '.strtoupper($name).' 素人',
                    3 => strtoupper($name).self::noise($random, 30),
                    4 => str_replace('.', "\t", $name),
                    default => $name,
                };
            }
            $names[] = $name;
        }

        return $names;
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<int>} */
    private static function requests(Randomizer $random, int $count, bool $uncommon): array
    {
        $endpoints = ['games', '/games', 'games/', 'covers', 'platforms', 'involved_companies', 'release_dates', 'search', 'multiquery', 'games/count', 'v4/games', 'Games', 'game-modes', '', '  /websites/  ', 'external_games', 'age_ratings'];
        $expires = ['5184000', '3600', '4999999', '60', '30', '0', '', 'abc', '1234567890', '86400'];
        $statuses = [200, 200, 200, 201, 204, 400, 401, 403, 404, 408, 429, 500, 502, 503, 0, 302];
        $paths = $ttls = $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $path = self::pick($random, $endpoints);
            $ttl = self::pick($random, $expires);
            $code = self::pick($random, $statuses);
            if ($uncommon) {
                switch ($random->getInt(0, 4)) {
                    case 0:
                        $path = self::noise($random, 80);
                        break;
                    case 1:
                        $path = str_repeat('/', $random->getInt(0, 30)).str_repeat('nested_path/', $random->getInt(1, 20)).str_repeat(" \t", $random->getInt(0, 10));
                        break;
                    case 2:
                        $ttl = $random->getInt(0, 1) === 1 ? (string) $random->getInt(0, PHP_INT_MAX) : self::noise($random, 12);
                        break;
                    case 3:
                        $code = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                        break;
                    default:
                        $path = 'Ünïcödé/'.$path;
                }
            }
            $paths[] = $path;
            $ttls[] = $ttl;
            $codes[] = $code;
        }

        return [$paths, $ttls, $codes];
    }

    /** @return array{0: list<int>, 1: list<string>, 2: list<int>} */
    private static function companies(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = ['Nintendo', 'Nintendo Co., Ltd.', 'Sony Interactive Entertainment', 'Square Enix', 'SQUARE ENIX Holdings Co., Ltd.', 'Electronic Arts Inc.', 'Ubisoft Entertainment SA', 'CD Projekt RED', 'Bandai Namco Entertainment', 'Take-Two Interactive Software, Inc.', 'FromSoftware, Inc.', 'Devolver Digital', 'Warner Bros. Games', 'Capcom Co., Ltd.', 'Valve Corporation', 'Team Cherry', '  Konami  Digital   Entertainment ', 'Sega Corporation', 'Activision Blizzard', 'THQ Nordic AB'];
        $ids = $texts = $countries = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $random->getInt(1, 50000);
            $name = self::pick($random, $names);
            $country = self::pick($random, [392, 840, 250, 826, 616, 752, 0, 124]);
            if ($uncommon) {
                switch ($random->getInt(0, 4)) {
                    case 0:
                        $name = self::noise($random, 120);
                        break;
                    case 1:
                        $name = str_repeat(" \t\x0C", $random->getInt(1, 20)).$name.str_repeat(' Inc Ltd GmbH', $random->getInt(1, 10)).str_repeat("\n", $random->getInt(0, 5));
                        break;
                    case 2:
                        $name = 'Ünïcödé 株式会社 '.$name;
                        break;
                    case 3:
                        $id = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                        $country = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                        break;
                    default:
                        $name = strtoupper($name);
                }
            }
            $ids[] = $id;
            $texts[] = $name;
            $countries[] = $country;
        }

        return [$ids, $texts, $countries];
    }

    /** @return array{0: list<string>, 1: list<int>, 2: list<int>} */
    private static function games(Randomizer $random, int $count, bool $uncommon): array
    {
        $titles = ['The Legend of Zelda: Breath of the Wild', 'Elden Ring', 'Grand Theft Auto V', 'Hollow Knight', 'Baldur\'s Gate 3', 'Super Mario Odyssey', 'The Witcher 3: Wild Hunt', 'Halo: The Master Chief Collection', '"Quoted" Title', 'Back\\Slash Game', '  Spaced   Out  Title ', 'Persona 5 Royal', 'Final Fantasy VII Remake'];
        $texts = $limits = $platforms = [];
        for ($i = 0; $i < $count; $i++) {
            $title = self::pick($random, $titles);
            $limit = self::pick($random, [1, 5, 10, 10, 10, 25, 50]);
            $platform = self::pick($random, [0, 0, 6, 48, 49, 130, 167, 169]);
            if ($uncommon) {
                switch ($random->getInt(0, 4)) {
                    case 0:
                        $title = self::noise($random, 150);
                        break;
                    case 1:
                        $title = str_repeat("\"\\ \t", $random->getInt(1, 40)).$title;
                        break;
                    case 2:
                        $limit = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                        break;
                    case 3:
                        $platform = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                        break;
                    default:
                        $title = 'Ünïcödé ゲーム '.$title;
                }
            }
            $texts[] = $title;
            $limits[] = $limit;
            $platforms[] = $platform;
        }

        return [$texts, $limits, $platforms];
    }

    /** @return array{0: list<int>, 1: list<string>, 2: list<?string>} */
    private static function platforms(Randomizer $random, int $count, bool $uncommon): array
    {
        $rows = [['PlayStation 5', 'PS5'], ['PlayStation 4', 'PS4'], ['Xbox Series X|S', 'Series X|S'], ['Xbox One', 'XONE'], ['Nintendo Switch', 'Switch'], ['PC (Microsoft Windows)', 'PC'], ['Mac', null], ['Linux', null], ['Android', ''], ['iOS', 'iOS'], ['Nintendo 3DS', '3DS'], ['Sega Mega Drive/Genesis', 'Genesis'], ['Dreamcast', 'DC'], ['Game Boy Advance', 'GBA'], ['Atari 2600', 'Atari2600'], ['Wii U', 'WiiU'], ['PlayStation Portable', 'PSP'], ['Super Nintendo Entertainment System', 'SNES']];
        $ids = $names = $abbreviations = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $random->getInt(1, 600);
            [$name, $abbreviation] = self::pick($random, $rows);
            if ($uncommon) {
                switch ($random->getInt(0, 4)) {
                    case 0:
                        $name = self::noise($random, 100);
                        break;
                    case 1:
                        $abbreviation = $random->getInt(0, 1) === 1 ? self::noise($random, 40) : str_repeat(" \x0C", $random->getInt(1, 20));
                        break;
                    case 2:
                        $name = str_repeat('Super Nintendo ', $random->getInt(1, 10)).$name;
                        $abbreviation = null;
                        break;
                    case 3:
                        $id = $random->getInt(PHP_INT_MIN, 0);
                        break;
                    default:
                        $name = 'Ünïcödé '.strtoupper($name).' 機';
                }
            }
            $ids[] = $id;
            $names[] = $name;
            $abbreviations[] = $abbreviation;
        }

        return [$ids, $names, $abbreviations];
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
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
                self::markTestSkipped('Native pipeline library with W54 symbols is not built.');
            }
        }
        $pairs = max(200, (int) (getenv('NNTMUX_W54_BENCH_PAIRS') ?: 2000));
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
                    $class::withW54RustFfi($native, static function () use ($class, $method, $args, $expected): void {
                        for ($i = 0; $i < 64; $i++) {
                            self::assertSame($expected, $class::{$method}(...$args));
                        }
                    });
                }
                for ($sample = 0; $sample < $pairs; $sample++) {
                    foreach ($random->getInt(0, 1) === 1 ? [false, true] : [true, false] as $native) {
                        $elapsed = $class::withW54RustFfi($native, static function () use ($class, $method, $args, $expected): int {
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
            fwrite(STDERR, sprintf("\nW54.4 %s %s", $method, json_encode($ratios + ['qualified' => $qualified], JSON_THROW_ON_ERROR)));
            self::assertSame($qualified, $class::usesW54RustFfi(), $name.' '.$method.' default must match the 1.5x mean/p99 gates: '.json_encode($ratios, JSON_THROW_ON_ERROR));
        }
    }
}
