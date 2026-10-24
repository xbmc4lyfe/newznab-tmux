<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;

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
}
