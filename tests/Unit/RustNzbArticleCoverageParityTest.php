<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbArticleCoverage;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use SimpleXMLElement;

final class RustNzbArticleCoverageParityTest extends TestCase
{
    private const string CDEF = 'ssize_t nntmux_nzb_coverage_ids(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);';

    private function coverage(): NzbArticleCoverage
    {
        return (new ReflectionClass(NzbArticleCoverage::class))->newInstanceWithoutConstructor();
    }

    private function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nzb_article_coverage', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust coverage FFI export is unavailable.');
        }

        return $ffi;
    }

    private static function inventory(string $segments, string $attributes = ''): string
    {
        return '<nzb'.$attributes.'><file><segments>'.$segments.'</segments></file></nzb>';
    }

    /** @return iterable<string, array{string, list<string>|null}> */
    public static function inventories(): iterable
    {
        $segment = '<segment number="1" bytes="0">one@test</segment>';
        yield 'single' => [self::inventory($segment), ['one@test']];
        yield 'canonical namespace' => [self::inventory($segment, ' xmlns="http://www.newzbin.com/DTD/2003/nzb"'), ['one@test']];
        yield 'head groups and multiple files' => ['<nzb><head><meta type="title">Title</meta></head><file><groups><group>alt.test</group></groups><segments>'.$segment.'</segments></file><file><segments><segment number="2" bytes="999999999999999999999999">two@test</segment>'.$segment.'</segments></file></nzb>', ['one@test', 'two@test']];
        yield 'duplicate' => [self::inventory($segment.$segment), ['one@test']];
        yield 'decoded entities and angle brackets' => [self::inventory('<segment number="&#49;" bytes="&#48;"> &#x9;&lt;a&amp;b&#64;test&gt; </segment><segment number="2" bytes="00">two&#x40;test</segment>'), ['a&b@test', 'two@test']];
        foreach (['0', '01', '-1', '+1', '1.0', '1 ', '', '١'] as $number) {
            yield 'number '.$number => [self::inventory('<segment number="'.$number.'" bytes="1">one@test</segment>'), null];
        }
        foreach (['', '-1', '+1', '1.0', '1 ', '١'] as $bytes) {
            yield 'bytes '.$bytes => [self::inventory('<segment number="1" bytes="'.$bytes.'">one@test</segment>'), null];
        }
        foreach (['', 'bad id', 'bad&#127;id', 'bad&#10;id', str_repeat('a', 256), str_repeat('a', 512)] as $id) {
            yield 'invalid id '.bin2hex(substr($id, 0, 16)).strlen($id) => [self::inventory('<segment number="1" bytes="1">'.$id.'</segment>'), null];
        }
        yield 'maximum current PHP id' => [self::inventory('<segment number="1" bytes="1">'.str_repeat('a', 255).'</segment>'), [str_repeat('a', 255)]];
        yield 'quoted PHP normalization' => [self::inventory('<segment number="1" bytes="1">&quot; &lt;one@test&gt; &quot;</segment>'), ['one@test']];
        yield 'inner angle PHP compatibility' => [self::inventory('<segment number="1" bytes="1">one&lt;x&gt;@test</segment>'), ['one<x>@test']];
        yield 'non ASCII text' => [self::inventory('<segment number="1" bytes="1">é@test</segment>'), ['é@test']];
        yield 'child inside segment' => [self::inventory('<segment number="1" bytes="1">one<child/>@test</segment>'), null];
        yield 'missing attributes' => [self::inventory('<segment>one@test</segment>'), null];
        yield 'empty segment' => [self::inventory('<segment number="1" bytes="1"/>'), null];
        yield 'missing segments' => ['<nzb><file/></nzb>', null];
        yield 'multiple segments' => ['<nzb><file><segments>'.$segment.'</segments><segments>'.$segment.'</segments></file></nzb>', null];
        yield 'empty segments' => [self::inventory(''), null];
        yield 'wrong segment child' => [self::inventory($segment.'<other/>'), null];
        yield 'no files' => ['<nzb><head/></nzb>', null];
        yield 'wrong root' => ['<wrong><file><segments>'.$segment.'</segments></file></wrong>', null];
        yield 'extra root child accepted by PHP' => ['<nzb><extra/><file><segments>'.$segment.'</segments></file></nzb>', ['one@test']];
        yield 'extra file child accepted by PHP' => ['<nzb><file><extra/><segments>'.$segment.'</segments></file></nzb>', ['one@test']];
        yield 'comment' => [self::inventory('<!-- comment -->'.$segment), ['one@test']];
        yield 'processing instruction' => [self::inventory('<?test value?>'.$segment), ['one@test']];
        yield 'CDATA' => [self::inventory('<segment number="1" bytes="1"><![CDATA[<one@test>]]></segment>'), ['one@test']];
        yield 'doctype' => ['<!DOCTYPE nzb SYSTEM "urn:offline">'.self::inventory($segment), ['one@test']];
        yield 'nonstandard namespace' => [self::inventory($segment, ' xmlns="urn:other"'), null];
        yield 'namespace reset' => ['<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file xmlns=""><segments>'.$segment.'</segments></file></nzb>', null];
        yield 'prefixed namespace' => ['<n:nzb xmlns:n="http://www.newzbin.com/DTD/2003/nzb"><n:file><n:segments><n:segment number="1" bytes="1">one@test</n:segment></n:segments></n:file></n:nzb>', ['one@test']];
        yield 'foreign file' => ['<nzb xmlns:x="urn:other"><x:file/><file><segments>'.$segment.'</segments></file></nzb>', null];
    }

    /** @param list<string>|null $expected */
    #[DataProvider('inventories')]
    public function test_exact_php_and_dispatch_parity(string $raw, ?array $expected): void
    {
        $xml = new SimpleXMLElement($raw, LIBXML_NONET);
        $ids = $expected === null ? null : array_fill_keys(array_map(static fn (string $id): string => "\0".$id, $expected), true);
        $coverage = $this->coverage();
        self::assertSame($ids, $coverage->coverageIdsPhp($xml));
        self::assertSame($ids, $coverage->coverageIds($xml));
        $ffi = RustPipelineLibrary::resolveDomain('nzb_article_coverage', self::CDEF);
        if ($ffi !== null) {
            $buffer = $ffi->new('char[4096]');
            $serialized = $xml->asXML();
            self::assertNotFalse($serialized);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_coverage_ids($serialized, strlen($serialized), $buffer, 4096);
            if ($written !== -1) {
                self::assertSame($expected, $written === -2 || $written === 0 ? null : explode("\n", FFI::string($buffer, $written)));
            }
        }
    }

    public function test_native_output_retry_invalid_and_decline_protocol(): void
    {
        $ffi = $this->ffi();
        $raw = self::inventory('<segment number="1" bytes="1">one@test</segment><segment number="2" bytes="1">two@test</segment><segment number="3" bytes="1">one@test</segment>');
        $buf = $ffi->new('char[64]');
        // @phpstan-ignore method.notFound
        self::assertSame(-33, $ffi->nntmux_nzb_coverage_ids($raw, strlen($raw), $buf, 1));
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_coverage_ids($raw, strlen($raw), $buf, 64);
        self::assertSame(17, $written);
        self::assertSame("one@test\ntwo@test", FFI::string($buf, $written));
        foreach ([self::inventory('') => -2, self::inventory('<!-- edge -->') => -1, self::inventory('<![CDATA[edge]]>') => -1, self::inventory('<?edge test?>') => -1, '<!DOCTYPE nzb SYSTEM "urn:offline"><nzb/>' => -1] as $input => $status) {
            // @phpstan-ignore method.notFound
            self::assertSame($status, $ffi->nntmux_nzb_coverage_ids($input, strlen($input), $buf, 64));
        }
    }

    public function test_segment_limit_counts_duplicates_and_includes_boundary(): void
    {
        $coverage = $this->coverage();
        $segment = '<segment number="1" bytes="1">one@test</segment>';
        foreach ([100000, 100001] as $count) {
            $xml = new SimpleXMLElement(self::inventory(str_repeat($segment, $count)));
            $expected = $count === 100000 ? ["\0one@test" => true] : null;
            self::assertSame($expected, $coverage->coverageIdsPhp($xml));
            self::assertSame($expected, $coverage->coverageIds($xml));
        }
    }

    public function test_unavailable_library_uses_php_fallback(): void
    {
        $libraries = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $libraries->getValue();
        $libraries->setValue(null, ['nzb_article_coverage|default|'.hash('xxh3', self::CDEF) => false]);
        try {
            $xml = new SimpleXMLElement(self::inventory('<segment number="1" bytes="1">one@test</segment>'));
            self::assertSame(["\0one@test" => true], $this->coverage()->coverageIds($xml));
        } finally {
            $libraries->setValue(null, $saved);
        }
    }

    public function test_attached_subtree_uses_absolute_xpath_php_semantics(): void
    {
        $xml = new SimpleXMLElement('<wrapper><nzb><file><segments><segment number="1" bytes="1">one@test</segment></segments></file></nzb></wrapper>');
        self::assertNull($this->coverage()->coverageIdsPhp($xml->nzb));
        self::assertNull($this->coverage()->coverageIds($xml->nzb));
    }

    public function test_decoded_byte_limit_matches_php_fallback(): void
    {
        $xml = new SimpleXMLElement('<nzb><head><meta>'.str_repeat('x', 16777216).'</meta></head><file><segments><segment number="1" bytes="1">one@test</segment></segments></file></nzb>');
        self::assertNull($this->coverage()->coverageIdsPhp($xml));
        self::assertNull($this->coverage()->coverageIds($xml));
    }

    public function test_randomized_inventories_match_php(): void
    {
        $random = new Randomizer(new Mt19937(781));
        $coverage = $this->coverage();
        for ($case = 0; $case < 100; $case++) {
            $raw = '<nzb'.($case % 2 === 0 ? ' xmlns="http://www.newzbin.com/DTD/2003/nzb"' : '').'>';
            $expected = [];
            for ($file = 0; $file < $random->getInt(1, 5); $file++) {
                $raw .= '<file><segments>';
                for ($segment = 1; $segment <= $random->getInt(1, 20); $segment++) {
                    $id = 'id'.$random->getInt(0, 30).'@test';
                    $expected["\0".$id] = true;
                    $text = $random->getInt(0, 1) === 0 ? $id : ' &lt;'.$id.'&gt; ';
                    $raw .= '<segment bytes="00" number="'.$segment.'">'.$text.'</segment>';
                }
                $raw .= '</segments></file>';
            }
            $xml = new SimpleXMLElement($raw.'</nzb>');
            self::assertSame($expected, $coverage->coverageIdsPhp($xml));
            self::assertSame($expected, $coverage->coverageIds($xml));
        }
    }

    /**
     * Opt-in reproducible screen: WAVE5_COVERAGE_BENCHMARK=1 php artisan test
     * --compact tests/Unit/RustNzbArticleCoverageParityTest.php --filter=benchmark
     * Serialization, FFI, allocation and reconstruction are inside each timing.
     * Parsing the caller's SimpleXMLElement and result checks are outside it.
     */
    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('WAVE5_COVERAGE_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in paired benchmark.');
        }
        $this->ffi();
        $coverage = $this->coverage();
        $random = new Randomizer(new Mt19937(20261024));
        $pairs = max(40, min(2000, (int) (getenv('WAVE5_COVERAGE_BENCHMARK_PAIRS') ?: 40)));
        $qualifications = [];
        foreach (['common' => [15, 200], 'large' => [60, 1500]] as $workload => [$files, $segments]) {
            $raw = '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><head><meta type="title">Coverage benchmark</meta></head>';
            for ($file = 0; $file < $files; $file++) {
                $raw .= '<file poster="poster@test" subject="archive.part'.$file.'.rar"><groups><group>alt.binaries.test</group></groups><segments>';
                for ($segment = 1; $segment <= $segments; $segment++) {
                    $raw .= '<segment number="'.$segment.'" bytes="700000">article-'.$file.'-'.$segment.'@benchmark.test</segment>';
                }
                $raw .= '</segments></file>';
            }
            $raw .= '</nzb>';
            $xml = new SimpleXMLElement($raw);
            $expected = $coverage->coverageIdsPhp($xml);
            self::assertNotNull($expected);
            self::assertCount($files * $segments, $expected);
            // Three warmups for each implementation; no warmup is timed.
            for ($i = 0; $i < 3; $i++) {
                self::assertSame($expected, $coverage->coverageIdsPhp($xml));
                self::assertSame($expected, $coverage->coverageIds($xml));
            }
            $samples = [];
            for ($pair = 0; $pair < $pairs; $pair++) {
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                $sample = ['pair' => $pair, 'order' => $order];
                foreach ($order as $implementation) {
                    $beforeCpu = getrusage();
                    $start = hrtime(true);
                    $result = $implementation === 'php' ? $coverage->coverageIdsPhp($xml) : $coverage->coverageIds($xml);
                    $sample[$implementation] = (hrtime(true) - $start) / 1e6;
                    $afterCpu = getrusage();
                    $sample[$implementation.'_cpu_ms'] = (($afterCpu['ru_utime.tv_sec'] - $beforeCpu['ru_utime.tv_sec']) + ($afterCpu['ru_stime.tv_sec'] - $beforeCpu['ru_stime.tv_sec'])) * 1000
                        + (($afterCpu['ru_utime.tv_usec'] - $beforeCpu['ru_utime.tv_usec']) + ($afterCpu['ru_stime.tv_usec'] - $beforeCpu['ru_stime.tv_usec'])) / 1000;
                    self::assertSame($expected, $result);
                    unset($result);
                }
                $samples[] = $sample;
            }
            $php = array_column($samples, 'php');
            $rust = array_column($samples, 'rust');
            sort($php);
            sort($rust);
            $meanSpeedup = array_sum($php) / array_sum($rust);
            // Nearest-rank p99 of N=40 is the maximum; a screening estimate.
            // Use WAVE5_COVERAGE_BENCHMARK_PAIRS=1000 to assess tail stability.
            $p99Index = (int) ceil($pairs * 0.99) - 1;
            $p99Speedup = $php[$p99Index] / $rust[$p99Index];
            fwrite(STDOUT, json_encode([
                'workload' => $workload, 'files' => $files, 'segments_per_file' => $segments,
                'input_bytes' => strlen($raw), 'input_sha256' => hash('sha256', $raw),
                'php_version' => PHP_VERSION, 'host' => php_uname(),
                'source_sha256' => [
                    'coverage_php' => hash_file('sha256', __DIR__.'/../../app/Services/Nzb/NzbArticleCoverage.php'),
                    'additional_rs' => hash_file('sha256', __DIR__.'/../../rust/pipeline-ffi/src/additional.rs'),
                    'harness' => hash_file('sha256', __FILE__),
                    'composer_lock' => hash_file('sha256', __DIR__.'/../../composer.lock'),
                    'cargo_lock' => hash_file('sha256', __DIR__.'/../../rust/pipeline-ffi/Cargo.lock'),
                    'vendor_installed' => hash_file('sha256', __DIR__.'/../../vendor/composer/installed.php'),
                    'release_library' => hash_file('sha256', __DIR__.'/../../storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')),
                ],
                'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
                'warmup_each' => 3, 'pairs' => $pairs, 'seed' => 20261024,
                'php_mean_ms' => array_sum($php) / $pairs, 'rust_mean_ms' => array_sum($rust) / $pairs,
                'php_p99_ms' => $php[$p99Index], 'rust_p99_ms' => $rust[$p99Index],
                'mean_speedup' => $meanSpeedup, 'p99_speedup' => $p99Speedup,
                'raw_samples_ms' => $samples,
            ], JSON_THROW_ON_ERROR)."\n");
            $qualifications[$workload] = [$meanSpeedup, $p99Speedup];
        }
        foreach ($qualifications as $workload => [$meanSpeedup, $p99Speedup]) {
            self::assertGreaterThanOrEqual(1.5, $meanSpeedup, $workload.' mean speedup');
            self::assertGreaterThanOrEqual(1.5, $p99Speedup, $workload.' p99 speedup');
        }
    }
}
