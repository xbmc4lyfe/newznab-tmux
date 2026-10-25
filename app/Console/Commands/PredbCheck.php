<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Predb;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class PredbCheck extends Command
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbCheckRebuildSuggestionsImportParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w373_predb_check_limit_plan(
            uint32_t flags,
            const char *limit_ptr,
            size_t limit_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Largest bounded check the limit plan reports; larger requests are clamped. */
    public const int MAX_CHECK_LIMIT = 1_000_000;

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    private const array MODES = ['all', 'bounded', 'invalid'];

    private const array REASONS = ['absent', 'zero', 'ok', 'clamped', 'empty', 'negative', 'not_integer'];

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $rustFfiOverride = null;

    private static FFI|false|null $rustFfiHandle = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'predb:check {limit? : Maximum number of releases to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check releases against PreDB for matches';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = $this->argument('limit');

        if ($limit !== null && ! is_numeric($limit)) {
            $this->error('Limit must be a number');

            return Command::FAILURE;
        }

        $this->info('Checking releases against PreDB...');

        try {
            Predb::checkPre($limit ? (int) $limit : false);

            $this->info('✅ PreDB check complete');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('PreDB check failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$rustFfiOverride = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$rustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$rustFfiOverride = null;
        self::$rustFfiHandle = null;
    }

    /**
     * Classifies a `limit` argument with a strict decimal grammar: surrounding whitespace and one
     * leading `+` are tolerated (but not canonical), zero means "check everything", and values
     * above MAX_CHECK_LIMIT are clamped. Signs, exponents and fractions are invalid.
     *
     * @return array{provided: bool, canonical: bool, valid: bool, limit: int, clamped: bool, mode: string, reason: string}
     */
    public static function planCheckLimit(?string $limit): array
    {
        return (self::usesRustFfi() ? self::planCheckLimitNative($limit) : null) ?? self::planCheckLimitPhp($limit);
    }

    /**
     * @return array{provided: bool, canonical: bool, valid: bool, limit: int, clamped: bool, mode: string, reason: string}|null
     */
    private static function planCheckLimitNative(?string $limit): ?array
    {
        $text = $limit ?? '';
        $fields = self::callNative('nntmux_w373_predb_check_limit_plan', [$limit === null ? 0 : 1, $text, strlen($text)], 64);
        if ($fields === null || count($fields) !== 7 || ! ctype_digit($fields[3])
            || ! in_array($fields[5], self::MODES, true) || ! in_array($fields[6], self::REASONS, true)) {
            return null;
        }

        return [
            'provided' => $fields[0] === '1',
            'canonical' => $fields[1] === '1',
            'valid' => $fields[2] === '1',
            'limit' => (int) $fields[3],
            'clamped' => $fields[4] === '1',
            'mode' => $fields[5],
            'reason' => $fields[6],
        ];
    }

    /**
     * @return array{provided: bool, canonical: bool, valid: bool, limit: int, clamped: bool, mode: string, reason: string}
     */
    private static function planCheckLimitPhp(?string $limit): array
    {
        if ($limit === null) {
            return ['provided' => false, 'canonical' => false, 'valid' => true, 'limit' => 0, 'clamped' => false, 'mode' => 'all', 'reason' => 'absent'];
        }
        $trimmed = trim($limit, self::TRIM_CHARS);
        $plus = str_starts_with($trimmed, '+');
        $body = $plus ? substr($trimmed, 1) : $trimmed;
        if (! ctype_digit($body)) {
            $reason = match (true) {
                $trimmed === '' => 'empty',
                $trimmed[0] === '-' && ctype_digit(substr($trimmed, 1)) => 'negative',
                default => 'not_integer',
            };

            return ['provided' => true, 'canonical' => false, 'valid' => false, 'limit' => 0, 'clamped' => false, 'mode' => 'invalid', 'reason' => $reason];
        }
        $significant = ltrim($body, '0');
        $clamped = strlen($significant) > 7 || (int) $significant > self::MAX_CHECK_LIMIT;
        $value = $clamped ? self::MAX_CHECK_LIMIT : (int) $significant;

        return [
            'provided' => true,
            'canonical' => $trimmed === $limit && ! $plus && (strlen($body) === 1 || $body[0] !== '0'),
            'valid' => true,
            'limit' => $value,
            'clamped' => $clamped,
            'mode' => $value === 0 ? 'all' : 'bounded',
            'reason' => match (true) {
                $value === 0 => 'zero',
                $clamped => 'clamped',
                default => 'ok',
            },
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private static function rustFfi(): ?FFI
    {
        if (self::$rustFfiHandle === null) {
            self::$rustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w373', self::FFI_CDEF) ?? false;
        }

        return self::$rustFfiHandle === false ? null : self::$rustFfiHandle;
    }
}
