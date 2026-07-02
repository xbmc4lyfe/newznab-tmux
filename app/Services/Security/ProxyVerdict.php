<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * Result of a behavioural proxy-fetch analysis.
 *
 * @property array<string, int> $reasons Per-signal score contributions.
 */
final readonly class ProxyVerdict
{
    /**
     * @param  array<string, int>  $reasons
     */
    public function __construct(
        public bool $shouldBlock,
        public int $score,
        public array $reasons,
    ) {}

    /**
     * A verdict that never blocks (used when detection is disabled).
     */
    public static function allow(): self
    {
        return new self(false, 0, []);
    }
}
