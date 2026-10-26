<?php

declare(strict_types=1);

namespace App\Services;

use App\Facades\Search;
use App\Models\Predb;
use App\Models\UsenetGroup;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Irc\IrcNetworks;
use App\Services\Predb\Irc\PreAnnounceParser;
use App\Services\Predb\PredbIdentityWriter;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Class IRCScraper.
 */
class IRCScraper extends IRCClient
{
    /**
     * Regex to ignore categories.
     */
    protected string|false $_categoryIgnoreRegex = false;

    /**
     * Array of current pre info.
     *
     * @var array<string, mixed>
     */
    protected array $_curPre;

    /**
     * List of groups and their id's.
     *
     * @var array<string, mixed>
     */
    protected array $_groupList;

    /**
     * Array of ignored channels.
     *
     * @var array<string, mixed>
     */
    protected array $_ignoredChannels;

    /**
     * Is this pre nuked or un nuked?
     */
    protected bool $_nuked;

    protected mixed $_oldPre;

    /**
     * Run this in silent mode (no text output).
     */
    protected bool $_silent;

    /**
     * Regex to ignore PRE titles.
     */
    protected string|false $_titleIgnoreRegex = false;

    /**
     * Connection profile from IrcNetworks::resolve().
     *
     * @var array{name: string, type: string, format: string, source: ?string, server: string, port: int, tls: bool, tls_verify_peer_name: ?bool, channels: array<string, ?string>, url: ?string}
     */
    protected array $_network;

    /**
     * Parser for public pre channels; null for the NNTmux bot format.
     */
    protected ?PreAnnounceParser $_announceParser = null;

    protected ?PredbFeedImporter $_importer = null;

    /**
     * Construct.
     *
     * @param  bool  $silent  Run this in silent mode (no text output).
     * @param  bool  $debug  Turn on debug? Shows sent/received socket buffer messages.
     * @param  string  $network  Key in irc_settings.networks to scrape.
     *
     * @throws \Exception
     */
    public function __construct(bool $silent, bool $debug, string $network = IrcNetworks::LEGACY)
    {
        $this->_network = IrcNetworks::resolve($network);
        if ($this->_network['format'] !== 'nntmux') {
            $this->_announceParser = new PreAnnounceParser($this->_network['format'], (string) $this->_network['source']);
            $this->_importer = app(PredbFeedImporter::class);
        }
        $this->_tlsVerifyPeerName = $this->_network['tls_verify_peer_name'];

        if (config('irc_settings.scrape_irc_source_ignore')) {
            try {
                $ignored = unserialize(
                    (string) config('irc_settings.scrape_irc_source_ignore'),
                    ['allowed_classes' => false]
                );
                $this->_ignoredChannels = is_array($ignored) ? $ignored : [];
            } catch (\ValueError $e) {
                $this->_ignoredChannels = [];
            }
        } else {
            $this->_ignoredChannels = [
                '#a.b.cd.image' => false,
                '#a.b.console.ps3' => false,
                '#a.b.dvd' => false,
                '#a.b.erotica' => false,
                '#a.b.flac' => false,
                '#a.b.foreign' => false,
                '#a.b.games.nintendods' => false,
                '#a.b.inner-sanctum' => false,
                '#a.b.moovee' => false,
                '#a.b.movies.divx' => false,
                '#a.b.sony.psp' => false,
                '#a.b.sounds.mp3.complete_cd' => false,
                '#a.b.teevee' => false,
                '#a.b.games.wii' => false,
                '#a.b.warez' => false,
                '#a.b.games.xbox360' => false,
                '#pre@corrupt' => false,
                '#scnzb' => false,
                '#tvnzb' => false,
                'srrdb' => false,
            ];
        }

        if (config('irc_settings.scrape_irc_category_ignore') !== '') {
            $this->_categoryIgnoreRegex = (string) config('irc_settings.scrape_irc_category_ignore');
        }

        if (config('irc_settings.scrape_irc_title_ignore') !== '') {
            $this->_titleIgnoreRegex = (string) config('irc_settings.scrape_irc_title_ignore');
        }

        $this->_groupList = [];
        $this->_silent = $silent;
        $this->_debug = $debug;
        $this->_resetPreVariables();
        $this->_startScraping();
    }

