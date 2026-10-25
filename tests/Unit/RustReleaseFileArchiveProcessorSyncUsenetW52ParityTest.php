<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class RustReleaseFileArchiveProcessorSyncUsenetW52ParityTest extends TestCase
{
    /** Per class: the batch entry and its Rust export. */
    private const TARGETS = [
        ReleaseFileManager::class => ['w52FileNameSizeBatch', 'nntmux_w523_release_file_name_size_batch'],
        ReleaseFilesArchiveFallback::class => ['w52ArchiveEntryBatch', 'nntmux_w523_archive_entry_batch'],
        ReleaseProcessor::class => ['w52SubjectClassBatch', 'nntmux_w523_release_subject_class_batch'],
        ReleaseSearchSyncCoordinator::class => ['w52DecideSequenceBatch', 'nntmux_w523_search_sync_decide_batch'],
        UsenetDownloadService::class => ['w52MappedArticleKindBatch', 'nntmux_w523_mapped_article_kind_batch'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        ReleaseFileManager::class => true,
        ReleaseFilesArchiveFallback::class => true,
        ReleaseProcessor::class => true,
        ReleaseSearchSyncCoordinator::class => true,
        UsenetDownloadService::class => true,
    ];

    private const RELEASE = 'Show.Name.S01E02.720p.HDTV.x264-GRP';

    /** Archive member, NFO and PAR2 names, including the shapes each matcher treats specially. */
    private const NAMES = ['Show.Name.S01E02.720p.HDTV.x264-GRP.mkv', 'Show.Name.S01E02.720p.HDTV.x264-GRP/grp-show.nfo', '00-grp.nfo', '000-grp.NFO',
        'grp.nfo', '.x-y.nfo', 'a/00-grp.nfo/', 'x.info', "x.info\n", "x.nfo\n\n", 'FILE_ID.DIZ', 'file-id.diz', 'readme.txt', 'Information.TXT',
        'notes.txt', 'myinfo.txt', 'info.txt.bak', 'x.inf', 'x.ofn', 'Sample/grp-sample.mkv', 'movie.sample.MKV', 'cover.jpg', 'cover.JPEG',
        'Cover.Png', 'shot.webp', "shot.webp\n", 'shot.gif', 'x.tar.gz', 'noext', 'dir.d/noext', '.hidden', 'trail.', 'a.jpg/', 'a/b.JPG//', '',
        '.', '..', 'x.part01.rar', 'x.part1.rar', 'x.r00', 'x.rar', 'x.RAR', "x.rar\n", 'x.zip', 'x.7z', 'x.7z.001', 'x.7Z.12', 'x.001', 'x.0001',
        'x.12', 'x.par2', 'x.vol00+01.par2', 'x.sfv', 'x.srr', 'x.nzb', 'movie.m2ts', 'movie.MPEG', 'movie.mpgv', 'movie.ts', 'movie.rmvb',
        'movie.mkv.part', "na\xefve.nfo", "\xff\xfe.rar", 'C:\\dir\\x.nfo', 'dir\\info.txt', 'a.very.long.name.with.many.dots.and.segments.that.keeps.going.for.a.while.to.cross.typical.buffer.sizes.mkv'];

    private const SIZES = ['0', '1', '12', '007', '000', '1048576', '9223372036854775807', '9223372036854775808', '00009223372036854775807',
        '99999999999999999999', '', ' 1', '1 ', "1\n", '-1', '+1', '1.5', '1e3', 'abc', '0x10'];

    /** NZB subjects as Usenet posts them, plus malformed and boundary shapes. */
    private const SUBJECTS = ['[01/50] - "Show.Name.S01E02.720p.HDTV.x264-GRP.part01.rar" yEnc (1/137)',
        '[02/50] - "Show.Name.S01E02.720p.HDTV.x264-GRP.part02.rar" yEnc (1/137)', '"Show.Name.S01E02.720p.HDTV.x264-GRP.mkv" yEnc (1/2000)',
        '"Show.Name.S01E02.720p.HDTV.x264-GRP-sample.mkv" yEnc (1/20)', '"sample.jpg" yEnc (1/1)', '"Sample.rar" yEnc', '"x.par2" yEnc (1/1)',
        '"x.vol00+01.par2" yEnc (1/3)', '"x.vol000+001.PAR2"', '"x.nfo" yEnc (1/1)', '"x.nfo" yEnc (1/1) and twenty more chars', '"x.sfv"', '"x.srs"',
        '"x.nzb"', 'x.inf', 'x.ofn-]', '"x.avi"', 'x.AVI', 'x.mp4]', 'x.mp4x', '"x.ts" yEnc', 'x.mkv.', 'x.mkv)', "x.mkv\n", 'x.mkv.sample',
        '"x.7z.001"', '"x.zip"', '"x.r01"', '"a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6.12" yEnc (1/15)', 'sample', 'SAMPLE.webp', 'nothing here', '', '"',
        '...', 'Show-Name-S01E02', 'show name s01e02 720p hdtv x264 grp', "na\xefve.mkv", "\xff\xfe sample"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w52_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW52RustFfi', 'withW52RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W52_RUST_FFI_DEFAULT', 'W52_FFI_CDEF'] as $name) {
                if (! $reflection->hasConstant($name)) {
                    $missing[] = $class.'::'.$name;
                }
            }
            if (! $reflection->hasProperty('useW52RustFfi') || ! $reflection->getProperty('useW52RustFfi')->isPrivate()) {
                $missing[] = $class.'::$useW52RustFfi';
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w52_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW52RustFfi(), $class);
            $this->assertTrue($class::withW52RustFfi(true, static fn (): bool => $class::usesW52RustFfi()));
            $this->assertFalse($class::withW52RustFfi(false, static fn (): bool => $class::usesW52RustFfi()));
            $this->assertTrue($class::withW52RustFfi(false, static fn (): bool => $class::withW52RustFfi(true, static fn (): bool => $class::usesW52RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW52RustFfi(), $class);
            try {
                $class::withW52RustFfi(! $class::usesW52RustFfi(), static fn () => throw new RuntimeException('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW52RustFfi(), $class.' restores after an exception');
        }

        // Each class keeps an independent toggle, separate from the earlier waves.
        ReleaseProcessor::withW52RustFfi(! ReleaseProcessor::usesW52RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[UsenetDownloadService::class], UsenetDownloadService::usesW52RustFfi());
            $this->assertSame(ReleaseProcessor::W406_RUST_FFI_DEFAULT, ReleaseProcessor::usesW406RustFfi());
        });
        ReleaseFileManager::withW52RustFfi(! ReleaseFileManager::usesW52RustFfi(), function (): void {
            $this->assertSame(ReleaseFileManager::W426_RUST_FFI_DEFAULT, ReleaseFileManager::usesW426RustFfi());
        });
    }

    public function test_w52_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [, $symbol]) {
            $cdef = \constant($class.'::W52_FFI_CDEF');
            $this->assertStringContainsString($symbol, $cdef);
            $this->assertInstanceOf(FFI::class, RustPipelineLibrary::resolveDomain('categorize_w52', $cdef), $class);
        }

        $ffi = RustPipelineLibrary::resolveDomain('categorize_w52', UsenetDownloadService::W52_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[16]');
        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_w523_mapped_article_kind_batch('a.rar', 5, null, 0, 1, $out, 16));
        $this->assertSame(UsenetDownloadService::w52MappedArticleKindBatchPhp(['a.rar']), array_values((array) unpack('P*', FFI::string($out, 24))));
        // A NUL inside a name, a short buffer, a null output, a null blob and a ragged integer buffer all decline.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w523_mapped_article_kind_batch("a\0b", 3, null, 0, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-3, $ffi->nntmux_w523_mapped_article_kind_batch('a.rar', 5, null, 0, 1, $out, 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w523_mapped_article_kind_batch('a.rar', 5, null, 0, 1, null, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w523_mapped_article_kind_batch(null, 5, null, 0, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w523_mapped_article_kind_batch('a.rar', 5, '1234567', 7, 1, $out, 16));
    }

    public function test_w52_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        $cases = [
            ReleaseFileManager::class => [["a.nfo\0movie.mkv\x0012\0x", '', 2, 16], [3, 12, 0, -1, 1, 1]],
            ReleaseFilesArchiveFallback::class => [["cover.JPG\0noext", '', 2, 16], [1 | 4 << 1, 0, 1, 1]],
            ReleaseProcessor::class => [["Show-GRP\0\"Show-GRP.mkv\" yEnc (1/50)", '', 1, 16], [1 | 8, 1, 0, 0, 1]],
            ReleaseSearchSyncCoordinator::class => [['', pack('q*', 1, 0, 0, 0, 0, 1, 7, 7), 2, 16], [2, 0, 3, 0, 1, 7, 1]],
            UsenetDownloadService::class => [["a.rar\0movie.MKV\0movie.sample.mkv", '', 3, 16], [1, 2, 0, 1, 1]],
        ];
        foreach ($cases as $class => [$args, $expected]) {
            $this->assertSame($expected, (new ReflectionMethod($class, 'w52Invoke'))->invoke(null, ...$args), $class);
        }
        $this->assertSame($cases[ReleaseFileManager::class][1], ReleaseFileManager::w52FileNameSizeBatchPhp(['a.nfo', 'movie.mkv'], ['12', 'x']));
        $this->assertSame($cases[ReleaseFilesArchiveFallback::class][1], ReleaseFilesArchiveFallback::w52ArchiveEntryBatchPhp(['cover.JPG', 'noext']));
        $this->assertSame($cases[ReleaseProcessor::class][1], ReleaseProcessor::w52SubjectClassBatchPhp(['"Show-GRP.mkv" yEnc (1/50)'], 'Show-GRP'));
        $this->assertSame($cases[ReleaseSearchSyncCoordinator::class][1], ReleaseSearchSyncCoordinator::w52DecideSequenceBatchPhp([0, 1], [7, 7]));
        $this->assertSame($cases[UsenetDownloadService::class][1], UsenetDownloadService::w52MappedArticleKindBatchPhp(['a.rar', 'movie.MKV', 'movie.sample.mkv']));
    }

    public function test_file_name_size_parity(): void
    {
        $this->assertSame([0, 0], ReleaseFileManager::w52FileNameSizeBatchPhp([], []));
        $this->assertSame([0, 0], ReleaseFileManager::w52FileNameSizeBatchNative([], []));
        $this->assertSame([0, -1, 0, 0], ReleaseFileManager::w52FileNameSizeBatchPhp([''], ['']));
        $names = [];
        $sizes = [];
        foreach (self::NAMES as $i => $name) {
            foreach (self::SIZES as $j => $size) {
                if (($i + $j) % 3 === 0) {
                    $names[] = $name;
                    $sizes[] = $size;
                }
            }
        }
        $this->assertParity(ReleaseFileManager::class, [$names, $sizes], 'edge rows');
        $this->assertParity(ReleaseFileManager::class, [[''], ['']], 'one empty row');
        $this->assertParity(ReleaseFileManager::class, [['a.nfo', 'b.nfo'], ['1']], 'short size column');
        $this->assertParity(ReleaseFileManager::class, [['a.nfo'], ['1', '2']], 'long size column');
        $this->assertParity(ReleaseFileManager::class, [[3 => 'a.nfo', 9 => 'b'], ['x' => '5', 'y' => '6']], 'sparse keys');
        $this->assertParity(ReleaseFileManager::class, [["a\0.nfo", 'b.nfo'], ['1', '2']], 'NUL name falls back');
        $this->assertParity(ReleaseFileManager::class, [['a.nfo'], ["1\0"]], 'NUL size falls back');

        $random = new Randomizer(new Mt19937(5231));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(ReleaseFileManager::class, $this->fileRowArgs($random, $random->getInt(1, 120), $round % 2 === 1), "round {$round}");
        }
    }

    public function test_archive_entry_parity(): void
    {
        $this->assertSame([0, 0], ReleaseFilesArchiveFallback::w52ArchiveEntryBatchPhp([]));
        $this->assertSame([0, 0], ReleaseFilesArchiveFallback::w52ArchiveEntryBatchNative([]));
        $this->assertSame([1 | 4 << 1, 3 << 1, 0, 0, 2 << 1, 1 | 5 << 1, 2, 4], ReleaseFilesArchiveFallback::w52ArchiveEntryBatchPhp(
            ['cover.JPG', 'dir/x.tar.gz', 'noext', 'trail.', 'dir.d/', 'a.webp'],
        ));
        $this->assertParity(ReleaseFilesArchiveFallback::class, [self::NAMES], 'edge names');
        $this->assertParity(ReleaseFilesArchiveFallback::class, [['']], 'one empty name');
        $this->assertParity(ReleaseFilesArchiveFallback::class, [['a.jpg', "b\0.png"]], 'NUL falls back');

        $random = new Randomizer(new Mt19937(5232));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(ReleaseFilesArchiveFallback::class, [$this->names($random, $random->getInt(1, 120), $round % 2 === 1)], "round {$round}");
        }
    }

    public function test_subject_class_parity(): void
    {
        $this->assertSame([0, 0, 0, 0], ReleaseProcessor::w52SubjectClassBatchPhp([], self::RELEASE));
        $this->assertSame([0, 0, 0, 0], ReleaseProcessor::w52SubjectClassBatchNative([], self::RELEASE));
        foreach ([self::RELEASE, '', '...', 'show name', "na\xefve", str_repeat('Show.Name.', 60)] as $release) {
            $this->assertParity(ReleaseProcessor::class, [self::SUBJECTS, $release], "edge subjects for {$release}");
        }
        $this->assertParity(ReleaseProcessor::class, [[''], ''], 'one empty subject');
        $this->assertParity(ReleaseProcessor::class, [[str_repeat('"Show.Name.S01E02.', 50).'mkv" yEnc'], self::RELEASE], 'long subject');
        $this->assertParity(ReleaseProcessor::class, [["x\0.mkv"], self::RELEASE], 'NUL subject falls back');
        $this->assertParity(ReleaseProcessor::class, [['x.mkv'], "GRP\0"], 'NUL release falls back');

        $random = new Randomizer(new Mt19937(5233));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(ReleaseProcessor::class, $this->subjectArgs($random, $random->getInt(1, 120), $round % 2 === 1), "round {$round}");
        }
    }

    /**
     * @param  list<int>  $ops
     * @param  list<int>  $ids
     * @param  list<int>  $expected
     */
    #[DataProvider('decideSequenceCases')]
    public function test_decide_sequence_cases(array $ops, array $ids, bool $coalesce, ?int $scope, bool $pending, array $expected): void
    {
        $this->assertSame($expected, ReleaseSearchSyncCoordinator::w52DecideSequenceBatchPhp($ops, $ids, $coalesce, $scope, $pending));
        $this->assertParity(ReleaseSearchSyncCoordinator::class, [$ops, $ids, $coalesce, $scope, $pending], 'case');
    }

    /**
     * @return array<string, array{list<int>, list<int>, bool, ?int, bool, list<int>}>
     */
    public static function decideSequenceCases(): array
    {
        return [
            'empty' => [[], [], true, null, false, [0, 0, 0]],
            'empty keeps scope' => [[], [], true, 5, true, [1, 5, 1]],
            'coalesced scope' => [[0, 1, 1, 2, 1, 3], [7, 7, 9, 7, 7, 0], true, null, false, [2, 0, 3, 0, 7, 9, 2, 0, 3, 0, 4, 7, 0, 0, 0]],
            'discarded scope syncs nothing' => [[0, 1, 2, 3], [4, 4, 4, 0], true, null, false, [2, 0, 3, 0, 2, 0, 0, 0, 0, 0, 0]],
            'immediate mode' => [[0, 1, 3], [4, 4, 4], false, 5, true, [3, 0, 7, 4, 3, 0, 1, 5, 1]],
            'zero and negative ids' => [[0, 1, 1, 3], [0, 0, -3, 0], true, null, false, [2, 0, 3, 0, 7, -3, 4, 0, 0, 0, 0]],
            'finish without scope' => [[3, 1], [0, 8], true, null, true, [0, 0, 4, 8, 0, 0, 0]],
            'extreme ids' => [[0, 1, 3], [PHP_INT_MAX, PHP_INT_MIN, 0], true, null, false, [2, 0, 6, PHP_INT_MIN, 0, 0, 0, 0, 0]],
        ];
    }

    public function test_decide_sequence_parity(): void
    {
        foreach ([[[9], [1]], [[0, -1], [1, 1]], [[1, 2], [1]]] as [$ops, $ids]) {
            foreach ([false, true] as $useRust) {
                try {
                    ReleaseSearchSyncCoordinator::withW52RustFfi($useRust, static fn (): array => ReleaseSearchSyncCoordinator::w52DecideSequenceBatch($ops, $ids));
                    $this->fail('expected an invalid sequence to throw');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertParity(ReleaseSearchSyncCoordinator::class, [[3 => 0, 8 => 1], ['a' => 4, 'b' => 4]], 'sparse keys');

        $random = new Randomizer(new Mt19937(5234));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(ReleaseSearchSyncCoordinator::class, $this->decideArgs($random, $random->getInt(1, 200), $round % 2 === 1), "round {$round}");
        }
    }

    public function test_mapped_article_kind_parity(): void
    {
        $this->assertSame([0, 0], UsenetDownloadService::w52MappedArticleKindBatchPhp([]));
        $this->assertSame([0, 0], UsenetDownloadService::w52MappedArticleKindBatchNative([]));
        $this->assertParity(UsenetDownloadService::class, [self::NAMES], 'edge names');
        $this->assertParity(UsenetDownloadService::class, [['']], 'one empty name');
        $this->assertParity(UsenetDownloadService::class, [['a.rar', "b\0.mkv"]], 'NUL falls back');
        $this->assertParity(UsenetDownloadService::class, [self::NAMES, '\.(MKV|MP4)'], 'custom regex stays on PHP');
        $this->assertSame([1, 2, 1, 1], UsenetDownloadService::w52MappedArticleKindBatch(['a.rar', 'b.mp4'], '\.(MP4)'));

        $random = new Randomizer(new Mt19937(5235));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(UsenetDownloadService::class, [$this->names($random, $random->getInt(1, 120), $round % 2 === 1)], "round {$round}");
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining,
        // packing, the FFI call and decoding (release dylib, 5 warmups, 401 paired samples per helper and workload,
        // 20 iterations per common sample and 10 per uncommon sample). Common = one typical batch (128 archive rows,
        // names or subjects; 128 search-sync events); uncommon = 256 long, noisy and malformed rows (256 events).
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5 NTS, release dylib, Apple Silicon,
        // shared host at load average ~4.5-5.4):
        // - ReleaseFileManager:           common mean 5.57-5.71x / p99 4.13-5.05x, uncommon mean 5.50-5.64x / p99 4.21-5.33x
        // - ReleaseFilesArchiveFallback:  common mean 3.35-3.41x / p99 2.54-3.05x, uncommon mean 3.50-3.56x / p99 3.03-3.39x
        // - ReleaseProcessor:             common mean 2.84-2.86x / p99 2.47-3.35x, uncommon mean 3.03-3.11x / p99 2.72-3.14x
        // - ReleaseSearchSyncCoordinator: common mean 3.19-3.51x / p99 2.67-10.23x, uncommon mean 3.36-3.49x / p99 2.70-3.00x
        // - UsenetDownloadService:        common mean 4.22-4.42x / p99 3.69-3.95x, uncommon mean 3.78-3.92x / p99 3.08-3.87x
        // Every helper clears every gate in every round and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustReleaseFileArchiveProcessorSyncUsenetW52ParityTest.php --filter benchmark
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5236));
        $warmup = 5;
        $samples = 401;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(5237)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(5238)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW52RustFfi($useRust, static fn (): array => $class::$entry(...$args)));
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
        fwrite(STDERR, "\nRustReleaseFileArchiveProcessorSyncUsenetW52 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW52RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
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
        $this->assertSame($php, $class::withW52RustFfi(true, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
        $this->assertSame($php, $class::withW52RustFfi(false, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
    }

    /**
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        $size = $uncommon ? 256 : 128;

        return match ($class) {
            ReleaseFileManager::class => $this->fileRowArgs($random, $size, $uncommon),
            ReleaseFilesArchiveFallback::class, UsenetDownloadService::class => [$this->names($random, $size, $uncommon)],
            ReleaseProcessor::class => $this->subjectArgs($random, $size, $uncommon),
            default => $this->decideArgs($random, $size, $uncommon),
        };
    }

    /**
     * A release's archive listing: episode volumes, NFO/SFV/PAR2 support files and sample/cover media; noisy
     * listings mix the edge-case names in and use long nested paths.
     *
     * @return list<string>
     */
    private function names(Randomizer $random, int $size, bool $noisy): array
    {
        $extensions = ['mkv', 'part01.rar', 'r00', 'r17', 'rar', 'nfo', 'sfv', 'par2', 'vol03+04.par2', 'jpg', 'png', 'srr', '7z.001', '001', 'mp4',
            'avi', 'txt', 'zip', 'nzb', 'webp'];
        $names = [];
        for ($i = 0; $i < $size; $i++) {
            if ($noisy && $random->getInt(0, 2) === 0) {
                $names[] = $this->pick($random, self::NAMES);

                continue;
            }
            $stem = 'Show.Name.S01E'.str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT).'.1080p.WEB.h264-GRP';
            $prefix = $noisy ? str_repeat('Deep.Folder.Name/', $random->getInt(1, 6)) : ($random->getInt(0, 3) === 0 ? 'Sample/' : '');
            $names[] = $prefix.$stem.($random->getInt(0, 9) === 0 ? '-sample' : '').'.'.$this->pick($random, $extensions);
        }

        return $names;
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function fileRowArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $sizes = [];
        for ($i = 0; $i < $size; $i++) {
            $sizes[] = $noisy && $random->getInt(0, 2) === 0 ? $this->pick($random, self::SIZES) : (string) $random->getInt(0, 4_000_000_000);
        }

        return [$this->names($random, $size, $noisy), $sizes];
    }

    /**
     * NZB subjects for one release: numbered volumes, PAR2 and NFO posts, samples and the main video; noisy
     * batches add the edge-case subjects and long obfuscated posts.
     *
     * @return array{list<string>, string}
     */
    private function subjectArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $extensions = ['part01.rar', 'part12.rar', 'r00', 'rar', 'nfo', 'sfv', 'par2', 'vol07+08.par2', 'mkv', 'jpg', '7z.001', 'zip', 'srs', 'nzb'];
        $subjects = [];
        for ($i = 0; $i < $size; $i++) {
            if ($noisy && $random->getInt(0, 2) === 0) {
                $subjects[] = $random->getInt(0, 1) === 0
                    ? $this->pick($random, self::SUBJECTS)
                    : '['.$i.'/'.$size.'] - "'.bin2hex($random->getBytes(16)).'.'.$random->getInt(10, 99).'" yEnc (1/'.$random->getInt(10, 999).') '.str_repeat('x', $random->getInt(0, 40));

                continue;
            }
            $name = self::RELEASE.($random->getInt(0, 9) === 0 ? '-sample' : '').'.'.$this->pick($random, $extensions);
            $subjects[] = '['.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT).'/'.$size.'] - "'.$name.'" yEnc (1/'.$random->getInt(1, 400).')';
        }

        return [$subjects, $noisy && $random->getInt(0, 3) === 0 ? $this->pick($random, ['', 'Other.Release-X', 'show name s01e02']) : self::RELEASE];
    }

    /**
     * Search-sync events from one processing pass: each release begins a scope, requests (often repeatedly) and
     * finishes; noisy passes interleave foreign releases, discards and immediate (non-coalescing) mode.
     *
     * @return array{list<int>, list<int>, bool, ?int, bool}
     */
    private function decideArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $ops = [];
        $ids = [];
        $release = $random->getInt(1, 1_000_000);
        for ($i = 0; $i < $size; $i++) {
            if ($noisy) {
                $ops[] = $random->getInt(0, 3);
                $ids[] = $random->getInt(0, 3) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $release + $random->getInt(0, 3);
            } else {
                $op = match ($i % 4) {
                    0 => ReleaseSearchSyncCoordinator::OP_BEGIN,
                    3 => ReleaseSearchSyncCoordinator::OP_FINISH,
                    default => ReleaseSearchSyncCoordinator::OP_REQUEST,
                };
                $release += $op === ReleaseSearchSyncCoordinator::OP_BEGIN ? 1 : 0;
                $ops[] = $op;
                $ids[] = $random->getInt(0, 7) === 0 ? $release + 5 : $release;
            }
        }
        $coalesce = ! $noisy || $random->getInt(0, 3) !== 0;

        return [$ops, $ids, $coalesce, $noisy && $random->getInt(0, 1) === 0 ? $release : null, $noisy && $random->getInt(0, 1) === 0];
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionClass($class))->setStaticPropertyValue('useW52RustFfi', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W52_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w52', UsenetDownloadService::W52_FFI_CDEF) === null) {
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
}
