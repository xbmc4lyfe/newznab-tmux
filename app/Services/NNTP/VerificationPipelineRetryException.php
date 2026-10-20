<?php

declare(strict_types=1);

namespace App\Services\NNTP;

/** No correlated article outcome was received; a single primary request may recover it. */
final class VerificationPipelineRetryException extends \RuntimeException {}
