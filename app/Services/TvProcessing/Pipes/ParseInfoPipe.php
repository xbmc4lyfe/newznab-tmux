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
