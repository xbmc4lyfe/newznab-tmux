<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Support\RustPipelineLibrary;
use FFI;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class VerificationPlanner
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_maintenance_verification(const char *input, size_t len, uint32_t mode, char *out, size_t cap);
        C;

    // Single-call candidates remain opt-in until both performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) !== null;
    }

    private static function transformNative(int $mode, string $input): string|false|null
    {
        if (! self::$useRustFfi || strlen($input) > 1_048_576) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 4 + 256), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_maintenance_verification($input, strlen($input), $mode, $scratch, $cap);
            if ($written === -3) {
                return false;
            }
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $values */
    private static function encodeFrames(array $values): string
    {
        $input = '';
        foreach ($values as $value) {
            $input .= pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /** @return list<string>|null */
    private static function decodeFrames(string $output): ?array
    {
        $values = [];
        $offset = 0;
        $size = strlen($output);
        while ($offset < $size) {
            if ($size - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', substr($output, $offset, 4));
            $length = $header['length'];
            $offset += 4;
            if ($length > $size - $offset) {
                return null;
            }
            $values[] = substr($output, $offset, $length);
            $offset += $length;
        }

        return $values;
    }

    /**
     * @param  list<string>  $paths
     * @return array{checks: list<array{name: string, reason: string, command: string}>, test_paths: list<string>}
     */
    public function plan(array $paths): array
    {
        return (self::$useRustFfi ? $this->planNative($paths) : null) ?? $this->planPhp($paths);
    }

    /** @param list<string> $paths
     * @return array{checks: list<array{name: string, reason: string, command: string}>, test_paths: list<string>}
     */
    public function planPhp(array $paths): array
    {
        $phpPaths = array_values(array_filter($paths, fn (string $path): bool => str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')));
        $frontendPaths = array_values(array_filter($paths, self::isFrontendPathPhp(...)));

        return $this->assemblePlan($paths, $phpPaths, $frontendPaths, $this->relatedTests($paths));
    }

    /** @param list<string> $paths
     * @return array{checks: list<array{name: string, reason: string, command: string}>, test_paths: list<string>}|null
     */
    public function planNative(array $paths): ?array
    {
        $flags = self::classifyPathsNative($paths);
        if ($flags === null) {
            return null;
        }
        $phpPaths = $frontendPaths = [];
        foreach ($paths as $i => $path) {
            if ((ord($flags[$i]) & 1) !== 0) {
                $phpPaths[] = $path;
            }
            if ((ord($flags[$i]) & 2) !== 0) {
                $frontendPaths[] = $path;
            }
        }

        return $this->assemblePlan($paths, $phpPaths, $frontendPaths, $this->relatedTests($paths, true));
    }

    /** @param list<string> $paths
     * @param  list<string>  $phpPaths
     * @param  list<string>  $frontendPaths
     * @param  list<string>  $testPaths
     * @return array{checks: list<array{name: string, reason: string, command: string}>, test_paths: list<string>}
     */
    private function assemblePlan(array $paths, array $phpPaths, array $frontendPaths, array $testPaths): array
    {
        $checks = [];

        if ($testPaths !== []) {
            $checks[] = [
                'name' => 'phpunit',
                'reason' => 'Run focused regression tests related to the changed paths.',
                'command' => 'vendor/bin/phpunit '.implode(' ', $testPaths),
            ];
        }

        if ($phpPaths !== []) {
            $checks[] = [
                'name' => 'pint',
                'reason' => 'Check changed PHP files against the project formatting rules without rewriting them.',
                'command' => 'vendor/bin/pint --test --format=agent '.implode(' ', $phpPaths),
            ];
            $checks[] = [
                'name' => 'phpstan',
                'reason' => 'Analyze the changed PHP surface with serial, sandbox-tolerant settings.',
                'command' => 'vendor/bin/phpstan analyse --memory-limit=2G --debug --no-progress '.implode(' ', $phpPaths),
            ];
            $checks[] = [
                'name' => 'php-lint',
                'reason' => 'Check each changed PHP file for syntax errors.',
                'command' => 'php -l <each changed PHP file>',
            ];
        }

        if ($frontendPaths !== []) {
            $checks[] = [
                'name' => 'frontend',
                'reason' => 'Compile Blade, JavaScript, CSS, and Vite inputs after frontend changes.',
                'command' => 'npm run build',
            ];
        }

        if (in_array('composer.json', $paths, true)) {
            $checks[] = [
                'name' => 'composer',
                'reason' => 'Validate Composer metadata after dependency changes.',
                'command' => 'composer validate --no-check-publish',
            ];
        }

        $checks[] = [
            'name' => 'diff',
            'reason' => 'Detect whitespace errors in both staged and unstaged changes for the selected paths.',
            'command' => 'git diff --check -- <paths> and git diff --cached --check -- <paths>',
        ];

        return [
            'checks' => $checks,
            'test_paths' => $testPaths,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function relatedTests(array $paths, bool $native = false): array
    {
        $tests = array_values(array_filter(
            $paths,
            fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php'),
        ));

        if (($native ? self::containsMcpPathNative($paths) : null) ?? self::containsMcpPathPhp($paths)) {
            foreach (['tests/Feature/McpDevelopmentServerTest.php', 'tests/Feature/McpVerificationServicesTest.php'] as $mcpTest) {
                if (is_file(base_path($mcpTest))) {
                    $tests[] = $mcpTest;
                }
            }
        }
        $stems = ($native ? self::relatedTestStemsNative($paths) : null) ?? self::relatedTestStemsPhp($paths);

        $testsRoot = base_path('tests');
        if (! is_dir($testsRoot)) {
            return $tests;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            foreach ($stems as $stem) {
                if ($stem !== '' && str_contains($file->getFilename(), $stem)) {
                    $tests[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
                    break;
                }
            }
        }

        return array_slice(array_values(array_unique($tests)), 0, 10);
    }

    public static function isFrontendPath(string $path): bool
    {
        return (self::$useRustFfi ? self::isFrontendPathNative($path) : null) ?? self::isFrontendPathPhp($path);
    }

    public static function isFrontendPathPhp(string $path): bool
    {
        return str_starts_with($path, 'resources/views/')
            || str_starts_with($path, 'resources/js/')
            || str_starts_with($path, 'resources/css/')
            || in_array($path, ['package.json', 'vite.config.js'], true);
    }

    /**
     * @param  list<string>  $paths
     */
    public static function containsMcpPath(array $paths): bool
    {
        return (self::$useRustFfi ? self::containsMcpPathNative($paths) : null) ?? self::containsMcpPathPhp($paths);
    }

    /** @param list<string> $paths */
    public static function containsMcpPathPhp(array $paths): bool
    {
        return array_any($paths, fn (string $path): bool => str_starts_with($path, 'app/Mcp/')
            || str_starts_with($path, 'app/Services/Mcp/')
            || $path === 'config/nntmux-mcp.php'
            || $path === 'routes/ai.php');
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    public static function relatedTestStemsPhp(array $paths): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $path): string => preg_replace('/(?:Test)?\.php$/', '', basename($path)) ?? '',
            $paths,
        ))));
    }

    /** @param list<string> $paths
     * @return list<string>|null
     */
    public static function relatedTestStemsNative(array $paths): ?array
    {
        // basename and PCRE character tables can depend on LC_CTYPE.
        if (preg_match('/[^\x00-\x7f]/', implode('', $paths)) === 1
            || (int) ini_get('pcre.backtrack_limit') < strlen(implode('', $paths)) + 16) {
            return null;
        }
        $output = self::transformNative(5, self::encodeFrames($paths));

        return is_string($output) ? self::decodeFrames($output) : null;
    }

    public static function isFrontendPathNative(string $path): ?bool
    {
        $flags = self::classifyPathsNative([$path]);

        return $flags === null ? null : (ord($flags[0]) & 2) !== 0;
    }

    /** @param list<string> $paths */
    public static function containsMcpPathNative(array $paths): ?bool
    {
        $flags = self::classifyPathsNative($paths);

        return $flags === null ? null : array_any(str_split($flags), static fn (string $flag): bool => (ord($flag) & 4) !== 0);
    }

    /** @param list<string> $paths */
    private static function classifyPathsNative(array $paths): ?string
    {
        $output = self::transformNative(4, self::encodeFrames($paths));

        return is_string($output) && strlen($output) === count($paths) ? $output : null;
    }
}
