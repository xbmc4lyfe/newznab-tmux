<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

/** The article is larger than the bounded spot-check fetch allows; refetching it cannot change that. */
final class ArticleBudgetExceeded extends RuntimeException {}
