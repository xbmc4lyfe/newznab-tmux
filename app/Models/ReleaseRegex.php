<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\ReleaseRegex.
 *
 * @property int $releases_id
 * @property int $collection_regex_id
 * @property int $naming_regex_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseRegex whereCollectionRegexId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseRegex whereNamingRegexId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseRegex whereReleasesId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseRegex newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseRegex newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseRegex query()
 */
class ReleaseRegex extends Model
{
    use HasCompositePrimaryKey;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_release_regex_normalize_link(
            int64_t releases_id,
            int64_t collection_regex_id,
            int64_t naming_regex_id,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustRegexIngestionConfigModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $dateFormat = false;

    /**
     * @var string
     */
    protected $primaryKey = 'releases_id';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['releases_id', 'collection_regex_id', 'naming_regex_id'];
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
     * Clamps a release-to-regex link row and derives its composite key.
     *
     * @return array{releases_id: int, collection_regex_id: int, naming_regex_id: int, has_collection_regex: bool, has_naming_regex: bool, valid: bool, composite_key: string}
     */
    public static function normalizeReleaseRegexLink(int $releasesId, int $collectionRegexId, int $namingRegexId): array
    {
        return self::usesRustFfi()
            ? self::normalizeReleaseRegexLinkNative($releasesId, $collectionRegexId, $namingRegexId)
            : self::normalizeReleaseRegexLinkPhp($releasesId, $collectionRegexId, $namingRegexId);
    }

    /**
     * @return array{releases_id: int, collection_regex_id: int, naming_regex_id: int, has_collection_regex: bool, has_naming_regex: bool, valid: bool, composite_key: string}
     */
    public static function normalizeReleaseRegexLinkNative(int $releasesId, int $collectionRegexId, int $namingRegexId): array
    {
        $fields = self::callNative('nntmux_w34_release_regex_normalize_link', [$releasesId, $collectionRegexId, $namingRegexId]);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizeReleaseRegexLinkPhp($releasesId, $collectionRegexId, $namingRegexId);
        }

        return [
            'releases_id' => (int) $fields[0],
            'collection_regex_id' => (int) $fields[1],
            'naming_regex_id' => (int) $fields[2],
            'has_collection_regex' => $fields[3] === '1',
            'has_naming_regex' => $fields[4] === '1',
            'valid' => $fields[5] === '1',
            'composite_key' => $fields[6],
        ];
    }

    /**
     * @return array{releases_id: int, collection_regex_id: int, naming_regex_id: int, has_collection_regex: bool, has_naming_regex: bool, valid: bool, composite_key: string}
     */
    public static function normalizeReleaseRegexLinkPhp(int $releasesId, int $collectionRegexId, int $namingRegexId): array
    {
        $releasesId = max(0, $releasesId);
        $collectionRegexId = max(0, $collectionRegexId);
        $namingRegexId = max(0, $namingRegexId);

        return [
            'releases_id' => $releasesId,
            'collection_regex_id' => $collectionRegexId,
            'naming_regex_id' => $namingRegexId,
            'has_collection_regex' => $collectionRegexId > 0,
            'has_naming_regex' => $namingRegexId > 0,
            'valid' => $releasesId > 0,
            'composite_key' => $releasesId.':'.$collectionRegexId.':'.$namingRegexId,
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
