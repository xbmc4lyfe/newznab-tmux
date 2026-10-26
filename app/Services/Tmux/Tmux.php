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
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Class Tmux.
 */
class Tmux
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_extract_peer_endpoint(const char *line, size_t line_len, char *out_buf, size_t out_cap);
        int32_t nntmux_tmux_socket_line_has_peer_port(const char *line, size_t line_len, const char *port, size_t port_len);
        int32_t nntmux_tmux_socket_line_matches_any_address_nul(const char *line, size_t line_len, const char *addresses, size_t addresses_len, size_t addr_count, const char *port, size_t port_len);
        int64_t nntmux_tmux_count_usp_connections(uint8_t is_alternate, const char *port, size_t port_len, const char *other_port, size_t other_port_len, const char *ips, size_t ips_len, size_t ips_count, const char *other_ips, size_t other_ips_len, size_t other_ips_count, const char *snapshot, size_t snapshot_len);
        ssize_t nntmux_tmux_decode_size(double bytes, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

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
        } catch (Throwable) {
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
        $snapshot = $socketSnapshot ?? $this->getSocketSnapshot();

        if (self::$useRustFfi) {
            $native = self::countUSPConnectionsNative(
                $which === 'alternate',
                $port,
                $otherPort,
                $ips,
                $otherIps,
                $snapshot,
            );
            if ($native !== null) {
                return [$which => $native];
            }
        }

        return $this->countUSPConnectionsPhp($which, $port, $otherPort, $ips, $otherIps, $snapshot);
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $otherIps
     * @return array{active: int, total: int}|null
     */
    public static function countUSPConnectionsNative(
        bool $isAlternate,
        string $port,
        string $otherPort,
        array $ips,
        array $otherIps,
        string $snapshot,
    ): ?array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $ipsCount = count($ips);
        $ipsJoined = $ipsCount === 0 ? '' : implode("\0", $ips);
        if ($ipsCount > 0 && substr_count($ipsJoined, "\0") !== $ipsCount - 1) {
            return null;
        }
        $otherIpsCount = count($otherIps);
        $otherIpsJoined = $otherIpsCount === 0 ? '' : implode("\0", $otherIps);
        if ($otherIpsCount > 0 && substr_count($otherIpsJoined, "\0") !== $otherIpsCount - 1) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_tmux_count_usp_connections(
                $isAlternate ? 1 : 0,
                $port,
                strlen($port),
                $otherPort,
                strlen($otherPort),
                $ipsJoined,
                strlen($ipsJoined),
                $ipsCount,
                $otherIpsJoined,
                strlen($otherIpsJoined),
                $otherIpsCount,
                $snapshot,
                strlen($snapshot),
            );
            if (! is_int($packed) || $packed < 0) {
                return null;
            }

            return [
                'active' => $packed & 0xFFFFFFFF,
                'total' => $packed >> 32,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $otherIps
     * @return array<string, array{active: int, total: int}>
     */
    private function countUSPConnectionsPhp(
        string $which,
        string $port,
        string $otherPort,
        array $ips,
        array $otherIps,
        string $snapshot,
    ): array {
        $samePortAsOther = $port !== '' && $port === $otherPort;

        $lines = array_values(array_filter(
            preg_split('/\R/', $snapshot) ?: [],
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
        return $this->socketLineHasPeerPortPhp($line, $port);
    }

    public static function socketLineHasPeerPortNative(string $line, string $port): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tmux_socket_line_has_peer_port($line, strlen($line), $port, strlen($port));

            return is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function socketLineHasPeerPortPhp(string $line, string $port): bool
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
        return $this->socketLineMatchesAnyAddressPhp($line, $addresses, $port);
    }

    /**
     * @param  list<string>  $addresses
     */
    public static function socketLineMatchesAnyAddressNative(string $line, array $addresses, string $port): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $count = count($addresses);
        if ($count === 0) {
            return false;
        }
        $joined = implode("\0", $addresses);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tmux_socket_line_matches_any_address_nul(
                $line,
                strlen($line),
                $joined,
                strlen($joined),
                $count,
                $port,
                strlen($port),
            );

            return is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $addresses
     */
    private function socketLineMatchesAnyAddressPhp(string $line, array $addresses, string $port): bool
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
        return $this->extractPeerEndpointPhp($line);
    }

    public static function extractPeerEndpointNative(string $line, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = strlen($line);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $len + 16), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_extract_peer_endpoint($line, $len, $scratch, $cap);
            if (! is_int($written)) {
                return null;
            }
            if ($written === -1) {
                $supported = true;

                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_extract_peer_endpoint($line, $len, $scratch, $cap);
                if (! is_int($written) || $written < 0) {
                    return null;
                }
            }
            $supported = true;

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private function extractPeerEndpointPhp(string $line): ?string
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
        return $this->decodeSizePhp($bytes);
    }

    public static function decodeSizeNative(int|float $bytes): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_decode_size((float) $bytes, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private function decodeSizePhp(int|float $bytes): string
    {
        // Handle zero case
        if ($bytes === 0) {
            return '0 B';
        }

        // Handle negative values
        if ($bytes < 0) {
            return '-'.$this->decodeSizePhp(abs($bytes));
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

    // ========================================================================
    // WAVE 58.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.51x, p99 5.67x; uncommon mean 3.51x, p99 2.83x); see RustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_TMUX_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_tmux_pane_status_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** ASCII-lowercased pane command basename => command class of w58PaneStatusBatch(); others are 6. */
    public const array W58_PANE_COMMANDS = ['bash' => 1, 'sh' => 1, 'zsh' => 1, 'fish' => 1, 'php' => 2, 'sleep' => 3, 'tmux' => 4,
        'python' => 5, 'python3' => 5];

    /** Pane colour per status of w58PaneStatusBatch(). */
    public const array W58_PANE_COLORS = [196, 160, 244, 33, 46, 250];

    /** Bytes allowed in a tmux session name. */
    public const string W58_SESSION_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';

    /** Trailer words after the per-item words of w58PaneStatusBatch(). */
    public const int W58_PANE_TRAILER = 9;

    public static function usesW58RustFfi(): bool
    {
        $override = self::w58RustFfiState();

        return \is_bool($override) ? $override : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 58 Rust path forced on or off, then restores the previous setting.
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

    /**
     * Wave 58.3 pane status lines `session:window.pane dead command [args]` (split as explode(' ', $line, 3)).
     * Per line `status | command << 3 | valid << 6 | dead << 7 | window << 9 | pane << 19 | sessionLength << 29
     * | colour << 36`: the target is valid with a 1-64 byte W58_SESSION_BYTES session before its last `:`, then
     * 1-3 digit window and pane numbers around the first `.` (window, pane and session length are 0 when
     * invalid); dead is 0 for `0`, 1 for `1` and 2 otherwise; command is the W58_PANE_COMMANDS class of the
     * ASCII-lowercased basename of the command's first word (0 empty, 6 other). Status: 0 invalid target, 1
     * dead, 2 idle shell, 3 sleeping, 4 running php/python, else 5; colour is W58_PANE_COLORS[status]. Then
     * the six status counts, the distinct valid sessions and the max valid window and pane.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58PaneStatusBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::w58PaneStatusBatchNative($items) : self::w58PaneStatusBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58PaneStatusBatchNative(array $items): array
    {
        return self::w58Invoke($items, \count($items) + self::W58_PANE_TRAILER) ?? self::w58PaneStatusBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58PaneStatusBatchPhp(array $items): array
    {
        $out = [];
        $statuses = [0, 0, 0, 0, 0, 0];
        $sessions = [];
        $maxWindow = 0;
        $maxPane = 0;
        foreach ($items as $item) {
            [$target, $dead, $command] = array_pad(explode(' ', (string) $item, 3), 3, '');
            $valid = false;
            $session = '';
            $window = 0;
            $pane = 0;
            $colon = strrpos($target, ':');
            if ($colon !== false) {
                $session = substr($target, 0, $colon);
                $rest = substr($target, $colon + 1);
                $dot = strpos($rest, '.');
                if ($dot !== false) {
                    $windowText = substr($rest, 0, $dot);
                    $paneText = substr($rest, $dot + 1);
                    $length = \strlen($session);
                    $valid = $length >= 1 && $length <= 64 && strspn($session, self::W58_SESSION_BYTES) === $length
                        && self::w58Digits($windowText) && self::w58Digits($paneText);
                    if ($valid) {
                        $window = (int) $windowText;
                        $pane = (int) $paneText;
                    }
                }
            }
            $dead = match ($dead) {
                '0' => 0,
                '1' => 1,
                default => 2,
            };
            $space = strpos($command, ' ');
            $command = $space === false ? $command : substr($command, 0, $space);
            $slash = strrpos($command, '/');
            $base = $slash === false ? $command : substr($command, $slash + 1);
            $class = $base === '' ? 0 : (self::W58_PANE_COMMANDS[strtolower($base)] ?? 6);
            $status = match (true) {
                ! $valid => 0,
                $dead === 1 => 1,
                $class === 1 => 2,
                $class === 3 => 3,
                $class === 2 || $class === 5 => 4,
                default => 5,
            };
            if ($valid) {
                $sessions[$session] = true;
                $maxWindow = max($maxWindow, $window);
                $maxPane = max($maxPane, $pane);
            }
            $statuses[$status]++;
            $out[] = $status | $class << 3 | ($valid ? 1 : 0) << 6 | $dead << 7 | $window << 9 | $pane << 19
                | ($valid ? \strlen($session) : 0) << 29 | self::W58_PANE_COLORS[$status] << 36;
        }
        array_push($out, ...$statuses);
        array_push($out, \count($sessions), $maxWindow, $maxPane);

        return $out;
    }

    /** One to three ASCII digits. */
    private static function w58Digits(string $value): bool
    {
        $length = \strlen($value);

        return $length >= 1 && $length <= 3 && strspn($value, '0123456789') === $length;
    }

    /**
     * Wave 58 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w58', self::W58_TMUX_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 58.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w58Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w58RustFfiState('ffi');
            $out = self::w58RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w58RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W58_TMUX_FFI_CDEF.)
            $written = $ffi->nntmux_w58_tmux_pane_status_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
