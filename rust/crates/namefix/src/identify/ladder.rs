//! The ladder: every rung gets the names and facts a release has, and the first to answer wins.
//!
//! Network and download rungs go last, behind the hooks.

use std::time::Instant;

use anyhow::Result;

use super::donor::{DonorIndex, DonorKey, DonorMatch};
use super::local_predb::{LocalPredb, NameMatch, PredbRow};
use super::name::{self, ParsedName, Scope};
use super::nzb::NzbSummary;

const MAX_SIZE_CANDIDATES: usize = 200;
const IDENTIFY_ATTEMPTS: &str = "nntmux_identify_attempts_total";
const IDENTIFY_SUCCESSES: &str = "nntmux_identify_successes_total";
const IDENTIFY_DURATION: &str = "nntmux_identify_step_duration_seconds";

/// Where a candidate name came from, cheapest first.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
pub enum NameSource {
    Subject,
    NzbFile,
    Par2File,
    Listing,
    MediaInfo,
}

impl NameSource {
    pub const ALL: [Self; 5] = [
        Self::Subject,
        Self::NzbFile,
        Self::Par2File,
        Self::Listing,
        Self::MediaInfo,
    ];

    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Subject => "subject",
            Self::NzbFile => "nzb_file",
            Self::Par2File => "par2_file",
            Self::Listing => "archive_listing",
            Self::MediaInfo => "media_info",
        }
    }

    const fn regex_method(self) -> &'static str {
        match self {
            Self::Subject => "release_name_regex",
            Self::NzbFile => "nzb_filename_regex",
            Self::Par2File => "par2_filename_regex",
            Self::Listing => "archive_listing_regex",
            Self::MediaInfo => "media_info_regex",
        }
    }

    const fn regex_confidence(self) -> f64 {
        match self {
            Self::Subject | Self::NzbFile => 0.8,
            Self::Par2File | Self::Listing => 0.85,
            Self::MediaInfo => 0.6,
        }
    }
}

/// Everything known about one release before any network or download.
#[derive(Clone, Debug, Default)]
pub struct Evidence {
    pub id: i64,
    pub name: String,
    pub search_name: String,
    pub size_bytes: i64,
    pub posted_at: i64,
    pub height: Option<i64>,
    pub nzb: Option<NzbSummary>,
    /// Filenames PAR2 packets carry.
    pub par2_names: Vec<String>,
    /// Paths from RAR, 7z or similar listings, biggest first.
    pub listing: Vec<(String, i64)>,
    pub media_names: Vec<String>,
    pub facts: Vec<(DonorKey, String)>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Identification {
    pub release_id: i64,
    pub name: String,
    pub method: &'static str,
    pub evidence_source: &'static str,
    pub confidence: f64,
    pub scope: Scope,
    pub parsed: ParsedName,
    pub predb_id: Option<i64>,
    pub donor_release_id: Option<i64>,
    pub note: Option<String>,
}

/// What the evidence report counts. A flag says the evidence exists, not that it was used.
#[derive(Clone, Debug, Default)]
pub struct Flags {
    pub nzb: bool,
    pub nzb_par2: bool,
    pub nzb_named: bool,
    pub subject_named: bool,
    pub par2: bool,
    pub par2_named: bool,
    pub listing: bool,
    pub listing_named: bool,
    pub media_info: bool,
    pub media_named: bool,
    pub predb_exact: bool,
    pub predb_normalized: bool,
    pub predb_size_any: bool,
    pub predb_size_unique: bool,
    pub donor: bool,
}

impl Flags {
    /// No readable name, PreDB hit or shared file anywhere: only size or a download could help.
    pub fn obfuscated_only(&self) -> bool {
        !(self.subject_named
            || self.nzb_named
            || self.par2_named
            || self.listing_named
            || self.media_named
            || self.predb_exact
            || self.predb_normalized
            || self.donor)
    }
}

#[derive(Clone, Debug, PartialEq)]
pub enum Outcome {
    Identified(Box<Identification>),
    /// Evidence pointed at more than one identity and nothing settled it.
    Ambiguous(&'static str),
    Unidentified,
}

/// What srrDB gets asked: CRCs of the files and whatever filenames are known.
#[derive(Clone, Debug, Default)]
pub struct SrrdbQuery {
    pub crc32s: Vec<String>,
    pub file_names: Vec<String>,
    pub size_bytes: i64,
    /// PreDB titles that fit the post's size and time, which on their own prove nothing.
    pub size_candidates: Vec<String>,
}

/// Asks srrDB for the release a file belongs to. The next piece of work fills this in.
pub trait SrrdbLookup: Send + Sync {
    /// The scene name of the release, when srrDB knows one.
    fn lookup(&self, query: &SrrdbQuery) -> Result<Option<String>>;
}

/// The release a fingerprinter works from: it needs the NZB to reach the articles.
#[derive(Clone, Debug)]
pub struct StreamQuery<'a> {
    pub release_id: i64,
    pub nzb: Option<&'a NzbSummary>,
}

/// Matches audio and subtitle streams, read by ffprobe from sampled articles, against known
/// releases. The next piece of work fills this in.
pub trait StreamFingerprinter: Send + Sync {
    fn identify(&self, query: &StreamQuery<'_>) -> Result<Option<String>>;
}

/// Stands in for a hook that isn't set up: it knows nothing and costs nothing.
pub struct NotConfigured;

impl SrrdbLookup for NotConfigured {
    fn lookup(&self, _query: &SrrdbQuery) -> Result<Option<String>> {
        Ok(None)
    }
}

impl StreamFingerprinter for NotConfigured {
    fn identify(&self, _query: &StreamQuery<'_>) -> Result<Option<String>> {
        Ok(None)
    }
}

pub struct Hooks<'a> {
    pub srrdb: &'a dyn SrrdbLookup,
    pub fingerprint: &'a dyn StreamFingerprinter,
}

impl Default for Hooks<'_> {
    fn default() -> Self {
        Self {
            srrdb: &NotConfigured,
            fingerprint: &NotConfigured,
        }
    }
}

