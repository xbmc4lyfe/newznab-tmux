<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ShortGroup;
use App\Models\UsenetGroup;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class GroupsUpdate extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        ssize_t nntmux_cat_rgb_cmd_filter_groups(const char *groups_ptr, size_t groups_len, const char *active_ptr, size_t active_len, char *out_buf, size_t out_cap);
        int32_t nntmux_cat_rgb_cmd_project_row(uint8_t first_kind, int64_t first_int, const char *first_ptr, size_t first_len, uint8_t last_kind, int64_t last_int, const char *last_ptr, size_t last_len, int64_t *out_values);
        ssize_t nntmux_cat_rgb_cmd_completion_lines(int64_t updated, const char *elapsed_ptr, size_t elapsed_len, size_t *out_first_len, char *out_buf, size_t out_cap);
        CDEF;

    private const int KIND_NULL = 0;

    private const int KIND_INT = 2;

    private const int KIND_STRING = 3;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'groups:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update first/last article numbers for all active groups';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $start = now();

        // Create NNTP connection
        $nntp = new NNTPService;
        $connectResult = $nntp->doConnect();
        if ($connectResult !== true) {
            $errorMessage = '❌ Unable to connect to usenet server';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            $this->error($errorMessage);

            return Command::FAILURE;
        }

        $this->info('📡 Getting first/last for all active groups...');

        try {
            $data = $nntp->getGroups();

            if ($nntp->isError($data)) {
                $this->error('❌ Failed to getGroups() from NNTP server');

                return Command::FAILURE;
            }

            $this->info('🔄 Updating short_groups table...');

            // Truncate and rebuild
            DB::statement('TRUNCATE TABLE short_groups');

            // Get all active groups
            $activeGroups = Arr::pluck(
                UsenetGroup::query()
                    ->where('active', '=', 1)
                    ->orWhere('backfill', '=', 1)
                    ->get(['name']),
                'name'
            );

            $updated = 0;
            $bar = $this->output->createProgressBar(count($data));
            $bar->start();

            foreach (self::filterActiveServerGroups($data, $activeGroups) as $newgroup) {
                ShortGroup::query()->insert([
                    ...self::projectShortGroupRow($newgroup),
                    'updated' => now(),
                ]);

                $updated++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $elapsed = now()->diffInSeconds($start, true);
            foreach (self::formatCompletionLines($updated, (string) $elapsed) as $line) {
                $this->info($line);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Update failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Server groups whose `group` name is strictly one of the active group names.
     *
     * @param  array<array-key, mixed>  $serverGroups
     * @param  array<array-key, mixed>  $activeGroupNames
     * @return list<mixed>
     */
    public static function filterActiveServerGroups(array $serverGroups, array $activeGroupNames): array
    {
        return self::$useRustFfi
            ? self::filterActiveServerGroupsNative($serverGroups, $activeGroupNames)
            : self::filterActiveServerGroupsPhp($serverGroups, $activeGroupNames);
    }

    /**
     * @param  array<array-key, mixed>  $serverGroups
     * @param  array<array-key, mixed>  $activeGroupNames
     * @return list<mixed>
     */
    public static function filterActiveServerGroupsPhp(array $serverGroups, array $activeGroupNames): array
    {
        return array_values(array_filter($serverGroups, static fn (mixed $group): bool => \in_array($group['group'], $activeGroupNames, true)));
    }

    /**
     * @param  array<array-key, mixed>  $serverGroups
     * @param  array<array-key, mixed>  $activeGroupNames
     * @return list<mixed>
     */
    public static function filterActiveServerGroupsNative(array $serverGroups, array $activeGroupNames): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::filterActiveServerGroupsPhp($serverGroups, $activeGroupNames);
        }

        $active = '';
        foreach ($activeGroupNames as $name) {
            if (! \is_string($name)) {
                return self::filterActiveServerGroupsPhp($serverGroups, $activeGroupNames);
            }
            $active .= pack('P', \strlen($name)).$name;
        }
        $groups = '';
        $count = 0;
        foreach ($serverGroups as $group) {
            if (! \is_array($group) || ! \array_key_exists('group', $group)) {
                return self::filterActiveServerGroupsPhp($serverGroups, $activeGroupNames);
            }
            // A non-string name never strictly equals a string active name; its placeholder is ignored below.
            $name = \is_string($group['group']) ? $group['group'] : '';
            $groups .= pack('P', \strlen($name)).$name;
            $count++;
        }
        $mask = self::callForString($ffi, 'nntmux_cat_rgb_cmd_filter_groups', $count, $groups, \strlen($groups), $active, \strlen($active));
        if ($mask === null || \strlen($mask) !== $count) {
            return self::filterActiveServerGroupsPhp($serverGroups, $activeGroupNames);
        }

        $filtered = [];
        $index = 0;
        foreach ($serverGroups as $group) {
            if ($mask[$index] === "\1" && \is_string($group['group'])) {
                $filtered[] = $group;
            }
            $index++;
        }

        return $filtered;
    }

    /**
     * A `short_groups` row (without the `updated` timestamp) for one server group.
     *
     * @param  array<array-key, mixed>  $group
     * @return array{name: string, first_record: int, last_record: int}
     */
    public static function projectShortGroupRow(array $group): array
    {
        return self::$useRustFfi ? self::projectShortGroupRowNative($group) : self::projectShortGroupRowPhp($group);
    }

    /**
     * @param  array<array-key, mixed>  $group
     * @return array{name: string, first_record: int, last_record: int}
     */
    public static function projectShortGroupRowPhp(array $group): array
    {
        return [
            'name' => (string) ($group['group'] ?? ''),
            'first_record' => (int) ($group['first'] ?? 0),
            'last_record' => (int) ($group['last'] ?? 0),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $group
     * @return array{name: string, first_record: int, last_record: int}
     */
    public static function projectShortGroupRowNative(array $group): array
    {
        $ffi = self::ffi();
        $name = $group['group'] ?? '';
        $first = self::castArguments($group['first'] ?? null);
        $last = self::castArguments($group['last'] ?? null);
        if ($ffi === null || PHP_INT_SIZE !== 8 || ! \is_string($name) || $first === null || $last === null) {
            return self::projectShortGroupRowPhp($group);
        }

        try {
            $values = $ffi->new('int64_t[2]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_rgb_cmd_project_row(...[...$first, ...$last, $values]);
            if ($rc !== 1) {
                return self::projectShortGroupRowPhp($group);
            }

            return ['name' => $name, 'first_record' => (int) $values[0], 'last_record' => (int) $values[1]];
        } catch (Throwable) {
            return self::projectShortGroupRowPhp($group);
        }
    }

    /**
     * The two lines printed after the table is rebuilt.
     *
     * @return list<string>
     */
    public static function formatCompletionLines(int $updated, string $elapsedFormatted): array
    {
        return self::$useRustFfi ? self::formatCompletionLinesNative($updated, $elapsedFormatted) : self::formatCompletionLinesPhp($updated, $elapsedFormatted);
    }

    /**
     * @return list<string>
     */
    public static function formatCompletionLinesPhp(int $updated, string $elapsedFormatted): array
    {
        return ["✅ Updated {$updated} groups", "⏱️  Running time: {$elapsedFormatted} seconds"];
    }

    /**
     * @return list<string>
     */
    public static function formatCompletionLinesNative(int $updated, string $elapsedFormatted): array
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::formatCompletionLinesPhp($updated, $elapsedFormatted);
        }

        try {
            $firstLength = $ffi->new('size_t[1]');
            $joined = self::callForString(
                $ffi,
                'nntmux_cat_rgb_cmd_completion_lines',
                \strlen($elapsedFormatted) + 96,
                $updated, $elapsedFormatted, \strlen($elapsedFormatted), $firstLength,
            );
            $split = (int) $firstLength[0];
            if ($joined === null || $split < 1 || $split > \strlen($joined)) {
                return self::formatCompletionLinesPhp($updated, $elapsedFormatted);
            }

            return [substr($joined, 0, $split), substr($joined, $split)];
        } catch (Throwable) {
            return self::formatCompletionLinesPhp($updated, $elapsedFormatted);
        }
    }

    /**
     * `[kind, int, string, length]` arguments for an `(int)` cast in Rust, or `null` for kinds PHP casts.
     *
     * @return array{int, int, string, int}|null
     */
    private static function castArguments(mixed $value): ?array
    {
        return match (true) {
            $value === null => [self::KIND_NULL, 0, '', 0],
            \is_int($value) => [self::KIND_INT, $value, '', 0],
            \is_string($value) => [self::KIND_STRING, 0, $value, \strlen($value)],
            default => null,
        };
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('w30-3-groups-update', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
