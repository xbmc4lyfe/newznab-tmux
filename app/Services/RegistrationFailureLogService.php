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
}
