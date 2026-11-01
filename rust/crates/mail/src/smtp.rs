//! The mailer Laravel's `config/mail.php` described, from the same environment variables.

use std::time::Duration;

use anyhow::{Context, Result, bail};
use lettre::message::{Mailbox, MultiPart};
use lettre::transport::smtp::authentication::Credentials;
use lettre::transport::smtp::client::{Tls, TlsParameters};
use lettre::transport::smtp::extension::ClientId;
use lettre::{AsyncSmtpTransport, AsyncTransport, Message, Tokio1Executor};
use nntmux_core::{optional, var_or};
use nntmux_webkit::mail::OutgoingMail;

/// Symfony's SMTP transport waited this long on a silent server.
const TIMEOUT: Duration = Duration::from_secs(60);

/// Where `MAIL_MAILER` sends mail.
#[derive(Clone, Debug, PartialEq)]
pub enum Mailer {
    Smtp(SmtpSettings),
    /// `MAIL_MAILER=log`: write each message to the log instead of sending it.
    Log,
}

#[derive(Clone, Debug, PartialEq)]
pub struct SmtpSettings {
    pub host: String,
    pub port: u16,
    /// TLS from the first byte. Otherwise STARTTLS is used whenever the server offers it, as
    /// Symfony did. Laravel only asked for `smtps` on port 465, whatever `MAIL_ENCRYPTION` said.
    pub implicit_tls: bool,
    pub credentials: Option<(String, String)>,
    pub ehlo_domain: Option<String>,
}

/// The settings a mailer reads, with Laravel's defaults. Values like `null` count as unset.
#[derive(Clone, Debug, Default)]
pub struct MailEnv {
    pub mailer: Option<String>,
    pub url: Option<String>,
    pub host: Option<String>,
    pub port: Option<String>,
    pub username: Option<String>,
    pub password: Option<String>,
    pub ehlo_domain: Option<String>,
}

impl MailEnv {
    pub fn from_env() -> Self {
        Self {
            mailer: optional("MAIL_MAILER"),
            url: optional("MAIL_URL"),
            host: optional("MAIL_HOST"),
            port: optional("MAIL_PORT"),
            username: optional("MAIL_USERNAME"),
            password: optional("MAIL_PASSWORD"),
            ehlo_domain: optional("MAIL_EHLO_DOMAIN"),
        }
    }

    pub fn mailer(&self) -> Result<Mailer> {
        match self.mailer.as_deref().unwrap_or("smtp") {
            "smtp" => {}
            "log" => return Ok(Mailer::Log),
            other => bail!("MAIL_MAILER={other} isn't supported: use smtp or log"),
        }
        if let Some(url) = &self.url {
            return smtp_from_url(url);
        }
        let port = match &self.port {
            Some(port) => port
                .parse()
                .with_context(|| format!("MAIL_PORT={port} isn't a port"))?,
            None => 587,
        };
        Ok(Mailer::Smtp(SmtpSettings {
            host: self
                .host
                .clone()
                .unwrap_or_else(|| "smtp.mailgun.org".to_owned()),
            port,
            implicit_tls: port == 465,
            credentials: credentials(self.username.clone(), self.password.clone()),
            ehlo_domain: self.ehlo_domain.clone(),
        }))
    }
}

fn credentials(username: Option<String>, password: Option<String>) -> Option<(String, String)> {
    username.map(|user| (user, password.unwrap_or_default()))
}

