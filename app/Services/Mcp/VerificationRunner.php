<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class VerificationRunner
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

    public function __construct(
        private readonly ProjectPathGuard $paths,
        private readonly ProcessRunner $processes,
        private readonly VerificationPlanner $planner,
    ) {}

    /**
     * @param  array<int, string>  $inputPaths
     * @return array<string, mixed>
     */
    public function run(string $check, array $inputPaths, ?string $testPath = null, ?string $filter = null): array
    {
        $startedAt = hrtime(true);
        $paths = $this->paths->normalizeMany($inputPaths);
        $normalizedTestPath = $testPath !== null ? $this->paths->normalize($testPath) : null;

        if ($normalizedTestPath !== null && (! str_starts_with($normalizedTestPath, 'tests/') || ! str_ends_with($normalizedTestPath, 'Test.php'))) {
            throw new InvalidArgumentException('test_path must identify a PHPUnit Test.php file under tests/.');
        }

        if ($filter !== null && ! self::validFilter($filter)) {
            throw new InvalidArgumentException('filter may only contain a PHPUnit test name or class/method selector.');
        }

        $results = $check === 'targeted'
            ? $this->runTargeted($paths, $normalizedTestPath, $filter)
            : $this->runCheck($check, $paths, $normalizedTestPath, $filter);
        $status = collect($results)->contains(fn (array $result): bool => ! in_array($result['status'], ['passed', 'skipped'], true))
            ? 'failed'
            : 'passed';

        $truncated = collect($results)->contains(fn (array $result): bool => (bool) ($result['truncated'] ?? false));

        Log::channel('nntmux_mcp')->info('MCP tool completed', [
            'tool' => 'run-verification',
            'safe_paths' => array_values(array_filter([...$paths, $normalizedTestPath])),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'status' => $status,
            'truncated' => $truncated,
        ]);

        return [
            'check' => $check,
            'paths' => $paths,
            'status' => $status,
            'results' => $results,
            'warnings' => [],
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    private function runTargeted(array $paths, ?string $testPath, ?string $filter): array
    {
        $plan = $this->planner->plan($paths);
        $plannedChecks = array_column($plan['checks'], 'name');
        $allowedChecks = (array) config('nntmux-mcp.check_profiles.targeted', [
            'phpunit', 'pint', 'phpstan', 'php-lint', 'frontend', 'composer', 'diff',
        ]);
        $checks = (self::$useRustFfi ? self::targetedChecksNative($plannedChecks, $allowedChecks) : null) ?? self::targetedChecksPhp($plannedChecks, $allowedChecks);
        $results = [];

        foreach ($checks as $plannedCheck) {
            $plannedTestPath = $testPath ?? ($plan['test_paths'][0] ?? null);
            if ($plannedCheck === 'phpunit' && $plannedTestPath === null) {
                $results[] = $this->skipped('phpunit', 'No related PHPUnit test was found for the selected paths.');

                continue;
            }

            $results = [...$results, ...$this->runCheck($plannedCheck, $paths, $plannedTestPath, $filter)];
        }

        return $results;
    }

    /**
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    private function runCheck(string $check, array $paths, ?string $testPath, ?string $filter): array
    {
        return match ($check) {
            'phpunit' => [$this->runPhpUnit($testPath, $filter)],
            'phpstan' => [$this->execute('phpstan', [
                $this->vendorBinary('phpstan'), 'analyse', '--memory-limit=2G', '--debug', '--no-progress', ...$paths,
            ])],
            'pint' => [$this->execute('pint', [
                $this->vendorBinary('pint'), '--test', '--format=agent', ...$paths,
            ])],
            'php-lint' => $this->runPhpLint($paths),
            'diff' => $this->runDiffChecks($paths),
            'composer' => [$this->execute('composer', [
                $this->executable('composer', 'composer'), 'validate', '--no-check-publish',
            ])],
            'frontend' => [$this->execute(
                'frontend',
                [$this->executable('npm', 'npm'), 'run', 'build'],
                (int) config('nntmux-mcp.timeouts.frontend_seconds', 900),
            )],
            default => throw new InvalidArgumentException("Unsupported verification check [{$check}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runPhpUnit(?string $testPath, ?string $filter): array
    {
        if ($testPath === null) {
            throw new InvalidArgumentException('test_path is required for the phpunit check.');
        }

        $command = [$this->vendorBinary('phpunit'), '--colors=never', $testPath];
        if ($filter !== null) {
            $command[] = '--filter='.$filter;
        }

        return $this->execute('phpunit', $command, environment: [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/nntmux-mcp-config-'.bin2hex(random_bytes(8)).'.php',
            'DB_CONNECTION' => 'testing',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ]);
    }

    /**
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    private function runPhpLint(array $paths): array
    {
        $files = $this->phpFiles($paths);
        if ($files === []) {
            return [$this->skipped('php-lint', 'No PHP files were selected.')];
        }

        return array_map(
            fn (string $path): array => $this->execute('php-lint', [$this->executable('php', PHP_BINARY), '-l', $path]),
            $files,
        );
    }

    /**
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    private function runDiffChecks(array $paths): array
    {
        return [
            $this->execute('diff-unstaged', ['git', 'diff', '--check', '--', ...$paths]),
            $this->execute('diff-staged', ['git', 'diff', '--cached', '--check', '--', ...$paths]),
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private function execute(
        string $name,
        array $command,
        ?int $timeout = null,
        array $environment = [],
    ): array {
        return [
            'name' => $name,
            ...$this->processes->run(
                $command,
                $timeout ?? (int) config('nntmux-mcp.timeouts.verification_seconds', 600),
                $environment,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skipped(string $name, string $reason): array
    {
        return [
            'name' => $name,
            'command' => [],
            'exit_code' => null,
            'duration_ms' => 0,
            'status' => 'skipped',
            'output' => $reason,
            'truncated' => false,
        ];
    }

    private function executable(string $key, string $fallback): string
    {
        $configured = config('boost.executable_paths.'.$key);

        return is_string($configured) && $configured !== '' ? $configured : $fallback;
    }

    private function vendorBinary(string $binary): string
    {
        $directory = $this->executable('vendor_bin', 'vendor/bin/');

        return (self::$useRustFfi ? self::vendorBinaryNative($directory, $binary) : null) ?? self::vendorBinaryPhp($directory, $binary);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $absolutePath = base_path($path);
            if (is_file($absolutePath) && str_ends_with($path, '.php')) {
                $files[] = $path;

                continue;
            }

            if (! is_dir($absolutePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolutePath));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
                }

                if (count($files) >= 200) {
                    break 2;
                }
            }
        }

        return array_values(array_unique($files));
    }

    public static function vendorBinaryPhp(string $directory, string $binary): string
    {
        return rtrim($directory, '/').'/'.$binary;
    }

    public static function vendorBinaryNative(string $directory, string $binary): ?string
    {
        $output = self::transformNative(6, self::encodeFrames([$directory, $binary]));

        return is_string($output) ? $output : null;
    }

    public static function validFilter(string $filter): bool
    {
        return (self::$useRustFfi ? self::validFilterNative($filter) : null) ?? self::validFilterPhp($filter);
    }

    public static function validFilterPhp(string $filter): bool
    {
        return preg_match('/^[A-Za-z0-9_.:\-\\\\]+$/', $filter) === 1;
    }

    public static function validFilterNative(string $filter): ?bool
    {
        if ((int) ini_get('pcre.backtrack_limit') < strlen($filter) + 1) {
            return null;
        }
        $output = self::transformNative(7, $filter);

        return is_string($output) ? $output === '1' : null;
    }

    /** @param list<string> $planned
     * @param  array<mixed>  $allowed
     * @return list<string>
     */
    public static function targetedChecksPhp(array $planned, array $allowed): array
    {
        return array_values(array_filter(array_values(array_unique($planned)), static fn (string $check): bool => in_array($check, $allowed, true)));
    }

    /** @param list<string> $planned
     * @param  array<mixed>  $allowed
     * @return list<string>|null
     */
    public static function targetedChecksNative(array $planned, array $allowed): ?array
    {
        $allowed = array_values(array_filter($allowed, is_string(...)));
        $output = self::transformNative(8, self::encodeFrames([(string) count($planned), ...$planned, ...$allowed]));

        return is_string($output) ? self::decodeFrames($output) : null;
    }
}
