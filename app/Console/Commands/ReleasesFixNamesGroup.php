<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Par2RenameOutcome;
use App\Exceptions\IncompletePredbMatchException;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReleasesFixNamesGroup extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w371_fix_names_reset_verify(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustFixReleaseNamesResetVerifyCommandParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int RUST_MAX_INPUT = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private const array GROUP_RUN_TYPES = ['invalid', 'standard', 'predbft'];

    private const array GROUP_RUN_ERRORS = [null, 'invalid_type', 'missing_guid_char'];

    private const array PREDB_OUTCOMES = [1 => 'matched', 2 => 'exhausted', 3 => 'decrement'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'releases:fix-names-group
                            {type : Type of fix (standard|predbft)}
                            {--guid-char= : GUID character to process (for standard type)}
                            {--limit=1000 : Maximum releases to process}
                            {--thread=1 : Worker number (for predbft type)}
                            {--workers=1 : Total workers (for predbft type)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix release names using various methods (group-based processing)';

    private NameFixingService $nameFixingService;

    private NameFixingQueryService $queries;

    private int $checked = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $maxPerRun = (int) $this->option('limit');

        $this->nameFixingService = app(NameFixingService::class);
        $this->queries = app(NameFixingQueryService::class);

        switch ($type) {
            case 'standard':
                return $this->processStandard($maxPerRun);

            case 'predbft':
                return $this->processPredbFulltext($maxPerRun);

            default:
                $this->error("Invalid type: {$type}. Use 'standard' or 'predbft'");

                return Command::FAILURE;
        }
    }

    /**
     * Process standard name fixing
     */
    protected function processStandard(int $maxPerRun): int
    {
        $guidChar = $this->option('guid-char');

        if ($guidChar === null) {
            $this->error('--guid-char is required for standard type');

            return Command::FAILURE;
        }

        $this->info("Processing releases with GUID starting with: {$guidChar}");
        $this->info("Maximum per run: {$maxPerRun}");

        $nntp = null;
        $nzbcontents = null;
        $connectionAttempted = false;
        $par2Processor = function (object $release) use (&$connectionAttempted, &$nntp, &$nzbcontents): Par2RenameOutcome {
            if (! $connectionAttempted) {
                $connectionAttempted = true;
                $nntp = app(NNTPService::class);
                $compressedHeaders = config('nntmux_nntp.compressed_headers');
                $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
                    ? $nntp->doConnect($compressedHeaders, true)
                    : $nntp->doConnect();

                if ($connectResult !== true) {
                    $errorMessage = 'Unable to connect to usenet for PAR2 processing';
                    if (NNTPService::isError($connectResult)) {
                        $errorMessage .= ' Error: '.$connectResult->getMessage();
                    }
                    $this->warn($errorMessage);
                } else {
                    $nzbcontents = app(NzbContentsService::class);
                    $nzbcontents->setNntp($nntp);
                    $nzbcontents->setNfo(app(NfoService::class));
                    $nzbcontents->setEchoOutput(false);
                }
            }

            if ($nzbcontents === null) {
                return Par2RenameOutcome::Retryable;
            }

            return $nzbcontents->checkPar2Result(
                $release->guid,
                $release->releases_id,
                $release->groups_id,
                1,
                1
            );
        };

        $stats = $this->nameFixingService->processStandardBatch($guidChar, $maxPerRun, true, $par2Processor);
        $this->checked += $stats['checked'];

        if ($stats['checked'] === 0) {
            $this->info('No releases to process');

            return Command::SUCCESS;
        }

        $this->info("Processed {$stats['checked']} releases");
        $this->info("Fixed {$stats['fixed']} release names");

        return Command::SUCCESS;
    }

    /**
     * Process PreDB fulltext matching
     */
    protected function processPredbFulltext(int $maxPerRun): int
    {
        $thread = (int) $this->option('thread');
        $workers = (int) $this->option('workers');

        $this->info('Processing PreDB fulltext matching');
        $this->info("Worker: {$thread}/{$workers}, Limit: {$maxPerRun}");

        try {
            $pres = $this->queries->reservePredbBatch($thread, $workers, $maxPerRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($pres === []) {
            $this->info('No PreDB entries to process');

            return Command::SUCCESS;
        }

        $this->info('Found '.count($pres).' PreDB entries to process');
        $bar = $this->output->createProgressBar(count($pres));
        $bar->start();

        $incomplete = 0;
        foreach ($pres as $pre) {
            $searched = 0;

            try {
                $outcome = $this->nameFixingService->matchPredbFulltextWithCompletion($pre);
                if ($outcome['retry']) {
                    throw new IncompletePredbMatchException('PreDB fulltext matching is incomplete: current release evidence could not be completely confirmed.');
                }
                $ftmatched = $outcome['updated'];
            } catch (IncompletePredbMatchException $exception) {
                $this->newLine();
                $this->warn('PreDB '.(int) $pre->predb_id.': '.$exception->getMessage());
                $incomplete++;
                $this->checked++;
                $bar->advance();

                continue;
            } catch (RuntimeException $exception) {
                $bar->finish();
                $this->newLine(2);
                $this->error($exception->getMessage());

                return Command::FAILURE;
            }

            if ($outcome['matched']) {
                $searched = 1;
            } elseif ($ftmatched < 0) {
                $searched = -6;
            } else {
                $searched = (int) $pre->searched - 1;
            }

            $completionPredicate = $searched === 1 ? '' : ' AND searched = 0';
            DB::update('UPDATE predb SET searched = ? WHERE id = ?'.$completionPredicate, [$searched, $pre->predb_id]);
            $this->checked++;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Processed {$this->checked} PreDB entries");
        if ($incomplete > 0) {
            $this->warn("{$incomplete} PreDB entries remain unsearched after incomplete matching.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Normalizes a `releases:fix-names-group` invocation: `standard` requires a GUID character
     * (a single hex digit is `guid_valid`) and `predbft` reports whether the worker number is
     * within `1..workers`. Values are reported as given; nothing is clamped.
     *
     * @return array{type: string, valid: bool, error: string|null, guid_char: string|null, guid_valid: bool, limit: int, limit_positive: bool, thread: int, workers: int, thread_in_range: bool}
     */
    public static function normalizeGroupRunPlan(string $type, ?string $guidChar, int $limit, int $thread = 1, int $workers = 1): array
    {
        return (self::usesRustFfi() ? self::normalizeGroupRunPlanNative($type, $guidChar, $limit, $thread, $workers) : null)
            ?? self::normalizeGroupRunPlanPhp($type, $guidChar, $limit, $thread, $workers);
    }

    /**
     * @return array{type: string, valid: bool, error: string|null, guid_char: string|null, guid_valid: bool, limit: int, limit_positive: bool, thread: int, workers: int, thread_in_range: bool}
     */
    private static function normalizeGroupRunPlanPhp(string $type, ?string $guidChar, int $limit, int $thread, int $workers): array
    {
        [$kind, $error] = match ($type) {
            'standard' => ['standard', $guidChar === null ? 'missing_guid_char' : null],
            'predbft' => ['predbft', null],
            default => ['invalid', 'invalid_type'],
        };

        return [
            'type' => $kind,
            'valid' => $error === null,
            'error' => $error,
            'guid_char' => $guidChar,
            'guid_valid' => $guidChar !== null && strlen($guidChar) === 1 && ctype_xdigit($guidChar),
            'limit' => $limit,
            'limit_positive' => $limit > 0,
            'thread' => $thread,
            'workers' => $workers,
            'thread_in_range' => $thread >= 1 && $thread <= $workers,
        ];
    }

    /**
     * @return array{type: string, valid: bool, error: string|null, guid_char: string|null, guid_valid: bool, limit: int, limit_positive: bool, thread: int, workers: int, thread_in_range: bool}|null
     */
    private static function normalizeGroupRunPlanNative(string $type, ?string $guidChar, int $limit, int $thread, int $workers): ?array
    {
        $input = self::packRustString($type).self::packRustString($guidChar ?? '').pack('q3', $limit, $thread, $workers);
        $fields = self::unpackRustInts(self::callRustFfi(2, $guidChar === null ? 0 : 1, $input), 9);
        if ($fields === null) {
            return null;
        }
        [$kind, $valid, $error, $guidValid, $limitOut, $limitPositive, $threadOut, $workersOut, $inRange] = $fields;
        $kindName = self::GROUP_RUN_TYPES[$kind] ?? null;
        if ($kindName === null || ! array_key_exists($error, self::GROUP_RUN_ERRORS) || ($valid | $guidValid | $limitPositive | $inRange) & ~1) {
            return null;
        }

        return [
            'type' => $kindName,
            'valid' => $valid === 1,
            'error' => self::GROUP_RUN_ERRORS[$error],
            'guid_char' => $guidChar,
            'guid_valid' => $guidValid === 1,
            'limit' => $limitOut,
            'limit_positive' => $limitPositive === 1,
            'thread' => $threadOut,
            'workers' => $workersOut,
            'thread_in_range' => $inRange === 1,
        ];
    }

    /**
     * Plans the `predb.searched` update after one fulltext match: a match marks it searched (1),
     * a negative match count exhausts it (-6) and otherwise it is decremented (saturating at
     * PHP_INT_MIN). Any value other than 1 is only written while the row is still unsearched.
     *
     * @return array{searched: int, outcome: string, requires_unsearched: bool}
     */
    public static function planPredbSearchedUpdate(bool $matched, int $ftmatched, int $searched): array
    {
        return (self::usesRustFfi() ? self::planPredbSearchedUpdateNative($matched, $ftmatched, $searched) : null)
            ?? self::planPredbSearchedUpdatePhp($matched, $ftmatched, $searched);
    }

    /**
     * @return array{searched: int, outcome: string, requires_unsearched: bool}
     */
    private static function planPredbSearchedUpdatePhp(bool $matched, int $ftmatched, int $searched): array
    {
        [$next, $outcome] = match (true) {
            $matched => [1, 'matched'],
            $ftmatched < 0 => [-6, 'exhausted'],
            default => [$searched === PHP_INT_MIN ? PHP_INT_MIN : $searched - 1, 'decrement'],
        };

        return ['searched' => $next, 'outcome' => $outcome, 'requires_unsearched' => $next !== 1];
    }

    /**
     * @return array{searched: int, outcome: string, requires_unsearched: bool}|null
     */
    private static function planPredbSearchedUpdateNative(bool $matched, int $ftmatched, int $searched): ?array
    {
        $fields = self::unpackRustInts(self::callRustFfi(3, $matched ? 1 : 0, pack('q2', $ftmatched, $searched)), 3);
        if ($fields === null) {
            return null;
        }
        [$next, $outcome, $requiresUnsearched] = $fields;
        $outcomeName = self::PREDB_OUTCOMES[$outcome] ?? null;
        if ($outcomeName === null || $requiresUnsearched & ~1) {
            return null;
        }

        return ['searched' => $next, 'outcome' => $outcomeName, 'requires_unsearched' => $requiresUnsearched === 1];
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    private static function packRustString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function unpackRustInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 37.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function callRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w371', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
