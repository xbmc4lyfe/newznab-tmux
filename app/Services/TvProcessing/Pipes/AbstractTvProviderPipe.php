<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Base class for TV processing pipe handlers.
 *
 * Each pipe records what it found on the passable and hands it on; nothing
 * further runs once a release is resolved (episode matched, season pack or parse failure).
 */
abstract class AbstractTvProviderPipe
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_pipe_truncate_title(const char *title, size_t len, int64_t max_length);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvShowAdderAndMetadataParityTest). */
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

    /**
     * Wave 48.3 batch pipe execution status, confidence threshold and fallback routing digest.
     * Disabled: seven paired runs missed the 1.5x common p99 gate in one (min common mean 2.25x, p99 1.48x;
     * uncommon mean 2.38x, p99 1.89x); these ~12 microsecond batches are dominated by call overhead and host noise (see the W48 parity test).
     */
    public const bool W48_TV_PROVIDER_RUST_FFI_DEFAULT = false;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_tv_provider_routing_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Result statuses and their codes; any other status is code -1. */
    public const array W48_STATUS_CODES = [
        'matched' => 0,
        'season_pack' => 1,
        'episode_missing' => 2,
        'not_found' => 3,
        'parse_failed' => 4,
        'transient' => 5,
        'skipped' => 6,
        'pending' => 7,
    ];

    /** Lowercase provider weights (percent); other providers weigh W48_DEFAULT_WEIGHT. */
    public const array W48_PROVIDER_WEIGHTS = ['tvdb' => 95, 'tvmaze' => 90, 'tmdb' => 85, 'trakt' => 80, 'local db' => 75];

    public const int W48_DEFAULT_WEIGHT = 50;

    /** Weighted confidence a match or season pack needs to stop the pipeline. */
    public const int W48_CONFIDENCE_THRESHOLD = 60;

    public const int W48_MAX_RETRY_AFTER = 86400;

    private static ?bool $w48RustFfiOverride = null;

    private static FFI|false|null $w48RustFfiHandle = null;

    /** Null restores W48_TV_PROVIDER_RUST_FFI_DEFAULT. */
    public static function useW48TvProviderRustFfi(?bool $enable = true): void
    {
        self::$w48RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w48RustFfiHandle = null;
        }
    }

    public static function usesW48TvProviderRustFfi(): bool
    {
        return self::$w48RustFfiOverride ?? self::W48_TV_PROVIDER_RUST_FFI_DEFAULT;
    }

    /**
     * Four values per item: the status code (W48_STATUS_CODES, else -1), the route (0 stop,
     * 1 advance to the next pipe, 2 retry later, 3 fall back), the weighted confidence
     * (confidence clamped to [0, 100] times the provider weight, integer percent) and the
     * FNV-1a 32 digest of `lowercase provider \x1F status \x1F route`. A match or season pack
     * below W48_CONFIDENCE_THRESHOLD falls back; parse failures stop, transients retry after
     * retry_after clamped to [0, W48_MAX_RETRY_AFTER], the other known statuses advance and
     * unknown statuses fall back. Then the four route counts, the index of the best stopping
     * match (highest weighted confidence, then lowest priority, then first; -1 without one),
     * the max wait and the rolling batch digest `(d * 31 + digest) & 0xFFFFFFFF`. Missing keys
     * read as '' or 0.
     *
     * @param  list<array<string, mixed>>  $items  status, provider, confidence, priority, retry_after
     * @return list<int>
     */
    public static function w48RoutingBatch(array $items): array
    {
        return self::usesW48TvProviderRustFfi()
            ? self::w48RoutingBatchNative($items)
            : self::w48RoutingBatchPhp($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48RoutingBatchNative(array $items): array
    {
        $count = \count($items);
        $c = self::w48Columns($items, ['status' => '', 'provider' => '', 'confidence' => 0, 'priority' => 0, 'retry_after' => 0]);

        return self::w48Invoke(
            self::w48RustFfi(),
            'nntmux_w48_tv_provider_routing_batch',
            [$c['status'], $c['provider']],
            [$c['confidence'], $c['priority'], $c['retry_after']],
            $count,
            $count * 4 + 7,
        ) ?? self::w48RoutingBatchPhp($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48RoutingBatchPhp(array $items): array
    {
        $out = [];
        $routes = [0, 0, 0, 0];
        $best = -1;
        $bestEffective = 0;
        $bestPriority = 0;
        $maxWait = 0;
        $rolling = 0;
        $index = 0;
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            $provider = strtolower((string) ($item['provider'] ?? ''));
            $code = self::W48_STATUS_CODES[$status] ?? -1;
            $confidence = max(0, min(100, (int) ($item['confidence'] ?? 0)));
            $priority = (int) ($item['priority'] ?? 0);
            $effective = intdiv($confidence * (self::W48_PROVIDER_WEIGHTS[$provider] ?? self::W48_DEFAULT_WEIGHT), 100);
            $route = match ($code) {
                0, 1 => $effective >= self::W48_CONFIDENCE_THRESHOLD ? 0 : 3,
                4 => 0,
                5 => 2,
                2, 3, 6, 7 => 1,
                default => 3,
            };
            $wait = $code === 5 ? max(0, min(self::W48_MAX_RETRY_AFTER, (int) ($item['retry_after'] ?? 0))) : 0;
            $routes[$route]++;
            $maxWait = max($maxWait, $wait);
            if ($route === 0 && $code < 2
                && ($best < 0 || $effective > $bestEffective || ($effective === $bestEffective && $priority < $bestPriority))) {
                $best = $index;
                $bestEffective = $effective;
                $bestPriority = $priority;
            }
            $digest = self::w48Fnv($provider."\x1F".$status."\x1F".$route);
            $rolling = ($rolling * 31 + $digest) & 0xFFFFFFFF;
            array_push($out, $code, $route, $effective, $digest);
            $index++;
        }
        array_push($out, ...$routes);
        array_push($out, $best, $maxWait, $rolling);

        return $out;
    }

    /**
     * Item columns keyed like $defaults; a column with a missing key fills its defaults item by item.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int|string>  $defaults
     * @return array<string, list<mixed>>
     */
    protected static function w48Columns(array $items, array $defaults): array
    {
        $count = \count($items);
        $columns = [];
        foreach ($defaults as $key => $default) {
            $column = array_column($items, $key);
            if (\count($column) !== $count) {
                $column = [];
                foreach ($items as $item) {
                    $column[] = $item[$key] ?? $default;
                }
            }
            $columns[$key] = $column;
        }

        return $columns;
    }

    /**
     * Calls a Wave 48 batch export with NUL-joined string columns and packed `i64` columns
     * (null for a strings-only export). Null when the library is missing, a string holds a NUL
     * byte or the call declines, so the caller falls back to PHP.
     *
     * @param  list<list<mixed>>  $strings
     * @param  list<list<mixed>>|null  $ints
     * @param  list<int>  $header  values packed ahead of the integer columns
     * @return list<int>|null
     */
    protected static function w48Invoke(?FFI $ffi, string $symbol, array $strings, ?array $ints, int $count, int $values, array $header = []): ?array
    {
        if ($ffi === null) {
            return null;
        }
        $blob = $count === 0 ? '' : implode("\0", array_merge(...$strings));
        if (substr_count($blob, "\0") !== max(0, $count * \count($strings) - 1)) {
            return null;
        }
        try {
            $bytes = $values * 8;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $bytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            if ($ints === null) {
                $written = $ffi->$symbol($blob, \strlen($blob), $count, $buffer, $capacity);
            } else {
                $packed = pack('q*', ...$header, ...array_merge(...$ints));
                $written = $ffi->$symbol($blob, \strlen($blob), $packed, \strlen($packed), $count, $buffer, $capacity);
            }
            if ($written !== $bytes) {
                return null;
            }
            $unpacked = unpack('P*', FFI::string($buffer, $bytes));

            /** @var list<int>|null */
            return $unpacked === false ? null : array_values($unpacked);
        } catch (Throwable) {
            return null;
        }
    }

    /** PHP `implode(' ', preg_split('/[^a-z0-9]+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY))`. */
    protected static function w48Normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)), ' ');
    }

    protected static function w48Fnv(string $bytes): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('N', hash('fnv1a32', $bytes, true));

        return $digest[1];
    }

    private static function w48RustFfi(): ?FFI
    {
        if (self::$w48RustFfiHandle === null) {
            self::$w48RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w48', self::W48_FFI_CDEF) ?? false;
        }

        return self::$w48RustFfiHandle === false ? null : self::$w48RustFfiHandle;
    }

    /** Wave 56.3 paired benchmarks decide this default; see RustTvProcessingPipesProviderPassableResultW56ParityTest. */
    public const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_TV_PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w563_tv_pipe_gate_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the two per-name words of w56PipeGateBatch(). */
    public const int W56_PIPE_GATE_TRAILER = 10;

    public static function usesW56RustFfi(): bool
    {
        $override = self::w56RustFfiState();

        return \is_bool($override) ? $override : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 56 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 56.3 pipe execution gates. Two words per release name split into `[A-Za-z0-9]` tokens:
     * `flags | season << 8 | episode << 16 | airdate << 28` for the first reference token (flags
     * 2 `SxxEyy`, 4 `NxNN`, 8 a `YYYY MM DD` airdate with month 1-12 and day 1-31 as `Ymd`,
     * 16 an `Sxx` season pack; then 1 no tokens, 32 any reference and 64 a reference without
     * title tokens), and the FNV-1a 32 of the lowercase title tokens before it joined by spaces
     * `| min(length, 0xFFFFFFF) << 32`. Then the seven flag counts, the max season and episode and
     * the distinct non-empty title hashes.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w56PipeGateBatch(array $names): array
    {
        return self::usesW56RustFfi() ? self::w56PipeGateBatchNative($names) : self::w56PipeGateBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w56PipeGateBatchNative(array $names): array
    {
        return self::w56Invoke($names, 2 * \count($names) + self::W56_PIPE_GATE_TRAILER) ?? self::w56PipeGateBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w56PipeGateBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0, 0];
        $maxSeason = 0;
        $maxEpisode = 0;
        $titles = [];
        foreach ($names as $name) {
            $tokens = self::w56Tokens((string) $name);
            $count = \count($tokens);
            [$flags, $season, $episode, $airdate, $at] = [0, 0, 0, 0, $count];
            foreach ($tokens as $i => $token) {
                if (preg_match('/^S(\d{1,2})E(\d{1,3})$/i', $token, $m) === 1) {
                    [$flags, $season, $episode, $at] = [2, (int) $m[1], (int) $m[2], $i];
                    break;
                }
                if (preg_match('/^(\d{1,2})x(\d{1,3})$/i', $token, $m) === 1) {
                    [$flags, $season, $episode, $at] = [4, (int) $m[1], (int) $m[2], $i];
                    break;
                }
                if (preg_match('/^S(\d{1,2})$/i', $token, $m) === 1) {
                    [$flags, $season, $at] = [16, (int) $m[1], $i];
                    break;
                }
                if (preg_match('/^(?:19|20)\d{2}$/', $token) === 1
                    && preg_match('/^\d{2}$/', $tokens[$i + 1] ?? '') === 1
                    && preg_match('/^\d{2}$/', $tokens[$i + 2] ?? '') === 1) {
                    $month = (int) $tokens[$i + 1];
                    $day = (int) $tokens[$i + 2];
                    if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                        [$flags, $airdate, $at] = [8, (int) $token * 10000 + $month * 100 + $day, $i];
                        break;
                    }
                }
            }
            if ($count === 0) {
                $flags |= 1;
            } elseif ($at === 0) {
                $flags |= 64;
            }
            if (($flags & 30) !== 0) {
                $flags |= 32;
            }
            $title = strtolower(implode(' ', \array_slice($tokens, 0, $at)));
            $fnv = self::w48Fnv($title);
            if ($at > 0) {
                $titles[$fnv] = true;
            }
            for ($bit = 0; $bit < 7; $bit++) {
                $totals[$bit] += ($flags >> $bit) & 1;
            }
            $maxSeason = max($maxSeason, $season);
            $maxEpisode = max($maxEpisode, $episode);
            $out[] = $flags | $season << 8 | $episode << 16 | $airdate << 28;
            $out[] = $fnv | min(\strlen($title), 0xFFFFFFF) << 32;
        }
        array_push($out, ...$totals);
        array_push($out, $maxSeason, $maxEpisode, \count($titles));

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function w56Tokens(string $value): array
    {
        $tokens = preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }

    /**
     * Wave 56 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w56', self::W56_TV_PIPE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w56RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w56RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 56.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w56Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w56RustFfiState('ffi');
            $out = self::w56RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w56RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W56_TV_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w563_tv_pipe_gate_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    protected int $priority = 50;

    protected bool $echoOutput = true;

    /**
     * Handle the TV processing request.
     */
    public function handle(TvProcessingPassable $passable, Closure $next): TvProcessingPassable
    {
        if ($passable->isResolved() || $passable->parsed === null) {
            return $next($passable);
        }

        $passable->recordProviderResult($this->getName(), $this->process($passable));

        return $next($passable);
    }

    /**
     * Get the priority of this provider (lower = higher priority).
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get the name of this provider for debugging/logging.
     */
    abstract public function getName(): string;

    /**
     * Attempt to process the release through this provider.
     */
    abstract protected function process(TvProcessingPassable $passable): TvProcessingResult;

    public function setEchoOutput(bool $echo): self
    {
        $this->echoOutput = $echo;

        return $this;
    }

    protected function truncateTitle(string $title, int $maxLength = 45): string
    {
        return self::usesRustFfi()
            ? self::truncateTitleNative($title, $maxLength)
            : self::truncateTitlePhp($title, $maxLength);
    }

    public static function truncateTitleNative(string $title, int $maxLength = 45): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::truncateTitlePhp($title, $maxLength);
        }
        try {
            // @phpstan-ignore method.notFound
            $prefix = $ffi->nntmux_tv_pipe_truncate_title($title, \strlen($title), $maxLength);
        } catch (Throwable) {
            return self::truncateTitlePhp($title, $maxLength);
        }
        if ($prefix === -2) {
            return $title;
        }
        if (! \is_int($prefix) || $prefix < 0 || $prefix > \strlen($title)) {
            return self::truncateTitlePhp($title, $maxLength);
        }

        return substr($title, 0, $prefix).'...';
    }

    public static function truncateTitlePhp(string $title, int $maxLength = 45): string
    {
        if (mb_strlen($title) <= $maxLength) {
            return $title;
        }

        return mb_substr($title, 0, $maxLength - 3).'...';
    }

    protected function outputMatch(string $title, ParsedTvRelease $parsed): void
    {
        if (! $this->echoOutput) {
            return;
        }

        $this->outputTitle($title, true);
        $this->outputEpisodeReference($parsed);
        cli()->primaryOver(' ✓ ');
        cli()->primary('MATCHED ('.$this->getName().')');
    }

    protected function outputSeasonPack(string $title): void
    {
        if (! $this->echoOutput) {
            return;
        }

        $this->outputTitle($title, true);
        cli()->primaryOver(' → ');
        cli()->primary('Full season matched ('.$this->getName().')');
    }

    protected function outputEpisodeMissing(string $title, ParsedTvRelease $parsed): void
    {
        if (! $this->echoOutput) {
            return;
        }

        $this->outputTitle($title, false);
        $this->outputEpisodeReference($parsed);
        cli()->primaryOver(' → ');
        cli()->warning('Episode not found in '.$this->getName());
    }

    protected function outputNotFound(string $title): void
    {
        if (! $this->echoOutput) {
            return;
        }

        $this->outputTitle($title, false);
        cli()->primaryOver(' → ');
        cli()->alternate('Not found in '.$this->getName());
    }

    protected function outputSkipped(string $title, string $reason): void
    {
        if (! $this->echoOutput) {
            return;
        }

        $this->outputTitle($title, false);
        cli()->primaryOver(' → ');
        cli()->alternate('Skipped in '.$this->getName().' ('.$reason.')');
    }

    protected function outputSearching(string $title): void
    {
        if (! $this->echoOutput) {
            return;
        }

        $this->outputTitle($title, true);
        cli()->primaryOver(' → ');
        cli()->info('Searching '.$this->getName().'...');
    }

    protected function outputTransient(string $title, string $error): void
    {
        if (! $this->echoOutput) {
            return;
        }

        $this->outputTitle($title, false);
        cli()->primaryOver(' → ');
        cli()->warning($this->getName().' unavailable, will retry: '.$this->truncateTitle($error, 80));
    }

    private function outputTitle(string $title, bool $highlight): void
    {
        cli()->primaryOver('    → ');
        if ($highlight) {
            cli()->headerOver($this->truncateTitle($title));
        } else {
            cli()->alternateOver($this->truncateTitle($title));
        }
    }

    private function outputEpisodeReference(ParsedTvRelease $parsed): void
    {
        if ($parsed->hasEpisodeNumbers()) {
            cli()->primaryOver(' S');
            cli()->warningOver(sprintf('%02d', $parsed->season));
            cli()->primaryOver('E');
            cli()->warningOver(sprintf('%02d', $parsed->episode));
        } elseif ($parsed->hasAirdate()) {
            cli()->primaryOver(' | ');
            cli()->warningOver((string) $parsed->airdate);
        }
    }
}
