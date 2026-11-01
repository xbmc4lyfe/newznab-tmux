//! A filename pass: ordered PreDB observations for a release's files, and the guard that
//! re-establishes them inside the transaction that acts on them.

use anyhow::Result;
use nntmux_predb::filename::{OriginalFilenameEvidence, clean_for_filename_discovery, exact_value};
use nntmux_predb::identity::{self, Candidate, Lookup};
use nntmux_predb::selector::{PredbHit, select_best_match};
use sqlx::{MySqlConnection, MySqlPool};

/// One filename's observation.
#[derive(Clone, Debug, PartialEq)]
pub struct Observation {
    pub filename: String,
    pub matched: Option<PredbHit>,
    /// The catalogue has something to say about this file, so generic naming must not act on it.
    pub evidence: bool,
    pub complete: bool,
}

/// Observed nominations only. Guarded writes must freshly establish SQL authority.
#[derive(Clone, Debug, PartialEq)]
pub struct FilenamePass {
    pub lookups: Vec<Observation>,
}

impl FilenamePass {
    pub fn complete(&self) -> bool {
        self.lookups.iter().all(|lookup| lookup.complete)
    }

    pub fn has_matches(&self) -> bool {
        self.lookups.iter().any(|lookup| lookup.matched.is_some())
    }

    pub fn filenames(&self) -> Vec<String> {
        self.lookups.iter().map(|lookup| lookup.filename.clone()).collect()
    }

    /// Every match, when all agree on one catalogue identity and the pass is complete.
    pub fn unique_matches(&self) -> Vec<(String, PredbHit)> {
        if !self.complete() {
            return Vec::new();
        }
        let mut selected = Vec::new();
        let mut identity: Option<(i64, String)> = None;
        for lookup in &self.lookups {
            let Some(hit) = &lookup.matched else {
                continue;
            };
            let current = (hit.id, exact_value(&hit.title));
            if identity.as_ref().is_some_and(|known| *known != current) {
                return Vec::new();
            }
            identity = Some(current);
            selected.push((lookup.filename.clone(), hit.clone()));
        }
        selected
    }
}

enum Eligible {
    Skip,
    Lookup(OriginalFilenameEvidence, String),
}

fn classify(filename: &str, minimum_length: usize) -> (Observation, Eligible) {
    let evidence = OriginalFilenameEvidence::new(filename);
    let observation = Observation {
        filename: filename.to_owned(),
        matched: None,
        evidence: true,
        complete: true,
    };
    if evidence.is_locally_rejected_pipe() {
        return (observation, Eligible::Skip);
    }
    match clean_for_filename_discovery(&evidence) {
        // PHP's empty() is also true for "0".
        Some(query) if !query.is_empty() && query != "0" && query.len() >= minimum_length => {
            (observation, Eligible::Lookup(evidence, query))
        }
        _ => (observation, Eligible::Skip),
    }
}

fn apply(observation: &mut Observation, result: Lookup, evidence: &OriginalFilenameEvidence, query: &str) {
    observation.complete = result != Lookup::Unknown;
    observation.evidence = result != Lookup::Absent;
    if let Lookup::Unique(candidate) = result {
        observation.matched = best_match(query, evidence, &candidate);
    }
}

/// Certified uniqueness doesn't bypass generic-name and scoring policy.
fn best_match(query: &str, evidence: &OriginalFilenameEvidence, candidate: &Candidate) -> Option<PredbHit> {
    if !evidence.accepts(&candidate.title, &candidate.filename) {
        return None;
    }
    let hits = [PredbHit {
        id: candidate.id as i64,
        title: candidate.title.clone(),
        filename: candidate.filename.clone(),
        source: String::new(),
    }];
    let found = select_best_match(&evidence.stem, &hits, Some(evidence))
        .or_else(|| select_best_match(&evidence.basename, &hits, Some(evidence)))
        .or_else(|| select_best_match(query, &hits, Some(evidence)))?;
    (found.id == candidate.id as i64 && exact_value(&found.title) == exact_value(&candidate.title)).then(|| found.clone())
}

/// Observes every filename in one authority envelope.
pub async fn collect_pass(db: &MySqlPool, filenames: &[String], minimum_length: usize) -> FilenamePass {
    let (mut lookups, eligible) = split(filenames, minimum_length);
    let evidences: Vec<_> = eligible.iter().map(|(_, evidence, _)| evidence.clone()).collect();
    let results = identity::lookup_many(db, &evidences).await;
    for ((index, evidence, query), result) in eligible.iter().zip(results) {
        apply(&mut lookups[*index], result, evidence, query);
    }
    FilenamePass { lookups }
}

async fn collect_pass_in(conn: &mut MySqlConnection, filenames: &[String], minimum_length: usize) -> FilenamePass {
    let (mut lookups, eligible) = split(filenames, minimum_length);
    let evidences: Vec<_> = eligible.iter().map(|(_, evidence, _)| evidence.clone()).collect();
    let results = identity::lookup_many_in(conn, &evidences).await;
    for ((index, evidence, query), result) in eligible.iter().zip(results) {
        apply(&mut lookups[*index], result, evidence, query);
    }
    FilenamePass { lookups }
}

fn split(filenames: &[String], minimum_length: usize) -> (Vec<Observation>, Vec<(usize, OriginalFilenameEvidence, String)>) {
    let mut lookups = Vec::with_capacity(filenames.len());
    let mut eligible = Vec::new();
    for (index, filename) in filenames.iter().enumerate() {
        let (observation, class) = classify(filename, minimum_length);
        lookups.push(observation);
        if let Eligible::Lookup(evidence, query) = class {
            eligible.push((index, evidence, query));
        }
    }
    (lookups, eligible)
}

fn sorted_unique(mut names: Vec<String>) -> Vec<String> {
    names.sort();
    names.dedup();
    names
}

/// The current file names, read under a shared lock so newly committed ones show.
async fn locked_inventory(conn: &mut MySqlConnection, release_id: i64) -> Result<Vec<String>> {
    Ok(sqlx::query_scalar("SELECT name FROM release_files WHERE releases_id = ? LOCK IN SHARE MODE")
        .bind(release_id)
        .fetch_all(conn)
        .await?)
}

/// Fails closed once it has failed: the inventory must be unchanged and the pass reproduce.
pub struct FilenameGuard {
    release_id: i64,
    pass: FilenamePass,
    minimum_length: usize,
    inventory: Vec<String>,
    valid: bool,
}

impl FilenameGuard {
    pub fn new(release_id: i64, pass: &FilenamePass, minimum_length: usize, inventory: Vec<String>) -> Self {
        Self {
            release_id,
            pass: pass.clone(),
            minimum_length,
            inventory: sorted_unique(inventory),
            valid: true,
        }
    }

    pub fn inventory(&self) -> &[String] {
        &self.inventory
    }

    pub async fn accepted(&mut self, conn: &mut MySqlConnection) -> Result<bool> {
        if !self.valid {
            return Ok(false);
        }
        let current = sorted_unique(locked_inventory(conn, self.release_id).await?);
        if current != self.inventory || !self.pass.complete() {
            self.valid = false;
            return Ok(false);
        }
        let fresh = collect_pass_in(conn, &self.pass.filenames(), self.minimum_length).await;
        self.valid = fresh.complete() && fresh == self.pass;
        Ok(self.valid)
    }
}
