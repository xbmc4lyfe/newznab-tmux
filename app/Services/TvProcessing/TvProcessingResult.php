<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Result returned by a pipe, and the final outcome of a release passing through the pipeline.
 */
class TvProcessingResult
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_result_is_matched(const char *status, size_t len);
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

    public const STATUS_MATCHED = 'matched';

    public const STATUS_SEASON_PACK = 'season_pack';

    public const STATUS_EPISODE_MISSING = 'episode_missing';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_PARSE_FAILED = 'parse_failed';

    public const STATUS_TRANSIENT = 'transient';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_PENDING = 'pending';

    /**
     * @param  array<string, mixed>  $debug
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $videoId = null,
        public readonly ?int $episodeId = null,
        public readonly ?string $providerName = null,
        public readonly array $debug = [],
        public readonly bool $transient = false,
        public readonly ?int $retryAfter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function matched(int $videoId, int $episodeId, string $providerName, array $debug = []): self
    {
        return new self(self::STATUS_MATCHED, $videoId, $episodeId, $providerName, $debug);
    }

    public static function seasonPack(int $videoId, string $providerName): self
    {
        return new self(self::STATUS_SEASON_PACK, $videoId, null, $providerName);
    }

    /**
     * The show is known but the episode is not. $transient marks that a provider failed
     * transiently along the way, so the retry comes sooner.
     *
     * @param  array<string, mixed>  $debug
     */
    public static function episodeMissing(int $videoId, ?string $providerName, array $debug = [], bool $transient = false, ?int $retryAfter = null): self
    {
        return new self(self::STATUS_EPISODE_MISSING, $videoId, null, $providerName, $debug, $transient, $retryAfter);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function notFound(?string $providerName = null, array $debug = []): self
    {
        return new self(self::STATUS_NOT_FOUND, providerName: $providerName, debug: $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function parseFailed(array $debug = []): self
    {
        return new self(self::STATUS_PARSE_FAILED, debug: $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function transient(?string $providerName, ?int $retryAfter = null, array $debug = []): self
    {
        return new self(self::STATUS_TRANSIENT, providerName: $providerName, debug: $debug, transient: true, retryAfter: $retryAfter);
    }

    public static function skipped(string $reason = '', ?string $providerName = null): self
    {
        return new self(self::STATUS_SKIPPED, providerName: $providerName, debug: ['reason' => $reason]);
    }

    public static function pending(): self
    {
        return new self(self::STATUS_PENDING);
    }

    public function isMatched(): bool
    {
        return self::usesRustFfi() ? $this->isMatchedNative() : $this->isMatchedPhp();
    }

    public function isMatchedNative(): bool
    {
        return self::isMatchedStatusNative($this->status);
    }

    public function isMatchedPhp(): bool
    {
        return self::isMatchedStatusPhp($this->status);
    }

    public static function isMatchedStatus(string $status): bool
    {
        return self::usesRustFfi()
            ? self::isMatchedStatusNative($status)
            : self::isMatchedStatusPhp($status);
    }

    public static function isMatchedStatusNative(string $status): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::isMatchedStatusPhp($status);
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_result_is_matched($status, \strlen($status));
        } catch (Throwable) {
            return self::isMatchedStatusPhp($status);
        }

        return match ($res) {
            1 => true,
            0 => false,
            default => self::isMatchedStatusPhp($status),
        };
    }

    public static function isMatchedStatusPhp(string $status): bool
    {
        return $status === self::STATUS_MATCHED;
    }

    /**
     * Wave 47.3 batch outcome aggregation. Stays on PHP: the paired benchmark measured about
     * 1.2-1.4x, short of the 1.5x gate; useW47ResultRustFfi(true) keeps Rust available for parity.
     */
    public const bool W47_RESULT_RUST_FFI_DEFAULT = false;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_tv_result_aggregate_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Status codes reported by w47AggregateBatch(); any other status reports 0. */
    public const array W47_STATUS_CODES = [
        self::STATUS_MATCHED => 1,
        self::STATUS_SEASON_PACK => 2,
        self::STATUS_EPISODE_MISSING => 3,
        self::STATUS_NOT_FOUND => 4,
        self::STATUS_PARSE_FAILED => 5,
        self::STATUS_TRANSIENT => 6,
        self::STATUS_SKIPPED => 7,
        self::STATUS_PENDING => 8,
    ];

    /** Retry-after values are clamped to [0, 2^31] before aggregation. */
    public const int W47_MAX_RETRY_AFTER = 1 << 31;

    /** Values written per result, followed by nine status counts and five summary metrics. */
    public const int W47_FIELDS_PER_RESULT = 2;

    private static ?bool $w47RustFfiOverride = null;

    private static FFI|false|null $w47RustFfiHandle = null;

    /** Null restores W47_RESULT_RUST_FFI_DEFAULT. */
    public static function useW47ResultRustFfi(?bool $enable = true): void
    {
        self::$w47RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w47RustFfiHandle = null;
        }
    }

    public static function usesW47ResultRustFfi(): bool
    {
        return self::$w47RustFfiOverride ?? self::W47_RESULT_RUST_FFI_DEFAULT;
    }

    /**
     * Two values per result: its W47_STATUS_CODES code and the FNV-1a 32 digest of
     * `status \x1F provider \x1F video id \x1F episode id` (nulls as ''). Then the count of each
     * code 0-8, matched results with a show, distinct show ids, transient results, and the max
     * (-1 when none) and sum of the clamped retry-after values.
     *
     * @param  list<self>  $results
     * @return list<int>
     */
    public static function w47AggregateBatch(array $results): array
    {
        return self::usesW47ResultRustFfi()
            ? self::w47AggregateBatchNative($results)
            : self::w47AggregateBatchPhp($results);
    }

    /**
     * @param  list<self>  $results
     * @return list<int>
     */
    public static function w47AggregateBatchNative(array $results): array
    {
        if (($ffi = self::w47RustFfi()) === null) {
            return self::w47AggregateBatchPhp($results);
        }
        $count = \count($results);
        $blob = $count === 0 ? '' : implode("\0", array_column($results, 'status'))
            ."\0".implode("\0", array_column($results, 'providerName'));
        if ($count > 0 && substr_count($blob, "\0") !== 2 * $count - 1) {
            return self::w47AggregateBatchPhp($results);
        }
        try {
            $videos = array_column($results, 'videoId');
            $episodes = array_column($results, 'episodeId');
            $retries = array_column($results, 'retryAfter');
            $noVideo = array_keys($videos, null, true);
            $noEpisode = array_keys($episodes, null, true);
            $noRetry = array_keys($retries, null, true);
            $packed = pack(
                'q*',
                \count($noVideo),
                \count($noEpisode),
                \count($noRetry),
                ...$videos,
                ...$episodes,
                ...$retries,
                ...array_column($results, 'transient'),
                ...$noVideo,
                ...$noEpisode,
                ...$noRetry,
            );
            $bytes = ($count * self::W47_FIELDS_PER_RESULT + 14) * 8;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $bytes, $capacity);
            if ($buffer === null) {
                return self::w47AggregateBatchPhp($results);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_tv_result_aggregate_batch($blob, \strlen($blob), $packed, \strlen($packed), $count, $buffer, $capacity);
            if ($written !== $bytes) {
                return self::w47AggregateBatchPhp($results);
            }
            $values = unpack('P*', FFI::string($buffer, $bytes));
            if ($values === false) {
                return self::w47AggregateBatchPhp($results);
            }

            /** @var list<int> */
            return array_values($values);
        } catch (Throwable) {
            return self::w47AggregateBatchPhp($results);
        }
    }

    /**
     * @param  list<self>  $results
     * @return list<int>
     */
    public static function w47AggregateBatchPhp(array $results): array
    {
        $out = [];
        $codes = array_fill(0, 9, 0);
        $matchedWithShow = 0;
        $shows = [];
        $transient = 0;
        $maxRetry = -1;
        $retrySum = 0;
        foreach ($results as $result) {
            $code = self::W47_STATUS_CODES[$result->status] ?? 0;
            $codes[$code]++;
            if ($result->videoId !== null && $result->videoId > 0) {
                if ($code === 1) {
                    $matchedWithShow++;
                }
                $shows[$result->videoId] = true;
            }
            if ($result->transient) {
                $transient++;
            }
            if ($result->retryAfter !== null) {
                $retry = max(0, min(self::W47_MAX_RETRY_AFTER, $result->retryAfter));
                $maxRetry = max($maxRetry, $retry);
                $retrySum += $retry;
            }
            $out[] = $code;
            $out[] = self::w47Fnv($result->status."\x1F".$result->providerName."\x1F".$result->videoId."\x1F".$result->episodeId);
        }
        array_push($out, ...$codes);
        array_push($out, $matchedWithShow, \count($shows), $transient, $maxRetry, $retrySum);

        return $out;
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
        ssize_t nntmux_w563_tv_result_outcome_summary_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
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

    /** Trailer words after the per-record words of w56OutcomeSummaryBatch(). */
    public const int W56_OUTCOME_SUMMARY_TRAILER = 8;

    /** Largest video id stored in a w56OutcomeSummaryBatch() word. */
    public const int W56_MAX_WORD_VIDEO = (1 << 40) - 1;

    /**
     * Wave 56.3 outcome summaries. Per `status:video_id:episode_id` snapshot (explode limit 3)
     * `class | code << 2 | flags << 6 | min(video, W56_MAX_WORD_VIDEO) << 10`, with `class` 0 matched
     * (matched, season_pack), 1 skipped (skipped, pending), 2 not found (not_found, episode_missing)
     * or 3 failed (anything else), `code` the W47_STATUS_CODES code of the trimmed lowercase status
     * (0 unknown), ids '' as 0, 1-18 ASCII digits as their value and anything else malformed, and
     * flags 1 positive video, 2 positive episode, 4 malformed id and 8 ids consistent with the
     * status (matched both positive; season_pack and episode_missing a positive video and no
     * episode; otherwise no episode). Then the four class counts, the inconsistent and malformed
     * counts, the distinct positive videos and the max episode.
     *
     * @param  array<array-key, string>  $records
     * @return list<int>
     */
    public static function w56OutcomeSummaryBatch(array $records): array
    {
        return self::usesW56RustFfi() ? self::w56OutcomeSummaryBatchNative($records) : self::w56OutcomeSummaryBatchPhp($records);
    }

    /**
     * @param  array<array-key, string>  $records
     * @return list<int>
     */
    public static function w56OutcomeSummaryBatchNative(array $records): array
    {
        return self::w56Invoke($records, \count($records) + self::W56_OUTCOME_SUMMARY_TRAILER) ?? self::w56OutcomeSummaryBatchPhp($records);
    }

    /**
     * @param  array<array-key, string>  $records
     * @return list<int>
     */
    public static function w56OutcomeSummaryBatchPhp(array $records): array
    {
        $out = [];
        $classes = [0, 0, 0, 0];
        $inconsistent = 0;
        $malformedCount = 0;
        $videos = [];
        $maxEpisode = 0;
        foreach ($records as $record) {
            $parts = explode(':', (string) $record, 3);
            $code = self::W47_STATUS_CODES[strtolower(trim($parts[0]))] ?? 0;
            $video = self::w56OutcomeId($parts[1] ?? '');
            $episode = self::w56OutcomeId($parts[2] ?? '');
            $class = match ($code) {
                1, 2 => 0,
                7, 8 => 1,
                3, 4 => 2,
                default => 3,
            };
            $malformed = $video < 0 || $episode < 0;
            $consistent = ! $malformed && match ($code) {
                1 => $video > 0 && $episode > 0,
                2, 3 => $video > 0 && $episode === 0,
                default => $episode === 0,
            };
            $flags = ($video > 0 ? 1 : 0) | ($episode > 0 ? 2 : 0) | ($malformed ? 4 : 0) | ($consistent ? 8 : 0);
            $classes[$class]++;
            $inconsistent += $consistent ? 0 : 1;
            $malformedCount += $malformed ? 1 : 0;
            if ($video > 0) {
                $videos[$video] = true;
            }
            $maxEpisode = max($maxEpisode, $episode);
            $out[] = $class | $code << 2 | $flags << 6 | max(0, min($video, self::W56_MAX_WORD_VIDEO)) << 10;
        }
        array_push($out, ...$classes);
        array_push($out, $inconsistent, $malformedCount, \count($videos), $maxEpisode);

        return $out;
    }

    /** A snapshot id: '' is 0, 1-18 ASCII digits their value, anything else -1 (malformed). */
    private static function w56OutcomeId(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return \strlen($value) <= 18 && ctype_digit($value) ? (int) $value : -1;
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
            $written = $ffi->nntmux_w563_tv_result_outcome_summary_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
