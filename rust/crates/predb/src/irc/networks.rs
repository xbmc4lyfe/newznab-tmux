//! The pre networks from `config/irc_settings.php`, as connection profiles.

use anyhow::{Result, bail};

use crate::feeds::env_truthy;

pub const LEGACY: &str = "synirc";

#[derive(Clone, Debug, PartialEq)]
pub enum Transport {
    Irc {
        server: String,
        port: u16,
        tls: bool,
        /// False only for a server whose certificate doesn't name it. The chain is still verified.
        verify_peer_name: bool,
        channels: Vec<(String, Option<String>)>,
    },
    WebSocket {
        url: String,
    },
}

#[derive(Clone, Debug, PartialEq)]
pub struct Network {
    pub name: String,
    pub format: String,
    /// `None` for the NNTmux bot format, whose lines name their own source.
    pub source: Option<String>,
    pub transport: Transport,
}

/// `(key, enabled env, format, source, server env, default server, port env, default port, tls, verify peer name, channels)`
type Row = (
    &'static str,
    &'static str,
    &'static str,
    &'static str,
    &'static str,
    &'static str,
    &'static str,
    u16,
    bool,
    bool,
    &'static [&'static str],
);

const NETWORKS: [Row; 7] = [
    (
        "corruptnet",
        "SCRAPE_IRC_CORRUPTNET_ENABLED",
        "corruptnet",
        "corrupt-net",
        "SCRAPE_IRC_CORRUPTNET_SERVER",
        "irc.corrupt-net.org",
        "SCRAPE_IRC_CORRUPTNET_PORT",
        6697,
        true,
        true,
        &["#pre", "#Pre.Spam"],
    ),
    // zenet's TLS certificate doesn't name irc.zenet.org; the chain is still verified.
    (
        "zenet",
        "SCRAPE_IRC_ZENET_ENABLED",
        "zenet",
        "zenet",
        "SCRAPE_IRC_ZENET_SERVER",
        "irc.zenet.org",
        "SCRAPE_IRC_ZENET_PORT",
        6697,
        true,
        false,
        &["#pre", "#Pre.Spam"],
    ),
    // #p2ptrace announces P2P releases that the scene pre channels never carry.
    (
        "predatabase",
        "SCRAPE_IRC_PREDATABASE_ENABLED",
        "predatabase",
        "predataba.se",
        "SCRAPE_IRC_PREDATABASE_SERVER",
        "irc.predataba.se",
        "SCRAPE_IRC_PREDATABASE_PORT",
        6697,
        true,
        true,
        &["#pre", "#pre.spam", "#p2ptrace"],
    ),
    (
        "abjects",
        "SCRAPE_IRC_ABJECTS_ENABLED",
        "zenet",
        "abjects",
        "SCRAPE_IRC_ABJECTS_SERVER",
        "irc.abjects.net",
        "SCRAPE_IRC_ABJECTS_PORT",
        6697,
        true,
        true,
        &["#MG-Pre"],
    ),
    (
        "ngp",
        "SCRAPE_IRC_NGP_ENABLED",
        "ngp",
        "ngp.re",
        "SCRAPE_IRC_NGP_SERVER",
        "irc.ngp.re",
        "SCRAPE_IRC_NGP_PORT",
        6697,
        true,
        true,
        &["#ngpre", "#ngpre.p2p.spam"],
    ),
    (
        "rizon",
        "SCRAPE_IRC_RIZON_ENABLED",
        "rizon",
        "rizon",
        "SCRAPE_IRC_RIZON_SERVER",
        "irc.rizon.net",
        "SCRAPE_IRC_RIZON_PORT",
        6697,
        true,
        true,
        &["#pre"],
    ),
    // A public mixed-format relay: receipt is only an observation; PRE time stays unknown.
    (
        "opentrackers",
        "SCRAPE_IRC_OPENTRACKERS_ENABLED",
        "opentrackers",
        "opentrackers-relay",
        "SCRAPE_IRC_OPENTRACKERS_SERVER",
        "irc.opentrackers.org",
        "SCRAPE_IRC_OPENTRACKERS_PORT",
        6697,
        true,
        true,
        &["#pre-all", "#p2p-pre"],
    ),
];

const STREAMS: [(&str, &str, &str, &str, &str); 2] = [
    (
        "predbclub_ws",
        "PREDB_STREAM_PREDB_CLUB_ENABLED",
        "predb_club",
        "PREDB_STREAM_PREDB_CLUB_URL",
        "wss://predb.club/api/v1/ws",
    ),
    (
        "predbnet_ws",
        "PREDB_STREAM_PREDB_NET_ENABLED",
        "predb_net",
        "PREDB_STREAM_PREDB_NET_URL",
        "wss://api.predb.net/ws",
    ),
];

