<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2FileList;
use dariusiii\rarinfo\Par2Info;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Par2DescriptorCompletenessTest extends TestCase
{
    public function test_complete_actual_inventory_is_independent_of_packet_order_and_duplicates(): void
    {
        $packets = $this->fixturePackets();
        $checksums = $descriptions = $other = [];
        foreach ($packets as $packet) {
            $type = substr($packet, 48, 16);
            if ($type === Par2Info::PACKET_FILEVER) {
                $checksums[] = $packet;
            } elseif ($type === Par2Info::PACKET_FILEDESC) {
                $descriptions[] = $packet;
            } else {
                $other[] = $packet;
            }
        }
        $info = $this->parse(implode('', [...$checksums, ...$descriptions, $descriptions[0], ...$other]));
        $list = new Par2FileList;

        $this->assertFalse($list->hasUnresolvedDescriptors($info));
        $files = $list->getFiles($info);
        $this->assertCount(10, $files);
        $this->assertCount(10, array_filter($files, static fn (array $file): bool => isset($file['name'])));
    }

    public function test_missing_checksums_do_not_make_named_descriptors_incomplete(): void
    {
        $packets = array_filter($this->fixturePackets(), static fn (string $packet): bool => substr($packet, 48, 16) !== Par2Info::PACKET_FILEVER);
        $info = $this->parse(implode('', $packets));

        $this->assertFalse((new Par2FileList)->hasUnresolvedDescriptors($info));
    }

    #[DataProvider('descriptorNames')]
    public function test_descriptor_only_inventory_keeps_full_64_bit_size_and_literal_name(string $name): void
    {
        $packet = $this->fixturePackets()[0];
        $length = 12 * 1024 * 1024 * 1024 + 123;
        $body = substr($packet, 64, 48).pack('V2', $length & 0xFFFFFFFF, intdiv($length, 0x100000000)).$name;
        $body = md5(substr($body, 32, 24).$name, true).substr($body, 16);
        $info = $this->parse($this->rewritePacket($packet, $body));
        $list = new Par2FileList;

        $this->assertFalse($list->hasUnresolvedDescriptors($info));
        $file = array_values($list->getFiles($info))[0];
        $this->assertSame($name, $file['name']);
        $this->assertSame($length, $file['size']);
    }

    /** @return array<string, array{string}> */
    public static function descriptorNames(): array
    {
        return ['ordinary filename' => ['Large.Movie.2026.2160p.WEB-DL.x265-GRP.mkv'], 'literal Unknown filename' => ['Unknown']];
    }

    public function test_blank_descriptor_name_is_unresolved_despite_the_parsers_placeholder(): void
    {
        $packet = $this->fixturePackets()[0];
        $info = $this->parse($this->rewritePacket($packet, substr($packet, 64, 56)));
        $list = new Par2FileList;

        $this->assertSame('Unknown', array_values($list->getFiles($info))[0]['name']);
        $this->assertTrue($list->hasUnresolvedDescriptors($info));
    }

    public function test_ifsc_without_main_or_description_still_requires_a_descriptor(): void
    {
        $packets = array_filter($this->fixturePackets(), static fn (string $packet): bool => substr($packet, 48, 16) === Par2Info::PACKET_FILEVER);
        $info = $this->parse(implode('', $packets));

        $this->assertTrue((new Par2FileList)->hasUnresolvedDescriptors($info));
    }

    #[DataProvider('retainMissingFileChecksums')]
    public function test_main_declared_file_stays_unresolved_with_or_without_its_ifsc(bool $retainChecksums): void
    {
        $packets = $this->fixturePackets();
        $missingId = substr($packets[0], 64, 16);
        $packets = array_filter($packets, static fn (string $packet): bool => substr($packet, 48, 16) !== Par2Info::PACKET_FILEDESC || substr($packet, 64, 16) !== $missingId);
        if (! $retainChecksums) {
            $packets = array_filter($packets, static fn (string $packet): bool => substr($packet, 48, 16) !== Par2Info::PACKET_FILEVER || substr($packet, 64, 16) !== $missingId);
        }
        $info = $this->parse(implode('', $packets));

        $this->assertTrue((new Par2FileList)->hasUnresolvedDescriptors($info));
    }

    /** @return array<string, array{bool}> */
    public static function retainMissingFileChecksums(): array
    {
        return ['orphan IFSC exists' => [true], 'only Main still declares file' => [false]];
    }

    public function test_unrecoverable_main_file_ids_also_require_their_descriptions(): void
    {
        $packets = $this->fixturePackets();
        $otherId = null;
        foreach ($packets as $key => $packet) {
            if (substr($packet, 48, 16) === Par2Info::PACKET_MAIN) {
                $body = substr($packet, 64);
                $otherId = substr($body, -16);
                $packets[$key] = $this->rewritePacket($packet, substr($body, 0, 8).pack('V', 9).substr($body, 12));
            }
        }
        $this->assertNotNull($otherId);
        $packets = array_filter($packets, static fn (string $packet): bool => ! in_array(substr($packet, 48, 16), [Par2Info::PACKET_FILEDESC, Par2Info::PACKET_FILEVER], true) || substr($packet, 64, 16) !== $otherId);
        $info = $this->parse(implode('', $packets));

        $this->assertTrue((new Par2FileList)->hasUnresolvedDescriptors($info));
    }

    public function test_description_from_another_recovery_set_cannot_satisfy_a_missing_file_id(): void
    {
        $packets = $this->fixturePackets();
        $missingDescription = array_shift($packets);
        $packets[] = $this->rewritePacket($missingDescription, substr($missingDescription, 64), md5('different recovery set', true));
        $info = $this->parse(implode('', $packets));

        $this->assertTrue((new Par2FileList)->hasUnresolvedDescriptors($info));
    }

    public function test_packet_checksum_error_cannot_report_a_complete_inventory(): void
    {
        $bytes = implode('', $this->fixturePackets());
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $info = new Par2Info;
        $this->assertFalse($info->setData($bytes));

        $this->assertTrue((new Par2FileList)->hasUnresolvedDescriptors($info));
    }

    /** @return list<string> */
    private function fixturePackets(): array
    {
        $data = (string) file_get_contents(dirname(__DIR__).'/Fixtures/par2/rename-index.par2');
        $packets = [];
        for ($offset = 0; $offset < strlen($data); $offset += $length) {
            $length = unpack('V', substr($data, $offset + 8, 4))[1];
            $packets[] = substr($data, $offset, $length);
        }

        return $packets;
    }

    private function rewritePacket(string $packet, string $body, ?string $setId = null): string
    {
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $header = ($setId ?? substr($packet, 32, 16)).substr($packet, 48, 16);

        return Par2Info::PACKET_MARKER.pack('V2', 64 + strlen($body), 0).md5($header.$body, true).$header.$body;
    }

    private function parse(string $bytes): Par2Info
    {
        $info = new Par2Info;
        $this->assertTrue($info->setData($bytes));
        $this->assertSame('', $info->error);

        return $info;
    }
}
