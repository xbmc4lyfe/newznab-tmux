<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

/** The candidate's lease token no longer owns it; the holder's result can never be published. */
final class LeaseLostException extends RuntimeException {}
