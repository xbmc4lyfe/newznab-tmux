//! Account mail. Each message renders here and goes to the `mail` role's outbox.

use anyhow::Result;
use askama::Template;
use nntmux_core::{optional, var_or};
use nntmux_runtime::Ctx;
use nntmux_webkit::config;
use nntmux_webkit::mail::{OutgoingMail, enqueue};

/// One branded message. The text version is the same content without the markup.
#[derive(Template)]
#[template(path = "mail/message.html")]
struct MailHtml<'a> {
    site: &'a str,
    site_url: &'a str,
    heading: &'a str,
    paragraphs: &'a [String],
    button: Option<(&'a str, &'a str)>,
    panel: Option<&'a str>,
    after: &'a [String],
    signoff: &'a str,
}

fn subject_prefix() -> String {
    optional("MAIL_SUBJECT_PREFIX").unwrap_or_else(|| format!("[{}] ", config::app_name()))
}

/// What the operator receives. `mail.from.address` is the site's own mailbox in Laravel.
pub fn site_mailbox() -> Option<String> {
    optional("MAIL_FROM_ADDRESS")
}

pub struct Letter<'a> {
    pub to: &'a str,
    pub subject: &'a str,
    pub heading: &'a str,
    pub paragraphs: Vec<String>,
    pub button: Option<(&'a str, &'a str)>,
    pub panel: Option<String>,
    pub after: Vec<String>,
    pub reply_to: Option<String>,
}

impl Letter<'_> {
    fn render(&self) -> Result<OutgoingMail> {
        let site = config::app_name();
        let site_url = config::app_url();
        let signoff = format!("The {site} Team");
        let html = MailHtml {
            site: &site,
            site_url: &site_url,
            heading: self.heading,
            paragraphs: &self.paragraphs,
            button: self.button,
            panel: self.panel.as_deref(),
            after: &self.after,
            signoff: &signoff,
        }
        .render()?;
        let mut text = vec![self.heading.to_owned()];
        text.extend(self.paragraphs.iter().cloned());
        if let Some((label, url)) = self.button {
            text.push(format!("{label}: {url}"));
        }
        text.extend(self.panel.iter().cloned());
        text.extend(self.after.iter().cloned());
        text.push(format!("Best regards,\n{signoff}"));
        Ok(OutgoingMail {
            to: self.to.to_owned(),
            subject: format!("{}{}", subject_prefix(), self.subject),
            html,
            text: text.join("\n\n"),
            reply_to: self.reply_to.clone(),
        })
    }

    pub async fn send(&self, ctx: &Ctx) -> Result<()> {
        enqueue(ctx, &self.render()?).await
    }
}

pub async fn send_verification(ctx: &Ctx, to: &str, username: &str, url: &str) -> Result<()> {
    let site = config::app_name();
    let greeting = if username.is_empty() {
        "Hi there,".to_owned()
    } else {
        format!("Hi {username},")
    };
    Letter {
        to,
        subject: "Verify your email address",
        heading: "Verify your email address",
        paragraphs: vec![
            greeting,
            format!("Thanks for signing up for {site}! Please confirm your email address by clicking the button below."),
        ],
        button: Some(("Verify email address", url)),
        panel: None,
        after: vec![
            "If you did not create an account, no further action is required — you can safely ignore this email.".to_owned(),
            format!("If you're having trouble clicking the verify button, copy and paste this URL into your browser: {url}"),
        ],
        reply_to: None,
    }
    .send(ctx)
    .await
}

pub async fn send_password_reset(ctx: &Ctx, to: &str, url: &str) -> Result<()> {
    Letter {
        to,
        subject: "Forgotten password reset",
        heading: "Password reset request",
        paragraphs: vec![
            "Hello,".to_owned(),
            "We received a request to reset the password associated with this email address.".to_owned(),
        ],
        button: Some(("Reset password", url)),
        panel: Some("Security notice: If you didn't request this password reset, you can safely ignore this email — your password will remain unchanged.".to_owned()),
        after: vec![format!("If the button above doesn't work, copy and paste this link into your browser: {url}")],
        reply_to: None,
    }
    .send(ctx)
    .await
}

pub async fn send_welcome(ctx: &Ctx, to: &str, username: &str) -> Result<()> {
    let site = config::app_name();
    Letter {
        to,
        subject: &format!("Welcome to {site}"),
        heading: &format!("Welcome to {site}"),
        paragraphs: vec![format!("Dear {username},"), "We're thrilled to have you join our community.".to_owned()],
        button: None,
        panel: Some("Next step: A separate verification email is on its way. Please check your inbox and click the verification link to activate your account.".to_owned()),
        after: vec!["If you have any questions or need assistance, just reach out — we're happy to help.".to_owned()],
        reply_to: None,
    }
    .send(ctx)
    .await
}

pub async fn send_new_account_notice(ctx: &Ctx, to: &str, username: &str) -> Result<()> {
    let site = var_or("APP_NAME", "NNTmux");
    Letter {
        to,
        subject: "New user registration",
        heading: "New user registration",
        paragraphs: vec![format!("A new user has registered on {site}.")],
        button: None,
        panel: Some(format!("New user: {username}")),
        after: vec!["This is an automated notification — no action is required unless you need to review the new registration.".to_owned()],
        reply_to: None,
    }
    .send(ctx)
    .await
}
