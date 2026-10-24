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
        } catch (\Throwable) {
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
}
