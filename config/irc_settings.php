<?php

use App\Support\IrcChannelList;

return [
    /***********************************************************************************************************************
     * You can use this to set the NICKNAME=>REALNAME and USERNAME below.
     * You can change them manually below if you have to.
     * @note THIS MUST NOT BE EMPTY=>THIS MUST ALSO BE UNIQUE OR YOU WILL NOT BE ABLE TO CONNECT TO IRC.
     * @note pick a normal name otherwise you will be banned from the pre channel !!!!
     **********************************************************************************************************************/
    'username' => env('SCRAPE_IRC_USERNAME', ''),

    /***********************************************************************************************************************
     * The IRC server to connect to.
     * @note If you have issues connecting=>head to https://www.synirc.net/servers and try another server.
     **********************************************************************************************************************/
    'scrape_irc_server' => env('SCRAPE_IRC_SERVER', 'irc.synirc.net'),

    /***********************************************************************************************************************
     * This is the port to the IRC server.
     * @note If you want use SSL/TLS=>use a corresponding port (6697 or 7001 for example)=>and set SCRAPE_IRC_TLS to true.
     **********************************************************************************************************************/
    'scrape_irc_port' => env('SCRAPE_IRC_PORT', 6667),

    /***********************************************************************************************************************
     * If you want to use SSL/TLS encryption on the IRC server=>set this to true.
     * @note Make sure you use a valid SSL/TLS port in SCRAPE_IRC_PORT.
     **********************************************************************************************************************/
    'scrape_irc_tls' => env('SCRAPE_IRC_TLS', false),

    /***********************************************************************************************************************
     * This is the nick name visible in IRC channels.
     **********************************************************************************************************************/
    'scrape_irc_nickname' => env('SCRAPE_IRC_USERNAME', ''),

    /***********************************************************************************************************************
     * This is a name that is visible to others when they type /whois nickname.
     **********************************************************************************************************************/
    'scrape_irc_realname' => env('SCRAPE_IRC_USERNAME', ''),

    /***********************************************************************************************************************
     * This is used as part of your "ident" when connecting to IRC.
     * @note This is also the username for ZNC.
     **********************************************************************************************************************/
    'scrape_irc_username' => env('SCRAPE_IRC_USERNAME', ''),

    /***********************************************************************************************************************
     * This is not required by synirc=>but if you use ZNC=>this is required.
     * @note Put your password between quotes: 'mypassword'
     * @note If you are using ZNC and having issues=>try 'username:password' or 'username/network:<password>'
     **********************************************************************************************************************/
    'scrape_irc_password' => env('SCRAPE_IRC_PASSWORD', false),

    /***********************************************************************************************************************
     * This is an optional field you can use for ignoring categories.
     * @note If you do not wish to exclude any categories=>leave it a empty string: ''
     * @examples Case sensitive:   '/^(XXX|PDA|EBOOK|MP3)$/'
     *           Case insensitive: '/^(X264|TV)$/i'
     **********************************************************************************************************************/
    'scrape_irc_category_ignore' => '',

    /***********************************************************************************************************************
     * This is an optional field you can use for ignoring PRE titles.
     * @note If you do not wish to exclude any PRE titles=>leave it a empty string: ''
     * @examples Case insensitive ignore German or XXX in the title: '/\.(German|XXX)\./i'
     *           This would ignore titles like:
     *           Yanks.14.06.30.Bianca.Travelman.Is.A.Nudist.XXX.MP4-FUNKY
     *           Blancanieves.Ein.Maerchen.von.Schwarz.und.Weiss.2012.German.1080p.BluRay.x264-CONTRiBUTiON
     **********************************************************************************************************************/
    'scrape_irc_title_ignore' => '',

    /***********************************************************************************************************************
     * This is a list of all the channels we fetch PRE's from.
     **********************************************************************************************************************/

    // Comma-separated, optional ":password" per channel, e.g. "#PreNNTmux,#nZEDbPRE".
    'scrape_irc_channels' => serialize(IrcChannelList::parse(env('SCRAPE_IRC_CHANNELS', '#PreNNTmux'))),

    /***********************************************************************************************************************
     * Additional public scene pre channels. Each enabled network runs as its own `irc:scrape --network=<key>`
     * process (plain `irc:scrape` supervises all enabled networks). They announce only a section and a release
     * name, so entries are stored with the receive time as the PRE time and enriched by the PreDB feeds.
     * The "synirc" network is the server and channels configured above (NNTmux bot format).
     **********************************************************************************************************************/
    'networks' => [
        'synirc' => [
            'enabled' => (bool) env('SCRAPE_IRC_SYNIRC_ENABLED', true),
            'format' => 'nntmux',
        ],
        // Each IRC network's parser reads every channel it joins: PRE lines add releases, INFO lines
        // (in the *.spam channels) only fill in files and size for releases already stored.
        'corruptnet' => [
            'enabled' => (bool) env('SCRAPE_IRC_CORRUPTNET_ENABLED', false),
            'format' => 'corruptnet',
            'source' => 'corrupt-net',
            'server' => env('SCRAPE_IRC_CORRUPTNET_SERVER', 'irc.corrupt-net.org'),
            'port' => (int) env('SCRAPE_IRC_CORRUPTNET_PORT', 6697),
            'tls' => true,
            'channels' => ['#pre' => null, '#Pre.Spam' => null],
        ],
        'zenet' => [
            'enabled' => (bool) env('SCRAPE_IRC_ZENET_ENABLED', false),
            'format' => 'zenet',
            'source' => 'zenet',
            'server' => env('SCRAPE_IRC_ZENET_SERVER', 'irc.zenet.org'),
            'port' => (int) env('SCRAPE_IRC_ZENET_PORT', 6697),
            'tls' => true,
            // zenet's TLS certificate does not name irc.zenet.org; the chain is still verified.
            'tls_verify_peer_name' => false,
            'channels' => ['#pre' => null, '#Pre.Spam' => null],
        ],
        'predatabase' => [
            'enabled' => (bool) env('SCRAPE_IRC_PREDATABASE_ENABLED', false),
            'format' => 'predatabase',
            'source' => 'predataba.se',
            'server' => env('SCRAPE_IRC_PREDATABASE_SERVER', 'irc.predataba.se'),
            'port' => (int) env('SCRAPE_IRC_PREDATABASE_PORT', 6697),
            'tls' => true,
            // #p2ptrace announces P2P releases (WEB-DL groups) that the scene pre channels never carry.
            'channels' => ['#pre' => null, '#pre.spam' => null, '#p2ptrace' => null],
        ],
        'efnet' => [
            'enabled' => (bool) env('SCRAPE_IRC_EFNET_ENABLED', false),
            'format' => 'corruptnet',
            'source' => 'efnet',
            'server' => env('SCRAPE_IRC_EFNET_SERVER', 'irc.efnet.org'),
            // EFnet's servers present self-signed certificates, so verified TLS cannot connect. Plain IRC is
            // the default (the channel is public and no password is sent); set TLS with port 6697 to opt in.
            'port' => (int) env('SCRAPE_IRC_EFNET_PORT', 6667),
            'tls' => (bool) env('SCRAPE_IRC_EFNET_TLS', false),
            'channels' => ['#pre' => null],
        ],
        'abjects' => [
            'enabled' => (bool) env('SCRAPE_IRC_ABJECTS_ENABLED', false),
            'format' => 'zenet',
            'source' => 'abjects',
            'server' => env('SCRAPE_IRC_ABJECTS_SERVER', 'irc.abjects.net'),
            'port' => (int) env('SCRAPE_IRC_ABJECTS_PORT', 6697),
            'tls' => true,
            'channels' => ['#MG-Pre' => null],
        ],
        'ngp' => [
            'enabled' => (bool) env('SCRAPE_IRC_NGP_ENABLED', false),
            'format' => 'ngp',
            'source' => 'ngp.re',
            'server' => env('SCRAPE_IRC_NGP_SERVER', 'irc.ngp.re'),
            'port' => (int) env('SCRAPE_IRC_NGP_PORT', 6697),
            'tls' => true,
            'channels' => ['#ngpre' => null, '#ngpre.p2p.spam' => null],
        ],
        'rizon' => [
            'enabled' => (bool) env('SCRAPE_IRC_RIZON_ENABLED', false),
            'format' => 'rizon',
            'source' => 'rizon',
            'server' => env('SCRAPE_IRC_RIZON_SERVER', 'irc.rizon.net'),
            'port' => (int) env('SCRAPE_IRC_RIZON_PORT', 6697),
            'tls' => true,
            'channels' => ['#pre' => null],
        ],
        // WebSocket push feeds (not IRC): the same rows as the predb.club and predb.net JSON APIs, about
        // a second after the pre. The scheduled feed import still backfills anything missed while disconnected.
        'predbclub_ws' => [
            'enabled' => (bool) env('PREDB_STREAM_PREDB_CLUB_ENABLED', false),
            'type' => 'websocket',
            'format' => 'predb_club',
            'url' => env('PREDB_STREAM_PREDB_CLUB_URL', 'wss://predb.club/api/v1/ws'),
        ],
        'predbnet_ws' => [
            'enabled' => (bool) env('PREDB_STREAM_PREDB_NET_ENABLED', false),
            'type' => 'websocket',
            'format' => 'predb_net',
            'url' => env('PREDB_STREAM_PREDB_NET_URL', 'wss://api.predb.net/ws'),
        ],
    ],

    /***********************************************************************************************************************
     * This is a list of all the sources we fetch PRE's from.
     * If you want to ignore a source=>change it from false to true.
     **********************************************************************************************************************/

    'scrape_irc_source_ignore' => serialize(
        [
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
        ]
    ),
];
