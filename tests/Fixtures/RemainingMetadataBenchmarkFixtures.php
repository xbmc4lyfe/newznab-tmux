<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use InvalidArgumentException;
use RuntimeException;

/**
 * Newly authored, bounded PHP references, not historical application fallbacks.
 * Only the explicitly selected projections are comparable; native full metadata
 * (including byte provenance and additional interpretations) is always retained.
 * Offline grammar: modern numeric-zone dates, alphanumeric dot-atom Message-IDs and advertised
 * Xref OVER columns. These fixtures do not establish full-schema equivalence.
 */
final class RemainingMetadataBenchmarkFixtures
{
    /** @return array<string,array{module:string,category:string,comparison_scope:string}> */
    public static function strata(): array
    {
        $result = [];
        foreach (['dates' => ['ordinary', 'folded', 'uncommon-duplicates', 'uncommon-malformed'],
            'identity' => ['ordinary', 'overview', 'uncommon-conflicts', 'uncommon-boundary'],
            'xref' => ['ordinary', 'overview', 'uncommon-duplicates', 'uncommon-limits']] as $module => $names) {
            foreach ($names as $name) {
                $result[$module.'-'.$name] = ['module' => 'article_'.$module,
                    'category' => str_starts_with($name, 'uncommon-') ? 'uncommon' : 'common',
                    'comparison_scope' => 'bounded-reference-shared-projection-v1'];
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public static function make(string $name): array
    {
        $definition = self::strata()[$name] ?? throw new InvalidArgumentException('Unknown remaining metadata stratum.');
        $fixture = ['name' => $name, 'raw_headers' => [], 'requested_message_id' => '<offline@example.test>',
            'overview_row' => null, 'overview_rows' => [], 'overview_format' => self::format(),
            'overview_response_code' => null, 'overview_attempted' => false,
            'head_response_text' => null, 'body_response_text' => null,
            'head_response_code' => 221, 'body_response_code' => 222,
            'overview_row_count' => 0, 'overview_format_response_code' => 215];
        if ($definition['module'] === 'article_dates') {
            $missing = self::dateSource('missing', []);
            $valid = self::dateCandidate('valid', 1704067200, [1]);
            $expected = ['date' => self::dateSource('valid', [$valid]), 'injection_date' => $missing, 'legacy_nntp_posting_date' => $missing];
            if ($name === 'dates-ordinary') {
                $fixture['raw_headers'] = ["Date: Mon, 01 Jan 2024 00:00:00 +0000\r\n", "Injection-Date: 01 Jan 2024 01:00:00 +0000\r\n", "NNTP-Posting-Date: 31 Dec 2023 19:00:00 -0500\r\n"];
                $expected['injection_date'] = self::dateSource('valid', [self::dateCandidate('valid', 1704070800, [2])]);
                $expected['legacy_nntp_posting_date'] = self::dateSource('valid', [self::dateCandidate('valid', 1704067200, [3])]);
            } elseif ($name === 'dates-folded') {
                $fixture['raw_headers'] = ["Subject: folded offline date\r\n", "dAtE: 01 Jan 2024\r\n", "\t00:00 -0000\r\n", "Injection-Date: 01 Jan 2024 00:00:00 GMT\r\n"];
                $expected['date'] = self::dateSource('valid', [self::dateCandidate('valid', 1704067200, [2, 3])]);
                $expected['injection_date'] = self::dateSource('valid', [self::dateCandidate('valid', 1704067200, [4])]);
            } elseif ($name === 'dates-uncommon-duplicates') {
                $fixture['raw_headers'] = self::padHeaders(array_fill(0, 257, "Date: 01 Jan 2024 00:00:00 +0000\r\n"));
                $candidates = [];
                for ($line = 1; $line <= 256; $line++) {
                    $candidates[] = self::dateCandidate('valid', 1704067200, [$line]);
                }
                $expected['date'] = self::dateSource('budget_exceeded', $candidates);
            } else {
                $fixture['raw_headers'] = ["Date: 31 Feb 2024 00:00:00 +0000\r\n", "Injection-Date: 01 Jan 2024 00:00:00 PST\r\n", "NNTP-Posting-Date: 01 Jan 2024 00:00:00 +0000\0\r\n"];
                $expected = ['date' => self::dateSource('invalid_calendar_or_time', [self::dateCandidate('invalid_calendar_or_time', null, [1])]),
                    'injection_date' => self::dateSource('unsupported_absolute_timezone', [self::dateCandidate('unsupported_absolute_timezone', null, [2])]),
                    'legacy_nntp_posting_date' => self::dateSource('invalid_octet_or_folding', [self::dateCandidate('invalid_octet_or_folding', null, [3])])];
            }
        } elseif ($definition['module'] === 'article_identity') {
            $id = $fixture['requested_message_id'];
            $fixture['raw_headers'] = ["Message-ID: $id\r\n"];
            $fixture['head_response_text'] = '42 '.$id.' article follows';
            $fixture['body_response_text'] = '42 '.$id;
            $expected = ['request_status' => 'valid', 'head' => self::identitySource('valid', [self::identityCandidate($id, [1])], true),
                'overview' => self::identitySource('not_attempted', [], null), 'overview_article_number' => null,
                'head_response' => self::response('valid', '42', $id), 'body_response' => self::response('valid', '42', $id),
                'relations' => ['head_response_matches_request' => true, 'body_response_matches_request' => true,
                    'head_response_matches_body_response' => true, 'head_matches_overview' => null]];
            if ($name !== 'identity-ordinary') {
                $fixture['overview_attempted'] = true;
                $fixture['overview_response_code'] = 224;
                $fixture['overview_row'] = self::row($id);
                $expected['overview'] = self::identitySource('valid', [self::identityCandidate($id, [])], true);
                $expected['overview_article_number'] = '42';
                $expected['relations']['head_matches_overview'] = true;
            }
            if ($name === 'identity-uncommon-conflicts') {
                $other = '<OFFLINE@example.test>';
                $fixture['raw_headers'] = ["Message-ID: $id\r\n", "Message-ID: $other\r\n"];
                $fixture['head_response_text'] = '42 '.$other;
                $fixture['overview_row'] = "invalid\tSubject\tFrom\tDate\t$id\t\t100\t2";
                $expected['head'] = self::identitySource('duplicate', [self::identityCandidate($id, [1]), self::identityCandidate($other, [2])], null);
                $expected['overview'] = self::identitySource('malformed_row', [], null);
                $expected['overview_article_number'] = null;
                $expected['head_response'] = self::response('valid', '42', $other);
                $expected['relations'] = ['head_response_matches_request' => false, 'body_response_matches_request' => true,
                    'head_response_matches_body_response' => false, 'head_matches_overview' => null];
            } elseif ($name === 'identity-uncommon-boundary') {
                $id = '<'.str_repeat('a', 235).'@example.test>';
                $fixture['requested_message_id'] = $id;
                $fixture['raw_headers'] = self::padHeaders(['Message-ID: <a'.substr($id, 1)."\r\n"]);
                $fixture['overview_row'] = self::row($id);
                $fixture['head_response_text'] = '1234567890123456 '.$id.' '.str_repeat('c', 257);
                $fixture['body_response_text'] = '12345678901234567 '.$id;
                $expected['head'] = self::identitySource('malformed', [self::identityCandidate(null, [1], 'malformed')], null);
                $expected['overview'] = self::identitySource('valid', [self::identityCandidate($id, [])], true);
                $expected['head_response'] = self::response('valid', '1234567890123456', $id);
                $expected['body_response'] = self::response('malformed_article_number', null, null);
                $expected['relations'] = ['head_response_matches_request' => true, 'body_response_matches_request' => null,
                    'head_response_matches_body_response' => null, 'head_matches_overview' => null];
            }
        } else {
            $value = 'news.example.test alt.test:42 alt.binaries.test:0043';
            $locations = [self::location('alt.test', '42'), self::location('alt.binaries.test', '0043')];
            $candidate = self::xrefCandidate('valid', 'news.example.test', $locations, [1]);
            $fixture['raw_headers'] = ["Xref: $value\r\n"];
            $expected = ['head' => self::xrefSource('valid', [$candidate], null),
                'overview' => self::xrefSource('not_attempted', [], 0),
                'format_status' => 'valid', 'xref_format_line_indices' => [7], 'head_matches_single_overview_value' => null];
            if ($name !== 'xref-ordinary') {
                $fixture['overview_attempted'] = true;
                $fixture['overview_response_code'] = 224;
                $fixture['overview_row_count'] = 1;
                $fixture['overview_rows'] = [self::row('<offline@example.test>')."\tXref: $value"];
                $expected['overview'] = self::xrefSource('valid', [self::xrefCandidate('valid', 'news.example.test', $locations, [], 0, 8)], 1);
                $expected['head_matches_single_overview_value'] = true;
            }
            if ($name === 'xref-uncommon-duplicates') {
                $fixture['raw_headers'][] = "Xref: news.example.test alt.test:42 alt.test:42\r\n";
                $expected['head'] = self::xrefSource('duplicate', [$candidate,
                    self::xrefCandidate('valid', 'news.example.test', array_fill(0, 2, self::location('alt.test', '42')), [2])], null);
                $fixture['overview_rows'][] = self::row('<offline@example.test>')."\tXref: news.example.test bad..group:2";
                $fixture['overview_row_count'] = 2;
                $expected['overview']['parse_status'] = 'multiple_rows';
                $expected['overview']['response_row_count'] = 2;
                $expected['overview']['occurrence_count'] = 2;
                $expected['overview']['candidates'][] = self::xrefCandidate('one_or_more_malformed_locations', 'news.example.test',
                    [self::location(null, '2', 'malformed')], [], 1, 8);
                $expected['head_matches_single_overview_value'] = null;
            } elseif ($name === 'xref-uncommon-limits') {
                $fixture['raw_headers'] = self::padHeaders(array_merge(['Xref: server '.implode(' ', array_fill(0, 513, 'a:1'))."\r\n"], array_fill(0, 64, "Xref: server a:2\r\n")));
                $candidates = [self::xrefCandidate('budget_exceeded', 'server', array_fill(0, 512, self::location('a', '1')), [1])];
                for ($line = 2; $line <= 64; $line++) {
                    $candidates[] = self::xrefCandidate('valid', 'server', [self::location('a', '2')], [$line]);
                }
                $fixture['overview_rows'] = array_fill(0, 65, self::row('<offline@example.test>')."\tXref: server a:1");
                $fixture['overview_row_count'] = 65;
                $expected['head'] = self::xrefSource('budget_exceeded', $candidates, null);
                $expected['overview'] = self::xrefSource('missing_or_budget_exceeded', [], 65);
                $expected['head_matches_single_overview_value'] = null;
            }
        }

        return $definition + $fixture + ['expected' => $expected];
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    public static function baseline(array $fixture): array
    {
        return match ($fixture['module']) {
            'article_dates' => self::referenceDates(implode('', $fixture['raw_headers'])),
            'article_identity' => self::referenceIdentity($fixture),
            'article_xref' => self::referenceXref($fixture),
            default => throw new InvalidArgumentException('Unknown metadata module.'),
        };
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array{full:array<string,mixed>,projection:array<string,mixed>}
     */
    public static function native(NativeMetadataParser $parser, array $fixture): array
    {
        $full = match ($fixture['module']) {
            'article_dates' => $parser->parseArticleDates($fixture['raw_headers']),
            'article_identity' => $parser->parseArticleIdentity($fixture['requested_message_id'], $fixture['raw_headers'],
                $fixture['overview_row'], $fixture['overview_response_code'], $fixture['overview_attempted'],
                $fixture['head_response_text'], $fixture['body_response_text'], $fixture['head_response_code'], $fixture['body_response_code']),
            'article_xref' => $parser->parseArticleXref($fixture['raw_headers'], $fixture['overview_rows'], $fixture['overview_format'],
                $fixture['head_response_code'], $fixture['overview_response_code'], $fixture['overview_attempted'],
                $fixture['overview_row_count'], $fixture['overview_format_response_code']),
            default => throw new InvalidArgumentException('Unknown metadata module.'),
        };
        if ($full === null) {
            throw new RuntimeException('Native metadata wrapper returned null for '.$fixture['module']);
        }
        $projection = match ($fixture['module']) {
            'article_dates' => array_combine(['date', 'injection_date', 'legacy_nntp_posting_date'], array_map(static function (string $key) use ($full): array {
                return ['parse_status' => $full[$key]['parse_status'], 'occurrence_count' => $full[$key]['occurrence_count'],
                    'utc_epoch_seconds' => $full[$key]['utc_epoch_seconds'], 'candidates' => array_map(static fn (array $row): array => self::dateCandidate(
                        $row['parse_status'], $row['utc_epoch_seconds'], $row['source_line_indices']), $full[$key]['candidates'])];
            }, ['date', 'injection_date', 'legacy_nntp_posting_date'])),
            'article_identity' => ['request_status' => $full['request']['syntax_status'],
                'head' => self::identityProjection($full['head_message_id']), 'overview' => self::identityProjection($full['overview_message_id']),
                'overview_article_number' => $full['overview_article_number'],
                'head_response' => self::response($full['head_response']['parse_status'], $full['head_response']['article_number'], $full['head_response']['message_id']),
                'body_response' => self::response($full['body_response']['parse_status'], $full['body_response']['article_number'], $full['body_response']['message_id']),
                'relations' => array_intersect_key($full['relations'], array_flip(['head_response_matches_request', 'body_response_matches_request', 'head_response_matches_body_response', 'head_matches_overview']))],
            'article_xref' => ['head' => self::xrefProjection($full['head']), 'overview' => self::xrefProjection($full['overview']),
                'format_status' => $full['overview_format']['parse_status'], 'xref_format_line_indices' => $full['overview_format']['xref_format_line_indices'],
                'head_matches_single_overview_value' => $full['relations']['head_matches_single_overview_value']],
        };

        return ['full' => $full, 'projection' => $projection];
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @param  array<string,mixed>  $projection
     */
    public static function correct(array $fixture, array $projection): bool
    {
        return $fixture['expected'] === $projection;
    }

    /** @return list<string> */
    public static function sourcePaths(): array
    {
        return ['app/Services/Releases/Fingerprint/NativeMetadataParser.php',
            'tests/Fixtures/RemainingMetadataBenchmarkFixtures.php', 'tests/Unit/RemainingMetadataBenchmarkFixturesTest.php'];
    }

    /** @return list<string> */
    private static function format(): array
    {
        return ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', ':bytes', ':lines', 'Xref:full'];
    }

    private static function row(string $id): string
    {
        return "42\tOffline subject\tposter@example.test\t01 Jan 2024 00:00:00 +0000\t$id\t\t100\t2";
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private static function padHeaders(array $headers): array
    {
        $headers[] = 'X-Padding: '.str_repeat('p', 65536 - strlen(implode('', $headers)) - 13)."\r\n";

        return $headers;
    }

    /** Physical-line scanner independent of all application/native parsers.
     * @return list<array{name:string,value:string,lines:list<int>}>
     */
    private static function fields(string $headers): array
    {
        $fields = [];
        foreach (explode("\n", $headers) as $index => $line) {
            $line = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
            if ($line === '') {
                break;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($fields !== []) {
                    $last = array_key_last($fields);
                    $fields[$last]['value'] .= $line;
                    $fields[$last]['lines'][] = $index + 1;
                }
            } elseif (($colon = strpos($line, ':')) !== false) {
                $fields[] = ['name' => strtolower(substr($line, 0, $colon)), 'value' => substr($line, $colon + 1), 'lines' => [$index + 1]];
            }
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    private static function referenceDates(string $headers): array
    {
        $result = [];
        $fields = strlen($headers) > 65536 ? [] : self::fields($headers);
        foreach (['date' => 'date', 'injection_date' => 'injection-date', 'legacy_nntp_posting_date' => 'nntp-posting-date'] as $key => $name) {
            $candidates = [];
            foreach ($fields as $field) {
                if ($field['name'] !== $name || count($candidates) >= 256) {
                    continue;
                }
                [$status, $epoch] = self::dateValue($field['value']);
                $candidates[] = self::dateCandidate($status, $epoch, $field['lines']);
            }
            $status = strlen($headers) > 65536 || count($candidates) >= 256 ? 'budget_exceeded'
                : (count($candidates) > 1 ? 'duplicate' : ($candidates[0]['parse_status'] ?? 'missing'));
            $result[$key] = self::dateSource($status, $candidates);
        }

        return $result;
    }

    /** Restricted absolute date grammar; PHP calendar primitives supply the epoch.
     * @return array{string,?int}
     */
    private static function dateValue(string $value): array
    {
        if (preg_match('/[^\x09\x20-\x7e]/', $value)) {
            return ['invalid_octet_or_folding', null];
        }
        if (! preg_match('/^\s*(?:(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s*)?(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})\s+(\d{2}):(\d{2})(?::(\d{2}))?\s+([+-]\d{4}|[A-Za-z]+)\s*$/D', $value, $parts)) {
            return ['malformed_or_unsupported_absolute_date', null];
        }
        $month = array_search(strtolower($parts[2]), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);
        $zone = strtoupper($parts[7]);
        if (! in_array($zone, ['GMT', 'UT'], true) && ! preg_match('/^[+-]\d{4}$/D', $zone)) {
            return ['unsupported_absolute_timezone', null];
        }
        $numeric = $zone[0] === '+' || $zone[0] === '-';
        $offsetHours = $numeric ? (int) substr($zone, 1, 2) : 0;
        $offsetMinutes = $numeric ? (int) substr($zone, 3, 2) : 0;
        if ($offsetHours > 23 || $offsetMinutes > 59 || $month === false) {
            return ['malformed_or_unsupported_absolute_date', null];
        }
        $second = $parts[6] === '' ? 0 : (int) $parts[6];
        if ((int) $parts[3] < 1900 || ! checkdate($month + 1, (int) $parts[1], (int) $parts[3]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || $second > 60) {
            return ['invalid_calendar_or_time', null];
        }
        $offset = ($offsetHours * 60 + $offsetMinutes) * ($zone[0] === '-' ? -60 : 60);

        return ['valid', gmmktime((int) $parts[4], (int) $parts[5], $second, $month + 1, (int) $parts[1], (int) $parts[3]) - $offset];
    }

    /**
     * @param  list<int>  $lines
     * @return array<string,mixed>
     */
    private static function dateCandidate(string $status, ?int $epoch, array $lines): array
    {
        return ['parse_status' => $status, 'utc_epoch_seconds' => $epoch, 'source_line_indices' => $lines];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private static function dateSource(string $status, array $candidates): array
    {
        return ['parse_status' => $status, 'occurrence_count' => count($candidates),
            'utc_epoch_seconds' => $status === 'valid' ? $candidates[0]['utc_epoch_seconds'] : null, 'candidates' => $candidates];
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private static function referenceIdentity(array $fixture): array
    {
        $request = $fixture['requested_message_id'];
        $requestValid = self::protocolId($request);
        $headers = implode('', $fixture['raw_headers']);
        $head = [];
        foreach (strlen($headers) > 65536 ? [] : self::fields($headers) as $field) {
            if ($field['name'] === 'message-id' && count($head) < 4096) {
                $token = trim($field['value'], " \t");
                $valid = self::headerId($token);
                $head[] = self::identityCandidate($valid ? $token : null, $field['lines'], $valid ? 'valid' : 'malformed');
            }
        }
        $headStatus = strlen($headers) > 65536 || count($head) === 4096 ? 'budget_exceeded' : self::identityStatus($head);
        $headToken = count($head) === 1 ? $head[0]['token'] : null;
        $row = $fixture['overview_row'];
        $overview = [];
        $number = null;
        $status = 'not_attempted';
        if (is_string($row) && strlen($row) > 32768) {
            $status = 'budget_exceeded';
        } elseif ($fixture['overview_attempted']) {
            $code = $fixture['overview_response_code'];
            $status = $code === 224 ? ($row === null ? 'empty_response' : 'valid')
                : ($code === null ? 'failed' : (in_array($code, [500, 501, 503], true) ? 'unsupported' : 'unavailable'));
            if ($status === 'valid') {
                $columns = explode("\t", rtrim($row, "\r\n"));
                if (preg_match('/[\r\n\x00]/', rtrim($row, "\r\n")) || ! preg_match('/^\d{1,16}$/D', $columns[0])) {
                    $status = 'malformed_row';
                } else {
                    $number = $columns[0];
                    if (count($columns) < 5) {
                        $status = 'missing_message_id_column';
                    } else {
                        $token = trim($columns[4], " \t");
                        $valid = self::headerId($token);
                        $overview[] = self::identityCandidate($valid ? $token : null, [], $valid ? 'valid' : 'malformed');
                        $status = $valid ? 'valid' : 'malformed';
                    }
                }
            }
        }
        $overviewToken = $overview[0]['token'] ?? null;
        $headResponse = self::referenceResponse($fixture['head_response_text'], $fixture['head_response_code'], 221);
        $bodyResponse = self::referenceResponse($fixture['body_response_text'], $fixture['body_response_code'], 222);

        return ['request_status' => strlen($request) > 8192 ? 'budget_exceeded' : ($requestValid ? 'valid' : 'malformed'),
            'head' => self::identitySource($headStatus, $head, self::equalTokens($headToken, $requestValid ? $request : null)),
            'overview' => self::identitySource($status, $overview, self::equalTokens($overviewToken, $requestValid ? $request : null)),
            'overview_article_number' => $number, 'head_response' => $headResponse, 'body_response' => $bodyResponse,
            'relations' => ['head_response_matches_request' => self::equalTokens($headResponse['message_id'], $requestValid ? $request : null),
                'body_response_matches_request' => self::equalTokens($bodyResponse['message_id'], $requestValid ? $request : null),
                'head_response_matches_body_response' => self::equalTokens($headResponse['message_id'], $bodyResponse['message_id']),
                'head_matches_overview' => self::equalTokens($headToken, $overviewToken)]];
    }

    private static function protocolId(string $value): bool
    {
        return strlen($value) <= 250 && preg_match('/^<[\x21-\x3d\x3f-\x7e]+>$/D', $value) === 1;
    }

    private static function headerId(string $value): bool
    {
        return strlen($value) <= 250 && preg_match('/^<[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*@[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*>$/D', $value) === 1;
    }

    private static function equalTokens(?string $left, ?string $right): ?bool
    {
        return $left === null || $right === null ? null : $left === $right;
    }

    /** @param list<array<string,mixed>> $candidates */
    private static function identityStatus(array $candidates): string
    {
        return count($candidates) > 1 ? 'duplicate' : (count($candidates) === 0 ? 'missing' : $candidates[0]['syntax_status']);
    }

    /** @return array<string,mixed> */
    private static function referenceResponse(?string $text, ?int $code, int $expected): array
    {
        if ($code !== $expected || $text === null) {
            return self::response($code === $expected ? 'missing_response_text' : 'unavailable', null, null);
        }
        if (strlen($text) > 4096) {
            return self::response('budget_exceeded', null, null);
        }
        $space = strpos($text, ' ');
        if ($space === false) {
            return self::response('malformed', null, null);
        }
        $number = substr($text, 0, $space);
        if (! preg_match('/^\d{1,16}$/D', $number)) {
            return self::response('malformed_article_number', null, null);
        }
        $rest = substr($text, $space + 1);
        if (! preg_match('/^(<[^>]*>)(?: (.*))?$/Ds', $rest, $parts)) {
            return self::response($rest === '' ? 'missing_message_id' : 'malformed_response_spacing', $number, null);
        }
        if (isset($parts[2]) && (preg_match('/[\x00\r\n\x7f]/', $parts[2]) || ! preg_match('//u', $parts[2]))) {
            return self::response('malformed_trailing_comment', $number, null);
        }
        $valid = self::protocolId($parts[1]);

        return self::response($valid ? 'valid' : 'malformed', $number, $valid ? $parts[1] : null);
    }

    /**
     * @param  list<int>  $lines
     * @return array<string,mixed>
     */
    private static function identityCandidate(?string $token, array $lines, string $status = 'valid'): array
    {
        return ['token' => $token, 'syntax_status' => $status, 'source_line_indices' => $lines];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private static function identitySource(string $status, array $candidates, ?bool $matches): array
    {
        return ['parse_status' => $status, 'occurrence_count' => count($candidates), 'candidates' => $candidates, 'matches_request' => $matches];
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    private static function identityProjection(array $source): array
    {
        $projection = self::identitySource($source['parse_status'], array_map(static fn (array $row): array => self::identityCandidate(
            $row['token'], $row['source_line_indices'], $row['syntax_status']), $source['candidates']), $source['matches_request']);
        $projection['occurrence_count'] = $source['occurrence_count'];

        return $projection;
    }

    /** @return array<string,mixed> */
    private static function response(string $status, ?string $number, ?string $id): array
    {
        return ['parse_status' => $status, 'article_number' => $number, 'message_id' => $id];
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private static function referenceXref(array $fixture): array
    {
        $head = [];
        $headers = implode('', $fixture['raw_headers']);
        $headStatus = $fixture['head_response_code'] === 221 ? 'missing' : 'head_response_not_successful';
        $exceeded = strlen($headers) > 65536;
        if ($fixture['head_response_code'] === 221 && ! $exceeded) {
            foreach (self::fields($headers) as $field) {
                if ($field['name'] !== 'xref') {
                    continue;
                }
                if (count($head) >= 64 || strlen($field['value']) > 4096) {
                    $exceeded = true;

                    continue;
                }
                $head[] = self::referenceXrefValue($field['value'], $field['lines']);
            }
            $headStatus = count($head) > 1 ? 'duplicate' : ($head[0]['syntax_status'] ?? 'missing');
        }
        if ($exceeded) {
            $headStatus = 'budget_exceeded';
        }
        $format = $fixture['overview_format'];
        $columns = [];
        $formatStatus = 'valid';
        if ($fixture['overview_format_response_code'] !== 215) {
            $formatStatus = $fixture['overview_format_response_code'] === null ? 'request_failed' : 'response_not_successful';
        } elseif (count($format) > 256 || array_sum(array_map('strlen', $format)) > 32768) {
            $formatStatus = 'missing_or_budget_exceeded';
        } elseif (count($format) < 7) {
            $formatStatus = 'incomplete_mandatory_fields';
        } elseif (array_map('strtolower', array_slice($format, 0, 7)) !== array_map('strtolower', array_slice(self::format(), 0, 7))) {
            $formatStatus = 'invalid_mandatory_field_order';
        } else {
            foreach (array_slice($format, 7, null, true) as $index => $line) {
                if (in_array(strtolower($line), ['xref', 'xref:full'], true)) {
                    $columns[] = $index;
                } elseif (! preg_match('/^(?::[^:]+|[^:]+(?::full)?)$/Di', $line)) {
                    $formatStatus = 'invalid_optional_field';
                }
            }
        }
        $overview = [];
        $rows = $fixture['overview_rows'];
        $status = 'not_attempted';
        if ($fixture['overview_attempted']) {
            if ($fixture['overview_response_code'] !== 224) {
                $status = 'response_not_successful';
            } elseif ($formatStatus !== 'valid') {
                $status = 'overview_format_invalid';
            } elseif (count($columns) !== 1) {
                $status = $columns === [] ? 'not_advertised' : 'ambiguous_format_column';
            } elseif (count($rows) > 64 || array_sum(array_map('strlen', $rows)) > 32768) {
                $status = 'missing_or_budget_exceeded';
            } elseif (count($rows) !== $fixture['overview_row_count']) {
                $status = 'reported_row_count_mismatch';
            } else {
                $bad = false;
                foreach ($rows as $index => $row) {
                    $fields = explode("\t", $row);
                    $column = $columns[0] + 1;
                    if (preg_match('/[\r\n\x00]/', $row) || count($fields) < 8 || ! ctype_digit($fields[0]) || ! isset($fields[$column]) || $fields[$column] === '') {
                        $bad = true;

                        continue;
                    }
                    $full = str_ends_with(strtolower($format[$columns[0]]), ':full');
                    $value = $fields[$column];
                    if ($full && ! preg_match('/^Xref:[ \t]/i', $value)) {
                        $overview[] = self::xrefCandidate('full_header_name_missing', null, [], [], $index, $column);
                    } else {
                        $overview[] = self::referenceXrefValue($full ? substr($value, 5) : $value, [], $index, $column);
                    }
                }
                $status = $bad ? 'row_missing_or_malformed_xref_column' : ($rows === [] ? 'empty_response'
                    : (count($rows) > 1 ? 'multiple_rows' : ($overview[0]['syntax_status'] ?? 'missing')));
            }
        }
        $relation = null;
        if ($headStatus === 'valid' && $status === 'valid' && count($head) === 1 && count($overview) === 1 && $fixture['overview_row_count'] === 1) {
            $headValue = null;
            foreach (self::fields($headers) as $field) {
                if ($field['name'] === 'xref') {
                    $headValue = $field['value'];
                }
            }
            $value = explode("\t", $rows[0])[$columns[0] + 1];
            if (preg_match('/^Xref:[ \t]/i', $value)) {
                $relation = str_replace("\t", ' ', $headValue) === str_replace("\t", ' ', substr($value, 5));
            }
        }

        return ['head' => self::xrefSource($headStatus, $head, null), 'overview' => self::xrefSource($status, $overview, $fixture['overview_row_count']),
            'format_status' => $formatStatus, 'xref_format_line_indices' => $columns, 'head_matches_single_overview_value' => $relation];
    }

    /**
     * @param  list<int>  $lines
     * @return array<string,mixed>
     */
    private static function referenceXrefValue(string $value, array $lines, ?int $row = null, ?int $column = null): array
    {
        $tokens = preg_split('/[ \t]+/', trim($value, " \t"));
        $server = array_shift($tokens);
        $serverValid = preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $server) === 1;
        $locations = [];
        foreach (array_slice($tokens, 0, 512) as $token) {
            $parts = explode(':', $token, 2);
            if (count($parts) !== 2) {
                $locations[] = ['newsgroup' => null, 'article_locator' => null, 'article_locator_is_decimal' => null, 'syntax_status' => 'missing_article_locator'];

                continue;
            }
            $groupValid = preg_match('/^[a-zA-Z0-9_+-]+(?:\.[a-zA-Z0-9_+-]+)*$/D', $parts[0]) === 1;
            $locatorValid = $parts[1] !== '' && ! preg_match('/[^\x21-\x27\x29-\x3a\x3c-\x7e]/', $parts[1]);
            $locations[] = self::location($groupValid ? $parts[0] : null, $locatorValid ? $parts[1] : null, $groupValid && $locatorValid ? 'valid' : 'malformed');
        }
        $valid = $locations !== [] && array_reduce($locations, static fn (bool $ok, array $location): bool => $ok && $location['syntax_status'] === 'valid', true);
        $status = count($tokens) > 512 || strlen($value) > 4096 ? 'budget_exceeded'
            : (! $serverValid ? 'malformed_server_name' : ($locations === [] ? 'missing_locations' : ($valid ? 'valid' : 'one_or_more_malformed_locations')));
        if ($row === null && ($value === '' || ! in_array($value[0], [' ', "\t"], true))) {
            $status = 'malformed_header_whitespace';
            $server = null;
            $locations = [];
        } elseif (preg_match('/[^\x09\x20-\x7e]/', $value)) {
            $status = 'malformed_whitespace_or_octet';
            $server = null;
            $locations = [];
        }

        return self::xrefCandidate($status, $serverValid ? $server : null, $locations, $lines, $row, $column);
    }

    /** @return array<string,mixed> */
    private static function location(?string $group, ?string $locator, string $status = 'valid'): array
    {
        return ['newsgroup' => $group, 'article_locator' => $locator,
            'article_locator_is_decimal' => $locator === null ? null : ctype_digit($locator), 'syntax_status' => $status];
    }

    /**
     * @param  list<array<string,mixed>>  $locations
     * @param  list<int>  $lines
     * @return array<string,mixed>
     */
    private static function xrefCandidate(string $status, ?string $server, array $locations, array $lines, ?int $row = null, ?int $column = null): array
    {
        return ['syntax_status' => $status, 'server_name' => $server, 'locations' => $locations,
            'source_line_indices' => $lines, 'source_overview_row_index' => $row, 'source_overview_column_index' => $column];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private static function xrefSource(string $status, array $candidates, ?int $rows): array
    {
        return ['parse_status' => $status, 'occurrence_count' => count($candidates), 'candidates' => $candidates, 'response_row_count' => $rows];
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    private static function xrefProjection(array $source): array
    {
        $candidates = [];
        foreach ($source['candidates'] as $candidate) {
            $locations = array_map(static fn (array $location): array => ['newsgroup' => $location['newsgroup'],
                'article_locator' => $location['article_locator'], 'article_locator_is_decimal' => $location['article_locator_is_decimal'],
                'syntax_status' => $location['syntax_status']], $candidate['locations']);
            $candidates[] = self::xrefCandidate($candidate['syntax_status'], $candidate['server_name'], $locations,
                $candidate['source_line_indices'], $candidate['source_overview_row_index'], $candidate['source_overview_column_index']);
        }

        $projection = self::xrefSource($source['parse_status'], $candidates, $source['response_row_count']);
        $projection['occurrence_count'] = $source['occurrence_count'];

        return $projection;
    }
}
