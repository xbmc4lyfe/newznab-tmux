//! `disposable:update`: refreshes the disposable-domain list the site's account forms read.
//!
//! The source is plain text with one domain per line. PHP's fetcher only took JSON, so its refresh
//! could never parse this source; JSON arrays still work here in case the source changes back.

use std::path::Path;
use std::time::Duration;

use anyhow::{Context, Result, bail};
use nntmux_core::var_or;
use tokio::fs;

pub const SOURCE: &str = "https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf";

const TIMEOUT: Duration = Duration::from_secs(60);

/// The list's location under `NNTMUX_STORAGE_DIR`, which the site crate also reads.
pub fn list_path() -> std::path::PathBuf {
    Path::new(&var_or("NNTMUX_STORAGE_DIR", "storage")).join("framework/disposable_domains.json")
}

/// Sorted, lowercase and without repeats. One bad entry fails the whole body, so an error page
/// served with a success status can never replace a good list.
pub fn parse(body: &str) -> Result<Vec<String>> {
    let entries: Vec<String> = if body.trim_start().starts_with('[') {
        serde_json::from_str(body).context("parsing the JSON domain list")?
    } else {
        body.lines()
            .map(str::trim)
            .filter(|line| !line.is_empty() && !line.starts_with('#'))
            .map(str::to_owned)
            .collect()
    };
    let mut domains = Vec::with_capacity(entries.len());
    for entry in entries {
        let domain = entry.to_ascii_lowercase();
        if !is_domain(&domain) {
            bail!("the domain list has an invalid entry: {entry:.80}");
        }
        domains.push(domain);
    }
    if domains.is_empty() {
        bail!("the domain list is empty");
    }
    domains.sort();
    domains.dedup();
    Ok(domains)
}

fn is_domain(domain: &str) -> bool {
    domain.contains('.')
        && domain.len() <= 253
        && domain
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'-'))
}

/// Downloads the list and swaps it in by rename, so the site never reads a partial file. Any failure
/// leaves the old file alone. Returns the number of domains.
pub async fn refresh(url: &str, path: &Path) -> Result<usize> {
    let body = reqwest::Client::builder()
        .timeout(TIMEOUT)
        .build()?
        .get(url)
        .send()
        .await?
        .error_for_status()?
        .text()
        .await?;
    let domains = parse(&body)?;
    let dir = path.parent().context("the list path has no directory")?;
    fs::create_dir_all(dir).await?;
    let temporary = dir.join(format!(".disposable_domains.{}.tmp", std::process::id()));
    fs::write(&temporary, serde_json::to_vec(&domains)?).await?;
    if let Err(failure) = fs::rename(&temporary, path).await {
        let _ = fs::remove_file(&temporary).await;
        return Err(failure.into());
    }
    Ok(domains.len())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_text_and_json_lists() {
        let text = "# comment\n\nMailinator.com\nb.example\r\nmailinator.com\n";
        assert_eq!(parse(text).unwrap(), ["b.example", "mailinator.com"]);
        assert_eq!(parse(r#"["B.example"]"#).unwrap(), ["b.example"]);
    }

    #[test]
    fn refuses_anything_that_is_not_a_domain_list() {
        assert!(parse("").is_err());
        assert!(parse("# only a comment\n").is_err());
        assert!(parse("<html>\n<body>rate limited</body>\n</html>").is_err());
        assert!(parse("good.example\nno spaces.example\n").is_err());
        assert!(parse("[1, 2]").is_err());
    }
}
