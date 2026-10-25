<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Par2RenameOutcome;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NameFixing\NameFixingService;
use App\Services\NNTP\NNTPService;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Support\RustPipelineLibrary;
use Closure;
use dariusiii\rarinfo\Par2Info;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Orchestrates post-processing of releases.
 *
 * This service coordinates various post-processing operations including:
 * - NFO processing
 * - Movie/TV/Anime lookups
 * - Music/Books/Games/Console processing
 * - Additional processing (RAR/ZIP contents, samples, etc.)
 */
final class PostProcessService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_pps_tv_lookup(int32_t kind, int64_t int_value, const char *ptr, size_t len, int64_t default_value, int32_t has_default, int64_t *out_resolved);
        C;

    public const bool W466_RUST_FFI_DEFAULT = false;

    public const string W466_FFI_CDEF = <<<'C'
        int32_t nntmux_w466_tv_lookup_summary(const char *values, size_t len, size_t count, int64_t default_value, int64_t *out, size_t out_count);
        C;

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static ?bool $w466RustFfi = null;

    private static FFI|false|null $w466Ffi = null;

    private static ?CData $w466Summary = null;

    private static FFI|false|null $ffi = null;

    private static ?CData $resolvedBuffer = null;

    private readonly bool $echoOutput;

    private readonly bool $alternateNNTP;

    private readonly bool $addPar2;

    private readonly NameFixingService $nameFixingService;

    private readonly Par2Info $par2Info;

    private readonly NfoService $nfo;

    private readonly Par2Processor $par2Processor;

    private readonly TvProcessingPipeline $tvPipeline;

    private readonly NfoProcessor $nfoProcessor;

    private readonly MoviesProcessor $moviesProcessor;

    private readonly MusicProcessor $musicProcessor;

    private readonly BooksProcessor $booksProcessor;

    private readonly ConsolesProcessor $consolesProcessor;

    private readonly GamesProcessor $gamesProcessor;

    private readonly AnimeProcessor $animeProcessor;

    public function __construct(
        ?NameFixingService $nameFixingService = null,
        ?Par2Info $par2Info = null,
        ?NfoService $nfo = null,
        ?Par2Processor $par2Processor = null,
        ?TvProcessingPipeline $tvPipeline = null,
        ?NfoProcessor $nfoProcessor = null,
        ?MoviesProcessor $moviesProcessor = null,
        ?MusicProcessor $musicProcessor = null,
        ?BooksProcessor $booksProcessor = null,
        ?ConsolesProcessor $consolesProcessor = null,
        ?GamesProcessor $gamesProcessor = null,
        ?AnimeProcessor $animeProcessor = null,
    ) {
        $this->echoOutput = (bool) config('nntmux.echocli');
        $this->addPar2 = (bool) config('nntmux_settings.add_par2');
        $this->alternateNNTP = (bool) config('nntmux_nntp.alternate_article_fallback');

        // Core dependencies
        $this->nameFixingService = $nameFixingService ?? new NameFixingService;
        $this->par2Info = $par2Info ?? new Par2Info;
        $this->nfo = $nfo ?? new NfoService;

        // Processors
        $this->par2Processor = $par2Processor ?? new Par2Processor(
            $this->nameFixingService,
            $this->par2Info,
            $this->addPar2,
            $this->alternateNNTP
        );
        $this->tvPipeline = $tvPipeline ?? TvProcessingPipeline::createDefault($this->echoOutput);
        $this->nfoProcessor = $nfoProcessor ?? new NfoProcessor($this->nfo);
        $this->moviesProcessor = $moviesProcessor ?? new MoviesProcessor($this->echoOutput);
        $this->musicProcessor = $musicProcessor ?? new MusicProcessor($this->echoOutput);
        $this->booksProcessor = $booksProcessor ?? new BooksProcessor($this->echoOutput);
        $this->consolesProcessor = $consolesProcessor ?? new ConsolesProcessor($this->echoOutput);
        $this->gamesProcessor = $gamesProcessor ?? new GamesProcessor($this->echoOutput);
        $this->animeProcessor = $animeProcessor ?? new AnimeProcessor($this->echoOutput);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
            self::$resolvedBuffer = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function useW466RustFfi(?bool $enabled): void
    {
        self::$w466RustFfi = $enabled;
    }

    public static function usesW466RustFfi(): bool
    {
        return self::$w466RustFfi ?? self::W466_RUST_FFI_DEFAULT;
    }

    public static function resetW466RustFfi(): void
    {
        self::$w466RustFfi = null;
        self::$w466Ffi = null;
        self::$w466Summary = null;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
        self::$resolvedBuffer = null;
    }

    /**
     * Resolve the TV lookup mode: a numeric `$processTV` wins, otherwise the configured
     * default (a closure is only invoked when needed); mode 2 means renamed releases only.
     *
     * @param  int|Closure(): int  $defaultLookupValue
     * @return array{resolved: int, renamedOnly: bool}
     */
    public static function resolveTvLookupMode(int|string|null $processTV, int|Closure $defaultLookupValue): array
    {
        return self::usesRustFfi()
            ? self::resolveTvLookupModeNative($processTV, $defaultLookupValue)
            : self::resolveTvLookupModePhp($processTV, $defaultLookupValue);
    }

    /**
     * @param  int|Closure(): int  $defaultLookupValue
     * @return array{resolved: int, renamedOnly: bool}
     */
    public static function resolveTvLookupModePhp(int|string|null $processTV, int|Closure $defaultLookupValue): array
    {
        $resolved = is_numeric($processTV)
            ? (int) $processTV
            : (int) ($defaultLookupValue instanceof Closure ? $defaultLookupValue() : $defaultLookupValue);

        return ['resolved' => $resolved, 'renamedOnly' => $resolved === 2];
    }

    /**
     * @param  int|Closure(): int  $defaultLookupValue
     * @return array{resolved: int, renamedOnly: bool}
     */
    public static function resolveTvLookupModeNative(int|string|null $processTV, int|Closure $defaultLookupValue): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolveTvLookupModePhp($processTV, $defaultLookupValue);
        }
        $kind = match (true) {
            $processTV === null => 0,
            \is_int($processTV) => 1,
            default => 2,
        };
        $intValue = \is_int($processTV) ? $processTV : 0;
        $text = \is_string($processTV) ? $processTV : '';
        $default = \is_int($defaultLookupValue) ? $defaultLookupValue : null;

        $flags = self::tvLookupNative($ffi, $kind, $intValue, $text, $default);
        if ($flags === -2 && $default === null && $defaultLookupValue instanceof Closure) {
            $default = (int) $defaultLookupValue();
            $flags = self::tvLookupNative($ffi, 0, 0, '', $default);
        }
        if ($flags === null || $flags < 0 || self::$resolvedBuffer === null) {
            return self::resolveTvLookupModePhp($processTV, $default ?? $defaultLookupValue);
        }
        /** @var int $resolved */
        $resolved = self::$resolvedBuffer[0];

        return ['resolved' => $resolved, 'renamedOnly' => ($flags & 1) !== 0];
    }

    /**
     * Resolve each TV lookup value like resolveTvLookupMode() with an integer default and
     * count the modes: 0 skips, 1 looks up all releases, 2 renamed releases only. The
     * variadic signature lets PHP enforce `int|string|null` on every value.
     *
     * @return array{skip: int, all: int, renamed: int, other: int}
     */
    public static function summarizeTvLookupModes(int $defaultLookupValue, int|string|null ...$values): array
    {
        return (self::usesW466RustFfi() ? self::tvLookupSummaryNative($defaultLookupValue, $values) : null)
            ?? self::tvLookupSummaryPhp($defaultLookupValue, $values);
    }

    /**
     * @return array{skip: int, all: int, renamed: int, other: int}
     */
    public static function summarizeTvLookupModesPhp(int $defaultLookupValue, int|string|null ...$values): array
    {
        return self::tvLookupSummaryPhp($defaultLookupValue, $values);
    }

    /**
     * Rust counts, or null when PHP must resolve the values (a NUL byte or an
     * unavailable library).
     *
     * @return array{skip: int, all: int, renamed: int, other: int}|null
     */
    public static function summarizeTvLookupModesNative(int $defaultLookupValue, int|string|null ...$values): ?array
    {
        return self::tvLookupSummaryNative($defaultLookupValue, $values);
    }

    /**
     * Run all post-processing types.
     *
     * @throws \Exception
     */
    public function processAll(NNTPService $nntp): void
    {
        $this->processAdditional();
        $this->processNfos($nntp);
        $this->processMovies();
        $this->processMusic();
        $this->processConsoles();
        $this->processGames();
        $this->processAnime();
        $this->processTv();
        $this->processBooks();
    }

    /**
     * Process anime releases using AniDB.
     *
     * @param  string  $groupID  Optional group ID filter
     * @param  string  $guidChar  Optional GUID character filter
     *
     * @throws \Exception
     */
    public function processAnime(string $groupID = '', string $guidChar = ''): void
    {
        $this->animeProcessor->process($groupID, $guidChar);
    }

    /**
     * Process book releases using ISBNdb, Google Books, iTunes, and Open Library.
     *
     * @param  string  $groupID  Optional group ID filter
     * @param  string  $guidChar  Optional GUID character filter
     *
     * @throws \Exception
     */
    public function processBooks(string $groupID = '', string $guidChar = ''): void
    {
        $this->booksProcessor->process($groupID, $guidChar);
    }

    /**
     * Process console game releases.
     *
     * @throws \Exception
     */
    public function processConsoles(string $groupID = '', string $guidChar = ''): void
    {
        $this->consolesProcessor->process($groupID, $guidChar);
    }

    /**
     * Process PC game releases.
     *
     * @throws \Exception
     */
    public function processGames(string $groupID = '', string $guidChar = ''): void
    {
        $this->gamesProcessor->process($groupID, $guidChar);
    }

    /**
     * Process movie releases using IMDB/TMDB.
     *
     * @param  string  $groupID  Optional group ID filter
     * @param  string  $guidChar  Optional GUID character filter
     * @param  int|string|null  $processMovies  Processing mode (0=skip, 1=all, 2=renamed only, ''=check setting)
     *
     * @throws \Exception
     */
    public function processMovies(
        string $groupID = '',
        string $guidChar = '',
        int|string|null $processMovies = ''
    ): void {
        $this->moviesProcessor->process($groupID, $guidChar, $processMovies);
    }

    /**
     * Process music releases.
     *
     * @throws \Exception
     */
    public function processMusic(string $groupID = '', string $guidChar = ''): void
    {
        $this->musicProcessor->process($groupID, $guidChar);
    }

    /**
     * Process NFO files for releases.
     *
     * @param  NNTPService  $nntp  NNTP connection for downloading NFOs
     * @param  string  $groupID  Optional group ID filter
     * @param  string  $guidChar  Optional GUID character filter
     *
     * @throws \Exception
     */
    public function processNfos(NNTPService $nntp, string $groupID = '', string $guidChar = ''): void
    {
        $this->nfoProcessor->process($nntp, $groupID, $guidChar);
    }

    /**
     * Process TV releases.
     *
     * @param  string  $groupID  Optional group ID filter
     * @param  string  $guidChar  Optional GUID character filter
     * @param  int|string|null  $processTV  Processing mode (0=skip, 1=all, 2=renamed only, ''=check setting)
     *
     * @throws \Exception
     */
    public function processTv(
        string $groupID = '',
        string $guidChar = '',
        int|string|null $processTV = '',
    ): void {
        if ($guidChar === '') {
            $forkingService = new ForkingService;
            $renamedOnly = self::resolveTvLookupMode(
                $processTV,
                static fn (): int => app(ConfigurationProvider::class)->metadata()->tvLookup->value,
            )['renamedOnly'];

            $forkingService->processTv($renamedOnly);
        } else {
            $this->tvPipeline->process($groupID, $guidChar, $processTV);
        }
    }

    /**
     * Process additional release data (RAR/ZIP contents, samples, media info).
     *
     * @param  int|string  $groupID  Optional group ID filter
     * @param  string  $guidChar  Optional GUID character filter
     *
     * @throws \Exception
     */
    public function processAdditional(int|string $groupID = '', string $guidChar = ''): void
    {
        $orchestrator = app(AdditionalProcessingOrchestrator::class);

        try {
            $orchestrator->start($groupID, $guidChar);
        } finally {
            $orchestrator->finish();
        }
    }

    /**
     * Attempt to get a better name from a PAR2 file and re-categorize.
     *
     * @param  string  $messageID  Message ID from NZB
     * @param  int  $relID  Release ID
     * @param  int  $groupID  Group ID
     * @param  NNTPService  $nntp  NNTP connection
     * @param  int  $show  Display mode (0=apply, 1=show only)
     *
     * @throws \Exception
     */
    public function parsePAR2(
        string $messageID,
        int $relID,
        int $groupID,
        NNTPService $nntp,
        int $show
    ): bool {
        return $this->par2Processor->parseFromMessage($messageID, $relID, $groupID, $nntp, $show);
    }

    /**
     * @param  null|callable(): bool  $canPersist
     * @param  null|callable(): bool  $onRead
     */
    public function parsePAR2ForRename(string $messageID, int $relID, int $groupID, NNTPService $nntp, int $show, ?callable $canPersist = null, bool $expectedPar2 = false, ?callable $onRead = null): Par2RenameOutcome
    {
        return $this->par2Processor->parseForRename($messageID, $relID, $groupID, $nntp, $show, $canPersist, $expectedPar2, $onRead);
    }

    /**
     * @param  null|callable(): bool  $canPersist
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string, max_payload_bytes?: int}|null  $expectedSnapshot
     */
    public function applyPAR2InventoryForRename(Par2PacketAccumulator $inventory, int $relID, int $groupID, int $show, ?callable $canPersist = null, bool $allowTerminalMetadata = true, ?array $expectedSnapshot = null): Par2RenameOutcome
    {
        return $this->par2Processor->applyInventoryForRename($inventory, $relID, $groupID, $show, $canPersist, $allowTerminalMetadata, $expectedSnapshot);
    }

    /**
     * @param  array<array-key, int|string|null>  $values
     * @return array{skip: int, all: int, renamed: int, other: int}
     */
    private static function tvLookupSummaryPhp(int $defaultLookupValue, array $values): array
    {
        $summary = ['skip' => 0, 'all' => 0, 'renamed' => 0, 'other' => 0];
        foreach ($values as $value) {
            $resolved = is_numeric($value) ? (int) $value : $defaultLookupValue;
            $summary[match ($resolved) {
                0 => 'skip',
                1 => 'all',
                2 => 'renamed',
                default => 'other',
            }]++;
        }

        return $summary;
    }

    /**
     * @param  array<array-key, int|string|null>  $values
     * @return array{skip: int, all: int, renamed: int, other: int}|null
     */
    private static function tvLookupSummaryNative(int $defaultLookupValue, array $values): ?array
    {
        $ffi = self::$w466Ffi instanceof FFI ? self::$w466Ffi : self::w466Ffi();
        if ($ffi === null) {
            return null;
        }
        $count = \count($values);
        // implode() renders int and null exactly as is_numeric()/(int) read them.
        $joined = implode("\0", $values);
        if (substr_count($joined, "\0") !== max(0, $count - 1)) {
            return null;
        }

        try {
            $out = self::$w466Summary ??= $ffi->new('int64_t[4]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w466_tv_lookup_summary($joined, \strlen($joined), $count, $defaultLookupValue, $out, 4);
        } catch (Throwable) {
            return null;
        }

        return $status === 1 ? ['skip' => $out[0], 'all' => $out[1], 'renamed' => $out[2], 'other' => $out[3]] : null;
    }

    /** Flags from `nntmux_pps_tv_lookup`, or null when the call fails. */
    private static function tvLookupNative(FFI $ffi, int $kind, int $intValue, string $text, ?int $default): ?int
    {
        try {
            self::$resolvedBuffer ??= $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $flags = $ffi->nntmux_pps_tv_lookup($kind, $intValue, $text, \strlen($text), $default ?? 0, $default === null ? 0 : 1, self::$resolvedBuffer);
        } catch (Throwable) {
            return null;
        }

        return \is_int($flags) ? $flags : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function w466Ffi(): ?FFI
    {
        if (self::$w466Ffi === null) {
            self::$w466Ffi = RustPipelineLibrary::resolveDomain('additional', self::W466_FFI_CDEF) ?? false;
        }

        return self::$w466Ffi === false ? null : self::$w466Ffi;
    }
}
