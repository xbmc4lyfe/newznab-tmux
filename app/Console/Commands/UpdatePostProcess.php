<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NNTPService;
use App\Services\PostProcessService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'update:postprocess')]
class UpdatePostProcess extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_update_postprocess_type_flags(const char *type_ptr, size_t type_len);
    ssize_t nntmux_update_postprocess_format_invalid_type(
        const char *type_ptr,
        size_t type_len,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /** `nntmux_update_postprocess_type_flags()` result bits. */
    private const int TYPE_FLAG_VALID = 1;

    private const int TYPE_FLAG_REQUIRES_NNTP = 2;

    /**
     * Rust stays opt-in: the FFI round trip is slower than these PHP helpers (< 1.5x); see
     * tests/Unit/RustPostProcessTvAniDbSteamCommandsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:postprocess
                            {type : Type (all, nfo, movies, tv, music, console, games, book, anime, additional, amazon)}
                            {echo? : Echo output (true/false, default: true)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post-process releases by type';

    /**
     * Valid types and whether they require NNTP.
     *
     * @var array<string, bool>
     */
    private const array VALID_TYPES = [
        'additional' => false,
        'all' => true,
        'amazon' => false,
        'anime' => false,
        'book' => false,
        'console' => false,
        'games' => false,
        'movies' => false,
        'music' => false,
        'nfo' => true,
        'tv' => false,
    ];

    public function __construct(
        private readonly PostProcessService $postProcessService
    ) {
        parent::__construct();
    }

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

    public static function isValidType(string $type): bool
    {
        return self::$useRustFfi ? self::isValidTypeNative($type) : self::isValidTypePhp($type);
    }

    public static function isValidTypePhp(string $type): bool
    {
        return array_key_exists($type, self::VALID_TYPES);
    }

    public static function isValidTypeNative(string $type): bool
    {
        $flags = self::typeFlagsNative($type);

        return $flags === null ? self::isValidTypePhp($type) : ($flags & self::TYPE_FLAG_VALID) !== 0;
    }

    public static function requiresNntpConnection(string $type): bool
    {
        return self::$useRustFfi ? self::requiresNntpConnectionNative($type) : self::requiresNntpConnectionPhp($type);
    }

    public static function requiresNntpConnectionPhp(string $type): bool
    {
        return self::VALID_TYPES[$type] ?? false;
    }

    public static function requiresNntpConnectionNative(string $type): bool
    {
        $flags = self::typeFlagsNative($type);

        return $flags === null ? self::requiresNntpConnectionPhp($type) : ($flags & self::TYPE_FLAG_REQUIRES_NNTP) !== 0;
    }

    public static function formatInvalidTypeMessage(string $type): string
    {
        return self::$useRustFfi ? self::formatInvalidTypeMessageNative($type) : self::formatInvalidTypeMessagePhp($type);
    }

    public static function formatInvalidTypeMessagePhp(string $type): string
    {
        return "Invalid type: {$type}";
    }

    public static function formatInvalidTypeMessageNative(string $type): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatInvalidTypeMessagePhp($type);
        }

        try {
            return self::callBufferFfi($ffi, strlen($type) + 32, 'nntmux_update_postprocess_format_invalid_type', $type, strlen($type))
                ?? self::formatInvalidTypeMessagePhp($type);
        } catch (Throwable) {
            return self::formatInvalidTypeMessagePhp($type);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = (string) $this->argument('type');

        if (! self::isValidType($type)) {
            $this->error(self::formatInvalidTypeMessage($type));
            $this->showHelp();

            return self::FAILURE;
        }

        try {
            $nntp = self::requiresNntpConnection($type) ? $this->getNntp() : null;

            match ($type) {
                'all' => $this->postProcessService->processAll($nntp ?? $this->getNntp()),
                'amazon' => $this->processAmazon(),
                'nfo' => $this->postProcessService->processNfos($nntp ?? $this->getNntp()),
                'movies' => $this->postProcessService->processMovies(),
                'music' => $this->postProcessService->processMusic(),
                'console' => $this->postProcessService->processConsoles(),
                'games' => $this->postProcessService->processGames(),
                'book' => $this->postProcessService->processBooks(),
                'anime' => $this->postProcessService->processAnime(),
                'tv' => $this->postProcessService->processTv(),
                'additional' => $this->postProcessService->processAdditional(),
            };

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Show help text.
     */
    private function showHelp(): void
    {
        $this->line('');
        $this->line('Available types:');
        $this->line('  all         - Does all the types of post processing');
        $this->line('  nfo         - Processes NFO files');
        $this->line('  movies      - Processes movies');
        $this->line('  music       - Processes music');
        $this->line('  console     - Processes console games');
        $this->line('  games       - Processes games');
        $this->line('  book        - Processes books');
        $this->line('  anime       - Processes anime');
        $this->line('  tv          - Processes tv');
        $this->line('  additional  - Processes previews/mediainfo/etc...');
        $this->line('  amazon      - Processes books, music, console, and games');
    }

    /**
     * Process amazon types (books, music, console, games).
     */
    private function processAmazon(): void
    {
        $this->postProcessService->processBooks();
        $this->postProcessService->processMusic();
        $this->postProcessService->processConsoles();
        $this->postProcessService->processGames();
    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \RuntimeException($errorMessage);
        }

        return $nntp;
    }

    /**
     * Bit set of `TYPE_FLAG_*` from Rust; `null` when the library is unavailable or declines.
     */
    private static function typeFlagsNative(string $type): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $flags = (int) $ffi->nntmux_update_postprocess_type_flags($type, strlen($type)); // @phpstan-ignore method.notFound

            return $flags < 0 ? null : $flags;
        } catch (Throwable) {
            return null;
        }
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
