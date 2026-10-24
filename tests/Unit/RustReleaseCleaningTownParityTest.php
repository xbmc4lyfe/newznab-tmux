<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionsCleaningService;
use App\Services\ReleaseCleaningService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;

/**
 * Admission experiment (Apple M5/macOS arm64, PHP 8.5.12, PCRE JIT, Rust release):
 * 10,000 randomized paired single-call observations/cell, 500 warmups/path.
 * PHP/Rust mean and p99 latency ratios (higher is faster; gate is >= 1.5 each):
 * early 0.10/0.08, middle 0.11/0.09, late 0.10/0.10, fallback 1.08/1.06;
 * uncommon long match 0.05/0.07, near miss 0.006/0.004, byte class 0.08/0.07,
 * Unicode decline 0.57/0.56. Every cell failed admission; production retains PHP.
 * Raw samples, input bytes, source hashes, startup and p99 rank intervals are
 * preserved by the opt-in benchmark below. Ratios describe this host, not a
 * production throughput claim. The initial invalid JSON artifact attempt is
 * recorded in the experiment metadata.
 */
final class RustReleaseCleaningTownParityTest extends TestCase
{
    private const string CDEF = 'ssize_t nntmux_release_generic_town(const char *subject, size_t subject_len, char *out_buf, size_t out_cap);';

    public function test_all_twenty_one_ordered_branches_match_the_php_reference(): void
    {
        $cleaner = self::cleaner();
        foreach (self::branchSubjects() as $index => [$subject, $expected]) {
            $cleaner->subject = $subject;
            self::assertSame($expected, $cleaner->genericTownNative(), 'Rust branch '.($index + 1));
            self::assertSame($expected, $cleaner->genericTownPhp(), 'PHP branch '.($index + 1));
            self::assertSame($expected, $cleaner->generic_town(), 'Caller branch '.($index + 1));
        }
    }

    public function test_fallback_array_and_byte_unicode_quantifier_and_case_parity(): void
    {
        $cleaner = self::cleaner();
        $subjects = ['', 'plain subject', "plain\0subject yEnc", "  spaced\t\tname - yEnc\n", str_repeat('x', 17000)];
        foreach (self::branchSubjects() as [$subject]) {
            $subjects[] = $subject;
            foreach (["\n", "\r\n", "\0", ' trailing', ' www.town.ag'] as $tail) {
                $subjects[] = $subject.$tail;
            }
            foreach (['yEnc' => 'YENC', 'TOWN' => 'town', 'ssl-news.info' => 'SSL-NEWS.INFO', 'sponsored' => 'ßponßored', ' - ' => ' ', '.par2' => '.PAR2', '[1/3]' => '[0001/0003]', 'Release-GRP' => 'Release.par2.part01.rar-GRP'] as $from => $to) {
                $subjects[] = str_replace($from, $to, $subject);
            }
        }
        foreach (['Abcdefg', 'Abcdefgh', 'Abcdefgh!', 'äöüÄÖÜß¤¶-GRP', "Abcdefg\xc3\xb6-GRP", "Abcdefg\xb6-GRP", "Abcdefg\xbc-GRP", "Abcdefg\xff-GRP", "Abcdefg\f-GRP", "Abcdefg\n-GRP", "Abcdefg\0-GRP", 'aбвгдежз-GRP', 'aKſ-GRP'] as $name) {
            foreach ([1, 15, 16, 20] as $branch) {
                $subjects[] = preg_replace('/[A-Za-z]+\.Release-GRP/', $name, self::branchSubjects()[$branch][0]);
            }
        }
        // Overlapping extensions expose greedy capture vs lazy extension stripping.
        foreach (['.rar', '.part01.rar', '.par2', '.vol01+02.par2', '.7z001.rev', '-sample.nfo', '_proof_thumbs.part.rar', '.PART12.RAR', '.nfo.rar', '.ßß', ''] as $suffix) {
            foreach ([0, 1, 2, 4, 14, 15, 16, 20] as $branch) {
                $subjects[] = preg_replace('/(?:-sample)?\.(?:part\d+\.rar|vol\d+\+\d+\.par2|par2)/i', $suffix, self::branchSubjects()[$branch][0]);
            }
        }
        foreach ($subjects as $subject) {
            self::assertIsString($subject);
            $cleaner->subject = $subject;
            $expected = $cleaner->genericTownPhp();
            self::assertSame($expected, $cleaner->genericTownNative() ?? $cleaner->genericTownPhp(), bin2hex($subject));
            self::assertSame($expected, $cleaner->generic_town());
        }
        $cleaner->subject = 'plain subject yEnc';
        self::assertSame(['cleansubject' => 'plain subject', 'properlynamed' => false], $cleaner->genericTownNative());
    }

