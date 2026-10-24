<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\ArchiveInfo;
use FFI;
use Throwable;

class ArchiveProcessingService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_aps_analysis_code(int32_t set_data_ok, const char *error, size_t error_len, int32_t encrypted_prop, int32_t summary_flag_present, int64_t summary_flag);
        C;

    // PHP remains the production default until benchmarks qualify Rust.
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(private readonly ArchiveInfo $archiveInfo) {}

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function additionalFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Analyze compressed data (RAR/ZIP/etc.).
     * Returns an array with: ok(bool), error(?string), summary(?array), is_encrypted(bool)
     *
     * @return array<string, mixed>
     */
    public function analyze(string $compressedData): array
    {
        $ok = $this->archiveInfo->setData($compressedData, true);
        $error = $this->archiveInfo->error;
        if (! $ok || $error !== '') {
            return self::normalizeAnalysis($ok, $error, false, null);
        }
        $summary = $this->archiveInfo->getSummary(true);

        return self::normalizeAnalysis(true, $error, ! empty($this->archiveInfo->isEncrypted), $summary);
    }

    /**
     * Normalize an archive inspection: `$archiveError` is ArchiveInfo's error after
     * setData(), `$isEncryptedProp` is `! empty($archiveInfo->isEncrypted)`.
     *
     * @return array{ok: bool, error: mixed, summary: mixed, is_encrypted: bool}
     */
    public static function normalizeAnalysis(bool $setDataOk, mixed $archiveError, bool $isEncryptedProp, mixed $summary): array
    {
        return self::$useRustFfi
            ? self::normalizeAnalysisNative($setDataOk, $archiveError, $isEncryptedProp, $summary)
            : self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
    }

    /**
     * @return array{ok: bool, error: mixed, summary: mixed, is_encrypted: bool}
     */
    public static function normalizeAnalysisPhp(bool $setDataOk, mixed $archiveError, bool $isEncryptedProp, mixed $summary): array
    {
        if (! $setDataOk) {
            return ['ok' => false, 'error' => $archiveError ?: 'Unknown error', 'summary' => null, 'is_encrypted' => false];
        }
        if ($archiveError !== '') {
            return ['ok' => false, 'error' => $archiveError, 'summary' => null, 'is_encrypted' => false];
        }
        $isEncrypted = $isEncryptedProp || (isset($summary['is_encrypted']) && (int) $summary['is_encrypted'] !== 0);

        return ['ok' => true, 'error' => null, 'summary' => $summary, 'is_encrypted' => $isEncrypted];
    }

    /**
     * @return array{ok: bool, error: mixed, summary: mixed, is_encrypted: bool}
     */
    public static function normalizeAnalysisNative(bool $setDataOk, mixed $archiveError, bool $isEncryptedProp, mixed $summary): array
    {
        $ffi = self::additionalFfi();
        // Non-string errors and non-scalar flags keep PHP's loose semantics.
        if ($ffi === null || ! is_string($archiveError)) {
            return self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
        }
        // Like PHP's short-circuit, the summary flag is only cast when the property is falsy.
        $flagPresent = $setDataOk && $archiveError === '' && ! $isEncryptedProp && isset($summary['is_encrypted']);
        if ($flagPresent && ! is_scalar($summary['is_encrypted'])) {
            return self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
        }
        $flag = $flagPresent ? (int) $summary['is_encrypted'] : 0;

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_aps_analysis_code(
                $setDataOk ? 1 : 0,
                $archiveError,
                strlen($archiveError),
                $isEncryptedProp ? 1 : 0,
                $flagPresent ? 1 : 0,
                $flag,
            );
        } catch (Throwable) {
            return self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
        }

        return match ($code) {
            0, 1 => ['ok' => true, 'error' => null, 'summary' => $summary, 'is_encrypted' => $code === 1],
            2 => ['ok' => false, 'error' => $archiveError, 'summary' => null, 'is_encrypted' => false],
            3 => ['ok' => false, 'error' => 'Unknown error', 'summary' => null, 'is_encrypted' => false],
            default => self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary),
        };
    }

    /**
     * Get file list from currently analyzed archive.
     *
     * @return array<string, mixed>
     */
    public function getFileList(): array
    {
        return $this->archiveInfo->getArchiveFileList() ?: [];
    }

    /**
     * Get file contents by name and source from the current archive.
     */
    public function getFileData(string $name, string $source): string|false
    {
        return $this->archiveInfo->getFileData($name, $source);
    }

    /**
     * Extract file by name from the current archive to destination path.
     */
    public function extractFile(string $name, string $destPath): bool
    {
        return $this->archiveInfo->extractFile($name, $destPath);
    }
}
