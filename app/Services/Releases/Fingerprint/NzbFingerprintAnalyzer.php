<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Services\Yenc\ArticleFrame;
use App\Services\YencService;
use RuntimeException;

final class NzbFingerprintAnalyzer
{
    public const MAX_ARTICLE_SAMPLES = 128;

    public function __construct(private readonly YencService $yenc) {}

    /** @return array<string, mixed> */
    public function analyzeManifest(string $xml): array
    {
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

        $frame = ArticleFrame::parse($rawBody);
        $yencFramingMetadata = (new NativeMetadataParser)->parseYencFraming($rawBody);
        $nntpHeaderMetadata = (new NativeMetadataParser)->parseNntpHeaders(implode('', $rawHeaders));
        $nntpBodyMetadata = (new NativeMetadataParser)->parseNntpBody($rawBody)
            ?? self::fallbackNntpBodyMetadata($rawBody);
        $nntpLinesBodyRelationship = (new NativeMetadataParser)->compareNntpLinesToBody(
            implode('', $rawHeaders),
            $rawBody,
        );
        $conflictingYencFields = [];
        $framingEvidenceIncomplete = self::yencFramingEvidenceIncomplete($yencFramingMetadata, $rawBody);
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
        if ($frame !== null) {
            $decoded = $this->yenc->decodeWithFrame($rawBody);
            $payload = substr($rawBody, $frame->payloadOffset, $frame->payloadLength);
            $payloadEscapeStateValid = self::payloadEscapeStateValid($payload);
            $decodedBytes = $payloadEscapeStateValid ? $decoded[0] : null;
            $payloadHash = hash('sha256', $payload);
            $decodedHash = hash('sha256', $decoded[0]);
            $decodedSize = strlen($decoded[0]);
            $decodedPrefix = substr($decoded[0], 0, 65_536);
            $formatMetadata = (new NativeMetadataParser)->parseFormats($decoded[0]) ?? [
                'parser_engine' => 'php-signature-fallback',
                'signatures' => self::detectFormatSignatures($decodedPrefix),
                'headers' => [],
            ];
            if (extension_loaded('fileinfo')) {
                $fileInfo = new \finfo(FILEINFO_NONE);
                $formatMetadata['magic_file_description'] = $fileInfo->buffer($decodedPrefix) ?: null;
                $formatMetadata['mime_type'] = (new \finfo(FILEINFO_MIME_TYPE))->buffer($decodedPrefix) ?: null;
                $formatMetadata['magic_basis'] = 'first 65536 decoded bytes; heuristic identification only';
            }
            foreach (preg_split('/\r?\n/', rtrim($payload, "\r\n")) ?: [] as $line) {
                $payloadLineLengths[] = strlen($line);
            }
            $rangeFieldsConflict = $framingEvidenceIncomplete || in_array('ypart.begin', $conflictingYencFields, true)
                || in_array('ypart.end', $conflictingYencFields, true);
            $headerSizeConflict = $framingEvidenceIncomplete || in_array('ybegin.size', $conflictingYencFields, true);
            $trailerSizeConflict = $framingEvidenceIncomplete || in_array('yend.size', $conflictingYencFields, true);
            $rangeStart = ! $framingEvidenceIncomplete && ! in_array('ypart.begin', $conflictingYencFields, true) && isset($frame->part['begin'])
                ? self::metadataNumber($frame->part['begin']) : null;
            $rangeEnd = ! $framingEvidenceIncomplete && ! in_array('ypart.end', $conflictingYencFields, true) && isset($frame->part['end'])
                ? self::metadataNumber($frame->part['end']) : null;
            $headerExpectedDecodedSize = ! $headerSizeConflict && isset($frame->header['size'])
                ? self::metadataNumber($frame->header['size']) : null;
            $hasRangeField = isset($frame->part['begin']) || isset($frame->part['end']);
            $rangeIsValid = ! $hasRangeField || $rangeFieldsConflict || $headerSizeConflict ? null : ($rangeStart !== null && $rangeEnd !== null
                && $rangeStart >= 1 && $rangeEnd >= $rangeStart
                && (! isset($frame->header['size']) || ($headerExpectedDecodedSize !== null
                    && $rangeEnd <= $headerExpectedDecodedSize)));
            $ypartExpectedDecodedSize = $rangeIsValid === true ? $rangeEnd - $rangeStart + 1 : null;
            $trailerExpectedDecodedSize = ! $trailerSizeConflict && isset($frame->trailer['size'])
                ? self::metadataNumber($frame->trailer['size']) : null;
            $trailerSizeIsDecimal = $trailerSizeConflict ? null : (! isset($frame->trailer['size']) || ctype_digit($frame->trailer['size']));
            $trailerNumericFieldsValid = array_intersect(
                ['yend.size', 'yend.part', 'yend.total'],
                $conflictingYencFields,
            ) === [];
            $trailerNumericFieldsRangeValid = true;
            foreach (['size', 'part', 'total'] as $numericField) {
                if (! isset($frame->trailer[$numericField])) {
                    continue;
                }
                $number = self::metadataNumber($frame->trailer[$numericField]);
                if ($number === null) {
                    $trailerNumericFieldsRangeValid = false;
                }
                if ($number === null || (in_array($numericField, ['part', 'total'], true) && $number < 1)) {
                    $trailerNumericFieldsValid = false;
                }
            }
            $trailerPartNumber = isset($frame->trailer['part'])
                ? self::metadataNumber($frame->trailer['part']) : null;
            $trailerTotalNumber = isset($frame->trailer['total'])
                ? self::metadataNumber($frame->trailer['total']) : null;
            $headerPartNumber = isset($frame->header['part'])
                ? self::metadataNumber($frame->header['part']) : null;
            $headerTotalNumber = isset($frame->header['total'])
                ? self::metadataNumber($frame->header['total']) : null;
            $trailerPartMatchesHeader = null;
            if (isset($frame->trailer['part'], $frame->header['part'])
                && ! $framingEvidenceIncomplete
                && ! in_array('yend.part', $conflictingYencFields, true)
                && ! in_array('ybegin.part', $conflictingYencFields, true)) {
                $trailerPartMatchesHeader = $trailerPartNumber !== null && $headerPartNumber !== null
                    ? $trailerPartNumber === $headerPartNumber : false;
            }
            $trailerTotalMatchesHeader = null;
            if (isset($frame->trailer['total'], $frame->header['total'])
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
                if (! isset($frame->header[$numericField])) {
                    continue;
                }
                $number = self::metadataNumber($frame->header[$numericField]);
                if ($number === null) {
                    $headerNumericFieldsRangeValid = false;
                }
                if ($number === null || $number < 1) {
                    $headerNumericFieldsValid = false;
                }
            }
            $multipart = isset($frame->header['part']) || isset($frame->trailer['part']) || $frame->part !== null;
            if (($multipart && ($frame->part === null || ! isset($frame->header['part'])))
                || (isset($frame->header['part'], $frame->header['total'])
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
                    : (! isset($frame->trailer['pcrc32']) ? 'missing'
                        : (preg_match('/\A[0-9a-f]{8}\z/i', $frame->trailer['pcrc32']) === 1 ? 'present-hex' : 'malformed')));
            $multipartRequiredFieldsMissing = $multipart && ($frame->part === null
                || ! isset($frame->header['part'], $frame->header['size'], $frame->trailer['part'], $frame->trailer['size'])
                || $multipartPcrc32Status === 'missing');
            $multipartStructureValid = ! $multipartStructureAmbiguous && (! $multipart || ($frame->part !== null
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
            $crcField = $multipart ? 'pcrc32' : (isset($frame->trailer['crc32']) ? 'crc32' : 'pcrc32');
            $crcFieldConflict = $framingEvidenceIncomplete || in_array('yend.'.$crcField, $conflictingYencFields, true);
            $expectedCrc = $crcFieldConflict ? null : ($frame->trailer[$crcField] ?? null);
            $criticalYencFields = [
                'ybegin.name', 'ybegin.line', 'ybegin.size', 'ybegin.part', 'ybegin.total',
                'ypart.begin', 'ypart.end', 'yend.size', 'yend.part', 'yend.total', 'yend.pcrc32', 'yend.crc32',
            ];
            $integrityChecksAmbiguous = $framingEvidenceIncomplete || array_intersect($criticalYencFields, $conflictingYencFields) !== [];
            $allDeclaredCrc32FieldsAreHex = true;
            foreach (['pcrc32', 'crc32'] as $declaredCrcField) {
                if (isset($frame->trailer[$declaredCrcField])
                    && (! is_string($frame->trailer[$declaredCrcField])
                        || preg_match('/\A[0-9a-f]{1,8}\z/i', $frame->trailer[$declaredCrcField]) !== 1)) {
                    $allDeclaredCrc32FieldsAreHex = false;
                }
            }
            $observedCrc = hash('crc32b', $decoded[0]);
            $expectedCrcIsHex = is_string($expectedCrc) && preg_match('/\A[0-9a-f]{1,8}\z/i', $expectedCrc) === 1;
            $observedDeclaredCrcMatches = $expectedCrcIsHex
                ? strcasecmp(str_pad($expectedCrc, 8, '0', STR_PAD_LEFT), $observedCrc) === 0 : null;
            $knownHeaderFields = ['line', 'size', 'part', 'total', 'name'];
            $knownPartFields = ['begin', 'end'];
            $knownTrailerFields = ['size', 'part', 'total', 'pcrc32', 'crc32'];
            $controlLines = [];
            preg_match_all('/^(?:=ybegin|=ypart|=yend)[^\r\n]*/mi', $rawBody, $controlMatches);
            $controlLines = $controlMatches[0] ?? [];
            $par2PrefixLength = min($headerExpectedDecodedSize ?? 16_384, 16_384);
            $prefixCoverageIsValid = $multipartStructureValid && ! $integrityChecksAmbiguous && $payloadEscapeStateValid && $par2PrefixLength > 0
                && (! $multipart || $observedDeclaredCrcMatches === true)
                && $decodedSize >= $par2PrefixLength
                && (! $multipart
                    ? $headerExpectedDecodedSize === null || $decodedSize === $headerExpectedDecodedSize
                    : $rangeIsValid === true && $rangeStart === 1 && $rangeEnd >= $par2PrefixLength
                        && $decodedSize === $ypartExpectedDecodedSize);
            $par2PrefixMd5Candidate = $prefixCoverageIsValid
                ? hash('md5', substr($decoded[0], 0, $par2PrefixLength)) : null;
            $yenc = [
                'payload_escape_state_valid' => $payloadEscapeStateValid,
                'payload_integrity_status' => $payloadEscapeStateValid ? 'escape_state_valid' : 'invalid_unmatched_escape',
                'name' => $frame->header['name'] ?? null,
                'file_size' => $headerExpectedDecodedSize,
                'line_length' => isset($frame->header['line']) ? self::metadataNumber($frame->header['line']) : null,
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
                'pcrc32' => $frame->trailer['pcrc32'] ?? null,
                'crc32' => $frame->trailer['crc32'] ?? null,
                'header_fields' => $frame->header,
                'part_fields' => $frame->part,
                'trailer_fields' => $frame->trailer,
                'decoded_segment_size' => $decodedSize,
                'decoded_segment_sha256' => $decodedHash,
                'par2_first_16k_md5_candidate' => $par2PrefixMd5Candidate,
                'encoded_payload_sha256' => $payloadHash,
                'payload_line_count' => count($payloadLineLengths),
                'payload_line_length_min' => $payloadLineLengths === [] ? null : min($payloadLineLengths),
                'payload_line_length_max' => $payloadLineLengths === [] ? null : max($payloadLineLengths),
                'decoded_prefix_format_signatures' => $formatMetadata['signatures'] ?? [],
                'raw_control_lines' => $controlLines,
                'header_field_order' => array_keys($frame->header),
                'part_field_order' => array_keys($frame->part ?? []),
                'trailer_field_order' => array_keys($frame->trailer),
                'unknown_header_fields' => array_diff_key($frame->header, array_flip($knownHeaderFields)),
                'unknown_part_fields' => array_diff_key($frame->part ?? [], array_flip($knownPartFields)),
                'unknown_trailer_fields' => array_diff_key($frame->trailer, array_flip($knownTrailerFields)),
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
                'escape_sequence_count' => substr_count($payload, '='),
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
                'article_body_sha256' => hash('sha256', $rawBody),
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

    public static function formatHint(string $subject): string
    {
        if (preg_match('/\.([a-z0-9]{1,8})\b/i', $subject, $matches) !== 1) {
            return 'unknown';
        }

        return strtolower($matches[1]);
    }

    /** @return array<string, mixed> */
    private static function fallbackNntpBodyMetadata(string $body): array
    {
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
}
