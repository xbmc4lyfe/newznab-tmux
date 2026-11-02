//! `captcha:status`: which CAPTCHA the site will show, from the settings `config/captcha.php`
//! read and `nntmux_site`'s login and registration pages use.

use nntmux_core::{optional, var_or};

/// One provider's settings.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Provider {
    pub enabled: bool,
    pub sitekey: bool,
    pub secret: bool,
}

impl Provider {
    fn read(flag: &str, sitekey: &str, secret: &str) -> Self {
        Self {
            // The words the site's pages accept as on.
            enabled: matches!(var_or(flag, "false").as_str(), "1" | "true" | "on" | "yes"),
            sitekey: optional(sitekey).is_some(),
            secret: optional(secret).is_some(),
        }
    }

    pub fn ready(&self) -> bool {
        self.enabled && self.sitekey && self.secret
    }
}

/// What `captcha:status` printed, line for line.
pub fn report() -> Vec<String> {
    render(
        &var_or("CAPTCHA_PROVIDER", "recaptcha"),
        &Provider::read("NOCAPTCHA_ENABLED", "NOCAPTCHA_SITEKEY", "NOCAPTCHA_SECRET"),
        &Provider::read("TURNSTILE_ENABLED", "TURNSTILE_SITEKEY", "TURNSTILE_SECRET"),
    )
}

pub fn render(provider: &str, recaptcha: &Provider, turnstile: &Provider) -> Vec<String> {
    let yes = |on: bool| if on { "Yes" } else { "No" };
    let set = |on: bool| if on { "Configured" } else { "Missing" };
    let mut lines = vec![
        "CAPTCHA Configuration Status".to_owned(),
        "================================".to_owned(),
        String::new(),
        format!("Active Provider: {provider}"),
        String::new(),
    ];
    for (name, settings) in [
        ("Google reCAPTCHA:", recaptcha),
        ("Cloudflare Turnstile:", turnstile),
    ] {
        lines.push(name.to_owned());
        lines.push(format!("  Enabled: {}", yes(settings.enabled)));
        lines.push(format!("  Site Key: {}", set(settings.sitekey)));
        lines.push(format!("  Secret: {}", set(settings.secret)));
        lines.push(String::new());
    }
    if recaptcha.ready() && turnstile.ready() {
        lines.push("⚠ WARNING: Both providers are enabled!".to_owned());
        lines.push("Only one CAPTCHA provider should be enabled at a time.".to_owned());
        lines.push(format!("The system will use: {provider}"));
        lines.push(String::new());
    }
    lines.push(
        match (provider, recaptcha.ready(), turnstile.ready()) {
            ("recaptcha", true, _) => "✓ reCAPTCHA is properly configured and active",
            ("turnstile", _, true) => "✓ Turnstile is properly configured and active",
            ("recaptcha", false, _) => "✗ reCAPTCHA is selected but not properly configured",
            ("turnstile", _, false) => "✗ Turnstile is selected but not properly configured",
            _ => "⚠ No CAPTCHA provider is active",
        }
        .to_owned(),
    );
    lines
}

#[cfg(test)]
mod tests {
    use super::*;

    const READY: Provider = Provider {
        enabled: true,
        sitekey: true,
        secret: true,
    };
    const OFF: Provider = Provider {
        enabled: false,
        sitekey: true,
        secret: true,
    };

    #[test]
    fn reports_the_active_provider_and_its_gaps() {
        let lines = render("turnstile", &OFF, &READY);
        assert_eq!(
            lines.last().unwrap(),
            "✓ Turnstile is properly configured and active"
        );
        assert!(lines.contains(&"  Enabled: No".to_owned()));

        let missing = Provider {
            secret: false,
            ..READY
        };
        let lines = render("recaptcha", &missing, &OFF);
        assert!(lines.contains(&"  Secret: Missing".to_owned()));
        assert_eq!(
            lines.last().unwrap(),
            "✗ reCAPTCHA is selected but not properly configured"
        );

        let lines = render("recaptcha", &READY, &READY);
        assert!(lines.contains(&"⚠ WARNING: Both providers are enabled!".to_owned()));
        assert_eq!(
            render("hcaptcha", &OFF, &OFF).last().unwrap(),
            "⚠ No CAPTCHA provider is active"
        );
    }
}
