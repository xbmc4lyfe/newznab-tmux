<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\FindSizeMismatchedReleases;
use App\Console\Commands\NntmuxResetPostProcessing;
use App\Console\Commands\RetryArchiveInspection;
use App\Models\UsenetGroup;
use App\Services\RegexService;
use App\Support\ReleaseNameDisplay;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Darwin arm64 / PHP 8.5.12 / release Rust: 20,000 randomized single-call
 * pairs per workload, 1,000 warmup pairs (2026-10-24 initial screen).
 *                              common mean/p99    uncommon mean/p99
 * groupQueryString               0.72x / 0.73x     0.85x / 0.81x
 * collectionFileCount            0.42x / 0.46x     0.84x / 0.83x
 * collectionHash                 0.36x / 0.39x     0.65x / 0.71x
 * clean                          0.60x / 0.71x     0.95x / 0.93x
 * cleanBatch                     0.68x / 0.74x     0.72x / 0.71x
 * normalizeCategories            2.98x / 2.83x     3.95x / 2.99x
 * invalidCategories              1.37x / 1.29x     0.72x / 0.63x
 * archiveType                    0.34x / 0.33x     0.62x / 0.30x
 * stripDomainFromString          0.33x / 0.32x     1.28x / 1.02x
 * Only normalization qualifies for adoption. All other production helpers
 * retain PHP; explicit toggles and Native methods exercise the candidates.
 * Reproduce using test_paired_single_call_benchmark below. The artifact prefix
 * /tmp/wave12-regex-cleaning-20261024 holds the initial raw and summary JSON.
 * Final guarded runs: /tmp/wave12-regex-cleaning-final{1,2}.{raw,summary}.json.
 * Singleton normalization common mean 2.48-2.56x / p99 2.31-2.52x;
 * uncommon mean 3.82-4.05x / p99 2.90-3.47x. Every other helper still fails.
 */
final class RustRegexAndReleaseCleaningParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A fallback-only run is not evidence of Rust parity.
        self::assertNotNull(ReleaseNameDisplay::transformNative(3, 'probe'));
    }

    protected function tearDown(): void
    {
        ReleaseNameDisplay::$useRustFfi = false;
        parent::tearDown();
    }

    public function test_display_candidate_preserves_wrappers_and_trailing_titles(): void
    {
        $cases = [
            '' => '',
            '[1/12] - "file.mkv" yEnc (1/50)' => 'file.mkv',
            '[1/12] "Title" meaningful text' => '[1/12] "Title" meaningful text',
            '[1/12] &quot; file.mkv &quot; - yEnc' => 'file.mkv',
        ];
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, ReleaseNameDisplay::clean($input));
            self::assertSame($expected, ReleaseNameDisplay::cleanNative($input));
        }
        self::assertSame(array_values($cases), ReleaseNameDisplay::cleanBatch(array_keys($cases)));
    }

    public function test_command_candidates_match_literal_contracts(): void
    {
        $retry = new RetryArchiveInspection;
        foreach (['x.7z.001' => '7z', 'x.part01' => 'rar', 'x.zipx' => 'zip', 'x.zip_foo' => 'unknown'] as $name => $expected) {
            self::assertSame($expected, $retry->archiveTypeNative($name));
        }
        $reset = new NntmuxResetPostProcessing;
        self::assertSame(['tv', 'movie', 'unknown'], $reset->normalizeCategoriesNative(['TV,Movies', 'category=tv unknown']));
        self::assertSame(['unknown'], $reset->invalidCategoriesNative(['tv', 'all', 'unknown']));
        $size = new FindSizeMismatchedReleases;
        self::assertSame('a  z', $size->stripDomainFromStringNative('a www.example.com z'));
        $regex = new RegexService;
        self::assertSame('00012', $regex->collectionFileCountNative('name [1/00012]'));
        self::assertSame(sha1('subjectposter700000012'), $regex->collectionHashNative('name [1/00012]', 'subject', 'poster', 7000));
    }

    public function test_wrappers_counters_archives_and_domains_match_php_on_boundaries_and_seeded_inputs(): void
    {
        $regex = new RegexService;
        $retry = new RetryArchiveInspection;
        $size = new FindSizeMismatchedReleases;
        $names = [null, '', '0', '[1/2] "a"', '[ 1 / 2 ]-#34; a #34; yENC',
            '[1/2] &#34;a&#34; - yEnc (20/90)', '[1/2] " "', '[1/2] "a" -',
            "[1/2] \"a\nb\"", "[1/2] \"\0a\0\" yEnc", '[1/2] "a" yEnc(1/2)',
            '[1/2] "a" trailing title', '[1/2] "a" yEnc ( 1/2)', "[1/2] \"a\"\n",
            '[1/2] &QUOT;a&QUOT;', '[1/2] "a#34;b"', '[1/2] "é.mkv"',
            'x [1/00012]', 'x (00001_Of_00002)', 'x 1-22:', 'x [123456/2]',
            'x [1/234567]', 'x [1/2$', 'x (1/2]', 'x [1/2] (1/3)', 'x.7z_name.rar',
            'x.rar_name.zip', 'x.r1', 'x.r12', 'x.r123', 'x.r1234', 'x.part0001',
            'x.PART1_', 'x.zipx_foo', 'x.zipé', 'a www.a.company z', 'www.x.abcdmore',
            'www.x.ab.foo.com!', 'www..ab', 'www.a.abcdef.www.b.com', "www.a.com\0tail",
            str_repeat('"', 5000), '[1/2] "'.str_repeat('x', 20000).'" yEnc (1/50)',
        ];
        mt_srand(1201);
        $fragments = ['[', ']', '(', ')', '/', '-', '_of_', ' Of ', ' ', "\t", "\n", "\0", '1', '00012', '123456', '"', '#34;', '&quot;', 'yEnc', '.rar', '.7z', '.zipx', 'www.', '.com', 'é', "\xff"];
        for ($i = 0; $i < 1500; $i++) {
            $name = '';
            for ($j = 0, $n = mt_rand(1, 15); $j < $n; $j++) {
                $name .= $fragments[mt_rand(0, count($fragments) - 1)];
            }
            $names[] = $name;
        }
        foreach ($names as $i => $name) {
            $value = $name ?? '';
            self::assertSame(ReleaseNameDisplay::cleanPhp($name), ReleaseNameDisplay::cleanNative($name), "display $i");
            self::assertSame($regex->collectionFileCountPhp($value), $regex->collectionFileCountNative($value), "count $i");
            self::assertSame($regex->collectionHashPhp($value, 'n', "poster\0", -1), $regex->collectionHashNative($value, 'n', "poster\0", -1), "hash $i");
            self::assertSame($retry->archiveTypePhp($value), $retry->archiveTypeNative($value), "archive $i");
            self::assertSame($size->stripDomainFromStringPhp($value), $size->stripDomainFromStringNative($value), "domain $i");
        }
        $names = ['a' => '[1/2] "a"', 91 => null, 'b' => '[1/2] "é"'];
        self::assertSame(['a' => 'a', 91 => '', 'b' => 'é'], ReleaseNameDisplay::cleanBatchNative($names));
        foreach ([false, true] as $toggle) {
            ReleaseNameDisplay::$useRustFfi = $toggle;
            self::assertSame(['a' => 'a', 91 => '', 'b' => 'é'], ReleaseNameDisplay::cleanBatch($names));
            self::assertSame('a', ReleaseNameDisplay::clean('[1/2] "a"'));
        }
    }

    public function test_categories_preserve_coercion_plural_rules_order_and_loose_uniqueness(): void
    {
        $reset = new NntmuxResetPostProcessing;
        $cases = [[], ['TV,Movies', 'category=tv unknown'], [['Books', 'games'], '-category=musics'],
            ['0', '00', '0.0', '1', '1.0', '01'], [null, false, true, 1, 2.5],
            ["tv\0", "\0TV\0", 'a=b=movies', 'miscs', 'musicss', 'alls', 'ALL'],
            ['=tv', 'category=', 'TV,Movies'], ['é', "\xff", 'TV'], ['music', 'music', 'all', 'unknown'],
        ];
        mt_srand(1202);
        $parts = ['TV', 'MovieS', 'bookss', 'misc', 'ALL', 'all', 'alls', 'foo', '=', '-category=', '00', '0.0', '1e2', ',', "\t", "\0"];
        for ($i = 0; $i < 300; $i++) {
            $raw = [];
            for ($j = 0; $j < 10; $j++) {
                $raw[] = $parts[mt_rand(0, count($parts) - 1)].$parts[mt_rand(0, count($parts) - 1)];
            }
            $cases[] = $raw;
        }
        foreach ($cases as $i => $raw) {
            $expected = $reset->normalizeCategoriesPhp($raw);
            self::assertSame($expected, $reset->normalizeCategoriesNative($raw), "normalize $i");
            self::assertSame($reset->invalidCategoriesPhp($expected), $reset->invalidCategoriesNative($expected), "invalid $i");
            foreach ([false, true, null] as $toggle) {
                $reset->useRustFfi = $toggle;
                self::assertSame($expected, (new ReflectionMethod($reset, 'normalizeCategories'))->invoke($reset, $raw));
                self::assertSame($reset->invalidCategoriesPhp($expected), (new ReflectionMethod($reset, 'invalidCategories'))->invoke($reset, $expected));
            }
        }
        foreach ([['', '0', 0, false, null, 'TV'], ['tv', 'tv', 'all', 'all', 'weird', 'weird']] as $normalized) {
            self::assertSame($reset->invalidCategoriesPhp($normalized), $reset->invalidCategoriesNative($normalized));
        }
    }

    public function test_category_errors_and_locale_fallback_preserve_php_behavior(): void
    {
        $reset = new NntmuxResetPostProcessing;
        foreach ([[new \stdClass], [[[]]], [[null, false, 12], new \stdClass]] as $raw) {
            $outcomes = [];
            foreach (['normalizeCategoriesPhp', 'normalizeCategoriesNative'] as $method) {
                set_error_handler(static function (int $severity, string $message): never {
                    throw new \ErrorException($message, 0, $severity);
                });
                try {
                    $outcomes[] = $reset->{$method}($raw);
                } catch (\Throwable $error) {
                    $outcomes[] = [$error::class, $error->getMessage()];
                } finally {
                    restore_error_handler();
                }
            }
            self::assertSame($outcomes[0], $outcomes[1]);
        }
        $previous = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C', 'en_US.ISO8859-1', 'en_US.UTF-8', 'C.UTF-8'] as $locale) {
                if (setlocale(LC_CTYPE, $locale) === false) {
                    continue;
                }
                foreach (['[1/2] "é"', "\xa0[1/2] \"x\"", "x.zip\xe9"] as $name) {
                    self::assertSame(ReleaseNameDisplay::cleanPhp($name), ReleaseNameDisplay::cleanNative($name));
                    $retry = new RetryArchiveInspection;
                    self::assertSame($retry->archiveTypePhp($name), $retry->archiveTypeNative($name));
                }
                // Reset PCRE character tables before selecting another locale.
                setlocale(LC_CTYPE, 'C');
            }
        } finally {
            setlocale(LC_CTYPE, 'C');
            if ($previous !== false) {
                setlocale(LC_CTYPE, $previous);
            }
        }
    }

    public function test_pcre_resource_limits_and_large_wrappers_fall_back(): void
    {
        $large = '[1/2] "'.str_repeat('x', 30000).'"';
        self::assertSame(ReleaseNameDisplay::cleanPhp($large), ReleaseNameDisplay::cleanNative($large));
        self::assertSame([ReleaseNameDisplay::cleanPhp($large)], ReleaseNameDisplay::cleanBatchNative([$large]));
        $reset = new NntmuxResetPostProcessing;
        $previous = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '0');
            self::assertSame($reset->normalizeCategoriesPhp(['TV,Movies']), $reset->normalizeCategoriesNative(['TV,Movies']));
            self::assertSame(ReleaseNameDisplay::cleanPhp('[1/2] "file"'), ReleaseNameDisplay::cleanNative('[1/2] "file"'));
            self::assertNull(ReleaseNameDisplay::transformNative(6, pack('V', 9).'TV,Movies'));
        } finally {
            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }
    }

    public function test_sql_quoting_and_collection_caller_with_disposable_sqlite(): void
    {
        $original = DB::getFacadeRoot();
        $resolver = UsenetGroup::getConnectionResolver();
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        DB::swap($capsule->getDatabaseManager());
        UsenetGroup::setConnectionResolver($capsule->getDatabaseManager());
        try {
            $regex = new RegexService;
            foreach (['', '0', 'alt.binaries.*|alt.test', "a'b", '%_\\'] as $pattern) {
                $expected = $pattern ? 'WHERE group_regex LIKE '.DB::connection()->getPdo()->quote('%'.$pattern.'%') : '';
                self::assertSame($expected, $regex->groupQueryStringNative($pattern));
                foreach ([false, true] as $toggle) {
                    $regex->useRustFfi = $toggle;
                    self::assertSame($expected, (new ReflectionMethod($regex, '_groupQueryString'))->invoke($regex, $pattern));
                }
            }
            foreach (['groupQueryStringPhp', 'groupQueryStringNative'] as $method) {
                try {
                    $regex->{$method}("a\0b");
                    self::fail('SQLite must reject a NUL in a quoted group pattern.');
                } catch (\PDOException $error) {
                    self::assertSame('SQLite PDO::quote does not support null bytes', $error->getMessage());
                }
            }
            DB::statement('CREATE TABLE usenet_groups (id INTEGER, name TEXT)');
            DB::statement('CREATE TABLE collections (id INTEGER, fromname TEXT, collectionhash BLOB)');
            DB::statement('CREATE TABLE binaries (name TEXT, totalparts INTEGER, currentparts INTEGER, binaryhash BLOB, collections_id INTEGER)');
            DB::insert('INSERT INTO usenet_groups VALUES (7000, ?)', ['alt.test']);
            DB::insert('INSERT INTO collections VALUES (1, ?, ?)', ['poster', 'old']);
            DB::insert('INSERT INTO binaries VALUES (?, 50, 40, ?, 1)', ['Title [1/00012]', 'binary']);
            foreach ([false, true] as $toggle) {
                $regex->useRustFfi = $toggle;
                $data = $regex->testCollectionRegex('alt.test', '/(?<title>Title)/', 10);
                self::assertCount(1, $data);
                $row = array_values(array_values($data)[0])[0];
                self::assertSame(sha1('Titleposter700000012'), $row['new_collection_hash']);
                self::assertSame('Title [1/00012]', $row['file_name']);
            }
        } finally {
            DB::swap($original);
            if ($resolver === null) {
                UsenetGroup::unsetConnectionResolver();
            } else {
                UsenetGroup::setConnectionResolver($resolver);
            }
        }
    }

    public function test_abi_rejects_malformed_records_and_reports_required_capacity(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_test', 'ssize_t nntmux_regex_release_cleaning(const char *input, size_t len, uint32_t mode, char *out, size_t cap);');
        self::assertNotNull($ffi);
        $out = $ffi->new('char[2]');
        FFI::memcpy($out, '!!', 2);
        // @phpstan-ignore method.notFound
        self::assertSame(-10, $ffi->nntmux_regex_release_cleaning('', 0, 4, $out, 2));
        self::assertSame('!!', FFI::string($out, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_regex_release_cleaning(null, 1, 3, $out, 2));
        foreach ([6, 7, 8, 99] as $mode) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_regex_release_cleaning('abc', 3, $mode, $out, 2));
            self::assertSame('!!', FFI::string($out, 2));
        }
        self::assertSame('', ReleaseNameDisplay::transformNative(3, ''));
        self::assertNull(ReleaseNameDisplay::transformNative(3, "\xff"));
    }

    /**
     * Reproduce with WAVE12_BENCHMARK=/tmp/wave12 php artisan test --compact --filter=test_paired_single_call_benchmark tests/Unit/RustRegexAndReleaseCleaningParityTest.php
     * 1,000 warmup pairs and 20,000 randomized single-call pairs per workload;
     * hrtime includes PHP caller, packing, FFI, scratch and output decoding.
     * Raw nanoseconds and build/source/runtime metadata are retained at the prefix.
     */
    public function test_paired_single_call_benchmark(): void
    {
        $prefix = getenv('WAVE12_BENCHMARK');
        if ($prefix === false || $prefix === '') {
            self::markTestSkipped('Opt-in paired benchmark.');
        }
        $regex = new RegexService;
        $reset = new NntmuxResetPostProcessing;
        $retry = new RetryArchiveInspection;
        $size = new FindSizeMismatchedReleases;
        $original = DB::getFacadeRoot();
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        DB::swap($capsule->getDatabaseManager());
        $summary = [];
        $raw = [];
        mt_srand(121212);
        try {
            foreach (['common', 'uncommon'] as $kind) {
                $long = $kind === 'uncommon';
                $subject = $long ? str_repeat('Collection title without counter ', 40).' (1_of_00099)' : 'Title [1/12]';
                $display = $long ? '[ 001 / 050 ] - &quot;'.str_repeat('Long.Release.', 20).'mkv&quot; yEnc (100/500)' : '[1/12] - "file.mkv" yEnc (1/50)';
                $batch = array_fill(0, $long ? 128 : 12, $display);
                $categories = $long ? array_fill(0, 20, 'TV,Movies,books,-category=musics unknown') : ['TV'];
                $normalized = $long ? array_fill(0, 128, 'unknown') : ['tv', 'all', 'unknown'];
                $archive = $long ? str_repeat('Release.', 100).'r123' : 'release.part01.rar';
                $domain = $long ? str_repeat('title www.example.com text ', 40) : 'title www.example.com text';
                $group = $long ? implode('|', array_fill(0, 64, 'alt.binaries.example*')) : 'alt.binaries.example*';
                $jobs = [
                    'groupQueryString' => [fn () => $regex->groupQueryStringPhp($group), fn () => $regex->groupQueryStringNative($group)],
                    'collectionFileCount' => [fn () => $regex->collectionFileCountPhp($subject), fn () => $regex->collectionFileCountNative($subject)],
                    'collectionHash' => [fn () => $regex->collectionHashPhp($subject, 'title', 'poster', 7000), fn () => $regex->collectionHashNative($subject, 'title', 'poster', 7000)],
                    'clean' => [fn () => ReleaseNameDisplay::cleanPhp($display), fn () => ReleaseNameDisplay::cleanNative($display)],
                    'cleanBatch' => [fn () => array_map(ReleaseNameDisplay::cleanPhp(...), $batch), fn () => ReleaseNameDisplay::cleanBatchNative($batch)],
                    'normalizeCategories' => [fn () => $reset->normalizeCategoriesPhp($categories), fn () => $reset->normalizeCategoriesNative($categories)],
                    'invalidCategories' => [fn () => $reset->invalidCategoriesPhp($normalized), fn () => $reset->invalidCategoriesNative($normalized)],
                    'archiveType' => [fn () => $retry->archiveTypePhp($archive), fn () => $retry->archiveTypeNative($archive)],
                    'stripDomainFromString' => [fn () => $size->stripDomainFromStringPhp($domain), fn () => $size->stripDomainFromStringNative($domain)],
                ];
                foreach ($jobs as $method => [$php, $rust]) {
                    self::assertSame($php(), $rust(), "$method $kind correctness gate");
                    for ($i = 0; $i < 1000; $i++) {
                        $php();
                        $rust();
                    }
                    $samples = [[], []];
                    for ($i = 0; $i < 20000; $i++) {
                        foreach (mt_rand(0, 1) ? [0, 1] : [1, 0] as $side) {
                            $call = $side === 0 ? $php : $rust;
                            $start = hrtime(true);
                            $call();
                            $samples[$side][] = hrtime(true) - $start;
                        }
                    }
                    $raw[$method][$kind] = $samples;
                    $means = array_map(static fn ($v) => array_sum($v) / count($v), $samples);
                    foreach ($samples as &$values) {
                        sort($values);
                    }
                    unset($values);
                    $p99 = [$samples[0][19799], $samples[1][19799]];
                    $summary[$method][$kind] = ['php_mean_ns' => $means[0], 'rust_mean_ns' => $means[1], 'php_p99_ns' => $p99[0], 'rust_p99_ns' => $p99[1], 'mean_speedup' => $means[0] / $means[1], 'p99_speedup' => $p99[0] / $p99[1]];
                }
            }
        } finally {
            DB::swap($original);
        }
        $root = dirname(__DIR__, 2);
        $meta = ['php' => PHP_VERSION, 'platform' => php_uname(), 'samples' => 20000, 'warmup' => 1000, 'seed' => 121212, 'profile' => 'cargo build --release; opt-level=3; lto=true; codegen-units=1', 'php_memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'pcre' => PCRE_VERSION, 'library_sha256' => hash_file('sha256', $root.'/storage/app/nntmux-native/libnntmux_pipeline.dylib'), 'source_sha256' => []];
        foreach (['rust/pipeline-ffi/src/cleaning.rs', 'app/Support/ReleaseNameDisplay.php', 'app/Services/RegexService.php', 'app/Console/Commands/NntmuxResetPostProcessing.php', 'app/Console/Commands/RetryArchiveInspection.php', 'app/Console/Commands/FindSizeMismatchedReleases.php', 'tests/Unit/RustRegexAndReleaseCleaningParityTest.php', 'composer.lock', 'vendor/composer/installed.php', 'rust/pipeline-ffi/Cargo.lock'] as $path) {
            $meta['source_sha256'][$path] = hash_file('sha256', $root.'/'.$path);
        }
        file_put_contents($prefix.'.raw.json', json_encode($raw, JSON_THROW_ON_ERROR));
        file_put_contents($prefix.'.summary.json', json_encode(['metadata' => $meta, 'results' => $summary], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        fwrite(STDERR, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }
}
