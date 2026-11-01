//! The feed sources, built by key.

mod json;
mod rss;

use anyhow::{Result, bail};

use super::http::FeedHttp;
use super::{FeedConfig, FeedSource};

pub use json::*;
pub use rss::{RssFeedSource, RssOptions};

const AVAILABLE: [&str; 34] = [
    "predb_club",
    "predb_net",
    "predb_me",
    "predb_org",
    "predatabase",
    "srrdb",
    "xrel",
    "xrel_p2p",
    "scnsrc_me",
    "eztv",
    "predb_ovh",
    "apibay",
    "nfodb",
    "one_techno",
    "one_gabba",
    "ddlvalley",
    "hdencode",
    "hdencode_tv",
    "mp3scene",
    "nima4k",
    "gload",
    "hdworld",
    "g4u",
    "nswdb",
    "3dsdb",
    "gabber",
    "movieblog",
    "scnlog",
    "knaben",
    "crowdnfo",
    "seriousaboutsound",
    "dats_pciso_1998",
    "nzedb_archive",
    "scene_archive_sql",
];

fn endpoint(config: &FeedConfig, key: &str) -> String {
    if let Some(url) = &config.endpoint_override {
        return url.clone();
    }
    match key {
        "predb_club" => "https://predb.club/api/v1/",
        "predb_net" => "https://api.predb.net/",
        "predb_me" => config.rss_url.as_str(),
        "predb_org" => "https://predb.org/api/search",
        "predatabase" => "https://predataba.se/api/search",
        "srrdb" => "https://api.srrdb.com/v1/search/order:date-desc",
        "xrel" => "https://api.xrel.to/v2/release/latest.json",
        "xrel_p2p" => "https://api.xrel.to/v2/p2p/releases.json",
        "scnsrc_me" => "https://www.scnsrc.me/feed",
        "eztv" => "https://eztvx.to/ezrss.xml",
        "predb_ovh" => "https://predb.ovh/api/v1/",
        "apibay" => "https://apibay.org/precompiled/data_top100_recent.json",
        "nfodb" => "https://nfodb.net.ru/rss.xml",
        "one_techno" => "https://1techno.org/rss.xml",
        "one_gabba" => "https://1gabba.pw/rss.xml",
        "ddlvalley" => "https://www.ddlvalley.me/feed/",
        "hdencode" => "https://hdencode.org/tag/movies/feed/",
        "hdencode_tv" => "https://hdencode.org/tag/tv-shows/feed/",
        "mp3scene" => "https://www.mp3scene.info/feeds/releases/",
        "nima4k" => "https://nima4k.org/",
        "gload" => "https://gload.to/feed/",
        "hdworld" => "https://hd-world.cc/feed/",
        "scnlog" => "https://scnlog.me/feed/",
        "knaben" => "https://api.knaben.org/v2/browse",
        "crowdnfo" => "https://crowdnfo.net/",
        "seriousaboutsound" => {
            "https://seriousaboutsound.net/forum/48-releases/?sortby=start_date&sortdirection=desc"
        }
        "movieblog" => "https://movieblog.to/feed/",
        "g4u" => "https://g4u.to/",
        "gabber" => "https://gabber.od.ua/0day",
        "nzedb_archive" => {
            "https://raw.githubusercontent.com/nZEDb/nZEDbPre_Dumps/ae51410b04bb3c59749b68582fbc4cc1e1f02d68/dumps/1689156393/1714642832_predb_dump.csv.gz"
        }
        "dats_pciso_1998" => "https://dats.site/exports/dat-sets/267",
        "nswdb" => "https://nswdb.com/xml.php",
        "3dsdb" => "https://3dsdb.com/xml.php",
        _ => "",
    }
    .to_owned()
}

/// Builds one source by its key.
pub fn make(key: &str, config: &FeedConfig, http: &FeedHttp) -> Result<Box<dyn FeedSource>> {
    let url = endpoint(config, key);
    let size = config.page_size;
    let h = http.clone();
    let rss = |label: &'static str, options: RssOptions| -> Box<dyn FeedSource> {
        Box::new(RssFeedSource::new(
            key.to_owned(),
            label,
            url.clone(),
            size,
            config.max_body_bytes,
            http.clone(),
            options,
        ))
    };
    let catalogue = RssOptions {
        publication_dates_only: true,
        ..RssOptions::default()
    };
    let mapped = RssOptions {
        map_category: true,
        publication_dates_only: true,
        ..RssOptions::default()
    };
    let spaced = RssOptions {
        spaced_titles: true,
        ..RssOptions::default()
    };
    let hdencode = RssOptions {
        publication_dates_only: true,
        strip_display_size_suffix: true,
        ..RssOptions::default()
    };
    Ok(match key {
        "predb_club" => Box::new(PredbClubSource::new(url, size, h)),
        "predb_net" => Box::new(PredbNetSource::new(url, size, h)),
        "predb_org" => Box::new(PredbOrgSource::new(url, size, h)),
        "predb_ovh" => Box::new(PredbOvhSource::new(url, size, h)),
        "predatabase" => Box::new(PredatabaseSource::new(
            url,
            size,
            h,
            config.predatabase_api_key.clone(),
        )),
        "srrdb" => Box::new(SrrdbSource::new(url, h, config.srrdb_timezone.clone())),
        "xrel" => Box::new(XrelSource::new(url, size, h, false)),
        "xrel_p2p" => Box::new(XrelSource::new(url, size, h, true)),
        "apibay" => Box::new(ApibaySource::new(url, size, h)),
        "predb_me" => rss("predb.me", RssOptions::default()),
        "scnsrc_me" => rss(
            "scnsrc.me",
            RssOptions {
                map_category: true,
                ..spaced
            },
        ),
        "eztv" => rss("eztv", spaced),
        "nfodb" => rss("nfodb.net.ru", mapped),
        "one_techno" => rss("1techno.org", mapped),
        "one_gabba" => rss("1gabba.pw", mapped),
        "scnlog" => rss("scnlog.me", mapped),
        "ddlvalley" => rss("ddlvalley.me", catalogue),
        "mp3scene" => rss("mp3scene.info", catalogue),
        "gload" => rss("gload.to", catalogue),
        "movieblog" => rss("movieblog.to", catalogue),
        "hdworld" => rss("hd-world.cc", catalogue),
        "hdencode" | "hdencode_tv" => rss("hdencode.org", hdencode),
        other if AVAILABLE.contains(&other) => {
            bail!("PreDB feed source [{other}] isn't ported yet")
        }
        other => bail!(
            "Unknown PreDB feed source [{other}]. Available: {}",
            AVAILABLE.join(", ")
        ),
    })
}
