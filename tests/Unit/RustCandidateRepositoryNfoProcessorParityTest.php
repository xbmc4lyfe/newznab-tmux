<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\NfoProcessor;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\FetchedArticle;
use App\Services\Releases\Verification\RemotePublishItem;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Support\Par2CandidateCheckpoint;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\YencArticles;
use Tests\TestCase;
use Throwable;

final class RustCandidateRepositoryNfoProcessorParityTest extends TestCase
{
    private const array CLASSES = [CandidateRepository::class, FetchedArticle::class, RemotePublishItem::class, Par2CandidateCheckpoint::class,
        NfoProcessor::class];

    private const string QUEUED = 'Upload stopped growing before it was complete; queued for partial publication.';

    private const string DEFERRED = 'Partial publication deferred: ';

    private const int SEED = 34022026;

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_defaults_nullable_toggles_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $this->assertSame(CandidateRepository::FFI_CDEF, $class::FFI_CDEF);
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

    public function test_php_helpers_preserve_the_previous_inline_expressions(): void
    {
        $this->travelTo('2026-10-25 12:00:00');
        config(['release_verification.expiry_hours' => 48, 'release_verification.partial_after_hours' => 4]);
        $repository = app(CandidateRepository::class);
        $identity = new ReflectionMethod($repository, 'articleIdentity');
        $owed = new ReflectionMethod(CandidateRepository::class, 'owedPartialAttempt');
        $cutoff = now()->subHours(52)->format('Y-m-d H:i:s');
        $random = new Randomizer(new Mt19937(self::SEED));
        for ($i = 0; $i < 500; $i++) {
            $manifest = self::manifest($random, $random->getInt(0, 30));
            $ids = [];
            foreach ($manifest['files'] as $file) {
                foreach ($file['segments'] as $part) {
                    $ids[] = $file['key'].':'.$part['number'].':'.$part['messageid'].':'.$part['bytes'];
                }
            }
            sort($ids);
            $this->assertSame(hash('sha256', implode("\n", $ids)), $identity->invoke($repository, $manifest));

            [$last, , $status, $reason] = self::owedArgs($random, $cutoff);
            $row = (object) ['last_unique_at' => $last, 'status' => $status, 'reason' => $reason];
            $previous = $row->last_unique_at >= $cutoff
                && (($row->status === 'waiting_upload' && ! str_starts_with((string) ($row->reason ?? ''), self::DEFERRED))
                    || ($row->status === 'ready' && $row->reason === self::QUEUED));
            $this->assertSame($previous, $owed->invoke(null, $row), serialize($row));

            [$pending, $capacity] = self::capacityArgs($random);
            $this->assertSame($pending < $capacity - min(33554432, (int) ($capacity / 10)), CandidateRepository::hasPendingCapacityPhp($pending, $capacity));

            $claim = (object) ['id' => $random->getInt(0, 1) === 1 ? $random->getInt(1, PHP_INT_MAX) : (string) $random->getInt(1, 99999),
                'version' => (string) $random->getInt(0, 50), 'manifest_hash' => bin2hex($random->getBytes(16)), 'lease_token' => $random->getInt(0, 3) === 0 ? null : 'tok'];
            $this->assertSame(['id' => (int) $claim->id, 'version' => (int) $claim->version, 'manifest_hash' => (string) $claim->manifest_hash,
                'lease_token' => (string) $claim->lease_token], (new RemotePublishItem($claim, new VerificationResult('sampled', 'ok')))->claimPayload());

            [$movie, $tv] = [$random->getInt(-2, 3), $random->getInt(-2, 3)];
            $this->assertSame(['imdb' => (bool) $movie, 'tv' => (bool) $tv], NfoProcessor::lookupFlagsPhp($movie, $tv));
        }
        // Claims holding other types keep PHP's casts.
        $claim = (object) ['id' => 12.9, 'version' => null, 'manifest_hash' => 7, 'lease_token' => false];
        $this->assertSame(['id' => 12, 'version' => 0, 'manifest_hash' => '7', 'lease_token' => ''], (new RemotePublishItem($claim, new VerificationResult('sampled', 'ok')))->claimPayload());
    }

