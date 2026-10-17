<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unauthenticated remote-worker publication endpoint is off unless configured, and then
 * answers only private-network clients. Serve it on a port reachable only from the LAN: Docker
 * port forwarding can present every client as the bridge gateway.
 */
class AllowRemoteVerificationPublication
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('release_verification.publish_endpoint')) {
            abort(404);
        }
        $networks = array_values(array_filter(array_map('trim', explode(',', (string) config('release_verification.publish_allowed_networks')))));
        $ip = $request->ip();
        if ($ip === null || $networks === [] || ! IpUtils::checkIp($ip, $networks)) {
            abort(403);
        }

        return $next($request);
    }
}
