//! Spot checks a bounded sample of articles before an upload may publish.

use std::collections::{BTreeMap, HashMap, HashSet};

use anyhow::Result;
use async_trait::async_trait;
use sha2::{Digest, Sha256};
use unicode_normalization::UnicodeNormalization;

use crate::article;
use crate::identity;
use crate::inventory::{self, HeaderStatus};
use crate::manifest::{Evidence, IdentityHint, Manifest, SpotCheck};

const MAX_BYTES: i64 = 1_048_576;
const MAX_ARTICLES: i64 = 16;

/// The limits from `config/release_verification.php`.
#[derive(Clone, Copy, Debug)]
pub struct SpotCheckOptions {
    pub articles: i64,
    pub bytes: i64,
    pub article_bytes: i64,
    pub stat_all_articles: bool,
}

impl SpotCheckOptions {
    fn byte_limit(&self) -> i64 {
        self.bytes.clamp(0, MAX_BYTES)
    }

    fn article_byte_limit(&self) -> i64 {
        self.article_bytes.clamp(0, self.byte_limit())
    }
}

/// An article fetch whose size is fixed: it can't come back smaller on a retry.
#[derive(Debug, thiserror::Error)]
#[error("{0}")]
pub struct ArticleBudgetExceeded(pub String);

/// Fetches articles. An article larger than `max_bytes` fails with [`ArticleBudgetExceeded`] without
/// being returned.
#[async_trait]
pub trait ArticleSource: Send + Sync {
    async fn fetch_bounded(&self, message_id: &str, max_bytes: i64) -> Result<Vec<u8>>;
    /// Extra availability evidence; it never replaces the sampled BODY checks.
    async fn stat(&self, message_ids: &[String]) -> Result<()>;
}

/// How an attempt ended.
#[derive(Clone, Debug, Default)]
pub struct VerificationResult {
    pub verdict: String,
    pub reason: String,
    pub manifest: Option<Manifest>,
    pub waiting_for_headers: bool,
    pub header_rejected: bool,
    pub deterministic: bool,
}

impl VerificationResult {
    pub fn passed(&self) -> bool {
        matches!(self.verdict.as_str(), "complete" | "repaired" | "sampled")
    }

    pub fn unknown(reason: impl Into<String>) -> Self {
        Self {
            verdict: "unknown".to_owned(),
            reason: reason.into(),
            ..Self::default()
        }
    }

    /// The manifest version, its planned sample and the configuration decide this outcome, so an
    /// immediate retry would fetch the same article and fail the same way. Transport and decoding
    /// errors keep the ordinary retry interval.
    fn deterministic(reason: impl Into<String>) -> Self {
        Self {
            deterministic: true,
            ..Self::unknown(reason)
        }
    }

    fn sampled(reason: String, manifest: Manifest) -> Self {
        Self {
            verdict: "sampled".to_owned(),
            reason,
            manifest: Some(manifest),
            ..Self::default()
        }
    }
}

/// One planned article.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
pub struct SampledSegment {
    pub file_key: String,
    pub totalparts: i64,
    pub totalparts_inferred: bool,
    pub number: i64,
    pub messageid: String,
    pub bytes: i64,
}

fn rank(identity: &str, value: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(identity);
    hasher.update([0]);
    hasher.update(value);
    hex::encode(hasher.finalize())
}

