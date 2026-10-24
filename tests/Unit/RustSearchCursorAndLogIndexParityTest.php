<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\SearchSuggestController;
use App\Http\Requests\SearchReleaseListRequest;
use App\Services\LogViewer\Index\LogSearchCoordinator;
use App\Services\LogViewer\Index\ManticoreLogSearcher;
use App\Services\LogViewer\LogEntry;
use App\Services\Search\DTO\SearchCursor;
use App\Services\Search\SearchCursorCodec;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Source contract: fork master 950ac7d53. SearchCursorCodec encrypts with Laravel's
 * Encrypter (AES-256-CBC + MAC); only the JSON payload step has a Rust candidate.
 * ReleaseIndexProjection is a query builder; its pure-CPU part is the driver-specific
 * aggregate SQL. LogEntry::toArray only builds a PHP array/delegates highlighting, so
 * the candidate is loggedAt(); fileStatus() has no arithmetic in the owned files.
 *
 * Paired screen: Apple M5, PHP 8.5.12, rustc 1.99.1, release LTO, CLI OPcache
 * off; two repetitions of 10,000 single-call pairs per workload. Conservative
 * speedups (minimum across repetitions), common mean/p99; uncommon mean/p99,
 * including dispatch, packing, FFI and (for the cursor) AES encryption:
 * cursorEncode:    0.805/0.576; 0.748/0.704
 * cursorDecode:    0.770/0.615; 0.706/0.678
 * aggregates:      0.138/0.070; 0.148/0.084
 * loggedAt:        7.439/16.168; 2.249/2.305   (adopted)
 * combinedEngine:  0.346/0.353; 0.174/0.153
 * hitPosition:     0.415/0.428; 0.457/0.428
 * sortSuggestions: 0.599/0.320; 3.042/3.163
 * categoryIds:     0.781/1.284; 2.209/1.784
 * trimmedInputs:   0.400/0.211; 0.552/0.510
 * numericRanges:   0.772/0.704; 0.618/0.406
 * Only loggedAt qualifies, so only LogEntry::loggedAt() defaults to Rust. Report-only
 * loggedAt rows: shapes Rust defers (invalid calendar dates) 0.691/0.655, and a
 * non-UTC default zone (FFI skipped) 0.973/0.990; neither occurs with canonical
 * Monolog headers under the default APP_TIMEZONE=UTC.
 * Reproduce with NNTMUX_WAVE15_BENCHMARK=1; raw pairs/provenance are saved outside
 * the checkout and their artifact path is printed by the opt-in test.
 */