    /**
     * Main method for scraping.
     */
    protected function _startScraping(): void
    {
        $server = $this->_network['server'];
        $port = $this->_network['port'];

        // Connect to IRC.
        if ($this->connect($server, $port, $this->_network['tls']) === false) {
            $this->fail('Error connecting to ('.$server.':'.$port.'). Please verify your server information and try again.');
        }

        // Normalize password to ?string. The server password belongs to the configured (synirc/ZNC) server only.
        $password = $this->_network['name'] === IrcNetworks::LEGACY ? config('irc_settings.scrape_irc_password') : null;
        $password = ($password === false || $password === '' || $password === null) ? null : (string) $password;

        // Login to IRC. Note parameter order: nick, user, real, pass.
        if ($this->login((string) config('irc_settings.scrape_irc_nickname'), (string) config('irc_settings.scrape_irc_username'), (string) config('irc_settings.scrape_irc_realname'), $password) === false) {
            $this->fail(
                'Error logging in to: ('.$server.':'.$port.') nickname: ('.config('irc_settings.scrape_irc_nickname').
                '). Verify your connection information, you might also be banned from this server or there might have been a connection issue.'
            );
        }

        // Join channels.
        $this->joinChannels($this->_network['channels']);

        if (! $this->_silent) {
            echo '['.
                date('r').
                '] [Scraping of IRC channels for ('.$server.':'.$port.') ('.
                config('irc_settings.scrape_irc_nickname').
                ') started.]'.
                PHP_EOL;
        }

        // Scan incoming IRC messages.
        $this->readIncoming();
    }

    /**
     * Report a fatal connection or login error and exit non-zero, so a supervisor can back off.
     */
    protected function fail(string $message): never
    {
        fwrite(STDERR, $message.PHP_EOL);

        exit(1);
    }

    /**
     * Process bot messages, insert/update PREs.
     *
     * @throws \Exception
     */
    protected function processChannelMessages(): void
    {
        if ($this->_debug && ! $this->_silent) {
            echo '[DEBUG] Processing message: '.$this->_channelData['message'].PHP_EOL;
        }

        if ($this->_announceParser !== null) {
            $this->processAnnounce();

            return;
        }

        $hits = self::parseBotMessage($this->_channelData['message']);
        if ($hits !== null) {
            if ($this->_debug && ! $this->_silent) {
                echo '[DEBUG] Regex matched! Title: '.$hits['title'].' | Source: '.$hits['source'].' | Category: '.$hits['category'].PHP_EOL;
            }

            if (isset($this->_ignoredChannels[$hits['source']]) && $this->_ignoredChannels[$hits['source']] === true) {
                if ($this->_debug && ! $this->_silent) {
                    echo '[DEBUG] Source '.$hits['source'].' is ignored, skipping...'.PHP_EOL;
                }

                return;
            }

            if ($this->_categoryIgnoreRegex !== false && preg_match((string) $this->_categoryIgnoreRegex, $hits['category'])) {
                if ($this->_debug && ! $this->_silent) {
                    echo '[DEBUG] Category '.$hits['category'].' is ignored by regex, skipping...'.PHP_EOL;
                }

                return;
            }

            if ($this->_titleIgnoreRegex !== false && preg_match((string) $this->_titleIgnoreRegex, $hits['title'])) {
                if ($this->_debug && ! $this->_silent) {
                    echo '[DEBUG] Title '.$hits['title'].' is ignored by regex, skipping...'.PHP_EOL;
                }

                return;
            }

            $utime = Carbon::createFromTimeString($hits['time'], 'UTC')->timestamp;

            $this->_curPre['predate'] = 'FROM_UNIXTIME('.$utime.')';
            $this->_curPre['title'] = $hits['title'];
            $this->_curPre['source'] = $hits['source'];
            if ($hits['category'] !== 'N/A') {
                $this->_curPre['category'] = $hits['category'];
            }
            if ($hits['req_id'] !== '') {
                $this->_curPre['reqid'] = $hits['req_id'];
                $this->_curPre['group_id'] = $this->_getGroupID($hits['req_group']);
            }
            if ($hits['size'] !== 'N/A') {
                $this->_curPre['size'] = $hits['size'];
            }
            if ($hits['files'] !== 'N/A') {
                $this->_curPre['files'] = substr($hits['files'], 0, 50);
            }

            if (isset($hits['filename']) && $hits['filename'] !== 'N/A') {
                $this->_curPre['filename'] = $hits['filename'];
            }

            if (isset($hits['nuked'])) {
                if ($hits['nuke_status'] !== '') {
                    $this->_curPre['nuked'] = (int) $hits['nuke_status'];
                }
                $this->_curPre['reason'] = (isset($hits['reason']) ? substr($hits['reason'], 0, 255) : '');
                $this->_nuked = true; // flag for output
            }
            $this->_checkForDupe();
        } else {
            if ($this->_debug && ! $this->_silent) {
                echo '[DEBUG] Message did not match PRE regex pattern'.PHP_EOL;
            }
        }
    }

