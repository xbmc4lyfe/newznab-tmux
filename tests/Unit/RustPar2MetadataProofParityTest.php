<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Verification\Par2ProofIdentity;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RustPar2MetadataProofParityTest extends TestCase
{
    private const int ITERATIONS = 400;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_par2_proof_identity(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_cross_volume_recovery_conflicts(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_validate_cross_volume_ifsc(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_relate_nzb_file_prefixes(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /** Marks an input the Rust side defers to PHP. */
    private const string DEFERRED = "\0deferred";

    private ?FFI $ffi = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ffi = RustPipelineLibrary::resolveDomain('par2_metadata_proof', self::FFI_CDEF);
        if ($this->ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline is not built.');
        }
    }

    #[Test]
    public function rust_accepts_common_inputs_without_deferring(): void
    {
        mt_srand(4100);
        $this->assertIsString($this->rust('nntmux_par2_proof_identity', self::proofPackets(3, 4, 2)));
        $this->assertIsArray($this->rust('nntmux_par2_cross_volume_recovery_conflicts', self::recoveryVolumes(8, 6, true)));
        $this->assertIsArray($this->rust('nntmux_par2_validate_cross_volume_ifsc', self::ifscInput(20, false)));
        $this->assertIsArray($this->rust('nntmux_par2_relate_nzb_file_prefixes', self::relateInput(12, false)));
        $this->assertSame(self::DEFERRED, $this->rust('nntmux_par2_proof_identity', [new \stdClass]));
    }

    #[Test]
    public function proof_identity_matches_php(): void
    {
        mt_srand(4101);
        $nonNull = 0;
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $packets = self::mutateProofPackets(self::proofPackets(mt_rand(1, 6), mt_rand(0, 6), mt_rand(1, 3)));
            $expected = Par2ProofIdentity::fromPackets($packets);
            $this->assertSame($expected, $this->rust('nntmux_par2_proof_identity', $packets), "iteration {$i}");
            $nonNull += $expected === null ? 0 : 1;
        }
        $this->assertGreaterThan(self::ITERATIONS / 5, $nonNull);
    }

    #[Test]
    public function proof_identity_matches_php_or_defers_for_edge_shapes(): void
    {
        $base = self::proofPackets(2, 1, 1);
        $id = $base[1]['file_id'];
        $cases = [
            'empty' => [],
            'creator only' => [['head_type' => Par2Info::PACKET_CREATOR, 'head_set_id' => str_repeat('a', 32)]],
            'numeric id' => self::replaceFileId($base, $id, str_repeat('1', 32)),
            'float set id' => array_map(static fn (array $p): array => ['head_set_id' => 1.5] + $p, $base),
            'null other ids' => [['other_file_ids' => null] + $base[0], ...array_slice($base, 1)],
            'zero block size' => [['block_size' => 0] + $base[0], ...array_slice($base, 1)],
            'string block size' => [['block_size' => '64'] + $base[0], ...array_slice($base, 1)],
            'binary ids' => self::replaceFileId($base, $id, "\0\xff\"};a:".str_repeat('z', 25)),
        ];
        foreach ($cases as $name => $packets) {
            $actual = $this->rust('nntmux_par2_proof_identity', $packets);
            if ($actual !== self::DEFERRED) {
                $this->assertSame(Par2ProofIdentity::fromPackets($packets), $actual, $name);
            }
        }
        $this->assertSame(self::DEFERRED, $this->rust('nntmux_par2_proof_identity', $cases['float set id']));
    }

    #[Test]
    public function cross_volume_recovery_conflicts_match_php(): void
    {
        mt_srand(4102);
        $conflicts = 0;
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $volumes = self::recoveryVolumes(mt_rand(0, 10), mt_rand(0, 8), mt_rand(0, 1) === 1);
            $expected = Par2MetadataParser::findCrossVolumeRecoveryConflicts($volumes);
            $this->assertSame($expected, $this->rust('nntmux_par2_cross_volume_recovery_conflicts', $volumes), "iteration {$i}");
            $conflicts += count($expected);
        }
        $this->assertGreaterThan(0, $conflicts);
    }

    #[Test]
    public function cross_volume_ifsc_validation_matches_php(): void
    {
        mt_srand(4103);
        $statuses = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$main, $sources, $blockSize, $checksums] = self::ifscArguments(mt_rand(0, 25), true);
            $expected = Par2MetadataParser::validateCrossVolumeIfscRelationships($main, $sources, $blockSize, $checksums);
            $mainFileIds = $main['all_file_ids'] ?? [...($main['recoverable_file_ids'] ?? []), ...($main['non_recoverable_file_ids'] ?? [])];
            $input = [array_values(array_filter($mainFileIds, 'is_string')), $sources, $blockSize, $checksums, $main !== null];
            $this->assertSame($expected, $this->rust('nntmux_par2_validate_cross_volume_ifsc', $input), "iteration {$i}");
            $statuses[$expected['status']] = true;
        }
        $this->assertCount(4, $statuses);
    }

    #[Test]
    public function nzb_prefix_relationships_match_php(): void
    {
        mt_srand(4104);
        $statuses = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $input = self::relateInput(mt_rand(0, 15), true);
            $expected = Par2MetadataParser::relateNzbFilePrefixesToSources(...$input);
            $this->assertSame($expected, $this->rust('nntmux_par2_relate_nzb_file_prefixes', $input), "iteration {$i}");
            foreach ($expected['files'] as $file) {
                $statuses[$file['status']] = true;
                $statuses[$file['evidence_status']] = true;
            }
        }
        foreach (['candidate_match', 'ambiguous', 'no_match', 'insufficient_evidence', 'conflicting_source_definition', 'yenc_integrity_failed', 'yenc_start_offset_not_zero', 'yenc_file_coverage_unverified'] as $status) {
            $this->assertArrayHasKey($status, $statuses);
        }
    }

    /** Invokes a Rust export over `serialize($input)` and decodes its result like a PHP caller would. */
    private function rust(string $function, mixed $input): mixed
    {
        $this->assertNotNull($this->ffi);
        $payload = serialize($input);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($this->ffi, max(4096, strlen($payload)), $capacity);
        $this->assertNotNull($scratch);
        $written = $this->ffi->{$function}($payload, strlen($payload), $scratch, $capacity);
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($this->ffi, -$written, $capacity);
            $this->assertNotNull($scratch);
            $written = $this->ffi->{$function}($payload, strlen($payload), $scratch, $capacity);
        }
        if ($written === -1) {
            return self::DEFERRED;
        }
        if ($function === 'nntmux_par2_proof_identity') {
            return $written === 0 ? null : FFI::string($scratch, $written);
        }
        $this->assertGreaterThan(0, $written);

        return unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
    }

    private static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** @param list<mixed> $values */
    private static function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    /** @return list<array<string, mixed>> */
    public static function proofPackets(int $files, int $recovery, int $copies): array
    {
        $set = self::hex(16);
        $ids = [];
        for ($i = 0; $i < $files; $i++) {
            $ids[] = self::hex(16);
        }
        $recoverable = max(1, intdiv($files + 1, 2));
        $main = ['head_type' => Par2Info::PACKET_MAIN, 'head_set_id' => $set, 'head_hash' => self::hex(16), 'block_size' => 384_000,
            'rec_file_count' => $recoverable, 'rec_file_ids' => array_map(static fn (string $id): array => [1 => $id], array_slice($ids, 0, $recoverable))];
        if ($files > $recoverable) {
            $main['other_file_ids'] = array_map(static fn (string $id): array => [1 => $id], array_slice($ids, $recoverable));
        }
        $metadata = [$main];
        foreach ($ids as $n => $id) {
            $metadata[] = ['head_type' => Par2Info::PACKET_FILEDESC, 'head_set_id' => $set, 'head_hash' => self::hex(16), 'file_id' => $id,
                'file_name' => "Release.Name.part{$n}.rar", 'file_length' => mt_rand(1, 50_000_000), 'file_hash' => self::hex(16), 'file_hash_16K' => self::hex(16)];
            $checksums = [];
            for ($b = mt_rand(1, 12); $b > 0; $b--) {
                $checksums[] = ['md5' => self::hex(16), 'crc32' => mt_rand(0, 0x7FFFFFFF)];
            }
            $metadata[] = ['head_type' => Par2Info::PACKET_FILEVER, 'head_set_id' => $set, 'head_hash' => self::hex(16), 'file_id' => $id,
                'block_checksums' => $checksums];
        }
        $packets = [];
        for ($c = 0; $c < $copies; $c++) {
            array_push($packets, ...$metadata);
        }
        for ($r = 0; $r < $recovery; $r++) {
            $packets[] = ['head_type' => Par2Info::PACKET_RECOVERY, 'head_set_id' => $set, 'head_hash' => self::hex(16), 'exponent' => $r,
                'offset' => $r * 384_068, 'head_length' => 384_068];
        }
        $packets[] = ['head_type' => Par2Info::PACKET_CREATOR, 'head_set_id' => $set, 'head_hash' => self::hex(16), 'client' => 'QuickPar 0.9'];
        shuffle($packets);

        return $packets;
    }

    /**
     * @param  list<array<string, mixed>>  $packets
     * @return list<array<string, mixed>>
     */
    private static function mutateProofPackets(array $packets): array
    {
        $index = mt_rand(0, count($packets) - 1);
        switch (mt_rand(0, 9)) {
            case 0:
                $packets[] = ['file_length' => 1] + self::firstOfType($packets, Par2Info::PACKET_FILEDESC);
                break;
            case 1:
                $packets[$index]['head_set_id'] = self::hex(16);
                break;
            case 2:
                array_splice($packets, $index, 1);
                break;
            case 3:
                $packets[] = ['head_type' => Par2Info::PACKET_FILENAME_UC, 'head_set_id' => $packets[0]['head_set_id']];
                break;
            case 4:
                $packets[] = ['block_checksums' => []] + self::firstOfType($packets, Par2Info::PACKET_FILEVER);
                break;
            case 5:
                $packets[] = ['head_hash' => self::hex(16)] + self::firstOfType($packets, Par2Info::PACKET_MAIN);
                break;
            case 6:
                $main = self::firstOfType($packets, Par2Info::PACKET_MAIN);
                $main['rec_file_ids'][] = $main['rec_file_ids'][0];
                $main['rec_file_count']++;
                $packets = [$main, ...array_values(array_filter($packets, static fn (array $p): bool => $p['head_type'] !== Par2Info::PACKET_MAIN))];
                break;
            default:
                break;
        }

        return $packets;
    }

    /**
     * @param  list<array<string, mixed>>  $packets
     * @return array<string, mixed>
     */
    private static function firstOfType(array $packets, string $type): array
    {
        foreach ($packets as $packet) {
            if ($packet['head_type'] === $type) {
                return $packet;
            }
        }

        return ['head_type' => $type, 'head_set_id' => $packets[0]['head_set_id']];
    }

    /**
     * @param  list<array<string, mixed>>  $packets
     * @return list<array<string, mixed>>
     */
    private static function replaceFileId(array $packets, string $from, string $to): array
    {
        $encoded = serialize($packets);
        $search = 's:32:"'.$from.'";';
        $replace = 's:'.strlen($to).':"'.$to.'";';

        return unserialize(str_replace($search, $replace, $encoded));
    }

    /** @return list<array<string, mixed>> */
    public static function recoveryVolumes(int $volumes, int $packetsPerVolume, bool $noisy): array
    {
        $sets = [self::hex(16), self::hex(16)];
        $hashes = [];
        $result = [];
        for ($v = 0; $v < $volumes; $v++) {
            $packets = [];
            for ($p = 0; $p < $packetsPerVolume; $p++) {
                $set = self::pick($sets);
                $exponent = mt_rand(0, 6);
                $hash = $hashes[$set.$exponent] ??= self::hex(32);
                if ($noisy && mt_rand(0, 4) === 0) {
                    $hash = self::hex(32);
                }
                if ($noisy && mt_rand(0, 6) === 0) {
                    $hash = strtoupper($hash);
                }
                $packet = [
                    'recovery_set_id' => $set,
                    'exponent' => $noisy && mt_rand(0, 9) === 0 ? self::pick([65535, 65536, -1, '3', null]) : $exponent,
                    'payload_bytes' => 384_004,
                    'slice_sha256' => $noisy && mt_rand(0, 15) === 0 ? self::pick(['xyz', null, substr($hash, 1)]) : $hash,
                    'packet_offset' => $noisy && mt_rand(0, 15) === 0 ? self::pick([-1, '7', null]) : $p * 384_068,
                    'article_segments' => [['segment_number' => $p + 1, 'message_id' => "<v{$v}p{$p}@example.test>", 'assembled_file_byte_start' => 0,
                        'assembled_file_byte_end_exclusive' => 384_068, 'overlap_bytes' => 384_068, 'server_role' => 'primary']],
                ];
                if ($noisy && mt_rand(0, 9) === 0) {
                    unset($packet['article_segments']);
                }
                $packets[] = $packet;
            }
            $volume = ['file_index' => $noisy && mt_rand(0, 9) === 0 ? self::pick([null, $v - 1, 'x']) : $v,
                'subject' => "Release.vol{$v}+01.par2 (1/1)", 'recovery_packets' => $packets];
            if ($noisy && mt_rand(0, 12) === 0) {
                unset($volume['recovery_packets']);
            }
            $result[] = $volume;
        }

        return $result;
    }

    /** @return array{?array<string, mixed>, array<string, array<string, mixed>>, ?int, list<array<string, mixed>>} */
    public static function ifscArguments(int $count, bool $noisy): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = self::hex(16);
        }
        $blockSize = $noisy ? self::pick([384_000, 384_000, 384_000, 0, null, 4096]) : 384_000;
        $sources = [];
        $checksums = [];
        foreach ($ids as $id) {
            $length = mt_rand(1, 20_000_000);
            if (! $noisy || mt_rand(0, 9) > 0) {
                $sources[$id] = ['file_id' => $id, 'file_length' => $noisy && mt_rand(0, 15) === 0 ? self::pick([null, '5', -1]) : $length];
            }
            $slices = is_int($blockSize) && $blockSize > 0 ? intdiv($length, $blockSize) + ($length % $blockSize === 0 ? 0 : 1) : 1;
            $checksum = ['recovery_set_id' => str_repeat('a', 32), 'file_id' => $id, 'slice_count' => $slices,
                'checksum_records_sha256' => hash('sha256', $id), 'duplicate_conflict' => false, 'slice_count_matches_file_length' => true];
            if ($noisy) {
                switch (mt_rand(0, 14)) {
                    case 0: $checksum['file_id'] = strtoupper($id);
                        break;
                    case 1: $checksum['slice_count']++;
                        break;
                    case 2: $checksum['duplicate_conflict'] = true;
                        break;
                    case 3: $checksum['file_id'] = self::pick(['bad', null, 17, substr($id, 1)]);
                        break;
                    case 4: $checksum['checksum_records_sha256'] = 'zz';
                        break;
                    case 5: $checksum['slice_count_matches_file_length'] = false;
                        break;
                    case 6: $checksums[] = ['checksum_records_sha256' => hash('sha256', 'other')] + $checksum;
                        break;
                    case 7: $checksums[] = $checksum;
                        break;
                    default: break;
                }
            }
            $checksums[] = $checksum;
        }
        $main = ['all_file_ids' => $ids];
        if ($noisy) {
            $main = self::pick([
                $main,
                null,
                ['recoverable_file_ids' => array_slice($ids, 0, 3), 'non_recoverable_file_ids' => array_slice($ids, 3)],
                ['all_file_ids' => array_slice($ids, 1)],
                ['all_file_ids' => [...$ids, 5]],
            ]);
        }

        return [$main, $sources, $blockSize, $checksums];
    }

    /** @return list<mixed> */
    public static function ifscInput(int $count, bool $noisy): array
    {
        [$main, $sources, $blockSize, $checksums] = self::ifscArguments($count, $noisy);

        return [$main['all_file_ids'] ?? [], $sources, $blockSize, $checksums, $main !== null];
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>, list<array<string, mixed>>, list<string>} */
    public static function relateInput(int $fileCount, bool $noisy): array
    {
        $set = self::hex(16);
        $files = [];
        $observations = [];
        $sources = [];
        $conflictKeys = [];
        for ($i = 0; $i < $fileCount; $i++) {
            $length = $noisy ? self::pick([20_000, 9_000, 5_000_000]) : 5_000_000 + $i;
            $hash = self::hex(16);
            $fileId = self::hex(16);
            $kind = $noisy && mt_rand(0, 7) === 0 ? 'par2' : 'rar';
            $files[] = ['index' => $i, 'kind' => $kind, 'subject' => "Release.part{$i}.rar (1/40)", 'segment_number_sequence_valid' => ! ($noisy && mt_rand(0, 15) === 0),
                'invalid_segment_record_count' => 0, 'invalid_segment_metadata_count' => 0, 'duplicate_message_id_count' => 0, 'duplicate_segment_number_count' => 0,
                'segments' => [['number' => 1, 'bytes' => 768_000, 'message_id' => "<f{$i}s1@example.test>"]]];
            $multipart = ! $noisy || mt_rand(0, 3) > 0;
            $prefix = min($length, 16_384);
            $yenc = $multipart ? [
                'file_size' => $length, 'multipart' => true, 'begin' => 1, 'end' => min($length, 768_000), 'part' => 1, 'total_parts' => 40,
                'decoded_segment_size' => min($length, 768_000), 'header_numeric_fields_valid' => true, 'integrity_checks_ambiguous' => false,
                'ypart_range_valid' => true, 'decoded_size_matches_ypart_range' => true, 'decoded_size_matches_yenc' => true,
                'decoded_size_matches_yend_size' => true, 'pcrc32' => 'aabbccdd', 'declared_crc32_is_hex' => true, 'declared_crc32_matches' => true,
                'trailer_fields' => ['part' => '1', 'total' => '40'],
            ] : [
                'file_size' => $length, 'multipart' => false, 'decoded_segment_size' => $length, 'header_numeric_fields_valid' => true,
                'integrity_checks_ambiguous' => false, 'decoded_size_matches_yenc' => true, 'decoded_size_matches_yend_size' => true,
                'decoded_size_matches_header_size' => true, 'crc32' => 'AABBCCDD', 'segment_crc32_basis' => 'crc32',
                'declared_crc32_is_hex' => true, 'declared_crc32_matches' => true,
            ];
            if ($noisy) {
                switch (mt_rand(0, 16)) {
                    case 0: $yenc['begin'] = 2;
                        break;
                    case 1: $yenc['declared_crc32_matches'] = false;
                        break;
                    case 2: $yenc['file_size'] = (string) $length;
                        break;
                    case 3: $yenc['multipart'] = null;
                        break;
                    case 4: $yenc['part'] = 2;
                        break;
                    case 5: $yenc['decoded_segment_size'] = 10;
                        break;
                    case 6: $yenc['segment_crc32_basis'] = self::pick(['missing-crc32', 'unavailable-multipart-structure', 'pcrc32', null]);
                        break;
                    case 7: $yenc['trailer_fields'] = ['part' => '01', 'total' => '39'];
                        break;
                    case 8: $yenc['file_size'] = '000'.$length;
                        break;
                    default: break;
                }
            }
            $metadata = [
                'yenc' => $yenc,
                'yenc_nzb_part_number_matches' => true,
                'yenc_nzb_total_parts_matches' => true,
                'article_identity' => ['parser_engine' => 'nntmux-rust-article-identity', 'schema_version' => 3,
                    'relations' => ['body_response_matches_request' => ! ($noisy && mt_rand(0, 15) === 0)]],
                'decoded_format_metadata' => ['sample_prefix_identity' => ['bytes_hashed' => $prefix, 'md5' => $noisy && mt_rand(0, 5) === 0 ? strtoupper($hash) : $hash,
                    'parser_input_offset_zero' => true, 'file_offset_known' => false], 'format' => 'rar', 'confidence' => 0.97],
                'timing_ms' => 12.5,
            ];
            if ($noisy && mt_rand(0, 4) === 0) {
                $metadata['yenc_framing_metadata'] = self::pick([
                    ['frame_count' => 1, 'frames' => [['part_number_matches' => true, 'total_parts_match' => true]]],
                    ['frame_count' => 2, 'frames' => [[], []]],
                    ['frame_count' => 1, 'frames' => [['part_number_matches' => false]]],
                    ['frames_truncated' => true],
                ]);
            }
            $observation = ['file_index' => $i, 'segment_number' => 1, 'sample_role' => 'first', 'server_role' => 'primary', 'server' => 'news.example.test',
                'provider_label' => 'Example', 'server_port' => $noisy ? self::pick([563, '119', null, -5]) : 563, 'server_tls' => true,
                'queried_at_utc' => '2026-10-13T12:00:00Z', 'message_id' => "<f{$i}s1@example.test>", 'metadata' => $metadata];
            $observations[] = $observation;
            $observations[] = ['segment_number' => 20, 'sample_role' => 'middle'] + $observation;
            if ($noisy && mt_rand(0, 15) === 0) {
                $observations[] = $observation;
            }
            if ($noisy && mt_rand(0, 9) === 0) {
                continue;
            }
            $source = ['recovery_set_id' => $set, 'file_id' => $fileId, 'filename' => "Original.part{$i}.rar", 'filename_bytes_length' => 18,
                'file_length' => $length, 'md5' => self::hex(16), 'md5_first_16k' => $hash, 'file_id_matches_derived' => true];
            if ($noisy) {
                switch (mt_rand(0, 10)) {
                    case 0: $sources[] = ['file_id' => self::hex(16)] + $source;
                        break;
                    case 1: $conflictKeys[] = $set.':'.$fileId;
                        break;
                    case 2: $source['md5_first_16k'] = strtoupper($hash);
                        break;
                    case 3: $source['file_length'] = (string) $length;
                        break;
                    case 4: $source['filename'] = null;
                        break;
                    case 5: $source['file_id'] = strtoupper($fileId);
                        break;
                    default: break;
                }
            }
            $sources[] = $source;
        }
        if ($noisy && mt_rand(0, 9) === 0) {
            $conflictKeys[] = $set.':*';
        }

        return [$files, $observations, $sources, $conflictKeys];
    }
}
