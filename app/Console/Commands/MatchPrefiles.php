<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NameFixing\NameFixingService;
use App\Services\Nzb\NzbArticleFingerprintUnavailable;
use App\Support\RustPipelineLibrary;
use Exception;
use FFI;
use Illuminate\Console\Command;

class MatchPrefiles extends Command
{
    public const string FFI_CDEF = NzbArticleFingerprintUnavailable::FFI_CDEF;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationCapacityPrefileSingletonParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string INVALID_LIMIT_MESSAGE = 'Limit must be "full" or a numeric value.';

    private const int HAS_LIMIT = 1;

    private const int SHOW = 2;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'match:prefiles
                                 {limit=full : Number of releases to process or "full" for all}
                                 {--show : Display the changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tries to match release filenames to PreDB filenames';

    /**
     * Execute the console command.
     */
    public function handle(NameFixingService $nameFixingService): int
    {
        $limit = $this->argument('limit');

        // Validate the limit argument
        if ($limit !== 'full' && ! is_numeric($limit)) {
            $this->error('Limit must be "full" or a numeric value.');

            return 1;
        }

        // Build the arguments array for NameFixingService
        $argv = [
            'match_prefiles.php',
            $limit,
        ];

        if ($this->option('show')) {
            $argv[] = 'show';
        }

        try {
            $nameFixingService->getPreFileNames($argv);

            return 0;
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }

    public static function useRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    /**
     * Mirrors handle()'s argument validation without running the command: the limit is valid when it is exactly
     * "full" or is_numeric(); valid arguments yield the NameFixingService argv (with "show" when requested) and exit
     * code 0, invalid ones the validation error and exit code 1.
     *
     * @return array{limit: ?string, valid: bool, full: bool, numeric: bool, show: bool, argv: list<string>, exit_code: int, error: ?string}
     */
    public static function normalizeArguments(?string $limit, bool $show): array
    {
        return (self::usesRustFfi() ? self::normalizeArgumentsNative($limit, $show) : null) ?? self::normalizeArgumentsPhp($limit, $show);
    }

    /**
     * @return array{limit: ?string, valid: bool, full: bool, numeric: bool, show: bool, argv: list<string>, exit_code: int, error: ?string}|null
     */
    private static function normalizeArgumentsNative(?string $limit, bool $show): ?array
    {
        $flags = ($limit === null ? 0 : self::HAS_LIMIT) | ($show ? self::SHOW : 0);
        $s = NzbArticleFingerprintUnavailable::evaluateW372(self::ffi(), 3, $flags, $limit ?? '', 5);
        if ($s === null || ! \in_array($s[0], [0, 1], true) || ! \in_array($s[1], [0, 1], true) || ! \in_array($s[2], [0, 1], true)
            || $s[3] !== (int) $show || $s[4] !== 1 - $s[0] || $s[0] !== ($s[1] | $s[2])) {
            return null;
        }

        return self::argumentsResult($limit, $s[0] === 1, $s[1] === 1, $s[2] === 1, $show);
    }

    /**
     * @return array{limit: ?string, valid: bool, full: bool, numeric: bool, show: bool, argv: list<string>, exit_code: int, error: ?string}
     */
    private static function normalizeArgumentsPhp(?string $limit, bool $show): array
    {
        $full = $limit === 'full';
        $numeric = is_numeric($limit);

        return self::argumentsResult($limit, $full || $numeric, $full, $numeric, $show);
    }

    /**
     * @return array{limit: ?string, valid: bool, full: bool, numeric: bool, show: bool, argv: list<string>, exit_code: int, error: ?string}
     */
    private static function argumentsResult(?string $limit, bool $valid, bool $full, bool $numeric, bool $show): array
    {
        $argv = $valid ? ['match_prefiles.php', (string) $limit] : [];
        if ($valid && $show) {
            $argv[] = 'show';
        }

        return [
            'limit' => $limit,
            'valid' => $valid,
            'full' => $full,
            'numeric' => $numeric,
            'show' => $show,
            'argv' => $argv,
            'exit_code' => $valid ? 0 : 1,
            'error' => $valid ? null : self::INVALID_LIMIT_MESSAGE,
        ];
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain(NzbArticleFingerprintUnavailable::FFI_DOMAIN, self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
