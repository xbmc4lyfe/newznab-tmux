<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\KnabenSource;
use App\Services\Predb\Feeds\Sources\NzedbArchiveSource;
use App\Services\Predb\Irc\PreAnnounceParser;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The Rust predb_feeds functions must reproduce the PHP implementations exactly, including exceptions.
 */
final class PredbFeedsRustParityTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 2));
        $container->instance('config', new Repository);
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Log::clearResolvedInstance('log');
    }

    protected function tearDown(): void
    {
        Log::clearResolvedInstance('log');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    /**
     * Deterministic fuzz seed; set PREDB_FUZZ_SEED to explore other corpora.
     */
    private static function seed(int $default): int
    {
        $override = getenv('PREDB_FUZZ_SEED');

        return $override !== false && $override !== '' ? (int) $override + $default : $default;
    }

    public function test_domain_library_is_available(): void
    {
        $this->assertNotNull(
            RustPipelineLibrary::resolveDomain('predb_feeds', 'int32_t nntmux_predb_knaben_validate_unique_keys(const char *json, size_t json_len);'),
            'Rust predb_feeds library must be built (cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml).'
        );
    }

    /**
     * @return list<array{string, string}>
     */
    private static function announceCorpus(): array
    {
        return [
            ['corruptnet', 'PRE: [TV-X264] MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG'],
            ['corruptnet', 'PRE:[FLAC]   Artist-Album-WEB-2026-GRP'],
            ['corruptnet', 'INFO: QuoVadisX.v1.0.85.MultiLanguage.WinALL.Incl.Keygen-BLiZZARD [14F 67MB]'],
            ['corruptnet', 'INFO: Some.Release.2026-GRP [1F 0.5MB]'],
            ['corruptnet', 'NUKE: Some.Release.2026-GRP [bad.audio]'],
            ['corruptnet', 'UNNUKE: Some.Release.2026-GRP [fixed] [NUKENET]'],
            ['corruptnet', 'MODNUKE: Some.Release.2026-GRP []'],
            ['corruptnet', 'RENUKE: Some.Release.2026-GRP [x]   [NUKENET]'],
            ['corruptnet', 'OLDNUKE: Some.Release.2026-GRP [old]'],
            ['zenet', '(PRE) (TV-X264-HD) (MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG)'],
            ['zenet', '(PRE) (MP3-WEB) (Slow_AF-Exceptions-(FGA103)-WEB-2026-PTC)'],
            ['zenet', '(pre)(MP3)(Name.2026-GRP)'],
            ['zenet', '(INFO) (Some.Release.2026.1080p.WEB.h264-GRP) (2 Files) (38 MB)'],
            ['zenet', '(info) (Some.Release-(X)-GRP) (1 file) (0.75 mb)'],
            ['zenet', '(NUKE) (Some.Release.2026-GRP) (dupe.of.other)'],
            ['zenet', '(unnuke) (Some.Release.2026-GRP) ()'],
            ['predatabase', 'pre | TV-HD-CZ | MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG'],
            ['predatabase', 'PRE|MP3-WEB|Name.2026-GRP'],
            ['predatabase', 'pre |  | Name.2026-GRP'],
            ['predatabase', 'info | QuoVadisX.v1.0.85-BLiZZARD - 14 F & 67 MB'],
            ['predatabase', 'p2p | #1 | BTN | The.Kardashians.S08E01.Slap.the.S.Outta.Her.2160p.DSNP.WEB-DL-Kitsune, WIN!'],
            ['predatabase', 'p2p | #3 | IPT | IMPACT.x.Nightline.S05E02.1080p-RAWR, HDT won 2h 38m 1s ago'],
            ['predatabase', 'p2p | #1 | S|P | Some.Release.2026-GRP,win!'],
            ['ngp', '.::: PRE ::: TV-X264-HD ::: MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG :::.'],
            ['ngp', '.:::PRE:::SEC:::Name.2026-GRP:::.'],
            ['ngp', '.::: NUKE ::: SEC ::: Name.2026-GRP :::.'],
            ['ngp', '.::: INFO ::: SEC ::: Name.2026-GRP :::.'],
            ['ngp', '[INFO] FOR Coss-Ginkgo-(METANOIA064)-24BIT-WEB-FLAC-2022-BABAS with 5F 158.3MB added.'],
            ['ngp', '[INFO] FOR Name.2026-GRP with 5F 158MB added'],
            ['rizon', '[PRE]  [TV-HD]  MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG'],
            ['rizon', '[PRE][TV-HD]Name.2026-GRP'],
            ['rizon', '[NUKE] Name.2026-GRP [reason here] [NUKENET]'],
            ['rizon', '[OLDNUKE] Name.2026-GRP []'],
            ['opentrackers', 'PRE: [TV] Name.2026-GRP'],
            ['opentrackers', '(INFO) (Name.2026-GRP) (2 Files) (38 MB)'],
            ['opentrackers', 'pre | TV | Name.2026-GRP'],
            ['opentrackers', '[PRE] [TV] Name.2026-GRP'],
            ['opentrackers', 'p2p | #1 | X | Name.2026-GRP, WIN!'],
            ['opentrackers', 'PRE: [TV] Séance.2026-GRP'],
            ['corruptnet', 'PRE: [Série] Séance.2026-GRP'],
            ['corruptnet', "PRE: [TV] Name.2026-GRP\n"],
            ['corruptnet', "  PRE: [TV] Name.2026-GRP \t"],
            ['corruptnet', "PRE: [TV] A\0B-GRP"],
            ['corruptnet', ''],
        ];
    }

    private const IRC_CDEF = <<<'C'
        ssize_t nntmux_predb_parse_irc_announce(const char *line, size_t line_len, int32_t format, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_parse_irc_announce_batch_nul(const char *lines, size_t lines_len, int32_t format, char *out_buf, size_t out_cap);
        C;

    /**
     * Raw Rust result: false when the line must be parsed by PHP, null for no announce, else
     * [type, title, section, reason, files, size].
     *
     * @return list<string>|null|false
     */
    private static function ircNative(string $line, int $format): array|null|false
    {
        $ffi = RustPipelineLibrary::resolveDomain('predb_feeds', self::IRC_CDEF);
        self::assertNotNull($ffi);
        $buffer = $ffi->new('char['.(strlen($line) + 64).']');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_parse_irc_announce($line, strlen($line), $format, $buffer, strlen($line) + 64);
        if ($written === 0) {
            return null;
        }
        if ($written < 0) {
            self::assertSame(-1, $written);

            return false;
        }

        return explode("\0", FFI::string($buffer, $written));
    }

    /**
     * The entry attributes a validated announce determines, derived from the PHP parser's entry.
     *
     * @return list<bool|int|string|null>|null
     */
    private static function phpSummary(?PredbFeedEntry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [$entry->title, $entry->category, $entry->size, $entry->files, $entry->nuked, $entry->nukeReason, $entry->enrichOnly, $entry->predate !== null || $entry->listedAt !== null];
    }

    /**
     * The same attributes derived from the Rust fields with the production entry rules.
     *
     * @param  list<string>|null  $fields
     * @return list<bool|int|string|null>|null
     */
    private static function nativeSummary(?array $fields): ?array
    {
        if ($fields === null) {
            return null;
        }
        [$type, $title, $section, $reason, $files, $size] = $fields;
        $nuked = [
            'PRE' => Predb::PRE_NONUKE, 'P2P' => Predb::PRE_NONUKE, 'INFO' => Predb::PRE_NONUKE, 'NUKE' => Predb::PRE_NUKED,
            'UNNUKE' => Predb::PRE_UNNUKED, 'MODNUKE' => Predb::PRE_MODNUKE, 'RENUKE' => Predb::PRE_RENUKED, 'OLDNUKE' => Predb::PRE_OLDNUKE,
        ][$type];
        $info = $type === 'INFO';

        return [
            $title,
            $section !== '' ? $section : null,
            $info ? PredbFeedEntry::sizeFromMegabytes((float) $size) : null,
            $info ? PredbFeedEntry::filesFromCount((int) $files) : null,
            $nuked,
            $nuked === Predb::PRE_NONUKE || $reason === '' ? null : $reason,
            $info,
            in_array($type, ['PRE', 'P2P'], true),
        ];
    }

    private static function mutate(string $line): string
    {
        $alphabet = " |:[]()-.#!,&\t\x0b\x0c_AaZzNn0159FMBWIN\xc3\xa9";
        $mutations = mt_rand(1, 3);
        for ($i = 0; $i < $mutations; $i++) {
            $length = strlen($line);
            $position = $length === 0 ? 0 : mt_rand(0, $length - 1);
            $character = $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            switch (mt_rand(0, 5)) {
                case 0:
                    $line = substr_replace($line, '', $position, 1);
                    break;
                case 1:
                    $line = substr_replace($line, $character, $position, 0);
                    break;
                case 2:
                    $line = substr_replace($line, $character, $position, 1);
                    break;
                case 3:
                    $line = substr($line, 0, $position).substr($line, $position, mt_rand(1, 6)).substr($line, $position);
                    break;
                case 4:
                    $line = substr($line, 0, $position);
                    break;
                default:
                    $line = strtolower($line) === $line ? strtoupper($line) : strtolower($line);
            }
        }

        return $line;
    }

    public function test_irc_announce_matcher_matches_php_across_corpus_and_mutations(): void
    {
        mt_srand(self::seed(20261023));
        $at = CarbonImmutable::create(2026, 10, 10, 10, 50, 0, 'UTC');
        $php = [];
        foreach (PreAnnounceParser::FORMATS as $id => $format) {
            $php[$id] = new PreAnnounceParser($format, 'src');
        }
        $checked = 0;
        $handled = 0;
        $matched = 0;

        $check = function (int $format, string $line) use ($php, $at, &$checked, &$handled, &$matched): void {
            $native = self::ircNative($line, $format);
            $checked++;
            if ($native === false) {
                $this->assertTrue(preg_match('/[\x80-\xff\x00]/', $line) === 1, 'Rust may only defer non-ASCII or NUL input: '.bin2hex($line));

                return;
            }
            $handled++;
            $matched += $native === null ? 0 : 1;
            $this->assertSame(
                self::phpSummary($php[$format]->parse($line, $at)),
                self::nativeSummary($native),
                sprintf('%s: %s', PreAnnounceParser::FORMATS[$format], json_encode($line, JSON_INVALID_UTF8_SUBSTITUTE)),
            );
        };

        foreach (self::announceCorpus() as [, $line]) {
            foreach (array_keys(PreAnnounceParser::FORMATS) as $format) {
                $check($format, $line);
            }
        }
        foreach (self::announceCorpus() as [, $line]) {
            for ($i = 0; $i < 400; $i++) {
                $mutated = self::mutate($line);
                foreach (array_keys(PreAnnounceParser::FORMATS) as $format) {
                    $check($format, $mutated);
                }
            }
        }

        $this->assertGreaterThan(20000, $checked);
        $this->assertGreaterThan($checked / 2, $handled, 'Rust should handle the ASCII majority natively.');
        $this->assertGreaterThan(500, $matched, 'Fuzz corpus must exercise matching announces.');
    }

    public function test_irc_announce_batch_matches_single_line_results(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('predb_feeds', self::IRC_CDEF);
        $this->assertNotNull($ffi);
        foreach (array_keys(PreAnnounceParser::FORMATS) as $format) {
            $lines = array_values(array_filter(array_column(self::announceCorpus(), 1), static fn (string $line): bool => ! str_contains($line, "\0")));
            $input = implode("\0", $lines)."\0";
            $buffer = $ffi->new('char['.(strlen($input) * 2 + 64).']');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_parse_irc_announce_batch_nul($input, strlen($input), $format, $buffer, strlen($input) * 2 + 64);
            $this->assertGreaterThan(0, $written);
            $records = explode("\n", FFI::string($buffer, $written));
            $this->assertSame('', array_pop($records));
            $this->assertCount(count($lines), $records);
            foreach ($lines as $index => $line) {
                $single = self::ircNative($line, $format);
                $expected = $single === false ? '?' : ($single === null ? '' : implode("\0", $single));
                $this->assertSame($expected, $records[$index], PreAnnounceParser::FORMATS[$format].': '.bin2hex($line));
            }
        }
    }

    private static function outfileField(?string $value): string
    {
        if ($value === null) {
            return '\\N';
        }

        return "'".strtr($value, [
            '\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\x08" => '\\b', "\x1a" => '\\Z', '"' => '\\"',
        ])."'";
    }

    private static function randomText(int $max): string
    {
        $pieces = ['Some', 'Release', '2026', '1080p', 'x264', 'GRP', '.', '-', '_', ' ', "\t", "\n", "\r", "\0", 'é', '日本', "\u{0085}", "\x7f", "\x01", "\x0c", '"', "'", '\\', 'Ab', 'ab', 'İ', 'ǅ', '😀'];
        $text = '';
        $count = mt_rand(0, $max);
        for ($i = 0; $i < $count; $i++) {
            $text .= $pieces[mt_rand(0, count($pieces) - 1)];
        }

        return $text;
    }

    private static function randomOutfileExport(): string
    {
        $records = mt_rand(1, 12);
        $titles = [];
        $csv = '';
        for ($r = 0; $r < $records; $r++) {
            $fields = [];
            for ($c = 0; $c < 12; $c++) {
                $fields[] = mt_rand(0, 9) === 0 ? null : self::randomText(6);
            }
            $mode = mt_rand(0, 5);
            if ($mode === 0) {
                $fields[0] = 'Release.'.mt_rand(1, 4).'-GRP';
            } elseif ($mode === 1 && $titles !== []) {
                $fields[0] = $titles[array_rand($titles)];
            } elseif ($mode === 2) {
                $fields[0] = str_repeat('x', mt_rand(250, 260));
            } elseif ($mode === 3) {
                $fields[0] = str_repeat('é', mt_rand(250, 260));
            }
            if (mt_rand(0, 3) === 0) {
                $fields[7] = str_repeat('c', mt_rand(250, 260));
            }
            if ($fields[0] !== null) {
                $titles[] = $fields[0];
            }
            $record = '';
            foreach ($fields as $index => $field) {
                $record .= self::outfileField($field).($index === 11 ? "\r\n" : "\t\t");
            }
            $csv .= $record;
        }
        // Occasionally damage the export so every error branch is compared.
        switch (mt_rand(0, 14)) {
            case 0:
                $csv = substr($csv, 0, mt_rand(1, strlen($csv)));
                break;
            case 1:
                $csv = substr_replace($csv, '\\q', mt_rand(0, strlen($csv) - 1), 0);
                break;
            case 2:
                $csv .= "'tail";
                break;
            case 3:
                $csv = substr_replace($csv, "\xff", mt_rand(0, strlen($csv) - 1), 1);
                break;
            case 4:
                $csv = substr_replace($csv, 'x', mt_rand(0, strlen($csv) - 1), 1);
                break;
            case 5:
                $csv .= "'a'\r\n";
                break;
            default:
        }

        return $csv;
    }

    /**
     * @return array{mixed, array<string, int>, int}
     */
    private static function runNzedb(NzedbArchiveSource $source, string $csv): array
    {
        try {
            $entries = array_map(
                static fn (PredbFeedEntry $e): array => [$e->title, $e->source, $e->category],
                $source->parseCsv($csv),
            );
        } catch (Throwable $exception) {
            $entries = [$exception::class, $exception->getMessage()];
        }

        return [$entries, $source->rejectionCounts(), $source->acceptedCount()];
    }

    public function test_nzedb_archive_csv_matches_php_on_random_exports(): void
    {
        mt_srand(self::seed(77));
        $fixed = new NzedbArchiveSource('https://example.invalid/x.gz', maximumRecords: 7, useNative: true);
        $fixedPhp = new NzedbArchiveSource('https://example.invalid/x.gz', maximumRecords: 7, useNative: false);
        $native = new NzedbArchiveSource('https://example.invalid/x.gz', useNative: true);
        $php = new NzedbArchiveSource('https://example.invalid/x.gz', useNative: false);

        $accepted = 0;
        for ($i = 0; $i < 1500; $i++) {
            $csv = self::randomOutfileExport();
            $this->assertSame(self::runNzedb($php, $csv), self::runNzedb($native, $csv), 'iteration '.$i.': '.bin2hex(substr($csv, 0, 80)));
            $this->assertSame(self::runNzedb($fixedPhp, $csv), self::runNzedb($fixed, $csv), 'limited iteration '.$i);
            $accepted += $native->acceptedCount();
        }
        $this->assertGreaterThan(500, $accepted, 'Fuzz corpus must exercise accepted records.');

        foreach (['', "'a'\t\t", "\0", "\xff\xfe", "\xed\xa0\x80", "\xc0\x80", "\xf4\x90\x80\x80", "\xef\xbf\xbe"] as $csv) {
            $this->assertSame(self::runNzedb($php, $csv), self::runNzedb($native, $csv), bin2hex($csv));
        }
    }

    private static function randomJson(int $depth = 0): string
    {
        $keys = ['a', 'b', 'title', 'A', 'a/b', 'a\\/b', '\\u0061', '\\ud83d\\ude00', "\u{1F600}", 'é', '\\u00e9', '', 'x y', 'k\\"q', 'k"q'];
        $pick = mt_rand(0, $depth > 3 ? 3 : 7);
        if ($pick <= 2) {
            return ['1', '"s\\\\\\"x"', 'true', 'null', '-1.5e3'][mt_rand(0, 4)];
        }
        if ($pick === 3) {
            return '[]';
        }
        if ($pick <= 5) {
            $items = [];
            for ($i = 0, $n = mt_rand(0, 4); $i < $n; $i++) {
                $items[] = self::randomJson($depth + 1);
            }

            return '['.implode(mt_rand(0, 1) ? ',' : " , \n", $items).']';
        }
        $members = [];
        for ($i = 0, $n = mt_rand(0, 6); $i < $n; $i++) {
            $key = $keys[mt_rand(0, count($keys) - 1)];
            $members[] = '"'.str_replace('k"q', 'k\\"q', $key).'"'.(mt_rand(0, 1) ? ':' : ' : ').self::randomJson($depth + 1);
        }

        return '{'.implode(mt_rand(0, 1) ? ',' : " ,\t", $members).'}';
    }

    private static function runKnaben(KnabenSource $source, string $json): string
    {
        try {
            return json_encode(array_map(static fn (PredbFeedEntry $e): array => [$e->title, $e->category], $source->parseJson($json)), JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return $exception::class.': '.$exception->getMessage();
        }
    }

    public function test_knaben_duplicate_member_scan_matches_php(): void
    {
        mt_srand(self::seed(99));
        $native = new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 10, useNative: true);
        $php = new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 10, useNative: false);

        $duplicates = 0;
        for ($i = 0; $i < 3000; $i++) {
            $json = self::randomJson();
            $expected = self::runKnaben($php, $json);
            $this->assertSame($expected, self::runKnaben($native, $json), $json);
            $duplicates += str_contains($expected, 'ambiguous duplicate') ? 1 : 0;
        }
        $this->assertGreaterThan(100, $duplicates, 'Fuzz corpus must exercise duplicate members.');

        $envelope = static fn (string $hit): string => '{"hits":['.$hit.'],"total":{"relation":"eq","value":1}}';
        $documents = [
            $envelope('{"title":"A.B-GRP","categoryId":[3000000],"category":"Movies"}'),
            $envelope('{"title":"A.B-GRP","title":"C.D-GRP","categoryId":[3000000]}'),
            $envelope('{"title":"A.B-GRP","\\u0074itle":"C.D-GRP","categoryId":[3000000]}'),
            $envelope('{"title":"A.B-GRP","categoryId":[3000000],"extra":{"k":1,"k":2}}'),
            '{"hits":[],"total":{"relation":"eq","value":0}}',
            '{"hits":[]',
            '',
            str_repeat('[', 70).str_repeat(']', 70),
        ];
        foreach ($documents as $json) {
            $this->assertSame(self::runKnaben($php, $json), self::runKnaben($native, $json), $json);
        }
    }
}
