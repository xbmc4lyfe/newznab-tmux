<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2FileList;
use dariusiii\rarinfo\Par2Info;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Par2FileListTest extends TestCase
{
    /** @param list<string> $order */
    #[DataProvider('packetOrders')]
    public function test_valid_packet_order_preserves_file_metadata(array $order): void
    {
        $name = 'Example.Movie.2020.1080p.WEB-DL.x264-GRP.mkv';
        $data = str_repeat('fixture payload ', 1200);
        $info = $this->parse($this->fixture(['first' => ['name' => $name, 'data' => $data]], $order, 16384));

        $files = (new Par2FileList)->getFiles($info);

        $this->assertCount(1, $files);
        $file = array_values($files)[0];
        $this->assertSame($name, $file['name']);
        $this->assertSame(strlen($data), $file['size']);
        $this->assertSame(md5($data), $file['hash']);
        $this->assertSame(md5(substr($data, 0, 16384)), $file['hash_16K']);
        $this->assertNotSame($file['hash'], $file['hash_16K']);
        $this->assertSame(2, $file['blocks']);
    }

    /** @return array<string, array{list<string>}> */
    public static function packetOrders(): array
    {
        return [
            'checksums before description' => [['first.ifsc', 'first.desc']],
            'description before checksums' => [['first.desc', 'first.ifsc']],
        ];
    }

    /** @param list<string> $order */
    #[DataProvider('packetOrders')]
    public function test_large_descriptor_length_preserves_high_word_and_distinct_hashes(array $order): void
    {
        $name = 'Large.Movie.2026.2160p.WEB-DL.x265-GRP.mkv';
        $data = str_repeat('fixture payload ', 1200);
        $length = 12 * 1024 * 1024 * 1024 + 123;
        $bytes = $this->fixture([
            'first' => ['name' => $name, 'data' => $data, 'length' => $length],
        ], $order, 16384);
        $this->assertLessThan(1024, strlen($bytes));
        $info = $this->parse($bytes);
        $descriptions = array_values(array_filter($info->getPackets(), static fn (array $packet): bool => $packet['head_type'] === Par2Info::PACKET_FILEDESC));

        $this->assertSame(3, $descriptions[0]['file_length_high']);
        $this->assertSame($length, $descriptions[0]['file_length']);
        $files = array_values((new Par2FileList)->getFiles($info));

        $this->assertCount(1, $files);
        $this->assertSame($name, $files[0]['name']);
        $this->assertSame($length, $files[0]['size']);
        $this->assertSame(md5($data), $files[0]['hash']);
        $this->assertSame(md5(substr($data, 0, 16384)), $files[0]['hash_16K']);
        $this->assertNotSame($files[0]['hash'], $files[0]['hash_16K']);
    }

    public function test_checksum_corrupt_large_descriptor_is_rejected(): void
    {
        $bytes = $this->fixture([
            'first' => ['name' => 'large.mkv', 'data' => 'hash fixture', 'length' => 12 * 1024 * 1024 * 1024 + 123],
        ], ['first.ifsc', 'first.desc']);
        $validInfo = $this->parse($bytes);
        $descriptions = array_values(array_filter($validInfo->getPackets(), static fn (array $packet): bool => $packet['head_type'] === Par2Info::PACKET_FILEDESC));
        $lengthOffset = $descriptions[0]['offset'] + 64 + 48;
        $bytes[$lengthOffset] = chr(ord($bytes[$lengthOffset]) ^ 1);
        $info = new Par2Info;

        $this->assertFalse($info->setData($bytes));
        $this->assertStringContainsString('Packet failed checksum', $info->error);
        $this->assertSame([], (new Par2FileList)->getFiles($info));
    }

    public function test_multiple_file_ids_keep_their_own_descriptions_and_block_counts(): void
    {
        $info = $this->parse($this->fixture([
            'first' => ['name' => 'first.mkv', 'data' => 'first payload'],
            'second' => ['name' => 'second.flac', 'data' => str_repeat('x', 25)],
        ], ['first.ifsc', 'second.desc', 'first.desc', 'second.ifsc']));

        $files = array_values((new Par2FileList)->getFiles($info));
        $byName = array_column($files, null, 'name');

        $this->assertCount(2, $files);
        $this->assertSame(13, $byName['first.mkv']['size']);
        $this->assertSame(md5('first payload'), $byName['first.mkv']['hash_16K']);
        $this->assertSame(2, $byName['first.mkv']['blocks']);
        $this->assertSame(25, $byName['second.flac']['size']);
        $this->assertSame(md5(str_repeat('x', 25)), $byName['second.flac']['hash_16K']);
        $this->assertSame(4, $byName['second.flac']['blocks']);
    }

    public function test_description_without_ifsc_keeps_metadata_and_zero_blocks(): void
    {
        $info = $this->parse($this->fixture(['first' => ['name' => 'first.mkv', 'data' => 'data']], ['first.desc']));

        $files = array_values((new Par2FileList)->getFiles($info));

        $this->assertSame('first.mkv', $files[0]['name']);
        $this->assertSame(4, $files[0]['size']);
        $this->assertSame(md5('data'), $files[0]['hash_16K']);
        $this->assertSame(0, $files[0]['blocks']);
    }

    public function test_orphan_ifsc_keeps_its_count_without_inventing_a_name(): void
    {
        $info = $this->parse($this->fixture(['first' => ['name' => 'absent.mkv', 'data' => 'first payload']], ['first.ifsc']));

        $files = array_values((new Par2FileList)->getFiles($info));

        $this->assertSame([['blocks' => 2]], $files);
    }

    public function test_duplicate_packets_keep_first_description_offset_and_do_not_double_count_blocks(): void
    {
        $info = $this->parse($this->fixture(['first' => ['name' => 'first.mkv', 'data' => 'first payload']], [
            'first.ifsc', 'first.desc', 'first.desc', 'first.ifsc',
        ]));
        $descriptions = array_values(array_filter($info->getPackets(true), static fn (array $packet): bool => $packet['head_type'] === Par2Info::PACKET_FILEDESC));

        $files = array_values((new Par2FileList)->getFiles($info));

        $this->assertCount(1, $files);
        $this->assertSame('first.mkv', $files[0]['name']);
        $this->assertSame(2, $files[0]['blocks']);
        $this->assertSame($descriptions[0]['next_offset'], $files[0]['next_offset']);
        $this->assertNotSame($descriptions[1]['next_offset'], $files[0]['next_offset']);
    }

    public function test_parser_errors_do_not_expose_preceding_valid_descriptors(): void
    {
        $bytes = $this->fixture(['first' => ['name' => 'first.mkv', 'data' => 'data']], ['first.desc']);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $info = new Par2Info;

        $this->assertFalse($info->setData($bytes));
        $this->assertNotSame('', $info->error);
        $this->assertNotEmpty($info->getFileList());
        $this->assertSame([], (new Par2FileList)->getFiles($info));
    }

    private function parse(string $bytes): Par2Info
    {
        $info = new Par2Info;
        $this->assertTrue($info->setData($bytes));
        $this->assertSame('', $info->error);

        return $info;
    }

    /**
     * Metadata-only length overrides exercise descriptor decoding without allocating a large payload.
     *
     * @param  array<string, array{name: string, data: string, length?: int}>  $files
     * @param  list<string>  $order
     */
    private function fixture(array $files, array $order, int $sliceSize = 8): string
    {
        $bodies = [];
        $ids = [];
        foreach ($files as $key => $file) {
            $fileLength = $file['length'] ?? strlen($file['data']);
            $length = pack('V2', $fileLength & 0xFFFFFFFF, intdiv($fileLength, 0x100000000));
            $hash16 = md5(substr($file['data'], 0, 16384), true);
            $id = md5($hash16.$length.$file['name'], true);
            $ids[] = $id;
            $bodies[$key.'.desc'] = [Par2Info::PACKET_FILEDESC, $id.md5($file['data'], true).$hash16.$length.$file['name']];
            $checksums = $id;
            foreach (str_split($file['data'], $sliceSize) as $slice) {
                $padded = str_pad($slice, $sliceSize, "\0");
                $checksums .= md5($padded, true).pack('V', crc32($padded));
            }
            $bodies[$key.'.ifsc'] = [Par2Info::PACKET_FILEVER, $checksums];
        }
        usort($ids, static fn (string $left, string $right): int => strcmp(strrev($left), strrev($right)));
        $main = pack('V2', $sliceSize, 0).pack('V', count($ids)).implode('', $ids);
        $setId = md5($main, true);
        $bytes = $this->packet(Par2Info::PACKET_MAIN, $main, $setId);
        foreach ($order as $key) {
            $bytes .= $this->packet($bodies[$key][0], $bodies[$key][1], $setId);
        }

        return $bytes.$this->packet(Par2Info::PACKET_CREATOR, 'Offline fixture https://example.test/', $setId);
    }

    private function packet(string $type, string $body, string $setId): string
    {
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $hashed = $setId.$type.$body;

        return Par2Info::PACKET_MARKER.pack('V2', 64 + strlen($body), 0).md5($hashed, true).$hashed;
    }
}
