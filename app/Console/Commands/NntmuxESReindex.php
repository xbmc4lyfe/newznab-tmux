<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class NntmuxESReindex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:reindex_es';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindex elasticsearch releases and predb indexes';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchIndexOffsetWorkerCommandParityTest.php.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /** @var list<string> */
    private const array REINDEX_TARGETS = ['releases', 'predb'];

    private static ?bool $useRustFfi = null;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        passthru('php '.app()->/* @scrutinizer ignore-call */ path().'/../misc/elasticsearch/create_es_indexes.php');
        passthru('php '.app()->/* @scrutinizer ignore-call */ path().'/../misc/elasticsearch/populate_es_indexes.php releases');
        passthru('php '.app()->/* @scrutinizer ignore-call */ path().'/../misc/elasticsearch/populate_es_indexes.php predb');

        return self::SUCCESS;
    }

    public static function useRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
    }

    /**
     * Plans the reindex shell commands handle() runs: index creation, then one populate step per
     * target. Targets are trimmed, lowercased and deduplicated (none given means releases and predb);
     * unknown targets are skipped. The app path loses trailing slashes and is single-quoted; a blank
     * path or one containing a NUL byte yields no commands.
     *
     * @param  list<string>  $targets
     * @return array{valid: bool, targets: list<string>, skipped: int, commands: list<string>}
     */
    public static function planReindexCommands(string $appPath, array $targets = []): array
    {
        return self::usesRustFfi()
            ? self::planReindexCommandsNative($appPath, $targets)
            : self::planReindexCommandsPhp($appPath, $targets);
    }

    /**
     * @param  list<string>  $targets
     * @return array{valid: bool, targets: list<string>, skipped: int, commands: list<string>}
     */
    private static function planReindexCommandsNative(string $appPath, array $targets): array
    {
        $fields = NntmuxPopulateSearchIndexes::evaluateNative(2, [$appPath, ...$targets]);
        $selected = $fields !== null && \count($fields) >= 3 ? (int) $fields[2] : -1;
        $valid = $fields !== null && $fields[0] === '1';
        if ($fields === null || $selected < 0 || \count($fields) !== 3 + $selected + ($valid ? $selected + 1 : 0)) {
            return self::planReindexCommandsPhp($appPath, $targets);
        }

        return [
            'valid' => $valid,
            'targets' => \array_slice($fields, 3, $selected),
            'skipped' => (int) $fields[1],
            'commands' => \array_slice($fields, 3 + $selected),
        ];
    }

    /**
     * @param  list<string>  $targets
     * @return array{valid: bool, targets: list<string>, skipped: int, commands: list<string>}
     */
    private static function planReindexCommandsPhp(string $appPath, array $targets): array
    {
        $selected = $targets === [] ? self::REINDEX_TARGETS : [];
        $skipped = 0;
        foreach ($targets as $target) {
            $target = strtolower(trim($target));
            if (! \in_array($target, self::REINDEX_TARGETS, true)) {
                $skipped++;
            } elseif (! \in_array($target, $selected, true)) {
                $selected[] = $target;
            }
        }

        $valid = trim($appPath) !== '' && ! str_contains($appPath, "\0");
        $commands = [];
        if ($valid) {
            $base = rtrim($appPath, '/');
            $script = static fn (string $name): string => "php '".str_replace("'", "'\\''", $base.'/../misc/elasticsearch/'.$name)."'";
            $commands[] = $script('create_es_indexes.php');
            foreach ($selected as $target) {
                $commands[] = $script('populate_es_indexes.php').' '.$target;
            }
        }

        return ['valid' => $valid, 'targets' => $selected, 'skipped' => $skipped, 'commands' => $commands];
    }
}