/// Selects a stable, bounded sample, spreading first across different files. A stable candidate
/// identity keeps retries from repeatedly sampling a new set.
pub fn sample(manifest: &Manifest, options: &SpotCheckOptions) -> Vec<SampledSegment> {
    let maximum_articles = options.articles.clamp(0, MAX_ARTICLES) as usize;
    let maximum_header_bytes = options.byte_limit();
    let maximum_article_bytes = options.article_byte_limit();
    if maximum_articles < 1 || maximum_header_bytes < 1 {
        return Vec::new();
    }
    let identity = manifest.identity.as_str();
    let mut files: Vec<(String, Vec<SampledSegment>, String)> = Vec::new();
    let mut by_key: HashMap<String, usize> = HashMap::new();
    for file in &manifest.files {
        let mut segments: Vec<(String, SampledSegment)> = file
            .segments
            .iter()
            .filter(|segment| {
                !segment.messageid.is_empty()
                    && segment.number >= 1
                    && segment.bytes >= 1
                    && segment.bytes <= maximum_header_bytes.min(maximum_article_bytes)
            })
            .map(|segment| {
                (
                    rank(identity, &segment.messageid),
                    SampledSegment {
                        file_key: file.key.clone(),
                        totalparts: file.totalparts.max(0),
                        totalparts_inferred: file.totalparts_inferred,
                        number: segment.number,
                        messageid: segment.messageid.clone(),
                        bytes: segment.bytes,
                    },
                )
            })
            .collect();
        if segments.is_empty() {
            continue;
        }
        // An inferred total can only be proved by its terminal article, so that goes first.
        segments.sort_by(|(left_rank, left), (right_rank, right)| {
            let terminal = |segment: &SampledSegment| segment.number == segment.totalparts;
            let inferred = if left.totalparts_inferred {
                terminal(right).cmp(&terminal(left))
            } else {
                std::cmp::Ordering::Equal
            };
            inferred.then_with(|| left_rank.cmp(right_rank))
        });
        let segments: Vec<SampledSegment> =
            segments.into_iter().map(|(_, segment)| segment).collect();
        let entry = (file.key.clone(), segments, rank(identity, &file.key));
        // A later file with the same key replaces the earlier one in place, as a PHP array does.
        match by_key.get(&file.key) {
            Some(index) => files[*index] = entry,
            None => {
                by_key.insert(file.key.clone(), files.len());
                files.push(entry);
            }
        }
    }
    files.sort_by(|left, right| {
        right.1[0]
            .totalparts_inferred
            .cmp(&left.1[0].totalparts_inferred)
            .then_with(|| left.2.cmp(&right.2))
    });
    let mut selected = Vec::new();
    let mut seen = HashSet::new();
    let mut bytes = 0;
    for round in 0.. {
        let mut added = false;
        for (_, segments, _) in &files {
            let Some(segment) = segments.get(round) else {
                continue;
            };
            if seen.contains(&segment.messageid) || bytes + segment.bytes > maximum_header_bytes {
                continue;
            }
            selected.push(segment.clone());
            seen.insert(segment.messageid.clone());
            bytes += segment.bytes;
            added = true;
            if selected.len() >= maximum_articles {
                return selected;
            }
        }
        if !added {
            break;
        }
    }
    selected
}

/// Rebuilds every publication field from the stored manifest and its sampled evidence, so a remote
/// worker's result is built exactly as a local one. Partial counts are recounted, never taken from
/// the sender.
pub fn with_evidence(manifest: &Manifest, evidence: &Evidence) -> Manifest {
    let mut sampled = manifest.clone();
    let declared_size = inventory::bytes(manifest);
    let file_count = manifest
        .files
        .iter()
        .filter(|file| !file.segments.is_empty())
        .count() as i64;
    let parity: Vec<String> = evidence
        .parity_file_keys
        .iter()
        .filter(|key| !key.is_empty())
        .cloned()
        .collect();
    sampled.verification_mode = Some("spot_check".to_owned());
    sampled.spot_check = Some(SpotCheck {
        articles: evidence.articles,
        bytes: evidence.bytes,
        sampled_files: evidence.sampled_files,
        method: "yenc-crc-and-identity".to_owned(),
        parity_file_keys: parity,
    });
    sampled.declared_size = Some(declared_size);
    sampled.publication_size = Some(declared_size);
    sampled.publication_file_count = Some(file_count);
    sampled.verified_file_count = Some(evidence.verified_file_names.len() as i64);
    sampled.verified_file_names = Some(evidence.verified_file_names.clone());
    sampled.identity_hints = Some(
        evidence
            .identity_hints
            .iter()
            .map(|name| IdentityHint {
                name: name.clone(),
                source: "yenc-spot-check".to_owned(),
            })
            .collect(),
    );
    if evidence.partial {
        sampled.partial = Some(inventory::completeness(manifest));
    }
    sampled
}

