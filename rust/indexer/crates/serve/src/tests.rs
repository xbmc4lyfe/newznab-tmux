//! The API end to end over a real catalog, NZB store and index in a temporary directory.

use std::io::Write as _;
use std::sync::Arc;

use flate2::Compression;
use flate2::write::GzEncoder;
use indexer_config::{ApiConfig, Secret};
use indexer_store::{Catalog, NzbEncoding, NzbStore, NzbWrite, StorePaths, body_sha256};

use super::*;

struct Fixture {
    _dir: tempfile::TempDir,
    api: Api,
    paths: StorePaths,
}

struct Row {
    id: i64,
    name: &'static str,
    category: u32,
    size: i64,
    posted_at: i64,
    tvdb: Option<i64>,
    imdb: Option<&'static str>,
    tmdb: Option<i64>,
    season: Option<i64>,
    episode: Option<i64>,
}

const fn row(id: i64, name: &'static str, category: u32, posted_at: i64) -> Row {
    Row {
        id,
        name,
        category,
        size: 2_000_000_000,
        posted_at,
        tvdb: None,
        imdb: None,
        tmdb: None,
        season: None,
        episode: None,
    }
}

fn rows() -> Vec<Row> {
    vec![
        Row {
            tvdb: Some(121_361),
            season: Some(3),
            episode: Some(2),
            ..row(
                1,
                "Silo.S03E02.1080p.WEB-DL.DDP5.1.H.264-FLUX",
                5040,
                1_700_000_100,
            )
        },
        Row {
            tvdb: Some(121_361),
            season: Some(3),
            episode: Some(3),
            ..row(
                2,
                "Silo.S03E03.2160p.WEB-DL.DDP5.1.H.265-FLUX",
                5045,
                1_700_000_200,
            )
        },
        Row {
            tvdb: Some(121_361),
            season: Some(3),
            ..row(
                3,
                "Silo.S03.1080p.BluRay.REMUX.AVC-FraMeSToR",
                5040,
                1_700_000_300,
            )
        },
        Row {
            imdb: Some("0133093"),
            tmdb: Some(603),
            size: 60_000_000_000,
            ..row(
                4,
                "The.Matrix.1999.2160p.UHD.BluRay.REMUX.HDR.HEVC-FraMeSToR",
                2045,
                1_700_000_400,
            )
        },
        row(5, "Some.Anime.E01.1080p", 5070, 1_700_000_500),
        Row {
            tvdb: Some(9),
            ..row(
                6,
                "Daily.Show.2024.10.15.1080p.WEB.h264 & <more>",
                5040,
                1_700_000_600,
            )
        },
    ]
}

fn gzip(data: &[u8]) -> Vec<u8> {
    let mut encoder = GzEncoder::new(Vec::new(), Compression::fast());
    encoder.write_all(data).unwrap();
    encoder.finish().unwrap()
}

fn insert(catalog: &Catalog, row: &Row) {
    catalog
        .conn()
        .execute(
            "INSERT INTO releases (id, guid, name, search_name, size_bytes, total_parts,
                 completion, group_id, poster, posted_at, added_at, legacy_category,
                 password_status, is_renamed, grabs, inner_file_count, imdb_id, tmdb_id,
                 tvdb_id, season, episode)
             VALUES (?1, ?2, ?3, ?3, ?4, 40, 100, 1, 'poster@example.test', ?5, ?5, ?6, 0, 1,
                 7, 1, ?7, ?8, ?9, ?10, ?11)",
            indexer_store::rusqlite::params![
                row.id,
                format!("guid{:04}", row.id),
                row.name,
                row.size,
                row.posted_at,
                row.category,
                row.imdb,
                row.tmdb,
                row.tvdb,
                row.season,
                row.episode,
            ],
        )
        .unwrap();
}

