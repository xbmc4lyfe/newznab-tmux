//! `Par2CandidateRecovery`: reconstructs one ordered candidate article by article, without
//! downloading articles a checkpoint already holds.
//!
//! Every step asks the caller's claim first, and anything that cannot be proved is retryable
//! or deferred, never a "no match": only a candidate that is complete, or provably cannot
//! describe the release, ends as `NoMatch`.

use std::sync::Arc;

use anyhow::{Result, bail};
use async_trait::async_trait;
use base64::Engine as _;
use base64::engine::general_purpose::STANDARD;
use nntmux_yenc::YencPart;

use crate::par2_accumulator::{AccumulatorError, PacketAccumulator};
use crate::par2_apply::{Context, Expected, apply_inventory};
use crate::par2_budget::exceeds_payload_budget;
use crate::par2_candidates::Candidate;
use crate::par2_checkpoint::{self as checkpoints, ArticleIdentity, Checkpoint, Save};
use crate::par2_info::MARKER;
use crate::par2_support::{Claim, Guard};
use crate::release::Snapshot;
use crate::service::{NameFixer, Par2Outcome};
use crate::update::ReleaseSearch;

/// A bounded article fetch. `body` is `None` for anything short of one complete article.
#[derive(Clone, Debug, Default)]
pub struct Fetched {
    pub body: Option<Vec<u8>>,
    pub part: Option<YencPart>,
    /// The article exceeded the byte ceiling, which retrying cannot change.
    pub oversized: bool,
}

/// Where articles come from. `on_read` is asked while the article streams in, and a false
/// answer stops the read.
#[async_trait]
pub trait ArticleSource: Send {
    /// Opens the session if it isn't open. An error is the reason, for the log; every fetch
    /// then fails retryably.
    async fn connect(&mut self) -> Result<(), String> {
        Ok(())
    }

    async fn get_message_bounded(
        &mut self,
        group: &str,
        message_id: &str,
        max_decoded_bytes: usize,
        alternate: bool,
        on_read: &mut (dyn FnMut() -> bool + Send),
    ) -> Fetched;
}

/// What a recovery did and what it cost.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Recovered {
    pub outcome: Par2Outcome,
    pub probes: i64,
    pub articles: i64,
}

/// What one recovery runs against.
pub struct Recovery<'a> {
    pub fixer: &'a mut NameFixer,
    pub search: &'a Arc<dyn ReleaseSearch>,
    pub add_par2: bool,
    pub articles: &'a mut dyn ArticleSource,
    pub claim: &'a Arc<dyn Claim>,
    pub alternate: bool,
}

/// The limits and identity of one candidate's recovery.
pub struct Request<'a> {
    pub candidate: &'a Candidate,
    pub snapshot: &'a Expected,
    pub manifest_signature: &'a str,
    pub group_id: i64,
    pub article_budget: i64,
    pub max_article_bytes: i64,
    pub max_state_bytes: i64,
}

#[derive(Default)]
struct Counters {
    probes: i64,
    articles: i64,
}

/// `Par2CandidateRecovery::recover`.
pub async fn recover(cx: &mut Recovery<'_>, request: &Request<'_>) -> Result<Recovered> {
    let mut counters = Counters::default();
    // Argument errors are thrown before the recovery's own handler, so they reach the caller.
    if request.candidate.segments.is_empty()
        || request.article_budget < 0
        || request.max_article_bytes < 1
        || request.max_state_bytes < 1
    {
        bail!("Invalid PAR2 recovery limits or empty candidate");
    }
    if !cx.claim.holds().await {
        return Ok(Recovered {
            outcome: Par2Outcome::Deferred,
            probes: 0,
            articles: 0,
        });
    }
    let outcome = match run(cx, request, &mut counters).await {
        Ok(outcome) => outcome,
        Err(error) => {
            tracing::debug!(%error, "PAR2 candidate recovery failed");
            if cx.claim.holds().await {
                Par2Outcome::Retryable
            } else {
                Par2Outcome::Deferred
            }
        }
    };
    Ok(Recovered {
        outcome,
        probes: counters.probes,
        articles: counters.articles,
    })
}

