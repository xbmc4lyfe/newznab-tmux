<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LogViewer\Index\LogIndex;
use App\Services\LogViewer\Index\LogIndexer;
use App\Services\LogViewer\LogFile;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

final class IndexLogs extends Command
{
    protected $signature = 'nntmux:index-logs
                            {--file=* : Only index these log files (paths relative to storage/logs)}
                            {--reset : Drop the log index and index every file again from the start}';

    protected $description = 'Tail storage/logs into the Manticore log index used by the admin log viewer search';

    public function handle(LogIndex $index, LogIndexer $indexer): int
    {
        if (! $index->isEnabled()) {
            $this->info('The log index is disabled (LOG_VIEWER_INDEX_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $index->isAvailable()) {
            $this->error('Manticore is not reachable; log viewer searches fall back to grep.');

            return self::FAILURE;
        }

        try {
            if ((bool) $this->option('reset')) {
                $indexer->reset();
                $this->info('Log index dropped; re-indexing from the start.');
            }

            $stats = $indexer->run(self::normalizeFileFilter($this->option('file')));
        } catch (Throwable $exception) {
            $this->error('Log indexing failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(self::formatIndexedSummary(
            $stats['entries'],
            LogFile::formatBytes($stats['bytes']),
            $stats['files'],
            $stats['purged'],
        ));

        return self::SUCCESS;
    }

    // FFI serialization/allocation does not qualify for the 1.5x default-on gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public const string FFI_CDEF = 'ptrdiff_t nntmux_search_schema_log_steam(unsigned int mode, const char *input, size_t input_len, char *out, size_t cap);';

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? false;
    }

    public static function useRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search-schema-log-steam', self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Length-prefixed binary packets preserve PHP byte strings, including NULs.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                if (strlen($item) > 16777216) {
                    return null;
                }
                $input .= pack('V', strlen($item)).$item;
                if (strlen($input) > 16777216) {
                    return null;
                }
            }
            $cap = strlen($input) + 256;
            $out = $ffi->new("char[{$cap}]");
            $written = self::callFfi($ffi, 'nntmux_search_schema_log_steam', $mode, $input, strlen($input), $out, $cap);
            if (is_int($written) && $written < 0 && $written >= -16777216) {
                $cap = -$written;
                $out = $ffi->new("char[{$cap}]");
                $written = self::callFfi($ffi, 'nntmux_search_schema_log_steam', $mode, $input, strlen($input), $out, $cap);
            }
            if (! is_int($written) || $written < 4 || $written > $cap) {
                return null;
            }
            $packet = FFI::string($out, $written);
            $count = unpack('Vcount', $packet)['count'];
            $offset = 4;
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                if ($offset + 4 > $written) {
                    return null;
                }
                $length = unpack('Vlength', $packet, $offset)['length'];
                $offset += 4;
                if ($length > $written - $offset) {
                    return null;
                }
                $result[] = substr($packet, $offset, $length);
                $offset += $length;
            }

            return $offset === $written ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    public static function normalizeFileFilter(mixed $files): array
    {
        return self::useRustFfi() ? self::normalizeFileFilterNative($files) : self::normalizeFileFilterPhp($files);
    }

    /** @return list<string> */
    public static function normalizeFileFilterPhp(mixed $files): array
    {
        return array_values(array_map('strval', (array) $files));
    }

    /** @return list<string> */
    public static function normalizeFileFilterNative(mixed $files): array
    {
        $items = [];
        foreach ((array) $files as $value) {
            if (! is_scalar($value) && $value !== null) {
                return self::normalizeFileFilterPhp($files);
            }
            $items[] = (string) $value;
        }

        return self::nativePacket(1, $items) ?? self::normalizeFileFilterPhp($files);
    }

    public static function formatIndexedSummary(int $entries, string $formattedBytes, int $files, int $purged): string
    {
        return self::useRustFfi() ? self::formatIndexedSummaryNative($entries, $formattedBytes, $files, $purged) : self::formatIndexedSummaryPhp($entries, $formattedBytes, $files, $purged);
    }

    public static function formatIndexedSummaryPhp(int $entries, string $formattedBytes, int $files, int $purged): string
    {
        return sprintf('Indexed %d entries (%s) from %d file(s); %d file(s) purged.', $entries, $formattedBytes, $files, $purged);
    }

    public static function formatIndexedSummaryNative(int $entries, string $formattedBytes, int $files, int $purged): string
    {
        return self::nativePacket(2, [(string) $entries, $formattedBytes, (string) $files, (string) $purged])[0] ?? self::formatIndexedSummaryPhp($entries, $formattedBytes, $files, $purged);
    }
}
