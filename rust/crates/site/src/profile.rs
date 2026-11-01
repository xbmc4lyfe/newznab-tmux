//! The profile page, account settings, theme switches and 2FA setup.

use std::collections::HashSet;

use anyhow::Result;
use askama::Template;
use axum::extract::State;
use axum::http::{HeaderMap, StatusCode, header};
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse, Redirect, Response};
use axum::routing::{get, post};
use axum::{Json, Router};
use chrono::{DateTime, NaiveDateTime, Utc};
use md5::{Digest, Md5};
use nntmux_core::var_or;
use nntmux_runtime::Ctx;
use nntmux_webkit::auth::{Auth, verify_password};
use nntmux_webkit::session::random_string;
use nntmux_webkit::throttle;
use nntmux_webkit::user::{Access, USER_MODEL};
use nntmux_webkit::{
    CurrentUser, Input, Layout, MaybeUser, Session, User, WebResult, auth, config, guard,
};
use rand::Rng;
use serde_json::json;

use crate::auth::{send_verification, verify_code};
use crate::guest::GuestLayout;
use crate::validate;

const SCHEMES: [&str; 11] = [
    "blue", "indigo", "cyan", "teal", "emerald", "violet", "pink", "rose", "red", "orange", "amber",
];
const THEMES: [&str; 3] = ["light", "dark", "system"];
const TIMEZONE_REGIONS: [&str; 10] = [
    "Africa",
    "America",
    "Antarctica",
    "Arctic",
    "Asia",
    "Atlantic",
    "Australia",
    "Europe",
    "Indian",
    "Pacific",
];
/// `syncUserPermissions`: each checkbox and the view permission it grants directly.
const VIEW_PERMISSIONS: [(&str, &str); 8] = [
    ("viewconsole", "view console"),
    ("viewmovies", "view movies"),
    ("viewaudio", "view audio"),
    ("viewpc", "view pc"),
    ("viewtv", "view tv"),
    ("viewadult", "view adult"),
    ("viewbooks", "view books"),
    ("viewother", "view other"),
];

pub fn router() -> Router<Ctx> {
    let pages = Router::new()
        .route("/profile", get(show).post(show))
        .route("/profileedit", get(edit).post(edit))
        .route("/profile/update-theme", post(update_theme))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    let two_factor_forms = Router::new()
        .route("/2fa", get(two_factor_form))
        .route("/2fa/enable", get(two_factor_form))
        .route("/2fa/disable", get(two_factor_form));
    let two_factor_changes = Router::new()
        .route("/2fa", post(enable_two_factor))
        .route("/generate2faSecret", post(generate_secret))
        .route("/profileedit/enable2fa", post(enable_two_factor))
        .route("/disable2fa", post(delete_two_factor))
        .route("/profileedit/disable2fa", post(delete_two_factor))
        .route("/profile-disable2fa", post(switch_off_two_factor))
        .route("/profileedit/cancel2fa", post(cancel_two_factor))
        .route(
            "/profile-security/disable-2fa",
            post(security_disable_two_factor),
        )
        // These all take a code or a password, so guesses share one budget.
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "2fa-setup", 6, 60)
        }));
    let two_factor = two_factor_forms
        .merge(two_factor_changes)
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    pages.merge(two_factor)
}

struct RoleExpiry {
    date: String,
    relative: String,
    expired: bool,
    days: i64,
}

struct PendingRole {
    name: String,
    date: String,
    relative: String,
}

#[derive(Template)]
#[template(path = "profile/index.html")]
struct ProfilePage {
    layout: Layout,
    header_title: String,
    user: User,
    is_admin: bool,
    can_see_private: bool,
    public_view: bool,
    gravatar: String,
    joined_date: String,
    joined_ago: String,
    last_login: String,
    role_name: String,
    role_expiry: Option<RoleExpiry>,
    pending_role: Option<PendingRole>,
    invited_by: Option<(i64, String)>,
    timezone_display: String,
    current_time: String,
    grabs_today: i64,
    download_remaining: i64,
    download_percentage: i64,
    download_color: &'static str,
    api_requests: i64,
    api_limit: i64,
    api_remaining: i64,
    api_percentage: i64,
    api_color: &'static str,
}

