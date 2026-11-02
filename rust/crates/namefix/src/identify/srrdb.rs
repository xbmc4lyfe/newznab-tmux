//! The srrDB hook: asks srrdb.com which scene release a file belongs to.
//!
//! Three kinds of evidence, strongest first:
//! - a file's CRC32, from an archive listing or the files of a NZB;
//! - a readable name;
//! - PreDB titles that fit the post's size, which only count once srrDB shows one of them holds
//!   a file with a CRC32 this release has. Size alone proves nothing.
//!
//! Requests are spaced out and answers, including empty ones, are cached on disk, so a re-run
//! costs srrDB nothing. Each query blocks its thread, so call this outside an async worker.

use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::{Duration, Instant};

use anyhow::{Context, Result, bail};
use reqwest::{StatusCode, Url};
use serde_json::Value;
use sha2::{Digest, Sha256};
use tokio::runtime::Runtime;

use super::ladder::{SrrdbLookup, SrrdbQuery};
use super::name::folded;

pub const DEFAULT_BASE_URL: &str = "https://api.srrdb.com/v1/";
const TIMEOUT: Duration = Duration::from_secs(30);
const MAX_RETRIES: u32 = 3;
const RETRY_DELAY: Duration = Duration::from_secs(30);
/// Bounds what one release can cost, because a pack can list hundreds of files.
const MAX_CRCS: usize = 3;
const MAX_NAMES: usize = 2;
const MAX_CANDIDATES: usize = 5;

#[derive(Clone, Debug)]
pub struct SrrdbConfig {
    pub base_url: String,
    pub cache_dir: PathBuf,
    /// The least time between two requests that reach the network.
    pub min_interval: Duration,
    /// Releases to ask about before answering nothing. `None` is no limit.
    pub budget: Option<usize>,
}

impl SrrdbConfig {
    pub fn new(cache_dir: impl Into<PathBuf>) -> Self {
        Self {
            base_url: DEFAULT_BASE_URL.to_owned(),
            cache_dir: cache_dir.into(),
            min_interval: Duration::from_secs(1),
            budget: None,
        }
    }
}

/// Hit counts per kind of evidence, for the hit-rate report.
#[derive(Debug, Default)]
pub struct SrrdbStats {
    pub releases: AtomicUsize,
    pub requests: AtomicUsize,
    pub cached: AtomicUsize,
    pub errors: AtomicUsize,
    pub crc_asked: AtomicUsize,
    pub crc_hits: AtomicUsize,
    pub crc_ambiguous: AtomicUsize,
    /// A post whose files named different releases, or only some of them one.
    pub crc_disagree: AtomicUsize,
    pub name_asked: AtomicUsize,
    pub name_hits: AtomicUsize,
    pub candidates_asked: AtomicUsize,
    pub candidates_hits: AtomicUsize,
    /// Releases with no CRC32 at all, so PreDB candidates couldn't be checked.
    pub candidates_uncheckable: AtomicUsize,
}

pub struct SrrdbClient {
    config: SrrdbConfig,
    http: reqwest::Client,
    runtime: Runtime,
    next_request: Mutex<Instant>,
    pub stats: SrrdbStats,
}

impl SrrdbClient {
    pub fn new(config: SrrdbConfig) -> Result<Self> {
        std::fs::create_dir_all(&config.cache_dir)
            .with_context(|| format!("creating {}", config.cache_dir.display()))?;
        let http = reqwest::Client::builder()
            .timeout(TIMEOUT)
            .user_agent(concat!("nntmux-identify/", env!("CARGO_PKG_VERSION")))
            .build()?;
        let runtime = tokio::runtime::Builder::new_multi_thread()
            .worker_threads(1)
            .enable_all()
            .build()?;
        Ok(Self {
            config,
            http,
            runtime,
            next_request: Mutex::new(Instant::now()),
            stats: SrrdbStats::default(),
        })
    }

