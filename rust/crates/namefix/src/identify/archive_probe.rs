//! Reads the names and CRC32s inside obfuscated archives, from the first article of a volume.
//!
//! A RAR volume opens with the header of the file it holds, so one article is enough and the rest
//! of the post is never downloaded. A 7z keeps its header at the end of the last volume, usually
//! compressed, so those are only counted. Encrypted headers can't be read at all.

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use anyhow::Result;
use nntmux_formats::archive::FormatHeader;
use nntmux_nntp::{NntpConfig, NntpPool};
use tokio::runtime::Runtime;

use super::donor::{DonorKey, MIN_DONOR_HASHED_SIZE};
use super::ladder::{Evidence, Outcome};
use super::name;
use super::nzb::{NzbFile, NzbSummary};
use crate::par2_nntp::NntpArticles;
use crate::par2_recovery::ArticleSource;

/// Most articles are under 1 MiB, and a header sits at the very start of the first.
const MAX_ARTICLE_BYTES: usize = 4 * 1024 * 1024;
/// Bounds what one release costs. The second try covers a post whose biggest file isn't an archive.
const MAX_VOLUMES: usize = 2;

/// Where articles come from.
pub trait ArticleFetch: Send + Sync {
    /// The decoded article, or `None` when it can't be had.
    fn fetch(&self, group: &str, message_id: &str) -> Option<Vec<u8>>;
}

/// [`ArticleFetch`] over the workspace's NNTP pool. It blocks its thread, so use it off async
/// workers.
pub struct NntpFetch {
    pool: NntpPool,
    use_alternate: bool,
    runtime: Arc<Runtime>,
}

impl NntpFetch {
    pub fn from_env() -> Result<Self> {
        let config = NntpConfig::from_env();
        let use_alternate = config.use_alternate;
        let runtime = tokio::runtime::Builder::new_multi_thread()
            .worker_threads(2)
            .enable_all()
            .build()?;
        Ok(Self {
            pool: NntpPool::new(config),
            use_alternate,
            runtime: Arc::new(runtime),
        })
    }
}

impl ArticleFetch for NntpFetch {
    fn fetch(&self, group: &str, message_id: &str) -> Option<Vec<u8>> {
        self.runtime.block_on(async {
            let mut source = NntpArticles::with_pool(self.pool.clone(), self.use_alternate);
            let mut keep_going = || true;
            source
                .get_message_bounded(group, message_id, MAX_ARTICLE_BYTES, false, &mut keep_going)
                .await
                .body
        })
    }
}

/// A file named in an archive header.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct InnerFile {
    pub name: String,
    pub size: u64,
    pub crc32: Option<u32>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct HeaderReading {
    pub files: Vec<InnerFile>,
    pub rar: bool,
    pub seven_zip: bool,
    pub encrypted: bool,
}

/// What an article's bytes say. Anything unrecognized reads as an empty result.
pub fn read_headers(bytes: &[u8]) -> HeaderReading {
    let mut reading = HeaderReading::default();
    let Some(metadata) = nntmux_formats::archive_metadata(bytes) else {
        return reading;
    };
    for header in &metadata.headers {
        match header {
            FormatHeader::Rar4 {
                encrypted_headers,
                first_file,
                ..
            } => {
                reading.rar = true;
                reading.encrypted |= *encrypted_headers == Some(true);
                if let Some(file) = first_file {
                    if file.encrypted {
                        reading.encrypted = true;
                    }
                    if let Some(name) = &file.filename {
                        reading.files.push(InnerFile {
                            name: name.clone(),
                            size: file.uncompressed_size.unwrap_or(0),
                            // Split across volumes, this only covers the packed data in this one.
                            crc32: (!file.split_after).then_some(file.file_crc32),
                        });
                    }
                }
            }
            FormatHeader::Rar5 {
                encryption_algorithm_version,
                visible_block_walk,
                ..
            } => {
                reading.rar = true;
                reading.encrypted |= encryption_algorithm_version.is_some();
                let blocks = visible_block_walk.iter().flat_map(|walk| &walk.blocks);
                for file in blocks.filter_map(|block| block.file_header.as_ref()) {
                    if let Some(name) = &file.filename {
                        reading.files.push(InnerFile {
                            name: name.clone(),
                            size: file.unpacked_size_effective.unwrap_or(0),
                            crc32: file
                                .data_crc32
                                .filter(|_| file.data_crc32_scope == Some("unpacked_file_data")),
                        });
                    }
                }
            }
            FormatHeader::SevenZip { .. } => reading.seven_zip = true,
            _ => {}
        }
    }
    reading
}

