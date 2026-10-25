<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\Collection.
 *
 * @property int $id
 * @property string $subject
 * @property string $fromname
 * @property string|null $date
 * @property string $xref
 * @property int $totalfiles
 * @property int $groups_id
 * @property string $collectionhash Raw 20-byte SHA-1 digest
 * @property int $collection_regexes_id FK to collection_regexes.id
 * @property string|null $dateadded
 * @property string $added
 * @property bool $filecheck
 * @property int $filesize
 * @property int|null $releases_id
 * @property string $noise
 * @property string|null $udate Computed Unix timestamp from raw query
 * @property string|null $gname Computed group name from join
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereCollectionRegexesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereCollectionhash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereDateadded($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereFilecheck($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereFilesize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereFromname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereGroupsId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereNoise($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereTotalfiles($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection whereXref($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Collection query()
 */
class Collection extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w33_collection_normalize_summary(
            int64_t total_files,
            int64_t file_size,
            uint32_t file_check,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustBinaryPartCollectionModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public $timestamps = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a collection's subject/poster/file counters and builds its summary key.
     *
     * @return array{subject: string, fromname: string, totalfiles: int, filesize: int, filecheck: int, average_file_size: int, summary_key: string}
     */
    public static function normalizeCollectionSummary(string $subject, string $fromName, int $totalFiles, int $fileSize, bool $fileCheck): array
    {
        return self::usesRustFfi()
            ? self::normalizeCollectionSummaryNative($subject, $fromName, $totalFiles, $fileSize, $fileCheck)
            : self::normalizeCollectionSummaryPhp($subject, $fromName, $totalFiles, $fileSize, $fileCheck);
    }

    /**
     * @return array{subject: string, fromname: string, totalfiles: int, filesize: int, filecheck: int, average_file_size: int, summary_key: string}
     */
    public static function normalizeCollectionSummaryNative(string $subject, string $fromName, int $totalFiles, int $fileSize, bool $fileCheck): array
    {
        $input = $subject.self::FIELD_SEPARATOR.$fromName;
        $fields = self::callNative('nntmux_w33_collection_normalize_summary', [$totalFiles, $fileSize, $fileCheck ? 1 : 0, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizeCollectionSummaryPhp($subject, $fromName, $totalFiles, $fileSize, $fileCheck);
        }

        return [
            'subject' => $fields[0],
            'fromname' => $fields[1],
            'totalfiles' => (int) $fields[2],
            'filesize' => (int) $fields[3],
            'filecheck' => (int) $fields[4],
            'average_file_size' => (int) $fields[5],
            'summary_key' => $fields[6],
        ];
    }

    /**
     * @return array{subject: string, fromname: string, totalfiles: int, filesize: int, filecheck: int, average_file_size: int, summary_key: string}
     */
    public static function normalizeCollectionSummaryPhp(string $subject, string $fromName, int $totalFiles, int $fileSize, bool $fileCheck): array
    {
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $subject) ?? $subject;
        $subject = mb_substr(trim($collapsed, self::WHITESPACE), 0, 255, 'UTF-8');
        $fromName = mb_substr(trim($fromName, self::WHITESPACE), 0, 255, 'UTF-8');
        $totalFiles = max(0, min($totalFiles, 4294967295));
        $fileSize = max(0, $fileSize);

        return [
            'subject' => $subject,
            'fromname' => $fromName,
            'totalfiles' => $totalFiles,
            'filesize' => $fileSize,
            'filecheck' => $fileCheck ? 1 : 0,
            'average_file_size' => $totalFiles > 0 ? intdiv($fileSize, $totalFiles) : 0,
            'summary_key' => strtolower($subject).'|'.strtolower($fromName).'|'.$totalFiles,
        ];
    }

    /**
     * @param  list<int|string>  $args
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
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
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

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
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