    public function test_instance_helpers_and_nfo_processor_wiring(): void
    {
        $this->nativeFfi();
        $raw = YencArticles::article('a fetched payload of some length');
        $article = VerifiedArticle::decode($raw);
        $fetched = new FetchedArticle($raw, $article);
        $checkpoint = new Par2CandidateCheckpoint(4, [1, 3], null, 0, null);
        $blocked = new Par2CandidateCheckpoint(2, [], ['version' => 1, 'packets' => [], 'tail' => '', 'skip_remaining' => 0], 12, 'too large');
        $item = new RemotePublishItem((object) ['id' => '41', 'version' => 3, 'manifest_hash' => 'abc', 'lease_token' => 'lease'], new VerificationResult('sampled', 'ok'));
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            $summary = $fetched->summary();
            $this->assertSame(\strlen($raw), $summary['raw_bytes']);
            $this->assertSame(\strlen($article->bytes), $summary['decoded_bytes']);
            $this->assertSame(\strlen($raw) - \strlen($article->bytes), $summary['overhead_bytes']);
            $this->assertSame($article->offset + \strlen($article->bytes), $summary['end_offset']);
            $this->assertSame(['blocked' => false, 'resumable' => true, 'remaining_segments' => 6, 'retry_count' => 2, 'complete' => false], $checkpoint->progress(10));
            $this->assertSame(['blocked' => true, 'resumable' => false, 'remaining_segments' => 0, 'retry_count' => 0, 'complete' => false], $blocked->progress(2));
            $this->assertSame(['id' => 41, 'version' => 3, 'manifest_hash' => 'abc', 'lease_token' => 'lease'], $item->claimPayload());

            $nfo = $this->createMock(NfoService::class);
            $nntp = $this->createStub(NNTPService::class);
            $provider = app(ConfigurationProvider::class);
            if ((int) $provider->postProcessing()->lookupNfo === 1) {
                $nfo->expects($this->once())->method('processNfoFiles')->with($nntp, '7', 'a',
                    (bool) $provider->metadata()->movieLookup->value, (bool) $provider->metadata()->tvLookup->value)->willReturn(0);
            } else {
                $nfo->expects($this->never())->method('processNfoFiles');
            }
            (new NfoProcessor($nfo))->process($nntp, '7', 'a');
        }
    }

    public function test_native_helpers_and_public_dispatch_have_exact_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = serialize($class::$php(...$args));
                $this->assertSame($expected, serialize($class::$native(...$args)), $class.'::'.$native.' '.bin2hex(serialize($args)));
                $class::setUseRustFfi(true);
                $forced = serialize($class::$method(...$args));
                $class::setUseRustFfi(false);
                $default = serialize($class::$method(...$args));
                $this->assertSame([$expected, $expected], [$forced, $default], $class.'::'.$method);
            }
        }
    }

    public function test_seeded_random_inputs_match_php_and_rust_answers_directly(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        $cutoff = '2026-10-23 08:00:00';
        for ($i = 0; $i < 3000; $i++) {
            $ids = self::ids($random, $random->getInt(0, 12));
            $lengths = array_map(strlen(...), $ids);
            $this->assertSame(CandidateRepository::articleIdentityDigestPhp($ids),
                CandidateRepository::evaluateNative(0, pack('q*', \count($ids), ...$lengths).implode('', $ids)), serialize($ids));
            $owed = self::owedArgs($random, $cutoff);
            $this->assertSame((int) CandidateRepository::isOwedPartialAttemptPhp(...$owed), self::raw(1, self::encodeOwed(...$owed), 1)[0], serialize($owed));
            $capacity = self::capacityArgs($random);
            $this->assertSame((int) CandidateRepository::hasPendingCapacityPhp(...$capacity), self::raw(2, pack('q2', ...$capacity), 1)[0], serialize($capacity));
            $claim = self::claimArgs($random);
            $expected = RemotePublishItem::normalizeClaimIdentityPhp(...$claim);
            $this->assertSame([$expected['id'], $expected['version']], self::raw(3, self::encodeClaim($claim[0], $claim[1]), 2), serialize($claim));
            $progress = self::progressArgs($random);
            $expected = Par2CandidateCheckpoint::summarizeProgressPhp(...$progress);
            $this->assertSame([(int) $expected['blocked'], (int) $expected['resumable'], $expected['remaining_segments'], $expected['retry_count'], (int) $expected['complete']],
                self::raw(4, pack('q5', $progress[0], $progress[1], (int) $progress[2], (int) $progress[3], $progress[4]), 5), serialize($progress));
            $transfer = self::transferArgs($random);
            $this->assertSame(FetchedArticle::summarizeTransferPhp(...$transfer), FetchedArticle::summarizeTransferNative(...$transfer), serialize($transfer));
            $this->assertSame(FetchedArticle::summarizeTransferPhp(...$transfer)['overhead_bytes'], self::raw(5, self::encodeTransfer(...$transfer), 7)[2]);
            $lookup = [$random->getInt(-3, 3), $random->getInt(0, 3) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(0, 2)];
            $flags = NfoProcessor::lookupFlagsPhp(...$lookup);
            $this->assertSame((int) $flags['imdb'] | ((int) $flags['tv'] << 1), self::raw(6, pack('q2', ...$lookup), 1)[0]);
        }
    }

    public function test_declined_inputs_fall_back_to_identical_php_results(): void
    {
        $this->nativeFfi();
        // PHP sorts and compares numeric strings numerically: Rust declines them.
        foreach ([['10', '9'], ['1e3', 'abc:1'], ['', 'x:1'], [' 5', '6 ']] as $ids) {
            $this->assertNull(CandidateRepository::evaluateNative(0, pack('q*', \count($ids), ...array_map(strlen(...), $ids)).implode('', $ids)));
            $this->assertSame(CandidateRepository::articleIdentityDigestPhp($ids), CandidateRepository::articleIdentityDigestNative($ids));
        }
        foreach ([['10', '9', 'waiting_upload', null], ['1e3', '200', 'waiting_upload', null], ['  9', '10', 'ready', self::QUEUED]] as $args) {
            $this->assertNull(CandidateRepository::evaluateNative(1, self::encodeOwed(...$args)));
            $this->assertSame(CandidateRepository::isOwedPartialAttemptPhp(...$args), CandidateRepository::isOwedPartialAttemptNative(...$args));
        }
        // Non-canonical integer strings keep PHP's (int) cast.
        foreach (['007', ' 12', '12abc', '1e3', '-0', '+4', '9223372036854775808', '-9223372036854775809', '', 'abc', '0x1A', "12\n"] as $value) {
            $this->assertNull(CandidateRepository::evaluateNative(3, self::encodeClaim($value, '1')), $value);
            $this->assertSame(RemotePublishItem::normalizeClaimIdentityPhp($value, '1', 'h', null), RemotePublishItem::normalizeClaimIdentityNative($value, '1', 'h', null));
            RemotePublishItem::setUseRustFfi(true);
            $this->assertSame((int) $value, RemotePublishItem::normalizeClaimIdentity($value, 2, 'h', 't')['id']);
            RemotePublishItem::setUseRustFfi(null);
        }
        foreach ([[7, pack('q2', 1, 1)], [-1, pack('q2', 1, 1)], [0, ''], [0, pack('q', -1)], [0, pack('q2', 1, 3).'ab'], [0, pack('q2', 1, 1).'ab'],
            [0, pack('q', PHP_INT_MAX)], [1, pack('q6', 2, 0, 0, 0, 0, 0)], [1, pack('q6', 0, 1, 0, 0, 0, 0).'x'], [1, pack('q6', 0, 0, 0, 0, 0, 1).'x'],
            [1, pack('q5', 0, 0, 0, 0, 0)], [2, pack('q', 1)], [2, pack('q3', 1, 1, 1)], [3, pack('q2', 1, 1).'1'], [3, pack('q2', -1, 1).'1'],
            [4, pack('q5', 0, 0, 2, 0, 0)], [4, pack('q5', 0, 0, 0, -1, 0)], [4, pack('q4', 0, 0, 0, 0)], [5, pack('q7', 0, 0, 0, 0, 0, 0, 1)],
            [5, pack('q7', 0, 0, 0, 0, 0, 2, 0)], [5, pack('q6', 0, 0, 0, 0, 0, 0)], [6, pack('q', 1)], [6, pack('q3', 1, 1, 1)]] as [$op, $input]) {
            $this->assertNull(CandidateRepository::evaluateNative($op, $input), $op.' '.bin2hex($input));
        }
        $this->assertNull(CandidateRepository::evaluateNative(6, str_repeat('x', 16777217)));
    }

    public function test_abi_capacity_malformed_inputs_and_bad_ranges_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        $ids = ['b:2:<n@x>:7', 'a:1:<m@x>:5'];
        $vectors = [[0, pack('q3', 2, 11, 11).implode('', $ids)], [1, self::encodeOwed('2026-10-24 00:00:00', '2026-10-23 00:00:00', 'ready', self::QUEUED)],
            [2, pack('q2', 10, 1000)], [3, self::encodeClaim('41', '-3')], [4, pack('q5', 4, 2, 1, 0, 10)], [5, self::encodeTransfer(1200, 5000, 1000, 1024, 2, 5)],
            [6, pack('q2', 1, 2)]];
        foreach ($vectors as [$op, $input]) {
            $buffer = $ffi->new('char[1024]');
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $length = $ffi->nntmux_w34_candidate_repository_nfo_processor($op, $input, \strlen($input), $buffer, 1024);
            $this->assertGreaterThan(0, $length);
            $wire = FFI::string($buffer, $length);
            $this->assertSame(CandidateRepository::evaluateNative($op, $input), $wire);
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w34_candidate_repository_nfo_processor($op, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2));
            }
        }
        $this->assertSame(hash('sha256', "a:1:<m@x>:5\nb:2:<n@x>:7"), CandidateRepository::evaluateNative(0, $vectors[0][1]));
        $buffer = $ffi->new('char[1024]');
        $input = pack('q2', 1, 1);
        foreach ([[null, 0, $buffer, 1024], [null, 16, $buffer, 1024], [$input, 16, null, 0], [$input, 16, null, 8], [$input, 16777217, $buffer, 1024],
            [$input, 16, $buffer, 16777217], [$buffer, 16, $buffer, 1024], [FFI::addr($buffer[8]), 16, $buffer, 1024], [$buffer, 16, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 16);
            FFI::memset(FFI::addr($buffer[16]), 0x5A, 1008);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w34_candidate_repository_nfo_processor(6, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 16).str_repeat('Z', 1008), FFI::string($buffer, 1024));
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w34_candidate_repository_nfo_processor(99, $input, 16, $buffer, 1024));
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Services\Releases\Verification\CandidateRepository::evaluateNative(6, pack('q2', 1, 1)) !== null) {
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
        $missing = '/tmp/w34-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    /** The wired callers' own suites (candidate admission, partial expiry, capacity, publication, PAR2 recovery) pass with every toggle forced on. */
    public function test_wired_callers_pass_their_suites_with_rust_forced(): void
    {
        $this->nativeFfi();
        $prepend = tempnam(sys_get_temp_dir(), 'w34-2-prepend-');
        $this->assertIsString($prepend);
        try {
            $classes = var_export(self::CLASSES, true);
            $base = addslashes(base_path());
            file_put_contents($prepend, <<<PREPEND
                <?php
                require '{$base}/vendor/autoload.php';
                if (App\\Services\\Releases\\Verification\\CandidateRepository::evaluateNative(6, pack('q2', 1, 1)) !== pack('q', 3)) {
                    fwrite(STDERR, 'Rust library did not load in the forced child.');
                    exit(97);
                }
                foreach ({$classes} as \$class) {
                    \$class::setUseRustFfi(true);
                }
                PREPEND);
            $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-d', 'auto_prepend_file='.$prepend, 'vendor/bin/phpunit', '--no-progress',
                'tests/Feature/ReleaseVerificationCandidateLifecycleTest.php', 'tests/Feature/ReleaseVerificationCandidatesTest.php',
                'tests/Feature/ReleaseVerificationArchiveLockTest.php', 'tests/Feature/ReleaseVerificationRemoteBatchPublisherTest.php',
                'tests/Unit/VerifiedArticleDecoderTest.php', 'tests/Unit/NzbContentsPar2RecoveryTest.php'],
                base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => getenv('NNTMUX_PIPELINE_FFI_LIB') ?: false], null, 900);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            // The project's PHPUnit printer reports one JSON summary line.
            $summary = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($summary, $process->getOutput());
            $this->assertSame('passed', $summary['result'] ?? null);
            $this->assertGreaterThan(80, $summary['tests'] ?? 0);
            $this->assertSame($summary['tests'], $summary['passed'] ?? null);
        } finally {
            @unlink($prepend);
        }
    }

    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave34-2-candidate-repository-nfo-processor-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w34-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w34-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustCandidateRepositoryNfoProcessorParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/Releases/Verification/CandidateRepository.php',
            'app/Services/Releases/Verification/FetchedArticle.php', 'app/Services/Releases/Verification/RemotePublishItem.php',
            'app/Support/Par2CandidateCheckpoint.php', 'app/Services/NfoProcessor.php', 'tests/Unit/RustCandidateRepositoryNfoProcessorParityTest.php'] as $file) {
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
                                $class::$method(...$inputs[$i % \count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < $pairs; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < $calls; $i++) {
                                $indices[] = $random->getInt(0, \count($inputs) - 1);
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
            fwrite(STDERR, "\nWave 34.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', CandidateRepository::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $cutoff = '2026-10-23 08:00:00';
        $common = static fn (callable $make, int $count): array => array_map(static fn (): array => $make(), range(1, $count));

        return [
            [CandidateRepository::class, 'articleIdentityDigest',
                $common(static fn (): array => [self::ids($random, $random->getInt(20, 80))], 8),
                [[[]], [self::ids($random, 2000)], [self::ids($random, 1, true)], [['10', '9', '8']], [array_fill(0, 50, 'dup:1:<same@x>:1')]]],
            [CandidateRepository::class, 'isOwedPartialAttempt',
                $common(static fn (): array => self::owedArgs($random, $cutoff), 16),
                [[null, $cutoff, 'waiting_upload', null], [null, '', 'waiting_upload', null], ['10', '9', 'ready', self::QUEUED],
                    ['2026-10-24 00:00:00', $cutoff, 'waiting_upload', self::DEFERRED.str_repeat('é', 600)], ['2026-10-24 00:00:00', $cutoff, 'ready', self::QUEUED.' ']]],
            [CandidateRepository::class, 'hasPendingCapacity',
                $common(static fn (): array => [$random->getInt(0, 2 << 30), 1073741824 * $random->getInt(1, 4)], 16),
                [[0, 0], [PHP_INT_MIN, PHP_INT_MIN], [PHP_INT_MAX, PHP_INT_MAX], [-1, 15], [14, 15], [PHP_INT_MAX - 33554433, PHP_INT_MAX], [0, -7]]],
            [RemotePublishItem::class, 'normalizeClaimIdentity',
                $common(static fn (): array => [(string) $random->getInt(1, 9999999), $random->getInt(1, 40), bin2hex($random->getBytes(32)), bin2hex($random->getBytes(16))], 16),
                [[PHP_INT_MAX, PHP_INT_MIN, '', null], ['007', '1e3', 'h', 't'], ['12abc', ' 4', 'h', null], ['-0', '0', '', ''], ['9223372036854775808', 1, 'h', 't']]],
            [Par2CandidateCheckpoint::class, 'summarizeProgress',
                $common(static fn (): array => self::progressArgs($random), 16),
                [[PHP_INT_MIN, PHP_INT_MIN, false, false, PHP_INT_MAX], [0, 0, false, false, 0], [10, 0, true, true, 10], [PHP_INT_MAX, 3, true, false, -5]]],
            [FetchedArticle::class, 'summarizeTransfer',
                $common(static fn (): array => self::transferArgs($random), 16),
                [[0, PHP_INT_MAX, PHP_INT_MAX, 1, 1, null], [-5, 0, -1, -9, 1, null], [PHP_INT_MAX, PHP_INT_MIN, 0, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN]]],
            [NfoProcessor::class, 'lookupFlags',
                $common(static fn (): array => [$random->getInt(0, 2), $random->getInt(0, 2)], 9),
                [[PHP_INT_MIN, PHP_INT_MAX], [-1, 0], [0, -1]]],
        ];
    }

    /** @return list<string> */
    private static function ids(Randomizer $random, int $count, bool $unicode = false): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $unicode ? 'fïlé-'.$random->getInt(0, 3) : 'file-'.$random->getInt(0, 9);
            $ids[] = $key.':'.$random->getInt(1, 400).':<part'.$random->getInt(1, 400).'of400.'.bin2hex($random->getBytes(4)).'@news.example>:'.$random->getInt(0, 800000);
        }
        if ($random->getInt(0, 9) === 0 && $count > 1) {
            $ids[] = $ids[0];
        }

        return $ids;
    }

    /**
     * @return array{files: list<array{key: string, segments: list<array{number: int, messageid: string, bytes: int}>}>}
     */
    private static function manifest(Randomizer $random, int $files): array
    {
        $manifest = ['files' => []];
        for ($f = 0; $f < $files; $f++) {
            $segments = [];
            for ($s = 0, $n = $random->getInt(0, 8); $s < $n; $s++) {
                $segments[] = ['number' => $random->getInt(1, 50), 'messageid' => bin2hex($random->getBytes(6)).'@x', 'bytes' => $random->getInt(0, 900000)];
            }
            $manifest['files'][] = ['key' => $random->getInt(0, 4) === 0 ? (string) $random->getInt(0, 99) : 'k'.$f, 'segments' => $segments];
        }

        return $manifest;
    }

    /** @return array{?string, string, string, ?string} */
    private static function owedArgs(Randomizer $random, string $cutoff): array
    {
        $last = match ($random->getInt(0, 5)) {
            0 => null,
            1 => $cutoff,
            2 => '2026-10-'.str_pad((string) $random->getInt(10, 28), 2, '0', STR_PAD_LEFT).' 00:00:00',
            3 => '',
            default => date('Y-m-d H:i:s', strtotime($cutoff) + $random->getInt(-86400, 86400)),
        };
        $status = ['waiting_upload', 'ready', 'pending', 'unknown', 'Ready', ''][$random->getInt(0, 5)];
        $reason = match ($random->getInt(0, 5)) {
            0 => null,
            1 => self::QUEUED,
            2 => self::DEFERRED.'policy rejected',
            3 => substr(self::DEFERRED, 0, $random->getInt(0, 29)),
            4 => '',
            default => 'Missing '.$random->getInt(1, 400).' articles.',
        };

        return [$last, $cutoff, $status, $reason];
    }

    /** @return array{int, int} */
    private static function capacityArgs(Randomizer $random): array
    {
        $pick = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-100, 100),
            default => $random->getInt(0, 1 << 34),
        };

        return [$pick(), $pick()];
    }

    /** @return array{int|string, int|string, string, ?string} */
    private static function claimArgs(Randomizer $random): array
    {
        $pick = static fn (): int|string => match ($random->getInt(0, 3)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => (string) $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => (string) $random->getInt(0, 999999),
        };

        return [$pick(), $pick(), bin2hex($random->getBytes(8)), $random->getInt(0, 3) === 0 ? null : 'lease'];
    }

    /** @return array{int, int, bool, bool, int} */
    private static function progressArgs(Randomizer $random): array
    {
        $count = $random->getInt(0, 3) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(0, 500);
        $next = $random->getInt(0, 3) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(0, 500);

        return [$next, $random->getInt(-2, 20), $random->getInt(0, 1) === 1, $random->getInt(0, 4) === 0, $count];
    }

    /** @return array{int, int, int, int, int, ?int} */
    private static function transferArgs(Randomizer $random): array
    {
        $wild = $random->getInt(0, 4) === 0;
        $int = static fn (int $min, int $max): int => $wild ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt($min, $max);
        $decoded = $int(0, 768000);
        $total = $random->getInt(0, 3) === 0 ? null : $int(1, 400);

        return [$int($wild ? 0 : $decoded, $wild ? 0 : $decoded + 30000), $int(0, 400 * 768000), $int(0, 300 * 768000), $decoded, $int(1, 400), $total];
    }

    private static function encodeOwed(?string $last, string $cutoff, string $status, ?string $reason): string
    {
        return pack('q6', (int) ($last !== null), \strlen($last ?? ''), \strlen($cutoff), \strlen($status), (int) ($reason !== null), \strlen($reason ?? ''))
            .$last.$cutoff.$status.$reason;
    }

    private static function encodeClaim(int|string $id, int|string $version): string
    {
        return pack('q2', \strlen((string) $id), \strlen((string) $version)).$id.$version;
    }

    private static function encodeTransfer(int $raw, int $size, int $offset, int $decoded, int $part, ?int $total): string
    {
        return pack('q7', $raw, $size, $offset, $decoded, $part, (int) ($total !== null), $total ?? 0);
    }

    /** @return list<int> */
    private static function raw(int $operation, string $input, int $slots): array
    {
        $values = CandidateRepository::nativeSlots(CandidateRepository::evaluateNative($operation, $input), $slots);
        if ($values === null) {
            throw new RuntimeException('Rust declined operation '.$operation.': '.bin2hex($input));
        }

        return $values;
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = \count($values);

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
