<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\Sources\CrowdnfoSource;
use App\Services\Predb\Feeds\Sources\G4uSource;
use App\Services\Predb\Feeds\Sources\GabberSource;
use App\Services\Predb\Feeds\Sources\SceneArchiveSqlSource;
use App\Services\Predb\Feeds\Sources\SeriousAboutSoundSource;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

final class PredbSqlHtmlRustParityTest extends TestCase
{
    private const CDEF = <<<'C'
        int64_t nntmux_predb_scene_sql_scan_statement(const char *input, size_t len, uint8_t quoted, uint8_t escaped);
        ssize_t nntmux_predb_scene_sql_parse_insert(const char *input, size_t len, uint32_t mode, char *output, size_t cap);
        int32_t nntmux_predb_html_validate_numeric_refs(const char *input, size_t len, uint32_t mode);
        int32_t nntmux_predb_html_validate_listing_scope(const char *input, size_t len, uint32_t mode);
        C;

    public function test_sql_html_exports_are_available(): void
    {
        self::assertNotNull(RustPipelineLibrary::resolveDomain('predb_sql_html', self::CDEF));
    }

    private const SQL_ERRORS = [
        -1 => 'returned an invalid INSERT frame.',
        -2 => 'contains an invalid tuple boundary.',
        -3 => 'contains an incomplete nine-column tuple.',
        -4 => 'contains unsupported trailing tuple data.',
        -5 => 'contains a truncated string escape.',
        -6 => 'contains an unsupported string escape.',
        -7 => 'contains an unterminated string.',
        -8 => 'contains an unsupported scalar expression.',
        -9 => 'string columns conflict with the declared schema.',
        -10 => 'numeric columns conflict with the declared schema.',
        -11 => 'contains an invalid catalogue ID.',
    ];

