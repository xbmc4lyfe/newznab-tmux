<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Models\ReleaseFingerprintMetadata;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\Yenc\DecoderFactory;
use App\Services\YencService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\RemainingMetadataBenchmarkFixtures;
use Tests\Fixtures\YencArticles;
use Tests\TestCase;

final class ReleaseFingerprintMetadataTest extends TestCase
{
    #[Test]
    public function it_recollects_prior_schema_metadata_for_new_archive_evidence(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
        });
        $migration = require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php');
        $migration->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'qo-schema-fixture']);
        ReleaseFingerprintMetadata::query()->create([
            'releases_id' => 1, 'status' => 'complete', 'metadata' => ['schema_version' => 27],
        ]);
        $nzb = \Mockery::mock(NzbService::class);
        $nzb->shouldReceive('readNzbContents')->once()->with('qo-schema-fixture')->andReturn(false);
        $yenc = new YencService;
        $collector = new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc);
        (new CollectReleaseFingerprintMetadata(1))->handle($collector);
        self::assertSame('unavailable', ReleaseFingerprintMetadata::query()->findOrFail(1)->status);
        foreach ([28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39] as $schemaVersion) {
            ReleaseFingerprintMetadata::query()->findOrFail(1)->update(['status' => 'complete', 'metadata' => ['schema_version' => $schemaVersion]]);
            $nzb->shouldReceive('readNzbContents')->once()->with('qo-schema-fixture')->andReturn(false);
            (new CollectReleaseFingerprintMetadata(1))->handle($collector);
            $recollected = ReleaseFingerprintMetadata::query()->where('releases_id', 1)->firstOrFail();
            self::assertSame('unavailable', $recollected->status);
        }
    }

    #[Test]
    public function it_preserves_reported_clock_evidence_in_storage_and_public_output(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
        });
        $migration = require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php');
        $migration->up();
        DB::table('releases')->insert(['id' => 1]);
        // Synthetic parser-shaped evidence tests persistence/rendering, not native execution.
        $dates = RemainingMetadataBenchmarkFixtures::baseline(
            RemainingMetadataBenchmarkFixtures::make('dates-folded'),
        ) + ['parser_engine' => 'nntmux-rust-article-dates', 'schema_version' => 2];
        foreach (['date', 'injection_date', 'legacy_nntp_posting_date', 'expires'] as $source) {
            $dates[$source]['occurrence_count_basis'] = 'retained candidates; the total may be larger when budget_exceeded';
        }
        $dates['date']['candidates'][0]['raw_field_hex'] = bin2hex("dAtE: 01 Jan 2024\r\n\t00:00 -0000\r\n");
        $metadata = ['schema_version' => 38, 'article_observations' => [[
            'server' => 'fixture.example.invalid', 'server_port' => 563, 'server_tls' => true,
            'provider_label' => 'Fixture provider', 'queried_at_utc' => '2026-10-16T00:00:00Z',
            'message_id' => '<clock@example.invalid>', 'sample_role' => 'first',
            'metadata' => ['article_dates' => $dates, 'raw_headers' => ['Subject: <script>bad()</script>']],
        ]]];
        ReleaseFingerprintMetadata::query()->create(['releases_id' => 1, 'status' => 'partial', 'metadata' => $metadata]);
        $persisted = ReleaseFingerprintMetadata::query()->findOrFail(1);
        self::assertSame($metadata, $persisted->metadata);
        $storedDates = $persisted->metadata['article_observations'][0]['metadata']['article_dates'];
        self::assertTrue(NativeMetadataParser::hasCurrentArticleDatesSchema($storedDates));
        self::assertSame(0, $storedDates['reported_clock_relations']['date_to_injection_date']['rhs_minus_lhs_seconds']);
        self::assertNull($storedDates['reported_clock_relations']['date_to_legacy_nntp_posting_date']['rhs_minus_lhs_seconds']);
        self::assertSame([2, 3], $storedDates['date']['candidates'][0]['source_line_indices']);
        $html = view('details.partials.fingerprint-metadata', ['fingerprintMetadata' => $persisted])->render();
        foreach (['reported_clock_relations', 'rhs_minus_lhs_seconds', 'lhs_order_relative_to_rhs', 'source_unavailable',
            'fixture.example.invalid', 'Fixture provider', 'raw_field_hex', 'source_line_indices', 'Partial'] as $value) {
            self::assertStringContainsString($value, $html);
        }
        self::assertStringContainsString('&lt;clock@example.invalid&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>bad()</script>', $html);
    }

    #[Test]
    public function it_exposes_comparable_zip_and_gzip_deflate_effort_and_os_metadata_through_yenc(): void
    {
        $zipDeflateMax = "PK\x03\x04".pack('vvv', 20, 0x0002, 8).str_repeat("\0", 20);
        $zipAesFast = "PK\x03\x04".pack('vvv', 51, 0x0005, 99).str_repeat("\0", 16)
            .pack('vv', 0, 11).pack('vvv', 0x9901, 7, 2)."AE\x03".pack('v', 8);
        $gzipMax = "\x1f\x8b\x08\x00\x00\x00\x00\x00\x02\x03";
        $gzipFast = "\x1f\x8b\x08\x00\x00\x00\x00\x00\x04\x0b";
        $sample = $zipDeflateMax.$zipAesFast.$gzipMax.$gzipFast;
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for DEFLATE effort integration.');
        }

        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        $headers = $analysis['metadata']['decoded_format_metadata']['headers'];
        self::assertCount(4, $headers);

        self::assertSame(8, $headers[0]['effective_compression_method']);
        self::assertSame('local_header', $headers[0]['effective_compression_method_source']);
        self::assertSame(1, $headers[0]['deflate_compression_option_bits']);
        self::assertSame('maximum', $headers[0]['deflate_compression_option']);
        self::assertSame('maximum', $headers[0]['deflate_effort_class']);
        self::assertSame('resolved_local_header', $headers[0]['deflate_effort_status']);

        self::assertSame(8, $headers[1]['effective_compression_method']);
        self::assertSame('aes_extra_field', $headers[1]['effective_compression_method_source']);
        self::assertSame(2, $headers[1]['deflate_compression_option_bits']);
        self::assertSame('fast', $headers[1]['deflate_compression_option']);
        self::assertSame('fast', $headers[1]['deflate_effort_class']);
        self::assertSame('resolved_via_aes_extra_field', $headers[1]['deflate_effort_status']);

        self::assertSame('maximum_compression', $headers[2]['deflate_extra_flags_meaning']);
        self::assertSame('maximum', $headers[2]['deflate_effort_class']);
        self::assertSame('resolved_extra_flags', $headers[2]['deflate_effort_status']);
        self::assertSame('unix', $headers[2]['operating_system_name']);

        self::assertSame('fastest_algorithm', $headers[3]['deflate_extra_flags_meaning']);
        self::assertSame('fast', $headers[3]['deflate_effort_class']);
        self::assertSame('resolved_extra_flags', $headers[3]['deflate_effort_status']);
        self::assertSame('ntfs_filesystem', $headers[3]['operating_system_name']);
    }

    #[Test]
    public function it_exposes_rar4_first_file_header_metadata_through_yenc(): void
    {
        $rar4Block = static function (int $type, int $flags, string $bodyAfterSize): string {
            $size = 7 + strlen($bodyAfterSize);
            $body = chr($type).pack('vv', $flags, $size).$bodyAfterSize;
            $crc16 = crc32($body) & 0xFFFF;

            return pack('v', $crc16).$body;
        };

        $filename = 'scene-release.mkv';
        $fileBody = pack('VV', 50_000_000, 50_000_000)
            .chr(3)
            .pack('VV', 0xDEAD_BEEF, 0x5421_1234)
            .chr(29)
            .chr(0x35)
            .pack('vV', strlen($filename), 0x0020)
            .$filename;
        $sample = "Rar!\x1a\x07\x00"
            .$rar4Block(0x73, 0x0001, str_repeat("\0", 6))
            .$rar4Block(0x74, 0x00D2, $fileBody);

        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for RAR4 first file header integration.');
        }

        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        $rar4 = $analysis['metadata']['decoded_format_metadata']['headers'][0];
        self::assertSame('rar4', $rar4['format']);
        self::assertSame('parsed', $rar4['first_file_header_status']);
        self::assertSame(0x74, $rar4['next_block_type']);
        $firstFile = $rar4['first_file'];
        self::assertTrue($firstFile['checksum_valid']);
        self::assertSame(50_000_000, $firstFile['compressed_size']);
        self::assertSame(50_000_000, $firstFile['uncompressed_size']);
        self::assertFalse($firstFile['uncompressed_size_unknown']);
        self::assertSame('unix', $firstFile['host_os_name']);
        self::assertSame(0xDEAD_BEEF, $firstFile['file_crc32']);
        self::assertSame(29, $firstFile['unpack_version']);
        self::assertSame('best', $firstFile['method_name']);
        self::assertSame(4096 * 1024, $firstFile['dictionary_size_bytes']);
        self::assertFalse($firstFile['is_directory']);
        self::assertFalse($firstFile['split_before']);
        self::assertTrue($firstFile['split_after']);
        self::assertTrue($firstFile['solid']);
        self::assertSame('scene-release.mkv', $firstFile['filename']);
        self::assertSame('ascii', $firstFile['name_encoding']);

        $walk = $rar4['visible_block_walk'];
        self::assertSame('data_truncated', $walk['status']);
        self::assertSame(1, $walk['inspected_block_count']);
        self::assertSame(1, $walk['file_block_count']);
        self::assertSame('file_header', $walk['blocks'][0]['header_type_name']);
        self::assertSame('scene-release.mkv', $walk['blocks'][0]['file_header']['filename']);
    }

    #[Test]
    public function it_preserves_long_gzip_text_fields_through_yenc(): void
    {
        $name = str_repeat('A', 4097);
        $comment = str_repeat('B', 4097);
        // Literal gzip header plus independent low-16-bit CRC32 (0xa583).
        $header = hex2bin('1f8b081a000000000003').$name."\0".$comment."\0".hex2bin('83a5');
        foreach ([false, true] as $corruptHeaderCrc) {
            $sample = str_repeat('U', 7).$header;
            if ($corruptHeaderCrc) {
                $sample[strlen($sample) - 1] = chr(ord($sample[strlen($sample) - 1]) ^ 1);
            }
            if ((new NativeMetadataParser)->parseFormats($sample) === null) {
                self::markTestSkipped('Native Rust format metadata is required for gzip text-field integration.');
            }
            $body = YencArticles::article($sample);
            $services = [new YencService];
            $libraryPath = (string) getenv('YENC_NATIVE_LIBRARY');
            if ($libraryPath !== '') {
                $nativeService = new YencService((new DecoderFactory)->make('native', $libraryPath));
                self::assertSame('RapidYenc', $nativeService->decoderName());
                $services[] = $nativeService;
            }
            foreach ($services as $yencService) {
                $analysis = (new NzbFingerprintAnalyzer($yencService))->analyzeArticleAndDecodedBytes([], $body);
                self::assertSame($sample, $analysis['decoded_bytes']);
                self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
                $gzip = $analysis['metadata']['decoded_format_metadata']['headers'][0];
                self::assertSame(7, $gzip['offset']);
                self::assertTrue($gzip['header_complete']);
                self::assertSame(! $corruptHeaderCrc, $gzip['header_valid']);
                self::assertSame(! $corruptHeaderCrc, $gzip['header_crc16_valid']);
                self::assertSame(4097, $gzip['filename_full_byte_length']);
                self::assertSame(4097, $gzip['original_filename_bytes_length']);
                self::assertSame(hash('sha256', $name), $gzip['filename_sha256']);
                self::assertSame($gzip['filename_sha256'], $gzip['original_filename_bytes_sha256']);
                self::assertSame(4097, $gzip['comment_full_byte_length']);
                self::assertSame(4097, $gzip['comment_bytes_length']);
                self::assertSame(hash('sha256', $comment), $gzip['comment_sha256']);
                self::assertSame($gzip['comment_sha256'], $gzip['comment_bytes_sha256']);
                self::assertSame(512, strlen($gzip['filename_bytes_hex']));
                self::assertSame(512, strlen($gzip['comment_bytes_hex']));
                self::assertTrue($gzip['filename_bytes_hex_truncated']);
                self::assertTrue($gzip['comment_bytes_hex_truncated']);
                self::assertSame('ascii', $gzip['filename_encoding']);
                self::assertSame(str_repeat('A', 4096), $gzip['filename']);
                self::assertTrue($gzip['filename_display_truncated']);
                self::assertSame('ascii', $gzip['comment_encoding']);
                self::assertSame(str_repeat('B', 4096), $gzip['comment']);
                self::assertTrue($gzip['comment_display_truncated']);
                self::assertSame(8208, $gzip['header_bytes']);
            }
        }
    }

    #[Test]
    public function it_resolves_zip_and_gzip_filename_authority_through_yenc(): void
    {
        $legacyName = "caf\x82.mkv";
        $legacyCrc = crc32($legacyName);
        $utf8Override = 'café.mkv';
        $upathExtra = pack('vvCV', 0x7075, 5 + strlen($utf8Override), 1, $legacyCrc).$utf8Override;
        $zipOverride = "PK\x03\x04".pack('vvvvvVVVvv', 20, 0x0000, 8, 0, 0, 0x1234_5678, 100, 200, strlen($legacyName), strlen($upathExtra))
            .$legacyName.$upathExtra;
        $gzipLatin1 = "\x1f\x8b\x08\x18\x00\x00\x00\x00\x02\x03"."caf\xe9.tar\0"."packed by caf\xc3\xa9\0"
            ."\x1f\x8b\x08\x18\x00\x00\x00\x00\x02\x03"."\xc3\x89pisode.tar\0"."\xe2\x80\x93 note\0";
        $sample = $zipOverride.$gzipLatin1;
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for ZIP/Gzip filename authority integration.');
        }

        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        $headers = $analysis['metadata']['decoded_format_metadata']['headers'];
        self::assertCount(3, $headers);

        $zip = $headers[0];
        self::assertSame('zip', $zip['format']);
        self::assertNull($zip['filename']);
        self::assertFalse($zip['filename_display_truncated']);
        self::assertSame('café.mkv', $zip['resolved_filename']);
        self::assertFalse($zip['resolved_filename_display_truncated']);
        self::assertSame('unicode_path_extra', $zip['resolved_filename_source']);
        self::assertSame('resolved', $zip['resolved_filename_status']);
        self::assertSame('café.mkv', $zip['zip_unicode_path_records'][0]['unicode_filename']);
        self::assertFalse($zip['zip_unicode_path_records'][0]['unicode_filename_display_truncated']);

        $gzip = $headers[1];
        self::assertSame('gzip', $gzip['format']);
        self::assertFalse($gzip['filename_utf8_valid']);
        self::assertTrue($gzip['filename_latin1_printable']);
        self::assertSame('iso_8859_1', $gzip['filename_encoding']);
        self::assertSame('café.tar', $gzip['filename']);
        self::assertFalse($gzip['filename_display_truncated']);
        self::assertNull($gzip['filename_utf8_text']);
        self::assertTrue($gzip['comment_utf8_valid']);
        self::assertTrue($gzip['comment_latin1_printable']);
        // RFC 1952 mandates ISO-8859-1: UTF-8-looking bytes stay Latin-1 and the UTF-8
        // reading is only an observed alternative.
        self::assertSame('iso_8859_1', $gzip['comment_encoding']);
        self::assertSame("packed by caf\u{c3}\u{a9}", $gzip['comment']);
        self::assertSame('packed by café', $gzip['comment_utf8_text']);
        self::assertFalse($gzip['comment_display_truncated']);

        // Valid UTF-8 with C1-range continuation bytes is text, never binary.
        $utf8 = $headers[2];
        self::assertSame('gzip', $utf8['format']);
        self::assertTrue($utf8['filename_utf8_valid']);
        self::assertFalse($utf8['filename_latin1_printable']);
        self::assertSame('iso_8859_1', $utf8['filename_encoding']);
        self::assertSame("\u{c3}\u{89}pisode.tar", $utf8['filename']);
        self::assertSame('Épisode.tar', $utf8['filename_utf8_text']);
        self::assertSame('iso_8859_1', $utf8['comment_encoding']);
        self::assertSame('– note', $utf8['comment_utf8_text']);
    }

    #[Test]
    public function it_retains_conditional_iso_path_table_copy_evidence_through_yenc(): void
    {
        $sample = str_repeat("\0", 49_152);
        $primary = "\x01CD001\x01".str_repeat("\0", 2041);
        $primary = substr_replace($primary, pack('V', 32).pack('N', 32), 80, 8);
        $primary = substr_replace($primary, pack('v', 2048).pack('n', 2048), 128, 4);
        $primary = substr_replace($primary, pack('V', 10).pack('N', 10), 132, 8);
        $primary = substr_replace($primary, pack('V2', 20, 21).pack('N2', 22, 23), 140, 16);
        $primary[881] = "\x01";
        $sample = substr_replace($sample, $primary, 32_768, 2048);
        $sample = substr_replace($sample, "\xffCD001\x01".str_repeat("\0", 2041), 34_816, 2048);
        $little = hex2bin('01001800000001000000');
        $big = hex2bin('01000000001800010000');
        self::assertIsString($little);
        self::assertIsString($big);
        foreach ([40_960, 43_008] as $offset) {
            $sample = substr_replace($sample, $little, $offset, 10);
        }
        foreach ([45_056, 47_104] as $offset) {
            $sample = substr_replace($sample, $big, $offset, 10);
        }
        $sample[43_010] = "\x19";
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for ISO path-table integration.');
        }
        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $copies = $analysis['metadata']['decoded_format_metadata']['iso_path_table_copies'];
        self::assertFalse($copies['image_origin_known']);
        self::assertFalse($copies['whole_image_validated']);
        self::assertNull($copies['qualified_path_table_copy_agreement']);
        self::assertStringContainsString('assumed', $copies['mapping_assumption']);
        self::assertSame(hash('sha256', $primary), $copies['source_primary_descriptor_sha256']);
        self::assertSame('terminated', $copies['sequence_status']);
        self::assertCount(4, $copies['tables']);
        self::assertCount(2, $copies['pairs']);
        self::assertSame(hash('sha256', $little), $copies['tables'][0]['raw_sha256']);
        self::assertSame(hash('sha256', $big), $copies['tables'][2]['raw_sha256']);
        self::assertSame(10, $copies['tables'][0]['bytes_hashed']);
        self::assertSame(32_912, $copies['tables'][1]['source_location_field_offset']);
        self::assertFalse($copies['pairs'][0]['candidate_range_bytes_equal']);
        self::assertSame(2, $copies['pairs'][0]['first_difference']['table_relative_offset']);
        self::assertSame(40_962, $copies['pairs'][0]['first_difference']['required_sample_offset']);
        self::assertSame(43_010, $copies['pairs'][0]['first_difference']['optional_sample_offset']);
        self::assertSame(24, $copies['pairs'][0]['first_difference']['required_byte']);
        self::assertSame(25, $copies['pairs'][0]['first_difference']['optional_byte']);
        self::assertTrue($copies['pairs'][1]['candidate_range_bytes_equal']);
    }

    #[Test]
    public function it_preserves_experimental_xz_footer_evidence_through_yenc(): void
    {
        $sample = hex2bin('fd377a585a000000ff12d94102c0050121010000f05dfb9f010000410000000000011101ada6580406729e7a010000000000595a');
        self::assertIsString($sample);
        $native = (new NativeMetadataParser)->parseFormats($sample);
        if (! is_array($native['headers'][0]['first_block']['following_footer'] ?? null)) {
            self::markTestSkipped('An experimental-xz-index Rust library is required for Footer propagation.');
        }
        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $block = $analysis['metadata']['decoded_format_metadata']['headers'][0]['first_block'];
        self::assertSame($native['headers'][0]['first_block']['following_footer'], $block['following_footer']);
        self::assertSame('footer_index_and_header_declarations_match', $block['following_footer']['status']);
        self::assertSame('06729e7a010000000000595a', $block['following_footer']['observed_hex']);
        self::assertSame(40, $block['following_footer']['start_offset']);
        self::assertSame(52, $block['following_footer']['end_offset_exclusive']);
        self::assertFalse($block['whole_stream_validated']);
        self::assertFalse($block['content_decompressed']);
    }

    #[Test]
    public function it_exposes_bounded_xz_first_block_declarations_and_sample_ranges(): void
    {
        $sample = hex2bin('fd377a585a000000ff12d94102c0050121010000f05dfb9f010000410000000000011101ada6580406729e7a010000000000595a');
        self::assertIsString($sample);
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for XZ first-block integration.');
        }
        $analyzer = new NzbFingerprintAnalyzer(new YencService);
        $body = YencArticles::article($sample);
        $analysis = $analyzer->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $format = $analysis['metadata']['decoded_format_metadata']['headers'][0];
        self::assertSame('xz', $format['format']);
        $block = $format['first_block'];
        self::assertSame('declared_block_ranges', $block['extent_status']);
        self::assertSame(12, $block['header_start_offset']);
        self::assertTrue($block['header_crc32_valid']);
        self::assertSame(5, $block['compressed_size']['value']);
        self::assertSame([24, 29, 5], array_map(static fn (string $key): int => $block['compressed_data'][$key], ['start_offset', 'end_offset_exclusive', 'available_bytes']));
        self::assertTrue($block['block_padding_zero']);
        self::assertSame(32, $block['next_item_offset']);
        self::assertFalse($block['whole_stream_validated']);
        self::assertFalse($block['content_decompressed']);
        self::assertFalse($block['file_offset_known']);
        self::assertStringContainsString('sample_relative', $block['offset_basis']);
        $sample[29] = "\x01";
        $conflict = $analyzer->analyzeArticleAndDecodedBytes([], YencArticles::article($sample));
        $block = $conflict['metadata']['decoded_format_metadata']['headers'][0]['first_block'];
        self::assertTrue($block['header_crc32_valid']);
        self::assertFalse($block['block_padding_zero']);
        self::assertSame('declared_block_ranges', $block['extent_status']);
        $huge = hex2bin('fd377a585a000000ff12d9410440e8ffffffffffffff7f21010000007725567d');
        self::assertIsString($huge);
        $large = $analyzer->analyzeArticleAndDecodedBytes([], YencArticles::article($huge));
        $block = $large['metadata']['decoded_format_metadata']['headers'][0]['first_block'];
        self::assertSame('9223372036854775816', $block['next_item_offset']);
        self::assertFalse($block['minimum_stream_size_lower_bound_fits']);
        self::assertFalse($block['compressed_data']['complete']);
        $sample[8] = chr(ord($sample[8]) ^ 1);
        $invalid = $analyzer->analyzeArticleAndDecodedBytes([], YencArticles::article($sample));
        $block = $invalid['metadata']['decoded_format_metadata']['headers'][0]['first_block'];
        self::assertFalse($block['stream_header_qualified']);
        self::assertTrue($block['header_crc32_valid']);
        self::assertSame(5, $block['compressed_size']['value']);
        self::assertNull($block['compressed_data']);
    }

    #[Test]
    public function it_exposes_zstandard_declarations_and_literal_size_relationships(): void
    {
        $literal = hex2bin('28b52ffd24041b00004111223344');
        self::assertIsString($literal);
        if ((new NativeMetadataParser)->parseFormats($literal) === null) {
            self::markTestSkipped('Native Rust format metadata is required for Zstandard integration.');
        }
        $body = YencArticles::article($literal);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($literal, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $format = $analysis['metadata']['decoded_format_metadata']['headers'][0];
        self::assertSame('zstandard', $format['format']);
        $frame = $format['frame'];
        self::assertSame('observed_supported_header', $frame['header_status']);
        self::assertSame(4, $frame['content_size']['value']);
        self::assertSame(1, $frame['first_block']['physical_payload_bytes']);
        self::assertSame(3, $frame['first_block']['regenerated_size']);
        self::assertSame('different', $frame['size_relationship']['status']);
        self::assertSame('observed_unverified', $frame['checksum']['status']);
        self::assertSame(0x44332211, $frame['checksum']['observed_little_endian_value']);
        self::assertSame('not_performed', $frame['checksum']['verification_status']);
        self::assertSame(14, $frame['observed_frame_end_offset']);
        self::assertFalse($frame['file_offset_known']);
        self::assertFalse($frame['whole_file_validated']);
        self::assertFalse($frame['content_decompressed']);

        // Limit observations do not suppress known literal framing or disagreement.
        foreach ([
            ['28b52ffd240119000041424311223344', 'different', 12],
            ['28b52ffd24011300004111223344', 'different', 10],
            ['28b52ffd24000300004111223344', 'equal', 10],
        ] as [$hex, $relationship, $checksumOffset]) {
            $sample = hex2bin($hex);
            self::assertIsString($sample);
            $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], YencArticles::article($sample));
            self::assertSame($sample, $analysis['decoded_bytes']);
            $frame = $analysis['metadata']['decoded_format_metadata']['headers'][0]['frame'];
            self::assertSame($relationship, $frame['size_relationship']['status']);
            self::assertNull($frame['size_relationship']['unavailable_reason']);
            self::assertSame($checksumOffset, $frame['checksum']['start_offset']);
            self::assertSame('observed_unverified', $frame['checksum']['status']);
            self::assertSame(strlen($sample), $frame['observed_frame_end_offset']);
            self::assertFalse($frame['whole_file_validated']);
        }

        $huge = hex2bin('28b52ffde0ffffffffffffffff010000');
        self::assertIsString($huge);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], YencArticles::article($huge));
        self::assertSame($huge, $analysis['decoded_bytes']);
        $frame = $analysis['metadata']['decoded_format_metadata']['headers'][0]['frame'];
        self::assertSame('18446744073709551615', $frame['content_size']['value']);
        self::assertSame('18446744073709551615', $frame['window_size']);
        self::assertSame('different', $frame['size_relationship']['status']);
        self::assertSame(0, $frame['first_block']['regenerated_size']);
    }

    #[Test]
    public function it_keeps_iso_primary_descriptor_byte_conflicts_and_sample_provenance(): void
    {
        $primary = "\x01CD001\x01".str_repeat("\0", 2041);
        $primary[40] = 'A';
        $later = $primary;
        $later[40] = 'B';
        $terminator = "\xffCD001\x01".str_repeat("\0", 2041);
        $sample = str_repeat("\0", 32_768).$primary.$later.$terminator;
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for ISO descriptor integration.');
        }
        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $copies = $analysis['metadata']['decoded_format_metadata']['iso_primary_volume_descriptor_copies'];
        self::assertSame('observed_copies_differ', $copies['status']);
        self::assertSame('terminated', $copies['sequence_status']);
        self::assertTrue($copies['sampled_descriptor_sequence_terminated']);
        self::assertSame(2, $copies['observed_descriptor_count']);
        self::assertSame(1, $copies['repeat_comparison_count']);
        self::assertFalse($copies['all_observed_copies_equal']);
        self::assertSame(hash('sha256', $primary), $copies['copies'][0]['raw_sha256']);
        self::assertSame(hash('sha256', $later), $copies['copies'][1]['raw_sha256']);
        self::assertSame(2048, $copies['copies'][1]['bytes_hashed']);
        self::assertSame(34_816, $copies['copies'][1]['offset']);
        self::assertSame(36_864, $copies['copies'][1]['end_offset_exclusive']);
        self::assertSame(40, $copies['first_difference']['descriptor_relative_offset']);
        self::assertSame(32_808, $copies['first_difference']['reference_sample_offset']);
        self::assertSame(34_856, $copies['first_difference']['differing_sample_offset']);
        self::assertSame(65, $copies['first_difference']['reference_byte']);
        self::assertSame(66, $copies['first_difference']['differing_byte']);
        self::assertFalse($copies['whole_image_validated']);
        self::assertFalse($copies['image_origin_known']);
        self::assertStringContainsString('sample-relative', $copies['offset_basis']);
    }

    #[Test]
    public function it_exposes_empty_rar5_file_copy_checksums_without_hashing_target_contents(): void
    {
        $sample = hex2bin('526172211a070100c51a333203010000ab8240160e0202010401008b9ed9d30000016141'
            .'b72e581d1502030600040100000000000000016205050500016119b23a3503050000');
        self::assertIsString($sample);
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for file-copy checksum integration.');
        }
        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $blocks = $analysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'];
        self::assertSame(0xD3D99E8B, $blocks[1]['file_header']['data_crc32']);
        self::assertSame('unpacked_file_data', $blocks[1]['file_header']['checksum_domain']);
        $copy = $blocks[2]['file_header'];
        self::assertSame('b', $copy['filename']);
        self::assertSame(0, $copy['packed_size']);
        self::assertSame(1, $copy['unpacked_size_effective']);
        self::assertSame('empty_file_copy_entry_data', $copy['checksum_domain']);
        self::assertSame('empty_file_copy_entry_data', $copy['data_crc32_scope']);
        $relationship = $copy['redirection_checksum_relationship'];
        self::assertSame('qualified_empty_entry_domain', $relationship['status']);
        self::assertTrue($relationship['stored_crc32_matches_empty_data']);
        self::assertSame('CRC32', $relationship['reference_selected_checksum']);
        self::assertSame(36, $relationship['source_header_offset']);
        self::assertSame(48, $relationship['source_crc32_byte_offset']);
        self::assertSame(56, $relationship['source_record_offset']);
        self::assertSame(62, $relationship['source_record_end_offset']);
        self::assertFalse($relationship['target_resolution_performed']);

        $blake = hex2bin('526172211a070100c51a33320301000024c3ca09340203290000010000000162'
            .'050505000161220200dd0e891776933f43c7d032b08a917e25741f8aa9a12c12e1cac8801500f2ca4f19b23a3503050000');
        self::assertIsString($blake);
        $blakeAnalysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], YencArticles::article($blake));
        $blakeCopy = $blakeAnalysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1]['file_header'];
        self::assertTrue($blakeCopy['redirection_checksum_relationship']['stored_blake2sp_matches_empty_data']);
        self::assertSame('BLAKE2sp', $blakeCopy['redirection_checksum_relationship']['reference_selected_checksum']);
        self::assertNull($blakeCopy['redirection_checksum_relationship']['stored_crc32_matches_empty_data']);
        self::assertSame(38, $blakeCopy['redirection_checksum_relationship']['source_hash_record_offset']);
        self::assertSame(73, $blakeCopy['redirection_checksum_relationship']['source_hash_record_end_offset']);
        self::assertSame(41, $blakeCopy['redirection_checksum_relationship']['source_hash_digest_offset']);
    }

    #[Test]
    public function it_exposes_quick_open_cache_relationships_in_native_release_metadata(): void
    {
        $sample = hex2bin('526172211a070100fec2942c08010104000301011bb50bc71209020000000000000161'
            .'28a2c30a0b030216001600000002514f67a54b8b11000e0e0f5ace8b0902000000000000016219b23a3503050000');
        self::assertIsString($sample);
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for Quick Open integration.');
        }
        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $blocks = $analysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'];
        self::assertSame('a', $blocks[1]['file_header']['filename']);
        $cache = $blocks[2]['quick_open_cache'];
        self::assertSame('complete', $cache['status']);
        self::assertTrue($cache['declared_data_fully_scanned']);
        self::assertSame(1, $cache['record_count']);
        $record = $cache['records'][0];
        self::assertTrue($record['structure_crc32_valid']);
        self::assertFalse($record['cached_bytes_match_original']);
        self::assertSame('mismatch', $record['comparison_status']);
        self::assertSame([51, 73, 59, 73, 21, 35], array_map(
            static fn (string $field): int => $record[$field],
            ['structure_start_offset', 'structure_end_offset', 'cached_data_start_offset', 'cached_data_end_offset', 'original_start_offset', 'original_end_offset'],
        ));
        self::assertSame('b', $record['cached_header']['filename']);
        self::assertSame('0f5ace8b09020000000000000162', $record['cached_data_preview_hex']);
        self::assertSame(hash('sha256', substr($sample, 59, 14)), $record['cached_data_sha256']);
        self::assertSame(hash('sha256', substr($sample, 21, 14)), $record['original_data_sha256']);

        $large = hex2bin('526172211a070100fec2942c08010104000301011bb50bc71209020000000000000161'
            .'37d760a30b030212001200000002514fd31cdf840d00ffffffffffffffffff01016119b23a3503050000');
        self::assertIsString($large);
        $largeAnalysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], YencArticles::article($large));
        $largeRecord = $largeAnalysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][2]['quick_open_cache']['records'][0];
        self::assertSame('18446744073709551615', $largeRecord['backward_offset']);
        self::assertSame('original_precedes_sample', $largeRecord['comparison_status']);
        self::assertNull($largeRecord['cached_bytes_match_original']);
    }

    #[Test]
    public function it_renders_persisted_quick_open_evidence_with_escaped_names_and_endpoint_provenance(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
        });
        $migration = require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php');
        $migration->up();
        DB::table('releases')->insert(['id' => 1]);
        ReleaseFingerprintMetadata::query()->create([
            'releases_id' => 1,
            'status' => 'partial',
            'metadata' => ['schema_version' => 28, 'article_observations' => [[
                'file_index' => 3, 'kind' => 'rar', 'message_id' => '<qo-fixture@example.invalid>',
                'segment_number' => 1, 'sample_role' => 'first',
                'server' => 'fixture.example', 'provider_label' => 'fixture', 'server_port' => 563,
                'server_tls' => true, 'server_role' => 'primary',
                'metadata' => ['decoded_format_metadata' => ['headers' => [['visible_block_walk' => ['blocks' => [[
                    'header_start_offset' => 35,
                    'quick_open_cache' => ['status' => 'service_data_partial', 'service_data_crc32_status' => 'data_partial',
                        'records' => [['comparison_status' => 'mismatch', 'original_start_offset' => 21,
                            'cached_data_start_offset' => 59, 'cached_header' => ['filename' => '<script>bad()</script>']]]],
                ]]]]]]],
            ]]],
        ]);
        $persisted = ReleaseFingerprintMetadata::query()->findOrFail(1);
        $observation = $persisted->metadata['article_observations'][0];
        self::assertSame('<qo-fixture@example.invalid>', $observation['message_id']);
        self::assertSame([3, 1, 'first', 'fixture.example', 563, true, 'primary'], array_map(
            static fn (string $field): mixed => $observation[$field],
            ['file_index', 'segment_number', 'sample_role', 'server', 'server_port', 'server_tls', 'server_role'],
        ));
        self::assertSame(21, $observation['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][0]['quick_open_cache']['records'][0]['original_start_offset']);
        $html = view('details.partials.fingerprint-metadata', ['fingerprintMetadata' => $persisted])->render();
        self::assertStringContainsString('quick_open_cache', $html);
        self::assertStringContainsString('mismatch', $html);
        self::assertStringContainsString('fixture.example', $html);
        self::assertStringContainsString('&lt;qo-fixture@example.invalid&gt;', $html);
        foreach (['file_index', 'segment_number', 'sample_role', 'server_port', 'server_tls', 'server_role',
            'decoded_format_metadata', 'original_start_offset', 'cached_data_start_offset', 'data_partial'] as $field) {
            self::assertStringContainsString($field, $html);
        }
        self::assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>bad()</script>', $html);
    }

    #[Test]
    public function it_exposes_archived_revision_declarations_without_replacing_stored_names(): void
    {
        $sample = hex2bin('526172211a070100c51a333203010000c238faf81702010400000000010a7265706f72742e7478740304000719b23a3503050000');
        self::assertIsString($sample);
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for archived revision integration.');
        }
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], YencArticles::article($sample));
        self::assertSame($sample, $analysis['decoded_bytes']);
        $file = $analysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1]['file_header'];
        self::assertSame('report.txt', $file['filename']);
        $extras = $file['file_extra_records'];
        self::assertSame(1, $extras['version_record_count']);
        self::assertSame(7, $extras['version']['version_number']);
        self::assertSame('validated_header_declaration', $extras['version']['interpretation_status']);
        self::assertSame('record-payload-relative', $extras['version']['coordinate_basis']);
        self::assertSame(42, $extras['version']['source_payload_offset']);
        self::assertSame(1, $extras['version']['version_number_byte_offset']);
        self::assertSame('report.txt;7', $extras['reference_interpreted_name']);
        self::assertSame($extras['version'], $extras['records'][0]['version']);

        $large = hex2bin('526172211a070100c51a333203010000afdaaf5e1702010d000000000101780c0400ffffffffffffffffff0119b23a3503050000');
        self::assertIsString($large);
        $largeAnalysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], YencArticles::article($large));
        $largeExtras = $largeAnalysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1]['file_header']['file_extra_records'];
        self::assertSame('18446744073709551615', $largeExtras['version']['version_number']);
        self::assertSame('version_outside_32bit_reference_range', $largeExtras['reference_name_relationship_status']);
        self::assertNull($largeExtras['reference_interpreted_name']);
    }

    #[Test]
    public function it_exposes_rar5_file_times_in_native_release_metadata(): void
    {
        $sample = hex2bin('526172211a070100c51a333203010000af35bf2f2902011b0200000100000000010178'
            .'1a031f01000000020000000300000004000000050000000600000019b23a3503050000');
        self::assertIsString($sample);
        if ((new NativeMetadataParser)->parseFormats($sample) === null) {
            self::markTestSkipped('Native Rust format metadata is required for RAR5 time integration.');
        }
        $body = YencArticles::article($sample);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        self::assertSame($sample, $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $analysis['metadata']['article_body_sha256']);
        $extras = $analysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1]['file_header']['file_extra_records'];
        self::assertSame(1, $extras['time_record_count']);
        self::assertTrue($extras['base_header_mtime_seconds_matches']);
        self::assertSame('declared_base_seconds_consistency_only', $extras['mtime_comparison_status']);
        self::assertSame('validated_header_declaration', $extras['time']['interpretation_status']);
        self::assertSame(37, $extras['time']['source_payload_offset']);
        self::assertSame([1, 2, 3], array_column($extras['time']['times'], 'base_value'));
        self::assertSame([4, 5, 6], array_column($extras['time']['times'], 'nanoseconds'));
        self::assertSame(['not_applicable_unix_seconds'], array_unique(array_column($extras['time']['times'], 'filetime_epoch_relationship_status')));

        // Independently framed literal: coarse mtime 1, FILETIME epoch + 1.1234567 seconds.
        $filetimeSample = hex2bin('526172211a070100c51a333203010000c1f085c51902010b0200000100000000010178'
            .'0a030207ede9d5deb19d0119b23a3503050000');
        self::assertIsString($filetimeSample);
        $filetimeBody = YencArticles::article($filetimeSample);
        $filetimeAnalysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $filetimeBody);
        self::assertSame($filetimeSample, $filetimeAnalysis['decoded_bytes']);
        self::assertSame(hash('sha256', $filetimeBody), $filetimeAnalysis['metadata']['article_body_sha256']);
        $filetimeExtras = $filetimeAnalysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1]['file_header']['file_extra_records'];
        self::assertTrue($filetimeExtras['base_header_mtime_seconds_matches']);
        self::assertSame('declared_filetime_floor_seconds_consistency_only', $filetimeExtras['mtime_comparison_status']);
        $time = $filetimeExtras['time']['times'][0];
        self::assertSame(116444736011234567, $time['base_value']);
        self::assertSame(1, $time['filetime_unix_seconds_floor']);
        self::assertSame(123456700, $time['filetime_unix_subsecond_nanoseconds']);
        self::assertTrue($time['filetime_calendar_api_input_range_supported']);
        self::assertSame('declared_epoch_arithmetic_only', $time['filetime_epoch_relationship_status']);
        self::assertSame(37, $filetimeExtras['time']['source_payload_offset']);
        self::assertSame($filetimeExtras['time']['times'], $filetimeExtras['records'][0]['time']['times']);

        $serviceSample = hex2bin('526172211a070100c51a3332030100005330bc561b03010b02000001000000000103434d54'
            .'0a030207ede9d5deb19d0119b23a3503050000');
        self::assertIsString($serviceSample);
        $serviceAnalysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], YencArticles::article($serviceSample));
        self::assertSame($serviceSample, $serviceAnalysis['decoded_bytes']);
        $block = $serviceAnalysis['metadata']['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1];
        self::assertSame(3, $block['header_type']);
        self::assertNull($block['file_header']);
        self::assertSame('434d54', $block['service_name_bytes_hex']);
        self::assertSame('service_identifier_not_archived_file_name', $block['service_header']['name_interpretation']);
        $serviceExtras = $block['service_header']['file_extra_records'];
        self::assertSame(39, $serviceExtras['time']['source_payload_offset']);
        self::assertSame(1, $serviceExtras['time']['times'][0]['filetime_unix_seconds_floor']);
        self::assertSame(123456700, $serviceExtras['time']['times'][0]['filetime_unix_subsecond_nanoseconds']);
        self::assertTrue($serviceExtras['base_header_mtime_seconds_matches']);
    }

    #[Test]
    public function it_excludes_unconfirmed_head_dates_from_effective_timeline_statistics(): void
    {
        $observations = [
            $this->timelineObservation('first@example.net', 1000),
            $this->timelineObservation('last@example.net', 1060),
        ];
        foreach ($this->unconfirmedHeadIdentities() as $name => $case) {
            $observation = $this->timelineObservation($name.'@example.net', 5000);
            $observation['metadata']['article_identity'] = array_replace_recursive(
                $observation['metadata']['article_identity'],
                $case['identity'],
            );
            $observations[] = $observation;
        }
        $originalObservations = $observations;
        $expected = count($observations);
        $report = $this->postingTimeline($observations, $expected);

        foreach ([
            'observed_article_date_span_by_server' => ['1970-01-01T00:16:40+00:00', '1970-01-01T00:17:40+00:00'],
            'observed_injection_date_span_by_server' => ['1970-01-01T00:16:50+00:00', '1970-01-01T00:17:50+00:00'],
            'observed_legacy_nntp_posting_date_span_by_server' => ['1970-01-01T00:17:00+00:00', '1970-01-01T00:18:00+00:00'],
        ] as $key => [$first, $last]) {
            $span = $report[$key]['primary news.example.net'];
            self::assertSame(2, $span['valid_timestamp_count'], $key);
            self::assertSame($first, $span['first_utc'], $key);
            self::assertSame($last, $span['last_utc'], $key);
            self::assertSame(60, $span['seconds'], $key);
            self::assertSame($expected, $span['observations'], $key);
            self::assertSame($expected, $span['expected_samples'], $key);
            self::assertFalse($span['complete'], $key);
        }
        $deltas = $report['composition_to_injection_delta_seconds'];
        self::assertSame(2, $deltas['paired_samples']);
        foreach (['min', 'median', 'p95', 'max'] as $key) {
            self::assertSame(10, $deltas[$key]);
        }
        $coverage = $report['head_date_identity_coverage_by_server']['primary news.example.net'];
        self::assertSame(2, $coverage['admitted_count']);
        self::assertSame($expected - 2, $coverage['excluded_count']);
        self::assertSame(2, $coverage['status_counts']['identity_verified']);
        self::assertSame($originalObservations, $observations);
        self::assertSame('valid', $observations[2]['metadata']['article_dates']['date']['parse_status']);
        self::assertSame(5000, $observations[2]['metadata']['article_dates']['date']['utc_epoch_seconds']);
    }

    #[Test]
    public function it_reports_each_head_identity_exclusion_without_discarding_raw_dates(): void
    {
        foreach ($this->unconfirmedHeadIdentities() as $name => $case) {
            $observation = $this->timelineObservation($name.'@example.net', 1000);
            $observation['metadata']['article_identity'] = array_replace_recursive(
                $observation['metadata']['article_identity'],
                $case['identity'],
            );
            $report = $this->postingTimeline([$observation], 1);
            foreach (['observed_article_date_span_by_server', 'observed_injection_date_span_by_server', 'observed_legacy_nntp_posting_date_span_by_server'] as $key) {
                self::assertFalse($report[$key]['primary news.example.net']['available'], $name.' '.$key);
                self::assertSame(0, $report[$key]['primary news.example.net']['valid_timestamp_count'], $name);
                self::assertSame(1, $report[$key]['primary news.example.net']['expected_samples'], $name);
            }
            self::assertSame(0, $report['composition_to_injection_delta_seconds']['paired_samples'], $name);
            self::assertNull($report['composition_to_injection_delta_seconds']['min'], $name);
            $coverage = $report['head_date_identity_coverage_by_server']['primary news.example.net'];
            self::assertSame(0, $coverage['admitted_count'], $name);
            self::assertSame(1, $coverage['excluded_count'], $name);
            self::assertSame(1, $coverage['status_counts'][$case['status']], $name);
            self::assertSame(1000, $observation['metadata']['article_dates']['date']['utc_epoch_seconds'], $name);
        }
    }

    #[Test]
    public function it_admits_verified_head_dates_independently_of_body_and_overview_identity(): void
    {
        $observation = $this->timelineObservation('head-only@example.net', 1000);
        $observation['metadata']['article_identity']['body_response'] = ['availability' => 'failed', 'parse_status' => 'unavailable', 'response_code' => null];
        $observation['metadata']['article_identity']['overview_message_id'] = ['availability' => 'unavailable', 'parse_status' => 'unavailable'];
        $observation['metadata']['article_identity']['relations']['body_response_matches_request'] = false;
        $observation['metadata']['article_identity']['relations']['head_response_matches_body_response'] = false;
        $report = $this->postingTimeline([$observation], 1);
        foreach (['observed_article_date_span_by_server', 'observed_injection_date_span_by_server', 'observed_legacy_nntp_posting_date_span_by_server'] as $key) {
            self::assertTrue($report[$key]['primary news.example.net']['available'], $key);
            self::assertSame(1, $report[$key]['primary news.example.net']['valid_timestamp_count'], $key);
            self::assertTrue($report[$key]['primary news.example.net']['complete'], $key);
        }
        self::assertSame(1, $report['composition_to_injection_delta_seconds']['paired_samples']);
        $coverage = $report['head_date_identity_coverage_by_server']['primary news.example.net'];
        self::assertSame(1, $coverage['admitted_count']);
        self::assertSame(0, $coverage['excluded_count']);
    }

    /** @return array<string, array{identity: array<string, mixed>, status: string}> */
    private function unconfirmedHeadIdentities(): array
    {
        return [
            'invalid-request' => ['identity' => ['request' => ['syntax_status' => 'malformed', 'token' => null]], 'status' => 'invalid_request_identity'],
            'wrong-request-token' => ['identity' => ['request' => ['token' => '<other@example.net>']], 'status' => 'invalid_request_identity'],
            'request-case-differs' => ['identity' => ['request' => ['token' => '<REQUEST-CASE-DIFFERS@example.net>']], 'status' => 'invalid_request_identity'],
            'response-mismatch' => ['identity' => ['relations' => ['head_response_matches_request' => false]], 'status' => 'identity_mismatch'],
            'header-mismatch' => ['identity' => ['head_message_id' => ['matches_request' => false], 'relations' => ['head_response_matches_head_field' => false]], 'status' => 'identity_mismatch'],
            'header-case-differs' => ['identity' => ['head_message_id' => ['matches_request' => false, 'candidates' => [['token' => '<HEADER-CASE-DIFFERS@example.net>', 'syntax_status' => 'valid']]], 'relations' => ['head_response_matches_head_field' => false]], 'status' => 'identity_mismatch'],
            'missing-header-id' => ['identity' => ['head_message_id' => ['parse_status' => 'missing', 'occurrence_count' => 0, 'matches_request' => null], 'relations' => ['head_response_matches_head_field' => null]], 'status' => 'identity_unconfirmed'],
            'duplicate-header-id' => ['identity' => ['head_message_id' => ['parse_status' => 'duplicate', 'occurrence_count' => 2, 'matches_request' => null], 'relations' => ['head_response_matches_head_field' => null]], 'status' => 'identity_unconfirmed'],
            'header-budget' => ['identity' => ['head_message_id' => ['availability' => 'budget_exceeded', 'parse_status' => 'budget_exceeded']], 'status' => 'identity_unconfirmed'],
            'response-budget' => ['identity' => ['head_response' => ['availability' => 'budget_exceeded', 'parse_status' => 'budget_exceeded']], 'status' => 'identity_unconfirmed'],
            'failed-head' => ['identity' => ['head_response' => ['availability' => 'failed', 'parse_status' => 'unavailable', 'response_code' => null]], 'status' => 'identity_unconfirmed'],
            'malformed-response' => ['identity' => ['head_response' => ['parse_status' => 'malformed_article_number']], 'status' => 'identity_unconfirmed'],
            'unsupported-engine' => ['identity' => ['parser_engine' => null], 'status' => 'identity_parser_unavailable'],
            'unsupported-schema' => ['identity' => ['schema_version' => 2], 'status' => 'identity_parser_unavailable'],
        ];
    }

    /** @return array<string, mixed> */
    private function timelineObservation(string $messageId, int $timestamp): array
    {
        return [
            'message_id' => $messageId,
            'server' => 'news.example.net',
            'server_role' => 'primary',
            'metadata' => [
                'article_dates' => [
                    'date' => ['parse_status' => 'valid', 'utc_epoch_seconds' => $timestamp],
                    'injection_date' => ['parse_status' => 'valid', 'utc_epoch_seconds' => $timestamp + 10],
                    'legacy_nntp_posting_date' => ['parse_status' => 'valid', 'utc_epoch_seconds' => $timestamp + 20],
                ],
                'article_identity' => [
                    'parser_engine' => 'nntmux-rust-article-identity',
                    'schema_version' => 3,
                    'request' => ['syntax_status' => 'valid', 'token' => '<'.$messageId.'>'],
                    'head_response' => ['availability' => 'available', 'parse_status' => 'valid', 'response_code' => 221, 'message_id' => '<'.$messageId.'>'],
                    'head_message_id' => ['availability' => 'available', 'parse_status' => 'valid', 'occurrence_count' => 1, 'matches_request' => true, 'candidates' => [['token' => '<'.$messageId.'>', 'syntax_status' => 'valid']]],
                    'relations' => ['head_response_matches_request' => true, 'head_response_matches_head_field' => true],
                ],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $observations
     * @return array<string, mixed>
     */
    private function postingTimeline(array $observations, int $expected): array
    {
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($collector, 'estimatePostingTimeline'))->invoke(
            $collector,
            [],
            $observations,
            [['name' => 'news.example.net', 'alternate' => false]],
            $expected,
        );
    }

    #[Test]
    public function it_keeps_timestamp_evidence_when_advertised_byte_rates_overflow(): void
    {
        // This pure estimator does not use the collector's storage or NNTP dependencies.
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $files = [
            ['date' => 120, 'advertised_bytes' => PHP_INT_MAX, 'segments' => []],
            ['date' => 120, 'advertised_bytes' => 1, 'segments' => []],
            ['date' => 180, 'advertised_bytes' => 1, 'segments' => []],
        ];

        $report = (new \ReflectionMethod($collector, 'estimatePostingTimeline'))->invoke($collector, $files, [], [], 0);
        $span = $report['nzb_file_timestamp_span'];

        self::assertTrue($span['available']);
        self::assertSame(60, $span['timestamp_span_seconds']);
        self::assertNull($span['advertised_bytes']);
        self::assertNull($span['whole_span_average_advertised_bytes_per_second']);
        self::assertNull($span['observed_nonempty_minute_bucket_rates_bytes_per_second']['maximum_nonempty_bucket']);
    }

    #[Test]
    public function it_does_not_clamp_unsupported_nzb_sizes(): void
    {
        $analyzer = new NzbFingerprintAnalyzer(new YencService);
        $unsupported = '<nzb><file subject="one.rar"><segments><segment bytes="18446744073709551615" number="1">one@example.net</segment></segments></file></nzb>';
        $report = $analyzer->analyzeManifest($unsupported);
        self::assertSame(1, $report['files'][0]['invalid_segment_metadata_count']);
        self::assertNull($report['files'][0]['advertised_bytes']);
        self::assertNull($report['totals']['rar']['advertised_bytes']);
        self::assertNull($report['par2_to_rar_advertised_percent']);
        self::assertFalse($report['advertised_byte_totals_valid']);
    }

    #[Test]
    public function it_flags_individual_decimal_nzb_size_overflow_separately_from_malformed_values(): void
    {
        foreach (['9223372036854775808' => true, '0009223372036854775808' => true, '18446744073709551615' => true, 'not-a-number' => false, '-1' => false] as $bytes => $overflow) {
            $xml = '<nzb><file subject="one.rar"><segments><segment bytes="'.$bytes.'" number="1">one@example.net</segment></segments></file></nzb>';
            $report = (new NzbFingerprintAnalyzer(new YencService))->analyzeManifest($xml);
            self::assertNull($report['files'][0]['advertised_bytes']);
            self::assertSame($overflow, $report['files'][0]['advertised_bytes_overflow']);
            self::assertSame($overflow, $report['totals']['rar']['advertised_bytes_overflow']);
        }
    }

    #[Test]
    public function it_does_not_round_overflowing_nzb_totals_across_files(): void
    {
        $analyzer = new NzbFingerprintAnalyzer(new YencService);
        $maximum = (string) PHP_INT_MAX;
        $overflow = '<nzb><file subject="one.rar"><segments><segment bytes="'.$maximum.'" number="1">one@example.net</segment></segments></file><file subject="two.rar"><segments><segment bytes="1" number="1">two@example.net</segment></segments></file></nzb>';
        $report = $analyzer->analyzeManifest($overflow);
        self::assertSame(PHP_INT_MAX, $report['files'][0]['advertised_bytes']);
        self::assertSame(1, $report['files'][1]['advertised_bytes']);
        self::assertNull($report['totals']['rar']['advertised_bytes']);
        self::assertFalse($report['advertised_byte_totals_valid']);
    }

    #[Test]
    public function it_does_not_round_overflowing_nzb_totals_within_one_file(): void
    {
        $maximum = (string) PHP_INT_MAX;
        $xml = '<nzb><file subject="one.rar"><segments><segment bytes="'.$maximum.'" number="1">one@example.net</segment><segment bytes="1" number="2">two@example.net</segment></segments></file></nzb>';
        $report = (new NzbFingerprintAnalyzer(new YencService))->analyzeManifest($xml);

        self::assertNull($report['files'][0]['advertised_bytes']);
        self::assertNull($report['totals']['rar']['advertised_bytes']);
        self::assertFalse($report['advertised_byte_totals_valid']);
    }

    #[Test]
    public function it_treats_incomplete_native_framing_evidence_as_ambiguous(): void
    {
        $method = new \ReflectionMethod(NzbFingerprintAnalyzer::class, 'yencFramingEvidenceIncomplete');
        $complete = ['frames' => [['frame_index' => 1, 'size_relationship_status' => 'consistent']], 'lines' => [['frame_index' => 1, 'field_occurrences_truncated' => false]]];
        self::assertFalse($method->invoke(null, $complete));
        foreach (['frames_truncated', 'control_lines_truncated'] as $flag) {
            self::assertTrue($method->invoke(null, $complete + [$flag => true]));
        }
        $truncated = $complete;
        $truncated['lines'][0]['field_occurrences_truncated'] = true;
        self::assertTrue($method->invoke(null, $truncated));
        $ambiguous = $complete;
        $ambiguous['frames'][0]['size_relationship_status'] = 'ambiguous';
        self::assertTrue($method->invoke(null, $ambiguous));
        $orphan = $complete;
        $orphan['lines'][] = ['frame_index' => null, 'field_occurrences_truncated' => false];
        self::assertTrue($method->invoke(null, $orphan));
    }

    #[Test]
    #[DataProvider('trailerOnlyMultipartDeclarations')]
    public function it_withholds_integrity_for_trailer_only_multipart_declarations(string $part, bool $nativeEnabled): void
    {
        $body = "=ybegin line=128 size=3 name=x.rar\r\nklm\r\n=yend size=3 part={$part} pcrc32=a3830348\r\n";
        if (! $nativeEnabled) {
            config(['yenc.native_library' => '']);
        }
        $native = (new NativeMetadataParser)->parseYencFraming($body);
        if ($nativeEnabled && $native === null) {
            self::markTestSkipped('Native yEnc framing library is required for trailer-only multipart integration.');
        }
        if (! $nativeEnabled) {
            self::assertNull($native);
        }

        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        $article = $analysis['metadata'];
        $yenc = $article['yenc'];

        self::assertSame('ABC', $analysis['decoded_bytes']);
        self::assertSame(hash('sha256', $body), $article['article_body_sha256']);
        self::assertSame(hash('sha256', 'ABC'), $yenc['decoded_segment_sha256']);
        self::assertSame(3, $yenc['decoded_segment_size']);
        self::assertSame('A3830348', $yenc['observed_decoded_crc32']);
        self::assertSame('a3830348', $yenc['pcrc32']);
        self::assertSame('pcrc32', $yenc['observed_crc32_declaration_field']);
        self::assertTrue($yenc['observed_crc32_matches_selected_declaration']);
        self::assertSame($part, $yenc['trailer_fields']['part']);
        self::assertNull($yenc['part_fields']);
        self::assertArrayNotHasKey('part', $yenc['header_fields']);
        self::assertCount(2, $yenc['raw_control_lines']);
        self::assertTrue($yenc['multipart']);
        self::assertFalse($yenc['header_numeric_fields_valid']);
        self::assertNull($yenc['expected_decoded_segment_size']);
        self::assertNull($yenc['decoded_size_matches_yenc']);
        self::assertNull($yenc['ypart_range_valid']);
        self::assertNull($yenc['declared_crc32_matches']);
        self::assertNull($yenc['par2_first_16k_md5_candidate']);
        self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid($yenc));
        $rangeMetadata = $article;
        $rangeMetadata['article_identity'] = [
            'parser_engine' => 'nntmux-rust-article-identity',
            'schema_version' => 3,
            'relations' => ['body_response_matches_request' => true],
        ];
        $range = (new \ReflectionMethod(ReleaseFingerprintCollector::class, 'decodedFileRange'))
            ->invoke((new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor(), $rangeMetadata, 'ABC');
        self::assertSame('invalid', $range['mapping_status']);
        self::assertSame('range_or_size_mismatch', $range['integrity_status']);
        if ($nativeEnabled) {
            self::assertSame($native, $article['yenc_framing_metadata']);
            self::assertSame('present-hex', $native['frames'][0]['multipart_pcrc32_status']);
            self::assertNull($native['frames'][0]['expected_segment_size']);
            self::assertNull($native['frames'][0]['yend_size_matches_expected']);
            if ($part === '1') {
                self::assertSame(1, $native['frames'][0]['yend_part_number']);
                self::assertSame('incomplete', $native['frames'][0]['size_relationship_status']);
            }
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function trailerOnlyMultipartDeclarations(): array
    {
        $cases = [];
        foreach (['valid_part' => '1', 'zero_part' => '0', 'empty_part' => '', 'nondecimal_part' => 'invalid'] as $name => $part) {
            $cases[$name.'_fallback'] = [$part, false];
            $cases[$name.'_native'] = [$part, true];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('framingParserBackends')]
    public function it_accepts_identical_control_fields_in_native_and_fallback_metadata(bool $nativeEnabled): void
    {
        $data = str_repeat('A', 16_384);
        $body = YencArticles::part($data, 'x.rar', 32_768, 1, 1, 2);
        $body = str_replace(['part=1', 'size=32768', 'begin=1', 'end=16384', 'pcrc32='.hash('crc32b', $data)],
            ['part=1 part=1', 'size=32768 size=32768', 'begin=1 begin=1', 'end=16384 end=16384', 'pcrc32='.hash('crc32b', $data).' pcrc32='.hash('crc32b', $data)], $body);
        $native = $this->framingBackend($body, $nativeEnabled);
        $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes([], $body);
        $yenc = $analysis['metadata']['yenc'];
        self::assertSame($data, $analysis['decoded_bytes']);
        self::assertFalse($yenc['integrity_checks_ambiguous']);
        self::assertSame('complete', $yenc['multipart_structure_status']);
        self::assertTrue($yenc['declared_crc32_matches']);
        self::assertSame(hash('md5', $data), $yenc['par2_first_16k_md5_candidate']);
        if ($nativeEnabled) {
            self::assertSame('complete', $native['frames'][0]['size_relationship_status']);
            self::assertContains('part', $native['lines'][0]['duplicate_field_names']);
            self::assertSame([], $native['lines'][0]['conflicting_duplicate_field_names']);
        }
    }

    #[Test]
    #[DataProvider('framingParserBackends')]
    public function it_requires_multipart_footer_fields_before_granting_integrity(bool $nativeEnabled): void
    {
        $data = str_repeat('A', 16_384);
        $normal = YencArticles::part($data, 'x.rar', 32_768, 1, 1, 2);
        $this->framingBackend($normal, $nativeEnabled);
        $analyzer = new NzbFingerprintAnalyzer(new YencService);
        self::assertSame(hash('md5', $data), $analyzer->analyzeArticle([], $normal)['yenc']['par2_first_16k_md5_candidate']);
        $crc = hash('crc32b', $data);
        foreach ([
            [str_replace(' part=1 pcrc32=', ' pcrc32=', $normal), 'incomplete'],
            [str_replace(' pcrc32='.$crc, '', $normal), 'incomplete'],
            [str_replace('=yend size=16384', '=yend', $normal), 'incomplete'],
            [str_replace(' pcrc32='.$crc, ' pcrc32=', $normal), 'invalid'],
            [str_replace(' pcrc32='.$crc, ' pcrc32=1', $normal), 'invalid'],
            [str_replace(' pcrc32='.$crc, ' pcrc32=not-hex', $normal), 'invalid'],
            [str_replace(' part=1 pcrc32='.$crc, ' pcrc32=', $normal), 'invalid'],
        ] as [$body, $status]) {
            $analysis = $analyzer->analyzeArticleAndDecodedBytes([], $body);
            $metadata = $analysis['metadata'];
            $yenc = $metadata['yenc'];
            self::assertSame($data, $analysis['decoded_bytes']);
            self::assertSame(hash('sha256', $body), $metadata['article_body_sha256']);
            self::assertSame(hash('sha256', $data), $yenc['decoded_segment_sha256']);
            self::assertSame(strtoupper($crc), $yenc['observed_decoded_crc32']);
            self::assertSame($status, $yenc['multipart_structure_status']);
            self::assertNull($yenc['declared_crc32_matches']);
            self::assertNull($yenc['par2_first_16k_md5_candidate']);
            self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid($yenc));
            $metadata['article_identity'] = ['parser_engine' => 'nntmux-rust-article-identity', 'schema_version' => 3,
                'relations' => ['body_response_matches_request' => true]];
            $range = (new \ReflectionMethod(ReleaseFingerprintCollector::class, 'decodedFileRange'))
                ->invoke((new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor(), $metadata, $data);
            self::assertSame('invalid', $range['mapping_status']);
        }
        $legacy = str_replace(' total=2', '', $normal);
        self::assertSame('complete', $analyzer->analyzeArticle([], $legacy)['yenc']['multipart_structure_status']);
        $wrongCrc = $analyzer->analyzeArticle([], str_replace(' pcrc32='.$crc, ' pcrc32=00000000', $normal))['yenc'];
        self::assertSame('complete', $wrongCrc['multipart_structure_status']);
        self::assertFalse($wrongCrc['declared_crc32_matches']);
        self::assertNull($wrongCrc['par2_first_16k_md5_candidate']);
    }

    /** @return array<string, array{bool}> */
    public static function framingParserBackends(): array
    {
        return ['fallback' => [false], 'native' => [true]];
    }

    /** @return array<string, mixed>|null */
    private function framingBackend(string $body, bool $nativeEnabled): ?array
    {
        if (! $nativeEnabled) {
            config(['yenc.native_library' => '']);
        }
        $native = (new NativeMetadataParser)->parseYencFraming($body);
        if ($nativeEnabled && $native === null) {
            self::markTestSkipped('Native yEnc framing library is required for field authority integration.');
        }
        if (! $nativeEnabled) {
            self::assertNull($native);
        }

        return $native;
    }

    #[Test]
    public function it_withholds_fallback_integrity_for_conflicting_or_unbounded_framing(): void
    {
        config(['yenc.native_library' => '']);
        $normal = "=ybegin size=3 line=128 name=x.rar\r\nklm\r\n=yend size=3 crc32=a3830348\r\n";
        $filler = implode(' ', array_map(static fn (int $i): string => 'x'.$i.'=v', range(1, 62)));
        foreach ([
            str_replace('size=3 line=', 'size=0 size=3 line=', $normal),
            str_replace('=yend size=3', '=yend size=0 size=3', $normal),
            str_replace('=yend size=3', '=yend size=0 '.$filler.' size=3', $normal),
            $normal.$normal,
            "=ypart begin=1 end=3\r\n".$normal,
            $normal."=ypart begin=1 end=3\r\n",
        ] as $body) {
            $article = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $body);
            self::assertTrue($article['yenc']['integrity_checks_ambiguous']);
            self::assertNull($article['yenc']['par2_first_16k_md5_candidate']);
            self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid($article['yenc']));
        }
        $same = str_replace('size=3 line=', 'size=3 size=3 line=', $normal);
        self::assertFalse((new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $same)['yenc']['integrity_checks_ambiguous']);
        $name = str_replace('name=x.rar', 'name=some file size=9.rar', $normal);
        self::assertFalse((new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $name)['yenc']['integrity_checks_ambiguous']);
    }

    #[Test]
    public function it_withholds_native_integrity_for_orphan_control_lines(): void
    {
        $normal = "=ybegin size=3 line=128 name=x.rar\r\nklm\r\n=yend size=3 crc32=a3830348\r\n";
        foreach (['=yend size=3', '=ypart begin=1 end=3'] as $orphan) {
            foreach ([$orphan."\r\n".$normal, $normal.$orphan."\r\n"] as $body) {
                $native = (new NativeMetadataParser)->parseYencFraming($body);
                if ($native === null) {
                    self::markTestSkipped('Native yEnc framing library is required for orphan-line integration.');
                }
                self::assertContains(null, array_column($native['lines'], 'frame_index'));
                $article = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $body);
                self::assertTrue($article['yenc']['integrity_checks_ambiguous']);
                self::assertNull($article['yenc']['par2_first_16k_md5_candidate']);
                self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid($article['yenc']));
            }
        }
    }

    #[Test]
    public function it_withholds_integrity_when_yenc_occurrence_scanning_is_truncated(): void
    {
        $filler = implode(' ', array_map(static fn (int $i): string => 'x'.$i.'=v', range(1, 62)));
        $bodies = [
            '=ybegin size=0 line=128 '.$filler." size=3 name=x.rar\r\nklm\r\n=yend size=3 crc32=a3830348\r\n",
            "=ybegin size=3 line=128 name=x.rar\r\nklm\r\n=yend size=0 crc32=a3830348 ".$filler." size=3\r\n",
        ];
        foreach ($bodies as $body) {
            $native = (new NativeMetadataParser)->parseYencFraming($body);
            if ($native === null) {
                self::markTestSkipped('Native yEnc framing library is required for scan-budget integration.');
            }
            self::assertContains(true, array_column($native['lines'], 'field_occurrences_truncated'));
            $article = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $body);
            self::assertTrue($article['yenc']['integrity_checks_ambiguous']);
            self::assertNull($article['yenc']['par2_first_16k_md5_candidate']);
            self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid($article['yenc']));
        }
    }

    #[Test]
    public function it_preserves_raw_yenc_sizes_without_claiming_an_out_of_range_integer_is_valid(): void
    {
        $body = "=ybegin line=128 size=18446744073709551615 name=one.rar\r\nklm\r\n=yend size=3 crc32=a3830348\r\n";
        $report = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $body);

        self::assertSame('18446744073709551615', $report['yenc']['header_fields']['size']);
        self::assertNull($report['yenc']['file_size']);
        self::assertFalse($report['yenc']['header_numeric_fields_valid']);
    }

    #[Test]
    public function it_marks_terminal_worker_failures_without_recreating_deleted_release_records(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
        });
        $migration = require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php');
        $migration->up();
        DB::table('releases')->insert(['id' => 1]);
        ReleaseFingerprintMetadata::query()->create(['releases_id' => 1, 'status' => 'processing']);

        (new CollectReleaseFingerprintMetadata(1))->failed(new \RuntimeException('private provider details'));

        $record = ReleaseFingerprintMetadata::query()->findOrFail(1);
        self::assertSame('failed', $record->status);
        self::assertSame('RuntimeException', $record->last_error);
        self::assertNotNull($record->collected_at);
        (new CollectReleaseFingerprintMetadata(2))->failed(new \RuntimeException('deleted release'));
        self::assertSame(1, ReleaseFingerprintMetadata::query()->count());
        $record->update(['status' => 'complete']);
        (new CollectReleaseFingerprintMetadata(1))->failed(new \RuntimeException('older expired attempt'));
        self::assertSame('complete', $record->fresh()->status);
    }

    #[Test]
    public function it_bounds_par2_network_samples_and_counts_omitted_unique_articles(): void
    {
        $segments = [];
        for ($number = 1; $number <= 140; $number++) {
            $segments[] = ['number' => $number, 'messageid' => 'par-'.$number.'@example.net'];
        }
        $segments[] = $segments[0];
        $files = [['index' => 0, 'kind' => 'par2', 'subject' => 'set.par2', 'segments' => $segments]];
        $omitted = 0;

        $samples = (new NzbFingerprintAnalyzer(new YencService))->selectArticleSamples($files, 128, $omitted);

        self::assertCount(128, $samples);
        self::assertSame(12, $omitted);
        self::assertSame('par-128@example.net', $samples[127]['messageid']);
    }

    #[Test]
    public function it_requires_the_current_native_nntp_header_parser_before_marking_coverage_complete(): void
    {
        self::assertTrue(NativeMetadataParser::hasCurrentNntpHeaderSchema([
            'parser_engine' => 'nntmux-rust-nntp-headers',
            'schema_version' => NativeMetadataParser::NNTP_HEADER_SCHEMA_VERSION,
            'status' => 'complete',
        ]));
        self::assertFalse(NativeMetadataParser::hasCurrentNntpHeaderSchema([
            'parser_engine' => 'nntmux-rust-nntp-headers',
            'schema_version' => 1,
            'status' => 'complete',
        ]));
        self::assertFalse(NativeMetadataParser::hasCurrentNntpHeaderSchema([
            'parser_engine' => null,
            'schema_version' => NativeMetadataParser::NNTP_HEADER_SCHEMA_VERSION,
            'status' => 'native_parser_unavailable_or_input_over_budget',
        ]));
        self::assertFalse(NativeMetadataParser::hasCurrentNntpHeaderSchema(null));
    }

    #[Test]
    public function it_lists_every_nzb_file_and_calculates_advertised_parity_ratio(): void
    {
        $xml = <<<'XML'
        <nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
          <head><meta type="category">Movies</meta></head>
          <file subject="archive.part01.rar" poster="poster@example.net" date="100"><groups><group>alt.test</group></groups><segments><segment bytes="100" number="1">one@example.net</segment><segment bytes="100" number="2">two@example.net</segment></segments></file>
          <file subject="archive.part02.rar" poster="poster@example.net" date="100"><groups><group>alt.test</group></groups><segments><segment bytes="200" number="1">three@example.net</segment></segments></file>
          <file subject="archive.vol00+01.par2" poster="poster@example.net" date="100"><groups><group>alt.test</group></groups><segments><segment bytes="50" number="1">four@example.net</segment></segments></file>
        </nzb>
        XML;

        $report = (new NzbFingerprintAnalyzer(new YencService))->analyzeManifest($xml);

        self::assertSame(3, $report['file_count']);
        self::assertCount(3, $report['files']);
        self::assertSame(200, $report['files'][0]['advertised_bytes']);
        self::assertSame(400, $report['totals']['rar']['advertised_bytes']);
        self::assertSame(50, $report['totals']['par2']['advertised_bytes']);
        self::assertSame(12.5, $report['par2_to_rar_advertised_percent']);
        self::assertSame(['Movies'], $report['nzb_meta_values']['category']);
    }

    #[Test]
    public function it_preserves_oversized_manifest_numbers_and_withholds_overflowed_totals(): void
    {
        $xml = <<<'XML'
        <nzb>
          <file subject="archive.rar" poster="poster@example.net" date="9223372036854775808">
            <segments>
              <segment bytes="9223372036854775807" number="1">one@example.net</segment>
              <segment bytes="9223372036854775807" number="9223372036854775808">two@example.net</segment>
            </segments>
          </file>
        </nzb>
        XML;

        $report = (new NzbFingerprintAnalyzer(new YencService))->analyzeManifest($xml);

        self::assertSame(1, $report['files'][0]['invalid_segment_metadata_count']);
        $oversizedSegment = collect($report['files'][0]['segments'])->firstWhere('messageid', 'two@example.net');
        self::assertNotNull($oversizedSegment);
        self::assertSame('9223372036854775808', $oversizedSegment['source_number']);
        self::assertNull($report['files'][0]['date']);
        self::assertSame('9223372036854775808', $report['files'][0]['source_date']);
        self::assertTrue($report['files'][0]['advertised_bytes_overflow']);
        self::assertNull($report['files'][0]['advertised_bytes']);
        self::assertTrue($report['totals']['rar']['advertised_bytes_overflow']);
        self::assertNull($report['totals']['rar']['advertised_bytes']);
        self::assertNull($report['par2_to_rar_advertised_percent']);
    }

    #[Test]
    public function it_samples_first_middle_and_last_file_segments_and_keeps_all_par2_segments(): void
    {
        $files = [
            ['index' => 0, 'kind' => 'rar', 'subject' => 'one.rar', 'segments' => [
                ['number' => 1, 'messageid' => 'one-a@example.net'],
                ['number' => 2, 'messageid' => 'one-b@example.net'],
                ['number' => 3, 'messageid' => 'one-c@example.net'],
            ]],
            ['index' => 1, 'kind' => 'par2', 'subject' => 'set.par2', 'segments' => [
                ['number' => 1, 'messageid' => 'par-a@example.net'],
                ['number' => 2, 'messageid' => 'par-b@example.net'],
            ]],
        ];

        $samples = (new NzbFingerprintAnalyzer(new YencService))->selectArticleSamples($files);

        self::assertSame(['one-a@example.net', 'one-b@example.net', 'one-c@example.net', 'par-a@example.net', 'par-b@example.net'], array_column($samples, 'messageid'));
        self::assertSame(['first', 'middle', 'last', 'all_par2_segments', 'all_par2_segments'], array_column($samples, 'sample_role'));
    }

    #[Test]
    public function it_reads_par2_file_description_offsets_and_recovery_denominator(): void
    {
        $fileId = str_repeat('F', 16);
        $main = pack('V2', 4096, 0).pack('V', 1).$fileId;
        $fileDescription = $fileId.str_repeat('M', 16).str_repeat('M', 16).pack('V2', 5000, 0).'payload.rar'."\0";
        $ifsc = $fileId.str_repeat('C', 40);
        $recovery = pack('V', 0).str_repeat('R', 4096);
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0FileDesc", $fileDescription)
            .$this->par2Packet("PAR 2.0\0IFSC", $ifsc)
            .$this->par2Packet("PAR 2.0\0RecvSlic", $recovery);

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertSame(5000, $metadata['source_files'][0]['file_length']);
        self::assertSame('payload.rar', $metadata['source_files'][0]['filename']);
        self::assertSame(2, $metadata['source_block_count']);
        self::assertSame(1, $metadata['unique_recovery_slice_count']);
        self::assertSame(50.0, $metadata['recovery_to_source_percent']);
        $ifscPacket = array_values(array_filter(
            $metadata['packet_summaries'],
            static fn (array $packet): bool => $packet['packet_type'] === 'PAR 2.0\\0IFSC',
        ))[0];
        self::assertSame(64, $ifscPacket['physical_regions'][0]['byte_length']);
        self::assertSame(16, $ifscPacket['physical_regions'][2]['byte_length']);
        self::assertSame(40, $ifscPacket['physical_regions'][3]['byte_length']);
        self::assertSame(2, $ifscPacket['physical_regions'][3]['record_count']);
        $recoveryPacket = array_values(array_filter(
            $metadata['packet_summaries'],
            static fn (array $packet): bool => $packet['packet_type'] === 'PAR 2.0\\0RecvSlic',
        ))[0];
        self::assertSame('recovery_exponent', $recoveryPacket['physical_regions'][2]['kind']);
        self::assertSame('recovery_data', $recoveryPacket['physical_regions'][3]['kind']);
    }

    #[Test]
    public function it_withholds_fallback_par2_percentage_for_inconsistent_small_file_hashes(): void
    {
        $filename = 'bad.rar';
        $length = pack('V2', 5000, 0);
        $first16k = str_repeat('N', 16);
        $fileId = md5($first16k.$length.$filename, true);
        $main = pack('V2', 4096, 0).pack('V', 1).$fileId;
        $fileDescription = $fileId.str_repeat('M', 16).$first16k.$length.$filename."\0";
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0FileDesc", $fileDescription)
            .$this->par2Packet("PAR 2.0\0RecvSlic", pack('V', 0).str_repeat('R', 4096));

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertFalse($metadata['source_files'][0]['short_file_hashes_consistent']);
        self::assertFalse($metadata['source_descriptions_valid']);
        self::assertFalse($metadata['recovery_sets'][0]['source_descriptions_valid']);
        self::assertNull($metadata['recovery_to_source_percent']);
    }

    #[Test]
    public function it_reports_fallback_par2_source_byte_overflow_with_a_representable_denominator(): void
    {
        $firstId = str_repeat('F', 16);
        $secondId = str_repeat('N', 16);
        $main = pack('V2', 0xFFFF_FFFC, 0x7FFF_FFFF).pack('V', 2).$firstId.$secondId;
        $length = pack('V2', 0xFFFF_FFFF, 0x7FFF_FFFF);
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0FileDesc", $firstId.str_repeat('M', 32).$length."first.rar\0\0\0")
            .$this->par2Packet("PAR 2.0\0FileDesc", $secondId.str_repeat('N', 32).$length."second.rar\0\0");
        config(['yenc.native_library' => '']);

        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertSame(PHP_INT_MAX - 3, $metadata['source_block_size']);
        self::assertSame(4, $metadata['source_block_count']);
        self::assertTrue($metadata['source_block_count_within_current_v2_limit']);
        self::assertTrue($metadata['source_file_bytes_overflow']);
        self::assertNull($metadata['source_file_bytes']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertCount(2, $metadata['source_files']);
        self::assertSame([PHP_INT_MAX, PHP_INT_MAX], array_column($metadata['source_files'], 'file_length'));
        self::assertSame('unavailable-source-file-byte-total-overflow', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    public function it_preserves_fallback_par2_source_conflict_precedence_over_byte_total_overflow(): void
    {
        $firstId = str_repeat('F', 16);
        $secondId = str_repeat('N', 16);
        $main = pack('V2', 0xFFFF_FFFC, 0x7FFF_FFFF).pack('V', 2).$firstId.$secondId;
        $conflictingMain = pack('V2', 0xFFFF_FFF8, 0x7FFF_FFFF).pack('V', 2).$firstId.$secondId;
        $length = pack('V2', 0xFFFF_FFFF, 0x7FFF_FFFF);
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0FileDesc", $firstId.str_repeat('M', 32).$length."first.rar\0\0\0")
            .$this->par2Packet("PAR 2.0\0FileDesc", $secondId.str_repeat('N', 32).$length."second.rar\0\0")
            .$this->par2Packet("PAR 2.0\0Main", $conflictingMain);
        config(['yenc.native_library' => '']);

        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertTrue($metadata['main_definition_conflict']);
        self::assertTrue($metadata['source_file_bytes_overflow']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame([PHP_INT_MAX, PHP_INT_MAX], array_column($metadata['source_files'], 'file_length'));
        self::assertSame('unavailable-conflicting-source-definition-packets', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    public function it_withholds_the_fallback_par2_success_basis_for_an_invalid_packet(): void
    {
        $invalid = $this->par2Packet("PAR 2.0\0Creator", 'bad!');
        $invalid[16] = chr(ord($invalid[16]) ^ 1);
        config(['yenc.native_library' => '']);

        $metadata = (new Par2MetadataParser)->parse($this->par2SourceBlockLimitFixture(32768).$invalid);

        self::assertSame(32768, $metadata['source_block_count']);
        self::assertTrue($metadata['source_inventory_complete']);
        self::assertSame(1, $metadata['invalid_packet_count']);
        self::assertFalse($metadata['packet_summaries'][3]['checksum_valid']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertStringStartsWith('unavailable-', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    public function it_withholds_the_fallback_par2_success_basis_when_the_packet_budget_is_exhausted(): void
    {
        $binary = $this->par2SourceBlockLimitFixture(32768)
            .str_repeat($this->par2Packet("PAR 2.0\0Creator", 'unit'), 19998);
        config(['yenc.native_library' => '']);

        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame(32768, $metadata['source_block_count']);
        self::assertTrue($metadata['source_inventory_complete']);
        self::assertSame(0, $metadata['invalid_packet_count']);
        self::assertTrue($metadata['packet_limit_reached']);
        self::assertGreaterThan(0, $metadata['trailing_unparsed_bytes']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertStringStartsWith('unavailable-', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    public function it_withholds_fallback_par2_percentage_above_the_current_v2_source_block_limit(): void
    {
        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($this->par2SourceBlockLimitFixture(32769));

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertSame(32769, $metadata['source_block_count']);
        self::assertTrue($metadata['source_inventory_complete']);
        self::assertSame(0, $metadata['invalid_packet_count']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-source-block-count-exceeds-current-v2-limit', $metadata['recovery_percentage_basis']);
        foreach ([$metadata, $metadata['recovery_sets'][0]] as $scope) {
            self::assertArrayHasKey('source_block_limit', $scope);
            self::assertArrayHasKey('source_block_count_within_current_v2_limit', $scope);
            self::assertSame(32768, $scope['source_block_limit']);
            self::assertFalse($scope['source_block_count_within_current_v2_limit']);
            self::assertSame(32769, $scope['source_block_count']);
            self::assertFalse($scope['source_block_count_overflow']);
        }
    }

    #[Test]
    public function it_allows_fallback_par2_percentage_at_the_current_v2_source_block_limit(): void
    {
        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($this->par2SourceBlockLimitFixture(32768));

        self::assertSame(32768, $metadata['source_block_count']);
        self::assertSame(0.003, $metadata['recovery_to_source_percent']);
        foreach ([$metadata, $metadata['recovery_sets'][0]] as $scope) {
            self::assertArrayHasKey('source_block_limit', $scope);
            self::assertArrayHasKey('source_block_count_within_current_v2_limit', $scope);
            self::assertSame(32768, $scope['source_block_limit']);
            self::assertTrue($scope['source_block_count_within_current_v2_limit']);
        }
    }

    #[Test]
    public function it_excludes_nonrecoverable_slices_from_the_fallback_par2_source_block_limit(): void
    {
        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($this->par2SourceBlockLimitFixture(32768, 32769));

        self::assertCount(2, $metadata['source_files']);
        self::assertSame(32768, $metadata['source_block_count']);
        self::assertSame(0.003, $metadata['recovery_to_source_percent']);
        self::assertArrayHasKey('source_block_count_within_current_v2_limit', $metadata);
        self::assertArrayHasKey('source_block_count_within_current_v2_limit', $metadata['recovery_sets'][0]);
        self::assertTrue($metadata['source_block_count_within_current_v2_limit']);
        self::assertTrue($metadata['recovery_sets'][0]['source_block_count_within_current_v2_limit']);
    }

    #[Test]
    public function it_keeps_unknown_fallback_par2_source_block_limit_validity_unavailable(): void
    {
        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($this->par2SourceBlockLimitFixture(32769, includeRecoverableDescription: false));

        self::assertFalse($metadata['source_inventory_complete']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-source-block-denominator', $metadata['recovery_percentage_basis']);
        foreach ([$metadata, $metadata['recovery_sets'][0]] as $scope) {
            self::assertArrayHasKey('source_block_limit', $scope);
            self::assertArrayHasKey('source_block_count_within_current_v2_limit', $scope);
            self::assertSame(32768, $scope['source_block_limit']);
            self::assertNull($scope['source_block_count']);
            self::assertNull($scope['source_block_count_within_current_v2_limit']);
        }
    }

    /**
     * @param  array<string, list<string>>  $main
     * @param  array<string, array{file_length: int}>  $sources
     */
    #[Test]
    #[DataProvider('assembledPar2SourceBlockLayouts')]
    public function it_gates_the_collector_par2_source_block_layout_after_cross_volume_merge(array $main, array $sources, ?int $expectedCount, ?bool $withinLimit, bool $complete): void
    {
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $layout = (new \ReflectionMethod($collector, 'computePar2SourceBlockLayout'))->invoke($collector, $main, 4, $sources, []);

        self::assertSame($expectedCount, $layout['source_block_count']);
        self::assertSame($withinLimit, $layout['source_block_count_within_current_v2_limit']);
        self::assertSame($complete, $layout['complete']);
        self::assertSame(32768, $layout['source_block_limit']);
        self::assertFalse($layout['source_block_count_overflow']);
        if ($withinLimit === false) {
            self::assertSame('unavailable-source-block-count-exceeds-current-v2-limit', $layout['recovery_percentage_basis']);
        }
    }

    /** @return iterable<string, array{array<string, list<string>>, array<string, array{file_length: int}>, int|null, bool|null, bool}> */
    public static function assembledPar2SourceBlockLayouts(): iterable
    {
        yield 'boundary source count' => [
            ['recoverable_file_ids' => ['first', 'second']],
            ['first' => ['file_length' => 65536], 'second' => ['file_length' => 65536]],
            32768, true, true,
        ];
        yield 'limit exceeded only after merging two descriptions' => [
            ['recoverable_file_ids' => ['first', 'second']],
            ['first' => ['file_length' => 65536], 'second' => ['file_length' => 65540]],
            32769, false, false,
        ];
        yield 'nonrecoverable description does not consume source constants' => [
            ['recoverable_file_ids' => ['first'], 'all_file_ids' => ['first', 'nonrecoverable']],
            ['first' => ['file_length' => 131072], 'nonrecoverable' => ['file_length' => 131076]],
            32768, true, true,
        ];
        yield 'missing description leaves the denominator unknown' => [
            ['recoverable_file_ids' => ['first', 'missing']],
            ['first' => ['file_length' => 65536]],
            null, null, false,
        ];
    }

    #[Test]
    public function it_preserves_collector_source_block_overflow_in_the_public_percentage_basis(): void
    {
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $layout = (new \ReflectionMethod($collector, 'computePar2SourceBlockLayout'))->invoke(
            $collector,
            ['recoverable_file_ids' => ['first', 'second', 'third', 'fourth', 'fifth']],
            4,
            array_fill_keys(['first', 'second', 'third', 'fourth', 'fifth'], ['file_length' => PHP_INT_MAX]),
            [],
        );

        self::assertTrue($layout['source_block_count_overflow']);
        self::assertNull($layout['source_block_count']);
        self::assertNull($layout['source_block_count_within_current_v2_limit']);
        self::assertFalse($layout['complete']);
        $basis = (new \ReflectionMethod($collector, 'par2RecoveryPercentageBasis'))->invoke(
            $collector,
            $layout['recovery_percentage_basis'],
            $layout['source_block_count_within_current_v2_limit'],
            false,
            null,
        );
        self::assertSame('unavailable-source-block-count-overflow', $basis);
    }

    #[Test]
    public function it_preserves_missing_collector_source_descriptions_in_the_public_percentage_basis(): void
    {
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $layout = (new \ReflectionMethod($collector, 'computePar2SourceBlockLayout'))->invoke(
            $collector,
            ['recoverable_file_ids' => ['missing']],
            4,
            [],
            [],
        );

        self::assertNull($layout['source_block_count']);
        self::assertNull($layout['source_block_count_within_current_v2_limit']);
        self::assertFalse($layout['complete']);
        $basis = (new \ReflectionMethod($collector, 'par2RecoveryPercentageBasis'))->invoke(
            $collector,
            $layout['recovery_percentage_basis'],
            $layout['source_block_count_within_current_v2_limit'],
            false,
            null,
        );
        self::assertSame('unavailable-source-block-denominator', $basis);
    }

    #[Test]
    public function it_uses_the_collector_success_basis_only_for_an_eligible_percentage(): void
    {
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($collector, 'par2RecoveryPercentageBasis');
        $successBasis = 'unique-valid-recovery-exponents-over-recoverable-source-blocks';

        self::assertSame('unavailable-incomplete-or-unsupported-par2-layout', $method->invoke($collector, $successBasis, true, false, null));
        self::assertSame($successBasis, $method->invoke($collector, $successBasis, true, false, 0.0));
        self::assertSame('unavailable-packed-recovery-layout', $method->invoke($collector, 'unavailable-source-block-count-overflow', null, true, null));
    }

    private function par2SourceBlockLimitFixture(int $recoverableSlices, ?int $nonrecoverableSlices = null, bool $includeRecoverableDescription = true): string
    {
        $fileId = str_repeat('F', 16);
        $nonrecoverableId = str_repeat('N', 16);
        $main = pack('V2', 4, 0).pack('V', 1).$fileId;
        if ($nonrecoverableSlices !== null) {
            $main .= $nonrecoverableId;
        }
        $binary = $this->par2Packet("PAR 2.0\0Main", $main);
        if ($includeRecoverableDescription) {
            $description = $fileId.str_repeat('M', 32).pack('V2', $recoverableSlices * 4, 0)."payload.rar\0";
            $binary .= $this->par2Packet("PAR 2.0\0FileDesc", $description);
        }
        if ($nonrecoverableSlices !== null) {
            $description = $nonrecoverableId.str_repeat('N', 32).pack('V2', $nonrecoverableSlices * 4, 0)."nfo.txt\0";
            $binary .= $this->par2Packet("PAR 2.0\0FileDesc", $description);
        }

        return $binary.$this->par2Packet("PAR 2.0\0RecvSlic", pack('V', 0).'RRRR');
    }

    #[Test]
    public function it_bounds_fallback_main_inventory_before_expanding_file_ids(): void
    {
        $main = pack('V2', 4096, 0).pack('V', 0);
        for ($index = 0; $index < 32769; $index++) {
            $main .= pack('V4', $index, 0, 0, 0);
        }
        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($this->par2Packet("PAR 2.0\0Main", $main));

        self::assertSame(32768, $metadata['main_file_id_limit']);
        self::assertTrue($metadata['main_file_id_limit_reached']);
        self::assertSame(32769, $metadata['main_file_id_limit_observations'][0]['observed_file_id_count']);
        self::assertNull($metadata['main_packet']);
        self::assertNull($metadata['source_block_count']);
        self::assertNull($metadata['recovery_to_source_percent']);
    }

    #[Test]
    public function it_validates_par2_input_slice_checksums_after_cross_volume_file_description_merge(): void
    {
        $fileId = str_repeat('f', 32);
        $main = ['all_file_ids' => [$fileId]];
        $sources = [$fileId => ['file_id' => $fileId, 'file_length' => 5000]];
        $oneSlice = [[
            'file_id' => $fileId,
            'slice_count' => 1,
            'checksum_records_sha256' => str_repeat('a', 64),
            'duplicate_conflict' => false,
        ]];
        $twoSlices = [[
            'file_id' => $fileId,
            'slice_count' => 2,
            'checksum_records_sha256' => str_repeat('b', 64),
            'duplicate_conflict' => false,
        ]];

        $short = Par2MetadataParser::validateCrossVolumeIfscRelationships($main, $sources, 4096, $oneSlice);
        $matchingCopies = Par2MetadataParser::validateCrossVolumeIfscRelationships(
            $main,
            $sources,
            4096,
            [$twoSlices[0], $twoSlices[0]],
        );
        $conflictingCopies = Par2MetadataParser::validateCrossVolumeIfscRelationships(
            $main,
            $sources,
            4096,
            [$twoSlices[0], array_merge($twoSlices[0], ['checksum_records_sha256' => str_repeat('c', 64)])],
        );

        self::assertFalse($short['valid']);
        self::assertSame([$fileId], $short['slice_count_mismatch_file_ids']);
        self::assertTrue($matchingCopies['valid']);
        self::assertSame([$fileId], $conflictingCopies['conflicting_checksum_file_ids']);
        $pending = Par2MetadataParser::validateCrossVolumeIfscRelationships(
            $main,
            [],
            4096,
            [$twoSlices[0]],
        );
        self::assertTrue($pending['valid']);
        self::assertSame('pending_source_metadata', $pending['status']);
        self::assertSame([$fileId], $pending['unresolved_file_ids']);
    }

    #[Test]
    public function it_withholds_fallback_par2_percentage_for_an_ifsc_count_mismatch(): void
    {
        $fileId = str_repeat('F', 16);
        $main = pack('V2', 4096, 0).pack('V', 1).$fileId;
        $fileDescription = $fileId.str_repeat('M', 16).str_repeat('M', 16).pack('V2', 5000, 0).'payload.rar'."\0";
        $ifsc = $fileId.str_repeat('C', 20);
        $recovery = pack('V', 0).str_repeat('R', 4096);
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0FileDesc", $fileDescription)
            .$this->par2Packet("PAR 2.0\0IFSC", $ifsc)
            .$this->par2Packet("PAR 2.0\0RecvSlic", $recovery);

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertTrue($metadata['source_inventory_complete']);
        self::assertFalse($metadata['ifsc_relationships_valid']);
        self::assertSame('unavailable-invalid-input-file-slice-checksums', $metadata['recovery_percentage_basis']);
        self::assertNull($metadata['recovery_to_source_percent']);
    }

    #[Test]
    public function it_reports_conflicting_par2_source_definitions_per_recovery_set(): void
    {
        $fileId = str_repeat('F', 16);
        $main = pack('V2', 4096, 0).pack('V', 1).$fileId;
        $fileDescription = $fileId.str_repeat('M', 16).str_repeat('M', 16).pack('V2', 5000, 0).'one.rar'."\0";
        $conflictingMain = pack('V2', 8192, 0).pack('V', 1).$fileId;
        $conflictingFileDescription = $fileId.str_repeat('M', 16).str_repeat('M', 16).pack('V2', 5001, 0).'one.rar'."\0";
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0FileDesc", $fileDescription)
            .$this->par2Packet("PAR 2.0\0Main", $conflictingMain)
            .$this->par2Packet("PAR 2.0\0FileDesc", $conflictingFileDescription);

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertFalse($metadata['source_inventory_complete']);
        self::assertTrue($metadata['main_definition_conflict']);
        self::assertSame([bin2hex($fileId)], $metadata['conflicting_file_description_ids']);
        self::assertSame('unavailable-conflicting-source-definition-packets', $metadata['recovery_percentage_basis']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertCount(2, $metadata['source_definition_conflicts']);
        self::assertSame($metadata['packet_summaries'][0]['offset'], $metadata['source_definition_conflicts'][0]['first_packet_offset']);
        self::assertSame($metadata['packet_summaries'][2]['offset'], $metadata['source_definition_conflicts'][0]['conflicting_packet_offset']);
        self::assertSame(2, count($metadata['recovery_sets'][0]['source_definition_conflicts']));
    }

    #[Test]
    public function it_links_only_trusted_yenc_prefixes_to_unique_or_ambiguous_par2_sources(): void
    {
        $hash = '1d64dce239c4437b7736041db089e1b9';
        $files = [[
            'index' => 0,
            'kind' => 'rar',
            'subject' => 'renamed.part01.rar',
            'segment_number_sequence_valid' => true,
            'invalid_segment_record_count' => 0,
            'invalid_segment_metadata_count' => 0,
            'duplicate_message_id_count' => 0,
            'duplicate_segment_number_count' => 0,
        ]];
        $observations = [[
            'file_index' => 0,
            'segment_number' => 1,
            'sample_role' => 'first',
            'server_role' => 'primary',
            'server' => 'news.example.test',
            'provider_label' => 'Example provider',
            'server_port' => 563,
            'server_tls' => true,
            'queried_at_utc' => '2026-10-13T12:00:00Z',
            'message_id' => '<first@example.test>',
            'metadata' => [
                'yenc' => [
                    'file_size' => 20_000,
                    'multipart' => true,
                    'begin' => 1,
                    'end' => 20_000,
                    'part' => 1,
                    'total_parts' => 1,
                    'decoded_segment_size' => 20_000,
                    'header_numeric_fields_valid' => true,
                    'integrity_checks_ambiguous' => false,
                    'ypart_range_valid' => true,
                    'decoded_size_matches_ypart_range' => true,
                    'decoded_size_matches_yenc' => true,
                    'decoded_size_matches_yend_size' => true,
                    'pcrc32' => 'aabbccdd',
                    'declared_crc32_is_hex' => true,
                    'declared_crc32_matches' => true,
                    'trailer_fields' => ['part' => '1'],
                ],
                'yenc_nzb_part_number_matches' => true,
                'yenc_nzb_total_parts_matches' => true,
                'article_identity' => [
                    'parser_engine' => 'nntmux-rust-article-identity',
                    'schema_version' => 3,
                    'relations' => ['body_response_matches_request' => true],
                ],
                'decoded_format_metadata' => [
                    'sample_prefix_identity' => [
                        'bytes_hashed' => 16_384,
                        'md5' => $hash,
                        'parser_input_offset_zero' => true,
                        'file_offset_known' => false,
                    ],
                ],
            ],
        ]];
        $sources = [[
            'recovery_set_id' => str_repeat('a', 32),
            'file_id' => str_repeat('b', 32),
            'filename' => 'original.rar',
            'file_length' => 20_000,
            'md5_first_16k' => $hash,
        ]];

        $unique = Par2MetadataParser::relateNzbFilePrefixesToSources($files, $observations, $sources);
        self::assertSame('candidate_match', $unique['files'][0]['status']);
        self::assertSame('<first@example.test>', $unique['files'][0]['sample']['message_id']);
        self::assertSame('Example provider', $unique['files'][0]['sample']['provider_label']);
        self::assertSame(563, $unique['files'][0]['sample']['server_port']);
        self::assertTrue($unique['files'][0]['sample']['server_tls']);
        self::assertSame('2026-10-13T12:00:00Z', $unique['files'][0]['sample']['queried_at_utc']);
        self::assertSame('original.rar', $unique['files'][0]['matches'][0]['par2_filename']);
        self::assertFalse($unique['files'][0]['matches'][0]['whole_file_identity_established']);

        $mainDefinitionConflict = Par2MetadataParser::relateNzbFilePrefixesToSources(
            $files,
            $observations,
            $sources,
            [str_repeat('a', 32).':*'],
        );
        self::assertSame('conflicting_source_definition', $mainDefinitionConflict['files'][0]['status']);
        self::assertSame([], $mainDefinitionConflict['files'][0]['matches']);

        $secondSource = $sources[0];
        $secondSource['recovery_set_id'] = str_repeat('c', 32);
        $secondSource['file_id'] = str_repeat('d', 32);
        $ambiguous = Par2MetadataParser::relateNzbFilePrefixesToSources(
            $files,
            $observations,
            [$sources[0], $secondSource],
        );
        self::assertSame('ambiguous', $ambiguous['files'][0]['status']);
        self::assertCount(2, $ambiguous['files'][0]['matches']);
    }

    #[Test]
    public function it_does_not_count_par2_carriers_as_unmatched_source_files(): void
    {
        $hash = '1d64dce239c4437b7736041db089e1b9';
        $files = [
            [
                'index' => 0,
                'kind' => 'rar',
                'subject' => 'renamed.part01.rar',
                'segment_number_sequence_valid' => true,
                'invalid_segment_record_count' => 0,
                'invalid_segment_metadata_count' => 0,
                'duplicate_message_id_count' => 0,
                'duplicate_segment_number_count' => 0,
            ],
            ['index' => 1, 'kind' => 'par2', 'subject' => 'set.vol00+01.par2'],
        ];
        $observation = [
            'file_index' => 0,
            'segment_number' => 1,
            'sample_role' => 'first',
            'server_role' => 'primary',
            'message_id' => '<first@example.test>',
            'metadata' => [
                'yenc' => [
                    'file_size' => 20_000,
                    'multipart' => true,
                    'begin' => 1,
                    'end' => 20_000,
                    'part' => 1,
                    'total_parts' => 1,
                    'decoded_segment_size' => 20_000,
                    'header_numeric_fields_valid' => true,
                    'integrity_checks_ambiguous' => false,
                    'ypart_range_valid' => true,
                    'decoded_size_matches_ypart_range' => true,
                    'decoded_size_matches_yenc' => true,
                    'decoded_size_matches_yend_size' => true,
                    'pcrc32' => 'aabbccdd',
                    'declared_crc32_is_hex' => true,
                    'declared_crc32_matches' => true,
                    'trailer_fields' => ['part' => '1'],
                ],
                'yenc_nzb_part_number_matches' => true,
                'yenc_nzb_total_parts_matches' => true,
                'article_identity' => [
                    'parser_engine' => 'nntmux-rust-article-identity',
                    'schema_version' => 3,
                    'relations' => ['body_response_matches_request' => true],
                ],
                'decoded_format_metadata' => [
                    'sample_prefix_identity' => [
                        'bytes_hashed' => 16_384,
                        'md5' => $hash,
                        'parser_input_offset_zero' => true,
                        'file_offset_known' => false,
                    ],
                ],
            ],
        ];
        $result = Par2MetadataParser::relateNzbFilePrefixesToSources(
            $files,
            [$observation, ['file_index' => 1, 'sample_role' => 'all_par2_segments']],
            [[
                'recovery_set_id' => str_repeat('a', 32),
                'file_id' => str_repeat('b', 32),
                'filename' => 'original.rar',
                'file_length' => 20_000,
                'md5_first_16k' => $hash,
            ]],
        );

        self::assertSame('complete', $result['status']);
        self::assertSame(2, $result['nzb_file_count']);
        self::assertSame(1, $result['source_file_count']);
        self::assertSame(1, $result['par2_carrier_file_count']);
        self::assertSame('candidate_match', $result['files'][0]['status']);
    }

    #[Test]
    public function it_rejects_prefix_matches_when_yenc_trailer_numbers_disagree(): void
    {
        $hash = '1d64dce239c4437b7736041db089e1b9';
        $file = [
            'index' => 0,
            'kind' => 'rar',
            'subject' => 'one.rar',
            'segment_number_sequence_valid' => true,
            'invalid_segment_record_count' => 0,
            'invalid_segment_metadata_count' => 0,
            'duplicate_message_id_count' => 0,
            'duplicate_segment_number_count' => 0,
        ];
        $observation = [
            'file_index' => 0,
            'segment_number' => 1,
            'sample_role' => 'first',
            'server_role' => 'primary',
            'message_id' => '<first@example.test>',
            'metadata' => [
                'yenc' => [
                    'file_size' => 20_000,
                    'multipart' => true,
                    'begin' => 1,
                    'end' => 20_000,
                    'part' => 1,
                    'total_parts' => 1,
                    'decoded_segment_size' => 20_000,
                    'header_numeric_fields_valid' => true,
                    'integrity_checks_ambiguous' => false,
                    'ypart_range_valid' => true,
                    'decoded_size_matches_ypart_range' => true,
                    'decoded_size_matches_yenc' => true,
                    'decoded_size_matches_yend_size' => true,
                    'pcrc32' => 'aabbccdd',
                    'declared_crc32_is_hex' => true,
                    'declared_crc32_matches' => true,
                    'trailer_fields' => ['part' => '2'],
                ],
                'yenc_nzb_part_number_matches' => true,
                'yenc_nzb_total_parts_matches' => true,
                'article_identity' => [
                    'parser_engine' => 'nntmux-rust-article-identity',
                    'schema_version' => 3,
                    'relations' => ['body_response_matches_request' => true],
                ],
                'decoded_format_metadata' => [
                    'sample_prefix_identity' => [
                        'bytes_hashed' => 16_384,
                        'md5' => $hash,
                        'parser_input_offset_zero' => true,
                        'file_offset_known' => false,
                    ],
                ],
            ],
        ];
        $result = Par2MetadataParser::relateNzbFilePrefixesToSources(
            [$file],
            [$observation],
            [[
                'recovery_set_id' => str_repeat('a', 32),
                'file_id' => str_repeat('b', 32),
                'filename' => 'one.rar',
                'file_length' => 20_000,
                'md5_first_16k' => $hash,
            ]],
        );

        self::assertSame('insufficient_evidence', $result['files'][0]['status']);
        self::assertSame('yenc_integrity_failed', $result['files'][0]['evidence_status']);
        self::assertSame([], $result['files'][0]['matches']);

        $validTrailerObservation = $observation;
        $validTrailerObservation['metadata']['yenc']['begin'] = 1;
        $validTrailerObservation['metadata']['yenc']['end'] = 20_000;
        $validTrailerObservation['metadata']['yenc']['declared_crc32_matches'] = true;
        $validTrailerObservation['metadata']['yenc']['trailer_fields'] = ['part' => '1'];
        $validTrailerObservation['metadata']['yenc_framing_metadata'] = [
            'parser_engine' => 'nntmux-rust-ffi',
            'frame_count' => 1,
            'frames_truncated' => false,
            'frames' => [['part_number_matches' => false, 'total_parts_match' => true]],
        ];
        $nativeMismatch = Par2MetadataParser::relateNzbFilePrefixesToSources(
            [$file],
            [$validTrailerObservation],
            [[
                'recovery_set_id' => str_repeat('a', 32),
                'file_id' => str_repeat('b', 32),
                'filename' => 'one.rar',
                'file_length' => 100,
                'md5_first_16k' => str_repeat('1', 32),
            ]],
        );
        self::assertSame('yenc_integrity_failed', $nativeMismatch['files'][0]['evidence_status']);
        self::assertSame([], $nativeMismatch['files'][0]['matches']);
    }

    #[Test]
    public function it_validates_the_selected_single_part_yenc_crc_field(): void
    {
        self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid([
            'crc32' => null,
            'pcrc32' => 'aabbccdd',
            'segment_crc32_basis' => 'pcrc32',
            'declared_crc32_is_hex' => true,
            'declared_crc32_matches' => false,
        ]));
        self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid([
            'crc32' => null,
            'pcrc32' => 'not-hex',
            'segment_crc32_basis' => 'pcrc32',
            'declared_crc32_is_hex' => false,
            'declared_crc32_matches' => null,
        ]));
        self::assertTrue(Par2MetadataParser::declaredYencCrc32Valid([
            'crc32' => 'aabbccdd',
            'pcrc32' => null,
            'segment_crc32_basis' => 'crc32',
            'declared_crc32_is_hex' => true,
            'declared_crc32_matches' => true,
        ]));
        self::assertTrue(Par2MetadataParser::declaredYencCrc32Valid([
            'crc32' => null,
            'pcrc32' => null,
            'segment_crc32_basis' => 'missing-crc32',
            'declared_crc32_is_hex' => null,
            'declared_crc32_matches' => null,
        ]));
    }

    #[Test]
    public function it_never_restores_crc_authority_from_unavailable_multipart_metadata(): void
    {
        foreach ([null, 'aabbccdd'] as $declaredCrc) {
            self::assertFalse(Par2MetadataParser::declaredYencCrc32Valid([
                'crc32' => $declaredCrc,
                'pcrc32' => $declaredCrc,
                'segment_crc32_basis' => 'unavailable-multipart-structure',
                'declared_crc32_is_hex' => true,
                'declared_crc32_matches' => true,
            ]));
        }
    }

    #[Test]
    public function it_reports_partial_multipart_structure_for_conflicting_or_invalid_fields(): void
    {
        config(['yenc.native_library' => '']);
        foreach ([
            [' total=2', 'part=1 part=2', 'ambiguous'],
            [' total=2', 'part=1 total=1 total=2', 'ambiguous'],
            ['', 'part=1 total=invalid', 'invalid'],
        ] as [$headerTotal, $trailerFields, $status]) {
            $body = "=ybegin part=1{$headerTotal} line=128 size=3 name=x.rar\r\n=ypart begin=1 end=3\r\nklm\r\n=yend size=3 {$trailerFields} pcrc32=a3830348\r\n";
            $yenc = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $body)['yenc'];
            self::assertSame($status, $yenc['multipart_structure_status']);
            self::assertNull($yenc['declared_crc32_matches']);
            self::assertNull($yenc['par2_first_16k_md5_candidate']);
            self::assertSame('A3830348', $yenc['observed_decoded_crc32']);
        }
    }

    #[Test]
    public function it_never_restores_file_range_authority_from_an_unavailable_crc_basis(): void
    {
        config(['yenc.native_library' => '']);
        $body = "=ybegin line=128 size=3 name=x.7z\r\nklm\r\n=yend size=3 crc32=a3830348\r\n";
        $metadata = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $body);
        $metadata['article_identity'] = [
            'parser_engine' => 'nntmux-rust-article-identity', 'schema_version' => 3,
            'relations' => ['body_response_matches_request' => true],
        ];
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($collector, 'decodedFileRange');
        self::assertSame('valid', $method->invoke($collector, $metadata, 'ABC')['mapping_status']);
        $metadata['yenc']['segment_crc32_basis'] = 'unavailable-multipart-structure';
        // The retained CRC syntax/match observations are deliberately still true.
        self::assertTrue($metadata['yenc']['declared_crc32_matches']);
        $range = $method->invoke($collector, $metadata, 'ABC');
        self::assertSame('invalid', $range['mapping_status']);
        self::assertArrayNotHasKey('start_offset', $range);
        self::assertArrayNotHasKey('end_offset_exclusive', $range);
    }

    #[Test]
    public function it_requires_the_requested_body_message_id_before_trusting_par2_bytes(): void
    {
        $metadata = [
            'article_identity' => [
                'parser_engine' => 'nntmux-rust-article-identity',
                'schema_version' => 3,
                'relations' => ['body_response_matches_request' => true],
            ],
        ];

        self::assertTrue(Par2MetadataParser::bodyResponseIdentityVerified($metadata));

        $metadata['article_identity']['relations']['body_response_matches_request'] = false;
        self::assertFalse(Par2MetadataParser::bodyResponseIdentityVerified($metadata));

        unset($metadata['article_identity']['relations']['body_response_matches_request']);
        self::assertFalse(Par2MetadataParser::bodyResponseIdentityVerified($metadata));
    }

    #[Test]
    public function it_requires_body_identity_before_admitting_source_or_seven_zip_ranges(): void
    {
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $rangeMethod = new \ReflectionMethod($collector, 'decodedFileRange');
        $body = "=ybegin line=128 size=3 name=one.7z\r\nklm\r\n=yend size=3 crc32=a3830348\r\n";
        $metadata = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle([], $body);
        $metadata['article_identity'] = [
            'parser_engine' => 'nntmux-rust-article-identity',
            'schema_version' => 3,
            'relations' => ['body_response_matches_request' => true],
        ];

        self::assertSame('valid', $rangeMethod->invoke($collector, $metadata, 'ABC')['mapping_status']);

        foreach ([false, null] as $identityMatches) {
            $metadata['article_identity']['relations']['body_response_matches_request'] = $identityMatches;
            $range = $rangeMethod->invoke($collector, $metadata, 'ABC');

            self::assertNotSame('valid', $range['mapping_status']);
            self::assertArrayNotHasKey('start_offset', $range);
            self::assertArrayNotHasKey('end_offset_exclusive', $range);
        }

        unset($metadata['article_identity']);
        self::assertNotSame('valid', $rangeMethod->invoke($collector, $metadata, 'ABC')['mapping_status']);
    }

    #[Test]
    public function it_requires_requested_identity_for_lines_body_relationships(): void
    {
        $collector = (new \ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($collector, 'relateLinesBodyToRequestedArticle');
        $relationship = ['status' => 'match', 'matches' => true, 'absolute_difference' => 0];
        $identity = [
            'parser_engine' => 'nntmux-rust-article-identity',
            'schema_version' => 3,
            'relations' => [
                'head_response_matches_body_response' => true,
                'body_response_matches_request' => false,
            ],
        ];

        $mismatched = $method->invoke($collector, $relationship, $identity);
        self::assertSame('article_identity_mismatch', $mismatched['status']);
        self::assertNull($mismatched['matches']);
        self::assertNull($mismatched['absolute_difference']);
        self::assertSame('body_message_id_differs_from_request', $mismatched['response_identity_status']);

        $identity['relations']['body_response_matches_request'] = true;
        self::assertTrue($method->invoke($collector, $relationship, $identity)['matches']);
        self::assertNull($method->invoke($collector, $relationship, null)['matches']);

        $identity['relations']['head_response_matches_body_response'] = false;
        self::assertNull($method->invoke($collector, $relationship, $identity)['matches']);
    }

    #[Test]
    public function it_preserves_source_conflict_keys_even_when_a_par2_volume_is_not_admissible(): void
    {
        $conflictedKeys = Par2MetadataParser::sourceDefinitionConflictKeys([
            'source_definition_conflicts' => [
                ['recovery_set_id' => str_repeat('A', 32), 'file_id' => str_repeat('B', 32)],
                ['recovery_set_id' => str_repeat('C', 32), 'file_id' => null],
            ],
        ]);

        self::assertSame([
            strtolower(str_repeat('A', 32).':'.str_repeat('B', 32)),
            strtolower(str_repeat('C', 32).':*'),
        ], $conflictedKeys);
    }

    #[Test]
    public function it_withholds_prefix_relationships_without_trusted_start_offset_and_integrity(): void
    {
        $observation = [
            'file_index' => 0,
            'segment_number' => 1,
            'sample_role' => 'first',
            'server_role' => 'primary',
            'message_id' => '<first@example.test>',
            'metadata' => [
                'yenc' => [
                    'file_size' => 100,
                    'multipart' => true,
                    'begin' => 2,
                    'end' => 101,
                    'part' => 1,
                    'total_parts' => 1,
                    'header_numeric_fields_valid' => true,
                    'integrity_checks_ambiguous' => false,
                    'ypart_range_valid' => true,
                    'decoded_size_matches_ypart_range' => true,
                    'decoded_size_matches_yenc' => true,
                    'decoded_size_matches_yend_size' => true,
                    'decoded_segment_size' => 100,
                    'pcrc32' => 'aabbccdd',
                    'declared_crc32_is_hex' => true,
                    'declared_crc32_matches' => false,
                    'trailer_fields' => ['part' => '1'],
                ],
                'yenc_nzb_part_number_matches' => true,
                'yenc_nzb_total_parts_matches' => true,
                'article_identity' => [
                    'parser_engine' => 'nntmux-rust-article-identity',
                    'schema_version' => 3,
                    'relations' => ['body_response_matches_request' => true],
                ],
                'decoded_format_metadata' => [
                    'sample_prefix_identity' => [
                        'bytes_hashed' => 100,
                        'md5' => str_repeat('1', 32),
                        'parser_input_offset_zero' => true,
                        'file_offset_known' => false,
                    ],
                ],
            ],
        ];
        $result = Par2MetadataParser::relateNzbFilePrefixesToSources(
            [[
                'index' => 0,
                'kind' => 'rar',
                'subject' => 'one.rar',
                'segment_number_sequence_valid' => true,
                'invalid_segment_record_count' => 0,
                'invalid_segment_metadata_count' => 0,
                'duplicate_message_id_count' => 0,
                'duplicate_segment_number_count' => 0,
            ]],
            [$observation],
            [[
                'recovery_set_id' => str_repeat('a', 32),
                'file_id' => str_repeat('b', 32),
                'filename' => 'one.rar',
                'file_length' => 100,
                'md5_first_16k' => str_repeat('1', 32),
            ]],
        );

        self::assertSame('insufficient_evidence', $result['files'][0]['status']);
        self::assertSame('yenc_start_offset_not_zero', $result['files'][0]['evidence_status']);
        self::assertSame([], $result['files'][0]['matches']);

        $failedIntegrityObservation = $observation;
        $failedIntegrityObservation['metadata']['yenc']['begin'] = 1;
        $failedIntegrityObservation['metadata']['yenc']['end'] = 100;
        $failedIntegrity = Par2MetadataParser::relateNzbFilePrefixesToSources(
            [[
                'index' => 0,
                'kind' => 'rar',
                'subject' => 'one.rar',
                'segment_number_sequence_valid' => true,
                'invalid_segment_record_count' => 0,
                'invalid_segment_metadata_count' => 0,
                'duplicate_message_id_count' => 0,
                'duplicate_segment_number_count' => 0,
            ]],
            [$failedIntegrityObservation],
            [[
                'recovery_set_id' => str_repeat('a', 32),
                'file_id' => str_repeat('b', 32),
                'filename' => 'one.rar',
                'file_length' => 100,
                'md5_first_16k' => str_repeat('1', 32),
            ]],
        );
        self::assertSame('insufficient_evidence', $failedIntegrity['files'][0]['status']);
        self::assertSame('yenc_integrity_failed', $failedIntegrity['files'][0]['evidence_status']);
    }

    #[Test]
    public function it_maps_par2_packet_ranges_to_the_exact_nntp_segments_that_carried_them(): void
    {
        $packets = [
            ['offset' => 8, 'packet_bytes' => 12],
        ];
        $segments = [
            ['segment_number' => 1, 'message_id' => 'one@example.net', 'offset' => 0, 'byte_length' => 10,
                'declared_file_byte_start' => 1, 'declared_file_byte_end_inclusive' => 10,
                'declared_range_matches_assembled_position' => true],
            ['segment_number' => 2, 'message_id' => 'two@example.net', 'offset' => 10, 'byte_length' => 10,
                'declared_file_byte_start' => 1, 'declared_file_byte_end_inclusive' => 10,
                'declared_range_matches_assembled_position' => false],
        ];

        $mapped = Par2MetadataParser::mapPacketArticleSpans($packets, $segments);

        self::assertSame([
            ['segment_number' => 1, 'message_id' => 'one@example.net', 'assembled_file_byte_start' => 8, 'assembled_file_byte_end_exclusive' => 10, 'segment_byte_start' => 8, 'segment_byte_end_exclusive' => 10, 'overlap_bytes' => 2,
                'declared_file_byte_start' => 1, 'declared_file_byte_end_inclusive' => 10,
                'declared_range_matches_assembled_position' => true],
            ['segment_number' => 2, 'message_id' => 'two@example.net', 'assembled_file_byte_start' => 10, 'assembled_file_byte_end_exclusive' => 20, 'segment_byte_start' => 0, 'segment_byte_end_exclusive' => 10, 'overlap_bytes' => 10,
                'declared_file_byte_start' => 1, 'declared_file_byte_end_inclusive' => 10,
                'declared_range_matches_assembled_position' => false],
        ], $mapped[0]['article_segments']);
        self::assertTrue($mapped[0]['article_span_mapping_complete']);
        self::assertSame(12, $mapped[0]['article_span_mapped_bytes']);
    }

    #[Test]
    public function it_treats_zero_length_par2_regions_as_vacuously_mapped(): void
    {
        $mapped = Par2MetadataParser::mapPacketArticleSpans(
            [['offset' => 10, 'packet_bytes' => 0], ['offset' => 21, 'packet_bytes' => 0]],
            [['segment_number' => 1, 'message_id' => 'one@example.net', 'offset' => 0, 'byte_length' => 10]],
        );

        self::assertSame([], $mapped[0]['article_segments']);
        self::assertSame(0, $mapped[0]['article_span_mapped_bytes']);
        self::assertTrue($mapped[0]['article_span_mapping_complete']);
        self::assertFalse($mapped[1]['article_span_mapping_complete']);
    }

    #[Test]
    public function it_detects_conflicting_recovery_slices_across_par2_volumes(): void
    {
        $volumes = [
            ['file_index' => 4, 'subject' => 'set.vol00+01.par2', 'recovery_packets' => [[
                'recovery_set_id' => 'set-id', 'exponent' => 7, 'slice_sha256' => str_repeat('a', 64), 'packet_offset' => 64,
                'article_segments' => [['segment_number' => 1, 'message_id' => 'first@example.net']],
            ]]],
            ['file_index' => 5, 'subject' => 'set.vol01+01.par2', 'recovery_packets' => [[
                'recovery_set_id' => 'set-id', 'exponent' => 7, 'slice_sha256' => str_repeat('b', 64), 'packet_offset' => 128,
                'article_segments' => [['segment_number' => 2, 'message_id' => 'second@example.net']],
            ]]],
        ];

        $conflicts = Par2MetadataParser::findCrossVolumeRecoveryConflicts($volumes);

        self::assertCount(1, $conflicts);
        self::assertSame('set-id', $conflicts[0]['recovery_set_id']);
        self::assertSame(7, $conflicts[0]['exponent']);
        self::assertSame('first@example.net', $conflicts[0]['first_article_segments'][0]['message_id']);
        self::assertSame('second@example.net', $conflicts[0]['conflicting_article_segments'][0]['message_id']);
        self::assertSame([], Par2MetadataParser::findCrossVolumeRecoveryConflicts([
            ['file_index' => 4, 'recovery_packets' => [
                ['recovery_set_id' => 'set-id', 'exponent' => 7, 'slice_sha256' => str_repeat('a', 64), 'packet_offset' => 64],
                ['recovery_set_id' => 'set-id', 'exponent' => 7, 'slice_sha256' => str_repeat('b', 64), 'packet_offset' => 128],
            ]],
        ]));
    }

    #[Test]
    public function it_excludes_wrong_sized_recovery_slices_from_set_level_counts(): void
    {
        $packets = [
            ['exponent' => 2, 'payload_bytes' => 4100],
            ['exponent' => 3, 'payload_bytes' => 8],
            ['exponent' => 2, 'payload_bytes' => 4100],
        ];

        self::assertSame([2], Par2MetadataParser::validatedRecoveryExponents($packets, 4096));
        self::assertSame([], Par2MetadataParser::validatedRecoveryExponents($packets, PHP_INT_MAX));
    }

    #[Test]
    public function it_withholds_par2_recovery_percentage_for_conflicting_exponent_packets(): void
    {
        $fileId = str_repeat('F', 16);
        $main = pack('V2', 4096, 0).pack('V', 1).$fileId;
        $fileDescription = $fileId.str_repeat('M', 16).str_repeat('M', 16).pack('V2', 5000, 0).'payload.rar'."\0";
        $firstRecovery = pack('V', 3).str_repeat('R', 4096);
        $identicalRecovery = $firstRecovery;
        $conflictingRecovery = pack('V', 3).str_repeat('X', 4096);
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0FileDesc", $fileDescription)
            .$this->par2Packet("PAR 2.0\0RecvSlic", $firstRecovery)
            .$this->par2Packet("PAR 2.0\0RecvSlic", $identicalRecovery)
            .$this->par2Packet("PAR 2.0\0RecvSlic", $conflictingRecovery);

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertSame(1, $metadata['invalid_packet_count']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-conflicting-recovery-exponent-packets', $metadata['recovery_percentage_basis']);
        self::assertCount(1, $metadata['conflicting_recovery_exponents']);
        self::assertSame(3, $metadata['conflicting_recovery_exponents'][0]['exponent']);
        self::assertSame($metadata['packet_summaries'][2]['offset'], $metadata['conflicting_recovery_exponents'][0]['first_packet_offset']);
        self::assertSame($metadata['packet_summaries'][4]['offset'], $metadata['conflicting_recovery_exponents'][0]['conflicting_packet_offset']);
        self::assertSame([3], $metadata['recovery_sets'][0]['conflicting_recovery_exponents']);
    }

    #[Test]
    public function it_keeps_recovery_only_par2_volume_slices_pending_set_level_validation(): void
    {
        $recovery = pack('V', 7).str_repeat('R', 4096);
        $binary = $this->par2Packet("PAR 2.0\0Creator", str_pad('par2j v1.3.3', 16, "\0"))
            .$this->par2Packet("PAR 2.0\0RecvSlic", $recovery);

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame(0, $metadata['invalid_packet_count']);
        self::assertSame([7], $metadata['recovery_exponents']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-source-block-size-not-in-this-volume', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    public function it_excludes_wrong_sized_recovery_packets_from_cross_volume_evidence(): void
    {
        $fileId = str_repeat('F', 16);
        $main = pack('V2', 4096, 0).pack('V', 1).$fileId;
        $binary = $this->par2Packet("PAR 2.0\0Main", $main)
            .$this->par2Packet("PAR 2.0\0RecvSlic", str_pad(pack('V', 7).'R', 8, "\0"));

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame(1, $metadata['invalid_packet_count']);
        self::assertSame([], $metadata['recovery_packets']);
    }

    #[Test]
    public function it_rejects_short_unicode_filename_packets_in_the_fallback(): void
    {
        config(['yenc.native_library' => '']);
        foreach ([0, 4, 12, 16] as $length) {
            $metadata = (new Par2MetadataParser)->parse($this->par2Packet("PAR 2.0\0UniFileN", str_repeat('U', $length)));
            self::assertSame(1, $metadata['invalid_packet_count']);
        }
    }

    #[Test]
    public function it_preserves_fileslic_and_empty_recovery_physical_regions_in_the_fallback(): void
    {
        config(['yenc.native_library' => '']);
        $cases = [
            ["PAR 2.0\0FileSlic", str_repeat('F', 12), ['fileslic_truncated_file_id' => [64, 12]]],
            ["PAR 2.0\0FileSlic", str_repeat('F', 16).'1234', ['fileslic_file_id' => [64, 16], 'fileslic_truncated_input_slice_index' => [80, 4]]],
            ["PAR 2.0\0FileSlic", str_repeat('F', 16).pack('V2', 1, 0).'data', ['fileslic_file_id' => [64, 16], 'fileslic_input_slice_index' => [80, 8], 'fileslic_physical_slice_field' => [88, 4]]],
            ["PAR 2.0\0RecvSlic", pack('V', 1), ['recovery_exponent' => [64, 4], 'recovery_data' => [68, 0]]],
        ];
        foreach ($cases as [$type, $payload, $expected]) {
            $metadata = (new Par2MetadataParser)->parse($this->par2Packet($type, $payload));
            $regions = array_column($metadata['packet_summaries'][0]['physical_regions'], null, 'kind');
            foreach ($expected as $kind => [$offset, $length]) {
                self::assertArrayHasKey($kind, $regions);
                self::assertSame($offset, $regions[$kind]['offset']);
                self::assertSame($length, $regions[$kind]['byte_length']);
            }
        }
    }

    #[Test]
    public function it_keeps_optional_unsigned_slice_indexes_out_of_global_par2_validity(): void
    {
        $method = new \ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges');
        $metadata = $method->invoke(null, [
            'invalid_packet_count' => 0,
            'integer_range_valid' => true,
            'recovery_to_source_percent' => 50.0,
            'optional_packet_summaries' => [['input_slice_index' => '9223372036854775808',
                'fileslic_relationship' => ['layout_status' => 'index-out-of-range', 'logical_file_offset' => '9223372036854775808']]],
        ]);
        self::assertSame(0, $metadata['invalid_packet_count']);
        self::assertTrue($metadata['integer_range_valid']);
        self::assertSame('9223372036854775808', $metadata['optional_packet_summaries'][0]['input_slice_index']);
        self::assertFalse($metadata['optional_packet_summaries'][0]['input_slice_index_integer_range_valid']);
        self::assertSame('9223372036854775808', $metadata['optional_packet_summaries'][0]['fileslic_relationship']['logical_file_offset']);
        self::assertFalse($metadata['optional_packet_summaries'][0]['fileslic_relationship']['logical_file_offset_integer_range_valid']);
        self::assertSame(50.0, $metadata['recovery_to_source_percent']);
    }

    #[Test]
    public function it_updates_native_aggregate_overflow_flags_at_the_php_integer_boundary(): void
    {
        $method = new \ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges');
        $aggregate = ['source_file_bytes' => '9223372036854775808', 'source_file_bytes_overflow' => false];
        $metadata = $method->invoke(null, $aggregate + [
            'source_block_count' => '9223372036854775808',
            'source_block_count_overflow' => false,
            'source_block_count_within_current_v2_limit' => false,
            'recovery_to_source_percent' => 10.0,
            'recovery_percentage_basis' => 'unique-valid-recovery-exponents-over-recoverable-source-blocks',
            'recovery_sets' => [$aggregate],
        ]);
        self::assertNull($metadata['source_file_bytes']);
        self::assertTrue($metadata['source_file_bytes_overflow']);
        self::assertTrue($metadata['source_block_count_overflow']);
        self::assertNull($metadata['source_block_count_within_current_v2_limit']);
        self::assertTrue($metadata['recovery_sets'][0]['source_file_bytes_overflow']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-unsupported-integer-range', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    public function it_recomputes_native_par2_source_block_limit_validity_from_usable_counts(): void
    {
        $method = new \ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges');
        $metadata = $method->invoke(null, [
            'source_block_count' => 32769,
            'source_block_count_within_current_v2_limit' => true,
            'recovery_to_source_percent' => 0.003,
            'recovery_percentage_basis' => 'unique-valid-recovery-exponents-over-recoverable-source-blocks',
            'recovery_sets' => [
                ['source_block_count' => 32768, 'source_block_count_within_current_v2_limit' => false],
                ['source_block_count' => 32769, 'source_block_count_within_current_v2_limit' => true],
                ['source_block_count_within_current_v2_limit' => false],
                ['source_block_count' => '9223372036854775808', 'source_block_count_within_current_v2_limit' => true],
            ],
        ]);

        self::assertSame(32769, $metadata['source_block_count']);
        self::assertSame(32768, $metadata['source_block_limit']);
        self::assertFalse($metadata['source_block_count_within_current_v2_limit']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-unsupported-integer-range', $metadata['recovery_percentage_basis']);
        self::assertTrue($metadata['recovery_sets'][0]['source_block_count_within_current_v2_limit']);
        self::assertFalse($metadata['recovery_sets'][1]['source_block_count_within_current_v2_limit']);
        self::assertNull($metadata['recovery_sets'][2]['source_block_count_within_current_v2_limit']);
        self::assertNull($metadata['recovery_sets'][3]['source_block_count_within_current_v2_limit']);
        self::assertNull($metadata['recovery_sets'][3]['source_block_count']);
        self::assertTrue($metadata['recovery_sets'][3]['source_block_count_overflow']);
        foreach ($metadata['recovery_sets'] as $set) {
            self::assertSame(32768, $set['source_block_limit']);
        }

        $oversized = $method->invoke(null, [
            'source_block_count' => 32769,
            'source_block_count_within_current_v2_limit' => true,
            'recovery_to_source_percent' => 0.003,
        ]);
        self::assertFalse($oversized['source_block_count_within_current_v2_limit']);
        self::assertNull($oversized['recovery_to_source_percent']);
        self::assertSame('unavailable-source-block-count-exceeds-current-v2-limit', $oversized['recovery_percentage_basis']);

        $unknown = $method->invoke(null, ['source_block_count_within_current_v2_limit' => true]);
        self::assertSame(32768, $unknown['source_block_limit']);
        self::assertNull($unknown['source_block_count_within_current_v2_limit']);
    }

    #[Test]
    public function it_withholds_a_legacy_native_success_basis_without_a_source_block_denominator(): void
    {
        $metadata = (new \ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges'))->invoke(null, [
            'source_block_size' => 4,
            'source_block_count' => null,
            'unique_recovery_slice_count' => 1,
            'source_inventory_complete' => false,
            'recovery_to_source_percent' => null,
            'recovery_percentage_basis' => 'unique-valid-recovery-exponents-over-recoverable-source-blocks',
        ]);

        self::assertNull($metadata['source_block_count']);
        self::assertNull($metadata['source_block_count_within_current_v2_limit']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-source-block-denominator', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    public function it_withholds_a_legacy_native_percentage_when_the_source_byte_total_overflowed(): void
    {
        $metadata = (new \ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges'))->invoke(null, [
            'source_block_count' => 4,
            'source_inventory_complete' => true,
            'source_file_bytes' => null,
            'source_file_bytes_overflow' => true,
            'recovery_to_source_percent' => 25.0,
            'recovery_percentage_basis' => 'unique-valid-recovery-exponents-over-recoverable-source-blocks',
            'source_files' => [
                ['file_length' => PHP_INT_MAX],
                ['file_length' => PHP_INT_MAX],
            ],
        ]);

        self::assertSame(4, $metadata['source_block_count']);
        self::assertTrue($metadata['source_block_count_within_current_v2_limit']);
        self::assertTrue($metadata['source_file_bytes_overflow']);
        self::assertSame([PHP_INT_MAX, PHP_INT_MAX], array_column($metadata['source_files'], 'file_length'));
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-source-file-byte-total-overflow', $metadata['recovery_percentage_basis']);
    }

    #[Test]
    #[DataProvider('par2ConflictPercentageBases')]
    public function it_preserves_native_par2_conflict_precedence_while_withholding_byte_overflow_percentages(string $conflictBasis): void
    {
        foreach ([4, 32769] as $count) {
            $metadata = (new \ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges'))->invoke(null, [
                'source_block_count' => $count,
                'source_inventory_complete' => true,
                'source_file_bytes' => null,
                'source_file_bytes_overflow' => true,
                'recovery_to_source_percent' => 25.0,
                'recovery_percentage_basis' => $conflictBasis,
            ]);

            self::assertSame($count, $metadata['source_block_count']);
            self::assertTrue($metadata['source_file_bytes_overflow']);
            self::assertNull($metadata['recovery_to_source_percent']);
            self::assertSame($conflictBasis, $metadata['recovery_percentage_basis']);
        }
    }

    #[Test]
    public function it_preserves_recovery_conflict_precedence_when_the_source_slice_capacity_is_exceeded(): void
    {
        config(['yenc.native_library' => '']);
        $bytes = $this->par2SourceBlockLimitFixture(32769)
            .$this->par2Packet("PAR 2.0\0RecvSlic", pack('V', 0).'SSSS');
        $metadata = (new Par2MetadataParser)->parse($bytes);

        self::assertSame(32769, $metadata['source_block_count']);
        self::assertFalse($metadata['source_block_count_within_current_v2_limit']);
        self::assertNull($metadata['recovery_to_source_percent']);
        self::assertSame('unavailable-conflicting-recovery-exponent-packets', $metadata['recovery_percentage_basis']);
    }

    /** @return iterable<string, array{string}> */
    public static function par2ConflictPercentageBases(): iterable
    {
        yield 'source definition conflict' => ['unavailable-conflicting-source-definition-packets'];
        yield 'recovery exponent conflict' => ['unavailable-conflicting-recovery-exponent-packets'];
    }

    #[Test]
    public function it_reports_unrepresentable_par2_u64_values_without_crashing_the_fallback(): void
    {
        $fileId = str_repeat('F', 16);
        $main = pack('V2', 0xFFFF_FFFC, 0xFFFF_FFFF).pack('V', 1).$fileId;
        $binary = $this->par2Packet("PAR 2.0\0Main", $main);

        config(['yenc.native_library' => '']);
        $metadata = (new Par2MetadataParser)->parse($binary);

        self::assertSame('php-fallback', $metadata['parser_engine']);
        self::assertSame(1, $metadata['invalid_packet_count']);
        self::assertNull($metadata['source_block_size']);
        self::assertNull($metadata['main_packet']);
    }

    #[Test]
    public function it_preserves_raw_header_order_and_extracts_yenc_frame_details(): void
    {
        $rawBody = (new YencService)->encode('sample payload', 'archive.part01.rar', 128);
        $headers = [
            'Message-Id: <part@example.net>',
            'User-Agent: Nyuu/0.4.2',
            'Injection-Info: news.example.net',
        ];

        $article = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle($headers, $rawBody);

        self::assertSame($headers, $article['raw_headers']);
        self::assertSame(['Message-Id', 'User-Agent', 'Injection-Info'], $article['header_profile']['ordered_names']);
        self::assertSame('Nyuu/0.4.2', $article['headers']['user-agent']);
        self::assertSame('archive.part01.rar', $article['yenc']['name']);
        self::assertSame(14, $article['yenc']['decoded_segment_size']);
        self::assertNotEmpty($article['article_body_sha256']);
    }

    #[Test]
    public function it_counts_trailing_blank_nntp_body_lines_without_trimming_them_away(): void
    {
        $analyzer = new NzbFingerprintAnalyzer(new YencService);

        $article = $analyzer->analyzeArticle([], "a\r\n\r\n");
        $blankLines = $analyzer->analyzeArticle([], "\r\n\r\n");
        $bareLf = $analyzer->analyzeArticle([], "a\n\n");

        self::assertSame(2, $article['retrieval_measurements']['body_line_count']);
        self::assertSame(2, $blankLines['retrieval_measurements']['body_line_count']);
        self::assertSame('complete_crlf', $article['nntp_body_metadata']['status']);
        self::assertNull($bareLf['retrieval_measurements']['body_line_count']);
        self::assertSame('non_crlf_line_endings', $bareLf['retrieval_measurements']['body_line_framing_status']);
    }

    private function par2Packet(string $type, string $payload): string
    {
        $type = str_pad($type, 16, "\0");
        $length = 64 + strlen($payload);
        $packet = "PAR2\0PKT".pack('V2', $length, 0).str_repeat("\0", 16).str_repeat('S', 16).$type.$payload;

        return substr($packet, 0, 16).md5(substr($packet, 32), true).substr($packet, 32);
    }
}