/// What a recovery keeps between articles.
struct State {
    cursor: i64,
    decoded_offset: i64,
    identity: Option<ArticleIdentity>,
    inventory: PacketAccumulator,
    checkpoint: Option<Checkpoint>,
}

fn blocked(checkpoint: &Option<Checkpoint>) -> bool {
    checkpoint.as_ref().is_some_and(Checkpoint::is_blocked)
}

async fn snapshot_current(
    db: &sqlx::MySqlPool,
    snapshot: &Snapshot,
    group_id: i64,
) -> Result<bool> {
    let mut conn = db.acquire().await?;
    let release = crate::par2_support::LockedRelease::read(&mut conn, snapshot.id).await?;
    Ok(release.is_some_and(|release| {
        release.guid == snapshot.guid
            && release.name == snapshot.name
            && release.searchname == snapshot.searchname
            && release.groups_id == group_id
            && release.isrenamed == 0
            && release.predb_id == 0
            && release.proc_par2 != 1
    }))
}

async fn run(
    cx: &mut Recovery<'_>,
    request: &Request<'_>,
    counters: &mut Counters,
) -> Result<Par2Outcome> {
    let db = cx.fixer.updater.db.clone();
    let candidate = request.candidate;
    let snapshot = &request.snapshot.snapshot;
    let segment_count = candidate.segments.len() as i64;
    let signature = candidate.signature();
    let claim = cx.claim.clone();
    let guard: &dyn Guard = claim.as_ref();

    let load = || {
        checkpoints::load(
            &db,
            snapshot.id,
            request.manifest_signature,
            &signature,
            segment_count,
            request.max_state_bytes,
        )
    };
    let checkpoint = match load().await {
        Ok(checkpoint) => checkpoint,
        Err(_) => {
            if !guard.holds().await {
                return Ok(Par2Outcome::Deferred);
            }
            checkpoints::block_corrupt_candidate(
                &db,
                snapshot,
                request.manifest_signature,
                &signature,
                segment_count,
                request.max_state_bytes,
                guard,
            )
            .await?;
            return Ok(if guard.holds().await {
                Par2Outcome::Retryable
            } else {
                Par2Outcome::Deferred
            });
        }
    };
    if !guard.holds().await {
        return Ok(Par2Outcome::Deferred);
    }
    if blocked(&checkpoint) {
        return Ok(Par2Outcome::Retryable);
    }
    let max_state = request.max_state_bytes as usize;
    let mut state = match checkpoint {
        Some(checkpoint) => {
            let inventory = checkpoint
                .accumulator
                .clone()
                .map(|inventory| inventory.with_limit(max_state))
                .transpose()?
                .unwrap_or(PacketAccumulator::new(max_state)?);
            State {
                cursor: checkpoint.next_segment,
                decoded_offset: checkpoint.decoded_offset,
                identity: checkpoint.identity.clone(),
                inventory,
                checkpoint: Some(checkpoint),
            }
        }
        None => match PacketAccumulator::new(max_state) {
            Ok(inventory) => State {
                cursor: 0,
                decoded_offset: 0,
                identity: None,
                inventory,
                checkpoint: None,
            },
            Err(AccumulatorError::Length(_)) => {
                checkpoints::save(
                    &db,
                    &Save {
                        snapshot,
                        manifest_signature: request.manifest_signature,
                        candidate_signature: &signature,
                        next_segment: 0,
                        retry_segments: &[],
                        accumulator: None,
                        segment_count,
                        max_state_bytes: request.max_state_bytes,
                        blocked_reason: Some("state_limit"),
                        decoded_offset: 0,
                        identity: None,
                    },
                    guard,
                )
                .await?;
                return Ok(if guard.holds().await {
                    Par2Outcome::Retryable
                } else {
                    Par2Outcome::Deferred
                });
            }
            Err(error) => return Err(error.into()),
        },
    };

    let group = group_name(&db, request.group_id).await?;
    if group.is_empty() || !guard.holds().await {
        return Ok(if guard.holds().await {
            Par2Outcome::Retryable
        } else {
            Par2Outcome::Deferred
        });
    }
    if state.checkpoint.is_some() {
        let outcome = apply(cx, request, &state).await?;
        if outcome != Par2Outcome::Retryable {
            return Ok(if outcome == Par2Outcome::Deferred {
                outcome
            } else {
                finish(cx, request, &state, outcome).await?
            });
        }
    }

    while state.cursor < segment_count {
        if !guard.holds().await {
            return Ok(Par2Outcome::Deferred);
        }
        if counters.probes >= request.article_budget {
            let saved = save(request, &db, &signature, &mut state, guard, None, false).await?;
            return Ok(if !saved {
                Par2Outcome::Deferred
            } else if blocked(&state.checkpoint) {
                Par2Outcome::Retryable
            } else {
                Par2Outcome::Deferred
            });
        }
        let segment = &candidate.segments[state.cursor as usize];
        if segment.bytes < 1
            || segment.bytes > request.max_article_bytes
            || segment.number < 1
            || segment.id.is_empty()
        {
            let saved = save(
                request,
                &db,
                &signature,
                &mut state,
                guard,
                Some("article_limit"),
                false,
            )
            .await?;
            return Ok(retry_or_defer(saved));
        }
        if !guard.holds().await {
            return Ok(Par2Outcome::Deferred);
        }
        counters.probes += 1;
        let fetched = {
            let claim = cx.claim.clone();
            let mut on_read = {
                let claim = claim.clone();
                move || claim.read_ok()
            };
            let read = cx.articles.get_message_bounded(
                &group,
                &segment.id,
                request.max_article_bytes as usize,
                cx.alternate,
                &mut on_read,
            );
            tokio::select! {
                fetched = read => fetched,
                () = claim.keep_alive() => Fetched::default(),
            }
        };
        if fetched.body.is_some() {
            counters.articles += 1;
        }
        if !cx.claim.received().await || !guard.holds().await {
            return Ok(Par2Outcome::Deferred);
        }
        let Some(article) = fetched.body else {
            let reason = fetched.oversized.then_some("article_limit");
            let saved = save(request, &db, &signature, &mut state, guard, reason, false).await?;
            return Ok(retry_or_defer(saved));
        };
        if article.len() as i64 > request.max_article_bytes {
            let saved = save(
                request,
                &db,
                &signature,
                &mut state,
                guard,
                Some("article_limit"),
                false,
            )
            .await?;
            return Ok(retry_or_defer(saved));
        }
        let appended = (|| -> Result<Option<Option<ArticleIdentity>>, Rejected> {
            let next_identity = validate_part(
                fetched.part.as_ref(),
                &article,
                segment.number,
                segment_count,
                state.decoded_offset,
                state.identity.as_ref(),
            )
            .map_err(|_| Rejected::Malformed)?;
            let mut body: &[u8] = &article;
            if state.cursor == 0 && state.decoded_offset == 0 {
                let marker = find(body, MARKER);
                let partial = body.len() < 8 && !body.is_empty() && MARKER.starts_with(body);
                if marker.is_none() && !partial && !candidate.explicit {
                    return Ok(None);
                }
                if let Some(at) = marker.filter(|at| *at > 0) {
                    body = &body[at..];
                }
            }
            if body.is_empty() || article.len() as i64 > i64::MAX - state.decoded_offset {
                return Err(Rejected::Malformed);
            }
            state.inventory.append(body).map_err(|error| match error {
                AccumulatorError::Length(_) => Rejected::StateLimit,
                AccumulatorError::Invalid(_) => Rejected::Malformed,
            })?;
            Ok(Some(next_identity))
        })();
        let next_identity = match appended {
            Ok(Some(identity)) => identity,
            Ok(None) => {
                let outcome = finish(cx, request, &state, Par2Outcome::NoMatch).await?;
                return Ok(outcome);
            }
            Err(Rejected::StateLimit) => {
                let saved = save(
                    request,
                    &db,
                    &signature,
                    &mut state,
                    guard,
                    Some("state_limit"),
                    true,
                )
                .await?;
                return Ok(retry_or_defer(saved));
            }
            Err(Rejected::Malformed) => {
                let saved = save(
                    request,
                    &db,
                    &signature,
                    &mut state,
                    guard,
                    Some("malformed_article"),
                    false,
                )
                .await?;
                return Ok(retry_or_defer(saved));
            }
        };
        state.decoded_offset += article.len() as i64;
        state.identity = next_identity;
        state.cursor += 1;
        if !save(request, &db, &signature, &mut state, guard, None, false).await? {
            return Ok(Par2Outcome::Deferred);
        }
        if blocked(&state.checkpoint) {
            return Ok(Par2Outcome::Retryable);
        }
        let outcome = apply(cx, request, &state).await?;
        if outcome != Par2Outcome::Retryable {
            return Ok(if outcome == Par2Outcome::Deferred {
                outcome
            } else {
                finish(cx, request, &state, outcome).await?
            });
        }
    }
    let saved = save(
        request,
        &db,
        &signature,
        &mut state,
        guard,
        Some("incomplete_stream"),
        false,
    )
    .await?;
    Ok(retry_or_defer(saved))
}

