<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Data;

use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;

/** Observed nominations only; guarded writes must freshly establish SQL authority. */
final readonly class FilenamePass
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_fixing_query_pass(uint32_t mode, const char *input, size_t len, int64_t first, int64_t second, char *out, size_t cap);
        C;

    /** Negative ABI results retain the exact PHP implementation. */
    private static function queryPassNative(int $mode, ?string $input, int $first = 0, int $second = 0, int $needed = 4096): ?string
    {
        if ($input === null || strlen($input) > intdiv(PHP_INT_MAX - 4096, 8)) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max($needed, strlen($input) * 8 + 4096), $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_name_fixing_query_pass($mode, $input, strlen($input), $first, $second, $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $fields */
    private static function queryPassFields(array $fields): ?string
    {
        $input = '';
        foreach ($fields as $field) {
            if (strlen($field) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($field)).$field;
        }

        return $input;
    }

    public const PREDB_IDENTITY_POLICY = 'b81be20cc5e56957ea810f455372bc36a7cf70e6e72f08e53743047afaf8f479';

    /**
     * Ordered nomination observations, not a claim to the full persisted inventory.
     *
     * @param  list<string>  $filenames
     * @param  list<array{filename: string, match: array<string, mixed>|null, evidence: bool, complete: bool}>  $lookups
     */
    public function __construct(public array $filenames, public array $lookups, public ?string $localRejectionPolicy = null, private bool $useRustFfi = false)
    {
        if ($localRejectionPolicy !== null && preg_match('/\A[0-9a-f]{64}\z/D', $localRejectionPolicy) !== 1) {
            throw new InvalidArgumentException('Invalid local rejection policy.');
        }
        if (! array_is_list($filenames) || ! array_is_list($lookups) || count($filenames) !== count($lookups)) {
            throw new InvalidArgumentException('Filename observations and lookups must be aligned lists.');
        }
        foreach ($filenames as $index => $filename) {
            $lookup = $lookups[$index];
            if (! is_string($filename) || ! is_array($lookup)
                || ($lookup['filename'] ?? null) !== $filename
                || ! array_key_exists('match', $lookup)
                || ! is_bool($lookup['complete'] ?? null) || ! is_bool($lookup['evidence'] ?? null)) {
                throw new InvalidArgumentException('Invalid filename observation.');
            }
            $match = $lookup['match'];
            if ($match !== null && (! is_array($match)
                || PredbSearchDocument::filenameCandidate(['_id' => $match['id'] ?? null, '_source' => $match]) === null)) {
                throw new InvalidArgumentException('Invalid filename observation candidate.');
            }
        }
    }

    public function complete(): bool
    {
        return ($this->useRustFfi ? $this->completeNative() : null) ?? $this->completePhp();
    }

    private function completeNative(): ?bool
    {
        $flags = '';
        foreach ($this->lookups as $lookup) {
            $flags .= chr((int) $lookup['complete'] | ($lookup['match'] !== null ? 2 : 0));
        }
        $raw = self::queryPassNative(9, $flags);

        return $raw === null ? null : $raw === '1';
    }

    public function completePhp(): bool
    {
        foreach ($this->lookups as $lookup) {
            if (! $lookup['complete']) {
                return false;
            }
        }

        return true;
    }

    public function hasMatches(): bool
    {
        return ($this->useRustFfi ? $this->hasMatchesNative() : null) ?? $this->hasMatchesPhp();
    }

    private function hasMatchesNative(): ?bool
    {
        $flags = '';
        foreach ($this->lookups as $lookup) {
            $flags .= chr((int) $lookup['complete'] | ($lookup['match'] !== null ? 2 : 0));
        }
        $raw = self::queryPassNative(10, $flags);

        return $raw === null ? null : $raw === '1';
    }

    public function hasMatchesPhp(): bool
    {
        foreach ($this->lookups as $lookup) {
            if ($lookup['match'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{filename: string, match: array<string, mixed>}> */
    /** @return list<array{filename: string, match: array<string, mixed>}> */
    public function uniqueMatches(): array
    {
        return ($this->useRustFfi ? $this->uniqueMatchesNative() : null) ?? $this->uniqueMatchesPhp();
    }

    /** @return list<array{filename: string, match: array<string, mixed>}>|null */
    private function uniqueMatchesNative(): ?array
    {
        $fields = [];
        foreach ($this->lookups as $lookup) {
            $fields[] = $lookup['complete'] ? '1' : '0';
            $fields[] = $lookup['match'] === null ? '' : (int) $lookup['match']['id'].':'.PredbSearchDocument::exactValue((string) $lookup['match']['title']);
        }
        $raw = self::queryPassNative(11, self::queryPassFields($fields));
        if ($raw === null) {
            return null;
        }
        $selected = [];
        foreach ($raw === '' ? [] : explode(',', $raw) as $index) {
            $lookup = $this->lookups[(int) $index] ?? null;
            if ($lookup === null || $lookup['match'] === null) {
                return null;
            }
            $selected[] = ['filename' => $lookup['filename'], 'match' => $lookup['match']];
        }

        return $selected;
    }

    /** @return list<array{filename: string, match: array<string, mixed>}> */
    public function uniqueMatchesPhp(): array
    {
        if (! $this->completePhp()) {
            return [];
        }
        $selected = [];
        $identity = null;
        foreach ($this->lookups as $lookup) {
            $match = $lookup['match'];
            if ($match === null) {
                continue;
            }
            $current = (int) $match['id'].':'.PredbSearchDocument::exactValue((string) $match['title']);
            if ($identity !== null && $identity !== $current) {
                return [];
            }
            $identity = $current;
            $selected[] = ['filename' => $lookup['filename'], 'match' => $match];
        }

        return $selected;
    }
}