final class RustSearchCursorAndLogIndexParityTest extends TestCase
{
    private const array METHODS = ['cursorEncode', 'cursorDecode', 'aggregates', 'loggedAt', 'combinedEngine', 'hitPosition', 'sortSuggestions', 'categoryIds', 'trimmedInputs', 'numericRanges'];

    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    public function test_cursor_payload_encoding_matches_json_encode(): void
    {
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1501));
        $strings = ['', 'query-hash', 'a/b', '"quoted"\\', "\x00\x01\x1f\x7f\t\n\r\x08\x0c", 'é日本語😀', '<>&\'', str_repeat('x', 3000)];
        $cursors = [
            new SearchCursor([1710000000, 42], 250, 'query-hash', 'elasticsearch', '7', 4102444800),
            new SearchCursor([], 0, '', '', '', 0),
            new SearchCursor([PHP_INT_MIN, PHP_INT_MAX, -0, '0', '01', 'x'], PHP_INT_MIN, 'q', 'manticore', 'g', PHP_INT_MAX),
        ];
        for ($i = 0; $i < 400; $i++) {
            $sort = [];
            for ($n = $random->getInt(0, 6); $n > 0; $n--) {
                $sort[] = $random->getInt(0, 1) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $strings[$random->getInt(0, count($strings) - 1)].$random->getBytesFromString('abc/"\\'."\n\x01\x7f", $random->getInt(1, 5));
            }
            $pick = static fn (): string => $strings[$random->getInt(0, count($strings) - 1)];
            $cursors[] = new SearchCursor($sort, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $pick(), $pick(), $pick(), $random->getInt(PHP_INT_MIN, PHP_INT_MAX));
        }
        foreach ($cursors as $cursor) {
            self::assertSame(SearchCursorCodec::encodePayloadPhp($cursor), SearchCursorCodec::encodePayloadNative($cursor));
        }
        foreach ([[1.5], [true], [null], [[1]], ['a' => 1], [1 => 2]] as $sort) {
            self::assertNull(SearchCursorCodec::encodePayloadNative(new SearchCursor($sort, 1, 'q', 'd', 'g', 1)));
        }
        $invalid = new SearchCursor(["bad\xff"], 1, 'q', 'd', 'g', 1);
        self::assertNull(SearchCursorCodec::encodePayloadNative($invalid));
        $codec = new SearchCursorCodec(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'), true);
        $this->expectException(JsonException::class);
        $codec->encodePayload($invalid);
    }

    public function test_cursor_payload_decoding_matches_php_casts_and_rejections(): void
    {
        $this->requireNative();
        $now = time();
        $future = $now + 100_000;
        $payloads = [
            '{"sort":[1710000000,42],"total":250,"query":"query-hash","driver":"elasticsearch","generation":"7","expires":'.$future.'}',
            '{"sort":[],"expires":'.$future.'}',
            ' { "sort" : [ "a\\/b" , -0 , "\\u00e9\\ud83d\\ude00\\u0000" ] , "expires" : '.$future.' } ',
            '{"sort":[1],"total":null,"query":null,"driver":null,"generation":null,"expires":'.$future.'}',
            '{"sort":[1],"sort":[2,3],"expires":1,"expires":'.$future.'}',
            '{"sort":[1],"expires":'.($now - 100).'}',
            '{"sort":[1]}',
            '{"sort":[1],"expires":null}',
            '{"expires":'.$future.'}',
            '{"sort":null,"expires":'.$future.'}',
            '{"sort":"x","expires":'.$future.'}',
            '{"sort":5,"expires":'.$future.'}',
            '{"sort":{"a":1,"b":"x"},"expires":'.$future.'}',
            '{"sort":[1.5],"expires":'.$future.'}',
            '{"sort":[true,null],"expires":'.$future.'}',
            '{"sort":[[1]],"expires":'.$future.'}',
            '{"sort":[9223372036854775808],"expires":'.$future.'}',
            '{"sort":[1],"total":"12abc","expires":'.$future.'}',
            '{"sort":[1],"total":1.9,"expires":'.$future.'}',
            '{"sort":[1],"query":42,"expires":'.$future.'}',
            '{"sort":[1],"expires":"'.$future.'"}',
            '{"sort":[1],"expires":1e5}',
            '[1,2]',
            '"text"',
            '42',
            'null',
            '',
            '{',
            '{"sort":[01],"expires":'.$future.'}',
            '{"sort":["\\ud800"],"expires":'.$future.'}',
            "{\"sort\":[\"bad\xff\"],\"expires\":{$future}}",
            "\xef\xbb\xbf{\"sort\":[],\"expires\":{$future}}",
            '{"sort":[1],"expires":'.$future.',"extra":{"deep":[1,{"x":null}]}}',
            '{"sort":'.str_repeat('[', 70).str_repeat(']', 70).',"expires":'.$future.'}',
        ];
        $random = new Randomizer(new Mt19937(1502));
        for ($i = 0; $i < 300; $i++) {
            $sort = [];
            for ($n = $random->getInt(0, 5); $n > 0; $n--) {
                $sort[] = match ($random->getInt(0, 5)) {
                    0, 3 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    1, 4 => $random->getBytesFromString("ab/\"\\é😀\n\x01", $random->getInt(1, 8)),
                    2 => $random->getFloat(-10, 10),
                    default => $random->getInt(0, 1) === 1,
                };
            }
            $payloads[] = json_encode(['sort' => $sort, 'total' => $random->getInt(-5, 5), 'query' => 'q'.$i, 'driver' => 'manticore', 'generation' => (string) $i, 'expires' => $now + $random->getInt(-1000, 100_000)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        }
        $handled = 0;
        $codec = new SearchCursorCodec(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'), true);
        foreach ($payloads as $payload) {
            $expected = $this->outcome(static fn (): SearchCursor => SearchCursorCodec::decodePayloadPhp($payload));
            $native = $this->outcome(static fn (): ?SearchCursor => SearchCursorCodec::decodePayloadNative($payload, $now));
            if ($native !== ['deferred']) {
                $handled++;
                self::assertSame($expected, $native, $payload);
            }
            self::assertSame($expected, $this->outcome(static fn (): SearchCursor => $codec->decodePayload($payload)), $payload);
        }
        self::assertGreaterThan(100, $handled);
    }

    public function test_cursor_codec_round_trips_through_encryption_for_both_toggles(): void
    {
        $this->requireNative();
        $encrypter = new Encrypter(str_repeat('k', 32), 'AES-256-CBC');
        $cursor = new SearchCursor([1710000000, 'é/x', 42], 250, 'query-hash', 'elasticsearch', '7', 4102444800);
        foreach ([false, true] as $enabled) {
            $codec = new SearchCursorCodec($encrypter, $enabled);
            $token = $codec->encode($cursor);
            self::assertSame(SearchCursorCodec::encodePayloadPhp($cursor), $encrypter->decryptString($token));
            self::assertSame(get_object_vars($cursor), get_object_vars($codec->decode($token)));
            foreach (['tampered', $encrypter->encryptString('{'), $encrypter->encryptString('{"sort":[1],"expires":1}')] as $bad) {
                try {
                    $codec->decode($bad);
                    self::fail('Expected an invalid cursor.');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('search cursor', $e->getMessage());
                }
            }
        }
    }

    public function test_projection_aggregates_match_existing_sql(): void
    {
        $this->requireNative();
        foreach ([false, true] as $sqlite) {
            self::assertSame(ReleaseIndexProjection::aggregateExpressionsPhp($sqlite), ReleaseIndexProjection::aggregateExpressionsNative($sqlite));
            self::assertSame(ReleaseIndexProjection::aggregateExpressionsPhp($sqlite), ReleaseIndexProjection::aggregateExpressions($sqlite, true));
        }
        self::assertSame("COALESCE((SELECT GROUP_CONCAT(rf.name SEPARATOR ' ') FROM release_files rf WHERE rf.releases_id = r.id), '')", ReleaseIndexProjection::aggregateExpressionsPhp(false)[0]);
        self::assertSame("cp.title || ' > ' || c.title", ReleaseIndexProjection::aggregateExpressionsPhp(true)[5]);
    }

    public function test_logged_at_matches_strtotime(): void
    {
        $this->requireNative();
        $samples = [null, '2026-10-24 10:00:00', '2026-10-24T10:00:00', '2026-10-24 10:00:00.123456', '2026-10-24 10:00:00.'.str_repeat('1', 300), '2026-10-24 10:00:00+02:00', '2026-10-24 10:00:00+0200', '2026-10-24 10:00:00Z', '2026-10-24 10:00:00-1130', '2026-10-24 10:00:00+1500', '2026-10-24 10:00:00+2359', '2026-10-24 10:00:00+9959', '2026-02-30 10:00:00', '2024-02-29 23:59:59', '2026-13-01 10:00:00', '2026-10-24 24:00:00', '2026-10-24 23:59:60', '0000-01-01 00:00:00', '9999-12-31 23:59:59', '2026-10-24 10:00:00.5Z', '2026-10-24 10:00:00+02:60', '2026-00-10 10:00:00', '2026-10-00 10:00:00', '1969-12-31 23:59:59', '2026-10-24 10:00:00.', '2026-10-24 10:00:00 ', 'garbage', '', '2026-10-24t10:00:00', '2026-10-24 10:00:00z', '2026-10-24 10:00:00+02'];
        $random = new Randomizer(new Mt19937(1503));
        for ($i = 0; $i < 400; $i++) {
            $samples[] = sprintf('%04d-%02d-%02d%s%02d:%02d:%02d%s%s', $random->getInt(0, 9999), $random->getInt(0, 13), $random->getInt(0, 32), [' ', 'T'][$i % 2], $random->getInt(0, 24), $random->getInt(0, 60), $random->getInt(0, 60), $i % 3 === 0 ? '.'.$random->getInt(0, 999999) : '', ['', 'Z', sprintf('%s%02d:%02d', ['+', '-'][$i % 2], $random->getInt(0, 24), $random->getInt(0, 60)), sprintf('-%02d%02d', $random->getInt(0, 14), $random->getInt(0, 59))][$i % 4]);
        }
        $handled = 0;
        foreach (['UTC', 'Europe/Amsterdam'] as $zone) {
            date_default_timezone_set($zone);
            foreach ($samples as $timestamp) {
                $entry = new LogEntry(0, 1, null, $timestamp, null, null, '', '', false);
                $supported = false;
                $native = $entry->loggedAtNative($supported);
                if ($supported) {
                    $handled++;
                    self::assertSame($entry->loggedAtPhp(), $native, (string) $timestamp);
                }
                self::assertSame($entry->loggedAtPhp(), $entry->loggedAt(true), (string) $timestamp);
                self::assertSame($entry->loggedAtPhp(), $entry->loggedAt(), (string) $timestamp);
                self::assertSame($entry->loggedAtPhp(), $entry->loggedAt(false), (string) $timestamp);
                if ($zone !== 'UTC' && $timestamp !== null) {
                    self::assertFalse($supported);
                }
            }
        }
        self::assertGreaterThan(150, $handled);
    }

    public function test_log_index_helpers_match_php(): void
    {
        $this->requireNative();
        foreach ([[], ['manticore'], ['manticore', 'manticore'], ['manticore', 'grep'], ['grep', 'rg', 'grep'], [''], ['', ''], ["nul\0x"], [1], ['1', 1]] as $engines) {
            self::assertSame(LogSearchCoordinator::combinedEnginePhp($engines), LogSearchCoordinator::combinedEngine($engines, true));
            $native = LogSearchCoordinator::combinedEngineNative($engines);
            if ($native !== null) {
                self::assertSame(LogSearchCoordinator::combinedEnginePhp($engines), $native);
            }
        }
        $random = new Randomizer(new Mt19937(1504));
        $hits = [['byte_offset' => 10], ['byte_offset' => 10, 'byte_end' => 20, 'line_no' => 3], ['byte_offset' => 0, 'byte_end' => null, 'line_no' => 0], ['byte_offset' => PHP_INT_MAX, 'byte_end' => PHP_INT_MIN, 'line_no' => -5], ['byte_offset' => '10', 'byte_end' => '20', 'line_no' => '3'], ['byte_offset' => 1.9, 'line_no' => null]];
        for ($i = 0; $i < 200; $i++) {
            $hits[] = ['byte_offset' => $random->getInt(PHP_INT_MIN, PHP_INT_MAX), 'byte_end' => $i % 3 === 0 ? null : $random->getInt(PHP_INT_MIN, PHP_INT_MAX), 'line_no' => $random->getInt(-3, 1000)];
        }
        foreach ($hits as $hit) {
            self::assertSame(ManticoreLogSearcher::hitPositionPhp($hit), ManticoreLogSearcher::hitPosition($hit, true));
            $native = ManticoreLogSearcher::hitPositionNative($hit);
            if ($native !== null) {
                self::assertSame(ManticoreLogSearcher::hitPositionPhp($hit), $native);
            }
        }
    }

    public function test_suggestion_order_matches_stable_usort(): void
    {
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1505));
        $lists = [[], [['suggest' => 'a', 'docs' => 1]], [['suggest' => 'a', 'docs' => 1], ['suggest' => 'b', 'docs' => 1]], [5 => ['suggest' => 'a', 'docs' => 1], 2 => ['suggest' => 'b', 'docs' => 9]], [['suggest' => 'a', 'docs' => PHP_INT_MAX], ['suggest' => 'b', 'docs' => 1]], [['suggest' => 'a', 'docs' => '3'], ['suggest' => 'b', 'docs' => 1]]];
        for ($i = 0; $i < 300; $i++) {
            $list = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $list[] = ['suggest' => 's'.$random->getInt(0, 99), 'distance' => $random->getInt(0, 3), 'docs' => $random->getInt(-3, $i % 2 === 0 ? 5 : 1 << 40)];
            }
            $lists[] = $list;
        }
        foreach ($lists as $list) {
            self::assertSame(SearchSuggestController::sortSuggestionsPhp($list), SearchSuggestController::sortSuggestions($list, true));
            $native = SearchSuggestController::sortSuggestionsNative($list);
            if ($native !== null) {
                self::assertSame(SearchSuggestController::sortSuggestionsPhp($list), $native);
            }
        }
        self::assertNull(SearchSuggestController::sortSuggestionsNative([['suggest' => 'a', 'docs' => PHP_INT_MAX], ['suggest' => 'b', 'docs' => 1]]));
    }

    public function test_list_request_helpers_match_php(): void
    {
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1506));
        $categories = [null, 5030, '', '0', '-1', '5030', '5030,5040,5030', '0,0', '007,7', '99999999999999999999', '9223372036854775807,9223372036854775808', '-5', '1,,2', ',', '1, 2', 'abc', "1\x002"];
        for ($i = 0; $i < 200; $i++) {
            $ids = [];
            for ($n = $random->getInt(1, 30); $n > 0; $n--) {
                $ids[] = (string) $random->getInt(0, $i % 2 === 0 ? 9000 : PHP_INT_MAX);
            }
            $categories[] = implode(',', $ids);
        }
        foreach ($categories as $value) {
            self::assertSame(SearchReleaseListRequest::categoryIdsPhp($value), SearchReleaseListRequest::categoryIdsFrom($value, true));
            $native = SearchReleaseListRequest::categoryIdsNative($value);
            if ($native !== null) {
                self::assertSame(SearchReleaseListRequest::categoryIdsPhp($value), $native);
            }
        }

        $whitespace = [' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", 'é'];
        $inputs = [[], ['poster' => ' a ', 'searchadvposter' => ' b ', 'search' => ' c '], ['searchadvposter' => ' b ', 0 => ' x ', 'n' => 5, 'arr' => [' y ']], ['a' => '', 'b' => '   ', 'c' => "\x0C", 'd' => "\0x\0"]];
        for ($i = 0; $i < 200; $i++) {
            $input = [];
            for ($n = $random->getInt(0, 12); $n > 0; $n--) {
                $input['f'.$random->getInt(0, 20)] = $random->getInt(0, 5) === 0 ? $random->getInt(0, 9) : implode('', array_map(static fn (): string => $whitespace[$random->getInt(0, count($whitespace) - 1)], range(0, $random->getInt(0, 3)))).$random->getBytesFromString('ab c', $random->getInt(1, 4));
            }
            $inputs[] = $input;
        }
        foreach ($inputs as $input) {
            foreach ([false, true] as $preserve) {
                self::assertSame(SearchReleaseListRequest::trimmedInputsPhp($input, $preserve), SearchReleaseListRequest::trimmedInputsNative($input, $preserve));
                self::assertSame(SearchReleaseListRequest::trimmedInputsPhp($input, $preserve), SearchReleaseListRequest::trimmedInputs($input, $preserve, true));
            }
        }

        $numbers = ['0', '1', '1.5', '0.0000001', '-0.4', '+5', '.5', '5.', '+.5', '1e3', '1E-3', '8796093022207', '4294967295', '-1', '007', ' 5', '5 ', 'abc', '-', '+-5', '0x1A', '', null];
        $sets = [[]];
        foreach ($numbers as $number) {
            $sets[] = ['minsize' => $number, 'maxsize' => $number, 'minfiles' => $number, 'maxgrabs' => $number, 'maxcomments' => $number];
        }
        for ($i = 0; $i < 200; $i++) {
            $set = [];
            foreach (['minsize', 'maxsize', 'minfiles', 'maxfiles', 'mingrabs', 'maxgrabs', 'mincomments', 'maxcomments'] as $key) {
                $set[$key] = match ($random->getInt(0, 3)) {
                    0 => null,
                    1 => (string) $random->getInt(0, 4294967295),
                    2 => $random->getInt(0, 99999).'.'.$random->getInt(0, 99),
                    default => $numbers[$random->getInt(0, count($numbers) - 1)],
                };
            }
            $sets[] = $set;
        }
        $sets[] = ['minsize' => 5, 'maxfiles' => 7];
        $sets[] = ['minfiles' => '99999999999999999999', 'maxfiles' => '-99999999999999999999', 'mingrabs' => '1e3'];
        foreach ($sets as $set) {
            self::assertSame(SearchReleaseListRequest::numericRangesPhp($set), SearchReleaseListRequest::numericRanges($set, true), json_encode($set, JSON_THROW_ON_ERROR));
            $native = SearchReleaseListRequest::numericRangesNative($set);
            if ($native !== null) {
                self::assertSame(SearchReleaseListRequest::numericRangesPhp($set), $native, json_encode($set, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function test_native_unavailability_falls_back_without_changing_results(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            date_default_timezone_set('UTC');
            $cursor = new \App\Services\Search\DTO\SearchCursor([1, 'a'], 2, 'q', 'd', 'g', 4102444800);
            $codec = new \App\Services\Search\SearchCursorCodec(new \Illuminate\Encryption\Encrypter(str_repeat('k', 32), 'AES-256-CBC'), true);
            echo json_encode([
                $codec->encodePayload($cursor),
                get_object_vars($codec->decode($codec->encode($cursor))),
                \App\Services\Search\Support\ReleaseIndexProjection::aggregateExpressions(true, true)[5],
                (new \App\Services\LogViewer\LogEntry(0, 1, null, '2026-10-24 10:00:00', null, null, '', '', false))->loggedAt(true),
                \App\Services\LogViewer\Index\LogSearchCoordinator::combinedEngine(['grep', 'manticore'], true),
                \App\Services\LogViewer\Index\ManticoreLogSearcher::hitPosition(['byte_offset' => 5, 'line_no' => 2], true),
                \App\Http\Controllers\SearchSuggestController::sortSuggestions([['suggest' => 'a', 'docs' => 1], ['suggest' => 'b', 'docs' => 2]], true),
                \App\Http\Requests\SearchReleaseListRequest::categoryIdsFrom('3,3,4', true),
                \App\Http\Requests\SearchReleaseListRequest::trimmedInputs(['a' => ' x '], false, true),
                \App\Http\Requests\SearchReleaseListRequest::numericRanges(['minsize' => '1', 'maxfiles' => '2'], true),
                \App\Http\Requests\SearchReleaseListRequest::categoryIdsNative('3'),
            ], JSON_THROW_ON_ERROR);
            PHP;
        $process = proc_open([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code, __DIR__.'/../bootstrap.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame([
            '{"sort":[1,"a"],"total":2,"query":"q","driver":"d","generation":"g","expires":4102444800}',
            ['sortValues' => [1, 'a'], 'total' => 2, 'queryHash' => 'q', 'driver' => 'd', 'indexGeneration' => 'g', 'expiresAt' => 4102444800],
            "cp.title || ' > ' || c.title",
            1792836000,
            'mixed',
            [5, 5, 2],
            [['suggest' => 'b', 'docs' => 2], ['suggest' => 'a', 'docs' => 1]],
            [3, 4],
            ['a' => 'x'],
            ['size' => ['min' => 1048576], 'totalpart' => ['max' => 2]],
            null,
        ], json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Opt in: NNTMUX_WAVE15_BENCHMARK=1 php artisan test --compact tests/Unit/RustSearchCursorAndLogIndexParityTest.php
     * Two repetitions, each with 500 warmups and 10,000 randomized single-call pairs per
     * workload. Includes PHP dispatch, packing, FFI and allocation; cursor workloads use
     * the real codec including AES encryption/decryption. Every raw pair, fixture, order,
     * source/runtime identity and p99 is saved to an owned temporary artifact.
     */
    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_WAVE15_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in paired benchmark.');
        }
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1501));
        $encrypter = new Encrypter(str_repeat('k', 32), 'AES-256-CBC');
        $codecs = [new SearchCursorCodec($encrypter, false), new SearchCursorCodec($encrypter, true)];
        $artifact = ['protocol' => 'wave15-v1', 'seed' => 1501, 'repetitions' => 2, 'warmups' => 500, 'pairs_per_repetition' => 10000, 'p99' => 'nearest-rank single-call latency', 'profile' => 'cargo release opt-level=3,lto=true,codegen-units=1', 'php' => PHP_VERSION, 'host' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'rustc' => trim((string) shell_exec('rustc --version')), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'sources' => [], 'results' => [], 'failures' => []];
        foreach (['app/Services/Search/SearchCursorCodec.php', 'app/Services/Search/Support/ReleaseIndexProjection.php', 'app/Services/LogViewer/LogEntry.php', 'app/Services/LogViewer/Index/LogSearchCoordinator.php', 'app/Services/LogViewer/Index/ManticoreLogSearcher.php', 'app/Http/Controllers/SearchSuggestController.php', 'app/Http/Requests/SearchReleaseListRequest.php', 'tests/Unit/RustSearchCursorAndLogIndexParityTest.php', 'rust/pipeline-ffi/src/search.rs', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock'] as $source) {
            $artifact['sources'][$source] = hash_file('sha256', dirname(__DIR__, 2).'/'.$source);
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        foreach (['storage/app/nntmux-native', 'rust/pipeline-ffi/target/release', '/usr/local/lib/nntmux'] as $directory) {
            $library = str_starts_with($directory, '/') ? $directory.'/libnntmux_pipeline.'.$suffix : dirname(__DIR__, 2).'/'.$directory.'/libnntmux_pipeline.'.$suffix;
            if (is_file($library)) {
                $artifact['sources'][$library] = hash_file('sha256', $library);
            }
        }
        $artifactPath = tempnam(sys_get_temp_dir(), 'nntmux-wave15-');
        self::assertNotFalse($artifactPath);
        $workloads = [];
        try {
            foreach (['common', 'uncommon'] as $kind) {
                $rare = $kind === 'uncommon';
                $cursors = $rare
                    ? [new SearchCursor(array_map(static fn (int $i): string => 'é/'.$i.'😀"', range(1, 40)), PHP_INT_MAX, str_repeat('h', 64), 'elasticsearch', '9999', 4102444800), new SearchCursor([], 0, '', '', '', 4102444800)]
                    : [new SearchCursor([1710000000, 42], 250, hash('xxh128', 'q'), 'manticore', '7', 4102444800), new SearchCursor([1720000000, 9], 1000, hash('xxh128', 'r'), 'elasticsearch', '8', 4102444800)];
                $tokens = array_map(static fn (SearchCursor $cursor): string => $codecs[0]->encode($cursor), $cursors);
                $timestamps = $rare ? ['2026-10-24 10:00:00.'.str_repeat('1', 300), '2024-02-29T23:59:59-11:30', '0001-01-01 00:00:00+0000'] : ['2026-10-24 10:00:00', '2026-10-24 10:00:00.123456+02:00', '2026-10-24T10:00:00Z'];
                $entries = array_map(static fn (string $timestamp): LogEntry => new LogEntry(0, 1, null, $timestamp, null, null, '', '', false), $timestamps);
                $engines = $rare ? [array_fill(0, 200, 'manticore'), [...array_fill(0, 100, 'manticore'), 'grep']] : [['manticore'], ['manticore', 'grep'], ['grep', 'grep']];
                $hits = $rare ? [['byte_offset' => PHP_INT_MAX, 'byte_end' => null, 'line_no' => -1], ['byte_offset' => 0, 'line_no' => 0]] : [['byte_offset' => 1024, 'byte_end' => 2048, 'line_no' => 12], ['byte_offset' => 99, 'byte_end' => 120, 'line_no' => 3]];
                $suggestions = $rare
                    ? [array_map(static fn (int $i): array => ['suggest' => 'term'.$i, 'distance' => $i % 3, 'docs' => ($i * 7919) % 101], range(1, 200)), array_map(static fn (int $i): array => ['suggest' => 'tie'.$i, 'distance' => 1, 'docs' => 5], range(1, 100))]
                    : [[['suggest' => 'matrix', 'distance' => 1, 'docs' => 120], ['suggest' => 'matrlx', 'distance' => 2, 'docs' => 4]], array_map(static fn (int $i): array => ['suggest' => 's'.$i, 'distance' => 1, 'docs' => $i * 3 % 7], range(1, 5))];
                $categories = $rare ? [implode(',', range(1000, 1300)), implode(',', array_fill(0, 200, '5030')), '99999999999999999999'] : ['5030', '5030,5040', '2000,2010,2020', '-1'];
                $inputs = $rare
                    ? [array_combine(array_map(static fn (int $i): string => 'f'.$i, range(1, 60)), array_map(static fn (int $i): string => str_repeat(' ', $i % 5).'value '.$i.str_repeat("\t", $i % 3), range(1, 60))), ['search' => str_repeat(' long query text ', 60), 'poster' => ' p ', 'searchadvposter' => ' q ']]
                    : [['search' => ' matrix ', 't' => '2000', 'ob' => 'posted_desc'], ['search' => 'ubuntu', 'search_type' => 'basic', 'page' => '2', 'minsize' => ' ']];
                $ranges = $rare
                    ? [['minsize' => '0.0000001', 'maxsize' => '8796093021', 'minfiles' => '4294967295', 'maxfiles' => '4294967295', 'mingrabs' => '0', 'maxgrabs' => '1', 'mincomments' => '0', 'maxcomments' => '7'], ['minsize' => '1e3', 'maxsize' => '.5']]
                    : [[], ['minsize' => '100', 'maxsize' => '4000'], ['mingrabs' => '1']];
                $artifact['fixtures'][$kind] = ['cursors' => array_map(static fn (SearchCursor $cursor): array => get_object_vars($cursor), $cursors), 'timestamps' => $timestamps, 'engines' => $engines, 'hits' => $hits, 'suggestions' => $suggestions, 'categories' => $categories, 'inputs' => $inputs, 'ranges' => $ranges];
                $workloads['cursorEncode/'.$kind] = [$cursors, static fn ($c) => $encrypter->decryptString($codecs[0]->encode($c)), static fn ($c) => $encrypter->decryptString($codecs[1]->encode($c)), static fn ($c) => $codecs[0]->encode($c), static fn ($c) => $codecs[1]->encode($c)];
                $workloads['cursorDecode/'.$kind] = [$tokens, static fn ($t) => get_object_vars($codecs[0]->decode($t)), static fn ($t) => get_object_vars($codecs[1]->decode($t))];
                $workloads['aggregates/'.$kind] = [$rare ? [true] : [false], static fn ($s) => ReleaseIndexProjection::aggregateExpressions($s, false), static fn ($s) => ReleaseIndexProjection::aggregateExpressions($s, true)];
                $workloads['loggedAt/'.$kind] = [$entries, static fn (LogEntry $e) => $e->loggedAt(false), static fn (LogEntry $e) => $e->loggedAt(true)];
                $workloads['combinedEngine/'.$kind] = [$engines, static fn ($e) => LogSearchCoordinator::combinedEngine($e, false), static fn ($e) => LogSearchCoordinator::combinedEngine($e, true)];
                $workloads['hitPosition/'.$kind] = [$hits, static fn ($h) => ManticoreLogSearcher::hitPosition($h, false), static fn ($h) => ManticoreLogSearcher::hitPosition($h, true)];
                $workloads['sortSuggestions/'.$kind] = [$suggestions, static fn ($s) => SearchSuggestController::sortSuggestions($s, false), static fn ($s) => SearchSuggestController::sortSuggestions($s, true)];
                $workloads['categoryIds/'.$kind] = [$categories, static fn ($c) => SearchReleaseListRequest::categoryIdsFrom($c, false), static fn ($c) => SearchReleaseListRequest::categoryIdsFrom($c, true)];
                $workloads['trimmedInputs/'.$kind] = [$inputs, static fn ($i) => SearchReleaseListRequest::trimmedInputs($i, isset($i['poster']), false), static fn ($i) => SearchReleaseListRequest::trimmedInputs($i, isset($i['poster']), true)];
                $workloads['numericRanges/'.$kind] = [$ranges, static fn ($r) => SearchReleaseListRequest::numericRanges($r, false), static fn ($r) => SearchReleaseListRequest::numericRanges($r, true)];
            }
            // Report-only: shapes Rust defers to PHP, and a non-UTC default zone.
            $deferred = array_map(static fn (string $timestamp): LogEntry => new LogEntry(0, 1, null, $timestamp, null, null, '', '', false), ['2026-02-30 10:00:00', '2026-10-24 24:00:00', '2026-10-24 10:00:00+9959']);
            $artifact['fixtures']['loggedAtReportOnly'] = ['deferred' => ['2026-02-30 10:00:00', '2026-10-24 24:00:00', '2026-10-24 10:00:00+9959'], 'non-utc' => 'Europe/Amsterdam with the common timestamps'];
            $workloads['loggedAt/deferred'] = [$deferred, static fn (LogEntry $e) => $e->loggedAt(false), static fn (LogEntry $e) => $e->loggedAt(true)];
            $workloads['loggedAt/non-utc'] = [$workloads['loggedAt/common'][0], static fn (LogEntry $e) => $e->loggedAt(false), static fn (LogEntry $e) => $e->loggedAt(true)];
            $zones = ['loggedAt/non-utc' => 'Europe/Amsterdam'];
            for ($repeat = 0; $repeat < 2; $repeat++) {
                foreach ($random->shuffleArray(array_keys($workloads)) as $name) {
                    $workload = $workloads[$name];
                    date_default_timezone_set($zones[$name] ?? 'UTC');
                    [$inputs, $checkPhp, $checkRust] = $workload;
                    // Encryption is randomized, so encode parity compares decrypted payloads.
                    [$php, $rust] = count($workload) === 5 ? [$workload[3], $workload[4]] : [$checkPhp, $checkRust];
                    foreach ($inputs as $input) {
                        self::assertSame($checkPhp($input), $checkRust($input), $name);
                    }
                    for ($i = 0; $i < 500; $i++) {
                        $input = $inputs[$i % count($inputs)];
                        $php($input);
                        $rust($input);
                    }
                    $pairs = [];
                    for ($i = 0; $i < 10000; $i++) {
                        $fixture = $random->getInt(0, count($inputs) - 1);
                        $input = $inputs[$fixture];
                        $first = $random->getInt(0, 1);
                        $pair = [];
                        foreach ([$first, 1 - $first] as $side) {
                            $start = hrtime(true);
                            ($side === 0 ? $php : $rust)($input);
                            $pair[$side] = hrtime(true) - $start;
                        }
                        $pairs[] = [$pair[0], $pair[1], $fixture, $first];
                    }
                    $p = array_column($pairs, 0);
                    $r = array_column($pairs, 1);
                    sort($p);
                    sort($r);
                    $result = ['repeat' => $repeat + 1, 'php_mean_ns' => array_sum($p) / count($p), 'rust_mean_ns' => array_sum($r) / count($r), 'php_p99_ns' => $p[9899], 'rust_p99_ns' => $r[9899], 'mean_speedup' => array_sum($p) / array_sum($r), 'p99_speedup' => $p[9899] / $r[9899], 'pairs_ns' => $pairs];
                    $artifact['results'][$name][] = $result;
                    fwrite(STDERR, sprintf("%s repeat %d mean %.3fx p99 %.3fx\n", $name, $repeat + 1, $result['mean_speedup'], $result['p99_speedup']));
                }
            }
            date_default_timezone_set('UTC');
            // Qualification is conservative: every metric in both repetitions must pass.
            foreach (self::METHODS as $method) {
                $ratios = [];
                foreach (['common', 'uncommon'] as $kind) {
                    foreach ($artifact['results'][$method.'/'.$kind] as $result) {
                        $ratios[] = $result['mean_speedup'];
                        $ratios[] = $result['p99_speedup'];
                    }
                }
                $artifact['qualified'][$method] = min($ratios) >= 1.5;
                fwrite(STDERR, sprintf("%s minimum %.3fx qualified=%s\n", $method, min($ratios), $artifact['qualified'][$method] ? 'yes' : 'no'));
            }
        } catch (\Throwable $error) {
            $artifact['failures'][] = $error->getMessage();
            throw $error;
        } finally {
            file_put_contents($artifactPath, json_encode($artifact, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            fwrite(STDERR, 'Raw benchmark artifact: '.$artifactPath.PHP_EOL);
        }
    }

    /**
     * @param  callable(): ?SearchCursor  $decode
     * @return list<mixed>
     */
    private function outcome(callable $decode): array
    {
        try {
            $cursor = $decode();

            return $cursor === null ? ['deferred'] : ['ok', get_object_vars($cursor)];
        } catch (InvalidArgumentException $e) {
            return ['error', $e->getMessage(), $e->getPrevious() === null ? null : $e->getPrevious()::class];
        }
    }

    private function requireNative(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_search_projection_aggregates(uint32_t sqlite, char *out, size_t cap);');
        self::assertNotNull($ffi, 'Build the release Rust library before running parity checks.');

        return $ffi;
    }
}
