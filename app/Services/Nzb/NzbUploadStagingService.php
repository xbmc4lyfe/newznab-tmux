<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Exceptions\NzbUploadException;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class NzbUploadStagingService
{
    /**
     * Hidden directory (relative to the upload folder) in which uploads are assembled.
     */
    public const STAGING_DIRECTORY = '.staging';

    private const MAX_NFO_SIZE = 65535;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_upload_staging_filename(const char *name, size_t name_len, const char *extension, size_t extension_len);
        int32_t nntmux_upload_staging_prefix(const char *data, size_t data_len, uint32_t kind);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private NzbUploadManifestService $manifests;

    public function __construct(
        private readonly Filesystem $filesystem,
        ?NzbUploadManifestService $manifests = null,
    ) {
        $this->manifests = $manifests ?? new NzbUploadManifestService($filesystem);
    }

    /**
     * @return array{name:string,files:array{nzb:array{filename:string,type:string},nfo:array{filename:string,type:string}|null}}
     */
    public function stage(UploadedFile $nzb, ?UploadedFile $nfo = null): array
    {
        [$nzbName, $nzbBase, $nzbContent] = $this->validateFile($nzb, 'nzb');
        $nfoDetails = $nfo === null ? null : $this->validateFile($nfo, 'nfo');
        $folder = $this->ensureUploadFolder();

        $uploadId = Str::uuid()->toString();
        $stagingDirectory = $this->ensureStagingFolder($folder).DIRECTORY_SEPARATOR.$uploadId;
        $uploadDirectory = $folder.DIRECTORY_SEPARATOR.$uploadId;
        if (! $this->filesystem->makeDirectory($stagingDirectory, 0775, true)) {
            throw new NzbUploadException('Failed to create upload staging directory', 500);
        }

        $files = [[$nzbName, $nzbContent, 'nzb']];
        if ($nfoDetails !== null) {
            $files[] = [$nfoDetails[0], $nfoDetails[2], 'nfo'];
        }

        try {
            foreach ($files as [$filename, $content, $type]) {
                $path = $stagingDirectory.DIRECTORY_SEPARATOR.$filename;
                if ($this->filesystem->put($path, $content) === false) {
                    throw new NzbUploadException("Failed to write {$filename} to disk", 500);
                }
            }

            $this->manifests->create(
                $stagingDirectory,
                $uploadId,
                $nzbName,
                $nfoDetails[0] ?? null,
                $nfoDetails[2] ?? null,
            );

            $this->publish($stagingDirectory, $uploadDirectory);
        } catch (Throwable $exception) {
            if ($this->filesystem->isDirectory($stagingDirectory)
                && ! $this->filesystem->deleteDirectory($stagingDirectory)) {
                Log::channel('nzb_upload')->error('Failed to roll back partial API upload', [
                    'upload_id' => $uploadId,
                    'files' => array_column($files, 0),
                ]);

                throw new NzbUploadException('Failed to write upload pair and roll back partial files', 500);
            }

            if ($exception instanceof NzbUploadException) {
                throw $exception;
            }

            throw new NzbUploadException('Failed to write upload pair to disk', 500);
        }

        foreach ($files as [$filename, , $type]) {
            Log::channel('nzb_upload')->info('File staged by API', [
                'upload_id' => $uploadId,
                'filename' => $filename,
                'type' => $type,
            ]);
        }

        return [
            'name' => $nzbBase,
            'files' => [
                'nzb' => ['filename' => $nzbName, 'type' => 'nzb'],
                'nfo' => $nfoDetails === null ? null : ['filename' => $nfoDetails[0], 'type' => 'nfo'],
            ],
        ];
    }

    /**
     * Preserve the API v1 single-file fallback while routing NZBs through manifested staging.
     *
     * @return array{name:string,filename:string,type:'nzb'|'nfo'}
     */
    public function stageLegacyFile(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['nzb', 'nfo'], true)) {
            throw new NzbUploadException('File is not an NZB or NFO file', 400);
        }

        if ($extension === 'nzb') {
            $staged = $this->stage($file);

            return [
                'name' => $staged['name'],
                'filename' => $staged['files']['nzb']['filename'],
                'type' => 'nzb',
            ];
        }

        [$filename, $basename, $content] = $this->validateFile($file, 'nfo');
        $folder = $this->ensureUploadFolder();
        $path = $folder.DIRECTORY_SEPARATOR.$filename;
        if ($this->filesystem->exists($path)) {
            throw new NzbUploadException("A staged file named {$filename} already exists", 409);
        }
        $temporaryPath = $this->ensureStagingFolder($folder).DIRECTORY_SEPARATOR.Str::uuid()->toString().'.nfo';
        try {
            if ($this->filesystem->put($temporaryPath, $content) === false) {
                throw new NzbUploadException("Failed to write {$filename} to disk", 500);
            }

            $this->publish($temporaryPath, $path);
        } finally {
            if ($this->filesystem->exists($temporaryPath)) {
                $this->filesystem->delete($temporaryPath);
            }
        }

        Log::channel('nzb_upload')->info('Legacy NFO file staged by API v1', [
            'filename' => $filename,
            'type' => 'nfo',
        ]);

        return ['name' => $basename, 'filename' => $filename, 'type' => 'nfo'];
    }

    /** @return array{string,string,string} */
    private function validateFile(UploadedFile $file, string $expectedExtension): array
    {
        if (! $file->isValid()) {
            throw new NzbUploadException("Invalid {$expectedExtension} upload", 400);
        }

        $filename = $file->getClientOriginalName();
        $basename = self::$useRustFfi
            ? (self::validateFilenameNative($filename, $expectedExtension) ?? self::validateFilenamePhp($filename, $expectedExtension))
            : self::validateFilenamePhp($filename, $expectedExtension);

        try {
            $content = $file->getContent();
        } catch (Throwable) {
            throw new NzbUploadException("Failed to read {$expectedExtension} upload", 400);
        }

        if ($expectedExtension === 'nzb' && ! self::xmlPayload($content)) {
            throw new NzbUploadException('Invalid NZB payload', 400);
        }
        if ($expectedExtension === 'nfo' && ($content === '' || strlen($content) > self::MAX_NFO_SIZE)) {
            throw new NzbUploadException('NFO upload must not be empty or exceed 65535 bytes', 400);
        }

        return [$filename, $basename, $content];
    }

    /** Validate without rewriting the filename; return the original pathinfo basename. */
    public static function validateFilenamePhp(string $filename, string $expectedExtension): string
    {
        if ($filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, "\0")) {
            throw new NzbUploadException("Unsafe {$expectedExtension} filename", 400);
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $basename = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($extension !== $expectedExtension || trim($basename) === '') {
            throw new NzbUploadException("The {$expectedExtension} field must contain a .{$expectedExtension} file", 400);
        }

        return $basename;
    }

    public static function validateFilenameNative(string $filename, string $expectedExtension): ?string
    {
        $ffi = self::$useRustFfi ? self::resolveFfi() : null;
        if ($ffi === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_upload_staging_filename($filename, strlen($filename), $expectedExtension, strlen($expectedExtension));
        if ($status < 0) {
            return null;
        }
        if ($status === 1) {
            throw new NzbUploadException("Unsafe {$expectedExtension} filename", 400);
        }
        if ($status === 2) {
            throw new NzbUploadException("The {$expectedExtension} field must contain a .{$expectedExtension} file", 400);
        }

        return (string) pathinfo($filename, PATHINFO_FILENAME);
    }

    public static function hasGzipMagicPhp(string $content): bool
    {
        return str_starts_with($content, "\x1f\x8b");
    }

    public static function hasGzipMagicNative(string $content): ?bool
    {
        return self::prefixNative($content, 0);
    }

    public static function hasUtf8BomPhp(string $content): bool
    {
        return str_starts_with($content, "\xef\xbb\xbf");
    }

    public static function hasUtf8BomNative(string $content): ?bool
    {
        return self::prefixNative($content, 1);
    }

    private static function prefixNative(string $content, int $kind): ?bool
    {
        $ffi = self::$useRustFfi ? self::resolveFfi() : null;
        if ($ffi === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_upload_staging_prefix($content, strlen($content), $kind);

        return $status < 0 ? null : $status === 1;
    }

    private static function xmlPayload(string $content): bool
    {
        return self::$useRustFfi
            ? (self::xmlPayloadNative($content) ?? self::xmlPayloadPhp($content))
            : self::xmlPayloadPhp($content);
    }

    public static function xmlPayloadPhp(string $content): bool
    {
        return isValidNewznabNzb($content);
    }

    /** Prefix preflight only: libxml retains namespace, encoding and XML semantics. */
    public static function xmlPayloadNative(string $content): ?bool
    {
        if (! self::$useRustFfi || self::resolveFfi() === null) {
            return null;
        }
        // Match PHP empty(), including its special string "0" case, before libxml state changes.
        if ($content === '' || $content === '0') {
            return false;
        }
        $gzip = self::hasGzipMagicNative($content) ?? self::hasGzipMagicPhp($content);
        $bomOnly = strlen($content) === 3
            && (self::hasUtf8BomNative($content) ?? self::hasUtf8BomPhp($content));
        if ($gzip || $bomOnly) {
            // These prefixes cannot form an XML document. Preserve the original helper's
            // global libxml error-mode and clearing side effects on parser rejection.
            libxml_use_internal_errors(true);
            libxml_clear_errors();

            return false;
        }

        // Do not strip BOMs or inflate gzip: staging preserves the exact uploaded bytes.
        return self::xmlPayloadPhp($content);
    }

    /**
     * Create the hidden work area where uploads are assembled before publication.
     *
     * It lives inside the upload folder so publishing is a same-filesystem rename, and its
     * dot-prefixed name keeps Finder-based scans (`ImportNzbs`, `findManifests()`) from ever
     * seeing in-progress or abandoned uploads.
     */
    private function ensureStagingFolder(string $folder): string
    {
        $stagingFolder = $folder.DIRECTORY_SEPARATOR.self::STAGING_DIRECTORY;
        // Forced (error-suppressed) so concurrent uploads racing to create it don't fail.
        $this->filesystem->makeDirectory($stagingFolder, 0775, true, true);
        if (! $this->filesystem->isDirectory($stagingFolder)) {
            throw new NzbUploadException('Failed to create upload staging directory', 500);
        }

        return $stagingFolder;
    }

    /**
     * Atomically move a fully written upload from the staging area to where importers scan.
     */
    private function publish(string $stagingPath, string $publishedPath): void
    {
        try {
            $moved = $this->filesystem->move($stagingPath, $publishedPath);
        } catch (Throwable) {
            $moved = false;
        }

        if (! $moved) {
            throw new NzbUploadException('Failed to publish staged upload', 500);
        }
    }

    private function ensureUploadFolder(): string
    {
        $folder = config('nntmux.nzb_upload_folder');
        if (! is_string($folder) || trim($folder) === '') {
            throw new NzbUploadException('NZB upload folder is not configured', 500);
        }

        $folder = rtrim($folder, DIRECTORY_SEPARATOR);
        if (! $this->filesystem->isDirectory($folder)
            && ! $this->filesystem->makeDirectory($folder, 0775, true)) {
            throw new NzbUploadException('Failed to create NZB upload folder', 500);
        }

        return $folder;
    }

    /**
     * Default for the Wave 42.2 toggle only; enabled because the paired benchmark in
     * tests/Unit/RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest.php reaches 1.5x on every measure.
     */
    public const bool W422_RUST_FFI_DEFAULT = true;

    private static ?bool $useW422RustFfi = null;

    public static function useW422RustFfi(bool $enable = true): void
    {
        self::$useW422RustFfi = $enable;
    }

    public static function usesW422RustFfi(): bool
    {
        return self::$useW422RustFfi ?? self::W422_RUST_FFI_DEFAULT;
    }

    public static function resetW422RustFfi(): void
    {
        self::$useW422RustFfi = null;
    }

    /**
     * Classify upload filenames the way validateFilenamePhp() would, without throwing: invalid (not a string), unsafe,
     * wrong_extension or accepted, whose pathinfo basename byte lengths are summed.
     *
     * @param  array<array-key, mixed>  $filenames
     * @return array{filenames: int, invalid: int, unsafe: int, wrong_extension: int, accepted: int, basename_bytes: int}
     */
    public static function summarizeUploadFilenames(array $filenames, string $expectedExtension = 'nzb'): array
    {
        return (self::usesW422RustFfi() ? self::summarizeUploadFilenamesNative($filenames, $expectedExtension) : null)
            ?? self::summarizeUploadFilenamesPhp($filenames, $expectedExtension);
    }

    /**
     * @param  array<array-key, mixed>  $filenames
     * @return array{filenames: int, invalid: int, unsafe: int, wrong_extension: int, accepted: int, basename_bytes: int}
     */
    public static function summarizeUploadFilenamesPhp(array $filenames, string $expectedExtension = 'nzb'): array
    {
        $invalid = $unsafe = $wrong = $accepted = $bytes = 0;
        foreach ($filenames as $filename) {
            if (! \is_string($filename)) {
                $invalid++;
            } elseif ($filename === '' || $filename === '.' || $filename === '..' || strpbrk($filename, "/\\\0") !== false) {
                $unsafe++;
            } elseif (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== $expectedExtension
                || trim($basename = (string) pathinfo($filename, PATHINFO_FILENAME)) === '') {
                $wrong++;
            } else {
                $accepted++;
                $bytes += \strlen($basename);
            }
        }

        return ['filenames' => \count($filenames), 'invalid' => $invalid, 'unsafe' => $unsafe, 'wrong_extension' => $wrong, 'accepted' => $accepted,
            'basename_bytes' => $bytes];
    }

    /**
     * @param  array<array-key, mixed>  $filenames
     * @return array{filenames: int, invalid: int, unsafe: int, wrong_extension: int, accepted: int, basename_bytes: int}|null
     */
    public static function summarizeUploadFilenamesNative(array $filenames, string $expectedExtension = 'nzb'): ?array
    {
        $texts = [];
        foreach ($filenames as $filename) {
            $texts[] = \is_string($filename) ? $filename : null;
        }
        $input = NzbSourceGuard::packW422Texts(\strlen($expectedExtension), $texts, $expectedExtension);
        $slots = $input === null ? null : NzbSourceGuard::evaluateW422Native(2, $input, 6);
        if ($slots === null) {
            return null;
        }
        [$count, $invalid, $unsafe, $wrong, $accepted, $bytes] = $slots;
        if ($count !== \count($filenames) || min($slots) < 0 || $invalid + $unsafe + $wrong + $accepted !== $count) {
            return null;
        }

        return ['filenames' => $count, 'invalid' => $invalid, 'unsafe' => $unsafe, 'wrong_extension' => $wrong, 'accepted' => $accepted,
            'basename_bytes' => $bytes];
    }

    // ---- Wave 60.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.00x/5.09x, uncommon 6.16x/6.40x); see RustNzbUploadStagingXmlElementsPar2RecoveryResultFingerprintStoreW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_UPLOAD_STAGING_FFI_CDEF = <<<'C'
        intptr_t nntmux_w602_nzb_upload_staging_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W60_UPLOAD_STAGING_TAIL = 8;

    /**
     * Wave 60 routing, binding and output buffer, kept in function-local statics (no new static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w60', self::W60_UPLOAD_STAGING_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w60RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planUploadStagingBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w60Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w60Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** 1 to $max ASCII digits as an integer, otherwise null. */
    private static function w60Digits(string $value, int $max): ?int
    {
        $length = \strlen($value);

        return $length > 0 && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    /** Byte quota for a staged NZB (NFOs keep MAX_NFO_SIZE). */
    public const int W60_MAX_NZB_BYTES = 67108864;

    /** Seconds an unpublished staging entry may age before it is purged. */
    public const int W60_STAGING_RETENTION = 86400;

    /**
     * Per `filename\tsize\tage\thead` row `kind | name << 2 | size << 4 | stale << 6 | bad_age << 7 |
     * head << 8 | bom << 11 | action << 12 | min(len(base), 0x3FFFF) << 14 | crc32(lower(base)) << 32`,
     * where base and extension split at the last dot (pathinfo() for slash-free names). Kind 2 for
     * a `.nfo` extension (any case), otherwise 1 (NZB). Name: 0 accepted, 1 unsafe (as
     * validateFilenamePhp()), 2 wrong extension or blank basename. Size: 0 within the kind's quota
     * (W60_MAX_NZB_BYTES or MAX_NFO_SIZE), 1 empty, 2 over quota, 3 not 1-18 digits. Stale: an
     * age (1-18 digits, else bad_age) above W60_STAGING_RETENTION. Head: 0 empty, 1 gzip magic,
     * then after an optional UTF-8 BOM and `[ \t\r\n]*`: 2 nothing, 3 `<?xml`, 4 `<!DOCTYPE` (any
     * case), 5 a `<nzb` root, 6 another element, 7 text. Action: 2 purge (stale), 1 reject (bad
     * name, size or age, or a head that is not 3-5 for an NZB or 3-7 for an NFO), 0 accept. Then
     * the accept, reject and purge counts, the NZB and NFO counts, the accepted bytes, the
     * distinct accepted lowercase basenames and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planUploadStagingBatchPhp(array $rows): array
    {
        $out = $seen = [];
        $actions = [0, 0, 0];
        $nzb = $nfo = $bytes = 0;
        foreach ($rows as $row) {
            [$name, $size, $age, $head] = explode("\t", (string) $row, 4) + ['', '', '', ''];
            $dot = strrpos($name, '.');
            $base = $dot === false ? $name : substr($name, 0, $dot);
            $extension = $dot === false ? '' : strtolower(substr($name, $dot + 1));
            $isNfo = $extension === 'nfo';
            if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, "/\\\0") !== false) {
                $nameStatus = 1;
            } elseif ((! $isNfo && $extension !== 'nzb') || trim($base) === '') {
                $nameStatus = 2;
            } else {
                $nameStatus = 0;
            }
            $parsed = self::w60Digits($size, 18);
            $sizeStatus = match (true) {
                $parsed === null => 3,
                $parsed === 0 => 1,
                $parsed > ($isNfo ? self::MAX_NFO_SIZE : self::W60_MAX_NZB_BYTES) => 2,
                default => 0,
            };
            $ageValue = self::w60Digits($age, 18);
            $stale = $ageValue !== null && $ageValue > self::W60_STAGING_RETENTION;
            $bom = 0;
            if ($head === '') {
                $class = 0;
            } elseif (str_starts_with($head, "\x1f\x8b")) {
                $class = 1;
            } else {
                $bom = str_starts_with($head, "\xef\xbb\xbf") ? 1 : 0;
                $rest = ltrim($bom === 1 ? substr($head, 3) : $head, " \t\r\n");
                $class = match (true) {
                    $rest === '' => 2,
                    str_starts_with($rest, '<?xml') => 3,
                    strncasecmp($rest, '<!doctype', 9) === 0 => 4,
                    str_starts_with($rest, '<nzb') && (\strlen($rest) === 4 || str_contains(" \t\r\n>/", $rest[4])) => 5,
                    $rest[0] === '<' => 6,
                    default => 7,
                };
            }
            if ($stale) {
                $action = 2;
            } elseif ($nameStatus !== 0 || $sizeStatus !== 0 || $ageValue === null) {
                $action = 1;
            } else {
                $action = ($isNfo ? $class >= 3 : $class >= 3 && $class <= 5) ? 0 : 1;
            }
            $actions[$action]++;
            $isNfo ? $nfo++ : $nzb++;
            $lower = strtolower($base);
            if ($action === 0) {
                $bytes += (int) $parsed;
                $seen[$lower] = true;
            }
            $out[] = ($isNfo ? 2 : 1) | $nameStatus << 2 | $sizeStatus << 4 | (int) $stale << 6 | ($ageValue === null ? 1 : 0) << 7 | $class << 8
                | $bom << 11 | $action << 12 | min(\strlen($base), 0x3FFFF) << 14 | crc32($lower) << 32;
        }
        array_push($out, ...$actions);
        array_push($out, $nzb, $nfo, $bytes, \count($seen));
        $out[] = self::w60Digest($out);

        return $out;
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planUploadStagingBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::planUploadStagingBatchNative($rows) : self::planUploadStagingBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planUploadStagingBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W60_UPLOAD_STAGING_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::planUploadStagingBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W60_UPLOAD_STAGING_FFI_CDEF.)
            $written = $ffi->nntmux_w602_nzb_upload_staging_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w60Words($written, $out, $words) ?? self::planUploadStagingBatchPhp($rows);
    }
}