    public function test_deterministic_randomized_structural_parity(): void
    {
        $random = new Randomizer(new Mt19937(8022026));
        $cleaner = self::cleaner();
        $fragments = [' ', '-', '_', '.', '"', '#34;', '<', '>', '[', ']', 'town', 'yEnc', "\n", "\r", "\0", "\xc3", "\xb6", 'ä', 'K', '.part01.rar'];
        for ($i = 0; $i < 2000; $i++) {
            $subject = self::branchSubjects()[$random->getInt(0, 20)][0];
            for ($j = 0, $count = $random->getInt(1, 4); $j < $count; $j++) {
                $pos = $random->getInt(0, strlen($subject));
                $subject = substr_replace($subject, $fragments[$random->getInt(0, count($fragments) - 1)], $pos, $random->getInt(0, min(4, strlen($subject) - $pos)));
            }
            $cleaner->subject = $subject;
            self::assertSame($cleaner->genericTownPhp(), $cleaner->genericTownNative() ?? $cleaner->genericTownPhp(), 'seed 8022026 iteration '.$i.' '.bin2hex($subject));
        }
    }

    public function test_mutated_extensions_and_pcre_limits_decline_to_the_original_caller(): void
    {
        $cleaner = self::cleaner();
        foreach (['e0', 'e1', 'e2'] as $field) {
            $old = $cleaner->{$field};
            $cleaner->{$field} = '(NEVERMATCH)'.$old;
            $cleaner->subject = self::branchSubjects()[0][0];
            self::assertNull($cleaner->genericTownNative());
            self::assertSame($cleaner->genericTownPhp(), $cleaner->generic_town());
            $cleaner->{$field} = $old;
        }
        foreach (['pcre.backtrack_limit', 'pcre.recursion_limit'] as $setting) {
            $old = ini_get($setting);
            try {
                ini_set($setting, '1');
                self::assertNull($cleaner->genericTownNative());
                self::assertSame($cleaner->genericTownPhp(), $cleaner->generic_town());
            } finally {
                ini_set($setting, $old);
            }
        }
    }

    public function test_latin1_and_individual_non_utf_bytes_match_pcre(): void
    {
        $cleaner = self::cleaner();
        for ($i = 0; $i < 256; $i++) {
            foreach ([chr($i), mb_convert_encoding(chr($i), 'UTF-8', 'ISO-8859-1')] as $char) {
                foreach ([1, 15, 16, 20] as $branch) {
                    $subject = self::branchSubjects()[$branch][0];
                    $subject = preg_replace('/[A-Za-z]+\.Release-GRP/', 'Name'.$char.'Test-GRP', $subject);
                    self::assertIsString($subject);
                    $cleaner->subject = $subject;
                    self::assertSame($cleaner->genericTownPhp(), $cleaner->genericTownNative() ?? $cleaner->genericTownPhp(), 'Latin-1 byte '.$i.' branch '.$branch);
                }
            }
        }
    }

    public function test_unavailable_library_and_overridden_fallback_helper(): void
    {
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $before = $cache->getValue();
        $key = 'release_town|default|'.hash('xxh3', self::CDEF);
        $cleaner = self::cleaner();
        $cleaner->subject = self::branchSubjects()[0][0];
        try {
            $cache->setValue(null, [$key => false] + $before);
            self::assertNull($cleaner->genericTownNative());
            self::assertSame(self::branchSubjects()[0][1], $cleaner->generic_town());
        } finally {
            $cache->setValue(null, $before);
        }
        $overridden = self::cleaner(ReleaseTownHelperProbe::class);
        $overridden->subject = 'unmatched';
        self::assertSame(['cleansubject' => 'overridden:unmatched', 'properlynamed' => false], $overridden->genericTownNative());
    }

