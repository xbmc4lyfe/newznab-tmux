<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustVerifiedNzbWriterParityTest extends TestCase
{
    private VerifiedNzbWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        VerifiedNzbWriter::setUseRustFfi(true);
        $this->writer = (new ReflectionClass(VerifiedNzbWriter::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        VerifiedNzbWriter::setUseRustFfi(true);
        parent::tearDown();
    }

    private function requireFfi(): void
    {
        if (RustPipelineLibrary::resolveDomain('verified_nzb_document', <<<'C'
            ssize_t nntmux_verified_nzb_document(const char *meta, size_t meta_len, size_t group_count, size_t password_count,
                int64_t inventory, uint32_t flags, const char *files, size_t files_len, size_t file_count, size_t segment_count,
                char *out_buf, size_t out_cap);
            C) === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function manifest(array $files, array $extra = []): array
    {
        return $extra + [
            'fromname' => 'Poster <poster@example.com>',
            'date' => '2026-10-24 12:34:56',
            'groups' => ['alt.binaries.test', 'alt.binaries.misc'],
            'files' => $files,
        ];
    }

    /** @return array<string, mixed> */
    private static function file(string $subject, int $totalParts, int $segments, string $prefix = 'part'): array
    {
        $list = [];
        for ($i = 1; $i <= $segments; $i++) {
            $list[] = ['number' => $i, 'bytes' => 739_000 + $i, 'messageid' => '<'.$prefix.$i.'$abc@news.example.com>'];
        }

        return ['subject' => $subject, 'totalparts' => $totalParts, 'segments' => $list];
    }

    /** @param array<string, mixed> $manifest */
    private function assertParity(array $manifest, bool $expectNative = true): void
    {
        $php = $this->writer->documentPhp($manifest);
        $native = $this->writer->documentNative($manifest);
        if ($expectNative) {
            self::assertNotNull($native);
            self::assertSame($php, $native);
        } else {
            self::assertNull($native);
        }
        self::assertSame($php, $this->writer->document($manifest));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function manifests(): iterable
    {
        $file = self::file('Release.Name.part01.rar "yEnc"', 12, 3);
        yield 'plain' => [self::manifest([$file]), true];
        yield 'no files' => [self::manifest([]), true];
        yield 'only empty files' => [self::manifest([['subject' => 'x', 'totalparts' => 1, 'segments' => []]]), true];
        yield 'empty files with inventory' => [self::manifest([], ['expected_files' => 4]), true];
        yield 'verified count' => [self::manifest([$file, $file], ['verified_file_count' => '7']), true];
        yield 'spot check' => [self::manifest([$file], ['verification_mode' => 'spot_check', 'expected_files' => 9]), true];
        yield 'spot check no inventory' => [self::manifest([], ['verification_mode' => 'spot_check']), true];
        yield 'passwords' => [self::manifest([$file], ['passwords' => ['p&ss<w>"rd\'', "tab\tline\ncr\r", 'ünïcødé 🔑', '']]), true];
        yield 'passwords only' => [self::manifest([], ['passwords' => ['secret']]), true];
        yield 'escaped strings' => [self::manifest([
            ['subject' => "A & B <C> \"D\" 'E'\tF\nG\rH ]]> é 日本", 'totalparts' => 3, 'segments' => [
                ['number' => '1&"', 'bytes' => "2<\t>", 'messageid' => "<<a&b<c>\"d\"\t\n\r'e>>"],
                ['number' => 2, 'bytes' => 0, 'messageid' => '<>'],
                ['number' => 3.5, 'bytes' => null, 'messageid' => ''],
            ]],
        ], ['fromname' => "Po\"st&er <x>\t\n\r é", 'groups' => ['a&b', '<c>', '"q"', "t\tn\nr\r", '']]), true];
        yield 'no groups' => [self::manifest([$file], ['groups' => []]), true];
        yield 'integer subject' => [self::manifest([['subject' => 42, 'totalparts' => 1, 'segments' => [
            ['number' => 1, 'bytes' => 5, 'messageid' => 'm@x'],
        ]]]), true];
        yield 'non-ascii and DEL' => [self::manifest([self::file("Unicode \u{FFFD}\u{10FFFF}\x7f", 1, 2)]), true];
        yield 'control byte subject' => [self::manifest([self::file("Bad\x01Subject", 1, 1)]), false];
        yield 'control byte message id' => [self::manifest([['subject' => 's', 'totalparts' => 1, 'segments' => [
            ['number' => 1, 'bytes' => 5, 'messageid' => "<m\x00x@y>"],
        ]]]), false];
        yield 'control byte poster' => [self::manifest([$file], ['fromname' => "p\x1f"]), false];
        yield 'invalid utf8' => [self::manifest([self::file("Bad\xff\xfe", 1, 1)]), false];
        yield 'unparsable date' => [self::manifest([$file], ['date' => 'not a date at all']), false];
        yield 'unparsable date without files' => [self::manifest([], ['date' => 'not a date at all']), true];
    }

    #[Test]
    public function native_document_matches_xml_writer_bytes(): void
    {
        $this->requireFfi();
        foreach (self::manifests() as $name => [$manifest, $expectNative]) {
            $php = $this->writer->documentPhp($manifest);
            $native = $this->writer->documentNative($manifest);
            if ($expectNative) {
                self::assertSame($php, $native, $name);
            } else {
                self::assertNull($native, $name);
            }
            self::assertSame($php, $this->writer->document($manifest), $name);
        }
    }

    #[Test]
    public function large_manifest_overflow_retry_matches(): void
    {
        $this->requireFfi();
        $files = [];
        for ($i = 0; $i < 80; $i++) {
            $files[] = self::file(sprintf('Big.Release.part%03d.rar "yEnc" %s', $i, str_repeat('&<>"', 50)), 80, 60, str_repeat('&', 40));
        }
        $this->assertParity(self::manifest($files, ['expected_files' => 80]));
    }

    #[Test]
    public function randomized_manifests_match(): void
    {
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(20261024));
        $alphabet = "abcXYZ019 .-_&<>\"'\t\n\r[]()@\$é日🔑";
        $chars = mb_str_split($alphabet);
        $string = static function (int $max) use ($random, $chars): string {
            $out = '';
            for ($i = $random->getInt(0, $max); $i > 0; $i--) {
                $out .= $chars[$random->getInt(0, count($chars) - 1)];
            }

            return $out;
        };
        for ($round = 0; $round < 200; $round++) {
            $files = [];
            for ($f = $random->getInt(0, 5); $f > 0; $f--) {
                $segments = [];
                for ($s = $random->getInt(0, 4); $s > 0; $s--) {
                    $segments[] = ['number' => $random->getInt(0, 999), 'bytes' => $random->getInt(0, 999_999), 'messageid' => $string(30)];
                }
                $files[] = ['subject' => $string(40), 'totalparts' => $random->getInt(1, 99), 'segments' => $segments];
            }
            $groups = [];
            for ($g = $random->getInt(0, 3); $g > 0; $g--) {
                $groups[] = $string(20);
            }
            $passwords = [];
            for ($p = $random->getInt(0, 2); $p > 0; $p--) {
                $passwords[] = $string(12);
            }
            $manifest = self::manifest($files, [
                'fromname' => $string(25),
                'groups' => $groups,
                'passwords' => $passwords,
                'expected_files' => $random->getInt(0, 6),
                'verification_mode' => $random->getInt(0, 1) === 1 ? 'spot_check' : 'full',
            ]);
            $this->assertParity($manifest);
        }
    }

    #[Test]
    public function invalid_passwords_throw_on_both_paths(): void
    {
        $this->requireFfi();
        foreach ([['bad' => 'x'], ["ctl\x01"], [1], 'nope'] as $passwords) {
            $manifest = self::manifest([self::file('s', 1, 1)], ['passwords' => $passwords]);
            foreach (['documentPhp', 'documentNative', 'document'] as $method) {
                try {
                    $this->writer->{$method}($manifest);
                    self::fail($method.' accepted invalid passwords');
                } catch (RuntimeException $e) {
                    self::assertSame('Invalid verified NZB password metadata.', $e->getMessage());
                }
            }
        }
    }

    #[Test]
    public function disabled_ffi_uses_php(): void
    {
        VerifiedNzbWriter::setUseRustFfi(false);
        $manifest = self::manifest([self::file('s', 1, 2)]);
        self::assertNull($this->writer->documentNative($manifest));
        self::assertSame($this->writer->documentPhp($manifest), $this->writer->document($manifest));
    }

    /**
     * Opt-in real-caller benchmark of document() (packing + FFI + copy) against documentPhp().
     * NNTMUX_VERIFIED_NZB_BENCHMARK=1 php -d opcache.enable_cli=1 artisan test --compact
     * tests/Unit/RustVerifiedNzbWriterParityTest.php --filter=paired_benchmark
     */
    #[Test]
    public function paired_benchmark(): void
    {
        if (getenv('NNTMUX_VERIFIED_NZB_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_VERIFIED_NZB_BENCHMARK=1 to run 40 paired blocks per workload.');
        }
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(24102026));
        $build = static function (int $fileCount, int $segmentsPerFile) use ($random): array {
            $files = [];
            for ($f = 0; $f < $fileCount; $f++) {
                $segments = [];
                for ($s = 1; $s <= $segmentsPerFile; $s++) {
                    $segments[] = ['number' => $s, 'bytes' => $random->getInt(700_000, 800_000),
                        'messageid' => '<'.bin2hex($random->getBytes(12)).'$part'.$s.'@news.example.com>'];
                }
                $files[] = ['subject' => sprintf('Some.Release.Name.2026.1080p.WEB-DL.x264-GRP [%02d/%02d] - "file.part%02d.rar" yEnc', $f + 1, $fileCount, $f + 1),
                    'totalparts' => $segmentsPerFile, 'segments' => $segments];
            }

            return self::manifest($files, ['expected_files' => $fileCount, 'passwords' => ['secret']]);
        };
        $workloads = ['common' => $build(20, 20), 'uncommon' => $build(80, 30)];
        $p99 = static function (array $values): float {
            sort($values);

            return $values[(int) ceil(count($values) * 0.99) - 1];
        };
        $report = ['seed' => 24102026, 'pairs' => 40, 'calls_per_block' => 25, 'warmup' => 200];
        foreach ($workloads as $name => $manifest) {
            self::assertSame($this->writer->documentPhp($manifest), $this->writer->document($manifest));
            for ($i = 0; $i < 200; $i++) {
                $this->writer->documentPhp($manifest);
                $this->writer->document($manifest);
            }
            $samples = ['php' => [], 'rust' => []];
            for ($pair = 0; $pair < 40; $pair++) {
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                foreach ($order as $variant) {
                    for ($call = 0; $call < 25; $call++) {
                        $start = hrtime(true);
                        $variant === 'php' ? $this->writer->documentPhp($manifest) : $this->writer->document($manifest);
                        $samples[$variant][] = hrtime(true) - $start;
                    }
                }
            }
            $summary = [];
            foreach ($samples as $variant => $values) {
                $summary[$variant] = ['mean_ns' => array_sum($values) / count($values), 'p99_ns' => $p99($values)];
            }
            $summary['mean_speedup'] = $summary['php']['mean_ns'] / $summary['rust']['mean_ns'];
            $summary['p99_speedup'] = $summary['php']['p99_ns'] / $summary['rust']['p99_ns'];
            $report['workloads'][$name] = $summary;
            fwrite(STDERR, $name.' '.json_encode($summary, JSON_THROW_ON_ERROR)."\n");
            self::assertGreaterThanOrEqual(1.5, $summary['mean_speedup'], $name.' mean');
            self::assertGreaterThanOrEqual(1.5, $summary['p99_speedup'], $name.' p99');
        }
    }
}
