<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\IrcScraperCommand;
use App\Console\Commands\PredbImportFeed;
use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Stream\PredbStreamListener;
use App\Services\Predb\Stream\WebSocketClient;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Parity and paired benchmark evidence for the PreDB feed entry, importer, stream, import-feed
 * and IRC supervisor Rust helpers in rust/pipeline-ffi/src/predb.rs.
 *
 * Paired randomized benchmark (Apple M5, macOS, PHP 8.5.12 CLI with opcache, rustc 1.99.1 release
 * profile with LTO and one codegen unit, base commit 1ffaec04d). Each run: 2 repetitions x 2000
 * paired samples per workload, sample order randomized, 8 calls per sample, 50 warm-up calls per
 * input per repetition; "Rust" is the production dispatcher with the toggle on (FFI, decoding and
 * any PHP fallback included). Per-call mean (p99), two independent runs:
 *
 * | Method / workload                     | Run 1 Rust          | Run 1 PHP           | Run 1 mean / p99 | Run 2 mean / p99 | Production |
 * | ------------------------------------- | ------------------- | ------------------- | ---------------- | ---------------- | ---------- |
 * | PredbStreamListener club common       | 1209.8 (1666.6) ns  | 2531.6 (3406.2) ns  | 2.09x / 2.04x    | 2.06x / 2.04x    | Rust FFI   |
 * | PredbStreamListener club uncommon     | 1033.8 (1854.2) ns  | 1998.5 (3687.5) ns  | 1.93x / 1.99x    | 1.93x / 1.81x    | Rust FFI   |
 * | PredbStreamListener net common        | 1075.1 (1432.2) ns  | 2053.1 (2718.8) ns  | 1.91x / 1.90x    | 1.90x / 1.58x    | Rust FFI   |
 * | PredbStreamListener net uncommon      | 1004.8 (1536.5) ns  | 1903.1 (3166.6) ns  | 1.89x / 2.06x    | 1.88x / 1.67x    | Rust FFI   |
 * | PredbFeedEntry::sizeFromMegabytes     | 234.7 (302.0) ns    | 206.6 (260.4) ns    | 0.88x / 0.86x    | 0.84x / 0.52x    | PHP        |
 * |   uncommon                            | 299.5 (552.1) ns    | 198.8 (322.9) ns    | 0.66x / 0.58x    | 0.64x / 0.49x    |            |
 * | PredbFeedEntry::filesFromCount        | 255.2 (593.8) ns    | 36.1 (46.9) ns      | 0.14x / 0.08x    | 0.14x / 0.16x    | PHP        |
 * |   uncommon                            | 231.4 (375.0) ns    | 45.2 (62.5) ns      | 0.20x / 0.17x    | 0.20x / 0.20x    |            |
 * | PredbFeedImporter::changesFor         | 3706.2 (4937.5) ns  | 2881.2 (3895.9) ns  | 0.78x / 0.79x    | 0.79x / 0.82x    | PHP        |
 * |   uncommon                            | 4837.2 (15098.9) ns | 3826.5 (10453.1) ns | 0.79x / 0.69x    | 0.81x / 0.73x    |            |
 * | PredbImportFeed::parseSince           | 1978.8 (3682.2) ns  | 1881.6 (3630.1) ns  | 0.95x / 0.99x    | 0.95x / 0.96x    | PHP        |
 * |   uncommon                            | 1320.4 (3510.4) ns  | 1435.3 (3442.8) ns  | 1.09x / 0.98x    | 1.09x / 0.96x    |            |
 * | PredbImportFeed::rateLimitWaitSeconds | 181.5 (239.5) ns    | 59.7 (78.1) ns      | 0.33x / 0.33x    | 0.34x / 0.37x    | PHP        |
 * |   uncommon                            | 1204.5 (2567.8) ns  | 1548.1 (3109.4) ns  | 1.29x / 1.21x    | 1.27x / 1.09x    |            |
 * | IrcScraperCommand::restartDelay       | 106.7 (140.6) ns    | 48.3 (62.5) ns      | 0.45x / 0.44x    | 0.45x / 0.48x    | PHP        |
 * |   uncommon                            | 106.4 (119.8) ns    | 46.5 (57.2) ns      | 0.44x / 0.48x    | 0.44x / 0.43x    |            |
 *
 * Only PredbStreamListener::entries clears 1.5x on every metric, so it alone defaults to Rust. The
 * other helpers stay on PHP: one FFI call costs more than number_format()/(int)/min(), Carbon
 * construction dominates parseSince(), and Eloquent attribute reads plus json_decode() dominate
 * changesFor(). An earlier stream attempt that wrapped the row for nntmux_predb_json_feed_parse
 * measured 1.24x-1.37x on uncommon workloads and was replaced by the single-pass extractor.
 */
final class RustPredbFeedsAndStreamParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_predb_feed_entry_format_size_mb(double megabytes, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_feed_entry_format_files(int64_t count, const char *text, size_t text_len, uint8_t is_string, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_feed_importer_changes_for_json(const char *existing_category, size_t existing_category_len, const char *existing_size, size_t existing_size_len, const char *existing_files, size_t existing_files_len, const char *existing_nukereason, size_t existing_nukereason_len, const char *entry_category, size_t entry_category_len, const char *entry_size, size_t entry_size_len, const char *entry_files, size_t entry_files_len, const char *entry_predate, size_t entry_predate_len, const char *entry_nukereason, size_t entry_nukereason_len, uint32_t present_mask, uint8_t existing_predate_null, int64_t existing_nuked, int64_t entry_nuked, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_stream_extract_entries_json(const char *message, size_t message_len, uint32_t format, char *out_buf, size_t out_cap);
        int32_t nntmux_predb_import_parse_since(const char *value, size_t value_len, int64_t *amount);
        int64_t nntmux_predb_import_rate_limit_wait(const char *retry_after, size_t retry_after_len, const char *reset_at, size_t reset_at_len, int64_t default_seconds, int64_t now, uint8_t has_now);
        int64_t nntmux_irc_scraper_restart_delay(int64_t failures);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('predb', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->ffi = $ffi;
        self::resetToggles();
    }

    protected function tearDown(): void
    {
        self::resetToggles();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private static function resetToggles(): void
    {
        PredbFeedEntry::setUseRustFfi(false);
        PredbFeedImporter::setUseRustFfi(false);
        PredbStreamListener::setUseRustFfi(true);
        PredbImportFeed::setUseRustFfi(false);
        IrcScraperCommand::setUseRustFfi(false);
    }

    public function test_production_defaults(): void
    {
        self::assertFalse(PredbFeedEntry::usesRustFfi());
        self::assertFalse(PredbFeedImporter::usesRustFfi());
        self::assertTrue(PredbStreamListener::usesRustFfi());
        self::assertFalse(PredbImportFeed::usesRustFfi());
        self::assertFalse(IrcScraperCommand::usesRustFfi());
    }

    public function test_c_abi_contracts(): void
    {
        $ffi = $this->ffi;
        $tiny = $ffi->new('char[2]');
        $amount = $ffi->new('int64_t');

        // @phpstan-ignore method.notFound
        self::assertSame(-strlen('700.5MB'), $ffi->nntmux_predb_feed_entry_format_size_mb(700.5, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_predb_feed_entry_format_size_mb(0.0, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_feed_entry_format_size_mb(NAN, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_feed_entry_format_files(0, null, 3, 1, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_feed_entry_format_files(0, '1', 1, 2, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-3, $ffi->nntmux_predb_feed_entry_format_files(123, null, 0, 0, $tiny, 2));

        // A present string with a null pointer and a non-zero length is rejected; so are unknown mask bits.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_feed_importer_changes_for_json(null, 0, null, 0, null, 0, null, 0, null, 4, null, 0, null, 0, null, 0, null, 0, 1 << 4, 0, 0, 0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_feed_importer_changes_for_json(null, 0, null, 0, null, 0, null, 0, null, 0, null, 0, null, 0, null, 0, null, 0, 1 << 9, 0, 0, 0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen('{"category":"TV"}'), $ffi->nntmux_predb_feed_importer_changes_for_json(null, 0, null, 0, null, 0, null, 0, 'TV', 2, null, 0, null, 0, null, 0, null, 0, 1 << 4, 0, 0, 0, $tiny, 2));

        $message = '{"message":{"release":"X-GRP"}}';
        // @phpstan-ignore method.notFound
        self::assertLessThan(-1, $ffi->nntmux_predb_stream_extract_entries_json($message, strlen($message), 1, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_stream_extract_entries_json(null, 5, 1, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_stream_extract_entries_json($message, strlen($message), 9, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_import_parse_since('14d', 3, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_import_parse_since(null, 3, FFI::addr($amount)));
        // @phpstan-ignore method.notFound
        self::assertSame(2, $ffi->nntmux_predb_import_parse_since('14d', 3, FFI::addr($amount)));
        // @phpstan-ignore property.notFound
        self::assertSame(14, $amount->cdata);

        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_predb_import_rate_limit_wait('', 0, '1000', 4, 60, 0, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_import_rate_limit_wait(null, 2, '', 0, 60, 0, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_predb_import_rate_limit_wait('', 0, '', 0, 60, 0, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(30, $ffi->nntmux_irc_scraper_restart_delay(PHP_INT_MIN));
        // @phpstan-ignore method.notFound
        self::assertSame(3600, $ffi->nntmux_irc_scraper_restart_delay(PHP_INT_MAX));
    }

    public function test_feed_entry_size_and_files_parity(): void
    {
        $rng = new Randomizer(new Mt19937(20261024));
        $sizes = [null, 0, -1, 1, 7, 700, 0.0, -0.5, 0.5, 0.0123, 0.999, 1.0, 1.005, 2.675, 49.0, 700.5, 4482.27, 999999.99, 1e6, 2.5e9, 1e-300, PHP_INT_MAX, INF, -INF, NAN];
        for ($i = 0; $i < 4000; $i++) {
            $sizes[] = match ($i % 4) {
                0 => $rng->getInt(1, 20000) / 100,
                1 => $rng->getInt(1, 1000000) / 1000000,
                2 => $rng->getInt(1, 100000000) / 7,
                default => $rng->getInt(1, 5000),
            };
        }
        $supportedCount = 0;
        foreach ($sizes as $size) {
            $expected = PredbFeedEntry::sizeFromMegabytesPhp($size);
            $supported = false;
            $native = PredbFeedEntry::sizeFromMegabytesNative($size, $supported);
            if ($supported) {
                $supportedCount++;
                self::assertSame($expected, $native, var_export($size, true));
            }
            foreach ([true, false] as $useRust) {
                PredbFeedEntry::setUseRustFfi($useRust);
                self::assertSame($expected, PredbFeedEntry::sizeFromMegabytes($size), var_export($size, true));
            }
        }
        self::assertGreaterThan(3000, $supportedCount);

        $counts = [null, 0, 1, -1, 40, PHP_INT_MAX, PHP_INT_MIN, '', '0', '12', ' 12', "\t\n12", "\v12", "\f7", '12 ', '12abc', '12 abc', '+5', '-5', '-0', '007', '0x1A', 'abc', '.5', '1.9', '1e3', '1E3', '12e', '99999999999999999999', '999999999999999999', ' - 3', "\x0012"];
        for ($i = 0; $i < 2000; $i++) {
            $counts[] = $i % 2 === 0 ? $rng->getInt(-50, 5000) : $rng->getBytesFromString(" \t+-0123456789abc.e", $rng->getInt(1, 8));
        }
        foreach ($counts as $count) {
            $expected = PredbFeedEntry::filesFromCountPhp($count);
            $supported = false;
            $native = PredbFeedEntry::filesFromCountNative($count, $supported);
            if ($supported) {
                self::assertSame($expected, $native, var_export($count, true));
            } else {
                self::assertIsString($count);
            }
            foreach ([true, false] as $useRust) {
                PredbFeedEntry::setUseRustFfi($useRust);
                self::assertSame($expected, PredbFeedEntry::filesFromCount($count), var_export($count, true));
            }
        }
    }

    public function test_importer_changes_for_parity(): void
    {
        $importer = new PredbFeedImporter;
        $rng = new Randomizer(new Mt19937(424242));
        $strings = [null, '', 'TV', 'TV-X264', 'MP3', '700MB', '12', 'dupe', 'dupe.proper', 'Ünïcødé reason', 'quote " and \\ slash', "ctl\x01\x1f", str_repeat('é', 300), str_repeat('a', 400), "bad\xff"];
        $nukes = [Predb::PRE_NONUKE, Predb::PRE_UNNUKED, Predb::PRE_NUKED, Predb::PRE_MODNUKE, Predb::PRE_RENUKED, Predb::PRE_OLDNUKE];
        $pick = static fn (array $pool): mixed => $pool[$rng->getInt(0, count($pool) - 1)];
        $supportedCount = 0;
        for ($i = 0; $i < 3000; $i++) {
            $existing = (new Predb)->setRawAttributes([
                'id' => $i + 1,
                'title' => 'Some.Release-GRP',
                'category' => $pick($strings),
                'size' => $pick($strings),
                'files' => $pick($strings),
                'predate' => $rng->getInt(0, 1) === 0 ? null : '2026-01-02 03:04:05',
                'nuked' => $rng->getInt(0, 3) === 0 ? (string) $pick($nukes) : $pick($nukes),
                'nukereason' => $pick($strings),
            ]);
            $entry = new PredbFeedEntry(
                title: 'Some.Release-GRP',
                source: 'test',
                category: $pick($strings),
                size: $pick($strings),
                files: $pick($strings),
                predate: $rng->getInt(0, 1) === 0 ? null : CarbonImmutable::createFromTimestampUTC($rng->getInt(0, 1900000000)),
                nuked: $pick($nukes),
                nukeReason: $pick($strings),
            );
            $expected = $importer->changesForPhp($existing, $entry);
            $supported = false;
            $native = $importer->changesForNative($existing, $entry, $supported);
            if ($supported) {
                $supportedCount++;
                self::assertSame($expected, $native, (string) $i);
            }
        }
        self::assertGreaterThan(1500, $supportedCount);

        // A non-string attribute declines to PHP.
        $existing = (new Predb)->setRawAttributes(['category' => 5, 'nuked' => 0, 'predate' => null]);
        $supported = true;
        self::assertNull($importer->changesForNative($existing, new PredbFeedEntry('A', 'test', category: 'TV'), $supported));
        self::assertFalse($supported);
    }

    public function test_stream_entries_parity(): void
    {
        $club = $this->listener('predb_club');
        $net = $this->listener('predb_net');
        $messages = [
            '',
            '   ',
            'hb',
            'null',
            '[1,2]',
            '{}',
            '{"hb":1791630534}',
            '{"action":"insert","row":{"id":15545958,"name":"MASH.S02E20.1974.DUBBED.CZECH.1080p.WEB.x264-OMG","team":"OMG","cat":"TV-1080P-CZ","size":0,"files":0,"preAt":1791630534,"nuke":null}}',
            '{"action":"update","row":{"id":15545957,"name":"Beard2Beard_and_Seventh_Soul-Mira-(CA156)-WEB-2026-PTC","team":"PTC","cat":"MP3","size":49,"files":4,"preAt":1791630519,"nuke":null}}',
            '{"action":"update","row":{"name":"X-GRP","nuke":{"type":"modnuke","reason":"bad.ar"}}}',
            '{"action":"update","row":{"name":"X-GRP","nuke":{"type":"UNNUKE","reason":""}}}',
            '{"action":"update","row":{"name":"X-GRP","nuke":[1,2]}}',
            '{"action":"update","row":{"name":"X-GRP","nuke":{"type":null}}}',
            '{"action":"delete","row":{"name":"X-GRP"}}',
            '{"action":"insert","row":{"name":"   "}}',
            '{"action":"insert","row":[{"name":"X-GRP"}]}',
            '{"action":"insert","row":"X-GRP"}',
            '{"action":["insert"],"row":{"name":"X-GRP"}}',
            '{"action":"insert","row":{"name":"First-GRP"},"row":{"name":"Second-GRP"}}',
            '{"row":{"name":"X-GRP"},"action":"update"}',
            '{"action":"insert","row":{"name":"X-GRP","size":"700","files":"12","preAt":"1791630534"}}',
            '{"action":"insert","row":{"name":"X-GRP","size":"7.5","files":"1e2","preAt":1.5e9}}',
            '{"action":"insert","row":{"name":"X-GRP","size":1.005,"files":3.9,"preAt":-5}}',
            '{"action":"insert","row":{"name":"X-GRP","size":1e400}}',
            '{"action":"insert","row":{"name":"Ünïcødé\\u00e9\\ud83d\\ude00-GRP","cat":"\\/TV\\n"}}',
            '{"action":"insert","row":{"name":"X\\u001fY-GRP"}}',
            '{"action":"insert","row":{"name":"X-GRP"}',
            '{"action":"insert","row":{"name":"X-GRP"}} trailing',
            '{"action":"insert","row":{"name":"X-GRP"},"x":01}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":"\\ud800"}',
            "{\"action\":\"insert\",\"row\":{\"name\":\"bad\xff-GRP\"}}",
            "\xef\xbb\xbf{\"action\":\"insert\",\"row\":{\"name\":\"X-GRP\"}}",
            '{"act\\u0069on":"insert","row":{"name":"X-GRP"}}',
            '{"action":"ins\\u0065rt","row":{"name":"X-GRP"}}',
            '{"message":{"pretime":1791630534,"release":"MASH.S02E20.1974.DUBBED.CZECH.1080p.WEB.x264-OMG","section":"TV-X264-HD","group":"OMG"}}',
            '{"message":{"pretime":"1791630534","release":"X-GRP","section":"","size":"12","files":"3","status":"1","reason":"dupe"}}',
            '{"message":{"release":"X-GRP","status":2,"reason":"fixed"}}',
            '{"message":{"release":"X-GRP","status":"x"}}',
            '{"message":{"release":"X-GRP","status":[1]}}',
            '{"message":{"release":"X-GRP","status":3.7,"reason":null}}',
            '{"message":[{"release":"X-GRP"}]}',
            '{"message":"X-GRP"}',
            '{"status":"ok"}',
            " \n{\"message\" : {\"release\" : \"X-GRP\"} } \r\n",
            '{"action":"insert","row":{"name":"X-GRP","size":" 700.5 ","files":"12 ","preAt":" 1791630534"}}',
            '{"action":"insert","row":{"name":"X-GRP","size":".5","files":"1e2","preAt":"-.5e-3"}}',
            '{"action":"insert","row":{"name":"X-GRP","size":"1e999","files":"0x1A","preAt":"1_000"}}',
            '{"action":"insert","row":{"name":"X-GRP","size":"9999999999999999999","files":"+5","preAt":"5."}}',
            '{"action":"insert","row":{"name":"X-GRP","size":2500000,"files":-3,"preAt":-62162121600}}',
            '{"message":{"release":"X-GRP","status":" 1","reason":"r"}}',
            '{"message":{"release":"X-GRP","status":"2abc","reason":"r"}}',
            '{"message":{"release":"X-GRP","status":"1.9","reason":"r"}}',
            '{"message":{"release":"X-GRP","status":true,"reason":"r"}}',
            '{"message":{"release":"X-GRP","status":{},"reason":"r"}}',
            '{"message":{"release":"X-GRP","status":{"a":1},"reason":"r"}}',
            '{"action":"insert","row":{"name":"A\\udc00"}}',
            '{"action":"insert","row":{"name":"A\\ud83d"}}',
            '{"action":"insert","row":{"name":"A\\ud83dx"}}',
            '{"action":"insert","row":{"name":"A\\u0000\\t "}}',
            '{"action":"insert","row":{"name":"A\\x"}}',
            '{"action":"insert","row":{"name":"A\\u12G4"}}',
            "{\"action\":\"insert\",\"row\":{\"name\":\"A\tB\"}}",
            '{"action":"insert","r\\u006fw":{"name":"Escaped-Key-GRP"}}',
            '{"action":"insert","row":{"name":"First-GRP","nuke":{"type":"modnuke"},"nuke":null}}',
            '{"action":"insert","row":{"name":"X-GRP","nuke":{"type":5,"reason":"r"}}}',
            '{"action":"insert","row":{"name":"X-GRP","nuke":{"type":true}}}',
            '{"action":"insert","row":{"name":"X-GRP","nuke":{}}}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":'.str_repeat('[', 600).str_repeat(']', 600).'}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":'.str_repeat('[', 499).str_repeat(']', 499).'}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":[1,]}',
            '{"action":"insert","row":{"name":"X-GRP"},}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":-01}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":1.}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":tru}',
            '{"action":"insert","row":{"name":"X-GRP"},"x":NaN}',
            '{"action":"insert","row":{"name":"X-GRP"}}{}',
        ];
        $rng = new Randomizer(new Mt19937(77));
        for ($i = 0; $i < 1500; $i++) {
            $messages[] = $this->randomMessage($rng, $i % 2 === 0 ? 'predb_club' : 'predb_net');
        }

        $supportedCount = 0;
        foreach ($messages as $message) {
            foreach ([$club, $net] as $listener) {
                $expected = $listener->entriesPhp($message);
                $native = $listener->entriesNative($message);
                if ($native !== null) {
                    $supportedCount++;
                    self::assertSame(self::normalise($expected), self::normalise($native), $message);
                    self::assertEquals($expected, $native, $message);
                }
                foreach ([true, false] as $useRust) {
                    PredbStreamListener::setUseRustFfi($useRust);
                    self::assertSame(self::normalise($expected), self::normalise($listener->entries($message)), $message);
                }
            }
        }
        self::assertGreaterThan(2500, $supportedCount);
    }

    public function test_import_feed_parse_since_parity(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:34:56.789 UTC'));
        $values = [null, '', ' ', "\n", '14d', '14D', '36h', '36 H', " 7\td ", "14\x0bd", "14\x0cd", "14\nd", '0d', '007h', '14w', 'd', '14', '-1d', '1.5d', '14dd', "14d\n", "\x0014d\x00", '9999999999999999999d', '999999h', "14d\xa0", '2026-09-25', ' 2026-09-25 ', '2026-02-29', '2024-02-29', '1900-02-29', '2000-02-29', '0000-02-29', '0000-01-01', '9999-12-31', '2026-13-01', '2026-00-10', '2026-04-31', '2026-04-00', '2026-9-25', '2026/09/25', '20260925', 'yesterday', 5, 1.5, true];
        $rng = new Randomizer(new Mt19937(9));
        for ($i = 0; $i < 600; $i++) {
            $values[] = $i % 3 === 0
                ? sprintf('%04d-%02d-%02d', $rng->getInt(0, 9999), $rng->getInt(0, 13), $rng->getInt(0, 32))
                : $rng->getBytesFromString(" \t0123456789dhDH-", $rng->getInt(1, 6));
        }
        foreach ($values as $value) {
            $expected = PredbImportFeed::parseSincePhp($value);
            $supported = false;
            $native = PredbImportFeed::parseSinceNative($value, $supported);
            $label = var_export($value, true);
            if ($supported) {
                self::assertSame(self::since($expected), self::since($native), $label);
            } else {
                self::assertTrue($value !== null && (! is_string($value) || preg_match('/[^\x00-\x7f]|\d{19}/', $value) === 1), $label);
            }
            foreach ([true, false] as $useRust) {
                PredbImportFeed::setUseRustFfi($useRust);
                self::assertSame(self::since($expected), self::since($this->invokePrivate(new PredbImportFeed, 'parseSince', $value)), $label);
            }
        }
    }

    public function test_import_feed_rate_limit_wait_parity(): void
    {
        $now = CarbonImmutable::parse('1994-11-06 08:00:00.250 UTC');
        $cases = [
            [null, null, 60], ['', '', -5], ['120', null, 60], [' 120 ', null, 60], ['0', null, 60], ['007', null, 60],
            ['-5', null, 60], ['1.5', null, 60], ['99999999999999999999', null, 60],
            ['Sun, 06 Nov 1994 08:49:37 GMT', null, 60], ['Sun, 06 Nov 1994 07:49:37 GMT', null, 60],
            ['Mon, 06 Nov 1994 08:49:37 GMT', null, 60], ['Sunday, 06-Nov-94 08:49:37 GMT', null, 60],
            ['sun, 06 nov 1994 08:49:37 gmt', null, 60], ['Sun, 06 Nov 1994 08:49:37 UTC', null, 60],
            ['Thu, 31 Feb 1994 08:49:37 GMT', null, 60], ['tomorrow', null, 60], ['not a date', null, 60],
            [null, (string) ($now->getTimestamp() + 90), 60], [null, ' '.($now->getTimestamp() - 90).' ', 60],
            [null, 'soon', 60], [null, '99999999999999999999', 60], ['', '1000', 60],
        ];
        $rng = new Randomizer(new Mt19937(5));
        for ($i = 0; $i < 400; $i++) {
            $at = $now->addSeconds($rng->getInt(-86400, 86400 * 30));
            $cases[] = match ($i % 4) {
                0 => [(string) $rng->getInt(0, 100000), null, 60],
                1 => [$at->format('D, d M Y H:i:s').' GMT', null, 60],
                2 => [null, (string) $at->getTimestamp(), $rng->getInt(-10, 900)],
                default => [null, null, $rng->getInt(-100, 900)],
            };
        }
        foreach ($cases as [$retryAfter, $resetAt, $default]) {
            CarbonImmutable::setTestNow($now);
            $label = var_export([$retryAfter, $resetAt, $default], true);
            $expected = PredbImportFeed::rateLimitWaitSecondsPhp($retryAfter, $resetAt, $default);
            $supported = false;
            $native = PredbImportFeed::rateLimitWaitSecondsNative($retryAfter, $resetAt, $default, $supported);
            if ($supported) {
                self::assertSame($expected, $native, $label);
            }
            foreach ([true, false] as $useRust) {
                PredbImportFeed::setUseRustFfi($useRust);
                self::assertSame($expected, $this->invokePrivate(new PredbImportFeed, 'rateLimitWaitSeconds', $retryAfter, $resetAt, $default), $label);
            }
        }
    }

    public function test_irc_scraper_restart_delay_parity(): void
    {
        $values = [PHP_INT_MIN, PHP_INT_MIN + 1, -1000, -1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 16, 17, 18, 1000, PHP_INT_MAX - 1, PHP_INT_MAX];
        foreach ($values as $failures) {
            $expected = IrcScraperCommand::restartDelayPhp($failures);
            self::assertSame($expected, IrcScraperCommand::restartDelayNative($failures), (string) $failures);
            foreach ([true, false] as $useRust) {
                IrcScraperCommand::setUseRustFfi($useRust);
                self::assertSame($expected, IrcScraperCommand::restartDelay($failures), (string) $failures);
            }
        }
    }

    /**
     * NNTMUX_PREDB_FEEDS_STREAM_BENCH=1 php -d opcache.enable_cli=1 artisan test --compact
     * tests/Unit/RustPredbFeedsAndStreamParityTest.php (optional NNTMUX_PREDB_FEEDS_STREAM_BENCH_OUT=<json path>).
     */
    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_PREDB_FEEDS_STREAM_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_PREDB_FEEDS_STREAM_BENCH=1 to run paired randomized benchmarks.');
        }

        $importer = new PredbFeedImporter;
        $club = $this->listener('predb_club');
        $net = $this->listener('predb_net');
        $rng = new Randomizer(new Mt19937(20261024));

        $complete = (new Predb)->setRawAttributes(['id' => 1, 'title' => 'A-GRP', 'category' => 'TV-X264', 'size' => '700MB', 'files' => '40', 'predate' => '2026-01-02 03:04:05', 'nuked' => 0, 'nukereason' => null]);
        $sparse = (new Predb)->setRawAttributes(['id' => 2, 'title' => 'B-GRP', 'category' => '', 'size' => null, 'files' => null, 'predate' => null, 'nuked' => '2', 'nukereason' => 'old reason']);
        $announce = new PredbFeedEntry('A-GRP', 'predb.club', 'TV-X264', '700MB', '40', CarbonImmutable::createFromTimestampUTC(1791630534));
        $nuke = new PredbFeedEntry('B-GRP', 'predb.club', str_repeat('Ünïcødé ', 40), '4482.27MB', '87', CarbonImmutable::createFromTimestampUTC(1791630534), Predb::PRE_MODNUKE, str_repeat('dupe.of.other.release ', 15));
        $renuke = new PredbFeedEntry('B-GRP', 'predb.net', null, null, null, null, Predb::PRE_NUKED, 'corrected reason');

        $clubCommon = [];
        $netCommon = [];
        for ($i = 0; $i < 32; $i++) {
            $clubCommon[] = $this->randomMessage($rng, 'predb_club', true);
            $netCommon[] = $this->randomMessage($rng, 'predb_net', true);
        }
        $clubUncommon = [
            '{"hb":1791630534}',
            '{"action":"delete","row":{"id":15545957,"name":"Beard2Beard-Mira-(CA156)-WEB-2026-PTC"}}',
            '{"action":"update","row":{"id":15545957,"name":"Beard2Beard-Mira-(CA156)-WEB-2026-PTC","team":"PTC","cat":"MP3","size":49,"files":4,"preAt":1791630519,"nuke":{"type":"nuke","reason":"mislabeled.get.proper","net":"LocalNet"}}}',
            '{"action":"update","row":{"id":15545957,"name":"Beard2Beard-Mira-(CA156)-WEB-2026-PTC","team":"PTC","cat":"MP3","size":49,"files":4,"preAt":1791630519,"nuke":{"type":"unnuke","reason":"fine"}}}',
            '{"action":"update","row":{"id":15545958,"name":"Some.Movie.2026.2160p.UHD.BluRay.x265-TERMiNAL","team":"TERMiNAL","cat":"X265","size":"61234.5","files":"87","preAt":"1791630534","nuke":{"type":"modnuke","reason":"dupe.of.Some.Movie.2026.2160p.UHD.BluRay.x265-OTHER"}}}',
            '{"action":"insert","row":{"id":15545959,"name":"\\u00dcn\\u00efc\\u00f8d\\u00e9.T\\u00eftle.2026-GRP","team":"GRP","cat":"TV-X264","size":0.25,"files":1,"preAt":1791630540,"nuke":null}}',
            '{"action":"insert","row":{"id":15545960,"name":"A.Very.Long.Release.Name.With.Many.Dotted.Words.S01E01.Episode.Title.Here.2026.1080p.WEB.h264-GROUP","team":"GROUP","cat":"TV-1080P","size":1.005,"files":40,"preAt":1791630541,"nuke":null}}',
            '{"action":"insert","row":[]}',
        ];
        $clubUncommon[] = '{"action":"update","row":{"name":"'.str_repeat('Long.Release.Name.', 12).'-GRP","cat":"ÜNÏCØDÉ","size":"700","files":"12","preAt":"1791630534","nuke":{"type":"renuke","reason":"'.str_repeat('reason ', 30).'"}}}';

        $since = static function (mixed $value): mixed {
            $supported = false;
            $native = PredbImportFeed::parseSinceNative($value, $supported);

            return $supported ? $native : PredbImportFeed::parseSincePhp($value);
        };
        $wait = static function (?string $retryAfter, ?string $resetAt, int $default): int {
            $supported = false;
            $native = PredbImportFeed::rateLimitWaitSecondsNative($retryAfter, $resetAt, $default, $supported);

            return $supported && $native !== null ? $native : PredbImportFeed::rateLimitWaitSecondsPhp($retryAfter, $resetAt, $default);
        };
        $changes = static function (Predb $existing, PredbFeedEntry $entry) use ($importer): array {
            $supported = false;
            $native = $importer->changesForNative($existing, $entry, $supported);

            return $supported && $native !== null ? $native : $importer->changesForPhp($existing, $entry);
        };

        $workloads = [
            'PredbFeedEntry::sizeFromMegabytes' => [
                'common' => [[700.5], [4482.27], [49.0], [1200.0], [0.5]],
                'uncommon' => [[0.0123], [999999.99], [1.005], [2.5e9], [7]],
                static fn (int|float $mb): ?string => PredbFeedEntry::sizeFromMegabytes($mb),
                static fn (int|float $mb): ?string => PredbFeedEntry::sizeFromMegabytesPhp($mb),
                PredbFeedEntry::setUseRustFfi(...),
            ],
            'PredbFeedEntry::filesFromCount' => [
                'common' => [[40], [4], [1], [120], [0]],
                'uncommon' => [[' 12'], ['12abc'], ['0'], ['1e3'], [null]],
                static fn (int|string|null $count): ?string => PredbFeedEntry::filesFromCount($count),
                static fn (int|string|null $count): ?string => PredbFeedEntry::filesFromCountPhp($count),
                PredbFeedEntry::setUseRustFfi(...),
            ],
            'PredbFeedImporter::changesFor' => [
                'common' => [[$complete, $announce]],
                'uncommon' => [[$sparse, $nuke], [$sparse, $renuke]],
                $changes,
                static fn (Predb $existing, PredbFeedEntry $entry): array => $importer->changesForPhp($existing, $entry),
                PredbFeedImporter::setUseRustFfi(...),
            ],
            'PredbStreamListener::entries (predb_club)' => [
                'common' => array_map(static fn (string $m): array => [$m], $clubCommon),
                'uncommon' => array_map(static fn (string $m): array => [$m], $clubUncommon),
                static fn (string $m): array => $club->entries($m),
                static fn (string $m): array => $club->entriesPhp($m),
                PredbStreamListener::setUseRustFfi(...),
            ],
            'PredbStreamListener::entries (predb_net)' => [
                'common' => array_map(static fn (string $m): array => [$m], $netCommon),
                'uncommon' => [
                    ['{"message":{"pretime":"1791630534","release":"'.str_repeat('Long.Release.', 15).'-GRP","section":"","size":"12","files":"3","status":"1","reason":"dupe"}}'],
                    ['{"status":"ok"}'],
                    ['{"message":{"pretime":1791630534,"release":"X-GRP","section":"MP3","status":2,"reason":"fixed"}}'],
                    ['{"message":{"pretime":1791630534,"release":"\\u00dcn\\u00efc\\u00f8d\\u00e9-GRP","section":"TV-X264-HD","group":"GRP","status":3,"reason":"delpre"}}'],
                    ['{"message":{"pretime":1791630534,"release":"Some.Movie.2026.2160p.UHD.BluRay.x265-TERMiNAL","section":"X265","size":61234.5,"files":87,"group":"TERMiNAL"}}'],
                ],
                static fn (string $m): array => $net->entries($m),
                static fn (string $m): array => $net->entriesPhp($m),
                PredbStreamListener::setUseRustFfi(...),
            ],
            'PredbImportFeed::parseSince' => [
                'common' => [['14d'], ['36h'], [null]],
                'uncommon' => [['2026-09-25'], ['2026-02-31'], [' 7 D '], ['garbage']],
                $since,
                static fn (mixed $value): mixed => PredbImportFeed::parseSincePhp($value),
                PredbImportFeed::setUseRustFfi(...),
            ],
            'PredbImportFeed::rateLimitWaitSeconds' => [
                'common' => [['60', null, 60], ['120', null, 60], [null, null, 60]],
                'uncommon' => [['Sun, 06 Nov 2033 08:49:37 GMT', null, 60], [null, '2000000000', 60], ['tomorrow', null, 60]],
                $wait,
                static fn (?string $retryAfter, ?string $resetAt, int $default): int => PredbImportFeed::rateLimitWaitSecondsPhp($retryAfter, $resetAt, $default),
                PredbImportFeed::setUseRustFfi(...),
            ],
            'IrcScraperCommand::restartDelay' => [
                'common' => [[1], [2], [3], [4]],
                'uncommon' => [[0], [17], [PHP_INT_MIN], [PHP_INT_MAX]],
                static fn (int $failures): int => IrcScraperCommand::restartDelay($failures),
                static fn (int $failures): int => IrcScraperCommand::restartDelayPhp($failures),
                IrcScraperCommand::setUseRustFfi(...),
            ],
        ];

        $repetitions = 2;
        $samples = 2000;
        $batch = 8;
        $results = [];
        $lines = [];
        foreach ($workloads as $method => $spec) {
            [$rust, $php, $toggle] = [$spec[0], $spec[1], $spec[2]];
            $toggle(true);
            foreach (['common', 'uncommon'] as $kind) {
                $inputs = $spec[$kind];
                $rustTimes = [];
                $phpTimes = [];
                for ($rep = 0; $rep < $repetitions; $rep++) {
                    foreach ($inputs as $args) {
                        for ($w = 0; $w < 50; $w++) {
                            $rust(...$args);
                            $php(...$args);
                        }
                    }
                    for ($s = 0; $s < $samples; $s++) {
                        $args = $inputs[$rng->getInt(0, count($inputs) - 1)];
                        $rustFirst = $rng->getInt(0, 1) === 0;
                        for ($side = 0; $side < 2; $side++) {
                            $useRust = ($side === 0) === $rustFirst;
                            $fn = $useRust ? $rust : $php;
                            $t0 = hrtime(true);
                            for ($b = 0; $b < $batch; $b++) {
                                $fn(...$args);
                            }
                            $elapsed = (hrtime(true) - $t0) / $batch;
                            if ($useRust) {
                                $rustTimes[] = $elapsed;
                            } else {
                                $phpTimes[] = $elapsed;
                            }
                        }
                    }
                }
                sort($rustTimes);
                sort($phpTimes);
                $n = count($rustTimes);
                $p99 = (int) floor($n * 0.99);
                $row = [
                    'samples' => $n,
                    'rust_mean_ns' => array_sum($rustTimes) / $n,
                    'php_mean_ns' => array_sum($phpTimes) / $n,
                    'rust_p99_ns' => $rustTimes[$p99],
                    'php_p99_ns' => $phpTimes[$p99],
                ];
                $row['mean_speedup'] = $row['php_mean_ns'] / max(1.0, $row['rust_mean_ns']);
                $row['p99_speedup'] = $row['php_p99_ns'] / max(1.0, $row['rust_p99_ns']);
                $results[$method][$kind] = $row;
                $lines[] = sprintf('| %-42s | %-8s | %8.1f ns (%8.1f ns) | %8.1f ns (%8.1f ns) | %5.2fx / %5.2fx |', $method, $kind, $row['rust_mean_ns'], $row['rust_p99_ns'], $row['php_mean_ns'], $row['php_p99_ns'], $row['mean_speedup'], $row['p99_speedup']);
            }
            self::resetToggles();
        }
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
        $out = getenv('NNTMUX_PREDB_FEEDS_STREAM_BENCH_OUT');
        if (is_string($out) && $out !== '') {
            file_put_contents($out, json_encode(['php' => PHP_VERSION, 'repetitions' => $repetitions, 'samples' => $samples, 'batch' => $batch, 'results' => $results], JSON_PRETTY_PRINT));
        }

        foreach (['PredbStreamListener::entries (predb_club)', 'PredbStreamListener::entries (predb_net)'] as $adopted) {
            foreach (['common', 'uncommon'] as $kind) {
                self::assertGreaterThanOrEqual(1.5, $results[$adopted][$kind]['mean_speedup'], "{$adopted} {$kind} mean");
                self::assertGreaterThanOrEqual(1.5, $results[$adopted][$kind]['p99_speedup'], "{$adopted} {$kind} p99");
            }
        }
    }

    private function listener(string $format): PredbStreamListener
    {
        return new PredbStreamListener(new WebSocketClient('wss://example.invalid/ws'), $format, $this->createStub(PredbFeedImporter::class), silent: true);
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    private function randomMessage(Randomizer $rng, string $format, bool $typical = false): string
    {
        $pick = static fn (array $pool): mixed => $pool[$rng->getInt(0, count($pool) - 1)];
        $title = $pick(['MASH.S02E20.1974.DUBBED.CZECH.1080p.WEB.x264-OMG', 'Beard2Beard-Mira-(CA156)-WEB-2026-PTC', 'Some.Movie.2026.2160p.UHD.BluRay.x265-TERMiNAL', 'VA-Summer_Hits-2CD-2026-GRP', 'Ünïcødé.Tïtle-GRP', ' Padded.Title-GRP ']);
        $title .= $rng->getInt(0, 99999);
        $any = static fn (): mixed => $pick([null, true, false, 0, 1, -3, 2.5, '', '0', '12', '1.5', 'abc', [], [1], ['type' => 'nuke']]);
        if ($format === 'predb_club') {
            $row = ['id' => $rng->getInt(1, 99999999), 'name' => $title, 'team' => 'GRP', 'cat' => $pick(['TV-X264', 'MP3', 'XXX', 'TV-1080P-CZ', '']), 'size' => $pick([0, 49, 700.5, 4482.27, 0.25]), 'files' => $pick([0, 4, 40, 87]), 'preAt' => $rng->getInt(1600000000, 1900000000), 'nuke' => null];
            if (! $typical) {
                $row['nuke'] = $pick([null, ['type' => $pick(['nuke', 'unnuke', 'modnuke', 'renuke', 'oldnuke', 'undelpre', 'NUKE', null, 5]), 'reason' => $pick(['dupe', '', null, 'get.reqd'])], [1], 'nuke']);
                foreach (['cat', 'size', 'files', 'preAt'] as $key) {
                    if ($rng->getInt(0, 3) === 0) {
                        $row[$key] = $any();
                    }
                }
                if ($rng->getInt(0, 9) === 0) {
                    unset($row['name']);
                }
            }
            $data = ['action' => $typical ? $pick(['insert', 'update']) : $pick(['insert', 'update', 'delete', null]), 'row' => $row];
        } else {
            $row = ['pretime' => $rng->getInt(1600000000, 1900000000), 'release' => $title, 'section' => $pick(['TV-X264-HD', 'MP3', 'FLAC', '']), 'group' => 'GRP'];
            if (! $typical) {
                foreach (['size', 'files', 'status', 'reason', 'section', 'pretime'] as $key) {
                    if ($rng->getInt(0, 2) === 0) {
                        $row[$key] = $any();
                    }
                }
                $row['status'] ??= $pick([0, 1, 2, 3, 4]);
            }
            $data = ['message' => $row];
        }
        $json = (string) json_encode($data, $rng->getInt(0, 1) === 0 ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0);
        if (! $typical && $rng->getInt(0, 9) === 0) {
            $json = substr($json, 0, $rng->getInt(0, strlen($json)));
        }

        return $json;
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $entries): array
    {
        return array_map(static fn (PredbFeedEntry $e): array => [
            'title' => $e->title,
            'source' => $e->source,
            'category' => $e->category,
            'size' => $e->size,
            'files' => $e->files,
            'predate' => $e->predate?->format('Y-m-d H:i:s.u e'),
            'nuked' => $e->nuked,
            'reason' => $e->nukeReason,
            'listed' => $e->listedAt?->format('Y-m-d H:i:s.u e'),
            'enrich' => $e->enrichOnly,
        ], $entries);
    }

    private static function since(mixed $value): mixed
    {
        return $value instanceof CarbonImmutable ? [$value->format('Y-m-d H:i:s.u e P'), $value->getTimestamp()] : $value;
    }
}
