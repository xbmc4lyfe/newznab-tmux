<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use FFI;
use Throwable;

/**
 * Guarded Rust candidate for {@see HeaderFilenameIdentity::matches()}. It is not wired into
 * production: the PHP rule stays authoritative until benchmarks qualify a replacement, and
 * scripts/benchmark-filename-identity.php keeps measuring the pair in CI. Null means "use PHP":
 * not Linux CLI with FFI, library missing, built from the Unicode tables of a different PCRE than the
 * one running this PHP, failing its known-answer probe, or input the native side declines.
 *
 * The port's letter, mark, number, digit and whitespace classes are generated from a PCRE
 * (scripts/generate-header-filename-unicode.php). Another PCRE version can classify a code point
 * differently, so the library reports its table version and the wrapper requires it to equal
 * PCRE_VERSION exactly; a deployment must regenerate the tables from the PHP it runs before building.
 */
final class NativeHeaderFilenameIdentity
{
    /** @var array<string, FFI|null> */
    private static array $libraries = [];

    private ?FFI $library = null;

    private bool $resolved = false;

    public function __construct(private readonly ?string $libraryPath = null, private readonly ?string $pcreVersion = null) {}

    /** Whether a verified native library backs this instance. */
    public function available(): bool
    {
        return $this->library() !== null;
    }

    public function matches(string $subject, string $actual): ?bool
    {
        $library = $this->library();
        if ($library === null) {
            return null;
        }

        return self::call($library, $subject, $actual);
    }

    private function library(): ?FFI
    {
        if (! $this->resolved) {
            $this->library = $this->load();
            $this->resolved = true;
        }

        return $this->library;
    }

    private function load(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        try {
            $path = $this->libraryPath ?? (string) config('yenc.native_library', '');
        } catch (Throwable) {
            return null;
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved.':'.($this->pcreVersion ?? PCRE_VERSION);
        if (array_key_exists($key, self::$libraries)) {
            return self::$libraries[$key];
        }
        self::$libraries[$key] = null;
        try {
            $library = FFI::cdef(
                'int nntmux_header_filename_identity_v1(const char *subject, size_t subject_len, const char *actual, size_t actual_len);'
                .' const char *nntmux_header_filename_identity_tables_v1(void);',
                $resolved,
            );
            // PHP FFI returns a `const char *` as a string already.
            // @phpstan-ignore method.notFound
            $tables = $library->nntmux_header_filename_identity_tables_v1();
            if ((is_string($tables) ? $tables : FFI::string($tables)) !== ($this->pcreVersion ?? PCRE_VERSION)) {
                return null;
            }
            foreach (self::probe() as [$subject, $actual, $expected]) {
                if (self::call($library, $subject, $actual) !== $expected) {
                    return null;
                }
            }
            self::$libraries[$key] = $library;

            return $library;
        } catch (Throwable) {
            return null;
        }
    }

    private static function call(FFI $library, string $subject, string $actual): ?bool
    {
        try {
            // @phpstan-ignore method.notFound
            $verdict = $library->nntmux_header_filename_identity_v1($subject, strlen($subject), $actual, strlen($actual));
        } catch (Throwable) {
            return null;
        }

        return match ($verdict) {
            1 => true,
            0 => false,
            default => null,
        };
    }

    /** @return list<array{string, string, bool}> */
    private static function probe(): array
    {
        return [
            ['Release name anything.bin yEnc (1/2)', 'anything.bin', true],
            ['Release name yEnc (1/2)', 'anything.bin', false],
            ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Readable.Release.Name.mkv', true],
            ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Different.Release.mkv', false],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'setup.exe', false],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'b7Qe1ZrT9wXy.mkv', true],
            ["[1/1] - 'movie.mkv' yEnc", 'other.mkv', false],
            ["[1/2] - \"Readable.Release.Name\xff.mkv\" yEnc", 'Different.mkv', false],
            ['[1/9] - "Final.Fantasy.VI.iso" yEnc', 'Final.Fantasy.VII.iso', false],
        ];
    }
}