/// Carbon's `diffForHumans`, for both directions.
pub(crate) fn relative(at: DateTime<Utc>, now: DateTime<Utc>) -> String {
    let seconds = (now - at).num_seconds();
    let size = seconds.abs();
    let (count, unit) = match size {
        0..60 => (size, "second"),
        60..3600 => (size / 60, "minute"),
        3600..86400 => (size / 3600, "hour"),
        86400..604_800 => (size / 86400, "day"),
        604_800..2_629_800 => (size / 604_800, "week"),
        2_629_800..31_557_600 => (size / 2_629_800, "month"),
        _ => (size / 31_557_600, "year"),
    };
    let plural = if count == 1 { "" } else { "s" };
    if seconds < 0 {
        format!("{count} {unit}{plural} from now")
    } else {
        format!("{count} {unit}{plural} ago")
    }
}

fn gravatar_url(email: &str) -> String {
    let hash = hex::encode(Md5::digest(email.trim().to_lowercase().as_bytes()));
    format!("https://www.gravatar.com/avatar/{hash}?s=200&d=identicon")
}

/// A usage bar: the percentage and its color, with `warm` at 70% and red at 90%.
fn usage_bar(used: i64, limit: i64, calm: &'static str) -> (i64, &'static str) {
    let percentage = if limit > 0 {
        (used * 100 / limit).min(100)
    } else {
        0
    };
    let color = match percentage {
        90.. => "bg-red-600",
        70.. => "bg-yellow-500",
        _ => calm,
    };
    (percentage, color)
}

fn local_time(zone: &str, now: DateTime<Utc>) -> String {
    let zone: chrono_tz::Tz = zone.parse().unwrap_or(chrono_tz::UTC);
    now.with_timezone(&zone)
        .format("%b %d, %Y %H:%M")
        .to_string()
}

async fn count_last_day(ctx: &Ctx, table: &'static str, user_id: i64) -> Result<i64> {
    let sql = format!(
        "SELECT COUNT(*) FROM {table} WHERE users_id = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
        .bind(user_id)
        .fetch_one(&ctx.db)
        .await?)
}