fn retry_or_defer(saved: bool) -> Par2Outcome {
    if saved {
        Par2Outcome::Retryable
    } else {
        Par2Outcome::Deferred
    }
}

enum Rejected {
    StateLimit,
    Malformed,
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}

async fn group_name(db: &sqlx::MySqlPool, id: i64) -> Result<String> {
    let name: Option<String> = sqlx::query_scalar("SELECT name FROM usenet_groups WHERE id = ?")
        .bind(id)
        .fetch_optional(db)
        .await?;
    Ok(name.unwrap_or_default())
}

/// The `$save` closure: writes the cursor and inventory (or a tombstone), then reloads what
/// was stored so the next decision rests on the durable state.
async fn save(
    request: &Request<'_>,
    db: &sqlx::MySqlPool,
    signature: &str,
    state: &mut State,
    guard: &dyn Guard,
    block: Option<&str>,
    tombstone: bool,
) -> Result<bool> {
    let snapshot = &request.snapshot.snapshot;
    let segment_count = request.candidate.segments.len() as i64;
    if !guard.holds().await {
        return Ok(false);
    }
    let saved = checkpoints::save(
        db,
        &Save {
            snapshot,
            manifest_signature: request.manifest_signature,
            candidate_signature: signature,
            next_segment: state.cursor,
            retry_segments: &[],
            accumulator: (!tombstone).then_some(&state.inventory),
            segment_count,
            max_state_bytes: request.max_state_bytes,
            blocked_reason: block,
            decoded_offset: if tombstone { 0 } else { state.decoded_offset },
            identity: if tombstone {
                None
            } else {
                state.identity.as_ref()
            },
        },
        guard,
    )
    .await?;
    if !saved || !guard.holds().await {
        return Ok(false);
    }
    state.checkpoint = checkpoints::load(
        db,
        snapshot.id,
        request.manifest_signature,
        signature,
        segment_count,
        request.max_state_bytes,
    )
    .await?;
    Ok(guard.holds().await && state.checkpoint.is_some())
}

