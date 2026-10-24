<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnimeProcessor;
use App\Services\NameFixing\PredbIdentityKey;
use App\Services\NameFixing\SeasonPackDetector;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Slice 9.1 admission: two final 10,000-pair runs per workload on Apple M5,
 * PHP 8.5.12/PCRE 10.49/Cargo 1.99.1, release opt-level=3/LTO/codegen-units=1.
 * Mean / p99 speedups, run 1 then run 2 (input and raw timing files are emitted):
 * unclassified common 3.99/4.49, 3.89/7.14; long 1.87/4.57, 3.25/2.56;
 * Unicode 4.30/4.25, 4.11/8.06. Adopted with exact PHP fallback for LF names.
 * ancillary common 2.32/2.20, 2.35/2.00; long 3.75/3.93, 3.78/3.92;
 * Unicode 2.05/1.84, 2.20/2.00. Adopted.
 * folder common 1.84/1.91, 1.86/1.71; Unicode 1.83/1.50, 1.79/1.62;
 * long 8.05/10.08, 7.91/7.58; long miss 8.53/11.14, 8.62/8.43. Adopted.
 * Retained PHP: distinct identities regress to 0.27/0.25 and 0.28/0.25 with
 * an early distinction in 64 names, despite gains for late-distinction batches.
 * Anime common only 1.25/1.20 and 1.27/1.30; Unicode fallback 0.61/0.62 and
 * 0.59/0.62. PreDB Unicode fallback 0.64/0.71 and 0.64/0.61. Native candidates
 * remain testable; entity text is preserved exactly as in the original PHP.
 * These host/fixture measurements do not qualify other architectures or LF-heavy
 * workloads. p99 rank intervals and first wrapper observations are in artifacts.
 */
final class RustSeasonPackAnimeCleaningParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        int32_t nntmux_season_pack_is_unclassified_episode(const char *name, size_t len);
        int32_t nntmux_season_pack_has_distinct_identities_nul(const char *names, size_t len);
        int32_t nntmux_season_pack_is_ancillary_name(const char *name, size_t len);
        int32_t nntmux_season_pack_season_folder(const char *name, size_t len);
        ssize_t nntmux_anime_fix_encoding(const char *text, size_t len, char *out, size_t cap);
        ssize_t nntmux_predb_identity_key_normalize(const char *text, size_t len, char *out, size_t cap);
        C;

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('season_anime_cleaning_parity', self::CDEF);
        self::assertNotNull($ffi, 'The release library must contain all six Slice 9.1 exports.');

        return $ffi;
    }

    private static function anime(): AnimeProcessor
    {
        // The constructor creates lookup services; these pure string methods need none.
        return (new ReflectionClass(AnimeProcessor::class))->newInstanceWithoutConstructor();
    }

    public function test_exports_and_characterization_contract(): void
    {
        self::ffi();
        self::assertTrue(SeasonPackDetector::isUnclassifiedEpisodeNameNative('Show.Episode.5.mkv'));
        self::assertFalse(SeasonPackDetector::isUnclassifiedEpisodeNameNative('Show.S01E01.mkv'));
        self::assertTrue(SeasonPackDetector::hasDistinctEpisodeIdentitiesNative(['Show.S01E01.mkv', 'Show.S01E02.mkv']));
        self::assertTrue(SeasonPackDetector::isAncillaryNameNative('Show.S01E01.srt'));
        $supported = false;
        self::assertSame('Show.S01', SeasonPackDetector::seasonFolderNative(['root/Show.S01/Show.S01E01.mkv'], $supported));
        self::assertTrue($supported);
        self::assertSame("Title\t\n &amp; &#233;", self::anime()->fixEncodingNative("Title\0\x7f\t\n &amp; &#233;"));
        self::assertSame('show.name-grp', PredbIdentityKey::normalizeNative(' Show__Name-GRP '));
    }

    public function test_season_helpers_match_existing_php_over_filename_grammars(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(9091));
        $names = ['', '/', '///', "\0", "\xff", 'Show.S01E01E02.mkv', 'Show.S01E01-E02.mkv', 'Show.S01E01-02.mkv', 'Show.S01E01X.mkv', 'Show.1x01x02.mkv', 'Show.S01E01.nfo', 'sample/Show.S01E01.mkv', 'Show.S01E01.sample.mkv', "Show.S01E01.mkv\n", 'Show.S00001E01.mkv', 'Show.S01E00001.mkv'];
        $prefixes = ['Show.Name', 'Show Name', 'Show_Name', ' show-name ', 'S01E01.Show', '番組', "Byte\xffTitle", '....', 'A', "A\0B", "A\nB"];
        $markers = ['S01E01', 's1.e2', 'S0001_E0002', 'S2026E1234', '1x01', '99x123', '1x1234', 'S01E01E02', 'S01E01-E02', 'S01E01-03', 'S12345E01', 'S01E12345', 'Episode.5', 'EP12', 'E001', 'S01', 'Season.1', 'Complete.Series', 'x264', ''];
        $suffixes = ['', '.1080p.WEB-DL-GRP.mkv', '.part01.rar.par2', '.mkv.rar.zip.001', '.sample.mkv', '.proof.txt', '.nzb', '.srt', '.jpeg', '.pdf', "\n", '.mkv/'];
        for ($i = 0; $i < 3000; $i++) {
            $names[] = ['', '/root/', 'C:\\root\\', ' /Season.2/ '][$random->getInt(0, 3)]
                .$prefixes[$random->getInt(0, count($prefixes) - 1)].'.'
                .$markers[$random->getInt(0, count($markers) - 1)]
                .$suffixes[$random->getInt(0, count($suffixes) - 1)];
        }
        foreach ($names as $name) {
            foreach (['isUnclassifiedEpisodeName', 'isAncillaryName'] as $method) {
                $expected = SeasonPackDetector::{$method.'Php'}($name);
                $native = SeasonPackDetector::{$method.'Native'}($name);
                if ($native !== null) {
                    self::assertSame($expected, $native, $method.' '.bin2hex($name));
                }
                self::assertSame($expected, SeasonPackDetector::{$method}($name));
            }
            $supported = false;
            $folder = SeasonPackDetector::seasonFolderNative([$name], $supported);
            if ($supported) {
                self::assertSame(SeasonPackDetector::seasonFolderPhp([$name]), $folder, bin2hex($name));
            }
            self::assertSame(SeasonPackDetector::seasonFolderPhp([$name]), SeasonPackDetector::seasonFolder([$name]));
        }
        $sets = [[], [''], ['Show.S01E01.mkv', 'show.s1e1.720p.mkv'], ['Show.S01E01.mkv', 'Other.S01E01.mkv'], ['Show.S01E01.mkv', 'Show.S01E01.srt'], ['Show.S01E01.sample.mkv', 'Show.S01E02.proof.mkv']];
        for ($i = 0; $i < 1000; $i++) {
            $set = [];
            for ($j = 0, $count = $random->getInt(0, 12); $j < $count; $j++) {
                $set[] = $names[$random->getInt(0, count($names) - 1)];
            }
            $sets[] = $set;
        }
        foreach ($sets as $set) {
            $expected = SeasonPackDetector::hasDistinctEpisodeIdentitiesPhp($set);
            $actual = SeasonPackDetector::hasDistinctEpisodeIdentitiesNative($set);
            if ($actual !== null) {
                self::assertSame($expected, $actual, json_encode($set, JSON_INVALID_UTF8_SUBSTITUTE));
            }
            self::assertSame($expected, SeasonPackDetector::hasDistinctEpisodeIdentities($set));
        }
        $supported = false;
        self::assertSame('First.S01', SeasonPackDetector::seasonFolderNative(['root/First.S01/Second.S02/file', 'Other.S03/file'], $supported));
        self::assertTrue($supported);
        self::assertNull(SeasonPackDetector::hasDistinctEpisodeIdentitiesNative(["Show.S01E01\0Other.S01E02"]), 'NUL in a filename must not become a batch delimiter.');
    }

    public function test_season_byte_mode_matches_php_for_unicode_and_opaque_filenames(): void
    {
        self::ffi();
        $prefixes = ['番組', "Cafe\u{0301}", "Opaque\xff\xc0", "A\0B", 'ÉTÉ.Σ'];
        for ($byte = 0; $byte <= 255; $byte++) {
            if ($byte !== 10) {
                $prefixes[] = 'Title'.chr($byte).'Name';
            }
        }
        foreach ($prefixes as $prefix) {
            foreach (['.S01E01.mkv', '.Episode.5.mkv', '.sample.mkv', '.srt', '.pdf', '.S01E01.mkv/'] as $suffix) {
                $name = $prefix.$suffix;
                self::assertSame(SeasonPackDetector::isUnclassifiedEpisodeNamePhp($name), SeasonPackDetector::isUnclassifiedEpisodeNameNative($name), bin2hex($name));
                self::assertSame(SeasonPackDetector::isAncillaryNamePhp($name), SeasonPackDetector::isAncillaryNameNative($name), bin2hex($name));
            }
            $paths = ['root/'.$prefix.'.S01/file'];
            $supported = false;
            self::assertSame(SeasonPackDetector::seasonFolderPhp($paths), SeasonPackDetector::seasonFolderNative($paths, $supported));
            self::assertTrue($supported);
        }
    }

    public function test_encoding_and_identity_preserve_unicode_and_entity_semantics(): void
    {
        self::ffi();
        $anime = self::anime();
        $texts = ['', 'Title.Name', "A\0B\x01\t\n\r\x7f", 'Ã¢Â_ÂTitle', 'Ã¢Â Â Title', 'AÃ©B C', 'ÂÂTitle', 'ÃAnything Title', 'Ã Title', "Cafe\u{0301}", 'CAFÉ İ Σ ẞ', '番組 日本語', 'ÀÁÂÃÄÅÆÇ', '&amp; &quot; &#233; &#x1F600;', "A\u{00a0}B\u{2003}C", str_repeat('X', 513), str_repeat('A', 512)];
        $random = new Randomizer(new Mt19937(9191));
        $tokens = ['A', '.', '_', ' ', "\t", "\0", 'Ã', '¢', 'Â', '©', '€', '£', "\u{0301}", 'À', 'Å', 'é', '番', '&amp;', "\n", "\u{00a0}"];
        for ($i = 0; $i < 1500; $i++) {
            $text = '';
            for ($j = 0, $count = $random->getInt(1, 25); $j < $count; $j++) {
                $text .= $tokens[$random->getInt(0, count($tokens) - 1)];
            }
            $texts[] = $text;
        }
        foreach ($texts as $text) {
            $expected = $anime->fixEncodingPhp($text);
            self::assertSame($expected, $anime->fixEncodingNative($text) ?? $anime->fixEncodingPhp($text), bin2hex($text));
            self::assertSame($expected, (new ReflectionMethod(AnimeProcessor::class, 'fixEncoding'))->invoke($anime, $text));
            $supported = false;
            $key = PredbIdentityKey::normalizeNative($text, $supported);
            if ($supported) {
                self::assertSame(PredbIdentityKey::normalizePhp($text), $key, bin2hex($text));
            }
            self::assertSame(PredbIdentityKey::normalizePhp($text), PredbIdentityKey::normalize($text));
        }
        foreach (["\xff", "\xc0\xaf", "\xed\xa0\x80", "\xf4\x90\x80\x80"] as $text) {
            $supported = false;
            self::assertNull(PredbIdentityKey::normalizeNative($text, $supported));
            self::assertTrue($supported);
            self::assertNull(PredbIdentityKey::normalize($text));
            self::assertNull($anime->fixEncodingNative($text));
        }
    }

    public function test_raw_abi_statuses_capacity_and_batched_ancillary_count(): void
    {
        $ffi = self::ffi();
        $buf = $ffi->new('char[32]');
        FFI::memset($buf, 90, 32);
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(-1, $ffi->nntmux_anime_fix_encoding(null, 1, $buf, 32));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(-1, $ffi->nntmux_predb_identity_key_normalize('a', 1, null, 1));
        foreach (['nntmux_anime_fix_encoding', 'nntmux_predb_identity_key_normalize'] as $symbol) {
            // @phpstan-ignore method.notFound, method.notFound (domain C ABI alternatives)
            self::assertSame(-8, $ffi->{$symbol}('Title', 5, $buf, 4));
            self::assertSame(str_repeat('Z', 32), FFI::string($buf, 32));
            // @phpstan-ignore method.notFound, method.notFound (domain C ABI alternatives)
            self::assertSame(5, $ffi->{$symbol}('Title', 5, $buf, 5));
            self::assertSame($symbol === 'nntmux_anime_fix_encoding' ? 'Title' : 'title', FFI::string($buf, 5));
            self::assertSame('Z', FFI::string(FFI::addr($buf[5]), 1));
            FFI::memset($buf, 90, 32);
        }
        $names = "Show.S01E01.mkv\0Show.S01E02.mkv\0Show.nfo\0sample.mkv\0";
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(5, $ffi->nntmux_season_pack_has_distinct_identities_nul($names, strlen($names)));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(0, $ffi->nntmux_season_pack_has_distinct_identities_nul(null, 0));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(-1, $ffi->nntmux_season_pack_is_ancillary_name(null, 1));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(-2, $ffi->nntmux_season_pack_season_folder('Show.S01/file', 7));
    }

    public function test_missing_library_falls_back_to_existing_php(): void
    {
        self::ffi();
        foreach ([SeasonPackDetector::class, AnimeProcessor::class, PredbIdentityKey::class] as $class) {
            $property = new ReflectionProperty($class, 'cleaningFfi');
            $old = $property->getValue();
            try {
                $property->setValue(null, false);
                self::assertSame(SeasonPackDetector::isUnclassifiedEpisodeNamePhp('Show.EP12'), SeasonPackDetector::isUnclassifiedEpisodeName('Show.EP12'));
                self::assertSame(SeasonPackDetector::hasDistinctEpisodeIdentitiesPhp(['Show.S01E01', 'Show.S01E02']), SeasonPackDetector::hasDistinctEpisodeIdentities(['Show.S01E01', 'Show.S01E02']));
                self::assertSame(SeasonPackDetector::isAncillaryNamePhp('Show.nfo'), SeasonPackDetector::isAncillaryName('Show.nfo'));
                self::assertSame(SeasonPackDetector::seasonFolderPhp(['Root/Show.S01/file']), SeasonPackDetector::seasonFolder(['Root/Show.S01/file']));
                if ($class === AnimeProcessor::class) {
                    self::assertNull(self::anime()->fixEncodingNative('Title'));
                    self::assertSame('Title', (new ReflectionMethod(AnimeProcessor::class, 'fixEncoding'))->invoke(self::anime(), 'Title'));
                }
                self::assertSame(PredbIdentityKey::normalizePhp(' A__B '), PredbIdentityKey::normalize(' A__B '));
            } finally {
                $property->setValue(null, $old);
            }
        }
    }

    /**
     * Admission protocol: 10,000 pairs/cell, identical inputs, seeded random order,
     * one complete PHP caller per observation, 400 warmup calls/path. p99 is the
     * nearest rank of individual calls, not a percentile of batch means. Raw order,
     * input indices, timings, source/library hashes and uncertainty are retained.
     * Run: php artisan test --compact tests/Unit/RustSeasonPackAnimeCleaningParityTest.php --group=benchmark
     */
    #[Group('benchmark')]
    public function test_paired_randomized_caller_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            self::markTestSkipped('Opt-in admission experiment; run this file with --group=benchmark.');
        }
        self::ffi();
        $anime = self::anime();
        $random = new Randomizer(new Mt19937(90912026));
        $folderCandidate = SeasonPackDetector::seasonFolder(...);
        $keyCandidate = static function (string $text): ?string {
            $supported = false;
            $result = PredbIdentityKey::normalizeNative($text, $supported);

            return $supported ? $result : PredbIdentityKey::normalizePhp($text);
        };
        $callers = [
            'unclassified' => [SeasonPackDetector::isUnclassifiedEpisodeNamePhp(...), SeasonPackDetector::isUnclassifiedEpisodeName(...)],
            'distinct' => [SeasonPackDetector::hasDistinctEpisodeIdentitiesPhp(...), static fn (array $names): bool => SeasonPackDetector::hasDistinctEpisodeIdentitiesNative($names) ?? SeasonPackDetector::hasDistinctEpisodeIdentitiesPhp($names)],
            'ancillary' => [SeasonPackDetector::isAncillaryNamePhp(...), SeasonPackDetector::isAncillaryName(...)],
            'folder' => [SeasonPackDetector::seasonFolderPhp(...), $folderCandidate],
            'anime' => [$anime->fixEncodingPhp(...), static fn (string $text): string => $anime->fixEncodingNative($text) ?? $anime->fixEncodingPhp($text)],
            'predb' => [PredbIdentityKey::normalizePhp(...), $keyCandidate],
        ];
        $fixtures = [];
        for ($i = 0; $i < 32; $i++) {
            $show = 'Some.Show.'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26);
            $fixtures['unclassified/common'][] = $show.['.S01E02.mkv', '.Episode.5.mkv', '.EP012.mkv', '.S01E02.sample.mkv'][$i % 4];
            $fixtures['unclassified/unicode'][] = '番組.ÉTÉ.'.($i % 2 === 0 ? 'Episode.5.mkv' : 'S01E02.mkv');
            $fixtures['unclassified/uncommon'][] = str_repeat('Long.Title-', 24).$show.['.S1234E9999.Pilot.mkv', '.Episode.9999.part01.rar.par2', '.S01E01-E02.mkv', '.Complete.Series.nfo'][$i % 4];
            $fixtures['distinct/common'][] = [$show.'.S01E01.1080p.mkv', $show.'.S01E02.1080p.mkv', $show.'.nfo'];
            $fixtures['distinct/uncommon'][] = array_merge(array_fill(0, 63, $show.'.S01E01.1080p.WEB-DL-GROUP.part01.rar'), [$show.'.S01E02.1080p.mkv']);
            $fixtures['distinct/early_exit'][] = array_merge([$show.'.S01E01.mkv', $show.'.S01E02.mkv'], array_fill(0, 62, $show.'.S01E02.1080p.mkv'));
            $fixtures['distinct/ancillary'][] = array_fill(0, 64, $show.'.S01E01.srt');
            $fixtures['ancillary/common'][] = $show.['.S01E01.mkv', '.S01E01.srt', '.sample.mkv', '.nfo'][$i % 4];
            $fixtures['ancillary/unicode'][] = '番組.ÉTÉ.'.($i % 2 === 0 ? 'S01E02.mkv' : 'S01E02.srt');
            $fixtures['ancillary/uncommon'][] = 'C:\\'.str_repeat('Long.Directory\\', 24).str_repeat('Long.Title-', 24).$show.['.S01E01.part01.rar', '.S01E01.srt', '.proof.part01.rar', '.unknown'][$i % 4];
            $fixtures['folder/common'][] = ['root/'.$show.'.S01/'.$show.'.S01E01.mkv'];
            $fixtures['folder/unicode'][] = ['日本語/番組.ÉTÉ.S01/file'];
            $fixtures['folder/uncommon'][] = [str_repeat('ordinary/folder/', 20).$show.'.S2026/'.$show.'.S2026E01.mkv'];
            $fixtures['folder/miss'][] = [str_repeat('ordinary/folder/', 20).$show.'.S01E01.mkv'];
            $fixtures['anime/common'][] = ['Anime Title '.$i, "Anime\0Title\x7f ".$i, 'Ã¢Â_ÂAnime Title '.$i, 'AÃ©B C '.$i][$i % 4];
            $fixtures['anime/uncommon'][] = str_repeat(['Anime\Title &amp; ', 'Ã¢Â_ÂA ', "Name\0\x7f ", 'ÂÂTitle '][$i % 4], 64).$i;
            $fixtures['anime/unicode_fallback'][] = ["Cafe\u{0301} 日本語 ".$i, 'Été &amp; 番組 '.$i][$i % 2];
            $fixtures['predb/common'][] = ' '.$show.'__S01E01  1080p.WEB-DL-GROUP ';
            $fixtures['predb/uncommon'][] = str_repeat('Title__NAME  .', 32).$i;
            $fixtures['predb/unicode_fallback'][] = ["Cafe\u{0301}__日本語 ".$i, 'İSTANBUL__Σ ÉTÉ '.$i][$i % 2];
        }
        $summary = $samples = $startup = [];
        $gates = array_fill_keys(array_keys($callers), true);
        foreach ($fixtures as $cell => $inputs) {
            [$method] = explode('/', $cell);
            [$php, $rust] = $callers[$method];
            foreach ([$php, $rust] as $path => $call) {
                $start = hrtime(true);
                $call($inputs[0]);
                $startup[$cell][$path] = hrtime(true) - $start;
                for ($warmup = 0; $warmup < 400; $warmup++) {
                    $call($inputs[$warmup % 32]);
                }
            }
            foreach ($inputs as $input) {
                self::assertSame($php($input), $rust($input), $cell.' correctness gate');
            }
            $raw = ['php' => [], 'rust' => [], 'order' => [], 'input' => []];
            for ($pair = 0; $pair < 10000; $pair++) {
                $index = $random->getInt(0, 31);
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                $raw['input'][] = $index;
                $raw['order'][] = $order[0];
                foreach ($order as $path) {
                    $call = $path === 'php' ? $php : $rust;
                    $start = hrtime(true);
                    $call($inputs[$index]);
                    $raw[$path][] = hrtime(true) - $start;
                }
            }
            $stats = [];
            foreach (['php', 'rust'] as $path) {
                $sorted = $raw[$path];
                sort($sorted, SORT_NUMERIC);
                $stats[$path] = [
                    'mean_ns' => array_sum($sorted) / 10000,
                    'p99_ns' => $sorted[9899],
                    // Binomial rank approximation: np +/- 1.96 sqrt(np(1-p)).
                    'p99_rank_95_percent_interval_ns' => [$sorted[9880], $sorted[9919]],
                ];
            }
            $mean = $stats['php']['mean_ns'] / $stats['rust']['mean_ns'];
            $p99 = $stats['php']['p99_ns'] / $stats['rust']['p99_ns'];
            $summary[$cell] = $stats + ['mean_speedup' => $mean, 'p99_speedup' => $p99];
            $gates[$method] = $gates[$method] && $mean >= 1.5 && $p99 >= 1.5;
            $samples[$cell] = $raw;
        }
        $hashes = [];
        foreach (['app/Services/NameFixing/SeasonPackDetector.php', 'app/Services/AnimeProcessor.php', 'app/Services/NameFixing/PredbIdentityKey.php', 'tests/Unit/RustSeasonPackAnimeCleaningParityTest.php', 'rust/pipeline-ffi/src/cleaning.rs', 'rust/pipeline-ffi/src/filename.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $file) {
            $hashes[$file] = hash_file('sha256', __DIR__.'/../../'.$file);
        }
        $artifact = sys_get_temp_dir().'/nntmux-wave9-slice91-'.getmypid().'.json';
        file_put_contents($artifact, json_encode([
            'protocol' => ['baseline_commit' => '6c2c99036ead3418158f6a4d79ea1846524f3f79', 'seed' => 90912026, 'pairs_per_cell' => 10000, 'warmup_calls_per_path' => 400, 'calls_per_observation' => 1, 'clock' => 'hrtime', 'failures_or_timeouts' => [], 'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml', 'profile' => 'opt-level=3 lto=true codegen-units=1', 'reproduce' => 'php artisan test --compact tests/Unit/RustSeasonPackAnimeCleaningParityTest.php --group=benchmark'],
            'runtime' => ['php' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'pcre_jit' => ini_get('pcre.jit'), 'os' => php_uname(), 'hardware' => 'Apple M5 Mac17,3, 10 CPUs, 32 GiB RAM', 'resource_limits' => ['php_memory' => ini_get('memory_limit'), 'cpu' => 'host scheduler; no explicit CPU quota'], 'files_sha256' => $hashes],
            'first_observation_ns_not_startup_admission' => $startup, 'fixtures' => $fixtures, 'summary' => $summary, 'admitted_methods' => $gates, 'raw_samples' => $samples,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        fwrite(STDERR, "\nSlice 9.1 raw benchmark: ".$artifact."\n");
        foreach ($summary as $cell => $stats) {
            fwrite(STDERR, sprintf("%s: mean %.2fx, p99 %.2fx\n", $cell, $stats['mean_speedup'], $stats['p99_speedup']));
        }
        fwrite(STDERR, json_encode($gates, JSON_THROW_ON_ERROR)."\n");
        self::assertCount(count($fixtures), $summary);
        foreach (['unclassified', 'ancillary', 'folder'] as $method) {
            self::assertTrue($gates[$method], $method.' must qualify in every measured cell; raw data: '.$artifact);
        }
    }
}
