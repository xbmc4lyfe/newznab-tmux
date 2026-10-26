<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

class CloudflareIpRangeService
{
    private const MANIFEST_VERSION = 1;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_cloudflare_is_valid_cidr(const char *cidr, size_t cidr_len);
        ssize_t nntmux_cloudflare_parse_cidr_body(const char *body, size_t body_len, uint8_t allow_proxy_tokens, char *out_buf, size_t out_cap);
        ssize_t nntmux_cloudflare_normalize_cidrs_nul(const char *input, size_t input_len, size_t count, uint8_t allow_proxy_tokens, char *out_buf, size_t out_cap);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_PROXY_TOKENS = [
        'REMOTE_ADDR',
        'PRIVATE_SUBNETS',
        'private_ranges',
    ];

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * Fetch the latest Cloudflare IP ranges and optionally persist them.
     *
     * @return array<string, mixed>
     */
    public function refresh(bool $persist = true): array
    {
        $ipv4 = $this->fetchRanges((string) config('trustedproxy.cloudflare.ipv4_url'));
        $ipv6 = $this->fetchRanges((string) config('trustedproxy.cloudflare.ipv6_url'));
        $proxies = $this->deduplicate(array_merge($ipv4, $ipv6));

        if ($proxies === []) {
            throw new RuntimeException('Cloudflare did not return any trusted proxy CIDRs.');
        }

        $manifest = [
            'version' => self::MANIFEST_VERSION,
            'updated_at' => now()->toIso8601String(),
            'sources' => [
                'ipv4_url' => (string) config('trustedproxy.cloudflare.ipv4_url'),
                'ipv6_url' => (string) config('trustedproxy.cloudflare.ipv6_url'),
            ],
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'proxies' => $proxies,
        ];

        if ($persist) {
            $this->writeManifest($manifest);
        }

        return $manifest;
    }

    /**
     * Return the currently effective trusted proxies.
     *
     * @return array<int, string>
     */
    public function trustedProxies(): array
    {
        $proxies = $this->deduplicate([
            ...$this->manualTrustedProxies(),
            ...$this->cloudflareTrustedProxies(),
        ]);

        if ($proxies !== []) {
            return $proxies;
        }

        if ((bool) config('trustedproxy.cloudflare.fallback_to_remote_addr', true)) {
            return ['REMOTE_ADDR'];
        }

        return [];
    }

    /**
     * Return manually configured trusted proxies.
     *
     * @return array<int, string>
     */
    public function manualTrustedProxies(): array
    {
        return $this->normalizeConfiguredProxies(config('trustedproxy.proxies'));
    }

    /**
     * Return persisted Cloudflare ranges when Cloudflare trust is enabled.
     *
     * @return array<int, string>
     */
    public function cloudflareTrustedProxies(): array
    {
        if (! (bool) config('trustedproxy.cloudflare.enabled', true)) {
            return [];
        }

        return $this->storedTrustedProxies();
    }

    public function manifestPath(): string
    {
        return $this->resolveManifestPath(
            (string) config('trustedproxy.cloudflare.storage_path', storage_path('app/cloudflare/trusted-proxies.json'))
        );
    }