    /**
     * Parse bot captures, request IDs and nuke status in a single native call.
     * Short messages (< 512 bytes) default to PCRE JIT where single-call array
     * materialization across FFI is < 1.5x; long messages (>= 512 bytes) and
     * explicit `$useRust = true` callers use the 3.00x native parser.
     *
     * @return array<string, string>|null
     */
    protected static function parseBotMessage(string $message, bool $useRust = false): ?array
    {
        $length = strlen($message);
        if ($useRust || $length >= 512) {
            static $native = null;
            static $buffer = null;
            static $capacity = 0;
            $native ??= RustPipelineLibrary::resolveDomain('irc_pre_announce',
                // Require the fused symbol as well: older candidates emitted a different bot record.
                'ssize_t nntmux_irc_parse_inbound_line(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                 ssize_t nntmux_irc_parse_bot_message(const char *input, size_t input_len, char *out_buf, size_t out_cap);') ?? false;
            if ($native instanceof FFI) {
                if ($buffer === null || $capacity < $length * 2 + 32) {
                    // req_group is also part of the original req capture in the wire record.
                    $buffer = RustPipelineLibrary::obtainScratch($native, $length * 2 + 32, $capacity);
                }
                if ($buffer !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $native->nntmux_irc_parse_bot_message($message, $length, $buffer, $capacity);
                    if ($written === 0) {
                        return null;
                    }
                    if ($written > 0) {
                        $values = explode("\0", FFI::string($buffer, $written));
                        if (! isset($values[9])) {
                            return [
                                'time' => $values[0], 'title' => $values[1],
                                'source' => $values[2], 'category' => $values[3],
                                'req' => $values[4], 'size' => $values[5], 'files' => $values[6],
                                'req_id' => $values[7], 'req_group' => $values[8], 'nuke_status' => '',
                            ];
                        }
                        if (! isset($values[10])) {
                            return [
                                'time' => $values[0], 'title' => $values[1],
                                'source' => $values[2], 'category' => $values[3],
                                'req' => $values[4], 'size' => $values[5], 'files' => $values[6],
                                'filename' => $values[9],
                                'req_id' => $values[7], 'req_group' => $values[8], 'nuke_status' => '',
                            ];
                        }

                        return [
                            'time' => $values[0], 'title' => $values[1],
                            'source' => $values[2], 'category' => $values[3],
                            'req' => $values[4], 'size' => $values[5], 'files' => $values[6],
                            'filename' => $values[9], 'nuked' => $values[10], 'reason' => $values[11],
                            'req_id' => $values[7], 'req_group' => $values[8], 'nuke_status' => $values[12],
                        ];
                    }
                }
            }
        }

        return self::parseBotMessageFieldsPhp($message);
    }

