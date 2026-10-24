<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationPipeline;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

class RustCategorizationPipelineParityTest extends TestCase
{
    private const string CDEF = 'int64_t nntmux_categorize_pipeline(const char *name, size_t name_len, const char *group, size_t group_len, const char *poster, size_t poster_len, uint32_t flags);';

    private Container $previousContainer;

    private ?ConnectionResolverInterface $previousResolver;

    private Container $container;

    private Manager $database;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('categorization-pipeline-test', self::CDEF);
        self::assertNotNull($ffi, 'Build the release library with the fused dispatcher before running parity tests.');
        $this->ffi = $ffi;
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['nntmux' => ['categorization' => ['log' => false]]]));
        $this->database = new Manager($this->container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT)');
        $this->database->getConnection()->table('usenet_groups')->insert(['id' => 1, 'name' => '']);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->getConnection()->disconnect();
            if ($this->previousResolver !== null) {
                Model::setConnectionResolver($this->previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            Container::setInstance($this->previousContainer);
        }
        parent::tearDown();
    }

    private function pipeline(bool $webDl = true, bool $foreign = true): CategorizationPipeline
    {
        $pipeline = CategorizationPipeline::createDefault();
        (new ReflectionProperty($pipeline, 'catWebDL'))->setValue($pipeline, $webDl);
        (new ReflectionProperty($pipeline, 'categorizeForeign'))->setValue($pipeline, $foreign);

        return $pipeline;
    }

    /**
     * Disable every categorizer's FFI, including Misc's shared library, so the reference really
     * executes the original PHP pipe loop rather than comparing Rust against individual Rust calls.
     */
    private function purePhp(callable $run): mixed
    {
        $saved = [];
        foreach ([
            [CategorizationPipeline::class, 'nativeFfi'],
            [RustPipelineLibrary::class, 'defaultLibrary'],
            ...array_map(static fn (string $class): array => [$class, 'nativeFfi'], [
                Categorizers\GroupNameCategorizer::class, Categorizers\XxxCategorizer::class,
                Categorizers\TvCategorizer::class, Categorizers\MovieCategorizer::class,
                Categorizers\PcCategorizer::class, Categorizers\ConsoleCategorizer::class,
                Categorizers\MusicCategorizer::class, Categorizers\BookCategorizer::class,
            ]),
        ] as [$class, $name]) {
            $property = new ReflectionProperty($class, $name);
            $saved[] = [$property, $property->getValue()];
            $property->setValue(null, false);
        }
        try {
            return $run();
        } finally {
            foreach ($saved as [$property, $value]) {
                $property->setValue(null, $value);
            }
        }
    }

    /** @return list<array{string, string, string}> */
    private static function corpus(): array
    {
        return [
            ['Ordinary Release Title', 'alt.binaries.movies', ''],
            ['Ordinary Release Title', 'alt.binaries.sounds.mp3', ''],
            ['Ordinary Release Title', 'alt.binaries.warez', ''],
            ['Ordinary Release Title', 'alt.binaries.games', ''],
            ['Ordinary Release Title', 'alt.binaries.ebooks', ''],
            ['Ordinary Release Title', 'alt.binaries.erotica', ''],
            ['Ordinary Release Title', 'alt.binaries.tv', ''],
            ['Ordinary Release Title', 'alt.binaries.anime', ''],
            ['Ordinary Release Title', '0', ''],
            ['Ordinary Release Title', '', ''],
            ['Movie.Name.2024.1080p.WEB-DL.x264-GRP', 'alt.binaries.tv', ''],
            ['Movie.Name.2024.2160p.BluRay.HEVC-GRP', '', ''],
            ['Movie.Name.French.2024.1080p.WEB-DL-GRP', '', ''],
            ['Show.Name.S01E02.1080p.WEB-DL.x264-GRP', 'alt.binaries.movies', ''],
            ['Show.Name.S01E02.French.1080p.WEB-DL-GRP', '', ''],
            ['Show.Name.S01.2024.2160p.NF.WEB-DL-GRP', '', ''],
            ['Show.Name.S01.720p.HDTV.x264-GRP', '', ''],
            ['Show.Name.S01E02', '', ''],
            ['[SubsPlease] Show Title - 08 (1080p) [ABCD1234]', '', ''],
            ['Ordinary Release Title', '', 'Anon <x@animetosho.org>'],
            ['UFC.2024.Week.1080p.WEB-DL-GRP', '', ''],
            ['Nature.Documentary.S01E01.720p.HDTV-GRP', '', ''],
            ['Game.Title-CODEX', 'alt.binaries.movies', ''],
            ['Game.Title.PS4-DUPLEX', '', ''],
            ['Game.Title.Xbox360', '', ''],
            ['Ordinary PS4 Game Release', '', ''],
            ['Adobe.Photoshop.2024.x64.Multilingual-GRP', '', ''],
            ['Application.Name.macOS', '', ''],
            ['Application.Name.Android.Release', '', ''],
            ['Application.Name.msix', '', ''],
            ['Ordinary Release Title', '', 'Bob <PC@MASTER.RACE>'],
            ['Artist.Album.2024.WEB.FLAC-GRP', '', ''],
            ['Artist.French.Album.2024.WEB.FLAC-GRP', '', ''],
            ['Artist.Album.MP3.320kbps.WEB-GRP', '', ''],
            ['Author.Novel.Audiobook.Unabridged.M4B', '', ''],
            ['Author.Novel.Audiobook.EPUB', '', ''],
            ['PC.Game.Audiobook.Unabridged.M4B', '', ''],
            ['Author.Novel.Audiobook.Unabridged.M4B', '', 'Anon <x@animetosho.org>'],
            ['Interesting.Podcast.Episode.123', '', ''],
            ['Author.Some.Novel.epub', '', ''],
            ['Marvel.Comic.Issue.12.cbz', '', ''],
            ['Manning.Python.Handbook.pdf', '', ''],
            ['Wired.Monthly.2024.pdf', '', ''],
            ['OF.Creator.Special.Video.mp4', '', ''],
            ['OnlyFans.Creator.Photo.Collection', '', ''],
            ['Brazzers.24.01.15.Some.Girl.1080p.XXX', '', ''],
            ['NaughtyAmericaVR.Scene.1080p', '', ''],
            ['XXX.Scene.WEB-DL', '', ''],
            ['XXX.Scene.WEB-DL', '', 'anon@y.com'],
            ['Jacquie.Et.Michel.Scene.1080p', '', ''],
            ['d41d8cd98f00b204e9800998ecf8427e', 'alt.binaries.anime', 'Anon <x@animetosho.org>'],
            ['ABCDEFGH1234567890XY', 'alt.binaries.movies', ''],
            ['xz.123456789012', 'alt.binaries.movies', ''],
            ['1234-5678-9012', 'alt.binaries.movies', ''],
            ['abcdefghijklm', 'alt.binaries.movies', ''],
            ['Backup.Collection.zip', '', ''],
            ['SQL.Backup.Dataset', '', ''],
            ['SQL.Backup.Dataset', 'alt.binaries.movies', ''],
            ['', '', ''],
            ["Movie.\0Name.2024.1080p.WEB-DL", '', ''],
            ['Étrange.Film.2024.1080p.WEB-DL-GRP', '', ''],
            [str_repeat('Long.Title.', 65).'S01E02.1080p.WEB-DL-GRP', '', ''],
        ];
    }

    public function test_fused_public_pipeline_matches_the_pure_php_loop(): void
    {
        foreach ([false, true] as $webDl) {
            foreach ([false, true] as $foreign) {
                $pipeline = $this->pipeline($webDl, $foreign);
                foreach (self::corpus() as [$name, $group, $poster]) {
                    $this->database->getConnection()->table('usenet_groups')->where('id', 1)->update(['name' => $group]);
                    $expected = $this->purePhp(fn () => $pipeline->categorize(1, $name, $poster));
                    // Fail if an apparently successful native result silently used Laravel's loop.
                    $this->container->bind(Pipeline::class, static fn () => throw new \LogicException('Unexpected pipe-loop fallback'));
                    try {
                        self::assertSame($expected, $pipeline->categorize(1, $name, $poster), $name);
                    } finally {
                        $this->container->offsetUnset(Pipeline::class);
                    }
                }
            }
        }
    }

    public function test_packed_winner_matches_php_confidence_rule_and_misc_lock(): void
    {
        $classes = [
            1 => Categorizers\GroupNameCategorizer::class, 2 => Categorizers\XxxCategorizer::class,
            3 => Categorizers\TvCategorizer::class, 4 => Categorizers\MovieCategorizer::class,
            5 => Categorizers\PcCategorizer::class, 6 => Categorizers\ConsoleCategorizer::class,
            7 => Categorizers\MusicCategorizer::class, 8 => Categorizers\BookCategorizer::class,
        ];
        $seen = [];
        foreach ([0, 1, 2, 3] as $flags) {
            $pipeline = $this->pipeline(($flags & 2) !== 0, ($flags & 1) !== 0);
            foreach (self::corpus() as [$name, $group, $poster]) {
                $this->database->getConnection()->table('usenet_groups')->where('id', 1)->update(['name' => $group]);
                $expected = $this->purePhp(fn () => $pipeline->categorize(1, $name, $poster, true))['debug'];
                // Use the same normalized name as the public caller.
                $name = $expected['release_name'];
                // @phpstan-ignore method.notFound
                $packed = $this->ffi->nntmux_categorize_pipeline($name, strlen($name), $group, strlen($group), $poster, strlen($poster), $flags);
                self::assertGreaterThanOrEqual(0, $packed, $name);
                if ($packed === 0) {
                    self::assertSame('no_match', $expected['matched_by']);

                    continue;
                }
                $pipe = ($packed >> 48) & 0xFF;
                $rule = ($packed >> 32) & 0xFFFF;
                $seen[$pipe] = true;
                $rules = match ($pipe) {
                    0 => (new \ReflectionClass(Categorizers\MiscCategorizer::class))->getConstant('MATCHED_BY_NAMES'),
                    9 => ['group_only_low_signal'],
                    default => (new \ReflectionClass($classes[$pipe]))->getConstant('NATIVE_RULES'),
                };
                self::assertSame($expected['final_category'], $packed & 0xFFFF, $name);
                self::assertSame($expected['final_confidence'], (($packed >> 16) & 0xFFFF) / 1000, $name);
                self::assertSame($expected['matched_by'], $rules[$rule], $name);
                self::assertSame($expected['locked_to_misc'], (($packed >> 56) & 1) === 1, $name);
            }
        }
        foreach (range(0, 9) as $pipe) {
            self::assertArrayHasKey($pipe, $seen, "No winning fixture for pipe {$pipe}");
        }
    }

    public function test_custom_and_exposed_pipes_disable_fusion(): void
    {
        $custom = new class extends AbstractCategorizationPipe
        {
            protected int $priority = 0;

            public function getName(): string
            {
                return 'Custom';
            }

            protected function categorize(ReleaseContext $context): CategorizationResult
            {
                return new CategorizationResult(Category::BOOKS_TECHNICAL, 1.0, 'custom');
            }
        };
        foreach (['addCategorizer', 'addPipe', 'collection', 'constructor'] as $method) {
            $pipeline = $this->pipeline();
            if ($method === 'collection') {
                $pipeline->getCategorizers()->prepend($custom);
            } elseif ($method === 'constructor') {
                $pipeline = new CategorizationPipeline([$custom]);
                (new ReflectionProperty($pipeline, 'catWebDL'))->setValue($pipeline, true);
                (new ReflectionProperty($pipeline, 'categorizeForeign'))->setValue($pipeline, true);
            } else {
                $pipeline->$method($custom);
            }
            self::assertFalse((new ReflectionProperty($pipeline, 'defaultPipesUnmodified'))->getValue($pipeline));
            self::assertSame(['categories_id' => Category::BOOKS_TECHNICAL], $pipeline->categorize(1, 'Ordinary Release Title'), $method);
        }
    }

    public function test_randomized_conflicts_match_the_pure_php_loop(): void
    {
        $random = new Randomizer(new Mt19937(24042026));
        $corpus = self::corpus();
        $names = ['WEB-DL', 'S01E02', 'PS4', 'FLAC', 'Audiobook', 'Unabridged', 'French', 'XXX', '720p', 'EPUB', '2024', 'x264', 'Portable', 'Manning', 'Archive.zip'];
        $pipelines = [$this->pipeline(false, false), $this->pipeline(false, true), $this->pipeline(true, false), $this->pipeline(true, true)];
        for ($i = 0; $i < 2000; $i++) {
            [$name, $group, $poster] = $corpus[$random->getInt(0, count($corpus) - 1)];
            $name .= '.'.$names[$random->getInt(0, count($names) - 1)].'.'.$i;
            $this->database->getConnection()->table('usenet_groups')->where('id', 1)->update(['name' => $group]);
            $pipeline = $pipelines[$i % 4];
            $expected = $this->purePhp(fn () => $pipeline->categorize(1, $name, $poster));
            self::assertSame($expected, $pipeline->categorize(1, $name, $poster), $name);
        }
    }

    public function test_invalid_abi_inputs_decline(): void
    {
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_categorize_pipeline(null, 1, '', 0, '', 0, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_categorize_pipeline('', 0, null, 1, '', 0, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_categorize_pipeline('', 0, '', 0, null, 1, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_categorize_pipeline('', 0, '', 0, '', 0, 4));
    }

    /**
     * Opt-in real-caller benchmark, including normalization, SQLite group lookup and allocation.
     * NNTMUX_PIPELINE_BENCHMARK=1 NNTMUX_PIPELINE_BENCHMARK_RESULTS=/tmp/pipeline-results.json
     * php -d opcache.enable_cli=1 artisan test --compact tests/Unit/RustCategorizationPipelineParityTest.php
     * --filter=paired_benchmark
     */
    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_PIPELINE_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_PIPELINE_BENCHMARK=1 to run 40 paired blocks per workload.');
        }
        $random = new Randomizer(new Mt19937(24042026));
        foreach ([1 => 'alt.binaries.multimedia', 2 => 'alt.binaries.movies', 3 => 'alt.binaries.tv',
            4 => 'alt.binaries.sounds.mp3', 5 => 'alt.binaries.ebooks', 6 => 'alt.binaries.games',
            7 => 'alt.binaries.warez', 8 => 'alt.binaries.anime', 9 => 'alt.binaries.erotica'] as $id => $group) {
            $this->database->getConnection()->table('usenet_groups')->updateOrInsert(['id' => $id], ['name' => $group]);
        }
        $fixtures = [
            'common' => [
                [3, 'Show.Name.S01E02.1080p.WEB-DL.x264-GRP', 'Uploader <poster@example.com>'],
                [2, 'Movie.Name.2024.1080p.WEB-DL.x264-GRP', 'Uploader <poster@example.com>'],
                [7, 'Interesting.Game.PC-GROUP', 'Bob <PC@MASTER.RACE>'],
                [6, 'Ordinary PS4 Game Release', 'Uploader <poster@example.com>'],
                [4, 'Artist.Album.2024.WEB.FLAC-GRP', 'Uploader <poster@example.com>'],
                [5, 'Author.Some.Novel.epub', 'Uploader <poster@example.com>'],
                [1, 'Ordinary Release Title', 'Uploader <poster@example.com>'],
                [9, 'XXX.Scene.WEB-DL', 'anon@y.com'],
            ],
            'uncommon' => [
                [8, '[SubsPlease] Show Title - 08 (1080p) [ABCD1234]', 'Anon <x@animetosho.org>'],
                [3, 'Show.Name.S01.2024.2160p.NF.WEB-DL-GRP', 'Uploader <poster@example.com>'],
                [4, 'Author.Novel.Audiobook.Unabridged.M4B', 'Uploader <poster@example.com>'],
                [9, 'Jacquie.Et.Michel.Scene.1080p', 'Uploader <poster@example.com>'],
                [1, 'SQL.Backup.Dataset', 'Uploader <poster@example.com>'],
                [2, 'Étrange.Film.2024.1080p.WEB-DL-GRP', 'Uploader <poster@example.com>'],
                [3, str_repeat('Long.Title.', 30).'S01E02.1080p.WEB-DL-GRP', 'Uploader <poster@example.com>'],
            ],
        ];
        $fused = $this->pipeline();
        $loop = $this->pipeline();
        (new ReflectionProperty($loop, 'defaultPipesUnmodified'))->setValue($loop, false);
        $run = static function (CategorizationPipeline $pipeline, array $names): array {
            $latencies = [];
            $outputs = [];
            foreach ($names as [$groupId, $name, $poster]) {
                $start = hrtime(true);
                $outputs[] = $pipeline->categorize($groupId, $name, $poster);
                $latencies[] = hrtime(true) - $start;
            }

            return [$latencies, $outputs];
        };
        $p99 = static function (array $values): int {
            sort($values);

            return $values[(int) ceil(count($values) * 0.99) - 1];
        };
        $report = ['seed' => 24042026, 'pairs' => 40, 'observations_per_pair' => 250, 'warmup' => 2000,
            'php' => PHP_VERSION, 'host' => php_uname(), 'sources' => [], 'workloads' => []];
        foreach (['app/Services/Categorization/CategorizationPipeline.php', 'rust/pipeline-ffi/src/categorize.rs',
            'tests/Unit/RustCategorizationPipelineParityTest.php', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock', 'vendor/composer/installed.json',
            'rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $path) {
            $report['sources'][$path] = hash_file('sha256', $path);
        }
        foreach ($fixtures as $workload => $names) {
            $pool = [];
            for ($i = 0; $i < 12000; $i++) {
                [$groupId, $name, $poster] = $names[$i % count($names)];
                $pool[] = [$groupId, $name.'.sample.'.$i, $poster];
            }
            $warmup = array_slice($pool, 0, 2000);
            $this->purePhp(fn () => $run($loop, $warmup));
            $run($loop, $warmup);
            $run($fused, $warmup);
            foreach (['php', 'individual_ffi'] as $baseline) {
                $baselineLatencies = [];
                $rustLatencies = [];
                $pairs = [];
                for ($pair = 0; $pair < 40; $pair++) {
                    $inputs = array_slice($pool, 2000 + $pair * 250, 250);
                    $order = $random->getInt(0, 1) === 0 ? ['baseline', 'rust'] : ['rust', 'baseline'];
                    $observations = [];
                    foreach ($order as $mode) {
                        $observations[$mode] = $mode === 'rust' ? $run($fused, $inputs)
                            : ($baseline === 'php' ? $this->purePhp(fn () => $run($loop, $inputs)) : $run($loop, $inputs));
                    }
                    self::assertSame($observations['baseline'][1], $observations['rust'][1], 'Measured outputs must match');
                    array_push($baselineLatencies, ...$observations['baseline'][0]);
                    array_push($rustLatencies, ...$observations['rust'][0]);
                    $pairs[] = ['pair' => $pair, 'order' => $order,
                        'baseline_ns' => $observations['baseline'][0], 'rust_ns' => $observations['rust'][0]];
                }
                $summary = ['n' => count($baselineLatencies),
                    'baseline_mean_us' => array_sum($baselineLatencies) / count($baselineLatencies) / 1000,
                    'rust_mean_us' => array_sum($rustLatencies) / count($rustLatencies) / 1000,
                    'baseline_p99_us' => $p99($baselineLatencies) / 1000, 'rust_p99_us' => $p99($rustLatencies) / 1000];
                $summary['mean_speedup'] = $summary['baseline_mean_us'] / $summary['rust_mean_us'];
                $summary['p99_speedup'] = $summary['baseline_p99_us'] / $summary['rust_p99_us'];
                $report['workloads'][$workload.'/'.$baseline] = ['summary' => $summary, 'pairs' => $pairs];
                fwrite(STDERR, $workload.'/'.$baseline.' '.json_encode($summary, JSON_THROW_ON_ERROR)."\n");
            }
        }
        $output = getenv('NNTMUX_PIPELINE_BENCHMARK_RESULTS');
        if ($output !== false && $output !== '') {
            file_put_contents($output, json_encode($report, JSON_THROW_ON_ERROR));
        }
        foreach (['common/php', 'uncommon/php'] as $workload) {
            self::assertGreaterThanOrEqual(1.5, $report['workloads'][$workload]['summary']['mean_speedup'], $workload.' mean');
            self::assertGreaterThanOrEqual(1.5, $report['workloads'][$workload]['summary']['p99_speedup'], $workload.' p99');
        }
    }

    public function test_logging_retains_the_pipe_loop(): void
    {
        $config = $this->container->make('config');
        $config->set('nntmux.categorization.log', true);
        $this->container->bind(Pipeline::class, static fn () => throw new \LogicException('Logging requires the pipe loop'));
        $this->expectExceptionMessage('Logging requires the pipe loop');
        $this->pipeline()->categorize(1, 'Ordinary Release Title');
    }

    public function test_debug_and_unavailable_native_preserve_the_php_result(): void
    {
        $pipeline = $this->pipeline();
        foreach (self::corpus() as [$name, $group, $poster]) {
            $this->database->getConnection()->table('usenet_groups')->where('id', 1)->update(['name' => $group]);
            $expected = $this->purePhp(fn () => $pipeline->categorize(1, $name, $poster, true));
            self::assertSame($expected, $pipeline->categorize(1, $name, $poster, true), $name);
        }
        $property = new ReflectionProperty(CategorizationPipeline::class, 'nativeFfi');
        $saved = $property->getValue();
        try {
            // A loaded old library without the new symbol is also an ordinary fallback.
            foreach ([false, FFI::cdef('')] as $unavailable) {
                $property->setValue(null, $unavailable);
                self::assertSame(['categories_id' => Category::TV_WEBDL], $pipeline->categorize(999, 'Show.Name.S01E02.WEB-DL', null));
            }
        } finally {
            $property->setValue(null, $saved);
        }
    }
}
