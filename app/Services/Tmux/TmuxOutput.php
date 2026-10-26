<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Facades\Yenc;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Tmux output functions for printing monitor data.
 *
 *
 * Class TmuxOutput
 */
class TmuxOutput extends Tmux
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_output_extract_server_info(const char *info, size_t info_len, char *out_buf, size_t out_cap);
        int64_t nntmux_tmux_output_percent(int64_t part, int64_t total);
        ssize_t nntmux_tmux_output_decode_count_row(int64_t in_process, const char *diff, size_t diff_len, int64_t in_database, int64_t percent, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    protected mixed $_colourMasks;

    private mixed $runVar;

    private mixed $tmpMasks;

    private ?string $gitVersion = null;

    private ?string $gitBranch = null;

    /**
     * TmuxOutput constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();

        $this->_setColourMasks();
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('irc', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    public function percent(int $part, int $total): int
    {
        return $this->percentPhp($part, $total);
    }

    public static function percentNative(int $part, int $total): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tmux_output_percent($part, $total);

            return is_int($res) ? $res : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function percentPhp(int $part, int $total): int
    {
        return $total > 0 ? (int) floor(($part / $total) * 100) : 0;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function decodeCountRow(int $inProcess, string $diff, int $inDatabase, int|string $percent): array
    {
        return $this->decodeCountRowPhp($inProcess, $diff, $inDatabase, $percent);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function decodeCountRowNative(int $inProcess, string $diff, int $inDatabase, int|string $percent): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $diffLen = strlen($diff);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $diffLen + 64), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_output_decode_count_row(
                $inProcess,
                $diff,
                $diffLen,
                $inDatabase,
                (int) $percent,
                $scratch,
                $cap,
            );
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_output_decode_count_row(
                    $inProcess,
                    $diff,
                    $diffLen,
                    $inDatabase,
                    (int) $percent,
                    $scratch,
                    $cap,
                );
                if (! is_int($written) || $written <= 0) {
                    return null;
                }
            }
            $parts = explode("\0", FFI::string($scratch, $written), 2);
            if (count($parts) !== 2) {
                return null;
            }

            return [$parts[0], $parts[1]];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function decodeCountRowPhp(int $inProcess, string $diff, int $inDatabase, int|string $percent): array
    {
        return [
            sprintf('%s(%s)', number_format($inProcess), $diff),
            sprintf('%s(%d%%)', number_format($inDatabase), (int) $percent),
        ];
    }

    /**
     * @return array{Threads: string, Opens: string, Tables: string, Slow: string, QPS: string}
     */
    public function extractServerInfoMetrics(string $info): array
    {
        if (self::$useRustFfi) {
            $native = self::extractServerInfoMetricsNative($info);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->extractServerInfoMetricsPhp($info);
    }

    /**
     * @return array{Threads: string, Opens: string, Tables: string, Slow: string, QPS: string}|null
     */
    public static function extractServerInfoMetricsNative(string $info): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = strlen($info);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 5 + 16), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_output_extract_server_info($info, $len, $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_output_extract_server_info($info, $len, $scratch, $cap);
                if (! is_int($written) || $written < 4) {
                    return null;
                }
            }
            $parts = explode("\0", FFI::string($scratch, $written), 5);
            if (count($parts) !== 5) {
                return null;
            }

            return [
                'Threads' => $parts[0],
                'Opens' => $parts[1],
                'Tables' => $parts[2],
                'Slow' => $parts[3],
                'QPS' => $parts[4],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{Threads: string, Opens: string, Tables: string, Slow: string, QPS: string}
     */
    public function extractServerInfoMetricsPhp(string $info): array
    {
        return [
            'Threads' => (string) preg_replace('/.*\bThreads: (\d+)\b.*/', '$1', $info),
            'Opens' => (string) preg_replace('/.*\bOpens[^:]*?: (\d+)\b.*/', '$1', $info),
            'Tables' => (string) preg_replace('/.* tables: (\d+)\b.*/', '$1', $info),
            'Slow' => (string) preg_replace('/.*\bSlow[^:]*?: (\d+)\b.*/', '$1', $info),
            'QPS' => (string) preg_replace('/.*\bQueries[^:]*?: (\d+)\b.*/', '$1', $info),
        ];
    }

    public function updateMonitorPane(mixed &$runVar): void
    {
        $this->runVar = $runVar;
        $this->tmpMasks = $this->_getFormatMasks(config('nntmux_nntp.compressed_headers'));

        $buffer = $this->_getHeader();

        if ($runVar['settings']['monitor'] > 0) {
            $buffer .= $this->_getMonitor();
        }

        if ((int) ($runVar['settings']['show_query'] ?? 0) === 1) {
            $buffer .= $this->_getQueries();
        }

        // begin update display with screen clear
        passthru('clear');
        echo $buffer;
    }

    protected function _getBackfill(): string
    {
        $buffer = sprintf($this->tmpMasks[3], 'Groups', 'Active', 'Backfill');
        $buffer .= $this->_getSeparator();

        if ($this->runVar['settings']['backfilldays'] === '1') {
            $buffer .= sprintf(
                $this->tmpMasks[4],
                'Activated',
                sprintf(
                    '%d(%d)',
                    $this->runVar['counts']['now']['active_groups'],
                    $this->runVar['counts']['now']['all_groups']
                ),
                sprintf(
                    '%d(%d)',
                    $this->runVar['counts']['now']['backfill_groups_days'],
                    $this->runVar['counts']['now']['all_groups']
                )
            );
        } else {
            $buffer .= sprintf(
                $this->tmpMasks[4],
                'Activated',
                sprintf(
                    '%d(%d)',
                    $this->runVar['counts']['now']['active_groups'],
                    $this->runVar['counts']['now']['all_groups']
                ),
                sprintf(
                    '%d(%d)',
                    $this->runVar['counts']['now']['backfill_groups_date'],
                    $this->runVar['counts']['now']['all_groups']
                )
            );
        }

        return $buffer;
    }

    /**
     * @return array<int, mixed>
     */
    protected function _getFormatMasks(mixed $compressed): array
    {
        $index = ($compressed === true ? '2.1' : '2.0');

        return [
            1 => &$this->_colourMasks[1],
            2 => &$this->_colourMasks[$index],
            3 => &$this->_colourMasks[3],
            4 => &$this->_colourMasks[4],
            5 => &$this->_colourMasks[5],
        ];
    }

    protected function _getHeader(): string
    {
        $buffer = '';
        $state = ((int) $this->runVar['settings']['is_running'] === 1) ? 'Running' : 'Disabled';
        $this->gitVersion ??= trim(Process::run('git describe --tags')->output());
        $this->gitBranch ??= trim(Process::run('git branch --show-current')->output());

        $version = $this->gitVersion;
        $branch = $this->gitBranch;

        $buffer .= sprintf(
            $this->tmpMasks[2],
            "Monitor $state $version [".$branch.'] :',
            $this->relativeTime($this->runVar['timers']['timer1'])
        );

        $buffer .= sprintf(
            $this->tmpMasks[1],
            'USP Connections:',
            sprintf(
                '%d active (%d total) - %s:%d',
                $this->runVar['conncounts']['primary']['active'],
                $this->runVar['conncounts']['primary']['total'],
                $this->runVar['connections']['host'],
                $this->runVar['connections']['port']
            )
        );

        if ($this->runVar['constants']['alternate_nntp'] === '1') {
            $buffer .= sprintf(
                $this->tmpMasks[1],
                'USP Alternate:',
                sprintf(
                    '%d active (%d total) - %s:%s',
                    $this->runVar['conncounts']['alternate']['active'] ?? 0,
                    $this->runVar['conncounts']['alternate']['total'] ?? 0,
                    $this->runVar['connections']['host_a'] ?: 'N/A',
                    $this->runVar['connections']['port_a'] ?: 'N/A'
                )
            );
        }

        $buffer .= sprintf(
            $this->tmpMasks[1],
            'Newest Release:',
            $this->runVar['timers']['newOld']['newestrelname']
        );
        $buffer .= sprintf(
            $this->tmpMasks[1],
            'Release Added:',
            sprintf(
                '%s',
                (isset($this->runVar['timers']['newOld']['newestrelease'])
                    ? $this->relativeTime($this->runVar['timers']['newOld']['newestrelease'])
                    : 0)
            )
        );
        $buffer .= sprintf(
            $this->tmpMasks[1],
            'Predb Updated:',
            sprintf(
                '%s',
                (isset($this->runVar['timers']['newOld']['newestpre'])
                    ? $this->relativeTime($this->runVar['timers']['newOld']['newestpre'])
                    : 0)
            )
        );
        $buffer .= sprintf(
            $this->tmpMasks[1],
            sprintf(
                'Collection Age[%d]:',
                $this->runVar['constants']['delaytime']
            ),
            sprintf(
                '%s',
                (isset($this->runVar['timers']['newOld']['oldestcollection'])
                    ? $this->relativeTime($this->runVar['timers']['newOld']['oldestcollection'])
                    : 0)
            )
        );
        $buffer .= sprintf(
            $this->tmpMasks[1],
            'Parts in Repair:',
            number_format((int) ($this->runVar['counts']['now']['missed_parts_table'] ?? 0))
        );

        if (((int) $this->runVar['settings']['post'] === 1 || (int) $this->runVar['settings']['post'] === 3) && (int) $this->runVar['constants']['sequential'] !== 2) {
            $buffer .= sprintf(
                $this->tmpMasks[1],
                'Postprocess:',
                'stale for '.$this->relativeTime($this->runVar['timers']['timer3'])
            );
        }

        $buffer .= sprintf(
            $this->tmpMasks[1],
            'yEnc Decoder:',
            Yenc::decoderName()
        );

        return $buffer.PHP_EOL;
    }

    protected function _getMonitor(): string
    {
        $buffer = $this->_getTableCounts();
        $buffer .= $this->_getPaths();

        $buffer .= sprintf($this->tmpMasks[3], 'PP Lists', 'Unmatched', 'Matched');
        $buffer .= $this->_getSeparator();

        [$nfoInProcess, $nfoInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processnfo'] ?? 0),
            (string) $this->runVar['counts']['diff']['processnfo'],
            (int) ($this->runVar['counts']['now']['nfo'] ?? 0),
            $this->runVar['counts']['percent']['nfo']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'Nfo', $nfoInProcess, $nfoInDb);
        [$predbInProcess, $predbInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['predb'] ?? 0) -
                (int) ($this->runVar['counts']['now']['distinct_predb_matched'] ?? 0),
            (string) $this->runVar['counts']['diff']['distinct_predb_matched'],
            (int) ($this->runVar['counts']['now']['predb_matched'] ?? 0),
            $this->runVar['counts']['percent']['predb_matched']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'PreDB', $predbInProcess, $predbInDb);
        [$renamesInProcess, $renamesInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processrenames'] ?? 0),
            (string) $this->runVar['counts']['diff']['processrenames'],
            (int) ($this->runVar['counts']['now']['renamed'] ?? 0),
            $this->runVar['counts']['percent']['renamed']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'Renames', $renamesInProcess, $renamesInDb);

        $buffer .= PHP_EOL;
        $buffer .= sprintf($this->tmpMasks[3], 'Category', 'In Process', 'In Database');
        $buffer .= $this->_getSeparator();

        [$audioInProcess, $audioInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processmusic'] ?? 0),
            (string) $this->runVar['counts']['diff']['processmusic'],
            (int) ($this->runVar['counts']['now']['audio'] ?? 0),
            $this->runVar['counts']['percent']['audio']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'Audio', $audioInProcess, $audioInDb);
        [$booksInProcess, $booksInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processbooks'] ?? 0),
            (string) $this->runVar['counts']['diff']['processbooks'],
            (int) ($this->runVar['counts']['now']['books'] ?? 0),
            $this->runVar['counts']['percent']['books']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'Books', $booksInProcess, $booksInDb);
        [$consoleInProcess, $consoleInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processconsole'] ?? 0),
            (string) $this->runVar['counts']['diff']['processconsole'],
            (int) ($this->runVar['counts']['now']['console'] ?? 0),
            $this->runVar['counts']['percent']['console']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'Console', $consoleInProcess, $consoleInDb);
        [$miscInProcess, $miscInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['work'] ?? 0),
            (string) $this->runVar['counts']['diff']['work'],
            (int) ($this->runVar['counts']['now']['misc'] ?? 0),
            $this->runVar['counts']['percent']['misc']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'Misc', $miscInProcess, $miscInDb);
        [$movieInProcess, $movieInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processmovies'] ?? 0),
            (string) $this->runVar['counts']['diff']['processmovies'],
            (int) ($this->runVar['counts']['now']['movies'] ?? 0),
            $this->runVar['counts']['percent']['movies']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'Movie', $movieInProcess, $movieInDb);
        [$pcInProcess, $pcInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processgames'] ?? 0),
            (string) $this->runVar['counts']['diff']['processgames'],
            (int) ($this->runVar['counts']['now']['pc'] ?? 0),
            $this->runVar['counts']['percent']['pc']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'PC', $pcInProcess, $pcInDb);
        [$tvInProcess, $tvInDb] = $this->decodeCountRow(
            (int) ($this->runVar['counts']['now']['processtv'] ?? 0),
            (string) $this->runVar['counts']['diff']['processtv'],
            (int) ($this->runVar['counts']['now']['tv'] ?? 0),
            $this->runVar['counts']['percent']['tv']
        );
        $buffer .= sprintf($this->tmpMasks[4], 'TV', $tvInProcess, $tvInDb);
        [$xxxInProcess, $xxxInDb] = $this->decodeCountRow(
            0,
            '0',
            (int) ($this->runVar['counts']['now']['xxx'] ?? 0),
            $this->runVar['counts']['percent']['xxx'] ?? 0
        );
        $buffer .= sprintf($this->tmpMasks[4], 'XXX', $xxxInProcess, $xxxInDb);
        $buffer .= $this->_getSeparator();

        $buffer .= sprintf(
            $this->tmpMasks[4],
            'Total',
            sprintf(
                '%s(%s)',
                number_format((int) ($this->runVar['counts']['now']['total_work'] ?? 0)),
                $this->runVar['counts']['diff']['total_work']
            ),
            sprintf(
                '%s(%s)',
                number_format((int) ($this->runVar['counts']['now']['releases'] ?? 0)),
                $this->runVar['counts']['diff']['releases']
            )
        );
        $buffer .= PHP_EOL;

        $buffer .= $this->_getBackfill();

        return $buffer;
    }

    protected function _getPaths(): string
    {
        $paths = array_values(array_unique(array_filter([
            $this->runVar['settings']['monitor_path'] ?? null,
            $this->runVar['settings']['monitor_path_a'] ?? null,
            $this->runVar['settings']['monitor_path_b'] ?? null,
        ], static fn (mixed $path): bool => is_string($path) && $path !== '' && file_exists($path))));

        if ($paths === []) {
            return PHP_EOL;
        }

        $buffer = PHP_EOL;
        $buffer .= sprintf($this->tmpMasks[3], 'File System', 'Used', 'Free');
        $buffer .= $this->_getSeparator();

        foreach ($paths as $path) {
            $total = disk_total_space($path);
            $free = disk_free_space($path);
            if ($total === false || $free === false) {
                continue;
            }

            $name = basename($path) ?: '/';
            $buffer .= sprintf(
                $this->tmpMasks[4],
                $name,
                $this->decodeSize($total - $free),
                $this->decodeSize($free),
            );
        }

        return $buffer.PHP_EOL;
    }

    protected function _getQueries(): string
    {
        $buffer = PHP_EOL;
        $buffer .= sprintf($this->tmpMasks[3], 'Query Block', 'Time', 'Cumulative');
        $buffer .= $this->_getSeparator();
        $buffer .= sprintf(
            $this->tmpMasks[4],
            'Combined',
            sprintf(
                '%.3f %.3f %.3f %.3f %.3f %.3f %.3f',
                $this->runVar['timers']['query']['tmux_time'],
                $this->runVar['timers']['query']['split_time'],
                $this->runVar['timers']['query']['init_time'],
                $this->runVar['timers']['query']['proc1_time'],
                $this->runVar['timers']['query']['proc2_time'],
                $this->runVar['timers']['query']['proc3_time'],
                $this->runVar['timers']['query']['tpg_time']
            ),
            sprintf(
                '%.3f %.3f %.3f %.3f %.3f %.3f %.3f',
                $this->runVar['timers']['query']['tmux_time'],
                $this->runVar['timers']['query']['split1_time'],
                $this->runVar['timers']['query']['init1_time'],
                $this->runVar['timers']['query']['proc11_time'],
                $this->runVar['timers']['query']['proc21_time'],
                $this->runVar['timers']['query']['proc31_time'],
                $this->runVar['timers']['query']['tpg1_time']
            )
        );

        $pdo = $this->pdo instanceof \PDO ? $this->pdo : DB::connection()->getPdo();
        $info = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_INFO);
        $pieces = [];
        foreach ($this->extractServerInfoMetrics($info) as $label => $value) {
            $pieces[] = cli()->ansiString($label.' = ', 'green').
                cli()->ansiString($value, 'yellow');
        }
        $buffer .= PHP_EOL.implode(', ', $pieces).PHP_EOL;

        return $buffer;
    }

    protected function _getSeparator(): string
    {
        return sprintf(
            $this->tmpMasks[3],
            '======================================',
            '=========================',
            '======================================'
        );
    }

    protected function _getTableCounts(): string
    {
        $buffer = sprintf($this->tmpMasks[3], 'Collections', 'Binaries', 'Parts');
        $buffer .= $this->_getSeparator();
        $buffer .= sprintf(
            $this->tmpMasks[5],
            number_format((int) ($this->runVar['counts']['now']['collections_table'] ?? 0)),
            number_format((int) ($this->runVar['counts']['now']['binaries_table'] ?? 0)),
            number_format((int) ($this->runVar['counts']['now']['parts_table'] ?? 0))
        );

        return $buffer;
    }

    protected function _setColourMasks(): void
    {
        $this->_colourMasks[1] = cli()->ansiString('%-18s', 'yellow').' '.cli()->ansiString('%-60.60s', 'yellow').PHP_EOL;
        $this->_colourMasks['2.0'] = cli()->ansiString('%-20s', 'magenta').' '.cli()->ansiString('%-33.33s', 'yellow').PHP_EOL;
        $this->_colourMasks['2.1'] = cli()->ansiString('%-20s', 'yellow').' '.cli()->ansiString('%-33.33s', 'yellow').PHP_EOL;
        $this->_colourMasks[3] = cli()->ansiString('%-16.16s %25.25s %25.25s', 'yellow').PHP_EOL;
        $this->_colourMasks[4] = cli()->ansiString('%-16.16s', 'green').' '.cli()->ansiString('%25.25s %25.25s', 'yellow').PHP_EOL;
        $this->_colourMasks[5] = cli()->ansiString('%-16.16s %25.25s %25.25s', 'yellow').PHP_EOL;
    }

    // ========================================================================
    // WAVE 58.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 4.39x, p99 3.58x; uncommon mean 8.07x, p99 8.04x); see RustTmuxLayoutMonitorOutputPaneSessionManagerW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_console_line_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per consoleLineRowBatch() record. */
    public const int W58_ROW_WORDS = 12;

    /** int64 summary words after the consoleLineRowBatch() records (none for an empty batch). */
    public const int W58_TRAILER_WORDS = 4;

    /**
     * Console line rows. Each item is [line, progress part, progress total, elapsed seconds].
     * CSI sequences are ESC "[" then parameter bytes 0x30-0x3F, intermediate bytes 0x20-0x2F
     * and a final byte 0x40-0x7E (broken without one); SGR ("m") parameters split on ";", where
     * an empty or 0 parameter resets the foreground, 39 clears it and 30-37/90-97 set it. A row
     * is [visible bytes (outside CSI, not control), visible chars (visible bytes that are not
     * UTF-8 continuation bytes), CSI, SGR, broken CSI, final foreground (-1 default), resets,
     * flags (1 ends with "\n", 2 has "\r", 4 colour left set, 8 lone ESC, 16 high bytes),
     * percent (floor(part * 100 / total), 0 without a total), 20-cell bar fill, hours, minutes *
     * 100 + seconds (-1 for negative elapsed)], followed by the trailer [visible bytes, max
     * visible chars, lines with SGR, broken CSI].
     *
     * @param  list<array{0: string, 1: int, 2: int, 3: int}>  $items
     * @return list<int>
     */
    public static function consoleLineRowBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::consoleLineRowBatchNative($items) : self::consoleLineRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3: int}>  $items
     * @return list<int>
     */
    public static function consoleLineRowBatchPhp(array $items): array
    {
        self::w58Items($items);
        $out = [];
        $summary = [0, 0, 0, 0];
        foreach ($items as [$line, $part, $total, $elapsed]) {
            $csi = (int) preg_match_all('/\e\[([\x30-\x3f]*)[\x20-\x2f]*([\x40-\x7e]?)/', $line, $matches, PREG_SET_ORDER);
            $sgr = 0;
            $broken = 0;
            $resets = 0;
            $foreground = -1;
            foreach ($matches as $match) {
                if ($match[2] === '') {
                    $broken++;

                    continue;
                }
                if ($match[2] !== 'm') {
                    continue;
                }
                $sgr++;
                foreach (explode(';', $match[1]) as $param) {
                    if ($param !== '' && (\strlen($param) > 9 || ! ctype_digit($param))) {
                        continue;
                    }
                    $code = (int) $param;
                    if ($code === 0) {
                        $resets++;
                        $foreground = -1;
                    } elseif ($code === 39) {
                        $foreground = -1;
                    } elseif (($code >= 30 && $code <= 37) || ($code >= 90 && $code <= 97)) {
                        $foreground = $code;
                    }
                }
            }
            $rest = $csi > 0 ? (string) preg_replace('/\e\[[\x30-\x3f]*[\x20-\x2f]*[\x40-\x7e]?/', '', $line) : $line;
            $visible = (string) preg_replace('/[\x00-\x1f\x7f]+/', '', $rest);
            $bytes = \strlen($visible);
            $chars = $bytes - (int) preg_match_all('/[\x80-\xbf]/', $visible);
            $percent = $total > 0 ? self::w58Floor((float) $part * 100.0 / (float) $total) : 0;
            array_push(
                $out,
                $bytes,
                $chars,
                $csi,
                $sgr,
                $broken,
                $foreground,
                $resets,
                (str_ends_with($line, "\n") ? 1 : 0) | (str_contains($line, "\r") ? 2 : 0) | ($foreground !== -1 ? 4 : 0)
                    | (str_contains($rest, "\e") ? 8 : 0) | (preg_match('/[\x80-\xff]/', $line) === 1 ? 16 : 0),
                $percent,
                intdiv(max(0, min(100, $percent)) * 20, 100),
                $elapsed < 0 ? -1 : intdiv($elapsed, 3600),
                $elapsed < 0 ? -1 : intdiv($elapsed % 3600, 60) * 100 + $elapsed % 60,
            );
            $summary[0] += $bytes;
            $summary[1] = max($summary[1], $chars);
            $summary[2] += $sgr > 0 ? 1 : 0;
            $summary[3] += $broken;
        }

        return $items === [] ? [] : [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3: int}>  $items
     * @return list<int>
     */
    public static function consoleLineRowBatchNative(array $items): array
    {
        self::w58Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        $blob = '';
        $lens = [];
        $parts = [];
        $totals = [];
        $elapsed = [];
        foreach ($items as $item) {
            $blob .= $item[0];
            $lens[] = \strlen($item[0]);
            $parts[] = $item[1];
            $totals[] = $item[2];
            $elapsed[] = $item[3];
        }

        return self::w58Call($blob, pack('P*', ...$lens, ...$parts, ...$totals, ...$elapsed), $count, $count * self::W58_ROW_WORDS + self::W58_TRAILER_WORDS)
            ?? self::consoleLineRowBatchPhp($items);
    }

    private static function w58Floor(float $value): int
    {
        return (int) max(-4.0e18, min(4.0e18, floor($value)));
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W58 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('enabled');
        self::w58RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w58Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W58 console line batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || ! array_is_list($item) || \count($item) !== 4 || ! \is_string($item[0]) || ! \is_int($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3])) {
                throw new \InvalidArgumentException('W58 console line batch items must be [string line, int part, int total, int elapsed] lists.');
            }
        }
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w58Call(string $blob, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w58RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w58', self::W58_FFI_CDEF) ?? false;
            self::w58RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w58RustFfiState('out', $out);
                self::w58RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_console_line_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w58RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W58 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
