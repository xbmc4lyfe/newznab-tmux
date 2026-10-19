<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

/** A partial upload does not meet publication policy yet; later articles could still let it publish. */
final class PartialPublicationDeferredException extends RuntimeException {}
