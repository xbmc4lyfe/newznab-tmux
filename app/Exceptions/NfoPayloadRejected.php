<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A captured, unchanged companion is permanently invalid, rather than retryable storage failure. */
final class NfoPayloadRejected extends RuntimeException {}
