<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/** Validate all article references without fetching any payload. */
final class CandidateHeaderInventory
{
    /**
     * Header defects depend only on the manifest, so retrying the same version cannot pass. These
     * candidates wait outside the claim queue until new headers create a new version (or expire).
     */
    public const string REJECTED = 'header_rejected';

    /** Every reason assessment() gives with the REJECTED status. */
    public const array REJECTION_REASONS = [
        'Conflicting header identities require resolution.',
        'Candidate has no file inventory to spot check.',
        'Invalid or duplicate file identity in candidate headers.',
        'Invalid declared article count in candidate headers.',
        'Invalid or duplicate article reference in candidate headers.',
    ];

    /**
     * Rust validates message IDs from this many articles; below it the FFI round trip costs more
     * than the PHP checks (see tests/Unit/RustCandidateHeaderInventoryParityTest.php).
     */
    private const int NATIVE_MINIMUM_ARTICLES = 32;

    private const int FIRST_ID_CHUNK = 16;

    private const int LAST_ID_CHUNK = 1024;

    private const int MODE_ASSESS = 1;

    private const int MODE_COMPLETENESS = 2;

    private const int MODE_BYTES = 4;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_candidate_header_assess_packed(const char *data, size_t length, int32_t conflict, int64_t expected_files, uint32_t mode, int64_t *out);
        int32_t nntmux_candidate_header_assess_json(const char *data, size_t length, uint32_t mode, int64_t *out);
        void nntmux_candidate_header_ids_begin(size_t capacity);
        int64_t nntmux_candidate_header_ids_feed(const char *data, size_t length, int64_t count);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $out = null;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /** @param array<string, mixed> $manifest */
    public function rejection(array $manifest): ?string
    {
        return $this->assessment($manifest)['reason'];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{status: 'ready'|'waiting_upload'|'header_rejected', reason: ?string}
     */
    public function assessment(array $manifest): array
    {
        if (($manifest['conflict'] ?? false) === true) {
            return ['status' => self::REJECTED, 'reason' => 'Conflicting header identities require resolution.'];
        }
        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === []) {
            return ['status' => self::REJECTED, 'reason' => 'Candidate has no file inventory to spot check.'];
        }
        $expected = max(0, (int) ($manifest['expected_files'] ?? 0));
        if (self::$useRustFfi && ($articles = self::articles($files)) >= self::NATIVE_MINIMUM_ARTICLES && ($ffi = self::ffi()) !== null) {
            $assessment = self::assessFilesWithNativeIds($ffi, $files, $expected, $articles);
            if ($assessment !== null) {
                return $assessment;
            }
        }

        return self::assessFiles($files, $expected);
    }

    /**
     * Rust assessment for parity tests and benchmarks; null when PHP must assess the manifest.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{status: 'ready'|'waiting_upload'|'header_rejected', reason: ?string}|null
     */
    public static function assessmentNative(array $manifest): ?array
    {
        if (($manifest['conflict'] ?? false) === true) {
            return ['status' => self::REJECTED, 'reason' => 'Conflicting header identities require resolution.'];
        }
        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === []) {
            return ['status' => self::REJECTED, 'reason' => 'Candidate has no file inventory to spot check.'];
        }
        $native = self::nativeCall($files, (int) ($manifest['expected_files'] ?? 0), self::MODE_ASSESS);

