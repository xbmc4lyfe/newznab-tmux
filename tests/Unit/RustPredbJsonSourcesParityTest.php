<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\ApibaySource;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\PredbOrgSource;
use App\Services\Predb\Feeds\Sources\PredbOvhSource;
use App\Services\Predb\Feeds\Sources\SrrdbSource;
use App\Services\Predb\Feeds\Sources\XrelSource;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

/**
 * Differential parity between the Rust JSON feed decoder and the exact json_decode paths of the
 * eight JSON PreDB sources: every body must produce identical entries (including Carbon state)
 * or identical exceptions, whether Rust decodes it or declines to PHP.
 */
final class RustPredbJsonSourcesParityTest extends TestCase
{
    private const CDEF = 'ssize_t nntmux_predb_json_feed_parse(const char *json, size_t json_len, uint32_t source, uint64_t limit, char *out_buf, size_t out_cap);';

    /** Odd JSON values substituted into every field of a base row. */
    private const ODD_VALUES = [
        'null', 'true', 'false', '0', '1', '-1', '2', '3', '4', '5', '7', '201', '207', '1.5', '-0.0', '0.0', '1.0', '1e3', '1E-2',
        '1700000000', '1700000000.9', '-1700000000', '253402300800', '9223372036854775807', '9223372036854775808', '-9223372036854775809',
        '1e400', '1e30', '0.0005', '0.285', '1.125', '1152', '2.675', '999999.995', '1048576', '1179648',
        '""', '" "', '"0"', '"1"', '"01"', '"007"', '" 1"', '"1 "', '"1.5"', '"1e3"', '"-5"', '"+5"', '"abc"', '"12abc"', '"-"',
        '"18446744073709551615"', '"18446744073709551616"', '"00000000000000000000042"', '"9223372036854775808"',
        '"error"', '"nuke"', '"UNNUKE"', '"undelpre"', '"ModNuke"', '"renuke"', '"oldnuke"', '"kb"', '"Gb"', '"TB"', '"MB"', '"RAR"',
        '"tv"', '"movie-hd"', '"0"', '"été"', '"😀"', '" \t Title.Name-GRP \n"', '"a\u001fb"', '"a\u001eb"', '"~1"',
        '"\ud83d"', '"\udc00x"', '"\ud83d\u0041"', '"\u12"', '"\uZZZZ"', '"\\"', '"a\/b\"c\\d"', '"\b\f\n\r\t"', '"\u0000"', '"\x41"',
        '"2026-10-09 22:13:12"', '" 2026-03-29 02:30:00 "', '"2026-10-25 02:30:00"', '"2026-13-45 25:61:61"', '"2026-1-9 2:3:4"', '"0000-00-00 00:00:00"',
        '"9999-12-31 23:59:59"', '"1969-12-31 23:59:59"', '"2026-10-09T22:13:12"', '"not a date"',
        '[]', '{}', '[1]', '{"a":1}', '{"0":1}', '{"number":1,"unit":"GB"}', '{"type":"nuke","reason":"dupe","typeId":1,"preId":5}',
        '{"meta_cat":"movies","sub_cat":"x264"}', '{"nuke_rls":1}', '{"nuke_rls":"0"}',
    ];

    /** @var array{native: int, php: int} */
    private array $engaged = ['native' => 0, 'php' => 0];

    /** @var Closure(): string */
    private Closure $responder;

