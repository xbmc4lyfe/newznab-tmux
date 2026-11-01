use std::sync::{Arc, Mutex};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use anyhow::Result;
use nntmux_mail::deliver;
use nntmux_mail::outbox::Outbox;
use nntmux_mail::smtp::{Failure, Mailer, SmtpSettings, Transport};
use nntmux_runtime::Shutdown;
use nntmux_webkit::mail::OutgoingMail;
use redis::AsyncCommands;
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpListener;

async fn outbox() -> Result<Option<Outbox>> {
    let Ok(url) = std::env::var("TEST_REDIS_URL") else {
        eprintln!("TEST_REDIS_URL is unset, skipping");
        return Ok(None);
    };
    let redis = redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?;
    let unique = SystemTime::now().duration_since(UNIX_EPOCH)?.as_nanos();
    Ok(Some(Outbox::new(
        redis,
        format!("test:{}:{unique}:mail:outbox", std::process::id()),
    )))
}

fn mail(to: &str, subject: &str) -> OutgoingMail {
    OutgoingMail {
        to: to.to_owned(),
        subject: subject.to_owned(),
        html: format!("<p>{subject}</p>"),
        text: subject.to_owned(),
        reply_to: None,
    }
}

/// What `nntmux_webkit::mail::enqueue` does, against the test's queue.
async fn enqueue(
    outbox: &Outbox,
    redis: &mut redis::aio::ConnectionManager,
    raw: &str,
) -> Result<()> {
    redis.lpush::<_, _, ()>(&outbox.queue, raw).await?;
    Ok(())
}

async fn redis() -> Result<redis::aio::ConnectionManager> {
    let url = std::env::var("TEST_REDIS_URL")?;
    Ok(redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?)
}

/// A minimal SMTP server that accepts everything except recipients at `reject.test`, and keeps
/// each message's DATA.
async fn smtp_server() -> Result<(u16, Arc<Mutex<Vec<String>>>)> {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let port = listener.local_addr()?.port();
    let received = Arc::new(Mutex::new(Vec::new()));
    let store = received.clone();
    tokio::spawn(async move {
        while let Ok((socket, _)) = listener.accept().await {
            let store = store.clone();
            tokio::spawn(async move {
                let (read, mut write) = socket.into_split();
                let mut lines = BufReader::new(read).lines();
                write.write_all(b"220 fake ESMTP\r\n").await.ok();
                while let Ok(Some(line)) = lines.next_line().await {
                    let verb = line.to_ascii_uppercase();
                    let reply: &[u8] = if verb.starts_with("EHLO") {
                        b"250-fake\r\n250 8BITMIME\r\n"
                    } else if verb.starts_with("RCPT") && verb.contains("REJECT.TEST") {
                        b"550 no such user\r\n"
                    } else if verb.starts_with("DATA") {
                        write.write_all(b"354 go\r\n").await.ok();
                        let mut body = String::new();
                        while let Ok(Some(line)) = lines.next_line().await {
                            if line == "." {
                                break;
                            }
                            body.push_str(&line);
                            body.push('\n');
                        }
                        store.lock().expect("lock").push(body);
                        b"250 queued\r\n"
                    } else if verb.starts_with("QUIT") {
                        write.write_all(b"221 bye\r\n").await.ok();
                        break;
                    } else {
                        b"250 ok\r\n"
                    };
                    write.write_all(reply).await.ok();
                }
            });
        }
    });
    Ok((port, received))
}