    private function resolveManifestPath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return storage_path('app/cloudflare/trusted-proxies.json');
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[\\/]~', $path) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function storedTrustedProxies(): array
    {
        $manifest = $this->readManifest();

        if ($manifest === null) {
            return [];
        }

        $ranges = $manifest['proxies'] ?? array_merge($manifest['ipv4'] ?? [], $manifest['ipv6'] ?? []);

        if (! is_array($ranges)) {
            Log::warning('Cloudflare trusted proxy manifest is missing proxy arrays.', [
                'path' => $this->manifestPath(),
            ]);

            return [];
        }

        try {
            return $this->normalizeRanges($ranges);
        } catch (RuntimeException $exception) {
            Log::warning('Ignoring invalid Cloudflare trusted proxy manifest.', [
                'path' => $this->manifestPath(),
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(): ?array
    {
        $path = $this->manifestPath();

        if (! $this->files->exists($path)) {
            return null;
        }

        try {
            $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('Unable to decode Cloudflare trusted proxy manifest.', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, string>
     */
    private function fetchRanges(string $url): array
    {
        if ($url === '') {
            throw new RuntimeException('Cloudflare IP list URL is not configured.');
        }

        $response = Http::accept('text/plain')
            ->connectTimeout((int) config('trustedproxy.cloudflare.connect_timeout', 5))
            ->retry(
                (int) config('trustedproxy.cloudflare.retry_times', 2),
                (int) config('trustedproxy.cloudflare.retry_sleep_ms', 250),
            )
            ->timeout((int) config('trustedproxy.cloudflare.timeout', 10))
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Cloudflare IP refresh failed for [{$url}] with HTTP status {$response->status()}.");
        }

        $ranges = $this->parseRangesFromBody($response->body());

        if ($ranges === []) {
            throw new RuntimeException("Cloudflare IP refresh returned no valid CIDRs for [{$url}].");
        }

        return $ranges;
    }

    /**
     * @return array<int, string>
     */
    private function parseRangesFromBody(string $body): array
    {
        if (self::$useRustFfi) {
            $native = self::parseRangesFromBodyNative($body);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->parseRangesFromBodyPhp($body);
    }

    /**
     * @return array<int, string>
     */
    private function parseRangesFromBodyPhp(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        return $this->normalizeRangesPhp($lines);
    }

    /**
     * @return array<int, string>|null
     */
    public static function parseRangesFromBodyNative(string $body, bool $allowProxyTokens = false): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = strlen($body);
        if ($len === 0) {
            return [];
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cloudflare_parse_cidr_body($body, $len, $allowProxyTokens ? 1 : 0, $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_cloudflare_parse_cidr_body($body, $len, $allowProxyTokens ? 1 : 0, $scratch, $cap);
                if (! is_int($written) || $written < 0) {
                    return null;
                }
            }

            return $written === 0 ? [] : explode("\n", FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>|string|null  $proxies
     * @return array<int, string>
     */
    private function normalizeConfiguredProxies(array|string|null $proxies): array
    {
        if ($proxies === null) {
            return [];
        }

        if (is_string($proxies)) {
            $proxies = preg_split('/[\s,]+/', $proxies) ?: [];
        }

        try {
            return $this->normalizeRanges($proxies, true);
        } catch (RuntimeException $exception) {
            Log::warning('Ignoring invalid manually configured trusted proxies.', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, mixed>  $ranges
     * @return array<int, string>
     */
    private function normalizeRanges(array $ranges, bool $allowProxyTokens = false): array
    {
        if (self::$useRustFfi && count($ranges) >= 4) {
            $native = self::normalizeRangesNative($ranges, $allowProxyTokens);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->normalizeRangesPhp($ranges, $allowProxyTokens);
    }

    /**
     * @param  array<int, mixed>  $ranges
     * @return array<int, string>
     */
    private function normalizeRangesPhp(array $ranges, bool $allowProxyTokens = false): array
    {
        $normalized = [];

        foreach ($ranges as $range) {
            if (! is_string($range)) {
                continue;
            }

            $range = trim($range);

            if ($range === '' || str_starts_with($range, '#')) {
                continue;
            }

            if ($allowProxyTokens && in_array($range, self::ALLOWED_PROXY_TOKENS, true)) {
                $normalized[] = $range === 'private_ranges' ? 'PRIVATE_SUBNETS' : $range;

                continue;
            }

            if (! $this->isValidCidr($range)) {
                throw new RuntimeException("Invalid trusted proxy entry [{$range}].");
            }

            $normalized[] = $range;
        }

        return $this->deduplicate($normalized);
    }

    /**
     * @param  array<int, mixed>  $ranges
     * @return array<int, string>|null
     */
    public static function normalizeRangesNative(array $ranges, bool $allowProxyTokens = false): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $strings = array_values(array_filter($ranges, 'is_string'));
        $count = count($strings);
        if ($count === 0) {
            return [];
        }
        $joined = implode("\0", $strings);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        $len = strlen($joined);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cloudflare_normalize_cidrs_nul($joined, $len, $count, $allowProxyTokens ? 1 : 0, $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_cloudflare_normalize_cidrs_nul($joined, $len, $count, $allowProxyTokens ? 1 : 0, $scratch, $cap);
                if (! is_int($written) || $written < 0) {
                    return null;
                }
            }

            return $written === 0 ? [] : explode("\n", FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private function isValidCidr(string $cidr): bool
    {
        // Retain native PHP validation for standalone single-CIDR calls (1.15x-1.37x mean / 1.20x-1.25x p99 < 1.5x);
        // batch callers (parseRangesFromBody / normalizeRanges) validate in Rust at 2.45x-4.27x speedup.
        return $this->isValidCidrPhp($cidr);
    }

    public static function isValidCidrNative(string $cidr): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_cloudflare_is_valid_cidr($cidr, strlen($cidr));

            return is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isValidCidrPhp(string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$address, $prefix] = explode('/', $cidr, 2);

        if (! ctype_digit($prefix)) {
            return false;
        }

        $isIpv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = ! $isIpv4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $isIpv4 && ! $isIpv6) {
            return false;
        }

        $maxPrefix = $isIpv4 ? 32 : 128;
        $prefixLength = (int) $prefix;

        return $prefixLength >= 0 && $prefixLength <= $maxPrefix;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cloudflare_cidr', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * @param  array<int, string>  $ranges
     * @return array<int, string>
     */
    private function deduplicate(array $ranges): array
    {
        return array_values(array_unique($ranges));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(array $manifest): void
    {
        $path = $this->manifestPath();
        $directory = dirname($path);
        $temporaryPath = $path.'.tmp';

        $this->files->ensureDirectoryExists($directory);
        $this->files->put(
            $temporaryPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }

        if (! @rename($temporaryPath, $path)) {
            $this->files->delete($temporaryPath);

            throw new RuntimeException("Unable to persist the Cloudflare trusted proxy manifest to [{$path}].");
        }
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W47 parity test). */
    public const bool W47_CIDR_MATCH_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_cloudflare_cidr_match_batch(const char *addresses, size_t addresses_len, size_t address_count, const char *ranges, size_t ranges_len, size_t range_count, char *out, size_t cap);
        C;

    private static ?bool $w47CidrMatchRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark default; setUseRustFfi() is untouched. */
    public static function useW47CidrMatchRustFfi(?bool $enabled = true): void
    {
        self::$w47CidrMatchRustFfi = $enabled;
    }

    public static function usesW47CidrMatchRustFfi(): bool
    {
        return self::$w47CidrMatchRustFfi ?? self::W47_CIDR_MATCH_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW47CidrMatchRustFfi(): void
    {
        self::$w47CidrMatchRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Verifies a CIDR table and matches addresses against it. The result holds one int per range
     * (`prefix | host bits set << 8 | IPv6 << 9`, or -1 for an entry that is not
     * `filter_var()`-valid `address/prefix` with a one-to-three digit prefix within the address
     * width), then one per address: the index of the first valid range containing it, -1 for
     * none, or -2 when it is not a valid IP. Families never cross (no IPv4-mapped matching).
     *
     * @param  list<string>  $addresses
     * @param  list<string>  $ranges
     * @return list<int>
     */
    public static function matchAddresses(array $addresses, array $ranges): array
    {
        return self::usesW47CidrMatchRustFfi() ? self::matchAddressesNative($addresses, $ranges) : self::matchAddressesPhp($addresses, $ranges);
    }

    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $ranges
     * @return list<int>
     */
    public static function matchAddressesPhp(array $addresses, array $ranges): array
    {
        $out = [];
        $table = [];
        foreach ($ranges as $cidr) {
            $parts = explode('/', $cidr, 2);
            $prefixText = $parts[1] ?? '';
            $packed = $prefixText !== '' && \strlen($prefixText) <= 3 && ctype_digit($prefixText) && filter_var($parts[0], FILTER_VALIDATE_IP) !== false
                ? inet_pton($parts[0])
                : false;
            $prefix = (int) $prefixText;
            if ($packed === false || $prefix > \strlen($packed) * 8) {
                $out[] = -1;

                continue;
            }
            $full = $prefix >> 3;
            $bits = $prefix & 7;
            $mask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
            $hostBits = ($bits !== 0 && (\ord($packed[$full]) & ~$mask & 0xFF) !== 0)
                || trim(substr($packed, $full + ($bits === 0 ? 0 : 1)), "\0") !== '';
            $table[] = [\strlen($packed), $full, $mask, substr($packed, 0, $full), $bits === 0 ? 0 : \ord($packed[$full]) & $mask, \count($out)];
            $out[] = $prefix | ($hostBits ? 256 : 0) | (\strlen($packed) === 16 ? 512 : 0);
        }
        foreach ($addresses as $address) {
            $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);
            if ($packed === false) {
                $out[] = -2;

                continue;
            }
            $width = \strlen($packed);
            $match = -1;
            foreach ($table as [$length, $full, $mask, $head, $tail, $index]) {
                if ($length === $width && strncmp($packed, $head, $full) === 0 && ($mask === 0 || (\ord($packed[$full]) & $mask) === $tail)) {
                    $match = $index;

                    break;
                }
            }
            $out[] = $match;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or an entry holds a NUL byte.
     *
     * @param  list<string>  $addresses
     * @param  list<string>  $ranges
     * @return list<int>
     */
    public static function matchAddressesNative(array $addresses, array $ranges): array
    {
        $addressCount = \count($addresses);
        $rangeCount = \count($ranges);
        if ($addressCount + $rangeCount === 0) {
            return [];
        }
        $ffi = self::$w47Ffi instanceof FFI ? self::$w47Ffi : self::w47Ffi();
        $joinedAddresses = implode("\0", $addresses);
        $joinedRanges = implode("\0", $ranges);
        if ($ffi === null
            || substr_count($joinedAddresses, "\0") !== max(0, $addressCount - 1)
            || substr_count($joinedRanges, "\0") !== max(0, $rangeCount - 1)) {
            return self::matchAddressesPhp($addresses, $ranges);
        }
        $bytes = ($addressCount + $rangeCount) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::matchAddressesPhp($addresses, $ranges);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_cloudflare_cidr_match_batch($joinedAddresses, \strlen($joinedAddresses), $addressCount, $joinedRanges, \strlen($joinedRanges), $rangeCount, $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::matchAddressesPhp($addresses, $ranges);
    }

    private static function w47Ffi(): ?FFI
    {
        self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w47', self::W47_FFI_CDEF) ?? false;

        return self::$w47Ffi === false ? null : self::$w47Ffi;
    }

    /**
     * Enabled: the paired benchmark in the W60 parity test measures 14.382x common mean, 14.085x
     * common p99, 13.021x uncommon mean and 12.163x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_CLOUDFLARE_IP_RANGE_FFI_CDEF = 'ssize_t nntmux_w601_cloudflare_ip_range_classify_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W60_RANGE_WORDS = 6;

    public const int W60_RANGE_TAIL = 8;

    public const int W60_CLASS_SHIFT = 4;

    public const int W60_TRIMMED = 256;

    public const int W60_SKIPPED = 512;

    public const int W60_INVALID_ADDRESS = 1024;

    public const int W60_PREFIX_IMPLIED = 2048;

    public const int W60_PREFIX_INVALID = 4096;

    public const int W60_HOST_BITS = 8192;

    public const int W60_CIDR_VALID = 16384;

    public const int W60_CF_MEMBER = 32768;

    public const int W60_PROXY_TOKEN = 65536;

    /** Cloudflare's published IPv4 then IPv6 ranges; a member reports its index. */
    public const array W60_CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 60 batch routed to Rust or PHP, then restores the routing. */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function ipRangeClassifyBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::ipRangeClassifyBatchNative($rows) : self::ipRangeClassifyBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function ipRangeClassifyBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W60_RANGE_WORDS + self::W60_RANGE_TAIL;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($buffer === null) {
            return self::ipRangeClassifyBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound (Declared by W60_CLOUDFLARE_IP_RANGE_FFI_CDEF.)
            $written = $ffi->nntmux_w601_cloudflare_ip_range_classify_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::ipRangeClassifyBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($result);
    }

    /**
     * Six words per trusted-proxy entry (trimmed): the family (1 IPv4, 2 IPv6), the address
     * class `<< W60_CLASS_SHIFT` (see w60AddressClass()) and the trimmed, skipped (empty or `#`
     * comment), proxy token, invalid address (not `filter_var()`-valid), implied prefix (no
     * `/`), invalid prefix (not one to three digits within the address width), host bits set,
     * valid CIDR and Cloudflare member flags; then the prefix (-1 when invalid), host bit
     * count, the network as two words (see w60Halves(); the unmasked address for an invalid
     * prefix) and the index of the first W60_CLOUDFLARE_RANGES entry containing it (-1). The
     * W60_RANGE_TAIL words count rows, IPv4, IPv6, invalid, skipped, Cloudflare member,
     * host-bit and valid CIDR rows.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function ipRangeClassifyBatchPhp(array $rows): array
    {
        $table = [];
        foreach (self::W60_CLOUDFLARE_RANGES as $range) {
            [$address, $length] = explode('/', $range);
            $packed = (string) inet_pton($address);
            $prefix = (int) $length;
            $full = $prefix >> 3;
            $mask = $prefix & 7 ? (0xFF << (8 - ($prefix & 7))) & 0xFF : 0;
            $table[] = [\strlen($packed), $prefix, $full, $mask, substr($packed, 0, $full), $mask === 0 ? 0 : \ord($packed[$full]) & $mask];
        }
        $words = [];
        $tail = array_fill(0, self::W60_RANGE_TAIL, 0);
        foreach ($rows as $row) {
            $tail[0]++;
            $entry = trim($row);
            $flags = $entry !== $row ? self::W60_TRIMMED : 0;
            if ($entry === '' || $entry[0] === '#' || \in_array($entry, self::ALLOWED_PROXY_TOKENS, true)) {
                array_push($words, $flags | ($entry === '' || $entry[0] === '#' ? self::W60_SKIPPED : self::W60_PROXY_TOKEN), 0, 0, 0, 0, -1);
                $tail[4]++;

                continue;
            }
            $parts = explode('/', $entry, 2);
            $packed = filter_var($parts[0], FILTER_VALIDATE_IP) !== false ? inet_pton($parts[0]) : false;
            if ($packed === false) {
                array_push($words, $flags | self::W60_INVALID_ADDRESS, 0, 0, 0, 0, -1);
                $tail[3]++;

                continue;
            }
            $bytes = \strlen($packed);
            $width = $bytes * 8;
            $flags |= ($bytes === 16 ? 2 : 1) | self::w60AddressClass($packed) << self::W60_CLASS_SHIFT;
            $tail[$bytes === 16 ? 2 : 1]++;
            if (! isset($parts[1])) {
                $prefix = $width;
                $flags |= self::W60_PREFIX_IMPLIED;
            } else {
                $text = $parts[1];
                $prefix = $text !== '' && \strlen($text) <= 3 && ctype_digit($text) && (int) $text <= $width ? (int) $text : -1;
                $flags |= $prefix >= 0 ? self::W60_CIDR_VALID : 0;
            }
            if ($prefix < 0) {
                array_push($words, $flags | self::W60_PREFIX_INVALID, -1, 0, ...self::w60Halves($packed));
                $words[] = -1;
                $tail[3]++;

                continue;
            }
            $full = $prefix >> 3;
            $bits = $prefix & 7;
            $network = substr($packed, 0, $full);
            if ($bits !== 0) {
                $network .= \chr(\ord($packed[$full]) & (0xFF << (8 - $bits)) & 0xFF);
            }
            $network = str_pad($network, $bytes, "\0");
            $hostBits = $network !== $packed;
            $member = -1;
            foreach ($table as $index => [$length, $rangePrefix, $rangeFull, $mask, $head, $rangeTail]) {
                if ($length === $bytes && $prefix >= $rangePrefix && strncmp($packed, $head, $rangeFull) === 0 && ($mask === 0 || (\ord($packed[$rangeFull]) & $mask) === $rangeTail)) {
                    $member = $index;

                    break;
                }
            }
            $flags |= ($hostBits ? self::W60_HOST_BITS : 0) | ($member >= 0 ? self::W60_CF_MEMBER : 0);
            array_push($words, $flags, $prefix, $width - $prefix, ...self::w60Halves($network));
            $words[] = $member;
            $tail[5] += $member >= 0 ? 1 : 0;
            $tail[6] += $hostBits ? 1 : 0;
            $tail[7] += ($flags & self::W60_CIDR_VALID) !== 0 ? 1 : 0;
        }

        return [...$words, ...$tail];
    }

    /**
     * The packed address as two words: an IPv4 address and 0, or an IPv6 address's high and low
     * 64 bits (two's complement).
     *
     * @return array{int, int}
     */
    private static function w60Halves(string $packed): array
    {
        /** @var array<int, int> $halves */
        $halves = unpack(\strlen($packed) === 4 ? 'N' : 'J2', $packed);

        return [$halves[1], $halves[2] ?? 0];
    }

    /**
     * Private 1, loopback 2, link-local 3, CGNAT 4, multicast 5, reserved 6, documentation 7,
     * unspecified 8, IPv4-mapped 9, else public 0; the first match wins.
     */
    private static function w60AddressClass(string $packed): int
    {
        $a = \ord($packed[0]);
        $b = \ord($packed[1]);
        if (\strlen($packed) === 4) {
            $c = \ord($packed[2]);

            return match (true) {
                $a === 10 || ($a === 172 && ($b & 0xF0) === 16) || ($a === 192 && $b === 168) => 1,
                $a === 127 => 2,
                $a === 169 && $b === 254 => 3,
                $a === 100 && ($b & 0xC0) === 64 => 4,
                ($a & 0xF0) === 224 => 5,
                ($a & 0xF0) === 240 || $a === 0 => 6,
                ($a === 192 && $b === 0 && $c === 2) || ($a === 198 && $b === 51 && $c === 100) || ($a === 203 && $b === 0 && $c === 113) => 7,
                default => 0,
            };
        }

        return match (true) {
            $packed === str_repeat("\0", 15)."\1" => 2,
            $packed === str_repeat("\0", 16) => 8,
            ($a & 0xFE) === 0xFC => 1,
            $a === 0xFE && ($b & 0xC0) === 0x80 => 3,
            $a === 0xFF => 5,
            str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") => 9,
            str_starts_with($packed, "\x20\x01\x0d\xb8") => 7,
            default => 0,
        };
    }

    /**
     * Wave 60 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics so the
     * class gains no Wave 60 static properties.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('cloudflare_ip_range_w60', self::W60_CLOUDFLARE_IP_RANGE_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