/// The `$finish` closure.
async fn finish(
    cx: &mut Recovery<'_>,
    request: &Request<'_>,
    state: &State,
    outcome: Par2Outcome,
) -> Result<Par2Outcome> {
    let db = cx.fixer.updater.db.clone();
    let snapshot = &request.snapshot.snapshot;
    let signature = request.candidate.signature();
    let guard: &dyn Guard = cx.claim.as_ref();
    if outcome == Par2Outcome::Renamed {
        if guard.holds().await && state.checkpoint.is_some() {
            checkpoints::delete_candidate(
                &db,
                snapshot,
                request.manifest_signature,
                &signature,
                guard,
            )
            .await?;
        }
        return Ok(outcome);
    }
    if !guard.holds().await
        || !snapshot_current(&db, snapshot, request.group_id).await?
        || (state.checkpoint.is_some()
            && !checkpoints::delete_candidate(
                &db,
                snapshot,
                request.manifest_signature,
                &signature,
                guard,
            )
            .await?)
        || !guard.holds().await
    {
        return Ok(Par2Outcome::Deferred);
    }
    Ok(outcome)
}

/// The `$apply` closure: judges the inventory so far.
async fn apply(cx: &mut Recovery<'_>, request: &Request<'_>, state: &State) -> Result<Par2Outcome> {
    if !cx.claim.holds().await {
        return Ok(Par2Outcome::Deferred);
    }
    let max_payload = request.snapshot.max_payload_bytes;
    let segment_count = request.candidate.segments.len() as i64;
    let file_complete = state
        .identity
        .as_ref()
        .is_none_or(|identity| state.decoded_offset == identity.size);
    let terminal = (state.cursor == segment_count
        && file_complete
        && state.inventory.is_complete()?)
        || (state.inventory.has_complete_main_inventory()? && state.inventory.skip_remaining() > 0)
        || (state.cursor > 0 && max_payload.is_some_and(|max| max <= 0))
        || exceeds_payload_budget(&state.inventory, max_payload)?;
    let guard: Arc<dyn Guard> = cx.claim.clone();
    let applied = apply_inventory(
        Context {
            fixer: cx.fixer,
            search: cx.search,
            add_par2: cx.add_par2,
            guard: &guard,
        },
        &state.inventory,
        request.snapshot.snapshot.id,
        request.group_id,
        terminal,
        Some(request.snapshot),
    )
    .await?;
    let outcome = applied.outcome;
    if outcome == Par2Outcome::Renamed {
        return Ok(outcome);
    }
    if !cx.claim.holds().await {
        return Ok(Par2Outcome::Deferred);
    }
    Ok(if outcome == Par2Outcome::NoMatch && !terminal {
        Par2Outcome::Retryable
    } else {
        outcome
    })
}

