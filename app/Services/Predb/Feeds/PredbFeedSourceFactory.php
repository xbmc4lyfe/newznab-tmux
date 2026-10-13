<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\Sources\ApibaySource;
use App\Services\Predb\Feeds\Sources\CrowdnfoSource;
use App\Services\Predb\Feeds\Sources\DatsPcIsoSource;
use App\Services\Predb\Feeds\Sources\G4uSource;
use App\Services\Predb\Feeds\Sources\GabberSource;
use App\Services\Predb\Feeds\Sources\KnabenSource;
use App\Services\Predb\Feeds\Sources\Nima4kSource;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Services\Predb\Feeds\Sources\NzedbArchiveSource;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\PredbOrgSource;
use App\Services\Predb\Feeds\Sources\PredbOvhSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use App\Services\Predb\Feeds\Sources\SceneArchiveSqlSource;
use App\Services\Predb\Feeds\Sources\SeriousAboutSoundSource;
use App\Services\Predb\Feeds\Sources\SrrdbSource;
use App\Services\Predb\Feeds\Sources\ThreeDsDbSource;
use App\Services\Predb\Feeds\Sources\XrelSource;
use InvalidArgumentException;

/**
 * Builds configured PreDB feed sources from config/predb_feeds.php.
 */
class PredbFeedSourceFactory
{
    /**
     * @return list<string>
     */
    public function availableKeys(): array
    {
        return ['predb_club', 'predb_net', 'predb_me', 'predb_org', 'predatabase', 'srrdb', 'xrel', 'xrel_p2p', 'scnsrc_me', 'eztv', 'predb_ovh', 'apibay', 'nfodb', 'one_techno', 'one_gabba', 'ddlvalley', 'hdencode', 'hdencode_tv', 'mp3scene', 'nima4k', 'gload', 'hdworld', 'g4u', 'nswdb', '3dsdb', 'gabber', 'movieblog', 'scnlog', 'knaben', 'crowdnfo', 'seriousaboutsound', 'dats_pciso_1998', 'nzedb_archive', 'scene_archive_sql'];
    }

    /**
     * @param  list<string>|null  $keys  Restrict to these keys; null uses `predb_feeds.sources`.
     * @return list<PredbFeedSource>
     */
    public function make(?array $keys = null): array
    {
        $keys ??= (array) config('predb_feeds.sources', []);

        return array_values(array_map(fn (string $key): PredbFeedSource => $this->makeOne($key), array_unique($keys)));
    }

    public function makeOne(string $key): PredbFeedSource
    {
        $endpoint = (string) config("predb_feeds.endpoints.{$key}", '');
        $pageSize = max(1, (int) config('predb_feeds.page_size', 100));
        $timeout = max(1, (int) config('predb_feeds.timeout', 15));
        $userAgent = (string) config('predb_feeds.user_agent', 'NNTmux-PreDB-Importer/1.0');
        $maximumBodyBytes = max(1, (int) config('predb_feeds.max_body_bytes', 1048576));

        return match ($key) {
            'predb_club' => new PredbClubSource($endpoint, $pageSize, $timeout, $userAgent),
            'predb_net' => new PredbNetSource($endpoint, $pageSize, $timeout, $userAgent),
            'predb_me' => new RssFeedSource('predb_me', 'predb.me', $endpoint, $pageSize, $timeout, $userAgent, maximumBodyBytes: $maximumBodyBytes),
            'predb_org' => new PredbOrgSource($endpoint, $pageSize, $timeout, $userAgent),
            'predatabase' => new PredatabaseSource($endpoint, $pageSize, $timeout, $userAgent, (string) config('predb_feeds.predatabase_api_key', '')),
            'srrdb' => new SrrdbSource($endpoint, $pageSize, $timeout, $userAgent, (string) config('predb_feeds.srrdb_timezone', 'Europe/Brussels')),
            'xrel' => new XrelSource($endpoint, $pageSize, $timeout, $userAgent),
            'xrel_p2p' => new XrelSource($endpoint, $pageSize, $timeout, $userAgent, p2p: true),
            'scnsrc_me' => new RssFeedSource('scnsrc_me', 'scnsrc.me', $endpoint, $pageSize, $timeout, $userAgent, spacedTitles: true, mapCategory: true, maximumBodyBytes: $maximumBodyBytes),
            'eztv' => new RssFeedSource('eztv', 'eztv', $endpoint, $pageSize, $timeout, $userAgent, spacedTitles: true, maximumBodyBytes: $maximumBodyBytes),
            'predb_ovh' => new PredbOvhSource($endpoint, $pageSize, $timeout, $userAgent),
            'apibay' => new ApibaySource($endpoint, $pageSize, $timeout, $userAgent),
            'nfodb' => new RssFeedSource('nfodb', 'nfodb.net.ru', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'one_techno' => new RssFeedSource('one_techno', '1techno.org', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'one_gabba' => new RssFeedSource('one_gabba', '1gabba.pw', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'ddlvalley' => new RssFeedSource('ddlvalley', 'ddlvalley.me', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'hdencode' => new RssFeedSource('hdencode', 'hdencode.org', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, stripDisplaySizeSuffix: true, maximumBodyBytes: $maximumBodyBytes),
            'hdencode_tv' => new RssFeedSource('hdencode_tv', 'hdencode.org', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, stripDisplaySizeSuffix: true, maximumBodyBytes: $maximumBodyBytes),
            'mp3scene' => new RssFeedSource('mp3scene', 'mp3scene.info', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'nima4k' => new Nima4kSource($endpoint, $pageSize, $timeout, $userAgent),
            'gload' => new RssFeedSource('gload', 'gload.to', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'movieblog' => new RssFeedSource('movieblog', 'movieblog.to', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'hdworld' => new RssFeedSource('hdworld', 'hd-world.cc', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'scnlog' => new RssFeedSource('scnlog', 'scnlog.me', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'knaben' => new KnabenSource($endpoint, $pageSize, $timeout, $userAgent),
            'crowdnfo' => new CrowdnfoSource($endpoint, $pageSize, $timeout, $userAgent),
            'seriousaboutsound' => new SeriousAboutSoundSource($endpoint, $pageSize, $timeout, $userAgent),
            'g4u' => new G4uSource($endpoint, $pageSize, $timeout, $userAgent),
            'gabber' => new GabberSource($endpoint, $pageSize, $timeout, $userAgent),
            'nzedb_archive' => new NzedbArchiveSource($endpoint, $pageSize, $timeout, $userAgent),
            'dats_pciso_1998' => new DatsPcIsoSource($endpoint, $pageSize, $timeout, $userAgent),
            'scene_archive_sql' => new SceneArchiveSqlSource((string) config('predb_feeds.scene_archive_sql_file', ''), $pageSize),
            'nswdb' => new NswdbSource($endpoint, $pageSize, $timeout, $userAgent),
            '3dsdb' => new ThreeDsDbSource($endpoint, $pageSize, $timeout, $userAgent),
            default => throw new InvalidArgumentException("Unknown PreDB feed source [{$key}]. Available: ".implode(', ', $this->availableKeys())),
        };
    }
}
