<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionsCleaningService;
use App\Services\RegexService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Admission experiment, not an enabled production replacement. Checked-out PHP is
 * retained unchanged because the required cold-cache mean/p99 gains did not qualify.
 *
 * Tested on Mac17,3/10 CPUs/32 GiB, PHP8.5.12/PCRE10.49, Cargo1.99.1.
 * The final admission command measures individual-call mean and p99 latencies;
 * results, including failures, are printed and retained in the raw artifact.
 *
 * Raw paired order/timings, source/library hashes and p99 rank intervals are saved
 * to the artifact printed by the opt-in benchmark. Results describe this fixture
 * and host; fallback-heavy workloads and other architectures remain unqualified.
 */
final class RustCollectionsCleaningParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_collections_clean_generic_pre_normalize(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_collections_music_subject(const char *input, size_t len, const char *group, size_t group_len, char *out, size_t cap);
        ssize_t nntmux_collections_enhance_short_subject(const char *input, size_t len, const char *clean, size_t clean_len, char *out, size_t cap);
        C;

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('collections_cleaning_test', self::CDEF);
        self::assertNotNull($ffi, 'Build and install the release library with the collections cleaning exports.');

        return $ffi;
    }

    public function test_native_exports_are_available_and_clean_common_subjects(): void
    {
        $ffi = self::ffi();
        $subject = '[1/50] Some.Show.S01E02.1080p.WEB-DL-GROUP "release.part001.rar" yEnc (1/50)';
        $out = $ffi->new('char[4096]');
        // @phpstan-ignore method.notFound (domain C ABI)
        $length = $ffi->nntmux_collections_clean_generic_pre_normalize($subject, strlen($subject), $out, 4096);
        self::assertIsInt($length);
        self::assertGreaterThanOrEqual(0, $length, 'Common subject must execute in Rust, not silently fall back.');
        $reference = new CollectionsCleaningPhpReference;
        self::assertSame($reference->run($subject, '', 'cleanGenericSubject'), (new CollectionsCleaningNativeProbe)->run($subject, '', 'cleanGenericSubject'));
    }

    public function test_every_regex_branch_and_public_pipeline_matches_frozen_php(): void
    {
        self::ffi();
        $reference = new CollectionsCleaningPhpReference;
        $native = new CollectionsCleaningNativeProbe;
        $production = new CollectionsCleaningProductionProbe;
        foreach (self::subjects() as $index => $subject) {
            foreach (['', 'alt.binaries.test', 'alt.binaries.music', 'alt.binaries.flac', 'alt.binaries.lossless', 'alt.binaries.mp3', 'alt.binaries.sounds', 'alt.binaries.MUSIC', 'alt.musicology', "alt.\xffmusic"] as $group) {
                foreach (['generic', 'cleanGenericSubject', 'cleanMusicSubject', 'musicSubject', 'enhanceShortSubject', 'isMusicGroup', 'normalizeString', 'collectionsCleaner'] as $method) {
                    self::assertSame($reference->run($subject, $group, $method), $production->run($subject, $group, $method));
                    self::assertSame($reference->run($subject, $group, $method), $native->run($subject, $group, $method), $method.' subject #'.$index.' '.bin2hex($subject).' group '.$group);
                }
            }
        }
    }

    public function test_mutated_extension_pattern_uses_exact_php_behavior(): void
    {
        $reference = new CollectionsCleaningPhpReference;
        $native = new CollectionsCleaningNativeProbe;
        foreach (['NEEDLE', '".*?"', '(?:foo|bar)'] as $pattern) {
            $reference->e0 = $native->e0 = $pattern;
            foreach (['NEEDLE "Title.part01.rar" yEnc', 'foo bar "Album" [1/2]', '"abc" NEEDLE'] as $subject) {
                foreach (['generic', 'cleanGenericSubject', 'cleanMusicSubject', 'enhanceShortSubject'] as $method) {
                    self::assertSame($reference->run($subject, 'alt.music', $method), $native->run($subject, 'alt.music', $method));
                }
            }
        }
    }

    public function test_arbitrary_bytes_and_encoding_inputs_match_php(): void
    {
        $reference = new CollectionsCleaningPhpReference;
        $native = new CollectionsCleaningNativeProbe;
        mt_srand(20261024);
        for ($i = 0; $i < 2500; $i++) {
            $subject = '';
            for ($j = mt_rand(0, 160); $j > 0; $j--) {
                $subject .= chr(mt_rand(0, 255));
            }
            if ($i % 2 === 0) {
                $subject .= ' "file.part01.rar" yEnc (1/50)';
            }
            foreach (['generic', 'enhanceShortSubject', 'musicSubject'] as $method) {
                $group = $i % 3 === 0 ? 'alt.music' : 'alt.test';
                self::assertSame($reference->run($subject, $group, $method), $native->run($subject, $group, $method), $method.' bytes '.bin2hex($subject));
            }
        }
    }

    public function test_certified_grammar_and_raw_abi_match_chained_pcre_replacements(): void
    {
        $ffi = self::ffi();
        $out = $ffi->new('char[16384]');
        $raw = new CollectionsCleaningRawReference;
        $reference = new CollectionsCleaningPhpReference;
        $native = new CollectionsCleaningNativeProbe;
        $random = new Randomizer(new Mt19937(6242026));
        $titles = ['A1of2', 'A.12341of2', 'Title.Name-GRP', 'Title1of2.Name', 'Title1234567', 'Artist_Album_2026', '1024KB', '1234abcd', 'Title.123', 'Title.1234', 'Title_1Of2.foo', 'Title-part', 'Title.part3.foo', 'Title.1of2.foo', 'AutoRarPar123', 'Title-sample', 'Title--proof', 'Title-1-2.Name', '3D.Title.Name', 'Title.Classic', 'Title.flac', 'Title.1080p'];
        for ($i = 0; $i < 6000; $i++) {
            $title = $titles[$random->getInt(0, count($titles) - 1)];
            $file = $title.['', '.rar', '.part01.rar', '.PART123456.rar', '.part', '.mp3', '.vol01+02.par2', '-sample.rar', '.001', '.1234"'][$random->getInt(0, 9)];
            $subject = ['', '[1/2] ', '(1/2) - ', '[12345/54321] ', "\f\0\t", '  '][$random->getInt(0, 5)]
                .['', 'Prefix.Title ', '1234abcd ', 'Prefix1of2.Name '][$random->getInt(0, 3)]
                .($random->getInt(0, 1) ? '"'.$file.'"' : $file)
                .['', ' yEnc', ' yENC (1/50)', ' (1/50)', "\f", "\0\t", " \n", ' 1024KB '][$random->getInt(0, 7)];
            // @phpstan-ignore method.notFound (domain C ABI)
            $length = $ffi->nntmux_collections_clean_generic_pre_normalize($subject, strlen($subject), $out, 16384);
            self::assertIsInt($length);
            if ($length >= 0) {
                self::assertSame($raw->run($subject, '', 'cleanGenericSubject')['name'], FFI::string($out, $length), 'Raw generic '.bin2hex($subject));
            } else {
                self::assertSame(-1, $length);
            }
            // @phpstan-ignore method.notFound (domain C ABI)
            $length = $ffi->nntmux_collections_music_subject($subject, strlen($subject), '.music', 6, $out, 16384);
            self::assertIsInt($length);
            if ($length >= 0) {
                self::assertSame($raw->run($subject, '.music', 'cleanMusicSubject')['name'], FFI::string($out, $length), 'Raw music '.bin2hex($subject));
            } else {
                self::assertContains($length, [-1, -3]);
            }
            foreach (['alt.test', 'alt.music'] as $group) {
                self::assertSame($reference->run($subject, $group), $native->run($subject, $group), 'Grammar '.bin2hex($subject).' '.$group);
            }
        }
        foreach (['.flac', '.lossless', '.mp3', '.music', '.sounds', '.musicology', '.MUSIC', '', 'music', "\xff.music"] as $group) {
            $subject = 'Plain.Title';
            // @phpstan-ignore method.notFound (domain C ABI)
            $length = $ffi->nntmux_collections_music_subject($subject, strlen($subject), $group, strlen($group), $out, 16384);
            self::assertSame(preg_match('/\.(flac|lossless|mp3|music|sounds)/', $group) === 1, $length !== -2);
        }
        foreach (['', 'clean', "\xff\0prefix", str_repeat('Long clean ', 100)] as $clean) {
            foreach (['"abc.def" yEnc (1/2)', '"a" "b"', 'a_b-c.d', '""', "\xff\0!", "a\nb"] as $subject) {
                // @phpstan-ignore method.notFound (domain C ABI)
                $length = $ffi->nntmux_collections_enhance_short_subject($subject, strlen($subject), $clean, strlen($clean), $out, 16384);
                self::assertIsInt($length);
                if ($length >= 0) {
                    self::assertSame($reference->run($subject, '', 'enhanceShortSubject', $clean), FFI::string($out, $length));
                }
            }
        }
    }

    public function test_unavailable_library_and_changed_pcre_limits_preserve_fallback(): void
    {
        $library = new ReflectionProperty(CollectionsCleaningNativeProbe::class, 'collectionsLibrary');
        $previousLibrary = $library->getValue();
        $backtrack = ini_get('pcre.backtrack_limit');
        try {
            $library->setValue(null, false);
            $subject = '[1/50] "Title.part01.rar" yEnc (1/50)';
            self::assertSame((new CollectionsCleaningPhpReference)->run($subject, 'alt.music'), (new CollectionsCleaningNativeProbe)->run($subject, 'alt.music'));
            $library->setValue(null, $previousLibrary);
            ini_set('pcre.backtrack_limit', '1');
            $outcomes = [];
            foreach ([new CollectionsCleaningPhpReference, new CollectionsCleaningNativeProbe] as $cleaner) {
                try {
                    $outcomes[] = [$cleaner->run($subject, 'alt.test'), preg_last_error()];
                } catch (\TypeError $error) {
                    $outcomes[] = [$error::class, preg_last_error()];
                }
            }
            self::assertSame($outcomes[0], $outcomes[1]);
        } finally {
            $library->setValue(null, $previousLibrary);
            ini_set('pcre.backtrack_limit', $backtrack);
        }
    }

    public function test_normalization_memo_reuse_and_eviction_preserve_encoding_detection(): void
    {
        $native = new CollectionsCleaningNativeProbe;
        self::clearMemo(CollectionsCleaningService::class);
        $special = 'S/3/S*Z%7}';
        $expected = mb_convert_encoding($special, 'UTF-8', mb_list_encodings());
        self::assertSame($expected, $native->run($special, '', 'normalizeString'));
        self::assertSame($expected, $native->run('  '.$special.'  ', '', 'normalizeString'));
        $memo = new ReflectionProperty(CollectionsCleaningService::class, 'normalizeMemo');
        self::assertCount(1, $memo->getValue());
        for ($i = 1; $i < 4096; $i++) {
            $subject = 'Memo fixture number '.$i;
            self::assertSame(mb_convert_encoding($subject, 'UTF-8', mb_list_encodings()), $native->run($subject, '', 'normalizeString'));
        }
        self::assertCount(4096, $memo->getValue());
        $native->run('Entry that causes eviction', '', 'normalizeString');
        self::assertCount(1, $memo->getValue());
        self::assertSame($expected, $native->run($special, '', 'normalizeString'));
    }

    /**
     * Frozen protocol: 5,000 paired observations of individual generic() calls per
     * group/cache/workload cell, randomized order, 200 warmup calls per path.
     * Cold observations each start with empty normalization memos (reset outside timing);
     * warm observations reuse the exact same normalized strings. FFI, allocation,
     * fallback, normalization and music enhancement costs are all inside timing.
     * Raw paired samples and runtime/source metadata are retained outside the repo.
     */
    #[Group('benchmark')]
    public function test_paired_randomized_full_caller_performance(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            self::markTestSkipped('Opt-in admission experiment: run this file with --group=benchmark; production retains PHP until every cell qualifies.');
        }
        self::ffi();
        $reference = new CollectionsCleaningPhpReference;
        $native = new CollectionsCleaningNativeProbe;
        $random = new Randomizer(new Mt19937(20261024));
        $startup = [];
        foreach (['php' => $reference, 'rust' => $native] as $path => $cleaner) {
            self::clearMemo($path === 'php' ? CollectionsCleaningPhpReference::class : CollectionsCleaningService::class);
            $start = hrtime(true);
            $cleaner->run('[1/50] Some.Show.S01E02.1080p.WEB-DL-GROUP "release.part001.rar" yEnc (1/50)', 'alt.binaries.test');
            $startup[$path] = hrtime(true) - $start;
        }
        $samples = [];
        $summary = [];
        $gates = [];
        foreach (['common', 'uncommon'] as $workload) {
            foreach (['alt.binaries.test', 'alt.binaries.music'] as $group) {
                foreach (['warm', 'cold'] as $cache) {
                    $key = $workload.'/'.$group.'/'.$cache;
                    $subjects = [];
                    for ($i = 0; $i < 32; $i++) {
                        $title = $group === 'alt.binaries.music' ? 'Artist_Album_2026_GRP' : 'Some.Show.S01E02.1080p.WEB-DL-GROUP';
                        if ($workload === 'uncommon') {
                            $title = str_repeat('Long.Release_Name-', 16).$title;
                        }
                        $title .= '.Set'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26);
                        $subjects[] = '['.($i + 1).'/50] '.$title.' "release.part'.sprintf('%03d', $i + 1).'.rar" yEnc (1/50)';
                    }
                    foreach ($subjects as $subject) {
                        self::assertSame($reference->run($subject, $group), $native->run($subject, $group));
                    }
                    for ($i = 0; $i < 200; $i++) {
                        $reference->run($subjects[$i % 32], $group);
                        $native->run($subjects[$i % 32], $group);
                    }
                    $raw = ['php' => [], 'rust' => [], 'order' => []];
                    for ($pair = 0; $pair < 5000; $pair++) {
                        $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                        $raw['order'][] = $order[0];
                        foreach ($order as $path) {
                            $cleaner = $path === 'php' ? $reference : $native;
                            if ($cache === 'cold') {
                                self::clearMemo($path === 'php' ? CollectionsCleaningPhpReference::class : CollectionsCleaningService::class);
                            }
                            $start = hrtime(true);
                            $cleaner->run($subjects[$pair % 32], $group);
                            $raw[$path][] = hrtime(true) - $start;
                        }
                    }
                    $metrics = [];
                    foreach (['php', 'rust'] as $path) {
                        $sorted = $raw[$path];
                        sort($sorted, SORT_NUMERIC);
                        $metrics[$path] = [
                            'mean_ns' => array_sum($sorted) / count($sorted),
                            'p99_ns' => $sorted[(int) ceil(count($sorted) * .99) - 1],
                            'p99_rank_95_percent_interval_ns' => [$sorted[4935], $sorted[4964]],
                        ];
                    }
                    $mean = $metrics['php']['mean_ns'] / $metrics['rust']['mean_ns'];
                    $p99 = $metrics['php']['p99_ns'] / $metrics['rust']['p99_ns'];
                    $summary[$key] = $metrics + ['mean_speedup' => $mean, 'p99_speedup' => $p99];
                    $samples[$key] = $raw;
                    $gates[$key] = $mean >= 1.5 && $p99 >= 1.5;
                }
            }
        }
        $artifact = sys_get_temp_dir().'/nntmux-collections-benchmark-'.getmypid().'.json';
        $files = [];
        foreach (['tests/Unit/RustCollectionsCleaningParityTest.php', 'app/Services/CollectionsCleaningService.php', 'rust/pipeline-ffi/src/cleaning.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $file) {
            $files[$file] = hash_file('sha256', __DIR__.'/../../'.$file);
        }
        file_put_contents($artifact, json_encode([
            'protocol' => ['seed' => 20261024, 'pairs_per_cell' => 5000, 'calls_per_observation' => 1, 'warmup_calls_per_path' => 200, 'clock' => 'hrtime', 'failures_or_timeouts' => [], 'profile' => 'cargo release opt-level=3 lto=true codegen-units=1', 'build_command' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml', 'reproduce' => 'php artisan test --compact tests/Unit/RustCollectionsCleaningParityTest.php --group=benchmark', 'baseline_commit' => '1a8231ab1fde6313a2142260cc225b3962fa2d72'],
            'runtime' => ['php' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'pcre_jit' => ini_get('pcre.jit'), 'os' => php_uname(), 'memory_limit' => ini_get('memory_limit'), 'files_sha256' => $files],
            'first_call_ns_single_observation' => $startup, 'summary' => $summary, 'raw_samples' => $samples,
        ], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nCollections benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertNotContains(false, $gates, 'Every full-caller mean and p99 workload must reach 1.5x; raw evidence: '.$artifact);
    }

    /** @param class-string $class */
    private static function clearMemo(string $class): void
    {
        (new ReflectionProperty($class, 'normalizeMemo'))->setValue(null, []);
    }

    /** @return list<string> */
    private static function subjects(): array
    {
        $subjects = [
            '', 'plain subject', 'A1of2.rar', 'A1of2.part01.rar', 'A.12341of2.rar', 'abcdefgyEnc', '1234567890', '12345678901', 'Some.Show.S01E02.1080p.WEB-DL-GROUP.part01.rar" yEnc (1/50)',
            '1.23 kb', '123,456 Mb', '123/456 gb', '] 1024KB yENC', ' 1024KB ', '" 123 bytes', ' 1 byte',
            '-123.45 GB - yenc', ' 1234 B ', ' (ddd,123 {K,M,G}B) ', 'yEnc 123k', '{123 yEnc bytes}', 'yEnc 123 tail', '(123 kb) yEnc', '(123bytes) yEnc',
            'AutoRarPar1', 'AutoRarPar123456', '(123) yEnc', '123Amateur', '123Classic', ' 1234abcd ', 'xvol12+34', '.part001',
            'Title (01) - Track.mp3', 'Title 01 - 02.Track.mp3', 'Title - 02.Track.mp3', 'Title 2001 01 - Track.mp3',
            'Title - 01-Track.mp3', 'Title 01. track.mp3', 'Title 01 of 02.mp3', 'Title 1of2.mp3',
            '(1/2)', ')12345-54321:', '[1234 of 4321]', ' 1_of_2 ', '(1|2)', 'abcd-1-2.', '-1-2.', ' 1 of 2.', ' 1/2', '1of2.', '1/2 Title', '1 - of 2',
            'Title - cover.jpg"', 'Title (2001)_cover.jpg', 'Title 2001-cover.jpg', 'Title playlist.mu3"',
            'Title 001.part12345.rar', 'Title 12345 .rar', 'Title sample.rar', 'Title - Partie 123.rar',
            'Title .001"', 'Title .001x', 'Title .001 ', 'Title-456.mp3', 'Title .par 2"', 'Title .par"', 'Title .vol01+02.par2',
            'Title 01.mp3', 'Title 123-456.mp3', 'Title 123.pdf', 'Title .part1234.',
            'Title-sample.part01.rar"', 'Title-proof.par2"', 'Title_thumbs.7z001.rev"', 'Title.vol12+34.par2"', '123,456 kb [1/2] "Title-proof.part01.rar" yEnc (1/50)', 'Title.abcd"', 'Title"',
            'Artist-Album "01-one.flac" "02-two.flac" yEnc', '""', '"a" "b"', '"a" "unterminated',
            'abcdefghij-GRP "01-track.flac" yEnc', "abcdefghij-GRP \"01-track.flac\" yEnc\n", 'abcdefghi-GRP "01-track.flac" yEnc',
            'abcdefghij-GRP "1-track.flac" yEnc', 'abcdefghij-GRP "01-track.flac" yenc', 'abcdefghij-GRP "01-" yEnc',
            '"ABC-one" "XYZ-two" #$%!', "prefix\n\"ABC-one\" yEnc", '"abc"', '"a"b"',
            " \tfoo\tbar\f\f baz\0 ", "\0\x0b\ntrim\r\0", "\ftrim\f", "single\fspace", "\xff\xc3\x28 \"café.rar\" yEnc", 'S/3/S*Z%7}',
            str_repeat('a', 5000).' "file.rar" yEnc',
        ];
        foreach (['7z', 'avi', 'diz', 'doc', 'docx', 'epub', 'idx', 'iso', 'jpg', 'm3u', 'm4a', 'mds', 'mkv', 'mobi', 'mp4', 'nfo', 'nzb', 'pdf', 'rar', 'rev', 'rtf', 'r01', 'sfv', 'srs', 'srr', 'sub', 'txt', 'xls', 'zip', 'zz', 'zzz'] as $extension) {
            $subjects[] = 'Title.'.strtoupper($extension).'" yEnc';
        }
        foreach (['flac', 'lossless', 'mp3', 'music', 'sounds', 'FLAC'] as $group) {
            $subjects[] = 'alt.binaries.'.$group;
        }

        return $subjects;
    }
}

/** Test access only: no database reads, both implementations exercise the same public caller. */
trait CollectionsCleaningTestAccess
{
    public function __construct()
    {
        $this->e0 = CollectionsCleaningService::REGEX_FILE_EXTENSIONS;
        $this->e1 = CollectionsCleaningService::REGEX_FILE_EXTENSIONS.CollectionsCleaningService::REGEX_END;
        $this->e2 = CollectionsCleaningService::REGEX_FILE_EXTENSIONS.CollectionsCleaningService::REGEX_SUBJECT_SIZE.CollectionsCleaningService::REGEX_END;
        $this->_regexes = new class extends RegexService
        {
            public function tryRegex(string $subject, string $groupName): string
            {
                return '';
            }
        };
    }

    /** @return array<string, mixed>|bool|string */
    public function run(string $subject, string $group, string $method = 'generic', string $clean = 'clean'): array|bool|string
    {
        $this->subject = $subject;
        $this->groupName = $group;

        return match ($method) {
            'generic' => $this->generic(),
            'cleanGenericSubject' => $this->cleanGenericSubject(),
            'cleanMusicSubject' => $this->cleanMusicSubject(),
            'enhanceShortSubject' => $this->enhanceShortSubject($clean),
            'musicSubject' => $this->musicSubject(),
            'isMusicGroup' => $this->isMusicGroup(),
            'normalizeString' => $this->normalizeString($subject),
            'collectionsCleaner' => $this->collectionsCleaner($subject, $group),
            default => throw new \LogicException('Unknown test method'),
        };
    }
}

/** Experimental bridge only: production keeps PHP because cold-cache admission failed. */
final class CollectionsCleaningNativeProbe extends CollectionsCleaningService
{
    use CollectionsCleaningTestAccess;

    private static FFI|false|null $collectionsLibrary = null;

    private static ?CData $collectionsScratch = null;

    private static int $collectionsScratchCapacity = 0;

    /** @return array<string, mixed> */
    protected function cleanGenericSubject(): array
    {
        $normalized = $this->nativeSubject(0);

        return $normalized === null ? parent::cleanGenericSubject() : [
            'id' => self::REGEX_GENERIC_MATCH,
            'name' => $this->normalizeString($normalized),
        ];
    }

    /** @return array<string, mixed> */
    protected function cleanMusicSubject(): array
    {
        $normalized = $this->nativeSubject(1);
        if ($normalized === null) {
            return parent::cleanMusicSubject();
        }
        $clean = $this->normalizeString($normalized);
        if (strlen($clean) <= 10 || preg_match('/^[\-a-z0-9$ ]{1,7}yEnc$/i', $clean)) {
            $clean = $this->enhanceShortSubject($clean);
        }

        return ['id' => self::REGEX_MUSIC_MATCH, 'name' => $clean];
    }

    protected function enhanceShortSubject(string $cleanSubject): string
    {
        return $this->nativeSubject(2, $cleanSubject) ?? parent::enhanceShortSubject($cleanSubject);
    }

    /**
     * Byte-oriented preprocessing only. Encoding detection and memoization stay in PHP.
     * A customized extension expression, absent library or declined input uses PCRE.
     *
     * @param  0|1|2  $mode
     */
    private function nativeSubject(int $mode, string $cleanSubject = ''): ?string
    {
        if ($this->e0 !== self::REGEX_FILE_EXTENSIONS
            || ini_get('pcre.backtrack_limit') !== '1000000'
            || ini_get('pcre.recursion_limit') !== '100000'
            || ! \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8'], true)) {
            return null;
        }
        if (self::$collectionsLibrary === null) {
            self::$collectionsLibrary = RustPipelineLibrary::resolveDomain('collections_cleaning', <<<'C'
                ssize_t nntmux_collections_clean_generic_pre_normalize(const char *input, size_t len, char *out, size_t cap);
                ssize_t nntmux_collections_music_subject(const char *input, size_t len, const char *group, size_t group_len, char *out, size_t cap);
                ssize_t nntmux_collections_enhance_short_subject(const char *input, size_t len, const char *clean, size_t clean_len, char *out, size_t cap);
                C) ?? false;
        }
        $ffi = self::$collectionsLibrary;
        if ($ffi === false) {
            return null;
        }
        $length = \strlen($this->subject);
        $capacity = max(256, $length * 2 + \strlen($cleanSubject) + 16);
        if (self::$collectionsScratch === null || self::$collectionsScratchCapacity < $capacity) {
            self::$collectionsScratch = RustPipelineLibrary::obtainScratch($ffi, $capacity, self::$collectionsScratchCapacity);
        }
        $out = self::$collectionsScratch;
        if ($out === null) {
            return null;
        }
        // cleanMusicSubject() also supports direct calls outside music groups.
        $written = match ($mode) {
            // @phpstan-ignore method.notFound (domain C ABI)
            0 => $ffi->nntmux_collections_clean_generic_pre_normalize($this->subject, $length, $out, self::$collectionsScratchCapacity),
            // @phpstan-ignore method.notFound (domain C ABI)
            1 => $ffi->nntmux_collections_music_subject($this->subject, $length, '.music', 6, $out, self::$collectionsScratchCapacity),
            // @phpstan-ignore method.notFound (domain C ABI)
            2 => $ffi->nntmux_collections_enhance_short_subject($this->subject, $length, $cleanSubject, \strlen($cleanSubject), $out, self::$collectionsScratchCapacity),
        };

        return \is_int($written) && $written >= 0 && $written <= self::$collectionsScratchCapacity
            ? ($written === 0 ? '' : FFI::string($out, $written))
            : null;
    }
}

final class CollectionsCleaningProductionProbe extends CollectionsCleaningService
{
    use CollectionsCleaningTestAccess;
}

/** Frozen PHP behavior (constructor supplied by the test harness) from 1a8231ab1fde6313a2142260cc225b3962fa2d72. */
class CollectionsCleaningPhpReference
{
    use CollectionsCleaningTestAccess;

    /**
     * Used for matching endings in article subjects.
     */
    public const string REGEX_END = '[\-_\s]{0,3}yEnc$/ui';

    /**
     * Used for matching file extension endings in article subjects.
     */
    public const string REGEX_FILE_EXTENSIONS = '([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z|\.par2)?(\d{1,3}\.rev"|\.vol\d+\+\d+.+?"|\.[A-Za-z0-9]{2,4}"|")';

    /**
     * Used for matching size strings in article subjects.
     *
     * @example ' - 365.15 KB - '
     */
    public const string REGEX_SUBJECT_SIZE = '[\-_\s]{0,3}\d+([.,]\d+)? [kKmMgG][bB][\-_\s]{0,3}';

    /**
     * Collection subject matched the Generic regular expression.
     */
    public const int REGEX_GENERIC_MATCH = -10;

    /**
     * Collection subject matched the Music generic regular expression.
     */
    public const int REGEX_MUSIC_MATCH = -20;

    /**
     * Normalized subjects remembered per process before the memo is cleared. See normalizeString().
     */
    private const int NORMALIZE_MEMO_SIZE = 4096;

    /**
     * Every encoding mbstring supports, cached per process.
     *
     * @var list<string>|null
     */
    private static ?array $allEncodings = null;

    /**
     * Recent normalizeString() results keyed by the collapsed subject.
     *
     * @var array<string, string>
     */
    private static array $normalizeMemo = [];

    /**
     * Cached file extension patterns
     */
    public string $e0;

    public string $e1;

    public string $e2;

    /**
     * Current group name being processed
     */
    public string $groupName = '';

    /**
     * Current subject being processed
     */
    public string $subject = '';

    /**
     * Regex handler for database-stored patterns
     */
    protected RegexService $_regexes;

    /**
     * Clean a collection subject line.
     *
     * Attempts to extract a clean collection name from a Usenet subject line.
     * First tries database regexes, then falls back to generic cleaning patterns.
     *
     * @param  string  $subject  The raw subject line to clean
     * @param  string  $groupName  The newsgroup name (optional, used for context-specific cleaning)
     * @return array<string, mixed> Returns ['id' => regex_id, 'name' => cleaned_name]
     *
     * @throws \Exception
     */
    public function collectionsCleaner(string $subject, string $groupName = ''): array
    {
        $this->subject = $subject;
        $this->groupName = $groupName;

        // Try database regex patterns first for better accuracy
        $potentialString = $this->_regexes->tryRegex($subject, $groupName);
        if ($potentialString) {
            return [
                'id' => $this->_regexes->matchedRegex,
                'name' => $potentialString,
            ];
        }

        // Fall back to generic cleaning patterns
        return $this->generic();
    }

    /**
     * Cleans usenet subject before inserting, used for collection hash.
     *
     * If no regexes matched on collectionsCleaner, this method applies generic cleaning patterns.
     *
     * @return array<string, mixed> Returns ['id' => match_type, 'name' => cleaned_name]
     */
    protected function generic(): array
    {
        // For non-music groups
        if (! $this->isMusicGroup()) {
            return $this->cleanGenericSubject();
        }

        // Music groups processing
        $musicSubject = $this->musicSubject();
        if ($musicSubject !== false) {
            return [
                'id' => self::REGEX_MUSIC_MATCH,
                'name' => $musicSubject,
            ];
        }

        return $this->cleanMusicSubject();
    }

    /**
     * Clean non-music group subjects.
     *
     * @return array<string, mixed> Returns ['id' => match_type, 'name' => cleaned_name]
     */
    protected function cleanGenericSubject(): array
    {
        // Remove common patterns:
        // - File/part count indicators
        // - File extensions
        // - File sizes (non-unique identifiers)
        // - Random/generic metadata
        $cleanSubject = preg_replace([
            // File sizes and yEnc markers
            '/\d{1,3}([,.\\/])\d{1,3}\s([kmg])b|(])?\s\d+KB\s(yENC)?|"?\s\d+\sbytes?|[- ]?\d+([.,])?\d+\s([gkm])?B\s-?(\s?yenc)?|\s\(d{1,3},\d{1,3}\s{K,M,G}B\)\s|yEnc \d+k$|{\d+ yEnc bytes}|yEnc \d+ |\(\d+ ?([kmg])?b(ytes)?\) yEnc$/i',
            // AutoRarPar and yEnc markers
            '/AutoRarPar\d{1,5}|\(\d+\)\s?yEnc|\d+(Amateur|Classic)| \d{4,}[a-z]{4,} |.vol\d+\+\d+|.part\d+/i',
            // Part/file numbering patterns
            '/((( \(\d\d\) -|(\d\d)? - \d\d\.|\d{4} \d\d -) | - \d\d-| \d\d\. [a-z]).+| \d\d of \d\d| \dof\d)\.mp3"?|([)(\[\s])\d{1,5}(\/|([\s_])of([\s_])|-)\d{1,5}([)\]\s$:])|\(\d{1,3}\|\d{1,3}\)|[^\d]{4}-\d{1,3}-\d{1,3}\.|\s\d{1,3}\sof\s\d{1,3}\.|\s\d{1,3}\/\d{1,3}|\d{1,3}of\d{1,3}\.|^\d{1,3}\/\d{1,3}\s|\d{1,3} - of \d{1,3}/i',
            // File extensions and common patterns
            '/(-? [a-z0-9]+-?|\(?\d{4}\)?([_-])[a-z0-9]+)\.jpg"?| [a-z0-9]+\.mu3"?|((\d{1,3})?\.part(\d{1,5})?|\d{1,5} ?|sample|- Partie \d+)?\.(7z|\d{3}(?=([\s"]))|avi|diz|docx?|epub|idx|iso|jpg|m3u|m4a|mds|mkv|mobi|mp4|nfo|nzb|par(\s?2|")|pdf|rar|rev|rtf|r\d\d|sfv|srs|srr|sub|txt|vol.+(par2)|xls|zip|z{2,3})"?|(\s|(\d{2,3})?-)\d{2,3}\.mp3|\d{2,3}\.pdf|\.part\d{1,4}\./i',
            // Cached extension patterns
            '/'.$this->e0.'/i',
        ], ' ', $this->subject);

        return [
            'id' => self::REGEX_GENERIC_MATCH,
            'name' => $this->normalizeString($cleanSubject),
        ];
    }

    /**
     * Clean music group subjects with generic patterns.
     *
     * @return array<string, mixed> Returns ['id' => match_type, 'name' => cleaned_name]
     */
    protected function cleanMusicSubject(): array
    {
        // Generic music cleaning patterns
        $cleanSubject = preg_replace([
            // Parts/files numbering
            '/((( \(\d\d\) -|(\d\d)? - \d\d\.|\d{4} \d\d -) | - \d\d-| \d\d\. [a-z]).+| \d\d of \d\d| \dof\d)\.mp3"?|([(\[\s])\d{1,4}(\/|([\s_])of([\s_])|-)\d{1,4}([)\]\s$:])|\(\d{1,3}\|\d{1,3}\)|-\d{1,3}-\d{1,3}\.|\s\d{1,3}\sof\s\d{1,3}\.|\s\d{1,3}\/\d{1,3}|\d{1,3}of\d{1,3}\.|^\d{1,3}\/\d{1,3}\s|\d{1,3} - of \d{1,3}/i',
            // Remove anything between quotes (too much variance)
            '/".+"/i',
            // File extensions
            '/(-? [a-z0-9]+-?|\(?\d{4}\)?([_-])[a-z0-9]+)\.jpg"?| [a-z0-9]+\.mu3"?|((\d{1,3})?\.part(\d{1,5})?|\d{1,5} ?|sample|- Partie \d+)?\.(7z|\d{3}(?=([\s"]))|avi|diz|docx?|epub|idx|iso|jpg|m3u|m4a|mds|mkv|mobi|mp4|nfo|nzb|par(\s?2|")|pdf|rar|rev|rtf|r\d\d|sfv|srs|srr|sub|txt|vol.+(par2)|xls|zip|z{2,3})"?|(\s|(\d{2,3})?-)\d{2,3}\.mp3|\d{2,3}\.pdf|\.part\d{1,4}\./i',
            // File sizes
            '/\d{1,3}([,.\\/])\d{1,3}\s([kmg])b|(])?\s\d+KB\s(yENC)?|"?\s\d+\sbytes?|[- ]?\d+[.,]?\d+\s([gkm])?B\s-?(\s?yenc)?|\s\(d{1,3},\d{1,3}\s{K,M,G}B\)\s|yEnc \d+k$|{\d+ yEnc bytes}|yEnc \d+ |\(\d+ ?([kmg])?b(ytes)?\) yEnc$/i',
            // AutoRarPar and yEnc markers
            '/AutoRarPar\d{1,5}|\(\d+\)\s?yEnc|\d+(Amateur|Classic)| \d{4,}[a-z]{4,} |.vol\d+\+\d+|.part\d+/i',
        ], ' ', $this->subject);

        $cleanSubject = $this->normalizeString($cleanSubject);

        // If the subject is too short or generic, try to extract additional info
        if (\strlen($cleanSubject) <= 10 || preg_match('/^[\-a-z0-9$ ]{1,7}yEnc$/i', $cleanSubject)) {
            $cleanSubject = $this->enhanceShortSubject($cleanSubject);
        }

        return [
            'id' => self::REGEX_MUSIC_MATCH,
            'name' => $cleanSubject,
        ];
    }

    /**
     * Enhance short or generic subjects by extracting additional information.
     *
     * @param  string  $cleanSubject  The cleaned subject that is too short
     * @return string Enhanced subject string
     */
    protected function enhanceShortSubject(string $cleanSubject): string
    {
        $x = '';
        if (preg_match('/.*("[A-Z0-9]+).*?"/i', $this->subject, $hit)) {
            $x = $hit[1];
        }

        if (preg_match_all('/[^A-Z0-9]/i', $this->subject, $match1)) {
            $start = 0;
            foreach ($match1[0] as $add) {
                if ($start > 2) {
                    break;
                }
                $x .= $add;
                $start++;
            }
        }

        $newName = preg_replace(['/".+?"/', '/[a-z0-9]|'.$this->e0.'/i'], '', $this->subject);

        return $cleanSubject.$newName.$x;
    }

    /**
     * Process music-specific subject patterns.
     *
     * Attempts to extract clean names from music group subject lines using
     * specialized patterns for music releases.
     *
     * @return string|false Returns cleaned subject or false if no pattern matches
     */
    protected function musicSubject(): bool|string
    {
        // Pattern: Broderick_Smith-Unknown_Country-2009-404 "00-broderick_smith-unknown_country-2009.sfv" yEnc
        if (preg_match('/^(\w{10,}-[a-zA-Z0-9]+ ")\d\d-.+?" yEnc$/', $this->subject, $hit)) {
            return $hit[1];
        }

        return false;
    }

    /**
     * Normalize a string by collapsing multiple spaces and encoding to UTF-8.
     *
     * @param  string  $subject  The subject to normalize
     * @return string Normalized and UTF-8 encoded string
     */
    protected function normalizeString(string $subject): string
    {
        // Collapse multiple spaces into one
        $normalized = trim(preg_replace('/\s\s+/', ' ', $subject));

        // Ensure UTF-8 encoding. Collection hashes depend on this output, so it must be
        // detection over every encoding: mbstring's choice depends on the whole candidate
        // set, so even a reduced list that decodes each byte the same can pick another
        // encoding (`S/3/S*Z%7}` is UTF-8 over all encodings but UCS-2 over a UTF/UCS
        // subset). Detection is costly, but every part of a post shares its collection
        // name, so the result is memoized per process.
        if (isset(self::$normalizeMemo[$normalized])) {
            return self::$normalizeMemo[$normalized];
        }

        if (count(self::$normalizeMemo) >= self::NORMALIZE_MEMO_SIZE) {
            self::$normalizeMemo = [];
        }

        self::$allEncodings ??= mb_list_encodings();

        return self::$normalizeMemo[$normalized] = mb_convert_encoding($normalized, 'UTF-8', self::$allEncodings);
    }

    /**
     * Check if the current group is a music group.
     *
     * @return bool True if the group is music-related, false otherwise
     */
    protected function isMusicGroup(): bool
    {
        return preg_match('/\.(flac|lossless|mp3|music|sounds)/', $this->groupName) === 1;
    }
}

/** Raw pre-encoding oracle; music enhancement runs only after this boundary. */
final class CollectionsCleaningRawReference extends CollectionsCleaningPhpReference
{
    protected function normalizeString(string $subject): string
    {
        return trim(preg_replace('/\s\s+/', ' ', $subject));
    }

    protected function enhanceShortSubject(string $cleanSubject): string
    {
        return $cleanSubject;
    }
}
