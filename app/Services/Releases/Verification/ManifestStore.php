<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Content-addressed candidate manifests: gzipped JSON named by the SHA-256 of the JSON.
 *
 * With release_verification.manifest_storage = database (the default) manifests are rows in
 * release_verification_manifests, so every worker host can read any candidate's manifest and a
 * manifest written inside an admission transaction commits or rolls back with it. Manifests
 * written before that switch stay readable from their legacy files until verification:import-manifests
 * copies them in. The files mode keeps the previous per-host files under storage_path/manifests.
 */
final class ManifestStore
{
    private static bool $useRustFfi = true;

    /** Wave 39.6 paired benchmarks decide this default; see RustVerificationStorePrelockManifestResetW39ParityTest. */
    public const bool W396_RUST_FFI_DEFAULT = false;

    private static ?bool $w396UseRustFfi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private const string TABLE = 'release_verification_manifests';

    /** Import chunk bounds: names per transaction, and file bytes held in memory at once. */
    private const int IMPORT_CHUNK_NAMES = 50;

    private const int IMPORT_CHUNK_BYTES = 33554432;

    private ?PreparationRunDiagnostics $readDiagnostics = null;

    /** Whether the manifests table exists, checked once per instance in files mode. */
    private ?bool $tableExists = null;

    /** @param array<string, mixed> $manifest */
    public function write(array $manifest): string
    {
        return $this->writePrepared($this->prepare($manifest));
    }

    /** @param array<string, mixed> $manifest */
    public function prepare(array $manifest): PreparedManifest
    {
        return new PreparedManifest($manifest);
    }

    public function writePrepared(PreparedManifest $prepared): string
    {
        return $this->usesDatabase() ? $this->writeRow($prepared) : $this->writeFile($prepared);
    }

    /**
     * Insert the manifest row, or validate the row already stored under its name. Inside a
     * transaction the row is part of it, so a rollback leaves nothing behind.
     */
    private function writeRow(PreparedManifest $prepared): string
    {
        $name = $prepared->name;
        // Insert a name this transaction's view lacks without a locking read first: locking an absent
        // key gap-locks the primary index, and concurrent admissions inserting into one gap deadlock.
        if (! DB::table(self::TABLE)->where('name', $name)->exists()) {
            try {
                DB::table(self::TABLE)->insert(['name' => $name, 'body' => $prepared->compressed, 'bytes' => strlen($prepared->compressed)]);

                return $name;
            } catch (UniqueConstraintViolationException) {
                // A concurrent writer committed the same name first; validate what it stored.
            }
        }
        // A current read: under REPEATABLE READ a plain read could still see a row that cleanup
        // deleted after this transaction's snapshot, and admission would then reference nothing.
        $existing = DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('body');
        if ($existing === null) {
            try {
                DB::table(self::TABLE)->insert(['name' => $name, 'body' => $prepared->compressed, 'bytes' => strlen($prepared->compressed)]);

                return $name;
            } catch (UniqueConstraintViolationException) {
                $existing = DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('body');
            }
        }
        if (! is_string($existing)) {
            throw new RuntimeException('Manifest integrity check failed.');
        }
        $this->validateOccupiedBody($existing, $prepared);

        return $name;
    }

