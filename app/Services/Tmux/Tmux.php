<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\BookProcessingCandidateQuery;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NfoService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Class Tmux.
 */
class Tmux
{
    private readonly ConfigurationProvider $configuration;

    private readonly ProcessingRuntimeStateRepository $runtimeState;

    /**
     * @var \PDO|null
     */
    public \Closure|\PDO|null $pdo = null;

    public mixed $tmux_session;

    /**
     * Tmux constructor.
     */
    public function __construct(
        ?ConfigurationProvider $configuration = null,
        ?ProcessingRuntimeStateRepository $runtimeState = null,
    ) {
        $this->configuration = $configuration ?? app(ConfigurationProvider::class);
        $this->runtimeState = $runtimeState ?? app(ProcessingRuntimeStateRepository::class);
        try {
            $this->pdo = DB::connection()->getPdo();
        } catch (\Throwable) {
            $this->pdo = null;
        }
    }

    /**
     * @return mixed
     */
    public function getConnectionsInfo(mixed $constants)
    {
        $runVar['connections']['port_a'] = $runVar['connections']['host_a'] = $runVar['connections']['ip_a'] = false;
        $runVar['connections']['ips_a'] = [];
        $runVar['connections']['port'] = config('nntmux_nntp.port');
        $runVar['connections']['host'] = config('nntmux_nntp.server');
        $primaryIps = $this->resolveHostAddresses((string) ($runVar['connections']['host'] ?? ''));
        $runVar['connections']['ips'] = $primaryIps;
        $runVar['connections']['ip'] = $primaryIps[0] ?? (string) ($runVar['connections']['host'] ?? '');
        if ($constants['alternate_nntp'] === '1') {
            $runVar['connections']['port_a'] = config('nntmux_nntp.alternate_server_port');
            $runVar['connections']['host_a'] = config('nntmux_nntp.alternate_server');
            $alternateIps = $this->resolveHostAddresses((string) ($runVar['connections']['host_a'] ?? ''));
            $runVar['connections']['ips_a'] = $alternateIps;
            $runVar['connections']['ip_a'] = $alternateIps[0] ?? (string) ($runVar['connections']['host_a'] ?? '');
        }

        return $runVar['connections'];
    }

    /**
     * @param  array<string, mixed>  $connections
     * @return array<string, array{active: int, total: int}>
     */
    public function getUSPConnections(string $which, array $connections, ?string $socketSnapshot = null): array
    {
        [$ipKey, $ipsKey, $hostKey, $portKey, $otherIpKey, $otherIpsKey, $otherHostKey, $otherPortKey] = $which === 'alternate'
            ? ['ip_a', 'ips_a', 'host_a', 'port_a', 'ip', 'ips', 'host', 'port']
            : ['ip', 'ips', 'host', 'port', 'ip_a', 'ips_a', 'host_a', 'port_a'];

        $port = trim((string) ($connections[$portKey] ?? ''));
        $otherPort = trim((string) ($connections[$otherPortKey] ?? ''));
        $ips = $this->extractConnectionAddresses($connections, $ipKey, $ipsKey, $hostKey);
        $otherIps = $this->extractConnectionAddresses($connections, $otherIpKey, $otherIpsKey, $otherHostKey);
        $samePortAsOther = $port !== '' && $port === $otherPort;

        $lines = array_values(array_filter(
            preg_split('/\R/', $socketSnapshot ?? $this->getSocketSnapshot()) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));

        if ($port !== '') {
            $portLines = array_values(array_filter(
                $lines,
                fn (string $line): bool => $this->socketLineHasPeerPort($line, $port),
            ));

            if ($portLines !== []) {
                if ($samePortAsOther) {
                    if ($which === 'alternate') {
                        $matchingLines = $ips === []
                            ? []
                            : array_values(array_filter(
                                $portLines,
                                fn (string $line): bool => $this->socketLineMatchesAnyAddress($line, $ips, $port),
                            ));
                    } else {
                        $matchingLines = $otherIps === []
                            ? $portLines
                            : array_values(array_filter(
                                $portLines,
                                fn (string $line): bool => ! $this->socketLineMatchesAnyAddress($line, $otherIps, $port),
                            ));
                    }
                } else {
                    $matchingLines = $portLines;
                }

                return [
                    $which => [
                        'active' => count(array_filter(
                            $matchingLines,
                            static fn (string $line): bool => str_contains($line, 'ESTAB'),
                        )),
                        'total' => count($matchingLines),
                    ],
                ];
            }
        }

        if ($ips !== []) {
            $matchingLines = array_values(array_filter(
                $lines,
                fn (string $line): bool => $this->socketLineMatchesAnyAddress($line, $ips, $port),
            ));

            if ($matchingLines !== []) {
                return [
                    $which => [
                        'active' => count(array_filter(
                            $matchingLines,
                            static fn (string $line): bool => str_contains($line, 'ESTAB'),
                        )),
                        'total' => count($matchingLines),
                    ],
                ];
            }
        }

        return [$which => ['active' => 0, 'total' => 0]];
    }