async fn show(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let privileged = signed_in.access.has_any_role(&["Admin", "Moderator"]);
    let private_profiles = !matches!(
        var_or("PRIVATE_PROFILES", "true").as_str(),
        "0" | "false" | "off" | "no"
    );
    let mut user = (*signed_in.user).clone();
    let mut public_view = false;
    if privileged || !private_profiles {
        let id = input
            .get("id")
            .filter(|id| id.bytes().all(|byte| byte.is_ascii_digit()))
            .and_then(|id| id.parse().ok());
        if let Some(id) = id {
            if let Some(found) = User::find(&ctx.db, id).await? {
                public_view = found.id != signed_in.user.id;
                user = found;
            }
        } else if let Some(name) = input.get("name")
            && let Some(found) = User::find_by(&ctx.db, "username", &name).await?
        {
            public_view = found.id != signed_in.user.id;
            user = found;
        }
    }
    let access = if user.id == signed_in.user.id {
        (*signed_in.access).clone()
    } else {
        Access::load(&ctx.db, user.id).await?
    };
    let role: Option<(String, i32, i32)> = sqlx::query_as(
        "SELECT r.name, CAST(r.downloadrequests AS SIGNED) + 0, CAST(r.apirequests AS SIGNED) + 0 FROM roles r
         JOIN model_has_roles m ON m.role_id = r.id WHERE m.model_type = ? AND m.model_id = ? ORDER BY r.id LIMIT 1",
    )
    .bind(USER_MODEL)
    .bind(user.id)
    .fetch_optional(&ctx.db)
    .await?;
    let (role_name, download_limit, api_limit) = role
        .map_or(("User".to_owned(), 0, 0), |(name, downloads, api)| {
            (name, i64::from(downloads), i64::from(api))
        });
    let grabs_today = count_last_day(&ctx, "user_downloads", user.id).await?;
    let api_requests = count_last_day(&ctx, "user_requests", user.id).await?;
    let (download_percentage, download_color) =
        usage_bar(grabs_today, download_limit, "bg-green-600");
    let (api_percentage, api_color) = usage_bar(api_requests, api_limit, "bg-purple-600");
    let now = Utc::now();
    let as_utc = |at: NaiveDateTime| at.and_utc();
    let role_expiry = user.rolechangedate.map(as_utc).map(|at| RoleExpiry {
        date: at.format("%b %d, %Y").to_string(),
        relative: relative(at, now),
        expired: at < now,
        days: (at - now).num_days(),
    });
    let pending_role = match (
        user.pending_roles_id,
        user.pending_role_start_date.map(as_utc),
    ) {
        (Some(id), Some(start)) if start > now => {
            let name: Option<String> = sqlx::query_scalar("SELECT name FROM roles WHERE id = ?")
                .bind(id)
                .fetch_optional(&ctx.db)
                .await?;
            Some(PendingRole {
                name: name.unwrap_or_else(|| "Unknown".to_owned()),
                date: start.format("%b %d, %Y %-I:%M %p").to_string(),
                relative: relative(start, now),
            })
        }
        _ => None,
    };
    let invited_by = match user.invitedby {
        Some(id) => User::find(&ctx.db, i64::from(id))
            .await?
            .map(|inviter| (inviter.id, inviter.username)),
        None => None,
    };
    let zone = user
        .timezone
        .clone()
        .filter(|zone| !zone.is_empty())
        .unwrap_or_else(|| "UTC".to_owned());
    let page = ProfilePage {
        layout: layout.titled(
            "View User Profile",
            "view,profile,user,details",
            format!("View User Profile for {}", user.username),
        ),
        header_title: if public_view {
            user.username.clone()
        } else {
            "Your profile".to_owned()
        },
        is_admin: access.has_role("Admin"),
        // Laravel checked the viewed account's role here, so a moderator opening an admin's
        // profile saw that admin's API token. Only the owner and admins see these now.
        can_see_private: !public_view || signed_in.access.has_role("Admin"),
        public_view,
        gravatar: gravatar_url(&user.email),
        joined_date: user
            .created_at
            .map(|at| at.format("%b %d, %Y").to_string())
            .unwrap_or_default(),
        joined_ago: user
            .created_at
            .map(|at| relative(at, now))
            .unwrap_or_default(),
        last_login: user
            .lastlogin
            .map(|at| relative(as_utc(at), now))
            .unwrap_or_else(|| relative(now, now)),
        role_name,
        role_expiry,
        pending_role,
        invited_by,
        timezone_display: zone.replace('_', " "),
        current_time: local_time(&zone, now),
        grabs_today,
        download_remaining: download_limit - grabs_today,
        download_percentage,
        download_color,
        api_requests,
        api_limit,
        api_remaining: api_limit - api_requests,
        api_percentage,
        api_color,
        user,
    };
    Ok(Html(page.render()?).into_response())
}

struct TimezoneGroup {
    region: &'static str,
    zones: Vec<&'static str>,
}

struct SubcategoryChoice {
    id: i64,
    title: String,
}

struct CategoryChoice {
    title: String,
    subs: Vec<SubcategoryChoice>,
}

#[derive(Template)]
#[template(path = "profile/edit.html")]
struct EditPage {
    layout: Layout,
    user: User,
    access: Access,
    error: String,
    success_2fa: Option<String>,
    email_value: String,
    theme_value: String,
    scheme_value: String,
    timezone: String,
    current_time: String,
    timezones: Vec<TimezoneGroup>,
    categories: Vec<CategoryChoice>,
    excluded: HashSet<i64>,
    two_factor_enabled: bool,
    two_factor_pending: bool,
    qr_svg: String,
    secret: String,
    passkeys_json: String,
}

fn timezone_groups() -> Vec<TimezoneGroup> {
    TIMEZONE_REGIONS
        .iter()
        .map(|region| {
            let prefix = format!("{region}/");
            TimezoneGroup {
                region,
                zones: chrono_tz::TZ_VARIANTS
                    .iter()
                    .map(|zone| zone.name())
                    .filter(|name| name.starts_with(&prefix))
                    .collect(),
            }
        })
        .collect()
}

fn valid_timezone(zone: &str) -> bool {
    zone == "UTC"
        || (TIMEZONE_REGIONS
            .iter()
            .any(|region| zone.starts_with(&format!("{region}/")))
            && zone.parse::<chrono_tz::Tz>().is_ok())
}

/// `^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$`
fn password_format_ok(password: &str) -> bool {
    password.chars().count() >= 8
        && password.chars().any(|c| c.is_ascii_uppercase())
        && password.chars().any(|c| c.is_ascii_lowercase())
        && password.chars().any(|c| c.is_ascii_digit())
        && password.chars().any(|c| "#?!@$%^&*-".contains(c))
}