#[tokio::test]
async fn queued_mail_goes_out_over_smtp_oldest_first() -> Result<()> {
    let Some(outbox) = outbox().await? else {
        return Ok(());
    };
    let mut redis = redis().await?;
    let (port, received) = smtp_server().await?;
    let transport = Transport::new(&Mailer::Smtp(SmtpSettings {
        host: "127.0.0.1".to_owned(),
        port,
        implicit_tls: false,
        credentials: None,
        ehlo_domain: None,
    }))?;
    let from = "NNTmux <site@example.com>".parse()?;
    for subject in ["first", "second"] {
        enqueue(
            &outbox,
            &mut redis,
            &serde_json::to_string(&mail("user@example.com", subject))?,
        )
        .await?;
    }
    enqueue(
        &outbox,
        &mut redis,
        &serde_json::to_string(&mail("nobody@reject.test", "rejected"))?,
    )
    .await?;

    let (transport, from) = (&transport, &from);
    deliver(
        &outbox,
        |mail| async move { transport.send(from, &mail).await },
        &Shutdown::default(),
        true,
        &[],
    )
    .await?;

    let received = received.lock().expect("lock").clone();
    assert_eq!(received.len(), 2);
    assert!(received[0].contains("Subject: first"));
    assert!(received[1].contains("Subject: second"));
    assert!(received[0].contains("From: NNTmux <site@example.com>"));
    // The rejected recipient is a permanent failure, parked without retries.
    assert_eq!(outbox.counts().await?, (0, 0, 1));
    Ok(())
}

#[tokio::test]
async fn transient_failures_retry_then_park() -> Result<()> {
    let Some(outbox) = outbox().await? else {
        return Ok(());
    };
    let mut redis = redis().await?;
    enqueue(
        &outbox,
        &mut redis,
        &serde_json::to_string(&mail("a@example.com", "flaky"))?,
    )
    .await?;
    enqueue(
        &outbox,
        &mut redis,
        &serde_json::to_string(&mail("b@example.com", "down"))?,
    )
    .await?;
    enqueue(&outbox, &mut redis, "not json").await?;

    let attempts = Arc::new(Mutex::new(Vec::new()));
    let log = attempts.clone();
    deliver(
        &outbox,
        move |mail| {
            let log = log.clone();
            async move {
                let mut log = log.lock().expect("lock");
                log.push(mail.subject.clone());
                let tries = log.iter().filter(|seen| **seen == mail.subject).count();
                match mail.subject.as_str() {
                    "flaky" if tries < 3 => Err(Failure::Transient("421 busy".to_owned())),
                    "flaky" => Ok(()),
                    _ => Err(Failure::Transient("connection refused".to_owned())),
                }
            }
        },
        &Shutdown::default(),
        true,
        &[Duration::ZERO, Duration::ZERO, Duration::ZERO],
    )
    .await?;

    let attempts = attempts.lock().expect("lock").clone();
    assert_eq!(
        attempts,
        ["flaky", "flaky", "flaky", "down", "down", "down", "down"]
    );
    // "down" ran out of retries and the unreadable entry can never be sent.
    assert_eq!(outbox.counts().await?, (0, 0, 2));
    assert_eq!(outbox.retry_failed().await?, 2);
    assert_eq!(outbox.counts().await?, (2, 0, 0));
    Ok(())
}

#[tokio::test]
async fn a_restart_requeues_mail_a_stopped_sender_held() -> Result<()> {
    let Some(outbox) = outbox().await? else {
        return Ok(());
    };
    let mut redis = redis().await?;
    enqueue(
        &outbox,
        &mut redis,
        &serde_json::to_string(&mail("a@example.com", "held"))?,
    )
    .await?;
    enqueue(
        &outbox,
        &mut redis,
        &serde_json::to_string(&mail("b@example.com", "next"))?,
    )
    .await?;
    // A shutdown during the retry wait leaves the message in processing.
    let stop = Shutdown::default();
    let stopper = stop.clone();
    deliver(
        &outbox,
        move |_| {
            stopper.request();
            async { Err(Failure::Transient("timeout".to_owned())) }
        },
        &stop,
        false,
        &[Duration::from_secs(60)],
    )
    .await?;
    assert_eq!(outbox.counts().await?, (1, 1, 0));

    assert_eq!(outbox.recover().await?, 1);
    let order = Arc::new(Mutex::new(Vec::new()));
    let log = order.clone();
    deliver(
        &outbox,
        move |mail| {
            log.lock().expect("lock").push(mail.subject);
            async { Ok(()) }
        },
        &Shutdown::default(),
        true,
        &[],
    )
    .await?;
    assert_eq!(*order.lock().expect("lock"), ["held", "next"]);
    assert_eq!(outbox.counts().await?, (0, 0, 0));
    Ok(())
}