pub struct Context<'a> {
    pub predb: &'a LocalPredb,
    pub donors: &'a DonorIndex,
    pub hooks: Hooks<'a>,
}

/// Every name worth asking PreDB about, for a caller that has to fetch the rows first.
pub fn candidate_names(evidence: &Evidence) -> Vec<String> {
    let mut flags = Flags::default();
    candidates(evidence, &mut flags)
        .into_iter()
        .chain(decode_targets(evidence))
        .map(|(_, name)| name)
        .collect()
}

/// The candidates of one source, in the order they should be tried.
fn candidates(evidence: &Evidence, flags: &mut Flags) -> Vec<(NameSource, String)> {
    let mut out: Vec<(NameSource, String)> = Vec::new();
    let mut push = |source, names: Vec<String>| -> bool {
        let found = !names.is_empty();
        for name in names {
            if !out.iter().any(|(_, known)| *known == name) {
                out.push((source, name));
            }
        }
        found
    };

    let mut subject = Vec::new();
    for text in [&evidence.search_name, &evidence.name] {
        if let Some(file) = name::name_from_subject(text) {
            subject.extend(name::names_from_path(&file));
        }
        subject.extend(name::subject_prefix(text));
    }
    flags.subject_named = push(NameSource::Subject, subject);

    if let Some(nzb) = &evidence.nzb {
        flags.nzb = true;
        flags.nzb_par2 = nzb.par2_files > 0;
        let mut files = Vec::new();
        for file in &nzb.files {
            if let Some(found) = name::name_from_subject(&file.subject) {
                files.extend(name::names_from_path(&found));
            }
            files.extend(name::subject_prefix(&file.subject));
        }
        flags.nzb_named = push(NameSource::NzbFile, files);
    }

    flags.par2 = !evidence.par2_names.is_empty();
    let par2 = evidence
        .par2_names
        .iter()
        .flat_map(|file| name::names_from_path(file))
        .collect();
    flags.par2_named = push(NameSource::Par2File, par2);

    flags.listing = !evidence.listing.is_empty();
    let listing = evidence
        .listing
        .iter()
        .flat_map(|(file, _)| name::names_from_path(file))
        .collect();
    flags.listing_named = push(NameSource::Listing, listing);

    flags.media_info = !evidence.media_names.is_empty();
    let media = evidence
        .media_names
        .iter()
        .flat_map(|file| name::names_from_path(file))
        .collect();
    flags.media_named = push(NameSource::MediaInfo, media);

    out
}

