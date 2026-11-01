//! The visitor's address behind Caddy and Cloudflare. `X-Forwarded-For` is read from the right,
//! skipping proxies we trust, so a client can't spoof it by sending its own header.

use std::convert::Infallible;
use std::net::{IpAddr, SocketAddr};

use axum::extract::{ConnectInfo, FromRequestParts};
use axum::http::request::Parts;
use ipnet::IpNet;
use nntmux_core::{optional, var_or};

/// Cloudflare's published ranges. Laravel fetched them with `cloudflare:reload`; they change
/// rarely enough to keep here, and `TRUSTED_PROXIES` covers additions.
const CLOUDFLARE: [&str; 22] = [
    "173.245.48.0/20",
    "103.21.244.0/22",
    "103.22.200.0/22",
    "103.31.4.0/22",
    "141.101.64.0/18",
    "108.162.192.0/18",
    "190.93.240.0/20",
    "188.114.96.0/20",
    "197.234.240.0/22",
    "198.41.128.0/17",
    "162.158.0.0/15",
    "104.16.0.0/13",
    "104.24.0.0/14",
    "172.64.0.0/13",
    "131.0.72.0/22",
    "2400:cb00::/32",
    "2606:4700::/32",
    "2803:f800::/32",
    "2405:b500::/32",
    "2405:8100::/32",
    "2a06:98c0::/29",
    "2c0f:f248::/32",
];

fn is_private(ip: IpAddr) -> bool {
    match ip {
        IpAddr::V4(v4) => v4.is_loopback() || v4.is_private() || v4.is_link_local(),
        IpAddr::V6(v6) => {
            v6.is_loopback()
                || v6.is_unique_local()
                || v6.is_unicast_link_local()
                || v6
                    .to_ipv4_mapped()
                    .is_some_and(|v4| is_private(IpAddr::V4(v4)))
        }
    }
}

fn trusted_proxies() -> Vec<IpNet> {
    let mut nets: Vec<IpNet> = optional("TRUSTED_PROXIES")
        .unwrap_or_default()
        .split(',')
        .filter_map(|entry| parse_net(entry.trim()))
        .collect();
    if !matches!(
        var_or("TRUST_CLOUDFLARE", "true").as_str(),
        "0" | "false" | "off" | "no"
    ) {
        nets.extend(CLOUDFLARE.iter().filter_map(|entry| parse_net(entry)));
    }
    nets
}

fn parse_net(entry: &str) -> Option<IpNet> {
    entry
        .parse()
        .ok()
        .or_else(|| entry.parse::<IpAddr>().ok().map(IpNet::from))
}

/// Walks from the connecting peer back through the proxies to the first address not trusted.
pub fn resolve(peer: IpAddr, forwarded: Option<&str>, trusted: &[IpNet]) -> IpAddr {
    let is_trusted = |ip: IpAddr| is_private(ip) || trusted.iter().any(|net| net.contains(&ip));
    if !is_trusted(peer) {
        return peer;
    }
    let mut last = peer;
    for hop in forwarded.unwrap_or_default().rsplit(',') {
        let Ok(ip) = hop.trim().parse::<IpAddr>() else {
            break;
        };
        last = ip;
        if !is_trusted(ip) {
            break;
        }
    }
    last
}

/// The resolved client address.
#[derive(Clone, Copy, Debug)]
pub struct ClientIp(pub IpAddr);

impl std::fmt::Display for ClientIp {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        self.0.fmt(formatter)
    }
}

/// Resolves from raw request parts, for middleware.
pub fn from_parts(parts: &Parts) -> ClientIp {
    let peer = parts
        .extensions
        .get::<ConnectInfo<SocketAddr>>()
        .map_or(IpAddr::from([127, 0, 0, 1]), |info| info.0.ip());
    let forwarded = parts
        .headers
        .get("x-forwarded-for")
        .and_then(|value| value.to_str().ok());
    ClientIp(resolve(peer, forwarded, &trusted_proxies()))
}

impl<S: Sync> FromRequestParts<S> for ClientIp {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, _: &S) -> Result<Self, Self::Rejection> {
        Ok(from_parts(parts))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ip(text: &str) -> IpAddr {
        text.parse().unwrap()
    }

    #[test]
    fn a_direct_public_client_cant_spoof_the_header() {
        assert_eq!(resolve(ip("8.8.8.8"), Some("1.1.1.1"), &[]), ip("8.8.8.8"));
    }

    #[test]
    fn the_rightmost_untrusted_hop_wins() {
        let cloudflare = vec![parse_net("162.158.0.0/15").unwrap()];
        let forwarded = Some("6.6.6.6, 8.8.8.8, 162.158.1.1");
        assert_eq!(
            resolve(ip("172.18.0.2"), forwarded, &cloudflare),
            ip("8.8.8.8")
        );
    }

    #[test]
    fn with_no_header_the_peer_stands() {
        assert_eq!(resolve(ip("10.0.0.1"), None, &[]), ip("10.0.0.1"));
    }

    #[test]
    fn junk_stops_the_walk() {
        assert_eq!(
            resolve(ip("10.0.0.1"), Some("oops, 10.0.0.2"), &[]),
            ip("10.0.0.2")
        );
    }
}