fn env_or(name: &str, default: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| default.to_owned())
}

fn is_enabled(name: &str, default: bool) -> bool {
    if std::env::var(name).is_err() {
        return default;
    }
    env_truthy(name)
}

/// Every configured network, in `irc_settings` order.
fn keys() -> Vec<&'static str> {
    let mut keys = vec![LEGACY];
    keys.extend(NETWORKS.iter().map(|row| row.0));
    keys.insert(4, "efnet");
    keys.extend(STREAMS.iter().map(|row| row.0));
    keys
}

pub fn enabled() -> Vec<String> {
    let mut enabled = Vec::new();
    if is_enabled("SCRAPE_IRC_SYNIRC_ENABLED", true) {
        enabled.push(LEGACY.to_owned());
    }
    for row in &NETWORKS {
        if is_enabled(row.1, false) {
            enabled.push(row.0.to_owned());
        }
    }
    if is_enabled("SCRAPE_IRC_EFNET_ENABLED", false) {
        enabled.push("efnet".to_owned());
    }
    for row in &STREAMS {
        if is_enabled(row.1, false) {
            enabled.push(row.0.to_owned());
        }
    }
    enabled
}

pub fn resolve(key: &str) -> Result<Network> {
    if key == LEGACY {
        return Ok(Network {
            name: key.to_owned(),
            format: "nntmux".to_owned(),
            source: None,
            transport: Transport::Irc {
                server: env_or("SCRAPE_IRC_SERVER", "irc.synirc.net"),
                port: env_or("SCRAPE_IRC_PORT", "6667").parse().unwrap_or(6667),
                tls: env_truthy("SCRAPE_IRC_TLS"),
                verify_peer_name: true,
                channels: parse_channels(&env_or("SCRAPE_IRC_CHANNELS", "#PreNNTmux")),
            },
        });
    }
    if key == "efnet" {
        // EFnet's servers present self-signed certificates, so verified TLS can't connect.
        // Plain IRC is the default; the channel is public and no password is sent.
        return Ok(Network {
            name: key.to_owned(),
            format: "corruptnet".to_owned(),
            source: Some("efnet".to_owned()),
            transport: Transport::Irc {
                server: env_or("SCRAPE_IRC_EFNET_SERVER", "irc.efnet.org"),
                port: env_or("SCRAPE_IRC_EFNET_PORT", "6667")
                    .parse()
                    .unwrap_or(6667),
                tls: env_truthy("SCRAPE_IRC_EFNET_TLS"),
                verify_peer_name: true,
                channels: vec![("#pre".to_owned(), None)],
            },
        });
    }
    if let Some(row) = NETWORKS.iter().find(|row| row.0 == key) {
        return Ok(Network {
            name: key.to_owned(),
            format: row.2.to_owned(),
            source: Some(row.3.to_owned()),
            transport: Transport::Irc {
                server: env_or(row.4, row.5),
                port: env_or(row.6, &row.7.to_string()).parse().unwrap_or(row.7),
                tls: row.8,
                verify_peer_name: row.9,
                channels: row
                    .10
                    .iter()
                    .map(|channel| ((*channel).to_owned(), None))
                    .collect(),
            },
        });
    }
    if let Some(row) = STREAMS.iter().find(|row| row.0 == key) {
        return Ok(Network {
            name: key.to_owned(),
            format: row.2.to_owned(),
            source: Some(key.to_owned()),
            transport: Transport::WebSocket {
                url: env_or(row.3, row.4),
            },
        });
    }
    bail!(
        "Unknown IRC network [{key}]. Configured: {}",
        keys().join(", ")
    )
}

/// `#chan1,#chan2:password`. A missing leading `#` is added.
pub fn parse_channels(value: &str) -> Vec<(String, Option<String>)> {
    let mut channels: Vec<(String, Option<String>)> = Vec::new();
    for item in value.split(',') {
        let item = item.trim();
        if item.is_empty() {
            continue;
        }
        let (name, password) = match item.split_once(':') {
            Some((name, password)) => (name.trim(), Some(password.trim())),
            None => (item, None),
        };
        if name.is_empty() || name == "#" {
            continue;
        }
        let name = if name.starts_with('#') {
            name.to_owned()
        } else {
            format!("#{name}")
        };
        let password = password.filter(|p| !p.is_empty()).map(str::to_owned);
        match channels.iter_mut().find(|(known, _)| *known == name) {
            Some(known) => known.1 = password,
            None => channels.push((name, password)),
        }
    }
    if channels.is_empty() {
        channels.push(("#PreNNTmux".to_owned(), None));
    }
    channels
}
