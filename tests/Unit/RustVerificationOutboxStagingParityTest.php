<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbUploadStagingService as Staging;
use App\Services\Releases\Verification\ArticleSource;
use App\Services\Releases\Verification\PayloadVerifier as Payload;
use App\Services\Releases\Verification\PreparationRunDiagnostics as Diagnostics;
use App\Services\Releases\Verification\PublicationOutboxEntry as Outbox;
use App\Services\Releases\Verification\SpotCheckVerifier as Spot;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class RustVerificationOutboxStagingParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::toggles(true);
        self::assertNotNull(self::library(), 'Build the release pipeline library before parity validation.');
    }

    protected function tearDown(): void
    {
        self::toggles(false);
        parent::tearDown();
    }

    private static function toggles(bool $enabled): void
    {
        foreach ([Payload::class, Spot::class, Outbox::class, Diagnostics::class, Staging::class] as $class) {
            $class::setUseRustFfi($enabled);
        }
    }

    private static function library(): ?FFI
    {
        $cdef = '';
        foreach ([Payload::class, Spot::class, Outbox::class, Diagnostics::class, Staging::class] as $class) {
            $cdef .= (new ReflectionClass($class))->getConstant('FFI_CDEF')."\n";
        }

        return RustPipelineLibrary::resolveDomain('nfo', $cdef);
    }

    /** @param list<mixed> $arguments */
    private static function abi(FFI $ffi, string $symbol, array $arguments): mixed
    {
        return $ffi->{$symbol}(...$arguments);
    }

    private static function article(string $name = 'file.bin', int $size = 3, int $offset = 0, int $part = 1,
        ?int $total = 1, string $bytes = 'abc'): VerifiedArticle
    {
        $class = new ReflectionClass(VerifiedArticle::class);
        $article = $class->newInstanceWithoutConstructor();
        foreach (['name' => $name, 'size' => $size, 'offset' => $offset, 'part' => $part,
            'totalParts' => $total, 'bytes' => $bytes, 'fileChecksum' => null] as $key => $value) {
            $class->getProperty($key)->setValue($article, $value);
        }

        return $article;
    }

    public function test_payload_identity_rejections_preserve_precedence_and_nullable_totals(): void
    {
        $cases = [
            [self::article(), 1, 1, 1, false, null, null],
            [self::article(part: 2), 1, 1, 1, false, 2, self::article('other')],
            [self::article(total: 2), 1, 1, 1, false, 3, null],
            [self::article(total: 2), 1, 1, 1, false, null, null],
            [self::article(total: 2), 1, 3, 3, true, null, null],
            [self::article(part: 3, total: null), 3, 2, 2, true, null, null],
            [self::article(), 1, 1, 1, false, null, self::article('other')],
            [self::article(total: null), 1, 1, 1, false, 2, null],
        ];
        foreach ($cases as $case) {
            self::assertSame(Payload::articleIdentityRejectionPhp(...$case), Payload::articleIdentityRejectionNative(...$case));
        }
        $article = VerifiedArticle::decode((new YencService)->encode('abc', 'file.bin'));
        self::assertNull(Payload::articleIdentityRejectionNative($article, 1, 1, 1, false, null, $article));
    }

    public function test_filename_extraction_handles_quotes_binary_paths_and_locale_fallback(): void
    {
        $startupAsciiWhitespace = preg_match('/^\s$/', "\xa0") === 0;
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C', 'en_US.UTF-8', 'en_US.ISO8859-1', 'fr_FR.ISO8859-1'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                foreach (['', 'file.bin', '[1/2] "file.bin" yEnc', '"" "file.bin"', "\"bad\r\n\" \"ok\"", '../a',
                    '.hidden', '-option', 'dir\\file', 'a[1].bin', 'YENC', "a\0b", "a\x7fb", 'café.bin', "\xff.bin", "a\xa0b"] as $subject) {
                    self::assertSame(Payload::requiredNamePhp($subject), Payload::requiredNameNative($subject), bin2hex($subject));
                    self::assertSame(Payload::validPar2FilenamePhp($subject), Payload::validPar2FilenameNative($subject), bin2hex($subject));
                }
            }
        } finally {
            if ($locale !== false) {
                setlocale(LC_CTYPE, $locale);
            }
            if ($startupAsciiWhitespace) {
                setlocale(LC_CTYPE, 'C');
            }
        }
    }

    public function test_spot_dimensions_and_stable_sample_match_existing_planner(): void
    {
        foreach ([self::article(), self::article(size: 4), self::article(offset: 1), self::article(part: 2),
            self::article(size: PHP_INT_MAX, offset: PHP_INT_MAX, part: 2), self::article(total: null)] as $article) {
            $segment = ['number' => 1, 'bytes' => 3, 'totalparts' => 1];
            self::assertSame(Spot::articleDimensionsMatchPhp($article, $segment), Spot::articleDimensionsMatchNative($article, $segment));
        }
        $verifier = self::spot();
        foreach ([0, 1, 16, 128] as $count) {
            $manifest = self::manifest($count);
            self::assertSame($verifier->samplePhp($manifest), $verifier->sampleNative($manifest));
            Spot::setUseRustFfi(false);
            $php = $verifier->sample($manifest);
            Spot::setUseRustFfi(true);
            self::assertSame($php, $verifier->sample($manifest));
        }
        $manifest = self::manifest(8);
        $manifest['files'][0]['segments'][0]['bytes'] = '4';
        self::assertSame($verifier->samplePhp($manifest), $verifier->sampleNative($manifest));
    }

    public function test_outbox_json_validation_hydration_and_warning_fallback_match(): void
    {
        $base = self::outboxRow();
        $rows = [$base, [...$base, 'attempts' => -7, 'deferrals' => '2', 'heartbeat_at' => null],
            [...$base, 'enqueued_at' => '1e3', 'not_before' => true],
            [...$base, 'evidence' => [...$base['evidence'], 'identity_hints' => [false, null, 7, ['nested']]]],
            [...$base, 'claim' => [...$base['claim'], 'id' => '1']],
            [...$base, 'reason' => 1], [...$base, 'attempts' => []], [], null];
        foreach ($rows as $row) {
            $json = json_encode($row, JSON_THROW_ON_ERROR);
            self::assertSame(self::capture(fn () => Outbox::decodePhp('a.json', '/tmp/a.json', $json, 123)),
                self::capture(fn () => Outbox::decode('a.json', '/tmp/a.json', $json, 123)));
        }
        foreach (['{', 'false', 'null', '{"claim":1}', "\xff", str_repeat('[', 514).'0'.str_repeat(']', 514)] as $json) {
            self::assertNull(Outbox::decode('a', 'b', $json, 0));
        }
        $entry = Outbox::decode('a', 'b', json_encode($base, JSON_THROW_ON_ERROR), 123);
        self::assertNotNull($entry);
        self::assertSame(17.5, $entry->heartbeatAt);
        self::assertSame(123, $entry->modifiedAt);
        self::assertSame(['movie.mkv', '7', ''], $entry->evidence['verified_file_names']);
    }

    public function test_diagnostic_accumulation_buckets_and_report_projection_match(): void
    {
        $php = new Diagnostics(100);
        $rust = new Diagnostics(100);
        foreach ([-1.0, 0.0, 0.1, 0.10001, 1.0, 10.0, 100.0, 1000.0, 10000.0, 10001.0] as $ms) {
            $php->recordPhasePhp('file_read', $ms, true);
            self::assertTrue($rust->recordPhaseNative('file_read', $ms, true));
        }
        foreach ([-1, 0, 1, 10, 100, 1000, 4096, 4097, 65536, 1048576, 16777216, PHP_INT_MAX] as $size) {
            foreach (['compressed_bytes', 'json_bytes', 'files'] as $kind) {
                $php->recordSizePhp($kind, $size);
                self::assertTrue($rust->recordSizeNative($kind, $size));
            }
        }
        foreach ([$php, $rust] as $diagnostics) {
            $diagnostics->selected(3);
            $diagnostics->admitted();
            $diagnostics->assessed('header_rejected');
            $diagnostics->updated('ready', 2, 3);
            $diagnostics->readAssessmentFailed();
        }
        foreach ([false, true] as $error) {
            foreach ([0, 5, 20] as $inspected) {
                foreach ([500, 1000, 1500] as $finished) {
                    self::assertSame($php->reportAtPhp(5, 10, $inspected, 1000, true, $error, $finished),
                        $rust->reportAtNative(5, 10, $inspected, 1000, true, $error, $finished));
                }
            }
        }
        foreach ([INF, -INF, NAN] as $ms) {
            $php->recordPhasePhp('hash', $ms, false);
            self::assertTrue($rust->recordPhaseNative('hash', $ms, false));
            self::assertSame(serialize($php->reportAtPhp(1, 1, 0, 1, false, false, 1)),
                serialize($rust->reportAtNative(1, 1, 0, 1, false, false, 1)));
        }
    }

    public function test_staging_filename_and_xml_semantics_preserve_errors_and_exact_bytes(): void
    {
        foreach (['x.nzb', 'X.NZB', '.nzb', ' .nzb', "\x0c.nzb", '..nzb', 'x..nzb', '', '.', '..',
            '../x.nzb', 'x\\y.nzb', "x\0.nzb", 'x.nfo', 'café.nzb'] as $filename) {
            self::assertSame(self::capture(fn () => Staging::validateFilenamePhp($filename, 'nzb')),
                self::capture(fn () => Staging::validateFilenameNative($filename, 'nzb') ?? Staging::validateFilenamePhp($filename, 'nzb')));
        }
        $mode = libxml_use_internal_errors();
        try {
            foreach (['', '0', '<nzb/>', '<NZB/>', '<wrong/>', '<wrong xmlns="urn:nzb"/>', '<nzb>',
                "\xef\xbb\xbf<nzb/>", "\xef\xbb\xbf", "\x1f\x8b", gzencode('<nzb/>'), '<!DOCTYPE nzb [<!ENTITY a "ok">]><nzb>&a;</nzb>'] as $xml) {
                self::assertIsString($xml);
                self::assertSame(Staging::hasGzipMagicPhp($xml), Staging::hasGzipMagicNative($xml));
                self::assertSame(Staging::hasUtf8BomPhp($xml), Staging::hasUtf8BomNative($xml));
                libxml_use_internal_errors(false);
                $expected = Staging::xmlPayloadPhp($xml);
                $expectedMode = libxml_use_internal_errors();
                libxml_use_internal_errors(false);
                self::assertSame($expected, Staging::xmlPayloadNative($xml));
                self::assertSame($expectedMode, libxml_use_internal_errors());
                self::assertSame([], libxml_get_errors());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($mode);
        }
    }

    public function test_direct_abi_executes_and_rejects_invalid_ranges_and_capacity(): void
    {
        $ffi = self::library();
        self::assertNotNull($ffi);
        $buffer = $ffi->new('char[2048]');
        self::assertSame(0, self::abi($ffi, 'nntmux_payload_article_identity', [1, 1, 1, 1, 0, 1, 1, 0, 0, 1]));
        self::assertSame(1, self::abi($ffi, 'nntmux_spot_article_dimensions', [1, 1, 3, 0, 3, 3, 1]));
        self::assertSame(0, self::abi($ffi, 'nntmux_upload_staging_filename', ['a.nzb', 5, 'nzb', 3]));
        self::assertSame(1, self::abi($ffi, 'nntmux_upload_staging_prefix', ["\x1f\x8b", 2, 0]));
        self::assertSame(0, self::abi($ffi, 'nntmux_verification_diagnostic_size', [0, 4096]));
        self::assertSame(15, self::abi($ffi, 'nntmux_verification_diagnostic_projection', [1, 1, 10, 10, 0, 1, 1]));
        $input = serialize(self::outboxRow());
        $small = self::abi($ffi, 'nntmux_verification_outbox_normalize', [$input, strlen($input), $buffer, 1]);
        self::assertLessThan(-1, $small);
        $written = self::abi($ffi, 'nntmux_verification_outbox_normalize', [$input, strlen($input), $buffer, 2048]);
        self::assertGreaterThan(0, $written);
        self::assertIsArray(unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]));
        self::assertSame(-8, self::abi($ffi, 'nntmux_payload_subject_name', ['filename', 8, $buffer, 1]));
        foreach (['nntmux_payload_subject_name', 'nntmux_verification_outbox_normalize'] as $symbol) {
            self::assertSame(-1, self::abi($ffi, $symbol, [null, 1, $buffer, 2048]));
            self::assertSame(-1, self::abi($ffi, $symbol, [$buffer, 1, $buffer, 2048]));
        }
        self::assertSame(-1, self::abi($ffi, 'nntmux_verification_sample_plan', [null, 1, '', 0, 1, 10, 10, $buffer, 2048]));
    }

    public function test_missing_ffi_library_falls_back_through_real_dispatch(): void
    {
        $code = <<<'CODE'
require 'vendor/autoload.php';
use App\Services\Releases\Verification\PayloadVerifier as P;
use App\Services\Releases\Verification\PublicationOutboxEntry as O;
use App\Services\Releases\Verification\PreparationRunDiagnostics as D;
P::setUseRustFfi(true); O::setUseRustFfi(true); D::setUseRustFfi(true);
$d = new D(0); $d->recordPhase('hash', 1, false); $d->recordSize('files', 1);
echo serialize([P::requiredNameNative('"file.bin" yEnc'), P::validPar2FilenameNative('file.bin'), O::decode('a', 'b', '{}', 0), $d->reportAtPhp(1,1,0,10,false,false,1)['phase_distributions']['hash']['count']]);
CODE;
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code], dirname(__DIR__, 2));
        $process->mustRun();
        self::assertSame(['file.bin', true, null, 1], unserialize($process->getOutput(), ['allowed_classes' => false]));
    }

    /** @return array{mixed, list<string>, string|null} */
    private static function capture(callable $operation): array
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $severity.':'.$message;

            return true;
        });
        try {
            $value = $operation();

            return [is_object($value) ? get_object_vars($value) : $value, $warnings, null];
        } catch (Throwable $error) {
            return [null, $warnings, $error::class.':'.$error->getMessage()];
        } finally {
            restore_error_handler();
        }
    }

    private static function spot(): Spot
    {
        return new Spot(new class implements ArticleSource
        {
            public function fetch(string $messageId): string
            {
                return (new YencService)->encode('abc', 'file.bin');
            }
        }, ['spot_check_articles' => 16, 'spot_check_bytes' => 1048576]);
    }

    /** @return array<string,mixed> */
    private static function manifest(int $segments): array
    {
        $files = [];
        for ($f = 0; $f < min(4, $segments); $f++) {
            $parts = [];
            for ($i = 1; $i <= $segments; $i++) {
                $parts[] = ['number' => $i, 'messageid' => $f.'-'.$i.'@article', 'bytes' => 3];
            }
            $files[] = ['key' => 'f'.$f, 'subject' => '"file.bin" yEnc', 'totalparts' => $segments,
                'totalparts_inferred' => $f % 2 === 0, 'segments' => $parts];
        }

        return ['identity' => 'candidate', 'files' => $files];
    }

    /** @return array<string,mixed> */
    private static function outboxRow(): array
    {
        return ['claim' => ['id' => 1, 'version' => 2, 'manifest_hash' => 'hash', 'lease_token' => 'token'],
            'reason' => 'sampled', 'evidence' => ['articles' => 2, 'bytes' => 1234, 'sampled_files' => 1,
                'verified_file_names' => ['movie.mkv', 7, null], 'identity_hints' => ['movie']],
            'enqueued_at' => 17.5, 'attempts' => 0, 'not_before' => 0.0];
    }

    /**
     * Admission record: 2026-10-24, Apple M5, 10 CPUs, 32 GiB, macOS 27.2,
     * PHP 8.5.12 (CLI opcache/JIT off), Rust 1.99.1 release opt=3/LTO.
     * Two valid runs, each 4096 pairs x 26 candidate/workloads, 64 warmups:
     * 212992 measured pairs. Fixed fixtures and seeded randomized paired order.
     * Minimum common mean / uncommon p99 speedups across the two valid runs:
     * payload_identity          0.13x  0.29x
     * spot_dimensions           0.26x  0.29x
     * payload_subject           0.48x  0.34x
     * payload_par2_filename     0.40x  0.58x
     * spot_sample               0.75x  0.58x
     * staging_filename          0.90x  0.90x
     * staging_gzip_magic        0.26x  0.50x
     * staging_bom               0.28x  0.40x
     * staging_xml_payload       0.88x  1.03x
     * outbox_decode             0.31x  0.39x
     * diagnostic_phase          0.23x  0.18x
     * diagnostic_size           0.61x  0.48x
     * diagnostic_projection     0.41x  0.38x
     * No candidate meets both 1.5x gates; all five runtime toggles default off.
     * Raw pairs, first calls, output and source hashes, p99 rank intervals:
     * /tmp/wave13-slice2-run{1,3}.json and adjacent .json.jsonl records.
     * Hardware, limits, tool versions and rejected experiment/setup records:
     * /tmp/wave13-slice2-environment.json. Run 2 was invalidated for overlap.
     * Frozen measured sources: /tmp/wave13-slice2-measured-source.
     */
    /**
     * Reproduce after a release build, without Laravel/database boot:
     * php -r 'require "vendor/autoload.php"; require "tests/Unit/RustVerificationOutboxStagingParityTest.php";
     * Tests\Unit\RustVerificationOutboxStagingParityTest::benchmark("/tmp/wave13-slice2.json");'
     *
     * Protocol: 4096 randomized pairs per candidate/workload, 64 warmups, seed 1302.
     * Time complete PHP callers, including FFI, allocation, serialization and hydration.
     * Keep raw pairs and input/source/library/environment identities in the artifact.
     * Candidates stay disabled unless every common mean and uncommon p99 exceeds 1.5x.
     */
    public static function benchmark(string $destination, int $samples = 4096): void
    {
        self::toggles(true);
        if (self::library() === null || $samples < 4096) {
            throw new RuntimeException('Release library and at least 4096 pairs required.');
        }
        $root = dirname(__DIR__, 2);
        $paths = ['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock',
            'vendor/composer/installed.php', 'app/Services/Releases/Verification/PayloadVerifier.php',
            'app/Services/Releases/Verification/SpotCheckVerifier.php', 'app/Services/Releases/Verification/PublicationOutboxEntry.php',
            'app/Services/Releases/Verification/PreparationRunDiagnostics.php', 'app/Services/Nzb/NzbUploadStagingService.php',
            'tests/Unit/RustVerificationOutboxStagingParityTest.php'];
        $metadata = ['utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'os' => php_uname(),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'pcre' => PCRE_VERSION,
            'warmups' => 64, 'samples' => $samples, 'seed' => 1302,
            'order' => 'randomized paired complete PHP caller invocations',
            'source_sha256' => array_combine($paths, array_map(fn (string $path) => hash_file('sha256', $root.'/'.$path), $paths)),
            'library_sha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'))];
        file_put_contents($destination.'.jsonl', json_encode(['event' => 'protocol', 'metadata' => $metadata], JSON_THROW_ON_ERROR)."\n");
        $random = new Randomizer(new Mt19937(1302));
        $results = [];
        try {
            foreach (self::benchmarkCases() as $case => [$php, $rust]) {
                $first = [];
                $values = [];
                foreach ([$php, $rust] as $which => $call) {
                    $start = hrtime(true);
                    $values[$which] = serialize($call());
                    $first[$which] = hrtime(true) - $start;
                }
                if ($values[0] !== $values[1]) {
                    throw new RuntimeException('Parity failure: '.$case);
                }
                for ($i = 0; $i < 64; $i++) {
                    $php();
                    $rust();
                }
                $raw = [];
                for ($i = 0; $i < $samples; $i++) {
                    $order = $random->getInt(0, 1);
                    $durations = [];
                    foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                        $call = $which === 0 ? $php : $rust;
                        $start = hrtime(true);
                        $call();
                        $durations[$which] = hrtime(true) - $start;
                    }
                    $raw[] = [$order, $durations[0], $durations[1]];
                }
                $base = array_column($raw, 1);
                $candidate = array_column($raw, 2);
                sort($base);
                sort($candidate);
                $p99 = (int) ceil(0.99 * $samples) - 1;
                $radius = (int) ceil(1.96 * sqrt($samples * 0.99 * 0.01));
                $lo = max(0, $p99 - $radius);
                $hi = min($samples - 1, $p99 + $radius);
                $results[$case] = ['first_calls_ns' => $first, 'output_sha256' => hash('sha256', $values[0]),
                    'php_mean_ns' => array_sum($base) / $samples, 'rust_mean_ns' => array_sum($candidate) / $samples,
                    'mean_speedup' => array_sum($base) / array_sum($candidate),
                    'php_p99_ns' => $base[$p99], 'rust_p99_ns' => $candidate[$p99],
                    'p99_speedup' => $base[$p99] / $candidate[$p99],
                    'p99_speedup_rank_interval' => [$base[$lo] / $candidate[$hi], $base[$hi] / $candidate[$lo]],
                    'raw_pairs_order_php_rust_ns' => $raw];
                file_put_contents($destination.'.jsonl', json_encode(['event' => 'measurement', 'case' => $case, 'result' => $results[$case]], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
                fwrite(STDOUT, sprintf("%-40s mean %.2fx p99 %.2fx\n", $case, $results[$case]['mean_speedup'], $results[$case]['p99_speedup']));
            }
            file_put_contents($destination, json_encode(['metadata' => $metadata, 'results' => $results], JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            file_put_contents($destination.'.jsonl', json_encode(['event' => 'invalid', 'error' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            throw $error;
        } finally {
            self::toggles(false);
        }
    }

    /** @return array<string,array{callable():mixed,callable():mixed}> */
    private static function benchmarkCases(): array
    {
        $cases = [];
        foreach (['common' => self::article(), 'uncommon' => self::article(str_repeat('x', 200), PHP_INT_MAX, 300, 7, null, str_repeat('x', 65536))] as $workload => $article) {
            $segment = ['number' => $article->part, 'bytes' => 70000, 'totalparts' => 8];
            $cases['payload_identity/'.$workload] = [
                fn () => Payload::articleIdentityRejectionPhp($article, $article->part, 8, 8, true, null, $article),
                fn () => Payload::articleIdentityRejectionNative($article, $article->part, 8, 8, true, null, $article)];
            $cases['spot_dimensions/'.$workload] = [fn () => Spot::articleDimensionsMatchPhp($article, $segment),
                fn () => Spot::articleDimensionsMatchNative($article, $segment)];
        }
        foreach (['common' => '"file.bin" yEnc', 'uncommon' => str_repeat('a', 4096).' "'.str_repeat('b', 200).'.bin" yEnc'] as $workload => $subject) {
            $cases['payload_subject/'.$workload] = [fn () => Payload::requiredNamePhp($subject), fn () => Payload::requiredNameNative($subject)];
        }
        foreach (['common' => 'file.bin', 'uncommon' => str_repeat('x', 196).'.bin'] as $workload => $name) {
            $cases['payload_par2_filename/'.$workload] = [fn () => Payload::validPar2FilenamePhp($name), fn () => Payload::validPar2FilenameNative($name)];
        }
        $spot = self::spot();
        foreach (['common' => self::manifest(8), 'uncommon' => self::manifest(1024)] as $workload => $manifest) {
            $cases['spot_sample/'.$workload] = [fn () => $spot->samplePhp($manifest), fn () => $spot->sampleNative($manifest)];
        }
        $cases += (static function (): array {
            Staging::setUseRustFfi(true);
            $pairs = [];
            foreach (['common' => 'Release.2026.1080p.nzb', 'uncommon' => str_repeat('r', 200).'.nzb'] as $workload => $name) {
                $pairs['staging_filename/'.$workload] = [
                    static fn () => Staging::validateFilenamePhp($name, 'nzb'),
                    static fn () => Staging::validateFilenameNative($name, 'nzb'),
                ];
            }
            foreach (['common' => '<nzb/>', 'uncommon' => "\x1f\x8b".str_repeat('x', 65535)] as $workload => $content) {
                $pairs['staging_gzip_magic/'.$workload] = [
                    static fn () => Staging::hasGzipMagicPhp($content),
                    static fn () => Staging::hasGzipMagicNative($content),
                ];
            }
            foreach (['common' => '<nzb/>', 'uncommon' => "\xef\xbb\xbf".str_repeat('x', 65535)] as $workload => $content) {
                $pairs['staging_bom/'.$workload] = [
                    static fn () => Staging::hasUtf8BomPhp($content),
                    static fn () => Staging::hasUtf8BomNative($content),
                ];
            }
            foreach (['common' => '<nzb><file subject="release"><segments><segment>id@x</segment></segments></file></nzb>', 'uncommon' => "\xef\xbb\xbf<nzb>".str_repeat('<file subject="x"/>', 200).'</nzb>'] as $workload => $xml) {
                $pairs['staging_xml_payload/'.$workload] = [
                    static fn () => Staging::xmlPayloadPhp($xml),
                    static fn () => Staging::xmlPayloadNative($xml),
                ];
            }

            return $pairs;
        })();
        $cases += (static function (): array {

            // Require Composer autoload before including this file. Each closure executes a
            // complete caller candidate and returns its result for parity/hash validation.
            Outbox::setUseRustFfi(true);
            Diagnostics::setUseRustFfi(true);
            $cases = [];
            foreach (['common' => 8, 'uncommon' => 512] as $workload => $count) {
                $names = [];
                for ($i = 0; $i < $count; $i++) {
                    $names['file_'.$i] = 'Release.Part.'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).'.rar';
                }
                $row = ['claim' => ['id' => 124, 'version' => 3, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 'lease-token'],
                    'reason' => 'verified', 'evidence' => ['articles' => 16, 'bytes' => 1234567, 'sampled_files' => 3,
                        'verified_file_names' => $names, 'identity_hints' => ['Example.Release', true, 12, null]],
                    'enqueued_at' => 1780000000.125, 'heartbeat_at' => 1780000050.25, 'attempts' => 2,
                    'deferrals' => 1, 'not_before' => 1780000100.5];
                $json = json_encode($row, JSON_THROW_ON_ERROR);
                $cases['outbox_decode/'.$workload] = [
                    static fn (): ?Outbox => Outbox::decodePhp('entry.json', '/tmp/entry.json', $json, 1780000000),
                    static function () use ($json): Outbox {
                        $result = Outbox::decodeNative('entry.json', '/tmp/entry.json', $json, 1780000000);
                        if ($result === null) {
                            throw new RuntimeException('Outbox native benchmark declined.');
                        }

                        return $result;
                    },
                ];
            }
            foreach (['common' => [0.05, 0.5, 5., 50., 500.],
                'uncommon' => [-1., 0.1, 1., 10., 100., 1000., 10000., 10000.001, INF, NAN]] as $workload => $values) {
                $cases['diagnostic_phase/'.$workload] = [
                    static function () use ($values): array {
                        $diagnostics = new Diagnostics(1000);
                        foreach ($values as $i => $milliseconds) {
                            $diagnostics->recordPhasePhp('file_read', $milliseconds, (bool) ($i % 2));
                        }

                        return $diagnostics->reportAtPhp(1, 30, 1, PHP_INT_MAX, false, false, 2000);
                    },
                    static function () use ($values): array {
                        $diagnostics = new Diagnostics(1000);
                        foreach ($values as $i => $milliseconds) {
                            if (! $diagnostics->recordPhaseNative('file_read', $milliseconds, (bool) ($i % 2))) {
                                throw new RuntimeException('Diagnostic phase native benchmark declined.');
                            }
                        }

                        return $diagnostics->reportAtPhp(1, 30, 1, PHP_INT_MAX, false, false, 2000);
                    },
                ];
            }
            foreach (['common' => [12, 5000, 90000], 'uncommon' => [PHP_INT_MIN, -1, 0, 1, 10, 100, 1000, 4096, 65536, 1048576, 16777216, PHP_INT_MAX]] as $workload => $values) {
                $cases['diagnostic_size/'.$workload] = [
                    static function () use ($values): array {
                        $diagnostics = new Diagnostics(1000);
                        foreach ($values as $size) {
                            foreach (['compressed_bytes', 'json_bytes', 'files'] as $kind) {
                                $diagnostics->recordSizePhp($kind, $size);
                            }
                        }

                        return $diagnostics->reportAtPhp(1, 30, 1, PHP_INT_MAX, false, false, 2000);
                    },
                    static function () use ($values): array {
                        $diagnostics = new Diagnostics(1000);
                        foreach ($values as $size) {
                            foreach (['compressed_bytes', 'json_bytes', 'files'] as $kind) {
                                if (! $diagnostics->recordSizeNative($kind, $size)) {
                                    throw new RuntimeException('Diagnostic size native benchmark declined.');
                                }
                            }
                        }

                        return $diagnostics->reportAtPhp(1, 30, 1, PHP_INT_MAX, false, false, 2000);
                    },
                ];
            }
            foreach (['common' => [10, 3, PHP_INT_MAX, false, false], 'uncommon' => [0, 1, -1, true, true]] as $workload => [$limit, $inspected, $deadline, $exhausted, $error]) {
                $diagnostics = new Diagnostics(1000);
                $diagnostics->selected(2);
                $diagnostics->admitted();
                $diagnostics->admitted();
                $cases['diagnostic_projection/'.$workload] = [
                    static fn (): array => $diagnostics->reportAtPhp($limit, 30, $inspected, $deadline, $exhausted, $error, 2000),
                    static function () use ($diagnostics, $limit, $inspected, $deadline, $exhausted, $error): array {
                        $result = $diagnostics->reportAtNative($limit, 30, $inspected, $deadline, $exhausted, $error, 2000);
                        if ($result === null) {
                            throw new RuntimeException('Diagnostic projection native benchmark declined.');
                        }

                        return $result;
                    },
                ];
            }

            return $cases;
        })();

        return $cases;
    }
}
