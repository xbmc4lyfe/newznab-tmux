<?php

namespace App\Services\Nzb;

use Throwable;

final class DirectorySyncBridge
{
    public static string $fail = '';

    /** Test-only tracing; production must not retain per-sync history. */
    public static bool $trace = false;

    /** @var list<array<string, bool|int|string|null>> */
    public static array $events = [];

    /** @var \Closure(string, string): void|null */
    public static ?\Closure $afterSync = null;

    /** @param array<string, bool|int|string|null> $event */
    private static function recordEvent(array $event): void
    {
        if (self::$trace) {
            self::$events[] = $event;
        }
    }

    /** Acknowledges only a successfully synchronized local directory descriptor. */
    public static function sync(string $directory, string $boundary): bool
    {
        if (self::$fail === $boundary) {
            self::recordEvent(['boundary' => $boundary, 'ok' => false, 'injected' => true]);

            return false;
        }

        if (! in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || ! function_exists('fsync') || str_contains($directory, '://')) {
            self::recordEvent(['boundary' => $boundary, 'ok' => false, 'failure' => 'unsupported']);

            return false;
        }

        $stream = null;
        $warning = false;
        $ok = false;
        set_error_handler(static function () use (&$warning): bool {
            $warning = true;

            return true;
        });
        try {
            $stream = fopen($directory, 'r');
            if (is_resource($stream)) {
                $metadata = stream_get_meta_data($stream);
                $stat = fstat($stream);
                if ($metadata['wrapper_type'] === 'plainfile' && is_array($stat) && (($stat['mode'] & 0170000) === 0040000)) {
                    $ok = fsync($stream);
                }
            }
        } catch (Throwable) {
            $ok = false;
        } finally {
            try {
                if (is_resource($stream) && ! fclose($stream)) {
                    $ok = false;
                }
            } catch (Throwable) {
                $ok = false;
            } finally {
                restore_error_handler();
            }
        }
        $ok = $ok && ! $warning;
        self::recordEvent(['boundary' => $boundary, 'ok' => $ok]);
        if ($ok && self::$afterSync !== null) {
            (self::$afterSync)($directory, $boundary);
        }

        return $ok;
    }
}