/// `MAIL_URL`, as `smtp://user:pass@host:port` or `smtps://...`.
fn smtp_from_url(url: &str) -> Result<Mailer> {
    let (scheme, rest) = url
        .split_once("://")
        .with_context(|| format!("MAIL_URL has no scheme: {url}"))?;
    let implicit = match scheme {
        "smtp" => false,
        "smtps" => true,
        other => bail!("MAIL_URL scheme {other} isn't supported: use smtp or smtps"),
    };
    let rest = rest.split(['/', '?']).next().unwrap_or_default();
    let (userinfo, hostport) = match rest.rsplit_once('@') {
        Some((userinfo, hostport)) => (Some(userinfo), hostport),
        None => (None, rest),
    };
    let (host, port) = match hostport.rsplit_once(':') {
        Some((host, port)) => (
            host,
            port.parse()
                .with_context(|| format!("MAIL_URL port {port} isn't a port"))?,
        ),
        None => (hostport, if implicit { 465 } else { 25 }),
    };
    if host.is_empty() {
        bail!("MAIL_URL has no host");
    }
    let credentials = userinfo.map(|userinfo| {
        let (user, pass) = userinfo.split_once(':').unwrap_or((userinfo, ""));
        (percent_decode(user), percent_decode(pass))
    });
    Ok(Mailer::Smtp(SmtpSettings {
        host: host.to_owned(),
        port,
        implicit_tls: implicit || port == 465,
        credentials,
        ehlo_domain: None,
    }))
}

fn percent_decode(raw: &str) -> String {
    let bytes = raw.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] == b'%'
            && let Some(hex) = raw.get(index + 1..index + 3)
            && let Ok(byte) = u8::from_str_radix(hex, 16)
        {
            out.push(byte);
            index += 3;
            continue;
        }
        out.push(bytes[index]);
        index += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// `mail.from`: the sender on every message, with Laravel's defaults.
pub fn sender() -> Result<Mailbox> {
    let address = optional("MAIL_FROM_ADDRESS").unwrap_or_else(|| "hello@example.com".to_owned());
    let name = optional("MAIL_FROM_NAME").unwrap_or_else(|| var_or("APP_NAME", "Laravel"));
    let address = address
        .parse()
        .with_context(|| format!("MAIL_FROM_ADDRESS={address} isn't an address"))?;
    Ok(Mailbox::new(Some(name), address))
}

/// Whether a failed send is worth trying again.
#[derive(Debug, PartialEq, Eq)]
pub enum Failure {
    /// A 5xx reply or a message that can't be built. Retrying sends the same thing again.
    Permanent(String),
    Transient(String),
}

pub enum Transport {
    Smtp(AsyncSmtpTransport<Tokio1Executor>),
    Log,
}

impl Transport {
    pub fn new(mailer: &Mailer) -> Result<Self> {
        let settings = match mailer {
            Mailer::Log => return Ok(Self::Log),
            Mailer::Smtp(settings) => settings,
        };
        let parameters =
            TlsParameters::new(settings.host.clone()).context("setting up TLS for SMTP")?;
        let tls = if settings.implicit_tls {
            Tls::Wrapper(parameters)
        } else {
            Tls::Opportunistic(parameters)
        };
        let mut builder =
            AsyncSmtpTransport::<Tokio1Executor>::builder_dangerous(settings.host.as_str())
                .port(settings.port)
                .tls(tls)
                .timeout(Some(TIMEOUT));
        if let Some((user, pass)) = &settings.credentials {
            builder = builder.credentials(Credentials::new(user.clone(), pass.clone()));
        }
        if let Some(domain) = &settings.ehlo_domain {
            builder = builder.hello_name(ClientId::Domain(domain.clone()));
        }
        Ok(Self::Smtp(builder.build()))
    }

    pub async fn send(&self, from: &Mailbox, mail: &OutgoingMail) -> Result<(), Failure> {
        let message =
            build(from, mail).map_err(|error| Failure::Permanent(format!("{error:#}")))?;
        match self {
            Self::Log => {
                tracing::info!(
                    to = %mail.to,
                    subject = %mail.subject,
                    body = %mail.text,
                    "mail (MAIL_MAILER=log)"
                );
                Ok(())
            }
            Self::Smtp(transport) => match transport.send(message).await {
                Ok(_) => Ok(()),
                Err(error) if error.is_permanent() => Err(Failure::Permanent(error.to_string())),
                Err(error) => Err(Failure::Transient(error.to_string())),
            },
        }
    }
}

