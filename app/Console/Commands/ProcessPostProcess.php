<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class ProcessPostProcess extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_gmr_cmd_is_valid_type(uint8_t command, const char *text_ptr, size_t text_len);
        ssize_t nntmux_cat_gmr_cmd_help_lines(uint8_t command, char *out_buf, size_t out_cap);
        int32_t nntmux_cat_gmr_cmd_renamed_only(uint8_t kind, int64_t int_value, const char *text_ptr, size_t text_len);
        CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:postprocess
                            {type : Type: ama, add, ani, mov, nfo, tv, boo, mus, con, gam}
                            {renamed=false : For mov/tv: only post-process renamed releases (true/false)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post-process releases using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $renamed = $this->argument('renamed');

        if (! \is_string($type) || ! self::isValidType($type)) {
            $this->showHelp();

            return self::FAILURE;
        }

        try {
            $renamedOnly = self::normalizeRenamedOnly($renamed);
            $service = new ForkingService;

            match ($type) {
                'ama' => $service->processAmazon(),
                'boo' => $service->processBooks(),
                'add' => $service->processAdditional(),
                'ani' => $service->processAnime(),
                'mov' => $service->processMovies($renamedOnly),
                'nfo' => $service->processNfo(),
                'tv' => $service->processTv($renamedOnly),
                'mus' => $service->processMusic(),
                'con' => $service->processConsoles(),
                'gam' => $service->processGames(),
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
     * @phpstan-assert-if-true 'ama'|'add'|'ani'|'mov'|'nfo'|'tv'|'boo'|'mus'|'con'|'gam' $type
     */
    public static function isValidType(string $type): bool
    {
        return self::$useRustFfi ? self::isValidTypeNative($type) : self::isValidTypePhp($type);
    }

    public static function isValidTypePhp(string $type): bool
    {
        return \in_array($type, ['ama', 'add', 'ani', 'mov', 'nfo', 'tv', 'boo', 'mus', 'con', 'gam'], true);
    }

    public static function isValidTypeNative(string $type): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isValidTypePhp($type);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_gmr_cmd_is_valid_type(0, $type, \strlen($type));

            return $rc === 0 || $rc === 1 ? $rc === 1 : self::isValidTypePhp($type);
        } catch (Throwable) {
            return self::isValidTypePhp($type);
        }
    }

    /**
     * `$renamed === 'true' || $renamed === true`: the mov/tv renamed-only flag.
     */
    public static function normalizeRenamedOnly(mixed $renamed): bool
    {
        return self::$useRustFfi ? self::normalizeRenamedOnlyNative($renamed) : self::normalizeRenamedOnlyPhp($renamed);
    }

    public static function normalizeRenamedOnlyPhp(mixed $renamed): bool
    {
        return $renamed === 'true' || $renamed === true;
    }

    public static function normalizeRenamedOnlyNative(mixed $renamed): bool
    {
        $ffi = self::ffi();
        $arguments = match (true) {
            $renamed === null => [0, 0, '', 0],
            \is_bool($renamed) => [1, $renamed ? 1 : 0, '', 0],
            \is_int($renamed) => [2, 0, '', 0],
            \is_float($renamed) => [3, 0, '', 0],
            \is_string($renamed) => [4, 0, $renamed, \strlen($renamed)],
            default => null,
        };
        if ($ffi === null || $arguments === null) {
            return self::normalizeRenamedOnlyPhp($renamed);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_gmr_cmd_renamed_only(...$arguments);

            return $rc === 0 || $rc === 1 ? $rc === 1 : self::normalizeRenamedOnlyPhp($renamed);
        } catch (Throwable) {
            return self::normalizeRenamedOnlyPhp($renamed);
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
            'error' => 'Type must be one of: ama, add, ani, mov, nfo, tv, boo, mus, con, gam',
            'lines' => [
                '',
                'ama => Do amazon (books+music+console+games) processing in parallel',
                'boo => Do books processing',
                'mus => Do music processing',
                'con => Do console processing',
                'gam => Do games processing',
                'add => Do additional (rar|zip) processing',
                'ani => Do anime processing',
                'mov => Do movie processing',
                'nfo => Do NFO processing',
                'tv  => Do TV processing',
            ],
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
            $written = $ffi->nntmux_cat_gmr_cmd_help_lines(0, $buffer, 1024);
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
            $ffi = RustPipelineLibrary::resolveDomain('w31-3-process-postprocess', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
