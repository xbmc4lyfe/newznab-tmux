<?php

declare(strict_types=1);

namespace App\Services\NNTP;

/**
 * A requested provider could not be connected or authenticated, as opposed to a single article
 * being missing; callers can stop querying that provider for the rest of their work.
 */
final class NntpProviderUnavailableException extends \RuntimeException {}
