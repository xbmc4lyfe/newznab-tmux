<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class ProcessSafe extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_gmr_cmd_is_valid_type(uint8_t command, const char *text_ptr, size_t text_len);
        ssize_t nntmux_cat_gmr_cmd_help_lines(uint8_t command, char *out_buf, size_t out_cap);
        CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:safe
                            {type : Type: binaries or backfill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safe binaries or backfill update using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');

        if (! \is_string($type) || ! self::isValidSafeType($type)) {
            $this->showHelp();

            return self::FAILURE;
        }

        try {
            $service = new ForkingService;

            match ($type) {
                'binaries' => $service->safeBinaries(),
                'backfill' => $service->safeBackfill(),
            };

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function showHelp(): void
    {
        $help = self::buildHelpLines();
        $this->error($help['error']);
        foreach ($help['lines'] as $line) {
            $this->line($line);
        }
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @phpstan-assert-if-true 'binaries'|'backfill' $type
     */
    public static function isValidSafeType(string $type): bool
    {
        return self::$useRustFfi ? self::isValidSafeTypeNative($type) : self::isValidSafeTypePhp($type);
    }

    public static function isValidSafeTypePhp(string $type): bool
    {
        return \in_array($type, ['binaries', 'backfill'], true);
    }

    public static function isValidSafeTypeNative(string $type): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isValidSafeTypePhp($type);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_gmr_cmd_is_valid_type(1, $type, \strlen($type));

            return $rc === 0 || $rc === 1 ? $rc === 1 : self::isValidSafeTypePhp($type);
        } catch (Throwable) {
            return self::isValidSafeTypePhp($type);
        }
    }

    /**
     * The error and help lines shown for an invalid type.
     *
     * @return array{error: string, lines: list<string>}
     */
    public static function buildHelpLines(): array
    {
        return self::$useRustFfi ? self::buildHelpLinesNative() : self::buildHelpLinesPhp();
    }

    /**
     * @return array{error: string, lines: list<string>}
     */
    public static function buildHelpLinesPhp(): array
    {
        return [
            'error' => 'Type must be either: binaries or backfill',
            'lines' => ['', 'binaries => Do Safe Binaries update', 'backfill => Do Safe Backfill update'],
        ];
    }

    /**
     * @return array{error: string, lines: list<string>}
     */
    public static function buildHelpLinesNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildHelpLinesPhp();
        }

        try {
            $buffer = $ffi->new('char[1024]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cat_gmr_cmd_help_lines(1, $buffer, 1024);
            if (! \is_int($written) || $written <= 0 || $written > 1024) {
                return self::buildHelpLinesPhp();
            }
            $lines = explode("\n", FFI::string($buffer, $written));

            return ['error' => array_shift($lines), 'lines' => $lines];
        } catch (Throwable) {
            return self::buildHelpLinesPhp();
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('w31-3-process-safe', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
