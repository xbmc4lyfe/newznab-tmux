<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\TvProcessing\Pipes\AbstractTvProviderPipe;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Pipes\ParseInfoPipe;
use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Services\TvProcessing\Pipes\TraktPipe;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Matches TV releases to shows and episodes by passing each one through the
 * provider pipes (parse → local DB → TVDB → TVMaze → TMDB → Trakt) and
 * persisting the outcome with TvLookupOutcomeWriter.
 */
class TvProcessingPipeline
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_pipeline_classify_status(const char *status, size_t len);
        ssize_t nntmux_tv_pipeline_format_summary_header(int64_t processed, int64_t matched, int64_t episode_missing, int64_t not_found, int64_t deferred, double duration, char *out, size_t cap);
        ssize_t nntmux_tv_pipeline_format_provider_summary(const char *ser, size_t ser_len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvPipelineAndIgdbDataNodeParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 2.21x, p99 2.06x; uncommon mean 2.08x, p99 1.81x); see RustTvCandidatePipelineTvMazeTvdbHydrationW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w503_tv_pipeline_result_tally_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$useW50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 50 Rust path forced on or off, then restores the previous setting. */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW50RustFfi;
        self::$useW50RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW50RustFfi = $previous;
        }
    }

    /**
     * Tallies a batch of processRelease() results as updateStats() does. Four values per result:
     * the bucket (0 matched, 1 episode missing, 2 not found, 3 deferred; see
     * classifyResultStatusPhp()), the provider slot (first-seen index among matched providers, a
     * missing or null provider reading as 'unknown'; -1 unless matched), the FNV-1a 32 key of the
     * normalized title (lowercase alphanumeric runs joined by single spaces) and flags (1 video_id
     * > 0, 2 episode_id > 0, 4 season pack). Then the processed count, the four bucket counts, the
     * provider count, the distinct normalized matched-title count and the per-slot match counts.
     *
     * @param  list<array<string, mixed>>  $results  status, provider, title, video_id, episode_id
     * @return list<int>
     */
    public static function w50ResultTallyBatch(array $results): array
    {
        return self::usesW50RustFfi() ? self::w50ResultTallyBatchNative($results) : self::w50ResultTallyBatchPhp($results);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w50ResultTallyBatchNative(array $results): array
    {
        $count = \count($results);
        $columns = self::w50Columns($results, ['status' => '', 'provider' => null, 'title' => '', 'video_id' => 0, 'episode_id' => 0]);
        $nullProviders = array_keys($columns['provider'], null, true);
        $blob = implode("\0", $columns['status'])."\0".implode("\0", $columns['provider'])."\0".implode("\0", $columns['title']);
        $ints = pack('q*', \count($nullProviders), ...$nullProviders).pack('q*', ...$columns['video_id']).pack('q*', ...$columns['episode_id']);

        return self::w50Invoke($count === 0 ? '' : $blob, $ints, $count)
            ?? self::w50ResultTallyBatchPhp($results);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w50ResultTallyBatchPhp(array $results): array
    {
        $out = [];
        $buckets = ['matched' => 0, 'episode_missing' => 0, 'not_found' => 0, 'deferred' => 0];
        $codes = ['matched' => 0, 'episode_missing' => 1, 'not_found' => 2, 'deferred' => 3];
        $slots = [];
        $slotCounts = [];
        $titles = [];
        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? '');
            $bucket = self::classifyResultStatusPhp($status);
            $buckets[$bucket]++;
            $title = implode(' ', preg_split('/[^a-z0-9]+/', strtolower((string) ($result['title'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $slot = -1;
            if ($bucket === 'matched') {
                $provider = (string) ($result['provider'] ?? 'unknown');
                if (! isset($slots[$provider])) {
                    $slots[$provider] = \count($slotCounts);
                    $slotCounts[] = 0;
                }
                $slot = $slots[$provider];
                $slotCounts[$slot]++;
                $titles[$title] = true;
            }
            array_push(
                $out,
                $codes[$bucket],
                $slot,
                (int) hexdec(hash('fnv1a32', $title)),
                ((int) ($result['video_id'] ?? 0) > 0 ? 1 : 0) | ((int) ($result['episode_id'] ?? 0) > 0 ? 2 : 0)
                    | ($status === TvProcessingResult::STATUS_SEASON_PACK ? 4 : 0),
            );
        }
        array_push($out, \count($results), ...array_values($buckets));
        array_push($out, \count($slotCounts), \count($titles), ...$slotCounts);

        return $out;
    }

    /**
     * One column per key of $defaults. Rows missing a key read its default; present nulls stay
     * null, which pack() and implode() read as 0 and '' like the defaults they replace.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $defaults
     * @return array<string, list<mixed>>
     */
    private static function w50Columns(array $rows, array $defaults): array
    {
        $count = \count($rows);
        $columns = [];
        foreach ($defaults as $key => $default) {
            $column = array_column($rows, $key);
            if (\count($column) !== $count) {
                $filled = [];
                foreach ($rows as $row) {
                    $filled[] = $row + $defaults;
                }
                $columns = [];
                foreach ($defaults as $name => $unused) {
                    $columns[$name] = array_column($filled, $name);
                }

                return $columns;
            }
            $columns[$key] = $column;
        }

        return $columns;
    }

    /**
     * Calls the Wave 50 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing, the call declines (for example a string holding a NUL byte) or the
     * output is inconsistent, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w50Invoke(string $blob, string $ints, int $count): ?array
    {
        self::$w50Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w50', self::W50_FFI_CDEF) ?? false;
        $ffi = self::$w50Ffi;
        if ($ffi === false) {
            return null;
        }
        $base = $count * 4 + 7;
        $maxWords = $base + $count;
        try {
            if (self::$w50Out === null || self::$w50OutCap < $maxWords) {
                self::$w50OutCap = max(1024, $maxWords * 2);
                self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w503_tv_pipeline_result_tally_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w50Out, self::$w50OutCap);
            if (! \is_int($written) || $written < $base || $written > $maxWords || $written !== $base + (int) self::$w50Out[$count * 4 + 5]) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w50Out, $written * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @var Collection<int, AbstractTvProviderPipe>
     */
    protected Collection $pipes;

    protected bool $echoOutput;

    protected TvLookupOutcomeWriter $writer;

    /**
     * @var array{processed: int, matched: int, episode_missing: int, not_found: int, deferred: int, duration: float, providers: array<string, int>}
     */
    protected array $stats;

    /**
     * @param  iterable<AbstractTvProviderPipe>  $pipes
     */
    public function __construct(iterable $pipes = [], bool $echoOutput = true, ?TvLookupOutcomeWriter $writer = null)
    {
        $this->pipes = collect($pipes)
            ->sortBy(fn (AbstractTvProviderPipe $p) => $p->getPriority())
            ->values();

        $this->echoOutput = $echoOutput;
        $this->writer = $writer ?? new TvLookupOutcomeWriter;
        $this->resetStats();
    }

    /**
     * Add a provider pipe to the pipeline.
     */
    public function addPipe(AbstractTvProviderPipe $pipe): self
    {
        $this->pipes->push($pipe);
        $this->pipes = $this->pipes->sortBy(fn (AbstractTvProviderPipe $p) => $p->getPriority())->values();

        return $this;
    }

    public function setEchoOutput(bool $echoOutput): self
    {
        $this->echoOutput = $echoOutput;

        return $this;
    }

    /**
     * Shared memo for a batch of releases passed to processRelease().
     */
    public function newRunState(): TvProcessingRunState
    {
        return new TvProcessingRunState;
    }

    /**
     * Process a single release through the pipeline and persist the outcome.
     *
     * @param  array<string, mixed>|object  $release  Release data
     * @param  bool  $debug  Whether to include debug information
     * @return array<string, mixed> Processing result
     */
    public function processRelease(array|object $release, bool $debug = false, ?TvProcessingRunState $runState = null): array
    {
        $context = TvReleaseContext::fromRelease($release);
        $passable = new TvProcessingPassable($context, $runState ?? $this->newRunState(), $debug);

        foreach ($this->pipes as $pipe) {
            $pipe->setEchoOutput($this->echoOutput);
        }

        try {
            app(Pipeline::class)
                ->send($passable)
                ->through($this->pipes->all())
                ->thenReturn();
        } catch (Throwable $e) {
            // One broken release must not abort the batch; the attempt cap stops it looping forever.
            Log::error('TV processing failed for release', [
                'release_id' => $context->releaseId,
                'searchname' => $context->searchName,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            $passable->markTransient('pipeline');
        }

        $this->writer->record($context, $passable->finalResult());

        return $passable->toArray();
    }

    /**
     * Process all TV releases due for a lookup.
     *
     * @param  string  $groupID  Group ID to process
     * @param  string  $guidChar  GUID character to process
     * @param  int|string|null  $processTV  0 = skip, 1 = all, 2 = renamed only, '' = use the configured lookup mode
     */
    public function process(string $groupID = '', string $guidChar = '', int|string|null $processTV = ''): void
    {
        $configuredMode = (int) app(ConfigurationProvider::class)->metadata()->tvLookup->value;
        $mode = is_numeric($processTV) ? (int) $processTV : $configuredMode;
        if ($mode <= 0) {
            return;
        }

        // The configured "renamed only" mode applies even when a caller passes 1.
        $renamedOnly = $mode === 2 || $configuredMode === 2;

        $releases = $this->getTvReleases($groupID, $guidChar, $renamedOnly);
        if ($releases->isEmpty()) {
            if ($this->echoOutput) {
                cli()->header('No TV releases to process.');
            }

            return;
        }

        if ($this->echoOutput) {
            cli()->header('Processing '.$releases->count().' TV release(s).');
        }

        $this->processBatch($releases);
    }

    /**
     * Process a set of releases with one shared run state, then show the summary.
     *
     * @param  iterable<array<string, mixed>|object>  $releases
     */
    public function processBatch(iterable $releases): void
    {
        $this->resetStats();
        $startTime = microtime(true);
        $runState = $this->newRunState();

        foreach ($releases as $release) {
            $this->updateStats($this->processRelease($release, false, $runState));
        }

        $this->stats['duration'] = microtime(true) - $startTime;

        $this->displaySummary();
    }

    /**
     * @return Collection<int, Release>
     */
    protected function getTvReleases(string $groupID, string $guidChar, bool $renamedOnly): Collection
    {
        $qry = Release::query()
            ->select(['id', 'guid', 'leftguid', 'groups_id', 'searchname', 'size', 'categories_id', 'videos_id', 'tv_episodes_id', 'postdate'])
            ->whereRaw(TvProcessingCandidateQuery::workCondition($renamedOnly ? 2 : 1))
            ->orderByDesc('postdate')
            ->limit($this->batchSize());

        if ($groupID !== '') {
            $qry->where('groups_id', $groupID);
        }

        if ($guidChar !== '') {
            $qry->where('leftguid', $guidChar);
        }

        return $qry->get();
    }

    /** Releases per worker run: the configured max_tv_processed, default 75. */
    protected function batchSize(): int
    {
        $maxTvProcessed = (int) app(ConfigurationProvider::class)->metadata()->maxTvProcessed;

        return $maxTvProcessed > 0 ? $maxTvProcessed : 75;
    }

    /**
     * @return Collection<int, AbstractTvProviderPipe>
     */
    public function getPipes(): Collection
    {
        return $this->pipes;
    }

    /**
     * @return array{processed: int, matched: int, episode_missing: int, not_found: int, deferred: int, duration: float, providers: array<string, int>}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    protected function resetStats(): void
    {
        $this->stats = [
            'processed' => 0,
            'matched' => 0,
            'episode_missing' => 0,
            'not_found' => 0,
            'deferred' => 0,
            'duration' => 0.0,
            'providers' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function updateStats(array $result): void
    {
        $this->stats['processed']++;

        $rawStatus = $result['status'] ?? '';
        $status = \is_string($rawStatus) ? $rawStatus : (string) $rawStatus;
        $bucket = self::classifyResultStatus($status);
        $this->stats[$bucket]++;

        if ($bucket === 'matched') {
            $provider = (string) ($result['provider'] ?? 'unknown');
            $this->stats['providers'][$provider] = ($this->stats['providers'][$provider] ?? 0) + 1;
        }
    }

    public static function classifyResultStatus(string $status): string
    {
        return self::usesRustFfi()
            ? self::classifyResultStatusNative($status)
            : self::classifyResultStatusPhp($status);
    }

    public static function classifyResultStatusNative(string $status): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::classifyResultStatusPhp($status);
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_tv_pipeline_classify_status($status, \strlen($status));
        } catch (Throwable) {
            return self::classifyResultStatusPhp($status);
        }

        return match ($code) {
            0 => 'matched',
            1 => 'episode_missing',
            2 => 'deferred',
            3 => 'not_found',
            default => self::classifyResultStatusPhp($status),
        };
    }

    public static function classifyResultStatusPhp(string $status): string
    {
        return match ($status) {
            TvProcessingResult::STATUS_MATCHED, TvProcessingResult::STATUS_SEASON_PACK => 'matched',
            TvProcessingResult::STATUS_EPISODE_MISSING => 'episode_missing',
            TvProcessingResult::STATUS_TRANSIENT => 'deferred',
            default => 'not_found',
        };
    }

    protected function displaySummary(): void
    {
        if (! $this->echoOutput) {
            return;
        }

        cli()->header(self::formatSummaryHeader($this->stats));

        $providerSummary = self::formatProviderSummary($this->stats['providers']);
        if ($providerSummary !== null) {
            cli()->primary($providerSummary);
        }
    }

    /**
     * @param  array{processed: int, matched: int, episode_missing: int, not_found: int, deferred: int, duration: float, providers?: array<string, int>}  $stats
     */
    public static function formatSummaryHeader(array $stats): string
    {
        return self::usesRustFfi()
            ? self::formatSummaryHeaderNative($stats)
            : self::formatSummaryHeaderPhp($stats);
    }

    /**
     * @param  array{processed: int, matched: int, episode_missing: int, not_found: int, deferred: int, duration: float, providers?: array<string, int>}  $stats
     */
    public static function formatSummaryHeaderNative(array $stats): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::formatSummaryHeaderPhp($stats);
        }
        $duration = (float) $stats['duration'];
        if (! is_finite($duration) || abs($duration) > 1e12) {
            return self::formatSummaryHeaderPhp($stats);
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
        if ($buffer === null) {
            return self::formatSummaryHeaderPhp($stats);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_pipeline_format_summary_header(
                (int) $stats['processed'],
                (int) $stats['matched'],
                (int) $stats['episode_missing'],
                (int) $stats['not_found'],
                (int) $stats['deferred'],
                $duration,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatSummaryHeaderPhp($stats);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_pipeline_format_summary_header(
                    (int) $stats['processed'],
                    (int) $stats['matched'],
                    (int) $stats['episode_missing'],
                    (int) $stats['not_found'],
                    (int) $stats['deferred'],
                    $duration,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::formatSummaryHeaderPhp($stats);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::formatSummaryHeaderPhp($stats);
        }
    }

    /**
     * @param  array{processed: int, matched: int, episode_missing: int, not_found: int, deferred: int, duration: float, providers?: array<string, int>}  $stats
     */
    public static function formatSummaryHeaderPhp(array $stats): string
    {
        return sprintf(
            'TV processing complete: %d processed, %d matched, %d episode missing, %d not found, %d deferred (%.2fs)',
            $stats['processed'],
            $stats['matched'],
            $stats['episode_missing'],
            $stats['not_found'],
            $stats['deferred'],
            $stats['duration']
        );
    }

    /**
     * @param  array<string, int>  $providers
     */
    public static function formatProviderSummary(array $providers): ?string
    {
        return self::usesRustFfi()
            ? self::formatProviderSummaryNative($providers)
            : self::formatProviderSummaryPhp($providers);
    }

    /**
     * @param  array<string, int>  $providers
     */
    public static function formatProviderSummaryNative(array $providers): ?string
    {
        if ($providers === []) {
            return null;
        }
        if (($ffi = self::ffi()) === null) {
            return self::formatProviderSummaryPhp($providers);
        }
        try {
            $ser = serialize($providers);
            $len = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 64), $capacity);
            if ($buffer === null) {
                return self::formatProviderSummaryPhp($providers);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_pipeline_format_provider_summary($ser, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatProviderSummaryPhp($providers);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_pipeline_format_provider_summary($ser, $len, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::formatProviderSummaryPhp($providers);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::formatProviderSummaryPhp($providers);
        }
    }

    /**
     * @param  array<string, int>  $providers
     */
    public static function formatProviderSummaryPhp(array $providers): ?string
    {
        if (empty($providers)) {
            return null;
        }

        $providerSummary = [];
        foreach ($providers as $provider => $count) {
            $providerSummary[] = "$provider: $count";
        }

        return 'Matches by provider: '.implode(', ', $providerSummary);
    }

    /**
     * Create a default pipeline with all standard providers.
     */
    public static function createDefault(bool $echoOutput = true): self
    {
        return new self([
            new ParseInfoPipe,
            new LocalDbPipe,
            new TvdbPipe,
            new TvMazePipe,
            new TmdbPipe,
            new TraktPipe,
        ], $echoOutput);
    }
}
