<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Support\RustPipelineLibrary;
use Throwable;

final class ProbeResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $responseTimeMs,
        public readonly ?IncidentImpactEnum $impact,
        public readonly string $reason,
        public readonly array $metadata = [],
    ) {}

    public const string FFI_CDEF = 'ssize_t nntmux_w346_configuration_status(uint8_t operation, const int64_t *input, size_t input_count, int64_t *output, size_t output_capacity);';

    // These small computations retain PHP until caller-inclusive benchmarks qualify Rust.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * @param  list<int>  $input
     * @return list<int>|null
     */
    private static function rustValues(int $operation, array $input, int $count): ?array
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('w346', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $length = count($input);
            $source = $ffi->new("int64_t[{$length}]");
            $output = $ffi->new("int64_t[{$count}]");
            foreach ($input as $index => $value) {
                $source[$index] = $value;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w346_configuration_status($operation, $source, $length, $output, $count);
            if ($written !== $count) {
                return null;
            }
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                $values[] = (int) $output[$index];
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{ok: bool, response_time_ms: int, impact: string|null, reason: string, metadata: array<string, mixed>} */
    public function summary(): array
    {
        if (self::usesRustFfi()) {
            $impactCode = $this->impact === null ? -1 : array_search($this->impact, IncidentImpactEnum::cases(), true);
            if ($impactCode !== false && ($values = self::rustValues(5, [(int) $this->ok, $this->responseTimeMs, $impactCode], 3)) !== null) {
                $impacts = [null, 'none', 'minor', 'major', 'critical'];
                if (array_key_exists($values[2] + 1, $impacts)) {
                    return ['ok' => (bool) $values[0], 'response_time_ms' => $values[1], 'impact' => $impacts[$values[2] + 1], 'reason' => $this->reason, 'metadata' => $this->metadata];
                }
            }
        }

        return $this->summaryPhp();
    }

    /** @return array{ok: bool, response_time_ms: int, impact: string|null, reason: string, metadata: array<string, mixed>} */
    public function summaryPhp(): array
    {
        return ['ok' => $this->ok, 'response_time_ms' => max(0, $this->responseTimeMs), 'impact' => $this->impact?->value, 'reason' => $this->reason, 'metadata' => $this->metadata];
    }
}
