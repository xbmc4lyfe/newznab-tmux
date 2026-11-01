<?php
// Regenerates feed_golden.json: each sample payload run through the PHP source's parser.
// php generate_feed_golden.php <php-final checkout> > feed_golden.json
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\ApibaySource;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\PredbOrgSource;
use App\Services\Predb\Feeds\Sources\PredbOvhSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use App\Services\Predb\Feeds\Sources\SrrdbSource;
use App\Services\Predb\Feeds\Sources\XrelSource;

date_default_timezone_set('UTC');
$dump = static fn (array $entries): array => array_map(static fn (PredbFeedEntry $e): array => [
    'title' => $e->title, 'source' => $e->source, 'category' => $e->category, 'size' => $e->size, 'files' => $e->files,
    'predate' => $e->predate?->format('Y-m-d H:i:s'), 'nuked' => $e->nuked, 'reason' => $e->nukeReason,
    'listed' => $e->listedAt?->format('Y-m-d H:i:s'), 'enrich' => $e->enrichOnly,
], $entries);

$club = [['name' => ' Some.Rel-GRP ', 'cat' => 'TV', 'size' => 1500.5, 'files' => '12', 'preAt' => 1700000000, 'nuke' => null],
    ['name' => 'Nuked.Rel-GRP', 'cat' => '', 'size' => 0.5, 'files' => 0, 'preAt' => '1700000001', 'nuke' => ['type' => 'modnuke', 'reason' => 'bad']],
    ['name' => 'Un.Rel-GRP', 'nuke' => ['type' => 'undelpre', 'reason' => '']], ['name' => 'Old.Rel-GRP', 'nuke' => ['type' => 'oldnuke']],
    ['name' => 'Re.Rel-GRP', 'size' => 'x', 'nuke' => ['type' => 'renuke', 'reason' => 'r']], ['name' => 'Plain-GRP', 'nuke' => ['reason' => 'x']],
    ['name' => '  '], ['nope' => 1], 'scalar'];
$net = [['release' => 'A.Rel-GRP', 'section' => 'MP3', 'size' => 12.345, 'files' => 3, 'pretime' => 1700000000, 'status' => 0, 'reason' => 'ignored'],
    ['release' => 'B.Rel-GRP', 'status' => 1, 'reason' => 'dupe'], ['release' => 'C.Rel-GRP', 'status' => 2], ['release' => 'D.Rel-GRP', 'status' => 3, 'reason' => 'x'], ['release' => 'E.Rel-GRP', 'status' => '4']];
$org = [['rlsname' => 'A-GRP', 'section' => 'X', 'ctime' => 1600000000, 'size' => '700', 'files' => 2, 'status' => 0, 'nukereason' => ''],
    ['rlsname' => 'B-GRP', 'status' => '1'], ['rlsname' => 'C-GRP', 'status' => 0, 'nukereason' => 'why']];
$ovh = [['id' => 5, 'name' => 'A-GRP', 'cat' => 'TV', 'size' => 2048, 'files' => 3, 'preAt' => 1600000000, 'nuke' => ['typeId' => 1, 'preId' => 5, 'reason' => 'r']],
    ['id' => 6, 'name' => 'B-GRP', 'nuke' => ['typeId' => 1, 'preId' => 7, 'reason' => 'other row']], ['id' => '8', 'name' => 'C-GRP', 'nuke' => ['typeId' => 5, 'preId' => '008']],
    ['name' => 'D-GRP', 'nuke' => ['typeId' => 3, 'reason' => 'mod']], ['name' => 'E-GRP', 'nuke' => ['typeId' => 4]]];