    fn cache_path(&self, path: &[&str]) -> PathBuf {
        let digest = Sha256::digest(path.join("/").as_bytes());
        self.config
            .cache_dir
            .join(format!("{}.json", hex::encode(digest)))
    }

    fn wait_for_turn(&self) {
        let wait = {
            let mut next = self
                .next_request
                .lock()
                .expect("no panic while holding this");
            let now = Instant::now();
            let start = (*next).max(now);
            *next = start + self.config.min_interval;
            start - now
        };
        std::thread::sleep(wait);
    }

    /// The JSON at `path`, from the cache if it has been asked before.
    fn get(&self, path: &[&str]) -> Result<Value> {
        let cached = self.cache_path(path);
        if let Ok(text) = std::fs::read_to_string(&cached)
            && let Ok(value) = serde_json::from_str(&text)
        {
            self.stats.cached.fetch_add(1, Ordering::Relaxed);
            return Ok(value);
        }
        let mut url = Url::parse(&self.config.base_url).context("the srrDB base URL")?;
        url.path_segments_mut()
            .map_err(|()| anyhow::anyhow!("the srrDB base URL has no path"))?
            .pop_if_empty()
            .extend(path);
        let value = self.fetch(url)?;
        write_atomically(&cached, &value.to_string())?;
        Ok(value)
    }

    fn fetch(&self, url: Url) -> Result<Value> {
        for attempt in 0..=MAX_RETRIES {
            self.wait_for_turn();
            self.stats.requests.fetch_add(1, Ordering::Relaxed);
            let response = self.runtime.block_on(self.http.get(url.clone()).send())?;
            let status = response.status();
            if status == StatusCode::TOO_MANY_REQUESTS || status.is_server_error() {
                if attempt < MAX_RETRIES {
                    tracing::warn!(%status, attempt, "srrDB asks to back off");
                    std::thread::sleep(RETRY_DELAY * (attempt + 1));
                    continue;
                }
                bail!("srrDB answered {status}");
            }
            // An unknown release is a 404 from `details`, and that's an answer worth caching.
            if status == StatusCode::NOT_FOUND {
                return Ok(Value::Null);
            }
            if !status.is_success() {
                bail!("srrDB answered {status}");
            }
            return Ok(self.runtime.block_on(response.json())?);
        }
        bail!("srrDB retries ran out")
    }

    fn search(&self, term: &str) -> Result<Vec<String>> {
        let answer = self.get(&["search", term])?;
        Ok(answer
            .get("results")
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(|row| row.get("release")?.as_str().map(str::to_owned))
            .collect())
    }

    /// The CRC32s srrDB lists for a release, lowercase.
    fn crcs_of(&self, release: &str) -> Result<HashSet<String>> {
        let answer = self.get(&["details", release])?;
        Ok(["files", "archived-files"]
            .iter()
            .filter_map(|list| answer.get(*list)?.as_array())
            .flatten()
            .filter_map(|file| file.get("crc")?.as_str())
            .map(str::to_ascii_lowercase)
            .collect())
    }

