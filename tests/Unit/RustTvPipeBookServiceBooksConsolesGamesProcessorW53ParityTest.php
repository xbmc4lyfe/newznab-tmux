<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BookService;
use App\Services\BooksProcessor;
use App\Services\Categorization\Pipes\TvPipe;
use App\Services\ConsolesProcessor;
use App\Services\GamesProcessor;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustTvPipeBookServiceBooksConsolesGamesProcessorW53ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export and the trailer word count after the per-record words. */
    private const TARGETS = [
        TvPipe::class => ['w53EpisodeTokenBatch', 'nntmux_w533_tv_episode_token_batch'],
        BookService::class => ['w53SubjectIsbnBatch', 'nntmux_w533_book_subject_isbn_batch'],
        BooksProcessor::class => ['w53BookTitleFlagBatch', 'nntmux_w533_book_title_flag_batch'],
        ConsolesProcessor::class => ['w53ConsoleTitleFlagBatch', 'nntmux_w533_console_title_flag_batch'],
        GamesProcessor::class => ['w53GameTitleFlagBatch', 'nntmux_w533_game_title_flag_batch'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        TvPipe::class => true,
        BookService::class => true,
        BooksProcessor::class => true,
        ConsolesProcessor::class => true,
        GamesProcessor::class => true,
    ];

    /** TV release names: episode, daily and season-pack shapes, near misses and boundary tokens. */
    private const TV_NAMES = ['Show.Name.S01E02.720p.HDTV.x264-GRP', 'show.name.s01e02', 'Show.Name.S1E2.WEB', 'Show.S01.E02.1080p',
        'Show.S01-E02', 'Show S01 E02', 'Show.S01_E02', 'Show.S99E999.x', 'Show.S01E1000', 'Show.S001E01', 'Show.S1E02.and.S05E10', 'xS01E02',
        '1S01E02', '_S01E02', 'Show.2024.03.15.Guest.720p', 'Show.1999-12-31', 'Show 2099 01 01', 'Show.2024.13.01', 'Show.2024.02.32',
        'Show.2024.02.00', 'Show.18991231', '12024.03.15', 'Show.2024.03.155', 'Show.S02.1080p.BluRay', 'Show.S02E', 'Show.S2', 'Show.Season.2',
        'Show.S02.Complete.S03', 'Show.S100', 'Movie.2019.1080p.BluRay', 'Movie', '', ' ', 'S01E01', 's1e1', 'SHOW.S10E100', "Sh\xc3\xb6w.S01E02",
        "\xff\xfeS01E02", 'Show.S01E02E03', 'Show.S01E02-E03', 'S01E02S03E04', 'a.s0e0', 'Show.S00E00'];

    /** Book release subjects: obfuscated poster prefixes and ISBN-13/ISBN-10 shapes, valid and invalid. */
    private const BOOK_SUBJECTS = ['N:/NZB [1/3] Some Book', 'n:/nzb x', 'N:/NZBx', 'N.NZB [x]', 'N - NZB - [x]', 'N:NZB[x]', 'NZB [x]', 'N NZB x',
        '  N:/NZB', "\tre posted by someone", 'Re: posted by x', 'reposted by x', 'posted by', 'postedby x', 'posted  by_x', 'posted by x',
        'Author - Title (978-0-306-40615-7) epub', 'Title 9780306406157 pdf', 'Title 978 0 306 40615 7', 'Title 978-0306406157',
        'Title 9780306406158', 'Title 97803064061570', 'x9780306406157', '9780306406157x', 'Title 0-306-40615-2', 'Title 0306406152',
        'Old Book 0-8044-2957-X', 'Old Book 080442957x', 'Old 080442957X1', 'Title 0-306-40615-3', 'Title 1234567890123',
        'Title 979-10-90636-07-1', 'Two 9780306406157 and 0306406152', 'Bad13 9780306406158 then 0306406152', '978--0306406157',
        '', 'plain title', "na\xefve 9780306406157", "N:/NZB \xff 0306406152", 'Title 0 3 0 6 4 0 6 1 5 2', "Title\v9780306406157"];

    /** Book, console and game release titles across the token families each classifier counts. */
    private const TITLES = ['Author - Title (2019) RETAIL EPUB', 'Author.Title.2019.MOBI.AZW3', 'Comic.Issue.12.CBZ.1999.2005',
        'Audiobook MP3 unabridged', 'Weekly.Magazine.2020.PDF', 'averyveryverylongtokenepub-epub', '1899 2100 19999 0019', 'retail',
        "  \t\n", "\0", "\x0b", "\x0c", 'Game.USA.PS4.Switch-GRP', 'Game.EUR.MULTI5.NSP.Update', 'Saturn.multi', 'MULTIx.PAL', 'Game.PS2.ps3',
        'Game.v1.2.3.MULTI10-CODEX', 'Game.Build.123.Crackfix.REPACK-FitGirl', 'Game-abcdefghijklmnopqrstu', 'Game-abcdefghijklmnopqrst',
        'Game - GRP', 'Game-', 'Game-_', 'Game.V.Vx.v01-RiP', 'Game.READNFO.DLC.Xbox.X360.REGIONFREE', '', 'x', "Gam\xc3\xa9.PS5-GRP",
        "\xff\xfe.ISO", 'Game.Name-GRP ', ' Game.Name-GRP', 'Title.EPUB.2019-GRP'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w53_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW53RustFfi', 'withW53RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W53_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPrivate() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W53_RUST_FFI_DEFAULT';
            }
            if (! $reflection->hasConstant('W53_FFI_CDEF')) {
                $missing[] = $class.'::W53_FFI_CDEF';
            }
            $state = new ReflectionMethod($class, 'w53RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if (! $state->isPrivate() || ! $state->isStatic() || $state->getDeclaringClass()->getName() !== $class
                || $params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w53RustFfiState';
            }
            $uses = new ReflectionMethod($class, 'usesW53RustFfi');
            $with = new ReflectionMethod($class, 'withW53RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' W53 toggle signatures';
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w53_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW53RustFfi(), $class);
            $this->assertTrue($class::withW53RustFfi(true, static fn (): bool => $class::usesW53RustFfi()));
            $this->assertFalse($class::withW53RustFfi(false, static fn (): bool => $class::usesW53RustFfi()));
            $this->assertTrue($class::withW53RustFfi(false, static fn (): bool => $class::withW53RustFfi(true, static fn (): bool => $class::usesW53RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW53RustFfi(), $class);
            try {
                $class::withW53RustFfi(! $class::usesW53RustFfi(), static fn () => throw new RuntimeException('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW53RustFfi(), $class.' restores after an exception');
        }
        // Each class keeps an independent toggle, separate from the earlier waves.
        TvPipe::withW53RustFfi(! TvPipe::usesW53RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[BookService::class], BookService::usesW53RustFfi());
            $this->assertSame(TvPipe::W413_RUST_FFI_DEFAULT, TvPipe::usesW413RustFfi());
            $this->assertFalse(TvPipe::usesRustFfi());
        });
        BooksProcessor::withW53RustFfi(! BooksProcessor::usesW53RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[ConsolesProcessor::class], ConsolesProcessor::usesW53RustFfi());
            $this->assertSame(self::DEFAULTS[GamesProcessor::class], GamesProcessor::usesW53RustFfi());
            $this->assertSame(BooksProcessor::W414_RUST_FFI_DEFAULT, BooksProcessor::usesW414RustFfi());
        });
        BookService::withW53RustFfi(! BookService::usesW53RustFfi(), function (): void {
            $this->assertSame(BookService::W404_RUST_FFI_DEFAULT, BookService::usesW404RustFfi());
        });
    }

    public function test_w53_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [, $symbol]) {
            $cdef = \constant($class.'::W53_FFI_CDEF');
            $this->assertStringContainsString($symbol, $cdef);
            $this->assertInstanceOf(FFI::class, RustPipelineLibrary::resolveDomain('categorize_w53', $cdef), $class);
        }
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w53', GamesProcessor::W53_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[16]');
        // @phpstan-ignore method.notFound
        $this->assertSame(7, $ffi->nntmux_w533_game_title_flag_batch('Game-GRP', 8, 1, $out, 16));
        $this->assertSame(GamesProcessor::w53GameTitleFlagBatchPhp(['Game-GRP']), array_values((array) unpack('P*', FFI::string($out, 56))));
        // A NUL inside a title, a short buffer, a null output, a null blob and a count mismatch all decline.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w533_game_title_flag_batch("a\0b", 3, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-7, $ffi->nntmux_w533_game_title_flag_batch('Game-GRP', 8, 1, $out, 6));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w533_game_title_flag_batch('Game-GRP', 8, 1, null, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w533_game_title_flag_batch(null, 8, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w533_game_title_flag_batch('a', 1, 2, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(6, $ffi->nntmux_w533_game_title_flag_batch(null, 0, 0, $out, 16));
    }

    public function test_w53_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        $cases = [
            TvPipe::class => [['Show.S01E02-GRP', 'Show.S02'], [1 | 1 << 2 | 2 << 16, 3, 1, 0, 1, 0]],
            BookService::class => [['N:/NZB [1/1] 0306406152', "na\xefve"], [1 | 2, 306406152, 0, 0, 1, 1, 0]],
            BooksProcessor::class => [['Title 2019 EPUB'], [2 | 2019 << 8, 0, 1, 0, 0, 0, 0, 1]],
            ConsolesProcessor::class => [['Game.PS4.USA'], [2 | 4 | 18 << 8, 0, 1, 1, 0, 0, 0]],
            GamesProcessor::class => [['Game.v2-GRP'], [2 | 32 | 3 << 8, 0, 1, 0, 0, 0, 1]],
        ];
        foreach ($cases as $class => [$items, $expected]) {
            $words = \count($expected);
            $raw = (new ReflectionMethod($class, 'w533Invoke'))->invoke(null, $items, $words);
            if ($class === BookService::class) {
                // The non-ASCII row is marked for PHP and left out of the Rust counts.
                $this->assertSame([1 | 2, 306406152, -1, 0, 1, 1, 0], $raw);
            } else {
                $this->assertSame($expected, $raw, $class);
            }
            $this->assertNull((new ReflectionMethod($class, 'w533Invoke'))->invoke(null, $items, $words + 1), $class.' rejects a word count mismatch');
            // An item holding a NUL byte stays on Rust through its stand-in byte.
            $nul = ["a\0b", "\0", ''];
            $this->assertSame($class::{self::TARGETS[$class][0].'Php'}($nul), (new ReflectionMethod($class, 'w533Invoke'))->invoke(null, $nul, \count($class::{self::TARGETS[$class][0].'Php'}($nul))), $class);
            $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Php'}($items), $class);
            $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Native'}($items), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Php'}($items));
        $this->assertParity($class, [$items], 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, list<int>}>
     */
    public static function knownCases(): array
    {
        return [
            'tv empty' => [TvPipe::class, [], [0, 0, 0, 0]],
            'tv kinds' => [TvPipe::class, ['Show.S01E02', 'Show.2024.03.15', 'Show.S03', 'Movie.2019', 'x.S99E999'],
                [1 | 1 << 2 | 2 << 16, 2, 3, 0, 1 | 99 << 2 | 999 << 16, 2, 1, 1, 1]],
            'tv leftmost episode wins' => [TvPipe::class, ['Show.2024.03.15.S05E10.S01E01'], [1 | 5 << 2 | 10 << 16, 1, 0, 0, 0]],
            'book subject empty' => [BookService::class, [], [0, 0, 0]],
            'book subject shapes' => [BookService::class, ['posted by x 978-0-306-40615-7', 'Old 0-8044-2957-X', 'Bad 9780306406158 0306406152', ''],
                [1 | 4, 9780306406157, 2 | 8, 80442957, 0, 0, 0, 0, 1, 1, 1]],
            'book subject non-ascii' => [BookService::class, ["N:/NZB \xff 0306406152"], [1 | 2, 306406152, 1, 1, 0]],
            'book titles empty' => [BooksProcessor::class, [], [0, 0, 0, 0, 0, 0, 0]],
            'book titles' => [BooksProcessor::class, ['Author - Title (2019) RETAIL EPUB', ' ', 'Comic.Issue.CBZ.1999.2005'],
                [2 | 32 | 2019 << 8, 1, 8 | 16 | 1999 << 8, 1, 1, 0, 1, 1, 1, 2]],
            'console titles empty' => [ConsolesProcessor::class, [], [0, 0, 0, 0, 0, 0]],
            'console titles' => [ConsolesProcessor::class, ['Game.USA.PS4.Switch-GRP', 'Game.EUR.MULTI5.NSP.Update', '', 'Saturn.multi'],
                [2 | 4 | 18 << 8, 4 | 8 | 16 | 32, 1, 2 | 8 | 31 << 8, 1, 2, 2, 2, 1, 1]],
            'game titles empty' => [GamesProcessor::class, [], [0, 0, 0, 0, 0, 0]],
            'game titles' => [GamesProcessor::class, ['Game.v1.2.3.MULTI10-CODEX', 'Game.Build.123.Crackfix.REPACK-FitGirl', 'Game-abcdefghijklmnopqrstu', ' '],
                [2 | 8 | 32 | 5 << 8, 2 | 4 | 16 | 32 | 7 << 8, 0, 1, 1, 2, 1, 1, 1, 2]],
        ];
    }

    public function test_episode_token_parity(): void
    {
        $this->assertParity(TvPipe::class, [self::TV_NAMES], 'edge names');
        $this->assertParity(TvPipe::class, [['']], 'one empty name');
        $this->assertParity(TvPipe::class, [['Show.S01E02', "Show\0.S01E03", "Show.S01\0E04", "\0S01E05\0", "S01 E06\0", "Show.S02\0", "\0\0"]], 'NUL rows');
        $this->assertParity(TvPipe::class, [[4 => 'Show.S01E02', 'x' => 'Show.S03']], 'sparse keys');
        $random = new Randomizer(new Mt19937(5331));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(TvPipe::class, [$this->tvNames($random, $random->getInt(1, 160), $round % 2 === 1)], "round {$round}");
        }
    }

    public function test_subject_isbn_parity(): void
    {
        $this->assertParity(BookService::class, [self::BOOK_SUBJECTS], 'edge subjects');
        $this->assertParity(BookService::class, [['']], 'one empty subject');
        $this->assertParity(BookService::class, [['Title 0306406152', "x\0 9780306406157", "978\00306406157", "0306406152\0", "\0N:/NZB [x]", "N\0NZB [x]", "posted\0by x", "\0"]], 'NUL rows');
        $this->assertParity(BookService::class, [[3 => "na\xefve 0306406152", 'k' => 'Title 9780306406157']], 'sparse keys with a PHP row');
        $this->assertParity(BookService::class, [[str_repeat('Long Title ', 600).'978-0-306-40615-7']], 'long subject');
        $service = (new ReflectionClass(BookService::class))->newInstanceWithoutConstructor();
        foreach (self::BOOK_SUBJECTS as $subject) {
            // Each row agrees with the existing single-subject helpers.
            [$flags, $isbn] = \array_slice(BookService::w53SubjectIsbnBatchPhp([$subject]), 0, 2);
            $this->assertSame($service->looksLikeObfuscatedBookSubjectPhp($subject), ($flags & 1) === 1, $subject);
            $expected = $service->extractIsbnPhp($subject);
            $decoded = match (true) {
                ($flags & 4) !== 0 => (string) $isbn,
                ($flags & 2) !== 0 => str_pad((string) $isbn, ($flags & 8) !== 0 ? 9 : 10, '0', STR_PAD_LEFT).(($flags & 8) !== 0 ? 'X' : ''),
                default => null,
            };
            $this->assertSame($expected, $decoded, $subject);
        }
        $random = new Randomizer(new Mt19937(5332));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(BookService::class, [$this->bookSubjects($random, $random->getInt(1, 160), $round % 2 === 1)], "round {$round}");
        }
    }

    public function test_title_flag_parity(): void
    {
        foreach ([BooksProcessor::class, ConsolesProcessor::class, GamesProcessor::class] as $seed => $class) {
            $this->assertParity($class, [self::TITLES], 'edge titles');
            $this->assertParity($class, [['']], 'one empty title');
            $this->assertParity($class, [['Game.PS4', "Game\0.ISO", "\0EPUB.PS4\0", "Game-GRP\0", "Game-GR\0P", "\0", "MULTI\05", "v\02"]], 'NUL rows');
            $this->assertParity($class, [[7 => 'Title.EPUB.2019', 'k' => 'Game.v2-GRP']], 'sparse keys');
            $this->assertParity($class, [[str_repeat('Long.Title.', 500).'EPUB.PS4.v2-GRP']], 'long title');
            $random = new Randomizer(new Mt19937(5333 + $seed));
            for ($round = 0; $round < 150; $round++) {
                $this->assertParity($class, [$this->titles($class, $random, $random->getInt(1, 160), $round % 2 === 1)], "round {$round}");
            }
        }
        // The per-title flags agree with the earlier W414 batch summaries.
        $titles = $this->titles(BooksProcessor::class, new Randomizer(new Mt19937(5339)), 200, true);
        $words = BooksProcessor::w53BookTitleFlagBatchPhp($titles);
        $summary = BooksProcessor::summarizeBookReleaseBatchPhp($titles);
        $this->assertSame([$summary['empty_title_count'], $summary['ebook_count'], $summary['audiobook_count'], $summary['comic_count'],
            $summary['magazine_count'], $summary['retail_count'], $summary['year_tagged_count']], \array_slice($words, 200));
        $titles = $this->titles(ConsolesProcessor::class, new Randomizer(new Mt19937(5339)), 200, true);
        $summary = ConsolesProcessor::summarizeConsoleReleaseBatchPhp($titles);
        $this->assertSame([$summary['empty_title_count'], $summary['platform_tagged_count'], $summary['region_tagged_count'],
            $summary['multi_language_count'], $summary['dump_format_count'], $summary['update_dlc_count']], \array_slice(ConsolesProcessor::w53ConsoleTitleFlagBatchPhp($titles), 200));
        $titles = $this->titles(GamesProcessor::class, new Randomizer(new Mt19937(5339)), 200, true);
        $summary = GamesProcessor::summarizeGameReleaseBatchPhp($titles);
        $this->assertSame([$summary['empty_title_count'], $summary['version_tagged_count'], $summary['crack_fix_count'],
            $summary['multi_language_count'], $summary['repack_count'], $summary['group_suffix_count']], \array_slice(GamesProcessor::w53GameTitleFlagBatchPhp($titles), 200));
        $summary = TvPipe::summarizeEpisodeTokenBatchPhp(self::TV_NAMES);
        $this->assertSame([$summary['episode'], $summary['daily'], $summary['season_pack'], $summary['none']], \array_slice(TvPipe::w53EpisodeTokenBatchPhp(self::TV_NAMES), \count(self::TV_NAMES)));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining,
        // the FFI call, decoding and any PHP row fallback (release dylib, 5 warmups, 401 paired samples per helper
        // and workload, 20 iterations per common sample and 10 per uncommon sample). Common = one typical batch of
        // 128 well-formed names or titles; uncommon = 256 long, noisy and malformed rows (non-ASCII bytes, boundary
        // tokens, empty and whitespace-only rows; BookService keeps its non-ASCII rows on PHP).
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5 NTS, release dylib, Apple Silicon,
        // shared host at load average ~7.5-8.2):
        // - TvPipe:            common mean 2.72-2.86x / p99 2.42-2.70x, uncommon mean 2.63-2.71x / p99 2.23-2.58x
        // - BookService:       common mean 3.30-3.48x / p99 3.17-3.75x, uncommon mean 1.78-1.81x / p99 1.73-2.02x
        // - BooksProcessor:    common mean 5.18-5.80x / p99 4.76-5.14x, uncommon mean 4.46-4.65x / p99 3.34-3.81x
        // - ConsolesProcessor: common mean 4.95-5.67x / p99 4.35-5.10x, uncommon mean 4.14-4.41x / p99 3.22-4.54x
        // - GamesProcessor:    common mean 5.91-6.28x / p99 4.94-6.32x, uncommon mean 5.09-5.33x / p99 4.23-4.95x
        // Every helper clears every gate in every round and defaults to Rust. BookService's uncommon margin is the
        // smallest because its non-ASCII rows (~16% of that workload) are classified by PHP.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustTvPipeBookServiceBooksConsolesGamesProcessorW53ParityTest.php --filter benchmark
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5336));
        $warmup = 5;
        $samples = 401;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(5337)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(5338)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW53RustFfi($useRust, static fn (): array => $class::$entry(...$args)));
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
        fwrite(STDERR, "\nRustTvPipeBookServiceBooksConsolesGamesProcessorW53 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW53RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
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
        $this->assertSame($php, $class::withW53RustFfi(true, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
        $this->assertSame($php, $class::withW53RustFfi(false, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
    }

    /**
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        $size = $uncommon ? 256 : 128;

        return [match ($class) {
            TvPipe::class => $this->tvNames($random, $size, $uncommon),
            BookService::class => $this->bookSubjects($random, $size, $uncommon),
            default => $this->titles($class, $random, $size, $uncommon),
        }];
    }

    /**
     * Scene TV names: mostly SxxEyy episodes, some daily air dates, season packs and movies; noisy batches add the
     * edge-case names and long dotted names with tokens late in the string.
     *
     * @return list<string>
     */
    private function tvNames(Randomizer $random, int $size, bool $noisy): array
    {
        $shows = ['The.Show', 'Another.Long.Show.Name', 'News.Tonight', 'Doc.Series', 'Show'];
        $tails = ['720p.HDTV.x264-GRP', '1080p.WEB.h264-NTb', '2160p.AMZN.WEB-DL.DDP5.1.HDR.HEVC-FLUX', 'HDTV.x264-LOL', 'WEBRip.x265-MiNX'];
        $names = [];
        for ($i = 0; $i < $size; $i++) {
            if ($noisy && $random->getInt(0, 2) === 0) {
                $names[] = $this->pick($random, self::TV_NAMES);

                continue;
            }
            $show = $this->pick($random, $shows).($noisy ? str_repeat('.Extra.Words', $random->getInt(1, 8)) : '');
            $token = match ($random->getInt(0, 9)) {
                0 => sprintf('%04d.%02d.%02d', $random->getInt(1990, 2030), $random->getInt(1, 12), $random->getInt(1, 31)),
                1 => sprintf('S%02d', $random->getInt(1, 30)),
                2 => sprintf('%04d', $random->getInt(1950, 2030)),
                default => sprintf('S%02dE%02d', $random->getInt(0, 40), $random->getInt(0, 120)),
            };
            $name = $show.'.'.$token.'.'.$this->pick($random, $tails);
            $names[] = $random->getInt(0, 3) === 0 ? strtolower($name) : $name;
        }

        return $names;
    }

    /**
     * Book subjects: titles with ISBN-13/ISBN-10 tokens (some with bad checksums) and obfuscated poster prefixes;
     * noisy batches add the edge-case subjects, non-ASCII titles and long descriptions.
     *
     * @return list<string>
     */
    private function bookSubjects(Randomizer $random, int $size, bool $noisy): array
    {
        $isbns = ['978-0-306-40615-7', '9780306406157', '978 0 306 40615 7', '9780306406158', '0-306-40615-2', '0306406152',
            '0-8044-2957-X', '080442957x', '979-10-90636-07-1', '1234567890'];
        $prefixes = ['', '', '', 'N:/NZB [1/3] ', 'N.NZB [x] ', 'Re: posted by someone ', '[01/10] - '];
        $subjects = [];
        for ($i = 0; $i < $size; $i++) {
            if ($noisy && $random->getInt(0, 2) === 0) {
                $subjects[] = $this->pick($random, self::BOOK_SUBJECTS);

                continue;
            }
            $title = 'Author Name - Book Title '.$random->getInt(1, 999).($noisy ? str_repeat(' and more words', $random->getInt(1, 10)) : '');
            if ($noisy && $random->getInt(0, 4) === 0) {
                $title .= " caf\xc3\xa9";
            }
            $isbn = $random->getInt(0, 2) === 0 ? '' : ' ('.$this->pick($random, $isbns).')';
            $subjects[] = $this->pick($random, $prefixes).$title.$isbn.' '.$this->pick($random, ['epub', 'PDF', 'mobi retail', '"x.epub" yEnc (1/1)']);
        }

        return $subjects;
    }

    /**
     * Book, console or game release titles built from that classifier's token families; noisy batches add the
     * edge-case titles, long titles, lowercase and non-ASCII words.
     *
     * @return list<string>
     */
    private function titles(string $class, Randomizer $random, int $size, bool $noisy): array
    {
        $tokens = match ($class) {
            BooksProcessor::class => ['EPUB', 'mobi', 'AZW3', 'PDF', 'Audiobook', 'MP3', 'M4B', 'Unabridged', 'CBZ', 'Comic', 'Magazine', 'Issue',
                'RETAIL', '2019', '1987', '2024', 'English', 'Novel', 'Vol', '12'],
            ConsolesProcessor::class => ['PS4', 'PS5', 'Switch', 'NSW', 'XBOX', 'X360', 'Wii', '3DS', 'USA', 'EUR', 'PAL', 'NTSC', 'JPN',
                'MULTI5', 'Multi', 'ISO', 'NSP', 'XCI', 'Update', 'DLC', 'Patch', 'v1.0.2', 'READNFO'],
            default => ['v1.2.3', 'V2', 'Build', '12345', 'Crackfix', 'CRACK', 'HOTFIX', 'READNFO', 'MULTI10', 'Multi', 'REPACK', 'FitGirl',
                'DODI', 'RIP', 'GOG', 'Update', 'x64'],
        };
        $groups = ['-CODEX', '-PLAZA', '-FLT', '-GRP', '-DARKSiDERS', '', ' [x]'];
        $titles = [];
        for ($i = 0; $i < $size; $i++) {
            if ($noisy && $random->getInt(0, 2) === 0) {
                $titles[] = $this->pick($random, self::TITLES);

                continue;
            }
            $words = ['Some', 'Title', 'Name'];
            for ($t = $random->getInt(1, $noisy ? 12 : 4); $t > 0; $t--) {
                $words[] = $this->pick($random, $tokens);
            }
            if ($noisy && $random->getInt(0, 3) === 0) {
                $words[] = "\xc3\xa9dition";
            }
            $title = implode($this->pick($random, ['.', ' ', '_', '.']), $words).$this->pick($random, $groups);
            $titles[] = $noisy && $random->getInt(0, 3) === 0 ? strtolower($title) : $title;
        }

        return $titles;
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w53RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W53_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w53', TvPipe::W53_FFI_CDEF) === null) {
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
