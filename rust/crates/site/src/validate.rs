//! Rules for account forms: Laravel's validator messages, the password policy, the breached
//! password check and the disposable-address check.

use std::time::Duration;

use hickory_resolver::TokioResolver;
use sha1::{Digest, Sha1};
use tracing::warn;

/// Laravel's `email` rule as used here: one `@`, a local part, a dotted-or-bare domain, no spaces.
pub fn valid_email(email: &str) -> bool {
    let Some((local, domain)) = email.split_once('@') else {
        return false;
    };
    !local.is_empty()
        && !domain.is_empty()
        && !domain.contains('@')
        && !email.chars().any(char::is_whitespace)
        && !domain.starts_with('.')
        && !domain.ends_with('.')
        && !domain.contains("..")
}

/// PHP's `FILTER_VALIDATE_EMAIL` for choosing the login column: unlike the form rule above it
/// wants a dotted domain, so a username like `bob@home` still signs in as a username.
pub fn login_is_email(login: &str) -> bool {
    valid_email(login)
        && login
            .rsplit_once('@')
            .is_some_and(|(_, domain)| domain.contains('.'))
}

/// The composition half of `Password::min(8)->letters()->mixedCase()->numbers()->symbols()`.
pub fn password_policy_errors(password: &str) -> Vec<&'static str> {
    let mut errors = Vec::new();
    if password.chars().count() < 8 {
        errors.push("The password must be at least 8 characters.");
    }
    if !password.chars().any(char::is_alphabetic) {
        errors.push("The password must contain letters.");
    }
    if !(password.chars().any(char::is_uppercase) && password.chars().any(char::is_lowercase)) {
        errors.push("The password must contain both uppercase and lowercase letters.");
    }
    if !password.chars().any(char::is_numeric) {
        errors.push("The password must contain at least one number.");
    }
    if !password
        .chars()
        .any(|c| !c.is_alphanumeric() && !c.is_whitespace())
    {
        errors.push("The password must contain at least one symbol.");
    }
    errors
}

const PWNED_URL: &str = "https://api.pwnedpasswords.com";

/// How many breaches list this password, by k-anonymity range lookup. Fails open: `None` when
/// the service can't answer, so an outage can't block a sign-in.
pub async fn breach_count_at(base: &str, password: &str) -> Option<u64> {
    let digest = hex::encode_upper(Sha1::digest(password.as_bytes()));
    let (prefix, suffix) = digest.split_at(5);
    let response = reqwest::Client::new()
        .get(format!("{base}/range/{prefix}"))
        .header("Add-Padding", "true")
        .timeout(Duration::from_secs(5))
        .send()
        .await;
    let body = match response.and_then(reqwest::Response::error_for_status) {
        Ok(response) => response.text().await.ok()?,
        Err(failure) => {
            warn!(error = %failure, "the breached-password check failed");
            return None;
        }
    };
    Some(
        body.lines()
            .filter_map(|line| line.trim().split_once(':'))
            .find(|(hash, _)| hash.eq_ignore_ascii_case(suffix))
            .and_then(|(_, count)| count.parse().ok())
            .unwrap_or(0),
    )
}

/// At login a password counts as breached above one sighting, at registration above none.
pub async fn is_breached(password: &str, threshold: u64) -> bool {
    breach_count_at(PWNED_URL, password)
        .await
        .is_some_and(|count| count > threshold)
}

const SUSPICIOUS_PATTERNS: [&str; 20] = [
    "temp",
    "temporary",
    "disposable",
    "throwaway",
    "fake",
    "trash",
    "spam",
    "guerrilla",
    "10minute",
    "minutemail",
    "tempmail",
    "guerrillamail",
    "mailinator",
    "yopmail",
    "maildrop",
    "trashmail",
    "getnada",
    "throwawaymail",
    "sharklasers",
    "grr.la",
];