    private static function outcome(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }
    }

    private function checkInsert(string $sql): void
    {
        $source = new SceneArchiveSqlSource('', useNative: false);
        $rowsMethod = new ReflectionMethod($source, 'rows');
        $reasonMethod = new ReflectionMethod($source, 'eligibilityReason');
        $counts = [0, 0, 0, 0, 0, 0];
        $hashes = $titles = '';
        $expected = self::outcome(function () use ($source, $rowsMethod, $reasonMethod, $sql, &$counts, &$hashes, &$titles): bool {
            foreach ($rowsMethod->invoke($source, $sql) as $row) {
                $counts[0]++;
                $reason = $reasonMethod->invoke($source, $row);
                $index = $reason === null ? 1 : ($reason === 'noncanonical_title' ? 2 : 3);
                $counts[$index]++;
                if ($index > 1 && $counts[$index + 2] === 0) {
                    $counts[$index + 2] = $counts[0];
                }
                $hashes .= hash('sha256', "id\0".$row[0], true).hash('sha256', "title\0".strtolower($row[2]), true);
                if ($reason === null) {
                    $titles .= $row[2]."\0";
                }
            }

            return true;
        });
        $ffi = RustPipelineLibrary::resolveDomain('predb_sql_html', self::CDEF);
        self::assertNotNull($ffi);
        $cap = max(1024, strlen($sql) * 4 + 24);
        $buffer = $ffi->new('char['.$cap.']');
        foreach ([0, 1] as $mode) {
            // @phpstan-ignore method.notFound
            $n = $ffi->nntmux_predb_scene_sql_parse_insert($sql, strlen($sql), $mode, $buffer, $cap);
            if ($expected !== true) {
                self::assertArrayHasKey($n, self::SQL_ERRORS, bin2hex($sql));
                self::assertSame($expected, 'RuntimeException: SCENE_ARCHIVE_SQL '.self::SQL_ERRORS[$n], bin2hex($sql));
            } else {
                self::assertGreaterThanOrEqual(0, $n, bin2hex($sql));
                self::assertSame($mode === 0 ? pack('V*', ...$counts).$hashes : $titles, FFI::string($buffer, $n), bin2hex($sql));
            }
        }
    }

    public function test_insert_protocol_matches_php_fields_errors_hashes_and_mutations(): void
    {
        $prefix = 'INSERT INTO `predb` VALUES ';
        $tuple = "(1,'TV','A.B-GRP','grp',0,NULL,0,NULL,1)";
        $corpus = [$prefix.$tuple.';', $prefix.$tuple.','.$tuple.';'];
        foreach (['0', '-1', '2147483647', '2147483648', "'01'", "'00000000001'", "'--1'", 'NULL', '999999999999'] as $id) {
            $corpus[] = $prefix.preg_replace('/^\\(1,/', '('.$id.',', $tuple).';';
        }
        foreach (["'A\\'B-GRP'", "'A''B-GRP'", "'A\\0B-GRP'", "'A\\b\\n\\r\\t\\Z\\\"\\\\-GRP'", "'A\\q-GRP'", 'NULL', "''", "'é-GRP'", "'A-OTHER'", "'A--GRP'", "'A-G_R'", "'".str_repeat('x', 256)."'", "'a\0b-GRP'"] as $title) {
            $corpus[] = $prefix.str_replace("'A.B-GRP'", $title, $tuple).';';
        }
        foreach ([1 => 20, 2 => 255, 3 => 50, 5 => 255] as $column => $max) {
            foreach ([$max, $max + 1] as $len) {
                $row = ['1', "'TV'", "'A-GRP'", "'GRP'", '0', 'NULL', '0', 'NULL', 'NULL'];
                $row[$column] = "'".str_repeat('x', $len)."'";
                $corpus[] = $prefix.'('.implode(',', $row).');';
            }
        }
        array_push($corpus, '', $prefix, $prefix."(1,'abc\\", $prefix."(1,'abc", $prefix.$tuple.'; ', $prefix.$tuple.',;', $prefix.'(1,2);', $prefix.'(1,2,3,4,5,6,7,8,9,10);');
        foreach ($corpus as $sql) {
            $this->checkInsert($sql);
        }
        mt_srand(20261023);
        $alphabet = "(),;'\\-0129NULL abc\t\n\0\xff";
        for ($i = 0; $i < 2500; $i++) {
            $sql = $corpus[mt_rand(0, count($corpus) - 1)];
            $at = mt_rand(0, strlen($sql));
            $sql = substr_replace($sql, $alphabet[mt_rand(0, strlen($alphabet) - 1)], $at, mt_rand(0, 1));
            $this->checkInsert($sql);
        }
    }

    public function test_statement_scan_matches_original_state_machine(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('predb_sql_html', self::CDEF);
        self::assertNotNull($ffi);
        mt_srand(407);
        $alphabet = "ab;\\'\0\n";
        for ($i = 0; $i < 3000; $i++) {
            $s = '';
            for ($j = mt_rand(0, 200); $j > 0; $j--) {
                $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $q = (bool) mt_rand(0, 1);
            $e = (bool) mt_rand(0, 1);
            $quoted = $q;
            $escaped = $e;
            $boundary = null;
            for ($j = 0; $j < strlen($s); $j++) {
                if ($e) {
                    $e = false;
                } elseif ($q && $s[$j] === '\\') {
                    $e = true;
                } elseif ($s[$j] === "'") {
                    $q = ! $q;
                } elseif (! $q && $s[$j] === ';') {
                    $boundary = $j;
                    break;
                }
            }
            $expected = str_contains($s, "\0") ? -5 : ($boundary ?? -(1 + (int) $q + 2 * (int) $e));
            // @phpstan-ignore method.notFound
            self::assertSame($expected, $ffi->nntmux_predb_scene_sql_scan_statement($s, strlen($s), (int) $quoted, (int) $escaped));
        }
    }

    public function test_html_validators_match_php_including_publisher_specific_noncharacters(): void
    {
        $sources = [new CrowdnfoSource('https://example.invalid'), new SeriousAboutSoundSource('https://example.invalid'), new G4uSource('https://example.invalid'), new GabberSource('https://example.invalid')];
        $htmls = ['', '<html><body></body></html>', '<html><body><div><a x=">">A</a></div></body></html>', '<html><body><div></body></html>', '<html><body></body>', '<html><body><!-- <div> --><script>x<div></script><style>x</style></body></html>', '<html><body><di<!-- -->v></div></body></html>', '<html><body><a title="<!--x-->">a</a></body></html>'];
        foreach (['0', '9', '13', '31', '32', '65534', '65535', '1114111', '1114112', '55296', '00000000000065', 'x0', 'xD800', 'xFFFE', 'XFFFF', 'x10ffff', 'x110000', 'x00000000000041', '9999999999999999999999', 'x', '-1'] as $ref) {
            $htmls[] = '<html><body><div>&#'.$ref.';&#'.$ref.'</div></body></html>';
        }
        mt_srand(723);
        for ($i = 0; $i < 1200; $i++) {
            $html = $htmls[mt_rand(0, count($htmls) - 1)];
            $at = mt_rand(0, strlen($html));
            $parts = ['<!--x-->', '<!--', '-->', '<div>', '</a>', '<span>', '<script>x</script>', "'", '"', '&#xFFFF;', '&#xD800;', '<body>', '</html>', '>', '\\', "\0"];
            $htmls[] = substr_replace($html, $parts[mt_rand(0, count($parts) - 1)], $at, mt_rand(0, 1));
        }
        foreach ($sources as $index => $source) {
            $fixture = ['crowdnfo-home.html', 'seriousaboutsound-releases.html', 'g4u.html', 'gabber.html'][$index];
            $htmls[] = (string) file_get_contents(__DIR__.'/../Fixtures/predb/'.$fixture);
            foreach ($htmls as $html) {
                foreach (['validateNumericReferences', 'validateClosedListingStructure'] as $method) {
                    if (! method_exists($source, $method)) {
                        continue;
                    }
                    $php = new ReflectionMethod($source, $method.'Php');
                    $native = new ReflectionMethod($source, $method);
                    self::assertSame(self::outcome(fn () => $php->invoke($source, $html)), self::outcome(fn () => $native->invoke($source, $html)), $source::class.' '.$method.' '.bin2hex(substr($html, 0, 200)));
                }
            }
        }
    }

    public function test_full_sql_reader_preserves_paging_duplicates_and_scratch_bounds(): void
    {
        $fixture = (string) file_get_contents(__DIR__.'/../Fixtures/predb/scene-archive-sql-subset.sql');
        $path = tempnam(sys_get_temp_dir(), 'sql-parity-');
        self::assertNotFalse($path);
        $run = static function (bool $native, int $scratch, int $bucket, int $frames) use ($path): mixed {
            return self::outcome(static function () use ($native, $scratch, $bucket, $frames, $path): array {
                $source = new SceneArchiveSqlSource($path, pageSize: 2, maxScratchBytes: $scratch, maxBucketBytes: $bucket, maxFrameIndexBytes: $frames, useNative: $native);
                $pages = [];
                foreach ([1, 2, 2, 3, 1, 4] as $page) {
                    $pages[] = array_map(static fn ($entry): string => $entry->title, $source->fetch($page));
                }

                return [$pages, $source->acceptedCount(), $source->rejectionCounts()];
            });
        };
        try {
            $inputs = [$fixture, str_replace('(2694713,', '(2694710,', $fixture), str_replace('Mean.Streets.SE.1973.DVDRip.XviD.Hungarian-GTB', 'er.s12e13.italian.ld.hdtv.xvid-tribeca', $fixture), str_replace("'TRiBECA','0'", "'OTHER','0'", $fixture), str_replace('ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA', 'Non canonical', $fixture), substr($fixture, 0, -30), str_replace('NULL,NULL);', 'BAD,NULL);', $fixture)];
            foreach ($inputs as $input) {
                file_put_contents($path, $input);
                foreach ([[268435456, 8388608, 4194304], [1, 32, 45], [460, 32, 90], [268435456, 8388608, 45]] as [$scratch, $bucket, $frames]) {
                    self::assertSame($run(false, $scratch, $bucket, $frames), $run(true, $scratch, $bucket, $frames));
                }
            }
        } finally {
            unlink($path);
        }
    }
}
