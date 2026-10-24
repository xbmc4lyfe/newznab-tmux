<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\NzbManifestReader;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Paired randomized single-call measurements, 2026-10-24, Apple M5 arm64 macOS,
 * PHP 8.5.12, rustc/cargo 1.99.1, release opt-level=3/LTO/codegen-units=1.
 * 50 warmup pairs, seed 7103; includes FFI, serialization and allocation costs.
 * Baseline: checked-in PHP at 2069af11e1e8978d7caace75666a2bab2bbbf881.
 *
 * Workload             Pairs   Mean speedup   p99 speedup   Production
 * read 1 file x 2      5000    2.94x          2.24x         Rust
 * read 42 files x 10   5000    2.11x          2.08x         Rust
 * read 252 files x 50  5000    1.83x          1.83x         Rust
 * count common        10000   0.88x          0.90x         PHP
 * count no counter    10000   0.59x          0.60x         PHP
 * count long subject  10000   1.31x          1.32x         PHP
 *
 * Approximate 95% p99 ratio rank intervals for reads: [1.81, 2.63],
 * [1.90, 2.26], [1.49, 2.18]. These local fixtures establish no production SLA.
 * Raw samples/source and input hashes: /tmp/wave7-nzb-manifest-reader-7103-final.json.
 * Reproduce with NNTMUX_MANIFEST_BENCHMARK=/tmp/manifest-results.json
 * php artisan test --compact tests/Unit/RustNzbManifestReaderParityTest.php
 * --filter=test_paired_randomized_benchmark (with other validation idle).
 */