/// Files to try, first volumes first. The order needs no real names: when none look like a first
/// volume, the biggest file is the best bet.
fn volumes_to_try(nzb: &NzbSummary) -> Vec<&NzbFile> {
    let first_volume = |file: &NzbFile| {
        let name = name::name_from_subject(&file.subject)
            .unwrap_or_default()
            .to_ascii_lowercase();
        if name.contains(".par2") {
            return None;
        }
        let part = name
            .rsplit_once(".part")
            .and_then(|(_, rest)| rest.strip_suffix(".rar"))
            .and_then(|digits| digits.parse::<u32>().ok());
        Some(match part {
            Some(1) => 0,
            Some(_) => 3,
            None if name.ends_with(".rar") || name.ends_with(".001") => 1,
            None => 2,
        })
    };
    // `files` is biggest first, and a stable sort keeps that within each rank.
    let mut ranked: Vec<(u8, &NzbFile)> = nzb
        .files
        .iter()
        .filter_map(|file| first_volume(file).map(|rank| (rank, file)))
        .collect();
    ranked.sort_by_key(|(rank, _)| *rank);
    ranked
        .into_iter()
        .map(|(_, file)| file)
        .take(MAX_VOLUMES)
        .collect()
}

#[derive(Debug, Default)]
struct Stats {
    releases: AtomicUsize,
    articles: AtomicUsize,
    missing: AtomicUsize,
    rar: AtomicUsize,
    seven_zip: AtomicUsize,
    encrypted: AtomicUsize,
    no_archive: AtomicUsize,
    readable_name: AtomicUsize,
    big_crc: AtomicUsize,
    identified: AtomicUsize,
    in_scope: AtomicUsize,
}

/// Probes up to `budget` releases, and counts what comes of it.
pub struct ArchiveProbe<'a> {
    fetch: &'a dyn ArticleFetch,
    budget: usize,
    stats: Stats,
    methods: Mutex<std::collections::BTreeMap<&'static str, usize>>,
}

impl<'a> ArchiveProbe<'a> {
    pub fn new(fetch: &'a dyn ArticleFetch, budget: usize) -> Self {
        Self {
            fetch,
            budget,
            stats: Stats::default(),
            methods: Mutex::default(),
        }
    }

    /// The release with what its archive headers say, or `None` once the budget is spent or
    /// there's nothing to add.
    pub fn enrich(&self, evidence: &Evidence) -> Option<Evidence> {
        let nzb = evidence.nzb.as_ref()?;
        let stats = &self.stats;
        if stats.releases.fetch_add(1, Ordering::Relaxed) >= self.budget {
            stats.releases.fetch_sub(1, Ordering::Relaxed);
            return None;
        }
        let mut reading = HeaderReading::default();
        for file in volumes_to_try(nzb) {
            let (Some(group), Some(id)) = (&file.group, &file.first_message_id) else {
                continue;
            };
            stats.articles.fetch_add(1, Ordering::Relaxed);
            let Some(body) = self.fetch.fetch(group, id) else {
                stats.missing.fetch_add(1, Ordering::Relaxed);
                continue;
            };
            let found = read_headers(&body);
            reading.rar |= found.rar;
            reading.seven_zip |= found.seven_zip;
            reading.encrypted |= found.encrypted;
            reading.files.extend(found.files);
            if !reading.files.is_empty() {
                break;
            }
        }
        if reading.rar {
            stats.rar.fetch_add(1, Ordering::Relaxed);
        }
        if reading.seven_zip {
            stats.seven_zip.fetch_add(1, Ordering::Relaxed);
        }
        if reading.encrypted {
            stats.encrypted.fetch_add(1, Ordering::Relaxed);
        }
        if !reading.rar && !reading.seven_zip {
            stats.no_archive.fetch_add(1, Ordering::Relaxed);
        }
        if reading
            .files
            .iter()
            .any(|file| !name::looks_obfuscated(&file.name))
        {
            stats.readable_name.fetch_add(1, Ordering::Relaxed);
        }
        let big = |file: &&InnerFile| {
            file.crc32.is_some_and(|crc| crc != 0)
                && i64::try_from(file.size).is_ok_and(|size| size >= MIN_DONOR_HASHED_SIZE)
        };
        if reading.files.iter().any(|file| big(&file)) {
            stats.big_crc.fetch_add(1, Ordering::Relaxed);
        }
        if reading.files.is_empty() {
            return None;
        }
        let mut enriched = evidence.clone();
        for file in &reading.files {
            enriched
                .listing
                .push((file.name.clone(), i64::try_from(file.size).unwrap_or(0)));
        }
        for file in reading.files.iter().filter(big) {
            let crc = file.crc32.unwrap_or_default();
            enriched
                .facts
                .push((DonorKey::Crc32, format!("{crc:08x}:{}", file.size)));
        }
        Some(enriched)
    }

