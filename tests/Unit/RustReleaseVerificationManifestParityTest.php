<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\CollectionReleasePolicy;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PreparedManifest;
use App\Services\Releases\Verification\VerificationLedger;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Process\Process;
use Tests\Fixtures\YencArticles;
use Throwable;

final class RustReleaseVerificationManifestParityTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([PreparedManifest::class, ManifestStore::class, VerificationLedger::class, VerifiedArticle::class, CollectionReleasePolicy::class] as $class) {
            $class::setUseRustFfi(true);
        }
        parent::tearDown();
    }

    private function requireNative(): FFI
    {
        $ffi = PreparedManifest::nativeLibrary();
        if ($ffi === null) {
            self::markTestSkipped('Build and copy the release pipeline library before native parity tests.');
        }

        return $ffi;
    }

    /** @return array{value:mixed}|array{error:class-string<Throwable>,message:string} */
    private static function outcome(Closure $call): array
    {
        try {
            return ['value' => $call()];
        } catch (Throwable $error) {
            return ['error' => $error::class, 'message' => $error->getMessage()];
        }
    }

    public function test_prepared_identity_and_constructor_preserve_exact_bytes_and_validation(): void
    {
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1202));
        foreach ([0, 1, 55, 56, 63, 64, 65, 127, 128, 129, 512, 8192, 1048576] as $length) {
            $json = $length === 0 ? '' : $random->getBytes($length);
            self::assertSame(['sha256' => hash('sha256', $json), 'bytes' => $length], PreparedManifest::identityNative($json));
            self::assertSame(PreparedManifest::identityPhp($json), PreparedManifest::identity($json));
        }
        foreach ([[], ['files' => [], 'size' => PHP_INT_MAX], ['name' => "Café\0\xff", 'passwords' => ['secret']]] as $manifest) {
            $prepared = new PreparedManifest($manifest);
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            self::assertSame(hash('sha256', $json).'.json.gz', $prepared->name);
            self::assertSame(strlen($json), $prepared->jsonBytes);
            self::assertSame($json, gzdecode($prepared->compressed));
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid candidate password metadata.');
        new PreparedManifest(['passwords' => 17]);
    }

    public function test_manifest_filename_and_full_gzip_consumption_match_current_storage_contract(): void
    {
        $this->requireNative();
        $store = new ManifestStore;
        $prepared = new PreparedManifest(['files' => [['subject' => 'Film.mkv', 'bytes' => 123]]]);
        foreach ([$prepared->name, '', str_repeat('a', 64).'.manifest.gz', strtoupper($prepared->name), '../'.$prepared->name,
            $prepared->name."\n", $prepared->name."\0", str_repeat('a', 63).'.json.gz', str_repeat('g', 64).'.json.gz'] as $name) {
            self::assertSame(self::outcome(static function () use ($store, $name): bool {
                $store->assertNamePhp($name);

                return true;
            }), self::outcome(static fn (): ?bool => $store->assertNameNative($name)), bin2hex($name));
        }
        $json = gzdecode($prepared->compressed);
        self::assertIsString($json);
        $body = $prepared->compressed;
        // Optional original filename/comment fields do not change the decoded digest.
        $optionalHeader = substr($body, 0, 3).chr(8 | 16).substr($body, 4, 6)."source.json\0comment\0".substr($body, 10);
        $bodies = [$body, gzencode($json, 0), gzencode($json, 1), $optionalHeader, '', "\x1f\x8b", $json,
            substr($body, 0, -1), substr_replace($body, "\0\0\0\0", -8, 4), $body.'trailing', $body.gzencode(''), $body.gzencode($json)];
        foreach ($bodies as $compressed) {
            self::assertIsString($compressed);
            self::assertSame($store->validBodyPhp($prepared->name, $compressed), $store->validBodyNative($prepared->name, $compressed));
            foreach ([$json, $json.' ', ''] as $expectedJson) {
                self::assertSame($store->consumesExactlyPhp($compressed, $expectedJson), $store->consumesExactlyNative($compressed, $expectedJson));
            }
        }
        self::assertFalse($store->validBodyNative(str_repeat('0', 64).'.json.gz', $body));
        self::assertFalse($store->validBodyNative($prepared->name."\n", $body));
        // validBody validates storage integrity, not the JSON schema.
        $notJson = 'not JSON';
        self::assertTrue($store->validBodyNative(hash('sha256', $notJson).'.json.gz', gzencode($notJson)));
    }

    public function test_publication_keys_and_all_stripes_preserve_duplicate_lock_coverage(): void
    {
        $this->requireNative();
        $cases = [[0, null, '', '', 0], [10, 'ABC123', 'Film.Title', 'Film Title', 7],
            [PHP_INT_MAX, "AB\0CD\nEF\xff", 'Été Straße 東京', 'Ete.Strasse.東京', PHP_INT_MAX],
            [-1, '', '!!!', '???', -1], [1, '0', '0', '0', 1], [1, null, "a\0b", 'AB', 0]];
        foreach ($cases as $args) {
            self::assertSame(VerificationLedger::publicationKeysPhp(...$args), VerificationLedger::publicationKeysNative(...$args));
        }
        self::assertSame(['fingerprint:abc123', 'predb:7', 'name:filmtitle', 'name:filmtitle', 'size:10'], VerificationLedger::publicationKeys(10, 'ABC123', 'Film.Title', 'Film Title', 7));
        self::assertSame(1024, VerificationLedger::PUBLICATION_STRIPES);
        $random = new Randomizer(new Mt19937(1203));
        foreach (['', '0', "\0\xff", str_repeat('long key', 1024)] as $key) {
            self::assertSame('publication:'.(crc32($key) % 1024), VerificationLedger::publicationStripeNative($key));
        }
        for ($i = 0; $i < 4096; $i++) {
            $key = $random->getBytes($random->getInt(1, 300));
            self::assertSame(VerificationLedger::publicationStripePhp($key), VerificationLedger::publicationStripeNative($key));
        }
    }

    public function test_article_decode_preserves_filename_dimensions_range_crc_and_error_order(): void
    {
        $this->requireNative();
        $single = YencArticles::article('hello');
        $part = YencArticles::part('tail', 'movie.mkv', 8, 2, 5, 2);
        $frames = ['', 'invalid', $single, $part, YencArticles::article(YencArticles::bytes()),
            str_replace('crc32=', 'missing=', $single), str_replace('pcrc32=', 'missing=', $part),
            str_replace('total=2', 'total=1', $part), str_replace('total=2', 'total=0', $part),
            str_replace('begin=5', 'begin=0', $part), str_replace('end=8', 'end=9', $part),
            str_replace('end=8', 'end=7', $part), str_replace('size=8', 'size=0', $part),
            str_replace('part=2', 'part=0', $part), str_replace('part=2', 'part=9999999999999999999', $part),
            str_replace('pcrc32='.hash('crc32b', 'tail'), 'pcrc32=00000000', $part),
            str_replace('size=5 name=', 'size=0 size=5 name=', $single),
            str_replace('size=5 name=', 'size=5 size=0 name=', $single),
            str_replace('=yend size=5', '=yend size=4', $single)];
        foreach (['', '.', '-unsafe', '../x', 'x/y', 'x\\y', "a\0b", "a\x01b", "a\x7fb", "bad\xff", '映画.mkv', str_repeat('a', 200), str_repeat('a', 201), str_repeat('a', 512), str_repeat('a', 513)] as $name) {
            $frames[] = str_replace('fixture file.bin', $name, $single);
        }
        foreach (['0', '-1', '+1', '', '1.0', '1e1', '000000000000000001', '0000000000000000001', '9999999999999999999'] as $number) {
            $frames[] = str_replace('size=5 name=', 'size='.$number.' name=', $single);
        }
        foreach ($frames as $raw) {
            $php = self::outcome(static fn (): array => (array) VerifiedArticle::decodePhp($raw));
            $rust = self::outcome(static fn (): array => (array) VerifiedArticle::decodeNative($raw));
            self::assertSame($php, $rust, bin2hex(substr($raw, 0, 180)));
            self::assertSame($php, self::outcome(static fn (): array => (array) VerifiedArticle::decode($raw)));
        }
        $article = VerifiedArticle::decodeNative($part);
        self::assertNotNull($article);
        self::assertSame([8, 4, 2, 2, 'tail'], [$article->size, $article->offset, $article->part, $article->totalParts, $article->bytes]);
    }

    public function test_collection_inspection_preserves_actual_policy_including_obfuscated_subjects(): void
    {
        $this->requireNative();
        $names = ['', 'file.par2', 'FILE.VOL001+02.PAR2', 'file.par2.exe', "file.par2\n", "file.par2\0", 'movie.mkv', 'README', '映画.par2'];
        $subjects = ['"file.par2" yEnc', "'file.PAR2' yEnc", 'file.par2', "file.par2\n", 'file.par2.exe', 'randomToken123',
            '"movie.mkv" "file.par2" yEnc', 'movie.rar', 'movie.r123', 'movie.s001', 'movie.001', 'movie.7z', 'movie.m4b', 'book.epub',
            "file.par2\x85", "file.par2\xa0", '映画.par2" yEnc', "file.par2\0"];
        foreach (array_merge([[]], array_map(static fn (string $v): array => [$v], $names), [$names, array_fill(0, 1000, 'file.par2')]) as $inventory) {
            self::assertSame(CollectionReleasePolicy::onlyPar2Php($inventory), CollectionReleasePolicy::onlyPar2Native($inventory));
        }
        foreach (array_merge([[]], array_map(static fn (string $v): array => [$v], $subjects), [$subjects, array_fill(0, 1000, '"file.par2" yEnc')]) as $inventory) {
            self::assertSame(CollectionReleasePolicy::subjectsHavePayloadPhp($inventory), CollectionReleasePolicy::subjectsHavePayloadNative($inventory) ?? CollectionReleasePolicy::subjectsHavePayloadPhp($inventory));
        }
        // Existing policy deliberately admits an obfuscated non-PAR2 subject here;
        // HeaderFilenameIdentity applies its separate sampled-body proof elsewhere.
        self::assertTrue(CollectionReleasePolicy::subjectsHavePayload(['randomToken123']));
    }

    public function test_native_capacity_null_and_malformed_wire_contracts(): void
    {
        $ffi = $this->requireNative();
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        self::assertNotNull($buffer);
        // @phpstan-ignore method.notFound
        self::assertSame(-64, $ffi->nntmux_verification_manifest_identity('abc', 3, $buffer, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-64, $ffi->nntmux_verification_manifest_identity('abc', 3, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_verification_manifest_identity(null, 3, $buffer, $capacity));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_verification_collection_inspect("\x08\0\0\0abc", 7, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_verification_publication_keys(1, 1, null, 1, '', 0, '', 0, 0, 0, $buffer, $capacity));
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_verification_publication_keys(1, 1, 'ABC', 3, 'x', 1, 'y', 1, 1, 1, $buffer, 1);
        self::assertLessThan(-1, $needed);
        // @phpstan-ignore method.notFound
        self::assertSame(-$needed, $ffi->nntmux_verification_publication_keys(1, 1, 'ABC', 3, 'x', 1, 'y', 1, 1, 1, $buffer, $capacity));
    }

    public function test_disabled_and_unavailable_candidates_leave_php_production_paths_usable(): void
    {
        foreach ([PreparedManifest::class, ManifestStore::class, VerificationLedger::class, VerifiedArticle::class, CollectionReleasePolicy::class] as $class) {
            $class::setUseRustFfi(false);
        }
        $store = new ManifestStore;
        $prepared = new PreparedManifest(['files' => []]);
        self::assertNull(PreparedManifest::identityNative('{}'));
        self::assertNull($store->validBodyNative($prepared->name, $prepared->compressed));
        self::assertNull($store->consumesExactlyNative($prepared->compressed, '{"files":[]}'));
        self::assertNull($store->assertNameNative($prepared->name));
        self::assertNull(VerificationLedger::publicationKeysNative(1, null, 'name', '', 0));
        self::assertNull(VerificationLedger::publicationStripeNative('name'));
        self::assertNull(VerifiedArticle::decodeNative(YencArticles::article('hi')));
        self::assertNull(CollectionReleasePolicy::onlyPar2Native(['file.par2']));
        self::assertNull(CollectionReleasePolicy::subjectsHavePayloadNative(['file.mkv']));
        self::assertTrue($store->validBody($prepared->name, $prepared->compressed));
        self::assertSame('hi', VerifiedArticle::decode(YencArticles::article('hi'))->bytes);
        self::assertNull(RustPipelineLibrary::resolveDomain('nfo', 'void nonexistent_wave12_symbol(void);', '/nonexistent/wave12-library'));
    }

    public function test_missing_symbol_library_declines_every_candidate_without_affecting_callers(): void
    {
        $libraries = new \ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $previous = $libraries->getValue();
        $cdef = (new \ReflectionClass(PreparedManifest::class))->getConstant('CDEF');
        self::assertIsString($cdef);
        self::assertIsArray($previous);
        try {
            // resolveDomain caches a missing or older symbol set as false.
            $libraries->setValue(null, ['nfo|default|'.hash('xxh3', $cdef) => false] + $previous);
            $store = new ManifestStore;
            $prepared = new PreparedManifest(['files' => []]);
            self::assertNull(PreparedManifest::identityNative('{}'));
            self::assertNull($store->validBodyNative($prepared->name, $prepared->compressed));
            self::assertNull($store->consumesExactlyNative($prepared->compressed, '{"files":[]}'));
            self::assertNull($store->assertNameNative($prepared->name));
            self::assertNull(VerificationLedger::publicationKeysNative(1, null, 'name', '', 0));
            self::assertNull(VerificationLedger::publicationStripeNative('name'));
            self::assertNull(VerifiedArticle::decodeNative(YencArticles::article('hi')));
            self::assertNull(CollectionReleasePolicy::onlyPar2Native(['file.par2']));
            self::assertNull(CollectionReleasePolicy::subjectsHavePayloadNative(['file.mkv']));
            self::assertTrue($store->validBody($prepared->name, $prepared->compressed));
            self::assertSame('hi', VerifiedArticle::decode(YencArticles::article('hi'))->bytes);
        } finally {
            $libraries->setValue(null, $previous);
        }
    }

    /**
     * Opt-in, 10,000 randomized pairs per cell, one full caller per observation,
     * 400 warmups/path. Raw inputs/order/timings and source/runtime hashes are retained.
     * PHP zlib, transliteration, packing, shared scratch and FFI costs are included.
     *
     * Admission decision: all nine production helpers retain PHP. Each missed a
     * required 1.5x mean or p99 gate on this Darwin experiment. In particular,
     * accelerated SHA-256 on larger JSON does not offset tiny manifests, and
     * faster CRC32 on long keys does not offset ordinary publication keys.
     * The native entry points and toggles remain available for parity testing.
     * The benchmark emits the full per-cell decision and raw artifact path.
     */
    #[Group('benchmark')]
    public function test_paired_randomized_single_call_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            self::markTestSkipped('Run this file with --group=benchmark to reproduce the admission experiment.');
        }
        $this->requireNative();
        $store = new ManifestStore;
        $callers = [
            'identity' => [PreparedManifest::identityPhp(...), PreparedManifest::identityNative(...)],
            'validBody' => [$store->validBodyPhp(...), $store->validBodyNative(...)],
            'consumesExactly' => [$store->consumesExactlyPhp(...), $store->consumesExactlyNative(...)],
            'assertName' => [static function (string $name) use ($store): bool {
                $store->assertNamePhp($name);

                return true;
            }, $store->assertNameNative(...)],
            'publicationKeys' => [VerificationLedger::publicationKeysPhp(...), VerificationLedger::publicationKeysNative(...)],
            'publicationStripe' => [VerificationLedger::publicationStripePhp(...), VerificationLedger::publicationStripeNative(...)],
            'decode' => [static fn (string $raw): array => (array) VerifiedArticle::decodePhp($raw), static fn (string $raw): array => (array) VerifiedArticle::decodeNative($raw)],
            'onlyPar2' => [CollectionReleasePolicy::onlyPar2Php(...), CollectionReleasePolicy::onlyPar2Native(...)],
            'subjectsHavePayload' => [CollectionReleasePolicy::subjectsHavePayloadPhp(...), static fn (array $subjects): bool => CollectionReleasePolicy::subjectsHavePayloadNative($subjects) ?? CollectionReleasePolicy::subjectsHavePayloadPhp($subjects)],
        ];
        $fixtures = [];
        for ($i = 0; $i < 16; $i++) {
            foreach (['common' => 12, 'uncommon' => 256] as $cell => $count) {
                $files = [];
                for ($j = 0; $j < $count; $j++) {
                    $files[] = ['subject' => 'Film.Title.2026.1080p.part'.($j + $i).'.rar', 'segments' => [['number' => $j + 1, 'bytes' => 750000, 'messageid' => 'article-'.$i.'-'.$j.'@news.example']]];
                }
                $json = json_encode(['groups' => ['alt.binaries.test'], 'files' => $files], JSON_THROW_ON_ERROR);
                $body = gzencode($json);
                self::assertIsString($body);
                $name = hash('sha256', $json).'.json.gz';
                $fixtures['identity/'.$cell][] = [$json];
                $fixtures['validBody/'.$cell][] = [$name, $body];
                $fixtures['consumesExactly/'.$cell][] = [$body, $json];
                $fixtures['assertName/'.$cell][] = [$cell === 'common' ? $name : [$name."\0", strtoupper($name), '../'.$name, $name."\n"][$i % 4]];
                $title = $cell === 'common' ? 'Film.Title.'.(2000 + $i).'.1080p' : str_repeat('Été Straße 東京 - ', 15).$i;
                $fixtures['publicationKeys/'.$cell][] = [750000 * $count, $i % 3 === 0 ? null : hash('sha256', $title), $title, $title, $i];
                $fixtures['publicationStripe/'.$cell][] = [$cell === 'common' ? 'name:filmtitle'.$i : "fingerprint:\0".str_repeat($title, 8)];
                $fixtures['decode/'.$cell][] = [$cell === 'common' ? YencArticles::article(YencArticles::randomBytes(2048 + $i)) : YencArticles::part(YencArticles::randomBytes(8192 + $i), '映画.mkv', 16384, 2, 8193 - $i, 2)];
                $inventory = array_map(static fn (int $j): string => 'Film.vol'.($j + $i).'+02.PAR2', range(0, $count - 1));
                $subjects = array_map(static fn (string $n): string => '"'.$n.'" yEnc', $inventory);
                if ($i % 2 === 0) {
                    $inventory[$count - 1] = 'Film.mkv';
                    $subjects[$count - 1] = '"Film.mkv" yEnc';
                }
                $fixtures['onlyPar2/'.$cell][] = [$inventory];
                $fixtures['subjectsHavePayload/'.$cell][] = [$subjects];
            }
            // Corrupt/trailing streams and non-ASCII PCRE fallbacks are distinct cells.
            $fixtures['validBody/uncommon_invalid'][] = [$name, $body.'trailing'];
            $fixtures['consumesExactly/uncommon_invalid'][] = [$body.gzencode(''), $json];
            $fixtures['subjectsHavePayload/uncommon_unicode'][] = [['"映画.par2" yEnc', "parity.par2\x85"]];
            $small = ['{}', '[]', '{"files":[]}'][$i % 3];
            $smallBody = gzencode($small);
            self::assertIsString($smallBody);
            $fixtures['identity/uncommon_small'][] = [$small];
            $fixtures['validBody/uncommon_small'][] = [hash('sha256', $small).'.json.gz', $smallBody];
            $fixtures['consumesExactly/uncommon_small'][] = [$smallBody, $small];
            $fixtures['decode/uncommon_rejected'][] = [str_replace('fixture file.bin', '../unsafe', YencArticles::article('hello'))];
        }
        $random = new Randomizer(new Mt19937(12022026));
        $summary = $samples = $firstCalls = [];
        $gates = array_fill_keys(array_keys($callers), true);
        foreach ($fixtures as $cell => $inputs) {
            [$method] = explode('/', $cell);
            [$php, $rust] = $callers[$method];
            // Time invalid calls with identical exception handling on both sides.
            if ($method === 'assertName' || $cell === 'decode/uncommon_rejected') {
                $php = static fn (...$args): array => self::outcome(static fn (): mixed => $callers[$method][0](...$args));
                $rust = static fn (...$args): array => self::outcome(static fn (): mixed => $callers[$method][1](...$args));
            }
            foreach ($inputs as $args) {
                self::assertSame($php(...$args), $rust(...$args), $cell.' correctness');
            }
            foreach ([$php, $rust] as $path => $call) {
                $started = hrtime(true);
                $call(...$inputs[0]);
                $firstCalls[$cell][$path] = hrtime(true) - $started;
                for ($i = 0; $i < 400; $i++) {
                    $call(...$inputs[$i % 16]);
                }
            }
            $raw = ['php' => [], 'rust' => [], 'order' => [], 'input' => []];
            for ($pair = 0; $pair < 10000; $pair++) {
                $index = $random->getInt(0, 15);
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                $raw['input'][] = $index;
                $raw['order'][] = $order[0];
                foreach ($order as $path) {
                    $call = $path === 'php' ? $php : $rust;
                    $start = hrtime(true);
                    $call(...$inputs[$index]);
                    $raw[$path][] = hrtime(true) - $start;
                }
            }
            $stats = [];
            foreach (['php', 'rust'] as $path) {
                $sorted = $raw[$path];
                sort($sorted, SORT_NUMERIC);
                $stats[$path] = ['mean_ns' => array_sum($sorted) / 10000, 'p99_ns' => $sorted[9899], 'p99_rank_95_percent_interval_ns' => [$sorted[9880], $sorted[9919]]];
            }
            $mean = $stats['php']['mean_ns'] / $stats['rust']['mean_ns'];
            $p99 = $stats['php']['p99_ns'] / $stats['rust']['p99_ns'];
            $summary[$cell] = $stats + ['mean_speedup' => $mean, 'p99_speedup' => $p99];
            $gates[$method] = $gates[$method] && $mean >= 1.5 && $p99 >= 1.5;
            $samples[$cell] = $raw;
        }
        $hashes = [];
        foreach (['app/Services/Releases/Verification/PreparedManifest.php', 'app/Services/Releases/Verification/ManifestStore.php', 'app/Services/Releases/Verification/VerificationLedger.php', 'app/Services/Releases/Verification/VerifiedArticle.php', 'app/Services/Releases/Verification/CollectionReleasePolicy.php', 'tests/Unit/RustReleaseVerificationManifestParityTest.php', 'rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.json', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $file) {
            $hashes[$file] = hash_file('sha256', __DIR__.'/../../'.$file);
        }
        $tools = new Process(['sh', '-c', 'git rev-parse HEAD; rustc -Vv; cargo -V; uname -a; ulimit -a; sysctl -n machdep.cpu.brand_string hw.memsize 2>/dev/null || true'], __DIR__.'/../..');
        $tools->run();
        $artifact = sys_get_temp_dir().'/nntmux-wave12-worker2-'.getmypid().'.json';
        file_put_contents($artifact, json_encode([
            'protocol' => ['baseline_commit' => 'c97b6e0e6', 'seed' => 12022026, 'pairs_per_cell' => 10000, 'warmup_calls_per_path' => 400, 'calls_per_observation' => 1, 'clock' => 'hrtime(true)', 'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml', 'profile' => 'opt-level=3 lto=true codegen-units=1', 'reproduce' => 'php artisan test --compact tests/Unit/RustReleaseVerificationManifestParityTest.php --group=benchmark', 'failures_or_timeouts' => []],
            'runtime' => ['php' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'zlib' => ZLIB_VERSION, 'ctype' => setlocale(LC_CTYPE, '0'), 'pcre_jit' => ini_get('pcre.jit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'memory_limit' => ini_get('memory_limit'), 'hardware_and_tools' => $tools->getOutput(), 'resource_limits' => 'host scheduler; no explicit CPU quota', 'files_sha256' => $hashes],
            'first_warm_observation_ns_not_startup_admission' => $firstCalls, 'fixtures_base64' => base64_encode(serialize($fixtures)), 'summary' => $summary, 'admitted_methods' => $gates, 'raw_samples' => $samples,
        ], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nWave 12 Worker 2 benchmark artifact: ".$artifact."\n");
        foreach ($summary as $cell => $stats) {
            fwrite(STDERR, sprintf("%s: mean %.2fx, p99 %.2fx\n", $cell, $stats['mean_speedup'], $stats['p99_speedup']));
        }
        fwrite(STDERR, json_encode($gates, JSON_THROW_ON_ERROR)."\n");
        self::assertCount(count($fixtures), $summary);
    }
}