/// The sampled evidence a remote worker sends instead of the whole manifest.
pub fn evidence(manifest: &Manifest) -> Result<Evidence> {
    let Some(spot_check) = manifest
        .spot_check
        .as_ref()
        .filter(|_| manifest.verification_mode.as_deref() == Some("spot_check"))
    else {
        anyhow::bail!("Sampled verdict has no spot-check evidence.");
    };
    Ok(Evidence {
        articles: spot_check.articles,
        bytes: spot_check.bytes,
        sampled_files: spot_check.sampled_files,
        verified_file_names: manifest.verified_file_names.clone().unwrap_or_default(),
        identity_hints: manifest
            .identity_hints
            .iter()
            .flatten()
            .map(|hint| hint.name.clone())
            .collect(),
        partial: manifest.partial.is_some(),
        parity_file_keys: spot_check
            .parity_file_keys
            .iter()
            .filter(|key| !key.is_empty())
            .cloned()
            .collect(),
    })
}

/// Verifies a manifest. An upload whose missing files or articles stopped arriving may, with
/// `allow_partial`, be spot checked on the articles it has and published as partial.
pub async fn verify(
    source: &dyn ArticleSource,
    options: &SpotCheckOptions,
    manifest: &Manifest,
    allow_partial: bool,
) -> VerificationResult {
    match verify_sample(source, options, manifest, allow_partial).await {
        Ok(result) => result,
        // The bounded fetch stops before an oversized article is returned; its size is fixed.
        Err(error) if error.is::<ArticleBudgetExceeded>() => {
            VerificationResult::deterministic(error.to_string())
        }
        Err(error) => VerificationResult::unknown(error.to_string()),
    }
}

