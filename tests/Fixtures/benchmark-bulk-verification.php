<?php

declare(strict_types=1);

use App\Services\NNTP\NNTPService;
use App\Services\NNTP\RustVerificationFramer;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerifiedArticle;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Fixtures\YencArticles;

// Standalone: deliberately never boots Laravel, loads .env, or opens a database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/YencArticles.php';

final class BulkBenchmarkNntp extends NNTPService
{
    /** @param resource $socket */
    public function __construct($socket, int $timeout)
    {
        $this->_socket = $socket;
        $this->_socketTimeout = $timeout;
        stream_set_timeout($socket, $timeout);
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        return $this->isConnected();
    }

    public function doQuit(bool $force = false): mixed
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        $this->_socket = null;

        return true;
    }

    public function __destruct()
    {
        $this->doQuit();
    }
}

/**
 * @phpstan-type Fixture array{name:string, kind:string, ids:list<string>, manifests:list<array<string,mixed>>, bodies:array<string,string>, expected:list<array<string,mixed>>, delay_us:int, payload_bytes:int, input_sha256:string}
 * @phpstan-type Observation array<string,mixed>
 */
final class BulkVerificationBenchmark
{
    private const int ARTICLE_BYTES = 1048576;

    private const int BUFFER_BYTES = 8388608;

    /** @var array<string,int> */
    private const array OPTIONS = ['spot_check_articles' => 1, 'spot_check_bytes' => 1048576, 'spot_check_article_bytes' => 1048576];

    /** @var resource */
    private $output;

    private readonly Repository $config;

    /** @var array<string,mixed> */
    private readonly array $initialProvenance;

    /** @param list<string> $argv */
    public function __construct(private readonly string $root, private readonly string $binary,
        private readonly int $pairs, private readonly int $warmups, private readonly int $seed,
        private readonly int $timeout, private readonly string $outputPath, private readonly array $argv)
    {
        $this->output = fopen($outputPath, 'xb');
        if ($this->output === false) {
            throw new RuntimeException('Cannot exclusively create JSONL output: '.$outputPath);
        }
        $container = new Container;
        Container::setInstance($container);
        $this->config = new Repository(['release_verification' => self::OPTIONS + [
            'article_bytes' => self::ARTICLE_BYTES, 'download_connections' => 1, 'pipeline_window' => 8,
            'download_buffer_bytes' => self::BUFFER_BYTES, 'rust_worker' => '', 'stat_all_articles' => false,
        ], 'nntmux_nntp' => ['alternate_article_fallback' => false]]);
        $container->instance('config', $this->config);
        $this->initialProvenance = $this->provenance();
    }

    /** @return array<string,mixed> */
    private function provenance(): array
    {
        $diff = $this->command(['git', 'diff', '--binary', 'HEAD', '--']);
        $untracked = [];
        foreach (explode("\0", $this->command(['git', 'ls-files', '--others', '--exclude-standard', '-z'])) as $path) {
            if ($path !== '') {
                $untracked[$path] = hash_file('sha256', $this->root.'/'.$path);
            }
        }
        ksort($untracked);
        $files = [];
        foreach (['composer.lock', 'vendor/composer/installed.json', 'tests/Fixtures/benchmark-bulk-verification.php'] as $path) {
            $files[$path] = hash_file('sha256', $this->root.'/'.$path);
        }

        return ['git_head' => trim($this->command(['git', 'rev-parse', 'HEAD'])),
            'git_status' => $this->command(['git', 'status', '--porcelain=v1', '--untracked-files=all']),
            'tracked_dirty_diff_sha256' => hash('sha256', $diff), 'untracked_sha256' => $untracked,
            'dirty_diff_sha256' => hash('sha256', $diff.json_encode($untracked, JSON_THROW_ON_ERROR)),
            'files_sha256' => $files, 'rust_binary' => $this->binary, 'rust_binary_sha256' => hash_file('sha256', $this->binary)];
    }

