//! Account messages: Laravel's `AccountChange`, `AccountDeleted`, `AccountExpired` and
//! `AccountWillExpire` mailables, rendered with the site's branded layout and queued for the
//! `mail` role.

use anyhow::Result;
use nntmux_runtime::Ctx;
use nntmux_site::mailer::{Letter, site_mailbox};
use nntmux_webkit::config;

/// `SendAccountChangedEmail`: a user's role changed.
pub async fn send_account_changed(ctx: &Ctx, to: &str, username: &str, role: &str) -> Result<()> {
    Letter {
        to,
        subject: "Account level changed",
        heading: "Account level changed",
        paragraphs: vec![
            format!("Dear {username},"),
            "We wanted to let you know that your account level has been updated.".to_owned(),
        ],
        button: None,
        panel: Some(format!("New account level: {role}")),
        after: vec![
            "Your new level is now active and you can start enjoying all the associated features and benefits immediately.".to_owned(),
            "If you have any questions about your new account level or need assistance, please don't hesitate to contact us.".to_owned(),
        ],
        reply_to: None,
    }
    .send(ctx)
    .await
}

/// `SendAccountDeletedEmail`: tells the site's own mailbox a user deleted their account. Does
/// nothing when `MAIL_FROM_ADDRESS` is unset, since there's nobody to tell.
pub async fn send_account_deleted(ctx: &Ctx, username: &str) -> Result<()> {
    let Some(to) = site_mailbox() else {
        return Ok(());
    };
    let site = config::app_name();
    Letter {
        to: &to,
        subject: "User account deleted",
        heading: "User account deleted",
        paragraphs: vec![format!(
            "This is a notification that a user has deleted their account from {site}."
        )],
        button: None,
        panel: Some(format!("Deleted account: {username}")),
        after: vec![
            "No further action is required. This email is for informational purposes only."
                .to_owned(),
        ],
        reply_to: None,
    }
    .send(ctx)
    .await
}

/// `SendAccountExpiredEmail`: a paid role ran out and the account moved to `role`.
pub async fn send_account_expired(ctx: &Ctx, to: &str, username: &str, role: &str) -> Result<()> {
    Letter {
        to,
        subject: "Your account has expired",
        heading: "Account expired",
        paragraphs: vec![
            format!("Dear {username},"),
            "We regret to inform you that your account subscription has expired.".to_owned(),
        ],
        button: None,
        panel: Some(format!(
            "Account downgraded: Your account has been automatically downgraded to {role}."
        )),
        after: vec![
            "Don't worry — you can still access the site with your downgraded account level. If you'd like to regain access to all your previous features and benefits, please consider renewing your subscription.".to_owned(),
            "If you have any questions or need help with the renewal process, please don't hesitate to contact us.".to_owned(),
        ],
        reply_to: None,
    }
    .send(ctx)
    .await
}

/// A role queued to start when the current one ends.
pub struct PendingRole<'a> {
    pub name: &'a str,
    /// As the message shows it.
    pub starts: &'a str,
}

/// `SendAccountWillExpireEmail`: `role` ends within `days` days.
pub async fn send_account_will_expire(
    ctx: &Ctx,
    to: &str,
    username: &str,
    role: &str,
    days: u32,
    pending: Option<PendingRole<'_>>,
) -> Result<()> {
    let mut after = match &pending {
        Some(pending) => vec![
            format!(
                "Your {} role is already scheduled to take effect on {} after your current role expires.",
                pending.name, pending.starts
            ),
            "No renewal action is needed for that pending role. If these details do not look right, please reach out before the expiry date.".to_owned(),
        ],
        None => vec!["To continue enjoying uninterrupted access to all your current features and benefits, please take action before your subscription expires.".to_owned()],
    };
    after.push(
        "If you have any questions about renewing your account, please don't hesitate to reach out."
            .to_owned(),
    );
    Letter {
        to,
        subject: "Your account is about to expire",
        heading: "Account expiration notice",
        paragraphs: vec![format!("Dear {username},")],
        button: None,
        panel: Some(format!(
            "Heads up: Your {role} role expires in less than {days} day(s)."
        )),
        after,
        reply_to: None,
    }
    .send(ctx)
    .await
}
