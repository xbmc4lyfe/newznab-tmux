//! What the provider clients share: retries, and the error that means "try this release later".

use std::fmt;
use std::time::Duration;

use reqwest::{RequestBuilder, Response};

/// A provider failed transiently: a rate limit, a 5xx, a timeout or a connection error. A 404 or
/// an empty result is never transient; the clients return `None` for those.
#[derive(Debug)]
pub struct TransientError {
    pub provider: &'static str,
    pub message: String,
    pub status: Option<u16>,
    pub retry_after: Option<i64>,
}

impl fmt::Display for TransientError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str(&self.message)
    }
}

impl std::error::Error for TransientError {}

pub fn is_transient_status(status: u16) -> bool {
    status == 429 || status >= 500
}

/// Seconds from a Retry-After header, which is delta-seconds or an HTTP date.
pub fn parse_retry_after(header: Option<&str>) -> Option<i64> {
    let header = header?.trim();
    if header.is_empty() {
        return None;
    }
    if let Ok(seconds) = header.parse::<f64>() {
        return Some((seconds as i64).max(0));
    }
    let date = chrono::DateTime::parse_from_rfc2822(header).ok()?;
    Some((date.timestamp() - chrono::Utc::now().timestamp()).max(0))
}

pub fn retry_after_of(response: &Response) -> Option<i64> {
    let header = response.headers().get(reqwest::header::RETRY_AFTER)?;
    parse_retry_after(header.to_str().ok())
}

/// Sends a request up to `tries` times with `delay` between attempts, as Laravel's
/// `Http::retry(..., throw: false)`: connection errors always retry, and a failed status retries
/// when `retry_status` says so. The last response or error comes back to the caller.
pub async fn send_retrying(
    request: RequestBuilder,
    tries: u32,
    delay: Duration,
    retry_status: impl Fn(u16) -> bool,
) -> Result<Response, reqwest::Error> {
    let tries = tries.max(1);
    let mut attempt = 1;
    loop {
        let this_try = request
            .try_clone()
            .expect("provider requests have no streaming body");
        match this_try.send().await {
            Ok(response) => {
                let status = response.status().as_u16();
                if status < 400 || attempt >= tries || !retry_status(status) {
                    return Ok(response);
                }
            }
            Err(failure) => {
                if attempt >= tries {
                    return Err(failure);
                }
            }
        }
        attempt += 1;
        tokio::time::sleep(delay).await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn retry_after_forms() {
        assert_eq!(parse_retry_after(Some("120")), Some(120));
        assert_eq!(parse_retry_after(Some("-5")), Some(0));
        assert_eq!(parse_retry_after(Some(" ")), None);
        assert_eq!(parse_retry_after(None), None);
        assert_eq!(parse_retry_after(Some("soon")), None);
        assert_eq!(
            parse_retry_after(Some("Wed, 21 Oct 2015 07:28:00 GMT")),
            Some(0)
        );
    }

    #[test]
    fn transient_statuses() {
        assert!(is_transient_status(429));
        assert!(is_transient_status(503));
        assert!(!is_transient_status(404));
        assert!(!is_transient_status(401));
    }
}