    /** @param list<string> $args */
    private function command(array $args): string
    {
        $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->root);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not run provenance command.');
        }
        $text = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $text === false) {
            throw new RuntimeException('Provenance command failed: '.implode(' ', $args));
        }

        return $text;
    }

    /** @return Fixture */
    private function fixture(string $name, int $payloadBytes, int $delayUs, bool $stat = false): array
    {
        $ids = $manifests = $bodies = $expected = $hashes = [];
        for ($index = 0; $index < ($stat ? 16 : 8); $index++) {
            $id = $name.'-'.$index.'@benchmark.invalid';
            $ids[] = $id;
            if ($stat) {
                $expected[] = ['message_id' => $id, 'verdict' => 'present'];

                continue;
            }
            $filename = $name.'-'.$index.'.bin';
            $payload = YencArticles::randomBytes($payloadBytes);
            // Rotate deterministic bytes by article, preserving escape and dot-stuff coverage.
            $payload = substr($payload, $index).substr($payload, 0, $index);
            $body = str_replace('name=fixture file.bin', 'name='.$filename, YencArticles::article($payload))."\r\n";
            $decoded = VerifiedArticle::decode($body);
            if ($decoded->bytes !== $payload || $decoded->name !== $filename || $decoded->size !== $payloadBytes) {
                throw new RuntimeException('Independent deterministic yEnc fixture did not round-trip.');
            }
            $bodies[$id] = $body;
            $hashes[$id] = ['payload' => hash('sha256', $payload), 'body' => hash('sha256', $body)];
            $manifests[] = ['identity' => $id, 'expected_files' => 1, 'files' => [[
                'key' => 'file-'.$index, 'subject' => '"'.$filename.'" yEnc (1/1)', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => $id, 'bytes' => strlen($body)]],
            ]]];
            $expected[] = ['articles' => 1, 'bytes' => strlen($body), 'sampled_files' => 1,
                'verified_file_names' => [$filename], 'identity_hints' => [$filename], 'partial' => false];
        }

        return ['name' => $name, 'kind' => $stat ? 'stat' : 'body', 'ids' => $ids, 'manifests' => $manifests,
            'bodies' => $bodies, 'expected' => $expected, 'delay_us' => $delayUs, 'payload_bytes' => $payloadBytes,
            'input_sha256' => hash('sha256', json_encode([$ids, $manifests, $hashes, $delayUs, self::OPTIONS], JSON_THROW_ON_ERROR))];
    }

    /** @param resource $socket */
    private static function writeAll($socket, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = @fwrite($socket, substr($bytes, $offset, 65536));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Fixture socket write failed or timed out.');
            }
            $offset += $written;
        }
    }

    /**
     * Read exactly one selected command group BEFORE sleeping/writing. Waiting for eight
     * sequential commands would deadlock; delaying each pipelined reply would fake eight RTTs.
     *
     * @param  resource  $socket
     * @param  resource  $report
     * @param  Fixture  $fixture
     */
    private function serve($socket, $report, array $fixture, int $groupSize, string $fault): never
    {
        pcntl_alarm($this->timeout);
        stream_set_timeout($socket, $this->timeout);
        $commands = [];
        $groups = 0;
        $wireBytes = 0;
        $error = null;
        try {
            foreach (array_chunk($fixture['ids'], $groupSize) as $group) {
                foreach ($group as $id) {
                    $command = fgets($socket, 512);
                    if ($command !== strtoupper($fixture['kind']).' <'.$id.">\r\n") {
                        throw new RuntimeException('Unexpected, missing, or out-of-order fixture command.');
                    }
                    $commands[] = $id;
                }
                usleep($fixture['delay_us']);
                $groups++;
                foreach ($group as $id) {
                    $body = $fixture['bodies'][$id] ?? '';
                    if ($fault === 'missing_middle' && $id === $fixture['ids'][4]) {
                        $wire = "430 article unavailable\r\n";
                    } elseif ($fixture['kind'] === 'stat') {
                        $wire = '223 0 <'.$id."> article exists\r\n";
                    } else {
                        if ($fault === 'wrong_identity' && $id === $fixture['ids'][4]) {
                            $body = preg_replace('/name=[^\r\n]+/', 'name=wrong-file.bin', $body) ?? throw new RuntimeException('Identity fixture failed.');
                        }
                        $wire = '222 0 <'.$id."> body follows\r\n".preg_replace('/^\./m', '..', $body).".\r\n";
                    }
                    self::writeAll($socket, $wire);
                    $wireBytes += strlen($wire);
                }
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
        fclose($socket);
        self::writeAll($report, json_encode(['commands' => $commands, 'groups' => $groups,
            'wire_bytes' => $wireBytes, 'error' => $error, 'php_peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));
        fclose($report);
        exit($error === null ? 0 : 1);
    }

    /** @param Fixture $fixture
     * @return Observation
     */
    private function observe(array $fixture, string $mode, string $fault = ''): array
    {
        $this->config->set('release_verification.rust_worker', $mode === 'rust_batch' ? $this->binary : '');
        $errors = $results = [];
        $rustFeedObserved = false;
        $heartbeats = 0;
        $nntp = $source = $verifier = null;
        $pid = -1;
        $data = $report = false;
        $server = null;
        $status = 0;
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage(true);
        $cpuBefore = getrusage();
        $start = hrtime(true); // Socket creation, fork, IPC startup, checks, cleanup and child join are included.
        $deadline = $start + $this->timeout * 1_000_000_000;
        $heartbeat = static function () use (&$rustFeedObserved, &$heartbeats, $deadline): void {
            $heartbeats++;
            if (hrtime(true) > $deadline) {
                throw new RuntimeException('Observation deadline exceeded.');
            }
            if (! $rustFeedObserved) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
                    if (($frame['class'] ?? '') === RustVerificationFramer::class && $frame['function'] === 'feed') {
                        $rustFeedObserved = true;
                        break;
                    }
                }
            }
        };
        try {
            $data = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $report = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($data === false || $report === false) {
                throw new RuntimeException('Could not create owned fixture socket pairs.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork fixture server.');
            }
            if ($pid === 0) {
                fclose($data[0]);
                fclose($report[0]);
                fclose($this->output);
                $this->serve($data[1], $report[1], $fixture, $mode === 'sequential' ? 1 : count($fixture['ids']), $fault);
            }
            fclose($data[1]);
            fclose($report[1]);
            stream_set_timeout($report[0], $this->timeout);
            $nntp = new BulkBenchmarkNntp($data[0], $this->timeout);
            $source = new NntpArticleSource($nntp, maximumBytes: self::ARTICLE_BYTES, allowAlternateFallback: false);
            if ($fixture['kind'] === 'stat') {
                $source->stat($fixture['ids'], $heartbeat);
                $results = $fixture['expected'];
            } else {
                if ($mode !== 'sequential') {
                    if ($source->batchSize() !== 8) {
                        throw new RuntimeException('Configuration no longer admits all eight prefetched articles.');
                    }
                    $source->prefetch($fixture['ids'], $heartbeat);
                }
                $verifier = new SpotCheckVerifier($source, self::OPTIONS);
                foreach ($fixture['manifests'] as $manifest) {
                    $heartbeat();
                    $result = $verifier->verify($manifest, $heartbeat);
                    $results[] = ['verdict' => $result->verdict, 'reason' => $result->reason,
                        'deterministic' => $result->deterministic, 'waiting_for_headers' => $result->waitingForHeaders,
                        'header_rejected' => $result->headerRejected,
                        'evidence' => $result->passed() ? SpotCheckVerifier::evidence($result->manifest) : null,
                        'manifest_sha256' => hash('sha256', json_encode($result->manifest, JSON_THROW_ON_ERROR))];
                }
            }
        } catch (Throwable $exception) {
            $errors[] = $exception::class.': '.$exception->getMessage();
        } finally {
            unset($verifier, $source);
            $nntp?->doQuit();
            if ($data !== false) {
                foreach ($data as $socket) {
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                }
            }
            if ($pid > 0) {
                while (($waited = pcntl_waitpid($pid, $status, WNOHANG)) === 0 && hrtime(true) < $deadline) {
                    usleep(1000);
                }
                if ($waited === 0) {
                    // Only our disposable fixture child is signalled, never application workers.
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                    $errors[] = 'Fixture child exceeded observation deadline.';
                } elseif ($waited === -1) {
                    $errors[] = 'Fixture child could not be reaped.';
                }
                if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $errors[] = 'Fixture child failed (wait status '.$status.').';
                }
                if ($report !== false && is_resource($report[0])) {
                    try {
                        $server = json_decode(stream_get_contents($report[0]) ?: '', true, 16, JSON_THROW_ON_ERROR);
                    } catch (Throwable $exception) {
                        $errors[] = 'Missing fixture report: '.$exception->getMessage();
                    }
                }
            }
            if ($report !== false) {
                foreach ($report as $socket) {
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                }
            }
        }
        $elapsed = hrtime(true) - $start;
        $peak = memory_get_peak_usage(true);
        $cpuAfter = getrusage();
        if ($rustFeedObserved !== ($mode === 'rust_batch')) {
            $errors[] = 'Rust feed execution evidence disagrees with selected mode; fallback cannot qualify.';
        }
        if (! is_array($server) || ($server['commands'] ?? null) !== $fixture['ids'] || ($server['error'] ?? null) !== null
            || ($server['groups'] ?? null) !== ($mode === 'sequential' ? count($fixture['ids']) : 1)) {
            $errors[] = 'Fixture did not complete the exact command/RTT protocol.';
        }
        if (count($results) !== count($fixture['ids'])) {
            $errors[] = 'Not all article outcomes were returned.';
        }
        if ($fixture['kind'] === 'body') {
            foreach ($results as $index => $result) {
                $faulted = $fault !== '' && $index === 4;
                if ($faulted) {
                    if ($result['verdict'] !== 'unknown' || $result['evidence'] !== null
                        || $result['deterministic'] !== ($fault === 'wrong_identity')
                        || ! str_contains($result['reason'], $fault === 'wrong_identity' ? 'identity differs' : 'unavailable')) {
                        $errors[] = 'Incorrect fault outcome at index '.$index;
                    }
                } elseif ($result['verdict'] !== 'sampled' || $result['evidence'] !== $fixture['expected'][$index]
                    || $result['deterministic'] || $result['waiting_for_headers'] || $result['header_rejected']) {
                    $errors[] = 'Invalid sampled verdict/evidence at index '.$index;
                }
            }
        }

        return ['mode' => $mode, 'fault' => $fault, 'valid' => $errors === [], 'errors' => $errors,
            'elapsed_ns' => $elapsed, 'php_peak_bytes' => $peak, 'php_memory_before_bytes' => $memoryBefore,
            'php_peak_increment_bytes' => max(0, $peak - $memoryBefore),
            'php_user_cpu_us' => self::cpuMicros($cpuAfter, 'utime') - self::cpuMicros($cpuBefore, 'utime'),
            'php_system_cpu_us' => self::cpuMicros($cpuAfter, 'stime') - self::cpuMicros($cpuBefore, 'stime'),
            'rust_feed_observed' => $rustFeedObserved, 'heartbeats' => $heartbeats, 'server' => $server, 'results' => $results];
    }

    /** @param array<string,int>|false $usage */
    private static function cpuMicros(array|false $usage, string $kind): int
    {
        return ($usage['ru_'.$kind.'.tv_sec'] ?? 0) * 1000000 + ($usage['ru_'.$kind.'.tv_usec'] ?? 0);
    }

    /** @param array<string,mixed> $record */
    private function record(array $record): void
    {
        self::writeAll($this->output, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        fflush($this->output);
    }

    /** @param list<float> $values
     * @return array<string,mixed>
     */
    private static function distribution(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return ['n' => 0, 'median_ms' => null, 'p99_ms' => null];
        }
        $quantile = static fn (float $p): float => $values[max(0, (int) ceil($p * $count) - 1)];
        $rank = 0.99 * $count;
        $delta = 1.96 * sqrt($count * 0.99 * 0.01);

        return ['n' => $count, 'median_ms' => $count % 2 === 0 ? ($values[intdiv($count, 2) - 1] + $values[intdiv($count, 2)]) / 2 : $values[intdiv($count, 2)],
            'p99_ms' => $quantile(0.99), 'min_ms' => $values[0], 'max_ms' => $values[$count - 1],
            'p99_approx_95pct_rank_interval_ms' => [$values[max(0, (int) floor($rank - $delta) - 1)], $values[min($count - 1, (int) ceil($rank + $delta) - 1)]]];
    }

    public function run(): int
    {
        $random = new Randomizer(new Mt19937($this->seed));
        $fixtures = [$this->fixture('common_32k', 32768, 2000), $this->fixture('uncommon_500k', 512000, 5000),
            $this->fixture('stat16', 0, 2000, true)];
        $this->record(['type' => 'protocol', 'version' => 1, 'created_utc' => gmdate(DATE_ATOM),
            'scope' => 'Socket-pair NNTP transport + SpotCheckVerifier for BODY; NntpArticleSource::stat for STAT16. No DB, scheduler, capacity, TCP or TLS evidence.',
            'adoption_qualified' => false, 'adoption_blocker' => 'Realistic TCP/TLS caller measurements required; fixture screen cannot authorize adoption.',
            'argv' => $this->argv, 'reproduce_argv' => array_merge([PHP_BINARY], $this->argv),
            'pairs' => $this->pairs, 'warmups' => $this->warmups, 'seed' => $this->seed, 'prng' => 'PHP Randomizer/Mt19937',
            'observation_timeout_seconds' => $this->timeout, 'source' => $this->initialProvenance,
            'runtime' => ['php' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'php_binary_sha256' => hash_file('sha256', PHP_BINARY),
                'os' => php_uname(), 'memory_limit' => ini_get('memory_limit'), 'extensions' => get_loaded_extensions(),
                'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
                'rust_build' => 'External release binary; record matching cargo build --release --locked command/build receipt with this artifact.',
                'cpu' => PHP_OS_FAMILY === 'Darwin' ? trim($this->command(['sysctl', '-n', 'machdep.cpu.brand_string'])) : @file_get_contents('/proc/cpuinfo')],
            'config' => $this->config->all(), 'transport' => ['type' => 'AF_UNIX socket_pair', 'tls' => false,
                'body_commands_per_rtt' => ['sequential' => 1, 'php_batch' => 8, 'rust_batch' => 8], 'stat_commands_per_rtt' => 16,
                'response_delay' => 'One sleep after receiving every command in each group, then ordered responses; no per-response sleep.'],
            'measurement' => 'Start before socket creation/fork; end after all outcomes, source/parser cleanup and child join/report. Fixture generation and JSONL writes excluded; observer heartbeat included.',
            'memory' => 'Per-observation PHP allocator peak reset; includes resident fixture inputs. Fixture child PHP peak reported separately. Rust RSS and combined process-tree peak are NOT measured.',
            'eligibility' => 'All modes pass expected verdict/evidence and exact wire protocol, with equal successful results. Fault observations never enter timings. At least 1000 eligible pairs per workload for p99 evidence.',
            'quantiles' => 'Median midpoint; p99 nearest rank ceil(0.99*n); approximate 95% binomial-normal rank interval, descriptive under serial dependence.',
            'fixtures' => array_map(static fn (array $f): array => array_diff_key($f, array_flip(['bodies', 'manifests', 'expected'])), $fixtures)]);

        // Prove readiness through the real IPC contract before any timed Rust observation.
        try {
            $probe = new RustVerificationFramer($this->binary, [['ids' => ['probe@benchmark.invalid'], 'mode' => 'stat']], 1, 16, static function (): void {});
            $reply = $probe->feed(0, "223 0 <probe@benchmark.invalid> exists\r\n");
            $probe->close();
            if ($reply !== ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'present', 'body' => '']]]) {
                throw new RuntimeException('Worker readiness result did not match.');
            }
            $this->record(['type' => 'worker_readiness', 'valid' => true]);
        } catch (Throwable $error) {
            $this->record(['type' => 'worker_readiness', 'valid' => false, 'error' => $error->getMessage()]);
            throw $error;
        }

        $correct = true;
        foreach (['missing_middle', 'wrong_identity'] as $fault) {
            $successful = [];
            foreach ($random->shuffleArray(['sequential', 'php_batch', 'rust_batch']) as $mode) {
                $observation = $this->observe($fixtures[0], $mode, $fault);
                $this->record(['type' => 'correctness', 'workload' => $fixtures[0]['name']] + $observation);
                $correct = $correct && $observation['valid'];
                $successful[$mode] = array_filter($observation['results'], static fn (array $r): bool => $r['verdict'] === 'sampled');
            }
            $equal = $successful['sequential'] === $successful['php_batch'] && $successful['php_batch'] === $successful['rust_batch'];
            $correct = $correct && $equal;
            $this->record(['type' => 'correctness_equality', 'fault' => $fault, 'successful_outputs_equal' => $equal,
                'note' => 'Missing-article transport error wording may differ; expected verdict, determinism and all following successful evidence must agree. Wrong identity means yEnc filename/header mismatch.']);
        }
        if (! $correct) {
            $this->record(['type' => 'summary', 'valid' => false, 'adoption_qualified' => false, 'error' => 'Correctness preflight failed; no performance measurements accepted.']);

            return 1;
        }

        $summaries = [];
        $invalidTotal = 0;
        $warmupFailures = 0;
        foreach ($random->shuffleArray($fixtures) as $fixture) {
            $modes = $fixture['kind'] === 'body' ? ['sequential', 'php_batch', 'rust_batch'] : ['php_batch', 'rust_batch'];
            $times = array_fill_keys($modes, []);
            $invalid = 0;
            for ($iteration = -$this->warmups; $iteration < $this->pairs; $iteration++) {
                $order = $random->shuffleArray($modes);
                $observations = [];
                foreach ($order as $position => $mode) {
                    $observation = $this->observe($fixture, $mode);
                    $observations[$mode] = $observation;
                    $this->record(['type' => 'observation', 'workload' => $fixture['name'], 'iteration' => $iteration,
                        'warmup' => $iteration < 0, 'order' => $order, 'position' => $position] + $observation);
                }
                $reference = $observations[$modes[0]]['results'];
                $equal = true;
                $valid = true;
                foreach ($observations as $observation) {
                    $equal = $equal && $reference === $observation['results'];
                    $valid = $valid && $observation['valid'];
                }
                $eligible = $valid && $equal;
                $this->record(['type' => 'pair', 'workload' => $fixture['name'], 'iteration' => $iteration,
                    'warmup' => $iteration < 0, 'eligible' => $eligible, 'verdict_and_evidence_equal' => $equal]);
                if ($iteration < 0) {
                    $warmupFailures += (int) ! $eligible;

                    continue;
                }
                if (! $eligible) {
                    $invalid++;

                    continue;
                }
                foreach ($observations as $mode => $observation) {
                    $times[$mode][] = $observation['elapsed_ns'] / 1e6;
                }
            }
            $distributions = array_map(self::distribution(...), $times);
            $comparisons = [];
            foreach ($fixture['kind'] === 'body' ? [['sequential', 'php_batch'], ['sequential', 'rust_batch'], ['php_batch', 'rust_batch']] : [['php_batch', 'rust_batch']] as [$baseline, $candidate]) {
                $a = $distributions[$baseline];
                $b = $distributions[$candidate];
                $comparisons[$baseline.'_over_'.$candidate] = ['median_speedup' => $b['median_ms'] ? $a['median_ms'] / $b['median_ms'] : null,
                    'p99_speedup' => $b['p99_ms'] ? $a['p99_ms'] / $b['p99_ms'] : null,
                    'ratios_are' => 'baseline quantile / candidate same quantile, not a quantile of paired ratios'];
            }
            $invalidTotal += $invalid;
            $summaries[$fixture['name']] = ['kind' => $fixture['kind'], 'requested_pairs' => $this->pairs,
                'eligible_pairs' => count($times['rust_batch']), 'invalid_pairs' => $invalid,
                'p99_sample_count_sufficient' => count($times['rust_batch']) >= 1000,
                'latency' => $distributions, 'comparisons' => $comparisons];
            fwrite(STDERR, $fixture['name'].': '.count($times['rust_batch']).' eligible, '.$invalid." invalid pairs\n");
        }
        $final = $this->provenance();
        $unchanged = $final === $this->initialProvenance;
        $this->record(['type' => 'provenance_end', 'unchanged' => $unchanged, 'source' => $final]);
        $summary = ['type' => 'summary', 'valid' => $invalidTotal === 0 && $warmupFailures === 0 && $unchanged,
            'adoption_qualified' => false, 'screen_only' => $this->pairs < 1000,
            'invalid_pairs' => $invalidTotal, 'warmup_failures' => $warmupFailures, 'source_unchanged' => $unchanged,
            'scope' => 'Only the measured caller and synthetic RTT mechanism; no realistic TCP/TLS or whole-app capacity qualification.',
            'workloads' => $summaries, 'raw_jsonl' => $this->outputPath];
        $this->record($summary);
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return $summary['valid'] ? 0 : 1;
    }
}

