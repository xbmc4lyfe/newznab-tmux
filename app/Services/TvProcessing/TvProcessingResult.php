<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
