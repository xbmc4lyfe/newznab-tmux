<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class DonorMatchSelector
{
    public const PREDB_IDENTITY_POLICY = '3b0bce4c8a8960da331c745d0f6eb47ed7b7c4b603f04461eb40e75b541ee33b';

    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_log_parse_header_spans(const char *line, size_t line_len);
        int64_t nntmux_log_plain_timestamp_span(const char *line, size_t line_len);
        ssize_t nntmux_log_is_header_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_log_parse_headers_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_log_parse_entries_block(const char *input, size_t input_len, uint32_t structured, size_t max_entry_bytes, int64_t *meta_out, size_t meta_cap, char *out_buf, size_t out_cap);
        int32_t nntmux_donor_validate_canonical_single(int64_t predb_id, const char *title, size_t title_len, const char *search_name, size_t search_name_len);
        int32_t nntmux_donor_select_canonical_batch_nul(const char *input, size_t input_len, size_t count);
        int32_t nntmux_donor_select_best_index(const int64_t *pairs, size_t count, int64_t target_size, int64_t tolerance_percent);
        int32_t nntmux_donor_select_best_nul(const char *input, size_t input_len, size_t count, int64_t target_size, int64_t tolerance_percent);
        ssize_t nntmux_book_isbn_normalize(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        int32_t nntmux_book_isbn_equivalent(const char *left, size_t left_len, const char *right, size_t right_len);
        ssize_t nntmux_book_isbn_normalize_batch_nul(const char *input, size_t input_len, size_t count, uint32_t mode, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static ?CData $rawScratch = null;

    private static ?CData $intScratch = null;

    private static int $intCap = 0;

    public function __construct(
        private readonly bool $useNative = true,
    ) {}

    /** @param list<object> $donors */
    public function selectCanonical(array $donors): ?object
    {
        $count = \count($donors);
        if ($count === 0 || $count > 256) {
            return null;
        }

        if ($this->useNative) {
            $ffi = self::$ffi ??= (RustPipelineLibrary::resolveDomain('log_donor_isbn', self::FFI_CDEF) ?? false);
            if ($ffi !== false) {
                $indexed = \array_is_list($donors) ? $donors : \array_values($donors);
                try {
                    if ($count === 1) {
                        $donor = $indexed[0];
                        $predbId = (int) ($donor->predb_id ?? 0);
                        if ($predbId <= 0) {
                            return null;
                        }
                        $title = (string) ($donor->canonical_title ?? '');
                        $searchName = (string) ($donor->searchname ?? '');
                        // @phpstan-ignore method.notFound
                        $status = $ffi->nntmux_donor_validate_canonical_single(
                            $predbId,
                            $title,
                            \strlen($title),
                            $searchName,
                            \strlen($searchName),
                        );
                        if ($status === 1) {
                            $selected = clone $donor;
                            $selected->searchname = $title;

                            return $selected;
                        }
                        if ($status === 0) {
                            return null;
                        }
                    } else {
                        $parts = [];
                        foreach ($indexed as $donor) {
                            $predbId = (int) ($donor->predb_id ?? 0);
                            if ($predbId <= 0) {
                                return null;
                            }
                            $parts[] = (string) $predbId;
                            $parts[] = (string) (int) ($donor->releases_id ?? 0);
                            $parts[] = (string) ($donor->canonical_title ?? '');
                            $parts[] = (string) ($donor->searchname ?? '');
                        }
                        $joined = \implode("\0", $parts);
                        // @phpstan-ignore method.notFound
                        $idx = $ffi->nntmux_donor_select_canonical_batch_nul($joined, \strlen($joined), $count);
                        if (\is_int($idx)) {
                            if ($idx >= 0 && isset($indexed[$idx])) {
                                $selected = clone $indexed[$idx];
                                $selected->searchname = (string) ($selected->canonical_title ?? '');

                                return $selected;
                            }
                            if ($idx === -1) {
                                return null;
                            }
                        }
                    }
                } catch (Throwable) {
                }
            }
        }

        return $this->selectCanonicalPhp($donors);
    }

    /** @param list<object> $donors */
    public function selectCanonicalPhp(array $donors): ?object
    {
        if (count($donors) > 256) {
            return null;
        }
        $identity = null;
        $selected = null;
        foreach ($donors as $donor) {
            $title = (string) ($donor->canonical_title ?? '');
            $searchName = (string) ($donor->searchname ?? '');
            if ((int) ($donor->predb_id ?? 0) <= 0 || trim($title) === '' || ! mb_check_encoding($title, 'UTF-8')
                || ! mb_check_encoding($searchName, 'UTF-8') || mb_strlen($title, 'UTF-8') > 255
                || preg_match('/\p{C}/u', $title) !== 0
                || PredbSearchDocument::exactValue($title) !== PredbSearchDocument::exactValue($searchName)) {
                return null;
            }
            $currentIdentity = (int) $donor->predb_id.':'.PredbSearchDocument::exactValue($title);
            if ($identity !== null && $identity !== $currentIdentity) {
                return null;
            }
            $identity = $currentIdentity;
            if ($selected === null || (int) $donor->releases_id < (int) $selected->releases_id) {
                $selected = clone $donor;
                $selected->searchname = $title;
            }
        }

        return $selected;
    }

    /**
     * @param  list<object>  $donors
     */
    public function select(array $donors, int $targetSize, int $tolerancePercent): ?object
    {
        $count = \count($donors);
        if ($targetSize <= 0 || $count === 0 || $tolerancePercent < 0) {
            return null;
        }

        if ($this->useNative) {
            $ffi = self::$ffi ??= (RustPipelineLibrary::resolveDomain('log_donor_isbn', self::FFI_CDEF) ?? false);
            if ($ffi !== false) {
                $intBuf = self::obtainIntScratch($ffi, $count * 2);
                if ($intBuf !== null) {
                    $indexed = \array_is_list($donors) ? $donors : \array_values($donors);
                    try {
                        $j = 0;
                        foreach ($indexed as $donor) {
                            $intBuf[$j] = (int) ($donor->relsize ?? $donor->size ?? 0);
                            $intBuf[$j + 1] = (int) ($donor->releases_id ?? $donor->id ?? PHP_INT_MAX);
                            $j += 2;
                        }
                        // @phpstan-ignore method.notFound
                        $idx = $ffi->nntmux_donor_select_best_index($intBuf, $count, $targetSize, $tolerancePercent);
                        if (\is_int($idx)) {
                            return ($idx >= 0 && isset($indexed[$idx])) ? $indexed[$idx] : null;
                        }
                    } catch (Throwable) {
                    }
                }
            }
        }

        return $this->selectPhp($donors, $targetSize, $tolerancePercent);
    }

    /**
     * @param  list<object>  $donors
     */
    public function selectPhp(array $donors, int $targetSize, int $tolerancePercent): ?object
    {
        if ($targetSize <= 0 || $donors === []) {
            return null;
        }

        $eligible = [];

        foreach ($donors as $donor) {
            $donorSize = (int) ($donor->relsize ?? $donor->size ?? 0);
            if ($donorSize <= 0) {
                continue;
            }

            $difference = abs($donorSize - $targetSize);
            $differencePercent = ($difference / $donorSize) * 100;

            if ($differencePercent <= $tolerancePercent) {
                $eligible[] = [
                    'donor' => $donor,
                    'difference' => $difference,
                    'id' => (int) ($donor->releases_id ?? $donor->id ?? PHP_INT_MAX),
                ];
            }
        }

        if ($eligible === []) {
            return null;
        }

        usort($eligible, static function (array $left, array $right): int {
            return [$left['difference'], $left['id']] <=> [$right['difference'], $right['id']];
        });

        return $eligible[0]['donor'];
    }

    private static function obtainIntScratch(FFI $ffi, int $minInts): ?CData
    {
        if (self::$intScratch !== null && self::$intCap >= $minInts) {
            return self::$intScratch;
        }
        $capBytes = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $minInts * 8, $capBytes);
        if ($scratch === null) {
            return null;
        }
        if ($scratch !== self::$rawScratch || self::$intScratch === null) {
            self::$rawScratch = $scratch;
            $intCap = intdiv($capBytes, 8);
            self::$intScratch = $ffi->cast("int64_t[{$intCap}]", $scratch);
            self::$intCap = $intCap;
        }

        return self::$intScratch;
    }
}
