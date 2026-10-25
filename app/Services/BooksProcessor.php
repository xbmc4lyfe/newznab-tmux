<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class BooksProcessor
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

    private const string FFI_SYMBOL = 'nntmux_w34_media_catalogue_processor_plan';

    private const int RUST_PROCESSOR = 1;

    private const string LABEL = 'Books';

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

    public function process(string $groupID = '', string $guidChar = ''): void
    {
        $plan = self::dispatchPlan((int) app(ConfigurationProvider::class)->metadata()->bookLookup->value, $this->echooutput);
        if ($plan['dispatch']) {
            (new BookService)->processBookReleases($groupID, $guidChar);
        }
    }

    /**
     * Resolves the book lookup gate, echo configuration and dispatch summary.
     *
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlan(int $lookupValue, bool $echoOutput): array
    {
        return self::usesRustFfi()
            ? self::dispatchPlanNative($lookupValue, $echoOutput)
            : self::dispatchPlanPhp($lookupValue, $echoOutput);
    }

    /**
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanNative(int $lookupValue, bool $echoOutput): array
    {
        $fields = self::callNative(self::FFI_SYMBOL, [self::RUST_PROCESSOR, 0, 0, null, 0, 1, $lookupValue, (int) $echoOutput]);

        return ($fields === null ? null : self::decodePlan($fields))
            ?? self::dispatchPlanPhp($lookupValue, $echoOutput);
    }

    /**
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanPhp(int $lookupValue, bool $echoOutput): array
    {
        $dispatch = $lookupValue !== 0;

        return [
            'dispatch' => $dispatch,
            'mode' => $lookupValue,
            'echo' => $echoOutput,
            'source' => 'setting',
            'summary' => sprintf(
                '%s lookup: mode=%d source=setting dispatch=%s echo=%s',
                self::LABEL,
                $lookupValue,
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
            || $fields[3] !== 'setting'
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
    private static function callNative(string $symbol, array $args): ?array
    {
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