        return $native === null ? null : self::assessmentFromStatus($native[0]);
    }

    /**
     * Rust assessment of a JSON manifest, as `assessment(json_decode($json, true))`; null when
     * PHP must decode and assess it.
     *
     * @return array{status: 'ready'|'waiting_upload'|'header_rejected', reason: ?string}|null
     */
    public static function assessmentJsonNative(string $json): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || $json === '' || self::$out === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            if ($ffi->nntmux_candidate_header_assess_json($json, \strlen($json), self::MODE_ASSESS, self::$out) !== 0) {
                return null;
            }

            return self::assessmentFromStatus((int) self::$out[0]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * assessFiles() with the message-ID checks (trim, pattern and uniqueness) moved to Rust, fed
     * in visiting order in chunks that grow from 16 to 1024 IDs so an early defect stops the walk
     * soon after PHP would. Every other defect still stops the walk where PHP stops; a file-level
     * defect first validates the IDs already visited, because PHP would have rejected an earlier
     * invalid ID before reaching that file. Null when PHP must assess.
     *
     * @param  array<mixed>  $files
     * @return array{status: 'ready'|'waiting_upload'|'header_rejected', reason: ?string}|null
     */
    private static function assessFilesWithNativeIds(FFI $ffi, array $files, int $expected, int $articles): ?array
    {
        $reference = ['status' => self::REJECTED, 'reason' => 'Invalid or duplicate article reference in candidate headers.'];
        $missingParts = false;
        $keys = [];
        $ids = [];
        $flushAt = self::FIRST_ID_CHUNK;
        try {
            $ffi->nntmux_candidate_header_ids_begin($articles); // @phpstan-ignore method.notFound
            foreach ($files as $file) {
                if (! is_array($file) || ! is_string($file['key'] ?? null) || $file['key'] === ''
                    || isset($keys[$file['key']]) || ! is_array($file['segments'] ?? null)) {
                    $valid = self::feedIds($ffi, $ids);

                    return $valid === null ? null : ($valid ? ['status' => self::REJECTED, 'reason' => 'Invalid or duplicate file identity in candidate headers.'] : $reference);
                }
                $keys[$file['key']] = true;
                $total = (int) ($file['totalparts'] ?? 0);
                if ($total < 1 || count($file['segments']) > $total) {
                    $valid = self::feedIds($ffi, $ids);

                    return $valid === null ? null : ($valid ? ['status' => self::REJECTED, 'reason' => 'Invalid declared article count in candidate headers.'] : $reference);
                }
                $missingParts = $missingParts || count($file['segments']) < $total;
                $previous = 0;
                $ascending = true;
                foreach ($file['segments'] as $segment) {
                    if (! is_array($segment)) {
                        return $reference;
                    }
                    $number = $segment['number'] ?? null;
                    $bytes = $segment['bytes'] ?? null;
                    $id = $segment['messageid'] ?? null;
                    if (! is_int($number) || $number < 1 || $number > $total || ! is_int($bytes) || $bytes < 1 || ! is_string($id)) {
                        return $reference;
                    }
                    if ($number <= $previous) {
                        $ascending = false;
                    }
                    $previous = $number;
                    $ids[] = $id;
                    if (count($ids) === $flushAt) {
                        $valid = self::feedIds($ffi, $ids);
                        if ($valid !== true) {
                            return $valid === null ? null : $reference;
                        }
                        $ids = [];
                        $flushAt = min(self::LAST_ID_CHUNK, $flushAt * 2);
                    }
                }
                // Strictly ascending part numbers cannot repeat; otherwise a repeated number collapses
                // into one key, which PHP rejects like any bad reference.
                if (! $ascending && count(array_flip(array_column($file['segments'], 'number'))) !== count($file['segments'])) {
                    return $reference;
                }
            }
            $valid = self::feedIds($ffi, $ids);
            if ($valid !== true) {
                return $valid === null ? null : $reference;
            }
        } catch (Throwable) {
            return null;
        } finally {
            try {
                $ffi->nntmux_candidate_header_ids_begin(0); // @phpstan-ignore method.notFound
            } catch (Throwable) {
            }
        }

        if ($expected > count($files)) {
            return ['status' => 'waiting_upload', 'reason' => 'Declared file inventory is still uploading; publication deferred.'];
        }
        if ($missingParts) {
            return ['status' => 'waiting_upload', 'reason' => 'Declared article inventory is still uploading; publication deferred.'];
        }

        return ['status' => 'ready', 'reason' => null];
    }

    /**
     * Whether the next visited message IDs pass the PHP pattern and stay unique; null when the
     * NUL framing is ambiguous and PHP must validate.
     *
     * @param  list<string>  $ids
     */
    private static function feedIds(FFI $ffi, array $ids): ?bool
    {
        $count = count($ids);
        if ($count === 0) {
            return true;
        }
        $joined = implode("\0", $ids);
        // @phpstan-ignore method.notFound
        $first = $ffi->nntmux_candidate_header_ids_feed($joined, \strlen($joined), $count);
        if (! \is_int($first) || $first < 0 || $first > $count) {
            return null;
        }

        return $first === $count;
    }

    /**
     * Rust message-ID validation of a complete ID list, for parity tests: whether every ID passes
     * the PHP pattern and is unique; null when Rust is unavailable or must defer to PHP.
     *
     * @param  list<string>  $ids
     */
    public static function messageIdsValidNative(array $ids): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $ffi->nntmux_candidate_header_ids_begin(count($ids)); // @phpstan-ignore method.notFound

            return self::feedIds($ffi, $ids);
        } catch (Throwable) {
            return null;
        } finally {
            $ffi->nntmux_candidate_header_ids_begin(0); // @phpstan-ignore method.notFound
        }
    }

    /**
     * @param  array<mixed>  $files
     * @return array{status: 'ready'|'waiting_upload'|'header_rejected', reason: ?string}
     */
    private static function assessFiles(array $files, int $expected): array
    {
        $missingParts = false;
        $keys = [];
        $ids = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['key'] ?? null) || $file['key'] === ''
                || isset($keys[$file['key']]) || ! is_array($file['segments'] ?? null)) {
                return ['status' => self::REJECTED, 'reason' => 'Invalid or duplicate file identity in candidate headers.'];
            }
            $keys[$file['key']] = true;
            $total = (int) ($file['totalparts'] ?? 0);
            if ($total < 1 || count($file['segments']) > $total) {
                return ['status' => self::REJECTED, 'reason' => 'Invalid declared article count in candidate headers.'];
            }
            $missingParts = $missingParts || count($file['segments']) < $total;
            $numbers = [];
            foreach ($file['segments'] as $segment) {
                if (! is_array($segment) || ! is_int($segment['number'] ?? null) || $segment['number'] < 1
                    || $segment['number'] > $total || isset($numbers[$segment['number']])
                    || ! is_int($segment['bytes'] ?? null) || $segment['bytes'] < 1
                    || ! is_string($segment['messageid'] ?? null)
                    || preg_match('/\A[^\s<>\x00-\x1f\x7f]+\z/D', trim($segment['messageid'], '<>')) !== 1
                    || isset($ids[trim($segment['messageid'], '<>')])) {
                    return ['status' => self::REJECTED, 'reason' => 'Invalid or duplicate article reference in candidate headers.'];
                }
                $numbers[$segment['number']] = true;
                $ids[trim($segment['messageid'], '<>')] = true;
            }
        }

        if ($expected > count($files)) {
            return ['status' => 'waiting_upload', 'reason' => 'Declared file inventory is still uploading; publication deferred.'];
        }
        if ($missingParts) {
            return ['status' => 'waiting_upload', 'reason' => 'Declared article inventory is still uploading; publication deferred.'];
        }

        return ['status' => 'ready', 'reason' => null];
    }

    /**
     * The rejection a partial publication still enforces: an upload that is only missing files or
     * articles is accepted, while invalid or conflicting headers are not.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function partialRejection(array $manifest): ?string
    {
        $assessment = $this->assessment($manifest);

        return $assessment['status'] === 'waiting_upload' ? null : $assessment['reason'];
    }

    /**
     * Articles and files found in the headers against those declared. A file is found once it has an
     * article (only those reach the NZB); files never seen declare no article count.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{articles_found: int, articles_expected: int, files_found: int, files_expected: int}
     */
    public function completeness(array $manifest): array
    {
        // Retain native PHP in production: it counts per file (O(files)), while the Rust path
        // must serialize every segment (0.00x-0.01x; see the parity test benchmark).
        return self::completenessPhp($manifest);
    }

    /**
     * Rust completeness for parity tests; null when PHP must count the manifest.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{articles_found: int, articles_expected: int, files_found: int, files_expected: int}|null
     */
    public static function completenessNative(array $manifest): ?array
    {
        $files = $manifest['files'] ?? [];
        if (! is_array($files) || $files === []) {
            return null;
        }
        $native = self::nativeCall($files, (int) ($manifest['expected_files'] ?? 0), self::MODE_COMPLETENESS);
        if ($native === null || $native[6] !== 1) {
            return null;
        }

        return ['articles_found' => $native[1], 'articles_expected' => $native[2], 'files_found' => $native[3],
            'files_expected' => $native[4]];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{articles_found: int, articles_expected: int, files_found: int, files_expected: int}
     */
    private static function completenessPhp(array $manifest): array
    {
        $found = 0;
        $expected = 0;
        $filesFound = 0;
        foreach ($manifest['files'] ?? [] as $file) {
            $segments = is_array($file['segments'] ?? null) ? count($file['segments']) : 0;
            $found += $segments;
            $expected += max($segments, (int) ($file['totalparts'] ?? 0));
            $filesFound += $segments > 0 ? 1 : 0;
        }

        return ['articles_found' => $found, 'articles_expected' => $expected, 'files_found' => $filesFound,
            'files_expected' => max(count($manifest['files'] ?? []), (int) ($manifest['expected_files'] ?? 0))];
    }

    /** @param array<string, mixed> $manifest */
    public function bytes(array $manifest): int
    {
        // Retain native PHP in production: one cast per segment is cheaper than serializing it
        // for Rust (0.17x-0.22x mean; see the parity test benchmark).
        return self::bytesPhp($manifest);
    }

    /**
     * Rust byte total for parity tests; null when PHP must sum the manifest.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function bytesNative(array $manifest): ?int
    {
        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === []) {
            return null;
        }
        $native = self::nativeCall($files, 0, self::MODE_BYTES);

        return $native === null || $native[7] !== 1 ? null : $native[5];
    }

    /** @param array<string, mixed> $manifest */
    private static function bytesPhp(array $manifest): int
    {
        $bytes = 0;
        foreach ($manifest['files'] as $file) {
            foreach ($file['segments'] as $segment) {
                $bytes += max(0, (int) ($segment['bytes'] ?? 0));
            }
        }

        return $bytes;
    }

    /** @param array<mixed> $files */
    private static function articles(array $files): int
    {
        $articles = 0;
        foreach ($files as $file) {
            if (is_array($file) && is_array($file['segments'] ?? null)) {
                $articles += count($file['segments']);
            }
        }

        return $articles;
    }

    /**
     * @param  array<mixed>  $files
     * @return list<int>|null status, articles found/expected, files found/expected, bytes, and
     *                        whether completeness and bytes were reproduced
     */
    private static function nativeCall(array $files, int $expected, int $mode): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || self::$out === null) {
            return null;
        }
        try {
            $input = serialize($files);
            // @phpstan-ignore method.notFound
            if ($ffi->nntmux_candidate_header_assess_packed($input, \strlen($input), 0, $expected, $mode, self::$out) !== 0) {
                return null;
            }
            $out = self::$out;

            return [(int) $out[0], (int) $out[1], (int) $out[2], (int) $out[3], (int) $out[4], (int) $out[5], (int) $out[6], (int) $out[7]];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{status: 'ready'|'waiting_upload'|'header_rejected', reason: ?string}|null */
    private static function assessmentFromStatus(int $status): ?array
    {
        return match (true) {
            $status === 0 => ['status' => 'ready', 'reason' => null],
            $status === 1 => ['status' => 'waiting_upload', 'reason' => 'Declared file inventory is still uploading; publication deferred.'],
            $status === 2 => ['status' => 'waiting_upload', 'reason' => 'Declared article inventory is still uploading; publication deferred.'],
            $status >= 10 && $status < 10 + count(self::REJECTION_REASONS) => ['status' => self::REJECTED, 'reason' => self::REJECTION_REASONS[$status - 10]],
            default => null,
        };
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('candidate_header_inventory', self::FFI_CDEF);
            try {
                self::$out = self::$ffi?->new('int64_t[8]');
            } catch (Throwable) {
                self::$ffi = null;
            }
            if (self::$out === null) {
                self::$ffi = null;
            }
        }

        return self::$ffi;
    }
}