/// `profileValidationRules`, as the one string Laravel joined them into.
async fn profile_errors(ctx: &Ctx, input: &Input, user: &User) -> Result<String> {
    let mut errors = String::new();
    if let Some(email) = input.get("email") {
        if !validate::valid_email(&email) {
            errors.push_str("The email field must be a valid email address.");
        } else if email.chars().count() > 255 {
            errors.push_str("The email field must not be greater than 255 characters.");
        } else {
            let taken: i64 =
                sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?")
                    .bind(&email)
                    .bind(user.id)
                    .fetch_one(&ctx.db)
                    .await?;
            if taken > 0 {
                errors.push_str("The email has already been taken.");
            } else if let Some(message) = validate::disposable_error(&email) {
                errors.push_str(message);
            } else if let Some((_, domain)) = email.rsplit_once('@')
                && !validate::domain_has_mail_records(domain).await
            {
                errors.push_str("The email domain does not appear to be valid or reachable.");
            }
        }
    }
    let password = input.str("password");
    let current = input.str("current_password");
    if !password.is_empty() && current.is_empty() {
        errors.push_str("The current password field is required when password is present.");
    } else if !current.is_empty() && !verify_password(&current, &user.password) {
        errors.push_str("The password is incorrect.");
    }
    if !password.is_empty() {
        if password.chars().count() < 8 {
            errors.push_str("The password field must be at least 8 characters.");
        }
        if password != input.str("password_confirmation") {
            errors.push_str("The password field confirmation does not match.");
        }
        if !password_format_ok(&password) {
            errors.push_str("The password field format is invalid.");
        }
    }
    Ok(errors)
}

/// What the save does besides the columns: exclusions, direct view permissions, the password.
async fn save_profile(ctx: &Ctx, input: &Input, auth: &Auth, unverify: bool) -> Result<()> {
    let user = &auth.user;
    let on = |name: &str| i32::from(input.has(name));
    let email = input.get("email");
    let theme = input
        .get("theme_preference")
        .filter(|value| THEMES.contains(&value.as_str()));
    let scheme = input
        .get("color_scheme")
        .filter(|value| SCHEMES.contains(&value.as_str()));
    let timezone = input.get("timezone").filter(|value| valid_timezone(value));
    let mut tx = ctx.db.begin().await?;
    sqlx::query(
        "UPDATE users SET movieview = ?, musicview = ?, gameview = ?, consoleview = ?, bookview = ?, style = 'None',
                email = COALESCE(?, email), theme_preference = COALESCE(?, theme_preference),
                color_scheme = COALESCE(?, color_scheme), timezone = COALESCE(?, timezone), updated_at = NOW()
         WHERE id = ?",
    )
    .bind(on("movieview"))
    .bind(on("musicview"))
    .bind(on("gameview"))
    .bind(on("consoleview"))
    .bind(on("bookview"))
    .bind(&email)
    .bind(&theme)
    .bind(&scheme)
    .bind(&timezone)
    .bind(user.id)
    .execute(&mut *tx)
    .await?;
    if unverify {
        sqlx::query("UPDATE users SET email_verified_at = NULL, verified = 0, verification_token = NULL WHERE id = ?")
            .bind(user.id)
            .execute(&mut *tx)
            .await?;
    }
    sqlx::query("DELETE FROM user_excluded_categories WHERE users_id = ?")
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    for category in input.list("excluded_categories") {
        if let Ok(category) = category.parse::<i64>() {
            sqlx::query("INSERT INTO user_excluded_categories (users_id, categories_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())")
                .bind(user.id)
                .bind(category)
                .execute(&mut *tx)
                .await?;
        }
    }
    for (field, permission) in VIEW_PERMISSIONS {
        let direct = auth.access.has_direct_permission(permission);
        if input.has(field) && !direct {
            sqlx::query(
                "INSERT INTO model_has_permissions (permission_id, model_type, model_id)
                 SELECT id, ?, ? FROM permissions WHERE name = ?",
            )
            .bind(USER_MODEL)
            .bind(user.id)
            .bind(permission)
            .execute(&mut *tx)
            .await?;
        } else if !input.has(field) && direct {
            sqlx::query(
                "DELETE m FROM model_has_permissions m JOIN permissions p ON p.id = m.permission_id
                 WHERE m.model_type = ? AND m.model_id = ? AND p.name = ?",
            )
            .bind(USER_MODEL)
            .bind(user.id)
            .bind(permission)
            .execute(&mut *tx)
            .await?;
        }
    }
    if let Some(password) = input.get("password") {
        sqlx::query("UPDATE users SET password = ? WHERE id = ?")
            .bind(auth::hash_password(&password)?)
            .bind(user.id)
            .execute(&mut *tx)
            .await?;
    }
    tx.commit().await?;
    Ok(())
}

