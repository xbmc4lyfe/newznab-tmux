<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\Admin\AdminLogSearchRequest;
use App\Services\LogViewer\Index\LogIndex;
use App\Services\LogViewer\Index\LogIndexer;
use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\LogFileRepository;
use App\Services\LogViewer\LogReader;
use App\Services\RegistrationFailureLogService;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Parity and paired benchmarks for the log viewer scanner, log index documents, registration audit parser
 * and admin log search boundaries (`nntmux_logviewer_*`, `nntmux_registration_parse_line` and
 * `nntmux_admin_log_boundary_shape` in `rust/pipeline-ffi/src/search.rs`).
 *
 * Paired randomized single-call benchmark (`LOG_AUDIT_BENCHMARK_PAIRS=20000` interleaved pairs per workload, each
 * pair one PHP and one Rust call in random order timed by `hrtime(true)`, 200 untimed warmup pairs; release
 * dylib `opt-level=3, lto=true, codegen-units=1`, PHP 8.5.12 NTS with PCRE JIT, Apple M5 macOS 27.2;
 * nearest-rank p99). Both sides go through equivalent closures, so the calls include the FFI marshalling,
 * scratch reads and string reconstruction the app would incur. Reproduce with
 * `LOG_AUDIT_BENCHMARK_PAIRS=20000 php artisan test --compact --filter=test_paired_benchmark tests/Unit/RustLogViewerAndAuditParserParityTest.php`.
 *
 * | Method / Workload | PHP Mean / p99 (ns) | Rust FFI Mean / p99 (ns) | Mean / p99 Speedup | Production Path |
 * | --- | --- | --- | --- | --- |
 * | `LogReader::cap` common 180 B line | 157.6 / 250.0 | 273.8 / 375.0 | 0.58x / 0.67x | Retained PHP (`< 1.5x`) |
 * | `LogReader::cap` uncommon 64 KiB oversized line | 845.7 / 1542.0 | 1011.7 / 2666.0 | 0.84x / 0.58x | Retained PHP (`< 1.5x`) |
 * | `LogReader::splitBackwardChunk` common 64 KiB, 120 B lines | 26130.7 / 68417.0 | 51790.2 / 110500.0 | 0.50x / 0.62x | Retained PHP (`< 1.5x`) |
 * | `LogReader::splitBackwardChunk` uncommon 64 KiB, traces + blank lines | 6671.5 / 21750.0 | 27905.3 / 59625.0 | 0.24x / 0.36x | Retained PHP (`< 1.5x`) |
 * | `LogIndex::documentId` common small file/offset | 37.4 / 84.0 | 122.5 / 292.0 | 0.31x / 0.29x | Retained PHP (`< 1.5x`) |
 * | `LogIndex::documentId` uncommon max file/offset | 47.4 / 84.0 | 156.8 / 333.0 | 0.30x / 0.25x | Retained PHP (`< 1.5x`) |
 * | `LogIndex::isSafeTableName` common `log_entries` | 65.2 / 125.0 | 155.6 / 333.0 | 0.42x / 0.38x | Retained PHP (`< 1.5x`) |
 * | `LogIndex::isSafeTableName` uncommon 64-char invalid | 231.6 / 417.0 | 314.9 / 500.0 | 0.74x / 0.83x | Retained PHP (`< 1.5x`) |
 * | `LogIndexer::documentFields` common ASCII entry | 835.2 / 2000.0 | 1206.7 / 5292.0 | 0.69x / 0.38x | Retained PHP (`< 1.5x`) |
 * | `LogIndexer::documentFields` uncommon 4 KiB invalid UTF-8 trace | 7351.5 / 31917.0 | 3231.6 / 17458.0 | 2.27x / 1.83x | Retained PHP (common path fails) |
 * | `RegistrationFailureLogService::matchLine` common context line | 1196.5 / 5584.0 | 1202.6 / 5958.0 | 0.99x / 0.94x | Retained PHP (`< 1.5x`) |
 * | `RegistrationFailureLogService::matchLine` uncommon 1.6 KiB nested context | 3803.4 / 14208.0 | 3082.1 / 11291.0 | 1.23x / 1.26x | Retained PHP (`< 1.5x`) |
 * | `RegistrationFailureLogService::parseLine` common context line | 2980.5 / 8500.0 | 2989.4 / 8667.0 | 1.00x / 0.98x | Retained PHP (`< 1.5x`) |
 * | `RegistrationFailureLogService::parseLine` uncommon 1.6 KiB nested context | 6261.3 / 14959.0 | 4745.2 / 11666.0 | 1.32x / 1.28x | Retained PHP (`< 1.5x`) |
 * | `AdminLogSearchRequest::boundaryShape` common `datetime-local` minute | 92.2 / 125.0 | 161.6 / 167.0 | 0.57x / 0.75x | Retained PHP (`< 1.5x`) |
 * | `AdminLogSearchRequest::boundaryShape` uncommon ISO offset datetime | 115.0 / 209.0 | 178.1 / 292.0 | 0.65x / 0.72x | Retained PHP (`< 1.5x`) |
 * | `AdminLogSearchRequest::parseBoundary` common `to` minute | 2713.9 / 4375.0 | 3520.9 / 4792.0 | 0.77x / 0.91x | Retained PHP (`< 1.5x`) |
 * | `AdminLogSearchRequest::parseBoundary` uncommon `to` ISO offset | 1470.2 / 2208.0 | 1582.4 / 2333.0 | 0.93x / 0.95x | Retained PHP (`< 1.5x`) |
 *
 * No helper reaches 1.5x on both its common and uncommon workloads (only the invalid UTF-8 document scrub
 * does, and its common ASCII path loses): each PHP path is a single C builtin
 * (`explode`, `substr`, `mb_scrub`, a JIT-compiled `preg_match`, an integer shift), and Carbon parsing or
 * `json_decode` dominate the registration and boundary methods, so the FFI call and result marshalling
 * cost as much as the work saved. All production methods stay in PHP; the `*Native` helpers remain
 * parity-tested behind each class's `setUseRustFfi()` toggle.
 */
