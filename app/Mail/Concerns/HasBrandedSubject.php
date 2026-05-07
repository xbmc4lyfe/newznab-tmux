<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

/**
 * Adds a configurable, site-wide subject prefix (default "[App Name] ") to
 * mailables, so transactional emails read consistently in users' inboxes.
 *
 * Concrete mailables call `$this->brandedSubject('Welcome')` instead of
 * `$this->subject('Welcome')`. The prefix can be overridden globally via
 * `MAIL_SUBJECT_PREFIX` (see `config/mail.php` -> `brand.subject_prefix`)
 * or disabled per call by passing `prefix: false`.
 */
trait HasBrandedSubject
{
    protected function brandedSubject(string $subject, bool $prefix = true): static
    {
        $prefixValue = $prefix
            ? (string) config('mail.brand.subject_prefix', '')
            : '';

        return $this->subject($prefixValue.$subject);
    }
}
