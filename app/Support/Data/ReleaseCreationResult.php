<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * Data transfer object for release creation results.
 */
#[TypeScript]
final class ReleaseCreationResult extends Data
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_creation_values(uint32_t mode, const int64_t *input, size_t len, int64_t *out, size_t cap);
        C;

    // PHP remains the default until paired common/uncommon mean and p99 qualify.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  list<int>  $values
     * @return list<int>|null
     */
    private static function calculateNative(array $values): ?array
    {
        try {
            $ffi = self::ffi();
            if ($ffi === null) {
                return null;
            }
            $input = $ffi->new('int64_t[2]');
            foreach ($values as $index => $value) {
                $input[$index] = $value;
            }
            $out = $ffi->new('int64_t[4]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_creation_values(1, $input, 2, $out, 4);
            if ($written !== 4) {
                return null;
            }
            $result = [];
            for ($index = 0; $index < 4; $index++) {
                $result[] = (int) $out[$index];
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public int $added = 0,
        public int $dupes = 0,
    ) {}

    public function total(): int
    {
        return self::$useRustFfi ? $this->totalNative() : $this->totalPhp();
    }

    public function totalNative(): int
    {
        return self::calculateNative([$this->added, $this->dupes])[0] ?? $this->totalPhp();
    }

    public function totalPhp(): int
    {
        return $this->added + $this->dupes;
    }

    public function hasAddedReleases(): bool
    {
        return self::$useRustFfi ? $this->hasAddedReleasesNative() : $this->hasAddedReleasesPhp();
    }

    public function hasAddedReleasesNative(): bool
    {
        $values = self::calculateNative([$this->added, $this->dupes]);

        return $values === null ? $this->hasAddedReleasesPhp() : $values[1] === 1;
    }

    public function hasAddedReleasesPhp(): bool
    {
        return $this->added > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{added: int, dupes: int}
     */
    public static function normalizeResultArray(array $data): array
    {
        return self::$useRustFfi ? self::normalizeResultArrayNative($data) : self::normalizeResultArrayPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{added: int, dupes: int}
     */
    public static function normalizeResultArrayNative(array $data): array
    {
        $normalized = self::normalizeResultArrayPhp($data);
        $values = self::calculateNative([$normalized['added'], $normalized['dupes']]);

        return $values === null ? $normalized : ['added' => $values[2], 'dupes' => $values[3]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{added: int, dupes: int}
     */
    public static function normalizeResultArrayPhp(array $data): array
    {
        return ['added' => (int) ($data['added'] ?? 0), 'dupes' => (int) ($data['dupes'] ?? 0)];
    }

    /** @return array{added: int, dupes: int} */
    public function resultArray(): array
    {
        return self::$useRustFfi ? $this->resultArrayNative() : $this->resultArrayPhp();
    }

    /** @return array{added: int, dupes: int} */
    public function resultArrayNative(): array
    {
        return self::normalizeResultArrayNative(['added' => $this->added, 'dupes' => $this->dupes]);
    }

    /** @return array{added: int, dupes: int} */
    public function resultArrayPhp(): array
    {
        return ['added' => $this->added, 'dupes' => $this->dupes];
    }
}
