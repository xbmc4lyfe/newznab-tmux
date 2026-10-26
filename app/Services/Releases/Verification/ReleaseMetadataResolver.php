<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Models\Category;
use App\Models\Predb;
use App\Models\UsenetGroup;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\PredbIdentityKey;
use App\Services\ReleaseCleaningService;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use App\Support\Utf8;
use FFI;
use FFI\CData;
use Throwable;

class ReleaseMetadataResolver
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w18_verification_metadata_fingerprint(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Enable only after paired common/uncommon mean and p99 all reach 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w18_verification_metadata_fingerprint($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(private readonly ReleaseCleaningService $cleaning, private readonly ?PayloadIdentityResolver $payloadIdentity = null, private readonly ?CategorizationService $categorization = null) {}

    /** @param array<string, mixed> $manifest
     * @return array{name: string, searchname: string, fromname: string, predb_id: int, isrenamed: int, categories_id: int, naming_regex_id: int}
     */
    public function resolve(array $manifest): array
    {
        $initial = self::normalizeManifestMetadata($manifest, null);
        $rawName = $initial['rawName'];
        $from = $initial['from'];
        $groupId = (int) ($manifest['groups_id'] ?? 0);
        $group = $groupId > 0 ? (string) UsenetGroup::query()->whereKey($groupId)->value('name') : (string) ($manifest['groups'][0] ?? '');
        $cleaned = $initial['importNameOverride'] ? null : $this->cleaning->releaseCleaner(
            $initial['collectionMetadata'] ? (string) $manifest['subject'] : $rawName,
            $initial['collectionMetadata'] ? (string) $manifest['fromname'] : $from,
            $group,
        );
        $normalized = $cleaned === null ? $initial : self::normalizeManifestMetadata($manifest, $cleaned);
        $name = $normalized['name'];
        $proper = $normalized['proper'];
        $predb = $normalized['predb'];
        if ($predb > 0) {
            $storedTitle = Predb::query()->whereKey($predb)->value('title');
            if (! is_string($storedTitle) || PredbSearchDocument::exactValue($storedTitle) !== PredbSearchDocument::exactValue($name)) {
                $predb = 0;
            }
        }
        if ($predb === 0 && $name !== '') {
            $match = Predb::matchPre($name);
            if ($match !== false) {
                $name = $match['title'];
                $predb = (int) $match['predb_id'];
                $proper = true;
            }
        }

        if (! $proper && $predb === 0) {
            $candidate = ($this->payloadIdentity ?? new PayloadIdentityResolver($this->cleaning))->resolve($manifest);
            if ($candidate !== null) {
                $existing = $this->cleaning->releaseNameFromFile($name);
                if ($existing === null || PredbIdentityKey::normalize($existing) === PredbIdentityKey::normalize($candidate)) {
                    $name = $candidate;
                    $proper = true;
                    $match = Predb::matchPre($name);
                    if ($match !== false && PredbSearchDocument::exactValue($match['title']) === PredbSearchDocument::exactValue($name)) {
                        $name = $match['title'];
                        $predb = (int) $match['predb_id'];
                    }
                }
            }
        }

        $explicitCategory = $normalized['explicitCategory'];
        $categoryId = $explicitCategory !== false && Category::query()->whereKey($explicitCategory)->where('status', Category::STATUS_ACTIVE)->exists()
            ? $explicitCategory : null;
        if ($categoryId === null) {
            $category = ($this->categorization ?? new CategorizationService)->determineCategory($groupId, $name, $from);
            $categoryId = $category['categories_id'] ?? Category::OTHER_MISC;
        }

        $searchName = self::searchName($name, $rawName, $normalized['importMetadata']);

        return ['name' => $rawName, 'searchname' => $searchName, 'fromname' => $from,
            'predb_id' => $predb, 'isrenamed' => $proper ? 1 : 0,
            'categories_id' => $categoryId,
            'naming_regex_id' => $normalized['naming_regex_id']];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{collectionMetadata: bool, importMetadata: bool, importNameOverride: bool, rawName: string, from: string, name: string, proper: bool, predb: int, explicitCategory: int|false, searchName: string, naming_regex_id: int}
     */
    public static function normalizeManifestMetadata(array $manifest, mixed $cleaned): array
    {
        return self::$useRustFfi ? (self::normalizeManifestMetadataNative($manifest, $cleaned) ?? self::normalizeManifestMetadataPhp($manifest, $cleaned)) : self::normalizeManifestMetadataPhp($manifest, $cleaned);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{collectionMetadata: bool, importMetadata: bool, importNameOverride: bool, rawName: string, from: string, name: string, proper: bool, predb: int, explicitCategory: int|false, searchName: string, naming_regex_id: int}
     */
    public static function normalizeManifestMetadataPhp(array $manifest, mixed $cleaned): array
    {
        $subject = (string) $manifest['subject'];
        $poster = (string) $manifest['fromname'];
        $collectionMetadata = ($manifest['metadata_source'] ?? 'collection') === 'collection';
        $importMetadata = ($manifest['metadata_source'] ?? null) === 'import';
        $displaySubject = $importMetadata && is_string($manifest['original_subject'] ?? null) ? $manifest['original_subject'] : $subject;
        $rawName = Utf8::clean($collectionMetadata ? str_replace(['#', '@', '$', '%', '^', '§', '¨', '©', 'Ö'], '', $subject) : $displaySubject);
        $from = Utf8::clean($collectionMetadata ? trim($poster, "'") : $poster);
        $importNameOverride = $importMetadata && ($manifest['import_name_override'] ?? false) === true && $subject !== '';
        $name = $importNameOverride ? Utf8::clean($subject) : (is_array($cleaned) ? (string) ($cleaned['cleansubject'] ?? $rawName) : (is_string($cleaned) && $cleaned !== '' ? $cleaned : $rawName));
        $proper = is_array($cleaned) ? (bool) ($cleaned['properlynamed'] ?? false) : true;
        $predb = is_array($cleaned) ? max(0, (int) ($cleaned['predb'] ?? 0)) : 0;
        $explicitCategory = $importMetadata
            ? filter_var($manifest['categories_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return ['collectionMetadata' => $collectionMetadata, 'importMetadata' => $importMetadata,
            'importNameOverride' => $importNameOverride, 'rawName' => $rawName, 'from' => $from,
            'name' => $name, 'proper' => $proper, 'predb' => $predb, 'explicitCategory' => $explicitCategory,
            'searchName' => self::searchNamePhp($name, $rawName, $importMetadata),
            'naming_regex_id' => is_array($cleaned) ? (int) ($cleaned['id'] ?? 0) : 0];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{collectionMetadata: bool, importMetadata: bool, importNameOverride: bool, rawName: string, from: string, name: string, proper: bool, predb: int, explicitCategory: int|false, searchName: string, naming_regex_id: int}|null
     */
    public static function normalizeManifestMetadataNative(array $manifest, mixed $cleaned): ?array
    {
        // Subject/poster casts and encoding detection retain PHP's established semantics.
        // Pass only the normalization fields, never a full manifest/payload or its objects.
        if (! self::$useRustFfi || ! is_scalar($manifest['subject'] ?? '') || ! is_scalar($manifest['fromname'] ?? '')) {
            return null;
        }
        $result = self::computeNative(0, [(string) $manifest['subject'], (string) $manifest['fromname'],
            $manifest['metadata_source'] ?? null, $manifest['original_subject'] ?? null,
            $manifest['import_name_override'] ?? false, $manifest['categories_id'] ?? null, $cleaned])['value'] ?? null;
        if (! is_array($result) || ! is_bool($result['collectionMetadata'] ?? null) || ! is_bool($result['importMetadata'] ?? null)
            || ! is_bool($result['importNameOverride'] ?? null) || ! is_string($result['rawName'] ?? null)
            || ! is_string($result['from'] ?? null) || ! is_string($result['name'] ?? null)
            || ! is_bool($result['proper'] ?? null) || ! is_int($result['predb'] ?? null)
            || ! (is_int($result['explicitCategory'] ?? null) || ($result['explicitCategory'] ?? null) === false)
            || ! is_string($result['searchName'] ?? null) || ! is_int($result['naming_regex_id'] ?? null)) {
            return null;
        }

        return $result;
    }

    public static function searchName(string $name, string $rawName, bool $importMetadata): string
    {
        return self::$useRustFfi ? (self::searchNameNative($name, $rawName, $importMetadata) ?? self::searchNamePhp($name, $rawName, $importMetadata)) : self::searchNamePhp($name, $rawName, $importMetadata);
    }

    public static function searchNamePhp(string $name, string $rawName, bool $importMetadata): string
    {
        return ($importMetadata ? $name !== '' : ! empty($name)) ? Utf8::clean($name) : $rawName;
    }

    public static function searchNameNative(string $name, string $rawName, bool $importMetadata): ?string
    {
        $result = self::computeNative(1, [$name, $rawName, $importMetadata])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    // Rust is the default only while the paired W57.6 benchmark clears 1.5x on all four metrics.
    private const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        int32_t nntmux_w576_metadata_score_batch(const char *strings, size_t strings_len, const char *string_twin, size_t string_twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private const int W57_MAX_ITEMS = 1_048_576;

    public const int W57_METADATA_HEADER = 23;

    private const int W57_SIZE_CAP = 1_125_899_906_842_623;

    private const int W57_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * Score release metadata completeness over guids, searchnames, categories, sizes, totalparts and
     * postdates. Fields: guid (40 hex; bit 4 when it holds uppercase), searchname (non-empty after
     * `trim()`; bit 6 when trimming changed it), category 10-9999 (bit 8 when below 1000), size > 0,
     * totalpart > 0 and postdate (`YYYY-MM-DD HH:MM:SS`, month 1-12, day 1-31, hour < 24, minute and
     * second < 60); the score counts the six valid fields.
     *
     * Layout: `[0]` count, `[1]` complete, `[2]` guid, `[3]` uppercase guid, `[4]` searchname, `[5]`
     * trimmed searchname, `[6]` category, `[7]` misc category, `[8]` size, `[9]` totalpart, `[10]`
     * postdate, `[11]` total score, `[12]` score ppm of the maximum, `[13]` total valid size (sizes
     * clamped to 2^50-1, total capped at 2^62), `[14]` first incomplete index (-1 when none), `[15..21]`
     * per score, `[22]` FNV-1a digest; then per release `score | guid << 3 | upper << 4 | name << 5 |
     * trimmed << 6 | category << 7 | misc << 8 | size << 9 | parts << 10 | date << 11 | min(trimmed
     * bytes, 65535) << 12 | min(dots, 255) << 28 | year << 36 | month << 50` (0 year and month for an
     * invalid postdate).
     *
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, string>  $searchNames
     * @param  array<array-key, int>  $categories
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $totalParts
     * @param  array<array-key, string>  $postDates
     * @return list<int>
     */
    public static function scoreMetadataBatch(array $guids, array $searchNames, array $categories, array $sizes, array $totalParts, array $postDates): array
    {
        return (self::usesW57RustFfi() ? self::scoreMetadataBatchNative($guids, $searchNames, $categories, $sizes, $totalParts, $postDates) : null)
            ?? self::scoreMetadataBatchPhp($guids, $searchNames, $categories, $sizes, $totalParts, $postDates);
    }

    /**
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, string>  $searchNames
     * @param  array<array-key, int>  $categories
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $totalParts
     * @param  array<array-key, string>  $postDates
     * @return list<int>
     */
    public static function scoreMetadataBatchPhp(array $guids, array $searchNames, array $categories, array $sizes, array $totalParts, array $postDates): array
    {
        $guids = array_values($guids);
        $searchNames = array_values($searchNames);
        $categories = array_values($categories);
        $sizes = array_values($sizes);
        $totalParts = array_values($totalParts);
        $postDates = array_values($postDates);
        $count = \count($guids);
        $out = array_fill(0, self::W57_METADATA_HEADER, 0);
        $out[0] = $count;
        $out[14] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        for ($at = 0; $at < $count; $at++) {
            $guid = (string) $guids[$at];
            $name = (string) ($searchNames[$at] ?? '');
            $date = (string) ($postDates[$at] ?? '');
            $category = (int) ($categories[$at] ?? 0);
            $size = (int) ($sizes[$at] ?? 0);
            $parts = (int) ($totalParts[$at] ?? 0);
            $guidOk = \strlen($guid) === 40 && strspn($guid, '0123456789abcdefABCDEF') === 40;
            $upper = $guidOk && strspn($guid, '0123456789abcdef') !== 40;
            $trimmed = trim($name);
            $nameOk = $trimmed !== '';
            $nameTrimmed = \strlen($trimmed) !== \strlen($name);
            $categoryOk = $category >= 10 && $category <= 9999;
            $misc = $categoryOk && $category < 1000;
            $sizeOk = $size > 0;
            $partsOk = $parts > 0;
            $year = $month = 0;
            $dateOk = false;
            if (\strlen($date) === 19 && preg_match('/\A(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\z/', $date, $match) === 1) {
                $day = (int) $match[3];
                $dateOk = (int) $match[2] >= 1 && (int) $match[2] <= 12 && $day >= 1 && $day <= 31
                    && (int) $match[4] < 24 && (int) $match[5] < 60 && (int) $match[6] < 60;
                if ($dateOk) {
                    $year = (int) $match[1];
                    $month = (int) $match[2];
                }
            }
            $score = (int) $guidOk + (int) $nameOk + (int) $categoryOk + (int) $sizeOk + (int) $partsOk + (int) $dateOk;
            $out[1] += (int) ($score === 6);
            $out[2] += (int) $guidOk;
            $out[3] += (int) $upper;
            $out[4] += (int) $nameOk;
            $out[5] += (int) $nameTrimmed;
            $out[6] += (int) $categoryOk;
            $out[7] += (int) $misc;
            $out[8] += (int) $sizeOk;
            $out[9] += (int) $partsOk;
            $out[10] += (int) $dateOk;
            $out[11] += $score;
            if ($sizeOk) {
                $out[13] = min($out[13] + min($size, self::W57_SIZE_CAP), self::W57_TOTAL_CAP);
            }
            if ($score < 6 && $out[14] < 0) {
                $out[14] = $at;
            }
            $out[15 + $score]++;
            $row = $score | (int) $guidOk << 3 | (int) $upper << 4 | (int) $nameOk << 5 | (int) $nameTrimmed << 6 | (int) $categoryOk << 7
                | (int) $misc << 8 | (int) $sizeOk << 9 | (int) $partsOk << 10 | (int) $dateOk << 11 | min(\strlen($trimmed), 65_535) << 12
                | min(substr_count($trimmed, '.'), 255) << 28 | $year << 36 | $month << 50;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[12] = intdiv($out[11] * 1_000_000, 6 * $count);
        }
        $out[22] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust scores, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, string>  $searchNames
     * @param  array<array-key, int>  $categories
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $totalParts
     * @param  array<array-key, string>  $postDates
     * @return list<int>|null
     */
    public static function scoreMetadataBatchNative(array $guids, array $searchNames, array $categories, array $sizes, array $totalParts, array $postDates): ?array
    {
        $count = \count($guids);
        if ($count > self::W57_MAX_ITEMS || \count($searchNames) !== $count || \count($categories) !== $count || \count($sizes) !== $count
            || \count($totalParts) !== $count || \count($postDates) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w57Strings(array_map('strval', [...array_values($guids), ...array_values($searchNames), ...array_values($postDates)]));
        $ints = pack('q*', ...array_values($categories), ...array_values($sizes), ...array_values($totalParts));
        $fields = self::W57_METADATA_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w57Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w576_metadata_score_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 57.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $forced = self::w57RustFfiState();

        return \is_bool($forced) ? $forced : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 57.6 routing and binding in function statics (shared by every Wave 57.6 target, including readonly classes and enums).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w57` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w57', self::W57_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 57.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w57Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w57RustFfiState('binding', $fields);
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
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w57Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }
}
