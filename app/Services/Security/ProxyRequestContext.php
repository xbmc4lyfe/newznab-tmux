<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Http\Request;

/**
 * Immutable snapshot of the request facts the IndexerProxyDetector needs.
 *
 * Keeping the detector dependent on this DTO rather than the raw HTTP Request
 * makes each behavioural signal trivial to unit test in isolation.
 */
final readonly class ProxyRequestContext
{
    public function __construct(
        public string $clientIp,
        public string $userAgent,
        public ?string $apiToken,
        public ?string $referer,
        public bool $isDownload,
        public bool $isSearch,
    ) {}

    /**
     * Build a context from the incoming request.
     *
     * The middleware already knows whether the request is a download or a
     * search (it computes those to decide the existing UA fast path), so those
     * flags are passed in rather than recomputed here.
     */
    public static function fromRequest(Request $request, bool $isDownload, bool $isSearch): self
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

    /**
     * Resolve the user token used to correlate searches with downloads.
     *
     * Newznab (`apikey`), the RSS feeds (`api_token`) and the getnzb RSS token
     * (`r`) all identify the same underlying user, so any of them is a valid
     * correlation key.
     */
    private static function extractApiToken(Request $request): ?string
    {
        foreach (['api_token', 'apikey', 'apiToken', 'r'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