async fn verify_sample(
    source: &dyn ArticleSource,
    options: &SpotCheckOptions,
    manifest: &Manifest,
    allow_partial: bool,
) -> Result<VerificationResult> {
    let assessment = inventory::assess(manifest);
    let mut partial = None;
    if let Some(reason) = assessment.reason {
        if !allow_partial || assessment.status != HeaderStatus::WaitingUpload {
            return Ok(VerificationResult {
                waiting_for_headers: assessment.status == HeaderStatus::WaitingUpload,
                header_rejected: assessment.status == HeaderStatus::Rejected,
                ..VerificationResult::unknown(reason)
            });
        }
        partial = Some(inventory::completeness(manifest));
    }
    let byte_limit = options.byte_limit();
    let article_byte_limit = options.article_byte_limit();
    let planned = sample(manifest, options);
    if planned.is_empty() {
        return Ok(VerificationResult::deterministic(
            "No article fits the configured spot-check budget.",
        ));
    }
    let planned_keys: HashSet<&str> = planned
        .iter()
        .map(|segment| segment.file_key.as_str())
        .collect();
    let mut inferred: BTreeMap<&str, bool> = manifest
        .files
        .iter()
        .filter(|file| file.totalparts_inferred)
        .map(|file| (file.key.as_str(), false))
        .collect();
    if inferred.keys().any(|key| !planned_keys.contains(key)) {
        return Ok(VerificationResult::deterministic(
            "Inferred article totals cannot be proved within the spot-check budget.",
        ));
    }
    if options.stat_all_articles {
        let ids: Vec<String> = manifest
            .files
            .iter()
            .flat_map(|file| {
                file.segments
                    .iter()
                    .map(|segment| segment.messageid.clone())
            })
            .collect();
        for chunk in ids.chunks(16) {
            source.stat(chunk).await?;
        }
    }
    let mut bytes = 0i64;
    let mut names: Vec<String> = Vec::new();
    let mut identities: HashMap<String, String> = HashMap::new();
    let mut file_identities: HashMap<&str, (String, i64)> = HashMap::new();
    let mut ranges: BTreeMap<&str, BTreeMap<i64, (i64, i64)>> = BTreeMap::new();
    let mut identity_hints: Vec<String> = Vec::new();
    let mut parity_keys: Vec<String> = Vec::new();
    for segment in &planned {
        let remaining = article_byte_limit.min(byte_limit - bytes);
        if remaining < 1 {
            return Ok(VerificationResult::deterministic(
                "Spot-check transfer budget is exhausted.",
            ));
        }
        let raw = source.fetch_bounded(&segment.messageid, remaining).await?;
        // An empty response is a fetch problem, not a property of the article.
        if raw.is_empty() {
            return Ok(VerificationResult::unknown(
                "Spot-check article was empty; retry verification later.",
            ));
        }
        let raw_length = raw.len() as i64;
        if raw_length > article_byte_limit || bytes + raw_length > byte_limit {
            return Ok(VerificationResult::deterministic(
                "Spot-check article exceeded the configured transfer budget.",
            ));
        }
        let article = article::decode(&raw)?;
        let length = article.bytes.len() as i64;
        let is_par2_packet = article.offset == 0 && article.bytes.starts_with(b"PAR2\0PKT");
        let subject = manifest
            .files
            .iter()
            .find(|file| file.key == segment.file_key)
            .map(|file| file.subject.as_str());
        // OVER reports article octets, including encoding and headers, rather than decoded payload
        // length. It must at least cover the payload.
        if article.part != segment.number
            || article.size < 1
            || length > segment.bytes
            || article.offset + length > article.size
            || (article.part == 1 && article.offset != 0)
            || (article.part == segment.totalparts && article.offset + length != article.size)
            || !subject
                .is_some_and(|subject| identity::matches(subject, &article.name, is_par2_packet))
            || article
                .total_parts
                .is_some_and(|total| total != segment.totalparts)
        {
            return Ok(VerificationResult::deterministic(
                "Spot-check article identity differs from its header.",
            ));
        }
        let key = segment.file_key.as_str();
        if (is_par2_packet || identity::is_par2_filename(&article.name))
            && !parity_keys.iter().any(|known| known == key)
        {
            parity_keys.push(key.to_owned());
        }
        if segment.totalparts_inferred
            && (article.total_parts.is_some() || article.part == segment.totalparts)
        {
            inferred.insert(key, true);
        }
        let file_identity = (article.name.clone(), article.size);
        if file_identities
            .get(key)
            .is_some_and(|known| *known != file_identity)
        {
            return Ok(VerificationResult::deterministic(
                "Spot-check articles have conflicting file identities.",
            ));
        }
        file_identities.insert(key, file_identity);
        ranges
            .entry(key)
            .or_default()
            .insert(article.part, (article.offset, article.offset + length));
        let folded = article.name.nfc().collect::<String>().to_lowercase();
        if identities
            .get(&folded)
            .is_some_and(|known| *known != article.name)
        {
            return Ok(VerificationResult::deterministic(
                "Spot-check filenames collide on the storage filesystem.",
            ));
        }
        identities.insert(folded, article.name.clone());
        bytes += raw_length;
        if !names.contains(&article.name) {
            names.push(article.name.clone());
        }
        identity_hints.push(article.name);
    }
    if inferred.values().any(|proved| !proved) {
        return Ok(VerificationResult::deterministic(
            "Inferred article total has no body total or terminal endpoint proof.",
        ));
    }
    for file_ranges in ranges.values() {
        let mut previous: Option<(i64, i64)> = None;
        for (part, (begin, end)) in file_ranges {
            if let Some((previous_part, previous_end)) = previous
                && (*begin < previous_end || (*part == previous_part + 1 && *begin != previous_end))
            {
                return Ok(VerificationResult::deterministic(
                    "Spot-check articles expose a missing or overlapping byte range.",
                ));
            }
            previous = Some((*part, *end));
        }
    }
    if inventory::bytes(manifest) < 1 {
        return Ok(VerificationResult::deterministic(
            "Candidate has no positive declared size.",
        ));
    }
    let evidence = Evidence {
        articles: planned.len() as i64,
        bytes,
        sampled_files: planned_keys.len() as i64,
        verified_file_names: names,
        identity_hints,
        partial: partial.is_some(),
        parity_file_keys: parity_keys,
    };
    let sampled = with_evidence(manifest, &evidence);
    Ok(match partial {
        Some(partial) => VerificationResult::sampled(
            format!(
                "Sampled article checks passed on an incomplete upload: {} of {} articles found.",
                partial.articles_found, partial.articles_expected
            ),
            sampled,
        ),
        None => VerificationResult::sampled(
            "Sampled article checks passed; the full payload was not downloaded.".to_owned(),
            sampled,
        ),
    })
}