async fn two_factor_row(ctx: &Ctx, user_id: i64) -> Result<Option<(bool, String)>> {
    let row: Option<(i8, Option<String>)> = sqlx::query_as("SELECT google2fa_enable, google2fa_secret FROM password_securities WHERE user_id = ? LIMIT 1")
        .bind(user_id)
        .fetch_optional(&ctx.db)
        .await?;
    Ok(row.map(|(enabled, secret)| (enabled != 0, secret.unwrap_or_default())))
}

/// The QR code authenticator apps scan, as inline SVG.
fn qr_svg(email: &str, secret: &str) -> String {
    let app = config::app_name();
    let uri = format!(
        "otpauth://totp/{}:{}?secret={secret}&issuer={}&algorithm=SHA1&digits=6&period=30",
        config::encode_component(&app),
        config::encode_component(email),
        config::encode_component(&app),
    );
    qrcode::QrCode::new(uri.as_bytes())
        .map(|code| {
            code.render::<qrcode::render::svg::Color<'_>>()
                .min_dimensions(200, 200)
                .build()
        })
        .unwrap_or_default()
}

async fn load_categories(ctx: &Ctx) -> Result<Vec<CategoryChoice>> {
    let roots: Vec<(i64, String)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), title FROM root_categories WHERE status = 1 ORDER BY title",
    )
    .fetch_all(&ctx.db)
    .await?;
    let subs: Vec<(i64, i64, String)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), CAST(root_categories_id AS SIGNED), title FROM categories
         WHERE status = 1 AND root_categories_id IS NOT NULL ORDER BY title",
    )
    .fetch_all(&ctx.db)
    .await?;
    Ok(roots
        .into_iter()
        .map(|(id, title)| CategoryChoice {
            title,
            subs: subs
                .iter()
                .filter(|(_, root, _)| *root == id)
                .map(|(id, _, title)| SubcategoryChoice {
                    id: *id,
                    title: title.clone(),
                })
                .collect(),
        })
        .collect())
}

async fn passkeys_json(ctx: &Ctx, user_id: i64) -> Result<String> {
    type Row = (i64, String, Option<DateTime<Utc>>, Option<DateTime<Utc>>);
    let rows: Vec<Row> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), name, created_at, last_used_at FROM passkeys WHERE authenticatable_id = ? ORDER BY id",
    )
    .bind(user_id)
    .fetch_all(&ctx.db)
    .await?;
    let items: Vec<_> = rows
        .into_iter()
        .map(|(id, name, created, used)| {
            json!({
                "id": id,
                "name": name,
                "created_at": created.map(|at| at.to_rfc3339()),
                "last_used_at": used.map(|at| at.to_rfc3339()),
            })
        })
        .collect();
    Ok(serde_json::to_string(&items)?)
}

