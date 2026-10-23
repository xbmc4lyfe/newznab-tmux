<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\AbstractCategorizer;
use App\Services\Categorization\Categorizers\BookCategorizer;
use App\Services\Categorization\Categorizers\ConsoleCategorizer;
use App\Services\Categorization\Categorizers\GroupNameCategorizer;
use App\Services\Categorization\Categorizers\MovieCategorizer;
use App\Services\Categorization\Categorizers\MusicCategorizer;
use App\Services\Categorization\Categorizers\PcCategorizer;
use App\Services\Categorization\Categorizers\TvCategorizer;
use App\Services\Categorization\Categorizers\XxxCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies the Rust FFI categorizers (`nntmux_categorize_*`) against the pure-PHP implementations.
 */
class CategorizersRustParityTest extends TestCase
{
    private const string CDEF = <<<'CDEF'
        int64_t nntmux_categorize_group_name(const char *group, size_t group_len);
        int64_t nntmux_categorize_pc(const char *name, size_t name_len, const char *poster, size_t poster_len, uint32_t flags);
        int64_t nntmux_categorize_console(const char *name, size_t name_len, uint32_t flags);
        int64_t nntmux_categorize_music(const char *name, size_t name_len, uint32_t flags);
        int64_t nntmux_categorize_book(const char *name, size_t name_len, uint32_t flags);
        int64_t nntmux_categorize_movie(const char *name, size_t name_len, uint32_t flags);
        int64_t nntmux_categorize_tv(const char *name, size_t name_len, const char *group, size_t group_len, const char *poster, size_t poster_len, uint32_t flags);
        int64_t nntmux_categorize_xxx(const char *name, size_t name_len, const char *poster, size_t poster_len, uint32_t flags);
        int32_t nntmux_categorize_should_skip(uint32_t kind, const char *name, size_t name_len);
        ssize_t nntmux_categorize_rule_name(uint32_t domain, uint32_t rule_id, char *out_buf, size_t out_cap);
CDEF;

    /** @var array<string, array{0: int, 1: class-string<AbstractCategorizer>, 2: int}> key => [domain, class, skipKind (0 = none)] */
    private const array DOMAINS = [
        'group' => [1, GroupNameCategorizer::class, 0],
        'pc' => [2, PcCategorizer::class, 2],
        'console' => [3, ConsoleCategorizer::class, 3],
        'music' => [4, MusicCategorizer::class, 4],
        'book' => [5, BookCategorizer::class, 5],
        'movie' => [6, MovieCategorizer::class, 6],
        'tv' => [7, TvCategorizer::class, 7],
        'xxx' => [8, XxxCategorizer::class, 0],
    ];

    private const array GROUPS = ['', '', '', 'alt.binaries.anime', 'alt.binaries.tv', 'alt.binaries.foo', '0', 'alt.binaries.erotica',
        'alt.binaries.movies.x264', 'alt.binaries.sounds.mp3', 'alt.binaries.games', 'alt.binaries.warez', 'alt.binaries.e-book', 'alt.binaries.hdtv'];

    private const array POSTERS = ['', '', '', 'Anon <x@animetosho.org>', 'Bob <PC@MASTER.RACE>', 'anon@y.com', 'oz@lot.com'];

    private ?FFI $ffi = null;

    /** @var array<string, AbstractCategorizer> */
    private array $categorizers = [];

    private function php(string $key, ReleaseContext $context): CategorizationResult
    {
        $categorizer = $this->categorizers[$key];

        return match (true) {
            $categorizer instanceof GroupNameCategorizer => $categorizer->categorizePhp($context),
            $categorizer instanceof PcCategorizer => $categorizer->categorizePhp($context),
            $categorizer instanceof ConsoleCategorizer => $categorizer->categorizePhp($context),
            $categorizer instanceof MusicCategorizer => $categorizer->categorizePhp($context),
            $categorizer instanceof BookCategorizer => $categorizer->categorizePhp($context),
            $categorizer instanceof MovieCategorizer => $categorizer->categorizePhp($context),
            $categorizer instanceof TvCategorizer => $categorizer->categorizePhp($context),
            $categorizer instanceof XxxCategorizer => $categorizer->categorizePhp($context),
            default => throw new LogicException("unknown categorizer {$key}"),
        };
    }

