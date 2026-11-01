//! The feed sources, built by key.

mod crowdnfo;
mod dats_pciso;
mod dom;
mod g4u;
mod gabber;
mod json;
mod knaben;
mod literal;
mod nima4k;
mod nzedb_archive;
mod rss;
mod scene_archive_sql;
mod seriousaboutsound;
mod xml_catalogue;
mod xml_tree;

use anyhow::{Result, bail};

use super::http::FeedHttp;
use super::{FeedConfig, FeedSource};

pub use crowdnfo::CrowdnfoSource;
pub use dats_pciso::DatsPcIsoSource;
pub use g4u::G4uSource;
pub use gabber::GabberSource;
pub use json::*;
pub use knaben::KnabenSource;
pub use nima4k::Nima4kSource;
pub use nzedb_archive::NzedbArchiveSource;
pub use rss::{RssFeedSource, RssOptions};
pub use scene_archive_sql::SceneArchiveSqlSource;
pub use seriousaboutsound::SeriousAboutSoundSource;
pub use xml_catalogue::XmlCatalogueSource;

/// The pure parse functions, for tests and one-off tools.
pub mod parse {
    pub use super::crowdnfo::parse_html as crowdnfo;
    pub use super::dats_pciso::{DatsCatalogue, parse_xml as dats_pciso};
    pub use super::g4u::parse_html as g4u;
    pub use super::gabber::parse_html as gabber;
    pub use super::knaben::{KnabenPage, parse_json as knaben};
    pub use super::nima4k::parse_html as nima4k;
    pub use super::nzedb_archive::{
        ArchiveCatalogue, Limits as NzedbLimits, decode_archive, parse_csv as nzedb_csv,
        parse_gzip as nzedb_gzip,
    };
    pub use super::scene_archive_sql::{
        Limits as SceneArchiveLimits, parse_file as scene_archive_sql,
    };
    pub use super::seriousaboutsound::parse_html as seriousaboutsound;
    pub use super::xml_catalogue::{Catalogue, parse_xml as xml_catalogue};
}

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
        "knaben" => Box::new(KnabenSource::new(&url, size, h)),
        "crowdnfo" => Box::new(CrowdnfoSource::new(url, size, h)),
        "seriousaboutsound" => Box::new(SeriousAboutSoundSource::new(url, size, h)),
        "g4u" => Box::new(G4uSource::new(url, size, h)),
        "gabber" => Box::new(GabberSource::new(url, config)?),
        "nima4k" => Box::new(Nima4kSource::new(url, size, h)),
        "nzedb_archive" => Box::new(NzedbArchiveSource::new(url, size, h)),
        "dats_pciso_1998" => Box::new(DatsPcIsoSource::new(url, size, h)),
        "scene_archive_sql" => Box::new(SceneArchiveSqlSource::new(
            config.scene_archive_sql_file.as_str(),
            size,
        )?),
        "nswdb" => Box::new(XmlCatalogueSource::nswdb(url, size, h)),
        "3dsdb" => Box::new(XmlCatalogueSource::three_ds_db(url, size, h)),
        other => bail!(
            "Unknown PreDB feed source [{other}]. Available: {}",
            AVAILABLE.join(", ")
        ),
    })
}
