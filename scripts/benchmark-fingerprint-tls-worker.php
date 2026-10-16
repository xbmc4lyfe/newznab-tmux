<?php

declare(strict_types=1);

use App\Models\Release;
use App\Services\Binaries\BinariesConfig;
use App\Services\CollectionCleanupService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NNTP\FingerprintConnectionReuseNntpService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\Tmux\Tmux;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\YencService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

const FINGERPRINT_REQUIRED_SOURCES = [
    'scripts/benchmark-fingerprint-tls-worker.php',
    'app/Services/NNTP/NNTPService.php',
    'app/Services/NNTP/FingerprintConnectionReuseNntpService.php',
    'app/Services/Releases/Fingerprint/ReleaseFingerprintCollector.php',
    'app/Services/Releases/Fingerprint/NzbFingerprintAnalyzer.php',
    'app/Services/Releases/Fingerprint/NativeMetadataParser.php',
    'app/Services/Releases/Fingerprint/Par2MetadataParser.php',
    'app/Services/Nzb/NzbService.php',
    'app/Services/YencService.php',
    'app/Services/Yenc/NativePayloadDecoder.php',
    'app/Services/Tmux/Tmux.php',
    'app/Services/Configuration/ConfigurationProvider.php',
    'app/Services/Binaries/BinariesConfig.php',
    'app/Extensions/helper/helpers.php',
    'vendor/dariusiii/net_nntp/src/Protocol/Client.php',
    'vendor/dariusiii/net_nntp/src/Client.php',
    'composer.lock',
    'vendor/composer/installed.json',
];