try {
    $options = getopt('', ['rust-worker:', 'output:', 'pairs:', 'warmups:', 'seed:', 'timeout:', 'help']);
    if (isset($options['help'])) {
        fwrite(STDOUT, "Usage: php tests/Fixtures/benchmark-bulk-verification.php --rust-worker=/absolute/release/binary [--pairs=10] [--warmups=3] [--seed=20261020] [--timeout=15] [--output=/tmp/unique.jsonl]\nUse --pairs=1000 only after the screen passes. STAT16 is separate; no TCP/TLS adoption qualification.\n");
        exit(0);
    }
    foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill', 'proc_open', 'memory_reset_peak_usage'] as $function) {
        if (! function_exists($function)) {
            throw new RuntimeException('Required host function unavailable: '.$function);
        }
    }
    if (! in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
        throw new RuntimeException('Use a macOS or Linux host.');
    }
    $integer = static function (string $key, int $default, int $minimum, int $maximum) use ($options): int {
        $value = $options[$key] ?? (string) $default;
        if (! is_string($value) || ! ctype_digit($value) || (float) $value < $minimum || (float) $value > $maximum) {
            throw new RuntimeException('--'.$key.' must be between '.$minimum.' and '.$maximum.'.');
        }

        return (int) $value;
    };
    $binary = isset($options['rust-worker']) && is_string($options['rust-worker']) ? realpath($options['rust-worker']) : false;
    if ($binary === false || ! is_file($binary) || ! is_readable($binary) || ! is_executable($binary)) {
        throw new RuntimeException('Supply --rust-worker with an existing executable release worker; fallback is forbidden.');
    }
    $output = $options['output'] ?? sys_get_temp_dir().'/nntmux-bulk-verification-'.gmdate('Ymd-His').'-'.getmypid().'.jsonl';
    if (! is_string($output) || ! str_starts_with($output, '/')) {
        throw new RuntimeException('--output must be an absolute, new JSONL file path.');
    }
    $benchmark = new BulkVerificationBenchmark(dirname(__DIR__, 2), $binary, $integer('pairs', 10, 1, 10000),
        $integer('warmups', 3, 1, 100), $integer('seed', 20261020, 0, 2147483647), $integer('timeout', 15, 1, 120), $output, $argv ?? []);
    exit($benchmark->run());
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    exit(1);
}
