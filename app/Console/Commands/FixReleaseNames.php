<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NameFixing\NameFixingService;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class FixReleaseNames extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w371_fix_names_reset_verify(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustFixReleaseNamesResetVerifyCommandParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int RUST_MAX_INPUT = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private const array FIX_NAME_SOURCES = ['', 'nfo', 'files', 'par2', 'media', 'xxx_files', 'srr', 'par_hash', 'media_movie_name', 'crc'];

    private const array FIX_NAME_CATEGORIES = [1 => 'other', 2 => 'all', 3 => 'predb_id'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'releases:fix-names
                                 {method : The method number (3-20) to use for fixing names}
                                 {--update : Actually update the names, otherwise just display results}
                                 {--category=other : Category to process: "other", "all", or "predb_id"}
                                 {--set-status : Set releases as checked after processing}
                                 {--limit=1000 : Candidate batch limit per pass}
                                 {--show : Display detailed release changes rather than just counters}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix release names using various methods (NFO, file name, Par2, etc.)';

    /**
     * Execute the console command.
     */
    public function handle(NameFixingService $nameFixingService, NNTPService $nntp): int
    {
        $method = $this->argument('method');
        $update = (bool) $this->option('update');
        $setStatus = (bool) $this->option('set-status');
        $show = $this->option('show');

        $limitOption = $this->option('limit');
        if ($limitOption !== null && $limitOption !== '') {
            $limit = max(1, (int) $limitOption);
            config(['tmux.fix_names.batch_limit' => $limit]);
        }

        // Set category option
        $categoryOption = $this->option('category');
        $other = 1;
        if ($categoryOption === 'all') {
            $other = 2;
        } elseif ($categoryOption === 'predb_id') {
            $other = 3;
        }

        // Connect to NNTP if method requires it
        if ($method === '7' || $method === '8') {
            $compressedHeaders = config('nntmux_nntp.compressed_headers');
            $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
                ? $nntp->doConnect($compressedHeaders, true)
                : $nntp->doConnect();

            if ($connectResult !== true) {
                $errorMessage = 'Unable to connect to usenet.';
                if (NNTPService::isError($connectResult)) {
                    $errorMessage .= ' Error: '.$connectResult->getMessage();
                }
                $this->error($errorMessage);

                return 1;
            }
        }

        switch ($method) {
            case '3':
                $nameFixingService->fixNamesWithNfo(1, $update, $other, $setStatus, $show);
                break;
            case '4':
                $nameFixingService->fixNamesWithNfo(2, $update, $other, $setStatus, $show);
                break;
            case '5':
                $nameFixingService->fixNamesWithFiles(1, $update, $other, $setStatus, $show);
                break;
            case '6':
                $nameFixingService->fixNamesWithFiles(2, $update, $other, $setStatus, $show);
                break;
            case '7':
                $nameFixingService->fixNamesWithPar2(1, $update, $other, $setStatus, $show, $nntp);
                break;
            case '8':
                $nameFixingService->fixNamesWithPar2(2, $update, $other, $setStatus, $show, $nntp);
                break;
            case '9':
                $nameFixingService->fixNamesWithMedia(1, $update, $other, $setStatus, $show);
                break;
            case '10':
                $nameFixingService->fixNamesWithMedia(2, $update, $other, $setStatus, $show);
                break;
            case '11':
                $nameFixingService->fixXXXNamesWithFiles(1, $update, $other, $setStatus, $show);
                break;
            case '12':
                $nameFixingService->fixXXXNamesWithFiles(2, $update, $other, $setStatus, $show);
                break;
            case '13':
                $nameFixingService->fixNamesWithSrr(1, $update, $other, $setStatus, $show);
                break;
            case '14':
                $nameFixingService->fixNamesWithSrr(2, $update, $other, $setStatus, $show);
                break;
            case '15':
                $nameFixingService->fixNamesWithParHash(1, $update, $other, $setStatus, $show);
                break;
            case '16':
                $nameFixingService->fixNamesWithParHash(2, $update, $other, $setStatus, $show);
                break;
            case '17':
                $nameFixingService->fixNamesWithMediaMovieName(1, $update, $other, $setStatus, $show);
                break;
            case '18':
                $nameFixingService->fixNamesWithMediaMovieName(2, $update, $other, $setStatus, $show);
                break;
            case '19':
                $nameFixingService->fixNamesWithCrc(1, $update, $other, $setStatus, $show);
                break;
            case '20':
                $nameFixingService->fixNamesWithCrc(2, $update, $other, $setStatus, $show);
                break;
            default:
                $this->showHelp();

                return 1;
        }

        return 0;
    }

    /**
     * Display detailed help information.
     */
    protected function showHelp(): void
    {
        $this->info('');
        $this->info('Usage Examples:');
        $this->info('');
        $this->info('php artisan releases:fix-names 3 : Fix release names using NFO in the past 6 hours.');
        $this->info('php artisan releases:fix-names 4 : Fix release names using NFO.');
        $this->info('php artisan releases:fix-names 5 : Fix release names in misc categories using File Name in the past 6 hours.');
        $this->info('php artisan releases:fix-names 6 : Fix release names in misc categories using File Name.');
        $this->info('php artisan releases:fix-names 7 : Fix release names in misc categories using Par2 Files in the past 6 hours.');
        $this->info('php artisan releases:fix-names 8 : Fix release names in misc categories using Par2 Files.');
        $this->info('php artisan releases:fix-names 9 : Fix release names in misc categories using UID in the past 6 hours.');
        $this->info('php artisan releases:fix-names 10 : Fix release names in misc categories using UID.');
        $this->info('php artisan releases:fix-names 11 : Fix SDPORN XXX release names using specific File Name in the past 6 hours.');
        $this->info('php artisan releases:fix-names 12 : Fix SDPORN XXX release names using specific File Name.');
        $this->info('php artisan releases:fix-names 13 : Fix release names using SRR files in the past 6 hours.');
        $this->info('php artisan releases:fix-names 14 : Fix release names using SRR files.');
        $this->info('php artisan releases:fix-names 15 : Fix release names using PAR2 hash_16K block in the past 6 hours.');
        $this->info('php artisan releases:fix-names 16 : Fix release names using PAR2 hash_16K block.');
        $this->info('php artisan releases:fix-names 17 : Fix release names using Mediainfo in the past 6 hours.');
        $this->info('php artisan releases:fix-names 18 : Fix release names using Mediainfo.');
        $this->info('php artisan releases:fix-names 19 : Fix release names using CRC32 in the past 6 hours.');
        $this->info('php artisan releases:fix-names 20 : Fix release names using CRC32.');
        $this->info('');
        $this->info('Options:');
        $this->info('  --update           Actually update the names (default: only display potential changes)');
        $this->info('  --category=VALUE   Process "other" categories (default), "all" categories, or "predb_id" for unmatched');
        $this->info('  --set-status       Mark releases as checked so they won\'t be processed again');
        $this->info('  --limit=VALUE      Candidate batch limit per pass (default from TMUX_FIX_NAMES_BATCH_LIMIT or 1000)');
        $this->info('  --show             Display detailed release changes (default: only show counters)');
        $this->info('');
    }

    /**
     * Normalizes a `releases:fix-names` invocation into its plan. Only canonical method numbers
     * 3-20 (no padding, sign or leading zero) are valid; odd methods cover the past 6 hours.
     *
     * @return array{method: int, valid: bool, source: string, pass: int, requires_nntp: bool, other: int, category: string, limit: int|null, update: bool, set_status: bool, show: bool}
     */
    public static function normalizeFixNamesPlan(string $method, ?string $category, ?int $limit, bool $update = false, bool $setStatus = false, bool $show = false): array
    {
        return (self::usesRustFfi() ? self::normalizeFixNamesPlanNative($method, $category, $limit, $update, $setStatus, $show) : null)
            ?? self::normalizeFixNamesPlanPhp($method, $category, $limit, $update, $setStatus, $show);
    }

    /**
     * @return array{method: int, valid: bool, source: string, pass: int, requires_nntp: bool, other: int, category: string, limit: int|null, update: bool, set_status: bool, show: bool}
     */
    private static function normalizeFixNamesPlanPhp(string $method, ?string $category, ?int $limit, bool $update, bool $setStatus, bool $show): array
    {
        $number = strlen($method) <= 2 && ctype_digit($method) && (strlen($method) === 1 || $method[0] !== '0') ? (int) $method : -1;
        $valid = $number >= 3 && $number <= 20;
        $other = match ($category) {
            'all' => 2,
            'predb_id' => 3,
            default => 1,
        };

        return [
            'method' => $valid ? $number : 0,
            'valid' => $valid,
            'source' => self::FIX_NAME_SOURCES[$valid ? intdiv($number - 1, 2) : 0],
            'pass' => $valid ? ($number % 2 === 1 ? 1 : 2) : 0,
            'requires_nntp' => $number === 7 || $number === 8,
            'other' => $other,
            'category' => self::FIX_NAME_CATEGORIES[$other],
            'limit' => $limit === null ? null : max(1, $limit),
            'update' => $update,
            'set_status' => $setStatus,
            'show' => $show,
        ];
    }

    /**
     * @return array{method: int, valid: bool, source: string, pass: int, requires_nntp: bool, other: int, category: string, limit: int|null, update: bool, set_status: bool, show: bool}|null
     */
    private static function normalizeFixNamesPlanNative(string $method, ?string $category, ?int $limit, bool $update, bool $setStatus, bool $show): ?array
    {
        $flags = ($update ? 1 : 0) | ($setStatus ? 2 : 0) | ($show ? 4 : 0) | ($limit === null ? 0 : 8);
        $input = self::packRustString($method).($category === null ? pack('q', -1) : self::packRustString($category)).pack('q', $limit ?? 0);
        $fields = self::unpackRustInts(self::callRustFfi(1, $flags, $input), 11);
        if ($fields === null) {
            return null;
        }
        [$number, $valid, $source, $pass, $nntp, $other, $hasLimit, $normalizedLimit, $updateOut, $setStatusOut, $showOut] = $fields;
        $sourceName = self::FIX_NAME_SOURCES[$source] ?? null;
        $categoryName = self::FIX_NAME_CATEGORIES[$other] ?? null;
        if ($sourceName === null || $categoryName === null || ($valid | $nntp | $hasLimit | $updateOut | $setStatusOut | $showOut) & ~1) {
            return null;
        }

        return [
            'method' => $number,
            'valid' => $valid === 1,
            'source' => $sourceName,
            'pass' => $pass,
            'requires_nntp' => $nntp === 1,
            'other' => $other,
            'category' => $categoryName,
            'limit' => $hasLimit === 1 ? $normalizedLimit : null,
            'update' => $updateOut === 1,
            'set_status' => $setStatusOut === 1,
            'show' => $showOut === 1,
        ];
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
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

    private static function packRustString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function unpackRustInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 37.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function callRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w371', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
