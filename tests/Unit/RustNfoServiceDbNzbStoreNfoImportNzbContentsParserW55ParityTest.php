<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NfoService;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbUploadManifestService;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustNfoServiceDbNzbStoreNfoImportNzbContentsParserW55ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        NfoService::class => 'planShowIdBatch',
        DatabaseNzbStore::class => 'planPayloadIntegrityBatch',
        NfoImportService::class => 'planManifestBatch',
        NzbContentsService::class => 'planPar2FamilyBatch',
        NzbParserService::class => 'planSubjectBatch',
    ];

    /** Class => Wave 55.2 export. */
    private const SYMBOLS = [
        NfoService::class => 'nntmux_w552_nfo_show_id_plan_batch',
        DatabaseNzbStore::class => 'nntmux_w552_nzb_store_integrity_plan_batch',
        NfoImportService::class => 'nntmux_w552_nfo_import_manifest_plan_batch',
        NzbContentsService::class => 'nntmux_w552_nzb_par2_family_plan_batch',
        NzbParserService::class => 'nntmux_w552_nzb_parser_subject_plan_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns): the per-metric median of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 55, each metric
     * the median of 3 rounds of 1000 pairs after 50 warmups, each side the fastest of 3
     * back-to-back calls, randomized fixtures, alternating call order, release Rust, PHP 8.5.12
     * CLI without opcache, Apple Silicon macOS on a shared host at load average 7-8). Timings
     * include dispatch, the PCRE table probe, packing, the FFI call and unpack('P*'). The runs'
     * low was NfoService Uncommon p99 1.794x in one run (1.871x and 1.968x in the others). A class
     * defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        NfoService::class => [3.527, 3.0, 2.169, 1.871],
        DatabaseNzbStore::class => [3.886, 3.502, 4.568, 4.015],
        NfoImportService::class => [2.718, 2.725, 2.281, 2.335],
        NzbContentsService::class => [6.376, 6.176, 6.796, 5.954],
        NzbParserService::class => [2.465, 2.388, 2.433, 2.456],
    ];

    private const ROUNDS = 3;

    private const PAIRS = 1000;

    private const WARMUPS = 50;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** parseShowIdNative() site per W55 pattern (1-8). */
    private const SITES = [1 => 'tvmaze', 2 => 'imdb', 3 => 'thetvdb', 4 => 'thetvdb', 5 => 'tmdb_movie', 6 => 'tmdb_tv', 7 => 'anidb', 8 => 'trakt'];

    private const STATES = [
        NzbUploadManifestService::STATE_STAGED, NzbUploadManifestService::STATE_NZB_IMPORTED, NzbUploadManifestService::STATE_NZB_DUPLICATE,
        NzbUploadManifestService::STATE_NZB_FAILED, NzbUploadManifestService::STATE_NZB_PENDING, NzbUploadManifestService::STATE_METADATA_REVIEW,
        NzbUploadManifestService::STATE_METADATA_DEFERRED, NzbUploadManifestService::STATE_NZB_REJECTED, NzbUploadManifestService::STATE_NFO_IMPORTED,
        NzbUploadManifestService::STATE_NFO_FAILED,
    ];

    private const RELEASES = [
        'Movie.Name.2024.1080p.BluRay.x264-GRP', 'Show.Name.S01E05.720p.WEB-DL.DDP5.1.H.264-NTb', 'Artist-Album-2023-WEB-FLAC-OMA',
        'Some_Documentary_2022_2160p_UHD', 'Game.Title.v1.2.3-RUNE', 'Old.Film.1968.DVDRip.XviD-iMBT',
    ];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW55RustFfi', 'withW55RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w55RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertTrue($reflection->hasConstant('W55_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W55_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W55_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W55_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w55', strtolower((string) $property), 'Wave 55 state lives in w55RustFfiState().');
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW55RustFfi(), $class);
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW55RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW55RustFfi(! $enabled, static fn (): bool => $class::usesW55RustFfi());

                    return [$class::usesW55RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW55RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW55RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW55RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_waves(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW55RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW55RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 55 toggles leave the earlier batch switches untouched.
        $earlier = static fn (): array => [NfoService::usesW424RustFfi(), DatabaseNzbStore::usesW402RustFfi(), NfoImportService::usesW412RustFfi(),
            NzbContentsService::usesW402RustFfi(), NzbParserService::usesW402RustFfi()];
        $before = $earlier();
        NfoService::withW55RustFfi(true, static function () use ($earlier, $before): void {
            DatabaseNzbStore::withW55RustFfi(true, static function () use ($earlier, $before): void {
                NzbParserService::withW55RustFfi(false, static function () use ($earlier, $before): void {
                    self::assertSame($before, $earlier());
                });
            });
        });
        self::assertSame($before, $earlier());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5500));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $args = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $args);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW55RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $args), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        $hex = str_repeat('ab', 32);
        // NUL bytes, ragged columns, keyed arrays and saturating totals keep the PHP semantics.
        $this->assertParity(NfoService::class, [[1 => 'imdb.com/title/tt1234567', 3 => 'x']]);
        $this->assertParity(DatabaseNzbStore::class, [[$hex."\0", $hex], [$hex, $hex], [1, 2], [3, 4], 10, 10]);
        $this->assertParity(DatabaseNzbStore::class, [[$hex, $hex], [$hex], [1, 2], [3, 4], 10, 10]);
        $this->assertParity(DatabaseNzbStore::class, [[$hex], [$hex], [1, 2], [3], 10, 10]);
        $this->assertParity(NfoImportService::class, [['nzb_imported', "nfo_failed\0"], ['g', 'h'], [1, 2], [true, true], [false, false]]);
        $this->assertParity(NfoImportService::class, [['nzb_imported', 'nfo_failed'], ['g', "h\0"], [1, 2], [true, true], [false, false]]);
        $this->assertParity(NfoImportService::class, [['nzb_imported', 'nfo_failed'], ['g', 'h'], [1], [true, true], [false, false]]);
        $this->assertParity(NzbContentsService::class, [["a.par2\0", 'b.par2']]);
        $this->assertParity(NzbContentsService::class, [['x' => 'a.par2']]);
        $this->assertParity(NzbParserService::class, [["a.nfo\0", 'b.par2'], [1, 1]]);
        $this->assertParity(NzbParserService::class, [['a.nfo', 'b.par2'], [1]]);
        $this->assertParity(NzbParserService::class, [['A (1/99999999999999999999)', 'b (1/2)'], [1, 2]]);

    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $one = pack('P', 1);
        // [good args, malformed args, words, empty args, empty words] before (out, out_cap).
        $guards = [
            NfoService::class => [['ab', 2, pack('P', 2), 8, 1], ['abc', 3, pack('P', 2), 8, 1], 11, [null, 0, null, 0, 0], 10],
            DatabaseNzbStore::class => [['a', 1, 'a', 1, pack('P*', 1, 2), 16, 1, 5, 5], ['a', 1, "a\0b", 3, pack('P*', 1, 2), 16, 1, 5, 5], 9,
                [null, 0, null, 0, null, 0, 0, 0, 0], 8],
            NfoImportService::class => [['nzb_imported', 12, 'g', 1, pack('P*', 1, 1, 0), 24, 1], ['nzb_imported', 12, 'g', 1, pack('P*', 1, 1), 16, 1], 12,
                [null, 0, null, 0, null, 0, 0], 11],
            NzbContentsService::class => [["a.par2\0b", 8, 2], ["a.par2\0b", 8, 1], 10, [null, 0, 0], 6],
            NzbParserService::class => [['x.nfo', 5, $one, 8, 1], ['A (1/99999999999999999999)', 26, $one, 8, 1], 8, [null, 0, null, 0, 0], 6],
        ];
        foreach ($guards as $class => [$good, $bad, $words, $empty, $emptyWords]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            $symbol = self::SYMBOLS[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$good, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$good, null, 32]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, [...$good, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$bad, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, $good[1], ...\array_slice($good, 2), $out, 32]), $class);
            self::assertSame($emptyWords, $this->invoke($ffi, $symbol, [...$empty, $out, 32]), $class);
        }
        // Negative frame lengths and short integer tables are refused.
        $show = $this->ffi(NfoService::class);
        $showOut = $show->new('int64_t[32]');
        self::assertSame(-1, $this->invoke($show, self::SYMBOLS[NfoService::class], ['abc', 3, pack('P*', -1, 4), 16, 2, $showOut, 32]));
        self::assertSame(-1, $this->invoke($show, self::SYMBOLS[NfoService::class], ['abc', 3, pack('P', 3), 4, 1, $showOut, 32]));
        $store = $this->ffi(DatabaseNzbStore::class);
        $storeOut = $store->new('int64_t[32]');
        self::assertSame(-1, $this->invoke($store, self::SYMBOLS[DatabaseNzbStore::class], ['a', 1, 'a', 1, $one, 8, 1, 5, 5, $storeOut, 32]));
        self::assertSame(-1, $this->invoke($store, self::SYMBOLS[DatabaseNzbStore::class], ['a', 1, null, 1, pack('P*', 1, 2), 16, 1, 5, 5, $storeOut, 32]));
    }

    public function test_show_id_plan_parity(): void
    {
        $this->requireBindings();
        $texts = [
            'see https://www.tvmaze.com/shows/1234567/x', 'IMDB.COM/Title/TT0111161 and tvmaze.com/shows/5', 'imdb.com/title/tt011116 imdb.com/title/tt01111612',
            'thetvdb.com/series/the-show thetvdb.com/?tab=series&id=81189', 'thetvdb.com/series/123456789x', 'thetvdb.com/series/Some_Show-2!',
            'themoviedb.org/tv/1399 themoviedb.org/movie/550', 'themoviedb.org/tv/1399', 'anidb.net/perl-bin/animedb.pl?show=anime&aid=1234567',
            'ANIDB.net/anime/69', 'trakt.tv/movies/the-matrix-1999 trakt.tv/shows/x', 'tvmaze.com/shows/ trakt.tv/users/x anidb.net/animex/1', '', '.',
        ];
        $word = static fn (int $pattern, string $id): int => $pattern | \strlen($id) << 8 | crc32($id) << 32;
        $words = [
            $word(1, '123456'), $word(1, '5'), $word(2, 'tt01111612'), $word(3, '81189'), $word(4, '12345678'), $word(4, 'Some_Show-2'), $word(5, '550'),
            $word(6, '1399'), $word(7, '123456'), $word(7, '69'), $word(8, 'the-matrix-1999'), 0, 0, 0,
            11, 2, 1, 1, 2, 1, 1, 2, 1,
        ];
        $this->assertWords(NfoService::class, [$texts], $words);
        $this->assertParity(NfoService::class, [$texts]);
        // The plan agrees with parseShowIdNative() for every text.
        $service = (new ReflectionClass(NfoService::class))->newInstanceWithoutConstructor();
        [$texts] = $this->fixture(NfoService::class, new Randomizer(new Mt19937(5510)), true);
        $plan = $this->call(NfoService::class, 'Native', [$texts]);
        foreach ($texts as $i => $text) {
            $parsed = $service->parseShowIdNative($text);
            $pattern = $plan[$i] & 0xFF;
            self::assertSame($parsed === false ? null : $parsed['site'], self::SITES[$pattern] ?? null);
            if ($parsed !== false) {
                self::assertSame(crc32($parsed['showid']), $plan[$i] >> 32 & 0xFFFFFFFF);
                self::assertSame(\strlen($parsed['showid']), $plan[$i] >> 8 & 0xFFFFFF);
            }
        }
        $this->assertRandomParity(NfoService::class, 5520);
    }

    public function test_payload_integrity_plan_parity(): void
    {
        $this->requireBindings();
        $hex = str_repeat('a', 64);
        $args = [[$hex, $hex, '', 'ABC'], [$hex, 'x', '', 'ABC'], [10, 5, -1, 20], [100, 7, 3, 300], 16, 200];
        $words = [1 | 2 | 4 | 8 | crc32($hex) << 32, 1 | 4 | crc32($hex) << 32, 2 | 16 | 32, 2 | crc32('ABC') << 32, 2, 3, 1, 2, 15, 100, 100];
        $this->assertWords(DatabaseNzbStore::class, $args, $words);
        $this->assertParity(DatabaseNzbStore::class, $args);
        $this->assertParity(DatabaseNzbStore::class, [['a', 'a'], ['a', 'a'], [0, 0], [PHP_INT_MAX, PHP_INT_MAX], 0, PHP_INT_MAX]);
        // The valid flag agrees with validPayloadPhp() over decoded payloads.
        $random = new Randomizer(new Mt19937(5530));
        $stored = $computed = $compressed = $decoded = $xmls = [];
        for ($i = 0; $i < 48; $i++) {
            $xml = $i % 7 === 0 ? '' : '<nzb>'.bin2hex($random->getBytes($random->getInt(1, 400))).'</nzb>';
            $xmls[] = $xml;
            $digest = NzbSourceGuard::digestPhp($xml);
            $stored[] = $i % 5 === 0 ? NzbSourceGuard::digestPhp($xml.'x') : $digest;
            $computed[] = $digest;
            $compressed[] = $random->getInt(0, 900);
            $decoded[] = \strlen($xml);
        }
        $plan = $this->call(DatabaseNzbStore::class, 'Native', [$stored, $computed, $compressed, $decoded, 1000, 600]);
        foreach ($xmls as $i => $xml) {
            self::assertSame(DatabaseNzbStore::validPayloadPhp($xml, $stored[$i], 600), ($plan[$i] & 8) !== 0, (string) $i);
        }
        $this->assertRandomParity(DatabaseNzbStore::class, 5540);
    }

    public function test_manifest_plan_parity(): void
    {
        $this->requireBindings();
        $args = [
            ['nzb_imported', 'nfo_failed', 'nzb_imported', 'staged', 'nfo_imported', 'metadata_deferred', 'nzb_rejected', 'nzb_imported', 'nfo_failed', 'nzb_imported'],
            ['g', 'g', 'h', '', 'x', 'y', 'z', '', 'w', 'v'],
            [1, 2, 1, 0, 3, 4, 5, 6, -7, 8],
            [true, true, true, false, true, true, true, true, true, false],
            [false, false, false, false, false, false, false, false, false, true],
        ];
        $words = [
            8 | crc32('g') << 32, 8 | 1 << 8 | crc32('g') << 32, 8 | 2 << 8 | crc32('h') << 32, 4 | 4 << 8, 1 | crc32('x') << 32, 2 | crc32('y') << 32,
            3 | crc32('z') << 32, 7 | 4 << 8, 7 | crc32('w') << 32, 5 | crc32('v') << 32,
            1, 1, 1, 1, 1, 0, 2, 3, 2, 2,
        ];
        $this->assertWords(NfoImportService::class, $args, $words);
        $this->assertParity(NfoImportService::class, $args);
        // Importable manifests are exactly those normalizeManifestEntryPhp() accepts in an importable state.
        [$states, $guids, $ids, $hasNfo, $relinquished] = $this->fixture(NfoImportService::class, new Randomizer(new Mt19937(5550)), true);
        $plan = $this->call(NfoImportService::class, 'Native', [$states, $guids, $ids, $hasNfo, $relinquished]);
        foreach ($states as $i => $state) {
            $ready = \in_array($state, [NzbUploadManifestService::STATE_NZB_IMPORTED, NzbUploadManifestService::STATE_NFO_FAILED], true) && $hasNfo[$i] && ! $relinquished[$i];
            $identity = NfoImportService::normalizeManifestEntryPhp(['release_id' => $ids[$i], 'release_guid' => $guids[$i]]);
            self::assertSame($ready && $identity !== null, ($plan[$i] & 0xFF) === 8, (string) $i);
        }
        $this->assertRandomParity(NfoImportService::class, 5560);
    }

    public function test_par2_family_plan_parity(): void
    {
        $this->requireBindings();
        $subjects = ['a.par2', '"a.vol0+1.par2"', 'b.rar', 'a.par2', '[01/10] - "Show.vol03+04.par2" yEnc', "x.par2\n\n", '"x.par2', '".par2" a.par2"b'];
        $key = static fn (string $key): int => \strlen($key) << 8 | crc32($key) << 32;
        $words = [
            1 | 16 | $key('a.par2'), 0, 1 | 2 | 4 | 8 | $key('a.par2'), 0, 0, -1, 1 | 2 | 16 | $key('a.par2'), 0,
            1 | 4 | 8 | 32 | $key('Show.par2'), 4, 1 | 16 | $key('x.par2'), 5, 1 | 2 | $key('x.par2'), 5, 1 | 8 | $key(' a.par2'), 7,
            7, 4, 2, 1, 3,
        ];
        $this->assertWords(NzbContentsService::class, [$subjects], $words);
        $this->assertParity(NzbContentsService::class, [$subjects]);
        // The keys agree with par2FamilyKey() for every subject.
        $service = (new ReflectionClass(NzbContentsService::class))->newInstanceWithoutConstructor();
        $familyKey = new ReflectionMethod(NzbContentsService::class, 'par2FamilyKey');
        [$subjects] = $this->fixture(NzbContentsService::class, new Randomizer(new Mt19937(5570)), true);
        $plan = $this->call(NzbContentsService::class, 'Native', [$subjects]);
        foreach ($subjects as $i => $subject) {
            $expected = $familyKey->invoke($service, $subject);
            self::assertSame($expected !== null, ($plan[2 * $i] & 1) === 1, $subject);
            self::assertSame($expected === null ? 0 : crc32($expected), $plan[2 * $i] >> 32 & 0xFFFFFFFF, $subject);
        }
        $this->assertRandomParity(NzbContentsService::class, 5580);
    }

    public function test_subject_plan_parity(): void
    {
        $this->requireBindings();
        $subjects = ['Release.nfo (1/1)', 'x.par2', 'file (12/40)', 'abc-def (1/1)', 'readme (1/2)', 'movie.part01.rar (1/0)', 'A 12)'];
        $counts = [1, 3, 40, 1, 2, 9, -4];
        $words = [
            1 | 4 | 16 | crc32('Release.nfo (1/1)') << 32, 1, 8 | crc32('x.par2') << 32, 0, 16 | crc32('file (12/40)') << 32, 40, 4 | 16 | crc32('abc-def (1/1)') << 32, 1,
            4 | 16 | crc32('readme (1/2)') << 32, 2, crc32('movie.part01.rar (1/0)') << 32, 0, 16 | crc32('A 12)') << 32, 12,
            1, 3, 1, 56, 40,
        ];
        $this->assertWords(NzbParserService::class, [$subjects, $counts], $words);
        $this->assertParity(NzbParserService::class, [$subjects, $counts]);
        // The plan agrees with the parser's single-subject detectors.
        $parser = new NzbParserService;
        [$subjects, $counts] = $this->fixture(NzbParserService::class, new Randomizer(new Mt19937(5590)), true);
        $plan = $this->call(NzbParserService::class, 'Native', [$subjects, $counts]);
        foreach ($subjects as $i => $subject) {
            $nfo = $parser->detectNfoFile($subject);
            self::assertSame($nfo === false ? 0 : $nfo['priority'], $plan[2 * $i] & 3, $subject);
            self::assertSame($parser->detectHiddenNfoFile($subject, $counts[$i]) !== false, ($plan[2 * $i] & 4) !== 0, $subject);
            self::assertSame($parser->detectPar2IndexFile($subject), ($plan[2 * $i] & 8) !== 0, $subject);
            self::assertSame($parser->extractPartsTotal($subject), $plan[2 * $i + 1], $subject);
        }
        $this->assertRandomParity(NzbParserService::class, 5600);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $args): void
    {
        $this->requireBindings();
        $this->assertParity($class, $args);
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<mixed>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('k', 70000);
        $hex = str_repeat('0f', 32);
        $binary = "\xff\xfe\x00\x01".str_repeat("\xDB\xB2", 30);
        yield 'show empty' => [NfoService::class, [[]]];
        yield 'show bounds' => [NfoService::class, [[
            '', "\0", $binary, $long, $long.'trakt.tv/shows/'.$long, 'tvmaze.com/shows/0', 'tvmaze.com/shows/0000000', 'imdb.com/title/tt1234567',
            'imdb.com/title/t1234567', 'imdb.com/title/tt123456', 'IMDb.Com/TITLE/tT99999999999999999999', 'thetvdb.com/?tab=series&id=', 'thetvdb.com/?TAB=SERIES&ID=1',
            'thetvdb.com/series/-', 'thetvdb.com/series/_', 'thetvdb.com/series/!', 'themoviedb.org/movie/', 'themoviedb.org/tv/123456789',
            'anidb.net/anime/', 'anidb.net/perl-bin/animedb.pl?show=anime&aid=', 'anidb.net/PERL-BIN/ANIMEDB.PL?SHOW=ANIME&AID=7', 'trakt.tv/shows/', 'trakt.tv/movies/-x-',
            'a..com tvmaze..com/shows/1 tvmaze.com//shows/1', "tvmaze.com/shows/1\n", "x\ntrakt.tv/shows/a\nimdb.com/title/tt7654321", 'trakt.tv/shows/'.$long,
            'thetvdb.com/series/'.str_repeat('9', 30), "trakt.tv/shows/caf\xE9", 'trakt.tv/shows/ünï',
        ]]];
        yield 'show priorities' => [NfoService::class, [array_fill(0, 12, 'trakt.tv/shows/z anidb.net/anime/2 themoviedb.org/tv/3 themoviedb.org/movie/4 thetvdb.com/series/5 thetvdb.com/?tab=series&id=6 imdb.com/title/tt0000007 tvmaze.com/shows/8')]];
        yield 'store empty' => [DatabaseNzbStore::class, [[], [], [], [], 0, 0]];
        yield 'store bounds' => [DatabaseNzbStore::class, [
            [$hex, strtoupper($hex), substr($hex, 1), $hex.'0', '', 'g'.substr($hex, 1), $long, $binary, $hex, $hex, $hex],
            [$hex, strtoupper($hex), substr($hex, 1), $hex, '', 'x', $long, $binary, $hex.'0', $hex, $hex],
            [0, -1, PHP_INT_MAX, PHP_INT_MIN, 1, 33554432, 33554433, 5, 6, PHP_INT_MAX, 0],
            [0, 1, -1, PHP_INT_MAX, PHP_INT_MIN, 268435456, 268435457, 7, 8, PHP_INT_MAX, PHP_INT_MAX],
            DatabaseNzbStore::MAX_COMPRESSED_BYTES, DatabaseNzbStore::MAX_DECODED_BYTES,
        ]];
        yield 'store saturating' => [DatabaseNzbStore::class, [[$hex, $hex, $hex], [$hex, $hex, $hex], [PHP_INT_MAX, PHP_INT_MAX, 1], [PHP_INT_MAX, PHP_INT_MAX, 1], PHP_INT_MAX, PHP_INT_MAX]];
        yield 'store negative limits' => [DatabaseNzbStore::class, [[$hex, $hex], [$hex, $hex], [0, 1], [0, -1], -1, PHP_INT_MIN]];
        yield 'manifest empty' => [NfoImportService::class, [[], [], [], [], []]];
        yield 'manifest bounds' => [NfoImportService::class, [
            ['', 'NZB_IMPORTED', 'nzb_imported ', 'nzb_imported', 'nfo_failed', 'nzb_imported', 'nzb_imported', 'nzb_imported', 'nzb_imported', 'nfo_failed', 'nzb_imported', $long, 'nzb_imported'],
            ['g', 'g', 'g', '0', '00', $long, $binary, '1', '1', '0', '', '', 'g'],
            [1, 1, 1, PHP_INT_MAX, PHP_INT_MAX, 1, 2, 0, -1, PHP_INT_MIN, 3, 4, 1],
            [true, true, true, true, true, true, true, true, true, true, true, false, true],
            [false, false, false, false, false, false, false, false, false, false, false, true, true],
        ]];
        yield 'manifest repeats' => [NfoImportService::class, [array_fill(0, 9, 'nzb_imported'), ['a', 'b', 'a', '1', '01', '1', 'c', 'c', 'a'], [1, 2, 3, 1, 1, 2, 9, 9, 9], array_fill(0, 9, true), array_fill(0, 9, false)]];
        yield 'family empty' => [NzbContentsService::class, [[]]];
        yield 'family bounds' => [NzbContentsService::class, [[
            '', ' ', '"', '""', '".par2"', '"a.par2"', 'a.par2', '.par2', 'a.PAR2', "a.par2\n", "a.par2 \n", "\"a.par2\"\n", "\ta.par2\x0b", "\x0ca.par2\r",
            '[1/2]a.par2', ' [1/2] a.par2', '[1/2', '[a/2] x.par2', '[1/2] [3/4] y.par2', 'x.vol1+2.par2', 'x.VOL01+02.PAR2', 'x.vol+2.par2', 'x.vol1+.par2', 'x.vol1-2.par2',
            "x.vol1+2.par2\n", '.vol1+2.par2', 'vol1+2.par2', "'q.par2'", " ' \"z.par2\" ' ", 'a"b.par2 c', 'a b.par2"c d.par2', $long.'.par2', '"'.$long.'.par2"', $binary,
            "\xff.par2", 'ünï.par2', 'a.par22', 'a.par2.rar', 'a.par2 yEnc (1/1)', 'a.vol00+01.par2 yEnc (1/3)',
        ]]];
        yield 'family repeats' => [NzbContentsService::class, [['a.par2', 'a.vol0+1.par2', 'A.par2', '"a.par2"', 'a.vol1+2.par2', 'b.par2', 'a.par2']]];
        yield 'subject empty' => [NzbParserService::class, [[], []]];
        yield 'subject bounds' => [NzbParserService::class, [
            ['', ' ', '(1/1)', 'x (1/1)', "x (1/1)\n", 'x (1/1)\n\n', 'readme', 'nfo', 'file_id.diz', 'x.nfo.rar', 'x.NFO', 'x.info', 'x.inf', 'x.nfo-x',
                'abc-def (1/1)', 'abc (1/1)', 'yEnc (12) [1/1]', 'x.exe (1/1)', 'x.r01 (1/1)', 'x.par2', 'x.PAR2', "x.par2\n", "x.par2\n\n", '1/2', '1/2]', '(1/0)',
                '1)', '0)', '00)', 'x 007/0012]', 'x [3/4', 'x (3/4) ', $long, $binary, "\xff (1/1)", 'ünï.nfo', '9223372036854775807)', '1/9223372036854775807'],
            [1, 1, 1, 1, 1, 1, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, -1, PHP_INT_MIN, PHP_INT_MAX, 2, 3, 1, 1, 1, 1, 1, 1, 1, 1, 1],
        ]];
        yield 'subject saturating sum' => [NzbParserService::class, [['a (1/9223372036854775807)', 'b (2/9223372036854775807)', 'c 5)'], [1, 2, 3]]];
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture, alternating which runs first; each side
     * keeps the fastest of REPEATS back-to-back calls. Common batches hold 64 typical records,
     * Uncommon batches 128-256 long, malformed or adversarial ones. Each of ROUNDS rounds times
     * PAIRS pairs after WARMUPS warmups; a metric is the median of its round values (nearest-rank
     * p99 per round). Every pair must agree; raw pairs are written outside the checkout. A class
     * must default to Rust exactly when all four metrics reach 1.5x.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(55));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $metrics = [];
            foreach (['Common', 'Uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
                $rounds = [];
                for ($round = 0; $round < self::ROUNDS; $round++) {
                    $php = $rust = [];
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW55RustFfi($native, fn (): array => $this->timed($class, $args));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $php[] = $elapsed[0];
                            $rust[] = $elapsed[1];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                    sort($php);
                    sort($rust);
                    $p99 = (int) ceil(\count($php) * 0.99) - 1;
                    $rounds[] = [
                        'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                        'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                        'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                    ];
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => $this->median(array_column($rounds, 'mean_speedup')), 'p99_speedup' => $this->median(array_column($rounds, 'p99_speedup')),
                    'php_mean_ns' => $this->median(array_column($rounds, 'php_mean_ns')), 'rust_mean_ns' => $this->median(array_column($rounds, 'rust_mean_ns')),
                    'php_p99_ns' => $this->median(array_column($rounds, 'php_p99_ns')), 'rust_p99_ns' => $this->median(array_column($rounds, 'rust_p99_ns')),
                    'rounds' => $rounds,
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w552-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => 55, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW55.2 paired benchmark artifact: {$artifact}\n".json_encode(array_map(static fn (array $class): array => array_map(static fn (mixed $w): mixed => \is_array($w) ? array_diff_key($w, ['rounds' => true]) : $w, $class), $summary), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW55RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * An explicit setlocale() makes PHP build PCRE tables from LC_CTYPE (macOS even marks
     * Latin-1 letters as `\w` under C.UTF-8), so the regex-backed helpers hand those batches to
     * PHP. Declared last: once a script sets a locale, later regexes keep the rebuilt tables.
     */
    public function test_native_helpers_fall_back_to_php_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $this->assertParity(NfoService::class, [["trakt.tv/shows/caf\xE9-noir", "thetvdb.com/series/\xC9t\xE9", 'IMDB.COM/TITLE/TT1234567']]);
                $this->assertParity(NzbContentsService::class, [["\xA0a.par2\xA0", "\x85\"b.par2\"", 'X.VOL1+2.PAR2']]);
                $this->assertParity(NzbParserService::class, [["\xA0readme\xA0 (1/1)", "\xE9.nfo", 'FILE_ID.DIZ (1/1)'], [1, 1, 1]]);
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $args);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W55_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $args): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix](...$args);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $php = $class::withW55RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW55RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * Documented words (without the digest) from both implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @param  list<int>  $words
     */
    private function assertWords(string $class, array $args, array $words): void
    {
        foreach (['Php', 'Native'] as $suffix) {
            self::assertSame($words, \array_slice($this->call($class, $suffix, $args), 0, -1), $class.'::'.$suffix);
        }
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function assertRandomParity(string $class, int $seed): void
    {
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity($class, $this->fixture($class, new Randomizer(new Mt19937($seed + $i)), $uncommon));
            }
        }
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w55RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w55 binding');

        return $ffi;
    }

    /** Each Wave 55.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        self::assertTrue(NzbParserService::pcreUsesDefaultTables(), 'PCRE must use its default tables so parity exercises Rust');
        $calls = [
            NfoService::class => [['a', 1, pack('P', 1), 8, 1], 11],
            DatabaseNzbStore::class => [['a', 1, 'a', 1, pack('P*', 1, 2), 16, 1, 5, 5], 9],
            NfoImportService::class => [['nzb_imported', 12, 'g', 1, pack('P*', 1, 1, 0), 24, 1], 12],
            NzbContentsService::class => [['a.par2', 6, 1], 8],
            NzbParserService::class => [['x.nfo', 5, pack('P', 1), 8, 1], 8],
        ];
        foreach ($calls as $class => [$args, $words]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame($words, $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W55_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            NfoService::class => [$this->nfoBatch($random, $uncommon)],
            DatabaseNzbStore::class => $this->integrityBatch($random, $uncommon),
            NfoImportService::class => $this->manifestBatch($random, $uncommon),
            NzbContentsService::class => [$this->subjectBatch($random, $uncommon)[0]],
            NzbParserService::class => $this->subjectBatch($random, $uncommon),
        };
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789._-/', $length);
    }

    private function batchSize(Randomizer $random, bool $uncommon): int
    {
        return $uncommon ? $random->getInt(128, 256) : 64;
    }

    /** A show/movie link in the forms NFOs carry; uncommon links include near misses. */
    private function link(Randomizer $random, bool $uncommon): string
    {
        $link = match ($random->getInt(0, 11)) {
            0, 1, 2, 3, 4 => 'https://www.imdb.com/title/tt'.str_pad((string) $random->getInt(1, 9999999), 7, '0', STR_PAD_LEFT).'/',
            5 => 'https://www.tvmaze.com/shows/'.$random->getInt(1, 99999).'/'.$this->noise($random, 20),
            6 => 'https://thetvdb.com/series/'.$this->pick($random, ['the-office', 'breaking-bad', (string) $random->getInt(1, 999999)]),
            7 => 'http://thetvdb.com/?tab=series&id='.$random->getInt(1, 999999),
            8 => 'https://www.themoviedb.org/'.$this->pick($random, ['movie', 'tv']).'/'.$random->getInt(1, 999999),
            9 => 'https://anidb.net/'.$this->pick($random, ['anime/', 'perl-bin/animedb.pl?show=anime&aid=']).$random->getInt(1, 20000),
            10 => 'https://trakt.tv/'.$this->pick($random, ['shows', 'movies']).'/'.$this->pick($random, ['the-matrix-1999', 'severance']),
            default => '',
        };
        if ($uncommon) {
            $link = match ($random->getInt(0, 5)) {
                0 => strtoupper($link),
                1 => substr($link, 0, max(0, \strlen($link) - $random->getInt(1, 4))),
                2 => $this->pick($random, ['imdb.com/title/tt12345', 'tvmaze.com/shows/x', 'trakt.tv/users/me', 'anidb.net/anime/', 'thetvdb.com/series/!']),
                3 => $link.' '.$this->link($random, false),
                default => $link,
            };
        }

        return $link;
    }

    /** NFO bodies: CP437 art, release info lines and an occasional show/movie link. */
    private function nfoText(Randomizer $random, bool $uncommon): string
    {
        $art = ["\xDB\xDB\xDB\xB2\xB1\xB0", "\xC9\xCD\xCD\xCD\xBB", '  .:: RELEASE INFO ::.  ', "\xDC\xDF\xDC\xDF", '. . . . . . .'];
        $lines = [];
        for ($l = $random->getInt(15, $uncommon ? 90 : 45); $l > 0; $l--) {
            $lines[] = match ($random->getInt(0, 9)) {
                0, 1 => str_repeat($this->pick($random, $art), $random->getInt(1, 10)),
                2 => $this->pick($random, self::RELEASES),
                3 => 'Size......: '.$random->getInt(1, 50).'.'.$random->getInt(0, 99).' GB',
                4 => 'Video.....: x264 @ '.$random->getInt(1000, 12000).' kbps',
                5 => 'Audio.....: English DTS-HD MA 5.1',
                6 => 'Source....: '.$this->pick($random, ['BluRay', 'WEB-DL', 'HDTV']).' / Date...: 2026-0'.$random->getInt(1, 9).'-1'.$random->getInt(0, 9),
                default => ucfirst($this->noise($random, 70)),
            };
        }
        for ($k = $random->getInt(0, $uncommon ? 3 : 1); $k > 0; $k--) {
            array_splice($lines, $random->getInt(0, \count($lines)), 0, [$this->link($random, $uncommon)]);
        }
        $text = implode("\r\n", $lines);
        if ($uncommon) {
            $text = match ($random->getInt(0, 7)) {
                0 => '',
                1 => $this->noise($random, 3000, true)."\0".$this->link($random, true),
                2 => str_repeat('.', $random->getInt(100, 3000)).$this->link($random, true),
                3 => str_repeat('trakt.tv/shows/', $random->getInt(5, 60)).$this->noise($random, 300),
                default => $text,
            };
        }

        return $text;
    }

    /** @return list<string> */
    private function nfoBatch(Randomizer $random, bool $uncommon): array
    {
        $texts = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $texts[] = $this->nfoText($random, $uncommon);
        }

        return $texts;
    }

    /** @return array{list<string>, list<string>, list<int>, list<int>, int, int} */
    private function integrityBatch(Randomizer $random, bool $uncommon): array
    {
        $stored = $computed = $compressed = $decoded = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $digest = bin2hex($random->getBytes(32));
            $other = $random->getInt(0, 30) === 0 ? bin2hex($random->getBytes(32)) : $digest;
            $size = $random->getInt(2_000, 4_000_000);
            $inflated = $size * $random->getInt(3, 9);
            if ($uncommon) {
                $digest = match ($random->getInt(0, 7)) {
                    0 => '',
                    1 => strtoupper($digest),
                    2 => substr($digest, 0, $random->getInt(0, 63)),
                    3 => $this->noise($random, 80, true),
                    4 => $digest.$this->noise($random, 4),
                    default => $digest,
                };
                $other = $random->getInt(0, 3) === 0 ? $this->pick($random, ['', $other, strtolower($digest)]) : $digest;
                $size = $random->getInt(0, 5) === 0 ? $this->pick($random, [PHP_INT_MIN, -1, 0, 33554432, 33554433, PHP_INT_MAX]) : $size;
                $inflated = $random->getInt(0, 5) === 0 ? $this->pick($random, [PHP_INT_MIN, -1, 0, 268435456, 268435457, PHP_INT_MAX]) : $inflated;
            }
            $stored[] = $digest;
            $computed[] = $other;
            $compressed[] = $size;
            $decoded[] = $inflated;
        }

        return [$stored, $computed, $compressed, $decoded, DatabaseNzbStore::MAX_COMPRESSED_BYTES, DatabaseNzbStore::MAX_DECODED_BYTES];
    }

    /** @return array{list<string>, list<string>, list<int>, list<bool>, list<bool>} */
    private function manifestBatch(Randomizer $random, bool $uncommon): array
    {
        $states = $guids = $ids = $hasNfo = $relinquished = [];
        $recent = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $state = $random->getInt(0, 2) === 0 ? $this->pick($random, self::STATES) : NzbUploadManifestService::STATE_NZB_IMPORTED;
            $guid = $recent !== [] && $random->getInt(0, 15) === 0 ? $this->pick($random, $recent) : bin2hex($random->getBytes(20));
            $recent[] = $guid;
            $id = $random->getInt(1, 5_000_000);
            if ($uncommon) {
                $state = match ($random->getInt(0, 6)) {
                    0 => '',
                    1 => strtoupper($state),
                    2 => $this->noise($random, 30),
                    3 => $state.' ',
                    default => $state,
                };
                $guid = match ($random->getInt(0, 6)) {
                    0 => '',
                    1 => $this->noise($random, 60, true),
                    2 => (string) $random->getInt(-5, 99),
                    default => $guid,
                };
                $id = $random->getInt(0, 4) === 0 ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX]) : $id;
            }
            $states[] = $state;
            $guids[] = $guid;
            $ids[] = $id;
            $hasNfo[] = $random->getInt(0, $uncommon ? 3 : 20) !== 0;
            $relinquished[] = $random->getInt(0, $uncommon ? 4 : 40) === 0;
        }

        return [$states, $guids, $ids, $hasNfo, $relinquished];
    }

    /** NZB file subjects in the shapes posters use, with their segment counts. */
    private function subject(Randomizer $random, bool $uncommon): string
    {
        $base = $this->pick($random, self::RELEASES);
        $name = match ($random->getInt(0, 9)) {
            0 => $base.'.par2',
            1, 2 => $base.'.vol'.sprintf('%02d', $random->getInt(0, 63)).'+'.sprintf('%02d', $random->getInt(1, 64)).'.par2',
            3 => $base.'.nfo',
            4 => $base.'.sfv',
            5 => $base.'.r'.sprintf('%02d', $random->getInt(0, 99)),
            default => $base.'.part'.sprintf('%02d', $random->getInt(1, 60)).'.rar',
        };
        $total = $random->getInt(1, 80);
        $index = $random->getInt(1, $total);
        $subject = match ($random->getInt(0, 3)) {
            0 => sprintf('[%02d/%02d] - "%s" yEnc (1/%d)', $index, $total, $name, $random->getInt(1, 400)),
            1 => sprintf('%s - [%d/%d] - "%s" yEnc (%d/%d)', $this->pick($random, ['[NZBgeek]', 'Group', '(Repost)']), $index, $total, $name, 1, $random->getInt(1, 400)),
            2 => sprintf('"%s" yEnc (1/%d)', $name, $random->getInt(1, 400)),
            default => $name.' yEnc ('.$index.'/'.$total.')',
        };
        if ($uncommon) {
            $subject = match ($random->getInt(0, 11)) {
                0 => '',
                1 => $subject."\n",
                2 => $this->noise($random, 200, true),
                3 => $this->pick($random, ['file_id.diz (1/1)', 'readme (1/1)', 'abc-def (1/1)', 'yEnc (12) [1/1]', 'x.exe (1/1)', '"x.PAR2"', 'nfo']),
                4 => strtoupper($subject),
                5 => str_replace('"', '', $subject),
                6 => ' '.$this->noise($random, 300).' '.$name.' '.$this->noise($random, 40),
                7 => $name,
                default => $subject,
            };
        }

        return $subject;
    }

    /** @return array{list<string>, list<int>} */
    private function subjectBatch(Randomizer $random, bool $uncommon): array
    {
        $subjects = $counts = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $subject = $this->subject($random, $uncommon);
            $subjects[] = $subject;
            $counts[] = $uncommon && $random->getInt(0, 4) === 0 ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 2, PHP_INT_MAX])
                : (str_contains($subject, '.nfo') || str_contains($subject, '(1/1)') ? $random->getInt(1, 2) : $random->getInt(1, 400));
        }

        return [$subjects, $counts];
    }
}