    private function writeFile(PreparedManifest $prepared): string
    {
        $directory = (string) config('release_verification.storage_path').'/manifests';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Candidate storage is unavailable.');
        }
        if (! @chmod($directory, 0700)) {
            throw new RuntimeException('Candidate storage is unavailable.');
        }
        $name = $prepared->name;
        $path = $directory.'/'.$name;
        $compressed = $prepared->compressed;
        if (is_file($path)) {
            $this->validateOccupiedTarget($path, $prepared);
            if (! @chmod($path, 0600)) {
                throw new RuntimeException('Cannot secure candidate manifest.');
            }
        } else {
            $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            $handle = fopen($temporary, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Cannot write candidate manifest.');
            }
            try {
                try {
                    if (! @chmod($temporary, 0600)) {
                        throw new RuntimeException('Cannot write candidate manifest.');
                    }
                    if (fwrite($handle, $compressed) !== strlen($compressed) || ! fflush($handle) || ! fsync($handle)) {
                        throw new RuntimeException('Cannot persist candidate manifest.');
                    }
                } finally {
                    fclose($handle);
                }
                if (! rename($temporary, $path)) {
                    throw new RuntimeException('Cannot finalize candidate manifest.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::afterRollBack(fn () => $this->deleteIfUnreferenced($name));
            }
        }

        return $name;
    }

    /** Validate reuse before admission can relinquish the uploader source. */
    private function validateOccupiedTarget(string $path, PreparedManifest $prepared): void
    {
        // Incoming JSON length was recorded during preparation, before capacity ownership.
        $body = @file_get_contents($path, false, null, 0, $prepared->jsonBytes * 2 + 65536 + 1);
        if ($body === false) {
            throw new RuntimeException('Manifest integrity check failed.');
        }
        $this->validateOccupiedBody($body, $prepared);
    }

    private function validateOccupiedBody(string $body, PreparedManifest $prepared): void
    {
        // Allow alternate compression and bounded optional gzip headers.
        if (strlen($body) > $prepared->jsonBytes * 2 + 65536) {
            throw new RuntimeException('Manifest integrity check failed.');
        }
        if ($body === $prepared->compressed) {
            return;
        }
        $json = @gzdecode($body, $prepared->jsonBytes + 1);
        if ($json === false || strlen($json) !== $prepared->jsonBytes
            || hash('sha256', $json).'.json.gz' !== $prepared->name || ! $this->consumesExactly($body, $json)) {
            throw new RuntimeException('Manifest integrity check failed.');
        }
    }

    /**
     * The bounded decode establishes the first member's output; check complete consumption
     * without accepting trailing bytes or extra gzip members.
     */
    public function consumesExactly(string $body, string $json): bool
    {
        return $this->consumesExactlyPhp($body, $json);
    }

    public function consumesExactlyPhp(string $body, string $json): bool
    {
        $inflater = inflate_init(ZLIB_ENCODING_GZIP);

        return $inflater !== false && @inflate_add($inflater, $body, ZLIB_FINISH) === $json
            && inflate_get_status($inflater) === ZLIB_STREAM_END
            && inflate_get_read_len($inflater) === strlen($body);
    }

    /** @return array<string, mixed> */
    public function read(string $name): array
    {
        $this->assertName($name);
        $diagnostics = $this->readDiagnostics;
        $body = $diagnostics === null ? $this->body($name)
            : $diagnostics->measurePhase('file_read', fn () => $this->body($name));
        if ($diagnostics !== null && $body !== false) {
            $diagnostics->recordSize('compressed_bytes', strlen($body));
        }
        $json = $body === false ? false : ($diagnostics === null ? gzdecode($body)
            : $diagnostics->measurePhase('gzip_decode', fn () => gzdecode($body)));
        if ($diagnostics !== null && $json !== false) {
            $diagnostics->recordSize('json_bytes', strlen($json));
        }
        if ($json === false || ! ($diagnostics === null ? hash('sha256', $json).'.json.gz' === $name
            : $diagnostics->measurePhase('hash', fn () => hash('sha256', $json).'.json.gz' === $name))) {
            throw new RuntimeException('Manifest integrity check failed.');
        }
        $result = $diagnostics === null ? json_decode($json, true, flags: JSON_THROW_ON_ERROR)
            : $diagnostics->measurePhase('json_decode', fn () => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        if (! is_array($result)) {
            throw new RuntimeException('Invalid manifest contents.');
        }
        if ($diagnostics !== null && is_array($result['files'] ?? null)) {
            $diagnostics->recordSize('files', count($result['files']));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function readWithDiagnostics(string $name, PreparationRunDiagnostics $diagnostics): array
    {
        $previous = $this->readDiagnostics;
        $this->readDiagnostics = $diagnostics;
        try {
            return $this->read($name);
        } finally {
            $this->readDiagnostics = $previous;
        }
    }

    /** Stored (compressed) size of a manifest, as counted against pending verification capacity. */
    public function size(string $name): int
    {
        $this->assertName($name);
        if ($this->usesDatabase()) {
            $bytes = DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('bytes');
            if ($bytes !== null) {
                return (int) $bytes;
            }
        }
        $size = @filesize($this->path($name));
        if ($size === false && ! $this->usesDatabase() && $this->tableAvailable()) {
            // Rolled back to files mode: manifests written in database mode exist only as rows.
            $bytes = DB::table(self::TABLE)->where('name', $name)->value('bytes');
            $size = $bytes === null ? false : (int) $bytes;
        }
        if ($size === false) {
            throw new RuntimeException('Cannot reserve manifest storage.');
        }

        return $size;
    }

    public function path(string $name): string
    {
        if (basename($name) !== $name) {
            throw new RuntimeException('Invalid manifest reference.');
        }

        return (string) config('release_verification.storage_path').'/manifests/'.$name;
    }

    /**
     * Copy legacy manifest files into the database. Each file must decode to its name; rows are
     * inserted under the capacity-state lock (the same order as admission and cleanup) and only
     * while a candidate still references the name, so a concurrent cleanup cannot be undone.
     * A file is removed only after a committed row for its name has itself been validated.
     *
     * @param  list<string>  $names
     * @return array{imported: int, already: int, missing: int, invalid: int, conflict: int, unreferenced: int}
     */
    public function importLegacyFiles(array $names, bool $deleteFiles): array
    {
        if (DB::transactionLevel() > 0) {
            // Files are deleted as each chunk commits; an outer rollback would undo the rows but not that.
            throw new RuntimeException('Manifest import cannot run inside an open transaction.');
        }
        $counts = ['imported' => 0, 'already' => 0, 'missing' => 0, 'invalid' => 0, 'conflict' => 0, 'unreferenced' => 0];
        $chunk = [];
        $chunkBytes = 0;
        foreach (array_values(array_unique($names)) as $name) {
            if (preg_match('/\A[0-9a-f]{64}\.json\.gz\z/', $name) !== 1) {
                // A malformed reference cannot name a file or row; count it and keep importing.
                $counts['invalid']++;

                continue;
            }
            $path = $this->path($name);
            $body = is_file($path) ? @file_get_contents($path) : false;
            $chunk[$name] = $body === false ? null : ($this->validBody($name, $body) ? $body : false);
            $chunkBytes += is_string($chunk[$name]) ? strlen($chunk[$name]) : 0;
            if (count($chunk) >= self::IMPORT_CHUNK_NAMES || $chunkBytes >= self::IMPORT_CHUNK_BYTES) {
                $this->importChunk($chunk, $deleteFiles, $counts);
                $chunk = [];
                $chunkBytes = 0;
            }
        }
        if ($chunk !== []) {
            $this->importChunk($chunk, $deleteFiles, $counts);
        }

        return $counts;
    }

    /**
     * Import one bounded chunk (at most IMPORT_CHUNK_NAMES names or about IMPORT_CHUNK_BYTES of
     * files) in one transaction, one row per statement to stay under the packet limit. Locking the
     * referencing candidates and then each manifest row follows admission's lock order.
     *
     * @param  array<string, string|false|null>  $files  name => verified body, false (invalid) or null (missing)
     * @param  array{imported: int, already: int, missing: int, invalid: int, conflict: int, unreferenced: int}  $counts
     */
    private function importChunk(array $files, bool $deleteFiles, array &$counts): void
    {
        $names = array_map('strval', array_keys($files));
        $verified = [];
        $outcomes = [];
        DB::transaction(function () use ($files, $names, &$verified, &$outcomes): void {
            $verified = [];
            $outcomes = [];
            (new VerificationLedger)->enter('Verification capacity state is unavailable; manifests not imported.');
            $referenced = array_flip(DB::table('release_verification_candidates')->whereIn('manifest_path', $names)
                ->lockForUpdate()->pluck('manifest_path')->map(static fn ($name): string => (string) $name)->all());
            foreach ($names as $name) {
                $stored = DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('body');
                if ($stored !== null) {
                    $valid = is_string($stored) && $this->validBody($name, $stored);
                    $outcomes[] = $valid ? 'already' : 'conflict';
                    if ($valid) {
                        $verified[] = $name;
                    }
                } elseif ($files[$name] === null) {
                    $outcomes[] = 'missing';
                } elseif ($files[$name] === false) {
                    $outcomes[] = 'invalid';
                } elseif (! isset($referenced[$name])) {
                    $outcomes[] = 'unreferenced';
                } else {
                    DB::table(self::TABLE)->insert(['name' => $name, 'body' => $files[$name], 'bytes' => strlen($files[$name])]);
                    $outcomes[] = 'imported';
                    $verified[] = $name;
                }
            }
        }, 5);
        foreach ($outcomes as $outcome) {
            $counts[$outcome]++;
        }
        if ($deleteFiles) {
            foreach ($verified as $name) {
                $path = $this->path($name);
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function validBody(string $name, string $body): bool
    {
        return $this->validBodyPhp($name, $body);
    }

    public function validBodyPhp(string $name, string $body): bool
    {
        $json = @gzdecode($body);

        return $json !== false && hash('sha256', $json).'.json.gz' === $name && $this->consumesExactlyPhp($body, $json);
    }

    /**
     * Serialize deletion with admissions and retain every manifest still referenced by a candidate.
     * Wait for open transactions to finish so a later rollback cannot restore a deleted reference.
     *
     * Database manifests lock the manifest row (or its gap) first. Every admission that adds a
     * reference writes that row under the same lock, so none can add one until this commits, and the
     * reference check below takes its read view only after the lock is granted, so it sees every
     * committed reference without locking candidates out of admission's candidate-then-manifest order.
     */
    public function deleteIfUnreferenced(string $name): void
    {
        if ($name === '') {
            return;
        }
        $this->assertName($name);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->deleteIfUnreferenced($name));
            DB::afterRollBack(fn () => $this->deleteIfUnreferenced($name));

            return;
        }

        DB::transaction(function () use ($name): void {
            if ($this->usesDatabase()) {
                DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('name');
                if (DB::table('release_verification_candidates')->where('manifest_path', $name)->exists()) {
                    return;
                }
            } else {
                (new VerificationLedger)->enter('Verification capacity state is unavailable; manifest retained.');
                $reference = DB::table('release_verification_candidates')->where('manifest_path', $name)->lockForUpdate()->first(['id']);
                if ($reference !== null) {
                    return;
                }
            }
            if ($this->tableAvailable()) {
                DB::table(self::TABLE)->where('name', $name)->delete();
            }
            $path = $this->path($name);
            if (is_file($path)) {
                unlink($path);
            }
        }, 5);
    }

    private function usesDatabase(): bool
    {
        return config('release_verification.manifest_storage', 'database') === 'database';
    }

    private function body(string $name): string|false
    {
        if (! $this->usesDatabase()) {
            $file = @file_get_contents($this->path($name));

            // Rolled back to files mode: manifests written in database mode exist only as rows.
            return $file !== false || ! $this->tableAvailable() ? $file : ($this->rowBody($name) ?? false);
        }
        $row = $this->rowBody($name);
        if ($row !== null) {
            return $row;
        }
        $file = @file_get_contents($this->path($name));

        // The importer commits a row before deleting its file, so a file that vanished between the
        // two lookups is now readable from the database.
        return $file !== false ? $file : ($this->rowBody($name) ?? false);
    }

    private function tableAvailable(): bool
    {
        return $this->usesDatabase() || ($this->tableExists ??= Schema::hasTable(self::TABLE));
    }

    private function rowBody(string $name): ?string
    {
        // Inside a transaction, read the committed row rather than the snapshot: the importer
        // may have moved this manifest from its legacy file after the snapshot was taken.
        $query = DB::table(self::TABLE)->where('name', $name);
        $body = (DB::transactionLevel() > 0 ? $query->sharedLock() : $query)->value('body');

        return is_string($body) ? $body : null;
    }

    public function assertName(string $name): void
    {
        $this->assertNamePhp($name);
    }

    public function assertNamePhp(string $name): void
    {
        if (preg_match('/\A[0-9a-f]{64}\.json\.gz\z/', $name) !== 1) {
            throw new RuntimeException('Invalid manifest reference.');
        }
    }

    /** True on success, null when the candidate is unavailable; invalid names still throw. */
    public function assertNameNative(string $name): ?bool
    {
        if (! self::$useRustFfi || ($ffi = PreparedManifest::nativeLibrary()) === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_verification_manifest_name($name, strlen($name));
        if ($status === 0) {
            throw new RuntimeException('Invalid manifest reference.');
        }

        return $status === 1 ? true : null;
    }

    public function validBodyNative(string $name, string $body): ?bool
    {
        if (! self::$useRustFfi || ($ffi = PreparedManifest::nativeLibrary()) === null) {
            return null;
        }
        $json = @gzdecode($body);
        if ($json === false) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_verification_manifest_body($name, strlen($name), $body, strlen($body), $json, strlen($json));

        return $status < 0 ? null : ($status === 1 && ($this->consumesExactlyNative($body, $json) ?? $this->consumesExactlyPhp($body, $json)));
    }

    public function consumesExactlyNative(string $body, string $json): ?bool
    {
        if (! self::$useRustFfi || ($ffi = PreparedManifest::nativeLibrary()) === null) {
            return null;
        }
        $inflater = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false || ($inflated = @inflate_add($inflater, $body, ZLIB_FINISH)) === false) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_verification_manifest_consumes(strlen($body), $json, strlen($json), $inflated, strlen($inflated), inflate_get_read_len($inflater), inflate_get_status($inflater));

        return $status >= 0 ? $status === 1 : null;
    }

    public static function useW396RustFfi(?bool $enabled): void
    {
        self::$w396UseRustFfi = $enabled;
    }

    public static function usesW396RustFfi(): bool
    {
        return self::$w396UseRustFfi ?? self::W396_RUST_FFI_DEFAULT;
    }

    public static function resetW396RustFfi(): void
    {
        self::$w396UseRustFfi = null;
    }

    /**
     * Admit manifest bodies, in order, against a byte budget of max(0, $maxTotalBytes). Non-integer and
     * non-positive sizes are empty and skipped; the first non-empty size that no longer fits records its
     * position in exceeded_index (else -1) and closes admission for the rest of the batch.
     *
     * @param  array<array-key, mixed>  $byteSizes
     * @return array{items: int, non_empty: int, empty: int, accepted_bytes: int, exceeded_index: int, accepted: int}
     */
    public static function summarizeManifestStorageBatch(array $byteSizes, int $maxTotalBytes = 67108864): array
    {
        return (self::usesW396RustFfi() ? self::summarizeManifestStorageBatchNative($byteSizes, $maxTotalBytes) : null)
            ?? self::summarizeManifestStorageBatchPhp($byteSizes, $maxTotalBytes);
    }

    /**
     * @param  array<array-key, mixed>  $byteSizes
     * @return array{items: int, non_empty: int, empty: int, accepted_bytes: int, exceeded_index: int, accepted: int}
     */
    public static function summarizeManifestStorageBatchPhp(array $byteSizes, int $maxTotalBytes = 67108864): array
    {
        $budget = max(0, $maxTotalBytes);
        $items = $nonEmpty = $empty = $bytes = $accepted = 0;
        $exceeded = -1;
        foreach ($byteSizes as $size) {
            $index = $items++;
            if (! \is_int($size) || $size <= 0) {
                $empty++;

                continue;
            }
            $nonEmpty++;
            if ($exceeded < 0) {
                // $bytes never exceeds $budget, so the difference cannot overflow.
                if ($size <= $budget - $bytes) {
                    $bytes += $size;
                    $accepted++;
                } else {
                    $exceeded = $index;
                }
            }
        }

        return ['items' => $items, 'non_empty' => $nonEmpty, 'empty' => $empty, 'accepted_bytes' => $bytes, 'exceeded_index' => $exceeded, 'accepted' => $accepted];
    }

    /**
     * @param  array<array-key, mixed>  $byteSizes
     * @return array{items: int, non_empty: int, empty: int, accepted_bytes: int, exceeded_index: int, accepted: int}|null
     */
    public static function summarizeManifestStorageBatchNative(array $byteSizes, int $maxTotalBytes = 67108864): ?array
    {
        $sizes = [];
        foreach ($byteSizes as $size) {
            $sizes[] = \is_int($size) ? $size : 0;
        }
        $slots = CollectionReleasePolicy::w396Slots(2, 0, pack('q2', $maxTotalBytes, \count($sizes)).($sizes === [] ? '' : pack('q*', ...$sizes)), 6);
        if ($slots === null || $slots[0] !== \count($sizes) || $slots[1] < 0 || $slots[2] < 0 || $slots[0] !== $slots[1] + $slots[2]
            || $slots[3] < 0 || $slots[4] < -1 || $slots[4] >= $slots[0] || $slots[5] < 0 || $slots[5] > $slots[1]) {
            return null;
        }

        return ['items' => $slots[0], 'non_empty' => $slots[1], 'empty' => $slots[2], 'accepted_bytes' => $slots[3], 'exceeded_index' => $slots[4], 'accepted' => $slots[5]];
    }

    /** Wave 51.2 paired benchmarks decide this default; see RustVerificationManifestNntpReaderDeferralsPayloadW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        intptr_t nntmux_w512_manifest_import_plan_batch(const char *names, size_t names_len, const char *sizes, size_t sizes_len, size_t count, int64_t max_total_bytes, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 51 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns a buffer of at least $val words (its capacity
     * through 'cap').
     */
    private static function w51RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w51', self::W51_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w51RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return $out;
            case 'cap':
                return $capacity;
            default:
                return $enabled;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        $enabled = self::w51RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planManifestImport() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w51RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w51RustFfiState('set', $previous);
        }
    }

    /**
     * Batch import plan for manifest references. One word per name: `flags | min(strlen, 0xFFFF)
     * << 8 | crc32(name) << 32` (flags: 1 valid name, 2 repeated name, 4 non-positive size,
     * 8 admitted, 16 first refusal). Valid first occurrences with a positive size are admitted in
     * order against max(0, $maxTotalBytes) until one no longer fits, which closes admission.
     * Valid, repeated and admitted counts, admitted bytes, the exceeded index (-1 for none) and
     * the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $names
     * @param  list<int>  $byteSizes
     * @return list<int>
     */
    public static function planManifestImport(array $names, array $byteSizes, int $maxTotalBytes = 67108864): array
    {
        return self::usesW51RustFfi()
            ? self::planManifestImportNative($names, $byteSizes, $maxTotalBytes)
            : self::planManifestImportPhp($names, $byteSizes, $maxTotalBytes);
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $byteSizes
     * @return list<int>
     */
    public static function planManifestImportPhp(array $names, array $byteSizes, int $maxTotalBytes = 67108864): array
    {
        $out = $seen = [];
        $budget = max(0, $maxTotalBytes);
        $valid = $repeated = $admitted = $bytes = 0;
        $exceeded = -1;
        foreach ($names as $i => $name) {
            $size = $byteSizes[$i] ?? 0;
            $flags = (int) (preg_match('/\A[0-9a-f]{64}\.json\.gz\z/', $name) === 1);
            $valid += $flags;
            if (isset($seen[$name])) {
                $flags |= 2;
                $repeated++;
            } else {
                $seen[$name] = true;
            }
            if ($size <= 0) {
                $flags |= 4;
            } elseif (($flags & 3) === 1 && $exceeded < 0) {
                // $bytes never exceeds $budget, so the difference cannot overflow.
                if ($size <= $budget - $bytes) {
                    $bytes += $size;
                    $admitted++;
                    $flags |= 8;
                } else {
                    $exceeded = $i;
                    $flags |= 16;
                }
            }
            $out[] = $flags | min(\strlen($name), 0xFFFF) << 8 | crc32($name) << 32;
        }
        $out[] = $valid;
        $out[] = $repeated;
        $out[] = $admitted;
        $out[] = $bytes;
        $out[] = $exceeded;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or a name holds a NUL byte.
     *
     * @param  list<string>  $names
     * @param  list<int>  $byteSizes
     * @return list<int>
     */
    public static function planManifestImportNative(array $names, array $byteSizes, int $maxTotalBytes = 67108864): array
    {
        $count = \count($names);
        $ffi = $count === 0 ? null : self::w51RustFfiState('ffi');
        if (! $ffi instanceof FFI || \count($byteSizes) !== $count || ! array_is_list($names) || ! array_is_list($byteSizes)) {
            return self::planManifestImportPhp($names, $byteSizes, $maxTotalBytes);
        }
        $joined = implode("\0", $names);
        $sizes = pack('P*', ...$byteSizes);
        $words = $count + 6;
        $values = false;
        try {
            $out = self::w51RustFfiState('out', $words);
            if ($out instanceof CData) {
                // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
                $written = $ffi->nntmux_w512_manifest_import_plan_batch($joined, \strlen($joined), $sizes, \strlen($sizes), $count, $maxTotalBytes, $out, self::w51RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (\Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::planManifestImportPhp($names, $byteSizes, $maxTotalBytes);
    }
}
