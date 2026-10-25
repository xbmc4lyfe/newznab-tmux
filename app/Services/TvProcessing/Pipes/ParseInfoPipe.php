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
}