/// `validatePart`: the article continues the file the stream started with, at the offset the
/// stream has reached. Anything else means the candidate cannot be trusted.
pub fn validate_part(
    part: Option<&YencPart>,
    body: &[u8],
    segment_number: i64,
    segment_count: i64,
    offset: i64,
    identity: Option<&ArticleIdentity>,
) -> Result<Option<ArticleIdentity>, &'static str> {
    let Some(part) = part else {
        if identity.is_some() {
            return Err("yEnc framing changed between articles");
        }
        return Ok(None);
    };
    if part.name.is_empty()
        || part.name.len() > 65_536
        || part.name.iter().any(|b| matches!(b, 0 | b'\r' | b'\n'))
        || part.size < 1
        || (offset > 0 && identity.is_none())
        || part.part.is_some_and(|number| number != segment_number)
    {
        return Err("Invalid yEnc filename, size or part identity");
    }
    let name_base64 = STANDARD.encode(&part.name);
    if let Some(identity) = identity
        && (name_base64 != identity.name_base64
            || part.size != identity.size
            || identity
                .part
                .is_some_and(|known| part.part != Some(known + 1)))
    {
        return Err("yEnc file identity changed between articles");
    }
    match (part.begin, part.end) {
        (Some(begin), Some(end)) => {
            if offset == i64::MAX
                || begin != offset + 1
                || end < begin
                || end > part.size
                || end - begin + 1 != body.len() as i64
            {
                return Err("Discontinuous yEnc article offsets");
            }
        }
        (begin, end) => {
            if segment_count != 1
                || offset != 0
                || begin.is_some()
                || end.is_some()
                || body.len() as i64 != part.size
            {
                return Err("Missing yEnc multipart offsets");
            }
        }
    }
    Ok(Some(ArticleIdentity {
        name_base64,
        size: part.size,
        part: part.part,
    }))
}
