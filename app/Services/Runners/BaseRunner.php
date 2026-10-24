<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

abstract class BaseRunner
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_runner_transform(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);
        double nntmux_runner_follow_up_deadline(double started_at, int64_t interval);
        C;

    /** No Slice 2 candidate met all four 1.5x thresholds; see RustTmuxRunnersAndDrainParityTest. */
    private static ?bool $useRustFfi = null;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('irc', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /** @param list<string> $fields */
    public static function runnerTransformNative(int $mode, array $fields): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $input = '';
            foreach ($fields as $field) {
                if (strlen($field) > 0xFFFFFFFF) {
                    return null;
                }
                $input .= pack('N', strlen($field)).$field;
            }
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) + 512), $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $length = (int) $ffi->nntmux_tmux_runner_transform($mode, $input, strlen($input), $scratch, $capacity);
            if ($length < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$length, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $length = (int) $ffi->nntmux_tmux_runner_transform($mode, $input, strlen($input), $scratch, $capacity);
            }

            return $length >= 0 && $length <= $capacity ? FFI::string($scratch, $length) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>|null
     */
    public static function runnerTransformListNative(int $mode, array $fields): ?array
    {
        $output = self::runnerTransformNative($mode, $fields);
        if ($output === null) {
            return null;
        }
        $fields = [];
        $offset = 0;
        $length = strlen($output);
        while ($offset < $length) {
            if ($length - $offset < 4) {
                return null;
            }
            $decoded = unpack('Nlength', $output, $offset);
            if ($decoded === false) {
                return null;
            }
            $size = (int) $decoded['length'];
            $offset += 4;
            if ($size > $length - $offset) {
                return null;
            }
            $fields[] = substr($output, $offset, $size);
            $offset += $size;
        }

        return $fields;
    }

    public static function followUpDeadlineNative(float $startedAt, int $interval): ?float
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            return (float) $ffi->nntmux_runner_follow_up_deadline($startedAt, $interval);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @var array<string|int, array{status: string, exit_code: ?int}> */
    protected array $workerOutcomes = [];

    private bool $cancellationRequested = false;

    private bool $drainRequested = false;

    private ?\Throwable $drainStateFailure = null;

    private float $lastDrainCheckAt = 0.0;

    protected function hasDrainRequest(bool $refresh = false): bool
    {
        if (! $this->drainRequested && ($refresh || microtime(true) - $this->lastDrainCheckAt >= 0.25)) {
            $this->lastDrainCheckAt = microtime(true);
            try {
                if (app()->bound('db') && app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                    $this->drainRequested = true;
                }
            } catch (\Throwable $exception) {
                $this->drainRequested = true;
                $this->drainStateFailure = $exception;
            }
        }

        return $this->drainRequested;
    }

    protected function workerStarting(string|int $key): void {}

    protected function workerCompleted(string|int $key, string $output): void {}

    protected function workerConcurrencyKey(string|int $key): string|int|null
    {
        return self::usesRustFfi() ? $this->workerConcurrencyKeyNative($key) : $this->workerConcurrencyKeyPhp($key);
    }

    public function workerConcurrencyKeyPhp(string|int $key): string|int|null
    {
        return null;
    }

    public function workerConcurrencyKeyNative(string|int $key): string|int|null
    {
        self::runnerTransformNative(13, [(string) $key]);

        return null;
    }

    /**
     * Another run of a worker that just succeeded, queued behind the batch while the batch's
     * first runs are still working. Follow-ups that have not started when the last first run
     * finishes are dropped, so they never extend the batch beyond its slowest worker's tail.
     *
     * @return array{command: string|list<string>, not_before: float}|null
     */
    protected function workerFollowUp(string|int $key, float $startedAt): ?array
    {
        return null;
    }

    /**
     * Resolve the configured worker timeout in seconds.
     */
    protected function concurrencyTimeout(): int
    {
        return (self::usesRustFfi() ? $this->concurrencyTimeoutNative() : null) ?? $this->concurrencyTimeoutPhp();
    }

    public function concurrencyTimeoutNative(): ?int
    {
        $value = self::runnerTransformNative(12, [(string) $this->concurrencyTimeoutPhp()]);

        return $value !== null ? (int) $value : null;
    }

    public function concurrencyTimeoutPhp(): int
    {
        $configured = config('nntmux.concurrency_timeout');

        return (int) ($configured ?? config('nntmux.multiprocessing_max_child_time', 1800));
    }

    protected function hasCancellationRequest(): bool
    {
        return $this->cancellationRequested;
    }

    /** @return list<string> */
    protected function buildDnrCommand(string $args): array
    {
        return (self::usesRustFfi() ? $this->buildDnrCommandNative($args) : null) ?? $this->buildDnrCommandPhp($args);
    }

    /** @return list<string>|null */
    public function buildDnrCommandNative(string $args): ?array
    {
        return self::runnerTransformListNative(9, [PHP_BINARY, base_path('artisan'), $args]);
    }

    /** @return list<string> */
    public function buildDnrCommandPhp(string $args): array
    {
        $parts = explode('  ', trim($args));
        $command = array_shift($parts);
        $worker = match ($command) {
            'backfill' => ['backfill:group', $parts[0] ?? '', $parts[1] ?? '1'],
            'backfill_all_quantity' => ['backfill:group', $parts[0] ?? '', '1', $parts[1] ?? ''],
            'backfill_all_quick' => ['backfill:group', $parts[0] ?? '', '1', '10000'],
            'get_range' => ['articles:get-range', $parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? '0', $parts[3] ?? '0'],
            'part_repair' => ['binaries:part-repair', $parts[0] ?? ''],
            'releases' => ['releases:process', ...$parts],
            'update_group_headers' => ['group:update-headers', $parts[0] ?? ''],
            'update_per_group' => ['group:update-all', $parts[0] ?? ''],
            'pp_additional' => ['postprocess:guid', 'additional', ...$parts],
            'pp_nfo' => ['postprocess:guid', 'nfo', ...$parts],
            'pp_movie' => ['postprocess:guid', 'movie', ...$parts],
            'pp_tv' => ['postprocess:guid', 'tv', ...$parts],
            default => throw new RuntimeException('Unrecognized multiprocessing command.'),
        };

        return [PHP_BINARY, base_path('artisan'), ...$worker];
    }

    /** @return list<string> */
    public function buildDnrCommandPublic(string $args): array
    {
        return $this->buildDnrCommand($args);
    }

    /** @param string|list<string> $command */
    protected function executeCommand(string|array $command): string
    {
        return $this->runWorkerPool([$command], 1, $this->concurrencyTimeout(), false)[0] ?? '';
    }

    protected function headerStart(string $workType, int $count, int $maxProcesses): void
    {
        if (config('nntmux.echocli')) {
            cli()->header(
                'Multi-processing started at '.now()->toRfc2822String().' for '.$workType.' with '.$count.
                ' job(s) to do using a max of '.max(1, $maxProcesses).' child process(es).'
            );
        }
    }

    protected function headerNone(): void
    {
        if (config('nntmux.echocli')) {
            cli()->header('No work to do!');
        }
    }

    /**
     * @param  array<string|int, string|list<string>>  $commands
     * @return array<string|int, string>
     */
    protected function runParallelCommands(array $commands, int $maxProcesses, ?int $timeout = null): array
    {
        return $this->runWorkerPool($commands, $maxProcesses, $timeout ?? $this->concurrencyTimeout(), false);
    }

    /**
     * @param  array<string|int, string|list<string>>  $commands
     * @param  callable(string|int, string): void  $onComplete
     */
    protected function runParallelCommandsWithCompletion(array $commands, int $maxProcesses, callable $onComplete): void
    {
        $this->runWorkerPool($commands, $maxProcesses, $this->concurrencyTimeout(), false, $onComplete);
    }

    /** @param array<string|int, string|list<string>> $commands */
    protected function runStreamingCommands(array $commands, int $maxProcesses, string $desc): void
    {
        $this->headerStart('postprocess: '.$desc, count($commands), max(1, $maxProcesses));
        $this->runWorkerPool($commands, $maxProcesses, $this->concurrencyTimeout(), true);
    }

    /** @param string|list<string> $command */
    private function workerProcess(string|array $command): Process
    {
        return is_array($command) ? new Process($command, base_path()) : Process::fromShellCommandline($command, base_path());
    }

    /**
     * @param  array<string|int, string|list<string>>  $commands
     * @param  callable(string|int, string): void|null  $onComplete
     * @return array<string|int, string>
     */
    private function runWorkerPool(array $commands, int $maxProcesses, int $timeout, bool $stream, ?callable $onComplete = null): array
    {
        $queue = $commands;
        $firstRuns = array_fill_keys(array_keys($commands), true);
        $followUps = [];
        $startedAt = [];
        $running = [];
        $results = [];
        $completionFailure = null;
        $this->workerOutcomes = [];
        $this->cancellationRequested = false;
        $handlers = [];
        $async = false;
        if (function_exists('pcntl_signal')) {
            $async = pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function (): void {
                    $this->cancellationRequested = true;
                });
            }
            $handlers[SIGUSR1] = pcntl_signal_get_handler(SIGUSR1);
            pcntl_signal(SIGUSR1, function (): void {
                $this->drainRequested = true;
            });
        }

        try {
            while ($queue !== [] || $running !== []) {
                if ($this->isCancellationRequested()) {
                    break;
                }
                if ($firstRuns === [] && $followUps !== []) {
                    $queue = array_diff_key($queue, $followUps);
                    $followUps = [];
                }
                if (($this->hasDrainRequest() || $completionFailure !== null) && $running === []) {
                    break;
                }
                while (! $this->isCancellationRequested() && $completionFailure === null && ! $this->hasDrainRequest(true) && count($running) < max(1, $maxProcesses) && $queue !== []) {
                    $key = null;
                    $busy = [];
                    foreach (array_keys($running) as $runningKey) {
                        $group = $this->workerConcurrencyKey($runningKey);
                        if ($group !== null) {
                            $busy[$group] = true;
                        }
                    }
                    $now = microtime(true);
                    foreach (array_keys($queue) as $queuedKey) {
                        if (isset($followUps[$queuedKey]) && ($followUps[$queuedKey] > $now || ! $this->firstRunsPending($firstRuns, $running))) {
                            continue;
                        }
                        $group = $this->workerConcurrencyKey($queuedKey);
                        if ($group === null || ! isset($busy[$group])) {
                            $key = $queuedKey;
                            break;
                        }
                    }
                    if ($key === null) {
                        break;
                    }
                    $process = $this->workerProcess($queue[$key]);
                    unset($queue[$key], $followUps[$key]);
                    $startedAt[$key] = microtime(true);
                    $process->setTimeout($timeout);
                    $running[$key] = $process;
                    try {
                        $this->workerStarting($key);
                        $process->start($stream ? static function (string $type, string $buffer): void {
                            echo $buffer;
                        } : null);
                    } catch (\Throwable) {
                        $this->recordWorkerOutcome($key, 'failure', null);
                        unset($running[$key], $firstRuns[$key]);
                    }
                }
                foreach ($running as $key => $process) {
                    $status = null;
                    try {
                        if ($process->isRunning()) {
                            if (! $this->hasDrainRequest()) {
                                $process->checkTimeout();
                            }

                            continue;
                        }
                        $status = $process->isSuccessful() ? 'success' : 'failure';
                    } catch (ProcessTimedOutException) {
                        $status = 'timeout';
                    }
                    $output = $process->getOutput();
                    if ($onComplete === null) {
                        $results[$key] = $output;
                    }
                    if (! $stream) {
                        echo $process->getErrorOutput();
                    }
                    $this->recordWorkerOutcome($key, $status, $process->getExitCode(), $this->outputTail($process));
                    unset($running[$key], $firstRuns[$key]);
                    if ($status === 'success') {
                        try {
                            $this->workerCompleted($key, $output);
                            if ($onComplete !== null) {
                                $onComplete($key, $output);
                            }
                            $followUp = $firstRuns !== [] && $completionFailure === null
                                && ! $this->isCancellationRequested() && ! $this->hasDrainRequest()
                                ? $this->workerFollowUp($key, $startedAt[$key]) : null;
                            if ($followUp !== null) {
                                $queue[$key] = $followUp['command'];
                                $followUps[$key] = $followUp['not_before'];
                            }
                        } catch (\Throwable $exception) {
                            $completionFailure ??= $exception;
                        }
                    }
                }
                usleep(50000);
            }
        } finally {
            foreach ($running as $process) {
                if ($process->isRunning()) {
                    try {
                        $process->signal(SIGTERM);
                    } catch (RuntimeException) {
                        // The owned worker may exit between the state query and signal.
                    }
                }
            }
            $deadline = microtime(true) + 3;
            do {
                $active = array_filter($running, static fn (Process $process): bool => $process->isRunning());
                if ($active === []) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            foreach ($running as $key => $process) {
                if ($process->isStarted()) {
                    $process->stop(0);
                }
                $this->recordWorkerOutcome($key, 'cancellation', $process->getExitCode());
            }
            $queuedOutcome = match (true) {
                $this->isCancellationRequested() => 'cancellation',
                $this->drainRequested => 'drained',
                $completionFailure !== null => 'failure',
                default => 'cancellation',
            };
            foreach (array_diff_key($queue, $followUps) as $key => $command) {
                $this->recordWorkerOutcome($key, $queuedOutcome, null);
            }
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($handlers !== []) {
                pcntl_async_signals($async);
            }
        }

        $failed = array_filter($this->workerOutcomes, static fn (array $outcome): bool => ! in_array($outcome['status'], ['success', 'drained'], true));
        $errors = [];
        if ($failed !== []) {
            $errors[] = 'Worker batch did not succeed: '.implode(', ', array_map(
                static fn (string|int $key, array $outcome): string => $key.' '.$outcome['status'].($outcome['status'] === 'timeout' ? ' exceeded the timeout' : '').' (exit '.($outcome['exit_code'] ?? 'none').')',
                array_keys($failed), array_values($failed),
            ));
        }

        if ($completionFailure !== null) {
            $errors[] = 'Worker completion failed: '.$completionFailure->getMessage();
        }
        if ($this->drainStateFailure !== null) {
            $errors[] = 'Unable to determine worker drain state: '.$this->drainStateFailure->getMessage();
        }
        if ($errors !== []) {
            throw new RuntimeException(implode('; ', $errors), 0, $completionFailure ?? $this->drainStateFailure);
        }

        return $results;
    }

    /**
     * Whether a first run is still queued or actually running. A start failure or an exit not yet
     * harvested by the completion loop can end the last one before the loop's own bookkeeping does.
     *
     * @param  array<string|int, true>  $firstRuns
     * @param  array<string|int, Process>  $running
     */
    private function firstRunsPending(array $firstRuns, array $running): bool
    {
        foreach (array_keys($firstRuns) as $key) {
            if (! isset($running[$key]) || $running[$key]->isRunning()) {
                return true;
            }
        }

        return false;
    }

    private function isCancellationRequested(): bool
    {
        return $this->cancellationRequested;
    }

    private function recordWorkerOutcome(string|int $key, string $status, ?int $exitCode, string $outputTail = ''): void
    {
        $this->workerOutcomes[$key] = ['status' => $status, 'exit_code' => $exitCode];
        if (! in_array($status, ['success', 'drained'], true)) {
            Log::error('Multiprocessing worker did not succeed', [
                'worker' => $key,
                'outcome' => $status,
                'exit_code' => $exitCode,
                ...($outputTail !== '' ? ['output_tail' => $outputTail] : []),
            ]);
        }
    }

    /**
     * The last lines a worker wrote, so a failed worker's reason reaches the log instead of
     * only the tmux pane. Bounded, and only read for workers that did not succeed.
     */
    private function outputTail(Process $process): string
    {
        return (self::usesRustFfi() ? $this->outputTailNative($process) : null) ?? $this->outputTailPhp($process);
    }

    public function outputTailNative(Process $process): ?string
    {
        if ($process->isSuccessful()) {
            return '';
        }
        if (strcasecmp(mb_internal_encoding(), 'UTF-8') !== 0) {
            return null;
        }

        return self::runnerTransformNative(10, ['0', $process->getErrorOutput(), $process->getOutput()]);
    }

    public function outputTailPhp(Process $process): string
    {
        if ($process->isSuccessful()) {
            return '';
        }

        $tail = static function (string $text): string {
            $text = trim(preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text);

            return mb_substr($text, -400);
        };
        $stderr = $tail($process->getErrorOutput());
        $stdout = $tail($process->getOutput());

        return trim(($stderr !== '' ? 'stderr: '.$stderr : '').($stdout !== '' ? "\nstdout: ".$stdout : ''));
    }
}