fn fixture() -> Fixture {
    let dir = tempfile::tempdir().unwrap();
    let paths = StorePaths::under(dir.path());
    let catalog = Catalog::open(&paths.catalog).unwrap();
    catalog
        .conn()
        .execute(
            "INSERT INTO usenet_groups (id, name, backfill_target, first_record, last_record,
                 active, backfill)
             VALUES (1, 'alt.binaries.hdtv', 0, 0, 0, 1, 0)",
            [],
        )
        .unwrap();
    for row in rows() {
        insert(&catalog, &row);
    }
    let mut nzbs = NzbStore::open(&paths.nzbs).unwrap();
    let body = gzip(b"<nzb>silo</nzb>");
    nzbs.put_batch(&[NzbWrite {
        release_id: 1,
        encoding: NzbEncoding::Gzip,
        decoded_bytes: 15,
        body_sha256: body_sha256(&body),
        legacy_digest: None,
        body,
    }])
    .unwrap();
    drop(nzbs);
    let index = open_index(
        &Catalog::open_read_only(&paths.catalog).unwrap(),
        &paths.search,
    )
    .unwrap();
    let config = ApiConfig {
        api_keys: vec![Secret::new("other".into()), Secret::new("key".into())],
        ..ApiConfig::default()
    };
    let api = Api::new(&config, &paths, Arc::new(index)).unwrap();
    drop(catalog);
    Fixture {
        _dir: dir,
        api,
        paths,
    }
}

fn get(api: &Api, query: &str) -> Reply {
    api.respond(query, Some("hydra.local:5060"))
}

/// The GUIDs of a result page, in order.
fn guids(reply: &Reply) -> Vec<String> {
    reply
        .text()
        .split("<guid isPermaLink=\"false\">")
        .skip(1)
        .map(|rest| rest.split_once('<').unwrap().0.to_owned())
        .collect()
}

fn total(reply: &Reply) -> u64 {
    let (_, rest) = reply.text().split_once("total=\"").unwrap();
    rest.split_once('"').unwrap().0.parse().unwrap()
}

#[test]
fn caps_needs_no_key() {
    let f = fixture();
    let reply = get(&f.api, "t=caps");
    assert_eq!(reply.status, StatusCode::OK);
    assert!(reply.text().contains("<caps>"), "{}", reply.text());
}

#[test]
fn searches_need_a_valid_key() {
    let f = fixture();
    for query in [
        "t=search&q=silo",
        "t=search&q=silo&apikey=nope",
        "t=get&id=guid0001&apikey=ke",
    ] {
        let reply = get(&f.api, query);
        assert_eq!(reply.status, StatusCode::UNAUTHORIZED, "{query}");
        assert!(reply.text().contains("code=\"100\""), "{query}");
    }
    assert_eq!(guids(&get(&f.api, "t=search&q=silo&apikey=other")).len(), 3);
}

#[test]
fn text_search_is_newest_first_and_keeps_to_tv_and_movies() {
    let f = fixture();
    let reply = get(&f.api, "t=search&q=silo&apikey=key");
    assert_eq!(guids(&reply), ["guid0003", "guid0002", "guid0001"]);
    assert_eq!(total(&reply), 3);
    let all = get(&f.api, "t=search&apikey=key");
    assert_eq!(total(&all), 5, "anime is never served");
    assert!(!all.text().contains("Anime"));
    assert!(guids(&get(&f.api, "t=search&q=anime&apikey=key")).is_empty());
}

#[test]
fn tvsearch_by_text_uses_season_and_episode() {
    let f = fixture();
    let page = |q: &str| guids(&get(&f.api, &format!("t=tvsearch&apikey=key&{q}")));
    assert_eq!(page("q=silo&season=3&ep=2"), ["guid0001"]);
    assert_eq!(
        page("q=silo&season=S03"),
        ["guid0003", "guid0002", "guid0001"]
    );
    assert_eq!(page("q=daily+show&season=2024&ep=10/15"), ["guid0006"]);
    assert!(
        page("q=matrix").is_empty(),
        "movies stay out of TV searches"
    );
}