async fn edit(
    State(ctx): State<Ctx>,
    layout: Layout,
    session: Session,
    input: Input,
    method: axum::http::Method,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let user = &signed_in.user;
    let action = if method == axum::http::Method::POST {
        input.get("action").unwrap_or_else(|| "view".to_owned())
    } else {
        "view".to_owned()
    };
    let mut error = String::new();
    match action.as_str() {
        "newapikey" => {
            sqlx::query("UPDATE users SET api_token = ?, updated_at = NOW() WHERE id = ?")
                .bind(hex::encode(Md5::digest(random_string(60).as_bytes())))
                .bind(user.id)
                .execute(&ctx.db)
                .await?;
            return Ok(Redirect::to("/profile").into_response());
        }
        "clearcookies" => return Ok(Redirect::to("/profileedit").into_response()),
        "submit" => {
            error = profile_errors(&ctx, &input, user).await?;
            if error.is_empty() {
                let new_email = input
                    .get("email")
                    .filter(|email| *email != user.email && !signed_in.access.has_role("Admin"));
                save_profile(&ctx, &input, &signed_in, new_email.is_some()).await?;
                if let Some(email) = new_email {
                    send_verification(&ctx, user.id, &email, &user.username).await?;
                    auth::logout(&session);
                    session.flash(
                        "info",
                        "You will be able to login after you verify your new email address",
                    );
                    return Ok(Redirect::to("/login").into_response());
                }
                session.flash("success", "Profile changes saved");
                return Ok(Redirect::to("/profile").into_response());
            }
        }
        _ => {}
    }

    let row = two_factor_row(&ctx, user.id).await?;
    let (two_factor_enabled, two_factor_pending, secret) = match row {
        Some((true, _)) => (true, false, String::new()),
        Some((false, secret)) => (false, true, secret),
        None => (false, false, String::new()),
    };
    let excluded: Vec<i64> = sqlx::query_scalar(
        "SELECT CAST(categories_id AS SIGNED) FROM user_excluded_categories WHERE users_id = ?",
    )
    .bind(user.id)
    .fetch_all(&ctx.db)
    .await?;
    if let Some(message) = session.flashed_str("error_2fa") {
        error = message;
    }
    let old_email = session.old("email");
    let timezone = user
        .timezone
        .clone()
        .filter(|zone| !zone.is_empty())
        .unwrap_or_else(|| "UTC".to_owned());
    let zone: chrono_tz::Tz = timezone.parse().unwrap_or(chrono_tz::UTC);
    let page = EditPage {
        layout: layout.titled(
            "Edit User Profile",
            "edit,profile,user,details",
            format!("Edit User Profile for {}", user.username),
        ),
        access: (*signed_in.access).clone(),
        error,
        success_2fa: session.flashed_str("success_2fa"),
        email_value: if old_email.is_empty() {
            input.get("email").unwrap_or_else(|| user.email.clone())
        } else {
            old_email
        },
        theme_value: user.theme_preference.clone(),
        scheme_value: user.color_scheme.clone(),
        current_time: Utc::now()
            .with_timezone(&zone)
            .format("%Y-%m-%d %H:%M:%S %Z")
            .to_string(),
        timezone,
        timezones: timezone_groups(),
        categories: load_categories(&ctx).await?,
        excluded: excluded.into_iter().collect(),
        two_factor_enabled,
        two_factor_pending,
        qr_svg: if two_factor_pending {
            qr_svg(&user.email, &secret)
        } else {
            String::new()
        },
        secret,
        passkeys_json: passkeys_json(&ctx, user.id).await?,
        user: (**user).clone(),
    };
    Ok(Html(page.render()?).into_response())
}

async fn update_theme(
    State(ctx): State<Ctx>,
    input: Input,
    MaybeUser(signed_in): MaybeUser,
) -> WebResult {
    let Some(signed_in) = signed_in else {
        return Ok((
            StatusCode::UNAUTHORIZED,
            Json(json!({"success": false, "message": "User not authenticated"})),
        )
            .into_response());
    };
    let theme = input.get("theme_preference");
    if theme
        .as_ref()
        .is_some_and(|value| !THEMES.contains(&value.as_str()))
    {
        return Ok(invalid(
            "theme_preference",
            "The selected theme preference is invalid.",
        ));
    }
    let scheme = input.get("color_scheme");
    if scheme
        .as_ref()
        .is_some_and(|value| !SCHEMES.contains(&value.as_str()))
    {
        return Ok(invalid(
            "color_scheme",
            "The selected color scheme is invalid.",
        ));
    }
    sqlx::query("UPDATE users SET theme_preference = COALESCE(?, theme_preference), color_scheme = COALESCE(?, color_scheme), updated_at = NOW() WHERE id = ?")
        .bind(&theme)
        .bind(&scheme)
        .bind(signed_in.user.id)
        .execute(&ctx.db)
        .await?;
    Ok(Json(json!({
        "success": true,
        "theme_preference": theme.unwrap_or_else(|| signed_in.user.theme_preference.clone()),
        "color_scheme": scheme.unwrap_or_else(|| signed_in.user.color_scheme.clone()),
    }))
    .into_response())
}

fn invalid(field: &str, message: &str) -> Response {
    (
        StatusCode::UNPROCESSABLE_ENTITY,
        Json(json!({"message": message, "errors": {field: [message]}})),
    )
        .into_response()
}

#[derive(Template)]
#[template(path = "auth/two_factor.html")]
struct TwoFactorPage {
    layout: GuestLayout,
    has_row: bool,
    enabled: bool,
    qr_svg: String,
}

