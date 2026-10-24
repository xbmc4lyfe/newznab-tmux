<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\File;
use SplFileObject;
use Throwable;

class RegistrationFailureLogService
{
    /**
     * Rust parity export; the line parser stays in PHP (below the 1.5x adoption gate, see
     * `tests/Unit/RustLogViewerAndAuditParserParityTest.php`).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_registration_parse_line(const char *line, size_t len, int64_t *out);
        C;

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $ranges = null;

    private string $logsDirectory;

    public function __construct(?string $logsDirectory = null)
    {
        $this->logsDirectory = rtrim($logsDirectory ?? storage_path('logs'), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentFailures(int $limit = 10): array
    {
        $entries = [];

        foreach ($this->registrationLogFiles() as $path) {
            $lines = $this->readLines($path);

            for ($index = count($lines) - 1; $index >= 0; $index--) {
                if (! str_contains($lines[$index], 'Registration attempt failed:')) {
                    continue;
                }

                $entry = $this->parseLine($lines[$index]);

                if ($entry === null) {
                    continue;
                }

                $entries[] = $entry;

                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function registrationLogFiles(): array
    {
        if (! File::isDirectory($this->logsDirectory)) {
            return [];
        }

        $paths = glob($this->logsDirectory.DIRECTORY_SEPARATOR.'registration*.log*') ?: [];
        $paths = array_values(array_filter($paths, static fn (string $path): bool => is_file($path) && is_readable($path)));

        usort($paths, static function (string $left, string $right): int {
            return filemtime($right) <=> filemtime($left);
        });

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function readLines(string $path): array
    {
        $lines = [];
        $file = new SplFileObject($path, 'r');

        while (! $file->eof()) {
            $line = rtrim($file->fgets(), "\r\n");

            if ($line === '') {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseLine(string $line): ?array
    {
        $fields = self::matchLine($line);

        return $fields === null ? null : $this->entry(...$fields);
    }

    /**
     * Rust parity helper for {@see parseLine()}: Rust locates the Monolog fields, PHP decodes the context.
     * Null when the library is unavailable or disabled, or the line needs the PHP reference.
     *
     * @return array{entry: array<string, mixed>|null}|null
     */
    public function parseLineNative(string $line): ?array
    {
        $fields = self::matchLineNative($line);

        if ($fields === null) {
            return null;
        }

        return ['entry' => $fields === false ? null : $this->entry(...$fields)];
    }

    /**
     * The timestamp, level, message and decoded context of a Monolog line, or null when it has no header.
     *
     * @return array{string, string, string, array<mixed>}|null
     */
    public static function matchLine(string $line): ?array
    {
        if (preg_match(
            '/^\[(?<timestamp>[^\]]+)\]\s+[A-Za-z0-9_.-]+\.(?<level>[A-Z]+):\s+(?<message>.*?)\s+(?<context>\{.*\})(?:\s+\[\])?$/',
            $line,
            $matches
        )) {
            $decodedContext = json_decode($matches['context'], true);

            return [$matches['timestamp'], $matches['level'], $matches['message'], is_array($decodedContext) ? $decodedContext : []];
        }

        if (preg_match(
            '/^\[(?<timestamp>[^\]]+)\]\s+[A-Za-z0-9_.-]+\.(?<level>[A-Z]+):\s+(?<message>.+)$/',
            $line,
            $matches
        )) {
            return [$matches['timestamp'], $matches['level'], $matches['message'], []];
        }

        return null;
    }

    /**
     * Rust parity helper for {@see matchLine()}: false when no pattern matches, null when the library is
     * unavailable or disabled, or the line needs the PHP reference (newlines, oversized lines).
     *
     * @return array{string, string, string, array<mixed>}|false|null
     */
    public static function matchLineNative(string $line): array|false|null
    {
        // macOS can retain non-C PCRE character tables after locale restoration.
        if (preg_match('/^\s$/', "\xa0") === 1 && preg_match('/[\x80-\xff]/', $line) === 1) {
            return null;
        }

        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        try {
            self::$ranges ??= $ffi->new('int64_t[8]');
            // @phpstan-ignore method.notFound
            $kind = $ffi->nntmux_registration_parse_line($line, strlen($line), self::$ranges);
        } catch (Throwable) {
            return null;
        }

        if (! is_int($kind) || $kind < 0) {
            return null;
        }

        if ($kind === 0) {
            return false;
        }

        $r = unpack('q8', FFI::string(self::$ranges, 64));

        if ($r === false) {
            return null;
        }

        $context = [];

        if ($kind === 1) {
            $decodedContext = json_decode(substr($line, $r[7], $r[8]), true);
            $context = is_array($decodedContext) ? $decodedContext : [];
        }

        return [substr($line, $r[1], $r[2]), substr($line, $r[3], $r[4]), substr($line, $r[5], $r[6]), $context];
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    private function entry(string $timestamp, string $level, string $message, array $context): array
    {
        return [
            'timestamp' => CarbonImmutable::parse($timestamp),
            'level' => strtolower($level),
            'message' => $message,
            'reason' => $context['reason'] ?? null,
            'username' => $context['username'] ?? null,
            'email' => $context['email'] ?? null,
            'ip' => $context['ip'] ?? null,
            'registration_status' => $context['registration_status'] ?? null,
            'manual_registration_status' => $context['manual_registration_status'] ?? null,
            'context' => $context,
        ];
    }

    private static function ffi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }

        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }
}