/// A bare, obfuscated name worth decoding, kept apart so it can't vouch for anything by itself.
fn decode_targets(evidence: &Evidence) -> Vec<(NameSource, String)> {
    let mut raw: Vec<(NameSource, String)> = Vec::new();
    if let Some(file) = name::name_from_subject(&evidence.name) {
        raw.push((NameSource::Subject, file));
    }
    if let Some(nzb) = &evidence.nzb {
        raw.extend(
            nzb.files
                .iter()
                .take(3)
                .filter_map(|file| name::name_from_subject(&file.subject))
                .map(|file| (NameSource::NzbFile, file)),
        );
    }
    raw.extend(
        evidence
            .par2_names
            .iter()
            .take(3)
            .map(|file| (NameSource::Par2File, file.clone())),
    );
    let mut out = Vec::new();
    for (source, file) in raw {
        // Longer names only: a short token has too little to tell noise from words.
        if file.len() < 16 || file.contains(['{', '}']) {
            continue;
        }
        for decoded in name::decoded_variants(&file) {
            let stem = name::stem(&decoded);
            if name::usable(&stem) && !out.iter().any(|(_, known)| *known == stem) {
                out.push((source, stem));
            }
        }
    }
    out
}

fn timed<T>(step: &'static str, run: impl FnOnce() -> Option<T>) -> Option<T> {
    let started = Instant::now();
    metrics::counter!(IDENTIFY_ATTEMPTS, "step" => step).increment(1);
    let answer = run();
    metrics::histogram!(IDENTIFY_DURATION, "step" => step).record(started.elapsed().as_secs_f64());
    if answer.is_some() {
        metrics::counter!(IDENTIFY_SUCCESSES, "step" => step).increment(1);
    }
    answer
}

/// The identity a run of names agrees on. Episodes of one show are a pack, and anything else
/// needs a clear majority, so bonus files or a stray sample don't sink a real name.
enum Agreement {
    One(String),
    Pack(String),
    Split,
}

fn agree<'a>(names: impl Iterator<Item = &'a str>) -> Option<Agreement> {
    let mut groups: Vec<(String, Vec<&str>)> = Vec::new();
    let mut total = 0;
    for name in names {
        total += 1;
        let parsed = name::parse(name);
        let key = format!(
            "{}|{:?}|{:?}",
            name::folded(&parsed.title),
            parsed.year,
            parsed.season
        );
        match groups.iter_mut().find(|(known, _)| *known == key) {
            Some((_, members)) => members.push(name),
            None => groups.push((key, vec![name])),
        }
    }
    if total == 0 {
        return None;
    }
    let (_, members) = groups.iter().max_by_key(|(_, members)| members.len())?;
    if members.len() * 3 < total * 2 {
        return Some(Agreement::Split);
    }
    let first = members[0];
    let episodes: std::collections::HashSet<_> = members
        .iter()
        .map(|name| name::parse(name).episode)
        .collect();
    if episodes.len() > 1 {
        Some(Agreement::Pack(name::pack_name(first)))
    } else {
        Some(Agreement::One(first.to_owned()))
    }
}

struct Named<'a> {
    name: String,
    method: &'static str,
    source: &'static str,
    confidence: f64,
    predb: Option<&'a PredbRow>,
    donor: Option<i64>,
    note: Option<String>,
}

fn finish(evidence: &Evidence, named: Named<'_>) -> Outcome {
    let parsed = name::parse(&named.name);
    let category = named.predb.map(|row| row.category.as_str());
    let scope = name::scope(&parsed, category, evidence.height);
    Outcome::Identified(Box::new(Identification {
        release_id: evidence.id,
        name: named.name,
        method: named.method,
        evidence_source: named.source,
        confidence: named.confidence,
        scope,
        parsed,
        predb_id: named.predb.map(|row| row.id),
        donor_release_id: named.donor,
        note: named.note,
    }))
}

/// A name match whose sizes disagree badly is more likely a re-use of the name than the release.
fn size_note(evidence: &Evidence, row: &PredbRow) -> Option<String> {
    if row.size_mib == 0 {
        return None;
    }
    let payload = evidence
        .nzb
        .as_ref()
        .map_or(evidence.size_bytes as f64, |nzb| nzb.payload_bytes as f64);
    let ratio = payload / 1_048_576.0 / f64::from(row.size_mib);
    (!(0.7..=1.5).contains(&ratio)).then(|| format!("size ratio {ratio:.2} to PreDB"))
}

/// Runs the ladder on one release. `flags` comes back filled in whatever the outcome.
pub fn identify(evidence: &Evidence, context: &Context<'_>) -> (Outcome, Flags) {
    let (mut outcome, flags) = climb(evidence, context);
    // The tags can't tell One.Piece.S15.1080p.WEB-DL from a live-action show, but PreDB can. Only
    // for series: a film can share its title with an anime and still be something else.
    if let Outcome::Identified(found) = &mut outcome
        && found.parsed.kind == name::Kind::Tv
        && (context.predb.is_anime_title(&found.parsed.title)
            || name::is_known_anime_title(&found.parsed.title))
    {
        found.parsed.kind = name::Kind::Anime;
        found.scope = Scope::Anime;
    }
    (outcome, flags)
}