async fn two_factor_form(
    State(ctx): State<Ctx>,
    layout: GuestLayout,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let row = two_factor_row(&ctx, signed_in.user.id).await?;
    let page = TwoFactorPage {
        layout,
        has_row: row.is_some(),
        enabled: row.as_ref().is_some_and(|(enabled, _)| *enabled),
        qr_svg: row
            .as_ref()
            .map(|(_, secret)| qr_svg(&signed_in.user.email, secret))
            .unwrap_or_default(),
    };
    Ok(Html(page.render()?).into_response())
}

const BASE32: &[u8; 32] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

/// Google2FA's default: 16 base32 characters.
fn new_secret() -> String {
    let mut rng = rand::rng();
    (0..16)
        .map(|_| BASE32[rng.random_range(0..32)] as char)
        .collect()
}

const TO_SECURITY: &str = "/profileedit#security";

fn security_flash(session: &Session, key: &str, message: &str) -> Response {
    session.flash(key, message);
    Redirect::to(TO_SECURITY).into_response()
}

async fn generate_secret(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    // A second click must not replace the secret an authenticator already scanned, or
    // create the duplicate row Laravel could.
    if two_factor_row(&ctx, signed_in.user.id).await?.is_none() {
        sqlx::query("INSERT INTO password_securities (user_id, google2fa_enable, google2fa_secret, created_at, updated_at) VALUES (?, 0, ?, NOW(), NOW())")
            .bind(signed_in.user.id)
            .bind(new_secret())
            .execute(&ctx.db)
            .await?;
    }
    let from_profile = input.has("from_profile")
        || headers
            .get(header::REFERER)
            .and_then(|value| value.to_str().ok())
            .is_some_and(|referer| referer.contains("profileedit"));
    if from_profile {
        return Ok(security_flash(
            &session,
            "success_2fa",
            "Secret Key is generated, Please scan the QR code and verify to Enable 2FA",
        ));
    }
    session.flash(
        "success",
        "Secret Key is generated, Please verify Code to Enable 2FA",
    );
    Ok(Redirect::to("/2fa").into_response())
}

async fn enable_two_factor(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    // Only a setup can be enabled. Answering codes for a live secret here would be a second,
    // cheaper way past the challenge.
    let Some((false, secret)) = two_factor_row(&ctx, signed_in.user.id).await? else {
        return Ok(security_flash(
            &session,
            "error_2fa",
            "Invalid Verification Code, Please try again.",
        ));
    };
    let now = u64::try_from(Utc::now().timestamp()).unwrap_or(0);
    if !verify_code(&secret, &input.str("verify-code"), now) {
        return Ok(security_flash(
            &session,
            "error_2fa",
            "Invalid Verification Code, Please try again.",
        ));
    }
    sqlx::query(
        "UPDATE password_securities SET google2fa_enable = 1, updated_at = NOW() WHERE user_id = ?",
    )
    .bind(signed_in.user.id)
    .execute(&ctx.db)
    .await?;
    // Turning it on from this browser proves the code here, so it shouldn't challenge at once.
    auth::mark_two_factor_passed(&session);
    Ok(security_flash(
        &session,
        "success_2fa",
        "2FA is Enabled Successfully.",
    ))
}

async fn cancel_two_factor(
    State(ctx): State<Ctx>,
    session: Session,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    if matches!(
        two_factor_row(&ctx, signed_in.user.id).await?,
        Some((false, _))
    ) {
        sqlx::query("DELETE FROM password_securities WHERE user_id = ?")
            .bind(signed_in.user.id)
            .execute(&ctx.db)
            .await?;
        return Ok(security_flash(
            &session,
            "success_2fa",
            "2FA setup has been cancelled.",
        ));
    }
    Ok(security_flash(
        &session,
        "error_2fa",
        "Unable to cancel 2FA setup.",
    ))
}

const WRONG_PASSWORD: &str =
    "Your password does not match with your account password. Please try again.";