final class RustNzbManifestReaderParityTest extends TestCase
{
    protected function tearDown(): void
    {
        NzbManifestReader::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_declared_counts_preserve_the_checked_in_counter_contract(): void
    {
        $cases = [
            '' => 0, '[01/12] "payload.bin" yEnc (1/999)' => 12,
            '"[01/99] payload.bin" [2/8] (1/12)' => 8,
            "'[01/99] payload.bin' [2/8] (1/12)" => 8,
            '1080p 24fps 10bit [1/7]' => 7, '1 of 12' => 0,
            'x[1/12]' => 0, '[1/12]x' => 0, '[1/12]' => 12,
            '(1/12)' => 0, '[13/12]' => 0, '[0/12]' => 12,
            '[0001/000012]' => 12, '[1/0]' => 0, '[1/12) [2/20]' => 20,
            '[1/12345]' => 12345, '[1/9999999999999999999999]' => PHP_INT_MAX,
            "[1/12]\f[2/14]\v(1/333)\n" => 14,
        ];
        foreach ($cases as $subject => $expected) {
            self::assertSame($expected, NzbManifestReader::declaredFileCount($subject), $subject);
            self::assertSame($expected, NzbManifestReader::declaredFileCountNative($subject), $subject);
        }
        mt_srand(7101);
        for ($i = 0; $i < 3000; $i++) {
            $subject = ['"[1/99] file.bin"', "'[2/99] file.bin'", '1080p 24fps', "x\0y", '名'][mt_rand(0, 4)]
                .[' ', 'x', "\n", "\t"][mt_rand(0, 3)]
                .['[', '('][mt_rand(0, 1)].mt_rand(0, 20).'/'.mt_rand(0, 30).[']', ')'][mt_rand(0, 1)]
                .['', ' x', 'x', ' (1/100)', "\n"][mt_rand(0, 4)];
            self::assertSame(NzbManifestReader::declaredFileCount($subject), NzbManifestReader::declaredFileCountNative($subject), $subject);
        }
    }

    public function test_native_manifest_matches_complete_php_result_and_release_field_types(): void
    {
        $reader = new NzbManifestReader;
        mt_srand(7102);
        for ($i = 0; $i < 140; $i++) {
            $release = self::release();
            $release->metadata_source = $i % 2 === 0 ? 'import' : 'audit';
            $release->categories_id = $i % 3 === 0 ? '2040' : 2040;
            $release->import_name_override = $i % 3 === 0 ? 1 : true;
            $release->original_subject = $i % 3 === 0 ? null : 'original subject';
            $xml = self::fixture(mt_rand(1, 12), mt_rand(1, 15), $i);
            NzbManifestReader::setUseRustFfi(false);
            $expected = $reader->read($xml, $release);
            self::assertSame($expected, NzbManifestReader::readNative($xml, $release), "native iteration {$i}");
            NzbManifestReader::setUseRustFfi(true);
            self::assertSame($expected, $reader->read($xml, $release), "caller iteration {$i}");
        }
    }

    public function test_declined_xml_uses_the_same_php_results_and_exception_classes_and_messages(): void
    {
        $base = self::fixture(1, 2, 0);
        $cases = [
            '', '<nzb/>', '<nzb><file></nzb>', '<nzb><file subject="a"subject="b"/></nzb>',
            '<nzb><file subject="a" subject="b"/></nzb>', '<nzb><file subject="&unknown;"/></nzb>',
            '<nzb><file subject="&#xFFFF;"/></nzb>', '<nzb><file subject="a"/></nzb>tail',
            str_replace('<nzb>', '<!DOCTYPE nzb><nzb>', $base),
            str_replace('<nzb>', '<!DOCTYPE nzb [<!ENTITY test "secret">]><nzb>', $base),
            str_replace('m0-1@example', '<![CDATA[m0-1@example]]>', $base),
            str_replace('<head>', '<head><meta type="password"> secret </meta>', $base),
            str_replace('<head>', '<head><meta type="password"><b>secret</b></meta>', $base),
            str_replace('<head>', '<head><meta type="password">'.str_repeat('x', 4097).'</meta>', $base),
            str_replace('<head>', '<head>'.str_repeat('<meta type="password">x</meta>', 65), $base),
            str_replace('<head>', '<head/><head><meta type="password">x</meta>', $base),
            str_replace('<nzb>', '<nzb xmlns:n="http://www.newzbin.com/DTD/2003/nzb">', $base),
            str_replace('<groups>', '<groups xmlns="urn:other">', $base),
            str_replace('<file ', '<file xmlns="http://www.newzbin.com/DTD/2003/nzb" ', $base),
            str_replace('<nzb>', '<nzb><!-- invalid -- comment -- >', $base),
            str_replace('subject="', "subject=\"bad\x01", $base),
        ];
        $reader = new NzbManifestReader;
        foreach ($cases as $i => $xml) {
            self::assertNull(NzbManifestReader::readNative($xml, self::release()), "decline {$i}");
            NzbManifestReader::setUseRustFfi(false);
            $expected = self::outcome($reader, $xml);
            NzbManifestReader::setUseRustFfi(true);
            self::assertSame($expected, self::outcome($reader, $xml), "fallback {$i}");
        }
    }

    public function test_output_buffer_retry_and_direct_export_decline(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('manifest-test', 'ssize_t nntmux_nzb_manifest_read_xml(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);');
        self::assertNotNull($ffi, 'Build the release library before running parity tests.');
        $buffer = $ffi->new('char[8]');
        $xml = self::fixture(1, 2, 0);
        // @phpstan-ignore method.notFound
        $required = $ffi->nntmux_nzb_manifest_read_xml($xml, strlen($xml), $buffer, 8);
        self::assertLessThan(-8, $required);
        $buffer = $ffi->new('char['.-$required.']');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_manifest_read_xml($xml, strlen($xml), $buffer, -$required);
        self::assertSame(-$required, $written);
        self::assertIsArray(unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]));
        $unsupported = '<!DOCTYPE nzb><nzb/>';
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_manifest_read_xml($unsupported, strlen($unsupported), $buffer, -$required));
        $large = self::fixture(80, 60, 0);
        NzbManifestReader::setUseRustFfi(false);
        self::assertSame((new NzbManifestReader)->read($large, self::release()), NzbManifestReader::readNative($large, self::release()));
    }

    public function test_supported_mutations_never_bypass_php_xml_validation(): void
    {
        $reader = new NzbManifestReader;
        $base = self::fixture(1, 2, 1);
        mt_srand(7104);
        $accepted = 0;
        for ($i = 0; $i < 1600; $i++) {
            $at = mt_rand(0, strlen($base) - 1);
            $xml = substr($base, 0, $at).['', ' ', '"', '<', '>', '&', '/', "\x01", ':', 'x'][mt_rand(0, 9)].substr($base, $at + 1);
            $native = NzbManifestReader::readNative($xml, self::release());
            if ($native === null) {
                continue;
            }
            $accepted++;
            NzbManifestReader::setUseRustFfi(false);
            self::assertSame(['result' => $native], self::outcome($reader, $xml), "mutation {$i} at {$at}");
        }
        self::assertGreaterThan(50, $accepted);
    }

    public function test_integer_casts_group_keys_and_xml_normalization_keep_php_semantics(): void
    {
        $reader = new NzbManifestReader;
        $base = self::fixture(1, 2, 1);
        $cases = [$base];
        foreach ([' 2 ', '+2', '-2', '2junk', '2e3', '2.5', '9223372036854775808', '', '0x10'] as $number) {
            $cases[] = str_replace('number="1"', 'number="'.$number.'"', $base);
            $cases[] = str_replace('bytes="1001"', 'bytes="'.$number.'"', $base);
        }
        foreach (['0', '-1', '-0', '01', '9223372036854775808', '&#x540D;', 'a&amp;b', "a\r\nb", "a\rb"] as $group) {
            $cases[] = str_replace('alt.fixture', $group, $base);
        }
        foreach (['&#9;', '&#13;', '&#10;', '&amp;', '&#x540D;', "\r\n"] as $text) {
            $cases[] = str_replace('m0-1@example', $text, $base);
            $cases[] = str_replace('&quot;名', $text.'&quot;名', $base);
        }
        foreach ($cases as $i => $xml) {
            NzbManifestReader::setUseRustFfi(false);
            $expected = self::outcome($reader, $xml);
            NzbManifestReader::setUseRustFfi(true);
            self::assertSame($expected, self::outcome($reader, $xml), "cast/normalization {$i}");
            $native = NzbManifestReader::readNative($xml, self::release());
            if ($native !== null) {
                self::assertSame($expected, ['result' => $native], "native cast/normalization {$i}");
            }
        }
    }

    public function test_reader_preserves_libxml_error_mode_and_clears_errors_on_success(): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            foreach ([false, true] as $native) {
                simplexml_load_string('<broken>');
                self::assertNotEmpty(libxml_get_errors());
                NzbManifestReader::setUseRustFfi($native);
                (new NzbManifestReader)->read(self::fixture(1, 2, 0), self::release());
                self::assertSame([], libxml_get_errors());
                self::assertTrue(libxml_use_internal_errors());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function test_libxml_lexical_limits_cannot_be_bypassed_by_the_fast_path(): void
    {
        $reader = new NzbManifestReader;
        $large = str_repeat('x', 10000001);
        foreach (['<nzb><file poster="'.$large.'"><groups/><segments/></file></nzb>',
            '<nzb><!--'.$large.'--><file><groups/><segments/></file></nzb>',
            '<nzb><head><meta type="unused">'.$large.'</meta></head><file><groups/><segments/></file></nzb>'] as $index => $xml) {
            self::assertNull(NzbManifestReader::readNative($xml, self::release()));
            NzbManifestReader::setUseRustFfi(false);
            $expected = self::outcome($reader, $xml);
            if ($index < 2) {
                self::assertSame(['exception' => \RuntimeException::class, 'message' => 'Invalid NZB.'], $expected);
            } else {
                self::assertArrayHasKey('result', $expected);
            }
            NzbManifestReader::setUseRustFfi(true);
            self::assertSame($expected, self::outcome($reader, $xml));
        }
    }

    public function test_decimal_values_beyond_float_range_preserve_php_integer_casts(): void
    {
        $reader = new NzbManifestReader;
        foreach ([str_repeat('9', 308), str_repeat('9', 309), '1'.str_repeat('0', 308),
            '2'.str_repeat('0', 308), str_repeat('0', 400).'12'] as $digits) {
            foreach (['[1/'.$digits.']', '['.$digits.'/2]'] as $subject) {
                self::assertSame(NzbManifestReader::declaredFileCount($subject), NzbManifestReader::declaredFileCountNative($subject));
            }
            foreach (['subject="(1/'.$digits.')"', 'subject="x"'] as $subject) {
                foreach ([$digits, '-'.$digits] as $number) {
                    $xml = '<nzb><file '.$subject.'><groups/><segments><segment number="'.$number.'" bytes="'.$number.'">m@x</segment></segments></file></nzb>';
                    NzbManifestReader::setUseRustFfi(false);
                    $expected = $reader->read($xml, self::release());
                    $native = NzbManifestReader::readNative($xml, self::release());
                    if ($native !== null) {
                        self::assertSame($expected, $native);
                    }
                    NzbManifestReader::setUseRustFfi(true);
                    self::assertSame($expected, $reader->read($xml, self::release()));
                }
            }
        }
    }

    /**
     * Opt in with NNTMUX_MANIFEST_BENCHMARK=/absolute/scratch/results.json.
     * Includes caller/FFI/serialization/allocation costs; never uses production data.
     */
    public function test_paired_randomized_benchmark(): void
    {
        $destination = getenv('NNTMUX_MANIFEST_BENCHMARK');
        if ($destination === false || $destination === '') {
            self::markTestSkipped('Set NNTMUX_MANIFEST_BENCHMARK to preserve raw benchmark samples.');
        }
        $reader = new NzbManifestReader;
        $release = self::release();
        $results = ['protocol' => 'seed=7103; 50 warmup pairs; randomized pair order; nanoseconds per single call; nearest-rank p99',
            'baseline_commit' => '2069af11e1e8978d7caace75666a2bab2bbbf881',
            'php' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'os' => php_uname(), 'memory_limit' => ini_get('memory_limit'),
            'source_hashes' => [], 'workloads' => []];
        foreach (['app/Services/Releases/Verification/NzbManifestReader.php', 'rust/pipeline-ffi/src/metadata.rs',
            'tests/Unit/RustNzbManifestReaderParityTest.php', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock',
            'storage/app/nntmux-native/libnntmux_pipeline.dylib'] as $path) {
            if (is_file($path)) {
                $results['source_hashes'][$path] = hash_file('sha256', $path);
            }
        }
        mt_srand(7103);
        foreach (['tiny' => [1, 2], 'common' => [42, 10], 'uncommon' => [252, 50]] as $name => [$files, $parts]) {
            $xml = self::fixture($files, $parts, 1);
            NzbManifestReader::setUseRustFfi(false);
            $expected = $reader->read($xml, $release);
            self::assertSame($expected, NzbManifestReader::readNative($xml, $release));
            $baseline = static function () use ($reader, $xml, $release): array {
                NzbManifestReader::setUseRustFfi(false);

                return $reader->read($xml, $release);
            };
            $candidate = static function () use ($reader, $xml, $release): array {
                NzbManifestReader::setUseRustFfi(true);

                return $reader->read($xml, $release);
            };
            $results['workloads']['read_'.$name] = self::measurePair($baseline, $candidate, 5000)
                + ['input_sha256' => hash('sha256', $xml), 'input_bytes' => strlen($xml), 'files' => $files, 'parts_per_file' => $parts];
        }
        foreach (['common' => '[01/42] "payload.part01.rar" yEnc (1/500)',
            'no_counter' => 'Movie.2026.1080p.24fps.x264 "payload.rar" yEnc (1/500)',
            'uncommon' => str_repeat('1080p 24fps 10bit "[1/99] quoted.bin" ', 30).' [1/2500] (1/500)'] as $name => $subject) {
            self::assertSame(NzbManifestReader::declaredFileCount($subject), NzbManifestReader::declaredFileCountNative($subject));
            $results['workloads']['count_'.$name] = self::measurePair(
                static fn () => NzbManifestReader::declaredFileCount($subject),
                static fn () => NzbManifestReader::declaredFileCountNative($subject), 10000
            ) + ['input' => $subject];
        }
        $results['peak_memory_bytes'] = memory_get_peak_usage(true);
        self::assertNotFalse(file_put_contents($destination, json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private static function measurePair(callable $baseline, callable $candidate, int $pairs): array
    {
        $operations = [$baseline, $candidate];
        $samples = [[], []];
        $orders = [];
        for ($i = -50; $i < $pairs; $i++) {
            $order = mt_rand(0, 1) === 0 ? [0, 1] : [1, 0];
            foreach ($order as $index) {
                $start = hrtime(true);
                $value = $operations[$index]();
                $elapsed = hrtime(true) - $start;
                if ($i >= 0) {
                    $samples[$index][] = $elapsed;
                }
                unset($value);
            }
            if ($i >= 0) {
                $orders[] = $order[0];
            }
        }
        $sorted = $samples;
        sort($sorted[0]);
        sort($sorted[1]);
        $means = [array_sum($samples[0]) / $pairs, array_sum($samples[1]) / $pairs];
        $p99 = [$sorted[0][(int) ceil($pairs * .99) - 1], $sorted[1][(int) ceil($pairs * .99) - 1]];
        // Approximate 95% binomial rank interval for the p99 ratio.
        $radius = 1.96 * sqrt(.99 * .01 / $pairs);
        $low = max(0, (int) floor($pairs * (.99 - $radius)) - 1);
        $high = min($pairs - 1, (int) ceil($pairs * (.99 + $radius)) - 1);

        return ['pairs' => $pairs, 'warmup_pairs' => 50, 'mean_ns' => $means, 'p99_ns' => $p99,
            'mean_speedup' => $means[0] / $means[1], 'p99_speedup' => $p99[0] / $p99[1],
            'p99_speedup_rank_interval' => [$sorted[0][$low] / $sorted[1][$high], $sorted[0][$high] / $sorted[1][$low]],
            'baseline_ns' => $samples[0], 'candidate_ns' => $samples[1], 'first_in_pair' => $orders];
    }

    /** @return array<string, mixed> */
    private static function outcome(NzbManifestReader $reader, string $xml): array
    {
        try {
            return ['result' => $reader->read($xml, self::release())];
        } catch (Throwable $e) {
            return ['exception' => $e::class, 'message' => $e->getMessage()];
        }
    }

    private static function release(): object
    {
        return (object) ['searchname' => 'fixture', 'fromname' => 'poster', 'postdate' => '2026-10-01 00:00:00', 'groups_id' => '7'];
    }

    private static function fixture(int $files, int $parts, int $seed): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb'.($seed % 2 ? ' xmlns="http://www.newzbin.com/DTD/2003/nzb"' : '').'><head>'
            .'<meta type="nntmux:verification-mode">spot-check</meta><meta type="nntmux:verified-file-count">'.$files.'</meta>'
            .'<meta type="nntmux:declared-file-count">'.($files + 1).'</meta><meta type="unused">inert &amp; metadata</meta></head>';
        for ($f = 0; $f < $files; $f++) {
            $subject = '['.($f + 1).'/'.($files + 2).'] &quot;名 &amp; file'.$f.'.bin&quot; yEnc'.($seed % 3 ? '' : ' (1/'.$parts.')');
            $xml .= '<file subject="'.$subject.'" poster="poster" date="1"><groups><group>alt.fixture</group><group>alt.fixture</group><group>'.($seed % 5 === 0 ? '12' : 'alt.second').'</group></groups><segments>';
            for ($s = 1; $s <= $parts; $s++) {
                $number = $seed % 7 === 0 && $s === $parts ? 0 : ($seed % 5 === 0 && $s === $parts ? 1 : $s);
                $xml .= '<segment number="'.$number.'" bytes="'.($seed % 4 ? 1000 + $s : -$s).'"> '.($seed % 9 === 0 && $s === $parts ? '' : 'm'.$f.'-'.$s.'@example')."\t</segment>";
            }
            $xml .= '</segments></file>';
        }

        return $xml.'</nzb>';
    }
}
