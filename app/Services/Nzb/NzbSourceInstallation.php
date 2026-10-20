<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * A retained descriptor prevents the installed inode being reused before cleanup. A database installation
 * (a release_nzbs row written in the caller's transaction) holds no descriptor: its rollback is the
 * transaction's, and its close() removes the legacy files the committed row now shadows.
 */
final class NzbSourceInstallation
{
    /** @var resource|null */
    private mixed $handle;

    private bool $finished = false;

    /**
     * Staged generations of this process's installations whose transactions have not finished, with their connection.
     *
     * @var array<string, PDO>
     */
    private static array $pendingStaged = [];

    /** @param resource|null $handle */
    public function __construct(
        private readonly NzbSourceGuard $sources,
        public readonly string $guid,
        public readonly string $path,
        public readonly string $digest,
        mixed $handle,
        public readonly PDO $pdo,
        // An in-place upgrade's generation, beside $path until its transaction commits: close() swaps it in.
        public readonly ?string $staged = null,
        public readonly bool $database = false,
    ) {
        $this->handle = $handle;
        if ($staged !== null) {
            self::$pendingStaged[$staged] = $pdo;
        }
    }

    /**
     * Whether $path is a generation staged by a transaction still open on $pdo: only its own close() settles it. A
     * reconnected connection's transaction cannot be the one that staged it.
     */
    public static function isPendingStaged(string $path, PDO $pdo): bool
    {
        return (self::$pendingStaged[$path] ?? null) === $pdo && $pdo->inTransaction();
    }

    public function ownsCurrentPath(): bool
    {
        return $this->owns($this->path);
    }

    public function ownsStagedPath(): bool
    {
        return $this->staged !== null && $this->owns($this->staged);
    }

    public function rollback(): void
    {
        // Rolled back: a staged generation is only ever removed, never swapped in.
        $this->finished = true;
        try {
            $this->sources->rollbackInstallation($this);
        } catch (Throwable $exception) {
            // Cleanup must not interrupt Laravel's rollback-manager unwind or
            // replace the original publication exception. A retained source is
            // still checked against its actual digest before naming admission.
            try {
                Log::warning('NZB rollback cleanup retained its source.', [
                    'release_guid' => $this->guid,
                    'staged_source' => $this->staged,
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Logging failure must not recursively reenter rollback either.
            }
        } finally {
            $this->close();
        }
    }

    /**
     * After commit: a staged generation is swapped in only when it is the committed one (see
     * NzbSourceGuard::reconcileStaged()); a failed COMMIT can still run after-commit callbacks.
     */
    public function close(): void
    {
        $this->release();
        if ($this->database && ! $this->finished) {
            $this->finished = true;
            try {
                $this->sources->removeLegacyCopies($this->guid);
            } catch (Throwable $exception) {
                try {
                    Log::warning('Could not remove legacy NZB files after a database installation.', [
                        'release_guid' => $this->guid, 'exception' => $exception::class,
                    ]);
                } catch (Throwable) {
                    // Readers prefer the row; nzb:import-files --delete-files removes them later.
                }
            }

            return;
        }
        if ($this->staged === null || $this->finished) {
            return;
        }
        $this->finished = true;
        if (! is_file($this->staged)) {
            // Already settled by a reader or the next installation.
            return;
        }
        try {
            $this->sources->reconcileStaged($this->staged);
        } catch (Throwable $exception) {
            try {
                Log::warning('Could not swap in a staged NZB generation after commit.', [
                    'staged_source' => $this->staged, 'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Settled later by naming inspection or nzbclean.
            }
        }
    }

    public function __destruct()
    {
        // Only the descriptor: a staged generation left unsettled is reconciled later.
        $this->release();
    }

    private function owns(string $path): bool
    {
        if (! is_resource($this->handle)) {
            return false;
        }
        clearstatcache(true, $path);
        $owned = fstat($this->handle);
        $current = @lstat($path);

        return $owned !== false && $current !== false
            && $owned['dev'] === $current['dev'] && $owned['ino'] === $current['ino'];
    }

    private function release(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        if ($this->staged !== null) {
            unset(self::$pendingStaged[$this->staged]);
        }
    }
}