#[test]
fn tvsearch_by_id_uses_the_catalog() {
    let f = fixture();
    let page = |q: &str| guids(&get(&f.api, &format!("t=tvsearch&apikey=key&{q}")));
    assert_eq!(page("tvdbid=121361"), ["guid0003", "guid0002", "guid0001"]);
    assert_eq!(page("tvdbid=121361&season=3&ep=3"), ["guid0002"]);
    assert_eq!(
        page("tvdbid=121361&season=3"),
        ["guid0003", "guid0002", "guid0001"]
    );
    assert_eq!(page("tvdbid=121361&cat=5045"), ["guid0002"]);
    assert_eq!(
        page("tvdbid=121361&tvmazeid=999"),
        ["guid0003", "guid0002", "guid0001"]
    );
    assert_eq!(page("tvdbid=9&season=2024&ep=10/15"), ["guid0006"]);
    assert!(page("tvdbid=9&season=2024&ep=10/16").is_empty());
    assert!(page("tvdbid=1").is_empty());
}

#[test]
fn movie_search_by_imdb_and_tmdb() {
    let f = fixture();
    let page = |q: &str| guids(&get(&f.api, &format!("t=movie&apikey=key&{q}")));
    assert_eq!(page("imdbid=tt0133093"), ["guid0004"]);
    assert_eq!(page("imdbid=133093"), ["guid0004"]);
    assert_eq!(page("tmdbid=603"), ["guid0004"]);
    assert_eq!(page("q=matrix+remux"), ["guid0004"]);
    assert!(page("q=silo").is_empty());
}

#[test]
fn size_and_paging_filters_apply() {
    let f = fixture();
    let page = |q: &str| get(&f.api, &format!("t=search&apikey=key&{q}"));
    assert_eq!(guids(&page("minsize=50000000000")), ["guid0004"]);
    assert_eq!(guids(&page("maxsize=50000000000&q=silo")).len(), 3);
    let second = page("q=silo&limit=1&offset=1");
    assert_eq!(guids(&second), ["guid0002"]);
    assert_eq!(total(&second), 3);
    assert!(second.text().contains("offset=\"1\""));
    let by_id = get(
        &f.api,
        "t=tvsearch&apikey=key&tvdbid=121361&limit=1&offset=2",
    );
    assert_eq!(
        (guids(&by_id), total(&by_id)),
        (vec!["guid0001".to_owned()], 3)
    );
    // Every release here is years old.
    assert!(guids(&page("maxage=30")).is_empty());
    assert!(
        guids(&get(
            &f.api,
            "t=tvsearch&apikey=key&tvdbid=121361&maxage=30"
        ))
        .is_empty()
    );
}

#[test]
fn items_carry_newznab_attributes_and_links() {
    let f = fixture();
    let reply = get(&f.api, "t=tvsearch&apikey=key&tvdbid=121361&season=3&ep=2");
    let text = reply.text();
    for expected in [
        "<title>Silo.S03E02.1080p.WEB-DL.DDP5.1.H.264-FLUX</title>",
        "<link>http://hydra.local:5060/api?t=get&amp;id=guid0001&amp;apikey=key</link>",
        "<enclosure url=\"http://hydra.local:5060/api?t=get&amp;id=guid0001&amp;apikey=key\" length=\"2000000000\" type=\"application/x-nzb\"/>",
        "<pubDate>Tue, 14 Nov 2023 22:15:00 +0000</pubDate>",
        "<category>TV &gt; HD</category>",
        "<newznab:attr name=\"category\" value=\"5000\"/>",
        "<newznab:attr name=\"category\" value=\"5040\"/>",
        "<newznab:attr name=\"size\" value=\"2000000000\"/>",
        "<newznab:attr name=\"tvdbid\" value=\"121361\"/>",
        "<newznab:attr name=\"season\" value=\"3\"/>",
        "<newznab:attr name=\"episode\" value=\"2\"/>",
        "<newznab:attr name=\"group\" value=\"alt.binaries.hdtv\"/>",
        "<newznab:attr name=\"grabs\" value=\"7\"/>",
        "<newznab:attr name=\"password\" value=\"0\"/>",
    ] {
        assert!(text.contains(expected), "missing {expected} in:\n{text}");
    }
    let movie = get(&f.api, "t=movie&apikey=key&tmdbid=603");
    assert!(
        movie
            .text()
            .contains("<newznab:attr name=\"imdb\" value=\"0133093\"/>")
    );
    let escaped = get(&f.api, "t=search&apikey=key&q=daily");
    assert!(escaped.text().contains("h264 &amp; &lt;more&gt;</title>"));
}

