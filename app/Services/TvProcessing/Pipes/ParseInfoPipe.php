<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Initial pipe that parses the release name into structured info.
 * This must run before any provider pipes.
 */
class ParseInfoPipe extends AbstractTvProviderPipe
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_parse_pipe_validate_info(const char *ser, size_t ser_len);
        int32_t nntmux_tv_parse_pipe_should_advance(uint32_t has_parsed);
        ssize_t nntmux_tv_parse_pipe_format_error(const char *search, size_t search_len, int64_t max_chars, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvIgdbSteamMetadataParityTest). */
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
     * Wave 48.3 batch release-name TV token parsing, multi-episode/season-pack/daily
     * classification and clean title extraction.
     * Enabled: seven paired runs cleared 1.5x on every gate (min common mean 7.45x, p99 5.95x; uncommon mean 4.58x, p99 3.55x; see the W48 parity test).
     */
    public const bool W48_PARSE_INFO_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_tv_parse_info_token_batch(const char *blob, size_t blob_len, size_t count, char *out, size_t out_cap);
        C;

    /** Kinds: no marker, single episode, multi-episode, season pack, daily show. */
    public const int W48_KINDS = 5;

    public const int W48_FIELDS_PER_NAME = 8;

    private static ?bool $w48RustFfiOverride = null;

    private static FFI|false|null $w48RustFfiHandle = null;

    /** Null restores W48_PARSE_INFO_RUST_FFI_DEFAULT. */
    public static function useW48ParseInfoRustFfi(?bool $enable = true): void
    {
        self::$w48RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w48RustFfiHandle = null;
        }
    }

    public static function usesW48ParseInfoRustFfi(): bool
    {
        return self::$w48RustFfiOverride ?? self::W48_PARSE_INFO_RUST_FFI_DEFAULT;
    }

    /**
     * Eight values per name over its lowercase `[a-z0-9]+` tokens: the kind (see W48_KINDS),
     * season, episode, last episode, airdate (YYYYMMDD), stripped title year, FNV-1a 32 digest
     * and length of the clean title. The first marker token wins: `sNeN[eN]` (an `eN` next token
     * also ends a range; a range end above the episode makes a multi-episode), `NxNN`, `sN`
     * (season pack), or a 19xx/20xx year followed by a 1-12 month and 1-31 day token (daily).
     * The title is the tokens before the marker, less a trailing 19xx/20xx year when more than
     * one token remains. Absent numbers are -1 (0 for airdate and year). Then the five kind counts.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function w48TokenBatch(array $names): array
    {
        return self::usesW48ParseInfoRustFfi()
            ? self::w48TokenBatchNative($names)
            : self::w48TokenBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function w48TokenBatchNative(array $names): array
    {
        $count = \count($names);

        return self::w48Invoke(
            self::w48RustFfi(),
            'nntmux_w48_tv_parse_info_token_batch',
            [$names],
            null,
            $count,
            $count * self::W48_FIELDS_PER_NAME + self::W48_KINDS,
        ) ?? self::w48TokenBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function w48TokenBatchPhp(array $names): array
    {
        $out = [];
        $kinds = array_fill(0, self::W48_KINDS, 0);
        foreach ($names as $name) {
            $normalized = self::w48Normalize((string) $name);
            $tokens = $normalized === '' ? [] : explode(' ', $normalized);
            $n = \count($tokens);
            [$kind, $season, $episode, $end, $airdate] = [0, -1, -1, -1, 0];
            $marker = $n;
            for ($k = 0; $k < $n; $k++) {
                $t = $tokens[$k];
                if (preg_match('/^s(\d{1,3})e(\d{1,4})(?:e(\d{1,4}))?$/', $t, $m) === 1) {
                    $season = (int) $m[1];
                    $episode = (int) $m[2];
                    $third = isset($m[3]) ? (int) $m[3]
                        : ($k + 1 < $n && preg_match('/^e(\d{1,4})$/', $tokens[$k + 1], $e) === 1 ? (int) $e[1] : null);
                    [$kind, $end] = $third !== null && $third > $episode ? [2, $third] : [1, $episode];
                    $marker = $k;
                    break;
                }
                if (preg_match('/^(\d{1,2})x(\d{2,3})$/', $t, $m) === 1) {
                    [$kind, $season, $episode, $end] = [1, (int) $m[1], (int) $m[2], (int) $m[2]];
                    $marker = $k;
                    break;
                }
                if (preg_match('/^s(\d{1,3})$/', $t, $m) === 1) {
                    [$kind, $season] = [3, (int) $m[1]];
                    $marker = $k;
                    break;
                }
                if ($k + 2 < $n && preg_match('/^(?:19|20)\d\d$/', $t) === 1
                    && preg_match('/^\d{1,2}$/', $tokens[$k + 1]) === 1 && preg_match('/^\d{1,2}$/', $tokens[$k + 2]) === 1) {
                    $month = (int) $tokens[$k + 1];
                    $day = (int) $tokens[$k + 2];
                    if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                        $kind = 4;
                        $airdate = (int) $t * 10000 + $month * 100 + $day;
                        $marker = $k;
                        break;
                    }
                }
            }
            $year = 0;
            if ($marker > 1 && preg_match('/^(?:19|20)\d\d$/', $tokens[$marker - 1]) === 1) {
                $year = (int) $tokens[$marker - 1];
                $marker--;
            }
            $title = implode(' ', \array_slice($tokens, 0, $marker));
            $kinds[$kind]++;
            array_push($out, $kind, $season, $episode, $end, $airdate, $year, self::w48Fnv($title), \strlen($title));
        }
        array_push($out, ...$kinds);

        return $out;
    }

    private static function w48RustFfi(): ?FFI
    {
        if (self::$w48RustFfiHandle === null) {
            self::$w48RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w48', self::W48_FFI_CDEF) ?? false;
        }

        return self::$w48RustFfiHandle === false ? null : self::$w48RustFfiHandle;
    }

    protected int $priority = 1;

    public function __construct(private ?LocalDbProvider $parser = null) {}

    public function getName(): string
    {
        return 'ParseInfo';
    }

    public function handle(TvProcessingPassable $passable, Closure $next): TvProcessingPassable
    {
        $this->parser ??= new LocalDbProvider;
        $parsed = ParsedTvRelease::fromParseInfo($this->parser->parseInfo($passable->context->searchName));

        if (! self::applyParseTransition($passable, $parsed, $this->getName())) {
            if ($this->echoOutput) {
                cli()->error(self::formatParseFailedMessage($passable->context->searchName, 50));
            }

            // Nothing else can work without parsed info.
            return $passable;
        }

        return $next($passable);
    }

    /**
     * Validate raw `AbstractTvProvider::parseInfo()` output before constructing a `ParsedTvRelease`.
     *
     * @param  array<string, mixed>|false  $info
     */
    public static function isValidParseInfo(array|false $info): bool
    {
        return self::usesRustFfi()
            ? self::isValidParseInfoNative($info)
            : self::isValidParseInfoPhp($info);
    }

    /**
     * @param  array<string, mixed>|false  $info
     */
    public static function isValidParseInfoNative(array|false $info): bool
    {
        if ($info === false) {
            return false;
        }
        if (($ffi = self::ffi()) === null) {
            return self::isValidParseInfoPhp($info);
        }
        try {
            $subset = array_intersect_key($info, ['name' => true, 'season' => true, 'episode' => true]);
            foreach ($subset as $val) {
                if (\is_object($val)) {
                    return self::isValidParseInfoPhp($info);
                }
            }
            $ser = serialize($subset);
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_parse_pipe_validate_info($ser, \strlen($ser));
            if ($res === 1) {
                return true;
            }
            if ($res === 0) {
                return false;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::isValidParseInfoPhp($info);
    }

    /**
     * @param  array<string, mixed>|false  $info
     */
    public static function isValidParseInfoPhp(array|false $info): bool
    {
        return $info !== false && ! empty($info['name']) && isset($info['season'], $info['episode']);
    }

    public static function shouldAdvance(?ParsedTvRelease $parsed): bool
    {
        return self::usesRustFfi()
            ? self::shouldAdvanceNative($parsed)
            : self::shouldAdvancePhp($parsed);
    }

    public static function shouldAdvanceNative(?ParsedTvRelease $parsed): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::shouldAdvancePhp($parsed);
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_parse_pipe_should_advance($parsed !== null ? 1 : 0);
            if ($res === 1) {
                return true;
            }
            if ($res === 0) {
                return false;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::shouldAdvancePhp($parsed);
    }

    public static function shouldAdvancePhp(?ParsedTvRelease $parsed): bool
    {
        return $parsed !== null;
    }

    /**
     * Apply the parsed release outcome to the passable and return whether the pipeline should advance.
     */
    public static function applyParseTransition(
        TvProcessingPassable $passable,
        ?ParsedTvRelease $parsed,
        string $providerName = 'ParseInfo',
    ): bool {
        if (! self::shouldAdvance($parsed) || $parsed === null) {
            $passable->markParseFailed();
            $passable->recordProviderResult($providerName, self::buildParseFailedResult());

            return false;
        }

        $passable->setParsed($parsed);

        return true;
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function buildParseFailedResult(array $debug = []): TvProcessingResult
    {
        return TvProcessingResult::parseFailed($debug);
    }

    public static function formatParseFailedMessage(string $searchName, int $maxLength = 50): string
    {
        return self::usesRustFfi()
            ? self::formatParseFailedMessageNative($searchName, $maxLength)
            : self::formatParseFailedMessagePhp($searchName, $maxLength);
    }

    public static function formatParseFailedMessageNative(string $searchName, int $maxLength = 50): string
    {
        if ($maxLength < 0 || ($ffi = self::ffi()) === null) {
            return self::formatParseFailedMessagePhp($searchName, $maxLength);
        }
        $len = \strlen($searchName);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(96, $len + 32), $capacity);
        if ($buffer === null) {
            return self::formatParseFailedMessagePhp($searchName, $maxLength);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_parse_pipe_format_error($searchName, $len, $maxLength, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatParseFailedMessagePhp($searchName, $maxLength);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_parse_pipe_format_error($searchName, $len, $maxLength, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::formatParseFailedMessagePhp($searchName, $maxLength);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::formatParseFailedMessagePhp($searchName, $maxLength);
        }
    }

    public static function formatParseFailedMessagePhp(string $searchName, int $maxLength = 50): string
    {
        return sprintf('  ✗ Parse failed: %s', mb_substr($searchName, 0, max(0, $maxLength)));
    }

    protected function process(TvProcessingPassable $passable): TvProcessingResult
    {
        // Not used - handle() does the parsing.
        return TvProcessingResult::pending();
    }

    // ========================================================================
    // WAVE 62.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 12.85x, p99 9.25x; uncommon mean 9.42x, p99 6.61x); see RustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_PARSE_INFO_PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w623_parse_info_pipe_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 62.3 batch. */
    public const int W62_PARSE_INFO_PIPE_TRAILER = 14;

    /** C0 control bytes and DEL. */
    private const string W62_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ParseInfoPipe Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.3 searchname pre-parse plans. Each item is a release searchname, trimmed. The gate is 0 empty, 1 parsed,
     * 2 over 512 bytes or 3 holding a control byte; only parsed names are tokenized into lowercase `[a-z0-9]+` tokens.
     * The first marker token wins: `sNeN` with optional inline `eN` runs (the last one, else an `eN` next token, ends a
     * range; an end above the episode is a multi-episode), `NxNN`, `sN` (an `eN` next token makes a single episode,
     * else a season pack), a 19xx/20xx year followed by 1-12 and 1-31 (1-2 digit) tokens (daily), `part`/`pt` with
     * a 1-2 digit number of at least 1 (inline or next token) and `ep`/`episode` with a 1-4 digit number of at least
     * 1 (`epN` inline, or `ep`/`episode` then the number). Per item `kind | gate << 3 | season << 5 | episode << 15 |
     * end << 29 | (year - 1900) << 43 | month << 51 | day << 55 | min(titleTokens, 7) << 60` with kind 0 none,
     * 1 single episode, 2 multi-episode, 3 season pack, 4 daily, 5 part and 6 absolute episode (parts and absolute
     * episodes fill episode and end); title tokens precede the marker (all tokens without one). Then the seven kind
     * and four gate counts, the title token sum, the multi-episode span sum and the highest season of kinds 1-3.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ParsePlanBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62ParsePlanBatchNative($items) : self::w62ParsePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ParsePlanBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_PARSE_INFO_PIPE_TRAILER) ?? self::w62ParsePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ParsePlanBatchPhp(array $items): array
    {
        $out = [];
        $kinds = [0, 0, 0, 0, 0, 0, 0];
        $gates = [0, 0, 0, 0];
        $tokenSum = $spanSum = $maxSeason = 0;
        foreach ($items as $item) {
            $text = trim((string) $item);
            $length = \strlen($text);
            $gate = $length === 0 ? 0 : ($length > 512 ? 2 : (strcspn($text, self::W62_CONTROL_CHARS) !== $length ? 3 : 1));
            $kind = $season = $episode = $end = $year = $month = $day = $titleTokens = 0;
            if ($gate === 1) {
                $tokens = self::w62Tokens($text);
                $titleTokens = \count($tokens);
                foreach (array_keys($tokens) as $k) {
                    $found = self::w62Marker($tokens, $k);
                    if ($found !== null) {
                        [$kind, $season, $episode, $end, $year, $month, $day] = $found;
                        $titleTokens = $k;
                        break;
                    }
                }
            }
            $kinds[$kind]++;
            $gates[$gate]++;
            $tokenSum += $titleTokens;
            if ($kind === 2) {
                $spanSum += $end - $episode;
            }
            if ($kind >= 1 && $kind <= 3) {
                $maxSeason = max($maxSeason, $season);
            }
            $out[] = $kind | $gate << 3 | min($season, 1023) << 5 | min($episode, 16383) << 15 | min($end, 16383) << 29
                | ($year > 0 ? $year - 1900 : 0) << 43 | $month << 51 | $day << 55 | min($titleTokens, 7) << 60;
        }
        array_push($out, ...$kinds, ...$gates);
        array_push($out, $tokenSum, $spanSum, $maxSeason);

        return $out;
    }

    /**
     * (kind, season, episode, end, year, month, day) for the marker token at $k, else null.
     *
     * @param  list<string>  $tokens
     * @return array{int, int, int, int, int, int, int}|null
     */
    private static function w62Marker(array $tokens, int $k): ?array
    {
        $token = $tokens[$k];
        $next = $tokens[$k + 1] ?? null;
        $nextEpisode = $next !== null && preg_match('/\Ae(\d{1,4})\z/', $next, $e) === 1 ? (int) $e[1] : null;
        if (preg_match('/\As(\d{1,3})e(\d{1,4})((?:e\d{1,4})*)\z/', $token, $m) === 1) {
            $episode = (int) $m[2];
            $end = $m[3] !== '' ? (int) substr($m[3], (int) strrpos($m[3], 'e') + 1) : $nextEpisode;

            return $end !== null && $end > $episode ? [2, (int) $m[1], $episode, $end, 0, 0, 0] : [1, (int) $m[1], $episode, $episode, 0, 0, 0];
        }
        if (preg_match('/\A(\d{1,2})x(\d{2,3})\z/', $token, $m) === 1) {
            return [1, (int) $m[1], (int) $m[2], (int) $m[2], 0, 0, 0];
        }
        if (preg_match('/\As(\d{1,3})\z/', $token, $m) === 1) {
            return $nextEpisode !== null ? [1, (int) $m[1], $nextEpisode, $nextEpisode, 0, 0, 0] : [3, (int) $m[1], 0, 0, 0, 0, 0];
        }
        if ($k + 2 < \count($tokens) && preg_match('/\A(?:19|20)\d\d\z/', $token) === 1
            && preg_match('/\A\d{1,2}\z/', $tokens[$k + 1]) === 1 && preg_match('/\A\d{1,2}\z/', $tokens[$k + 2]) === 1) {
            $month = (int) $tokens[$k + 1];
            $day = (int) $tokens[$k + 2];
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return [4, 0, 0, 0, (int) $token, $month, $day];
            }
        }
        if ($token === 'part' || $token === 'pt') {
            $part = $next !== null && preg_match('/\A\d{1,2}\z/', $next) === 1 ? (int) $next : null;
        } else {
            $part = preg_match('/\A(?:part|pt)(\d{1,2})\z/', $token, $m) === 1 ? (int) $m[1] : null;
        }
        if ($part !== null && $part >= 1) {
            return [5, 0, $part, $part, 0, 0, 0];
        }
        if ($token === 'ep' || $token === 'episode') {
            $number = $next !== null && preg_match('/\A\d{1,4}\z/', $next) === 1 ? (int) $next : null;
        } else {
            $number = preg_match('/\Aep(\d{1,4})\z/', $token, $m) === 1 ? (int) $m[1] : null;
        }

        return $number !== null && $number >= 1 ? [6, 0, $number, $number, 0, 0, 0] : null;
    }

    /**
     * Lowercase `[a-z0-9]+` tokens.
     *
     * @return list<string>
     */
    private static function w62Tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w623', self::W62_PARSE_INFO_PIPE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
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
                $handle = self::w62RustFfiState('ffi');
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
     * Calls the Wave 62.3 ParseInfoPipe export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_PARSE_INFO_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w623_parse_info_pipe_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
