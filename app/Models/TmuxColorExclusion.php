<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;

/** @property int $color */
final class TmuxColorExclusion extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_tmux_color_exclusion_normalize(
            int64_t raw_color,
            int64_t colors_start,
            int64_t colors_end,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIngestionTmuxEnumsCleanupModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    /** Highest tmux 256-colour palette index (`tinyint unsigned` storage). */
    private const int COLOR_MAX = 255;

    public $timestamps = false;

    protected $guarded = [];

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes an excluded tmux colour: the code is clamped to the 0-255 palette, the
     * configured colour range is clamped and ordered, and `in_palette` reports whether a valid
     * code falls inside that range.
     *
     * @return array{color: int, is_valid: bool, in_palette: bool, range_start: int, range_end: int}
     */
    public static function normalizeExcludedColorCode(int $rawColor, int $colorsStart = 1, int $colorsEnd = 250): array
    {
        return self::usesRustFfi()
            ? self::normalizeExcludedColorCodeNative($rawColor, $colorsStart, $colorsEnd)
            : self::normalizeExcludedColorCodePhp($rawColor, $colorsStart, $colorsEnd);
    }

    /**
     * @return array{color: int, is_valid: bool, in_palette: bool, range_start: int, range_end: int}
     */
    public static function normalizeExcludedColorCodeNative(int $rawColor, int $colorsStart = 1, int $colorsEnd = 250): array
    {
        $fields = TmuxCleanupRule::callCleaningNative(self::ffi(), 'nntmux_w35_tmux_color_exclusion_normalize', [$rawColor, $colorsStart, $colorsEnd]);
        if ($fields === null || \count($fields) !== 5 || \in_array(null, $fields, true)
            || ! \in_array($fields[1], ['0', '1'], true) || ! \in_array($fields[2], ['0', '1'], true)) {
            return self::normalizeExcludedColorCodePhp($rawColor, $colorsStart, $colorsEnd);
        }

        return [
            'color' => (int) $fields[0],
            'is_valid' => $fields[1] === '1',
            'in_palette' => $fields[2] === '1',
            'range_start' => (int) $fields[3],
            'range_end' => (int) $fields[4],
        ];
    }

    /**
     * @return array{color: int, is_valid: bool, in_palette: bool, range_start: int, range_end: int}
     */
    public static function normalizeExcludedColorCodePhp(int $rawColor, int $colorsStart = 1, int $colorsEnd = 250): array
    {
        $start = max(0, min($colorsStart, self::COLOR_MAX));
        $end = max(0, min($colorsEnd, self::COLOR_MAX));
        [$low, $high] = $start <= $end ? [$start, $end] : [$end, $start];
        $color = max(0, min($rawColor, self::COLOR_MAX));
        $isValid = $rawColor >= 0 && $rawColor <= self::COLOR_MAX;

        return [
            'color' => $color,
            'is_valid' => $isValid,
            'in_palette' => $isValid && $color >= $low && $color <= $high,
            'range_start' => $low,
            'range_end' => $high,
        ];
    }

    protected function casts(): array
    {
        return ['color' => 'integer'];
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
