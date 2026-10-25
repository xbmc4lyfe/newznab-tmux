<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

class MoviesProcessor
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_media_catalogue_processor_plan(
            uint32_t processor,
            uint32_t override_kind,
            int64_t override_int,
            const char *override_ptr,
            size_t override_len,
            uint32_t has_default,
            int64_t default_lookup,
            uint32_t echo_output,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustMediaCatalogueProcessorsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int NEED_DEFAULT = -2;

    private const string FFI_SYMBOL = 'nntmux_w34_media_catalogue_processor_plan';

    private const int RUST_PROCESSOR = 4;

    private const string LABEL = 'Movies';

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private bool $echooutput;

    public function __construct(bool $echooutput)
    {
        $this->echooutput = $echooutput;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * @param  int|string|null  $processMovies  0/1/2 or '' to read from settings
     *
     * @throws GuzzleException
     */
    public function process(string $groupID = '', string $guidChar = '', int|string|null $processMovies = ''): void
    {
        $plan = self::dispatchPlan(
            $processMovies,
            static fn (): int => (int) app(ConfigurationProvider::class)->metadata()->movieLookup->value,
            $this->echooutput,
        );
        if ($plan['dispatch']) {
            (new MovieService)->processMovieReleases($groupID, $guidChar, $plan['mode']);
        }
    }

    /**
     * Resolves the movie lookup mode (a numeric `$processMovies` overrides the setting), the
     * dispatch gate, echo configuration and dispatch summary. The default is only read when
     * the override is not numeric.
     *
     * @param  int|Closure(): int  $defaultLookup
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlan(int|string|null $processMovies, int|Closure $defaultLookup, bool $echoOutput): array
    {
        return self::usesRustFfi()
            ? self::dispatchPlanNative($processMovies, $defaultLookup, $echoOutput)
            : self::dispatchPlanPhp($processMovies, $defaultLookup, $echoOutput);
    }

    /**
     * @param  int|Closure(): int  $defaultLookup
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanNative(int|string|null $processMovies, int|Closure $defaultLookup, bool $echoOutput): array
    {
        $kind = match (true) {
            $processMovies === null => 0,
            \is_int($processMovies) => 1,
            default => 2,
        };
        $intValue = \is_int($processMovies) ? $processMovies : 0;
        $text = \is_string($processMovies) ? $processMovies : null;
        $length = $text === null ? 0 : strlen($text);
        $default = \is_int($defaultLookup) ? $defaultLookup : null;

        $status = 0;
        $fields = self::callNative(self::FFI_SYMBOL, [self::RUST_PROCESSOR, $kind, $intValue, $text, $length, $default === null ? 0 : 1, $default ?? 0, (int) $echoOutput], $status);
        if ($fields === null && $status === self::NEED_DEFAULT && $defaultLookup instanceof Closure) {
            $default = $defaultLookup();
            $fields = self::callNative(self::FFI_SYMBOL, [self::RUST_PROCESSOR, $kind, $intValue, $text, $length, 1, $default, (int) $echoOutput], $status);
        }

        return ($fields === null ? null : self::decodePlan($fields))
            ?? self::dispatchPlanPhp($processMovies, $default ?? $defaultLookup, $echoOutput);
    }

    /**
     * @param  int|Closure(): int  $defaultLookup
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanPhp(int|string|null $processMovies, int|Closure $defaultLookup, bool $echoOutput): array
    {
        $fromOverride = is_numeric($processMovies);
        $mode = $fromOverride
            ? (int) $processMovies
            : ($defaultLookup instanceof Closure ? $defaultLookup() : $defaultLookup);
        $dispatch = $mode > 0;
        $source = $fromOverride ? 'override' : 'setting';

        return [
            'dispatch' => $dispatch,
            'mode' => $mode,
            'echo' => $echoOutput,
            'source' => $source,
            'summary' => sprintf(
                '%s lookup: mode=%d source=%s dispatch=%s echo=%s',
                self::LABEL,
                $mode,
                $source,
                $dispatch ? 'yes' : 'no',
                $echoOutput ? 'on' : 'off',
            ),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}|null
     */
    private static function decodePlan(array $fields): ?array
    {
        if (count($fields) !== 5
            || ! in_array($fields[0], ['0', '1'], true)
            || ! in_array($fields[2], ['0', '1'], true)
            || ! in_array($fields[3], ['setting', 'override'], true)
            || (string) (int) $fields[1] !== $fields[1]) {
            return null;
        }

        return [
            'dispatch' => $fields[0] === '1',
            'mode' => (int) $fields[1],
            'echo' => $fields[2] === '1',
            'source' => $fields[3],
            'summary' => $fields[4],
        ];
    }

    /**
     * @param  non-empty-string  $symbol
     * @param  list<int|string|null>  $args
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int &$status = 0): ?array
    {
        $status = 0;
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -2) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                $status = is_int($written) ? $written : 0;

                return null;
            }

            return explode("\x1F", FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
