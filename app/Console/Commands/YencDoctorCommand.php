<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yenc\NativePayloadDecoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

class YencDoctorCommand extends Command
{
    protected $signature = 'nntmux:yenc-doctor
                                {--json : Output machine-readable JSON}';

    protected $description = 'Diagnose yEnc decoder configuration and RapidYenc native library availability';

    private const array STRING_FIELDS = ['php', 'os', 'sapi', 'ffi_enable', 'decoder_mode', 'library_path', 'active_decoder'];

    private const array BOOL_FIELDS = ['thread_safe', 'ffi_extension', 'library_present', 'native_available', 'hardware_crc32'];

    // Keep PHP as the production default until the Rust performance gate passes.
    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    public static function isValidConfiguredMode(string $mode): bool
    {
        return self::useRustFfi() ? self::isValidConfiguredModeNative($mode) : self::isValidConfiguredModePhp($mode);
    }

    public static function isValidConfiguredModePhp(string $mode): bool
    {
        return in_array($mode, ['auto', 'php', 'native'], true);
    }

    public static function isValidConfiguredModeNative(string $mode): bool
    {
        $result = ImportNfos::transformNative(13, [$mode]);

        return is_bool($result) ? $result : self::isValidConfiguredModePhp($mode);
    }

    /**
     * RapidYenc is active only when PHP FFI is loaded and the library passed its self-check.
     */
    public static function resolveActiveDecoder(string $configuredMode, bool $ffiAvailable, bool $rapidyencAvailable): string
    {
        return self::useRustFfi()
            ? self::resolveActiveDecoderNative($configuredMode, $ffiAvailable, $rapidyencAvailable)
            : self::resolveActiveDecoderPhp($configuredMode, $ffiAvailable, $rapidyencAvailable);
    }

    public static function resolveActiveDecoderPhp(string $configuredMode, bool $ffiAvailable, bool $rapidyencAvailable): string
    {
        return match (true) {
            ! self::isValidConfiguredModePhp($configuredMode) => 'unavailable',
            $configuredMode === 'php' => 'PHP',
            $ffiAvailable && $rapidyencAvailable => 'RapidYenc',
            $configuredMode === 'native' => 'unavailable',
            default => 'PHP',
        };
    }

    public static function resolveActiveDecoderNative(string $configuredMode, bool $ffiAvailable, bool $rapidyencAvailable): string
    {
        $result = ImportNfos::transformNative(14, [$configuredMode, $ffiAvailable, $rapidyencAvailable]);

        return is_string($result) ? $result : self::resolveActiveDecoderPhp($configuredMode, $ffiAvailable, $rapidyencAvailable);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{string, string}>
     */
    public static function buildDiagnosticTableRows(array $report): array
    {
        return self::useRustFfi() ? self::buildDiagnosticTableRowsNative($report) : self::buildDiagnosticTableRowsPhp($report);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{string, string}>
     */
    public static function buildDiagnosticTableRowsPhp(array $report): array
    {
        /** @var array{php: string, os: string, sapi: string, thread_safe: bool, ffi_extension: bool, ffi_enable: string, decoder_mode: string, library_path: string, library_present: bool, native_available: bool, native_error: ?string, rapidyenc_version: ?string, hardware_crc32: bool, active_decoder: string} $report */
        return [
            ['PHP', $report['php'].' ('.$report['os'].', '.$report['sapi'].($report['thread_safe'] ? ', ZTS' : ', NTS').')'],
            ['FFI extension', $report['ffi_extension'] ? 'loaded' : 'missing'],
            ['ffi.enable', $report['ffi_enable'] === '' ? '(default)' : $report['ffi_enable']],
            ['YENC_DECODER', $report['decoder_mode']],
            ['Library', $report['library_path'] === '' ? '(unset)' : $report['library_path']],
            ['Library present', $report['library_present'] ? 'yes' : 'no'],
            ['Native self-check', $report['native_available'] ? 'passed' : 'failed: '.$report['native_error']],
            ['RapidYenc version', $report['rapidyenc_version'] ?? 'n/a'],
            ['Hardware CRC32', $report['hardware_crc32'] ? 'yes' : 'no'],
            ['Active decoder', $report['active_decoder']],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{string, string}>
     */
    public static function buildDiagnosticTableRowsNative(array $report): array
    {
        // Rust only accepts the typed report shape; anything else keeps PHP coercion and warnings.
        foreach (self::STRING_FIELDS as $field) {
            if (! is_string($report[$field] ?? null)) {
                return self::buildDiagnosticTableRowsPhp($report);
            }
        }
        foreach (self::BOOL_FIELDS as $field) {
            if (! is_bool($report[$field] ?? null)) {
                return self::buildDiagnosticTableRowsPhp($report);
            }
        }
        foreach (['native_error', 'rapidyenc_version'] as $field) {
            if (! array_key_exists($field, $report) || ($report[$field] !== null && ! is_string($report[$field]))) {
                return self::buildDiagnosticTableRowsPhp($report);
            }
        }
        $result = ImportNfos::transformNative(15, [$report]);
        if (is_array($result) && count($result) === 10 && array_is_list($result)) {
            foreach ($result as $row) {
                if (! is_array($row) || ! array_is_list($row) || count($row) !== 2 || ! is_string($row[0]) || ! is_string($row[1])) {
                    return self::buildDiagnosticTableRowsPhp($report);
                }
            }

            /** @var list<array{string, string}> $result */
            return $result;
        }

        return self::buildDiagnosticTableRowsPhp($report);
    }

    public function handle(): int
    {
        $mode = Config::string('yenc.decoder', 'auto');
        $library = Config::string('yenc.native_library', '');
        $report = [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'thread_safe' => (bool) PHP_ZTS,
            'ffi_extension' => extension_loaded('FFI'),
            'ffi_enable' => (string) ini_get('ffi.enable'),
            'decoder_mode' => $mode,
            'configuration_error' => self::isValidConfiguredMode($mode)
                ? null : 'YENC_DECODER must be auto, php or native.',
            'library_path' => $library,
            'library_present' => $library !== '' && is_file($library),
            'native_available' => false,
            'native_error' => null,
            'rapidyenc_version' => null,
            'hardware_crc32' => false,
        ];
        try {
            $native = new NativePayloadDecoder($library);
            $report['native_available'] = true;
            $report['rapidyenc_version'] = $native->version();
            $report['hardware_crc32'] = $native->crc32('') !== null;
        } catch (Throwable $exception) {
            $report['native_error'] = $exception->getMessage();
        }
        $report['active_decoder'] = self::resolveActiveDecoder($mode, $report['ffi_extension'], $report['native_available']);

        $json = (bool) $this->option('json');
        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Value'], self::buildDiagnosticTableRows($report));
        }

        if ($report['configuration_error'] !== null) {
            if (! $json) {
                $this->error($report['configuration_error']);
            }

            return self::FAILURE;
        }
        if ($mode === 'native' && ! $report['native_available']) {
            if (! $json) {
                $this->error('YENC_DECODER=native but the RapidYenc library is unusable.');
            }

            return self::FAILURE;
        }
        if ($json) {
            return self::SUCCESS;
        }
        if ($mode !== 'php' && ! $report['native_available']) {
            $this->warn('RapidYenc is unavailable; decoding uses the slower PHP fallback.');
            $this->line('Run scripts/build-rapidyenc.sh on Linux CLI to build it, then restart CLI workers.');
        } elseif ($report['native_available'] && ! $report['hardware_crc32']) {
            $this->warn('The loaded library predates CRC support; rerun scripts/build-rapidyenc.sh to enable hardware CRC32.');
        }

        return self::SUCCESS;
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