    /**
     * @return list<string>
     */
    protected function resolveHostAddresses(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = @gethostbynamel($host);
        if (is_array($addresses) && $addresses !== []) {
            return array_values(array_unique(array_filter($addresses, static fn (string $ip): bool => $ip !== '')));
        }

        $single = gethostbyname($host);

        return $single !== '' && $single !== $host ? [$single] : [];
    }

    /**
     * @param  array<string, mixed>  $connections
     * @return list<string>
     */
    private function extractConnectionAddresses(array $connections, string $ipKey, string $ipsKey, string $hostKey): array
    {
        $addresses = [];

        if (isset($connections[$ipsKey]) && is_array($connections[$ipsKey])) {
            foreach ($connections[$ipsKey] as $candidate) {
                $candidateStr = trim((string) $candidate);
                if ($candidateStr !== '' && $candidateStr !== '0') {
                    $addresses[] = $candidateStr;
                }
            }
        }

        $singleIp = isset($connections[$ipKey]) && $connections[$ipKey] !== false
            ? trim((string) $connections[$ipKey])
            : '';
        if ($singleIp !== '' && $singleIp !== '0') {
            if (filter_var($singleIp, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $singleIp;
            } else {
                $addresses = array_merge($addresses, $this->resolveHostAddresses($singleIp));
            }
        }

        if ($addresses === [] && isset($connections[$hostKey]) && $connections[$hostKey] !== false) {
            $host = trim((string) $connections[$hostKey]);
            if ($host !== '') {
                $addresses = $this->resolveHostAddresses($host);
            }
        }

        return array_values(array_unique($addresses));
    }

    private function socketLineHasPeerPort(string $line, string $port): bool
    {
        $peer = $this->extractPeerEndpoint($line);
        if ($peer !== null) {
            $colonPos = strrpos($peer, ':');
            if ($colonPos !== false) {
                $peerPort = substr($peer, $colonPos + 1);

                return $peerPort === $port || ($port === '443' && $peerPort === 'https');
            }
        }

        return preg_match('/(?:^|\s)\S+:'.preg_quote($port, '/').'(?:\s|$)/', $line) === 1;
    }

    /**
     * @param  list<string>  $addresses
     */
    private function socketLineMatchesAnyAddress(string $line, array $addresses, string $port): bool
    {
        $peer = $this->extractPeerEndpoint($line);

        foreach ($addresses as $ip) {
            if ($peer !== null) {
                if (
                    $peer === $ip
                    || ($port !== '' && $peer === $ip.':'.$port)
                    || $peer === $ip.':https'
                    || str_starts_with($peer, $ip.':')
                    || str_starts_with($peer, '['.$ip.']:')
                ) {
                    return true;
                }

                continue;
            }

            if (
                ($port !== '' && str_contains($line, $ip.':'.$port))
                || str_contains($line, $ip.':https')
                || str_contains($line, $ip)
            ) {
                return true;
            }
        }

        return false;
    }

    private function extractPeerEndpoint(string $line): ?string
    {
        $columns = preg_split('/\s+/', trim($line)) ?: [];

        return $columns[4] ?? null;
    }

    public function getSocketSnapshot(): string
    {
        return (string) shell_exec('ss -nH 2>/dev/null');
    }

    /**
     * @return array<string, mixed>
     */
    public function getListOfPanes(mixed $constants): array
    {
        $panes = ['zero' => [], 'one' => [], 'two' => []];
        $session = new TmuxSessionManager($constants['tmux_session'] ?? null);
        foreach ($session->listPanes() as $position => $title) {
            [$window] = explode(':', $position, 2);
            $key = ['zero', 'one', 'two'][(int) $window] ?? null;
            if ($key !== null && ((int) ($constants['sequential'] ?? 0) !== 2 || $key !== 'two')) {
                $panes[$key][] = $title;
            }
        }

        return $panes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConstantSettings(): array
    {
        $tmux = $this->configuration->tmux(fresh: true);

        return [
            'sequential' => $tmux->sequentialMode,
            'tmux_session' => $tmux->sessionName,
            'run_ircscraper' => $tmux->runIrcScraper,
            'delaytime' => $this->configuration->ingestion()->collectionDelayHours,
            'alternate_nntp' => config('nntmux_nntp.use_alternate_nntp_server') ? '1' : '0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMonitorSettings(): array
    {
        $tmux = $this->configuration->tmux(fresh: true);
        $ingestion = $this->configuration->ingestion(fresh: true);
        $metadata = $this->configuration->metadata(fresh: true);
        $postProcessing = $this->configuration->postProcessing(fresh: true);

        return [
            'monitor' => $tmux->monitorDelay,
            'binaries_run' => (int) $tmux->binariesEnabled,
            'backfill' => $tmux->backfillMode,
            'backfill_qty' => $ingestion->backfillQuantity,
            'post' => $tmux->postMode,
            'releases_run' => (int) $tmux->releasesEnabled,
            'fix_names' => (int) $tmux->fixNamesEnabled,
            'seq_timer' => $tmux->sequentialTimer,
            'bins_timer' => $tmux->binariesTimer,
            'back_timer' => $tmux->backfillTimer,
            'rel_timer' => $tmux->releaseTimer,
            'fix_timer' => $tmux->fixTimer,
            'post_timer' => $tmux->postTimer,
            'collections_kill' => $tmux->collectionsKillThreshold,
            'postprocess_kill' => $tmux->postProcessKillThreshold,
            'crap_timer' => $tmux->cleanupTimer,
            'fix_crap' => implode(',', $tmux->cleanupRules),
            'fix_crap_opt' => $tmux->cleanupMode,
            'post_kill_timer' => $tmux->postKillTimer,
            ...$this->runtimeState->monitorPaths(),
            'progressive' => (int) $tmux->progressiveBackfill,
            'backfilldays' => (string) $ingestion->backfillDaysMode,
            'post_amazon' => $tmux->postAmazonMode,
            'post_timer_amazon' => $tmux->postAmazonTimer,
            'post_non' => $tmux->postNonMode,
            'post_timer_non' => $tmux->postNonTimer,
            'colors_start' => $tmux->colorsStart,
            'colors_end' => $tmux->colorsEnd,
            'colors_exc' => implode(',', $tmux->colorExclusions),
            'show_query' => 0,
            'is_running' => (int) $this->runtimeState->isTmuxRunning(),
            'processbooks' => $metadata->bookLookup->value,
            'processmusic' => $metadata->musicLookup->value,
            'processgames' => $metadata->gameLookup->value,
            'processmovies' => $metadata->movieLookup->value,
            'processtvrage' => $metadata->tvLookup->value,
            'processanime' => $metadata->animeLookup->value,
            'processnfo' => (int) $postProcessing->lookupNfo,
            'processpar2' => (int) $postProcessing->lookupPar2,
            'maxsize_pp' => $postProcessing->maxSizeToPostProcess,
            'minsize_pp' => $postProcessing->minSizeToPostProcess,
        ];
    }

    public function microtime_float(): float
    {
        [$usec, $sec] = explode(' ', microtime());

        return (float) $usec + (float) $sec;
    }

    public function decodeSize(int|float $bytes): string
    {
        // Handle zero case
        if ($bytes === 0) {
            return '0 B';
        }

        // Handle negative values
        if ($bytes < 0) {
            return '-'.$this->decodeSize(abs($bytes));
        }

        // Add more size units (PB, EB)
        $types = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
        $index = 0;

        // Use while loop instead of foreach for more efficiency
        while ($bytes >= 1024.0 && $index < count($types) - 1) {
            $bytes /= 1024.0;
            $index++;
        }

        return round($bytes, 2).' '.$types[$index];
    }

    public function writelog(mixed $pane): ?string
    {
        $path = storage_path('logs');
        $getDate = now()->format('Y_m_d');
        $logs = app(ConfigurationProvider::class)->tmux()->writeLogs;
        if ($logs) {
            return "2>&1 | tee -a $path/$pane-$getDate.log";
        }

        return '';
    }

    /**
     * @throws \Exception
     */
    public function get_color(mixed $colors_start, mixed $colors_end, mixed $colors_exc): int
    {
        // Handle null values with sensible defaults
        $colors_start = $colors_start ?? 0;
        $colors_end = $colors_end ?? 255;
        $colors_exc = $colors_exc ?? '';

        $exception = str_replace('.', '.', $colors_exc);
        $exceptions = explode(',', $exception);
        sort($exceptions);
        $number = random_int($colors_start, $colors_end - \count($exceptions));
        foreach ($exceptions as $exception) {
            if ($number >= $exception) {
                $number++;
            } else {
                break;
            }
        }

        return $number;
    }

    public function relativeTime(mixed $_time): string
    {
        return Carbon::createFromTimestamp($_time, date_default_timezone_get())->ago();
    }

    /**
     * @throws \Exception
     */
    public function proc_query(mixed $qry, string $db_name, ?string $ppmax = '', ?string $ppmin = ''): bool|string
    {
        // Convert null to empty string for backward compatibility
        $ppmax = $ppmax ?? '';
        $ppmin = $ppmin ?? '';

        switch ((int) $qry) {
            case 1:

                $metadata = $this->configuration->metadata();
                $tmux = $this->configuration->tmux();
                $postProcessing = $this->configuration->postProcessing();
                $hasPostPanes = $tmux->sequentialMode !== 2;
                $nonAmazonEnabled = $hasPostPanes && $tmux->postNonMode > 0;
                $amazonEnabled = $tmux->postAmazonMode > 0;

                $conditions = [
                    'processtv' => $nonAmazonEnabled ? TvProcessingCandidateQuery::workCondition($metadata->tvLookup->value) : '0 = 1',
                    'processanime' => $this->readyLookupCondition(
                        'categories_id = '.Category::TV_ANIME.' AND anidbid IS NULL',
                        $metadata->animeLookup->value, $nonAmazonEnabled, renamedOnly: false,
                    ),
                    'processmovies' => $this->readyLookupCondition(
                        'categories_id BETWEEN '.Category::MOVIE_ROOT.' AND '.Category::MOVIE_OTHER.' AND '.imdb_id_needs_lookup_sql('imdbid'),
                        $metadata->movieLookup->value, $nonAmazonEnabled,
                    ),
                    'processmusic' => $this->readyLookupCondition(
                        'categories_id IN ('.Category::MUSIC_MP3.', '.Category::MUSIC_LOSSLESS.', '.Category::MUSIC_OTHER.') AND musicinfo_id IS NULL',
                        $metadata->musicLookup->value, $amazonEnabled,
                    ),
                    'processconsole' => $this->readyLookupCondition(
                        'categories_id BETWEEN '.Category::GAME_ROOT.' AND '.Category::GAME_OTHER.' AND consoleinfo_id IS NULL',
                        $metadata->gameLookup->value, $amazonEnabled,
                    ),
                    'processbooks' => $amazonEnabled ? BookProcessingCandidateQuery::workCondition($metadata->bookLookup->value) : '0 = 1',
                    'processgames' => $this->readyLookupCondition(
                        'categories_id = '.Category::PC_GAMES.' AND gamesinfo_id = 0',
                        $metadata->gameLookup->value, $amazonEnabled,
                    ),
                    'processnfo' => ! $hasPostPanes
                        ? '1=1 '.NfoService::NfoQueryString().' AND '.AdditionalCandidateQuery::sequentialGroupCondition()
                        : (in_array($tmux->postMode, [2, 3], true) && $postProcessing->lookupNfo
                            ? '1=1 '.NfoService::NfoQueryString() : '0 = 1'),
                    'processrenames' => $tmux->fixNamesEnabled ? NameFixingQueryService::pendingWorkCondition() : '0 = 1',
                    'renamed' => 'isrenamed = 1',
                    'nfo' => 'nfostatus = 1',
                    'predb_matched' => 'predb_id > 0',
                ];
                $aggregates = [];
                foreach ($conditions as $name => $condition) {
                    $aggregates[] = 'COALESCE(SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END), 0) AS '.$name;
                }
                $aggregates[] = 'COUNT(DISTINCT(predb_id)) AS distinct_predb_matched';

                return "SELECT\n".implode(",\n", $aggregates).' FROM releases r';

            case 2:
                // NOTE: the "Misc In Process" / `work` count was previously computed here
                // and repeatedly drifted out of sync with the actual additional
                // post-processor selection in
                // \App\Services\AdditionalProcessing\AdditionalCandidateQuery::applyPredicates()
                // (typical drift: a missing `nzbstatus = 1` filter, which leaves
                // NZB-less releases stuck in the queue forever). It now lives in
                // \App\Services\Tmux\TmuxMonitorService::collectProcessCounts(), which
                // calls AdditionalCandidateQuery directly. The $ppmax / $ppmin args
                // are kept for backward compatibility with the public signature but
                // are no longer used by this case.
                unset($ppmax, $ppmin);

                return 'SELECT
					(SELECT COUNT(id) FROM usenet_groups WHERE active = 1) AS active_groups,
					(SELECT COUNT(id) FROM usenet_groups WHERE name IS NOT NULL) AS all_groups';

            case 4:
                $safeBackfillDate = escapeString($this->configuration->ingestion()->safeBackfillDate);

                return sprintf(
                    "
					SELECT
					(SELECT TABLE_ROWS FROM information_schema.TABLES WHERE table_name = 'predb' AND TABLE_SCHEMA = %1\$s) AS predb,
					(SELECT COUNT(id) FROM usenet_groups WHERE first_record IS NOT NULL AND backfill = 1
						AND (now() - INTERVAL backfill_target DAY) < first_record_postdate
					) AS backfill_groups_days,
					(SELECT COUNT(id) FROM usenet_groups WHERE first_record IS NOT NULL AND backfill = 1 AND (now() - INTERVAL datediff(curdate(),
					%2\$s) DAY) < first_record_postdate) AS backfill_groups_date",
                    escapeString($db_name),
                    $safeBackfillDate,
                );
            case 6:
                return 'SELECT
					(SELECT searchname FROM releases ORDER BY id DESC LIMIT 1) AS newestrelname,
					(SELECT UNIX_TIMESTAMP(MIN(dateadded)) FROM collections) AS oldestcollection,
					(SELECT UNIX_TIMESTAMP(MAX(predate)) FROM predb) AS newestpre,
					(SELECT UNIX_TIMESTAMP(adddate) FROM releases ORDER BY id DESC LIMIT 1) AS newestrelease';
            default:
                return false;
        }
    }

    private function readyLookupCondition(string $condition, int $lookupMode, bool $enabled, bool $renamedOnly = true): string
    {
        if (! $enabled || $lookupMode <= 0) {
            return '0 = 1';
        }

        return $condition.($renamedOnly && $lookupMode === 2 ? ' AND isrenamed = 1' : '').' AND '.Release::nzbSettledSql('r');
    }

    /**
     * @return bool true if tmux is running, false otherwise.
     *
     * @throws \RuntimeException
     */
    public function isRunning(): bool
    {
        return $this->runtimeState->isTmuxRunning();
    }

    /**
     * @throws \Exception
     */
    public function stopIfRunning(): bool
    {
        if ($this->isRunning()) {
            $this->runtimeState->requestStop();
            $this->runtimeState->setTmuxRunning(false);
            $sleep = app(ConfigurationProvider::class)->tmux()->monitorDelay;
            cli()->header('Stopping tmux scripts and waiting '.$sleep.' seconds for all panes to shutdown');
            sleep($sleep);

            return true;
        }
        cli()->info('Tmux scripts are not running!');

        return false;
    }

    /**
     * @throws \RuntimeException
     */
    public function startRunning(): void
    {
        if (! $this->isRunning()) {
            $this->runtimeState->requestStop(false);
            $this->runtimeState->setTmuxRunning(true);
        }
    }

    /**
     * Count the active ingestion tables directly; InnoDB row estimates can stay
     * stale and legacy multigroup tables are no longer used by ingestion.
     *
     * @return list<object{name: string, row_count: int}>
     */
    public function cbpmTableQuery(): array
    {
        $counts = [];

        foreach (['binaries', 'parts', 'missed_parts'] as $table) {
            $counts[] = (object) ['name' => $table, 'row_count' => $this->tableRowCount($table)];
        }

        return $counts;
    }

    /**
     * Above this many rows the InnoDB estimate replaces an exact count.
     */
    private const int EXACT_COUNT_LIMIT = 1_000_000;

    /**
     * Row count for the monitor display. An exact COUNT(*) over the hundred-million-row parts table
     * takes over two minutes, which stalls the monitor loop past the health check's heartbeat limit
     * and gets the whole indexer restarted; large tables therefore use InnoDB's row estimate.
     */
    public function tableRowCount(string $table): int
    {
        $rows = $this->estimatedRowCount($table);

        return $rows !== null && $rows > self::EXACT_COUNT_LIMIT ? $rows : DB::table($table)->count();
    }

    /**
     * InnoDB's row estimate, or null where the driver has none.
     */
    protected function estimatedRowCount(string $table): ?int
    {
        if (! \in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $estimate = DB::selectOne(
            'SELECT TABLE_ROWS AS row_estimate FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($estimate->row_estimate ?? 0);
    }
}
