<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Services\NameFixing\Checkers\AppNameChecker;
use App\Services\NameFixing\Checkers\GameNameChecker;
use App\Services\NameFixing\Checkers\MovieNameChecker;
use App\Services\NameFixing\Checkers\TvNameChecker;
use App\Services\NameFixing\Contracts\NameCheckerInterface;
use App\Services\NameFixing\Data\NameFixResult;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Collection;

/**
 * Service for checking release names against multiple patterns.
 *
 * Orchestrates multiple name checkers (TV, Movie, Game, App) to find
 * potential name fixes for releases.
 */
class NameCheckerService
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_check_all_first(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_name_check_all_collect(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private static bool $ffiResolved = false;

    private static ?FFI $defaultFfi = null;

    private static ?CData $defaultBuf = null;

    private static int $defaultCap = 0;

    private bool $defaultCheckersUnmodified = true;

    /**
     * Collection of registered name checkers.
     *
     * @var Collection<int, NameCheckerInterface>
     */
    protected Collection $checkers;

    /**
     * Whether checkers are sorted by priority.
     */
    protected bool $sorted = false;

    // Fused caller measurements pass the 1.5x common and uncommon p99 gates.
    // Individual checker defaults remain PHP; fusion amortizes the shared scan.
    public function __construct(
        private readonly ?string $libraryPath = null,
        private readonly bool $useRust = true,
    ) {
        $this->checkers = new Collection;
        $this->registerDefaultCheckers();
        $this->defaultCheckersUnmodified = get_class($this) === self::class;
    }

    /**
     * Register the default name checkers.
     */
    protected function registerDefaultCheckers(): void
    {
        $this->addChecker(new TvNameChecker);
        $this->addChecker(new MovieNameChecker);
        $this->addChecker(new GameNameChecker);
        $this->addChecker(new AppNameChecker);
    }

    /**
     * Add a name checker to the service.
     */
    public function addChecker(NameCheckerInterface $checker): self
    {
        $this->defaultCheckersUnmodified = false;
        $this->checkers->push($checker);
        $this->sorted = false;

        return $this;
    }

    /**
     * Check a release name against all registered checkers.
     *
     * @param  object  $release  The release object
     * @param  string  $textstring  The text to check
     * @return NameFixResult|null The first matching result, or null if no match
     */
    public function check(object $release, string $textstring): ?NameFixResult
    {
        if ($this->defaultCheckersUnmodified && $this->useRust) {
            $raw = $this->checkNative($textstring, false);
            if ($raw !== false) {
                return $raw === '' ? null : $this->nativeResult($raw);
            }
        }
        $this->ensureSorted();

        foreach ($this->checkers as $checker) {
            $result = $checker->check($release, $textstring);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Check all registered checkers and return all matches.
     *
     * Useful for debugging or when you want to see all potential matches.
     *
     * @param  object  $release  The release object
     * @param  string  $textstring  The text to check
     * @return Collection<int, NameFixResult> All matching results
     */
    public function checkAll(object $release, string $textstring): Collection
    {
        if ($this->defaultCheckersUnmodified && $this->useRust) {
            $raw = $this->checkNative($textstring, true);
            if ($raw !== false) {
                $results = new Collection;
                for ($offset = 0, $length = strlen($raw); $offset < $length;) {
                    $patternEnd = strpos($raw, "\0", $offset + 2);
                    $matchEnd = strpos($raw, "\0", $patternEnd + 1);
                    $end = $matchEnd === false ? $length : $matchEnd;
                    $results->push($this->nativeResult(substr($raw, $offset, $end - $offset)));
                    $offset = $end + 1;
                }

                return $results;
            }
        }
        $this->ensureSorted();
        $results = new Collection;

        foreach ($this->checkers as $checker) {
            $result = $checker->check($release, $textstring);
            if ($result !== null) {
                $results->push($result);
            }
        }

        return $results;
    }

    /** Empty string means no match; false asks the caller to run PHP. */
    private function checkNative(string $input, bool $collect): string|false
    {
        try {
            if ($this->libraryPath !== null) {
                $ffi = RustPipelineLibrary::resolveDomain('name_checker_service', self::FFI_CDEF, $this->libraryPath);
            } else {
                if (! self::$ffiResolved) {
                    self::$defaultFfi = RustPipelineLibrary::resolveDomain('name_checker_service', self::FFI_CDEF);
                    if (self::$defaultFfi !== null) {
                        // Four maximum-length matches, pattern names, and separators.
                        self::$defaultBuf = RustPipelineLibrary::obtainScratch(self::$defaultFfi, 16640, self::$defaultCap);
                    }
                    self::$ffiResolved = true;
                }
                $ffi = self::$defaultFfi;
            }
            if ($ffi === null) {
                return false;
            }
            $cap = self::$defaultCap;
            $buf = $this->libraryPath === null ? self::$defaultBuf : RustPipelineLibrary::obtainScratch($ffi, 16640, $cap);
            if ($buf === null) {
                return false;
            }
            if ($collect) {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_name_check_all_collect($input, strlen($input), $buf, $cap);
            } else {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_name_check_all_first($input, strlen($input), $buf, $cap);
            }

            return $written >= 0 ? ($written === 0 ? '' : FFI::string($buf, $written)) : false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function nativeResult(string $raw): NameFixResult
    {
        [$pattern, $matched] = explode("\0", substr($raw, 2), 2);
        // Default registration order is also priority order. Keep formatting and
        // confidence in each checker, shared with its individual native path.
        /** @var TvNameChecker|MovieNameChecker|GameNameChecker|AppNameChecker $checker */
        $checker = $this->checkers[ord($raw[0])];

        return $checker->fromNativeMatch($pattern, $matched);
    }

    /**
     * Check using a specific checker by name.
     *
     * @param  string  $checkerName  The name of the checker to use
     * @param  object  $release  The release object
     * @param  string  $textstring  The text to check
     * @return NameFixResult|null The result if matched, null otherwise
     */
    public function checkWith(string $checkerName, object $release, string $textstring): ?NameFixResult
    {
        $checker = $this->checkers->first(
            fn (NameCheckerInterface $c) => strtolower($c->getName()) === strtolower($checkerName)
        );

        return $checker?->check($release, $textstring);
    }

    /**
     * Get all registered checkers.
     *
     * @return Collection<int, NameCheckerInterface>
     */
    public function getCheckers(): Collection
    {
        // The returned collection is mutable; subsequent calls must honor edits.
        $this->defaultCheckersUnmodified = false;
        $this->ensureSorted();

        return $this->checkers;
    }

    /**
     * Get checker statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->checkers->map(fn (NameCheckerInterface $checker) => [
            'name' => $checker->getName(),
            'priority' => $checker->getPriority(),
            'class' => get_class($checker),
        ])->toArray();
    }

    /**
     * Ensure checkers are sorted by priority.
     */
    protected function ensureSorted(): void
    {
        if (! $this->sorted) {
            $this->checkers = $this->checkers->sortBy(
                fn (NameCheckerInterface $checker) => $checker->getPriority()
            )->values();
            $this->sorted = true;
        }
    }

    // ---- Wave 58.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 7.67x/6.93x, uncommon 8.00x/8.36x); see RustConstrainedPredbFileNameNfoObfuscatedNameCheckerW58ParityTest. */
    private const bool W58_RUST_FFI_DEFAULT = true;

    private const string W58_FFI_CDEF = <<<'C'
        intptr_t nntmux_w58_name_checker_dispatch_plan_batch(const char *names, size_t names_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 58 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w58', self::W58_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w58RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planDispatchBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w58Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w58Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    private const array W58_TV_TOKENS = ['hdtv' => true, 'pdtv' => true, 'sdtv' => true];

    private const array W58_MOVIE_TOKENS = ['bluray' => true, 'bdrip' => true, 'brrip' => true, 'dvdrip' => true, 'webrip' => true,
        'webdl' => true, 'web' => true, 'remux' => true, 'x264' => true, 'x265' => true, 'hevc' => true, 'xvid' => true, '720p' => true,
        '1080p' => true, '2160p' => true];

    private const array W58_GAME_TOKENS = ['ps3' => true, 'ps4' => true, 'ps5' => true, 'psv' => true, 'xbox360' => true, 'xboxone' => true,
        'nsw' => true, 'wii' => true, 'wiiu' => true, 'nds' => true, 'codex' => true, 'skidrow' => true, 'plaza' => true, 'gog' => true,
        'fitgirl' => true, 'dodi' => true, 'flt' => true, 'razor1911' => true, 'reloaded' => true, 'cpy' => true, 'empress' => true,
        'tenoke' => true];

    private const array W58_APP_TOKENS = ['x64' => true, 'x86' => true, 'win32' => true, 'win64' => true, 'macos' => true, 'osx' => true,
        'linux' => true, 'keygen' => true, 'incl' => true, 'cracked' => true, 'portable' => true, 'multilingual' => true, 'retail' => true];

    /**
     * Batch dispatch plan over candidate names, tokenized as lowercase `[a-z0-9]` runs. Routes:
     * 1 TV (an `s\d{1,2}e\d{1,3}` or `\d{1,2}x\d{2,3}` token, or hdtv/pdtv/sdtv), 2 Movie (a
     * `(19|20)YY` token and a source/codec/resolution token), 4 Game (a platform or scene game
     * group token), 8 App (a platform/crack token or `v\d{1,3}`). Qualified: 8-255 bytes, two or
     * more tokens, an ASCII letter and no NUL byte. First: the lowest route of a qualified name
     * in checker priority order (TV, Movie, Game, App), else 4. Per name `routes | qualified << 4
     * | first << 5 | min(tokens, 0xFFFFFF) << 8 | crc32(lowercase name) << 32`; then the
     * dispatch count per checker, the qualified names, the qualified unrouted names and the
     * XXH64 digest of all earlier words.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planDispatchBatch(array $names): array
    {
        return self::usesW58RustFfi() ? self::planDispatchBatchNative($names) : self::planDispatchBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planDispatchBatchPhp(array $names): array
    {
        $out = [];
        $counts = [0, 0, 0, 0];
        $qualifiedTotal = $unrouted = 0;
        foreach ($names as $name) {
            $name = (string) $name;
            $lower = strtolower($name);
            $tokens = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $routes = 0;
            $year = $movie = false;
            foreach ($tokens as $token) {
                if (isset(self::W58_TV_TOKENS[$token]) || preg_match('/\A(?:s[0-9]{1,2}e[0-9]{1,3}|[0-9]{1,2}x[0-9]{2,3})\z/', $token) === 1) {
                    $routes |= 1;
                }
                $year = $year || preg_match('/\A(?:19|20)[0-9]{2}\z/', $token) === 1;
                $movie = $movie || isset(self::W58_MOVIE_TOKENS[$token]);
                if (isset(self::W58_GAME_TOKENS[$token])) {
                    $routes |= 4;
                }
                if (isset(self::W58_APP_TOKENS[$token]) || preg_match('/\Av[0-9]{1,3}\z/', $token) === 1) {
                    $routes |= 8;
                }
            }
            if ($year && $movie) {
                $routes |= 2;
            }
            $length = \strlen($name);
            $qualified = $length >= 8 && $length <= 255 && \count($tokens) >= 2
                && strpbrk($lower, 'abcdefghijklmnopqrstuvwxyz') !== false && ! str_contains($name, "\0");
            $first = 4;
            if ($qualified) {
                $qualifiedTotal++;
                if ($routes === 0) {
                    $unrouted++;
                } else {
                    $first = ($routes & 1) !== 0 ? 0 : (($routes & 2) !== 0 ? 1 : (($routes & 4) !== 0 ? 2 : 3));
                    $counts[$first]++;
                }
            }
            $out[] = $routes | (int) $qualified << 4 | $first << 5 | min(\count($tokens), 0xFFFFFF) << 8 | crc32($lower) << 32;
        }
        array_push($out, ...$counts);
        array_push($out, $qualifiedTotal, $unrouted);
        $out[] = self::w58Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a name holds a NUL byte or the
     * column is not a list.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planDispatchBatchNative(array $names): array
    {
        $count = \count($names);
        $words = $count + 7;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w58RustFfiState('out', $words);
        if ($state === null || ! array_is_list($names)) {
            return self::planDispatchBatchPhp($names);
        }
        $blob = implode("\0", $names);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_name_checker_dispatch_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }

        return self::w58Words($written, $out, $words) ?? self::planDispatchBatchPhp($names);
    }
}