    /**
     * PHP branch of parseBotMessage(): the same captures, request ID and nuke status fields.
     *
     * @return array<string, string>|null
     */
    protected static function parseBotMessageFieldsPhp(string $message): ?array
    {
        $hits = self::parseBotMessagePhp($message);

        if ($hits === null) {
            return null;
        }
        $hits = array_filter($hits, static fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
        $hits['req_id'] = '';
        $hits['req_group'] = '';
        if ($hits['req'] !== 'N/A' && preg_match('/^(?P<req>\d+):(?P<group>.+)$/i', $hits['req'], $req)) {
            $hits['req_id'] = $req['req'];
            $hits['req_group'] = $req['group'];
        }
        $hits['nuke_status'] = match ($hits['nuked'] ?? '') {
            'NUKED' => (string) Predb::PRE_NUKED,
            'UNNUKED' => (string) Predb::PRE_UNNUKED,
            'MODNUKED' => (string) Predb::PRE_MODNUKE,
            'RENUKED' => (string) Predb::PRE_RENUKED,
            'OLDNUKE' => (string) Predb::PRE_OLDNUKE,
            default => '',
        };

        return $hits;
    }

    /** @return array<int|string, string>|null */
    protected static function parseBotMessagePhp(string $message): ?array
    {
        if (preg_match(
            '/^(NEW|UPD|NUK): \[DT: (?P<time>.+?)\]\s?\[TT: (?P<title>.+?)\]\s?\[SC: (?P<source>.+?)\]\s?\[CT: (?P<category>.+?)\]\s?\[RQ: (?P<req>.+?)\]'.
            '\s?\[SZ: (?P<size>.+?)\]\s?\[FL: (?P<files>.+?)\]\s?(\[FN: (?P<filename>.+?)\]\s?)?(\[(?P<nuked>(UN|MOD|RE|OLD)?NUKED?): (?P<reason>.+?)\])?$/i',
            $message,
            $hits
        )) {
            return $hits;
        }

        return null;
    }

    /**
     * Store a pre announced on a public pre channel, using the PreDB feed import rules.
     *
     * @throws \Exception
     */
    protected function processAnnounce(): void
    {
        $entry = $this->_announceParser?->parse($this->_channelData['message'], CarbonImmutable::now('UTC'));
        if ($entry === null || $this->_importer === null) {
            return;
        }

        if (($this->_categoryIgnoreRegex !== false && $entry->category !== null && preg_match((string) $this->_categoryIgnoreRegex, $entry->category))
            || ($this->_titleIgnoreRegex !== false && preg_match((string) $this->_titleIgnoreRegex, $entry->title))) {
            return;
        }

        $result = $this->_importer->import([$entry]);

        if (! $this->_silent && $result['skipped'] === 0) {
            echo '['.date('r').'] ['.($result['inserted'] > 0 ? 'Added Pre ' : ($entry->enrichOnly ? 'Info Pre  ' : 'Updated Pre')).'] ['.
                $entry->source.'] ['.$entry->title.']'.($entry->category !== null ? ' ['.$entry->category.']' : '').PHP_EOL;
        }
    }

    /**
     * Check if we already have the PRE, update if we have it, insert if not.
     *
     * @throws \Exception
     */
    protected function _checkForDupe(): void
    {
        $this->_oldPre = Predb::query()->where('title', $this->_curPre['title'])->select(['id', 'title', 'category', 'size', 'source'])->first();
        if ($this->_oldPre === null) {
            if ($this->_debug && ! $this->_silent) {
                echo '[DEBUG] New PRE found, inserting: '.$this->_curPre['title'].PHP_EOL;
            }
            $this->_insertNewPre();
        } else {
            if ($this->_debug && ! $this->_silent) {
                echo '[DEBUG] PRE already exists, updating: '.$this->_curPre['title'].PHP_EOL;
            }
            $this->_updatePre();
        }
        $this->_resetPreVariables();
    }

    /**
     * Insert new PRE into the DB.
     *
     *
     * @throws \Exception
     */
    protected function _insertNewPre(): void
    {
        // Check if title is empty first
        if (empty($this->_curPre['title'])) {
            if ($this->_debug && ! $this->_silent) {
                echo '[DEBUG] PRE title is empty, skipping insert'.PHP_EOL;
            }

            return;
        }

        $connection = DB::connection();
        $ambient = $connection->transactionLevel() > 0;
        $current = $this->_curPre;
        $written = (new PredbIdentityWriter($connection))->insertLegacy($current);
        if ($written['status'] === 'source_rejected') {
            $failure = $written['source_failure'];
            if (! $failure instanceof QueryException) {
                throw new RuntimeException('PreDB writer source rejection lacks its original failure.');
            }
            if (! $failure instanceof UniqueConstraintViolationException && ! str_starts_with((string) $failure->getCode(), '22')) {
                throw $failure;
            }
            if ($this->_debug && ! $this->_silent) {
                echo '[DEBUG] ERROR inserting PRE: '.$failure->getMessage().PHP_EOL;
            }

            return;
        }
        if (in_array($written['status'], ['noop', 'exists'], true)) {
            return;
        }
        if ($written['status'] !== 'inserted' || $written['id'] === null) {
            throw new RuntimeException('PreDB writer did not return the inserted legacy owner.');
        }
        $parameters = [
            'id' => (string) $written['id'],
            'title' => $current['title'],
            'filename' => $current['filename'] ?? null,
            'source' => $current['source'] ?? null,
        ];
        $this->publishPredbDocument($connection, $ambient, 'insert', $parameters);
        $this->_doEcho(true);
    }

    /** Updates PRE data while the observed literal title remains current. */
    protected function _updatePre(): void
    {
        if (empty($this->_curPre['title']) || $this->_oldPre === null
            || PredbSearchDocument::exactValue((string) ($this->_oldPre['title'] ?? '')) !== PredbSearchDocument::exactValue((string) $this->_curPre['title'])
        ) {
            return;
        }
        $connection = DB::connection();
        $ambient = $connection->transactionLevel() > 0;
        $written = (new PredbIdentityWriter($connection))->updateLegacy(
            (int) $this->_oldPre['id'],
            (string) $this->_oldPre['title'],
            $this->_curPre,
        );
        if ($written['status'] === 'source_rejected') {
            $failure = $written['source_failure'];
            if (! $failure instanceof QueryException) {
                throw new RuntimeException('PreDB writer source rejection lacks its original failure.');
            }
            // Legacy update previously propagated source errors; keep the exact object.
            throw $failure;
        }
        if ($written['status'] === 'noop') {
            return;
        }
        if ($written['status'] === 'stale') {
            $this->_doEcho(false);

            return;
        }
        if (! in_array($written['status'], ['updated', 'unchanged'], true) || $written['row'] === null) {
            throw new RuntimeException('PreDB writer did not return an accepted legacy update row.');
        }
        $row = $written['row'];
        $parameters = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'filename' => $row['filename'],
            'source' => $row['source'],
        ];
        $this->publishPredbDocument($connection, $ambient, 'update', $parameters);
        $this->_doEcho(false);
    }

