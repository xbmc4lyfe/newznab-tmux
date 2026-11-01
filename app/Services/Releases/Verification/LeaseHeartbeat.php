<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Closure;

/** Coalesce progress callbacks without weakening forced ownership checks at publication. */
final class LeaseHeartbeat
{
    private ?float $lastRenewal = null;

    private readonly Closure $renew;

    private readonly Closure $clock;

    public function __construct(callable $renew, private readonly float $intervalSeconds, ?Closure $clock = null)
    {
        $this->renew = Closure::fromCallable($renew);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function __invoke(): void
    {
        $now = ($this->clock)();
        if ($this->lastRenewal !== null && $now - $this->lastRenewal < $this->intervalSeconds) {
            return;
        }
        ($this->renew)();
        $this->lastRenewal = $now;
    }
}
