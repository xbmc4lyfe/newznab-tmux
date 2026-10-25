<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