    /** @param array<string, mixed> $parameters */
    private function publishPredbDocument(Connection $connection, bool $ambient, string $method, array $parameters): void
    {
        $publish = static function () use ($method, $parameters): void {
            if ($method === 'insert') {
                Search::insertPredb($parameters);
            } else {
                Search::updatePreDb($parameters);
            }
        };
        if ($ambient) {
            $connection->afterCommit($publish);
        } else {
            $publish();
        }
    }

    /**
     * Echo new or update pre to CLI.
     */
    protected function _doEcho(bool $new = true): void
    {
        if (! $this->_silent) {
            $nukeString = '';
            if ($this->_nuked !== false) {
                switch ((int) $this->_curPre['nuked']) {
                    case Predb::PRE_NUKED:
                        $nukeString = '[ NUKED ] ';
                        break;
                    case Predb::PRE_UNNUKED:
                        $nukeString = '[UNNUKED] ';
                        break;
                    case Predb::PRE_MODNUKE:
                        $nukeString = '[MODNUKE] ';
                        break;
                    case Predb::PRE_OLDNUKE:
                        $nukeString = '[OLDNUKE] ';
                        break;
                    case Predb::PRE_RENUKED:
                        $nukeString = '[RENUKED] ';
                        break;
                    default:
                        break;
                }
                $nukeString .= '['.($this->_curPre['reason'] ?? '').'] ';
            }

            echo '['.
                date('r').
                ($new ? '] [ Added Pre ] [' : '] [Updated Pre] [').
                ($this->_curPre['source'] ?? '').
                '] '.
                $nukeString.
                '['.
                $this->_curPre['title'].
                ']'.
                (
                    ! empty($this->_curPre['category'])
                    ? ' ['.$this->_curPre['category'].']'
                    : (
                        ! empty($this->_oldPre['category'])
                        ? ' ['.$this->_oldPre['category'].']'
                        : ''
                    )
                ).
                (! empty($this->_curPre['size']) ? ' ['.$this->_curPre['size'].']' : '').
                PHP_EOL;
        }
    }

    /**
     * Get a group id for a group name.
     */
    protected function _getGroupID(string $groupName): mixed
    {
        if (! isset($this->_groupList[$groupName])) {
            $group = UsenetGroup::query()->where('name', $groupName)->first(['id']);
            $this->_groupList[$groupName] = $group !== null ? $group['id'] : '';
        }

        return $this->_groupList[$groupName];
    }