fn climb(evidence: &Evidence, context: &Context<'_>) -> (Outcome, Flags) {
    let mut flags = Flags::default();
    let plain = candidates(evidence, &mut flags);
    let decoded = decode_targets(evidence);
    let mut ambiguous = None;

    // PreDB, by every name there is. Decoded names may only ever pass here or with quality tags.
    let hits = timed("predb_name", || {
        let mut hits: Vec<(&PredbRow, NameMatch, NameSource, bool)> = Vec::new();
        for (source, candidate, was_decoded) in plain
            .iter()
            .map(|(source, name)| (source, name, false))
            .chain(decoded.iter().map(|(source, name)| (source, name, true)))
        {
            if let Some((row, how)) = context.predb.by_name(candidate) {
                hits.push((row, how, *source, was_decoded));
            }
        }
        (!hits.is_empty()).then_some(hits)
    });
    if let Some(hits) = hits {
        flags.predb_exact = hits.iter().any(|hit| hit.1 == NameMatch::Exact);
        flags.predb_normalized = hits.iter().any(|hit| hit.1 == NameMatch::Normalized);
        match agree(hits.iter().map(|hit| hit.0.title.as_str())) {
            Some(Agreement::One(title)) => {
                let hit = hits
                    .iter()
                    .find(|hit| hit.0.title == title)
                    .expect("the title came from these hits");
                let exact = hit.1 == NameMatch::Exact;
                let note = size_note(evidence, hit.0);
                let confidence = match (exact, note.is_some()) {
                    (_, true) => 0.5,
                    (true, false) => 0.99,
                    (false, false) => 0.95,
                };
                let outcome = finish(
                    evidence,
                    Named {
                        name: title,
                        method: if exact {
                            "predb_exact"
                        } else {
                            "predb_normalized"
                        },
                        source: hit.2.as_str(),
                        confidence,
                        predb: Some(hit.0),
                        donor: None,
                        note: if hit.3 {
                            Some(note.map_or("decoded name".to_owned(), |n| {
                                format!("decoded name, {n}")
                            }))
                        } else {
                            note
                        },
                    },
                );
                return finish_with_flags(outcome, flags, evidence, context);
            }
            Some(Agreement::Pack(name)) => {
                let season = name::parse(&name);
                let hit = hits
                    .iter()
                    .find(|hit| {
                        let parsed = name::parse(&hit.0.title);
                        name::folded(&parsed.title) == name::folded(&season.title)
                            && parsed.season == season.season
                    })
                    .unwrap_or(&hits[0]);
                let outcome = finish(
                    evidence,
                    Named {
                        name,
                        method: "predb_pack",
                        source: hit.2.as_str(),
                        confidence: 0.9,
                        predb: Some(hit.0),
                        donor: None,
                        note: Some(format!("{} PreDB episodes", hits.len())),
                    },
                );
                // A pack isn't one PreDB row. The row only supplied the category.
                let outcome = match outcome {
                    Outcome::Identified(mut found) => {
                        found.predb_id = None;
                        Outcome::Identified(found)
                    }
                    other => other,
                };
                return finish_with_flags(outcome, flags, evidence, context);
            }
            Some(Agreement::Split) => ambiguous = Some("predb_names_disagree"),
            None => {}
        }
    }

    // The name a source spells out, with no PreDB row behind it.
    for source in NameSource::ALL {
        let answer = timed(source.regex_method(), || {
            let names: Vec<&str> = plain
                .iter()
                .filter(|(from, _)| *from == source)
                .map(|(_, name)| name.as_str())
                .filter(|name| {
                    name::acceptable(&name::parse(name), name, source != NameSource::MediaInfo)
                })
                .collect();
            match agree(names.iter().copied())? {
                Agreement::One(name) => Some((name, None)),
                Agreement::Pack(name) => Some((name, Some(format!("{} episodes", names.len())))),
                Agreement::Split => {
                    ambiguous = Some("file_names_disagree");
                    None
                }
            }
        });
        if let Some((name, note)) = answer {
            let outcome = finish(
                evidence,
                Named {
                    name,
                    method: source.regex_method(),
                    source: source.as_str(),
                    confidence: source.regex_confidence(),
                    predb: None,
                    donor: None,
                    note,
                },
            );
            return finish_with_flags(outcome, flags, evidence, context);
        }
    }

    // A decoded name stands only with quality tags, because noise decodes into noise.
    let answer = timed("decoded_name_regex", || {
        decoded.iter().find(|(_, name)| {
            let parsed = name::parse(name);
            parsed.resolution.is_some()
                && parsed.source.is_some()
                && name::acceptable(&parsed, name, true)
        })
    });
    if let Some((source, name)) = answer {
        let outcome = finish(
            evidence,
            Named {
                name: name.clone(),
                method: "decoded_name_regex",
                source: source.as_str(),
                confidence: 0.75,
                predb: None,
                donor: None,
                note: None,
            },
        );
        return finish_with_flags(outcome, flags, evidence, context);
    }

    // A file shared with a release that already has a name.
    let donor = timed("donor", || {
        match context.donors.find(&evidence.facts, evidence.id) {
            DonorMatch::None => None,
            found => Some(found),
        }
    });
    match donor {
        Some(DonorMatch::Found {
            key,
            release_id,
            name,
        }) => {
            flags.donor = true;
            let outcome = finish(
                evidence,
                Named {
                    name: name.to_owned(),
                    method: key.method(),
                    source: "donor",
                    confidence: key.confidence(),
                    predb: None,
                    donor: Some(release_id),
                    note: None,
                },
            );
            return finish_with_flags(outcome, flags, evidence, context);
        }
        Some(DonorMatch::Conflict(_)) => {
            flags.donor = true;
            ambiguous = Some("donors_disagree");
        }
        _ => {}
    }

    let size_candidates = size_candidates(evidence, context, &mut flags);

    // Last, because these leave the machine.
    let query = SrrdbQuery {
        crc32s: evidence
            .facts
            .iter()
            .filter(|(key, _)| *key == DonorKey::Crc32)
            .map(|(_, value)| value.clone())
            .collect(),
        file_names: plain.iter().map(|(_, name)| name.clone()).collect(),
        size_bytes: evidence.size_bytes,
        size_candidates,
    };
    if let Some(name) = hook_answer("srrdb", || context.hooks.srrdb.lookup(&query)) {
        let outcome = finish(
            evidence,
            Named {
                name,
                method: "srrdb",
                source: "srrdb",
                confidence: 0.95,
                predb: None,
                donor: None,
                note: None,
            },
        );
        return (outcome, flags);
    }
    let stream = StreamQuery {
        release_id: evidence.id,
        nzb: evidence.nzb.as_ref(),
    };
    if let Some(name) = hook_answer("stream_fingerprint", || {
        context.hooks.fingerprint.identify(&stream)
    }) {
        let outcome = finish(
            evidence,
            Named {
                name,
                method: "stream_fingerprint",
                source: "stream_fingerprint",
                confidence: 0.85,
                predb: None,
                donor: None,
                note: None,
            },
        );
        return (outcome, flags);
    }

    let outcome = ambiguous.map_or(Outcome::Unidentified, Outcome::Ambiguous);
    (outcome, flags)
}

