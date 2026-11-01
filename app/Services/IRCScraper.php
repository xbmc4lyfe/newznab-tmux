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
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

        if (preg_match(
            '/^(NEW|UPD|NUK): \[DT: (?P<time>.+?)\]\s?\[TT: (?P<title>.+?)\]\s?\[SC: (?P<source>.+?)\]\s?\[CT: (?P<category>.+?)\]\s?\[RQ: (?P<req>.+?)\]'.
            '\s?\[SZ: (?P<size>.+?)\]\s?\[FL: (?P<files>.+?)\]\s?(\[FN: (?P<filename>.+?)\]\s?)?(\[(?P<nuked>(UN|MOD|RE|OLD)?NUKED?): (?P<reason>.+?)\])?$/i',
            $this->_channelData['message'],
            $hits
        )) {
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
            if ($hits['req'] !== 'N/A' && preg_match('/^(?P<req>\d+):(?P<group>.+)$/i', $hits['req'], $matches2)) {
                $this->_curPre['reqid'] = $matches2['req'];
                $this->_curPre['group_id'] = $this->_getGroupID($matches2['group']);
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
                switch ($hits['nuked']) {
                    case 'NUKED':
                        $this->_curPre['nuked'] = Predb::PRE_NUKED;
                        break;
                    case 'UNNUKED':
                        $this->_curPre['nuked'] = Predb::PRE_UNNUKED;
                        break;
                    case 'MODNUKED':
                        $this->_curPre['nuked'] = Predb::PRE_MODNUKE;
                        break;
                    case 'RENUKED':
                        $this->_curPre['nuked'] = Predb::PRE_RENUKED;
                        break;
                    case 'OLDNUKE':
                        $this->_curPre['nuked'] = Predb::PRE_OLDNUKE;
                        break;
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
}