    fn answer(&self, query: &SrrdbQuery) -> Result<Option<String>> {
        let stats = &self.stats;
        // Facts are `crc:size`, and srrDB only takes the CRC.
        let crcs: Vec<String> = query
            .crc32s
            .iter()
            .map(|fact| fact.split(':').next().unwrap_or(fact).to_ascii_lowercase())
            .collect();

        if !crcs.is_empty() {
            stats.crc_asked.fetch_add(1, Ordering::Relaxed);
        }
        // One file's release only names the post if the other files agree. A pack can hold
        // episodes released apart, and a miss may be an episode srrDB doesn't know.
        let mut agreed: Option<String> = None;
        let mut asked = 0;
        for crc in crcs.iter().take(MAX_CRCS) {
            asked += 1;
            let mut names = self.search(&format!("archive-crc:{crc}"))?;
            names.dedup_by_key(|name| folded(name));
            match names.as_slice() {
                [name]
                    if agreed
                        .as_ref()
                        .is_none_or(|known| folded(known) == folded(name)) =>
                {
                    agreed = Some(name.clone());
                    continue;
                }
                [] => {}
                [_] => {
                    stats.crc_disagree.fetch_add(1, Ordering::Relaxed);
                }
                // Several releases can share a file, such as a sample or a subtitle.
                _ => {
                    stats.crc_ambiguous.fetch_add(1, Ordering::Relaxed);
                }
            }
            agreed = None;
            break;
        }
        if asked > 0
            && let Some(name) = agreed
        {
            // The files not searched for still have to be in that release, which one more
            // request can check whatever their number.
            let unchecked = &crcs[asked..];
            if !unchecked.is_empty() {
                let theirs = self.crcs_of(&name)?;
                if !unchecked.iter().all(|crc| theirs.contains(crc)) {
                    stats.crc_disagree.fetch_add(1, Ordering::Relaxed);
                    return Ok(None);
                }
            }
            stats.crc_hits.fetch_add(1, Ordering::Relaxed);
            return Ok(Some(name));
        }

        if !query.file_names.is_empty() {
            stats.name_asked.fetch_add(1, Ordering::Relaxed);
        }
        for name in query.file_names.iter().take(MAX_NAMES) {
            let wanted = folded(name);
            let found = self.search(name)?;
            if let Some(release) = found.iter().find(|release| folded(release) == wanted) {
                stats.name_hits.fetch_add(1, Ordering::Relaxed);
                return Ok(Some(release.clone()));
            }
        }

        if !query.size_candidates.is_empty() {
            if crcs.is_empty() {
                stats.candidates_uncheckable.fetch_add(1, Ordering::Relaxed);
                return Ok(None);
            }
            stats.candidates_asked.fetch_add(1, Ordering::Relaxed);
            let mine: HashSet<&String> = crcs.iter().collect();
            for candidate in query.size_candidates.iter().take(MAX_CANDIDATES) {
                // Every file of this post has to be in the candidate, not just one of them.
                let theirs = self.crcs_of(candidate)?;
                if mine.iter().all(|crc| theirs.contains(*crc)) {
                    stats.candidates_hits.fetch_add(1, Ordering::Relaxed);
                    return Ok(Some(candidate.clone()));
                }
            }
        }
        Ok(None)
    }

    pub fn render_stats(&self) -> String {
        let stats = &self.stats;
        let read = |value: &AtomicUsize| value.load(Ordering::Relaxed);
        format!(
            "# srrDB run\n\nReleases asked about: {}. Requests: {}. From cache: {}. Errors: {}.\n\n\
             | Evidence | Releases asked | Hits |\n|---|---|---|\n\
             | CRC32 | {} | {} ({} matched several releases, {} files disagreed) |\n\
             | Readable name | {} | {} |\n\
             | PreDB size candidates checked by CRC32 | {} | {} |\n\n\
             Releases with size candidates but no CRC32 to check them against: {}.\n",
            read(&stats.releases),
            read(&stats.requests),
            read(&stats.cached),
            read(&stats.errors),
            read(&stats.crc_asked),
            read(&stats.crc_hits),
            read(&stats.crc_ambiguous),
            read(&stats.crc_disagree),
            read(&stats.name_asked),
            read(&stats.name_hits),
            read(&stats.candidates_asked),
            read(&stats.candidates_hits),
            read(&stats.candidates_uncheckable),
        )
    }
}

impl SrrdbLookup for SrrdbClient {
    fn lookup(&self, query: &SrrdbQuery) -> Result<Option<String>> {
        // Nothing to ask spends no share of the sample.
        if query.crc32s.is_empty() && query.file_names.is_empty() {
            if !query.size_candidates.is_empty() {
                self.stats
                    .candidates_uncheckable
                    .fetch_add(1, Ordering::Relaxed);
            }
            return Ok(None);
        }
        let turn = self.stats.releases.fetch_add(1, Ordering::Relaxed);
        if self.config.budget.is_some_and(|budget| turn >= budget) {
            self.stats.releases.fetch_sub(1, Ordering::Relaxed);
            return Ok(None);
        }
        let answer = self.answer(query);
        if answer.is_err() {
            self.stats.errors.fetch_add(1, Ordering::Relaxed);
        }
        answer
    }
}