// Standalone collector boundary only. No kernel, providers, dotenv, queue or persisted release metadata.
$outputPath = null;
$database = null;
$observations = [];
$preflight = [];
$input = [];
$exitCode = 1;
$correctness = false;
$preflightCompleted = false;
$correctnessTrace = null;
ob_start();
try {
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
        throw new RuntimeException('This worker requires Linux CLI PHP with FFI.');
    }
    $arguments = $_SERVER['argv'] ?? [];
    if (count($arguments) !== 2 || ! isset($arguments[1]) || ! str_starts_with($arguments[1], '/')) {
        throw new InvalidArgumentException('Usage: php benchmark-fingerprint-tls-worker.php /owned/input.json');
    }
    $inputPath = realpath($arguments[1]);
    if ($inputPath === false || ! is_file($inputPath) || filesize($inputPath) > 65536) {
        throw new InvalidArgumentException('Input must be an existing JSON file of at most 64 KiB.');
    }
    $controlRoot = dirname($inputPath);
    if ($controlRoot === '/' || $controlRoot === realpath(dirname(__DIR__))) {
        throw new InvalidArgumentException('Use a dedicated disposable input directory.');
    }
    $input = json_decode(file_get_contents($inputPath), true, 32, JSON_THROW_ON_ERROR);
    $correctness = is_array($input) && ($input['purpose'] ?? null) === 'idle_tls_correctness';
    $allowedArms = $correctness ? ['baseline', 'candidate-held', 'candidate'] : ['baseline', 'candidate'];
    if (! is_array($input) || ! in_array($input['arm'] ?? null, $allowedArms, true)) {
        if ($correctness) {
            throw new InvalidArgumentException('Correctness arm must be baseline, candidate-held or candidate.');
        }
        throw new InvalidArgumentException('arm must be baseline or candidate.');
    }
    foreach (['primary_port', 'alternate_port'] as $key) {
        if (! is_int($input[$key] ?? null) || $input[$key] < 1024 || $input[$key] > 65535) {
            throw new InvalidArgumentException($key.' must be a nonprivileged fixture port.');
        }
    }
    if ($input['primary_port'] === $input['alternate_port']) {
        throw new InvalidArgumentException('Provider fixture ports must be distinct.');
    }
    foreach (['ca_file', 'native_library', 'nzb_root'] as $key) {
        $path = $input[$key] ?? null;
        if (! is_string($path) || ! str_starts_with($path, '/') || realpath($path) !== $path
            || ! is_readable($path) || ($key === 'nzb_root' ? ! is_dir($path) : ! is_file($path))) {
            throw new InvalidArgumentException($key.' must be an absolute canonical readable path.');
        }
        if ($key !== 'native_library' && ! str_starts_with($path, $controlRoot.'/')) {
            throw new InvalidArgumentException($key.' must be inside the disposable input directory.');
        }
    }
    $requestedOutput = $input['output_file'] ?? null;
    if (! is_string($requestedOutput) || ! str_starts_with($requestedOutput, $controlRoot.'/')
        || realpath(dirname($requestedOutput)) !== dirname($requestedOutput)
        || file_exists($requestedOutput) || is_link($requestedOutput)) {
        throw new InvalidArgumentException('output_file must be a new absolute file inside the disposable input directory.');
    }
    $outputPath = $requestedOutput;
    if (! is_string($input['guid'] ?? null) || preg_match('/\A[0-9a-f]{32}\z/D', $input['guid']) !== 1
        || ! is_int($input['expected_samples'] ?? null) || $input['expected_samples'] < 1 || $input['expected_samples'] > 128
        || ! is_string($input['workload_id'] ?? null) || preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/D', $input['workload_id']) !== 1) {
        throw new InvalidArgumentException('Invalid guid, expected_samples or workload_id.');
    }
    $nzbPath = $input['nzb_root'].'/'.implode('/', str_split(substr($input['guid'], 0, 4))).'/'.$input['guid'].'.nzb.gz';
    if (realpath($nzbPath) !== $nzbPath || ! is_file($nzbPath) || ! is_readable($nzbPath) || filesize($nzbPath) > 33554432) {
        throw new InvalidArgumentException('Missing or oversized owned gzip NZB at the four-level GUID path.');
    }
    foreach (['ca_file_sha256' => $input['ca_file'], 'nzb_sha256' => $nzbPath] as $key => $path) {
        if (! is_string($input[$key] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $input[$key]) !== 1
            || ! hash_equals($input[$key], hash_file('sha256', $path))) {
            throw new RuntimeException('Required owned fixture hash mismatch: '.$key);
        }
    }
    $expectedPayloads = $input['expected_payloads'] ?? null;
    if (! is_array($expectedPayloads) || count($expectedPayloads) !== $input['expected_samples']) {
        throw new InvalidArgumentException('expected_payloads must contain exactly the selected sample identities.');
    }
    foreach ($expectedPayloads as $messageId => $expected) {
        if (! is_string($messageId) || preg_match('/\A[^\s<>\x00-\x1f\x7f]+\z/D', $messageId) !== 1
            || ! is_array($expected) || ! is_int($expected['decoded_bytes'] ?? null) || $expected['decoded_bytes'] < 1
            || $expected['decoded_bytes'] > 2000000
            || ! is_string($expected['decoded_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $expected['decoded_sha256']) !== 1
            || ! is_string($expected['raw_body_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $expected['raw_body_sha256']) !== 1) {
            throw new InvalidArgumentException('Malformed expected payload declaration.');
        }
    }
    $repo = dirname(__DIR__);
    $sourceHashes = $input['source_sha256'] ?? null;
    if (! is_array($sourceHashes) || ! isset($sourceHashes['composer.lock'], $sourceHashes['vendor/composer/installed.json'])
        || ! is_string($input['native_library_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $input['native_library_sha256']) !== 1
        || ! hash_equals($input['native_library_sha256'], hash_file('sha256', $input['native_library']))) {
        throw new RuntimeException('Required source/dependency/native library pins are missing or mismatched.');
    }
    foreach (FINGERPRINT_REQUIRED_SOURCES as $requiredSource) {
        if (! isset($sourceHashes[$requiredSource])) {
            throw new RuntimeException('Missing mandatory repository source pin: '.$requiredSource);
        }
    }
    if ($correctness && ! isset($sourceHashes['scripts/fingerprint-tls-correctness-trace.php'])) {
        throw new RuntimeException('Missing mandatory correctness trace source pin.');
    }
    foreach ($sourceHashes as $relative => $expected) {
        if (! is_string($relative) || str_starts_with($relative, '/') || str_contains($relative, '..')
            || ! is_string($expected) || preg_match('/\A[0-9a-f]{64}\z/D', $expected) !== 1
            || ! is_file($repo.'/'.$relative) || ! hash_equals($expected, hash_file('sha256', $repo.'/'.$relative))) {
            throw new RuntimeException('Required repository source hash mismatch: '.$relative);
        }
    }
    $hashes = $input['required_sha256'] ?? [];
    if (! is_array($hashes)) {
        throw new InvalidArgumentException('required_sha256 must map paths to SHA-256 values.');
    }
    $verifiedHashes = [];
    foreach ($hashes as $name => $expected) {
        $path = match ($name) {
            'worker' => __FILE__,
            'native_library' => $input['native_library'],
            default => is_string($name) && str_starts_with($name, '/') ? $name : $repo.'/'.$name,
        };
        if (! is_string($expected) || preg_match('/\A[0-9a-f]{64}\z/D', $expected) !== 1
            || ! is_file($path) || ! hash_equals($expected, hash_file('sha256', $path))) {
            throw new RuntimeException('Frozen source hash mismatch: '.$name);
        }
        $verifiedHashes[$name] = $expected;
    }

    $runtimeRoot = $controlRoot.'/worker-runtime-'.getmypid().'-'.bin2hex(random_bytes(8));
    if (! mkdir($runtimeRoot, 0700) || realpath($runtimeRoot) !== $runtimeRoot) {
        throw new RuntimeException('Cannot create the owned worker runtime directory.');
    }
    foreach (['storage', 'bootstrap', 'environment', 'storage/nzb'] as $relative) {
        $directory = $runtimeRoot.'/'.$relative;
        if (! mkdir($directory, 0700) || realpath($directory) !== $directory) {
            throw new RuntimeException('Cannot create a canonical owned application directory.');
        }
    }
    // Application path access must never inherit the repository or ambient storage tree.
    require $repo.'/vendor/autoload.php';
    if ($correctness) {
        require $repo.'/scripts/fingerprint-tls-correctness-trace.php';
        $correctnessTrace = new FingerprintTlsCorrectnessTrace;
    }
    $app = new Application($repo);
    $app->useStoragePath($runtimeRoot.'/storage');
    $app->useBootstrapPath($runtimeRoot.'/bootstrap');
    $app->useEnvironmentPath($runtimeRoot.'/environment');
    if (storage_path() !== $runtimeRoot.'/storage') {
        throw new RuntimeException('Application storage escaped its owned runtime directory.');
    }
    $app->instance('config', new Repository([
        'app' => ['name' => 'Disposable fingerprint TLS fixture', 'debug' => false, 'timezone' => 'UTC'],
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]], 'prefix' => 'fixture'],
        'database' => ['default' => 'fixture', 'connections' => []],
        'filesystems' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $controlRoot]]],
        'nntmux' => ['echocli' => false],
        'nntmux_settings' => ['path_to_nzbs' => $input['nzb_root']],
        'nntmux_ssl' => [
            'ssl_cafile' => $input['ca_file'], 'ssl_capath' => '',
            'ssl_verify_peer' => true, 'ssl_verify_host' => true, 'ssl_allow_self_signed' => false,
        ],
        'yenc' => ['native_library' => $input['native_library']],
        'nntmux_nntp' => [
            'server' => '127.0.0.1', 'alternate_server' => '127.0.0.1',
            'port' => $input['primary_port'], 'alternate_server_port' => $input['alternate_port'],
            'ssl' => true, 'alternate_server_ssl' => true,
            'username' => 'local-primary', 'password' => 'fixture-primary',
            'alternate_server_username' => 'local-alternate', 'alternate_server_password' => 'fixture-alternate',
            'socket_timeout' => 5, 'alternate_server_socket_timeout' => 5,
            'main_nntp_connections' => 16, 'alternate_nntp_connections' => 16,
            'use_alternate_nntp_server' => true, 'compressed_headers' => false,
        ],
    ]));
    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();
    Model::unsetEventDispatcher();
    $database = new Capsule($app);
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'fixture');
    $database->getDatabaseManager()->setDefaultConnection('fixture');
    $database->bootEloquent();
    $app->instance('db', $database->getDatabaseManager());
    $app->instance('db.schema', $database->getConnection('fixture')->getSchemaBuilder());
    $app->instance('files', new Filesystem);
    $app->instance('filesystem', new FilesystemManager($app));
    $app->instance('cache', new CacheManager($app));
    $app->instance('cache.store', $app->make('cache')->store('array'));
    $configuration = new ConfigurationProvider;
    $app->instance(ConfigurationProvider::class, $configuration);
    $defaults = $configuration->ingestion();
    if ($defaults->nntpRetries !== 10 || $defaults->nzbSplitLevel !== 4
        || $app->make('db.schema')->hasTable('ingestion_configurations')) {
        throw new RuntimeException('Expected isolated ingestion defaults without an ingestion table.');
    }
    Carbon::setTestNow(Carbon::parse('2026-10-16T12:00:00Z'));
    $decoder = new NativePayloadDecoder($input['native_library']);
    $yenc = new YencService($decoder);
    $app->instance(YencService::class, $yenc);
    $parser = new NativeMetadataParser;
    $probeHeaders = ["Message-ID: <probe@fixture.invalid>\r\n", "Date: Fri, 16 Oct 2026 10:00:00 +0000\r\n"];
    $probeBody = "=ybegin line=128 size=3 name=probe.bin\r\nklm\r\n=yend size=3 crc32=".hash('crc32b', 'ABC')."\r\n";
    $headerProbe = $parser->parseNntpHeaders(implode('', $probeHeaders));
    $dateProbe = $parser->parseArticleDates($probeHeaders);
    $framingProbe = $parser->parseYencFraming($probeBody);
    if (($headerProbe['parser_engine'] ?? null) !== 'nntmux-rust-nntp-headers'
        || ($dateProbe['parser_engine'] ?? null) !== 'nntmux-rust-article-dates'
        || $framingProbe === null || $yenc->decodeWithFrame($probeBody)[0] !== 'ABC') {
        throw new RuntimeException('Native parser or yEnc preflight did not prove the required implementation.');
    }
    // Verify ss exists through the actual Tmux path before measurement; do not replace its timed calls.
    $ssPath = trim((string) shell_exec('command -v ss'));
    if ($ssPath === '') {
        throw new RuntimeException('Linux ss is required for actual NNTP admission accounting.');
    }
    $ssOutput = [];
    $ssExitCode = -1;
    exec(escapeshellarg($ssPath).' -nH 2>&1', $ssOutput, $ssExitCode);
    if ($ssExitCode !== 0) {
        throw new RuntimeException('Actual ss preflight failed with exit '.$ssExitCode.': '.implode("\n", $ssOutput));
    }
    $socketSnapshot = (new Tmux)->getSocketSnapshot();
    $preflight = [
        'native_library_sha256' => hash_file('sha256', $input['native_library']),
        'worker_sha256' => hash_file('sha256', __FILE__), 'verified_sha256' => $verifiedHashes,
        'source_sha256' => $sourceHashes,
        'gzip_sha256' => hash_file('sha256', $nzbPath),
        'ca_file_sha256' => $input['ca_file_sha256'], 'expected_payloads' => $expectedPayloads,
        'header_parser' => $headerProbe, 'date_parser' => $dateProbe, 'yenc_framing_parser' => $framingProbe,
        'decoded_probe_sha256' => hash('sha256', 'ABC'), 'ss_path' => $ssPath,
        'socket_snapshot_bytes' => strlen($socketSnapshot), 'ss_exit_code' => $ssExitCode,
        'owned_application_paths' => ['storage' => $app->storagePath(), 'bootstrap' => $app->bootstrapPath(), 'environment' => $app->environmentPath()],
        'ingestion_defaults' => ['nntp_retries' => $defaults->nntpRetries, 'nzb_split_level' => $defaults->nzbSplitLevel],
    ];
    $binaries = BinariesConfig::fromSettings();
    $nzb = new NzbService(new CollectionCleanupService($binaries), $binaries);
    $collector = new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc);
    $release = new Release;
    $release->guid = $input['guid'];
    if ($correctness) {
        // An injected incumbent is comparable only when the collector would construct its own owner.
        $fixtureXml = $nzb->readNzbContents($input['guid']);
        if (! is_string($fixtureXml) || $fixtureXml === '') {
            throw new RuntimeException('Correctness capture requires an available valid NZB.');
        }
        $fixtureAnalyzer = new NzbFingerprintAnalyzer($yenc);
        $fixtureManifest = $fixtureAnalyzer->analyzeManifest($fixtureXml);
        $fixtureOmitted = 0;
        $fixtureSamples = $fixtureAnalyzer->selectArticleSamples($fixtureManifest['files'], NzbFingerprintAnalyzer::MAX_ARTICLE_SAMPLES, $fixtureOmitted);
        if ($fixtureOmitted !== 0 || count($fixtureSamples) !== $input['expected_samples']
            || array_column($fixtureSamples, 'messageid') !== array_keys($expectedPayloads)) {
            throw new RuntimeException('Correctness NZB sample identities do not match the declared fixture.');
        }
        unset($fixtureXml, $fixtureAnalyzer, $fixtureManifest, $fixtureSamples);
    }
    $preflightCompleted = true;
    $phases = $correctness ? ['first_after_preflight'] : ['first_after_preflight', 'repeat'];
    foreach ($phases as $phase) {
        $memoryBefore = memory_get_usage(true);
        $usageBefore = getrusage();
        $report = null;
        $phaseFailure = null;
        $start = hrtime(true);
        try {
            if ($correctness) {
                $owner = $input['arm'] === 'baseline'
                    ? new FingerprintTlsCorrectnessBaselineNntp($correctnessTrace)
                    : new FingerprintTlsCorrectnessReuseNntp($correctnessTrace);
                try {
                    // A/B markers are correctness-only no-ops; C releases exactly at this boundary.
                    $capture = fn (): array => $collector->collect($release, $owner, function () use ($owner, $input): void {
                        $owner->fingerprintTraceMarker('transport_boundary_enter');
                        if ($input['arm'] === 'candidate') {
                            $owner->releaseFingerprintAlternateTransport();
                        }
                        $owner->fingerprintTraceMarker('transport_boundary_return');
                    });
                    $report = $input['arm'] === 'baseline' ? $capture() : $owner->withFingerprintConnectionReuse($capture);
                } finally {
                    // Release the callback capture before the locally owned incumbent's destructor.
                    unset($capture, $owner);
                }
            } elseif ($input['arm'] === 'candidate') {
                $owner = new FingerprintConnectionReuseNntpService;
                try {
                    $report = $owner->withFingerprintConnectionReuse(fn (): array => $collector->collect(
                        $release, $owner, $owner->releaseFingerprintAlternateTransport(...),
                    ));
                } finally {
                    unset($owner);
                }
            } else {
                $report = $collector->collect($release);
            }
        } catch (Throwable $collectionException) {
            $phaseFailure = ['type' => $collectionException::class, 'message' => $collectionException->getMessage()];
            if (! $correctness) {
                throw $collectionException;
            }
        } finally {
            $elapsed = hrtime(true) - $start;
            $observations[] = [
                'phase' => $phase, 'caller_ns' => $elapsed,
                'memory_before_bytes' => $memoryBefore, 'memory_after_bytes' => memory_get_usage(true),
                'process_peak_bytes' => memory_get_peak_usage(true), 'rusage_before' => $usageBefore, 'rusage_after' => getrusage(),
                'report' => $report, 'failure' => $phaseFailure,
            ];
        }
        if ($correctness) {
            // Adverse caller output is evidence; the independent peer/driver owns its parity oracle.
            continue;
        }
        $data = $report['data'];
        if ($report['status'] !== 'complete' || $data['schema_version'] !== ReleaseFingerprintCollector::SCHEMA_VERSION
            || $data['errors'] !== [] || $data['collection_policy']['article_samples_selected'] !== $input['expected_samples']
            || $data['collection_policy']['article_samples_omitted_due_to_limit'] !== 0
            || count($data['article_observations']) !== 2 * $input['expected_samples']
            || ! $data['article_identity_parser_coverage_complete'] || ! $data['article_xref_parser_coverage_complete']
            || ! $data['article_date_parser_coverage_complete'] || ! $data['nntp_header_parser_coverage_complete']) {
            throw new RuntimeException('Healthy collector correctness gate failed for '.$phase.'.');
        }
        $seenPayloads = [];
        foreach ($data['article_observations'] as $article) {
            $messageId = $article['message_id'] ?? '';
            $expected = $expectedPayloads[$messageId] ?? null;
            $role = $article['server_role'] ?? '';
            if ($expected === null || ! in_array($role, ['primary', 'alternate'], true) || isset($seenPayloads[$messageId][$role])
                || ($article['metadata']['yenc']['decoded_segment_size'] ?? null) !== $expected['decoded_bytes']
                || ($article['metadata']['yenc']['decoded_size_matches_yend_size'] ?? null) !== true
                || ($article['metadata']['yenc']['declared_crc32_matches'] ?? null) !== true
                || ($article['metadata']['yenc']['decoded_segment_sha256'] ?? null) !== $expected['decoded_sha256']
                || ($article['metadata']['article_body_sha256'] ?? null) !== $expected['raw_body_sha256']) {
                throw new RuntimeException('Actual article yEnc decoding was not proven for '.$phase.'.');
            }
            $seenPayloads[$messageId][$role] = true;
        }
        foreach ($expectedPayloads as $messageId => $expected) {
            if (! isset($seenPayloads[$messageId]['primary'], $seenPayloads[$messageId]['alternate'])) {
                throw new RuntimeException('Expected payload lacks both provider observations for '.$phase.'.');
            }
        }
    }
    $payload = ['valid' => true];
    $exitCode = 0;
} catch (Throwable $exception) {
    $payload = ['valid' => false, 'failure' => ['type' => $exception::class, 'message' => $exception->getMessage(),
        'file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => $exception->getTraceAsString()]];
} finally {
    if ($database !== null) {
        try {
            $database->getConnection('fixture')->disconnect();
        } catch (Throwable $cleanupException) {
            $payload['valid'] = false;
            $payload['cleanup_failure'] = ['type' => $cleanupException::class, 'message' => $cleanupException->getMessage()];
            $exitCode = 1;
        }
    }
}
$capturedStdout = ob_get_clean();
if ($correctness && strlen($capturedStdout) > 65_536) {
    // Bound the serialized capture without changing the caller's existing output buffer.
    $capturedStdout = substr($capturedStdout, 0, 65_536);
    $correctnessTrace?->fail('Captured stdout exceeded its 65536 byte correctness limit.');
    $payload['valid'] = false;
    $exitCode = 1;
}
$payload += [
    'boundary' => 'standalone real collector with owned gzip NZB, in-memory configuration database, actual loopback TLS/authentication and native FFI; excludes job persistence and production',
    'phase_definition' => 'first_after_preflight is the first collection after native decoder, header, date and framing preflight outside the collection timer; it is partially warmed and may initialize identity, xref, body and format parser families inside its timer. repeat is the second collection in the same PHP process. Each collection constructs a fresh NNTP owner inside its timer and includes cleanup.',
    'arm' => $input['arm'] ?? null, 'workload_id' => $input['workload_id'] ?? null,
    'preflight' => $preflight, 'observations' => $observations,
    'runtime' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'pid' => getmypid()],
    'captured_stdout' => $capturedStdout,
];
if ($correctness) {
    $trace = $correctnessTrace?->snapshot();
    $infrastructureValid = $payload['valid'] && $preflightCompleted && $trace !== null && $trace['infrastructure_failure'] === null;
    if (! $infrastructureValid) {
        $payload['valid'] = false;
        $exitCode = 1;
    }
    $payload += [
        'purpose' => 'idle_tls_correctness', 'validation_scope' => 'caller_capture_not_parity',
        'preflight_completed' => $preflightCompleted, 'infrastructure_valid' => $infrastructureValid,
        'collections' => 1, 'trace' => $trace,
        'limits' => ['max_output_file_bytes' => 1_048_576, 'max_captured_stdout_bytes' => 65_536],
        'instrumentation' => 'correctness-only injected call-through owner and boundary markers; baseline is not a performance baseline; logical quit events do not imply wire QUIT; qualified parent calls bypass virtual observers',
    ];
    $payload['phase_definition'] = 'One first_after_preflight collection after native and valid-NZB preflight; partially warmed, instrumented caller capture only. Caller report/errors/failure are retained without a parity claim. A/B boundary callbacks only mark the boundary; C also releases its owned alternate transport. Owner cleanup is inside caller_ns.';
}
try {
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | ($correctness ? 0 : JSON_PRETTY_PRINT))."\n";
    if ($correctness && strlen($json) > 1_048_576) {
        // Check before opening/writing the result, rather than inspecting an oversized file afterwards.
        $json = json_encode([
            'valid' => false, 'purpose' => 'idle_tls_correctness',
            'validation_scope' => 'caller_capture_not_parity', 'preflight_completed' => $preflightCompleted,
            'infrastructure_valid' => false, 'arm' => $input['arm'] ?? null,
            'failure' => ['type' => 'CorrectnessOutputLimitExceeded', 'message' => 'Serialized correctness output exceeded its 1048576 byte pre-write limit; observations omitted.'],
            'limits' => ['max_output_file_bytes' => 1_048_576, 'max_captured_stdout_bytes' => 65_536],
        ], JSON_THROW_ON_ERROR)."\n";
        $exitCode = 1;
    }
    if ($outputPath !== null) {
        $output = fopen($outputPath, 'x');
        if ($output === false) {
            throw new RuntimeException('Cannot exclusively create the owned result file.');
        }
        try {
            if (fwrite($output, $json) !== strlen($json)) {
                throw new RuntimeException('Short write while recording worker result.');
            }
        } finally {
            fclose($output);
        }
    }
    fwrite(STDOUT, $json);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['valid' => false, 'failure' => ['type' => $exception::class, 'message' => $exception->getMessage()]], JSON_THROW_ON_ERROR)."\n");
    $exitCode = 1;
}
exit($exitCode);