/// A multipart message with the text and HTML versions, like Laravel's Markdown mail.
pub fn build(from: &Mailbox, mail: &OutgoingMail) -> Result<Message> {
    let to: Mailbox = mail
        .to
        .parse()
        .with_context(|| format!("recipient {} isn't an address", mail.to))?;
    let mut builder = Message::builder()
        .from(from.clone())
        .to(to)
        .subject(mail.subject.clone());
    if let Some(reply_to) = &mail.reply_to {
        let reply_to: Mailbox = reply_to
            .parse()
            .with_context(|| format!("reply-to {reply_to} isn't an address"))?;
        builder = builder.reply_to(reply_to);
    }
    let body = MultiPart::alternative_plain_html(mail.text.clone(), mail.html.clone());
    builder.multipart(body).context("building the message")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn env(pairs: &[(&str, &str)]) -> MailEnv {
        let get = |key: &str| {
            pairs
                .iter()
                .find(|(name, _)| *name == key)
                .map(|(_, value)| (*value).to_owned())
        };
        MailEnv {
            mailer: get("mailer"),
            url: get("url"),
            host: get("host"),
            port: get("port"),
            username: get("username"),
            password: get("password"),
            ehlo_domain: get("ehlo"),
        }
    }

    #[test]
    fn defaults_match_laravel() {
        let Mailer::Smtp(smtp) = env(&[]).mailer().expect("valid") else {
            panic!("smtp is the default");
        };
        assert_eq!(smtp.host, "smtp.mailgun.org");
        assert_eq!(smtp.port, 587);
        assert!(!smtp.implicit_tls);
        assert_eq!(smtp.credentials, None);
    }

    #[test]
    fn only_port_465_wraps_the_connection_in_tls() {
        let Mailer::Smtp(smtp) = env(&[("host", "mail.example"), ("port", "465")])
            .mailer()
            .expect("valid")
        else {
            panic!("smtp");
        };
        assert!(smtp.implicit_tls);
        let Mailer::Smtp(smtp) = env(&[("host", "mailpit"), ("port", "1025"), ("username", "u")])
            .mailer()
            .expect("valid")
        else {
            panic!("smtp");
        };
        assert!(!smtp.implicit_tls);
        assert_eq!(smtp.credentials, Some(("u".to_owned(), String::new())));
    }

    #[test]
    fn mail_url_wins_over_the_host_settings() {
        let parsed = env(&[
            (
                "url",
                "smtps://me%40example.com:p%3Ass@smtp.example.com:2465",
            ),
            ("host", "ignored"),
        ])
        .mailer()
        .expect("valid");
        assert_eq!(
            parsed,
            Mailer::Smtp(SmtpSettings {
                host: "smtp.example.com".to_owned(),
                port: 2465,
                implicit_tls: true,
                credentials: Some(("me@example.com".to_owned(), "p:ss".to_owned())),
                ehlo_domain: None,
            })
        );
    }

    #[test]
    fn unknown_mailers_fail_at_start() {
        assert!(env(&[("mailer", "ses")]).mailer().is_err());
        assert_eq!(
            env(&[("mailer", "log")]).mailer().expect("valid"),
            Mailer::Log
        );
    }

    #[test]
    fn builds_both_bodies_and_the_reply_to() {
        let from = Mailbox::new(
            Some("NNTmux".to_owned()),
            "site@example.com".parse().unwrap(),
        );
        let mail = OutgoingMail {
            to: "user@example.com".to_owned(),
            subject: "[NNTmux] Hello".to_owned(),
            html: "<p>Hi</p>".to_owned(),
            text: "Hi".to_owned(),
            reply_to: Some("asker@example.com".to_owned()),
        };
        let raw = String::from_utf8(build(&from, &mail).expect("builds").formatted()).unwrap();
        assert!(raw.contains("To: user@example.com"));
        assert!(raw.contains("Reply-To: asker@example.com"));
        assert!(raw.contains("Subject: [NNTmux] Hello"));
        assert!(raw.contains("text/plain"));
        assert!(raw.contains("text/html"));
    }

    #[test]
    fn a_bad_recipient_is_permanent() {
        let from = Mailbox::new(None, "site@example.com".parse().unwrap());
        let mail = OutgoingMail {
            to: "not an address".to_owned(),
            subject: String::new(),
            html: String::new(),
            text: String::new(),
            reply_to: None,
        };
        assert!(build(&from, &mail).is_err());
    }
}
