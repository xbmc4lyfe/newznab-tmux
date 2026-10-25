<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\PredbImport.
 *
 * @property string $title
 * @property string|null $nfo
 * @property string|null $size
 * @property string|null $category
 * @property string|null $predate
 * @property string $source
 * @property int $requestid
 * @property int $groups_id FK to groups
 * @property bool $nuked Is this pre nuked? 0 no 2 yes 1 un nuked 3 mod nuked
 * @property string|null $nukereason If this pre is nuked, what is the reason?
 * @property string|null $files How many files does this pre have ?
 * @property string $filename
 * @property bool $searched
 * @property string|null $groupname
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereFiles($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereGroupname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereGroupsId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereNfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereNuked($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereNukereason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport wherePredate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereRequestid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereSearched($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport whereTitle($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PredbImport query()
 */
class PredbImport extends Model
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbCheckRebuildSuggestionsImportParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w373_predb_import_summarize(
            uint32_t op,
            uint32_t flags,
            const char *text_ptr,
            size_t text_len,
            int64_t value,
            char *out_buf,
            size_t out_cap
        );
        C;

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    private const array NUKE_LABELS = [0 => 'none', 1 => 'unnuked', 2 => 'nuked', 3 => 'modnuked'];

    private const int OP_NUKE = 0;

    private const int OP_TITLE = 1;

    private const int HAS_TEXT = 1;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $rustFfiOverride = null;

    private static FFI|false|null $rustFfiHandle = null;

    /**
     * @var bool
     */
    public $timestamps = false;

    public static function useRustFfi(?bool $enabled): void
    {
        self::$rustFfiOverride = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$rustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$rustFfiOverride = null;
        self::$rustFfiHandle = null;
    }

    /**
     * Summarizes the `nuked` code (0 none, 1 un-nuked, 2 nuked, 3 mod-nuked) with its trimmed
     * reason; a row is inconsistent when the code is unknown or a never-nuked pre has a reason.
     *
     * @return array{nuked: int, label: string, is_nuked: bool, was_nuked: bool, reason: ?string, has_reason: bool, consistent: bool}
     */
    public static function summarizeNukeStatus(int $nuked, ?string $nukeReason): array
    {
        return (self::usesRustFfi() ? self::summarizeNukeStatusNative($nuked, $nukeReason) : null) ?? self::summarizeNukeStatusPhp($nuked, $nukeReason);
    }

    /**
     * Trims an imported title, collapses whitespace runs to one space, truncates it to
     * `$maxBytes` bytes when positive and extracts a trailing `-GROUP` from space-free titles.
     *
     * @return array{title: string, bytes: int, valid_utf8: bool, has_control: bool, truncated: bool, group: ?string}
     */
    public static function normalizeImportTitle(string $title, int $maxBytes = 0): array
    {
        return (self::usesRustFfi() ? self::normalizeImportTitleNative($title, $maxBytes) : null) ?? self::normalizeImportTitlePhp($title, $maxBytes);
    }

    /**
     * @return array{nuked: int, label: string, is_nuked: bool, was_nuked: bool, reason: ?string, has_reason: bool, consistent: bool}|null
     */
    private static function summarizeNukeStatusNative(int $nuked, ?string $nukeReason): ?array
    {
        $text = $nukeReason ?? '';
        $fields = self::callNative(
            'nntmux_w373_predb_import_summarize',
            [self::OP_NUKE, $nukeReason === null ? 0 : self::HAS_TEXT, $text, strlen($text), $nuked],
            64 + strlen($text),
            7,
        );
        if ($fields === null || count($fields) !== 7 || $fields[0] !== (string) $nuked
            || $fields[1] !== (self::NUKE_LABELS[$nuked] ?? 'unknown')) {
            return null;
        }
        $hasReason = $fields[4] === '1';

        return [
            'nuked' => $nuked,
            'label' => $fields[1],
            'is_nuked' => $fields[2] === '1',
            'was_nuked' => $fields[3] === '1',
            'reason' => $hasReason ? $fields[6] : null,
            'has_reason' => $hasReason,
            'consistent' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{nuked: int, label: string, is_nuked: bool, was_nuked: bool, reason: ?string, has_reason: bool, consistent: bool}
     */
    private static function summarizeNukeStatusPhp(int $nuked, ?string $nukeReason): array
    {
        $reason = $nukeReason === null ? '' : trim($nukeReason, self::TRIM_CHARS);
        $hasReason = $reason !== '';
        $known = isset(self::NUKE_LABELS[$nuked]);

        return [
            'nuked' => $nuked,
            'label' => self::NUKE_LABELS[$nuked] ?? 'unknown',
            'is_nuked' => $nuked === 2 || $nuked === 3,
            'was_nuked' => $nuked >= 1 && $nuked <= 3,
            'reason' => $hasReason ? $reason : null,
            'has_reason' => $hasReason,
            'consistent' => $known && ! ($nuked === 0 && $hasReason),
        ];
    }

    /**
     * @return array{title: string, bytes: int, valid_utf8: bool, has_control: bool, truncated: bool, group: ?string}|null
     */
    private static function normalizeImportTitleNative(string $title, int $maxBytes): ?array
    {
        $fields = self::callNative(
            'nntmux_w373_predb_import_summarize',
            [self::OP_TITLE, self::HAS_TEXT, $title, strlen($title), $maxBytes],
            64 + 2 * strlen($title),
            7,
        );
        // The tail is "<title>\x1F<group>" with the title length in field 5, so both may hold any byte.
        if ($fields === null || count($fields) !== 7 || ! ctype_digit($fields[0]) || ! ctype_digit($fields[5])
            || strlen($fields[6]) <= (int) $fields[5] || $fields[6][(int) $fields[5]] !== self::FIELD_SEPARATOR) {
            return null;
        }
        $length = (int) $fields[5];

        return [
            'title' => substr($fields[6], 0, $length),
            'bytes' => (int) $fields[0],
            'valid_utf8' => $fields[1] === '1',
            'has_control' => $fields[2] === '1',
            'truncated' => $fields[3] === '1',
            'group' => $fields[4] === '1' ? substr($fields[6], $length + 1) : null,
        ];
    }

    /**
     * @return array{title: string, bytes: int, valid_utf8: bool, has_control: bool, truncated: bool, group: ?string}
     */
    private static function normalizeImportTitlePhp(string $title, int $maxBytes): array
    {
        $normalized = (string) preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', trim($title, self::TRIM_CHARS));
        $truncated = $maxBytes > 0 && strlen($normalized) > $maxBytes;
        if ($truncated) {
            $normalized = substr($normalized, 0, $maxBytes);
        }
        $dash = strrpos($normalized, '-');
        $hasGroup = ! str_contains($normalized, ' ') && $dash !== false && $dash > 0 && $dash < strlen($normalized) - 1;

        return [
            'title' => $normalized,
            'bytes' => strlen($normalized),
            'valid_utf8' => mb_check_encoding($normalized, 'UTF-8'),
            'has_control' => preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1,
            'truncated' => $truncated,
            'group' => $hasGroup ? substr($normalized, $dash + 1) : null,
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private static function rustFfi(): ?FFI
    {
        if (self::$rustFfiHandle === null) {
            self::$rustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w373', self::FFI_CDEF) ?? false;
        }

        return self::$rustFfiHandle === false ? null : self::$rustFfiHandle;
    }
}
