<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W48 parity test). */
    public const bool W48_FAILURE_DIGEST_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_registration_failure_batch(const char *ips, size_t ips_len, const char *emails, size_t emails_len, const char *reasons, size_t reasons_len, const char *agents, size_t agents_len, const char *agent_lens, size_t agent_lens_len, const char *stamps, size_t stamps_len, size_t count, int64_t window, int64_t limit, char *out, size_t cap);
        C;

    /** Normalized failure reasons and their codes; '' is 0 and any other reason 15. */
    public const array W48_REASONS = [
        'captcha_failed' => 1,
        'invalid_invite' => 2,
        'email_taken' => 3,
        'username_taken' => 4,
        'disposable_email' => 5,
        'registration_closed' => 6,
        'proxy_detected' => 7,
        'rate_limited' => 8,
        'validation_failed' => 9,
        'password_breached' => 10,
    ];

    public const array W48_BOT_AGENTS = ['bot', 'spider', 'crawl'];

    public const array W48_SCRIPT_AGENTS = ['curl/', 'wget/', 'python-requests', 'go-http-client', 'okhttp', 'libwww', 'java/'];

    /** Timestamps and the window clamp to 0..2^40 so differences cannot overflow. */
    public const int W48_MAX_STAMP = 1 << 40;

    private const string W48_DOMAIN_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789.-';

    private static ?bool $w48FailureDigestRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark default; setUseRustFfi() is untouched. */
    public static function useW48FailureDigestRustFfi(?bool $enabled = true): void
    {
        self::$w48FailureDigestRustFfi = $enabled;
    }

    public static function usesW48FailureDigestRustFfi(): bool
    {
        return self::$w48FailureDigestRustFfi ?? self::W48_FAILURE_DIGEST_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW48FailureDigestRustFfi(): void
    {
        self::$w48FailureDigestRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Batch sanitization and rate-spike detection for registration failures. Three words per
     * record: `family | private << 2 | emailValid << 3 | reasonCode << 4 | agentClass << 8 |
     * agentTruncated << 11 | controlBytesRemoved << 12 | agentLength << 20 | emailLength << 28 |
     * spike << 38`, then `crc32(emailDomain) | crc32(normalizedReason) << 32`, then
     * `inWindow | sameIp << 16`: the batch records from the same IP within $window seconds up to
     * this record's timestamp, and all records from that IP. A record spikes when its window
     * holds at least $limit records. Families: 0 invalid, 1 IPv4, 2 IPv6. Agent classes: 0 empty,
     * 1 bot, 2 script, 3 browser, 4 other, matched on the control-free, 255-byte, lowercase agent.
     * Reasons normalize to `[a-z0-9]` runs joined by `_`. A final word holds the FNV-1a 64 digest
     * of the record words.
     *
     * @param  list<string>  $ips
     * @param  list<string>  $emails  Parallel to $ips.
     * @param  list<string>  $reasons  Parallel to $ips.
     * @param  list<string>  $userAgents  Parallel to $ips.
     * @param  list<int>  $timestamps  Unix seconds, parallel to $ips.
     * @return list<int>
     */
    public static function summarizeFailures(array $ips, array $emails, array $reasons, array $userAgents, array $timestamps, int $window, int $limit): array
    {
        return self::usesW48FailureDigestRustFfi()
            ? self::summarizeFailuresNative($ips, $emails, $reasons, $userAgents, $timestamps, $window, $limit)
            : self::summarizeFailuresPhp($ips, $emails, $reasons, $userAgents, $timestamps, $window, $limit);
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $emails
     * @param  list<string>  $reasons
     * @param  list<string>  $userAgents
     * @param  list<int>  $timestamps
     * @return list<int>
     */
    public static function summarizeFailuresPhp(array $ips, array $emails, array $reasons, array $userAgents, array $timestamps, int $window, int $limit): array
    {
        $window = max(0, min(self::W48_MAX_STAMP, $window));
        $records = $stamps = $groups = [];
        foreach ($ips as $i => $ip) {
            $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
            $stamps[$i] = max(0, min(self::W48_MAX_STAMP, $timestamps[$i] ?? 0));
            if ($packed !== false) {
                $groups[$packed][] = $i;
            }
            $email = strtolower(trim($emails[$i] ?? ''));
            $at = strrpos($email, '@');
            $local = $at === false ? $email : substr($email, 0, $at);
            $domain = $at === false ? '' : substr($email, $at + 1);
            $emailOk = substr_count($email, '@') === 1 && $local !== '' && \strlen($local) <= 64
                && \strlen($domain) >= 3 && \strlen($domain) <= 253 && strspn($domain, self::W48_DOMAIN_CHARS) === \strlen($domain)
                && str_contains($domain, '.') && $domain[0] !== '.' && ! str_ends_with($domain, '.') && ! str_contains($domain, '..')
                && preg_match('/[\x00-\x20\x7F]/', $local) !== 1;
            $reason = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($reasons[$i] ?? ''))), '_');
            $agent = $userAgents[$i] ?? '';
            $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $agent);
            $lower = strtolower(substr($clean, 0, 255));
            $class = 4;
            if ($lower === '') {
                $class = 0;
            } elseif (self::w48ContainsAny($lower, self::W48_BOT_AGENTS)) {
                $class = 1;
            } elseif (self::w48ContainsAny($lower, self::W48_SCRIPT_AGENTS)) {
                $class = 2;
            } elseif (str_contains($lower, 'mozilla/')) {
                $class = 3;
            }
            $records[$i] = [
                ($packed === false ? 0 : (\strlen($packed) === 4 ? 1 : 2)) | (int) ($packed !== false && self::w48PrivateIp($packed)) << 2
                    | (int) $emailOk << 3 | ($reason === '' ? 0 : (self::W48_REASONS[$reason] ?? 15)) << 4 | $class << 8
                    | (int) (\strlen($clean) > 255) << 11 | min(255, \strlen($agent) - \strlen($clean)) << 12
                    | \strlen($lower) << 20 | min(1023, \strlen($email)) << 28,
                crc32($domain) | crc32($reason) << 32,
            ];
        }
        $windows = [];
        foreach ($groups as $members) {
            foreach ($members as $i) {
                $within = 0;
                foreach ($members as $j) {
                    if ($stamps[$j] <= $stamps[$i] && $stamps[$j] >= $stamps[$i] - $window) {
                        $within++;
                    }
                }
                $windows[$i] = [$within, \count($members)];
            }
        }
        $out = [];
        foreach ($records as $i => [$word, $crcs]) {
            [$within, $size] = $windows[$i] ?? [0, 0];
            $out[] = $word | (int) (isset($windows[$i]) && $within >= $limit) << 38;
            $out[] = $crcs;
            $out[] = min(65535, $within) | min(65535, $size) << 16;
        }
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; user agents travel concatenated with `pack('V*')` lengths because they may hold
     * NUL bytes. Falls back to PHP when the library is missing, the lists differ in length or an
     * IP, email or reason holds a NUL byte.
     *
     * @param  list<string>  $ips
     * @param  list<string>  $emails
     * @param  list<string>  $reasons
     * @param  list<string>  $userAgents
     * @param  list<int>  $timestamps
     * @return list<int>
     */
    public static function summarizeFailuresNative(array $ips, array $emails, array $reasons, array $userAgents, array $timestamps, int $window, int $limit): array
    {
        $count = \count($ips);
        $ffi = $count === 0 ? null : (self::$w48Ffi instanceof FFI ? self::$w48Ffi : self::w48Ffi());
        $columns = [implode("\0", $ips), implode("\0", $emails), implode("\0", $reasons), implode('', $userAgents)];
        if ($ffi === null || \count($emails) !== $count || \count($reasons) !== $count || \count($userAgents) !== $count || \count($timestamps) !== $count
            || substr_count($columns[0], "\0") !== $count - 1 || substr_count($columns[1], "\0") !== $count - 1
            || substr_count($columns[2], "\0") !== $count - 1) {
            return self::summarizeFailuresPhp($ips, $emails, $reasons, $userAgents, $timestamps, $window, $limit);
        }
        $agentLengths = pack('V*', ...array_map(strlen(...), $userAgents));
        $stamps = pack('P*', ...$timestamps);
        $bytes = ($count * 3 + 1) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::summarizeFailuresPhp($ips, $emails, $reasons, $userAgents, $timestamps, $window, $limit);
            }
            [$a, $b, $c, $d] = $columns;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_registration_failure_batch($a, \strlen($a), $b, \strlen($b), $c, \strlen($c), $d, \strlen($d), $agentLengths, \strlen($agentLengths), $stamps, \strlen($stamps), $count, $window, $limit, $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::summarizeFailuresPhp($ips, $emails, $reasons, $userAgents, $timestamps, $window, $limit);
    }

    private static function w48Ffi(): ?FFI
    {
        self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w48', self::W48_FFI_CDEF) ?? false;

        return self::$w48Ffi === false ? null : self::$w48Ffi;
    }

    /** @param  list<string>  $needles */
    private static function w48ContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loopback, RFC 1918, link-local and CGNAT IPv4; IPv6 loopback, ULA and link-local.
     */
    private static function w48PrivateIp(string $packed): bool
    {
        $a = \ord($packed[0]);
        $b = \ord($packed[1]);
        if (\strlen($packed) === 4) {
            return $a === 10 || $a === 127 || ($a === 172 && ($b & 0xF0) === 16) || ($a === 192 && $b === 168)
                || ($a === 169 && $b === 254) || ($a === 100 && ($b & 0xC0) === 64);
        }

        return $packed === "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\1" || ($a & 0xFE) === 0xFC || ($a === 0xFE && ($b & 0xC0) === 0x80);
    }

    // ========================================================================
    // WAVE 61.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs reached 2.48x common mean, 2.34x common p99, 2.07x uncommon mean and 2.04x uncommon p99; see RustUpdateLockPasswordBreachAniListRecaptchaRegistrationLogW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_REGISTRATION_FAILURE_FFI_CDEF = 'ssize_t nntmux_w614_registration_failure_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per failureLogPlanRowBatch() record. */
    public const int W61_ROW_WORDS = 5;

    /** int64 summary words after the failureLogPlanRowBatch() records (none for an empty batch). */
    public const int W61_TRAILER_WORDS = 8;

    /** Abuse weight per reason code (W48_REASONS; 15 is any other reason). */
    private const array W61_REASON_WEIGHTS = [0, 15, 10, 5, 5, 25, 0, 30, 20, 5, 5, 10, 10, 10, 10, 10];

    /** Abuse weight per agent class: empty, bot, script, browser, other. */
    private const array W61_AGENT_WEIGHTS = [15, 25, 30, 0, 5];

    private const array W61_DISPOSABLE = ['mailinator.com' => true, 'guerrillamail.com' => true, '10minutemail.com' => true, 'tempmail.com' => true,
        'yopmail.com' => true, 'trashmail.com' => true];

    private const string W61_USER_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

    private const string W61_INVITE_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-';

    private const string W61_DIGITS = '0123456789';

    private const string W61_IPV6_BYTES = '0123456789abcdefABCDEF:';

    private const string W61_CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** Bytes an email local part may not hold: controls, space and DEL. */
    private const string W61_LOCAL_FORBIDDEN = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f";

    /** Upper clamp for epoch-second timestamps. */
    private const int W61_TIME_MAX = 1 << 40;

    /**
     * Registration failure log plan rows for [reason, ip, username, email, user agent, invite
     * code, prior attempts from the ip, timestamp, now] items (attempts clamp to 0..2^31, times
     * to 0..2^40).
     *
     * Reasons normalize to `[a-z0-9]` runs joined by '_' and map through W48_REASONS (0 empty,
     * 15 other). Addresses are 0 empty, 1 IPv4 (masked `a << 16 | b << 8 | c`; private for
     * loopback, RFC 1918, link-local and CGNAT), 2 IPv6-shaped (masked to the ':' count) or 3
     * other. Trimmed usernames are 0 empty, 1 3-32 bytes of `[A-Za-z0-9_.-]` or 2 otherwise;
     * trimmed, lowercased emails 0 empty, 1 valid (one '@', 1-64 byte local part without
     * controls or spaces, 3-253 byte `[a-z0-9.-]` dotted domain without edge or doubled dots)
     * or 2 invalid; agents 0 empty, 1 bot, 2 script, 3 browser or 4 other on the lowercased
     * first 255 bytes; trimmed invite codes 0 empty, 1 8-64 bytes of `[A-Za-z0-9-]` or 2
     * otherwise. The abuse score (capped at 100) sums the reason and agent weights, 20 for a
     * disposable domain, 10 for an invalid email, an admin-like username, an invalid invite or
     * an unparsable address, 5 for an invalid username and three per prior attempt (up to 30);
     * severity is 3 at 70, 2 at 40, 1 at 15 and 0 below.
     *
     * Rows [reason | ip << 4 | private << 6 | username << 8 | email << 10 | agent << 12 | invite
     * << 16 | severity << 18 | flags << 20 (1 admin-like, 2 numeric username, 4 disposable, 8
     * agent control byte, 16 agent over 255 bytes, 32 within the last hour), score, age (-1 when
     * in the future), masked ip, attempts], then the trailer [watch, throttle, block, automated
     * agents, disposable, recent, score sum, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function failureLogPlanRowBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::failureLogPlanRowBatchNative($items) : self::failureLogPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function failureLogPlanRowBatchPhp(array $items): array
    {
        self::w61Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$reason, $ip, $username, $email, $agent, $invite, $attempts, $ts, $now]) {
            $reason = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($reason)), '_');
            $reasonCode = $reason === '' ? 0 : (self::W48_REASONS[$reason] ?? 15);
            [$ipClass, $masked] = self::w61Ip($ip);
            $private = false;
            if ($ipClass === 1) {
                $a = $masked >> 16;
                $b = $masked >> 8 & 255;
                $private = $a === 10 || $a === 127 || ($a === 172 && ($b & 0xF0) === 16) || ($a === 192 && $b === 168)
                    || ($a === 169 && $b === 254) || ($a === 100 && ($b & 0xC0) === 64);
            }
            $user = trim($username);
            $userLength = \strlen($user);
            $userClass = $userLength === 0 ? 0 : ($userLength >= 3 && $userLength <= 32 && strspn($user, self::W61_USER_BYTES) === $userLength ? 1 : 2);
            $admin = $userLength >= 5 && stripos($user, 'admin') !== false;
            $numeric = $userLength > 0 && strspn($user, self::W61_DIGITS) === $userLength;
            $email = strtolower(trim($email));
            $emailClass = 0;
            $disposable = false;
            if ($email !== '') {
                $at = strpos($email, '@');
                $emailClass = 2;
                if ($at !== false) {
                    $domain = substr($email, $at + 1);
                    $domainLength = \strlen($domain);
                    if ($at >= 1 && $at <= 64 && ! str_contains($domain, '@') && strcspn($email, self::W61_LOCAL_FORBIDDEN, 0, $at) === $at
                        && $domainLength >= 3 && $domainLength <= 253 && strspn($domain, self::W48_DOMAIN_CHARS) === $domainLength
                        && str_contains($domain, '.') && $domain[0] !== '.' && $domain[$domainLength - 1] !== '.' && ! str_contains($domain, '..')) {
                        $emailClass = 1;
                        $disposable = isset(self::W61_DISPOSABLE[$domain]);
                    }
                }
            }
            $lower = strtolower(substr($agent, 0, 255));
            $agentClass = match (true) {
                $lower === '' => 0,
                self::w48ContainsAny($lower, self::W48_BOT_AGENTS) => 1,
                self::w48ContainsAny($lower, self::W48_SCRIPT_AGENTS) => 2,
                str_contains($lower, 'mozilla/') => 3,
                default => 4,
            };
            $code = trim($invite);
            $inviteLength = \strlen($code);
            $inviteClass = $inviteLength === 0 ? 0 : ($inviteLength >= 8 && $inviteLength <= 64 && strspn($code, self::W61_INVITE_BYTES) === $inviteLength ? 1 : 2);
            $attempts = max(0, min(1 << 31, $attempts));
            $ts = max(0, min(self::W61_TIME_MAX, $ts));
            $now = max(0, min(self::W61_TIME_MAX, $now));
            $age = $ts > $now ? -1 : $now - $ts;
            $recent = $age >= 0 && $age <= 3600;
            $score = min(100, self::W61_REASON_WEIGHTS[$reasonCode] + self::W61_AGENT_WEIGHTS[$agentClass] + ($disposable ? 20 : 0)
                + ($emailClass === 2 ? 10 : 0) + ($admin ? 10 : 0) + ($userClass === 2 ? 5 : 0) + ($inviteClass === 2 ? 10 : 0)
                + ($ipClass === 3 ? 10 : 0) + min(30, $attempts * 3));
            $severity = $score >= 70 ? 3 : ($score >= 40 ? 2 : ($score >= 15 ? 1 : 0));
            $flags = ($admin ? 1 : 0) | ($numeric ? 2 : 0) | ($disposable ? 4 : 0) | (strcspn($lower, self::W61_CONTROL) !== \strlen($lower) ? 8 : 0)
                | (\strlen($agent) > 255 ? 16 : 0) | ($recent ? 32 : 0);
            $word = $reasonCode | $ipClass << 4 | ($private ? 64 : 0) | $userClass << 8 | $emailClass << 10 | $agentClass << 12 | $inviteClass << 16
                | $severity << 18 | $flags << 20;
            array_push($out, $word, $score, $age, $masked, $attempts);
            if ($severity > 0) {
                $summary[$severity - 1]++;
            }
            if ($agentClass === 1 || $agentClass === 2) {
                $summary[3]++;
            }
            if ($disposable) {
                $summary[4]++;
            }
            if ($recent) {
                $summary[5]++;
            }
            $summary[6] += $score;
            $value = $word ^ $score << 32 ^ $age;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function failureLogPlanRowBatchNative(array $items): array
    {
        self::w61Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w61Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3),
            array_column($items, 4), array_column($items, 5)], $count);
        $ints = pack('P*', ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8));

        return self::w61Call($blob, $twin, $ints, $count, $count * self::W61_ROW_WORDS + self::W61_TRAILER_WORDS)
            ?? self::failureLogPlanRowBatchPhp($items);
    }

    /**
     * (class, masked): 0 empty, 1 strict dotted-quad IPv4 (`a << 16 | b << 8 | c`), 2
     * IPv6-shaped (2-39 hex digits and colons, at least two colons, at most one '::'; masked to
     * the colon count), 3 other (-1).
     *
     * @return array{0: int, 1: int}
     */
    private static function w61Ip(string $ip): array
    {
        if ($ip === '') {
            return [0, -1];
        }
        $octets = explode('.', $ip);
        if (\count($octets) === 4) {
            $valid = true;
            foreach ($octets as $octet) {
                $length = \strlen($octet);
                if ($length === 0 || $length > 3 || strspn($octet, self::W61_DIGITS) !== $length || ($length > 1 && $octet[0] === '0') || (int) $octet > 255) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                return [1, (int) $octets[0] << 16 | (int) $octets[1] << 8 | (int) $octets[2]];
            }
        }
        $length = \strlen($ip);
        $colons = substr_count($ip, ':');

        return $length >= 2 && $length <= 39 && $colons >= 2 && substr_count($ip, '::') <= 1 && strspn($ip, self::W61_IPV6_BYTES) === $length
            ? [2, $colons] : [3, -1];
    }

    /** @param array<array-key, mixed> $items */
    private static function w61Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W61 registration failure plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 9 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_string($item[4]) || ! \is_string($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])) {
                throw new InvalidArgumentException('W61 registration failure plan batch items must be [string reason, string ip, string username, string email, string user agent, string invite code, int attempts, int timestamp, int now] lists.');
            }
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W61 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('enabled');
        self::w61RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w61Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w61Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w61RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w61', self::W61_REGISTRATION_FAILURE_FFI_CDEF) ?? false;
            self::w61RustFfiState('ffi', $ffi);
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
                self::w61RustFfiState('out', $out);
                self::w61RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W61_REGISTRATION_FAILURE_FFI_CDEF.)
            $written = $ffi->nntmux_w614_registration_failure_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w61RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W61 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
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

            return null;
        }

        return $state[$op] ?? null;
    }
}
