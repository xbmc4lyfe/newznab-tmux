<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ManifestStore
{
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
        $directory = (string) config('release_verification.storage_path').'/manifests';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Candidate storage is unavailable.');
        }
        $name = $prepared->name;
        $path = $directory.'/'.$name;
        $compressed = $prepared->compressed;
        if (! is_file($path)) {
            $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            $handle = fopen($temporary, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Cannot write candidate manifest.');
            }
            try {
                try {
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

    /** @return array<string, mixed> */
    public function read(string $name): array
    {
        if (preg_match('/\A[0-9a-f]{64}\.json\.gz\z/', $name) !== 1) {
            throw new RuntimeException('Invalid manifest reference.');
        }
        $diagnostics = $this->readDiagnostics;
        $body = $diagnostics === null ? file_get_contents($this->path($name))
            : $diagnostics->measurePhase('file_read', fn () => file_get_contents($this->path($name)));
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

    public function path(string $name): string
    {
        if (basename($name) !== $name) {
            throw new RuntimeException('Invalid manifest reference.');
        }

        return (string) config('release_verification.storage_path').'/manifests/'.$name;
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
        if (preg_match('/\A[0-9a-f]{64}\.json\.gz\z/', $name) !== 1) {
            throw new RuntimeException('Invalid manifest reference.');
        }
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
            $path = $this->path($name);
            if (is_file($path)) {
                unlink($path);
            }
        }, 5);
    }
}
