<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;
use Throwable;

class ValidEmailDomain implements ValidationRule
{
    /** The domain passed the hardcoded blacklist and pattern checks. */
    public const int BLOCK_NONE = 0;

    /** The domain is on the hardcoded blacklist; `BLOCK_PATTERN + i` matched `SUSPICIOUS_PATTERNS[i]`. */
    public const int BLOCK_BLACKLIST = 1;

    public const int BLOCK_PATTERN = 2;

    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_email_normalized_domain(const char *email, size_t email_len, char *out, size_t cap);
        int32_t nntmux_email_domain_block_code(const char *domain, size_t domain_len);
        int32_t nntmux_email_has_plus_addressing(const char *email, size_t email_len);
        C;

    /**
     * The Rust helpers stay opt-in: they did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustEmailDomainCaptchaTurnstileTrustedDeviceParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Common temporary/disposable email patterns
     */
    public const array SUSPICIOUS_PATTERNS = [
        'temp',
        'temporary',
        'disposable',
        'throwaway',
        'fake',
        'trash',
        'spam',
        'guerrilla',
        '10minute',
        'minutemail',
        'tempmail',
        'guerrillamail',
        'mailinator',
        'yopmail',
        'maildrop',
        'trashmail',
        'getnada',
        'throwawaymail',
        'sharklasers',
        'guerrillamail',
        'grr.la',
    ];

    /**
     * Additional hardcoded disposable domains list
     */
    public const array BLOCKED_DOMAINS = [
        '10minutemail.com',
        'guerrillamail.com',
        'mailinator.com',
        'maildrop.cc',
        'temp-mail.org',
        'tempmail.com',
        'throwawaymail.com',
        'yopmail.com',
        'getnada.com',
        'sharklasers.com',
        'guerrillamail.info',
        'guerrillamail.biz',
        'guerrillamail.de',
        'grr.la',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamail.com',
        'spam4.me',
        'trashmail.com',
        'dispostable.com',
        'fakeinbox.com',
        'tmpeml.info',
        'tempinbox.com',
        'mytemp.email',
        'mohmal.com',
        'emailondeck.com',
        'throwam.com',
        'mintemail.com',
        'spamgourmet.com',
        'mailnesia.com',
        'trashmail.net',
        'trash-mail.com',
        'emailtemporanea.com',
        'anonbox.net',
        'anonymbox.com',
        'discard.email',
        'spambox.us',
        'trbvm.com',
        'emailna.co',
        'my10minutemail.com',
    ];

    /**
     * Validate the email domain
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = self::normalizedDomain($value);
        if ($domain === null) {
            $fail('The :attribute must be a valid email address.');

            return;
        }

        // Check 1: Use the disposable email package
        if (! DisposableDomains::isNotDisposable($value)) {
            Log::warning('Disposable email attempt blocked (package detection)', [
                'email' => $value,
                'domain' => $domain,
            ]);
            $fail('Temporary or disposable email addresses are not allowed.');

            return;
        }

        // Check 2: Check against our hardcoded blacklist
        // Check 3: Check for suspicious patterns in domain name
        $blockCode = self::domainBlockCode($domain);
        if ($blockCode === self::BLOCK_BLACKLIST) {
            Log::warning('Disposable email attempt blocked (hardcoded blacklist)', [
                'email' => $value,
                'domain' => $domain,
            ]);
            $fail('Temporary or disposable email addresses are not allowed.');

            return;
        }

        if ($blockCode >= self::BLOCK_PATTERN) {
            Log::warning('Disposable email attempt blocked (pattern match)', [
                'email' => $value,
                'domain' => $domain,
                'pattern' => self::SUSPICIOUS_PATTERNS[$blockCode - self::BLOCK_PATTERN],
            ]);
            $fail('Temporary or disposable email addresses are not allowed.');

            return;
        }

        // Check 4: Validate domain has valid DNS records (MX or A record)
        if (! $this->validateDnsRecords($domain)) {
            Log::warning('Email domain has no valid DNS records', [
                'email' => $value,
                'domain' => $domain,
            ]);
            $fail('The email domain does not appear to be valid or reachable.');

            return;
        }

        // Check 5: Block common free email services with plus addressing abuse
        // (optional - you may want to comment this out if you want to allow Gmail, etc.)
        // if ($this->hasSuspiciousPlusAddressing($value)) {
        //     Log::warning('Suspicious plus addressing detected', [
        //         'email' => $value,
        //         'domain' => $domain,
        //     ]);
        //     $fail('This email format is not allowed.');
        //     return;
        // }
    }

    /**
     * Validate that the domain has proper DNS records
     */
    protected function validateDnsRecords(string $domain): bool
    {
        // Check for MX records (primary email validation)
        if (@checkdnsrr($domain, 'MX')) {
            return true;
        }

        // Fall back to A record check (some domains use A records for email)
        if (@checkdnsrr($domain, 'A')) {
            return true;
        }

        return false;
    }