const BLOCKED_DOMAINS: [&str; 41] = [
    "10minutemail.com",
    "guerrillamail.com",
    "mailinator.com",
    "maildrop.cc",
    "temp-mail.org",
    "tempmail.com",
    "throwawaymail.com",
    "yopmail.com",
    "getnada.com",
    "sharklasers.com",
    "guerrillamail.info",
    "guerrillamail.biz",
    "guerrillamail.de",
    "grr.la",
    "guerrillamail.net",
    "guerrillamail.org",
    "spam4.me",
    "trashmail.com",
    "dispostable.com",
    "fakeinbox.com",
    "tmpeml.info",
    "tempinbox.com",
    "mytemp.email",
    "mohmal.com",
    "emailondeck.com",
    "throwam.com",
    "mintemail.com",
    "spamgourmet.com",
    "mailnesia.com",
    "trashmail.net",
    "trash-mail.com",
    "emailtemporanea.com",
    "anonbox.net",
    "anonymbox.com",
    "discard.email",
    "spambox.us",
    "trbvm.com",
    "emailna.co",
    "my10minutemail.com",
    "tempr.email",
    "harakirimail.com",
];

/// The domain half of `ValidEmailDomain`, without DNS. The message, if the address is refused.
pub fn disposable_error(email: &str) -> Option<&'static str> {
    const REFUSED: &str = "Temporary or disposable email addresses are not allowed.";
    let (local, domain) = email.split_once('@')?;
    let domain = domain.trim().to_ascii_lowercase();
    if local.trim().is_empty() || domain.is_empty() || domain.contains('@') {
        return Some("The email must be a valid email address.");
    }
    if BLOCKED_DOMAINS.contains(&domain.as_str())
        || SUSPICIOUS_PATTERNS
            .iter()
            .any(|pattern| domain.contains(pattern))
    {
        return Some(REFUSED);
    }
    None
}

/// `checkdnsrr` for MX, then A or AAAA. Resolver trouble counts as reachable, because a DNS blip
/// shouldn't turn away a real address.
pub async fn domain_has_mail_records(domain: &str) -> bool {
    let Ok(builder) = TokioResolver::builder_tokio() else {
        return true;
    };
    let resolver = builder.build();
    let name = format!("{domain}.");
    if resolver
        .mx_lookup(name.as_str())
        .await
        .is_ok_and(|found| found.iter().next().is_some())
    {
        return true;
    }
    match resolver.lookup_ip(name.as_str()).await {
        Ok(found) => found.iter().next().is_some(),
        Err(failure) => !failure.is_no_records_found(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{header, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    #[test]
    fn the_policy_names_each_gap() {
        assert!(password_policy_errors("Str0ng!pass").is_empty());
        assert_eq!(
            password_policy_errors("Sh0rt!"),
            ["The password must be at least 8 characters."]
        );
        assert!(
            password_policy_errors("alllowercase1!")
                .contains(&"The password must contain both uppercase and lowercase letters.")
        );
        assert!(
            password_policy_errors("NoDigitsHere!")
                .contains(&"The password must contain at least one number.")
        );
        assert!(
            password_policy_errors("NoSymbols123")
                .contains(&"The password must contain at least one symbol.")
        );
    }

    #[test]
    fn addresses_are_screened() {
        assert_eq!(disposable_error("a@example.com"), None);
        assert!(disposable_error("a@mailinator.com").is_some());
        assert!(
            disposable_error("a@tempinbox.net").is_some(),
            "pattern match"
        );
        assert!(valid_email("a.b+c@example.com"));
        assert!(!valid_email("a b@example.com"));
        assert!(!valid_email("nodomain@"));
        assert!(!valid_email("twoats@@example.com"));
        assert!(login_is_email("bob@example.com"));
        assert!(!login_is_email("bob@home"));
    }

    #[tokio::test]
    async fn breach_lookups_send_only_the_prefix() {
        let server = MockServer::start().await;
        // SHA-1 of "password" is 5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8.
        Mock::given(method("GET"))
            .and(path("/range/5BAA6"))
            .and(header("Add-Padding", "true"))
            .respond_with(ResponseTemplate::new(200).set_body_string(
                "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n1E4C9B93F3F0682250B6CF8331B7EE68FD8:3861493\r\n",
            ))
            .mount(&server)
            .await;
        assert_eq!(
            breach_count_at(&server.uri(), "password").await,
            Some(3_861_493)
        );
        assert_eq!(
            breach_count_at(&server.uri(), "unlisted-prefix").await,
            None
        );
    }

    #[tokio::test]
    async fn an_outage_fails_open() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .respond_with(ResponseTemplate::new(500))
            .mount(&server)
            .await;
        assert_eq!(breach_count_at(&server.uri(), "password").await, None);
    }
}