$srr = [['release' => 'A-GRP', 'date' => '2026-07-01 12:00:00'], ['release' => 'B-GRP', 'date' => '2026-01-01 12:00:00'], ['release' => 'C-GRP', 'date' => 'junk'], ['release' => 'D-GRP']];
$scene = [['dirname' => 'A-GRP', 'time' => 1600000000, 'size' => ['number' => 700, 'unit' => 'MB'], 'ext_info' => ['type' => 'movie'], 'flags' => ['nuke_rls' => true]],
    ['dirname' => 'B-GRP', 'size' => ['number' => 2, 'unit' => 'gb']], ['dirname' => 'C-GRP', 'size' => ['number' => 5, 'unit' => 'RAR'], 'flags' => ['nuke_rls' => false]], ['dirname' => 'D-GRP', 'size' => ['number' => 512, 'unit' => 'KB']], ['dirname' => 'E-GRP', 'size' => ['number' => 1, 'unit' => 'TB']]];
$p2p = [['dirname' => 'A-GRP', 'pub_time' => 1600000000, 'size_mb' => 1234, 'category' => ['meta_cat' => 'movies', 'sub_cat' => 'hd']], ['dirname' => 'B-GRP', 'category' => ['meta_cat' => 'tv']], ['dirname' => 'C-GRP', 'category' => ['sub_cat' => 'x']]];
$apibay = [['name' => 'A-GRP', 'category' => '207', 'size' => '1073741824', 'num_files' => '3', 'added' => '1600000000'], ['name' => 'Adult', 'category' => 501], ['name' => 'B-GRP', 'category' => 205, 'size' => 1024]];
$predatabase = [['rlsname' => 'A-GRP', 'section' => 'X', 'size' => 3, 'files' => 1, 'ctime' => 1600000000], ['rlsname' => 'B-GRP', 'ctime' => 0], ['rlsname' => '']];
$rss = <<<'XML'

<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Feed</title>
<item><title>Some.Rel-GRP</title><category>TV</category><category>Second</category><pubDate>Sat, 01 Aug 2026 10:00:00 +0200</pubDate></item>
<item><title><![CDATA[Cdata.Rel-GRP]]></title><pubDate>not a date</pubDate></item>
<item><title>Amp&amp;Rel-GRP &#8211; 1.5 GB</title><pubDate>2026-08-01 10:00:00</pubDate></item>
<item><title>Spaced Rel 720p  EZTV</title></item>
<item><title>Size.Rel-GRP – 700 MB</title><category> Mapped </category></item>
<item><category>no title</category></item>
</channel></rss>
XML;
$make = static fn (array $o): RssFeedSource => new RssFeedSource('k', 'label', 'http://x', 100, 15, 'ua', ...$o);

echo json_encode([
    'club' => ['in' => $club, 'out' => $dump((new PredbClubSource('x'))->parseRows($club))],
    'net' => ['in' => $net, 'out' => $dump((new PredbNetSource('x'))->parseRows($net))],
    'org' => ['in' => $org, 'out' => $dump((new PredbOrgSource('x'))->parseRows($org))],
    'ovh' => ['in' => $ovh, 'out' => $dump((new PredbOvhSource('x'))->parseRows($ovh))],
    'srrdb' => ['in' => $srr, 'out' => $dump((new SrrdbSource('x'))->parseRows($srr))],
    'xrel' => ['in' => $scene, 'out' => $dump((new XrelSource('x'))->parseRows($scene))],
    'xrel_p2p' => ['in' => $p2p, 'out' => $dump((new XrelSource('x', p2p: true))->parseRows($p2p))],
    'apibay' => ['in' => $apibay, 'out' => $dump((new ApibaySource('x'))->parseRows($apibay))],
    'predatabase' => ['in' => $predatabase, 'out' => $dump((new PredatabaseSource('x'))->parseRows($predatabase))],
    'rss' => ['xml' => ltrim($rss), 'plain' => $dump($make([])->parseXml($rss)),
        'spaced' => $dump($make(['spacedTitles' => true, 'mapCategory' => true])->parseXml($rss)),
        'catalogue' => $dump($make(['publicationDatesOnly' => true, 'stripDisplaySizeSuffix' => true])->parseXml($rss))],
    'sizes' => array_map(static fn (float $v): ?string => PredbFeedEntry::sizeFromMegabytes($v), ['0.5' => 0.5, '1' => 1.0, '1.5' => 1.5, '1500.123' => 1500.123, '0.0009' => 0.0009, '0' => 0.0, '100' => 100.0]),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
