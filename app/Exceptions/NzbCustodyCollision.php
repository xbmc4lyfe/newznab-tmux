<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class NzbCustodyCollision extends RuntimeException
{
    public const string FFI_CDEF = NfoPayloadRejected::FFI_CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function __construct(public readonly string $attempt, public readonly ?string $recoveredFilename, public readonly string $privateDirectory)
    {
        parent::__construct('A replacement input entered private custody; retained for explicit restaging.');
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** @return array{attempt: string, recovered_filename: ?string, private_directory: string, recoverable: bool, restage_required: bool} */
    public function custodyRecord(): array
    {
        return self::normalizeCustodyRecord($this->attempt, $this->recoveredFilename, $this->privateDirectory);
    }

    /**
     * Normalize a custody collision for reporting: the trimmed attempt, the recovered file's trimmed final path
     * segment (null when absent or empty), and the private directory's final segment after trimming whitespace and
     * trailing slashes. It is recoverable only when all three are non-empty; restaging is always explicit.
     *
     * @return array{attempt: string, recovered_filename: ?string, private_directory: string, recoverable: bool, restage_required: bool}
     */
    public static function normalizeCustodyRecord(string $attempt, ?string $recoveredFilename, string $privateDirectory): array
    {
        return self::usesRustFfi() ? self::normalizeCustodyRecordNative($attempt, $recoveredFilename, $privateDirectory)
            : self::normalizeCustodyRecordPhp($attempt, $recoveredFilename, $privateDirectory);
    }

    /** @return array{attempt: string, recovered_filename: ?string, private_directory: string, recoverable: bool, restage_required: bool} */
    public static function normalizeCustodyRecordPhp(string $attempt, ?string $recoveredFilename, string $privateDirectory): array
    {
        $attempt = trim($attempt);
        $recovered = $recoveredFilename === null ? '' : self::finalSegment(trim($recoveredFilename));
        $directory = self::finalSegment(rtrim(trim($privateDirectory), '/'));

        return [
            'attempt' => $attempt,
            'recovered_filename' => $recovered === '' ? null : $recovered,
            'private_directory' => $directory,
            'recoverable' => $attempt !== '' && $recovered !== '' && $directory !== '',
            'restage_required' => true,
        ];
    }

    /** @return array{attempt: string, recovered_filename: ?string, private_directory: string, recoverable: bool, restage_required: bool} */
    public static function normalizeCustodyRecordNative(string $attempt, ?string $recoveredFilename, string $privateDirectory): array
    {
        $recovered = $recoveredFilename ?? '';
        $slots = NfoPayloadRejected::nativeSlots(NfoPayloadRejected::evaluateNative(1,
            pack('q4', \strlen($attempt), (int) ($recoveredFilename !== null), \strlen($recovered), \strlen($privateDirectory)).$attempt.$recovered.$privateDirectory), 8);
        if ($slots !== null && self::validRange($attempt, $slots[0], $slots[1]) && \in_array($slots[2], [0, 1], true)
            && self::validRange($recovered, $slots[3], $slots[4]) && ($slots[2] === 1) === ($slots[4] > 0)
            && self::validRange($privateDirectory, $slots[5], $slots[6]) && \in_array($slots[7], [0, 1], true)) {
            return [
                'attempt' => substr($attempt, $slots[0], $slots[1]),
                'recovered_filename' => $slots[2] === 1 ? substr($recovered, $slots[3], $slots[4]) : null,
                'private_directory' => substr($privateDirectory, $slots[5], $slots[6]),
                'recoverable' => $slots[7] === 1,
                'restage_required' => true,
            ];
        }

        return self::normalizeCustodyRecordPhp($attempt, $recoveredFilename, $privateDirectory);
    }

    private static function finalSegment(string $value): string
    {
        $slash = strrpos($value, '/');

        return $slash === false ? $value : substr($value, $slash + 1);
    }

    private static function validRange(string $value, int $start, int $length): bool
    {
        return $start >= 0 && $length >= 0 && $start <= \strlen($value) - $length;
    }
}
