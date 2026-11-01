//! The news server client behind ingest's [`Nntp`] trait.

use std::sync::Arc;

use anyhow::{Context, Result};
use async_trait::async_trait;
use nntmux_nntp::{NntpClient, NntpConfig, NntpError, OverviewRow};

use crate::groups::ServerGroup;
use crate::header::{Overview, php_int};
use crate::nntp::{NO_SUCH_ARTICLE, Nntp, NntpFailure, SelectedGroup};
use crate::run::Connector;

/// Opens one client per job, on the provider the environment chooses.
pub struct ClientConnector {
    config: Arc<NntpConfig>,
}

impl ClientConnector {
    pub fn new(config: NntpConfig) -> Self {
        Self {
            config: Arc::new(config),
        }
    }
}

#[async_trait]
impl Connector for ClientConnector {
    async fn connect(&self) -> Result<Box<dyn Nntp>> {
        let mut client = NntpClient::new(self.config.clone());
        client
            .do_connect(true, self.config.use_alternate)
            .await
            .context("Unable to connect to usenet.")?;
        Ok(Box::new(Client(client)))
    }
}

struct Client(NntpClient);

impl From<NntpError> for NntpFailure {
    fn from(failure: NntpError) -> Self {
        Self {
            code: failure
                .code()
                .or(failure.is_missing_article().then_some(NO_SUCH_ARTICLE)),
            message: failure.to_string(),
        }
    }
}

#[async_trait]
impl Nntp for Client {
    async fn select_group(&mut self, group: &str) -> Result<SelectedGroup, NntpFailure> {
        let summary = self.0.select_group(group, false).await?;
        Ok(SelectedGroup {
            name: summary.group,
            first: u64::try_from(summary.first).unwrap_or(0),
            last: u64::try_from(summary.last).unwrap_or(0),
        })
    }

    async fn xover(&mut self, first: u64, last: u64) -> Result<Vec<Overview>, NntpFailure> {
        let rows = self.0.get_xover(&format!("{first}-{last}")).await?;
        Ok(rows.iter().filter_map(overview).collect())
    }

    async fn xover_article(&mut self, number: u64) -> Result<Vec<Overview>, NntpFailure> {
        let rows = self.0.get_xover(&number.to_string()).await?;
        Ok(rows.iter().filter_map(overview).collect())
    }

    async fn reconnect(&mut self, compression: bool) -> Result<(), NntpFailure> {
        Ok(self.0.reconnect(compression).await?)
    }

    async fn enable_compression(&mut self) -> Result<(), NntpFailure> {
        self.0.enable_compression().await;
        Ok(())
    }

    async fn list_groups(&mut self) -> Result<Vec<ServerGroup>, NntpFailure> {
        let groups = self.0.get_groups(None).await?;
        Ok(groups
            .into_iter()
            .map(|group| ServerGroup {
                first: group.first.trim().parse().unwrap_or(0),
                last: group.last.trim().parse().unwrap_or(0),
                name: group.group,
            })
            .collect())
    }
}

/// A row by the server's field names. A row without a usable article number returns None, since
/// no number could be stored or repaired for it.
fn overview(row: &OverviewRow) -> Option<Overview> {
    let field = |name: &str| row.get(name).unwrap_or_default().to_vec();
    let number = std::str::from_utf8(row.get("Number")?)
        .ok()?
        .trim()
        .parse()
        .ok()?;
    // A server advertising `:bytes` has its field named `:byte` by the client's format parser.
    let bytes = ["Bytes", ":bytes", ":byte"]
        .iter()
        .find_map(|name| row.get(name).filter(|value| !value.is_empty()))
        .unwrap_or_default();
    Some(Overview {
        number,
        subject: field("Subject"),
        from: field("From"),
        date: field("Date"),
        message_id: field("Message-ID"),
        bytes: php_int(bytes),
        xref: field("Xref"),
    })
}
