<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PopulateAniListService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxPopulateAniDB extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_populate_anidb_resolve_mode(
        uint32_t info,
        uint32_t kind,
        int64_t int_value,
        double float_value,
        const char *str_ptr,
        size_t str_len,
        uint32_t *out_has_id,
        int64_t *out_id
    );
    CDEF;

    /**
     * Rust stays opt-in: the FFI round trip is slower than these PHP helpers (< 1.5x); see
     * tests/Unit/RustPostProcessTvAniDbSteamCommandsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /** Rust mode codes, indexed by `nntmux_populate_anidb_resolve_mode()` result. */
    private const array MODES = ['single', 'all', 'missing_info'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:populate-anidb
    {--info : Populate info only}
    {--anilistid : Populate tables for specific AniList ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate AniList table (replaces AniDB)';

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return array{mode: 'single'|'all'|'missing_info', anilist_id: ?int}
     */
    public static function resolvePopulateMode(bool $info, mixed $anilistId): array
    {
        return self::$useRustFfi ? self::resolvePopulateModeNative($info, $anilistId) : self::resolvePopulateModePhp($info, $anilistId);
    }

    /**
     * `single` when `--info` has a numeric AniList id, `all` for `--info` alone, otherwise `missing_info`.
     *
     * @return array{mode: 'single'|'all'|'missing_info', anilist_id: ?int}
     */
    public static function resolvePopulateModePhp(bool $info, mixed $anilistId): array
    {
        if ($info && is_numeric($anilistId)) {
            return ['mode' => 'single', 'anilist_id' => (int) $anilistId];
        }

        return ['mode' => $info ? 'all' : 'missing_info', 'anilist_id' => null];
    }

    /**
     * @return array{mode: 'single'|'all'|'missing_info', anilist_id: ?int}
     */
    public static function resolvePopulateModeNative(bool $info, mixed $anilistId): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolvePopulateModePhp($info, $anilistId);
        }

        [$kind, $intValue, $floatValue, $text] = match (true) {
            \is_int($anilistId) => [1, $anilistId, 0.0, ''],
            \is_float($anilistId) => [2, 0, $anilistId, ''],
            \is_string($anilistId) => [3, 0, 0.0, $anilistId],
            default => [0, 0, 0.0, ''],
        };

        try {
            $hasId = $ffi->new('uint32_t[1]');
            $id = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $mode = (int) $ffi->nntmux_populate_anidb_resolve_mode($info ? 1 : 0, $kind, $intValue, $floatValue, $text, \strlen($text), $hasId, $id);
            if (! isset(self::MODES[$mode])) {
                return self::resolvePopulateModePhp($info, $anilistId);
            }

            return ['mode' => self::MODES[$mode], 'anilist_id' => (int) $hasId[0] === 1 ? (int) $id[0] : null];
        } catch (Throwable) {
            return self::resolvePopulateModePhp($info, $anilistId);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $palist = new PopulateAniListService;

        $resolved = self::resolvePopulateMode((bool) $this->option('info'), $this->option('anilistid'));
        if ($resolved['mode'] === 'single') {
            $palist->populateTable('info', $resolved['anilist_id'] ?? '');
        } elseif ($resolved['mode'] === 'all') {
            $palist->populateTable('info');
        } else {
            $this->error('Please specify --info option');

            return;
        }

        $this->info('AniList tables populated with requested data');
    }

    /**
     * Runs a buffer-writing export, appending the scratch buffer and its capacity; `null` when it declines.
     */
    private static function callBufferFfi(FFI $ffi, int $minBytes, string $symbol, mixed ...$args): ?string
    {
        $cap = 0;
        $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, $minBytes), $cap);
        if ($buf === null) {
            return null;
        }

        $rc = (int) $ffi->$symbol(...[...$args, $buf, $cap]);
        if ($rc < 0) {
            return null;
        }

        return $rc > 0 ? FFI::string($buf, $rc) : '';
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
