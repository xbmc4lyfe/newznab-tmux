<?php

declare(strict_types=1);

namespace App\Enums;

use App\Providers\HorizonServiceProvider;
use App\Support\RustPipelineLibrary;
use FFI\CData;

enum TmuxPaneRole: string
{
    case Monitor = 'monitor';
    case Binaries = 'binaries';
    case Backfill = 'backfill';
    case Releases = 'releases';
    case Sequential = 'sequential';
    case FixNames = 'fix_names';
    case RemoveCrap = 'remove_crap';
    case PostAdditional = 'post_additional';
    case PostTv = 'post_tv';
    case PostMetadata = 'post_metadata';
    case PostMovies = 'post_movies';
    case IrcScraper = 'irc_scraper';
    case Htop = 'htop';
    case Nmon = 'nmon';
    case Vnstat = 'vnstat';
    case Tcptrack = 'tcptrack';
    case BandwidthMonitor = 'bandwidth_monitor';
    case Mytop = 'mytop';
    case Redis = 'redis';
    case Console = 'console';

    public const string FFI_CDEF = HorizonServiceProvider::FFI_CDEF;

    // Retain PHP until paired caller benchmarks meet the 1.5x adoption gate.
    private const bool RUST_FFI_DEFAULT = false;

    public static function setUseRustFfi(?bool $use): void
    {
        HorizonServiceProvider::setRustFfiToggle(self::class, $use);
    }

