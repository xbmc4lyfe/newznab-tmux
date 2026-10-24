<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Monitoring\GrafanaEmbedService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

class ContentSecurityPolicy
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_csp_build_header(const char *nonce, size_t nonce_len, const char *vite_asset, size_t vite_asset_len, const char *vite_ws, size_t vite_ws_len, const char *grafana_origin, size_t grafana_origin_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_csp_parse_vite_hot_url(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Tell Vite to add the CSP nonce to generated script/style tags
        $nonce = function_exists('csp_nonce') ? csp_nonce() : base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);
        Horizon::cspNonce($nonce);

        $response = $next($request);

        // Check if Turnstile is enabled
        $turnstileEnabled = config('captcha.provider') === 'turnstile'
            && config('captcha.turnstile.enabled') === true;

        // If Turnstile is enabled, skip CSP to avoid sandbox iframe issues
        // Turnstile creates sandboxed iframes that conflict with strict CSP
        if ($turnstileEnabled) {
            // Set minimal security headers only
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

            return $response;
        }

        // Reuse the nonce generated above (shared with Blade via csp_nonce())

        $viteDevServerSources = $this->viteDevServerSources();

        // Grafana embeds (admin Monitoring page) are same-origin under /grafana by default;
        // only an absolute GRAFANA_URL on another origin needs to be allowed explicitly.
        $grafanaOrigin = GrafanaEmbedService::frameOrigin(config('monitoring.grafana.url'), config('app.url'));

        $csp = $this->buildCspHeader($nonce, $viteDevServerSources, $grafanaOrigin);

        $response->headers->set('Content-Security-Policy', $csp);

        // Also set X-Frame-Options for older browsers
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Set X-Content-Type-Options
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Set Referrer-Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * @param  array{asset: string, websocket: string}|null  $viteDevServerSources
     */
    public function buildCspHeader(
        string $nonce,
        ?array $viteDevServerSources = null,
        ?string $grafanaOrigin = null,
    ): string {
        return self::$useRustFfi
            ? $this->buildCspHeaderNative($nonce, $viteDevServerSources, $grafanaOrigin)
            : $this->buildCspHeaderPhp($nonce, $viteDevServerSources, $grafanaOrigin);
    }

    /**
     * @param  array{asset: string, websocket: string}|null  $viteDevServerSources
     */
    public function buildCspHeaderPhp(
        string $nonce,
        ?array $viteDevServerSources = null,
        ?string $grafanaOrigin = null,
    ): string {
        $viteAssetSource = $viteDevServerSources === null ? '' : ' '.$viteDevServerSources['asset'];
        $viteConnectSources = $viteDevServerSources === null
            ? ''
            : ' '.$viteDevServerSources['asset'].' '.$viteDevServerSources['websocket'];
        $grafanaFrameSource = ($grafanaOrigin === null || $grafanaOrigin === '') ? '' : ' '.$grafanaOrigin;

        // Build CSP directives for non-Turnstile pages
        // 'strict-dynamic' propagates trust from nonce-validated scripts to
        // dynamically loaded scripts (e.g. TinyMCE loaded via createElement).
        // Host-based allowlists are kept as fallback for older browsers.
        $directives = [
            "default-src 'self'",
            // 'unsafe-eval' is required because @alpinejs/csp bundles dead-code from the
            // standard Alpine evaluator (new Function) that cannot be tree-shaken, and
            // TinyMCE (loaded from CDN on admin content pages) also relies on eval.
            // 'unsafe-inline' has been removed in favor of nonce-based validation.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' 'strict-dynamic' https://challenges.cloudflare.com https://cdn.tiny.cloud https://cdn.jsdelivr.net/ https://static.cloudflareinsights.com/ https://cdnjs.cloudflare.com/ https://unpkg.com/ https://cdn.tailwindcss.com/ https://code.jquery.com https://apis.google.com https://www.google.com https://www.gstatic.com https://ajax.cloudflare.com blob:{$viteAssetSource}",
            "script-src-elem 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com https://cdn.tiny.cloud https://cdn.jsdelivr.net/ https://static.cloudflareinsights.com/ https://cdnjs.cloudflare.com/ https://unpkg.com/ https://cdn.tailwindcss.com/ https://code.jquery.com https://apis.google.com https://www.google.com https://www.gstatic.com https://ajax.cloudflare.com{$viteAssetSource}",
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net/ https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud{$viteAssetSource}",
            "style-src-elem 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net/ https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud{$viteAssetSource}",
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud data:{$viteAssetSource}",
            "img-src 'self' data: https: blob:{$viteAssetSource}",
            "connect-src 'self' https://www.google.com https://cdn.tiny.cloud https://sp.tinymce.com{$viteConnectSources}",
            "frame-src 'self' https://www.google.com https://www.gstatic.com https://challenges.cloudflare.com https://cdn.tiny.cloud data: blob:{$grafanaFrameSource}",
            "child-src 'self' https://www.google.com https://challenges.cloudflare.com https://cdn.tiny.cloud blob:{$grafanaFrameSource}",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        return implode('; ', $directives);
    }

    /**
     * @param  array{asset: string, websocket: string}|null  $viteDevServerSources
     */
    public function buildCspHeaderNative(
        string $nonce,
        ?array $viteDevServerSources = null,
        ?string $grafanaOrigin = null,
    ): string {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->buildCspHeaderPhp($nonce, $viteDevServerSources, $grafanaOrigin);
        }

        $asset = $viteDevServerSources === null ? '' : $viteDevServerSources['asset'];
        $ws = $viteDevServerSources === null ? '' : $viteDevServerSources['websocket'];
        $grafana = (string) $grafanaOrigin;

        $nonceLen = \strlen($nonce);
        $assetLen = \strlen($asset);
        $wsLen = \strlen($ws);
        $grafanaLen = \strlen($grafana);
        $needed = 1600 + $nonceLen * 2 + $assetLen * 7 + $wsLen + $grafanaLen * 2;

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(2048, $needed), $cap);
        if ($scratch === null) {
            return $this->buildCspHeaderPhp($nonce, $viteDevServerSources, $grafanaOrigin);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_csp_build_header(
            $nonce,
            $nonceLen,
            $asset,
            $assetLen,
            $ws,
            $wsLen,
            $grafana,
            $grafanaLen,
            $scratch,
            $cap,
        );
        if (! \is_int($written) || $written === -1) {
            return $this->buildCspHeaderPhp($nonce, $viteDevServerSources, $grafanaOrigin);
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->buildCspHeaderPhp($nonce, $viteDevServerSources, $grafanaOrigin);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_csp_build_header(
                $nonce,
                $nonceLen,
                $asset,
                $assetLen,
                $ws,
                $wsLen,
                $grafana,
                $grafanaLen,
                $scratch,
                $cap,
            );
            if (! \is_int($written) || $written <= 0) {
                return $this->buildCspHeaderPhp($nonce, $viteDevServerSources, $grafanaOrigin);
            }
        }

        return $written <= 0
            ? $this->buildCspHeaderPhp($nonce, $viteDevServerSources, $grafanaOrigin)
            : FFI::string($scratch, $written);
    }

    /**
     * @return array{asset: string, websocket: string}|null
     */
    public function parseViteHotUrl(string $rawHotUrl): ?array
    {
        return self::$useRustFfi
            ? $this->parseViteHotUrlNative($rawHotUrl)
            : $this->parseViteHotUrlPhp($rawHotUrl);
    }

    /**
     * @return array{asset: string, websocket: string}|null
     */
    public function parseViteHotUrlPhp(string $rawHotUrl): ?array
    {
        $hotUrl = trim($rawHotUrl);
        $parts = parse_url($hotUrl);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return null;
        }

        $urlHost = (str_contains($host, ':') && ! str_starts_with($host, '[')) ? "[{$host}]" : $host;
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $assetOrigin = "{$scheme}://{$urlHost}{$port}";
        $websocketScheme = $scheme === 'https' ? 'wss' : 'ws';

        return [
            'asset' => $assetOrigin,
            'websocket' => "{$websocketScheme}://{$urlHost}{$port}",
        ];
    }

    /**
     * @return array{asset: string, websocket: string}|null
     */
    public function parseViteHotUrlNative(string $rawHotUrl): ?array
    {
        if ($rawHotUrl === '') {
            return null;
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->parseViteHotUrlPhp($rawHotUrl);
        }

        $len = \strlen($rawHotUrl);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 2 + 32), $cap);
        if ($scratch === null) {
            return $this->parseViteHotUrlPhp($rawHotUrl);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_csp_parse_vite_hot_url($rawHotUrl, $len, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return $this->parseViteHotUrlPhp($rawHotUrl);
        }
        if ($written === 0) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->parseViteHotUrlPhp($rawHotUrl);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_csp_parse_vite_hot_url($rawHotUrl, $len, $scratch, $cap);
            if (! \is_int($written) || $written < 0) {
                return $this->parseViteHotUrlPhp($rawHotUrl);
            }
            if ($written === 0) {
                return null;
            }
        }

        $pair = explode("\t", FFI::string($scratch, $written), 2);
        if (\count($pair) !== 2) {
            return $this->parseViteHotUrlPhp($rawHotUrl);
        }

        return [
            'asset' => $pair[0],
            'websocket' => $pair[1],
        ];
    }

    /**
     * @return array{asset: string, websocket: string}|null
     */
    private function viteDevServerSources(): ?array
    {
        if (config('app.env') !== 'local' || ! Vite::isRunningHot()) {
            return null;
        }

        return $this->parseViteHotUrl((string) file_get_contents(Vite::hotFile()));
    }
}
