<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/** A retained descriptor prevents the installed inode being reused before cleanup. */
final class NzbSourceInstallation
{
    /** @var resource|null */
    private mixed $handle;

    private bool $keepPrevious = false;

    /** @param resource $handle */
    public function __construct(
        private readonly NzbSourceGuard $sources,
        public readonly string $guid,
        public readonly string $path,
        public readonly string $digest,
        mixed $handle,
        public readonly PDO $pdo,
        // A hard link to the source this installation replaced; rollback restores it, close discards it.
        public readonly ?string $previous = null,
    ) {
        $this->handle = $handle;
    }

    public function ownsCurrentPath(): bool
    {
        if (! is_resource($this->handle)) {
            return false;
        }
        clearstatcache(true, $this->path);
        $owned = fstat($this->handle);
        $current = @lstat($this->path);

        return $owned !== false && $current !== false
            && $owned['dev'] === $current['dev'] && $owned['ino'] === $current['ino'];
    }

    public function rollback(): void
    {
        try {
            $this->sources->rollbackInstallation($this);
        } catch (Throwable $exception) {
            // Restoration failed: the displaced source stays beside the installation for manual recovery.
            $this->keepPrevious = true;
            // Cleanup must not interrupt Laravel's rollback-manager unwind or
            // replace the original publication exception. A retained source is
            // still checked against its actual digest before naming admission.
            try {
                Log::warning('NZB rollback cleanup retained its source.', [
                    'release_guid' => $this->guid,
                    'preserved_source' => $this->previous,
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Logging failure must not recursively reenter rollback either.
            }
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        if ($this->previous !== null && ! $this->keepPrevious && is_file($this->previous)) {
            @unlink($this->previous);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
