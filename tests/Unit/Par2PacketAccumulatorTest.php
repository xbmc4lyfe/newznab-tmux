<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2PacketAccumulator;
use dariusiii\rarinfo\Par2Info;
use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Par2PacketAccumulatorTest extends TestCase
{
    public function test_main_in_first_article_resumes_with_real_fixture_descriptors(): void
    {
        $packets = $this->fixturePackets();
        $main = array_values(array_filter($packets, static fn (string $packet): bool => substr($packet, 48, 16) === Par2Info::PACKET_MAIN))[0];
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($main);

        $this->assertFalse($accumulator->isComplete());
        $resumed = Par2PacketAccumulator::fromState(json_decode(json_encode($accumulator->exportState(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $resumed->append(implode('', $packets));

        $this->assertTrue($resumed->isComplete());
        $files = array_values($resumed->filesBySet())[0];
        $this->assertCount(10, $files);
        $this->assertSame(['test-3.data', 'test-1.data', 'test-0.data', 'test-7.data', 'test-8.data', 'test-2.data', 'test-5.data', 'test-4.data', 'test-9.data', 'test-6.data'], array_column($files, 'name'));
    }

    #[DataProvider('splitOffsets')]
    public function test_packet_headers_and_bodies_resume_after_json_round_trip(int $split, bool $complete): void
    {
        $bytes = file_get_contents(__DIR__.'/../Fixtures/par2/rename-index.par2');
        $this->assertIsString($bytes);
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append(substr($bytes, 0, $split));
        $state = $accumulator->exportState();

        $this->assertSame($complete, $accumulator->isComplete());
        $resumed = Par2PacketAccumulator::fromState(json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($state, $resumed->exportState());
        $resumed->append(substr($bytes, $split));

        $this->assertTrue($resumed->isComplete());
        $this->assertCount(10, array_values($resumed->filesBySet())[0]);
    }

    /** @return array<string, array{int, bool}> */
    public static function splitOffsets(): array
    {
        return ['marker' => [3, false], 'length' => [12, false], 'hash' => [22, false], 'type' => [52, false], 'descriptor body' => [123, false], 'IFSC body' => [500, false], 'packet boundary' => [132, true], 'Main body' => [6200, false], 'Creator body' => [6440, false]];
    }

    public function test_equal_file_ids_in_different_sets_do_not_hide_missing_descriptors(): void
    {
        $id = str_repeat('i', 16);
        $first = str_repeat('a', 16);
        $second = str_repeat('b', 16);
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->main($id, $first).$this->descriptor('first.mkv', $id, $first).$this->main($id, $second));

        $this->assertFalse($accumulator->isComplete());
        $accumulator->append($this->descriptor('second.mkv', $id, $second));

        $this->assertTrue($accumulator->isComplete());
        $files = $accumulator->filesBySet();
        $this->assertSame([bin2hex($first), bin2hex($second)], array_keys($files));
        $this->assertSame('first.mkv', $files[bin2hex($first)][bin2hex($id)]['name']);
        $this->assertSame('second.mkv', $files[bin2hex($second)][bin2hex($id)]['name']);
    }

    public function test_descriptor_only_preserves_long_name_large_size_and_distinct_hashes(): void
    {
        $name = str_repeat('long-name.', 35).'mkv';
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->descriptor($name, str_repeat('i', 16), str_repeat('s', 16), 12884902011));

        $files = array_values(array_values($accumulator->filesBySet())[0]);
        $this->assertTrue($accumulator->isComplete());
        $this->assertSame($name, $files[0]['name']);
        $this->assertSame(12884902011, $files[0]['size']);
        $this->assertSame(md5('whole file'), $files[0]['hash']);
        $this->assertSame(md5('first 16K'), $files[0]['hash_16K']);
        $this->assertSame(0, $files[0]['blocks']);
    }

    public function test_literal_filename_trailing_spaces_are_preserved(): void
    {
        $name = "Movie Name.mkv \t";
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->descriptor($name, str_repeat('i', 16), str_repeat('s', 16)));

        $this->assertSame($name, array_values(array_values($accumulator->filesBySet())[0])[0]['name']);
    }

    public function test_descriptor_collection_projects_validated_real_fixture_packets_losslessly(): void
    {
        $packets = $this->fixturePackets();
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append(implode('', $packets));
        $before = $accumulator->exportState();
        $descriptors = $accumulator->descriptors();
        $expectedPackets = array_values(array_filter($packets, static fn (string $packet): bool => substr($packet, 48, 16) === Par2Info::PACKET_FILEDESC));

        $this->assertCount(10, $descriptors);
        foreach ($expectedPackets as $index => $packet) {
            $descriptor = $descriptors[$index];
            $this->assertSame(bin2hex(substr($packet, 32, 16)), $descriptor['recovery_set_id']);
            $this->assertSame(bin2hex(substr($packet, 64, 16)), $descriptor['file_id']);
            $this->assertSame(bin2hex(substr($packet, 80, 16)), $descriptor['hash']);
            $this->assertSame(bin2hex(substr($packet, 96, 16)), $descriptor['hash_16K']);
            $this->assertSame(substr($packet, 120), $descriptor['filename_bytes']);
            $this->assertSame(md5(substr($packet, 32)), $descriptor['packet_checksum']);
            $file = $accumulator->filesBySet()[$descriptor['recovery_set_id']][$descriptor['file_id']];
            $this->assertSame((string) $file['size'], $descriptor['size_decimal']);
        }
        $this->assertSame($before, $accumulator->exportState());
        $resumed = Par2PacketAccumulator::fromState(json_decode(json_encode($before, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($descriptors, $resumed->descriptors());
    }

    public function test_descriptor_collection_preserves_binary_padded_name_and_unsigned_size(): void
    {
        $body = str_repeat('i', 16).md5('whole file', true).md5('first 16K', true).pack('V2', 0xFFFFFFFE, 0xFFFFFFFF)."raw\xff\0name \t";
        $packet = $this->packet(Par2Info::PACKET_FILEDESC, $body, str_repeat('s', 16));
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($packet);

        $this->assertSame([[
            'recovery_set_id' => bin2hex(str_repeat('s', 16)),
            'file_id' => bin2hex(str_repeat('i', 16)),
            'hash' => md5('whole file'),
            'hash_16K' => md5('first 16K'),
            'size_decimal' => '18446744073709551614',
            'filename_bytes' => "raw\xff\0name \t\0",
            'packet_checksum' => bin2hex(substr($packet, 16, 16)),
        ]], $accumulator->descriptors());
    }

    public function test_descriptor_collection_keeps_aliases_and_equal_file_ids_in_distinct_sets(): void
    {
        $first = $this->descriptor('same.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $alias = $this->descriptor('same.mkv', str_repeat('j', 16), str_repeat('s', 16));
        $otherSet = $this->descriptor('other.mkv', str_repeat('i', 16), str_repeat('t', 16));
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($first.$alias.$otherSet.$first);

        $descriptors = $accumulator->descriptors();
        $this->assertCount(3, $descriptors);
        $this->assertSame([bin2hex(str_repeat('i', 16)), bin2hex(str_repeat('j', 16)), bin2hex(str_repeat('i', 16))], array_column($descriptors, 'file_id'));
        $this->assertSame([bin2hex(str_repeat('s', 16)), bin2hex(str_repeat('s', 16)), bin2hex(str_repeat('t', 16))], array_column($descriptors, 'recovery_set_id'));
        $this->assertSame(['same.mkv', 'same.mkv', "other.mkv\0\0\0"], array_column($descriptors, 'filename_bytes'));
    }

    public function test_descriptor_collection_never_exposes_partial_corrupt_or_conflicting_packets(): void
    {
        $packet = $this->descriptor('first.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append(substr($packet, 0, -1));
        $this->assertSame([], $accumulator->descriptors());
        $accumulator->append(substr($packet, -1));
        $before = $accumulator->descriptors();
        $corrupt = $this->descriptor('bad.mkv', str_repeat('j', 16), str_repeat('s', 16));
        $corrupt[16] = chr(ord($corrupt[16]) ^ 1);
        $conflicting = $this->descriptor('conflict.mkv', str_repeat('i', 16), str_repeat('s', 16));

        foreach ([$corrupt, $conflicting] as $rejected) {
            try {
                $accumulator->append($rejected);
                $this->fail('Invalid descriptor was accepted');
            } catch (InvalidArgumentException) {
                $this->assertSame($before, $accumulator->descriptors());
            }
        }
    }

    public function test_database_json_key_reordering_is_accepted_and_export_is_canonical(): void
    {
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16)));
        $state = $accumulator->exportState();
        $reordered = array_reverse($state, true);

        $this->assertSame($state, Par2PacketAccumulator::fromState($reordered)->exportState());
    }

    #[DataProvider('descriptorZeroOrders')]
    public function test_literal_zero_filename_is_preserved_with_or_without_preceding_ifsc(bool $withIfsc): void
    {
        $set = str_repeat('s', 16);
        $id = str_repeat('i', 16);
        $bytes = $withIfsc ? $this->packet(Par2Info::PACKET_FILEVER, $id.md5('slice', true).pack('V', 123), $set) : '';
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($bytes.$this->descriptor('0', $id, $set));

        $this->assertTrue($accumulator->isComplete());
        $this->assertSame('0', $accumulator->filesBySet()[bin2hex($set)][bin2hex($id)]['name']);
    }

    /** @return array<string, array{bool}> */
    public static function descriptorZeroOrders(): array
    {
        return ['descriptor only' => [false], 'IFSC first' => [true]];
    }

    public function test_ifsc_without_descriptor_is_unresolved(): void
    {
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->packet(Par2Info::PACKET_FILEVER, str_repeat('i', 16).md5('slice', true).pack('V', 123), str_repeat('s', 16)));

        $this->assertFalse($accumulator->isComplete());
    }

    public function test_ifsc_crc_corruption_is_rejected_by_packet_checksum(): void
    {
        $bytes = $this->packet(Par2Info::PACKET_FILEVER, str_repeat('i', 16).md5('slice', true).pack('V', 123), str_repeat('s', 16));
        $bytes[96] = chr(ord($bytes[96]) ^ 1);
        $accumulator = new Par2PacketAccumulator;

        $this->expectException(InvalidArgumentException::class);
        $accumulator->append($bytes);
    }

    public function test_complete_main_inventory_requires_its_declared_descriptors(): void
    {
        $set = str_repeat('s', 16);
        $id = str_repeat('i', 16);
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->main($id, $set));

        $this->assertFalse($accumulator->hasCompleteMainInventory());
        $accumulator->append($this->descriptor('movie.mkv', $id, $set));
        $this->assertTrue($accumulator->hasCompleteMainInventory());
    }

    public function test_descriptor_only_completion_does_not_claim_complete_main_inventory(): void
    {
        $accumulator = new Par2PacketAccumulator;
        $this->assertFalse($accumulator->hasCompleteMainInventory());
        $accumulator->append($this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16)));

        $this->assertTrue($accumulator->isComplete());
        $this->assertFalse($accumulator->hasCompleteMainInventory());
    }

    public function test_complete_main_inventory_requires_a_complete_main_for_every_retained_set(): void
    {
        $first = str_repeat('a', 16);
        $second = str_repeat('b', 16);
        $id = str_repeat('i', 16);
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->main($id, $first).$this->descriptor('first.mkv', $id, $first).$this->descriptor('second.mkv', $id, $second));

        $this->assertTrue($accumulator->isComplete());
        $this->assertFalse($accumulator->hasCompleteMainInventory());
        $accumulator->append($this->main($id, $second));
        $this->assertTrue($accumulator->hasCompleteMainInventory());
    }

    public function test_complete_main_inventory_requires_orphan_ifsc_descriptors_too(): void
    {
        $set = str_repeat('s', 16);
        $id = str_repeat('i', 16);
        $orphan = str_repeat('o', 16);
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->main($id, $set).$this->descriptor('movie.mkv', $id, $set).$this->packet(Par2Info::PACKET_FILEVER, $orphan.md5('slice', true).pack('V', 123), $set));

        $this->assertFalse($accumulator->hasCompleteMainInventory());
        $accumulator->append($this->descriptor('orphan.mkv', $orphan, $set));
        $this->assertTrue($accumulator->hasCompleteMainInventory());
    }

    public function test_complete_main_inventory_can_stop_during_huge_recognized_parity_skip(): void
    {
        $set = str_repeat('s', 16);
        $id = str_repeat('i', 16);
        $length = 5 * 1024 * 1024 * 1024 + 64;
        $header = Par2Info::PACKET_MARKER.pack('V2', $length & 0xFFFFFFFF, intdiv($length, 0x100000000)).str_repeat("\0", 16).$set.Par2Info::PACKET_RECOVERY;
        $accumulator = new Par2PacketAccumulator(1024);
        $accumulator->append($this->main($id, $set).$this->descriptor('movie.mkv', $id, $set).$header);

        $this->assertTrue($accumulator->hasCompleteMainInventory());
        $this->assertFalse($accumulator->isComplete());
        $this->assertTrue(Par2PacketAccumulator::fromState($accumulator->exportState(), 1024)->hasCompleteMainInventory());
        $this->assertSame($length - 64, $accumulator->exportState()['skip_remaining']);
    }

    #[DataProvider('incompleteInventoryTails')]
    public function test_complete_main_inventory_rejects_incomplete_header_or_metadata_tail(int $length): void
    {
        $set = str_repeat('s', 16);
        $id = str_repeat('i', 16);
        $descriptor = $this->descriptor('movie.mkv', $id, $set);
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->main($id, $set).$descriptor.substr($descriptor, 0, $length));

        $this->assertFalse($accumulator->hasCompleteMainInventory());
    }

    /** @return array<string, array{int}> */
    public static function incompleteInventoryTails(): array
    {
        return ['header prefix' => [3], 'metadata body' => [80]];
    }

    public function test_huge_parity_payload_is_skipped_with_bounded_restart_state(): void
    {
        $set = str_repeat('s', 16);
        $id = str_repeat('i', 16);
        $accumulator = new Par2PacketAccumulator(1024);
        $accumulator->append($this->descriptor('movie.mkv', $id, $set));
        $parityLength = 5 * 1024 * 1024 * 1024 + 64;
        $header = Par2Info::PACKET_MARKER.pack('V2', $parityLength & 0xFFFFFFFF, intdiv($parityLength, 0x100000000)).str_repeat("\0", 16).$set.Par2Info::PACKET_RECOVERY;
        $accumulator->append($header.str_repeat('p', 65536));

        $this->assertFalse($accumulator->isComplete());
        $this->assertSame($parityLength - 64 - 65536, $accumulator->exportState()['skip_remaining']);
        $this->assertSame('', $accumulator->exportState()['tail']);
        $this->assertLessThan(1024, $accumulator->stateBytes());
        $resumed = Par2PacketAccumulator::fromState($accumulator->exportState(), 1024);
        $resumed->append(str_repeat('p', 65536));
        $this->assertSame($parityLength - 64 - 131072, $resumed->exportState()['skip_remaining']);
    }

    public function test_parity_skip_finishes_before_following_descriptor_without_retaining_payload(): void
    {
        $accumulator = new Par2PacketAccumulator(1024);
        $parity = $this->packet(Par2Info::PACKET_RECOVERY, pack('V', 1).str_repeat('x', 100000), str_repeat('s', 16));
        $accumulator->append(substr($parity, 0, 50000));
        $accumulator = Par2PacketAccumulator::fromState($accumulator->exportState(), 1024);

        $accumulator->append(substr($parity, 50000).$this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16)));

        $this->assertTrue($accumulator->isComplete());
        $this->assertCount(1, $accumulator->exportState()['packets']);
    }

    #[DataProvider('corruptionOffsets')]
    public function test_corrupt_metadata_is_rejected_transactionally_and_clean_retry_succeeds(int $offset): void
    {
        $bytes = $this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $broken = $bytes;
        $broken[$offset] = chr(ord($broken[$offset]) ^ 1);
        $accumulator = new Par2PacketAccumulator;
        $state = $accumulator->exportState();

        try {
            $accumulator->append($broken);
            $this->fail('Corrupt packet was accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame($state, $accumulator->exportState());
            $this->assertFalse($accumulator->isComplete());
        }
        $accumulator->append($bytes);
        $this->assertTrue($accumulator->isComplete());
    }

    /** @return array<string, array{int}> */
    public static function corruptionOffsets(): array
    {
        return ['marker' => [0], 'MD5 header' => [16], 'set ID' => [32], 'packet type' => [48], 'descriptor hash' => [90], 'file name' => [122]];
    }

    public function test_exact_serialized_state_ceiling_accepts_limit_and_rejects_one_byte_less(): void
    {
        $bytes = $this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $reference = new Par2PacketAccumulator;
        $reference->append($bytes);
        $size = strlen(json_encode($reference->exportState(), JSON_THROW_ON_ERROR));
        $accumulator = new Par2PacketAccumulator($size);
        $accumulator->append($bytes);
        $this->assertSame($size, $accumulator->stateBytes());
        $this->assertSame($reference->exportState(), Par2PacketAccumulator::fromState($accumulator->exportState(), $size)->exportState());
        $smaller = new Par2PacketAccumulator($size - 1);
        $before = $smaller->exportState();

        try {
            $smaller->append($bytes);
            $this->fail('State exceeded its ceiling');
        } catch (LengthException) {
            $this->assertSame($before, $smaller->exportState());
        }
    }

    public function test_identical_packet_retry_does_not_grow_state(): void
    {
        $bytes = $this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($bytes);
        $before = $accumulator->exportState();

        $accumulator->append($bytes);

        $this->assertSame($before, $accumulator->exportState());
    }

    public function test_conflicting_description_for_same_set_and_file_is_rejected(): void
    {
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($this->descriptor('first.mkv', str_repeat('i', 16), str_repeat('s', 16)));

        $this->expectException(InvalidArgumentException::class);
        $accumulator->append($this->descriptor('different.mkv', str_repeat('i', 16), str_repeat('s', 16)));
    }

    #[DataProvider('malformedPackets')]
    public function test_malformed_complete_packet_cannot_become_success(string $type, string $body): void
    {
        $accumulator = new Par2PacketAccumulator;

        $this->expectException(InvalidArgumentException::class);
        $accumulator->append($this->packet($type, $body, str_repeat('s', 16)));
    }

    /** @return array<string, array{string, string}> */
    public static function malformedPackets(): array
    {
        return [
            'Main count exceeds identities' => [Par2Info::PACKET_MAIN, pack('V3', 4, 0, 2).str_repeat('i', 16)],
            'Main partial identity' => [Par2Info::PACKET_MAIN, pack('V3', 4, 0, 0).'abcd'],
            'short descriptor' => [Par2Info::PACKET_FILEDESC, str_repeat('x', 52)],
            'IFSC partial checksum' => [Par2Info::PACKET_FILEVER, str_repeat('i', 16).'abcd'],
        ];
    }

    #[DataProvider('invalidLengths')]
    public function test_invalid_packet_lengths_are_rejected_without_waiting_for_payload(int $low, int $high): void
    {
        $header = Par2Info::PACKET_MARKER.pack('V2', $low, $high).str_repeat("\0", 16).str_repeat('s', 16).Par2Info::PACKET_RECOVERY;
        $accumulator = new Par2PacketAccumulator;

        $this->expectException(InvalidArgumentException::class);
        $accumulator->append($header);
    }

    /** @return array<string, array{int,int}> */
    public static function invalidLengths(): array
    {
        return ['under header' => [60, 0], 'misaligned' => [65, 0], 'signed overflow' => [64, 0x80000000]];
    }

    public function test_partial_packet_never_exposes_unvalidated_descriptor(): void
    {
        $bytes = $this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append(substr($bytes, 0, -1));

        $this->assertFalse($accumulator->isComplete());
        $this->assertSame([], $accumulator->filesBySet());
        $this->assertSame(base64_encode(substr($bytes, 0, -1)), $accumulator->exportState()['tail']);
    }

    public function test_state_import_revalidates_packet_checksums(): void
    {
        $bytes = $this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $bytes[122] = 'X';

        $this->expectException(InvalidArgumentException::class);
        Par2PacketAccumulator::fromState(['version' => 1, 'packets' => [base64_encode($bytes)], 'tail' => '', 'skip_remaining' => 0]);
    }

    public function test_corrupted_creator_checksum_after_valid_descriptor_rejects_whole_append(): void
    {
        $descriptor = $this->descriptor('movie.mkv', str_repeat('i', 16), str_repeat('s', 16));
        $creator = $this->packet(Par2Info::PACKET_CREATOR, 'fixture creator', str_repeat('s', 16));
        $creator[16] = chr(ord($creator[16]) ^ 1);
        $accumulator = new Par2PacketAccumulator;
        $before = $accumulator->exportState();

        try {
            $accumulator->append($descriptor.$creator);
            $this->fail('Corrupt Creator was silently skipped');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $accumulator->exportState());
            $this->assertFalse($accumulator->isComplete());
        }
    }

    public function test_unsigned_64_bit_size_bytes_survive_state_round_trip_exactly(): void
    {
        $body = str_repeat('i', 16).md5('whole file', true).md5('first 16K', true).pack('V2', 0xFFFFFFFE, 0xFFFFFFFF).'huge.mkv';
        $bytes = $this->packet(Par2Info::PACKET_FILEDESC, $body, str_repeat('s', 16));
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append($bytes);

        $state = Par2PacketAccumulator::fromState($accumulator->exportState())->exportState();

        $this->assertSame($bytes, base64_decode($state['packets'][0], true));
        $this->assertSame(pack('V2', 0xFFFFFFFE, 0xFFFFFFFF), substr(base64_decode($state['packets'][0], true), 112, 8));
    }

    public function test_oversized_metadata_header_is_rejected_before_its_body_is_buffered(): void
    {
        $header = Par2Info::PACKET_MARKER.pack('V2', 1028, 0).str_repeat("\0", 16).str_repeat('s', 16).Par2Info::PACKET_FILEDESC;
        $accumulator = new Par2PacketAccumulator(1024);
        $before = $accumulator->exportState();

        try {
            $accumulator->append($header);
            $this->fail('Oversized metadata header was accepted');
        } catch (LengthException) {
            $this->assertSame($before, $accumulator->exportState());
        }
    }

    public function test_partial_body_cannot_exceed_serialized_state_ceiling(): void
    {
        $packet = $this->descriptor(str_repeat('n', 500), str_repeat('i', 16), str_repeat('s', 16));
        $accumulator = new Par2PacketAccumulator(650);
        $before = $accumulator->exportState();

        try {
            $accumulator->append(substr($packet, 0, -1));
            $this->fail('Partial body exceeded its state ceiling');
        } catch (LengthException) {
            $this->assertSame($before, $accumulator->exportState());
        }
    }

    public function test_empty_state_and_parity_only_state_are_incomplete(): void
    {
        $accumulator = new Par2PacketAccumulator;
        $this->assertFalse($accumulator->isComplete());

        $accumulator->append($this->packet(Par2Info::PACKET_RECOVERY, pack('V', 1).'xxxx', str_repeat('s', 16)));

        $this->assertFalse($accumulator->isComplete());
        $this->assertSame([], $accumulator->filesBySet());
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('malformedStates')]
    public function test_malformed_restored_state_is_rejected(array $changes): void
    {
        $state = array_replace(['version' => 1, 'packets' => [], 'tail' => '', 'skip_remaining' => 0], $changes);

        $this->expectException(InvalidArgumentException::class);
        Par2PacketAccumulator::fromState($state);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedStates(): array
    {
        return [
            'negative skip' => [['skip_remaining' => -1]],
            'noninteger skip' => [['skip_remaining' => '1']],
            'overflow skip' => [['skip_remaining' => PHP_INT_MAX]],
            'tail during skip' => [['skip_remaining' => 4, 'tail' => base64_encode('PAR')]],
            'unknown version' => [['version' => 2]],
            'unknown key' => [['extra' => true]],
            'invalid base64 tail' => [['tail' => '#']],
            'noncanonical base64 tail' => [['tail' => 'UEFS\n']],
            'invalid prefix' => [['tail' => base64_encode('BAD')]],
            'short length in partial header' => [['tail' => base64_encode(Par2Info::PACKET_MARKER.pack('V2', 60, 0))]],
            'overflow length in partial header' => [['tail' => base64_encode(Par2Info::PACKET_MARKER.pack('V2', 64, 0x80000000))]],
            'associative inventory' => [['packets' => ['key' => 'UEFS']]],
        ];
    }

    public function test_rust_native_and_php_fallback_produce_identical_states_and_files(): void
    {
        $bytes = file_get_contents(__DIR__.'/../Fixtures/par2/rename-index.par2');
        $this->assertIsString($bytes);

        $native = new Par2PacketAccumulator;
        $native->append(substr($bytes, 0, 500), useNative: true);
        $php = new Par2PacketAccumulator;
        $php->append(substr($bytes, 0, 500), useNative: false);

        $this->assertSame($php->exportState(), $native->exportState());
        $this->assertSame($php->stateBytes(), $native->stateBytes());

        $nativeResumed = Par2PacketAccumulator::fromState($native->exportState(), 262144, useNative: true);
        $phpResumed = Par2PacketAccumulator::fromState($php->exportState(), 262144, useNative: false);

        $nativeResumed->append(substr($bytes, 500), useNative: true);
        $phpResumed->append(substr($bytes, 500), useNative: false);

        $this->assertSame($phpResumed->exportState(), $nativeResumed->exportState());
        $this->assertSame($phpResumed->filesBySet(), $nativeResumed->filesBySet());
        $this->assertSame($phpResumed->descriptors(), $nativeResumed->descriptors());
        $this->assertSame($phpResumed->isComplete(), $nativeResumed->isComplete());
        $this->assertSame($phpResumed->hasCompleteMainInventory(), $nativeResumed->hasCompleteMainInventory());
    }

    /** @return list<string> */
    private function fixturePackets(): array
    {
        $bytes = file_get_contents(__DIR__.'/../Fixtures/par2/rename-index.par2');
        $this->assertIsString($bytes);
        $packets = [];
        for ($offset = 0; $offset < strlen($bytes);) {
            $length = unpack('V', substr($bytes, $offset + 8, 4))[1];
            $packets[] = substr($bytes, $offset, $length);
            $offset += $length;
        }

        return $packets;
    }

    private function main(string $id, string $set): string
    {
        return $this->packet(Par2Info::PACKET_MAIN, pack('V3', 8, 0, 1).$id, $set);
    }

    private function descriptor(string $name, string $id, string $set, int $size = 9): string
    {
        return $this->packet(Par2Info::PACKET_FILEDESC, $id.md5('whole file', true).md5('first 16K', true).pack('V2', $size & 0xFFFFFFFF, intdiv($size, 0x100000000)).$name, $set);
    }

    private function packet(string $type, string $body, string $set): string
    {
        $body = str_pad($body, (int) (ceil(strlen($body) / 4) * 4), "\0");
        $hashed = $set.$type.$body;

        return Par2Info::PACKET_MARKER.pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed;
    }
}
