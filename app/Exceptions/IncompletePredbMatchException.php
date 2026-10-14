<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** One title could not be exhaustively confirmed; retain it for a later matching attempt. */
final class IncompletePredbMatchException extends RuntimeException {}