    /// Counts what the ladder made of an enriched release.
    pub fn record_outcome(&self, outcome: &Outcome) {
        if let Outcome::Identified(found) = outcome {
            self.stats.identified.fetch_add(1, Ordering::Relaxed);
            if found.scope == super::name::Scope::InScope {
                self.stats.in_scope.fetch_add(1, Ordering::Relaxed);
            }
            *self
                .methods
                .lock()
                .expect("no panic while holding this")
                .entry(found.method)
                .or_default() += 1;
        }
    }

    pub fn render_stats(&self) -> String {
        let stats = &self.stats;
        let read = |value: &AtomicUsize| value.load(Ordering::Relaxed);
        let mut out = format!(
            "# Archive header probe\n\n\
             | | Releases |\n|---|---|\n\
             | Probed | {} |\n\
             | RAR header found | {} |\n\
             | 7z found (header not read) | {} |\n\
             | Encrypted | {} |\n\
             | No archive in the articles read | {} |\n\
             | Gained a readable inner name | {} |\n\
             | Gained a CRC32 for a file of 50 MB or more | {} |\n\
             | Identified offline after that | {} |\n\
             | Of those, in scope | {} |\n\n\
             Articles asked for: {}. Not available: {}.\n",
            read(&stats.releases),
            read(&stats.rar),
            read(&stats.seven_zip),
            read(&stats.encrypted),
            read(&stats.no_archive),
            read(&stats.readable_name),
            read(&stats.big_crc),
            read(&stats.identified),
            read(&stats.in_scope),
            read(&stats.articles),
            read(&stats.missing),
        );
        for (method, count) in self
            .methods
            .lock()
            .expect("no panic while holding this")
            .iter()
        {
            out.push_str(&format!("\n- {method}: {count}"));
        }
        out.push('\n');
        out
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use std::collections::HashMap;

    use super::*;
    use crate::identify::donor::DonorIndex;
    use crate::identify::ladder::{Context, Hooks, identify};
    use crate::identify::local_predb::{LocalPredb, PredbRow};

    /// A RAR4 volume's first bytes, with valid header checksums.
    pub(crate) fn rar4(name: &str, size: u32, crc: u32, flags: u16) -> Vec<u8> {
        let mut out = b"Rar!\x1a\x07\x00".to_vec();
        let mut main = vec![0x73, 0x00, 0x00, 13, 0x00];
        main.extend_from_slice(&[0; 6]);
        out.extend_from_slice(&(crc32fast::hash(&main) as u16).to_le_bytes());
        out.extend_from_slice(&main);

        let mut file = vec![0x74];
        file.extend_from_slice(&(flags | 0x8000).to_le_bytes());
        file.extend_from_slice(&((32 + name.len()) as u16).to_le_bytes());
        file.extend_from_slice(&size.to_le_bytes());
        file.extend_from_slice(&size.to_le_bytes());
        file.push(3);
        file.extend_from_slice(&crc.to_le_bytes());
        file.extend_from_slice(&0u32.to_le_bytes());
        file.push(29);
        file.push(0x30);
        file.extend_from_slice(&(name.len() as u16).to_le_bytes());
        file.extend_from_slice(&0x20u32.to_le_bytes());
        file.extend_from_slice(name.as_bytes());
        out.extend_from_slice(&(crc32fast::hash(&file) as u16).to_le_bytes());
        out.extend_from_slice(&file);
        out
    }

    fn vint(mut value: u64) -> Vec<u8> {
        let mut out = Vec::new();
        loop {
            let byte = (value & 0x7f) as u8;
            value >>= 7;
            if value == 0 {
                out.push(byte);
                return out;
            }
            out.push(byte | 0x80);
        }
    }

    fn rar5_block(header: &[u8]) -> Vec<u8> {
        let mut sized = vint(header.len() as u64);
        sized.extend_from_slice(header);
        let mut out = crc32fast::hash(&sized).to_le_bytes().to_vec();
        out.extend_from_slice(&sized);
        out
    }

    fn rar5(name: &str, size: u64, crc: u32) -> Vec<u8> {
        rar5_flagged(name, size, crc, 0)
    }

    fn rar5_flagged(name: &str, size: u64, crc: u32, block_flags: u8) -> Vec<u8> {
        let mut out = b"Rar!\x1a\x07\x01\x00".to_vec();
        out.extend_from_slice(&rar5_block(&[1, 0, 0]));
        let mut file = vec![2, block_flags];
        file.extend(vint(0x04));
        file.extend(vint(size));
        file.extend(vint(0x20));
        file.extend_from_slice(&crc.to_le_bytes());
        file.extend(vint(0));
        file.extend(vint(1));
        file.extend(vint(name.len() as u64));
        file.extend_from_slice(name.as_bytes());
        out.extend_from_slice(&rar5_block(&file));
        out
    }

    #[derive(Default)]
    pub(crate) struct Fake(pub(crate) HashMap<String, Vec<u8>>);

    impl ArticleFetch for Fake {
        fn fetch(&self, _group: &str, message_id: &str) -> Option<Vec<u8>> {
            self.0.get(message_id).cloned()
        }
    }

    fn file(name: &str, bytes: u64, id: &str) -> NzbFile {
        NzbFile {
            subject: format!(r#""{name}" yEnc (1/90)"#),
            bytes,
            group: Some("alt.binaries.misc".to_owned()),
            first_message_id: Some(id.to_owned()),
        }
    }

    fn obfuscated(files: Vec<NzbFile>) -> Evidence {
        Evidence {
            id: 1,
            name: r#""GPInOt3kYN.part01.rar" yEnc"#.to_owned(),
            search_name: "GPInOt3kYN".to_owned(),
            nzb: Some(NzbSummary {
                files,
                ..NzbSummary::default()
            }),
            ..Evidence::default()
        }
    }

    const REMUX: &str = "Huge.Film.2023.2160p.UHD.BluRay.REMUX.HEVC-GRP.mkv";

    #[test]
    fn reads_a_rar4_file_header() {
        let reading = read_headers(&rar4(REMUX, 2_000_000_000, 0xAABB_CCDD, 0));
        assert!(reading.rar && !reading.encrypted);
        assert_eq!(
            reading.files,
            [InnerFile {
                name: REMUX.to_owned(),
                size: 2_000_000_000,
                crc32: Some(0xAABB_CCDD),
            }]
        );
    }

    #[test]
    fn reads_a_rar5_file_header() {
        let reading = read_headers(&rar5(REMUX, 64_000_000_000, 0x1234_5678));
        assert!(reading.rar);
        assert_eq!(
            reading.files,
            [InnerFile {
                name: REMUX.to_owned(),
                size: 64_000_000_000,
                crc32: Some(0x1234_5678),
            }]
        );
    }

    #[test]
    fn a_split_file_keeps_its_name_but_not_a_volume_only_crc() {
        for bytes in [
            rar4(REMUX, 2_000_000_000, 0xAABB_CCDD, 0x0002),
            rar5_flagged(REMUX, 2_000_000_000, 0xAABB_CCDD, 0x10),
        ] {
            let reading = read_headers(&bytes);
            assert_eq!(reading.files.len(), 1);
            assert_eq!(reading.files[0].name, REMUX);
            assert_eq!(reading.files[0].crc32, None);
        }
    }

    #[test]
    fn a_split_file_adds_no_crc_fact() {
        let mut fake = Fake::default();
        fake.0.insert(
            "one".to_owned(),
            rar4(
                "7d8c650a91cf437a8dd9ecce444c1ddc.mkv",
                3_000_000_000,
                7,
                0x0002,
            ),
        );
        let probe = ArchiveProbe::new(&fake, 10);
        let enriched = probe
            .enrich(&obfuscated(vec![file("x.part01.rar", 900, "one")]))
            .expect("headers");
        assert!(enriched.facts.is_empty());
        assert!(
            probe
                .render_stats()
                .contains("| Gained a CRC32 for a file of 50 MB or more | 0 |")
        );
    }

    #[test]
    fn a_rar4_encrypted_file_is_flagged() {
        assert!(read_headers(&rar4("x.mkv", 1, 1, 0x0004)).encrypted);
    }

    #[test]
    fn other_bytes_read_as_nothing() {
        assert_eq!(
            read_headers(b"\x1a\x45\xdf\xa3 matroska"),
            HeaderReading::default()
        );
    }

    #[test]
    fn first_volumes_come_first_and_par2_never() {
        let nzb = NzbSummary {
            files: vec![
                file("a.part02.rar", 900, "two"),
                file("a.vol00+1.par2", 950, "par"),
                file("a.part01.rar", 800, "one"),
                file("a.nfo", 10, "nfo"),
            ],
            ..NzbSummary::default()
        };
        let ids: Vec<_> = volumes_to_try(&nzb)
            .iter()
            .map(|file| file.first_message_id.clone().unwrap_or_default())
            .collect();
        assert_eq!(ids, ["one", "nfo"]);
    }

    #[test]
    fn an_inner_name_identifies_the_release_and_a_big_crc_becomes_a_fact() {
        let mut fake = Fake::default();
        fake.0
            .insert("one".to_owned(), rar4(REMUX, 3_000_000_000, 0xAABB_CCDD, 0));
        let probe = ArchiveProbe::new(&fake, 10);
        let enriched = probe
            .enrich(&obfuscated(vec![file("GPInOt3kYN.part01.rar", 900, "one")]))
            .expect("headers");
        assert_eq!(
            enriched.facts,
            [(DonorKey::Crc32, "aabbccdd:3000000000".to_owned())]
        );

        let predb = LocalPredb::default();
        let donors = DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks::default(),
        };
        let (outcome, _) = identify(&enriched, &context);
        probe.record_outcome(&outcome);
        let Outcome::Identified(found) = outcome else {
            panic!("expected an identification");
        };
        assert_eq!(found.method, "archive_listing_regex");
        assert_eq!(found.scope, name::Scope::InScope);
        let stats = probe.render_stats();
        assert!(
            stats.contains("| Gained a readable inner name | 1 |"),
            "{stats}"
        );
        assert!(stats.contains("| Of those, in scope | 1 |"), "{stats}");
    }

    #[test]
    fn a_small_inner_crc_is_not_a_fact() {
        let mut fake = Fake::default();
        fake.0.insert("one".to_owned(), rar4("sub.srt", 1000, 7, 0));
        let probe = ArchiveProbe::new(&fake, 10);
        let enriched = probe
            .enrich(&obfuscated(vec![file("GPInOt3kYN.part01.rar", 900, "one")]))
            .expect("headers");
        assert!(enriched.facts.is_empty());
    }

    #[test]
    fn a_predb_row_can_match_the_inner_name() {
        let mut predb = LocalPredb::default();
        predb.insert(PredbRow {
            id: 5,
            title: REMUX.trim_end_matches(".mkv").to_owned(),
            category: "MOVIE".to_owned(),
            size_mib: 0,
            files: 0,
            pre_at: None,
        });
        predb.finish();
        let mut fake = Fake::default();
        fake.0
            .insert("one".to_owned(), rar5(REMUX, 64_000_000_000, 9));
        let probe = ArchiveProbe::new(&fake, 10);
        let enriched = probe
            .enrich(&obfuscated(vec![file("x.rar", 900, "one")]))
            .expect("headers");
        let donors = DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks::default(),
        };
        let Outcome::Identified(found) = identify(&enriched, &context).0 else {
            panic!("expected an identification");
        };
        assert_eq!((found.method, found.predb_id), ("predb_exact", Some(5)));
    }

    #[test]
    fn the_budget_and_missing_articles_are_respected() {
        let fake = Fake::default();
        let probe = ArchiveProbe::new(&fake, 1);
        let release = obfuscated(vec![file("a.rar", 900, "gone")]);
        assert!(probe.enrich(&release).is_none());
        assert!(probe.enrich(&release).is_none());
        let stats = probe.render_stats();
        assert!(stats.contains("| Probed | 1 |"), "{stats}");
        assert!(
            stats.contains("Articles asked for: 1. Not available: 1."),
            "{stats}"
        );
    }
}