fn hook_answer(step: &'static str, run: impl FnOnce() -> Result<Option<String>>) -> Option<String> {
    timed(step, || match run() {
        Ok(answer) => answer,
        Err(error) => {
            tracing::warn!(step, %error, "identify hook failed");
            None
        }
    })
}

/// Size and time alone never name a release: tried on 7,941 releases with a known PreDB row, a
/// lone fitting row was right 0 times out of 8, and a file count didn't rescue it (9 right of 1,429
/// at best). What it finds are candidates for srrDB, which can check them against real files.
fn size_candidates(evidence: &Evidence, context: &Context<'_>, flags: &mut Flags) -> Vec<String> {
    let Some(nzb) = &evidence.nzb else {
        return Vec::new();
    };
    let rows = timed("predb_size_candidates", || {
        let rows = context.predb.by_size(nzb.payload_bytes, evidence.posted_at);
        (!rows.is_empty()).then_some(rows)
    })
    .unwrap_or_default();
    flags.predb_size_any = !rows.is_empty();
    flags.predb_size_unique = rows.len() == 1;
    rows.iter()
        .take(MAX_SIZE_CANDIDATES)
        .map(|row| row.title.clone())
        .collect()
}

/// Fills in the flags later rungs would have set, so the report counts all the evidence a
/// release has, not only the evidence the winning rung got to see.
fn finish_with_flags(
    outcome: Outcome,
    mut flags: Flags,
    evidence: &Evidence,
    context: &Context<'_>,
) -> (Outcome, Flags) {
    if !matches!(
        context.donors.find(&evidence.facts, evidence.id),
        DonorMatch::None
    ) {
        flags.donor = true;
    }
    if let Some(nzb) = &evidence.nzb {
        let rows = context.predb.by_size(nzb.payload_bytes, evidence.posted_at);
        flags.predb_size_any = !rows.is_empty();
        flags.predb_size_unique = rows.len() == 1;
    }
    (outcome, flags)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::identify::local_predb::PredbRow;
    use crate::identify::nzb::NzbFile;

    const POSTED: i64 = 1_000_000;

    fn predb() -> LocalPredb {
        let mut index = LocalPredb::default();
        for (id, title, size) in [
            (10, "Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR", 2468),
            (11, "Zmora.S01E06.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR", 2500),
            (12, "Unique.Size.Film.2024.2160p.WEB.H265-GRP", 9000),
            (13, "Twin.One.2024.1080p.WEB.H264-GRP", 5000),
            (14, "Twin.Two.2024.1080p.WEB.H264-GRP", 5000),
            (
                15,
                "Some.Show.S01E01.German.DL.ANiME.1080p.WEB.h264-GRP",
                1500,
            ),
        ] {
            index.insert(PredbRow {
                id,
                title: title.to_owned(),
                category: "TV".to_owned(),
                size_mib: size,
                files: 20,
                pre_at: Some(POSTED),
            });
        }
        index.finish();
        index
    }

    fn release(name: &str) -> Evidence {
        Evidence {
            id: 1,
            name: name.to_owned(),
            search_name: name.to_owned(),
            size_bytes: 6_000_000_000,
            posted_at: POSTED,
            ..Evidence::default()
        }
    }

    fn nzb(subjects: &[(&str, u64)]) -> NzbSummary {
        NzbSummary {
            files: subjects
                .iter()
                .map(|(subject, bytes)| NzbFile {
                    subject: (*subject).to_owned(),
                    bytes: *bytes,
                })
                .collect(),
            payload_bytes: subjects.iter().map(|(_, bytes)| bytes).sum(),
            payload_files: subjects.len() as u32,
            par2_files: 0,
        }
    }

    fn run(evidence: &Evidence, donors: &DonorIndex) -> Outcome {
        let predb = predb();
        let context = Context {
            predb: &predb,
            donors,
            hooks: Hooks::default(),
        };
        identify(evidence, &context).0
    }

    fn identified(outcome: Outcome) -> Identification {
        match outcome {
            Outcome::Identified(found) => *found,
            other => panic!("expected an identification, got {other:?}"),
        }
    }

    #[test]
    fn an_obfuscated_post_with_no_evidence_stays_unidentified() {
        let evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        assert_eq!(
            run(&evidence, &DonorIndex::default()),
            Outcome::Unidentified
        );
    }

    #[test]
    fn the_subject_name_matches_predb() {
        let evidence =
            release(r#"[1/9] - "Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.part1.rar" yEnc"#);
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.method, "predb_exact");
        assert_eq!(found.predb_id, Some(10));
        assert_eq!(found.scope, Scope::InScope);
    }

    #[test]
    fn a_name_without_predb_stands_on_its_tags() {
        let evidence = release(r#"[1/1] "Unlisted.Film.2023.1080p.BluRay.x264-NOGRP.mkv" yEnc"#);
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.method, "release_name_regex");
        assert_eq!(found.scope, Scope::InScope);
    }

    #[test]
    fn a_single_big_mkv_is_never_turned_away() {
        let mut evidence = release(r#"funxd.mkv yEnc"#);
        evidence.nzb = Some(nzb(&[(
            r#""Huge.Film.2023.2160p.UHD.BluRay.REMUX.HEVC.TrueHD.7.1-GRP.mkv" yEnc"#,
            64_000_000_000,
        )]));
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.method, "nzb_filename_regex");
        assert_eq!(found.scope, Scope::InScope);
        assert_eq!(found.parsed.source, Some("remux"));
    }

    #[test]
    fn nzb_files_are_read_when_the_subject_says_nothing() {
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.nzb = Some(nzb(&[(
            r#"[1/9] "Zmora.S01E06.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.mkv" yEnc"#,
            2_600_000_000,
        )]));
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(
            (found.method, found.evidence_source),
            ("predb_exact", "nzb_file")
        );
    }

    #[test]
    fn a_folder_in_a_listing_names_the_release() {
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.listing = vec![(
            "Unlisted.Show.S03E02.1080p.WEB-DL.H264-GRP/7d8c650a91cf437a8dd9ecce444c1ddc.mkv"
                .to_owned(),
            3_000_000_000,
        )];
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.method, "archive_listing_regex");
        assert_eq!(found.parsed.episode, Some(2));
    }

    #[test]
    fn par2_names_are_evidence() {
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.par2_names = vec!["Unlisted.Film.2022.2160p.WEB-DL.H265-GRP.mkv".to_owned()];
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.method, "par2_filename_regex");
    }

    #[test]
    fn episodes_of_one_season_make_a_pack() {
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.nzb = Some(nzb(&[
            (
                r#""Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.mkv" yEnc"#,
                2_600_000_000,
            ),
            (
                r#""Zmora.S01E06.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.mkv" yEnc"#,
                2_600_000_000,
            ),
        ]));
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.method, "predb_pack");
        assert_eq!(found.name, "Zmora.S01.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR");
        assert_eq!(found.parsed.episode, None);
    }

    #[test]
    fn a_counter_at_the_end_of_subjects_changes_nothing() {
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.nzb = Some(nzb(&[(
            r#"[01/01] - "Unlisted.Film.2023.1080p.BluRay.x264-NOGRP.mkv" yEnc (1/500)"#,
            3_000_000_000,
        )]));
        assert_eq!(
            identified(run(&evidence, &DonorIndex::default())).method,
            "nzb_filename_regex"
        );

        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.nzb = Some(nzb(&[(
            "Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.mkv yEnc (1/500)",
            2_600_000_000,
        )]));
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!((found.method, found.predb_id), ("predb_exact", Some(10)));
    }

    #[test]
    fn anime_with_no_tag_is_out_of_scope() {
        let mut predb = predb();
        for (id, episode) in [(90, 11), (91, 12)] {
            predb.insert(PredbRow {
                id,
                title: format!("Dan.Da.Dan.S02E{episode}.German.2025.ANiME.DL.1080p.WEB.h264-GRP"),
                category: "TV-1080P-DE".to_owned(),
                size_mib: 0,
                files: 0,
                pre_at: None,
            });
        }
        predb.finish();
        let donors = DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks::default(),
        };
        for name in [
            "One.Piece.S15.Grim.Prospects.A.Trap.that.Waits.in.the.New.World.1080p.CR.WEB-DL.DUAL.DDP2.0.H.264-Kitsune",
            "Hunter.x.Hunter.S03E56.1080p.NF.WEB-DL.DDP2.0.x264.DUAL-sh4down",
            "Dan.da.Dan.S02E03.1080p.BluRay.FLAC.2.0.x265.DUAL-FRNcR",
        ] {
            let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
            evidence.par2_names = vec![format!("{name}.mkv")];
            let found = identified(identify(&evidence, &context).0);
            assert_eq!(found.scope, Scope::Anime, "{name}");
        }
        // A live-action show with no such history stays in.
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.par2_names =
            vec!["Wednesday.S02E01.1080p.NF.WEB-DL.AAC2.0.AV1.DUAL-Lup1n.mkv".to_owned()];
        assert_eq!(
            identified(identify(&evidence, &context).0).scope,
            Scope::InScope
        );
    }

    #[test]
    fn names_that_disagree_decide_nothing() {
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.nzb = Some(nzb(&[
            (
                r#""Film.One.2021.1080p.WEB-DL.H264-AAA.mkv" yEnc"#,
                3_000_000_000,
            ),
            (
                r#""Film.Two.2022.1080p.WEB-DL.H264-BBB.mkv" yEnc"#,
                3_000_000_000,
            ),
        ]));
        assert_eq!(
            run(&evidence, &DonorIndex::default()),
            Outcome::Ambiguous("file_names_disagree")
        );
    }

    #[test]
    fn a_rot13_name_can_identify_with_tags() {
        // Unlisted.Film.2022.1080p.WEB-DL.H264-GRP
        let evidence = release(r#"[1/1] "Hayvfgrq.Svyz.2022.1080c.JRO-QY.U264-TEC.mkv" yEnc"#);
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.method, "decoded_name_regex");
        assert_eq!(found.parsed.title, "Unlisted Film");
    }

    #[test]
    fn noise_does_not_decode_into_an_identity() {
        let evidence = release(r#"[1/1] "kGb4MmDz8va2XUhdLxPq.mkv" yEnc"#);
        assert_eq!(
            run(&evidence, &DonorIndex::default()),
            Outcome::Unidentified
        );
    }

    #[test]
    fn a_shared_file_borrows_a_name() {
        let mut donors = DonorIndex::default();
        donors.add_name(2, "Harry.Potter.2001.2160p.BluRay.x265-MarkII".to_owned());
        donors.add_key(DonorKey::Crc32, "5615b8b1:8539116558".to_owned(), 2);
        let mut evidence = release(r#""4e87cbaf1e26498892fde2da5d7bb0f6.mkv" yEnc"#);
        evidence.facts = vec![(DonorKey::Crc32, "5615b8b1:8539116558".to_owned())];
        let found = identified(run(&evidence, &donors));
        assert_eq!(found.method, "donor_crc32");
        assert_eq!(found.donor_release_id, Some(2));
    }

    #[test]
    fn a_right_size_alone_names_nothing_but_reaches_srrdb() {
        struct Srr;
        impl SrrdbLookup for Srr {
            fn lookup(&self, query: &SrrdbQuery) -> Result<Option<String>> {
                Ok(query.size_candidates.first().cloned())
            }
        }
        let mut evidence = release(r#"[01/99] "GPInOt3kYN.part001.rar" yEnc"#);
        evidence.nzb = Some(nzb(&[(
            r#""GPInOt3kYN.mkv" yEnc"#,
            9000 * 1_048_576 * 102 / 100,
        )]));
        assert_eq!(
            run(&evidence, &DonorIndex::default()),
            Outcome::Unidentified
        );

        let predb = predb();
        let donors = DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks {
                srrdb: &Srr,
                fingerprint: &NotConfigured,
            },
        };
        let (outcome, flags) = identify(&evidence, &context);
        assert!(flags.predb_size_unique);
        let found = identified(outcome);
        assert_eq!(found.method, "srrdb");
        assert_eq!(found.name, "Unique.Size.Film.2024.2160p.WEB.H265-GRP");
    }

    #[test]
    fn a_size_that_disagrees_lowers_a_name_match() {
        let mut evidence =
            release(r#""Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.mkv" yEnc"#);
        evidence.nzb = Some(nzb(&[(
            r#""Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.mkv" yEnc"#,
            600_000_000,
        )]));
        let found = identified(run(&evidence, &DonorIndex::default()));
        assert_eq!(found.confidence, 0.5);
        assert!(found.note.is_some_and(|note| note.contains("size ratio")));
    }

    #[test]
    fn hooks_run_last_and_answer_only_when_nothing_else_did() {
        struct Srr;
        impl SrrdbLookup for Srr {
            fn lookup(&self, _query: &SrrdbQuery) -> Result<Option<String>> {
                Ok(Some("Found.By.Srrdb.2020.1080p.BluRay.x264-GRP".to_owned()))
            }
        }
        struct Fingerprint;
        impl StreamFingerprinter for Fingerprint {
            fn identify(&self, _query: &StreamQuery<'_>) -> Result<Option<String>> {
                panic!("srrDB answered first");
            }
        }
        let predb = predb();
        let donors = DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks {
                srrdb: &Srr,
                fingerprint: &Fingerprint,
            },
        };
        let (outcome, _) = identify(&release(r#""GPInOt3kYN.part001.rar" yEnc"#), &context);
        let found = identified(outcome);
        assert_eq!(found.method, "srrdb");
        assert_eq!(found.scope, Scope::InScope);

        // A hook isn't reached when the name was there all along.
        let (outcome, _) = identify(
            &release(r#""Unlisted.Film.2023.1080p.BluRay.x264-NOGRP.mkv" yEnc"#),
            &context,
        );
        assert_eq!(identified(outcome).method, "release_name_regex");
    }

    #[test]
    fn a_failing_hook_counts_as_no_answer() {
        struct Broken;
        impl SrrdbLookup for Broken {
            fn lookup(&self, _query: &SrrdbQuery) -> Result<Option<String>> {
                anyhow::bail!("network down")
            }
        }
        let predb = predb();
        let donors = DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks {
                srrdb: &Broken,
                fingerprint: &NotConfigured,
            },
        };
        let (outcome, _) = identify(&release(r#""GPInOt3kYN.part001.rar" yEnc"#), &context);
        assert_eq!(outcome, Outcome::Unidentified);
    }

    #[test]
    fn flags_record_evidence_on_a_release_that_was_identified_early() {
        let mut evidence =
            release(r#""Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.part1.rar" yEnc"#);
        evidence.par2_names = vec!["abc.part01.rar".to_owned()];
        let predb = predb();
        let donors = DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks::default(),
        };
        let (_, flags) = identify(&evidence, &context);
        assert!(flags.subject_named && flags.predb_exact && flags.par2 && !flags.par2_named);
        assert!(!flags.obfuscated_only());
    }
}
