<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Par2FileHashEvidence;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use LengthException;
use Throwable;

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

    /** The Wave 44.2 export shared by the accumulator, metadata parser, proof identity, payload identity and sanitizer helpers. */
    public const string W442_FFI_CDEF = <<<'C'
        int32_t nntmux_w442_par2_packet_metadata_proof_payload_sanitizer(
            uint32_t operation,
            uint32_t flags,
            const char *input,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string W442_FFI_DOMAIN = 'nfo_w442';

    /**
     * Default for the Wave 44.2 toggle only (summarizePacketBatch); false because common p99 in the paired benchmark in
     * tests/Unit/RustPar2PacketMetadataProofPayloadSanitizerW44ParityTest.php fell to 1.31-1.42x in three of six runs.
     */
    public const bool W442_RUST_FFI_DEFAULT = false;

    public const int W442_MAX_INPUT = 16777216;

    /** Recovery-slice packet types whose payloads the accumulator skips. */
    private const array W442_RECOVERY_TYPES = [Par2Info::PACKET_RECOVERY, Par2Info::PACKET_INPUT_BLOCK, Par2Info::PACKET_PACKED_RECOVERY];

    /** Recognized packet types the accumulator retains without a body check. */
    private const array W442_AUXILIARY_TYPES = [Par2Info::PACKET_CREATOR, Par2Info::PACKET_FILENAME_UC, Par2Info::PACKET_COMMENT_ASCII,
        Par2Info::PACKET_COMMENT_UC, Par2Info::PACKET_RECOVERY_VER, Par2Info::PACKET_PACKED_MAIN];

    private static ?bool $useW442RustFfi = null;

    /** @var array<string, FFI|false> resolved Wave 44.2 handles per explicit library path ('' for the default search) */
    private static array $w442Ffi = [];

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

    /** Force the Wave 44.2 helper onto Rust (true) or PHP (false); null restores W442_RUST_FFI_DEFAULT. */
    public static function useW442RustFfi(?bool $enable = true): void
    {
        self::$useW442RustFfi = $enable;
    }

    public static function usesW442RustFfi(): bool
    {
        return self::$useW442RustFfi ?? self::W442_RUST_FFI_DEFAULT;
    }

    public static function resetW442RustFfi(): void
    {
        self::$useW442RustFfi = null;
    }

    /**
     * Run one Wave 44.2 operation over `[0, $rows]`, the row slots and the byte tail; null whenever the library, input
     * or answer is unusable, so callers fall back to PHP.
     *
     * @param  list<int>  $slots
     * @return list<int>|null
     */
    public static function evaluateW442Native(int $operation, int $rows, array $slots, string $tail, int $outSlots): ?array
    {
        try {
            if ($operation < 0 || $operation > 0xFFFFFFFF || $outSlots < 1 || $rows < 0
                || 16 + \count($slots) * 8 + \strlen($tail) > self::W442_MAX_INPUT) {
                return null;
            }
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: '';
            $ffi = self::$w442Ffi[$path] ??= RustPipelineLibrary::resolveDomain(self::W442_FFI_DOMAIN, self::W442_FFI_CDEF, $path === '' ? null : $path) ?? false;
            if ($ffi === false) {
                return null;
            }
            $input = pack('q*', 0, $rows, ...$slots).$tail;
            $needed = $outSlots * 8;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $output = RustPipelineLibrary::obtainScratch($ffi, $needed, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w442_par2_packet_metadata_proof_payload_sanitizer($operation, 0, $input, \strlen($input), $output, $capacity);
                if (! \is_int($written) || $written === -1 || $written > $capacity) {
                    return null;
                }
                if ($written < 0) {
                    // Too small: retry once with the reported capacity.
                    $needed = -$written;

                    continue;
                }
                if ($written !== $outSlots * 8) {
                    return null;
                }
                $values = array_values(unpack('q'.$outSlots, FFI::string($output, $written)) ?: []);

                return \count($values) === $outSlots ? $values : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Inspect raw PAR2 packets as the accumulator's header and body checks see them: non-strings are invalid; strings
     * shorter than a header are truncated; then a wrong marker, a declared length that is unsupported, not a multiple
     * of four or not the string length; then Main, FileDesc and IFSC packets (malformed when their bodies fail
     * retain()'s shape checks), recovery slices, other recognized (auxiliary) and unknown types. Bytes sum every
     * string; sets counts the distinct recovery-set IDs of accepted packets.
     *
     * @param  array<array-key, mixed>  $packets
     * @return array{packets: int, invalid: int, truncated: int, bad_marker: int, bad_length: int, main: int, filedesc: int, filever: int, recovery: int, auxiliary: int, unknown: int, malformed: int, bytes: int, sets: int}
     */
    public static function summarizePacketBatch(array $packets): array
    {
        return (self::usesW442RustFfi() ? self::summarizePacketBatchNative($packets) : null)
            ?? (self::usesW56RustFfi() ? self::summarizePacketBatchW56($packets) : null)
            ?? self::summarizePacketBatchPhp($packets);
    }

    /**
     * @param  array<array-key, mixed>  $packets
     * @return array{packets: int, invalid: int, truncated: int, bad_marker: int, bad_length: int, main: int, filedesc: int, filever: int, recovery: int, auxiliary: int, unknown: int, malformed: int, bytes: int, sets: int}
     */
    public static function summarizePacketBatchPhp(array $packets): array
    {
        $summary = ['packets' => \count($packets), 'invalid' => 0, 'truncated' => 0, 'bad_marker' => 0, 'bad_length' => 0, 'main' => 0,
            'filedesc' => 0, 'filever' => 0, 'recovery' => 0, 'auxiliary' => 0, 'unknown' => 0, 'malformed' => 0, 'bytes' => 0, 'sets' => 0];
        $sets = [];
        foreach ($packets as $packet) {
            if (! \is_string($packet)) {
                $summary['invalid']++;

                continue;
            }
            $length = \strlen($packet);
            $summary['bytes'] += $length;
            if ($length < 64) {
                $summary['truncated']++;

                continue;
            }
            if (substr($packet, 0, 8) !== Par2Info::PACKET_MARKER) {
                $summary['bad_marker']++;

                continue;
            }
            /** @var array{low: int, high: int} $parts */
            $parts = unpack('Vlow/Vhigh', $packet, 8);
            if ($parts['high'] > 0x7FFFFFFF || $parts['low'] % 4 !== 0 || $length !== $parts['high'] * 0x100000000 + $parts['low']) {
                $summary['bad_length']++;

                continue;
            }
            $type = substr($packet, 48, 16);
            $body = $length - 64;
            if ($type === Par2Info::PACKET_MAIN) {
                $key = $body < 12 || ($body - 12) % 16 !== 0 || unpack('V', $packet, 72)[1] > intdiv($body - 12, 16) ? 'malformed' : 'main';
            } elseif ($type === Par2Info::PACKET_FILEDESC) {
                $key = $body < 56 ? 'malformed' : 'filedesc';
            } elseif ($type === Par2Info::PACKET_FILEVER) {
                $key = $body < 16 || ($body - 16) % 20 !== 0 ? 'malformed' : 'filever';
            } elseif (\in_array($type, self::W442_RECOVERY_TYPES, true)) {
                $key = 'recovery';
            } elseif (\in_array($type, self::W442_AUXILIARY_TYPES, true)) {
                $key = 'auxiliary';
            } else {
                $key = 'unknown';
            }
            $summary[$key]++;
            if ($key !== 'malformed' && $key !== 'unknown') {
                $sets[substr($packet, 32, 16)] = true;
            }
        }
        $summary['sets'] = \count($sets);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $packets
     * @return array{packets: int, invalid: int, truncated: int, bad_marker: int, bad_length: int, main: int, filedesc: int, filever: int, recovery: int, auxiliary: int, unknown: int, malformed: int, bytes: int, sets: int}|null
     */
    public static function summarizePacketBatchNative(array $packets): ?array
    {
        $slots = [];
        $tail = [];
        foreach ($packets as $packet) {
            if (\is_string($packet)) {
                // Only the header and Main count are inspected; payloads stay in PHP.
                $length = \strlen($packet);
                $slots[] = $length;
                $tail[] = $length > 76 ? substr($packet, 0, 76) : $packet;
            } else {
                $slots[] = -1;
            }
        }
        $values = self::evaluateW442Native(0, \count($packets), $slots, implode('', $tail), 14);
        if ($values === null) {
            return null;
        }
        [$count, $invalid, $truncated, $badMarker, $badLength, $main, $filedesc, $filever, $recovery, $auxiliary, $unknown, $malformed, $bytes, $sets] = $values;
        if ($count !== \count($packets) || min($values) < 0
            || $invalid + $truncated + $badMarker + $badLength + $main + $filedesc + $filever + $recovery + $auxiliary + $unknown + $malformed !== $count
            || $sets > $main + $filedesc + $filever + $recovery + $auxiliary) {
            return null;
        }

        return ['packets' => $count, 'invalid' => $invalid, 'truncated' => $truncated, 'bad_marker' => $badMarker, 'bad_length' => $badLength,
            'main' => $main, 'filedesc' => $filedesc, 'filever' => $filever, 'recovery' => $recovery, 'auxiliary' => $auxiliary,
            'unknown' => $unknown, 'malformed' => $malformed, 'bytes' => $bytes, 'sets' => $sets];
    }

    // ---------------------------------------------------------------------
    // Wave 56.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 2.73x, p99 2.24x; uncommon mean 2.17x, p99 1.93x); see RustTmdbTvMazeTvdbPar2AccumulatorCompactFpW56ParityTest. */
    public const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_FFI_CDEF = <<<'C'
        ssize_t nntmux_w564_par2_packet_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** summarizePacketBatchPhp() keys by W56 packet class (1-10). */
    private const array W56_CLASS_KEYS = [1 => 'truncated', 2 => 'bad_marker', 3 => 'bad_length', 4 => 'main', 5 => 'filedesc',
        6 => 'filever', 7 => 'recovery', 8 => 'auxiliary', 9 => 'unknown', 10 => 'malformed'];

    /**
     * Raw PAR2 packets classified as summarizePacketBatchPhp() does, one row each. Each packet
     * yields five words: the class (truncated 1, bad marker 2, bad length 3, Main 4, FileDesc
     * 5, IFSC 6, recovery 7, auxiliary 8, unknown 9, malformed 10), the Main slice size or
     * FileDesc file length (unpack('P'), else -1), the Main file count (else -1), the first
     * earlier header-valid (class 4-10) packet with the same packet MD5 (-1) and the first
     * accepted (class 4-8) packet of the same recovery set, itself when first (-1 when the
     * packet is not accepted).
     *
     * @param  list<string>  $packets
     * @return list<int>
     */
    public static function par2PacketRowBatch(array $packets): array
    {
        return self::usesW56RustFfi() ? self::par2PacketRowBatchNative($packets) : self::par2PacketRowBatchPhp($packets);
    }

    /**
     * @param  list<string>  $packets
     * @return list<int>
     */
    public static function par2PacketRowBatchNative(array $packets): array
    {
        self::w56Par2Columns($packets);
        $count = \count($packets);
        if ($count === 0) {
            return [];
        }

        // Full lengths plus only the first 120 bytes of each packet (header, Main slice size and
        // count, FileDesc length) cross; payloads stay in PHP.
        $lens = array_map(\strlen(...), $packets);
        $prefixes = $packets;
        foreach ($lens as $i => $length) {
            if ($length > 120) {
                $prefixes[$i] = substr($packets[$i], 0, 120);
            }
        }

        return self::w56Par2Call(implode('', $prefixes), pack('P*', ...$lens), $count, 5)
            ?? self::par2PacketRowBatchPhp($packets);
    }

    /**
     * @param  list<string>  $packets
     * @return list<int>
     */
    public static function par2PacketRowBatchPhp(array $packets): array
    {
        self::w56Par2Columns($packets);
        $out = [];
        $digests = [];
        $sets = [];
        foreach ($packets as $i => $packet) {
            $class = self::w56PacketClass($packet);
            [$size, $files, $duplicate, $set] = [-1, -1, -1, -1];
            if ($class === 4) {
                $size = unpack('P', $packet, 64)[1];
                $files = unpack('V', $packet, 72)[1];
            } elseif ($class === 5) {
                $size = unpack('P', $packet, 112)[1];
            }
            if ($class >= 4) {
                $digest = substr($packet, 16, 16);
                if (isset($digests[$digest])) {
                    $duplicate = $digests[$digest];
                } else {
                    $digests[$digest] = $i;
                }
            }
            if ($class >= 4 && $class <= 8) {
                $set = $sets[substr($packet, 32, 16)] ??= $i;
            }
            array_push($out, $class, $size, $files, $duplicate, $set);
        }

        return $out;
    }

    /** summarizePacketBatchPhp()'s classification of one packet as a W56 class (1-10). */
    private static function w56PacketClass(string $packet): int
    {
        $length = \strlen($packet);
        if ($length < 64) {
            return 1;
        }
        if (substr($packet, 0, 8) !== Par2Info::PACKET_MARKER) {
            return 2;
        }
        /** @var array{low: int, high: int} $parts */
        $parts = unpack('Vlow/Vhigh', $packet, 8);
        if ($parts['high'] > 0x7FFFFFFF || $parts['low'] % 4 !== 0 || $length !== $parts['high'] * 0x100000000 + $parts['low']) {
            return 3;
        }
        $type = substr($packet, 48, 16);
        $body = $length - 64;

        return match (true) {
            $type === Par2Info::PACKET_MAIN => $body < 12 || ($body - 12) % 16 !== 0 || unpack('V', $packet, 72)[1] > intdiv($body - 12, 16) ? 10 : 4,
            $type === Par2Info::PACKET_FILEDESC => $body < 56 ? 10 : 5,
            $type === Par2Info::PACKET_FILEVER => $body < 16 || ($body - 16) % 20 !== 0 ? 10 : 6,
            \in_array($type, self::W442_RECOVERY_TYPES, true) => 7,
            \in_array($type, self::W442_AUXILIARY_TYPES, true) => 8,
            default => 9,
        };
    }

    /**
     * summarizePacketBatch() from W56 packet rows: identical to summarizePacketBatchPhp().
     *
     * @param  array<array-key, mixed>  $packets
     * @return array{packets: int, invalid: int, truncated: int, bad_marker: int, bad_length: int, main: int, filedesc: int, filever: int, recovery: int, auxiliary: int, unknown: int, malformed: int, bytes: int, sets: int}
     */
    private static function summarizePacketBatchW56(array $packets): array
    {
        $strings = [];
        $bytes = 0;
        foreach ($packets as $packet) {
            if (\is_string($packet)) {
                $strings[] = $packet;
                $bytes += \strlen($packet);
            }
        }
        $summary = ['packets' => \count($packets), 'invalid' => \count($packets) - \count($strings), 'truncated' => 0, 'bad_marker' => 0,
            'bad_length' => 0, 'main' => 0, 'filedesc' => 0, 'filever' => 0, 'recovery' => 0, 'auxiliary' => 0, 'unknown' => 0,
            'malformed' => 0, 'bytes' => $bytes, 'sets' => 0];
        $rows = self::par2PacketRowBatchNative($strings);
        foreach ($strings as $i => $packet) {
            $summary[self::W56_CLASS_KEYS[$rows[$i * 5]]]++;
            $summary['sets'] += (int) ($rows[$i * 5 + 4] === $i);
        }

        return $summary;
    }

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W56 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('enabled');
        self::w56RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('enabled', $previous);
        }
    }

    /**
     * Equal-length list columns, or InvalidArgumentException.
     *
     * @param  array<array-key, mixed>  ...$columns
     */
    private static function w56Par2Columns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (! array_is_list($column) || \count($column) !== $count) {
                throw new InvalidArgumentException('W56 batch columns must be lists of equal length.');
            }
        }
    }

    /**
     * One FFI crossing for the batch: `$words` int64 words back per record. Null when the
     * library is missing or declines a malformed frame, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w56Par2Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
            $state = self::w56RustFfiState();
            $ffi = $state['ffi'];
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w56', self::W56_FFI_CDEF) ?? false;
                self::w56RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w56RustFfiState('out', $out);
                self::w56RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W56_FFI_CDEF.)
            $written = $ffi->nntmux_w564_par2_packet_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W56 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
