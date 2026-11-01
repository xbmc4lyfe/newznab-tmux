//! Fetching older articles: `BackfillService`.

use anyhow::{Result, bail};
use tracing::{info, warn};

use crate::config::capacity_available;
use crate::groups::{self, Group};
use crate::nntp::SelectedGroup;
use crate::scan::{Ingest, ScanKind, ScanOutcome};

/// How far back to go.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Target {
    /// This many articles before the group's first record.
    Articles(u64),
    /// The group's `backfill_target` days back.
    Date,
}

/// Which groups `backfill_all` covers when no group is named.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Order {
    Name,
    /// Newest first record first.
    Date,
}

/// The article to backfill to, never below the server's oldest.
pub fn clamp_target(wanted: i64, server_first: u64) -> u64 {
    u64::try_from(wanted).unwrap_or(0).max(server_first)
}

impl Ingest<'_> {
    /// Backfills one named group, or every enabled group in order.
    pub async fn backfill_all(
        &mut self,
        group_name: Option<&str>,
        target: Target,
        order: Order,
    ) -> Result<()> {
        let list = match group_name {
            Some(name) => groups::by_name(self.pool, name)
                .await?
                .into_iter()
                .collect(),
            None => groups::backfill_enabled(self.pool, order == Order::Date).await?,
        };
        if list.is_empty() {
            warn!("no groups to backfill; add them to the database first");
            return Ok(());
        }
        for group in &list {
            if self.stopping().await? {
                break;
            }
            self.backfill_group(group, target).await?;
        }
        Ok(())
    }

    pub async fn backfill_group(&mut self, group: &Group, target: Target) -> Result<()> {
        if self.stopping().await? || !capacity_available(self.pool, self.config).await? {
            return Ok(());
        }
        self.mark_binary_run().await?;
        if group.first_record == 0 {
            warn!(
                group = group.name,
                "update this group's binaries first, or disable it if it's dead"
            );
            return Ok(());
        }
        let Some(server) = self.select_group(&group.name).await else {
            return Ok(());
        };
        let wanted = match target {
            Target::Articles(count) => i64::try_from(group.first_record)?
                .saturating_sub(i64::try_from(count).unwrap_or(i64::MAX)),
            Target::Date => i64::try_from(
                self.day_to_post(i64::from(group.backfill_target), &server)
                    .await?,
            )?,
        };
        let target_post = clamp_target(wanted, server.first);
        if target_post >= group.first_record || group.first_record <= server.first {
            if self.config.disable_backfill_group {
                groups::disable_backfill(self.pool, group.id).await?;
            }
            info!(
                group = group.name,
                "reached the most this group can backfill"
            );
            return Ok(());
        }
        self.backfill_chunks(group, target_post, &server).await
    }

    async fn backfill_chunks(
        &mut self,
        group: &Group,
        target: u64,
        server: &SelectedGroup,
    ) -> Result<()> {
        let kind = if self.config.safe_part_repair {
            ScanKind::Update
        } else {
            ScanKind::Backfill
        };
        let buffer = self.config.message_buffer;
        let mut last = group.first_record - 1;
        let mut first = (last + 1).saturating_sub(buffer).max(target);
        loop {
            if self.stopping().await? || !capacity_available(self.pool, self.config).await? {
                return Ok(());
            }
            let outcome = self.scan(group, first, last, kind, None).await?;
            if outcome.summary.is_none() && outcome.completed_end.is_none() {
                bail!("Backfill overview failed; the range must be retried.");
            }
            self.record_backfill(group, first, &outcome, server).await?;
            if first == target {
                return Ok(());
            }
            last = first - 1;
            first = (last + 1).saturating_sub(buffer).max(target);
        }
    }

    async fn record_backfill(
        &mut self,
        group: &Group,
        first: u64,
        outcome: &ScanOutcome,
        server: &SelectedGroup,
    ) -> Result<()> {
        let date = match &outcome.summary {
            Some(summary) => std::str::from_utf8(&summary.first_date)
                .ok()
                .and_then(crate::header::parse_date),
            None => Some(self.postdate(first, server).await),
        };
        sqlx::query(
            "UPDATE usenet_groups SET first_record_postdate = FROM_UNIXTIME(?), first_record = ?, \
             last_updated = NOW() WHERE id = ?",
        )
        .bind(date)
        .bind(first)
        .bind(group.id)
        .execute(self.pool)
        .await?;
        Ok(())
    }
}