    public static function usesRustFfi(): bool
    {
        return HorizonServiceProvider::rustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Exact backing values or this enum's cases only; no object/scalar coercion.
     * Unknown roles remain null so they cannot be classified as known workers.
     *
     * @return array{value: string|null, is_valid: bool}
     */
    public static function normalizePaneRoleValue(mixed $value): array
    {
        return (self::usesRustFfi() ? self::normalizePaneRoleValueNative($value) : null)
            ?? self::normalizePaneRoleValuePhp($value);
    }

    /** @return array{value: string|null, is_valid: bool} */
    public static function normalizePaneRoleValuePhp(mixed $value): array
    {
        $raw = $value instanceof self ? $value->value : $value;
        $resolved = is_string($raw) ? self::tryFrom($raw) : null;

        return ['value' => $resolved?->value, 'is_valid' => $resolved !== null];
    }

    /** @return array{value: string|null, is_valid: bool}|null */
    public static function normalizePaneRoleValueNative(mixed $value): ?array
    {
        $result = HorizonServiceProvider::normalizeEnumNative(self::class, 1, $value);
        if ($result === null || ($result['value'] !== null && ! is_string($result['value']))) {
            return null;
        }

        return $result;
    }

    // ---- Wave 59.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.96x/6.34x, uncommon 3.39x/3.30x); see RustEnumsTmuxPanePostProcessSequentialPredbKeySeasonPackW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_PANE_ROLE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w592_tmux_pane_role_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 59 routing, binding and output buffer, kept in function-local statics (enums cannot declare static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w59', self::W59_PANE_ROLE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w59RustFfiState('ffi');
                    $buffer = $resolved instanceof \FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
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

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planPaneRoleBatch() routed to Rust ($enabled) or PHP,
     * then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w59Digest(array $out): int
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
    private static function w59Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', \FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** Backing value => [case index, canonical window, canonical pane, layouts (1 Full, 2 Basic, 4 Stripped)]. */
    private const array W59_ROLES = [
        'monitor' => [0, 0, 0, 7], 'binaries' => [1, 0, 1, 1], 'backfill' => [2, 0, 2, 1], 'releases' => [3, 0, 3, 3],
        'sequential' => [4, 0, 1, 4], 'fix_names' => [5, 1, 0, 7], 'remove_crap' => [6, 1, 1, 3], 'post_additional' => [7, 2, 0, 3],
        'post_tv' => [8, 2, 1, 3], 'post_metadata' => [9, 2, 2, 7], 'post_movies' => [10, 2, 3, 3], 'irc_scraper' => [11, 3, 0, 7],
        'htop' => [12, 4, 0, 0], 'nmon' => [13, 4, 1, 0], 'vnstat' => [14, 4, 2, 0], 'tcptrack' => [15, 4, 3, 0],
        'bandwidth_monitor' => [16, 4, 4, 0], 'mytop' => [17, 4, 5, 0], 'redis' => [18, 4, 6, 0], 'console' => [19, 5, 0, 0],
    ];

    /** Backing value without underscores => backing value. */
    private const array W59_COMPACT = [
        'monitor' => 'monitor', 'binaries' => 'binaries', 'backfill' => 'backfill', 'releases' => 'releases', 'sequential' => 'sequential',
        'fixnames' => 'fix_names', 'removecrap' => 'remove_crap', 'postadditional' => 'post_additional', 'posttv' => 'post_tv',
        'postmetadata' => 'post_metadata', 'postmovies' => 'post_movies', 'ircscraper' => 'irc_scraper', 'htop' => 'htop', 'nmon' => 'nmon',
        'vnstat' => 'vnstat', 'tcptrack' => 'tcptrack', 'bandwidthmonitor' => 'bandwidth_monitor', 'mytop' => 'mytop', 'redis' => 'redis',
        'console' => 'console',
    ];

    /** Legacy `W.P` pane target => the first role (in case order) on that window and pane. */
    private const array W59_LEGACY = [
        '0.0' => 'monitor', '0.1' => 'binaries', '0.2' => 'backfill', '0.3' => 'releases', '1.0' => 'fix_names', '1.1' => 'remove_crap',
        '2.0' => 'post_additional', '2.1' => 'post_tv', '2.2' => 'post_metadata', '2.3' => 'post_movies', '3.0' => 'irc_scraper',
        '4.0' => 'htop', '4.1' => 'nmon', '4.2' => 'vnstat', '4.3' => 'tcptrack', '4.4' => 'bandwidth_monitor', '4.5' => 'mytop',
        '4.6' => 'redis', '5.0' => 'console',
    ];

    /**
     * Batch pane-role plan. Per token `role | kind << 5 | layouts << 8 | worker << 11 | window << 12
     * | pane << 16 | min(len, 0xFFF) << 20 | crc32(strtolower(trim(token))) << 32`: role is the case
     * index (31 unresolved); kind 1 the exact backing value, 2 the trimmed, lowercased token with
     * `-`, `.` and spaces as `_`, 3 the lowercased token without `_-. ` naming a case, 4 a legacy
     * `W.P` pane target, 0 unresolved; layouts 1 Full, 2 Basic, 4 Stripped; workers are Binaries
     * through IrcScraper. Then the resolved and unresolved counts, the Full, Basic and Stripped
     * eligible counts, the bitmask of resolved roles, the worker count and the XXH64 digest of all
     * earlier words.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function planPaneRoleBatch(array $tokens): array
    {
        return self::usesW59RustFfi() ? self::planPaneRoleBatchNative($tokens) : self::planPaneRoleBatchPhp($tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function planPaneRoleBatchPhp(array $tokens): array
    {
        $out = [];
        $resolved = $full = $basic = $stripped = $mask = $workers = 0;
        foreach ($tokens as $raw) {
            $raw = (string) $raw;
            $trimmed = trim($raw);
            $lower = strtolower($trimmed);
            $word = min(\strlen($raw), 0xFFF) << 20 | crc32($lower) << 32;
            if (isset(self::W59_ROLES[$raw])) {
                [$value, $kind] = [$raw, 1];
            } elseif (isset(self::W59_ROLES[$normalized = strtr($lower, '-. ', '___')])) {
                [$value, $kind] = [$normalized, 2];
            } elseif (($value = self::W59_COMPACT[str_replace(['_', '-', '.', ' '], '', $lower)] ?? null) !== null) {
                $kind = 3;
            } elseif (($value = self::W59_LEGACY[$trimmed] ?? null) !== null) {
                $kind = 4;
            } else {
                $out[] = $word | 31;

                continue;
            }
            [$role, $window, $pane, $layouts] = self::W59_ROLES[$value];
            $worker = $role >= 1 && $role <= 11 ? 1 : 0;
            $resolved++;
            $mask |= 1 << $role;
            $workers += $worker;
            $full += $layouts & 1;
            $basic += $layouts >> 1 & 1;
            $stripped += $layouts >> 2 & 1;
            $out[] = $word | $role | $kind << 5 | $layouts << 8 | $worker << 11 | $window << 12 | $pane << 16;
        }
        array_push($out, $resolved, \count($tokens) - $resolved, $full, $basic, $stripped, $mask, $workers);
        $out[] = self::w59Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, an item holds a NUL byte or the
     * column is not a list.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function planPaneRoleBatchNative(array $tokens): array
    {
        $count = \count($tokens);
        $words = $count + 8;
        /** @var array{\FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($state === null || ! array_is_list($tokens)) {
            return self::planPaneRoleBatchPhp($tokens);
        }
        $blob = implode("\0", $tokens);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W59_PANE_ROLE_FFI_CDEF.)
            $written = $ffi->nntmux_w592_tmux_pane_role_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }

        return self::w59Words($written, $out, $words) ?? self::planPaneRoleBatchPhp($tokens);
    }
}