    private function skipPhp(string $key, ReleaseContext $context): bool
    {
        $categorizer = $this->categorizers[$key];

        return match (true) {
            $categorizer instanceof PcCategorizer => $categorizer->shouldSkipPhp($context),
            $categorizer instanceof ConsoleCategorizer => $categorizer->shouldSkipPhp($context),
            $categorizer instanceof MusicCategorizer => $categorizer->shouldSkipPhp($context),
            $categorizer instanceof BookCategorizer => $categorizer->shouldSkipPhp($context),
            $categorizer instanceof MovieCategorizer => $categorizer->shouldSkipPhp($context),
            $categorizer instanceof TvCategorizer => $categorizer->shouldSkipPhp($context),
            default => false,
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ffi = RustPipelineLibrary::resolveDomain('categorizers-parity', self::CDEF);
        if ($this->ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline (FFI) is not available; run cargo build --release.');
        }
        foreach (self::DOMAINS as $key => [, $class]) {
            $this->categorizers[$key] = new $class;
        }
    }

    public function test_rule_tables_match_the_native_library(): void
    {
        foreach (self::DOMAINS as $key => [$domain, $class]) {
            $native = (new ReflectionClass($class))->getReflectionConstant('NATIVE_RULES');
            $this->assertNotFalse($native, $class);
            /** @var array<int, string> $rules */
            $rules = $native->getValue();
            $this->assertSame(array_keys($rules), range(0, \count($rules) - 1), $key);

            $exported = [];
            for ($id = 0; $id < 512; $id++) {
                $name = $this->nativeRuleName($domain, $id);
                if ($name === '') {
                    break;
                }
                $exported[] = $name;
            }
            $this->assertSame(array_values($rules), $exported, "rule table mismatch for {$key}");
        }
    }

    public function test_curated_corpus_matches_pure_php(): void
    {
        $this->assertNoMismatches($this->compare(self::curatedNames(), mutate: false));
    }

    public function test_randomized_corpus_matches_pure_php(): void
    {
        mt_srand(20260501);
        $this->assertNoMismatches($this->compare($this->randomNames(18000), mutate: false));
    }

    public function test_mutated_corpus_matches_pure_php(): void
    {
        mt_srand(20260502);
        $this->assertNoMismatches($this->compare(self::curatedNames(), mutate: true));
    }

    public function test_every_rule_is_exercised_by_the_corpus(): void
    {
        mt_srand(20260503);
        $names = array_merge(self::curatedNames(), $this->randomNames(18000));
        $seen = [];
        foreach ($names as $name) {
            foreach (self::contextVariants($name) as $context) {
                foreach (self::DOMAINS as $key => $_) {
                    $result = $this->php($key, $context);
                    if ($result->isSuccessful() || $result->matchedBy !== 'no_match') {
                        $seen[$key][$result->matchedBy] = true;
                    }
                }
            }
        }

        $missing = [];
        foreach (self::DOMAINS as $key => [, $class]) {
            $native = (new ReflectionClass($class))->getReflectionConstant('NATIVE_RULES');
            $this->assertNotFalse($native);
            foreach ($native->getValue() as $rule) {
                if (! isset($seen[$key][$rule])) {
                    $missing[] = "{$key}:{$rule}";
                }
            }
        }
        $this->assertSame([], $missing, 'rules never produced by the corpus');
    }

    public function test_public_entry_points_match_pure_php(): void
    {
        mt_srand(20260504);
        $names = array_slice(array_merge(self::curatedNames(), $this->randomNames(3000)), 0, 3500);
        $mismatches = [];
        foreach ($names as $name) {
            foreach (self::contextVariants($name) as $context) {
                foreach ($this->categorizers as $key => $categorizer) {
                    $expected = $this->php($key, $context);
                    $actual = $categorizer->categorize($context);
                    if (! self::sameResult($expected, $actual)) {
                        $mismatches[] = "categorize {$key}: {$name}";
                    }
                    if (self::DOMAINS[$key][2] !== 0 && $categorizer->shouldSkip($context) !== $this->skipPhp($key, $context)) {
                        $mismatches[] = "shouldSkip {$key}: {$name}";
                    }
                }
            }
        }
        $this->assertNoMismatches($mismatches);
    }

    public function test_combined_skip_and_categorize_flow_matches_pure_php(): void
    {
        mt_srand(20260505);
        $names = array_slice(array_merge(self::curatedNames(), $this->randomNames(2500)), 0, 3000);
        $mismatches = [];
        foreach ($names as $index => $name) {
            $context = self::contextVariants($name)[0];
            // A context from an unrelated release must never leak into the next categorize() call.
            $other = new ReleaseContext($names[($index + 7) % \count($names)], 0, '', '', true, true);
            foreach (array_keys(self::DOMAINS) as $key) {
                $categorizer = $this->categorizers[$key];
                $skipsLikePhp = self::DOMAINS[$key][2] === 0 || $categorizer->shouldSkip($context) === $this->skipPhp($key, $context);
                if (! $skipsLikePhp) {
                    $mismatches[] = "shouldSkip {$key}: {$name}";
                }
                if (! self::sameResult($this->php($key, $context), $categorizer->categorize($context))) {
                    $mismatches[] = "flow {$key}: {$name}";
                }
                if (self::DOMAINS[$key][2] !== 0) {
                    $categorizer->shouldSkip($context);
                    if (! self::sameResult($this->php($key, $other), $categorizer->categorize($other))) {
                        $mismatches[] = "stale-context {$key}: {$name}";
                    }
                }
            }
        }
        $this->assertNoMismatches($mismatches);
    }

    public function test_categorizers_fall_back_to_php_when_native_is_unavailable(): void
    {
        $context = new ReleaseContext('Show.Name.S01E02.720p.HDTV.x264-GRP', 0);
        foreach (self::DOMAINS as $key => [, $class]) {
            $reflection = new ReflectionClass($class);
            $handle = $reflection->getProperty('nativeFfi');
            $savedHandle = $handle->getValue();
            $handle->setValue(null, false);
            try {
                $categorizer = new $class;
                $this->assertTrue(self::sameResult($this->php($key, $context), $categorizer->categorize($context)), $key);
                if (self::DOMAINS[$key][2] !== 0) {
                    $this->assertSame($this->skipPhp($key, $context), $categorizer->shouldSkip($context), $key);
                }
            } finally {
                $handle->setValue(null, $savedHandle);
            }
        }
    }

    public function test_existing_pipeline_scenarios_match_pure_php(): void
    {
        $scenarios = [
            ['Show.Name.S01.1080p.WEB-DL.DDP5.1.H.264-GRP', ''],
            ['Band-Album-2020-FLAC-GRP', 'alt.binaries.sounds.mp3'],
            ['Game.Title-CODEX', 'alt.binaries.games'],
            ['[SubsPlease] Show Title - 08 (1080p) [ABCD1234]', 'alt.binaries.anime'],
            ['Brazzers.24.01.15.Some.Girl.1080p.XXX', 'alt.binaries.erotica'],
            ['Adobe.Photoshop.2024.v25.x64.Multilingual-GRP', 'alt.binaries.warez'],
            ['Some.Novel.Author.2020.epub', 'alt.binaries.ebook'],
        ];
        $mismatches = [];
        foreach ($scenarios as [$name, $group]) {
            $context = new ReleaseContext($name, 0, $group);
            foreach ($this->categorizers as $key => $categorizer) {
                if (! self::sameResult($this->php($key, $context), $categorizer->categorize($context))) {
                    $mismatches[] = "{$key}: {$name}";
                }
            }
        }
        $this->assertNoMismatches($mismatches);
    }

    /**
     * Opt-in paired benchmark (NNTMUX_CATEGORIZER_BENCHMARK=<iterations>): the Rust FFI pipeline versus
     * the pure-PHP one on distinct generated release names, interleaved in random order.
     *
     * NNTMUX_CATEGORIZER_BENCHMARK_MIN (default 3.0) is the required mean and p99 speedup of a full
     * pass over all eight categorizers. Run with:
     *   NNTMUX_CATEGORIZER_BENCHMARK=20000 php -d opcache.enable_cli=1 artisan test --compact tests/Unit/CategorizersRustParityTest.php --filter=paired_benchmark
     */
    public function test_paired_benchmark_against_pure_php(): void
    {
        $iterations = (int) getenv('NNTMUX_CATEGORIZER_BENCHMARK');
        if ($iterations < 1000) {
            $this->markTestSkipped('Set NNTMUX_CATEGORIZER_BENCHMARK=<iterations >= 1000> to run the benchmark.');
        }
        $minimum = (float) (getenv('NNTMUX_CATEGORIZER_BENCHMARK_MIN') ?: 3.0);
        $warmup = (int) (getenv('NNTMUX_CATEGORIZER_BENCHMARK_WARMUP') ?: 30000);
        mt_srand((int) (getenv('NNTMUX_CATEGORIZER_BENCHMARK_SEED') ?: 12345));

        $report = [];
        foreach (['common' => 'benchmarkCommonName', 'uncommon' => 'benchmarkUncommonName'] as $workload => $generator) {
            $seen = [];
            $fresh = function (int $needed) use (&$seen, $generator): array {
                $pool = [];
                while (\count($pool) < $needed) {
                    $name = self::$generator();
                    if (! isset($seen[$name])) {
                        $seen[$name] = true;
                        $pool[] = $name;
                    }
                }

                return $pool;
            };
            foreach ([null, ...array_keys(self::DOMAINS)] as $only) {
                $label = ($only ?? 'ALL').'/'.$workload;
                $report[$label] = $this->pairedRun($fresh($warmup + $iterations), $warmup, $iterations, $only);
            }
        }

        $lines = [\sprintf('%-18s %8s %10s %10s %7s %10s %10s %7s', 'workload', 'n', 'php_mean', 'rust_mean', 'mean_x', 'php_p99', 'rust_p99', 'p99_x')];
        foreach ($report as $label => $row) {
            $lines[] = \sprintf(
                '%-18s %8d %9.2fu %9.2fu %6.2fx %9.2fu %9.2fu %6.2fx',
                $label, $iterations, $row['php_mean'], $row['rust_mean'], $row['php_mean'] / $row['rust_mean'], $row['php_p99'], $row['rust_p99'], $row['php_p99'] / $row['rust_p99']
            );
        }
        fwrite(STDERR, "\n".implode("\n", $lines)."\n");

        foreach (['ALL/common', 'ALL/uncommon'] as $label) {
            $row = $report[$label];
            $this->assertGreaterThanOrEqual($minimum, $row['php_mean'] / $row['rust_mean'], "{$label} mean speedup");
            $this->assertGreaterThanOrEqual($minimum, $row['php_p99'] / $row['rust_p99'], "{$label} p99 speedup");
        }
    }

    /**
     * @param  list<string>  $pool
     * @return array{php_mean: float, rust_mean: float, php_p99: float, rust_p99: float}
     */
    private function pairedRun(array $pool, int $warmup, int $iterations, ?string $only): array
    {
        $groups = ['', 'alt.binaries.multimedia', 'alt.binaries.teevee', 'alt.binaries.anime', 'alt.binaries.sounds.mp3', 'alt.binaries.games', 'alt.binaries.e-book', 'alt.binaries.warez'];
        $posters = ['', 'someone@example.com', 'Anon <x@animetosho.org>', 'Bob <PC@MASTER.RACE>', 'anon@y.com'];
        $keys = $only === null ? array_keys(self::DOMAINS) : [$only];
        $pass = function (ReleaseContext $context, bool $native) use ($keys): void {
            foreach ($keys as $key) {
                $categorizer = $this->categorizers[$key];
                if ($native) {
                    if (self::DOMAINS[$key][2] !== 0 && $categorizer->shouldSkip($context)) {
                        continue;
                    }
                    $categorizer->categorize($context);
                } else {
                    if (self::DOMAINS[$key][2] !== 0 && $this->skipPhp($key, $context)) {
                        continue;
                    }
                    $this->php($key, $context);
                }
            }
        };

        for ($i = 0; $i < $warmup; $i++) {
            $context = new ReleaseContext($pool[$i], 0, $groups[$i % \count($groups)], $posters[$i % \count($posters)], true, true);
            $pass($context, true);
            $pass($context, false);
        }
        $php = [];
        $rust = [];
        for ($i = 0; $i < $iterations; $i++) {
            $context = new ReleaseContext($pool[$warmup + $i], 0, $groups[mt_rand(0, \count($groups) - 1)], $posters[mt_rand(0, \count($posters) - 1)], true, true);
            if (mt_rand(0, 1) === 0) {
                $start = hrtime(true);
                $pass($context, false);
                $php[] = hrtime(true) - $start;
                $start = hrtime(true);
                $pass($context, true);
                $rust[] = hrtime(true) - $start;
            } else {
                $start = hrtime(true);
                $pass($context, true);
                $rust[] = hrtime(true) - $start;
                $start = hrtime(true);
                $pass($context, false);
                $php[] = hrtime(true) - $start;
            }
        }
        sort($php);
        sort($rust);
        $p99 = static fn (array $sorted): float => $sorted[min(\count($sorted) - 1, (int) floor(0.99 * \count($sorted)))] / 1000;

        return [
            'php_mean' => array_sum($php) / \count($php) / 1000,
            'rust_mean' => array_sum($rust) / \count($rust) / 1000,
            'php_p99' => $p99($php),
            'rust_p99' => $p99($rust),
        ];
    }

    /**
     * @param  list<string>  $items
     */
    private static function pick(array $items): string
    {
        return $items[mt_rand(0, \count($items) - 1)];
    }

    private static function benchmarkWord(): string
    {
        if (mt_rand(0, 2) > 0) {
            return self::pick(['Dark', 'Night', 'Storm', 'River', 'Empire', 'Shadow', 'Last', 'King', 'Silent', 'Golden', 'City', 'Hunter', 'Winter', 'Summer', 'Blood', 'Iron', 'Lost', 'Ghost', 'Final', 'Rise', 'Fall', 'Secret', 'Wild', 'Black', 'White', 'Red', 'Blue', 'Fire', 'Ice', 'Star']);
        }
        $word = '';
        for ($i = mt_rand(3, 9); $i > 0; $i--) {
            $word .= \chr(mt_rand(97, 122));
        }

        return ucfirst($word);
    }

    private static function benchmarkTitle(string $separator): string
    {
        $words = [];
        for ($i = mt_rand(1, 5); $i > 0; $i--) {
            $words[] = self::benchmarkWord();
        }

        return implode($separator, $words);
    }

    private static function benchmarkHex(int $length): string
    {
        $hex = '';
        for ($i = 0; $i < $length; $i++) {
            $hex .= dechex(mt_rand(0, 15));
        }

        return $hex;
    }

    private static function benchmarkGroup(): string
    {
        return self::pick(['GRP', 'NTb', 'FLUX', 'SPARKS', 'CODEX', 'SKIDROW', 'DEFLATE', 'TEPES', 'RARBG', 'YIFY', 'CMRG', 'KTR', 'PLAZA', 'DUPLEX', 'TiMES']);
    }

    private static function benchmarkCommonName(): string
    {
        $sep = self::pick(['.', '.', '.', ' ', '_']);
        $res = self::pick(['720p', '1080p', '2160p', '1080i', '480p']);
        $src = self::pick(['HDTV', 'WEB-DL', 'WEBRip', 'BluRay', 'BDRip', 'DVDRip']);
        $codec = self::pick(['x264', 'x265', 'H.264', 'HEVC', 'XviD']);

        return match (mt_rand(0, 11)) {
            0, 1, 2 => self::benchmarkTitle($sep).$sep.\sprintf('S%02dE%02d', mt_rand(1, 12), mt_rand(1, 24)).$sep.$res.$sep.$src.$sep.$codec.'-'.self::benchmarkGroup(),
            3 => self::benchmarkTitle($sep).$sep.\sprintf('S%02d', mt_rand(1, 12)).$sep.'COMPLETE'.$sep.$res.$sep.$src.'-'.self::benchmarkGroup(),
            4, 5 => self::benchmarkTitle($sep).$sep.mt_rand(1985, 2025).$sep.$res.$sep.$src.$sep.$codec.'-'.self::benchmarkGroup(),
            6 => self::benchmarkTitle('-').'-'.self::benchmarkTitle('_').'-'.mt_rand(1990, 2025).'-'.self::pick(['FLAC', 'MP3', '320kbps', 'WEB']).'-'.self::benchmarkGroup(),
            7 => self::benchmarkTitle(' ').' Author '.mt_rand(1990, 2025).self::pick(['.epub', '.pdf', '.mobi', ' EPUB', '']),
            8 => self::benchmarkTitle($sep).self::pick(['-CODEX', '-SKIDROW', '-PLAZA', '.PS4-DUPLEX', '.XBOX360-GRP', '.NSW-VENOM', '.PC.GAMES-GRP']),
            9 => self::benchmarkTitle($sep).$sep.'v'.mt_rand(1, 30).'.'.mt_rand(0, 9).'.'.mt_rand(0, 9).$sep.self::pick(['x64', 'Multilingual', 'Portable', 'Incl.Keygen']).'-'.self::benchmarkGroup(),
            10 => self::pick(['Brazzers', 'Vixen', 'Tushy', 'Blacked', 'Wicked', 'Nubiles']).\sprintf('.%02d.%02d.%02d.', mt_rand(15, 25), mt_rand(1, 12), mt_rand(1, 28)).self::benchmarkWord().'.'.self::benchmarkWord().'.'.$res.'.MP4-KTR',
            default => '['.self::pick(['SubsPlease', 'Erai-raws', 'Judas', 'Group'.mt_rand(1, 99)]).'] '.self::benchmarkTitle(' ').' - '.\sprintf('%02d', mt_rand(1, 26)).' [1080p] ['.strtoupper(self::benchmarkHex(8)).']',
        };
    }

    private static function benchmarkUncommonName(): string
    {
        switch (mt_rand(0, 9)) {
            case 0:
                $name = '';
                $target = mt_rand(150, 400);
                while (\strlen($name) < $target) {
                    $name .= self::benchmarkWord().self::pick(['.', ' ', '-', '_']);
                }

                return $name.'S01E01.720p.HDTV.x264-GRP';
            case 1:
                return self::benchmarkHex([32, 40, 64][mt_rand(0, 2)]);
            case 2:
                return '['.mt_rand(1, 99).'/'.mt_rand(1, 99).'] - "'.self::benchmarkHex(mt_rand(12, 24)).'.part'.mt_rand(1, 9).'.rar" yEnc';
            case 3:
                return strtoupper(self::benchmarkTitle(self::pick(['.', ' ']))).'.'.mt_rand(1990, 2025).'.'.self::pick(['MULTI', 'GERMAN', 'FRENCH', 'DUBBED', 'Multisub', 'Castellano']).'.1080p.BluRay.x264-'.self::benchmarkGroup();
            case 4:
                return self::benchmarkTitle('.').'.'.self::pick(['XXX', 'PACK', 'IMAGESET', 'VR180', 'OnlyFans', 'Hardcore']).'.'.self::pick(['1080p', '2160p', '720p']).'.'.self::pick(['MP4', 'WMV', 'x264']).'-'.self::benchmarkGroup();
            case 5:
                return 'Ünïcödé '.self::benchmarkTitle(' ').' '.mt_rand(1990, 2025).' '.self::pick(['é', 'ñ', 'ü', 'ø']).' 1080p BluRay-'.self::benchmarkGroup();
            case 6:
                return self::benchmarkTitle('.').'.'.self::pick(['Audiobook', 'Podcast', 'Discography', 'Concert', 'Greatest.Hits', 'Soundtrack', 'OST']).'.'.self::pick(['Unabridged', 'MP3', 'FLAC', 'M4B', 'Live']).'.'.self::pick(['320kbps', 'V0', '24bit', '']);
            case 7:
                return self::pick(['Marvel', 'Forbes', 'Wired', 'National.Geographic']).'.'.self::pick(['Issue.', 'Monthly.', 'Vol.']).mt_rand(1, 99).'.'.self::pick(['Jan', 'Feb', 'Mar']).'.'.mt_rand(1990, 2025).self::pick(['.pdf', '.cbr', '.epub']);
            case 8:
                $words = [];
                for ($i = mt_rand(8, 20); $i > 0; $i--) {
                    $words[] = self::benchmarkWord();
                }

                return implode('.', $words).'-'.self::benchmarkGroup();
            default:
                return self::pick(['Ubuntu', 'Windows', 'Adobe', 'Autodesk']).'.'.self::benchmarkTitle('.').'.'.self::pick(['x64', 'amd64', 'win64']).'.'.self::pick(['iso', 'Setup', 'Keygen', 'Crack']).'.'.self::pick(['Incl.Crack', 'Multilingual', 'Portable', '']);
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function compare(array $names, bool $mutate): array
    {
        $mismatches = [];
        $queue = $names;
        if ($mutate) {
            $queue = [];
            foreach ($names as $name) {
                for ($i = 0; $i < 6; $i++) {
                    $queue[] = self::mutate($name);
                }
            }
        }
        foreach ($queue as $name) {
            foreach (self::contextVariants($name) as $context) {
                foreach (self::DOMAINS as $key => [, , $skipKind]) {
                    $categorizer = $this->categorizers[$key];
                    $expected = $this->php($key, $context);
                    $actual = $this->nativeResult($key, $context);
                    if ($actual === null) {
                        $mismatches[] = "native declined {$key}: {$name}";
                    } elseif (! self::sameResult($expected, $actual)) {
                        $mismatches[] = "{$key} ".json_encode($name).' foreign='.(int) $context->categorizeForeign.' webdl='.(int) $context->catWebDL
                            ." php={$expected->categoryId}/{$expected->confidence}/{$expected->matchedBy}"
                            ." rust={$actual->categoryId}/{$actual->confidence}/{$actual->matchedBy}";
                    }
                    if ($skipKind !== 0) {
                        // @phpstan-ignore method.notFound
                        $native = $this->ffi->nntmux_categorize_should_skip($skipKind, $name, \strlen($name));
                        if ($native !== (int) $this->skipPhp($key, $context)) {
                            $mismatches[] = "shouldSkip {$key} ".json_encode($name)." rust={$native}";
                        }
                    }
                }
                if (\count($mismatches) > 40) {
                    return $mismatches;
                }
            }
        }

        return $mismatches;
    }

    /**
     * @param  list<string>  $mismatches
     */
    private function assertNoMismatches(array $mismatches): void
    {
        $this->assertSame([], \array_slice($mismatches, 0, 15), \count($mismatches).' mismatches');
    }

    private static function sameResult(CategorizationResult $a, CategorizationResult $b): bool
    {
        return $a->categoryId === $b->categoryId && $a->confidence === $b->confidence && $a->matchedBy === $b->matchedBy;
    }

    private function nativeResult(string $key, ReleaseContext $context): ?CategorizationResult
    {
        $ffi = $this->ffi;
        $this->assertNotNull($ffi);
        $name = $context->releaseName;
        $nameLen = \strlen($name);
        $flags = ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0);

        $packed = match ($key) {
            // @phpstan-ignore method.notFound
            'group' => $ffi->nntmux_categorize_group_name($context->groupName, \strlen($context->groupName)),
            // @phpstan-ignore method.notFound
            'pc' => $ffi->nntmux_categorize_pc($name, $nameLen, $context->poster, \strlen($context->poster), $flags),
            // @phpstan-ignore method.notFound
            'console' => $ffi->nntmux_categorize_console($name, $nameLen, $flags),
            // @phpstan-ignore method.notFound
            'music' => $ffi->nntmux_categorize_music($name, $nameLen, $flags),
            // @phpstan-ignore method.notFound
            'book' => $ffi->nntmux_categorize_book($name, $nameLen, $flags),
            // @phpstan-ignore method.notFound
            'movie' => $ffi->nntmux_categorize_movie($name, $nameLen, $flags),
            // @phpstan-ignore method.notFound
            'tv' => $ffi->nntmux_categorize_tv($name, $nameLen, $context->groupName, \strlen($context->groupName), $context->poster, \strlen($context->poster), $flags),
            // @phpstan-ignore method.notFound
            'xxx' => $ffi->nntmux_categorize_xxx($name, $nameLen, $context->poster, \strlen($context->poster), $flags),
            default => throw new LogicException("unknown categorizer {$key}"),
        };
        if (! \is_int($packed) || $packed < 0) {
            return null;
        }
        if ($packed === 0) {
            return CategorizationResult::noMatch();
        }
        $rule = $this->nativeRuleName(self::DOMAINS[$key][0], $packed >> 32);

        return new CategorizationResult($packed & 0xFFFF, (($packed >> 16) & 0xFFFF) / 1000, $rule);
    }

    private function nativeRuleName(int $domain, int $id): string
    {
        $ffi = $this->ffi;
        $this->assertNotNull($ffi);
        $buffer = $ffi->new('char[128]');
        // @phpstan-ignore method.notFound
        $length = $ffi->nntmux_categorize_rule_name($domain, $id, $buffer, 128);

        return \is_int($length) && $length > 0 ? FFI::string($buffer, $length) : '';
    }

    /**
     * @return list<ReleaseContext>
     */
    private static function contextVariants(string $name): array
    {
        $group = self::GROUPS[mt_rand(0, \count(self::GROUPS) - 1)];
        $poster = self::POSTERS[mt_rand(0, \count(self::POSTERS) - 1)];

        return [
            new ReleaseContext($name, 0, $group, $poster, true, true),
            new ReleaseContext($name, 0, $group, $poster, false, false),
            new ReleaseContext($name, 0, '', '', mt_rand(0, 1) === 1, mt_rand(0, 1) === 1),
        ];
    }

    private static function mutate(string $name): string
    {
        $len = \strlen($name);
        if ($len === 0) {
            return $name;
        }
        $separators = ['.', ' ', '-', '_'];
        for ($round = mt_rand(1, 3); $round > 0; $round--) {
            $len = \strlen($name);
            if ($len < 2) {
                break;
            }
            $pos = mt_rand(0, $len - 1);
            switch (mt_rand(0, 5)) {
                case 0:
                    $name = substr($name, 0, $pos).substr($name, $pos + 1);
                    break;
                case 1:
                    $name = substr($name, 0, $pos).$separators[mt_rand(0, 3)].substr($name, $pos + 1);
                    break;
                case 2:
                    $name = substr($name, 0, $pos).$separators[mt_rand(0, 3)].substr($name, $pos);
                    break;
                case 3:
                    $name = substr($name, 0, $pos).(ctype_upper($name[$pos]) ? strtolower($name[$pos]) : strtoupper($name[$pos])).substr($name, $pos + 1);
                    break;
                case 4:
                    $name = substr($name, 0, $pos);
                    break;
                default:
                    $name .= $separators[mt_rand(0, 3)].['GRP', 'x264', '1080p', 'S01E01', '2024', 'FLAC', ''][mt_rand(0, 6)];
                    break;
            }
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private function randomNames(int $count): array
    {
        $vocabulary = self::vocabulary();
        $separators = ['.', '.', '.', ' ', '-', '_'];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $parts = [];
            for ($n = mt_rand(2, 9); $n > 0; $n--) {
                $token = $vocabulary[mt_rand(0, \count($vocabulary) - 1)];
                $roll = mt_rand(0, 9);
                if ($roll === 0) {
                    $token = strtolower($token);
                } elseif ($roll === 1) {
                    $token = strtoupper($token);
                }
                $parts[] = $token;
            }
            $sep = $separators[mt_rand(0, \count($separators) - 1)];
            $name = implode($sep, $parts);
            switch (mt_rand(0, 7)) {
                case 0:
                    $name = '[Grp] '.$name;
                    break;
                case 1:
                    $name .= '-GRP';
                    break;
                case 2:
                    $name .= ['.mkv', '.mp3', '.epub', '.exe', '.iso', '.cbr', '.flac', '.pdf'][mt_rand(0, 7)];
                    break;
                case 3:
                    $name .= ' ['.['ABCD1234', 'a1b2c3d4', '12345678'][mt_rand(0, 2)].']';
                    break;
                default:
                    break;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function vocabulary(): array
    {
        return [
            // generic
            'Some', 'Name', 'Title', 'Show', 'Movie', 'Game', 'Band', 'Artist', 'Album', 'Tool', 'Pro', 'GRP', 'FOO', 'Great', 'Cool', 'The', 'Of',
            // years / dates / episodes
            '2019', '2020', '2024', '1999', '24.01.15', '2024.01.15', '18.01.15', '121025', 'S01E02', 'S01', 'S02', 'S03E10', 'S1D1', 'E05', 'E12', 'Ep.05',
            'Episode.12', 'Season.2', 'III', 'II', 'no', 'Anime', 'Complete', 'Full',
            // video
            '1080p', '1080i', '720p', '2160p', '480p', '576p', 'HDTV', 'PDTV', 'DSR', 'WEB-DL', 'WEBRip', 'WEB', 'BluRay', 'Blu-Ray', 'BDRip', 'DVDRip', 'DVDR', 'DVD9', 'DVD5',
            'x264', 'x265', 'H.264', 'HEVC', 'AVC', 'XviD', 'DivX', 'NF', 'AMZN', 'Netflix', 'DEFLATE', 'NTb', 'TrollHD', 'MeGusta', 'CMRG', 'UHD', '4K', 'HDR', 'Dolby.Vision',
            '3D', 'SBS', 'BD25', 'Untouched', 'R5', 'CAM', 'HDTS', 'German', 'French', 'Dutch', 'ITA', 'Multisub', 'Castellano', 'Staffel', 'DUBBED', 'Documentary', 'DOCU',
            'NFL', 'UFC', 'Formula.1', 'Week', 'vs', 'Playoffs', 'SubsPlease', 'Erai-raws', 'Judas', 'NanDesuKa', 'mp4', 'wmv',
            // consoles / pc
            'PS4', 'PS3', 'PS5', 'PSVita', 'PSP', 'XBOX', 'XBOX360', 'XBOXONE', 'Wii', 'WiiU', '3DS', 'NDS', 'SNES', 'N64', 'USA', 'EUR', 'NTSC', 'CUSA12345', 'PPSA12345', 'CODEX',
            'SKIDROW', 'PC.GAMES', 'Steam.Rip', 'iPhone', 'ANDROID', 'macOS', 'Mac.OSX', 'ISO', 'UDEMY', 'Pluralsight', 'Incl.Crack', 'Keygen', 'x64', 'win64', 'Portable', 'Setup',
            'Adobe', 'v1.2.3', 'Multilingual', 'exe', 'msi', 'Linux', 'Ubuntu', 'amd64', 'Fonts', 'DRM', 'Patch',
            // music
            'FLAC', 'MP3', '320kbps', 'V0', 'CD', 'VA', 'OST', 'Discography', 'Audiobook', 'Unabridged', 'Podcast', 'Concert', 'Live', 'Tour', 'MV', '24bit', 'Vinyl', 'Compilation',
            'Greatest.Hits', 'Remastered', 'DJ.Mix', 'Mixed.By', 'Read.By.John.Smith', 'M4B', 'Narrated', '256K', 'EP', 'Box.Set', 'WavPack', 'Cream', 'ALAC', 'Serial', 'NPR',
            // books
            'EPUB', 'MOBI', 'PDF', 'Kindle', 'Ebook', 'ISBN', 'Edition', 'Author', 'Novel', 'Packt', "O'Reilly", 'Wiley', 'Python', 'Programming', 'Guide', 'Cookbook', 'Forbes',
            'Wired', 'Issue.12', 'Monthly', 'Weekly', 'Jan.2020', 'Marvel', 'Comics', 'CBR', 'CBZ', 'Manga', 'Annual', 'Vol.3', 'MAGAZINE', 'MCN.Jan.12.2020', 'Volume',
            // adult
            'XXX', 'Brazzers', 'Wicked', 'Vixen', 'Tushy', 'Blacked', 'Jacquie.Et.Michel', 'MILF', 'Anal', 'Creampie', 'Teen', 'StockingsVR', 'VR180', 'Oculus', 'Quest2', 'PSVR',
            'OnlyFans', 'OF', 'Photoset', 'PACK', 'Siterip', 'SDX264XXX', 'IMAGESET', 'KTR', 'hUSHhUSH', '10musume', 'JAV', 'AV', 'Hentai', 'Jane.Doe', 'Hardcore', 'Sex', 'Lady.Lyne',
            'X-Art', 'SexArt', 'Defloration', 'detoxication', 'KLEENEX', 'SDPORN', 'North.Pole', 'Casting.Couch',
        ];
    }

    /**
     * @return list<string>
     */
    private static function curatedNames(): array
    {
        return [
            'Show.Name.S01E02.720p.HDTV.x264-GRP', 'Show.Name.S01.1080p.WEB-DL.DDP5.1.H.264-GRP', 'Show.Name.S01.Complete.BluRay-GRP', 'Show.Name.S02E10.2160p.NF.WEB-DL.DDP5.1.x265-DEFLATE',
            'Show.Name.S01E01.2160p.AMZN.WEB-DL.H.265-NTb', 'Show.Name.S01E01.German.DL.720p.HDTV.x264-GRP', 'Show.Name.S01E01.1080p.x265-MeGusta', 'Show.Name.S01E01.1080p.WEB.x265-MeGusta',
            'Daily.Show.2024.01.15.720p.HDTV.x264-GRP', 'Docu.Series.Name.S01E01.Documentary.720p', 'NFL.2024.Week.5.720p.HDTV', 'UFC.300.Main.Card.720p.WEB', 'Show S01', 'Show.Name.S01E01', 'Show.Name.S1D1',
            '[SubsPlease] Show Title - 08 (1080p) [ABCD1234]', '[Grp] Show Title - 08 [1080p][ABCD1234]', 'Shuumatsu.no.Valkyrie.E05.1080p.WEB', 'Shingeki.no.Kyojin.III.E05.1080p', 'Title.III.E05.1080p',
            'Series.Name.E05.1080p.BluRay.x264-GRP', 'Series.Anime.E05.1080p', 'Some.Anime.Show.720p', 'Show.Episode.12.HDTV', 'Show.S01.WEB-DL', 'Show.S01.NF.WEB-DL',
            'Movie.Name.2020.1080p.BluRay.x264-GRP', 'Movie.Name.2020.1080p.BluRay-GRP', 'Movie.Name.2020.2160p.UHD.BluRay.x265-GRP', 'Movie.Name.2020.German.DL.1080p.BluRay.x264-GRP', 'Movie.Name.2020.Castellano.DVDRip.XviD',
            'Movie.Name.2020.720p.French.BDRip', 'Movie.Name.2020.DVDRip.German.XviD', 'Movie.Name.2020.3D.1080p.BluRay-GRP', 'Movie.Name.2020.3D.SBS.AVC.BluRay.GRP', 'Movie.Name.2020.PAL.DVD9-GRP', 'Movie.Name.2020.DVDR.NTSC-GRP',
            'Movie.Name.2020.WEB-DL.x264-GRP', 'Movie.Name.2020.WEBRip.x264-GRP', 'Movie.Name.2020.HDRip.XviD.AC3-GRP', 'Movie.Name.2020.CAM.XviD-GRP', 'Movie.Name.2020.HDTS-LINE.XviD-GRP', 'Movie.Name.2020.Cam.Sample',
            'Movie.Name.2020.x265.1080p.BluRay-Tigole', 'Movie.Name.2020.2160p.HDR.HEVC.BluRay', 'Movie.Name.2160p.UHD.BluRay.REMUX', 'Movie.Name.2160p.NF.WEB-DL.HDR', 'Movie.Name.2020.Untouched.BD50',
            'Movie.Name.2020.Multisub.1080p.BluRay', 'Movie.Name.2020.NL.Sub.720p.BluRay', 'Movie.Name.2020.DVD5.R5.XVID', 'Movie.Name.VHSRip.DivX', 'Movie.Name.1080p.BluRay.SecretUsenet.com',
            'Game.Title.PS4-DUPLEX', 'PS4_Game_Title', 'Game.Title.CUSA12345', 'Game.Title.PS3-DUPLEX', 'Game.Title.PSVita-X', 'Game.Title.PSP-X', 'Game.Title.XBOXONE-X', 'Game.Title.XBOX360-X', 'Game.Title.XBOX-X',
            'Game.Title.WiiU-X', 'Game.Title.Wii-X', 'Game.Title.3DS-X', 'Game.Title.NDS-X', 'Game.SNES.USA.ROM', 'Game.Title.XSX', 'Game.Title.Switch-NSW',
            'Some.Game-CODEX', 'Some.Game.PC.GAMES.Repack', 'Some.Game.2024.Steam.Rip', 'Cool.App.iPhone.v1.0', 'Cool.App.ANDROID.v1.0', 'Cool.App.symbian.v1.0', 'Cool.App.macOS.Universal', 'Cool.App.Mac.OSX.Cracked',
            'Tool.Pro.v1.0.Incl.Crack', 'Cool.Tool.x64.Setup', 'Cool.Tool.msi', 'Adobe.Photoshop.2024.v25.x64.Multilingual-GRP', 'Cool.Tool.Keygen.Only', 'Ubuntu.22.04.amd64.iso', 'Training.UDEMY.Course.Name', 'Some.Tool.Portable.exe',
            'Band-Album-2020-FLAC-GRP', 'Band.Album.2020.FLAC.24bit.96kHz', 'Band Album [FLAC]', 'Band - Album (2020) [FLAC]', 'Band-Album-2020-320kbps-MP3-GRP', 'Band - Album 320kbps MP3', 'Artist.German.Album.FLAC.24bit',
            'The.Great.Book.Audiobook.Unabridged', 'Great.Book.Read.By.John.Smith.MP3', 'Some.Podcast.Episode.12', 'NPR.Show.Episode.12', 'Artist.Live.Concert.1080p.BluRay', 'Artist - Song Title 1080p x264', 'VA - Greatest Hits Compilation 2020',
            'Artist.Album.CD.Collection', 'Artist-Live.at.Venue-2020', 'DJ.Mix.Club.Hits.2020', 'Ministry.of.Sound.Anthems', 'abc-2020-def', 'Artist - Album 192K', 'Band Album (320) CD MP3',
            'Some.Novel.Author.2020.epub', 'Some.Novel.EPUB-GRP', 'Some Book PDF Author Edition', 'Python.Programming.Guide.Author.Edition', 'Packt.Learning.Rust', "O'Reilly.Learning.Rust", 'Forbes.Magazine.Issue.12.2020', 'Wired.Monthly.March.2020',
            'Marvel.Comics.Issue.4.cbr', 'Great.Manga.Volume.3', 'MCN.Jan.12.2020.MAGAZINE', 'Some.Title - Issue 12 2020', 'Kindle.Collection.2020', 'Fonts.Pack.ttf', 'Some.Movie.2020.1080p.BluRay',
            'Brazzers.24.01.15.Some.Girl.1080p.XXX', 'Brazzers.24.01.15.Some.Girl', 'Brazzers.2024.01.15.Some.Girl.2160p.XXX', 'Wicked.2024.1080p.WEB-DL.x264-GRP', 'Wicked.24.01.15.Performer.Name.1080p', 'Jacquie.Et.Michel.TV.Scene.Name.1080p',
            'StockingsVR.com.Scene.180', 'Scene.Name.VR180.XXX', 'Scene.Name.Oculus.XXX.2160p', 'OF.Some.Creator.1080p', 'Some.Creator.OnlyFans.Photoset', 'Some.Creator.OnlyFans.mp4', 'Something.XXX.PACK.2020', 'Something.XXX.2160p.MP4-KTR',
            'Something.XXX.2160p.WEB.MP4-KTR', 'Some.XXX.DVDRip', 'Some.XXX.DVDR-GRP', 'Some.XXX.WMV', 'Some.XXX.x264-GRP', 'Some.XXX.IMAGESET', 'Some.XXX.SDX264XXX', '10musume.121025.Girl', 'JAV.Some.Title.Hardcore.1080p',
            'Site.24.01.15.Performer.Name.1080p', 'Site.2024.01.15.Performer.Name.1080p', 'Site.2024.01.15.Jane.Doe.Hardcore', 'Hentai.Title.720p',
            'Thepodcast.Name', 'ShowS01X.HDTV.2160p.Netflix.NTb', 'Show.S01E01.DSR.x264-GRP', 'FooBarVR.com.Scene', '10musume.121025.Girl.1080p', 'Brazzers Some Girl XXX 1080p',
            'abcdefghijklmnopqrstuvwxyz012345', 'd41d8cd98f00b204e9800998ecf8427e', 'Random Title With Spaces', '', 'a', '.', 'Some.Name.With.Trailing.Newline'."\n",
        ];
    }
}
