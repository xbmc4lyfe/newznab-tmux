//! Outgoing mail: the `mail` role that delivers what web requests and jobs queue, and the
//! account messages that admin work and scheduled jobs send.
//!
//! Owner: ws-admin. See `rust/WORKSTREAMS.md`.

pub mod account;
pub mod outbox;
pub mod smtp;

use std::future::Future;
use std::time::Duration;

use anyhow::{Context, Result};
use clap::Subcommand;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use nntmux_webkit::mail::{OutgoingMail, outbox_key};
use tracing::{error, info, warn};

use crate::outbox::{BACKOFF, Outbox};
use crate::smtp::{Failure, MailEnv, Transport, sender};

/// How often an empty outbox is checked. Mail is rare and a reset link shouldn't wait long.
const POLL: Duration = Duration::from_secs(1);
/// How long to wait after Redis fails before claiming again.
const REDIS_RETRY: Duration = Duration::from_secs(5);

/// `nntmux-worker mail`: sends queued mail, one message at a time, until shutdown.
pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let mailer = MailEnv::from_env().mailer()?;
    let transport = Transport::new(&mailer)?;
    let from = sender()?;
    let outbox = Outbox::new(ctx.redis.clone(), outbox_key());
    let recovered = outbox.recover().await?;
    if recovered > 0 {
        info!(recovered, "requeued mail a stopped sender was holding");
    }
    let transport = &transport;
    let from = &from;
    deliver(
        &outbox,
        |mail| async move { transport.send(from, &mail).await },
        &stop,
        args.once,
        &BACKOFF,
    )
    .await
}

/// Sends until shutdown, or until the outbox is empty with `once`. A message the server turns
/// away for now is retried after each wait in `backoff`, then parked with the failed ones.
pub async fn deliver<F, Fut>(
    outbox: &Outbox,
    send: F,
    stop: &Shutdown,
    once: bool,
    backoff: &[Duration],
) -> Result<()>
where
    F: Fn(OutgoingMail) -> Fut,
    Fut: Future<Output = Result<(), Failure>>,
{
    while !stop.requested() {
        let raw = match outbox.claim().await {
            Ok(Some(raw)) => raw,
            Ok(None) if once => break,
            Ok(None) => {
                stop.sleep(POLL).await;
                continue;
            }
            Err(failure) => {
                warn!(
                    error = format!("{failure:#}"),
                    "the mail outbox is unreachable"
                );
                if once {
                    return Err(failure);
                }
                stop.sleep(REDIS_RETRY).await;
                continue;
            }
        };
        let mail: OutgoingMail = match serde_json::from_str(&raw) {
            Ok(mail) => mail,
            Err(failure) => {
                error!(error = %failure, "unreadable mail in the outbox, parked with failed mail");
                outbox.fail(&raw).await?;
                metrics::counter!("nntmux_mail_total", "outcome" => "failed").increment(1);
                continue;
            }
        };
        let mut retries = backoff.iter();
        loop {
            match send(mail.clone()).await {
                Ok(()) => {
                    outbox.done(&raw).await?;
                    metrics::counter!("nntmux_mail_total", "outcome" => "sent").increment(1);
                    info!(to = %mail.to, subject = %mail.subject, "mail sent");
                    break;
                }
                Err(Failure::Transient(reason)) => {
                    let Some(wait) = retries.next() else {
                        error!(to = %mail.to, subject = %mail.subject, %reason, "mail kept failing, parked with failed mail");
                        outbox.fail(&raw).await?;
                        metrics::counter!("nntmux_mail_total", "outcome" => "failed").increment(1);
                        break;
                    };
                    warn!(to = %mail.to, %reason, retry_in = wait.as_secs(), "mail send failed");
                    metrics::counter!("nntmux_mail_total", "outcome" => "retried").increment(1);
                    stop.sleep(*wait).await;
                    if stop.requested() {
                        // Still in processing: the next start sends it.
                        return Ok(());
                    }
                }
                Err(Failure::Permanent(reason)) => {
                    error!(to = %mail.to, subject = %mail.subject, %reason, "mail rejected, parked with failed mail");
                    outbox.fail(&raw).await?;
                    metrics::counter!("nntmux_mail_total", "outcome" => "failed").increment(1);
                    break;
                }
            }
        }
    }
    Ok(())
}

/// `nntmux-ctl mail ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Count queued, in-flight and failed mail.
    Status,
    /// Return every failed message to the outbox for the role to send again.
    RetryFailed,
    /// Send one test message now, straight through the configured mailer.
    Test {
        /// The recipient.
        to: String,
    },
}

pub async fn ctl(ctx: Ctx, command: Command) -> Result<()> {
    let outbox = Outbox::new(ctx.redis.clone(), outbox_key());
    match command {
        Command::Status => {
            let (queued, processing, failed) = outbox.counts().await?;
            println!("queued {queued}, sending {processing}, failed {failed}");
        }
        Command::RetryFailed => {
            let moved = outbox.retry_failed().await?;
            println!("requeued {moved} message(s)");
        }
        Command::Test { to } => {
            let transport = Transport::new(&MailEnv::from_env().mailer()?)?;
            let mail = OutgoingMail {
                to,
                subject: "Test message".to_owned(),
                html: "<p>This is a test message from nntmux-ctl.</p>".to_owned(),
                text: "This is a test message from nntmux-ctl.".to_owned(),
                reply_to: None,
            };
            match transport.send(&sender()?, &mail).await {
                Ok(()) => println!("sent"),
                Err(Failure::Permanent(reason) | Failure::Transient(reason)) => {
                    return Err(anyhow::anyhow!(reason)).context("sending the test message");
                }
            }
        }
    }
    Ok(())
}