fn write_atomically(path: &Path, text: &str) -> Result<()> {
    let partial = path.with_extension(format!("{}.tmp", std::process::id()));
    std::fs::write(&partial, text)?;
    std::fs::rename(&partial, path)?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use serde_json::json;
    use wiremock::matchers::{method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    use super::*;

    const RELEASE: &str = "Andor.S01E06.2160p.UHD.BluRay.x265-STORiES";

    struct Setup {
        server: MockServer,
        _dir: tempfile::TempDir,
        config: SrrdbConfig,
    }

    async fn setup() -> Setup {
        let server = MockServer::start().await;
        let dir = tempfile::tempdir().expect("tempdir");
        let config = SrrdbConfig {
            base_url: format!("{}/v1/", server.uri()),
            cache_dir: dir.path().to_owned(),
            min_interval: Duration::ZERO,
            budget: None,
        };
        Setup {
            server,
            _dir: dir,
            config,
        }
    }

    async fn serve(server: &MockServer, at: &str, body: Value) {
        Mock::given(method("GET"))
            .and(path(at))
            .respond_with(ResponseTemplate::new(200).set_body_json(body))
            .mount(server)
            .await;
    }

    fn results(names: &[&str]) -> Value {
        let rows: Vec<Value> = names.iter().map(|name| json!({"release": name})).collect();
        json!({"results": rows, "resultsCount": names.len().to_string()})
    }

    /// The client blocks, so it can't run on the test's own async thread.
    async fn ask(config: SrrdbConfig, query: SrrdbQuery) -> (Result<Option<String>>, SrrdbClient) {
        tokio::task::spawn_blocking(move || {
            let client = SrrdbClient::new(config).expect("client");
            let answer = client.lookup(&query);
            (answer, client)
        })
        .await
        .expect("join")
    }

    /// The runtime inside the client can't be dropped from an async thread.
    async fn finish(client: SrrdbClient) {
        tokio::task::spawn_blocking(move || drop(client))
            .await
            .expect("join");
    }

    #[tokio::test]
    async fn one_release_with_the_crc_answers() {
        let setup = setup().await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:5615b8b1",
            results(&[RELEASE]),
        )
        .await;
        let query = SrrdbQuery {
            crc32s: vec!["5615B8B1:8539116558".to_owned()],
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query).await;
        assert_eq!(answer.expect("answer").as_deref(), Some(RELEASE));
        assert_eq!(client.stats.crc_hits.load(Ordering::Relaxed), 1);
        finish(client).await;
    }

    #[tokio::test]
    async fn files_of_a_post_must_agree_on_the_release() {
        let setup = setup().await;
        let other = "Andor.S01E07.2160p.UHD.BluRay.x265-STORiES";
        serve(
            &setup.server,
            "/v1/search/archive-crc:00000011",
            results(&[RELEASE]),
        )
        .await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:00000012",
            results(&[RELEASE]),
        )
        .await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:00000013",
            results(&[other]),
        )
        .await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:00000014",
            results(&[]),
        )
        .await;
        let ask_about = |crcs: &[&str]| SrrdbQuery {
            crc32s: crcs.iter().map(|crc| format!("{crc}:1")).collect(),
            ..SrrdbQuery::default()
        };

        let (answer, client) =
            ask(setup.config.clone(), ask_about(&["00000011", "00000012"])).await;
        assert_eq!(answer.expect("answer").as_deref(), Some(RELEASE));
        finish(client).await;

        // A pack of episodes released apart.
        let (answer, client) =
            ask(setup.config.clone(), ask_about(&["00000011", "00000013"])).await;
        assert_eq!(answer.expect("answer"), None);
        assert_eq!(client.stats.crc_disagree.load(Ordering::Relaxed), 1);
        finish(client).await;

        // A pack with an episode srrDB doesn't know.
        let (answer, client) =
            ask(setup.config.clone(), ask_about(&["00000011", "00000014"])).await;
        assert_eq!(answer.expect("answer"), None);
        finish(client).await;
    }

    #[tokio::test]
    async fn files_past_the_request_limit_are_checked_against_the_release() {
        let setup = setup().await;
        for crc in ["00000031", "00000032", "00000033"] {
            serve(
                &setup.server,
                &format!("/v1/search/archive-crc:{crc}"),
                results(&[RELEASE]),
            )
            .await;
        }
        serve(
            &setup.server,
            &format!("/v1/details/{RELEASE}"),
            json!({"archived-files": [
                {"crc": "00000031"}, {"crc": "00000032"}, {"crc": "00000033"}, {"crc": "00000034"}
            ]}),
        )
        .await;
        let query = |last: &str| SrrdbQuery {
            crc32s: ["00000031", "00000032", "00000033", last]
                .iter()
                .map(|crc| format!("{crc}:1"))
                .collect(),
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query("00000034")).await;
        assert_eq!(answer.expect("answer").as_deref(), Some(RELEASE));
        finish(client).await;

        // A fourth file from another release sinks it.
        let (answer, client) = ask(setup.config.clone(), query("00000035")).await;
        assert_eq!(answer.expect("answer"), None);
        assert_eq!(client.stats.crc_disagree.load(Ordering::Relaxed), 1);
        finish(client).await;
    }

    #[tokio::test]
    async fn an_empty_query_spends_no_budget() {
        let setup = setup().await;
        let mut config = setup.config.clone();
        config.budget = Some(1);
        let (answer, client) = ask(config, SrrdbQuery::default()).await;
        assert_eq!(answer.expect("answer"), None);
        assert_eq!(client.stats.releases.load(Ordering::Relaxed), 0);
        finish(client).await;
    }

    #[tokio::test]
    async fn a_size_candidate_must_hold_every_file_of_the_post() {
        let setup = setup().await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:00000021",
            results(&[]),
        )
        .await;
        serve(
            &setup.server,
            &format!("/v1/details/{RELEASE}"),
            json!({"archived-files": [{"name": "a.mkv", "crc": "00000021"}]}),
        )
        .await;
        let query = SrrdbQuery {
            crc32s: vec!["00000021:1".to_owned(), "00000022:1".to_owned()],
            size_candidates: vec![RELEASE.to_owned()],
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query).await;
        assert_eq!(answer.expect("answer"), None);
        finish(client).await;
    }

    #[tokio::test]
    async fn several_releases_with_the_crc_answer_nothing() {
        let setup = setup().await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:aabbccdd",
            results(&[RELEASE, "Other.Show.S01E01.1080p.WEB.h264-GRP"]),
        )
        .await;
        let query = SrrdbQuery {
            crc32s: vec!["aabbccdd:1".to_owned()],
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query).await;
        assert_eq!(answer.expect("answer"), None);
        assert_eq!(client.stats.crc_ambiguous.load(Ordering::Relaxed), 1);
        finish(client).await;
    }

    #[tokio::test]
    async fn a_name_must_match_in_full() {
        let setup = setup().await;
        serve(
            &setup.server,
            &format!("/v1/search/{RELEASE}"),
            results(&[&format!("{RELEASE}.PROPER"), RELEASE]),
        )
        .await;
        let query = SrrdbQuery {
            file_names: vec![RELEASE.to_owned()],
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query).await;
        assert_eq!(answer.expect("answer").as_deref(), Some(RELEASE));
        finish(client).await;
    }

    #[tokio::test]
    async fn a_size_candidate_counts_only_with_a_matching_crc() {
        let setup = setup().await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:12345678",
            results(&[]),
        )
        .await;
        serve(
            &setup.server,
            "/v1/details/Wrong.Film.2021.1080p.WEB.h264-GRP",
            json!({"archived-files": [{"name": "a.mkv", "crc": "FFFFFFFF"}]}),
        )
        .await;
        serve(
            &setup.server,
            &format!("/v1/details/{RELEASE}"),
            json!({"archived-files": [{"name": "a.mkv", "crc": "12345678"}]}),
        )
        .await;
        let candidates = vec![
            "Wrong.Film.2021.1080p.WEB.h264-GRP".to_owned(),
            RELEASE.to_owned(),
        ];
        let query = SrrdbQuery {
            crc32s: vec!["12345678:1".to_owned()],
            size_candidates: candidates.clone(),
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query).await;
        assert_eq!(answer.expect("answer").as_deref(), Some(RELEASE));
        assert_eq!(client.stats.candidates_hits.load(Ordering::Relaxed), 1);
        finish(client).await;

        // With no CRC32 to compare, a candidate stays a candidate, and nothing is even asked.
        let query = SrrdbQuery {
            size_candidates: candidates,
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query).await;
        assert_eq!(answer.expect("answer"), None);
        assert_eq!(
            client.stats.candidates_uncheckable.load(Ordering::Relaxed),
            1
        );
        assert_eq!(client.stats.requests.load(Ordering::Relaxed), 0);
        finish(client).await;
    }

    #[tokio::test]
    async fn an_answer_is_only_fetched_once_even_an_empty_one() {
        let setup = setup().await;
        Mock::given(method("GET"))
            .and(path("/v1/search/archive-crc:00000001"))
            .respond_with(ResponseTemplate::new(200).set_body_json(results(&[])))
            .expect(1)
            .mount(&setup.server)
            .await;
        let query = SrrdbQuery {
            crc32s: vec!["00000001:1".to_owned()],
            ..SrrdbQuery::default()
        };
        for expected_cached in [0, 1] {
            // A new client, so the second round can only be answered from disk.
            let (answer, client) = ask(setup.config.clone(), query.clone()).await;
            assert_eq!(answer.expect("answer"), None);
            assert_eq!(client.stats.cached.load(Ordering::Relaxed), expected_cached);
            finish(client).await;
        }
    }

    #[tokio::test]
    async fn an_error_is_not_cached() {
        let setup = setup().await;
        Mock::given(method("GET"))
            .and(path("/v1/search/archive-crc:00000002"))
            .respond_with(ResponseTemplate::new(403))
            .mount(&setup.server)
            .await;
        let query = SrrdbQuery {
            crc32s: vec!["00000002:1".to_owned()],
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(setup.config.clone(), query).await;
        assert!(answer.is_err());
        assert_eq!(client.stats.errors.load(Ordering::Relaxed), 1);
        finish(client).await;
        let files = std::fs::read_dir(&setup.config.cache_dir)
            .expect("dir")
            .count();
        assert_eq!(files, 0);
    }

    #[tokio::test]
    async fn the_budget_stops_further_releases() {
        let setup = setup().await;
        let mut config = setup.config.clone();
        config.budget = Some(0);
        let query = SrrdbQuery {
            crc32s: vec!["00000003:1".to_owned()],
            ..SrrdbQuery::default()
        };
        let (answer, client) = ask(config, query).await;
        assert_eq!(answer.expect("answer"), None);
        assert_eq!(client.stats.requests.load(Ordering::Relaxed), 0);
        finish(client).await;
    }

    #[tokio::test]
    async fn requests_are_spaced_out() {
        let setup = setup().await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:00000004",
            results(&[RELEASE]),
        )
        .await;
        serve(
            &setup.server,
            "/v1/search/archive-crc:00000005",
            results(&[RELEASE]),
        )
        .await;
        let mut config = setup.config.clone();
        config.min_interval = Duration::from_millis(200);
        let query = SrrdbQuery {
            crc32s: vec!["00000004:1".to_owned(), "00000005:1".to_owned()],
            ..SrrdbQuery::default()
        };
        let started = Instant::now();
        let (answer, client) = ask(config, query).await;
        assert_eq!(answer.expect("answer").as_deref(), Some(RELEASE));
        assert!(started.elapsed() >= Duration::from_millis(200));
        finish(client).await;
    }
}
