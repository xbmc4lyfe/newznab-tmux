<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;

class NfoProcessor
{
    public const string FFI_CDEF = CandidateRepository::FFI_CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private NfoService $nfo;

    public function __construct(NfoService $nfo)
    {
        $this->nfo = $nfo;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Process NFO files if enabled by settings.
     */
    public function process(NNTPService $nntp, string $groupID = '', string $guidChar = ''): void
    {
        if ((int) app(ConfigurationProvider::class)->postProcessing()->lookupNfo === 1) {
            $metadata = app(ConfigurationProvider::class)->metadata();
            $lookups = self::lookupFlags($metadata->movieLookup->value, $metadata->tvLookup->value);
            $this->nfo->processNfoFiles($nntp, $groupID, $guidChar, $lookups['imdb'], $lookups['tv']);
        }
    }

    /**
     * Which metadata lookups NFO processing runs: any enabled lookup mode (non-zero) turns its lookup on.
     *
     * @return array{imdb: bool, tv: bool}
     */
    public static function lookupFlags(int $movieLookup, int $tvLookup): array
    {
        return self::usesRustFfi() ? self::lookupFlagsNative($movieLookup, $tvLookup) : self::lookupFlagsPhp($movieLookup, $tvLookup);
    }

    /** @return array{imdb: bool, tv: bool} */
    public static function lookupFlagsPhp(int $movieLookup, int $tvLookup): array
    {
        return ['imdb' => (bool) $movieLookup, 'tv' => (bool) $tvLookup];
    }

    /** @return array{imdb: bool, tv: bool} */
    public static function lookupFlagsNative(int $movieLookup, int $tvLookup): array
    {
        $slots = CandidateRepository::nativeSlots(CandidateRepository::evaluateNative(6, pack('q2', $movieLookup, $tvLookup)), 1);
        if ($slots !== null && $slots[0] >= 0 && $slots[0] <= 3) {
            return ['imdb' => ($slots[0] & 1) === 1, 'tv' => ($slots[0] & 2) === 2];
        }

        return self::lookupFlagsPhp($movieLookup, $tvLookup);
    }
}
