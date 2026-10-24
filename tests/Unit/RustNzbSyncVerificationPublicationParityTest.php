<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\ReleaseVerificationPublicationBatchController;
use App\Http\Controllers\ReleaseVerificationPublicationController;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbSourceInstallation;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Services\Releases\Verification\VerificationResetService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNzbSyncVerificationPublicationParityTest extends TestCase
{
    private const array CLASSES = [DirectorySyncBridge::class, NzbSourceInstallation::class, VerificationResetService::class,
        ReleaseVerificationPublicationBatchController::class, ReleaseVerificationPublicationController::class];

    private const int SEED = 32022026;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        DirectorySyncBridge::$trace = false;
        DirectorySyncBridge::$events = [];
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        parent::tearDown();
    }

    public function test_defaults_nullable_toggles_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $this->assertSame(DirectorySyncBridge::FFI_CDEF, $class::FFI_CDEF);
        }
        foreach (self::CLASSES as $selected) {
            $selected::setUseRustFfi(true);
            foreach (self::CLASSES as $class) {
                $this->assertSame($selected === $class, $class::usesRustFfi());
            }
            $selected::setUseRustFfi(false);
            $this->assertFalse($selected::usesRustFfi());
            $selected::setUseRustFfi(null);
            $this->assertFalse($selected::usesRustFfi());
        }
    }

    public function test_php_helpers_preserve_existing_contracts(): void
    {
        $unsupported = ['ok' => false, 'failure' => 'unsupported'];
        $this->assertSame(['ok' => true], DirectorySyncBridge::normalizeBridgeProbeResultPhp('fsync', true, true));
        $this->assertSame(['ok' => false], DirectorySyncBridge::normalizeBridgeProbeResultPhp('fsync', true, false));
        $this->assertSame(['ok' => false], DirectorySyncBridge::normalizeBridgeProbeResultPhp('fsync', true, null));
        $this->assertSame($unsupported, DirectorySyncBridge::normalizeBridgeProbeResultPhp('fsync', false, true));
        $this->assertSame($unsupported, DirectorySyncBridge::normalizeBridgeProbeResultPhp(null, true, true));
        $this->assertSame($unsupported, DirectorySyncBridge::normalizeBridgeProbeResultPhp('', true, true));

        $this->assertSame(['source' => '/n/a/g.nzb.gz', 'destination' => '/n/a/.g.nzb.gz.1', 'staged' => true, 'sibling' => true],
            NzbSourceInstallation::normalizeSourcePathPairPhp('/n/a/g.nzb.gz', '/n/a/.g.nzb.gz.1'));
        $this->assertSame(['source' => '/n/a/x', 'destination' => '/n/b/x', 'staged' => true, 'sibling' => false],
            NzbSourceInstallation::normalizeSourcePathPairPhp('/n/a/x', '/n/b/x'));
        $this->assertSame(['source' => 'x', 'destination' => 'x', 'staged' => false, 'sibling' => true],
            NzbSourceInstallation::normalizeSourcePathPairPhp('x', 'x'));
        $this->assertFalse(NzbSourceInstallation::normalizeSourcePathPairPhp('/a', 'a')['sibling']);
        $this->assertFalse(NzbSourceInstallation::normalizeSourcePathPairPhp('', '')['sibling']);

        $this->assertSame(['host' => 'w1', 'candidate_id' => 7, 'version' => 3, 'audit_release_id' => null, 'terminal' => true, 'delete_guid' => 'g'],
            VerificationResetService::buildTerminalScratchRecordPhp(7, 3, 'w1', null, 'g'));
        $this->assertSame(['host' => '', 'candidate_id' => null, 'version' => 0, 'audit_release_id' => 9, 'terminal' => true, 'delete_guid' => null],
            VerificationResetService::buildTerminalScratchRecordPhp(7, -3, '', 9, ''));
        foreach ([['/s', '/s/a', true], ['/s/', '/s/a', true], ['/s//', '/s//a', true], ['/s/.', '/s/./a', true], ['/', '/a', true],
            ['s', 's/a/b', true], ['/s', '/s/...', true], ['/s', '/s', false], ['/s', '/s/', false], ['/s', '/sa', false],
            ['/s', '/s/..', false], ['/s', '/s/a/../b', false], ['/s', '/s/./a', false], ['', '/a', false], ['/s', "/s/a\0", false]] as [$root, $path, $safe]) {
            $this->assertSame($safe, VerificationResetService::isSafeSubpathPhp($root, $path), $root.' '.$path);
        }

        $this->assertSame(['status' => 200, 'error' => null, 'max_items' => 64, 'paused' => true],
            ReleaseVerificationPublicationBatchController::classifyBatchResponseStatusPhp(true, 64));
        $this->assertSame(['status' => 422, 'error' => 'items must be a non-empty list.', 'max_items' => 16, 'paused' => false],
            ReleaseVerificationPublicationBatchController::classifyBatchResponseStatusPhp(true, 0, 16));
        $this->assertSame(['status' => 422, 'error' => 'A batch holds at most 16 items.', 'max_items' => 16, 'paused' => false],
            ReleaseVerificationPublicationBatchController::classifyBatchResponseStatusPhp(false, 17, 16));
        $this->assertSame(ReleaseVerificationPublicationBatchController::classifyBatchResponseStatusPhp(false, 65, 64),
            ReleaseVerificationPublicationBatchController::classifyBatchResponseStatus(false, 65));
    }

    public function test_single_classification_matches_the_previous_controller_match(): void
    {
        $previous = static fn (PublicationOutcome $outcome) => match ($outcome->status) {
            PublicationOutcome::PUBLISHED => response()->json(['release_id' => $outcome->releaseId]),
            PublicationOutcome::DEFERRED => response()->json(['deferred' => $outcome->error], 409),
            PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW, PublicationOutcome::UNRESOLVED => response()->json(['release_id' => null]),
            default => response()->json(['error' => $outcome->error], 409),
        };
        $statuses = [PublicationOutcome::PUBLISHED, PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW, PublicationOutcome::DEFERRED,
            PublicationOutcome::STALE, PublicationOutcome::PAUSED, PublicationOutcome::RETRY, PublicationOutcome::INVALID, PublicationOutcome::ERROR,
            PublicationOutcome::UNRESOLVED, PublicationOutcome::TRANSPORT, PublicationOutcome::NO_RELEASE, '', 'PUBLISHED'];
        foreach ([false, true] as $rust) {
            ReleaseVerificationPublicationController::setUseRustFfi($rust);
            foreach ($statuses as $status) {
                foreach ([[null, null], [42, 'Claim moved.'], [PHP_INT_MAX, "é\0\"\\"]] as [$releaseId, $error]) {
                    $outcome = new PublicationOutcome($status, $releaseId, $error);
                    $expected = $previous($outcome);
                    $classified = ReleaseVerificationPublicationController::classifySinglePublicationResponse($outcome->releaseId, $outcome->status, $outcome->error);
                    $actual = response()->json($classified['body'], $classified['status']);
                    $this->assertSame($expected->getStatusCode(), $actual->getStatusCode(), $status);
                    $this->assertSame($expected->getContent(), $actual->getContent(), $status);
                }
            }
        }
    }

    public function test_batch_controller_rejections_are_identical_with_rust_forced(): void
    {
        $this->nativeFfi();
        config(['release_verification.publish_batch_max_items' => 3]);
        $service = (new ReflectionClass(RemotePublicationService::class))->newInstanceWithoutConstructor();
        $bodies = [['protocol' => 1], ['protocol' => 1, 'items' => []], ['protocol' => 1, 'items' => 'x'], ['protocol' => 1, 'items' => ['a' => 1]],
            ['protocol' => 1, 'items' => [1, 2, 3, 4]], ['protocol' => 2, 'items' => [1]]];
        foreach ($bodies as $body) {
            $responses = [];
            foreach ([false, true] as $rust) {
                ReleaseVerificationPublicationBatchController::setUseRustFfi($rust);
                $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
                $response = (new ReleaseVerificationPublicationBatchController)($request, $service);
                $responses[] = [$response->getStatusCode(), $response->getContent()];
            }
            $this->assertSame($responses[0], $responses[1], json_encode($body, JSON_THROW_ON_ERROR));
            $this->assertSame(422, $responses[1][0]);
            $expected = match (true) {
                $body['protocol'] !== 1 => '{"error":"Unsupported batch protocol.","supported_protocol":1}',
                isset($body['items']) && $body['items'] === [1, 2, 3, 4] => '{"error":"A batch holds at most 3 items.","max_items":3}',
                default => '{"error":"items must be a non-empty list.","max_items":3}',
            };
            $this->assertSame($expected, $responses[1][1]);
        }
    }

    public function test_native_helpers_and_public_dispatch_have_exact_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $label = $class.'::'.$method.' '.json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE);
                $expected = $class::$php(...$args);
                // Check the raw bridge first: fallback must not conceal a failed native call.
                $this->assertTrue(DirectorySyncBridge::transformNative($op, $args, $raw), $label.' raw native declined');
                $this->assertSame($expected, $raw, $label.' raw native');
                $this->assertSame($expected, $class::$native(...$args), $label.' native');
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $label.' public dispatch');
                }
                $class::setUseRustFfi(null);
            }
        }
    }

    public function test_declined_inputs_fall_back_to_identical_php_results(): void
    {
        $this->nativeFfi();
        $declined = [
            [DirectorySyncBridge::class, 'normalizeBridgeProbeResult', ["fsync\xff", true, true], 0],
            [NzbSourceInstallation::class, 'normalizeSourcePathPair', ["/n/\xff", '/n/x'], 1],
            [NzbSourceInstallation::class, 'normalizeSourcePathPair', ['/n/x', "\xc3"], 1],
            [VerificationResetService::class, 'buildTerminalScratchRecord', [1, 1, "\xfe", null, null], 2],
            [VerificationResetService::class, 'buildTerminalScratchRecord', [1, 1, 'h', null, "\x80"], 2],
            [VerificationResetService::class, 'isSafeSubpath', ["/s\xff", "/s\xff/a"], 3],
            [ReleaseVerificationPublicationController::class, 'classifySinglePublicationResponse', [null, 'deferred', "held \xff"], 5],
        ];
        foreach ($declined as [$class, $method, $args, $op]) {
            $this->assertFalse(DirectorySyncBridge::transformNative($op, $args), $method.' must decline '.bin2hex(serialize($args)));
            $php = $method.'Php';
            $native = $method.'Native';
            $this->assertSame($class::$php(...$args), $class::$native(...$args), $method.' fallback');
            $class::setUseRustFfi(true);
            $this->assertSame($class::$php(...$args), $class::$method(...$args), $method.' dispatch fallback');
        }
        $this->assertTrue(VerificationResetService::isSafeSubpathNative("/s\xff", "/s\xff/a"));
        $this->assertFalse(DirectorySyncBridge::transformNative(999, []));
        $this->assertFalse(DirectorySyncBridge::transformNative(3, ['/s']));
        $this->assertFalse(DirectorySyncBridge::transformNative(3, ['/s', '/s/a', '/s/b']));
        $this->assertFalse(DirectorySyncBridge::transformNative(3, ['/s', 1]));
        $this->assertFalse(DirectorySyncBridge::transformNative(4, [0, 1, 64]));
        $this->assertFalse(DirectorySyncBridge::transformNative(5, ['5', 'published', null]));
        $this->assertFalse(DirectorySyncBridge::transformNative(1, ['x' => 'a', 'y' => 'b']));
        $this->assertFalse(DirectorySyncBridge::transformNative(1, [new \stdClass, 'b']));
        $this->assertFalse(DirectorySyncBridge::transformNative(1, [['a'], 'b']));
        $this->assertFalse(DirectorySyncBridge::transformNative(4, [false, 1.0, 64]));
    }

    public function test_seeded_random_inputs_match_php(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        $alphabet = ['/', '/', '/', '.', '..', 'a', 'b', 'é', "\0", ' ', '\\', '"', 'nzb', '-'];
        $ints = [0, 1, -1, 16, 64, 65, PHP_INT_MAX, PHP_INT_MIN];
        $kinds = [null, '', PublicationOutcome::PUBLISHED, PublicationOutcome::DEFERRED, PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW,
            PublicationOutcome::UNRESOLVED, PublicationOutcome::STALE, 'Published'];
        $path = static function () use ($random, $alphabet): string {
            $value = '';
            for ($i = $random->getInt(0, 10); $i > 0; $i--) {
                $value .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $value;
        };
        for ($sample = 0; $sample < 2000; $sample++) {
            $root = $path();
            $candidate = $random->getInt(0, 1) === 1 ? $root.$path() : $path();
            $label = bin2hex($root).' '.bin2hex($candidate);
            $this->assertSame(VerificationResetService::isSafeSubpathPhp($root, $candidate), VerificationResetService::isSafeSubpathNative($root, $candidate), 'subpath '.$label);
            $this->assertTrue(DirectorySyncBridge::transformNative(3, [$root, $candidate], $raw), 'subpath raw '.$label);
            $this->assertSame(VerificationResetService::isSafeSubpathPhp($root, $candidate), $raw, 'subpath raw '.$label);
            $this->assertTrue(DirectorySyncBridge::transformNative(1, [$root, $candidate], $raw), 'pair raw '.$label);
            $this->assertSame(NzbSourceInstallation::normalizeSourcePathPairPhp($root, $candidate), $raw, 'pair raw '.$label);
            $count = $ints[$random->getInt(0, count($ints) - 1)];
            $max = $ints[$random->getInt(0, count($ints) - 1)];
            $paused = $random->getInt(0, 1) === 1;
            $this->assertTrue(DirectorySyncBridge::transformNative(4, [$paused, $count, $max], $raw));
            $this->assertSame(ReleaseVerificationPublicationBatchController::classifyBatchResponseStatusPhp($paused, $count, $max), $raw, "batch $count $max");
            $release = $random->getInt(0, 2) === 0 ? null : $ints[$random->getInt(0, count($ints) - 1)];
            $kind = $kinds[$random->getInt(0, count($kinds) - 1)];
            $message = $random->getInt(0, 2) === 0 ? null : $path();
            $this->assertTrue(DirectorySyncBridge::transformNative(5, [$release, $kind, $message], $raw));
            $this->assertSame(ReleaseVerificationPublicationController::classifySinglePublicationResponsePhp($release, $kind, $message), $raw, 'single '.$label);
            $audit = $random->getInt(0, 1) === 1 ? null : $count;
            $guid = $random->getInt(0, 2) === 0 ? null : $path();
            $this->assertTrue(DirectorySyncBridge::transformNative(2, [$max, $count, $root, $audit, $guid], $raw));
            $this->assertSame(VerificationResetService::buildTerminalScratchRecordPhp($max, $count, $root, $audit, $guid), $raw, 'record '.$label);
        }
    }

    public function test_abi_capacity_malformed_inputs_and_bad_ranges_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            foreach ([...$common, ...$uncommon] as $args) {
                $input = json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $buffer = $ffi->new('char[65536]');
                FFI::memset($buffer, 0x5A, 65536);
                // @phpstan-ignore method.notFound
                $length = $ffi->nntmux_w32_nzb_sync_verification_publication($op, $input, strlen($input), $buffer, 65536);
                $this->assertGreaterThan(0, $length);
                $wire = FFI::string($buffer, $length);
                $this->assertSame([$class::$php(...$args)], json_decode($wire, true, 64, JSON_THROW_ON_ERROR));
                $small = $ffi->new('char['.($length + 2).']');
                foreach ([0, 1, $length - 1, $length, $length + 1] as $capacity) {
                    FFI::memset($small, 0x5A, $length + 2);
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_w32_nzb_sync_verification_publication($op, $input, strlen($input), $small, $capacity);
                    $this->assertSame($capacity < $length ? -$length : $length, $written, $method.' capacity');
                    $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2));
                }
            }
        }
        $buffer = $ffi->new('char[1024]');
        foreach (['["/s","/s/a"] x', '["/s","/s/a"', '["\ud800","/s"]', '["\x","/s"]', "[\"/s\x01\",\"/s/a\"]", '{"0":"/s","1":"/s/a"}', '["/s"]', '["/s",1]',
            "[\"\xff\",\"/s\"]", '[01,"/s"]', str_repeat('[', 40).str_repeat(']', 40)] as $bad) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w32_nzb_sync_verification_publication(3, $bad, strlen($bad), $buffer, 1024), bin2hex($bad));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
        $input = '["/s","/s/a"]';
        foreach ([[null, 0, $buffer, 1024], [null, 1, $buffer, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 1], [$input, 16777217, $buffer, 1024],
            [$input, strlen($input), $buffer, 16777217], [$buffer, 20, $buffer, 1024], [FFI::addr($buffer[1]), 20, $buffer, 1024], [$buffer, 20, FFI::addr($buffer[1]), 1023]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w32_nzb_sync_verification_publication(3, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w32_nzb_sync_verification_publication(6, $input, strlen($input), $buffer, 1024));
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Services\Nzb\DirectorySyncBridge::transformNative(3, ['/s', '/s/a'])) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($vectors as [$class, $method, $common, $uncommon]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $class::setUseRustFfi(true);
                foreach ([...$common, ...$uncommon] as $args) {
                    $expected = serialize($class::$php(...$args));
                    if ($expected !== serialize($class::$native(...$args)) || $expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $missing = '/tmp/w32-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_wired_callers_behave_identically_with_rust_forced(): void
    {
        $this->nativeFfi();
        $directory = sys_get_temp_dir().'/w32-2-sync-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $this->cleanup[] = $directory;
        file_put_contents($directory.'/file', 'x');
        $this->cleanup[] = $directory.'/file';
        DirectorySyncBridge::$trace = true;
        $events = [];
        foreach ([false, true] as $rust) {
            DirectorySyncBridge::setUseRustFfi($rust);
            DirectorySyncBridge::$events = [];
            $results = [DirectorySyncBridge::sync($directory, 'dir'), DirectorySyncBridge::sync($directory.'/file', 'file'),
                DirectorySyncBridge::sync($directory.'/missing', 'missing'), DirectorySyncBridge::sync('file://'.$directory, 'remote')];
            $events[] = [$results, DirectorySyncBridge::$events];
        }
        $this->assertSame($events[0], $events[1]);
        $this->assertSame([[true, false, false, false], [['boundary' => 'dir', 'ok' => true], ['boundary' => 'file', 'ok' => false],
            ['boundary' => 'missing', 'ok' => false], ['boundary' => 'remote', 'ok' => false, 'failure' => 'unsupported']]], $events[1]);

        // Every pathname the reset's iterator yields stays inside its root, however the root is spelled.
        mkdir($directory.'/sub', 0700);
        $this->cleanup[] = $directory.'/sub';
        foreach ([false, true] as $rust) {
            VerificationResetService::setUseRustFfi($rust);
            foreach ([$directory, $directory.'/', $directory.'//', $directory.'/.', $directory.'/./', dirname($directory).'//'.basename($directory)] as $root) {
                foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    $this->assertTrue(VerificationResetService::isSafeSubpath($root, $entry->getPathname()), $root.' '.$entry->getPathname());
                }
            }
        }

        $guard = (new ReflectionClass(NzbSourceGuard::class))->newInstanceWithoutConstructor();
        $pdo = new PDO('sqlite::memory:');
        $pdo->beginTransaction();
        foreach ([false, true] as $rust) {
            NzbSourceInstallation::setUseRustFfi($rust);
            $staged = $directory.'/.guid.nzb.gz.'.($rust ? 'native' : 'php');
            $installation = new NzbSourceInstallation($guard, 'guid', $directory.'/guid.nzb.gz', 'digest', null, $pdo, $staged);
            $this->assertTrue(NzbSourceInstallation::isPendingStaged($staged, $pdo));
            $this->assertFalse(NzbSourceInstallation::isPendingStaged($directory.'/guid.nzb.gz', $pdo));
            $installation->close();
            $this->assertFalse(NzbSourceInstallation::isPendingStaged($staged, $pdo));
        }
        $pdo->rollBack();
    }

    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave32-2-nzb-sync-verification-publication-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including JSON encoding, FFI, allocation and decoding. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w32-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w32-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustNzbSyncVerificationPublicationParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/Nzb/DirectorySyncBridge.php', 'app/Services/Nzb/NzbSourceInstallation.php',
            'app/Services/Releases/Verification/VerificationResetService.php', 'app/Http/Controllers/ReleaseVerificationPublicationBatchController.php',
            'app/Http/Controllers/ReleaseVerificationPublicationController.php', 'tests/Unit/RustNzbSyncVerificationPublicationParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $class::setUseRustFfi(true);
                        $this->assertSame(end($expected), serialize($class::$method(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < $pairs; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < $calls; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            foreach ($order as $side) {
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / max(1.0, $nativeStats['mean_ns']), 'p99_speedup' => $phpStats['p99_ns'] / max(1, $nativeStats['p99_ns'])];
                    }
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $qualifies = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $report['results'][$name]['qualifies'] = $qualifies;
                $class::setUseRustFfi(null);
                if (! $qualifies) {
                    $this->assertFalse($class::usesRustFfi(), $name.' must keep PHP as the default below 1.5x');
                }
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            $summary = [];
            foreach ($report['results'] as $helper => $cells) {
                $summary[] = sprintf('%s common mean %.2fx p99 %.2fx; uncommon mean %.2fx p99 %.2fx', $helper,
                    $cells['common']['round_range']['mean_speedup']['min'], $cells['common']['round_range']['p99_speedup']['min'],
                    $cells['uncommon']['round_range']['mean_speedup']['min'], $cells['uncommon']['round_range']['p99_speedup']['min']);
            }
            fwrite(STDERR, "\nWave 32.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', DirectorySyncBridge::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>, int}> */
    private static function workloads(): array
    {
        $long = str_repeat('/deep/é', 64);

        return [
            [DirectorySyncBridge::class, 'normalizeBridgeProbeResult', [['fsync', true, true], ['fsync', true, null]],
                [['fsync', true, false], ['fsync', false, null], [null, true, null], ['', true, true], [null, false, false], ["fsync\0", true, true], ['é', true, false]], 0],
            [NzbSourceInstallation::class, 'normalizeSourcePathPair', [['/var/nzb/a/b/0123abcd.nzb.gz', '/var/nzb/a/b/.0123abcd.nzb.gz.staged-9f8e'],
                ['/var/nzb/c/d/4567ef01.nzb.gz', '/var/nzb/c/d/.4567ef01.nzb.gz.staged-1a2b']],
                [['', ''], ['x', 'x'], ['/a', 'a'], ['/n/a/', '/n/a/x'], ["/n/é\0/x", "/n/é\0/y"], [$long.'/a', $long.'/b'], ['/', '/'], ['/n/"q"\\', '/n/x']], 1],
            [VerificationResetService::class, 'buildTerminalScratchRecord', [[123, 2, 'worker-1', null, null], [0, 0, 'worker-2', 456, '0123abcd']],
                [[PHP_INT_MIN, -1, '', null, ''], [PHP_INT_MAX, PHP_INT_MAX, 'é', PHP_INT_MIN, "g\0"], [1, 1, str_repeat('h', 300), 0, '0']], 2],
            [VerificationResetService::class, 'isSafeSubpath', [['/data/verification', '/data/verification/123-1'], ['/data/verification/', '/data/verification/audit-5-0-0-9']],
                [['/s//', '/s//a'], ['/s/.', '/s/./a'], ['/', '/a'], ['rel', 'rel/a'], ['/s', '/s'], ['/s', '/s/'], ['/s', '/sa'], ['/s', '/s/..'], ['/s', '/s/a/../b'],
                    ['', '/a'], ['/s', "/s/a\0"], ['/s', '/s/...'], ['/s/é', '/s/é/ü'], ['///', '//a'], [$long, $long.'/x']], 3],
            [ReleaseVerificationPublicationBatchController::class, 'classifyBatchResponseStatus', [[false, 16, 16], [false, 1, 16], [true, 8, 64]],
                [[false, 0, 16], [true, 17, 16], [true, -1, 64], [false, PHP_INT_MAX, PHP_INT_MAX], [true, 1, PHP_INT_MIN], [false, PHP_INT_MIN, 0]], 4],
            [ReleaseVerificationPublicationController::class, 'classifySinglePublicationResponse', [[123, PublicationOutcome::PUBLISHED, null], [null, PublicationOutcome::FILTERED, null]],
                [[null, PublicationOutcome::DEFERRED, 'Partial upload held.'], [5, PublicationOutcome::METADATA_REVIEW, null], [null, PublicationOutcome::UNRESOLVED, null],
                    [null, PublicationOutcome::STALE, 'Claim no longer owns the candidate.'], [null, PublicationOutcome::PAUSED, 'x'], [null, null, null],
                    [PHP_INT_MAX, PublicationOutcome::PUBLISHED, 'ignored'], [PHP_INT_MIN, 'PUBLISHED', "é\0\"\\"], [null, '', '']], 5],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1],
            'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
