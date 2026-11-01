<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Par2FileHashEvidence;
use dariusiii\rarinfo\Par2Info;
use InvalidArgumentException;
use LengthException;

/**
 * A restartable PAR2 metadata stream. Recovery payloads never enter the inventory.
 * Appends are transactional; callers may retry the same input after an exception.
 */
final class Par2PacketAccumulator
{
    /** @var list<string> */
    private array $packets = [];

    private string $tail = '';

    private int $skipRemaining = 0;

    public function __construct(private readonly int $maxStateBytes = 262144)
    {
        $this->assertStateLimit();
    }

    public function append(string $bytes): void
    {
        $next = clone $this;
        $next->consume($bytes);
        $this->packets = $next->packets;
        $this->tail = $next->tail;
        $this->skipRemaining = $next->skipRemaining;
    }

    /** @return array{version: 1, packets: list<string>, tail: string, skip_remaining: int} */
    public function exportState(): array
    {
        return [
            'version' => 1,
            'packets' => array_map(base64_encode(...), $this->packets),
            'tail' => base64_encode($this->tail),
            'skip_remaining' => $this->skipRemaining,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromState(array $state, int $maxStateBytes = 262144): self
    {
        $keys = array_keys($state);
        sort($keys);
        if ($keys !== ['packets', 'skip_remaining', 'tail', 'version']
            || $state['version'] !== 1
            || ! is_array($state['packets']) || ! array_is_list($state['packets'])
            || ! is_string($state['tail'])
            || ! is_int($state['skip_remaining'])
            || $state['skip_remaining'] < 0 || $state['skip_remaining'] > PHP_INT_MAX - 64
            || ($state['skip_remaining'] > 0 && $state['tail'] !== '')) {
            throw new InvalidArgumentException('Invalid PAR2 accumulator state');
        }
        $result = new self($maxStateBytes);
        if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > $maxStateBytes) {
            throw new LengthException('PAR2 metadata state exceeds byte ceiling');
        }
        foreach ($state['packets'] as $packet) {
            if (! is_string($packet)) {
                throw new InvalidArgumentException('Invalid PAR2 packet inventory');
            }
            $result->append(self::decode($packet));
        }
        $result->append(self::decode($state['tail']));
        $result->skipRemaining = $state['skip_remaining'];
        $canonical = ['version' => $state['version'], 'packets' => $state['packets'], 'tail' => $state['tail'], 'skip_remaining' => $state['skip_remaining']];
        if ($result->exportState() !== $canonical) {
            throw new InvalidArgumentException('Noncanonical PAR2 accumulator state');
        }
        $result->assertStateLimit();

        return $result;
    }

    public function stateBytes(): int
    {
        return strlen(json_encode($this->exportState(), JSON_THROW_ON_ERROR));
    }

    public function isComplete(): bool
    {
        if ($this->tail !== '' || $this->skipRemaining !== 0 || $this->packets === []) {
            return false;
        }
        $fileList = new Par2FileList;
        foreach ($this->parsersBySet() as $parser) {
            if ($fileList->hasUnresolvedDescriptors($parser)) {
                return false;
            }
        }

        return true;
    }

    /** A complete Main inventory can stop before reading a recognized recovery payload. */
    public function hasCompleteMainInventory(): bool
    {
        if ($this->tail !== '' || $this->packets === []) {
            return false;
        }
        $mainSets = [];
        foreach ($this->packets as $packet) {
            if (substr($packet, 48, 16) === Par2Info::PACKET_MAIN) {
                $mainSets[bin2hex(substr($packet, 32, 16))] = true;
            }
        }
        $fileList = new Par2FileList;
        foreach ($this->parsersBySet() as $set => $parser) {
            if (! isset($mainSets[$set]) || $fileList->hasUnresolvedDescriptors($parser)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse each recovery set independently to prevent vendor file-ID deduplication
     * from merging descriptions belonging to different sets.
     *
     * @return array<string, Par2Info>
     */
    public function parsersBySet(): array
    {
        $sets = [];
        foreach ($this->packets as $packet) {
            $set = bin2hex(substr($packet, 32, 16));
            $sets[$set] = ($sets[$set] ?? '').$packet;
        }
        $parsers = [];
        foreach ($sets as $set => $bytes) {
            $parsers[$set] = $this->parse($bytes);
        }

        return $parsers;
    }

    /** @return array<string, array<string, array{name?: string, size?: int|float, size_decimal?: string, hash?: string, hash_16K?: string, blocks: int, next_offset?: int}>> */
    public function filesBySet(): array
    {
        $files = [];
        $fileList = new Par2FileList;
        foreach ($this->parsersBySet() as $set => $parser) {
            $files[$set] = $fileList->getFiles($parser);
        }
        foreach ($this->packets as $packet) {
            if (substr($packet, 48, 16) === Par2Info::PACKET_FILEDESC) {
                $set = bin2hex(substr($packet, 32, 16));
                $fileId = bin2hex(substr($packet, 64, 16));
                $files[$set][$fileId]['name'] = rtrim(substr($packet, 120), "\0");
                $files[$set][$fileId]['size_decimal'] = Par2FileHashEvidence::littleEndianSize(substr($packet, 112, 8));
            }
        }

        return $files;
    }

    /**
     * Project accepted FileDesc packets without filename or file-ID deduplication.
     * IDs, hashes and validated packet checksums are lowercase hex; filename bytes
     * include the original NUL padding and may contain non-UTF-8 data. Rejected
     * conflicting identities are not accepted evidence and never appear here.
     *
     * @return list<array{recovery_set_id: string, file_id: string, hash: string, hash_16K: string, size_decimal: string, filename_bytes: string, packet_checksum: string}>
     */
    public function descriptors(): array
    {
        $descriptors = [];
        foreach ($this->packets as $packet) {
            if (substr($packet, 48, 16) !== Par2Info::PACKET_FILEDESC) {
                continue;
            }
            $descriptors[] = [
                'recovery_set_id' => bin2hex(substr($packet, 32, 16)),
                'file_id' => bin2hex(substr($packet, 64, 16)),
                'hash' => bin2hex(substr($packet, 80, 16)),
                'hash_16K' => bin2hex(substr($packet, 96, 16)),
                'size_decimal' => Par2FileHashEvidence::littleEndianSize(substr($packet, 112, 8)),
                'filename_bytes' => substr($packet, 120),
                'packet_checksum' => bin2hex(substr($packet, 16, 16)),
            ];
        }

        return $descriptors;
    }

    private function consume(string $bytes): void
    {
        $offset = 0;
        $available = strlen($bytes);
        while ($offset < $available) {
            if ($this->skipRemaining > 0) {
                $skip = min($this->skipRemaining, $available - $offset);
                $this->skipRemaining -= $skip;
                $offset += $skip;

                continue;
            }
            if (strlen($this->tail) < 64) {
                $take = min(64 - strlen($this->tail), $available - $offset);
                $this->tail .= substr($bytes, $offset, $take);
                $offset += $take;
                $prefixLength = min(8, strlen($this->tail));
                if (substr($this->tail, 0, $prefixLength) !== substr(Par2Info::PACKET_MARKER, 0, $prefixLength)) {
                    throw new InvalidArgumentException('Invalid PAR2 packet marker');
                }
                if (strlen($this->tail) >= 16) {
                    $this->packetLength($this->tail);
                }
                if (strlen($this->tail) < 64) {
                    $this->assertStateLimit();

                    break;
                }
            }
            $length = $this->packetLength($this->tail);
            $type = substr($this->tail, 48, 16);
            if (! in_array($type, [
                Par2Info::PACKET_MAIN, Par2Info::PACKET_FILEDESC, Par2Info::PACKET_FILEVER,
                Par2Info::PACKET_RECOVERY, Par2Info::PACKET_CREATOR,
                Par2Info::PACKET_FILENAME_UC, Par2Info::PACKET_COMMENT_ASCII, Par2Info::PACKET_COMMENT_UC,
                Par2Info::PACKET_INPUT_BLOCK, Par2Info::PACKET_RECOVERY_VER,
                Par2Info::PACKET_PACKED_MAIN, Par2Info::PACKET_PACKED_RECOVERY,
            ], true)) {
                throw new InvalidArgumentException('Unknown PAR2 packet type');
            }
            if (in_array($type, [Par2Info::PACKET_RECOVERY, Par2Info::PACKET_INPUT_BLOCK, Par2Info::PACKET_PACKED_RECOVERY], true)) {
                $this->tail = '';
                $this->skipRemaining = $length - 64;

                continue;
            }
            if ($length > $this->maxStateBytes) {
                throw new LengthException('PAR2 metadata packet exceeds byte ceiling');
            }
            $take = min($length - strlen($this->tail), $available - $offset);
            $this->tail .= substr($bytes, $offset, $take);
            $offset += $take;
            if (strlen($this->tail) === $length) {
                $packet = $this->tail;
                $this->tail = '';
                $this->retain($packet);
            }
            $this->assertStateLimit();
        }
        $this->assertStateLimit();
    }

    private function packetLength(string $header): int
    {
        $parts = unpack('Vlow/Vhigh', substr($header, 8, 8));
        if (! is_array($parts) || $parts['high'] > 0x7FFFFFFF) {
            throw new InvalidArgumentException('PAR2 packet length exceeds supported integer range');
        }
        $length = $parts['high'] * 0x100000000 + $parts['low'];
        if (! is_int($length) || $length < 64 || $length % 4 !== 0) {
            throw new InvalidArgumentException('Invalid PAR2 packet length');
        }

        return $length;
    }

    private function retain(string $packet): void
    {
        $type = substr($packet, 48, 16);
        $bodyLength = strlen($packet) - 64;
        if (($type === Par2Info::PACKET_MAIN && ($bodyLength < 12 || ($bodyLength - 12) % 16 !== 0))
            || ($type === Par2Info::PACKET_FILEDESC && $bodyLength < 56)
            || ($type === Par2Info::PACKET_FILEVER && ($bodyLength < 16 || ($bodyLength - 16) % 20 !== 0))) {
            throw new InvalidArgumentException('Malformed PAR2 metadata packet body');
        }
        if ($type === Par2Info::PACKET_MAIN) {
            $count = unpack('V', substr($packet, 72, 4));
            if (! is_array($count) || $count[1] > intdiv($bodyLength - 12, 16)) {
                throw new InvalidArgumentException('PAR2 Main count exceeds packet identities');
            }
        }
        $this->parse($packet);
        if (! in_array($type, [Par2Info::PACKET_MAIN, Par2Info::PACKET_FILEDESC, Par2Info::PACKET_FILEVER], true)) {
            return;
        }
        $identity = substr($packet, 32, 32).($type === Par2Info::PACKET_MAIN ? '' : substr($packet, 64, 16));
        foreach ($this->packets as $existing) {
            if ($existing === $packet) {
                return;
            }
            $existingType = substr($existing, 48, 16);
            $existingIdentity = substr($existing, 32, 32).($existingType === Par2Info::PACKET_MAIN ? '' : substr($existing, 64, 16));
            if ($identity === $existingIdentity) {
                throw new InvalidArgumentException('Conflicting PAR2 metadata identity');
            }
        }
        $this->packets[] = $packet;
    }

    private function parse(string $bytes): Par2Info
    {
        $parser = new class extends Par2Info
        {
            protected $maxFilenameLength = PHP_INT_MAX;

            /** @param array<mixed> $packet */
            protected function processPacket(&$packet): void
            {
                parent::processPacket($packet);
                if ($packet['head_type'] === self::PACKET_FILEDESC) {
                    $offset = $this->offset;
                    $this->seek((int) $packet['offset'] + 120);
                    $packet['file_name'] = rtrim($this->read((int) $packet['head_length'] - 120), "\0");
                    $this->seek($offset);
                }
            }
        };
        $parser->setMaxReadBytes(strlen($bytes));
        if (! $parser->setData($bytes) || $parser->error !== '') {
            throw new InvalidArgumentException('Invalid PAR2 metadata checksum or packet');
        }

        return $parser;
    }

    private function assertStateLimit(): void
    {
        if ($this->stateBytes() > $this->maxStateBytes) {
            throw new LengthException('PAR2 metadata state exceeds byte ceiling');
        }
    }

    private static function decode(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new InvalidArgumentException('Invalid PAR2 state base64');
        }

        return $decoded;
    }
}