final class RustLogViewerAndAuditParserParityTest extends TestCase
{
    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([LogReader::FFI_CDEF, LogIndex::FFI_CDEF, LogIndexer::FFI_CDEF, RegistrationFailureLogService::FFI_CDEF, AdminLogSearchRequest::FFI_CDEF] as $cdef) {
            if (RustPipelineLibrary::resolveDomain('search', $cdef) === null) {
                self::markTestSkipped('libnntmux_pipeline with the wave 12 log viewer exports is not built.');
            }
        }

        $this->random = new Randomizer(new Mt19937(20261024));
    }

    protected function tearDown(): void
    {
        foreach ([LogReader::class, LogIndex::class, LogIndexer::class, RegistrationFailureLogService::class, AdminLogSearchRequest::class] as $class) {
            $class::setUseRustFfi(true);
        }

        parent::tearDown();
    }

    public function test_toggle_disables_every_native_helper(): void
    {
        LogReader::setUseRustFfi(false);
        LogIndex::setUseRustFfi(false);
        LogIndexer::setUseRustFfi(false);
        RegistrationFailureLogService::setUseRustFfi(false);
        AdminLogSearchRequest::setUseRustFfi(false);

        $this->assertNull(LogReader::capNative('line', 2));
        $this->assertNull(LogReader::splitBackwardChunkNative("a\nb", 0));
        $this->assertNull(LogIndex::documentIdNative(1, 2));
        $this->assertNull(LogIndex::isSafeTableNameNative('logs'));
        $this->assertNull(LogIndexer::scrubUtf8Native("\xff"));
        $this->assertNull(LogIndexer::documentFieldsNative(1, $this->entry('x', 'y'), 'x'));
        $this->assertNull(RegistrationFailureLogService::matchLineNative('[t] a.ERROR: x'));
        $this->assertNull(AdminLogSearchRequest::boundaryShapeNative('2026-10-24'));
        $this->assertFalse(LogReader::usesRustFfi());
    }

    public function test_cap_parity(): void
    {
        $cap = $this->privateCap();

        foreach ([0, 1, 5, 32, 4096] as $length) {
            $line = $length === 0 ? '' : $this->random->getBytes($length);

            foreach ([-$length - 3, -1, 0, 1, $length - 1, $length, $length + 1, 32_768] as $maxBytes) {
                $this->assertSame($cap($line, $maxBytes), LogReader::capNative($line, $maxBytes), "len {$length} max {$maxBytes}");
            }
        }
    }

    public function test_split_backward_chunk_parity(): void
    {
        $cases = ['', "\n", "\n\n", "a\n", "\nb", "a\r\nb\r\n", 'head only', str_repeat("x\n", 300), str_repeat('y', 70_000)."\nz"];

        for ($i = 0; $i < 400; $i++) {
            $cases[] = $this->fromAlphabet(["\n", "\r", 'a', 'line ', "\xff", '[2026-10-24 10:00:00] local.ERROR: x', ''], $this->random->getInt(0, 60));
        }

        foreach ($cases as $buffer) {
            foreach ([0, 65_536, PHP_INT_MAX >> 4] as $position) {
                $this->assertSame(LogReader::splitBackwardChunk($buffer, $position), LogReader::splitBackwardChunkNative($buffer, $position), bin2hex($buffer));
            }
        }
    }

    public function test_split_backward_chunk_keeps_log_reader_offsets(): void
    {
        $directory = storage_path('framework/testing/log-wave12-'.Str::uuid()->toString());
        File::ensureDirectoryExists($directory);

        try {
            $lines = [];

            for ($i = 0; $i < 400; $i++) {
                $lines[] = "[2026-10-24 10:00:{$this->pad($i % 60)}] local.".(['INFO', 'ERROR', 'DEBUG'][$i % 3]).": entry {$i}".($i % 7 === 0 ? "\r\n#0 trace {$i}\n#1 trace" : '');
            }

            File::put($directory.'/laravel.log', implode("\n", $lines)."\n");
            config(['nntmux.log_viewer.path' => $directory]);
            $repository = app(LogFileRepository::class);
            $file = $repository->find('laravel.log');
            $this->assertNotNull($file);
            $reader = new LogReader(new LogEntryParser, chunkSize: 97);
            $result = $reader->latest($file, null, 500);
            $contents = (string) file_get_contents($directory.'/laravel.log');

            $this->assertCount(400, $result['entries']);

            foreach ($result['entries'] as $entry) {
                $this->assertStringStartsWith('[2026-10-24 10:00:', substr($contents, $entry->offset, 18));
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_document_id_and_table_name_parity(): void
    {
        $ids = [[0, 0], [1, 0], [3, 17], [-1, 0], [1 << 23, (1 << 40) - 1], [PHP_INT_MAX, 5], [PHP_INT_MIN, 0], [7, -1]];

        for ($i = 0; $i < 500; $i++) {
            $ids[] = [$this->random->getInt(0, 1 << 23), $this->random->getInt(0, (1 << 40) - 1)];
        }

        foreach ($ids as [$fileId, $offset]) {
            $this->assertSame(LogIndex::documentId($fileId, $offset), LogIndex::documentIdNative($fileId, $offset));
        }

        $names = ['', "\n", 'log_entries', "log_entries\n", "log_entries\n\n", "\nlog", 'bad-name', 'é', 'a b', "a\0b", 'A9_z'];

        for ($i = 0; $i < 500; $i++) {
            $names[] = $this->fromAlphabet(['a', 'Z', '0', '_', '-', "\n", ' ', 'é', '.'], $this->random->getInt(0, 6));
        }

        foreach ($names as $name) {
            $this->assertSame(LogIndex::isSafeTableName($name), LogIndex::isSafeTableNameNative($name), bin2hex($name));
        }
    }

    public function test_scrub_and_document_parity(): void
    {
        $texts = ['', 'ascii', 'é ünï 😀', "\xff", "\xe2\x82", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xc0\xaf", "\xe0\x80\xaf", "a\x80\x80b", "\xf0\x9f\x98"];

        for ($i = 0; $i < 2000; $i++) {
            $texts[] = $this->random->getBytes($this->random->getInt(1, 24));
            $texts[] = $this->fromAlphabet(["\xc3", "\xa9", "\xe2", "\x82", "\xac", "\xf0", "\x9f", "\x98", "\x80", "\xed", "\xa0", "\xbf", 'a'], $this->random->getInt(0, 12));
        }

        foreach ($texts as $text) {
            $this->assertSame(mb_scrub($text, 'UTF-8'), LogIndexer::scrubUtf8Native($text), bin2hex($text));
        }

        $parser = new LogEntryParser;
        $entries = [
            [$parser->make(0, 40, '[2026-10-24 10:00:00] local.ERROR: boom', "#0 trace\xff", false, true, 1), '[2026-10-24 10:00:00] local.ERROR: boom'],
            [$parser->make(1 << 30, (1 << 30) + 9, "plain \xe2\x82 line", '', true, false, null), "plain \xe2\x82 line"],
            [$this->entry("bad \xed\xa0\x80", str_repeat("é\xff", 2000)), "bad \xed\xa0\x80"],
        ];

        foreach ($entries as [$entry, $firstLine]) {
            foreach ([1, 42, 1 << 23] as $fileId) {
                $this->assertSame(LogIndexer::documentFields($fileId, $entry, $firstLine), LogIndexer::documentFieldsNative($fileId, $entry, $firstLine));
            }
        }
    }

    public function test_registration_match_line_parity(): void
    {
        $lines = [
            '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"reason":"invite","username":"bob","email":"b@x.io","ip":"10.0.0.1"} []',
            '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"reason":"invite"}',
            '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"a": {"b":1}} []',
            '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"a":"} []"}',
            '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {broken json} []',
            '[2026-10-24 10:00:00] a.b.c.ERROR: plain message',
            '[2026-10-24 10:00:00] a.ERROR:  {}',
            '[2026-10-24 10:00:00] a.ERROR:   {} []',
            '[2026-10-24 10:00:00] a.ERROR: {}',
            '[2026-10-24 10:00:00] a.ERROR:  ',
            '[2026-10-24 10:00:00] a.ERROR: ',
            '[2026-10-24 10:00:00] a.Error: x',
            '[2026-10-24 10:00:00] .ERROR: x',
            '[2026-10-24 10:00:00] a.: x',
            '[2026-10-24 10:00:00]a.ERROR: x',
            '[] a.ERROR: x',
            "[ts]\t\x0b\x0ca.ERROR:\t{} \t[]",
            "[ts] a.ERROR: x {}\r",
            "[ts] a.ERROR: x {} []\r",
            "[ts] a.ERROR: x\ry {}",
            '[ts] a.ERROR: x {}  []  ',
            '[ts] a.ERROR: x {}[]',
            '[ts] a.ERROR: x { } {} []',
        ];

        $alphabet = ['[', ']', '[ts]', ' ', "\t", "\r", "\x0b", "\x0c", "\xa0", "\x85", 'a', 'local', '.', '-', '_', 'ERROR', 'Error', ':', '{', '}', '{"k":"v"}', '[]', 'x', 'é', "\xff", '0'];

        for ($i = 0; $i < 6000; $i++) {
            $lines[] = $this->fromAlphabet($alphabet, $this->random->getInt(0, 18));
            $lines[] = '[ts] chan.ERROR:'.$this->fromAlphabet($alphabet, $this->random->getInt(0, 14));
        }

        foreach ($lines as $line) {
            $php = RegistrationFailureLogService::matchLine($line);
            $this->assertSame($php ?? false, RegistrationFailureLogService::matchLineNative($line), bin2hex($line));
        }

        // Newlines and oversized lines are declined so the PHP reference handles them.
        $this->assertNull(RegistrationFailureLogService::matchLineNative("[ts] a.ERROR: x\n"));
        $this->assertNull(RegistrationFailureLogService::matchLineNative('[ts] a.ERROR: '.str_repeat('x', 300_000)));
    }

    public function test_registration_parse_line_parity(): void
    {
        $service = new RegistrationFailureLogService(storage_path('framework/testing/absent'));
        $parse = new ReflectionMethod($service, 'parseLine');

        foreach ([
            '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"reason":"invite","username":"bob","email":"b@x.io","ip":"10.0.0.1","registration_status":1,"manual_registration_status":true} []',
            '[2026-10-24T10:00:00.123456+00:00] local.INFO: Registration attempt failed: {"reason":null}',
            '[2026-10-24 10:00:00] local.ERROR: Registration attempt failed: no context',
            'not a log line',
        ] as $line) {
            $native = $service->parseLineNative($line);
            $this->assertNotNull($native);
            $this->assertEquals($parse->invoke($service, $line), $native['entry']);
        }
    }

    public function test_boundary_shape_parity(): void
    {
        $values = ['', '2026-10-24', "2026-10-24\n", "2026-10-24\n\n", '2026-10-24T10:30', '2026-10-24 10:30', "2026-10-24 10:30\n", '2026-10-24T10:30:15', '2026-10-24T10:30Z', '26-10-24', '2026-1-24', 'x 10:30', 'T10:30', '10:30', '٢٠٢٦-10-24'];

        for ($i = 0; $i < 4000; $i++) {
            $values[] = $this->fromAlphabet(['2026', '-', '10', '24', 'T', ' ', ':', '30', '1', "\n", 'Z', '+00:00', '.5'], $this->random->getInt(0, 9));
        }

        foreach ($values as $value) {
            $this->assertSame(AdminLogSearchRequest::boundaryShape($value), AdminLogSearchRequest::boundaryShapeNative($value), bin2hex($value));
        }

        foreach (['2026-10-24', '2026-10-24T10:30', '2026-10-24 10:30:15', '2026-10-24T10:30:15+02:00'] as $value) {
            foreach (['from', 'to'] as $key) {
                $this->assertEquals(AdminLogSearchRequest::parseBoundary($key, $value), AdminLogSearchRequest::parseBoundaryNative($key, $value));
            }
        }

        $this->assertEquals(CarbonImmutable::parse('2026-10-24')->endOfDay(), AdminLogSearchRequest::parseBoundary('to', '2026-10-24'));
    }

    public function test_paired_benchmark(): void
    {
        $pairs = (int) (getenv('LOG_AUDIT_BENCHMARK_PAIRS') ?: 0);

        if ($pairs <= 0) {
            self::markTestSkipped('Set LOG_AUDIT_BENCHMARK_PAIRS to run the paired benchmark.');
        }

        $cap = $this->privateCap();
        $service = new RegistrationFailureLogService(storage_path('framework/testing/absent'));
        $parse = new ReflectionMethod($service, 'parseLine');
        $commonChunk = $this->logChunk(65_536, 120, 0);
        $uncommonChunk = $this->logChunk(65_536, 900, 40);
        $commonEntry = $this->entry('[2026-10-24 10:00:00] production.INFO: Indexed release 123456 in 12 ms', '');
        $uncommonEntry = $this->entry("[2026-10-24 10:00:00] production.ERROR: Failed \xff decoding", str_repeat("#12 /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(441): é \xe2\x82\n", 48));
        $commonLine = '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"reason":"invite_required","username":"newuser42","email":"new@example.org","ip":"203.0.113.7","registration_status":1,"manual_registration_status":0} []';
        $uncommonLine = '[2026-10-24 10:00:00] production.WARNING: Registration attempt failed: {"reason":"validation","errors":{"email":["taken"],"username":["The username format is invalid."]},"input":'.json_encode(str_repeat('x', 1500)).'} []';

        $workloads = [
            ['LogReader::cap', 'common 180 B line', fn () => $cap(str_repeat('a', 180), 32_768), fn () => LogReader::capNative(str_repeat('a', 180), 32_768)],
            ['LogReader::cap', 'uncommon 64 KiB oversized line', fn () => $cap($uncommonChunk, 32_768), fn () => LogReader::capNative($uncommonChunk, 32_768)],
            ['LogReader::splitBackwardChunk', 'common 64 KiB, 120 B lines', fn () => LogReader::splitBackwardChunk($commonChunk, 1 << 20), fn () => LogReader::splitBackwardChunkNative($commonChunk, 1 << 20)],
            ['LogReader::splitBackwardChunk', 'uncommon 64 KiB, traces + blank lines', fn () => LogReader::splitBackwardChunk($uncommonChunk, 1 << 20), fn () => LogReader::splitBackwardChunkNative($uncommonChunk, 1 << 20)],
            ['LogIndex::documentId', 'common small file/offset', fn () => LogIndex::documentId(7, 123_456), fn () => LogIndex::documentIdNative(7, 123_456)],
            ['LogIndex::documentId', 'uncommon max file/offset', fn () => LogIndex::documentId((1 << 23) - 1, (1 << 40) - 1), fn () => LogIndex::documentIdNative((1 << 23) - 1, (1 << 40) - 1)],
            ['LogIndex::isSafeTableName', 'common `log_entries`', fn () => LogIndex::isSafeTableName('log_entries'), fn () => LogIndex::isSafeTableNameNative('log_entries')],
            ['LogIndex::isSafeTableName', 'uncommon 64-char invalid', fn () => LogIndex::isSafeTableName(str_repeat('ab_', 21).'-'), fn () => LogIndex::isSafeTableNameNative(str_repeat('ab_', 21).'-')],
            ['LogIndexer::documentFields', 'common ASCII entry', fn () => LogIndexer::documentFields(7, $commonEntry, $commonEntry->message), fn () => LogIndexer::documentFieldsNative(7, $commonEntry, $commonEntry->message)],
            ['LogIndexer::documentFields', 'uncommon 4 KiB invalid UTF-8 trace', fn () => LogIndexer::documentFields(7, $uncommonEntry, $uncommonEntry->message), fn () => LogIndexer::documentFieldsNative(7, $uncommonEntry, $uncommonEntry->message)],
            ['RegistrationFailureLogService::matchLine', 'common context line', fn () => RegistrationFailureLogService::matchLine($commonLine), fn () => RegistrationFailureLogService::matchLineNative($commonLine)],
            ['RegistrationFailureLogService::matchLine', 'uncommon 1.6 KiB nested context', fn () => RegistrationFailureLogService::matchLine($uncommonLine), fn () => RegistrationFailureLogService::matchLineNative($uncommonLine)],
            ['RegistrationFailureLogService::parseLine', 'common context line', fn () => $parse->invoke($service, $commonLine), fn () => $service->parseLineNative($commonLine)['entry'] ?? null],
            ['RegistrationFailureLogService::parseLine', 'uncommon 1.6 KiB nested context', fn () => $parse->invoke($service, $uncommonLine), fn () => $service->parseLineNative($uncommonLine)['entry'] ?? null],
            ['AdminLogSearchRequest::boundaryShape', 'common `datetime-local` minute', fn () => AdminLogSearchRequest::boundaryShape('2026-10-24T10:30'), fn () => AdminLogSearchRequest::boundaryShapeNative('2026-10-24T10:30')],
            ['AdminLogSearchRequest::boundaryShape', 'uncommon ISO offset datetime', fn () => AdminLogSearchRequest::boundaryShape('2026-10-24T10:30:15.250+02:00'), fn () => AdminLogSearchRequest::boundaryShapeNative('2026-10-24T10:30:15.250+02:00')],
            ['AdminLogSearchRequest::parseBoundary', 'common `to` minute', fn () => AdminLogSearchRequest::parseBoundary('to', '2026-10-24T10:30'), fn () => AdminLogSearchRequest::parseBoundaryNative('to', '2026-10-24T10:30')],
            ['AdminLogSearchRequest::parseBoundary', 'uncommon `to` ISO offset', fn () => AdminLogSearchRequest::parseBoundary('to', '2026-10-24T10:30:15.250+02:00'), fn () => AdminLogSearchRequest::parseBoundaryNative('to', '2026-10-24T10:30:15.250+02:00')],
        ];

        $rows = [];

        foreach ($workloads as [$method, $workload, $php, $rust]) {
            $this->assertEquals($php(), $rust(), "{$method} {$workload}");
            [$phpTimes, $rustTimes] = $this->paired($php, $rust, $pairs);
            [$phpMean, $phpP99] = $this->stats($phpTimes);
            [$rustMean, $rustP99] = $this->stats($rustTimes);
            $rows[] = sprintf(
                ' * | `%s` %s | %.1f / %.1f | %.1f / %.1f | %.2fx / %.2fx | %s |',
                $method,
                $workload,
                $phpMean,
                $phpP99,
                $rustMean,
                $rustP99,
                $phpMean / $rustMean,
                $phpP99 / $rustP99,
                'Retained PHP',
            );
        }

        fwrite(STDERR, "\npairs={$pairs}\n".implode("\n", $rows)."\n");
    }

    /**
     * @return array{list<int>, list<int>}
     */
    private function paired(Closure $php, Closure $rust, int $pairs): array
    {
        for ($i = 0; $i < 200; $i++) {
            $php();
            $rust();
        }

        $phpTimes = [];
        $rustTimes = [];

        for ($i = 0; $i < $pairs; $i++) {
            $order = $this->random->getInt(0, 1) === 0 ? [[$php, true], [$rust, false]] : [[$rust, false], [$php, true]];

            foreach ($order as [$call, $isPhp]) {
                $start = hrtime(true);
                $call();
                $elapsed = hrtime(true) - $start;

                if ($isPhp) {
                    $phpTimes[] = $elapsed;
                } else {
                    $rustTimes[] = $elapsed;
                }
            }
        }

        return [$phpTimes, $rustTimes];
    }

    /**
     * @param  list<int>  $times
     * @return array{float, float}
     */
    private function stats(array $times): array
    {
        sort($times);
        $count = count($times);

        return [array_sum($times) / $count, (float) $times[max(0, (int) ceil(0.99 * $count) - 1)]];
    }

    private function logChunk(int $bytes, int $lineLength, int $blankEvery): string
    {
        $chunk = '';
        $i = 0;

        while (strlen($chunk) < $bytes) {
            $line = '[2026-10-24 10:00:00] production.INFO: entry '.$i.' ';
            $chunk .= str_pad($line, $lineLength, 'x')."\n";

            if ($blankEvery > 0 && $i % $blankEvery === 0) {
                $chunk .= "\n#0 /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(441)\r\n";
            }

            $i++;
        }

        return substr($chunk, 0, $bytes);
    }

    /**
     * @return Closure(string, int): array{string, bool}
     */
    private function privateCap(): Closure
    {
        $reader = new LogReader(new LogEntryParser);

        /** @var Closure(string, int): array{string, bool} */
        return Closure::bind(fn (string $line, int $maxBytes): array => $this->cap($line, $maxBytes), $reader, LogReader::class);
    }

    private function entry(string $message, string $body): LogEntry
    {
        return new LogEntry(offset: 4096, end: 4096 + strlen($message) + strlen($body) + 1, lineNumber: 12, timestamp: '2026-10-24 10:00:00', channel: 'production', level: 'error', message: $message, body: $body, truncated: false);
    }

    /**
     * @param  list<string>  $alphabet
     */
    private function fromAlphabet(array $alphabet, int $tokens): string
    {
        $text = '';

        for ($i = 0; $i < $tokens; $i++) {
            $text .= $alphabet[$this->random->getInt(0, count($alphabet) - 1)];
        }

        return $text;
    }

    private function pad(int $value): string
    {
        return str_pad((string) $value, 2, '0', STR_PAD_LEFT);
    }
}