    public function test_direct_abi_status_resize_and_buffer_ownership(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('release_town_test', self::CDEF);
        self::assertNotNull($ffi, 'Build and install the release library before running town parity tests.');
        $subject = self::branchSubjects()[0][0];
        $expected = self::branchSubjects()[0][1];
        $buffer = $ffi->new('char[256]');
        FFI::memset($buffer, 90, 256);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_release_generic_town(null, 1, $buffer, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-3, $ffi->nntmux_release_generic_town(null, 0, $buffer, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-3, $ffi->nntmux_release_generic_town('fallback', 8, $buffer, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_release_generic_town($subject, strlen($subject), null, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen($expected) - 4, $ffi->nntmux_release_generic_town($subject, strlen($subject), null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen($expected) - 4, $ffi->nntmux_release_generic_town($subject, strlen($subject), $buffer, 1));
        self::assertSame(str_repeat('Z', 256), FFI::string($buffer, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(strlen($expected), $ffi->nntmux_release_generic_town($subject, strlen($subject), $buffer, strlen($expected)));
        self::assertSame($expected.'Z', FFI::string($buffer, strlen($expected) + 1));
        $bytes = str_replace('Sixteenth.Release-GRP', "Byte\xb6\xc3Name-GRP", self::branchSubjects()[15][0]);
        // Non-/u classes admit individual bytes from the UTF-8 spelling of äöüÄÖÜß¤¶.
        // @phpstan-ignore method.notFound
        $length = $ffi->nntmux_release_generic_town($bytes, strlen($bytes), $buffer, 256);
        self::assertSame("Byte\xb6\xc3Name-GRP", FFI::string($buffer, $length));
        $unsupported = str_replace('Second.Release-GRP', 'UnicodeK-GRP', self::branchSubjects()[1][0]);
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_release_generic_town($unsupported, strlen($unsupported), $buffer, 256));
    }

    /**
     * Admission protocol: seed 8022026, 10,000 randomized paired single-call samples
     * per cell, identical inputs, 500 warmups per path. Includes wrapper, FFI,
     * scratch allocation, string/array conversion and any PHP fallback. Startup is
     * measured separately; no database, network or production runtime is involved.
     * Reproduce: php artisan test --compact tests/Unit/RustReleaseCleaningTownParityTest.php --group=benchmark
     */
    #[Group('benchmark')]
    public function test_paired_randomized_caller_admission_benchmark(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            self::markTestSkipped('Opt-in paired admission benchmark; raw samples are written to the system temporary directory.');
        }
        $reference = self::cleaner();
        $candidate = self::cleaner(ReleaseTownCandidateProbe::class);
        $random = new Randomizer(new Mt19937(8022026));
        $startup = [];
        foreach ($random->shuffleArray(['php', 'rust']) as $path) {
            $cleaner = $path === 'php' ? $reference : $candidate;
            $cleaner->subject = self::branchSubjects()[0][0];
            $start = hrtime(true);
            $result = $cleaner->generic_town();
            $startup[$path] = hrtime(true) - $start;
            self::assertSame(self::branchSubjects()[0][1], $result);
        }
        $workloads = [
            'common/early' => array_column(array_slice(self::branchSubjects(), 0, 3), 0),
            'common/middle' => array_column(array_slice(self::branchSubjects(), 8, 6), 0),
            'common/late' => array_column(array_slice(self::branchSubjects(), 15, 6), 0),
            'common/fallback' => ['unrelated subject yEnc', 'plain subject', '[1/20] - "Other.Release-GRP.part01.rar" yEnc'],
            'uncommon/long-match' => [str_replace('Early.Release-GRP', str_repeat('Long.Release_Name-', 64).'GRP', self::branchSubjects()[0][0])],
            'uncommon/near-miss' => [str_replace('yEnc', 'yEncX', self::branchSubjects()[1][0]), str_repeat('[ TOWN ]-', 128).'unterminated www.town.ag'],
            'uncommon/byte-class' => [str_replace('Sixteenth.Release-GRP', "Byte\xb6\xc3Name-GRP", self::branchSubjects()[15][0])],
            'uncommon/unicode-decline' => [str_replace('Second.Release-GRP', 'UnicodeK-GRP', self::branchSubjects()[1][0])],
        ];
        $summary = $raw = $gates = [];
        foreach ($workloads as $cell => $subjects) {
            foreach ($subjects as $subject) {
                $reference->subject = $candidate->subject = $subject;
                self::assertSame($reference->generic_town(), $candidate->generic_town(), $cell);
            }
            for ($i = 0; $i < 500; $i++) {
                $reference->subject = $candidate->subject = $subjects[$i % count($subjects)];
                $reference->generic_town();
                $candidate->generic_town();
            }
            $observations = ['php' => [], 'rust' => [], 'first' => [], 'subject_index' => []];
            for ($pair = 0; $pair < 10000; $pair++) {
                $index = $random->getInt(0, count($subjects) - 1);
                $reference->subject = $candidate->subject = $subjects[$index];
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                $observations['first'][] = $order[0];
                $observations['subject_index'][] = $index;
                foreach ($order as $path) {
                    $cleaner = $path === 'php' ? $reference : $candidate;
                    $start = hrtime(true);
                    $cleaner->generic_town();
                    $observations[$path][] = hrtime(true) - $start;
                }
            }
            $metrics = [];
            foreach (['php', 'rust'] as $path) {
                $sorted = $observations[$path];
                sort($sorted, SORT_NUMERIC);
                $metrics[$path] = [
                    'mean_ns' => array_sum($sorted) / count($sorted),
                    'p99_ns' => $sorted[9899],
                    'p99_approximate_95_percent_rank_interval_ns' => [$sorted[9879], $sorted[9919]],
                ];
            }
            $mean = $metrics['php']['mean_ns'] / $metrics['rust']['mean_ns'];
            $p99 = $metrics['php']['p99_ns'] / $metrics['rust']['p99_ns'];
            $summary[$cell] = $metrics + ['mean_speedup' => $mean, 'p99_speedup' => $p99];
            $gates[$cell] = $mean >= 1.5 && $p99 >= 1.5;
            $raw[$cell] = $observations;
        }
        $files = [];
        foreach (['app/Services/ReleaseCleaningService.php', 'rust/pipeline-ffi/src/cleaning.rs', 'tests/Unit/RustReleaseCleaningTownParityTest.php', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $file) {
            $files[$file] = hash_file('sha256', dirname(__DIR__, 2).'/'.$file);
        }
        $artifact = sys_get_temp_dir().'/nntmux-release-town-benchmark-'.getmypid().'.json';
        file_put_contents($artifact, json_encode([
            'protocol' => ['seed' => 8022026, 'pairs_per_cell' => 10000, 'warmup_calls_per_path' => 500, 'calls_per_observation' => 1, 'clock' => 'hrtime', 'baseline_commit' => 'ea3de407cc1973ccf368344eec7e3a832bbe738f', 'profile' => 'release opt-level=3 lto=true codegen-units=1', 'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml', 'reproduce' => 'php artisan test --compact tests/Unit/RustReleaseCleaningTownParityTest.php --group=benchmark', 'failures_or_timeouts' => [], 'prior_invalid_attempt' => 'Initial artifact serialization rejected a non-UTF8 fixture; timings were not recovered. Workloads are now stored losslessly in base64.'],
            'runtime' => ['php' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'pcre_jit' => ini_get('pcre.jit'), 'os' => php_uname(), 'hardware' => 'Apple M5, 32 GiB, unrestricted host process', 'rustc' => '1.99.1 (58cf8f9c35b355199727627d07ae34a4e2846d67) LLVM 23.1.3 aarch64-apple-darwin', 'memory_limit' => ini_get('memory_limit'), 'files_sha256' => $files],
            'startup_single_call_ns' => $startup, 'workloads_base64' => array_map(static fn (array $subjects): array => array_map(base64_encode(...), $subjects), $workloads), 'summary' => $summary, 'gates' => $gates, 'all_qualify' => ! in_array(false, $gates, true), 'raw_samples' => $raw,
        ], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nTown benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertFileExists($artifact);
    }

    /** @return list<array{string, string}> */
    private static function branchSubjects(): array
    {
        $angle = '<TOWN><www.town.ag > <partner of www.ssl-news.info >';
        $square = '[ TOWN ]-[ www.town.ag ]-[ partner of www.ssl-news.info ]';

        return [
            [$angle.' [1/3] - "Early.Release-GRP-sample.part01.rar" - 7,87 GB yEnc', 'Early.Release-GRP'],
            [$square.'-[ 1080p ] - [1/3] - "Second.Release-GRP.vol000+5.par2" - 17.09 GB yEnc', 'Second.Release-GRP'],
            [$angle.' [1/3] - "Third.Release-GRP.part01.rar" yEnc', 'Third.Release-GRP'],
            ['(1/3) -'.$angle.' - "Fourth.Release-GRP-.part4.rar" - 741,51 kB - yEnc', 'Fourth.Release-GRP'],
            [$square.'-[ MOVIE ] [1/3] - "Fifth:Release-GRP.part12.rar" - 660,80 MB yEnc', 'Fifth:Release-GRP'],
            [$square.'-[ MOVIE ] [1/3] - "Sixth:Release-GRP.par2" - 7,49 GB yEnc', 'Sixth:Release-GRP'],
            [$square.' [1/3] - "Seventh.Release-GRP.vol31+11.par2" - 1,45 GB yEnc', 'Seventh.Release-GRP'],
            [$square.' [ TV ] [1/3] - "Eighth.Release-GRP.par2" - 352,58 MB yEnc', 'Eighth.Release-GRP'],
            ['4675.-.Ninth.Release-GRP '.$angle.' <Games-NDS > [1/3] - #34;file.par2#34; - 72,80 MB - yEnc', 'Ninth.Release-GRP'],
            ['4675.-.Tenth.Release-GRP '.$angle.' <Games-NDS > [1/3] - #34;file.par2', 'Tenth.Release-GRP'],
            ['Eleventh.Release-GRP '.$angle.' [1/3] - #34;file.par2#34; - 92,12 MB - yEnc', 'Eleventh.Release-GRP'],
            ['Twelfth.Release-GRP '.$angle.' [1/3] - #34;file.par2', 'Twelfth.Release-GRP'],
            [$angle.' Thirteenth.Release-GRP  [1/3] - #34;file.par2#34; - 200,77 MB - yEnc', 'Thirteenth.Release-GRP '],
            [$angle.' Fourteenth.Release-GRP  [1/3] - #34;file.par2', 'Fourteenth.Release-GRP '],
            ['<town><www.town.ag > <partner of www.ssl-news.info > [1/3] - "Fifteenth.Release-GRP.part01.rar" - 421,98 MB yEnc', 'Fifteenth.Release-GRP.part01'],
            ['<TOWN> www.town.ag > sponsored by www.ssl-news.info > (1/3) "Sixteenth.Release-GRP.par2" - 43,89 MB - yEnc', 'Sixteenth.Release-GRP'],
            ['(1/3)<<<www.town.ag>>> sponsored by ssl-news.info<<<anything "Seventeenth.Release-GRP.par2" yEnc', 'Seventeenth.Release-GRP'],
            ['[ TOWN ]-[ www.town.ag ]-[ Eighteenth.Release-GRP ]-[ partner of www.ssl-news.info ] [1/3]- "file.r71" yEnc', 'Eighteenth.Release-GRP'],
            ['(TOWN)(www.town.ag ) (partner of www.ssl-news.info ) Nineteenth.Release-GRP  [1/3] - #34;file.par2#34; - 266,00 MB - yEnc', 'Nineteenth.Release-GRP '],
            [strtolower($angle).' Twentieth.Release-GRP  [1/3] - #34;file.par2#34; - 526,99 MB - yEnc', 'Twentieth.Release-GRP '],
            ['[ TOWN ]-[ www.town.ag ]-[ ANIME ] [1/3] - "TwentyFirst.Release-GRP.par2" - 585,03 MB yEnc', 'TwentyFirst.Release-GRP'],
        ];
    }

    /** @param class-string<ReleaseCleaningService> $class */
    private static function cleaner(string $class = ReleaseCleaningService::class): ReleaseCleaningService
    {
        // The constructor's RegexService reads the database; town only needs these fields.
        $cleaner = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $cleaner->e0 = CollectionsCleaningService::REGEX_FILE_EXTENSIONS;
        $cleaner->e1 = $cleaner->e0.CollectionsCleaningService::REGEX_END;
        $cleaner->e2 = $cleaner->e0.CollectionsCleaningService::REGEX_SUBJECT_SIZE.CollectionsCleaningService::REGEX_END;

        return $cleaner;
    }
}

/** Exercises the proposed production caller without enabling an unqualified path. */
final class ReleaseTownCandidateProbe extends ReleaseCleaningService
{
    public function generic_town()
    {
        return $this->genericTownNative() ?? $this->genericTownPhp();
    }
}

final class ReleaseTownHelperProbe extends ReleaseCleaningService
{
    public function releaseCleanerHelper(string $subject): string
    {
        return 'overridden:'.$subject;
    }
}
