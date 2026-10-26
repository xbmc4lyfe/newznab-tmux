<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum ConfigurationDomain: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_cfgd_resolve(const char *ptr, size_t len);
        ssize_t nntmux_cfgd_text(const char *ptr, size_t len, int32_t field, char *out, size_t cap);
        C;

    /** `nntmux_cfgd_text` field selectors. */
    public const int TEXT_LABEL = 0;

    public const int TEXT_TABLE = 1;

    public const int TEXT_CACHE_KEY = 2;

    public const int TEXT_MODEL_CLASS = 3;

    case Site = 'site';
    case Registration = 'registration';
    case Ingestion = 'ingestion';
    case PostProcessing = 'post-processing';
    case Metadata = 'metadata';
    case Tmux = 'tmux';

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Site => 'Site',
            self::Registration => 'Registration',
            self::Ingestion => 'Ingestion',
            self::PostProcessing => 'Post Processing',
            self::Metadata => 'Metadata',
            self::Tmux => 'Tmux',
        };
    }

    public function labelNative(): string
    {
        return $this->textNative(self::TEXT_LABEL) ?? $this->labelPhp();
    }

    public function table(): string
    {
        return self::usesRustFfi() ? $this->tableNative() : $this->tablePhp();
    }

    public function tablePhp(): string
    {
        return match ($this) {
            self::Site => 'site_configurations',
            self::Registration => 'registration_configurations',
            self::Ingestion => 'ingestion_configurations',
            self::PostProcessing => 'post_processing_configurations',
            self::Metadata => 'metadata_configurations',
            self::Tmux => 'tmux_configurations',
        };
    }

    public function tableNative(): string
    {
        return $this->textNative(self::TEXT_TABLE) ?? $this->tablePhp();
    }

    /**
     * Resolve a backing value through Rust; null for an unknown value.
     */
    public static function tryFromNative(string $value): ?self
    {
        if (($ffi = self::ffi()) === null) {
            return self::tryFrom($value);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_cfgd_resolve($value, \strlen($value));
        } catch (Throwable) {
            return self::tryFrom($value);
        }
        if (! \is_int($index)) {
            return self::tryFrom($value);
        }

        return $index < 0 ? null : (self::cases()[$index] ?? self::tryFrom($value));
    }

    /**
     * Text `$field` ({@see TEXT_LABEL}..{@see TEXT_MODEL_CLASS}) for this domain from Rust, or
     * null when the library is unavailable or rejects the request.
     */
    public function textNative(int $field): ?string
    {
        if (($ffi = self::ffi()) === null || ($buffer = self::textBuffer($ffi)) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cfgd_text($this->value, \strlen($this->value), $field, $buffer, 64);
            if (! \is_int($written) || $written <= 0) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Reusable `char[64]` text buffer for `$ffi`. */
    private static function textBuffer(FFI $ffi): ?CData
    {
        static $buffer = null;
        static $owner = null;
        if ($owner !== $ffi || $buffer === null) {
            try {
                $buffer = $ffi->new('char[64]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffer;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W61.6 benchmark clears 1.5x on all four metrics.
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_DOMAIN_FFI_CDEF = <<<'C'
        int32_t nntmux_w616_configuration_domain_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W61_DOMAIN_HEADER = 24;

    private const int W61_MAX_ITEMS = 1_048_576;

    /** `ConfigurationProvider::cacheKey()` prefix. */
    private const string W61_CACHE_PREFIX = 'configuration:';

    /** Longest valid setting name. */
    private const int W61_SETTING_CAP = 64;

    private const string W61_SETTING_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789.-';

    /** Backing value => case index (6 is unknown). */
    private const array W61_VALUES = ['site' => 0, 'registration' => 1, 'ingestion' => 2, 'post-processing' => 3, 'metadata' => 4, 'tmux' => 5];

    /** Case name => case index. */
    private const array W61_NAMES = ['Site' => 0, 'Registration' => 1, 'Ingestion' => 2, 'PostProcessing' => 3, 'Metadata' => 4, 'Tmux' => 5];

    /**
     * Classify configuration keys in order. Keys resolve by backing value (source 0, kind domain), case name
     * (1, domain), a `configuration:` cache key (2, cache key), the backing value of `str_replace('_', '-',
     * strtolower(trim($key)))` (3, domain), a backing value before its first `.` (4, setting), else unknown case
     * 6 (5, unknown). Kinds: 0 domain, 1 setting, 2 cache key, 3 unknown. Flags: 1 unknown domain, 2 empty
     * setting, 4 setting byte outside `[a-z0-9.-]`, 8 setting over 64 bytes.
     *
     * Layout: `[0]` count, `[1..7]` per case, `[8..13]` per source, `[14..17]` per kind, `[18]` valid settings,
     * `[19]` flagged and `[20]` the first flagged index (-1), `[21]` setting bytes, `[22]` longest key, `[23]`
     * FNV-1a digest; then per key `case | source << 3 | kind << 6 | flags << 8 | valid setting << 12 |
     * min(setting bytes, 65535) << 13 | min(key bytes, 2^20-1) << 29`.
     *
     * @param  array<array-key, string>  $keys
     * @return list<int>
     */
    public static function planConfigurationDomainBatch(array $keys): array
    {
        return (self::usesW61RustFfi() ? self::planConfigurationDomainBatchNative($keys) : null)
            ?? self::planConfigurationDomainBatchPhp($keys);
    }

    /**
     * @param  array<array-key, string>  $keys
     * @return list<int>
     */
    public static function planConfigurationDomainBatchPhp(array $keys): array
    {
        $keys = array_values($keys);
        $out = array_fill(0, self::W61_DOMAIN_HEADER, 0);
        $out[0] = \count($keys);
        $out[20] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($keys as $at => $key) {
            $key = (string) $key;
            $setting = null;
            if (isset(self::W61_VALUES[$key])) {
                [$case, $source, $kind] = [self::W61_VALUES[$key], 0, 0];
            } elseif (isset(self::W61_NAMES[$key])) {
                [$case, $source, $kind] = [self::W61_NAMES[$key], 1, 0];
            } elseif (str_starts_with($key, self::W61_CACHE_PREFIX)) {
                [$case, $source, $kind] = [self::W61_VALUES[substr($key, 14)] ?? 6, 2, 2];
            } else {
                $normalized = str_replace('_', '-', strtolower(trim($key)));
                if (isset(self::W61_VALUES[$normalized])) {
                    [$case, $source, $kind] = [self::W61_VALUES[$normalized], 3, 0];
                } else {
                    $dot = strpos($normalized, '.');
                    $case = $dot === false ? null : (self::W61_VALUES[substr($normalized, 0, $dot)] ?? null);
                    if ($case === null || $dot === false) {
                        [$case, $source, $kind] = [6, 5, 3];
                    } else {
                        [$source, $kind] = [4, 1];
                        $setting = substr($normalized, $dot + 1);
                    }
                }
            }
            $settingLength = $setting === null ? 0 : \strlen($setting);
            $flags = $setting === null
                ? ($case === 6 ? 1 : 0)
                : ($setting === '' ? 2 : 0) | (strspn($setting, self::W61_SETTING_BYTES) !== $settingLength ? 4 : 0) | ($settingLength > self::W61_SETTING_CAP ? 8 : 0);
            $valid = $kind === 1 && $flags === 0 ? 1 : 0;
            $out[1 + $case]++;
            $out[8 + $source]++;
            $out[14 + $kind]++;
            $out[18] += $valid;
            if ($flags !== 0) {
                $out[19]++;
                if ($out[20] < 0) {
                    $out[20] = $at;
                }
            }
            $out[21] += $settingLength;
            $out[22] = max($out[22], \strlen($key));
            $row = $case | $source << 3 | $kind << 6 | $flags << 8 | $valid << 12 | min($settingLength, 65_535) << 13 | min(\strlen($key), 1_048_575) << 29;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[23] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $keys
     * @return list<int>|null
     */
    public static function planConfigurationDomainBatchNative(array $keys): ?array
    {
        $count = \count($keys);
        if ($count > self::W61_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w61Strings(array_values($keys));
        $words = self::W61_DOMAIN_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w61Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w616_configuration_domain_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $words));
    }

    /**
     * Run `$callback` with the Wave 61.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $forced = self::w61RustFfiState();

        return \is_bool($forced) ? $forced : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 61.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'binding'` returns the cached binding and an output buffer of at least `$val` values, or null.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w616', self::W61_DOMAIN_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 61.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w61Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w61RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w61Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }
}
