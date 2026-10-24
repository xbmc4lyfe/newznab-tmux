<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Services\Yenc\ArticleFrame;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;

final class NzbFingerprintAnalyzer
{
    public const MAX_ARTICLE_SAMPLES = 128;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_nzb_file_kind(const char *subject, size_t subject_len);
        ssize_t nntmux_nzb_format_hint(const char *subject, size_t subject_len, char *out_buf, size_t out_cap);
        int32_t nntmux_nzb_detect_format_signatures(const char *bytes, size_t len);
        ssize_t nntmux_nzb_analyze_manifest(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_unfold_headers(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_analyze_article_body(const char *body, size_t body_len, uint32_t flags, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_select_article_samples(const char *input, size_t input_len, size_t file_count, size_t max_samples, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = true;

    public function __construct(private readonly YencService $yenc) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }

        return RustPipelineLibrary::resolveDomain('nzb_fingerprint_manifest', self::FFI_CDEF);
    }

    /** @return array<string, mixed> */
    public function analyzeManifest(string $xml): array
    {
        if (strlen($xml) <= 33_554_432) {
            $native = self::analyzeManifestNative($xml);
            if ($native !== null) {
                return $native;
            }
        }
        if (strlen($xml) > 33_554_432 || str_contains(strtoupper($xml), '<!ENTITY')) {
            throw new RuntimeException('NZB exceeds the metadata parser budget or contains an entity declaration.');
        }
        // Remove only the app's standard external declaration before parsing;
        // internal subsets and other declarations remain rejected below.
        $xml = preg_replace(
            '/<!DOCTYPE\s+nzb\s+PUBLIC\s+(["\'])-\/\/newzBin\/\/DTD NZB 1\.1\/\/EN\1\s+(["\'])http:\/\/www\.newzbin\.com\/DTD\/nzb\/nzb-1\.1\.dtd\2\s*>/i',
            '',
            $xml,
        ) ?? $xml;
        if (str_contains(strtoupper($xml), '<!DOCTYPE')) {
            throw new RuntimeException('NZB contains an unsupported DTD.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $options = LIBXML_NONET | LIBXML_NOBLANKS;
            if (defined('LIBXML_NO_XXE')) {
                $options |= (int) constant('LIBXML_NO_XXE');
            }
            $document = simplexml_load_string($xml, options: $options);
            if ($document === false || $document->getName() !== 'nzb') {
                throw new RuntimeException('Invalid NZB XML.');
            }

            $meta = [];
            $metaElements = $document->xpath('/*[local-name()="nzb"]/*[local-name()="head"]/*[local-name()="meta"]') ?: [];
            foreach ($metaElements as $element) {
                $type = trim((string) $element['type']);
                if ($type !== '') {
                    $meta[$type][] = trim((string) $element);
                }
            }

            $files = [];
            $totals = [
                'rar' => ['file_count' => 0, 'segment_count' => 0, 'advertised_bytes' => 0, 'advertised_bytes_overflow' => false],
                'par2' => ['file_count' => 0, 'segment_count' => 0, 'advertised_bytes' => 0, 'advertised_bytes_overflow' => false],
                'other' => ['file_count' => 0, 'segment_count' => 0, 'advertised_bytes' => 0, 'advertised_bytes_overflow' => false],
            ];
            $fileElements = $document->xpath('/*[local-name()="nzb"]/*[local-name()="file"]') ?: [];
            foreach ($fileElements as $fileIndex => $element) {
                $subject = trim((string) $element['subject']);
                $kind = self::fileKind($subject);
                $segments = [];
                $segmentElements = $element->xpath('./*[local-name()="segments"]/*[local-name()="segment"]') ?: [];
                $invalidSegmentCount = 0;
                $invalidSegmentMetadataCount = 0;
                $advertisedBytes = 0;
                $advertisedBytesOverflow = false;
                foreach ($segmentElements as $segment) {
                    $messageId = trim((string) $segment);
                    if ($messageId === '' || preg_match('/\A[^\s<>\x00-\x1f\x7f]+\z/D', trim($messageId, '<>')) !== 1) {
                        $invalidSegmentCount++;

                        continue;
                    }
                    $segmentNumber = trim((string) $segment['number']);
                    $segmentBytes = trim((string) $segment['bytes']);
                    $parsedNumber = self::metadataNumber($segmentNumber);
                    $parsedBytes = self::metadataNumber($segmentBytes);
                    if ($parsedNumber === null || $parsedNumber < 1 || $parsedBytes === null) {
                        $invalidSegmentMetadataCount++;
                    }
                    if (($parsedBytes === null && ctype_digit($segmentBytes))
                        || (is_int($advertisedBytes) && is_int($parsedBytes) && $parsedBytes > PHP_INT_MAX - $advertisedBytes)) {
                        $advertisedBytesOverflow = true;
                    }
                    $advertisedBytes = self::checkedAdd($advertisedBytes, $parsedBytes);
                    $segments[] = [
                        'number' => $parsedNumber ?? 0,
                        'bytes' => $parsedBytes,
                        'source_number' => $segmentNumber,
                        'source_bytes' => $segmentBytes,
                        'messageid' => trim($messageId, '<>'),
                    ];
                }
                usort($segments, static fn (array $left, array $right): int => $left['number'] <=> $right['number']);
                $segmentNumbers = array_column($segments, 'number');
                $messageIds = array_column($segments, 'messageid');
                $numberSequenceValid = $segmentNumbers !== [] && $segmentNumbers === range(1, count($segmentNumbers));
                $groups = [];
                $groupElements = $element->xpath('./*[local-name()="groups"]/*[local-name()="group"]') ?: [];
                foreach ($groupElements as $group) {
                    $groups[] = trim((string) $group);
                }
                $files[] = [
                    'index' => (int) $fileIndex,
                    'kind' => $kind,
                    'format_hint' => self::formatHint($subject),
                    'subject' => $subject,
                    'poster' => (string) $element['poster'],
                    'date' => self::boundedDecimalInt(trim((string) $element['date'])),
                    'source_date' => (string) $element['date'],
                    'groups' => $groups,
                    'segment_count' => count($segments),
                    'source_segment_record_count' => count($segmentElements),
                    'invalid_segment_record_count' => $invalidSegmentCount,
                    'invalid_segment_metadata_count' => $invalidSegmentMetadataCount,
                    'duplicate_message_id_count' => count($messageIds) - count(array_unique($messageIds)),
                    'duplicate_segment_number_count' => count($segmentNumbers) - count(array_unique($segmentNumbers)),
                    'segment_number_sequence_valid' => $numberSequenceValid,
                    'advertised_bytes' => $advertisedBytes,
                    'advertised_bytes_overflow' => $advertisedBytesOverflow,
                    'segments' => $segments,
                ];
                $totals[$kind]['file_count']++;
                $totals[$kind]['segment_count'] += count($segments);
                if ($advertisedBytesOverflow || (is_int($totals[$kind]['advertised_bytes']) && is_int($advertisedBytes)
                    && $advertisedBytes > PHP_INT_MAX - $totals[$kind]['advertised_bytes'])) {
                    $totals[$kind]['advertised_bytes_overflow'] = true;
                }
                $totals[$kind]['advertised_bytes'] = self::checkedAdd($totals[$kind]['advertised_bytes'], $advertisedBytes);
            }
            if ($files === []) {
                throw new RuntimeException('NZB contains no files.');
            }

            $advertisedByteTotalsValid = count(array_filter($totals, static fn (array $total): bool => is_int($total['advertised_bytes']))) === count($totals);
            $par2ToRarPercent = is_int($totals['rar']['advertised_bytes']) && $totals['rar']['advertised_bytes'] > 0
                && is_int($totals['par2']['advertised_bytes'])
                ? round($totals['par2']['advertised_bytes'] / $totals['rar']['advertised_bytes'] * 100, 3)
                : null;

            return [
                'file_count' => count($files),
                'files' => $files,
                'totals' => $totals,
                'advertised_byte_totals_valid' => $advertisedByteTotalsValid,
                'nzb_meta_values' => $meta,
                'par2_to_rar_advertised_percent' => $par2ToRarPercent,
                'par2_to_rar_percent_basis' => 'NZB advertised segment bytes; includes article and yEnc overhead and is not the PAR2 recovery percentage.',
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param list<array<string, mixed>> $files
     * @param-out int $omittedSampleCount
     *
     * @return list<array{file_index: int, kind: string, subject: string, segment_number: int, messageid: string, segment_bytes: int|null, sample_role: string}>
     */
    public function selectArticleSamples(array $files, int $maximumSamples = self::MAX_ARTICLE_SAMPLES, ?int &$omittedSampleCount = null): array
    {
        $samples = [];
        $seen = [];
        $omittedSampleCount = 0;
        $maximumSamples = max(1, min(self::MAX_ARTICLE_SAMPLES, $maximumSamples));
        foreach ($files as $file) {
            $segments = $file['segments'] ?? [];
            if (! is_array($segments) || $segments === []) {
                continue;
            }
            if (($file['kind'] ?? null) === 'par2') {
                $selected = array_map(static fn (array $segment): array => [$segment, 'all_par2_segments'], $segments);
            } else {
                $last = count($segments) - 1;
                $positions = $last === 0 ? [[0, 'first']] : [[0, 'first'], [$last, 'last']];
                if ($last > 1) {
                    array_splice($positions, 1, 0, [[intdiv($last, 2), 'middle']]);
                }
                $selected = array_map(static fn (array $sample): array => [$segments[$sample[0]], $sample[1]], $positions);
            }
            foreach ($selected as [$segment, $sampleRole]) {
                $messageId = (string) ($segment['messageid'] ?? '');
                $sampleKey = (int) $file['index']."\0".$messageId;
                if ($messageId === '' || isset($seen[$sampleKey])) {
                    continue;
                }
                $seen[$sampleKey] = true;
                if (count($samples) >= $maximumSamples) {
                    $omittedSampleCount++;

                    continue;
                }
                $samples[] = [
                    'file_index' => (int) $file['index'],
                    'kind' => (string) $file['kind'],
                    'subject' => (string) $file['subject'],
                    'segment_number' => (int) $segment['number'],
                    'messageid' => $messageId,
                    'segment_bytes' => is_int($segment['bytes'] ?? null) ? $segment['bytes'] : null,
                    'sample_role' => $sampleRole,
                ];
            }
        }

        return $samples;
    }

    /** @param list<string> $rawHeaders
     * @return array<string, mixed>
     */
    public function analyzeArticle(array $rawHeaders, string $rawBody): array
    {
        return $this->analyzeArticleAndDecodedBytes($rawHeaders, $rawBody)['metadata'];
    }

    /** @param list<string> $rawHeaders
     * @return array{metadata: array<string, mixed>, decoded_bytes: ?string}
     */
    public function analyzeArticleAndDecodedBytes(array $rawHeaders, string $rawBody): array
    {
        $values = [];
        $orderedNames = [];
        $previousName = null;
        foreach ($rawHeaders as $line) {
            $content = rtrim($line, "\r\n");
            if (($content[0] ?? '') === ' ' || ($content[0] ?? '') === "\t") {
                if ($previousName !== null) {
                    $values[$previousName] = rtrim((string) ($values[$previousName] ?? '')).' '.trim($content);
                }

                continue;
            }
            if (! str_contains($content, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $content, 2);
            $normalizedName = strtolower(trim($name));
            if ($normalizedName === '') {
                continue;
            }
            $orderedNames[] = trim($name);
            $previousName = $normalizedName;
            $values[$normalizedName] = isset($values[$normalizedName])
                ? $values[$normalizedName].', '.trim($value)
                : trim($value);
        }

        $joinedHeaders = implode('', $rawHeaders);
        $yencFramingMetadata = (new NativeMetadataParser)->parseYencFraming($rawBody);
        $nntpHeaderMetadata = (new NativeMetadataParser)->parseNntpHeaders($joinedHeaders);
        $nntpBodyMetadata = (new NativeMetadataParser)->parseNntpBody($rawBody);
        $nntpLinesBodyRelationship = (new NativeMetadataParser)->compareNntpLinesToBody(
            $joinedHeaders,
            $rawBody,
        );

        $useRustYencDecode = $this->yenc::class === YencService::class && $this->yenc->decoderName() === 'PHP';
        $bodyFlags = 4
            | ($nntpBodyMetadata === null ? 1 : 0)
            | ($yencFramingMetadata === null ? 2 : 0)
            | ($useRustYencDecode ? 8 : 0);
        $nativeBody = self::analyzeArticleBodyNative($rawBody, $bodyFlags);

        if ($nntpBodyMetadata === null) {
            $nntpBodyMetadata = $nativeBody['nntp_body_metadata'] ?? self::fallbackNntpBodyMetadata($rawBody);
        }
        $conflictingYencFields = [];
        $framingEvidenceIncomplete = ($yencFramingMetadata === null && $nativeBody !== null)
            ? $nativeBody['framing_incomplete']
            : self::yencFramingEvidenceIncomplete($yencFramingMetadata, $rawBody);
        $framingLines = is_array($yencFramingMetadata['lines'] ?? null) ? $yencFramingMetadata['lines'] : [];
        foreach ($framingLines as $controlLine) {
            if (! is_array($controlLine) || ! is_string($controlLine['kind'] ?? null)) {
                continue;
            }
            $duplicateFieldNames = is_array($controlLine['conflicting_duplicate_field_names'] ?? null)
                ? $controlLine['conflicting_duplicate_field_names'] : [];
            foreach ($duplicateFieldNames as $fieldName) {
                if (is_string($fieldName)) {
                    $conflictingYencFields[] = $controlLine['kind'].'.'.$fieldName;
                }
            }
        }
        $conflictingYencFields = array_values(array_unique($conflictingYencFields));
        $rawHeaderBytes = array_sum(array_map(static fn (string $line): int => str_ends_with($line, "\n") ? strlen($line) : strlen($line) + 2, $rawHeaders));
        $yenc = null;
        $decodedHash = null;
        $decodedSize = null;
        $payloadHash = null;
        $payloadLineLengths = [];
        $decodedPrefix = null;
        $formatMetadata = null;
        $decodedBytes = null;
        $hasFrame = $nativeBody !== null ? $nativeBody['has_frame'] : (($frame = ArticleFrame::parse($rawBody)) !== null);
        if ($hasFrame) {
            if ($nativeBody !== null) {
                $frameHeader = $nativeBody['header'];
                $framePart = $nativeBody['part'];
                $frameTrailer = $nativeBody['trailer'];
                $payloadEscapeStateValid = $nativeBody['payload_escape_state_valid'];
                $payloadHash = $nativeBody['payload_sha256'] !== ''
                    ? $nativeBody['payload_sha256']
                    : hash('sha256', substr($rawBody, $nativeBody['payload_offset'], $nativeBody['payload_length']));
                if ($nativeBody['has_decoded_bytes']) {
                    $decodedData = $nativeBody['decoded_bytes'];
                    $decodedHash = $nativeBody['decoded_sha256'] !== ''
                        ? $nativeBody['decoded_sha256']
                        : hash('sha256', $decodedData);
                    $observedCrc = $nativeBody['decoded_crc32'];
                    $fallbackSignatures = self::formatSignaturesFromMask($nativeBody['format_signatures_mask']);
                } else {
                    $decoded = $this->yenc->decodeWithFrame($rawBody);
                    $decodedData = $decoded[0];
                    $decodedHash = hash('sha256', $decodedData);
                    $observedCrc = hash('crc32b', $decodedData);
                    $fallbackSignatures = null;
                }
                $payloadLineCount = $nativeBody['payload_line_count'];
                $payloadLineMin = $nativeBody['payload_line_min'];
                $payloadLineMax = $nativeBody['payload_line_max'];
                $controlLines = $nativeBody['control_lines'];
                $escapeSequenceCount = $nativeBody['escape_sequence_count'];
            } else {
                $frameHeader = $frame->header;
                $framePart = $frame->part;
                $frameTrailer = $frame->trailer;
                $decoded = $this->yenc->decodeWithFrame($rawBody);
                $decodedData = $decoded[0];
                $payload = substr($rawBody, $frame->payloadOffset, $frame->payloadLength);
                $payloadEscapeStateValid = self::payloadEscapeStateValid($payload);
                $payloadHash = hash('sha256', $payload);
                $decodedHash = hash('sha256', $decodedData);
                $observedCrc = hash('crc32b', $decodedData);
                $fallbackSignatures = null;
                foreach (preg_split('/\r?\n/', rtrim($payload, "\r\n")) ?: [] as $line) {
                    $payloadLineLengths[] = strlen($line);
                }
                $payloadLineCount = count($payloadLineLengths);
                $payloadLineMin = $payloadLineLengths === [] ? null : min($payloadLineLengths);
                $payloadLineMax = $payloadLineLengths === [] ? null : max($payloadLineLengths);
                $controlLines = [];
                preg_match_all('/^(?:=ybegin|=ypart|=yend)[^\r\n]*/mi', $rawBody, $controlMatches);
                $controlLines = $controlMatches[0] ?? [];
                $escapeSequenceCount = substr_count($payload, '=');
            }
            $decodedBytes = $payloadEscapeStateValid ? $decodedData : null;
            $decodedSize = strlen($decodedData);
            $decodedPrefix = substr($decodedData, 0, 65_536);
            $formatMetadata = (new NativeMetadataParser)->parseFormats($decodedData) ?? [
                'parser_engine' => 'php-signature-fallback',
                'signatures' => $fallbackSignatures ?? self::detectFormatSignatures($decodedPrefix),
                'headers' => [],
            ];
            if (extension_loaded('fileinfo')) {
                $fileInfo = new \finfo(FILEINFO_NONE);
                $formatMetadata['magic_file_description'] = $fileInfo->buffer($decodedPrefix) ?: null;
                $formatMetadata['mime_type'] = (new \finfo(FILEINFO_MIME_TYPE))->buffer($decodedPrefix) ?: null;
                $formatMetadata['magic_basis'] = 'first 65536 decoded bytes; heuristic identification only';
            }
            $rangeFieldsConflict = $framingEvidenceIncomplete || in_array('ypart.begin', $conflictingYencFields, true)
                || in_array('ypart.end', $conflictingYencFields, true);
            $headerSizeConflict = $framingEvidenceIncomplete || in_array('ybegin.size', $conflictingYencFields, true);
            $trailerSizeConflict = $framingEvidenceIncomplete || in_array('yend.size', $conflictingYencFields, true);
            $rangeStart = ! $framingEvidenceIncomplete && ! in_array('ypart.begin', $conflictingYencFields, true) && isset($framePart['begin'])
                ? self::metadataNumber($framePart['begin']) : null;
            $rangeEnd = ! $framingEvidenceIncomplete && ! in_array('ypart.end', $conflictingYencFields, true) && isset($framePart['end'])
                ? self::metadataNumber($framePart['end']) : null;
            $headerExpectedDecodedSize = ! $headerSizeConflict && isset($frameHeader['size'])
                ? self::metadataNumber($frameHeader['size']) : null;
            $hasRangeField = isset($framePart['begin']) || isset($framePart['end']);
            $rangeIsValid = ! $hasRangeField || $rangeFieldsConflict || $headerSizeConflict ? null : ($rangeStart !== null && $rangeEnd !== null
                && $rangeStart >= 1 && $rangeEnd >= $rangeStart
                && (! isset($frameHeader['size']) || ($headerExpectedDecodedSize !== null
                    && $rangeEnd <= $headerExpectedDecodedSize)));
            $ypartExpectedDecodedSize = $rangeIsValid === true ? $rangeEnd - $rangeStart + 1 : null;
            $trailerExpectedDecodedSize = ! $trailerSizeConflict && isset($frameTrailer['size'])
                ? self::metadataNumber($frameTrailer['size']) : null;
            $trailerSizeIsDecimal = $trailerSizeConflict ? null : (! isset($frameTrailer['size']) || ctype_digit($frameTrailer['size']));
            $trailerNumericFieldsValid = array_intersect(
                ['yend.size', 'yend.part', 'yend.total'],
                $conflictingYencFields,
            ) === [];
            $trailerNumericFieldsRangeValid = true;
            foreach (['size', 'part', 'total'] as $numericField) {
                if (! isset($frameTrailer[$numericField])) {
                    continue;
                }
                $number = self::metadataNumber($frameTrailer[$numericField]);
                if ($number === null) {
                    $trailerNumericFieldsRangeValid = false;
                }
                if ($number === null || (in_array($numericField, ['part', 'total'], true) && $number < 1)) {
                    $trailerNumericFieldsValid = false;
                }
            }
            $trailerPartNumber = isset($frameTrailer['part'])
                ? self::metadataNumber($frameTrailer['part']) : null;
            $trailerTotalNumber = isset($frameTrailer['total'])
                ? self::metadataNumber($frameTrailer['total']) : null;
            $headerPartNumber = isset($frameHeader['part'])
                ? self::metadataNumber($frameHeader['part']) : null;
            $headerTotalNumber = isset($frameHeader['total'])
                ? self::metadataNumber($frameHeader['total']) : null;
            $trailerPartMatchesHeader = null;
            if (isset($frameTrailer['part'], $frameHeader['part'])
                && ! $framingEvidenceIncomplete
                && ! in_array('yend.part', $conflictingYencFields, true)
                && ! in_array('ybegin.part', $conflictingYencFields, true)) {
                $trailerPartMatchesHeader = $trailerPartNumber !== null && $headerPartNumber !== null
                    ? $trailerPartNumber === $headerPartNumber : false;
            }
            $trailerTotalMatchesHeader = null;
            if (isset($frameTrailer['total'], $frameHeader['total'])
                && ! $framingEvidenceIncomplete
                && ! in_array('yend.total', $conflictingYencFields, true)
                && ! in_array('ybegin.total', $conflictingYencFields, true)) {
                $trailerTotalMatchesHeader = $trailerTotalNumber !== null && $headerTotalNumber !== null
                    ? $trailerTotalNumber === $headerTotalNumber : false;
            }
            $headerNumericFieldsValid = array_intersect(
                ['ybegin.line', 'ybegin.size', 'ybegin.part', 'ybegin.total'],
                $conflictingYencFields,
            ) === [];
            $headerNumericFieldsRangeValid = true;
            foreach (['line', 'size', 'part', 'total'] as $numericField) {
                if (! isset($frameHeader[$numericField])) {
                    continue;
                }
                $number = self::metadataNumber($frameHeader[$numericField]);
                if ($number === null) {
                    $headerNumericFieldsRangeValid = false;
                }
                if ($number === null || $number < 1) {
                    $headerNumericFieldsValid = false;
                }
            }
            $multipart = isset($frameHeader['part']) || isset($frameTrailer['part']) || $framePart !== null;
            if (($multipart && ($framePart === null || ! isset($frameHeader['part'])))
                || (isset($frameHeader['part'], $frameHeader['total'])
                    && $headerPartNumber !== null && $headerTotalNumber !== null
                    && $headerPartNumber > $headerTotalNumber)) {
                $headerNumericFieldsValid = false;
            }
            $multipartStructureAmbiguous = $framingEvidenceIncomplete || array_intersect(
                ['ybegin.line', 'ybegin.size', 'ybegin.part', 'ybegin.total', 'ypart.begin', 'ypart.end',
                    'yend.size', 'yend.part', 'yend.total', 'yend.pcrc32'],
                $conflictingYencFields,
            ) !== [];
            // Conservative metadata admission matches the native parser's eight-digit CRC policy.
            // Decoding and the separate observed CRC comparison retain their compatibility rules.
            $multipartPcrc32Status = ! $multipart ? 'not-required-single-part'
                : (($framingEvidenceIncomplete || in_array('yend.pcrc32', $conflictingYencFields, true)) ? 'ambiguous'
                    : (! isset($frameTrailer['pcrc32']) ? 'missing'
                        : (preg_match('/\A[0-9a-f]{8}\z/i', $frameTrailer['pcrc32']) === 1 ? 'present-hex' : 'malformed')));
            $multipartRequiredFieldsMissing = $multipart && ($framePart === null
                || ! isset($frameHeader['part'], $frameHeader['size'], $frameTrailer['part'], $frameTrailer['size'])
                || $multipartPcrc32Status === 'missing');
            $multipartStructureValid = ! $multipartStructureAmbiguous && (! $multipart || ($framePart !== null
                && ! $multipartRequiredFieldsMissing && $multipartPcrc32Status === 'present-hex'
                && $headerPartNumber !== null && $headerPartNumber >= 1
                && $rangeIsValid === true && $headerNumericFieldsValid && $trailerNumericFieldsValid
                && $trailerPartMatchesHeader === true && $trailerTotalMatchesHeader !== false));
            $multipartStructureStatus = $multipartStructureAmbiguous ? 'ambiguous'
                : (! $multipart ? 'single-part'
                    : ((! $trailerNumericFieldsValid || $multipartPcrc32Status === 'malformed') ? 'invalid'
                        : ($multipartRequiredFieldsMissing ? 'incomplete'
                            : ($multipartStructureValid ? 'complete' : 'invalid'))));
            $expectedDecodedSize = $multipart ? $ypartExpectedDecodedSize : $headerExpectedDecodedSize;
            $crcField = $multipart ? 'pcrc32' : (isset($frameTrailer['crc32']) ? 'crc32' : 'pcrc32');
            $crcFieldConflict = $framingEvidenceIncomplete || in_array('yend.'.$crcField, $conflictingYencFields, true);
            $expectedCrc = $crcFieldConflict ? null : ($frameTrailer[$crcField] ?? null);
            $criticalYencFields = [
                'ybegin.name', 'ybegin.line', 'ybegin.size', 'ybegin.part', 'ybegin.total',
                'ypart.begin', 'ypart.end', 'yend.size', 'yend.part', 'yend.total', 'yend.pcrc32', 'yend.crc32',
            ];
            $integrityChecksAmbiguous = $framingEvidenceIncomplete || array_intersect($criticalYencFields, $conflictingYencFields) !== [];
            $allDeclaredCrc32FieldsAreHex = true;
            foreach (['pcrc32', 'crc32'] as $declaredCrcField) {
                if (isset($frameTrailer[$declaredCrcField])
                    && (! is_string($frameTrailer[$declaredCrcField])
                        || preg_match('/\A[0-9a-f]{1,8}\z/i', $frameTrailer[$declaredCrcField]) !== 1)) {
                    $allDeclaredCrc32FieldsAreHex = false;
                }
            }
            $expectedCrcIsHex = is_string($expectedCrc) && preg_match('/\A[0-9a-f]{1,8}\z/i', $expectedCrc) === 1;
            $observedDeclaredCrcMatches = $expectedCrcIsHex
                ? strcasecmp(str_pad($expectedCrc, 8, '0', STR_PAD_LEFT), $observedCrc) === 0 : null;
            $knownHeaderFields = ['line', 'size', 'part', 'total', 'name'];
            $knownPartFields = ['begin', 'end'];
            $knownTrailerFields = ['size', 'part', 'total', 'pcrc32', 'crc32'];
            $par2PrefixLength = min($headerExpectedDecodedSize ?? 16_384, 16_384);
            $prefixCoverageIsValid = $multipartStructureValid && ! $integrityChecksAmbiguous && $payloadEscapeStateValid && $par2PrefixLength > 0
                && (! $multipart || $observedDeclaredCrcMatches === true)
                && $decodedSize >= $par2PrefixLength
                && (! $multipart
                    ? $headerExpectedDecodedSize === null || $decodedSize === $headerExpectedDecodedSize
                    : $rangeIsValid === true && $rangeStart === 1 && $rangeEnd >= $par2PrefixLength
                        && $decodedSize === $ypartExpectedDecodedSize);
            $par2PrefixMd5Candidate = $prefixCoverageIsValid
                ? hash('md5', substr($decodedData, 0, $par2PrefixLength)) : null;
            $yenc = [
                'payload_escape_state_valid' => $payloadEscapeStateValid,
                'payload_integrity_status' => $payloadEscapeStateValid ? 'escape_state_valid' : 'invalid_unmatched_escape',
                'name' => $frameHeader['name'] ?? null,
                'file_size' => $headerExpectedDecodedSize,
                'line_length' => isset($frameHeader['line']) ? self::metadataNumber($frameHeader['line']) : null,
                'part' => $headerPartNumber,
                'multipart' => $multipart,
                'multipart_structure_status' => $multipartStructureStatus,
                'multipart_pcrc32_status' => $multipartPcrc32Status,
                'total_parts' => $headerTotalNumber,
                'header_numeric_fields_valid' => $headerNumericFieldsValid,
                'conflicting_control_fields' => $conflictingYencFields,
                'integrity_checks_ambiguous' => $integrityChecksAmbiguous,
                'native_framing_evidence_status' => $yencFramingMetadata === null ? 'unavailable'
                    : ($framingEvidenceIncomplete ? 'incomplete_or_ambiguous' : 'occurrence_scan_complete'),
                'fallback_framing_evidence_status' => $yencFramingMetadata !== null ? null
                    : ($framingEvidenceIncomplete ? 'incomplete_or_ambiguous' : 'occurrence_scan_complete'),
                'header_numeric_fields_representable' => $headerNumericFieldsRangeValid,
                'begin' => $rangeStart,
                'end' => $rangeEnd,
                'ypart_range_valid' => $rangeIsValid,
                'ypart_range_decoded_size' => $ypartExpectedDecodedSize,
                'trailer_size' => $trailerExpectedDecodedSize,
                'trailer_numeric_fields_valid' => $trailerNumericFieldsValid,
                'trailer_numeric_fields_representable' => $trailerNumericFieldsRangeValid,
                'trailer_part_matches_header' => $trailerPartMatchesHeader,
                'trailer_total_matches_header' => $trailerTotalMatchesHeader,
                'header_size' => $headerExpectedDecodedSize,
                'decoded_size_matches_header_size' => $headerExpectedDecodedSize === null ? null
                    : ($multipart ? ($rangeEnd !== null && $rangeEnd <= $headerExpectedDecodedSize) : $decodedSize === $headerExpectedDecodedSize),
                'trailer_size_is_decimal' => $trailerSizeIsDecimal,
                'pcrc32' => $frameTrailer['pcrc32'] ?? null,
                'crc32' => $frameTrailer['crc32'] ?? null,
                'header_fields' => $frameHeader,
                'part_fields' => $framePart,
                'trailer_fields' => $frameTrailer,
                'decoded_segment_size' => $decodedSize,
                'decoded_segment_sha256' => $decodedHash,
                'par2_first_16k_md5_candidate' => $par2PrefixMd5Candidate,
                'encoded_payload_sha256' => $payloadHash,
                'payload_line_count' => $payloadLineCount,
                'payload_line_length_min' => $payloadLineMin,
                'payload_line_length_max' => $payloadLineMax,
                'decoded_prefix_format_signatures' => $formatMetadata['signatures'] ?? [],
                'raw_control_lines' => $controlLines,
                'header_field_order' => array_keys($frameHeader),
                'part_field_order' => array_keys($framePart ?? []),
                'trailer_field_order' => array_keys($frameTrailer),
                'unknown_header_fields' => array_diff_key($frameHeader, array_flip($knownHeaderFields)),
                'unknown_part_fields' => array_diff_key($framePart ?? [], array_flip($knownPartFields)),
                'unknown_trailer_fields' => array_diff_key($frameTrailer, array_flip($knownTrailerFields)),
                'expected_decoded_segment_size' => $expectedDecodedSize,
                'decoded_size_matches_yenc' => $expectedDecodedSize === null ? null : $expectedDecodedSize === $decodedSize,
                'decoded_size_matches_ypart_range' => $ypartExpectedDecodedSize === null ? null : $ypartExpectedDecodedSize === $decodedSize,
                'decoded_size_matches_yend_size' => $trailerExpectedDecodedSize === null ? null : $trailerExpectedDecodedSize === $decodedSize,
                'observed_decoded_crc32' => strtoupper($observedCrc),
                'segment_crc32_basis' => $crcFieldConflict ? 'unavailable-conflicting-yenc-field'
                    : (! $multipartStructureValid ? 'unavailable-multipart-structure'
                        : ($multipart ? ($expectedCrc === null ? 'missing-pcrc32' : 'pcrc32') : ($expectedCrc === null ? 'missing-crc32' : $crcField))),
                'observed_crc32_declaration_field' => $crcFieldConflict ? null : $crcField,
                'observed_crc32_matches_selected_declaration' => $observedDeclaredCrcMatches,
                'observed_crc32_comparison_basis' => 'Unambiguous selected declared CRC value versus lenient decoded bytes; does not establish file bounds or framing validity.',
                'declared_crc32_is_hex' => is_string($expectedCrc) ? $expectedCrcIsHex : null,
                'all_declared_crc32_fields_are_hex' => $allDeclaredCrc32FieldsAreHex,
                'declared_crc32_matches' => $multipartStructureValid && $observedDeclaredCrcMatches !== null
                    ? $payloadEscapeStateValid && $observedDeclaredCrcMatches : null,
                'escape_sequence_count' => $escapeSequenceCount,
            ];
        }

        $software = [];
        foreach (['user-agent', 'x-newsreader', 'x-poster'] as $headerName) {
            $value = $values[$headerName] ?? '';
            foreach (['Nyuu', 'ngPost', 'Postie'] as $candidate) {
                if (stripos($value, $candidate) !== false) {
                    $software[$candidate] = ['header' => $headerName, 'value' => $value];
                }
            }
        }

        return [
            'metadata' => [
                'raw_headers' => array_values($rawHeaders),
                'headers' => $values,
                'header_profile' => ['ordered_names' => $orderedNames, 'ordered_names_sha256' => hash('sha256', implode("\n", $orderedNames))],
                'nntp_header_metadata' => $nntpHeaderMetadata ?? [
                    'parser_engine' => null,
                    'schema_version' => NativeMetadataParser::NNTP_HEADER_SCHEMA_VERSION,
                    'status' => 'native_parser_unavailable_or_input_over_budget',
                    'field_count' => null,
                    'fields' => [],
                ],
                'nntp_body_metadata' => $nntpBodyMetadata,
                'nntp_lines_body_relationship' => $nntpLinesBodyRelationship ?? [
                    'parser_engine' => null,
                    'schema_version' => 1,
                    'status' => 'native_parser_unavailable_or_input_over_budget',
                    'matches' => null,
                ],
                'retrieval_measurements' => [
                    'raw_header_line_count' => count($rawHeaders),
                    'raw_header_bytes_including_crlf' => $rawHeaderBytes,
                    'body_bytes_after_nntp_dot_unstuffing' => strlen($rawBody),
                    'body_line_count' => $nntpBodyMetadata['body_line_count'] ?? null,
                    'body_line_framing_status' => $nntpBodyMetadata['status'] ?? 'unavailable',
                    'article_bytes_after_dot_unstuffing' => $rawHeaderBytes + 2 + strlen($rawBody),
                    'measurement_basis' => 'Collector-side bytes after NNTP dot-unstuffing; excludes TCP/TLS, NNTP status lines, and any wire-level compression.',
                ],
                'yenc' => $yenc,
                'yenc_framing_metadata' => $yencFramingMetadata,
                'decoded_format_metadata' => $formatMetadata,
                'article_body_sha256' => ($nativeBody !== null && $nativeBody['body_sha256'] !== '')
                    ? $nativeBody['body_sha256']
                    : hash('sha256', $rawBody),
                'uploader_software_candidates' => $software,
            ],
            'decoded_bytes' => $decodedBytes,
        ];
    }

    private static function boundedDecimalInt(string $value): ?int
    {
        if (preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return 0;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    private static function payloadEscapeStateValid(string $payload): bool
    {
        $escapeCount = 0;
        for ($offset = strlen($payload) - 1; $offset >= 0; $offset--) {
            if ($payload[$offset] === '=') {
                $escapeCount++;
            } elseif ($payload[$offset] !== "\r" && $payload[$offset] !== "\n") {
                break;
            }
        }

        return ($escapeCount % 2) === 0;
    }

    private static function checkedAdd(?int $left, ?int $right): ?int
    {
        return $left === null || $right === null || $left > PHP_INT_MAX - $right ? null : $left + $right;
    }

    private static function metadataNumber(string $value): ?int
    {
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }
        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return 0;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    public static function fileKind(string $subject): string
    {
        if (preg_match('/\.par2\b/i', $subject) === 1) {
            return 'par2';
        }
        if (preg_match('/\.(?:part\d+\.rar|rar|r\d{2,})\b/i', $subject) === 1) {
            return 'rar';
        }

        return 'other';
    }

    public static function fileKindNative(string $subject): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $code = $ffi->nntmux_nzb_file_kind($subject, strlen($subject));
        if (! is_int($code) || $code < 0) {
            return null;
        }

        return match ($code) {
            2 => 'par2',
            1 => 'rar',
            default => 'other',
        };
    }

    public static function formatHint(string $subject): string
    {
        if (preg_match('/\.([a-z0-9]{1,8})\b/i', $subject, $matches) !== 1) {
            return 'unknown';
        }

        return strtolower($matches[1]);
    }

    public static function formatHintNative(string $subject): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 16, $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_format_hint($subject, strlen($subject), $scratch, $cap);
        if (! is_int($written) || $written < 0) {
            return null;
        }

        return $written === 0 ? 'unknown' : FFI::string($scratch, $written);
    }

    /** @return array<string, mixed> */
    private static function fallbackNntpBodyMetadata(string $body): array
    {
        $native = self::analyzeArticleBodyNative($body, 1);
        if ($native !== null) {
            return $native['nntp_body_metadata'];
        }

        $crlfLineBreakCount = substr_count($body, "\r\n");
        $lfLineBreakCount = substr_count($body, "\n");
        preg_match_all('/(?<!\r)\n/', $body, $bareLfMatches);
        preg_match_all('/\r(?!\n)/', $body, $bareCrMatches);
        $bareLfCount = count($bareLfMatches[0]);
        $bareCrCount = count($bareCrMatches[0]);
        $finalLineTerminated = $body === '' || str_ends_with($body, "\r\n");
        $lastLf = strrpos($body, "\n");
        $unterminatedTailByteCount = $lastLf === false ? strlen($body) : strlen($body) - $lastLf - 1;
        $completeCrlfFraming = $bareLfCount === 0 && $bareCrCount === 0 && $finalLineTerminated;
        $status = $body === '' ? 'empty'
            : (($bareLfCount > 0 || $bareCrCount > 0) ? 'non_crlf_line_endings'
                : (! $finalLineTerminated ? 'unterminated_final_line' : 'complete_crlf'));

        return [
            'parser_engine' => 'php-fallback',
            'schema_version' => 1,
            'status' => $status,
            'body_octets_after_dot_unstuffing' => strlen($body),
            'crlf_line_break_count' => $crlfLineBreakCount,
            'lf_line_break_count' => $lfLineBreakCount,
            'bare_lf_count' => $bareLfCount,
            'bare_cr_count' => $bareCrCount,
            'nul_byte_count' => substr_count($body, "\0"),
            'body_line_count' => $completeCrlfFraming ? $crlfLineBreakCount : null,
            'observed_lf_delimited_line_count' => $lfLineBreakCount + (int) ($body !== '' && ! str_ends_with($body, "\n")),
            'unterminated_tail_byte_count' => $unterminatedTailByteCount,
            'final_line_terminated' => $finalLineTerminated,
            'measurement_basis' => 'Raw NNTP BODY octets after one dot-unstuffing pass; excludes the NNTP dot terminator.',
        ];
    }

    /** @param array<string, mixed>|null $metadata */
    private static function yencFramingEvidenceIncomplete(?array $metadata, string $body = ''): bool
    {
        if ($metadata === null) {
            return self::fallbackYencFramingEvidenceIncomplete($body);
        }
        if (($metadata['frames_truncated'] ?? false) === true
            || ($metadata['control_lines_truncated'] ?? false) === true
            || ! is_array($metadata['lines'] ?? null)
            || ! is_array($metadata['frames'] ?? null)
            || count($metadata['frames']) !== 1) {
            return true;
        }
        foreach ($metadata['lines'] as $line) {
            if (! is_array($line) || ($line['field_occurrences_truncated'] ?? false) === true
                || ! is_int($line['frame_index'] ?? null)
                || $line['frame_index'] !== ($metadata['frames'][0]['frame_index'] ?? null)) {
                return true;
            }
        }
        foreach ($metadata['frames'] as $frame) {
            if (! is_array($frame) || ($frame['size_relationship_status'] ?? null) === 'ambiguous') {
                return true;
            }
        }

        return false;
    }

    private static function fallbackYencFramingEvidenceIncomplete(string $body): bool
    {
        if (strlen($body) > 2_000_000) {
            return true;
        }
        $native = self::analyzeArticleBodyNative($body, 2);
        if ($native !== null) {
            return $native['framing_incomplete'];
        }
        $counts = ['ybegin' => 0, 'ypart' => 0, 'yend' => 0];
        $critical = [
            'ybegin' => ['name', 'line', 'size', 'part', 'total'],
            'ypart' => ['begin', 'end'],
            'yend' => ['size', 'part', 'total', 'pcrc32', 'crc32'],
        ];
        $offset = 0;
        $bodyLength = strlen($body);
        while ($offset < $bodyLength) {
            $newline = strpos($body, "\n", $offset);
            $end = $newline === false ? $bodyLength : $newline;
            $prefix = substr($body, $offset, min(8, $end - $offset));
            if (preg_match('/\A=(ybegin|ypart|yend)(?:[ \t\r]|$)/i', $prefix, $kindMatch) === 1) {
                $kind = strtolower($kindMatch[1]);
                $counts[$kind]++;
                if ($counts[$kind] > 1 || $end - $offset > 16_384) {
                    return true;
                }
                if (($kind === 'ypart' && ($counts['ybegin'] !== 1 || $counts['yend'] !== 0))
                    || ($kind === 'yend' && $counts['ybegin'] !== 1)) {
                    return true;
                }
                $line = rtrim(substr($body, $offset + strlen($kind) + 1, $end - $offset - strlen($kind) - 1), "\r");
                $fields = [];
                $fieldOffset = 0;
                $fieldCount = 0;
                while (preg_match('/(?:^|[ \t])([a-z][a-z0-9_-]*)=([^ \t]*)/i', $line, $match, PREG_OFFSET_CAPTURE, $fieldOffset) === 1) {
                    if (++$fieldCount > 64 || strlen($match[1][0]) > 128) {
                        return true;
                    }
                    $name = strtolower($match[1][0]);
                    $value = $match[2][0];
                    if ($kind === 'ybegin' && $name === 'name') {
                        break; // The filename consumes the remainder, including spaces.
                    }
                    if (in_array($name, $critical[$kind], true)) {
                        if (isset($fields[$name]) && $fields[$name] !== $value) {
                            return true;
                        }
                        $fields[$name] = $value;
                    }
                    $fieldOffset = $match[0][1] + strlen($match[0][0]);
                }
            }
            $offset = $newline === false ? $bodyLength : $newline + 1;
        }

        return $counts['ybegin'] !== 1 || $counts['yend'] !== 1;
    }

    /** @return list<array{name: string, offset: int, basis: string}> */
    private static function detectFormatSignatures(string $bytes): array
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_nzb_detect_format_signatures($bytes, strlen($bytes));
            if (is_int($mask) && $mask >= 0) {
                return self::formatSignaturesFromMask($mask);
            }
        }

        $signatures = [];
        foreach ([
            ['name' => 'RAR 5', 'signature' => "Rar!\x1A\x07\x01\x00", 'offset' => 0],
            ['name' => 'RAR 4', 'signature' => "Rar!\x1A\x07\x00", 'offset' => 0],
            ['name' => '7-Zip', 'signature' => "7z\xBC\xAF\x27\x1C", 'offset' => 0],
            ['name' => 'ZIP', 'signature' => "PK\x03\x04", 'offset' => 0],
            ['name' => 'gzip', 'signature' => "\x1F\x8B\x08", 'offset' => 0],
            ['name' => 'XZ', 'signature' => "\xFD7zXZ\x00", 'offset' => 0],
            ['name' => 'ISO 9660 descriptor', 'signature' => 'CD001', 'offset' => 32_769],
        ] as $candidate) {
            $offset = $candidate['offset'];
            if (strlen($bytes) >= $offset + strlen($candidate['signature'])
                && substr($bytes, $offset, strlen($candidate['signature'])) === $candidate['signature']) {
                $signatures[] = ['name' => $candidate['name'], 'offset' => $offset, 'basis' => 'decoded first-segment prefix signature'];
            }
        }

        return $signatures;
    }

    /** @return list<array{name: string, offset: int, basis: string}> */
    private static function formatSignaturesFromMask(int $mask): array
    {
        if ($mask === 0) {
            return [];
        }
        $signatures = [];
        foreach ([
            [1, 'RAR 5', 0],
            [2, 'RAR 4', 0],
            [4, '7-Zip', 0],
            [8, 'ZIP', 0],
            [16, 'gzip', 0],
            [32, 'XZ', 0],
            [64, 'ISO 9660 descriptor', 32_769],
        ] as [$bit, $name, $offset]) {
            if (($mask & $bit) !== 0) {
                $signatures[] = ['name' => $name, 'offset' => $offset, 'basis' => 'decoded first-segment prefix signature'];
            }
        }

        return $signatures;
    }

    /** @return array<string, mixed>|null */
    private static function analyzeManifestNative(string $xml): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $xmlLen = strlen($xml);
        if ($xmlLen === 0) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max($xmlLen * 2, 16_384), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_analyze_manifest($xml, $xmlLen, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_analyze_manifest($xml, $xmlLen, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }

        $tokens = explode("\0", FFI::string($scratch, $written));
        $pos = 0;
        $metaGroupCount = (int) ($tokens[$pos++] ?? 0);
        $meta = [];
        for ($m = 0; $m < $metaGroupCount; $m++) {
            $type = $tokens[$pos++] ?? '';
            $valCount = (int) ($tokens[$pos++] ?? 0);
            $vals = [];
            for ($v = 0; $v < $valCount; $v++) {
                $vals[] = $tokens[$pos++] ?? '';
            }
            $meta[$type] = $vals;
        }

        $fileCount = (int) ($tokens[$pos++] ?? 0);
        $totals = [];
        foreach (['rar', 'par2', 'other'] as $kindKey) {
            $fc = (int) ($tokens[$pos++] ?? 0);
            $sc = (int) ($tokens[$pos++] ?? 0);
            $abStr = $tokens[$pos++] ?? '';
            $abo = ($tokens[$pos++] ?? '0') === '1';
            $totals[$kindKey] = [
                'file_count' => $fc,
                'segment_count' => $sc,
                'advertised_bytes' => $abStr === '' ? null : (int) $abStr,
                'advertised_bytes_overflow' => $abo,
            ];
        }
        $advertisedByteTotalsValid = ($tokens[$pos++] ?? '0') === '1';

        $files = [];
        for ($fileIndex = 0; $fileIndex < $fileCount; $fileIndex++) {
            $kind = $tokens[$pos++] ?? 'other';
            $formatHint = $tokens[$pos++] ?? 'unknown';
            $subject = $tokens[$pos++] ?? '';
            $poster = $tokens[$pos++] ?? '';
            $dateStr = $tokens[$pos++] ?? '';
            $sourceDate = $tokens[$pos++] ?? '';
            $groupCount = (int) ($tokens[$pos++] ?? 0);
            $groups = [];
            for ($g = 0; $g < $groupCount; $g++) {
                $groups[] = $tokens[$pos++] ?? '';
            }
            $segCount = (int) ($tokens[$pos++] ?? 0);
            $sourceSegCount = (int) ($tokens[$pos++] ?? 0);
            $invalidSegCount = (int) ($tokens[$pos++] ?? 0);
            $invalidMetaCount = (int) ($tokens[$pos++] ?? 0);
            $dupMsgIdCount = (int) ($tokens[$pos++] ?? 0);
            $dupSegNumCount = (int) ($tokens[$pos++] ?? 0);
            $seqValid = ($tokens[$pos++] ?? '0') === '1';
            $advBytesStr = $tokens[$pos++] ?? '';
            $advOverflow = ($tokens[$pos++] ?? '0') === '1';
            $segments = [];
            for ($s = 0; $s < $segCount; $s++) {
                $num = (int) ($tokens[$pos++] ?? 0);
                $bytesStr = $tokens[$pos++] ?? '';
                $srcNum = $tokens[$pos++] ?? '';
                $srcBytes = $tokens[$pos++] ?? '';
                $msgId = $tokens[$pos++] ?? '';
                $segments[] = [
                    'number' => $num,
                    'bytes' => $bytesStr === '' ? null : (int) $bytesStr,
                    'source_number' => $srcNum,
                    'source_bytes' => $srcBytes,
                    'messageid' => $msgId,
                ];
            }
            $files[] = [
                'index' => $fileIndex,
                'kind' => $kind,
                'format_hint' => $formatHint,
                'subject' => $subject,
                'poster' => $poster,
                'date' => $dateStr === '' ? null : (int) $dateStr,
                'source_date' => $sourceDate,
                'groups' => $groups,
                'segment_count' => $segCount,
                'source_segment_record_count' => $sourceSegCount,
                'invalid_segment_record_count' => $invalidSegCount,
                'invalid_segment_metadata_count' => $invalidMetaCount,
                'duplicate_message_id_count' => $dupMsgIdCount,
                'duplicate_segment_number_count' => $dupSegNumCount,
                'segment_number_sequence_valid' => $seqValid,
                'advertised_bytes' => $advBytesStr === '' ? null : (int) $advBytesStr,
                'advertised_bytes_overflow' => $advOverflow,
                'segments' => $segments,
            ];
        }

        $par2ToRarPercent = is_int($totals['rar']['advertised_bytes']) && $totals['rar']['advertised_bytes'] > 0
            && is_int($totals['par2']['advertised_bytes'])
            ? round($totals['par2']['advertised_bytes'] / $totals['rar']['advertised_bytes'] * 100, 3)
            : null;

        return [
            'file_count' => $fileCount,
            'files' => $files,
            'totals' => $totals,
            'advertised_byte_totals_valid' => $advertisedByteTotalsValid,
            'nzb_meta_values' => $meta,
            'par2_to_rar_advertised_percent' => $par2ToRarPercent,
            'par2_to_rar_percent_basis' => 'NZB advertised segment bytes; includes article and yEnc overhead and is not the PAR2 recovery percentage.',
        ];
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return array{raw_header_bytes_including_crlf: int, ordered_names: list<string>, headers: array<string, string>, uploader_software_candidates: array<string, array{header: string, value: string}>}|null
     */
    public static function unfoldHeadersNative(array $rawHeaders): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $count = count($rawHeaders);
        $packed = implode("\0", $rawHeaders);
        $packedLen = strlen($packed);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max($packedLen * 2 + 512, 4096), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_unfold_headers($packed, $packedLen, $count, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_unfold_headers($packed, $packedLen, $count, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }

        $tokens = explode("\0", FFI::string($scratch, $written));
        $rawHeaderBytes = (int) $tokens[0];
        $orderedCount = (int) ($tokens[1] ?? 0);
        $pos = 2;
        $orderedNames = [];
        for ($i = 0; $i < $orderedCount; $i++) {
            $orderedNames[] = $tokens[$pos++] ?? '';
        }
        $valueCount = (int) ($tokens[$pos++] ?? 0);
        $values = [];
        for ($i = 0; $i < $valueCount; $i++) {
            $k = $tokens[$pos++] ?? '';
            $v = $tokens[$pos++] ?? '';
            $values[$k] = $v;
        }
        $swCount = (int) ($tokens[$pos++] ?? 0);
        $software = [];
        for ($i = 0; $i < $swCount; $i++) {
            $cand = $tokens[$pos++] ?? '';
            $hdr = $tokens[$pos++] ?? '';
            $val = $tokens[$pos++] ?? '';
            $software[$cand] = ['header' => $hdr, 'value' => $val];
        }

        return [
            'raw_header_bytes_including_crlf' => $rawHeaderBytes,
            'ordered_names' => $orderedNames,
            'headers' => $values,
            'uploader_software_candidates' => $software,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $files
     *
     * @param-out int $omittedSampleCount
     *
     * @return list<array{file_index: int, kind: string, subject: string, segment_number: int, messageid: string, segment_bytes: int|null, sample_role: string}>|null
     */
    public static function selectArticleSamplesNative(array $files, int $maximumSamples = self::MAX_ARTICLE_SAMPLES, ?int &$omittedSampleCount = null): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $omittedSampleCount = 0;
        $maximumSamples = max(1, min(self::MAX_ARTICLE_SAMPLES, $maximumSamples));
        if ($files === []) {
            return [];
        }
        $parts = [];
        $fileCount = 0;
        foreach ($files as $file) {
            $segments = $file['segments'] ?? [];
            if (! is_array($segments)) {
                $segments = [];
            }
            $parts[] = (string) (int) ($file['index'] ?? 0);
            $parts[] = (string) ($file['kind'] ?? 'other');
            $parts[] = (string) ($file['subject'] ?? '');
            $parts[] = (string) count($segments);
            foreach ($segments as $segment) {
                $parts[] = (string) (int) ($segment['number'] ?? 0);
                $parts[] = is_int($segment['bytes'] ?? null) ? (string) $segment['bytes'] : '';
                $parts[] = (string) ($segment['messageid'] ?? '');
            }
            $fileCount++;
        }
        $packed = implode("\0", $parts);
        $packedLen = strlen($packed);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max($packedLen + 1024, 16_384), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_select_article_samples($packed, $packedLen, $fileCount, $maximumSamples, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_select_article_samples($packed, $packedLen, $fileCount, $maximumSamples, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }

        $tokens = explode("\0", FFI::string($scratch, $written));
        $omittedSampleCount = (int) $tokens[0];
        $sampleCount = (int) ($tokens[1] ?? 0);
        $pos = 2;
        $samples = [];
        for ($i = 0; $i < $sampleCount; $i++) {
            $fileIndex = (int) ($tokens[$pos++] ?? 0);
            $kind = $tokens[$pos++] ?? 'other';
            $subject = $tokens[$pos++] ?? '';
            $segNumber = (int) ($tokens[$pos++] ?? 0);
            $msgId = $tokens[$pos++] ?? '';
            $bytesStr = $tokens[$pos++] ?? '';
            $role = $tokens[$pos++] ?? 'first';
            $samples[] = [
                'file_index' => $fileIndex,
                'kind' => $kind,
                'subject' => $subject,
                'segment_number' => $segNumber,
                'messageid' => $msgId,
                'segment_bytes' => $bytesStr === '' ? null : (int) $bytesStr,
                'sample_role' => $role,
            ];
        }

        return $samples;
    }

    /**
     * @return array{
     *     nntp_body_metadata: array<string, mixed>,
     *     framing_incomplete: bool,
     *     has_frame: bool,
     *     has_part: bool,
     *     payload_escape_state_valid: bool,
     *     has_decoded_bytes: bool,
     *     payload_offset: int,
     *     payload_length: int,
     *     escape_sequence_count: int,
     *     payload_line_count: int,
     *     payload_line_min: int|null,
     *     payload_line_max: int|null,
     *     format_signatures_mask: int,
     *     decoded_crc32: string,
     *     body_sha256: string,
     *     payload_sha256: string,
     *     decoded_sha256: string,
     *     header: array<string, string>,
     *     part: array<string, string>|null,
     *     trailer: array<string, string>,
     *     control_lines: list<string>,
     *     decoded_bytes: string
     * }|null
     */
    private static function analyzeArticleBodyNative(string $body, int $flags): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $bodyLen = strlen($body);
        $cap = 0;
        $minScratch = ($flags & 12) !== 0 ? max($bodyLen + 4096, 8192) : 256;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $minScratch, $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_analyze_article_body($body, $bodyLen, $flags, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_analyze_article_body($body, $bodyLen, $flags, $scratch, $cap);
            if (! is_int($written) || $written < 76) {
                return null;
            }
        }
        if ($written < 76) {
            return null;
        }

        /** @var array<int, int>|false $hdr */
        $hdr = unpack('V19', FFI::string($scratch, 76));
        if ($hdr === false) {
            return null;
        }

        $bodyStatusCode = $hdr[1];
        $crlfCount = $hdr[2];
        $lfCount = $hdr[3];
        $bareLfCount = $hdr[4];
        $bareCrCount = $hdr[5];
        $nulCount = $hdr[6];
        $observedLfLineCount = $hdr[7];
        $unterminatedTailCount = $hdr[8];
        $flagsOut = $hdr[9];
        $payloadOffset = $hdr[10];
        $payloadLength = $hdr[11];
        $escapeSeqCount = $hdr[12];
        $payloadLineCount = $hdr[13];
        $payloadLineMin = $hdr[14];
        $payloadLineMax = $hdr[15];
        $formatMask = $hdr[16];
        $decodedCrc32 = sprintf('%08x', $hdr[17]);
        $metaLen = $hdr[18];
        $decodedLen = $hdr[19];

        $finalLineTerminated = ($flagsOut & 1) !== 0;
        $framingIncomplete = ($flagsOut & 2) !== 0;
        $hasFrame = ($flagsOut & 4) !== 0;
        $hasPart = ($flagsOut & 8) !== 0;
        $payloadEscapeStateValid = ($flagsOut & 16) !== 0;
        $hasDecodedBytes = ($flagsOut & 32) !== 0;

        $completeCrlfFraming = $bareLfCount === 0 && $bareCrCount === 0 && $finalLineTerminated;
        $status = match ($bodyStatusCode) {
            0 => 'empty',
            2 => 'non_crlf_line_endings',
            3 => 'unterminated_final_line',
            default => 'complete_crlf',
        };
        $nntpBodyMetadata = [
            'parser_engine' => 'php-fallback',
            'schema_version' => 1,
            'status' => $status,
            'body_octets_after_dot_unstuffing' => $bodyLen,
            'crlf_line_break_count' => $crlfCount,
            'lf_line_break_count' => $lfCount,
            'bare_lf_count' => $bareLfCount,
            'bare_cr_count' => $bareCrCount,
            'nul_byte_count' => $nulCount,
            'body_line_count' => $completeCrlfFraming ? $crlfCount : null,
            'observed_lf_delimited_line_count' => $observedLfLineCount,
            'unterminated_tail_byte_count' => $unterminatedTailCount,
            'final_line_terminated' => $finalLineTerminated,
            'measurement_basis' => 'Raw NNTP BODY octets after one dot-unstuffing pass; excludes the NNTP dot terminator.',
        ];

        $bodySha256 = '';
        $payloadSha256 = '';
        $decodedSha256 = '';
        $header = [];
        $part = null;
        $trailer = [];
        $controlLines = [];
        $decodedBytes = '';

        if ($metaLen > 0) {
            $metaStr = FFI::string(FFI::addr($scratch[76]), $metaLen);
            $metaTokens = explode("\n", $metaStr);
            $bodySha256 = $metaTokens[0];
            if ($hasFrame) {
                $payloadSha256 = $metaTokens[1] ?? '';
                $decodedSha256 = $metaTokens[2] ?? '';
                $hdrCount = (int) ($metaTokens[3] ?? 0);
                $mPos = 4;
                for ($i = 0; $i < $hdrCount; $i++) {
                    $k = $metaTokens[$mPos++] ?? '';
                    $v = $metaTokens[$mPos++] ?? '';
                    $header[$k] = $v;
                }
                if ($hasPart) {
                    $partCount = (int) ($metaTokens[$mPos++] ?? 0);
                    $part = [];
                    for ($i = 0; $i < $partCount; $i++) {
                        $k = $metaTokens[$mPos++] ?? '';
                        $v = $metaTokens[$mPos++] ?? '';
                        $part[$k] = $v;
                    }
                }
                $trailerCount = (int) ($metaTokens[$mPos++] ?? 0);
                for ($i = 0; $i < $trailerCount; $i++) {
                    $k = $metaTokens[$mPos++] ?? '';
                    $v = $metaTokens[$mPos++] ?? '';
                    $trailer[$k] = $v;
                }
                $ctrlCount = (int) ($metaTokens[$mPos++] ?? 0);
                for ($i = 0; $i < $ctrlCount; $i++) {
                    $controlLines[] = $metaTokens[$mPos++] ?? '';
                }
                if ($hasDecodedBytes && $decodedLen > 0) {
                    $decodedBytes = FFI::string(FFI::addr($scratch[76 + $metaLen]), $decodedLen);
                }
            }
        }

        return [
            'nntp_body_metadata' => $nntpBodyMetadata,
            'framing_incomplete' => $framingIncomplete,
            'has_frame' => $hasFrame,
            'has_part' => $hasPart,
            'payload_escape_state_valid' => $payloadEscapeStateValid,
            'has_decoded_bytes' => $hasDecodedBytes,
            'payload_offset' => $payloadOffset,
            'payload_length' => $payloadLength,
            'escape_sequence_count' => $escapeSeqCount,
            'payload_line_count' => $payloadLineCount,
            'payload_line_min' => $payloadLineCount === 0 ? null : $payloadLineMin,
            'payload_line_max' => $payloadLineCount === 0 ? null : $payloadLineMax,
            'format_signatures_mask' => $formatMask,
            'decoded_crc32' => $decodedCrc32,
            'body_sha256' => $bodySha256,
            'payload_sha256' => $payloadSha256,
            'decoded_sha256' => $decodedSha256,
            'header' => $header,
            'part' => $part,
            'trailer' => $trailer,
            'control_lines' => $controlLines,
            'decoded_bytes' => $decodedBytes,
        ];
    }
}
