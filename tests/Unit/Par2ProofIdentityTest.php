<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\Par2ProofIdentity;
use dariusiii\rarinfo\Par2Info;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Par2ProofIdentityTest extends TestCase
{
    public function test_repeated_metadata_and_different_recovery_packets_share_identity(): void
    {
        $packets = $this->packets();
        $volume = array_merge(array_reverse($packets), $packets, [[
            'head_type' => Par2Info::PACKET_RECOVERY, 'head_set_id' => str_repeat('a', 32), 'exponent' => 9,
        ]]);
        $this->assertNotNull(Par2ProofIdentity::fromPackets($packets));
        $this->assertSame(Par2ProofIdentity::fromPackets($packets), Par2ProofIdentity::fromPackets($volume));
    }

    #[DataProvider('metadataChanges')]
    public function test_changed_proof_metadata_cannot_reuse_identity(int $packet, string $field, mixed $value): void
    {
        $baseline = $this->packets();
        $changed = $baseline;
        $changed[$packet][$field] = $value;
        $this->assertNotSame(Par2ProofIdentity::fromPackets($baseline), Par2ProofIdentity::fromPackets($changed));
    }

    public static function metadataChanges(): iterable
    {
        yield 'block size' => [0, 'block_size', 128];
        yield 'main inventory' => [0, 'rec_file_ids', [[1 => str_repeat('c', 32)]]];
        yield 'filename' => [1, 'file_name', 'other.bin'];
        yield 'size' => [1, 'file_length', 128];
        yield 'full hash' => [1, 'file_hash', str_repeat('d', 32)];
        yield 'prefix hash' => [1, 'file_hash_16K', str_repeat('e', 32)];
        yield 'block checksum' => [2, 'block_checksums', [['md5' => str_repeat('f', 32), 'crc32' => 0]]];
        yield 'mixed set' => [2, 'head_set_id', str_repeat('b', 32)];
        yield 'raw main bytes' => [0, 'head_hash', str_repeat('e', 32)];
        yield 'raw descriptor bytes hidden by filename trimming' => [1, 'head_hash', str_repeat('f', 32)];
        yield 'raw IFSC bytes' => [2, 'head_hash', str_repeat('a', 32)];
    }

    public function test_ambiguous_or_incomplete_metadata_is_ineligible(): void
    {
        $packets = $this->packets();
        $conflict = $packets[1];
        $conflict['file_length']++;
        $this->assertNull(Par2ProofIdentity::fromPackets([...$packets, $conflict]));
        $this->assertNull(Par2ProofIdentity::fromPackets(array_slice($packets, 0, 2)));
        $this->assertNull(Par2ProofIdentity::fromPackets(array_slice($packets, 1)));
        $this->assertNull(Par2ProofIdentity::fromPackets([...$packets, ['head_type' => 'unsupported', 'head_set_id' => str_repeat('a', 32)]]));
        $packets[0]['rec_file_ids'][] = $packets[0]['rec_file_ids'][0];
        $packets[0]['rec_file_count']++;
        $this->assertNull(Par2ProofIdentity::fromPackets($packets));
    }

    /** @return list<array<string, mixed>> */
    private function packets(): array
    {
        $set = str_repeat('a', 32);
        $id = str_repeat('b', 32);

        return [
            ['head_type' => Par2Info::PACKET_MAIN, 'head_set_id' => $set, 'head_hash' => str_repeat('1', 32), 'block_size' => 64,
                'rec_file_count' => 1, 'rec_file_ids' => [[1 => $id]]],
            ['head_type' => Par2Info::PACKET_FILEDESC, 'head_set_id' => $set, 'head_hash' => str_repeat('2', 32), 'file_id' => $id,
                'file_name' => 'payload.bin', 'file_length' => 64, 'file_hash' => str_repeat('c', 32), 'file_hash_16K' => str_repeat('c', 32)],
            ['head_type' => Par2Info::PACKET_FILEVER, 'head_set_id' => $set, 'head_hash' => str_repeat('3', 32), 'file_id' => $id,
                'block_checksums' => [['md5' => str_repeat('c', 32), 'crc32' => 123]]],
        ];
    }
}
