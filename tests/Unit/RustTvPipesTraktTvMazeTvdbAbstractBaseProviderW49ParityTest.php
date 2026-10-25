<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\Pipes\TraktPipe;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\BaseVideoProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class RustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49ParityTest extends TestCase
{
    /** Per class: the batch entry and its Rust export. */
    private const TARGETS = [
        TraktPipe::class => ['w49IdsBatch', 'nntmux_w49_tv_trakt_ids_batch'],
        TvMazePipe::class => ['w49EpisodeBatch', 'nntmux_w49_tv_tvmaze_episode_batch'],
        TvdbPipe::class => ['w49SearchBatch', 'nntmux_w49_tv_tvdb_search_batch'],
        AbstractTvProvider::class => ['w49ShowFieldsBatch', 'nntmux_w49_tv_provider_show_fields_batch'],
        BaseVideoProvider::class => ['w49TitlePlanBatch', 'nntmux_w49_video_title_plan_batch'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        TraktPipe::class => true,
        TvMazePipe::class => true,
        TvdbPipe::class => true,
        AbstractTvProvider::class => false,
        BaseVideoProvider::class => true,
    ];

    private const WORDS = ['Show', 'Name', 'The', 'Office', 'and', 'AND', 'er', 'ER', '.', ' ', ' ', '_', '-', '(', ')', '(2019)', ' (2020)',
        ' (1899)', '(19', "'", '!', 'S01E02', '2024', 'US', "\xC3\xA9", "\xff", '0', "\t", "\n", "\r", "\x0B", "\x0C", 'tt', 'Theater'];

    private const COUNTRIES = ['US', 'us', 'uk', 'UK', 'GB', 'Germany', '', ' ', ' de ', 'U', 'USA', "\xC3\xA9x", "''"];

    private const IMDB = ['tt0944947', 'TT1234567', 'tt12345', '0944947', '0', '', "''", 'tt', 'xtt1234567', 'tt12tt1234567', 'tt1234567x',
        'tt12345678901234567890', '1234567890123456789', ' tt0944947 ', "tt0944947\n", 'nm0000001'];

    private const DATES = ['2020-01-02', '2024-02-29', '2023-02-29', '1900-02-29', '2000-02-29', '0000-01-01', '2020-13-01', '2020-00-10',
        '2020-01-32', '2020-1-2', ' 2021-06-30 ', "2021-06-30\n", '2021-06-30T00:00:00Z', '', 'TBA', '20200102', '9999-12-31'];

    private const TYPES = ['regular', 'significant_special', 'insignificant_special', '', 'Regular', 'special', "regular\n"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w49_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW49RustFfi', 'withW49RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic()) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W49_RUST_FFI_DEFAULT', 'W49_FFI_CDEF'] as $name) {
                if (! $reflection->hasConstant($name)) {
                    $missing[] = $class.'::'.$name;
                }
            }
            if (! $reflection->hasProperty('useW49RustFfi') || ! $reflection->getProperty('useW49RustFfi')->isPrivate()) {
                $missing[] = $class.'::$useW49RustFfi';
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_w49_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW49RustFfi(), $class);
            $this->assertTrue($class::withW49RustFfi(true, static fn (): bool => $class::usesW49RustFfi()));
            $this->assertFalse($class::withW49RustFfi(false, static fn (): bool => $class::usesW49RustFfi()));
            $this->assertTrue($class::withW49RustFfi(false, static fn (): bool => $class::withW49RustFfi(true, static fn (): bool => $class::usesW49RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW49RustFfi(), $class);
            try {
                $class::withW49RustFfi(! $class::usesW49RustFfi(), static fn () => throw new RuntimeException('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW49RustFfi(), $class.' restores after an exception');
        }

        // The toggles are independent between the provider base classes.
        AbstractTvProvider::withW49RustFfi(false, function (): void {
            $this->assertSame(self::DEFAULTS[BaseVideoProvider::class], BaseVideoProvider::usesW49RustFfi());
        });

        $titles = ['Law and Order', 'Zorro (1990)', ''];
        $expected = BaseVideoProvider::w49TitlePlanBatchPhp($titles);
        foreach ([true, false] as $enabled) {
            $this->assertSame($expected, BaseVideoProvider::withW49RustFfi($enabled, static fn (): array => BaseVideoProvider::w49TitlePlanBatch($titles)));
        }
    }

    public function test_w49_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [, $symbol]) {
            $ffi = RustPipelineLibrary::resolveDomain('categorize_w49', \constant($class.'::W49_FFI_CDEF'));
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $this->assertStringContainsString($symbol, \constant($class.'::W49_FFI_CDEF'));
        }

        $ffi = RustPipelineLibrary::resolveDomain('categorize_w49', BaseVideoProvider::W49_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[16]');
        // @phpstan-ignore method.notFound
        $this->assertSame(7, $ffi->nntmux_w49_video_title_plan_batch('Zorro (1990)', 12, '', 0, 1, $out, 16));
        $this->assertSame(BaseVideoProvider::w49TitlePlanBatchPhp(['Zorro (1990)']), array_values((array) unpack('P*', FFI::string($out, 56))));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w49_video_title_plan_batch("a\0b", 3, '', 0, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-7, $ffi->nntmux_w49_video_title_plan_batch('Zorro', 5, '', 0, 1, $out, 6));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w49_video_title_plan_batch('Zorro', 5, '', 0, 1, null, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w49_video_title_plan_batch(null, 5, '', 0, 1, $out, 16));

        $ffi = RustPipelineLibrary::resolveDomain('categorize_w49', TvdbPipe::W49_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w49_tv_tvdb_search_batch('q', 1, '1234567', 7, 0, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_w49_tv_tvdb_search_batch('q', 1, pack('q', 2020), 8, 0, $out, 16));
    }

    public function test_trakt_ids_parity(): void
    {
        $this->assertSame([0, 0, -1], TraktPipe::w49IdsBatchPhp([]));
        $this->assertSame([0, 0, -1], TraktPipe::w49IdsBatchNative([]));
        $fixed = [
            ['title' => 'Show (2019)', 'first_aired' => '2019-05-01T00:00:00.000Z', 'imdb' => 'tt0944947', 'year' => 1, 'trakt' => 5,
                'tvdb' => 6, 'tmdb' => 7, 'tvrage' => 8],
            ['title' => '', 'year' => 30000, 'trakt' => -1, 'imdb' => 'tt12'],
            ['title' => 'The Office (US)', 'first_aired' => '2005-3-24', 'year' => 2005, 'trakt' => 1, 'tvdb' => 1, 'tmdb' => 1, 'imdb' => 'xTT12tt1234567'],
            ['title' => 'Show (1899)', 'first_aired' => '20050324', 'year' => -5, 'imdb' => 'tt12345678901234567890'],
            [],
            ['title' => "Caf\xC3\xA9 (2020)", 'imdb' => "tt0944947\n", 'year' => PHP_INT_MAX, 'trakt' => PHP_INT_MAX, 'tvrage' => PHP_INT_MIN],
        ];
        $php = TraktPipe::w49IdsBatchPhp($fixed);
        $this->assertSame([5, 944947, 2019, 63, $this->fnv('show')], \array_slice($php, 0, 5));
        $this->assertSame([0, 0, 9999, 64, $this->fnv('')], \array_slice($php, 5, 5));
        $this->assertSame([1, 1234567, 2005, 15, $this->fnv('the office us')], \array_slice($php, 10, 5));
        $this->assertSame([0, -1, 0, 0, $this->fnv('show 1899')], \array_slice($php, 15, 5));
        $this->assertSame([PHP_INT_MAX, 0, 9999, 33, $this->fnv('caf')], \array_slice($php, 25, 5));
        $this->assertSame([3, 2, 0], \array_slice($php, 30));

        $mixed = [['title' => 42, 'first_aired' => null, 'imdb' => 1234567, 'year' => 2005.7, 'trakt' => '12abc', 'tvdb' => null, 'tmdb' => true,
            'tvrage' => ' 7'], ['title' => 1.5, 'first_aired' => false, 'imdb' => 'tt1234567', 'year' => '1e3', 'trakt' => 'abc', 'tvdb' => 3,
                'tmdb' => false, 'tvrage' => null]];
        $batches = [$fixed, $mixed, [['title' => "nul\0"]], [['imdb' => "\0"]], [['first_aired' => "2020-01-01\0"]]];
        $random = new Randomizer(new Mt19937(4931));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = $this->traktItems($random, $size, $noisy);
            }
        }
        foreach ($batches as $i => $items) {
            $this->assertParity(TraktPipe::class, [$items], "batch {$i}");
        }
    }

    public function test_tvmaze_episode_parity(): void
    {
        $this->assertSame([0, 0, 0, 0, 0], TvMazePipe::w49EpisodeBatchPhp([]));
        $this->assertSame([0, 0, 0, 0, 0], TvMazePipe::w49EpisodeBatchNative([]));
        $fixed = [
            ['airdate' => '2020-01-02', 'type' => 'regular', 'season' => 1, 'number' => 2],
            ['airdate' => ' 2020-01-02 ', 'type' => 'significant_special', 'season' => 0, 'number' => 0],
            ['airdate' => '', 'type' => 'x', 'season' => 1, 'number' => 0],
            ['airdate' => '2020-01-02', 'season' => 1, 'number' => 2],
            ['airdate' => 'bad', 'type' => '', 'season' => -3, 'number' => 0],
            ['airdate' => '2024-02-29', 'type' => 'insignificant_special', 'season' => 4, 'number' => -1],
            ['airdate' => '2023-02-29', 'type' => 'Regular', 'season' => PHP_INT_MAX, 'number' => PHP_INT_MAX],
            [],
            ['airdate' => "2021-06-30\n", 'season' => PHP_INT_MIN, 'number' => 1],
        ];
        $php = TvMazePipe::w49EpisodeBatchPhp($fixed);
        $this->assertSame([1, 2, 20200102, 1, 0, 0, 0, 20200102, 2, 1, 1, 0, 0, 0, 3, 1, 2, 20200102, 3, 0, 0, 0, 0, 0, 0], \array_slice($php, 0, 25));
        $this->assertSame([4, 0, 20240229, 2, 2, PHP_INT_MAX, PHP_INT_MAX, 0, 1, 3, 0, 0, 0, 0, 0, 0, 1, 20210630, 1, 0], \array_slice($php, 25, 20));
        $this->assertSame([3, 2, 3, 1, PHP_INT_MAX], \array_slice($php, 45));

        $mixed = [['airdate' => null, 'type' => null, 'season' => '3x', 'number' => 4.9], ['airdate' => 20200102, 'type' => false, 'season' => true,
            'number' => '1e1']];
        $batches = [$fixed, $mixed, [['airdate' => "\0"]], [['type' => "regular\0"]]];
        $random = new Randomizer(new Mt19937(4932));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = $this->tvMazeItems($random, $size, $noisy);
            }
        }
        foreach ($batches as $i => $items) {
            $this->assertParity(TvMazePipe::class, [$items], "batch {$i}");
        }
    }

    public function test_tvdb_search_parity(): void
    {
        foreach (['', 'Show', "a\0b"] as $query) {
            $this->assertSame([-1, 0, 0], TvdbPipe::w49SearchBatchPhp([], $query, 2020));
            $this->assertSame([-1, 0, 0], TvdbPipe::w49SearchBatchNative([], $query, 2020));
        }
        $fixed = [
            ['tvdb_id' => 'series-73244', 'name' => 'The Office', 'year' => '2005'],
            ['tvdb_id' => '12', 'name' => 'Office Hours', 'first_air_time' => '2006-01-01'],
            ['tvdb_id' => 'x', 'name' => 'The Office'],
            ['tvdb_id' => '7', 'name' => 'The.Office.US', 'year' => '05', 'first_air_time' => '2004'],
            ['tvdb_id' => 'Series-5', 'name' => 'Office'],
            ['tvdb_id' => '0000000000000000009', 'name' => 'the office'],
            ['tvdb_id' => '000000000000000009', 'name' => 'THE OFFICE', 'year' => '2005'],
            [],
            ['tvdb_id' => 'series-', 'name' => '...', 'year' => '20055'],
        ];
        $php = TvdbPipe::w49SearchBatchPhp($fixed, 'The Office (2005)', 2005);
        $this->assertSame([73244, 2005, 3, 320, 12, 2006, 1, 105, 0, 0, 3, 300, 7, 2004, 2, 205], \array_slice($php, 0, 16));
        $this->assertSame([0, 0, 1, 100, 0, 0, 3, 300, 9, 2005, 3, 320, 0, 0, 0, 0, 0, 0, 0, 0], \array_slice($php, 16, 20));
        $this->assertSame([0, 4, 4], \array_slice($php, 36));
        $this->assertSame(1, TvdbPipe::w49SearchBatchPhp($fixed, 'Office Hours', 2006)[36]);

        $batches = [[$fixed, 'The Office (2005)', 2005], [$fixed, '', 0], [$fixed, 'Office', PHP_INT_MIN], [$fixed, 'office', PHP_INT_MAX],
            [[['name' => "nul\0"]], 'x', 0], [$fixed, "q\0", 0]];
        $random = new Randomizer(new Mt19937(4933));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = $this->tvdbArgs($random, $size, $noisy);
            }
        }
        foreach ($batches as $i => $args) {
            $this->assertParity(TvdbPipe::class, $args, "batch {$i}");
        }
    }

    public function test_abstract_tv_provider_show_fields_parity(): void
    {
        $this->assertSame([0, 0, 0, 0], AbstractTvProvider::w49ShowFieldsBatchPhp([]));
        $this->assertSame([0, 0, 0, 0], AbstractTvProvider::w49ShowFieldsBatchNative([]));
        $fixed = [
            ['imdb' => 'tt0944947', 'country' => 'uk', 'summary' => 'Sum', 'publisher' => 'HBO', 'localzone' => 'America/New_York', 'tvdb' => 1,
                'tmdb' => 2],
            ['imdb' => 'abc', 'country' => 'Germany', 'summary' => "''", 'publisher' => ' ', 'localzone' => 'UTC'],
            ['imdb' => ' 0 ', 'country' => ' us ', 'trakt' => 3, 'tvrage' => -1, 'tvmaze' => PHP_INT_MAX],
            ['imdb' => '12345', 'country' => 'U'],
            ['imdb' => 1234567, 'country' => "\xC3\xA9"],
            ['imdb' => 'TT1234567890123456789', 'country' => "''"],
            [],
            ['imdb' => "''", 'summary' => "\x0B\n", 'publisher' => "\x0C"],
        ];
        $php = AbstractTvProvider::w49ShowFieldsBatchPhp($fixed);
        $this->assertSame([944947, (\ord('G') << 8) | \ord('B'), 17, 7], \array_slice($php, 0, 4));
        $this->assertSame([-1, -1, 0, 4, 0, (\ord('U') << 8) | \ord('S'), 10, 0, -1, -1, 0, 0], \array_slice($php, 4, 12));
        $this->assertSame([1234567, (0xC3 << 8) | 0xA9, 0, 0, -1, (\ord("'") << 8) | \ord("'"), 0, 0, 0, 0, 0, 0, 0, 0, 0, 2], \array_slice($php, 16, 16));
        $this->assertSame([2, 2, 27, 1], \array_slice($php, 32));

        $mixed = [['imdb' => 944947.0, 'country' => null, 'summary' => 0, 'publisher' => 1.25, 'localzone' => true, 'tvdb' => '5', 'trakt' => 2.9,
            'tvrage' => null, 'tvmaze' => false, 'tmdb' => '1e2']];
        $batches = [$fixed, $mixed, [['summary' => "a\0"]], [['country' => "\0"]]];
        $random = new Randomizer(new Mt19937(4934));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = $this->showItems($random, $size, $noisy);
            }
        }
        foreach ($batches as $i => $items) {
            $this->assertParity(AbstractTvProvider::class, [$items], "batch {$i}");
        }
    }

    public function test_base_video_provider_title_plan_parity(): void
    {
        $this->assertSame([0, 0, 0], BaseVideoProvider::w49TitlePlanBatchPhp([]));
        $this->assertSame([0, 0, 0], BaseVideoProvider::w49TitlePlanBatchNative([]));
        $fixed = ['Law and Order', 'Zorro (1990)', '', 'Zorro 1990', 'Theater', "Bob's Burgers!", ' (1990)', "A\n (1990)", "A\nB (1990)",
            "x\r(2000)", 'Show(2020)', 'Show (20201)', 'Law AND and Order', 'Silent Witness (1996)', "\n(1990)", 'a  b', "Caf\xC3\xA9 and er"];
        $php = BaseVideoProvider::w49TitlePlanBatchPhp($fixed);
        $this->assertSame([29, 3, $this->fnv('Law and Order'), $this->fnv("%Law%&%Order%\x1F%Law%and%Ordre%")], \array_slice($php, 0, 4));
        $this->assertSame([19, 2, $this->fnv('Zorro'), $this->fnv('%Zorro%(1990)%')], \array_slice($php, 4, 4));
        $this->assertSame([0, 1, $this->fnv(''), $this->fnv('')], \array_slice($php, 8, 4));
        $this->assertSame([25, 1, $this->fnv('Theater'), $this->fnv('%Theatre%')], \array_slice($php, 16, 4));
        $this->assertSame([25, 2, $this->fnv("Bob's Burgers!"), $this->fnv('%Bobs%Burgres%')], \array_slice($php, 20, 4));
        $this->assertSame([19, 2, $this->fnv(''), $this->fnv('%%(1990)%')], \array_slice($php, 24, 4));
        $this->assertSame([19, 2, $this->fnv('A')], \array_slice($php, 28, 3));
        $this->assertSame([17, 2, $this->fnv("A\nB (1990)")], \array_slice($php, 32, 3));
        $this->assertSame([3, 1, $this->fnv('x')], \array_slice($php, 36, 3));
        $this->assertSame([3, 1, $this->fnv('Show')], \array_slice($php, 40, 3));
        $this->assertSame([1, 1, $this->fnv("\n(1990)")], \array_slice($php, 56, 3));
        $this->assertSame([13, 3, 4], \array_slice($php, 68));

        $batches = [$fixed, [''], ["nul\0"], ["\0", 'x']];
        $random = new Randomizer(new Mt19937(4935));
        foreach ([64, 128, 256] as $size) {
            $batches[] = $this->titles($random, $size, false);
            $batches[] = $this->titles($random, $size, true);
        }
        foreach ($batches as $i => $titles) {
            $this->assertParity(BaseVideoProvider::class, [$titles], "batch {$i}");
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including packing, the FFI
        // call and decoding (release dylib, 5 warmups, 401 paired samples per helper and workload, 20 iterations per
        // common sample and 10 per uncommon sample). Common = one 64-128 item batch of typical provider payloads;
        // uncommon = 256 long, noisy and malformed payloads. Speedup = PHP latency / Rust latency, ranges over 3 rounds
        // (PHP 8.5.12 NTS + OPcache, release dylib, Apple Silicon, shared host at load average ~9-10):
        // - TraktPipe:          common mean 2.76-2.86x / p99 2.26-3.04x, uncommon mean 2.52-2.59x / p99 2.13-2.20x
        // - TvMazePipe:         common mean 3.05-3.15x / p99 2.56-3.10x, uncommon mean 2.77-2.83x / p99 2.45-2.60x
        // - TvdbPipe:           common mean 3.10-3.36x / p99 3.12-3.96x, uncommon mean 3.65-3.71x / p99 3.37-3.84x
        // - AbstractTvProvider: common mean 1.97-2.04x / p99 1.33-2.18x, uncommon mean 1.85-2.00x / p99 1.41-2.13x
        // - BaseVideoProvider:  common mean 5.49-5.61x / p99 5.25-5.58x, uncommon mean 2.73-2.78x / p99 2.48-2.65x
        // TraktPipe, TvMazePipe, TvdbPipe and BaseVideoProvider clear every gate in every round and default to Rust;
        // AbstractTvProvider's p99 can dip below 1.5x under load so it retains PHP by default.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every enabled default to clear 1.5x.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49ParityTest.php --filter benchmark
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(4936));
        $warmup = 5;
        $samples = 401;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(4937)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(4938)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW49RustFfi($useRust, static fn (): array => $class::$entry(...$args)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            if ($class::usesW49RustFfi()) {
                $this->assertTrue($qualifies, $class.' defaults to Rust and must clear 1.5x on every gate: '.$summary);
            } else {
                $this->assertFalse($class::usesW49RustFfi(), $class.' retains PHP by default: '.$summary);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}(...$args);
        $this->assertSame($php, $class::{$entry.'Native'}(...$args), $class.' '.$message);
        $this->assertSame($php, $class::withW49RustFfi(true, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
        $this->assertSame($php, $class::withW49RustFfi(false, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
    }

    /**
     * Common: typical batches (96 Trakt results, 128 TVMaze episodes, 64 TVDB results, 96 show payloads, 128 titles).
     * Uncommon: 256 items with long, noisy or malformed strings and out-of-range numbers.
     *
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            TraktPipe::class => [$this->traktItems($random, $uncommon ? 256 : 96, $uncommon)],
            TvMazePipe::class => [$this->tvMazeItems($random, $uncommon ? 256 : 128, $uncommon)],
            TvdbPipe::class => $this->tvdbArgs($random, $uncommon ? 256 : 64, $uncommon),
            AbstractTvProvider::class => [$this->showItems($random, $uncommon ? 256 : 96, $uncommon)],
            default => [$this->titles($random, $uncommon ? 256 : 128, $uncommon)],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function traktItems(Randomizer $random, int $size, bool $noisy): array
    {
        return array_map(fn (): array => [
            'title' => $noisy ? $this->randomName($random, 10, 40) : $this->showTitle($random).$this->pick($random, ['', '', ' (2019)', ' (1999)']),
            'first_aired' => $noisy ? $this->pick($random, self::DATES) : $random->getInt(1990, 2026).'-0'.$random->getInt(1, 9).'-1'.$random->getInt(0, 9).'T01:00:00.000Z',
            'imdb' => $noisy ? $this->pick($random, self::IMDB) : 'tt'.str_pad((string) $random->getInt(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'year' => $noisy ? $random->getInt(-50, 20000) : $random->getInt(1990, 2026),
            'trakt' => $random->getInt($noisy ? -5 : 0, 200_000),
            'tvdb' => $random->getInt($noisy ? -5 : 0, 400_000),
            'tmdb' => $random->getInt($noisy ? -5 : 0, 200_000),
            'tvrage' => $random->getInt($noisy ? -5 : 0, 1) * $random->getInt(0, 50_000),
        ], range(1, $size));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tvMazeItems(Randomizer $random, int $size, bool $noisy): array
    {
        return array_map(fn (): array => [
            'airdate' => $noisy ? $this->pick($random, self::DATES).$this->pick($random, ['', ' ', "\t"])
                : $random->getInt(2000, 2026).'-'.str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'-'
                    .str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT),
            'type' => $noisy ? $this->pick($random, self::TYPES) : $this->pick($random, ['regular', 'regular', 'regular', 'significant_special']),
            'season' => $random->getInt($noisy ? -2 : 1, $noisy ? 40 : 8),
            'number' => $random->getInt($noisy ? -2 : 0, $noisy ? 40 : 24),
        ], range(1, $size));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string, 2: int}
     */
    private function tvdbArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $query = $noisy ? $this->randomName($random, 4, 20) : $this->showTitle($random).$this->pick($random, ['', ' (2005)']);
        $items = array_map(fn (): array => [
            'tvdb_id' => $noisy ? $this->pick($random, ['series-', 'Series-', '', 'x', 'series-'.$random->getInt(0, 999_999), str_repeat('9', 19)])
                : 'series-'.$random->getInt(1, 450_000),
            'name' => $noisy ? $this->randomName($random, 4, 30) : $this->showTitle($random),
            'year' => $noisy ? $this->pick($random, ['', '05', '2005', '20055', 'abcd']) : (string) $random->getInt(1995, 2026),
            'first_air_time' => $noisy ? $this->pick($random, self::DATES) : $random->getInt(1995, 2026).'-01-01',
        ], range(1, $size));

        return [$items, $query, $noisy ? $random->getInt(-5, 20000) : $random->getInt(0, 1) * $random->getInt(1995, 2026)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function showItems(Randomizer $random, int $size, bool $noisy): array
    {
        return array_map(fn (): array => [
            'imdb' => $noisy ? $this->pick($random, self::IMDB) : $this->pick($random, ['tt'.$random->getInt(1_000_000, 9_999_999), '', '0']),
            'country' => $noisy ? $this->pick($random, self::COUNTRIES) : $this->pick($random, ['US', 'GB', 'UK', 'CA', '']),
            'summary' => $noisy ? $this->randomName($random, 10, 60) : $this->randomName($random, 4, 12),
            'publisher' => $noisy ? $this->randomName($random, 0, 6) : $this->pick($random, ['HBO', 'BBC One', 'Netflix', '']),
            'localzone' => $noisy ? $this->pick($random, ['', "''", ' ', 'UTC', "\tEurope/London\n"]) : $this->pick($random, ['America/New_York', 'Europe/London', '']),
            'tvdb' => $random->getInt($noisy ? -5 : 0, 400_000),
            'trakt' => $random->getInt($noisy ? -5 : 0, 200_000),
            'tvrage' => $random->getInt($noisy ? -5 : 0, 1) * $random->getInt(0, 50_000),
            'tvmaze' => $random->getInt($noisy ? -5 : 0, 70_000),
            'tmdb' => $random->getInt($noisy ? -5 : 0, 200_000),
        ], range(1, $size));
    }

    /**
     * @return list<string>
     */
    private function titles(Randomizer $random, int $size, bool $noisy): array
    {
        return array_map(fn (): string => $noisy
            ? $this->randomName($random, 8, 40)
            : $this->showTitle($random).$this->pick($random, ['', '', ' (2019)', ' and Friends', "'s Place"]), range(1, $size));
    }

    private function showTitle(Randomizer $random): string
    {
        return $this->pick($random, ['The Office', 'Law and Order', 'Silent Witness', 'Doctor Who', 'Theater of Blood', "Bob's Burgers",
            'Monster Hunter', 'Grey', 'Stranger Things', 'The Mandalorian', 'Top Gear', 'Shameless US', 'Better Call Saul']);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionClass($class))->setStaticPropertyValue('useW49RustFfi', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W49_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w49', BaseVideoProvider::W49_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    private function fnv(string $bytes): int
    {
        return (int) hexdec(hash('fnv1a32', $bytes));
    }

    /**
     * @param  list<string>  $values
     */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function randomName(Randomizer $random, int $minWords, int $maxWords): string
    {
        $name = '';
        for ($i = $random->getInt($minWords, $maxWords); $i > 0; $i--) {
            $name .= self::WORDS[$random->getInt(0, \count(self::WORDS) - 1)];
        }

        return $name;
    }
}
