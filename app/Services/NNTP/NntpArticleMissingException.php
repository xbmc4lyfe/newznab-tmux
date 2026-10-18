<?php

declare(strict_types=1);

namespace App\Services\NNTP;

/**
 * The provider answered that the article does not exist (430 or 423), as opposed to a protocol,
 * authentication or transport failure. It extends RuntimeException so existing callers keep working.
 */
final class NntpArticleMissingException extends \RuntimeException {}
