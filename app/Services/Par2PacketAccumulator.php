<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Par2FileHashEvidence;
use App\Support\RustPipelineLibrary;
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

    public function append(string $bytes, bool $useNative = true): void
    {
        if ($useNative && $this->consumeNative($bytes)) {
            return;
        }

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
    public static function fromState(array $state, int $maxStateBytes = 262144, bool $useNative = true): self
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
        if (self::encodedStateBytes($state['packets'], $state['tail'], $state['skip_remaining']) > $maxStateBytes) {
            throw new LengthException('PAR2 metadata state exceeds byte ceiling');
        }
        $decodedChunks = [];
        foreach ($state['packets'] as $packet) {
            if (! is_string($packet)) {
                throw new InvalidArgumentException('Invalid PAR2 packet inventory');
            }
            $decodedChunks[] = self::decode($packet);
        }
        $decodedTail = self::decode($state['tail']);
        if ($useNative) {
            $decodedChunks[] = $decodedTail;
            $result->append(implode('', $decodedChunks), useNative: true);
        } else {
            foreach ($decodedChunks as $decodedPacket) {
                $result->append($decodedPacket, useNative: false);
            }
            $result->append($decodedTail, useNative: false);
        }
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
        $bytes = 54 + strlen((string) $this->skipRemaining);
        if ($this->tail !== '') {
            $tailB64 = base64_encode($this->tail);
            $bytes += strlen($tailB64) + substr_count($tailB64, '/');
        }
        $count = count($this->packets);
        if ($count > 0) {
            $bytes += $count * 2 + ($count - 1);
            foreach ($this->packets as $packet) {
                $b64 = base64_encode($packet);
                $bytes += strlen($b64) + substr_count($b64, '/');
            }
        }

        return $bytes;
    }

    public function isComplete(): bool
    {
        if ($this->tail !== '' || $this->skipRemaining !== 0 || $this->packets === []) {
            return false;
        }
        foreach ($this->packetsBySet() as $setPackets) {
            if (self::setPacketsHaveUnresolvedDescriptors($setPackets)) {
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
        foreach ($this->packetsBySet() as $setPackets) {
            $hasMain = false;
            foreach ($setPackets as $packet) {
                if (substr($packet, 48, 16) === Par2Info::PACKET_MAIN) {
                    $hasMain = true;
                    break;
                }
            }
            if (! $hasMain || self::setPacketsHaveUnresolvedDescriptors($setPackets)) {
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
        foreach ($this->packetsBySet() as $set => $setPackets) {
            $ret = [];
            $offset = 0;
            foreach ($setPackets as $packet) {
                $pktLen = strlen($packet);
                $nextOffset = $offset + $pktLen;
                $type = substr($packet, 48, 16);
                if ($type === Par2Info::PACKET_FILEDESC) {
                    $fileId = bin2hex(substr($packet, 64, 16));
                    if (! isset($ret[$fileId])) {
                        $lenParts = unpack('Vlow/Vhigh', substr($packet, 112, 8));
                        $fileLength = $lenParts['low'] + ($lenParts['high'] * 0x100000000);
                        $ret[$fileId] = [
                            'name' => rtrim(substr($packet, 120), "\0"),
                            'size' => $fileLength,
                            'hash' => bin2hex(substr($packet, 80, 16)),
                            'hash_16K' => bin2hex(substr($packet, 96, 16)),
                            'blocks' => 0,
                            'next_offset' => $nextOffset,
                            'size_decimal' => Par2FileHashEvidence::littleEndianSize(substr($packet, 112, 8)),
                        ];
                    }
                } elseif ($type === Par2Info::PACKET_FILEVER) {
                    $fileId = bin2hex(substr($packet, 64, 16));
                    if (empty($ret[$fileId]['blocks'])) {
                        $ret[$fileId]['blocks'] = intdiv($pktLen - 80, 20);
                    }
                }
                $offset = $nextOffset;
            }
            $offset = 0;
            foreach ($setPackets as $packet) {
                $pktLen = strlen($packet);
                $nextOffset = $offset + $pktLen;
                if (substr($packet, 48, 16) === Par2Info::PACKET_FILEDESC) {
                    $fileId = bin2hex(substr($packet, 64, 16));
                    if (! isset($ret[$fileId]['name'])) {
                        $lenParts = unpack('Vlow/Vhigh', substr($packet, 112, 8));
                        $fileLength = $lenParts['low'] + ($lenParts['high'] * 0x100000000);
                        $ret[$fileId] = [
                            'name' => rtrim(substr($packet, 120), "\0"),
                            'size' => $fileLength,
                            'hash' => bin2hex(substr($packet, 80, 16)),
                            'hash_16K' => bin2hex(substr($packet, 96, 16)),
                            'blocks' => $ret[$fileId]['blocks'] ?? 0,
                            'next_offset' => $nextOffset,
                            'size_decimal' => Par2FileHashEvidence::littleEndianSize(substr($packet, 112, 8)),
                        ];
                    } else {
                        $ret[$fileId]['name'] = rtrim(substr($packet, 120), "\0");
                        $ret[$fileId]['size_decimal'] = Par2FileHashEvidence::littleEndianSize(substr($packet, 112, 8));
                    }
                }
                $offset = $nextOffset;
            }
            $files[$set] = $ret;
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

    private function consumeNative(string $bytes): bool
    {
        $res = RustPipelineLibrary::par2ScanPackets(
            implode('', $this->packets),
            $this->tail,
            $this->skipRemaining,
            $this->maxStateBytes,
            $bytes
        );
        if ($res === null) {
            return false;
        }
        if ($res['status'] === -2) {
            throw new InvalidArgumentException('Invalid PAR2 packet stream');
        }
        if ($res['status'] === -3) {
            throw new LengthException('PAR2 metadata state exceeds byte ceiling');
        }

        $payload = $res['payload'];
        /** @var array{skip: int, tailLen: int, newCount: int}|false $hdr */
        $hdr = unpack('Pskip/VtailLen/VnewCount', substr($payload, 0, 16));
        if (! is_array($hdr)) {
            return false;
        }

        $tailLen = $hdr['tailLen'];
        $newCount = $hdr['newCount'];
        $this->skipRemaining = $hdr['skip'];
        $this->tail = $tailLen > 0 ? substr($payload, 16, $tailLen) : '';

        $off = 16 + $tailLen;
        for ($i = 0; $i < $newCount; $i++) {
            /** @var array{low: int, high: int} $lenParts */
            $lenParts = unpack('Vlow/Vhigh', substr($payload, $off + 8, 8));
            $pktLen = $lenParts['high'] * 0x100000000 + $lenParts['low'];
            $this->packets[] = substr($payload, $off, $pktLen);
            $off += $pktLen;
        }

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    private function packetsBySet(): array
    {
        $sets = [];
        foreach ($this->packets as $packet) {
            $set = bin2hex(substr($packet, 32, 16));
            $sets[$set][] = $packet;
        }

        return $sets;
    }

    /**
     * @param  list<string>  $setPackets
     */
    private static function setPacketsHaveUnresolvedDescriptors(array $setPackets): bool
    {
        $required = [];
        $described = [];
        foreach ($setPackets as $packet) {
            $type = substr($packet, 48, 16);
            if ($type === Par2Info::PACKET_MAIN) {
                $len = strlen($packet);
                for ($off = 76; $off < $len; $off += 16) {
                    $required[substr($packet, $off, 16)] = true;
                }
            } elseif ($type === Par2Info::PACKET_FILEVER) {
                $required[substr($packet, 64, 16)] = true;
            } elseif ($type === Par2Info::PACKET_FILEDESC) {
                $fileId = substr($packet, 64, 16);
                $required[$fileId] = true;
                if (! array_key_exists($fileId, $described)) {
                    $name = rtrim(substr($packet, 120), "\0");
                    $described[$fileId] = trim($name) !== '';
                }
            }
        }
        if ($described === []) {
            return true;
        }
        foreach ($required as $fileId => $_) {
            if (! ($described[$fileId] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $encodedPackets
     */
    private static function encodedStateBytes(array $encodedPackets, string $encodedTail, int $skipRemaining): int
    {
        $bytes = 54 + strlen((string) $skipRemaining);
        if ($encodedTail !== '') {
            $bytes += strlen($encodedTail) + substr_count($encodedTail, '/');
        }
        $count = count($encodedPackets);
        if ($count > 0) {
            $bytes += $count * 2 + ($count - 1);
            foreach ($encodedPackets as $pkt) {
                if (is_string($pkt)) {
                    $bytes += strlen($pkt) + substr_count($pkt, '/');
                }
            }
        }

        return $bytes;
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
