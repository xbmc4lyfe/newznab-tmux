<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Service for managing tmux sessions and panes
 */
class TmuxSessionManager
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_session_build_window_cmd_nul(const char *base_args, size_t base_args_len, size_t base_count, uint8_t cmd_kind, const char *session_target, size_t session_target_len, const char *window, size_t window_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_session_get_split_setup(int32_t sequential_mode, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    protected string $sessionName;

    protected string $configFile;

    private ?string $lastError = null;

    private ?string $sessionId = null;

    public function __construct(?string $sessionName = null)
    {
        $this->sessionName = $sessionName ?? $this->getSessionName();
        $this->configFile = config('tmux.config_file');
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('irc', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * Get the tmux session name from settings or config
     */
    public function getSessionName(): string
    {
        return app(ConfigurationProvider::class)->tmux()->sessionName;
    }

    public function sessionName(): string
    {
        return $this->sessionName;
    }

    /**
     * Check if tmux session exists
     */
    public function sessionExists(): bool
    {
        $result = Process::timeout(10)
            ->run(TmuxCommand::arguments(['has-session', '-t', $this->sessionId ?? '='.$this->sessionName]));

        if (! $result->successful()) {
            return false;
        }
        $this->target();

        return true;
    }

    /**
     * Create a new tmux session
     */
    public function createSession(string $windowName = 'Monitor'): ?string
    {
        if ($this->sessionExists()) {
            $this->lastError = "Tmux session '{$this->sessionName}' already exists.";

            return null;
        }

        $arguments = TmuxCommand::arguments([]);
        if (file_exists($this->configFile)) {
            array_push($arguments, '-f', $this->configFile);
        }
        array_push(
            $arguments,
            'new-session',
            '-d',
            '-P',
            '-F',
            '#{pane_id}',
            '-s',
            $this->sessionName,
            '-n',
            $windowName,
            '-c',
            base_path(),
            'sleep',
            '86400',
        );

        $result = Process::timeout(30)->run($arguments);

        if (! $result->successful()) {
            $this->lastError = trim($result->errorOutput());

            return null;
        }

        $paneId = trim($result->output());
        if (! preg_match('/^%[0-9]+$/', $paneId)) {
            $this->lastError = "Tmux returned an invalid pane ID: '{$paneId}'.";

            return null;
        }

        $this->lastError = null;
        $this->sessionId = null;
        $this->resolveTarget($paneId);

        $panes = new TmuxPaneManager($this->sessionName);
        if (! $panes->releasePlaceholder($paneId)) {
            $this->killSession();
            $this->lastError = $panes->lastError();

            return null;
        }

        return $paneId;
    }

    /**
     * Kill the tmux session
     */
    public function killSession(): bool
    {
        if (! $this->sessionExists()) {
            return true;
        }

        $result = Process::timeout(30)->run(TmuxCommand::arguments(['kill-session', '-t', $this->target()]));

        return $result->successful();
    }

    /**
     * Attach to the tmux session
     */
    public function attachSession(): bool
    {
        if (! $this->sessionExists()) {
            return false;
        }

        passthru(implode(' ', array_map('escapeshellarg', TmuxCommand::arguments(['attach-session', '-t', $this->target()]))), $exitCode);

        return $exitCode === 0;
    }

    /**
     * List all panes in the session
     *
     * @return array<string, mixed>
     */
    public function listPanes(): array
    {
        if (! $this->sessionExists()) {
            return [];
        }

        $result = Process::timeout(10)->run(
            TmuxCommand::arguments(['list-panes', '-s', '-t', $this->target(), '-F', "#{window_index}:#{pane_index}\t#{pane_title}"])
        );

        if (! $result->successful()) {
            return [];
        }

        $panes = [];
        $lines = explode("\n", trim($result->output()));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            [$position, $title] = array_pad(explode("\t", $line, 2), 2, '');
            $panes[$position] = $title;
        }

        return $panes;
    }

    /**
     * Get pane status
     */
    public function getPaneStatus(string $window, string $pane): ?string
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['display-message', '-p', '-t', $this->target().":{$window}.{$pane}", '#{pane_current_command}'])
        );

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Check if a pane is running a command
     */
    public function isPaneActive(string $window, string $pane): bool
    {
        $status = $this->getPaneStatus($window, $pane);

        return $status !== null && $status !== 'bash' && $status !== 'sh';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Build the `select-layout -t {session}:{window} tiled` tmux command argv.
     *
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildSelectTiledCommand(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        if (self::$useRustFfi) {
            $native = $this->buildSelectTiledCommandNative($window, $sessionTarget, $baseArguments);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->buildSelectTiledCommandPhp($window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>|null
     */
    public function buildSelectTiledCommandNative(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): ?array {
        return $this->buildWindowCommandNative(0, (string) $window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildSelectTiledCommandPhp(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        $target = $sessionTarget ?? $this->target();
        $base = $baseArguments ?? TmuxCommand::arguments([]);

        return array_merge($base, ['select-layout', '-t', "{$target}:{$window}", 'tiled']);
    }

    /**
     * Build the `kill-window -t {session}:{window}` tmux command argv.
     *
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildKillWindowCommand(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        if (self::$useRustFfi) {
            $native = $this->buildKillWindowCommandNative($window, $sessionTarget, $baseArguments);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->buildKillWindowCommandPhp($window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>|null
     */
    public function buildKillWindowCommandNative(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): ?array {
        return $this->buildWindowCommandNative(1, (string) $window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildKillWindowCommandPhp(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        $target = $sessionTarget ?? $this->target();
        $base = $baseArguments ?? TmuxCommand::arguments([]);

        return array_merge($base, ['kill-window', '-t', "{$target}:{$window}"]);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>|null
     */
    private function buildWindowCommandNative(
        int $cmdKind,
        string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): ?array {
        $ffi = self::ffi();
        if ($ffi === null || $window === '' || str_contains($window, "\0")) {
            return null;
        }

        $target = $sessionTarget ?? $this->target();
        if ($target === '' || str_contains($target, "\0")) {
            return null;
        }

        $base = $baseArguments ?? TmuxCommand::arguments([]);
        if ($base === []) {
            return null;
        }
        foreach ($base as $arg) {
            if (str_contains($arg, "\0")) {
                return null;
            }
        }

        $basePacked = implode("\0", $base);

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_session_build_window_cmd_nul(
                $basePacked,
                strlen($basePacked),
                count($base),
                $cmdKind,
                $target,
                strlen($target),
                $window,
                strlen($window),
                $scratch,
                $cap,
            );
            if ($rc <= -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_tmux_session_build_window_cmd_nul(
                    $basePacked,
                    strlen($basePacked),
                    count($base),
                    $cmdKind,
                    $target,
                    strlen($target),
                    $window,
                    strlen($window),
                    $scratch,
                    $cap,
                );
            }
            if ($rc <= 0) {
                return null;
            }

            /** @var list<string> $argv */
            $argv = explode("\0", FFI::string($scratch, $rc));

            return $argv;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Return the pane split layout plan for the given sequential mode (`0` = full, `1` = basic, `2` = stripped).
     *
     * @return list<array{window: int, direction: string, target_role: string, percentage: int, role: string}>
     */
    public function getSplitSetup(int $sequentialMode = 0): array
    {
        if (self::$useRustFfi) {
            $native = $this->getSplitSetupNative($sequentialMode);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->getSplitSetupPhp($sequentialMode);
    }

    /**
     * @return list<array{window: int, direction: string, target_role: string, percentage: int, role: string}>|null
     */
    public function getSplitSetupNative(int $sequentialMode = 0): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_session_get_split_setup(
                $sequentialMode,
                $scratch,
                $cap,
            );
            if ($rc <= -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_tmux_session_get_split_setup(
                    $sequentialMode,
                    $scratch,
                    $cap,
                );
            }
            if ($rc <= 0) {
                return null;
            }

            $rows = [];
            foreach (explode("\n", FFI::string($scratch, $rc)) as $line) {
                $parts = explode("\t", $line);
                if (count($parts) !== 5) {
                    return null;
                }
                $rows[] = [
                    'window' => (int) $parts[0],
                    'direction' => $parts[1],
                    'target_role' => $parts[2],
                    'percentage' => (int) $parts[3],
                    'role' => $parts[4],
                ];
            }

            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{window: int, direction: string, target_role: string, percentage: int, role: string}>
     */
    public function getSplitSetupPhp(int $sequentialMode = 0): array
    {
        return match ($sequentialMode) {
            1 => [
                ['window' => 0, 'direction' => 'h', 'target_role' => 'monitor', 'percentage' => 67, 'role' => 'releases'],
                ['window' => 1, 'direction' => 'h', 'target_role' => 'fix_names', 'percentage' => 50, 'role' => 'remove_crap'],
                ['window' => 2, 'direction' => 'h', 'target_role' => 'post_additional', 'percentage' => 50, 'role' => 'post_movies'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_additional', 'percentage' => 67, 'role' => 'post_tv'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_tv', 'percentage' => 50, 'role' => 'post_metadata'],
            ],
            2 => [
                ['window' => 0, 'direction' => 'h', 'target_role' => 'monitor', 'percentage' => 67, 'role' => 'sequential'],
                ['window' => 1, 'direction' => 'h', 'target_role' => 'fix_names', 'percentage' => 50, 'role' => 'post_metadata'],
            ],
            default => [
                ['window' => 0, 'direction' => 'h', 'target_role' => 'monitor', 'percentage' => 67, 'role' => 'binaries'],
                ['window' => 0, 'direction' => 'v', 'target_role' => 'binaries', 'percentage' => 67, 'role' => 'backfill'],
                ['window' => 0, 'direction' => 'v', 'target_role' => 'backfill', 'percentage' => 50, 'role' => 'releases'],
                ['window' => 1, 'direction' => 'h', 'target_role' => 'fix_names', 'percentage' => 50, 'role' => 'remove_crap'],
                ['window' => 2, 'direction' => 'h', 'target_role' => 'post_additional', 'percentage' => 50, 'role' => 'post_movies'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_additional', 'percentage' => 67, 'role' => 'post_tv'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_tv', 'percentage' => 50, 'role' => 'post_metadata'],
            ],
        };
    }

    public function target(): string
    {
        return $this->sessionId ?? $this->resolveTarget('='.$this->sessionName.':');
    }

    private function resolveTarget(string $target): string
    {
        $result = Process::timeout(10)->run(TmuxCommand::arguments([
            'display-message', '-p', '-t', $target, '#{session_id}',
        ]));
        $id = trim($result->output());
        if (! $result->successful() || ! preg_match('/^\$[0-9]+$/', $id)) {
            throw new \RuntimeException("Unable to resolve exact tmux session '{$this->sessionName}'.");
        }
        $this->sessionId = $id;

        return $id;
    }

    // ========================================================================
    // WAVE 58.4 BATCH HELPER
    // ========================================================================

    /** Disabled: uncommon batches reached only 1.40x mean and 1.45x p99 in the minimum of three paired runs (common mean 2.99x, p99 2.78x), since malformed lines fail fast in PHP and packing dominates; see RustTmuxLayoutMonitorOutputPaneSessionManagerW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = false;

    public const string W58_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_session_line_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per sessionLineRowBatch() record. */
    public const int W58_ROW_WORDS = 6;

    /** int64 summary words after the sessionLineRowBatch() records (none for an empty batch). */
    public const int W58_TRAILER_WORDS = 4;

    /**
     * Session readiness rows for default `list-sessions` lines (kind 0: "NAME: N window[s]
     * (created DATE)" plus optional " (group G)" and " (attached)") and `list-windows` lines
     * (kind 1: "INDEX: NAME[*-#!~] (N pane[s])" plus an optional " [WxH]"); counts and window
     * indexes are 1-9 ASCII digits. Each item is [line, expected name, kind, minimum windows or
     * panes]. A malformed line yields [-1, -1, -1, 0, -1, 0]; otherwise [kind, window index (-1
     * for sessions), name span (offset << 32 | length), windows or panes, created span or
     * width << 32 | height (-1 without geometry), flags (1 attached or current, 2 grouped or
     * last, 4 valid name, 8 matches the expected name, 16 enough windows or panes, 32 ready, 64
     * activity, bell or silence)], followed by the trailer [parsed, matching, ready, malformed].
     * Valid names hold 1-255 bytes without control bytes, and session names also without ':'
     * or '.'.
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $items
     * @return list<int>
     */
    public static function sessionLineRowBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::sessionLineRowBatchNative($items) : self::sessionLineRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $items
     * @return list<int>
     */
    public static function sessionLineRowBatchPhp(array $items): array
    {
        self::w58Items($items);
        $out = [];
        $summary = [0, 0, 0, 0];
        foreach ($items as [$line, $expected, $kind, $minimum]) {
            $parsed = self::w58ParseLine($line, $kind);
            if ($parsed === null) {
                array_push($out, -1, -1, -1, 0, -1, 0);
                $summary[3]++;

                continue;
            }
            [$index, $start, $length, $count, $extra, $bits] = $parsed;
            $name = substr($line, $start, $length);
            $valid = self::w58ValidName($name, $kind === 0);
            $matches = $name === $expected;
            $enough = $count >= $minimum;
            $ready = $valid && $matches && $enough;
            array_push(
                $out,
                $kind,
                $index,
                ($start << 32) | $length,
                $count,
                $extra,
                $bits | ($valid ? 4 : 0) | ($matches ? 8 : 0) | ($enough ? 16 : 0) | ($ready ? 32 : 0),
            );
            $summary[0]++;
            $summary[1] += $matches ? 1 : 0;
            $summary[2] += $ready ? 1 : 0;
        }

        return $items === [] ? [] : [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $items
     * @return list<int>
     */
    public static function sessionLineRowBatchNative(array $items): array
    {
        self::w58Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        $blob = '';
        $lens = [];
        $kinds = [];
        $minimums = [];
        foreach ($items as $item) {
            $blob .= $item[0].$item[1];
            $lens[] = \strlen($item[0]);
            $lens[] = \strlen($item[1]);
            $kinds[] = $item[2];
            $minimums[] = $item[3];
        }

        return self::w58Call($blob, pack('P*', ...$lens, ...$kinds, ...$minimums), $count, $count * self::W58_ROW_WORDS + self::W58_TRAILER_WORDS)
            ?? self::sessionLineRowBatchPhp($items);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}|null [index, name offset, name length, count, extra, flags] */
    private static function w58ParseLine(string $line, int $kind): ?array
    {
        $p = strpos($line, ': ');
        if ($p === false || $p === 0) {
            return null;
        }
        if ($kind === 0) {
            $q = $p + 2;
            $windows = self::w58Digits($line, $q);
            if ($windows === null || substr($line, $q, 7) !== ' window') {
                return null;
            }
            $q += 7;
            if (($line[$q] ?? '') === 's') {
                $q++;
            }
            if (substr($line, $q, 10) !== ' (created ') {
                return null;
            }
            $q += 10;
            $close = strpos($line, ')', $q);
            if ($close === false) {
                return null;
            }
            $tail = substr($line, $close + 1);

            return [-1, 0, $p, $windows, ($q << 32) | ($close - $q),
                (str_contains($tail, ' (attached)') ? 1 : 0) | (str_contains($tail, ' (group ') ? 2 : 0)];
        }
        if ($kind !== 1 || $p > 9 || ! ctype_digit(substr($line, 0, $p))) {
            return null;
        }
        $q = strpos($line, ' (', $p + 2);
        if ($q === false || $q === $p + 2) {
            return null;
        }
        $end = $q;
        $bits = 0;
        while ($end > $p + 2 && str_contains('*-#!~', $line[$end - 1])) {
            $bits |= match ($line[$end - 1]) {
                '*' => 1,
                '-' => 2,
                default => 64,
            };
            $end--;
        }
        $r = $q + 2;
        $panes = self::w58Digits($line, $r);
        if ($panes === null || substr($line, $r, 5) !== ' pane') {
            return null;
        }
        $r += 5;
        if (($line[$r] ?? '') === 's') {
            $r++;
        }
        if (($line[$r] ?? '') !== ')') {
            return null;
        }
        $r++;
        $geometry = -1;
        if (substr($line, $r, 2) === ' [') {
            $g = $r + 2;
            $width = self::w58Digits($line, $g);
            if ($width !== null && ($line[$g] ?? '') === 'x') {
                $g++;
                $height = self::w58Digits($line, $g);
                if ($height !== null && ($line[$g] ?? '') === ']') {
                    $geometry = ($width << 32) | $height;
                }
            }
        }

        return [(int) substr($line, 0, $p), $p + 2, $end - $p - 2, $panes, $geometry, $bits];
    }

    /** 1-9 ASCII digits at $pos, advancing it. */
    private static function w58Digits(string $s, int &$pos): ?int
    {
        $n = strspn($s, '0123456789', $pos, 9);
        if ($n === 0) {
            return null;
        }
        $value = (int) substr($s, $pos, $n);
        $pos += $n;

        return $value;
    }

    private static function w58ValidName(string $name, bool $session): bool
    {
        /** @var array{0: string, 1: string}|null $masks */
        static $masks = null;
        if ($masks === null) {
            $control = implode('', array_map(\chr(...), range(0, 31))).\chr(127);
            $masks = [$control, $control.':.'];
        }
        $length = \strlen($name);

        return $length >= 1 && $length <= 255 && strcspn($name, $masks[$session ? 1 : 0]) === $length;
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W58 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('enabled');
        self::w58RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w58Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W58 session line batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || ! array_is_list($item) || \count($item) !== 4 || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3])) {
                throw new \InvalidArgumentException('W58 session line batch items must be [string line, string expected name, int kind, int minimum] lists.');
            }
        }
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w58Call(string $blob, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w58RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w58', self::W58_FFI_CDEF) ?? false;
            self::w58RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w58RustFfiState('out', $out);
                self::w58RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_session_line_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w58RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W58 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
