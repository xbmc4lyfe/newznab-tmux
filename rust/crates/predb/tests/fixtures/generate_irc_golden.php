<?php
// Regenerates irc_golden.json: announce lines through the PHP parser and restart delays.
// php generate_irc_golden.php <php-final checkout> > irc_golden.json
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Console\Commands\IrcScraperCommand;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Irc\PreAnnounceParser;
use App\Services\Predb\Stream\PredbStreamListener;
use App\Services\Predb\Stream\WebSocketClient;
use Carbon\CarbonImmutable;

$now = CarbonImmutable::parse('2026-08-01 10:00:00', 'UTC');
$lines = [
    'corruptnet' => ['PRE: [FLAC] Name.Album-GRP', 'PRE: [] Name-GRP', 'INFO: Name-GRP [14F 67.5MB]', 'INFO: Name-GRP [0F 1MB]', 'NUKE: Name-GRP [bad.rip]', 'UNNUKE: Name-GRP []', 'MODNUKE: Name-GRP [x] [NUKENET]', 'RENUKE: Name-GRP [y]', 'OLDNUKE: Name-GRP [z]', 'pre: [X] Name-GRP', 'PRE: [X] NoGroup', 'PRE: [X] Sp ace-GRP', "PRE: [X] Name-GRP\n", '  PRE: [FLAC] Trimmed-GRP  ', 'PRE: [X] Accénted.Name-GRP'],
    'zenet' => ['(PRE) (MP3-WEB) (Name-GRP)', '(pre) (x) (Name-GRP)', '(INFO) (Name-GRP) (2 Files) (38 MB)', '(INFO) (Name-GRP) (1 File) (0.5 MB)', '(NUKE) (Name-GRP) (reason here)', '(PRE) () (Name-GRP)'],
    'predatabase' => ['pre | MP3-WEB | Name-GRP', 'info | Name-GRP - 14 F & 67 MB', 'p2p | #1 | BTN | Name-GRP, WIN!', 'p2p | #2 | BTN | Name-GRP, WIN!', 'pre |  | Name-GRP'],
    'ngp' => ['.::: PRE ::: TV-X264-HD ::: Name-GRP :::.', '[INFO] FOR Name-GRP with 5F 158.3MB added.', '[INFO] FOR Name-GRP with 5F 158.3MB added', '.::: NUKE ::: X ::: Name-GRP :::.', '.::: BOGUS ::: X ::: Name-GRP :::.'],
    'rizon' => ['[PRE]  [TV-HD]  Name-GRP', '[NUKE] Name-GRP [dupe] [NUKENET]', '[PRE] Name-GRP'],
    'opentrackers' => ['PRE: [FLAC] Name-GRP', '(PRE) (MP3) (Name-GRP)', 'pre | X | Name-GRP', '[PRE] [X] Name-GRP', 'INFO: Name-GRP [14F 67MB]', 'NUKE: Name-GRP [why]', 'p2p | #1 | BTN | Name-GRP, WIN!', 'junk'],
];
$dump = static fn (?PredbFeedEntry $e): ?array => $e === null ? null : [
    'title' => $e->title, 'source' => $e->source, 'category' => $e->category, 'size' => $e->size, 'files' => $e->files,
    'predate' => $e->predate?->format('Y-m-d H:i:s'), 'nuked' => $e->nuked, 'reason' => $e->nukeReason,
    'listed' => $e->listedAt?->format('Y-m-d H:i:s'), 'enrich' => $e->enrichOnly,
];
$announce = [];
foreach ($lines as $format => $messages) {
    $parser = new PreAnnounceParser($format, 'src');
    foreach ($messages as $message) {
        $announce[] = ['format' => $format, 'message' => $message, 'out' => $dump($parser->parse($message, $now))];
    }
}
$streams = [];
foreach ([['predb_club', '{"action":"insert","row":{"name":"A-GRP","cat":"TV","size":10,"files":2,"preAt":1600000000,"nuke":null}}'],
    ['predb_club', '{"action":"delete","row":{"name":"A-GRP"}}'], ['predb_club', '{"hb":1}'], ['predb_club', 'not json'],
    ['predb_net', '{"message":{"pretime":1600000000,"release":"B-GRP","section":"MP3","group":"GRP"}}'], ['predb_net', '{"message":"text"}']] as [$format, $message]) {
    $listener = new PredbStreamListener(new WebSocketClient('ws://x'), $format, app(App\Services\Predb\Feeds\PredbFeedImporter::class));
    $streams[] = ['format' => $format, 'message' => $message, 'out' => array_map($dump, $listener->entries($message))];
}
echo json_encode(['announce' => $announce, 'streams' => $streams,
    'delays' => array_map(static fn (int $f): int => IrcScraperCommand::restartDelay($f), [1, 2, 3, 4, 7, 8, 30])],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