#[test]
fn public_url_replaces_the_host() {
    let f = fixture();
    let config = ApiConfig {
        api_keys: vec![Secret::new("key".into())],
        public_url: Some("https://nzb.example.test/".into()),
        ..ApiConfig::default()
    };
    let index = TantivyIndexHandle::open(&f.paths);
    let api = Api::new(&config, &f.paths, index).unwrap();
    let reply = api.respond("t=search&apikey=key&q=matrix", Some("evil.test"));
    assert!(
        reply
            .text()
            .contains("https://nzb.example.test/api?t=get&amp;id=guid0004")
    );
    assert!(!reply.text().contains("evil.test"));
}

struct TantivyIndexHandle;

impl TantivyIndexHandle {
    fn open(paths: &StorePaths) -> Arc<dyn TextIndex> {
        Arc::new(indexer_search::TantivyIndex::open(&paths.search).unwrap())
    }
}

#[test]
fn get_serves_the_decoded_nzb_and_details_one_item() {
    let f = fixture();
    let nzb = get(&f.api, "t=get&id=guid0001&apikey=key");
    assert_eq!(nzb.status, StatusCode::OK);
    assert_eq!(nzb.content_type, NZB);
    assert_eq!(nzb.body, b"<nzb>silo</nzb>");
    assert_eq!(
        nzb.filename.as_deref(),
        Some("Silo.S03E02.1080p.WEB-DL.DDP5.1.H.264-FLUX")
    );
    let missing = get(&f.api, "t=get&id=guid0002&apikey=key");
    assert!(missing.text().contains("code=\"300\""));
    let details = get(&f.api, "t=details&id=guid0004&apikey=key");
    assert_eq!(guids(&details), ["guid0004"]);
    assert!(
        get(&f.api, "t=details&id=nothing&apikey=key")
            .text()
            .contains("code=\"300\"")
    );
}

#[test]
fn bad_requests_answer_newznab_errors() {
    let f = fixture();
    for (query, code) in [
        ("", "200"),
        ("t=music&apikey=key", "202"),
        ("t=search&apikey=key&limit=x", "201"),
        ("t=get&apikey=key", "200"),
    ] {
        let reply = get(&f.api, query);
        assert_eq!(reply.status, StatusCode::OK, "{query}");
        assert!(
            reply.text().contains(&format!("code=\"{code}\"")),
            "{query}: {}",
            reply.text()
        );
    }
}

#[test]
fn the_index_catches_up_with_new_releases() {
    let f = fixture();
    let catalog = Catalog::open(&f.paths.catalog).unwrap();
    insert(
        &catalog,
        &row(7, "Fresh.Show.S01E01.1080p.WEB-DL", 5040, 1_700_000_700),
    );
    drop(catalog);
    let reader = Catalog::open_read_only(&f.paths.catalog).unwrap();
    let index = open_index(&reader, &f.paths.search).unwrap();
    assert_eq!(index.watermark().unwrap(), Some(7));
    let config = ApiConfig {
        api_keys: vec![Secret::new("key".into())],
        ..ApiConfig::default()
    };
    let api = Api::new(&config, &f.paths, Arc::new(index)).unwrap();
    assert_eq!(
        guids(&get(&api, "t=search&apikey=key&q=fresh")),
        ["guid0007"]
    );

    let rebuilt = rebuild_index(&reader, &f.paths.search).unwrap();
    assert_eq!(rebuilt.watermark().unwrap(), Some(7));
}