/// `/disable2fa`: removes the setup entirely.
async fn delete_two_factor(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let password = input.str("current-password");
    if password.is_empty() {
        session.flash_errors(&[(
            "current-password",
            "The current-password field is required.".to_owned(),
        )]);
        return Ok(crate::auth::back(&headers, TO_SECURITY));
    }
    if !verify_password(&password, &signed_in.user.password) {
        return Ok(security_flash(&session, "error_2fa", WRONG_PASSWORD));
    }
    sqlx::query("DELETE FROM password_securities WHERE user_id = ?")
        .bind(signed_in.user.id)
        .execute(&ctx.db)
        .await?;
    Ok(security_flash(
        &session,
        "success_2fa",
        "2FA is now Disabled.",
    ))
}

/// `/profile-disable2fa`: keeps the secret, switched off.
async fn switch_off_two_factor(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let password = input.str("current-password");
    if password.is_empty() {
        session.flash_errors(&[(
            "current-password",
            "The current-password field is required.".to_owned(),
        )]);
        return Ok(crate::auth::back(&headers, TO_SECURITY));
    }
    if !verify_password(&password, &signed_in.user.password) {
        return Ok(security_flash(&session, "error_2fa", WRONG_PASSWORD));
    }
    sqlx::query(
        "UPDATE password_securities SET google2fa_enable = 0, updated_at = NOW() WHERE user_id = ?",
    )
    .bind(signed_in.user.id)
    .execute(&ctx.db)
    .await?;
    Ok(security_flash(
        &session,
        "success_2fa",
        "2FA is now Disabled.",
    ))
}

async fn security_disable_two_factor(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let json_wanted = guard::expects_json(&headers) || headers.contains_key("x-requested-with");
    let answer = |success: bool, message: &str| -> Response {
        if json_wanted {
            return Json(json!({"success": success, "message": message})).into_response();
        }
        security_flash(
            &session,
            if success { "success_2fa" } else { "error_2fa" },
            message,
        )
    };
    let password = input.str("current_password");
    if password.is_empty() {
        return Ok(invalid(
            "current_password",
            "The current password field is required.",
        ));
    }
    if !verify_password(&password, &signed_in.user.password) {
        return Ok(answer(
            false,
            "Your password does not match. Please try again.",
        ));
    }
    let done = sqlx::query(
        "UPDATE password_securities SET google2fa_enable = 0, updated_at = NOW() WHERE user_id = ?",
    )
    .bind(signed_in.user.id)
    .execute(&ctx.db)
    .await?;
    if done.rows_affected() == 0 && two_factor_row(&ctx, signed_in.user.id).await?.is_none() {
        return Ok(answer(false, "No 2FA configuration found for this user."));
    }
    Ok(answer(true, "2FA has been successfully disabled."))
}

#[cfg(test)]
mod tests {
    use chrono::Duration;

    use super::*;

    #[test]
    fn password_rule_matches_the_laravel_pattern() {
        assert!(password_format_ok("Abcdef1!"));
        assert!(!password_format_ok("abcdef1!"), "no capital");
        assert!(!password_format_ok("Abcdefg!"), "no digit");
        assert!(!password_format_ok("Abcdefg1"), "no symbol from the list");
        assert!(!password_format_ok("Abcdef1+"), "plus isn't in PHP's list");
        assert!(!password_format_ok("Ab1!"), "too short");
    }

    #[test]
    fn timezones_keep_to_the_listed_regions() {
        assert!(valid_timezone("UTC"));
        assert!(valid_timezone("Europe/Berlin"));
        assert!(!valid_timezone("Europe/Nowhere"));
        assert!(
            !valid_timezone("Etc/GMT"),
            "PHP's region lists leave this out"
        );
    }

    #[test]
    fn usage_bars_warm_up() {
        assert_eq!(usage_bar(10, 100, "calm"), (10, "calm"));
        assert_eq!(usage_bar(70, 100, "calm"), (70, "bg-yellow-500"));
        assert_eq!(usage_bar(95, 100, "calm"), (95, "bg-red-600"));
        assert_eq!(usage_bar(500, 100, "calm"), (100, "bg-red-600"));
        assert_eq!(usage_bar(5, 0, "calm"), (0, "calm"));
    }

    #[test]
    fn relative_times_read_both_ways() {
        let now = Utc::now();
        assert_eq!(relative(now - Duration::days(2), now), "2 days ago");
        assert_eq!(relative(now + Duration::hours(3), now), "3 hours from now");
    }

    #[test]
    fn secrets_and_qr_codes_work_together() {
        let secret = new_secret();
        assert_eq!(secret.len(), 16);
        assert!(qr_svg("a@example.com", &secret).contains("<svg"));
    }
}