    /**
     * Check for suspicious plus addressing patterns
     * Some users abuse plus addressing to create multiple accounts
     *
     * @phpstan-ignore method.unused
     */
    private function hasSuspiciousPlusAddressing(string $email): bool
    {
        // Check if contains + with suspicious patterns
        if (self::hasPlusAddressing($email)) {
            // You can add more sophisticated checks here
            // For now, just log it but don't block
            return false;
        }

        return false;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * The trimmed, lowercased domain of a `local@domain` address, or null when the value
     * is not a usable email address.
     */
    public static function normalizedDomain(mixed $value): ?string
    {
        return self::usesRustFfi()
            ? self::normalizedDomainNative($value)
            : self::normalizedDomainPhp($value);
    }

    public static function normalizedDomainPhp(mixed $value): ?string
    {
        if (empty($value) || ! is_string($value)) {
            return null;
        }

        // Extract domain from email
        $parts = explode('@', $value);
        if (count($parts) !== 2) {
            return null;
        }

        $localPart = trim($parts[0]);
        $domain = strtolower(trim($parts[1]));

        // Validate local part and domain are not empty
        if (empty($localPart) || empty($domain)) {
            return null;
        }

        return $domain;
    }

    public static function normalizedDomainNative(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, max(1, \strlen($value)), $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::normalizedDomainPhp($value);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_email_normalized_domain($value, \strlen($value), $scratch, $cap);
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return self::normalizedDomainPhp($value);
            }

            return $written === 0 ? null : FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::normalizedDomainPhp($value);
        }
    }

    /**
     * `BLOCK_NONE`, `BLOCK_BLACKLIST`, or `BLOCK_PATTERN + i` for the first matching
     * `SUSPICIOUS_PATTERNS[i]`.
     */
    public static function domainBlockCode(string $domain): int
    {
        return self::usesRustFfi()
            ? self::domainBlockCodeNative($domain)
            : self::domainBlockCodePhp($domain);
    }

    public static function domainBlockCodePhp(string $domain): int
    {
        if (in_array($domain, self::BLOCKED_DOMAINS, true)) {
            return self::BLOCK_BLACKLIST;
        }

        foreach (self::SUSPICIOUS_PATTERNS as $index => $pattern) {
            if (str_contains($domain, $pattern)) {
                return self::BLOCK_PATTERN + $index;
            }
        }

        return self::BLOCK_NONE;
    }

    public static function domainBlockCodeNative(string $domain): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::domainBlockCodePhp($domain);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_email_domain_block_code($domain, \strlen($domain));
        } catch (Throwable) {
            return self::domainBlockCodePhp($domain);
        }

        return \is_int($result) && $result >= self::BLOCK_NONE && $result < self::BLOCK_PATTERN + \count(self::SUSPICIOUS_PATTERNS)
            ? $result
            : self::domainBlockCodePhp($domain);
    }

    /**
     * Whether the part before the first `@` uses plus addressing.
     */
    public static function hasPlusAddressing(string $email): bool
    {
        return self::usesRustFfi()
            ? self::hasPlusAddressingNative($email)
            : self::hasPlusAddressingPhp($email);
    }

    public static function hasPlusAddressingPhp(string $email): bool
    {
        $parts = explode('@', $email);

        return str_contains($parts[0], '+');
    }

    public static function hasPlusAddressingNative(string $email): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::hasPlusAddressingPhp($email);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_email_has_plus_addressing($email, \strlen($email));
        } catch (Throwable) {
            return self::hasPlusAddressingPhp($email);
        }

        return $result === 0 || $result === 1
            ? $result === 1
            : self::hasPlusAddressingPhp($email);
    }
}