    /**
     * After updating or inserting new PRE, reset these.
     */
    protected function _resetPreVariables(): void
    {
        $this->_nuked = false;
        $this->_oldPre = [];
        $this->_curPre =
            [
                'title' => '',
                'size' => '',
                'predate' => '',
                'category' => '',
                'source' => '',
                'group_id' => '',
                'reqid' => '',
                'nuked' => '',
                'reason' => '',
                'files' => '',
                'filename' => '',
            ];
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all nine runs (min 3.12x; see the W44 parity test). */
    public const bool W441_RUST_FFI_DEFAULT = true;

    public const string W441_FFI_CDEF = <<<'C'
        ssize_t nntmux_w441_irc_scraper_bot_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W441_SUMMARY_KEYS = ['total_messages', 'empty_message_count', 'bot_message_count', 'request_id_count', 'filename_count', 'nuke_tag_count', 'nuke_status_count', 'update_count'];

    private static ?bool $w441UseRustFfi = null;

    private static FFI|false|null $w441Ffi = null;

    /** Null restores the benchmark default; independent of the IRCClient toggle. */
    public static function useW441RustFfi(?bool $enable = true): void
    {
        self::$w441UseRustFfi = $enable;
    }

    public static function usesW441RustFfi(): bool
    {
        return self::$w441UseRustFfi ?? self::W441_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW441RustFfi(): void
    {
        self::$w441UseRustFfi = null;
        self::$w441Ffi = null;
    }

    /**
     * Summarizes channel messages with parseBotMessageFieldsPhp(): bot announcements, those with a
     * request ID, a filename, a nuke tag (any accepted variant), a mapped nuke status, and UPD
     * announcements. Entries come from strings, integers, or the first non-null message, line array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeBotMessageBatch(array $rows): array
    {
        return self::usesW441RustFfi() ? self::summarizeBotMessageBatchNative($rows) : self::summarizeBotMessageBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable, a message holds
     * NUL, or the native matcher exhausts its backtracking budget.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeBotMessageBatchNative(array $rows): array
    {
        $values = XrefService::w441Invoke(self::$w441Ffi, self::W441_FFI_CDEF, 'nntmux_w441_irc_scraper_bot_batch', $rows, ['message', 'line'], \count(self::W441_SUMMARY_KEYS));

        return $values === null ? self::summarizeBotMessageBatchPhp($rows) : array_combine(self::W441_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeBotMessageBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W441_SUMMARY_KEYS, 0);
        foreach (XrefService::w441Entries($rows, ['message', 'line']) as $entry) {
            $summary['total_messages']++;
            if ($entry === '') {
                $summary['empty_message_count']++;

                continue;
            }
            $hits = self::parseBotMessageFieldsPhp($entry);
            if ($hits === null) {
                continue;
            }
            $summary['bot_message_count']++;
            $summary['request_id_count'] += (int) ($hits['req_id'] !== '');
            $summary['filename_count'] += (int) (($hits['filename'] ?? '') !== '');
            $summary['nuke_tag_count'] += (int) (($hits['nuked'] ?? '') !== '');
            $summary['nuke_status_count'] += (int) ($hits['nuke_status'] !== '');
            $summary['update_count'] += (int) (strncasecmp($entry, 'UPD', 3) === 0);
        }

        return $summary;
    }

    // ========================================================================
    // WAVE 63.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.54x, p99 5.72x; uncommon mean 5.50x, p99 5.58x); see RustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_IRC_SCRAPER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w631_irc_scraper_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w63IrcScraperPlanBatch(). */
    public const int W63_IRC_SCRAPER_TRAILER = 27;

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /** Forces the IRCScraper Wave 63 Rust path on until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Forces the IRCScraper Wave 63 PHP path until reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    /**
     * Runs $callback with the IRCScraper Wave 63 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 63.1 PreDB bot announcement plans. Each item is a trimmed channel message. Its prefix is 1 `NEW: `, 2
     * `UPD: ` or 3 `NUK: ` (case-insensitive), else 0. `[NAME: value]` tags (2-8 letters, first occurrence wins,
     * flag 2 on a repeat, flag 4 when a tag never closes, flag 8 on an unknown name) set mask bits 0-7 for DT TT SC
     * CT RQ SZ FL FN and bit 8 for NUKED UNNUKED MODNUKED RENUKED OLDNUKE (nuke 1-5). The category is 0 N/A or
     * empty, 1 TV, 2 movies, 3 music, 4 games, 5 apps, 6 XXX, 7 books, 8 anime, 15 other (lowercased prefix). Size
     * is `digits(.digits)? unit` in MiB (B KB KiB MB MiB GB GiB TB TiB), files 1-9 digits and the request
     * `digits:group`; N/A skips each, and anything else sets flag 128. Other flags: 1 title repeated in the batch
     * (case-insensitive), 16 non-ASCII, 32 control byte, 64 `#` source. Decision: 0 not an announcement, 1 missing
     * DT-FL tag or empty title, 4 nuke, 3 update or repeated title, 2 new. Per item `decision | prefix << 3 | mask
     * << 5 | nuke << 14 | category << 17 | flags << 21 | bitLength(mib) << 29 | min(files, 4095) << 35 |
     * bitLength(requestId) << 47 | min(titleLength, 511) << 53`; then 5 decision counts, 8 flag counts, 9 tag
     * counts, the MiB sum of decisions 2-3 (each capped at 2^40), the file sum, the distinct titles and sources and
     * the largest file count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63IrcScraperPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63IrcScraperPlanBatchNative($items) : self::w63IrcScraperPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63IrcScraperPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_IRC_SCRAPER_TRAILER) ?? self::w63IrcScraperPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63IrcScraperPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W63_IRC_SCRAPER_TRAILER, 0);
        $titles = [];
        $sources = [];
        foreach ($items as $item) {
            $m = trim((string) $item);
            $prefix = self::W63_SCRAPER_PREFIXES[strtolower(substr($m, 0, 5))] ?? 0;
            $flags = $mask = $nuke = 0;
            $values = ['', '', '', '', '', '', '', ''];
            $pos = 0;
            while (($at = strpos($m, '[', $pos)) !== false) {
                $nameLength = strspn($m, self::W63_SCRAPER_LETTERS, $at + 1);
                $colon = $at + 1 + $nameLength;
                if ($nameLength < 2 || $nameLength > 8 || substr($m, $colon, 2) !== ': ') {
                    $pos = $at + 1;

                    continue;
                }
                $start = $colon + 2;
                $close = strpos($m, ']', $start);
                if ($close === false) {
                    $flags |= 4;
                    break;
                }
                $value = substr($m, $start, $close - $start);
                $pos = $close + 1;
                $name = strtoupper(substr($m, $at + 1, $nameLength));
                if (isset(self::W63_SCRAPER_TAGS[$name])) {
                    $bit = self::W63_SCRAPER_TAGS[$name];
                } elseif (isset(self::W63_SCRAPER_NUKES[$name])) {
                    $bit = 8;
                } else {
                    $flags |= 8;

                    continue;
                }
                if (($mask >> $bit & 1) === 1) {
                    $flags |= 2;

                    continue;
                }
                $mask |= 1 << $bit;
                if ($bit === 8) {
                    $nuke = self::W63_SCRAPER_NUKES[$name];
                } else {
                    $values[$bit] = $value;
                }
            }
            $title = trim($values[1]);
            $category = 0;
            if (($mask & 8) !== 0) {
                $c = strtolower(trim($values[3]));
                if ($c !== '' && $c !== 'n/a') {
                    $category = 15;
                    foreach (self::W63_SCRAPER_CATEGORIES as [$categoryPrefix, $kind]) {
                        if (str_starts_with($c, $categoryPrefix)) {
                            $category = $kind;
                            break;
                        }
                    }
                }
            }
            $invalid = false;
            $mib = 0;
            if (($mask & 32) !== 0) {
                $size = self::w63ScraperSize($values[5]);
                if ($size === false) {
                    $invalid = true;
                } else {
                    $mib = $size ?? 0;
                }
            }
            $files = 0;
            if (($mask & 64) !== 0) {
                $f = trim($values[6]);
                if (strtolower($f) !== 'n/a') {
                    $length = \strlen($f);
                    if ($length >= 1 && $length <= 9 && strspn($f, '0123456789') === $length) {
                        $files = (int) $f;
                    } else {
                        $invalid = true;
                    }
                }
            }
            $request = 0;
            if (($mask & 16) !== 0) {
                $r = trim($values[4]);
                if (strtolower($r) !== 'n/a') {
                    $c = strpos($r, ':');
                    if ($c !== false && $c >= 1 && $c <= 12 && strspn($r, '0123456789', 0, $c) === $c && $c + 1 < \strlen($r)) {
                        $request = (int) substr($r, 0, $c);
                    } else {
                        $invalid = true;
                    }
                }
            }
            $flags |= $invalid ? 128 : 0;
            $source = trim($values[2]);
            if ($source !== '') {
                $flags |= $source[0] === '#' ? 64 : 0;
                $sources[strtolower($source)] = true;
            }
            if ($title !== '') {
                $lower = strtolower($title);
                if (isset($titles[$lower])) {
                    $flags |= 1;
                } else {
                    $titles[$lower] = true;
                }
            }
            $flags |= (preg_match('/[\x80-\xff]/', $m) === 1 ? 16 : 0) | (strcspn($m, self::W63_SCRAPER_CONTROL_BYTES) !== \strlen($m) ? 32 : 0);
            $decision = match (true) {
                $prefix === 0 => 0,
                ($mask & 0x7F) !== 0x7F || $title === '' => 1,
                ($mask & 0x100) !== 0 || $prefix === 3 => 4,
                $prefix === 2 || ($flags & 1) !== 0 => 3,
                default => 2,
            };
            $tail[$decision]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[5 + $b] += $flags >> $b & 1;
            }
            for ($b = 0; $b < 9; $b++) {
                $tail[13 + $b] += $mask >> $b & 1;
            }
            if ($decision === 2 || $decision === 3) {
                $tail[22] += min($mib, 1 << 40);
            }
            $tail[23] += $files;
            $tail[26] = max($tail[26], $files);
            $out[] = $decision | $prefix << 3 | $mask << 5 | $nuke << 14 | $category << 17 | $flags << 21 | self::w63BitLength($mib) << 29
                | min($files, 4095) << 35 | self::w63BitLength($request) << 47 | min(\strlen($title), 511) << 53;
        }
        $tail[24] = \count($titles);
        $tail[25] = \count($sources);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, int> */
    private const array W63_SCRAPER_PREFIXES = ['new: ' => 1, 'upd: ' => 2, 'nuk: ' => 3];

    private const string W63_SCRAPER_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** @var array<string, int> */
    private const array W63_SCRAPER_TAGS = ['DT' => 0, 'TT' => 1, 'SC' => 2, 'CT' => 3, 'RQ' => 4, 'SZ' => 5, 'FL' => 6, 'FN' => 7];

    /** @var array<string, int> */
    private const array W63_SCRAPER_NUKES = ['NUKED' => 1, 'UNNUKED' => 2, 'MODNUKED' => 3, 'RENUKED' => 4, 'OLDNUKE' => 5];

    /** @var list<array{string, int}> */
    private const array W63_SCRAPER_CATEGORIES = [['tv', 1], ['movie', 2], ['x264', 2], ['x265', 2], ['bluray', 2], ['dvdr', 2], ['xvid', 2],
        ['mp3', 3], ['flac', 3], ['music', 3], ['mvid', 3], ['games', 4], ['ps', 4], ['xbox', 4], ['nsw', 4], ['wii', 4], ['apps', 5], ['0day', 5],
        ['xxx', 6], ['ebook', 7], ['books', 7], ['audiobook', 7], ['anime', 8]];

    private const string W63_SCRAPER_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** A `[SZ: ...]` value in MiB: null for N/A, false when malformed. */
    private static function w63ScraperSize(string $raw): int|false|null
    {
        $size = trim($raw);
        if (strtolower($size) === 'n/a') {
            return null;
        }
        $intLength = strspn($size, '0123456789');
        if ($intLength === 0 || $intLength > 9) {
            return false;
        }
        $at = $intLength;
        $fraction = 0;
        if (substr($size, $at, 1) === '.') {
            $fractionLength = strspn($size, '0123456789', $at + 1);
            if ($fractionLength === 0 || $fractionLength > 6) {
                return false;
            }
            $fraction = (int) str_pad(substr($size, $at + 1, min(3, $fractionLength)), 3, '0');
            $at += 1 + $fractionLength;
        }
        $at += strspn($size, ' ', $at);
        [$numerator, $denominator] = match (strtolower(substr($size, $at))) {
            'b' => [1, 1048576],
            'kb', 'kib' => [1, 1024],
            'mb', 'mib' => [1, 1],
            'gb', 'gib' => [1024, 1],
            'tb', 'tib' => [1048576, 1],
            default => [0, 0],
        };
        if ($denominator === 0) {
            return false;
        }

        return intdiv(((int) substr($size, 0, $intLength) * 1000 + $fraction) * $numerator, 1000 * $denominator);
    }

    private static function w63BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Wave 63 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('irc_scraper_w631', self::W63_IRC_SCRAPER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w63RustFfiState('ffi');
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
                $handle = self::w63RustFfiState('ffi');
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
     * Calls the Wave 63.1 IRCScraper export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w63Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w63RustFfiState('ffi');
            $out = self::w63RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w63RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W63_IRC_SCRAPER_FFI_CDEF.)
            $written = $ffi->nntmux_w631_irc_scraper_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
