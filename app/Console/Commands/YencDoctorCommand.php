<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NNTPService;
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

    /**
     * Default for the Wave 38.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNntpServiceFingerprintDispatchCommandsParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const array W382_DECODERS = ['PHP', 'RapidYenc', 'unavailable'];

    private const array W382_ERRORS = ['none', 'configuration', 'native_unusable'];

    private const array W382_WARNINGS = ['none', 'php_fallback', 'stale_library'];

    private const array W382_LIBRARY_STATES = ['unset', 'missing', 'loaded', 'unusable'];

    // Keep PHP as the production default until the Rust performance gate passes.
    private static ?bool $useRustFfi = null;

    private static ?bool $useWave382RustFfi = null;

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

    public static function useWave382RustFfi(?bool $use): void
    {
        self::$useWave382RustFfi = $use;
    }

    public static function usesWave382RustFfi(): bool
    {
        return self::$useWave382RustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetWave382RustFfi(): void
    {
        self::$useWave382RustFfi = null;
    }

    /**
     * Summarize what handle() reports for one probe: the active decoder, the failing configuration (invalid mode, or
     * native mode without a usable library) and its exit code, the non-JSON warning (PHP fallback or a library
     * without hardware CRC32) when nothing failed, and whether the library is unset, missing, loaded or unusable.
     *
     * @return array{mode_valid: bool, active_decoder: string, error: string, exit_code: int, warning: string, library_state: string}
     */
    public static function summarizeDecoderReadiness(string $configuredMode, string $libraryPath, bool $ffiExtension, bool $libraryPresent,
        bool $nativeAvailable, bool $hardwareCrc32): array
    {
        return (self::usesWave382RustFfi()
            ? self::summarizeDecoderReadinessNative($configuredMode, $libraryPath, $ffiExtension, $libraryPresent, $nativeAvailable, $hardwareCrc32) : null)
            ?? self::summarizeDecoderReadinessPhp($configuredMode, $libraryPath, $ffiExtension, $libraryPresent, $nativeAvailable, $hardwareCrc32);
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

    /** @return array{mode_valid: bool, active_decoder: string, error: string, exit_code: int, warning: string, library_state: string} */
    private static function summarizeDecoderReadinessPhp(string $configuredMode, string $libraryPath, bool $ffiExtension, bool $libraryPresent,
        bool $nativeAvailable, bool $hardwareCrc32): array
    {
        $valid = self::isValidConfiguredModePhp($configuredMode);
        $error = match (true) {
            ! $valid => 'configuration',
            $configuredMode === 'native' && ! $nativeAvailable => 'native_unusable',
            default => 'none',
        };

        return ['mode_valid' => $valid, 'active_decoder' => self::resolveActiveDecoderPhp($configuredMode, $ffiExtension, $nativeAvailable),
            'error' => $error, 'exit_code' => $error === 'none' ? self::SUCCESS : self::FAILURE,
            'warning' => match (true) {
                $error !== 'none' => 'none',
                $configuredMode !== 'php' && ! $nativeAvailable => 'php_fallback',
                $nativeAvailable && ! $hardwareCrc32 => 'stale_library',
                default => 'none',
            },
            'library_state' => match (true) {
                $libraryPath === '' => 'unset',
                ! $libraryPresent => 'missing',
                $nativeAvailable => 'loaded',
                default => 'unusable',
            }];
    }

    /** @return array{mode_valid: bool, active_decoder: string, error: string, exit_code: int, warning: string, library_state: string}|null */
    private static function summarizeDecoderReadinessNative(string $configuredMode, string $libraryPath, bool $ffiExtension, bool $libraryPresent,
        bool $nativeAvailable, bool $hardwareCrc32): ?array
    {
        $flags = ($ffiExtension ? 1 : 0) | ($libraryPresent ? 2 : 0) | ($nativeAvailable ? 4 : 0) | ($hardwareCrc32 ? 8 : 0) | ($libraryPath !== '' ? 16 : 0);
        $slots = NNTPService::evaluateWave382Native(4, $flags, pack('q', strlen($configuredMode)).$configuredMode, 6);
        if ($slots === null) {
            return null;
        }
        [$valid, $active, $error, $exit, $warning, $library] = $slots;
        if (($valid !== 0 && $valid !== 1) || ! isset(self::W382_DECODERS[$active], self::W382_ERRORS[$error], self::W382_WARNINGS[$warning], self::W382_LIBRARY_STATES[$library])
            || $exit !== (int) ($error !== 0)) {
            return null;
        }

        return ['mode_valid' => $valid === 1, 'active_decoder' => self::W382_DECODERS[$active], 'error' => self::W382_ERRORS[$error],
            'exit_code' => $exit === 0 ? self::SUCCESS : self::FAILURE, 'warning' => self::W382_WARNINGS[$warning], 'library_state' => self::W382_LIBRARY_STATES[$library]];
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
