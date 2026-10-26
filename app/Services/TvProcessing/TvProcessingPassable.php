<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * State carried through the TV processing pipeline for one release.
 *
 * Pipes only record what they found here; TvLookupOutcomeWriter persists the final outcome.
 */
class TvProcessingPassable
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_passable_is_resolved(uint32_t parse_failed, int64_t episode_id, uint32_t season_pack);
        int32_t nntmux_tv_passable_resolve_final_status(uint32_t parse_failed, uint32_t has_parsed, int64_t video_id, int64_t episode_id, uint32_t season_pack, uint32_t has_transient);
        ssize_t nntmux_tv_passable_dedup_transient_providers_nul(const char *input, size_t input_len, size_t count, char *out, size_t cap);
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

    public ?ParsedTvRelease $parsed = null;

    public bool $parseFailed = false;

    /** The show the release belongs to, 0 while unknown. */
    public int $videoId;

    public ?string $showProvider = null;

    public int $episodeId = 0;

    public bool $seasonPack = false;

    public ?string $matchedBy = null;

    /** @var list<string> */
    public array $transientProviders = [];

    public ?int $maxRetryAfter = null;

    /** @var array<string, array<string, mixed>> */
    public array $providerResults = [];

    public function __construct(
        public readonly TvReleaseContext $context,
        public readonly TvProcessingRunState $runState,
        public readonly bool $debug = false,
    ) {
        // A release already linked to a show (episode retries, tv:reprocess) keeps that show.
        $this->videoId = max(0, $context->videosId);
        if ($this->videoId > 0) {
            $this->showProvider = 'existing';
        }
    }

    public function setParsed(ParsedTvRelease $parsed): void
    {
        $this->parsed = $parsed;
    }

    public function markParseFailed(): void
    {
        $this->parsed = null;
        $this->parseFailed = true;
    }

    public function hasShow(): bool
    {
        return $this->videoId > 0;
    }

    public function setShow(int $videoId, string $provider): void
    {
        $this->videoId = $videoId;
        $this->showProvider = $provider;
    }

    public function markMatched(int $episodeId, string $provider): void
    {
        $this->episodeId = $episodeId;
        $this->matchedBy = $provider;
    }

    public function markSeasonPack(string $provider): void
    {
        $this->seasonPack = true;
        $this->matchedBy = $provider;
    }

    public function markTransient(string $provider, ?int $retryAfter = null): void
    {
        $this->transientProviders[] = $provider;
        if ($retryAfter !== null) {
            $this->maxRetryAfter = max($this->maxRetryAfter ?? 0, $retryAfter);
        }
    }

    /** True once nothing further down the pipeline can improve the outcome. */
    public function isResolved(): bool
    {
        return self::usesRustFfi() ? $this->isResolvedNative() : $this->isResolvedPhp();
    }

    public function isResolvedNative(): bool
    {
        if (($ffi = self::ffi()) === null) {
            return $this->isResolvedPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_passable_is_resolved(
                $this->parseFailed ? 1 : 0,
                $this->episodeId,
                $this->seasonPack ? 1 : 0,
            );
        } catch (Throwable) {
            return $this->isResolvedPhp();
        }

        return match ($res) {
            1 => true,
            0 => false,
            default => $this->isResolvedPhp(),
        };
    }

    public function isResolvedPhp(): bool
    {
        return $this->parseFailed || $this->episodeId > 0 || $this->seasonPack;
    }

    public function recordProviderResult(string $providerName, TvProcessingResult $result): void
    {
        if (! $this->debug) {
            return;
        }

        $this->providerResults[$providerName] = [
            'status' => $result->status,
            'video_id' => $result->videoId,
            'episode_id' => $result->episodeId,
            'debug' => $result->debug,
        ];
    }

    /** The outcome to persist for this release. */
    public function finalResult(): TvProcessingResult
    {
        return self::usesRustFfi() ? $this->finalResultNative() : $this->finalResultPhp();
    }

    public function finalResultNative(): TvProcessingResult
    {
        $outcome = self::resolveFinalOutcomeNative(
            $this->parseFailed,
            $this->parsed !== null,
            $this->videoId,
            $this->episodeId,
            $this->seasonPack,
            $this->transientProviders !== [],
        );

        return match ($outcome) {
            0 => TvProcessingResult::parseFailed(['search_name' => $this->context->searchName]),
            1 => TvProcessingResult::matched($this->videoId, $this->episodeId, (string) $this->matchedBy),
            2 => TvProcessingResult::seasonPack($this->videoId, (string) $this->matchedBy),
            3 => TvProcessingResult::episodeMissing(
                $this->videoId,
                $this->showProvider,
                ['transient_providers' => $this->transientProviders],
                $this->transientProviders !== [],
                $this->maxRetryAfter,
            ),
            4 => TvProcessingResult::transient(
                self::deduplicateTransientProvidersNative($this->transientProviders),
                $this->maxRetryAfter,
            ),
            default => TvProcessingResult::notFound(),
        };
    }

    public function finalResultPhp(): TvProcessingResult
    {
        if ($this->parseFailed || $this->parsed === null) {
            return TvProcessingResult::parseFailed(['search_name' => $this->context->searchName]);
        }

        if ($this->episodeId > 0) {
            return TvProcessingResult::matched($this->videoId, $this->episodeId, (string) $this->matchedBy);
        }

        if ($this->seasonPack && $this->videoId > 0) {
            return TvProcessingResult::seasonPack($this->videoId, (string) $this->matchedBy);
        }

        $transient = $this->transientProviders !== [];

        if ($this->videoId > 0) {
            return TvProcessingResult::episodeMissing(
                $this->videoId,
                $this->showProvider,
                ['transient_providers' => $this->transientProviders],
                $transient,
                $this->maxRetryAfter,
            );
        }

        if ($transient) {
            return TvProcessingResult::transient(
                self::deduplicateTransientProvidersPhp($this->transientProviders),
                $this->maxRetryAfter,
            );
        }

        return TvProcessingResult::notFound();
    }

    public static function resolveFinalOutcome(
        bool $parseFailed,
        bool $hasParsed,
        int $videoId,
        int $episodeId,
        bool $seasonPack,
        bool $hasTransient,
    ): int {
        return self::usesRustFfi()
            ? self::resolveFinalOutcomeNative($parseFailed, $hasParsed, $videoId, $episodeId, $seasonPack, $hasTransient)
            : self::resolveFinalOutcomePhp($parseFailed, $hasParsed, $videoId, $episodeId, $seasonPack, $hasTransient);
    }

    public static function resolveFinalOutcomeNative(
        bool $parseFailed,
        bool $hasParsed,
        int $videoId,
        int $episodeId,
        bool $seasonPack,
        bool $hasTransient,
    ): int {
        if (($ffi = self::ffi()) === null) {
            return self::resolveFinalOutcomePhp($parseFailed, $hasParsed, $videoId, $episodeId, $seasonPack, $hasTransient);
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_tv_passable_resolve_final_status(
                $parseFailed ? 1 : 0,
                $hasParsed ? 1 : 0,
                $videoId,
                $episodeId,
                $seasonPack ? 1 : 0,
                $hasTransient ? 1 : 0,
            );
        } catch (Throwable) {
            return self::resolveFinalOutcomePhp($parseFailed, $hasParsed, $videoId, $episodeId, $seasonPack, $hasTransient);
        }

        return \is_int($code) && $code >= 0 && $code <= 5
            ? $code
            : self::resolveFinalOutcomePhp($parseFailed, $hasParsed, $videoId, $episodeId, $seasonPack, $hasTransient);
    }

    public static function resolveFinalOutcomePhp(
        bool $parseFailed,
        bool $hasParsed,
        int $videoId,
        int $episodeId,
        bool $seasonPack,
        bool $hasTransient,
    ): int {
        if ($parseFailed || ! $hasParsed) {
            return 0;
        }
        if ($episodeId > 0) {
            return 1;
        }
        if ($seasonPack && $videoId > 0) {
            return 2;
        }
        if ($videoId > 0) {
            return 3;
        }
        if ($hasTransient) {
            return 4;
        }

        return 5;
    }

    /**
     * @param  list<string>  $providers
     */
    public static function deduplicateTransientProviders(array $providers): string
    {
        return self::usesRustFfi()
            ? self::deduplicateTransientProvidersNative($providers)
            : self::deduplicateTransientProvidersPhp($providers);
    }

    /**
     * @param  list<string>  $providers
     */
    public static function deduplicateTransientProvidersNative(array $providers): string
    {
        $count = \count($providers);
        if ($count === 0) {
            return '';
        }
        if (($ffi = self::ffi()) === null) {
            return self::deduplicateTransientProvidersPhp($providers);
        }
        foreach ($providers as $provider) {
            if (! \is_string($provider) || str_contains($provider, "\0")) {
                return self::deduplicateTransientProvidersPhp($providers);
            }
        }
        $joined = implode("\0", $providers);
        $len = \strlen($joined);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $len + 16), $capacity);
        if ($buffer === null) {
            return self::deduplicateTransientProvidersPhp($providers);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_passable_dedup_transient_providers_nul($joined, $len, $count, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::deduplicateTransientProvidersPhp($providers);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_passable_dedup_transient_providers_nul($joined, $len, $count, $buffer, $capacity);
            }
            if ($written === 0) {
                return '';
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::deduplicateTransientProvidersPhp($providers);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::deduplicateTransientProvidersPhp($providers);
        }
    }

    /**
     * @param  list<string>  $providers
     */
    public static function deduplicateTransientProvidersPhp(array $providers): string
    {
        return implode(',', array_unique($providers));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = $this->finalResult();

        $returnValue = [
            'status' => $result->status,
            'matched' => $result->isMatched(),
            'video_id' => $result->videoId,
            'episode_id' => $result->episodeId,
            'provider' => $result->providerName,
        ];

        if ($this->debug) {
            $returnValue['debug'] = [
                'release_id' => $this->context->releaseId,
                'search_name' => $this->context->searchName,
                'parsed_info' => $this->parsed,
                'provider_results' => $this->providerResults,
            ];
        }

        return $returnValue;
    }

    /**
     * Wave 47.3 batch pipeline trace and match-confidence evaluation. Stays on PHP: the paired
     * benchmark measured about 1.35x common and 1.1x uncommon, short of the 1.5x gate;
     * useW47PassableRustFfi(true) keeps Rust available for parity.
     */
    public const bool W47_PASSABLE_RUST_FFI_DEFAULT = false;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_tv_passable_trace_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Values written per passable, followed by six outcome counts, the confidence sum and max retry-after. */
    public const int W47_FIELDS_PER_PASSABLE = 4;

    /** Match confidence by lowercase provider name; any other non-empty provider scores 50. */
    public const array W47_PROVIDER_WEIGHTS = [
        'tvdb' => 95,
        'tvmaze' => 90,
        'tmdb' => 85,
        'trakt' => 80,
        'local db' => 75,
        'existing' => 70,
    ];

    private static ?bool $w47RustFfiOverride = null;

    private static FFI|false|null $w47RustFfiHandle = null;

    /** Null restores W47_PASSABLE_RUST_FFI_DEFAULT. */
    public static function useW47PassableRustFfi(?bool $enable = true): void
    {
        self::$w47RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w47RustFfiHandle = null;
        }
    }

    public static function usesW47PassableRustFfi(): bool
    {
        return self::$w47RustFfiOverride ?? self::W47_PASSABLE_RUST_FFI_DEFAULT;
    }

    /**
     * Four values per passable: resolveFinalOutcome() code, 0-100 match confidence, distinct
     * transient provider count and the FNV-1a 32 digest of the outcome digit followed by
     * `show \x1E matched \x1E transients` (transients `\x1F`-joined). Then the count of each
     * outcome code 0-5, the confidence sum and the max retry-after (-1 when none).
     *
     * @param  list<self>  $passables
     * @return list<int>
     */
    public static function w47TraceBatch(array $passables): array
    {
        return self::usesW47PassableRustFfi()
            ? self::w47TraceBatchNative($passables)
            : self::w47TraceBatchPhp($passables);
    }

    /**
     * @param  list<self>  $passables
     * @return list<int>
     */
    public static function w47TraceBatchNative(array $passables): array
    {
        if (($ffi = self::w47RustFfi()) === null) {
            return self::w47TraceBatchPhp($passables);
        }
        $count = \count($passables);
        $transients = array_column($passables, 'transientProviders');
        $flat = $transients === [] ? [] : array_merge(...$transients);
        $blob = $count === 0 ? '' : implode("\0", array_column($passables, 'showProvider'))
            ."\0".implode("\0", array_column($passables, 'matchedBy'));
        if ($flat !== []) {
            $blob .= "\0".implode("\0", $flat);
        }
        $pieces = 2 * $count + \count($flat);
        if ($pieces > 0 && substr_count($blob, "\0") !== $pieces - 1) {
            return self::w47TraceBatchPhp($passables);
        }
        try {
            $retries = array_column($passables, 'maxRetryAfter');
            $unparsed = array_keys(array_column($passables, 'parsed'), null, true);
            $noRetry = array_keys($retries, null, true);
            $packed = pack(
                'q*',
                \count($unparsed),
                \count($noRetry),
                ...array_column($passables, 'parseFailed'),
                ...array_column($passables, 'seasonPack'),
                ...array_column($passables, 'videoId'),
                ...array_column($passables, 'episodeId'),
                ...array_map(\count(...), $transients),
                ...$retries,
                ...$unparsed,
                ...$noRetry,
            );
            $bytes = ($count * self::W47_FIELDS_PER_PASSABLE + 8) * 8;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $bytes, $capacity);
            if ($buffer === null) {
                return self::w47TraceBatchPhp($passables);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_tv_passable_trace_batch($blob, \strlen($blob), $packed, \strlen($packed), $count, $buffer, $capacity);
            if ($written !== $bytes) {
                return self::w47TraceBatchPhp($passables);
            }
            $values = unpack('P*', FFI::string($buffer, $bytes));
            if ($values === false) {
                return self::w47TraceBatchPhp($passables);
            }

            /** @var list<int> */
            return array_values($values);
        } catch (Throwable) {
            return self::w47TraceBatchPhp($passables);
        }
    }

    /**
     * @param  list<self>  $passables
     * @return list<int>
     */
    public static function w47TraceBatchPhp(array $passables): array
    {
        $out = [];
        $outcomes = [0, 0, 0, 0, 0, 0];
        $confidenceSum = 0;
        $maxRetry = -1;
        foreach ($passables as $passable) {
            $transient = $passable->transientProviders;
            $outcome = self::resolveFinalOutcomePhp(
                $passable->parseFailed,
                $passable->parsed !== null,
                $passable->videoId,
                $passable->episodeId,
                $passable->seasonPack,
                $transient !== [],
            );
            $distinct = \count(array_unique($transient));
            $matchedBy = (string) $passable->matchedBy;
            $showProvider = (string) $passable->showProvider;
            $confidence = match ($outcome) {
                1 => self::w47Weight($matchedBy) - 5 * $distinct,
                2 => self::w47Weight($matchedBy) - 15 - 5 * $distinct,
                3 => intdiv(self::w47Weight($showProvider), 2) - 5 * $distinct,
                4 => 10,
                default => 0,
            };
            $confidence = max(0, min(100, $confidence));
            $digest = self::w47Fnv($outcome.$showProvider."\x1E".$matchedBy."\x1E".implode("\x1F", $transient));
            $outcomes[$outcome]++;
            $confidenceSum += $confidence;
            $maxRetry = max($maxRetry, $passable->maxRetryAfter ?? -1);
            array_push($out, $outcome, $confidence, $distinct, $digest);
        }
        array_push($out, ...$outcomes);
        $out[] = $confidenceSum;
        $out[] = $maxRetry;

        return $out;
    }

    private static function w47Weight(string $provider): int
    {
        return $provider === '' ? 0 : (self::W47_PROVIDER_WEIGHTS[strtolower($provider)] ?? 50);
    }

    private static function w47Fnv(string $bytes): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('N', hash('fnv1a32', $bytes, true));

        return $digest[1];
    }

    private static function w47RustFfi(): ?FFI
    {
        if (self::$w47RustFfiHandle === null) {
            self::$w47RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w47', self::W47_FFI_CDEF) ?? false;
        }

        return self::$w47RustFfiHandle === false ? null : self::$w47RustFfiHandle;
    }

    /** Wave 56.3 paired benchmarks decide this default; see RustTvProcessingPipesProviderPassableResultW56ParityTest. */
    public const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_FFI_CDEF = <<<'C'
        ssize_t nntmux_w563_tv_passable_context_fingerprint_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

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

    /** Trailer words after the per-name words of w56ContextFingerprintBatch(). */
    public const int W56_CONTEXT_FINGERPRINT_TRAILER = 6;

    /**
     * Wave 56.3 release context fingerprints. Per search name `fnv | min(tokens, 0xFFFF) << 32 |
     * group << 48 | flags << 53`, with `fnv` the FNV-1a 32 cache key of the lowercase `[a-z0-9]`
     * tokens joined by dots, `group` the length of a trimmed `-GROUP` suffix (1-20 word bytes after
     * a dash that is not the first byte) and flags 1 no tokens, 2 group suffix, 4 a non-ASCII byte
     * and 8 a `[...]` tag. Then the four flag counts, the distinct keys and the token total.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w56ContextFingerprintBatch(array $names): array
    {
        return self::usesW56RustFfi() ? self::w56ContextFingerprintBatchNative($names) : self::w56ContextFingerprintBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w56ContextFingerprintBatchNative(array $names): array
    {
        return self::w56Invoke($names, \count($names) + self::W56_CONTEXT_FINGERPRINT_TRAILER) ?? self::w56ContextFingerprintBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w56ContextFingerprintBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0];
        $keys = [];
        $tokenTotal = 0;
        foreach ($names as $name) {
            $name = (string) $name;
            $trimmed = trim($name);
            $tokens = preg_split('/[^a-z0-9]+/', strtolower($trimmed), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $count = \count($tokens);
            $fnv = self::w47Fnv(implode('.', $tokens));
            $flags = $count === 0 ? 1 : 0;
            $group = 0;
            if (preg_match('/.-([A-Za-z0-9_]{1,20})\z/s', $trimmed, $m) === 1) {
                $flags |= 2;
                $group = \strlen($m[1]);
            }
            if (preg_match('/[\x80-\xff]/', $name) === 1) {
                $flags |= 4;
            }
            if (preg_match('/\[[^\]]*\]/', $name) === 1) {
                $flags |= 8;
            }
            for ($bit = 0; $bit < 4; $bit++) {
                $totals[$bit] += ($flags >> $bit) & 1;
            }
            $keys[$fnv] = true;
            $tokenTotal += $count;
            $out[] = $fnv | min($count, 0xFFFF) << 32 | $group << 48 | $flags << 53;
        }
        array_push($out, ...$totals);
        array_push($out, \count($keys), $tokenTotal);

        return $out;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w56', self::W56_FFI_CDEF) ?? false;

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
            // @phpstan-ignore method.notFound (Declared by W56_FFI_CDEF.)
            $written = $ffi->nntmux_w563_tv_passable_context_fingerprint_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
}