    protected function setUp(): void
    {
        parent::setUp();
        if (RustPipelineLibrary::resolveDomain('predb_json_sources', self::CDEF) === null) {
            self::markTestSkipped('libnntmux_pipeline with JSON feed source exports is not built.');
        }
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(($this->responder)()));
    }

    protected function tearDown(): void
    {
        $this->setNative(null);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_fixtures_are_decoded_natively_and_match_php(): void
    {
        $fixtures = [
            [1, 'predatabase.json'], [2, 'xrel.json'], [3, 'xrel_p2p.json'], [4, 'srrdb.json'], [5, 'predb_ovh.json'],
            [6, 'predb_club.json'], [7, 'predb_net.json'], [8, 'predb_org.json'], [9, 'apibay.json'],
        ];
        foreach ($fixtures as [$source, $name]) {
            $body = (string) file_get_contents(base_path('tests/Fixtures/predb/'.$name));
            // The predataba.se fixture lists increasing ctimes, which PHP rejects, so Rust declines it.
            $this->assertSame($source !== 1, $this->assertParity($source, $body), $name);
        }
        $ordered = str_replace('1791589652', '1791589600', (string) file_get_contents(base_path('tests/Fixtures/predb/predatabase.json')));
        $this->assertTrue($this->assertParity(1, $ordered));
    }

    #[DataProvider('envelopes')]
    public function test_envelopes_and_malformed_documents_match_php(string $body): void
    {
        foreach (range(1, 9) as $source) {
            $this->assertParity($source, $body);
        }
    }

    /** @return array<string, array{string}> */
    public static function envelopes(): array
    {
        $row = '{"name":"A.B-GRP","rlsname":"A.B-GRP","release":"A.B-GRP","dirname":"A.B-GRP","category":"201","size":5,"ctime":1700000000,"preAt":1700000000}';
        $bodies = [
            'empty object' => '{}', 'empty list' => '[]', 'scalar' => '"x"', 'null' => 'null', 'empty' => '', 'whitespace' => " \n",
            'bom' => "\xEF\xBB\xBF[".$row.']', 'trailing' => '['.$row.'] x', 'trailing comma' => '['.$row.',]', 'leading zero' => '[01]',
            'invalid utf8' => "[{\"name\":\"A\xC3(-GRP\"}]", 'lone surrogate' => '[{"name":"\ud800"}]', 'bad escape' => '[{"name":"\x"}]',
            'raw control' => "[{\"name\":\"A\tB\"}]", 'deep' => str_repeat('[', 200).str_repeat(']', 200), 'single quotes' => "{'a':1}",
            'object list' => '{"0":'.$row.'}', 'nested object list' => '{"results":{"0":'.$row.'},"values":{"0":'.$row.'},"list":{"0":'.$row.'},"data":{"0":'.$row.',"rows":{"0":'.$row.'}}}',
            'empty containers' => '{"results":{},"values":{},"list":{},"data":{"rows":{}}}',
            'status error' => '{"status":"error","message":"Rate limit exceeded","data":{"rows":['.$row.']}}',
            'status error other' => '{"status":"error","message":"boom","data":['.$row.']}',
            'status escaped' => '{"status":"error","message":"x","data":{"rows":[]}}',
            'status ok' => '{"status":"success","data":{"rows":['.$row.'],"rowCount":1}}',
            'data list' => '{"data":['.$row.','.$row.']}', 'data null' => '{"data":null}', 'data rows string' => '{"data":{"rows":"x"}}',
            'duplicate keys' => '{"results":[],"results":['.$row.'],"values":[1],"values":['.$row.'],"list":"x","list":['.$row.'],"data":{"rows":[]},"data":{"rows":['.$row.']}}',
            'escaped keys' => '{"results":['.$row.'],"values":['.$row.'],"list":['.$row.'],"data":{"rows":['.$row.']}}',
            'cursor' => '{"results":['.$row.'],"next_page_id":"abc"}', 'empty cursor' => '{"results":['.$row.'],"next_page_id":""}',
            'numeric cursor' => '{"results":['.$row.'],"next_page_id":5}', 'array cursor' => '{"results":['.$row.'],"next_page_id":[]}',
            'separator cursor' => '{"results":['.$row.'],"next_page_id":"a\u001fb"}',
            'reversed' => '{"results":[{"rlsname":"A-G","ctime":5},{"rlsname":"B-G","ctime":6}]}',
            'unknown times' => '{"results":[{"rlsname":"A-G","ctime":9},{"ctime":0},{"rlsname":"B-G"},5,{"ctime":"7"},{"ctime":3}]}',
            'pagination' => '{"pagination":{"total_pages":"3"},"list":['.$row.']}', 'pagination past' => '{"pagination":{"total_pages":0},"list":"x"}',
            'pagination odd' => '{"pagination":{"total_pages":[1]},"list":['.$row.']}', 'pagination float' => '{"pagination":{"total_pages":1.9},"list":[]}',
            'pagination list' => '{"pagination":[3],"list":['.$row.']}', 'pagination string' => '{"pagination":"3","list":['.$row.']}',
            'non-object rows' => '{"results":[1,"x",null,[],[1],{}],"values":[1,[]],"list":[true],"data":{"rows":[[],2]}}',
            'apibay many' => '['.implode(',', array_fill(0, 30, $row)).']',
        ];

        return array_map(static fn (string $body): array => [$body], $bodies);
    }

    public function test_every_field_with_odd_values_matches_php(): void
    {
        foreach ($this->baseRows() as $source => $row) {
            foreach (array_keys($row) as $field) {
                foreach (self::ODD_VALUES as $value) {
                    $mutated = $row;
                    $mutated[$field] = $value;
                    $this->assertParity($source, $this->envelope($source, [$mutated]), $field.'='.$value);
                }
                $missing = $row;
                unset($missing[$field]);
                $this->assertParity($source, $this->envelope($source, [$missing]), 'missing '.$field);
            }
        }
        $this->assertGreaterThan($this->engaged['php'] * 2, $this->engaged['native'], 'Most single-field mutations stay on the Rust path.');
    }

    public function test_randomized_rows_match_php(): void
    {
        mt_srand(20261024);
        for ($iteration = 0; $iteration < 400; $iteration++) {
            foreach ($this->baseRows() as $source => $row) {
                $rows = [];
                for ($n = mt_rand(0, 6); $n > 0; $n--) {
                    $candidate = $row;
                    foreach (array_keys($candidate) as $field) {
                        $roll = mt_rand(0, 9);
                        if ($roll === 0) {
                            unset($candidate[$field]);
                        } elseif ($roll < 4) {
                            $candidate[$field] = self::ODD_VALUES[array_rand(self::ODD_VALUES)];
                        }
                    }
                    $rows[] = $candidate;
                }
                $this->assertParity($source, $this->envelope($source, $rows), 'iteration '.$iteration);
            }
        }
        $this->assertGreaterThan(400, $this->engaged['native']);
        $this->assertGreaterThan(400, $this->engaged['php']);
    }

    public function test_sizes_near_rounding_ties_match_php(): void
    {
        $values = ['0.005', '0.015', '0.285', '1.005', '1.115', '2.675', '1.125', '1.375', '0.0048828125', '0.0009765625', '999999.995', '1000000',
            '123456789.125', '0.000001', '1e-9', '5e-324', '1.7976931348623157e308', '0.99999999', '0.999999999999'];
        mt_srand(77);
        for ($i = 0; $i < 300; $i++) {
            $values[] = (string) (mt_rand(1, 400000) / 8);
            $values[] = (string) (mt_rand(1, 100000) / 1000 + 0.005);
            $values[] = (string) mt_rand(1, PHP_INT_MAX >> 10);
        }
        foreach ([1 => 'size', 3 => 'size_mb', 5 => 'size', 6 => 'size', 7 => 'size', 8 => 'size', 9 => 'size'] as $source => $field) {
            $rows = [];
            foreach ($values as $value) {
                $row = $this->baseRows()[$source];
                $row[$field] = $value;
                $rows[] = $row;
            }
            $this->assertParity($source, $this->envelope($source, $rows), $field);
        }
        foreach (['KB', 'MB', 'GB', 'TB'] as $unit) {
            $rows = [];
            foreach ($values as $value) {
                $row = $this->baseRows()[2];
                $row['size'] = '{"number":'.$value.',"unit":"'.$unit.'"}';
                $rows[] = $row;
            }
            $this->assertParity(2, $this->envelope(2, $rows), $unit);
        }
    }

    public function test_timestamps_and_srrdb_dates_match_php(): void
    {
        $times = ['0', '1', '-1', '86399', '951782400', '1700000000', '2147483647', '2147483648', '253402300799', '253402300800', '-62135596800', '99999999999999'];
        $dates = ['"2026-03-29 01:59:59"', '"2026-03-29 02:00:00"', '"2026-03-29 02:30:00"', '"2026-03-29 03:00:00"', '"2026-10-25 02:00:00"',
            '"2026-10-25 02:59:59"', '"2026-10-25 03:00:00"', '"1970-01-01 00:00:00"', '"1970-01-01 01:00:00"', '"1900-01-01 00:00:00"',
            '"2024-02-29 12:00:00"', '"2023-02-29 12:00:00"', '"2026-00-10 10:00:00"', '"2026-10-00 10:00:00"', '"2026-10-09 24:00:00"',
            '"9999-12-31 23:59:59"', '"0001-01-01 00:00:00"', '"2026-10-09 22:13:12 "', '"\t2026-10-09 22:13:12"', '"2026-10-09  22:13:12"'];
        foreach ([1 => 'ctime', 2 => 'time', 3 => 'pub_time', 5 => 'preAt', 6 => 'preAt', 7 => 'pretime', 8 => 'ctime', 9 => 'added'] as $source => $field) {
            $rows = [];
            foreach ($source === 1 ? array_reverse($times) : $times as $time) {
                $row = $this->baseRows()[$source];
                $row[$field] = $time;
                $rows[] = $row;
            }
            $this->assertParity($source, $this->envelope($source, $rows), $field);
        }
        $rows = array_map(fn (string $date): array => ['release' => '"R.'.md5($date).'-GRP"', 'date' => $date], $dates);
        foreach (['Europe/Brussels', 'UTC', 'America/New_York', 'Asia/Kolkata', '+02:00', 'Not/AZone'] as $timezone) {
            $this->assertParity(4, $this->envelope(4, $rows), $timezone, $timezone);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-02 03:04:05.678901', 'UTC'));
        $this->assertParity(4, $this->envelope(4, $rows), 'mocked clock');
        $this->assertParity(5, $this->envelope(5, [$this->baseRows()[5]]), 'mocked clock');
    }

    public function test_predatabase_cursor_history_matches_php(): void
    {
        $page = fn (array $times, ?string $next): string => '{"results":['.implode(',', array_map(
            static fn (int $t): string => '{"rlsname":"R'.$t.'-GRP","ctime":'.$t.'}', $times)).'],"next_page_id":'.($next === null ? 'null' : '"'.$next.'"').'}';
        $sequences = [
            'ordered' => [$page([90, 80], 'a'), $page([70, 60], 'b'), $page([50], null)],
            'reversed across pages' => [$page([90, 80], 'a'), $page([85, 60], 'b')],
            'repeated cursor' => [$page([90], 'a'), $page([80], 'a')],
            'empty pages' => [$page([], 'a'), $page([], 'b'), $page([], 'c'), $page([], 'd'), $page([], 'e'), $page([], 'f'), $page([], 'g')],
            'unknown times between' => ['{"results":[{"rlsname":"X-G"}],"next_page_id":"a"}', $page([90], 'b'), $page([95], null)],
        ];
        foreach ($sequences as $label => $bodies) {
            $run = function () use ($bodies): mixed {
                $index = 0;
                $this->responder = static function () use ($bodies, &$index): string {
                    return $bodies[min($index++, count($bodies) - 1)];
                };
                $source = new PredatabaseSource('https://predataba.se/api/search', pageSize: 10, apiKey: 'key');
                $pages = [];
                for ($p = 1; $p <= 3; $p++) {
                    $pages[] = $this->normalise(fn (): array => $source->fetch($p));
                }

                return $pages;
            };
            $this->assertSame($this->withNative(false, $run), $this->withNative(true, $run), $label);
        }
    }

    /** Asserts identical results with and without Rust; returns whether Rust decoded the body. */
    private function assertParity(int $source, string $body, string $label = '', string $timezone = 'Europe/Brussels'): bool
    {
        $run = function () use ($source, $body, $timezone): mixed {
            $this->responder = static fn (): string => $body;

            return $this->normalise(fn (): array => $this->source($source, $timezone)->fetch(1));
        };
        $this->assertSame($this->withNative(false, $run), $this->withNative(true, $run), $label.' '.$source.': '.substr($body, 0, 300));
        $native = PredatabaseSource::nativeJsonFields($body, $source, 7) !== null;
        $this->engaged[$native ? 'native' : 'php']++;

        return $native;
    }

    private function withNative(bool $native, Closure $run): mixed
    {
        $this->setNative($native ? null : false);
        try {
            return $run();
        } finally {
            $this->setNative(null);
        }
    }

    private function setNative(?bool $value): void
    {
        (new ReflectionProperty(PredatabaseSource::class, 'native'))->setValue(null, $value);
    }

    private function source(int $source, string $timezone): PredbFeedSource
    {
        return match ($source) {
            1 => new PredatabaseSource('https://predataba.se/api/search', pageSize: 10, apiKey: 'key'),
            2 => new XrelSource('https://api.xrel.to/v2/release/latest.json'),
            3 => new XrelSource('https://api.xrel.to/v2/p2p/releases.json', p2p: true),
            4 => new SrrdbSource('https://api.srrdb.com/v1/search/order:date-desc', timezone: $timezone),
            5 => new PredbOvhSource('https://predb.ovh/api/v1/'),
            6 => new PredbClubSource('https://predb.club/api/v1/'),
            7 => new PredbNetSource('https://api.predb.net/'),
            8 => new PredbOrgSource('https://predb.org/api/search'),
            default => new ApibaySource('https://apibay.org/precompiled/data_top100_recent.json', pageSize: 7),
        };
    }

    /** @param Closure(): list<PredbFeedEntry> $fetch */
    private function normalise(Closure $fetch): mixed
    {
        try {
            $entries = $fetch();
        } catch (Throwable $exception) {
            return [$exception::class, $exception->getMessage()];
        }

        return array_map(static function (PredbFeedEntry $entry): array {
            $fields = get_object_vars($entry);
            foreach (['predate', 'listedAt'] as $time) {
                if ($fields[$time] instanceof CarbonImmutable) {
                    $state = (array) $fields[$time];
                    unset($state["\0*\0constructedObjectId"]);
                    $fields[$time] = [$fields[$time]::class, $fields[$time]->format('Y-m-d H:i:s.u e T U'), $state];
                }
            }

            return $fields;
        }, $entries);
    }

    /** @return array<int, array<string, string>> Base rows of raw JSON values per source. */
    private function baseRows(): array
    {
        return [
            1 => ['rlsname' => '"Some.Release.2026.1080p.WEB.H264-GRP"', 'section' => '"TV-HD"', 'size' => '1234.5', 'files' => '42', 'ctime' => '1700000000'],
            2 => ['dirname' => '"Some.Release.S01E01.720p.HDTV.x264-GRP"', 'time' => '1700000000', 'size' => '{"number":2371,"unit":"MB"}',
                'ext_info' => '{"type":"tv","title":"Some"}', 'flags' => '{"nuke_rls":true,"fix_rls":false}'],
            3 => ['dirname' => '"Some.P2P.Release.2026.1080p.WEB-DL-GRP"', 'pub_time' => '1700000000', 'size_mb' => '4321.25',
                'category' => '{"meta_cat":"movies","sub_cat":"hd"}'],
            4 => ['release' => '"Some.Release-GRP"', 'date' => '"2026-10-09 22:13:12"', 'size' => '122400'],
            5 => ['id' => '42', 'name' => '"Some.Release-GRP"', 'team' => '"GRP"', 'cat' => '"TV"', 'size' => '2048000', 'files' => '30',
                'preAt' => '1700000000', 'nuke' => '{"id":9,"typeId":1,"type":"nuke","reason":"dupe","preId":42}'],
            6 => ['name' => '"Some.Release-GRP"', 'cat' => '"TV"', 'size' => '2048.75', 'files' => '30', 'preAt' => '1700000000',
                'nuke' => '{"type":"modnuke","reason":"bad.aspect"}'],
            7 => ['release' => '"Some.Release-GRP"', 'section' => '"X264"', 'size' => '700', 'files' => '15', 'pretime' => '1700000000',
                'status' => '1', 'reason' => '"dupe"'],
            8 => ['rlsname' => '"Some.Release-GRP"', 'section' => '"PRE"', 'size' => '1099', 'files' => '24', 'ctime' => '1700000000',
                'status' => '"0"', 'nukereason' => '""'],
            9 => ['name' => '"Some Movie 2026 1080p"', 'category' => '"207"', 'size' => '2147483648', 'num_files' => '3', 'added' => '1700000000'],
        ];
    }

    /** @param list<array<string, string>> $rows */
    private function envelope(int $source, array $rows): string
    {
        $list = '['.implode(',', array_map(static fn (array $row): string => '{'.implode(',', array_map(
            static fn (string $key, string $value): string => json_encode($key).':'.$value, array_keys($row), $row)).'}', $rows)).']';

        return match ($source) {
            1 => '{"results":'.$list.',"next_page_id":null}',
            2, 3 => '{"total_count":1,"pagination":{"current_page":1,"per_page":5,"total_pages":2},"list":'.$list.'}',
            4 => '{"results":'.$list.',"resultsCount":1}',
            5, 6 => '{"status":"success","message":"","data":{"rowCount":1,"rows":'.$list.'}}',
            7 => '{"status":"success","data":'.$list.'}',
            8 => '{"values":'.$list.',"total":1}',
            default => $list,
        };
    }
}
