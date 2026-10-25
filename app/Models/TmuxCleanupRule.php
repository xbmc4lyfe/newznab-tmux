<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** @property string $rule */
final class TmuxCleanupRule extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_tmux_cleanup_rule_normalize(
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** `SettingsPageCatalog::cleanupRules()` in catalogue order. */
    public const array KNOWN_RULES = ['blacklist', 'blfiles', 'executable', 'gibberish', 'hashed', 'installbin', 'passworded', 'passwordurl', 'sample', 'scr', 'short', 'size', 'nzb', 'codec'];

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIngestionTmuxEnumsCleanupModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    /** PHP's default `trim()` set. */
    private const string TRIM_CHARACTERS = " \t\n\r\0\x0B";

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    public $timestamps = false;

    protected $guarded = [];

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Rust toggles for the cleaning-domain enums, which cannot declare static properties.
     *
     * @var array<class-string, bool>
     */
    private static array $enumUseRustFfi = [];

    /**
     * Resolved cleaning-domain FFI handles for those enums; `false` caches a failed resolution.
     *
     * @var array<class-string, FFI|false>
     */
    private static array $enumFfi = [];

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Explicit Rust toggle stored for `$enum`, or null when it uses its own default.
     *
     * @param  class-string  $enum
     */
    public static function enumRustFfiToggle(string $enum): ?bool
    {
        return self::$enumUseRustFfi[$enum] ?? null;
    }

    /**
     * @param  class-string  $enum
     */
    public static function setEnumRustFfiToggle(string $enum, ?bool $use): void
    {
        if ($use === null) {
            unset(self::$enumUseRustFfi[$enum]);

            return;
        }
        self::$enumUseRustFfi[$enum] = $use;
    }

    /**
     * Cached cleaning-domain FFI handle for `$enum`, resolved once with its `$cdef`.
     *
     * @param  class-string  $enum
     */
    public static function enumFfi(string $enum, string $cdef): ?FFI
    {
        if (! \array_key_exists($enum, self::$enumFfi)) {
            self::$enumFfi[$enum] = RustPipelineLibrary::resolveDomain('cleaning', $cdef) ?? false;
        }

        return self::$enumFfi[$enum] === false ? null : self::$enumFfi[$enum];
    }

    /**
     * Normalizes a stored cleanup rule: trimmed, ASCII-lowercased and matched against the
     * cleanup catalogue. `position` is the catalogue index of a known rule.
     *
     * @return array{rule: string, is_valid: bool, position: ?int}
     */
    public static function normalizeCleanupPattern(string $rawRule): array
    {
        return self::usesRustFfi() ? self::normalizeCleanupPatternNative($rawRule) : self::normalizeCleanupPatternPhp($rawRule);
    }

    /**
     * @return array{rule: string, is_valid: bool, position: ?int}
     */
    public static function normalizeCleanupPatternNative(string $rawRule): array
    {
        $fields = self::callCleaningNative(self::ffi(), 'nntmux_w35_tmux_cleanup_rule_normalize', [$rawRule, \strlen($rawRule)]);
        if ($fields === null || \count($fields) !== 3 || $fields[0] === null || ($fields[1] !== '0' && $fields[1] !== '1')
            || ($fields[1] === '1') !== ($fields[2] !== null)
            || ($fields[2] !== null && ! isset(self::KNOWN_RULES[(int) $fields[2]]))) {
            return self::normalizeCleanupPatternPhp($rawRule);
        }

        return [
            'rule' => $fields[0],
            'is_valid' => $fields[1] === '1',
            'position' => $fields[2] === null ? null : (int) $fields[2],
        ];
    }

    /**
     * @return array{rule: string, is_valid: bool, position: ?int}
     */
    public static function normalizeCleanupPatternPhp(string $rawRule): array
    {
        $rule = strtolower(trim($rawRule, self::TRIM_CHARACTERS));
        $position = array_search($rule, self::KNOWN_RULES, true);

        return [
            'rule' => $rule,
            'is_valid' => $position !== false,
            'position' => $position === false ? null : $position,
        ];
    }

    /**
     * Calls a cleaning-domain export that writes `0x1F`-separated fields (`0x1E` alone for null)
     * into the shared scratch buffer, retrying once when it reports the required size.
     *
     * @internal Shared by the cleaning-domain model and enum helpers.
     *
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    public static function callCleaningNative(?FFI $ffi, string $symbol, array $args): ?array
    {
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
