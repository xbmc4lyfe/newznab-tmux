<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
    private const string TABLE = 'release_verification_manifests';

    private ?PreparationRunDiagnostics $readDiagnostics = null;

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
        $existing = DB::table(self::TABLE)->where('name', $name)->value('body');
        if ($existing === null) {
            try {
                DB::table(self::TABLE)->insert(['name' => $name, 'body' => $prepared->compressed, 'bytes' => strlen($prepared->compressed)]);

                return $name;
            } catch (UniqueConstraintViolationException) {
                // A concurrent writer committed the same name first; validate what it stored.
                $existing = DB::table(self::TABLE)->where('name', $name)->value('body');
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
    private function consumesExactly(string $body, string $json): bool
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
            $bytes = DB::table(self::TABLE)->where('name', $name)->value('bytes');
            if ($bytes !== null) {
                return (int) $bytes;
            }
        }
        $size = @filesize($this->path($name));
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
     * Copy legacy manifest files into the database after verifying each decodes to its name.
     * Returns counts of imported, already (row present), missing (no file) and invalid files.
     * A file is removed only once the database holds its row; invalid files are left in place.
     *
     * @param  list<string>  $names
     * @return array{imported: int, already: int, missing: int, invalid: int}
     */
    public function importLegacyFiles(array $names, bool $deleteFiles): array
    {
        $counts = ['imported' => 0, 'already' => 0, 'missing' => 0, 'invalid' => 0];
        $names = array_values(array_unique($names));
        foreach ($names as $name) {
            $this->assertName($name);
        }
        $present = array_flip(DB::table(self::TABLE)->whereIn('name', $names)->pluck('name')->all());
        $rows = [];
        foreach ($names as $name) {
            if (isset($present[$name])) {
                $counts['already']++;

                continue;
            }
            $path = $this->path($name);
            $body = is_file($path) ? @file_get_contents($path) : false;
            if ($body === false) {
                $counts['missing']++;

                continue;
            }
            $json = @gzdecode($body);
            if ($json === false || hash('sha256', $json).'.json.gz' !== $name || ! $this->consumesExactly($body, $json)) {
                $counts['invalid']++;

                continue;
            }
            $rows[] = ['name' => $name, 'body' => $body, 'bytes' => strlen($body)];
            $counts['imported']++;
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table(self::TABLE)->insertOrIgnore($chunk);
        }
        if ($deleteFiles) {
            foreach (DB::table(self::TABLE)->whereIn('name', $names)->pluck('name') as $stored) {
                $path = $this->path((string) $stored);
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        return $counts;
    }

    /**
     * Serialize deletion with admissions and retain every manifest still referenced by a candidate.
     * Wait for open transactions to finish so a later rollback cannot restore a deleted reference.
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
            $state = DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null) {
                throw new RuntimeException('Verification capacity state is unavailable; manifest retained.');
            }
            $reference = DB::table('release_verification_candidates')->where('manifest_path', $name)->lockForUpdate()->first(['id']);
            if ($reference !== null) {
                return;
            }
            if ($this->usesDatabase()) {
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
        if ($this->usesDatabase()) {
            $body = DB::table(self::TABLE)->where('name', $name)->value('body');
            if (is_string($body)) {
                return $body;
            }
        }

        return @file_get_contents($this->path($name));
    }

    private function assertName(string $name): void
    {
        if (preg_match('/\A[0-9a-f]{64}\.json\.gz\z/', $name) !== 1) {
            throw new RuntimeException('Invalid manifest reference.');
        }
    }
}
