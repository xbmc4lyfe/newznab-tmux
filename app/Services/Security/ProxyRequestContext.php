<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Http\Request;
use Throwable;

/**
 * Immutable snapshot of the request facts the IndexerProxyDetector needs.
 *
 * Keeping the detector dependent on this DTO rather than the raw HTTP Request
 * makes each behavioural signal trivial to unit test in isolation.
 */
final readonly class ProxyRequestContext
{
    /**
     * Rust candidates for token resolution and context normalization. They stay
     * opt-in: neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchPageProxySortDropdownParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_proxy_resolve_token(const char *buf, size_t buf_len, const int64_t *lens, size_t count);
        int32_t nntmux_proxy_context_normalize(const char *buf, size_t buf_len, const int64_t *lens, size_t count, int32_t flags, int64_t *out, size_t out_cap);
        C;

    /**
     * Request inputs that carry the user token, in priority order.
     */
    public const array TOKEN_KEYS = ['api_token', 'apikey', 'apiToken', 'r'];

    public function __construct(
        public string $clientIp,
        public string $userAgent,
        public ?string $apiToken,
        public ?string $referer,
        public bool $isDownload,
        public bool $isSearch,
    ) {}

    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::rustEnabled($useRustFfi);
    }

    /**
     * Cached search-domain FFI handle; `false` records a missing library.
     */
    private static function ffiState(bool $assign = false, FFI|false|null $ffi = null): FFI|false|null
    {
        static $state = null;
        if ($assign) {
            $state = $ffi;
        }

        return $state;
    }

    public static function setSearchFfi(FFI|false|null $ffi): void
    {
        self::ffiState(true, $ffi);
    }

    public static function searchFfi(): ?FFI
    {
        if (self::ffiState() === null) {
            self::ffiState(true, RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false);
        }
        $ffi = self::ffiState();

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * Build a context from the incoming request.
     *
     * The middleware already knows whether the request is a download or a
     * search (it computes those to decide the existing UA fast path), so those
     * flags are passed in rather than recomputed here.
     */
    public static function fromRequest(Request $request, bool $isDownload, bool $isSearch): self
    {
        return self::rustEnabled()
            ? self::fromRequestNative($request, $isDownload, $isSearch)
            : self::fromRequestPhp($request, $isDownload, $isSearch);
    }

    public static function fromRequestPhp(Request $request, bool $isDownload, bool $isSearch): self
    {
        return new self(
            clientIp: (string) ($request->ip() ?? ''),
            userAgent: $request->userAgent() ?? '',
            apiToken: self::extractApiToken($request),
            referer: $request->headers->get('referer'),
            isDownload: $isDownload,
            isSearch: $isSearch,
        );
    }

    public static function fromRequestNative(Request $request, bool $isDownload, bool $isSearch): self
    {
        $candidates = [];
        foreach (self::TOKEN_KEYS as $key) {
            $candidates[] = $request->input($key);
        }

        return new self(...self::normalizeContextNative(
            $request->ip(),
            $request->userAgent(),
            $request->headers->get('referer'),
            $candidates,
            $isDownload,
            $isSearch,
        ));
    }

    /**
     * Normalize raw request facts into the constructor arguments.
     *
     * @param  list<mixed>  $tokenCandidates  Token inputs in `TOKEN_KEYS` order.
     * @return array{clientIp: string, userAgent: string, apiToken: ?string, referer: ?string, isDownload: bool, isSearch: bool}
     */
    public static function normalizeContextPhp(
        ?string $clientIp,
        ?string $userAgent,
        ?string $referer,
        array $tokenCandidates,
        bool $isDownload,
        bool $isSearch,
    ): array {
        return [
            'clientIp' => $clientIp ?? '',
            'userAgent' => $userAgent ?? '',
            'apiToken' => self::resolveApiTokenPhp($tokenCandidates),
            'referer' => $referer,
            'isDownload' => $isDownload,
            'isSearch' => $isSearch,
        ];
    }

    /**
     * @param  list<mixed>  $tokenCandidates  Token inputs in `TOKEN_KEYS` order.
     * @return array{clientIp: string, userAgent: string, apiToken: ?string, referer: ?string, isDownload: bool, isSearch: bool}
     */
    public static function normalizeContextNative(
        ?string $clientIp,
        ?string $userAgent,
        ?string $referer,
        array $tokenCandidates,
        bool $isDownload,
        bool $isSearch,
    ): array {
        $ffi = self::searchFfi();
        if ($ffi === null || ! array_is_list($tokenCandidates)) {
            return self::normalizeContextPhp($clientIp, $userAgent, $referer, $tokenCandidates, $isDownload, $isSearch);
        }

        $fields = [$clientIp, $userAgent, $referer, ...$tokenCandidates];
        $count = \count($fields);
        $lens = $ffi->new("int64_t[{$count}]");
        $buf = '';
        foreach ($fields as $i => $field) {
            if (\is_string($field)) {
                $buf .= $field;
                $lens[$i] = \strlen($field);
            } else {
                $lens[$i] = -1;
            }
        }
        $out = $ffi->new('int64_t[9]');
        $flags = ($isDownload ? 1 : 0) | ($isSearch ? 2 : 0);
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_proxy_context_normalize($buf, \strlen($buf), $lens, $count, $flags, $out, 9);
        if ($rc !== 0) {
            return self::normalizeContextPhp($clientIp, $userAgent, $referer, $tokenCandidates, $isDownload, $isSearch);
        }

        $span = static fn (int $slot): ?string => $out[$slot + 1] < 0
            ? null
            : substr($buf, (int) $out[$slot], (int) $out[$slot + 1]);

        return [
            'clientIp' => (string) $span(0),
            'userAgent' => (string) $span(2),
            'apiToken' => $span(4),
            'referer' => $span(6),
            'isDownload' => ($out[8] & 1) === 1,
            'isSearch' => ($out[8] & 2) === 2,
        ];
    }

    /**
     * The first non-empty string token candidate, or null.
     *
     * @param  list<mixed>  $tokenCandidates
     */
    public static function resolveApiToken(array $tokenCandidates): ?string
    {
        return self::rustEnabled()
            ? self::resolveApiTokenNative($tokenCandidates)
            : self::resolveApiTokenPhp($tokenCandidates);
    }

    /**
     * @param  list<mixed>  $tokenCandidates
     */
    public static function resolveApiTokenPhp(array $tokenCandidates): ?string
    {
        foreach ($tokenCandidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $tokenCandidates
     */
    public static function resolveApiTokenNative(array $tokenCandidates): ?string
    {
        $ffi = self::searchFfi();
        $count = \count($tokenCandidates);
        if ($ffi === null || $count === 0 || ! array_is_list($tokenCandidates)) {
            return self::resolveApiTokenPhp($tokenCandidates);
        }

        $lens = $ffi->new("int64_t[{$count}]");
        $buf = '';
        foreach ($tokenCandidates as $i => $value) {
            if (\is_string($value)) {
                $buf .= $value;
                $lens[$i] = \strlen($value);
            } else {
                $lens[$i] = -1;
            }
        }
        // @phpstan-ignore method.notFound
        $index = $ffi->nntmux_proxy_resolve_token($buf, \strlen($buf), $lens, $count);
        if (! \is_int($index) || $index < 0 || $index > $count) {
            return self::resolveApiTokenPhp($tokenCandidates);
        }
        if ($index === $count) {
            return null;
        }
        $value = $tokenCandidates[$index];

        return \is_string($value) ? $value : self::resolveApiTokenPhp($tokenCandidates);
    }

    /**
     * Resolve the user token used to correlate searches with downloads.
     *
     * Newznab (`apikey`), the RSS feeds (`api_token`) and the getnzb RSS token
     * (`r`) all identify the same underlying user, so any of them is a valid
     * correlation key.
     */
    private static function extractApiToken(Request $request): ?string
    {
        foreach (self::TOKEN_KEYS as $key) {
            $value = $request->input($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W47 parity test). */
    public const bool W47_XFF_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_proxy_forwarded_chain_batch(const char *remotes, size_t remotes_len, const char *chains, size_t chains_len, size_t count, const char *trusted, size_t trusted_len, size_t trusted_count, char *out, size_t cap);
        C;

    /** Null restores the benchmark default; setUseRustFfi() is untouched. */
    public static function useW47XffRustFfi(?bool $enabled = true): void
    {
        self::w47XffToggle(true, $enabled);
    }

    public static function usesW47XffRustFfi(): bool
    {
        return self::w47XffToggle() ?? self::W47_XFF_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW47XffRustFfi(): void
    {
        self::w47XffToggle(true, null);
        self::w47Ffi(true);
    }

    /**
     * Resolves X-Forwarded-For chains against trusted proxies (CIDRs or exact addresses; other
     * entries are ignored), two ints per request. The first is the index of the client hop (the
     * rightmost untrusted hop, else the leftmost), -1 when the remote address is invalid or
     * untrusted (it is the client), -2 when a hop is malformed, or -3 without a header. The
     * second is `hops | trusted hops skipped << 8 | port seen << 16 | IPv6 hop << 17 |
     * valid remote << 18 | invalid hops << 24` (counts capped at 255). Hops are comma-separated,
     * blank-trimmed `[v6]`, `[v6]:port`, `v4:port` or bare `filter_var()`-valid addresses.
     *
     * @param  list<string>  $remoteAddrs
     * @param  list<string>  $forwardedFor  Parallel to $remoteAddrs; '' when absent.
     * @param  list<string>  $trustedProxies
     * @return list<int>
     */
    public static function resolveForwardedChains(array $remoteAddrs, array $forwardedFor, array $trustedProxies): array
    {
        return self::usesW47XffRustFfi()
            ? self::resolveForwardedChainsNative($remoteAddrs, $forwardedFor, $trustedProxies)
            : self::resolveForwardedChainsPhp($remoteAddrs, $forwardedFor, $trustedProxies);
    }

    /**
     * @param  list<string>  $remoteAddrs
     * @param  list<string>  $forwardedFor
     * @param  list<string>  $trustedProxies
     * @return list<int>
     */
    public static function resolveForwardedChainsPhp(array $remoteAddrs, array $forwardedFor, array $trustedProxies): array
    {
        $table = [];
        foreach ($trustedProxies as $entry) {
            if (str_contains($entry, '/')) {
                [$address, $prefixText] = explode('/', $entry, 2);
                if ($prefixText === '' || \strlen($prefixText) > 3 || ! ctype_digit($prefixText)) {
                    continue;
                }
                $packed = self::w47PackedIp($address);
                $prefix = (int) $prefixText;
                if ($packed === null || $prefix > \strlen($packed) * 8) {
                    continue;
                }
            } else {
                $packed = self::w47PackedIp($entry);
                if ($packed === null) {
                    continue;
                }
                $prefix = \strlen($packed) * 8;
            }
            $full = $prefix >> 3;
            $bits = $prefix & 7;
            $mask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
            $table[] = [\strlen($packed), $full, $mask, substr($packed, 0, $full), $bits === 0 ? 0 : \ord($packed[$full]) & $mask];
        }

        $out = [];
        foreach ($remoteAddrs as $i => $remote) {
            $header = $forwardedFor[$i] ?? '';
            $remotePacked = self::w47PackedIp($remote);
            $hops = [];
            $count = $invalid = $skipped = 0;
            $port = $v6 = false;
            $present = trim($header, " \t") !== '';
            if ($present) {
                foreach (explode(',', $header) as $raw) {
                    $count++;
                    $hop = self::w47Hop($raw);
                    if ($hop === null) {
                        $invalid++;

                        continue;
                    }
                    $hops[] = $hop[0];
                    $port = $port || $hop[1];
                    $v6 = $v6 || \strlen($hop[0]) === 16;
                }
            }
            if ($remotePacked === null || ! self::w47Trusted($remotePacked, $table)) {
                $client = -1;
            } elseif (! $present) {
                $client = -3;
            } elseif ($invalid > 0) {
                $client = -2;
            } else {
                $client = 0;
                for ($j = \count($hops) - 1; $j >= 0; $j--) {
                    if (! self::w47Trusted($hops[$j], $table)) {
                        $client = $j;

                        break;
                    }
                    $skipped++;
                }
            }
            $out[] = $client;
            $out[] = min($count, 255) | min($skipped, 255) << 8 | ($port ? 1 << 16 : 0) | ($v6 ? 1 << 17 : 0)
                | ($remotePacked !== null ? 1 << 18 : 0) | min($invalid, 255) << 24;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a value holds a NUL byte.
     *
     * @param  list<string>  $remoteAddrs
     * @param  list<string>  $forwardedFor
     * @param  list<string>  $trustedProxies
     * @return list<int>
     */
    public static function resolveForwardedChainsNative(array $remoteAddrs, array $forwardedFor, array $trustedProxies): array
    {
        $count = \count($remoteAddrs);
        if ($count === 0) {
            return [];
        }
        $ffi = self::w47Ffi();
        $remotes = implode("\0", $remoteAddrs);
        $chains = implode("\0", $forwardedFor);
        $trustedCount = \count($trustedProxies);
        $trusted = implode("\0", $trustedProxies);
        if ($ffi === null || \count($forwardedFor) !== $count
            || substr_count($remotes, "\0") !== $count - 1 || substr_count($chains, "\0") !== $count - 1
            || substr_count($trusted, "\0") !== max(0, $trustedCount - 1)) {
            return self::resolveForwardedChainsPhp($remoteAddrs, $forwardedFor, $trustedProxies);
        }
        $bytes = $count * 16;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::resolveForwardedChainsPhp($remoteAddrs, $forwardedFor, $trustedProxies);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_proxy_forwarded_chain_batch($remotes, \strlen($remotes), $chains, \strlen($chains), $count, $trusted, \strlen($trusted), $trustedCount, $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::resolveForwardedChainsPhp($remoteAddrs, $forwardedFor, $trustedProxies);
    }

    /**
     * Readonly classes cannot declare static properties; the toggle lives in a static local.
     */
    private static function w47XffToggle(bool $assign = false, ?bool $enabled = null): ?bool
    {
        static $toggle = null;
        if ($assign) {
            $toggle = $enabled;
        }

        return $toggle;
    }

    /**
     * Cached cleaning_w47 handle; `false` records a missing library.
     */
    private static function w47Ffi(bool $reset = false): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = null;

            return null;
        }
        $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w47', self::W47_FFI_CDEF) ?? false;

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * `inet_pton()` bytes of a `filter_var()`-valid IP, otherwise null.
     */
    private static function w47PackedIp(string $ip): ?string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    /**
     * @return array{string, bool}|null Packed address and whether a port was stripped.
     */
    private static function w47Hop(string $raw): ?array
    {
        $hop = trim($raw, " \t");
        if ($hop !== '' && $hop[0] === '[') {
            $close = strpos($hop, ']');
            if ($close === false) {
                return null;
            }
            $rest = substr($hop, $close + 1);
            if ($rest !== '' && ($rest[0] !== ':' || ! self::w47Port(substr($rest, 1)))) {
                return null;
            }
            $address = substr($hop, 1, $close - 1);
            $port = $rest !== '';
        } elseif (substr_count($hop, ':') === 1) {
            [$address, $portText] = explode(':', $hop, 2);
            if (! self::w47Port($portText)) {
                return null;
            }
            $port = true;
        } else {
            $address = $hop;
            $port = false;
        }
        $packed = self::w47PackedIp($address);

        return $packed === null ? null : [$packed, $port];
    }

    private static function w47Port(string $port): bool
    {
        return $port !== '' && \strlen($port) <= 5 && ctype_digit($port);
    }

    /**
     * @param  list<array{int, int, int, string, int}>  $table
     */
    private static function w47Trusted(string $packed, array $table): bool
    {
        $width = \strlen($packed);
        foreach ($table as [$length, $full, $mask, $head, $tail]) {
            if ($length === $width && strncmp($packed, $head, $full) === 0 && ($mask === 0 || (\ord($packed[$full]) & $mask) === $tail)) {
                return true;
            }
        }

        return false;
    }

    // Rust is the default only while the paired W59.6 benchmark clears 1.5x on all four metrics.
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        int32_t nntmux_w596_proxy_context_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, size_t count, const char *trusted, size_t trusted_len, size_t trusted_count, int64_t *out, size_t out_cap);
        C;

    private const int W59_MAX_ITEMS = 1_048_576;

    public const int W59_CONTEXT_HEADER = 16;

    private const string W59_ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Request context summaries: the client of each remote address and X-Forwarded-For chain as
     * resolveForwardedChainsPhp() picks it, plus the API key class after `trim(" \t")` (0 absent, 1 32
     * lowercase hex, 2 other alphanumeric up to 64 bytes, 3 other bytes, 4 longer than 64 bytes).
     * Client source 0 untrusted remote, 1 forwarded hop, 2 trusted remote without a header, 3 malformed
     * chain, 4 invalid remote.
     *
     * Layout: `[0]` count, `[1..5]` per source, `[6..10]` per key class, `[11]` hops, `[12]` trusted hops
     * skipped, `[13]` distinct client subnets, `[14]` first malformed index (-1), `[15]` FNV-1a digest;
     * then two words per request: the resolveForwardedChainsPhp() flags `| source << 32 | key class << 35
     * | trimmed << 38 | min(key bytes, 255) << 39 | min(client + 3, 65535) << 47`, and the client's `/24`
     * IPv4 (`1 << 48 | a.b.c`) or `/48` IPv6 (`2 << 48 | first six bytes`) subnet key, or -1.
     *
     * @param  array<array-key, string>  $remoteAddrs
     * @param  array<array-key, string>  $forwardedFor  Parallel to $remoteAddrs; '' when absent.
     * @param  array<array-key, string>  $apiKeys  Parallel to $remoteAddrs; '' when absent.
     * @param  array<array-key, string>  $trustedProxies
     * @return list<int>
     */
    public static function summarizeContextBatch(array $remoteAddrs, array $forwardedFor, array $apiKeys, array $trustedProxies): array
    {
        return (self::usesW59RustFfi() ? self::summarizeContextBatchNative($remoteAddrs, $forwardedFor, $apiKeys, $trustedProxies) : null)
            ?? self::summarizeContextBatchPhp($remoteAddrs, $forwardedFor, $apiKeys, $trustedProxies);
    }

    /**
     * @param  array<array-key, string>  $remoteAddrs
     * @param  array<array-key, string>  $forwardedFor
     * @param  array<array-key, string>  $apiKeys
     * @param  array<array-key, string>  $trustedProxies
     * @return list<int>
     */
    public static function summarizeContextBatchPhp(array $remoteAddrs, array $forwardedFor, array $apiKeys, array $trustedProxies): array
    {
        $remotes = array_map(strval(...), array_values($remoteAddrs));
        $forwardedFor = array_values($forwardedFor);
        $apiKeys = array_values($apiKeys);
        $headers = [];
        foreach ($remotes as $at => $remote) {
            $headers[] = (string) ($forwardedFor[$at] ?? '');
        }
        $chains = self::resolveForwardedChainsPhp($remotes, $headers, array_map(strval(...), array_values($trustedProxies)));
        $out = array_fill(0, self::W59_CONTEXT_HEADER, 0);
        $out[0] = \count($remotes);
        $out[14] = -1;
        $digest = 2_166_136_261;
        $rows = $subnets = [];
        foreach ($remotes as $at => $remote) {
            $client = $chains[2 * $at];
            $flags = $chains[2 * $at + 1];
            if ($client >= 0) {
                $source = 1;
                $hop = self::w47Hop(explode(',', $headers[$at])[$client] ?? '');
                $packed = $hop === null ? null : $hop[0];
            } elseif ($client === -1) {
                $valid = ($flags & 1 << 18) !== 0;
                $source = $valid ? 0 : 4;
                $packed = $valid ? self::w47PackedIp($remote) : null;
            } elseif ($client === -3) {
                $source = 2;
                $packed = self::w47PackedIp($remote);
            } else {
                $source = 3;
                $packed = null;
            }
            $raw = (string) ($apiKeys[$at] ?? '');
            $key = trim($raw, " \t");
            $length = \strlen($key);
            $class = match (true) {
                $length === 0 => 0,
                $length > 64 => 4,
                strspn($key, self::W59_ALNUM) !== $length => 3,
                $length === 32 && strspn($key, '0123456789abcdef') === 32 => 1,
                default => 2,
            };
            $subnet = $packed === null ? -1 : self::w59Subnet($packed);
            if ($subnet >= 0) {
                $subnets[$subnet] = true;
            }
            $out[1 + $source]++;
            $out[6 + $class]++;
            $out[11] += $flags & 0xFF;
            $out[12] += ($flags >> 8) & 0xFF;
            if ($source === 3 && $out[14] < 0) {
                $out[14] = $at;
            }
            $word = $flags | $source << 32 | $class << 35 | (int) ($length !== \strlen($raw)) << 38 | min($length, 255) << 39
                | min($client + 3, 65_535) << 47;
            foreach ([$word, $subnet] as $row) {
                $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
                $rows[] = $row;
            }
        }
        $out[13] = \count($subnets);
        $out[15] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summaries, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $remoteAddrs
     * @param  array<array-key, string>  $forwardedFor
     * @param  array<array-key, string>  $apiKeys
     * @param  array<array-key, string>  $trustedProxies
     * @return list<int>|null
     */
    public static function summarizeContextBatchNative(array $remoteAddrs, array $forwardedFor, array $apiKeys, array $trustedProxies): ?array
    {
        $count = \count($remoteAddrs);
        if ($count > self::W59_MAX_ITEMS || \count($forwardedFor) !== $count || \count($apiKeys) !== $count
            || \count($trustedProxies) > self::W59_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w59Strings(array_values($remoteAddrs), array_values($forwardedFor), array_values($apiKeys));
        // A NUL-holding entry is never a valid address or CIDR, so it can be dropped before NUL-joining.
        $trusted = [];
        foreach ($trustedProxies as $entry) {
            $entry = (string) $entry;
            if (! str_contains($entry, "\0")) {
                $trusted[] = $entry;
            }
        }
        $trustedJoined = implode("\0", $trusted);
        $trustedCount = \count($trusted);
        $fields = self::W59_CONTEXT_HEADER + 2 * $count;

        // @phpstan-ignore method.notFound
        return self::w59Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w596_proxy_context_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $trustedJoined, \strlen($trustedJoined), $trustedCount, $out, $fields));
    }

    /**
     * `/24` IPv4 or `/48` IPv6 key of `inet_pton()` bytes.
     */
    private static function w59Subnet(string $packed): int
    {
        if (\strlen($packed) === 4) {
            return 1 << 48 | \ord($packed[0]) << 16 | \ord($packed[1]) << 8 | \ord($packed[2]);
        }
        /** @var array{1: int} $prefix */
        $prefix = unpack('J', "\0\0".substr($packed, 0, 6));

        return 2 << 48 | $prefix[1];
    }

    /**
     * Run `$callback` with the Wave 59.6 Rust batch path forced on or off, then restore the previous routing.
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

    public static function usesW59RustFfi(): bool
    {
        $forced = self::w59RustFfiState();

        return \is_bool($forced) ? $forced : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 59.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w59` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w59', self::W59_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 59.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w59Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w59RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w59Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
