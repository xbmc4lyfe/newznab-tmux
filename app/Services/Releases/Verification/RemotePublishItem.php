<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/** One verified candidate a worker wants the primary to publish. */
final readonly class RemotePublishItem
{
    public function __construct(public object $claim, public VerificationResult $result) {}
}
