<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SteamService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class SteamSearchGames extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'steam:search
                            {query : The search query}
                            {--limit=10 : Maximum number of results}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Search for games in the local Steam database';

    /**
     * Execute the console command.
     */
    public function handle(SteamService $steamService): int
    {
        $query = $this->argument('query');
        $limit = (int) $this->option('limit');

        info("Searching for: {$query}");

        $results = $steamService->searchMultiple($query, $limit);

        if ($results->isEmpty()) {
            warning('No games found matching your query.');

            return Command::SUCCESS;
        }

        info(self::formatFoundCountBanner($results->count()));

        $tableData = $results->map(fn ($r) => self::projectSearchResultRow(
            $r['appid'], $r['name'], $r['score'],
        ))->toArray();

        table(
            ['App ID', 'Name', 'Score', 'Store URL'],
            $tableData
        );

        return Command::SUCCESS;
    }

    // FFI serialization/allocation does not qualify for the 1.5x default-on gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public const string FFI_CDEF = 'ptrdiff_t nntmux_search_schema_log_steam(unsigned int mode, const char *input, size_t input_len, char *out, size_t cap);';

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? false;
    }

    public static function useRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search-schema-log-steam', self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Length-prefixed binary packets preserve PHP byte strings, including NULs.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                if (strlen($item) > 16777216) {
                    return null;
                }
                $input .= pack('V', strlen($item)).$item;
                if (strlen($input) > 16777216) {
                    return null;
                }
            }
            $cap = strlen($input) + 256;
            $out = $ffi->new("char[{$cap}]");
            $written = self::callFfi($ffi, 'nntmux_search_schema_log_steam', $mode, $input, strlen($input), $out, $cap);
            if (is_int($written) && $written < 0 && $written >= -16777216) {
                $cap = -$written;
                $out = $ffi->new("char[{$cap}]");
                $written = self::callFfi($ffi, 'nntmux_search_schema_log_steam', $mode, $input, strlen($input), $out, $cap);
            }
            if (! is_int($written) || $written < 4 || $written > $cap) {
                return null;
            }
            $packet = FFI::string($out, $written);
            $count = unpack('Vcount', $packet)['count'];
            $offset = 4;
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                if ($offset + 4 > $written) {
                    return null;
                }
                $length = unpack('Vlength', $packet, $offset)['length'];
                $offset += 4;
                if ($length > $written - $offset) {
                    return null;
                }
                $result[] = substr($packet, $offset, $length);
                $offset += $length;
            }

            return $offset === $written ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{int, string, string, string} */
    public static function projectSearchResultRow(int $appId, string $name, float $score): array
    {
        return self::useRustFfi() ? self::projectSearchResultRowNative($appId, $name, $score) : self::projectSearchResultRowPhp($appId, $name, $score);
    }

    /** @return array{int, string, string, string} */
    public static function projectSearchResultRowPhp(int $appId, string $name, float $score): array
    {
        return [$appId, substr($name, 0, 60), number_format($score, 1).'%', 'https://store.steampowered.com/app/'.$appId];
    }

    /** @return array{int, string, string, string} */
    public static function projectSearchResultRowNative(int $appId, string $name, float $score): array
    {
        // PHP's decimal half rounding includes a tolerance around binary ties.
        // Decline those boundaries, non-finite scores and thousands grouping.
        $scaled = abs($score * 10);
        if (! is_finite($score) || abs($score) >= 999.95 || abs(($scaled - floor($scaled)) - 0.5) < 1.0e-10) {
            return self::projectSearchResultRowPhp($appId, $name, $score);
        }
        $row = self::nativePacket(3, [(string) $appId, $name, pack('e', $score)]);

        return $row !== null && count($row) === 4 ? [(int) $row[0], $row[1], $row[2], $row[3]] : self::projectSearchResultRowPhp($appId, $name, $score);
    }

    public static function formatFoundCountBanner(int $count): string
    {
        return self::useRustFfi() ? self::formatFoundCountBannerNative($count) : self::formatFoundCountBannerPhp($count);
    }

    public static function formatFoundCountBannerPhp(int $count): string
    {
        return "Found {$count} result(s):";
    }

    public static function formatFoundCountBannerNative(int $count): string
    {
        return self::nativePacket(4, [(string) $count])[0] ?? self::formatFoundCountBannerPhp($count);
    }
}
