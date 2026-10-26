<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use Throwable;

class RepositoryContextCollector
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_mcp_parse_and_redact_rg_output(const char *input, size_t input_len, size_t max_results, char *out_buf, size_t out_cap);
        int32_t nntmux_mcp_glob_matches(const char *glob, size_t glob_len, const char *path, size_t path_len);
        ssize_t nntmux_mcp_extract_config_keys(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_mcp_search_terms(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    public function __construct(
        private readonly ProjectPathGuard $paths,
        private readonly ProcessRunner $processes,
        private readonly SensitiveValueRedactor $redactor,
        private readonly VerificationPlanner $verificationPlanner,
    ) {}

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $query, string $kind, int $maximumResults): array
    {
        $startedAt = hrtime(true);
        $resolvedKind = $kind === 'auto' ? $this->detectKind($query) : $kind;
        $path = $resolvedKind === 'path' ? $this->paths->normalize($query) : null;
        $terms = $this->searchTerms($query, $resolvedKind, $path);
        $matches = $this->search($terms, $maximumResults);
        $contextPaths = array_values(array_unique(array_filter([
            $path,
            ...array_column($matches, 'path'),
        ])));

        $payload = [
            'kind' => $resolvedKind,
            'query' => $this->redactor->redact($query),
            'architecture_area' => $this->architectureArea($path ?? ($contextPaths[0] ?? '')),
            'matches' => $matches,
            'dependants' => array_values(array_filter($matches, fn (array $match): bool => ! str_starts_with($match['path'], 'tests/'))),
            'related_tests' => array_values(array_unique(array_map(
                fn (array $match): string => $match['path'],
                array_filter($matches, fn (array $match): bool => str_starts_with($match['path'], 'tests/')),
            ))),
            'applicable_rules' => $this->applicableRules($contextPaths, $terms),
            'configuration_keys' => $this->configurationKeys($contextPaths),
            'recent_history' => $path !== null ? $this->recentHistory($path) : [],
            'warnings' => $matches === [] ? ['No matching source locations were found.'] : [],
            'truncated' => count($matches) >= $maximumResults,
        ];

        Log::channel('nntmux_mcp')->info('MCP tool completed', [
            'tool' => 'inspect-code-context',
            'safe_paths' => $contextPaths,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'status' => 'completed',
            'truncated' => $payload['truncated'],
        ]);

        return $payload;
    }

    /**
     * @param  list<string>  $inputPaths
     * @return array<string, mixed>
     */
    public function changeImpact(array $inputPaths): array
    {
        $startedAt = hrtime(true);
        $paths = $this->paths->normalizeMany($inputPaths);
        $maximumResults = max(1, (int) config('nntmux-mcp.limits.max_results', 50));
        $dependants = [];
        $rules = [];

        foreach ($paths as $path) {
            $term = preg_replace('/(?:\.blade)?\.[^.]+$/', '', basename($path)) ?? basename($path);
            $dependants = [...$dependants, ...$this->search([$term], min(10, $maximumResults))];
            $rules = [...$rules, ...$this->applicableRules([$path], [$term])];
        }

        $unstaged = $this->gitDiff($paths, false);
        $staged = $this->gitDiff($paths, true);
        $verification = $this->verificationPlanner->plan($paths);
        $uniqueDependants = $this->uniqueMatches($dependants);
        $dependantsTruncated = count($uniqueDependants) > $maximumResults;

        $payload = [
            'paths' => $paths,
            'areas' => array_values(array_unique(array_map($this->architectureArea(...), $paths))),
            'unstaged_diff' => $unstaged['output'],
            'staged_diff' => $staged['output'],
            'dependants' => array_slice($uniqueDependants, 0, $maximumResults),
            'related_tests' => $verification['test_paths'],
            'applicable_rules' => array_values(array_unique($rules)),
            'affected_interfaces' => $this->affectedInterfaces($paths),
            'verification' => $verification,
            'warnings' => array_values(array_filter([
                $unstaged['status'] === 'failed' ? 'Unable to read the unstaged diff.' : null,
                $staged['status'] === 'failed' ? 'Unable to read the staged diff.' : null,
            ])),
            'truncated' => $unstaged['truncated'] || $staged['truncated'] || $dependantsTruncated,
        ];

        Log::channel('nntmux_mcp')->info('MCP tool completed', [
            'tool' => 'inspect-change-impact',
            'safe_paths' => $paths,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'status' => 'completed',
            'truncated' => $payload['truncated'],
        ]);

        return $payload;
    }

    private function detectKind(string $query): string
    {
        if (str_contains($query, "\n") || preg_match('/(?:Exception|Error|SQLSTATE|Stack trace| at )/i', $query) === 1) {
            return 'error';
        }

        if (str_contains($query, '/') || preg_match('/\.(?:php|js|ts|css|json|md|xml|ya?ml)$/i', $query) === 1) {
            return 'path';
        }

        return 'symbol';
    }

    /**
     * @return list<string>
     */
    private function searchTerms(string $query, string $kind, ?string $path): array
    {
        if ($kind === 'path' && $path !== null) {
            return [preg_replace('/(?:\.blade)?\.[^.]+$/', '', basename($path)) ?? basename($path)];
        }

        if ($kind === 'symbol') {
            return [trim($query)];
        }

        if (self::$useRustFfi) {
            $native = self::searchTermsNative($query);
            if ($native !== null) {
                return $native !== [] ? $native : [mb_substr(trim($query), 0, 120)];
            }
        }

        return $this->searchTermsPhp($query);
    }

    /**
     * @return list<string>
     */
    private function searchTermsPhp(string $query): array
    {
        preg_match_all('/(?:App\\\\[A-Za-z0-9_\\\\]+|app\/[A-Za-z0-9_\/.\-]+\.php|[A-Z][A-Za-z0-9_]+(?:Exception|Service|Controller|Command|Runner|Probe))/', $query, $matches);
        $terms = array_map(
            fn (string $term): string => basename(str_replace('\\', '/', preg_replace('/:\d+$/', '', $term) ?? $term), '.php'),
            $matches[0],
        );

        return array_slice(array_values(array_unique(array_filter($terms))), 0, 5) ?: [mb_substr(trim($query), 0, 120)];
    }

    /**
     * @return list<string>|null
     */
    public static function searchTermsNative(string $query): ?array
    {
        $len = \strlen($query);
        if ($len < 5) {
            return [];
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 64), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mcp_search_terms($query, $len, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_mcp_search_terms($query, $len, $scratch, $cap);
                if (! \is_int($written) || $written <= 0) {
                    return null;
                }
            }

            return explode("\n", FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{path: string, line: int, excerpt: string}>
     */
    private function search(array $terms, int $maximumResults): array
    {
        $roots = array_values(array_filter(
            (array) config('nntmux-mcp.allowed_roots', []),
            fn (string $root): bool => is_dir(base_path($root)),
        ));
        $matches = [];

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $result = $this->processes->run([
                'rg', '--line-number', '--no-heading', '--color=never', '--fixed-strings', '--', $term, ...$roots,
            ], (int) config('nntmux-mcp.timeouts.repository_seconds', 20));

            $remaining = $maximumResults - \count($matches);
            if ($remaining <= 0) {
                break;
            }

            if (self::$useRustFfi) {
                $parsed = self::parseAndRedactRgOutputNative($result['output'], $remaining);
                if ($parsed !== null) {
                    foreach ($parsed as $item) {
                        $matches[] = $item;
                    }
                    if (\count($matches) >= $maximumResults) {
                        break;
                    }

                    continue;
                }
            }

            foreach ($this->parseAndRedactRgOutputPhp($result['output'], $remaining) as $item) {
                $matches[] = $item;
            }
            if (\count($matches) >= $maximumResults) {
                break;
            }
        }

        return $this->uniqueMatches($matches);
    }

    /**
     * @return list<array{path: string, line: int, excerpt: string}>
     */
    private function parseAndRedactRgOutputPhp(string $output, int $maximumResults): array
    {
        if ($output === '' || $maximumResults <= 0) {
            return [];
        }

        $matches = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([^:]+):(\d+):(.*)$/', $line, $parts) !== 1) {
                continue;
            }

            $matches[] = [
                'path' => $parts[1],
                'line' => (int) $parts[2],
                'excerpt' => $this->redactor->redact(trim($parts[3])),
            ];

            if (\count($matches) >= $maximumResults) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @return list<array{path: string, line: int, excerpt: string}>|null
     */
    public static function parseAndRedactRgOutputNative(string $output, int $maximumResults): ?array
    {
        if ($output === '' || $maximumResults <= 0) {
            return [];
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        $len = \strlen($output);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(512, $len + 256), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mcp_parse_and_redact_rg_output($output, $len, $maximumResults, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_mcp_parse_and_redact_rg_output($output, $len, $maximumResults, $scratch, $cap);
                if (! \is_int($written) || $written <= 0) {
                    return null;
                }
            }

            $matches = [];
            foreach (explode("\n", FFI::string($scratch, $written)) as $row) {
                $parts = explode(':', $row, 3);
                if (\count($parts) === 3) {
                    $matches[] = [
                        'path' => $parts[0],
                        'line' => (int) $parts[1],
                        'excerpt' => $parts[2],
                    ];
                }
            }

            return $matches;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{path: string, line: int, excerpt: string}>  $matches
     * @return list<array{path: string, line: int, excerpt: string}>
     */
    private function uniqueMatches(array $matches): array
    {
        $unique = [];
        foreach ($matches as $match) {
            $unique[$match['path'].':'.$match['line']] = $match;
        }

        return array_values($unique);
    }

    private function architectureArea(string $path): string
    {
        foreach ((array) config('nntmux-mcp.architecture_areas', []) as $prefix => $description) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return (string) $description;
            }
        }

        return 'General project infrastructure';
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function applicableRules(array $paths, array $terms): array
    {
        $rules = [];
        $indexPath = base_path('.ai/rules/index.md');
        if (is_file($indexPath)) {
            $rules[] = '.ai/rules/index.md';
            $index = (string) file_get_contents($indexPath);
            if (preg_match_all('/\|\s*`([^`]+)`\s*\|\s*\[[^]]+]\(\.\/([^)]+)\)/', $index, $rows, PREG_SET_ORDER)) {
                foreach ($rows as $row) {
                    foreach ($paths as $path) {
                        if ($this->globMatches($row[1], $path)) {
                            $rules[] = '.ai/rules/'.$row[2];
                        }
                    }
                }
            }
        }

        foreach (array_slice($terms, 0, 3) as $term) {
            if ($term === '' || ! is_dir(base_path('.ai/rules'))) {
                continue;
            }

            $result = $this->processes->run(
                ['rg', '--files-with-matches', '--ignore-case', '--fixed-strings', '--', $term, '.ai/rules'],
                (int) config('nntmux-mcp.timeouts.repository_seconds', 20),
            );
            $rules = [...$rules, ...(preg_split('/\R/', trim($result['output'])) ?: [])];
        }

        return array_values(array_unique(array_filter($rules, fn (string $rule): bool => $rule !== '')));
    }

    private function globMatches(string $glob, string $path): bool
    {
        if ($glob === '**') {
            return true;
        }

        // Retain native PHP in production: two-string FFI marshaling on short (~35B) paths
        // yields 1.18x-1.21x mean and 1.17x-1.26x p99 speedup, below the 1.5x adoption bar.
        return $this->globMatchesPhp($glob, $path);
    }

    private function globMatchesPhp(string $glob, string $path): bool
    {
        if ($glob === '**') {
            return true;
        }

        $quoted = preg_quote($glob, '#');
        $pattern = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $quoted);

        return preg_match('#^'.$pattern.'$#', $path) === 1;
    }

    public static function globMatchesNative(string $glob, string $path): ?bool
    {
        if ($glob === '**') {
            return true;
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_mcp_glob_matches($glob, \strlen($glob), $path, \strlen($path));

            return \is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function configurationKeys(array $paths): array
    {
        $keys = [];
        foreach (array_slice($paths, 0, 20) as $path) {
            try {
                $safePath = $this->paths->normalize($path);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (! is_file(base_path($safePath))) {
                continue;
            }

            $contents = (string) file_get_contents(base_path($safePath));
            if (self::$useRustFfi) {
                $extracted = self::extractConfigKeysNative($contents);
                if ($extracted !== null) {
                    $keys = [...$keys, ...$extracted];

                    continue;
                }
            }

            $keys = [...$keys, ...$this->extractConfigKeysPhp($contents)];
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function extractConfigKeysPhp(string $contents): array
    {
        preg_match_all('/\b(?:config|env)\(\s*["\']([^"\']+)["\']/', $contents, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>|null
     */
    public static function extractConfigKeysNative(string $contents): ?array
    {
        $len = \strlen($contents);
        if ($len < 7) {
            return [];
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, intdiv($len, 4) + 64), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mcp_extract_config_keys($contents, $len, $scratch, $cap);
            if (! \is_int($written) || $written === -1 || $written === -2) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
            if ($written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_mcp_extract_config_keys($contents, $len, $scratch, $cap);
                if (! \is_int($written) || $written <= 0) {
                    return null;
                }
            }

            return explode("\n", FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('mcp_repository_context_collector', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * @return list<array{commit: string, subject: string}>
     */
    private function recentHistory(string $path): array
    {
        $result = $this->processes->run(
            ['git', 'log', '-n', '5', '--format=%h%x09%s', '--', $path],
            (int) config('nntmux-mcp.timeouts.repository_seconds', 20),
        );
        $history = [];

        foreach (preg_split('/\R/', trim($result['output'])) ?: [] as $line) {
            [$commit, $subject] = array_pad(explode("\t", $line, 2), 2, '');
            if ($commit !== '') {
                $history[] = ['commit' => $commit, 'subject' => $subject];
            }
        }

        return $history;
    }

    /**
     * @param  list<string>  $paths
     * @return array{command: list<string>, exit_code: int|null, duration_ms: int, status: string, output: string, truncated: bool}
     */
    private function gitDiff(array $paths, bool $cached): array
    {
        $command = ['git', 'diff', '--no-ext-diff', '--unified=3'];
        if ($cached) {
            $command[] = '--cached';
        }
        $command[] = '--';

        return $this->processes->run(
            [...$command, ...$paths],
            (int) config('nntmux-mcp.timeouts.repository_seconds', 20),
        );
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function affectedInterfaces(array $paths): array
    {
        $interfaces = [];
        foreach ($paths as $path) {
            $interfaces[] = match (true) {
                str_starts_with($path, 'routes/') => 'route registration and URL contracts',
                str_starts_with($path, 'app/Http/Controllers/Api/') => 'public API responses',
                str_starts_with($path, 'app/Console/Commands/') => 'Artisan command interface',
                str_starts_with($path, 'database/migrations/') => 'database schema',
                str_starts_with($path, 'config/'), $path === '.env.example' => 'runtime configuration',
                str_starts_with($path, 'resources/') => 'rendered frontend assets',
                str_starts_with($path, 'app/Mcp/') => 'MCP tool, resource, or prompt contract',
                default => 'internal application behavior',
            };
        }

        return array_values(array_unique($interfaces));
    }

    // ---- Wave 57.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.80x/4.74x, uncommon 5.52x/4.56x); see RustMcpPathGuardRepoContextRedactorStatusTmuxCollectorW57ParityTest. */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = <<<'C'
        intptr_t nntmux_w57_repo_history_plan_batch(const char *outputs, size_t outputs_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 57 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w57', self::W57_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w57RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planHistoryBatch() routed to Rust ($enabled) or PHP, then restores
     * the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w57Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w57Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Batch plan over `git log --format=%h%x09%s` outputs, parsed as recentHistory() does
     * (trim, split on `\R`, commit before the first tab, empty commits skipped). Per output:
     * `flags | min(commits, 0xFFFFFF) << 8 | crc32(entries) << 32`, the entries joined as
     * `commit\tsubject` by newlines. Flags: 1 a line was skipped, 2 a commit line had no tab, 4
     * every commit (at least one) is 4-40 lowercase hex digits. The total commits, outputs
     * without commits, outputs with skipped lines, outputs with untabbed lines, the distinct
     * commit hashes and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $outputs
     * @return list<int>
     */
    public static function planHistoryBatch(array $outputs): array
    {
        return self::usesW57RustFfi() ? self::planHistoryBatchNative($outputs) : self::planHistoryBatchPhp($outputs);
    }

    /**
     * @param  list<string>  $outputs
     * @return list<int>
     */
    public static function planHistoryBatchPhp(array $outputs): array
    {
        $out = $seen = [];
        $total = $empty = $skipped = $untabbed = 0;
        foreach ($outputs as $output) {
            $entries = [];
            $flags = 0;
            $hex = true;
            foreach (preg_split('/\R/', trim((string) $output)) ?: [] as $line) {
                [$commit, $subject] = array_pad(explode("\t", $line, 2), 2, '');
                if ($commit === '') {
                    $flags |= 1;

                    continue;
                }
                if (! str_contains($line, "\t")) {
                    $flags |= 2;
                }
                $length = \strlen($commit);
                $hex = $hex && $length >= 4 && $length <= 40 && strspn($commit, '0123456789abcdef') === $length;
                $entries[] = $commit."\t".$subject;
                $seen[$commit] = true;
            }
            $commits = \count($entries);
            $flags |= $hex && $commits > 0 ? 4 : 0;
            $total += $commits;
            $empty += $commits === 0 ? 1 : 0;
            $skipped += $flags & 1;
            $untabbed += $flags >> 1 & 1;
            $out[] = $flags | min($commits, 0xFFFFFF) << 8 | crc32(implode("\n", $entries)) << 32;
        }
        array_push($out, $total, $empty, $skipped, $untabbed, \count($seen));
        $out[] = self::w57Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, an output holds a NUL byte or
     * the column is not a list.
     *
     * @param  list<string>  $outputs
     * @return list<int>
     */
    public static function planHistoryBatchNative(array $outputs): array
    {
        $count = \count($outputs);
        $words = $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w57RustFfiState('out', $words);
        if ($state === null || ! array_is_list($outputs)) {
            return self::planHistoryBatchPhp($outputs);
        }
        $blob = implode("\0", $outputs);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_repo_history_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w57Words($written, $out, $words) ?? self::planHistoryBatchPhp($outputs);
    }
}
