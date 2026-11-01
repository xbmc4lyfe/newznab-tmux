//! `nntmux-ctl init`: sets up a new deployment on an empty database (`nntmux:deploy-init`).
//!
//! It never replaces data. A database with any table or view is refused before anything is
//! written, and a success marker stops a second run. `nntmux:install`, which wiped the database
//! with `migrate:fresh`, has no port: this is the only way to initialize.

use std::fs::{self, File, TryLockError};
use std::path::{Path, PathBuf};

use anyhow::{Context, Result, bail, ensure};
use base64::Engine;
use nntmux_core::{optional, var_or};
use nntmux_runtime::Ctx;
use nntmux_search::SearchClient;
use sqlx::MySqlPool;

/// `roles.id` of the Admin role in the seed.
const ADMIN_ROLE: i32 = 2;
/// `Invitation::DEFAULT_INVITES`, which `User::add` gave the administrator.
const ADMIN_INVITES: i32 = 1;
/// Laravel's `AES-256-CBC` takes a 32-byte key.
const APP_KEY_BYTES: usize = 32;
const MIN_ADMIN_PASSWORD: usize = 12;

/// What `init` needs from the environment, checked before anything is touched.
#[derive(Debug)]
pub struct Settings {
    pub admin: Admin,
    pub storage: PathBuf,
    pub directories: Vec<PathBuf>,
}

#[derive(Debug)]
pub struct Admin {
    pub username: String,
    pub email: String,
    pub password: String,
}

impl Settings {
    pub fn from_env() -> Result<Self> {
        validate_app_key(optional("APP_KEY").as_deref())?;
        let admin = Admin::new(
            optional("ADMIN_USER"),
            optional("ADMIN_EMAIL"),
            optional("ADMIN_PASS"),
        )?;
        let storage = PathBuf::from(var_or("NNTMUX_STORAGE_DIR", "storage"));
        let path = |key: &str, default: &str| {
            optional(key).map_or_else(|| storage.join(default), PathBuf::from)
        };
        let directories = vec![
            storage.clone(),
            path("PATH_TO_NZBS", "nzb"),
            path("COVERS_PATH", "covers"),
            path("TEMP_UNRAR_PATH", "tmp/unrar"),
        ];
        Ok(Self {
            admin,
            storage,
            directories,
        })
    }

    fn marker(&self) -> PathBuf {
        self.storage.join("install/install.lock")
    }
}

impl Admin {
    pub fn new(
        username: Option<String>,
        email: Option<String>,
        password: Option<String>,
    ) -> Result<Self> {
        const MESSAGE: &str =
            "configure ADMIN_USER, ADMIN_EMAIL, and an ADMIN_PASS of at least 12 characters";
        let username = username.filter(|name| !name.trim().is_empty());
        let email = email.filter(|email| valid_email(email));
        let password = password.filter(|password| password.len() >= MIN_ADMIN_PASSWORD);
        match (username, email, password) {
            (Some(username), Some(email), Some(password)) => Ok(Self {
                username,
                email,
                password,
            }),
            _ => bail!(MESSAGE),
        }
    }
}

/// Accepts what Laravel's `encrypt` would: `base64:` and 32 decoded bytes, or 32 raw bytes.
pub fn validate_app_key(key: Option<&str>) -> Result<()> {
    let valid = key.is_some_and(|key| match key.strip_prefix("base64:") {
        Some(encoded) => base64::engine::general_purpose::STANDARD
            .decode(encoded)
            .is_ok_and(|bytes| bytes.len() == APP_KEY_BYTES),
        None => key.len() == APP_KEY_BYTES,
    });
    ensure!(
        valid,
        "a valid APP_KEY must be supplied before initialization"
    );
    Ok(())
}

/// A plain shape check, as `FILTER_VALIDATE_EMAIL` would reject anything without one.
fn valid_email(email: &str) -> bool {
    let Some((local, domain)) = email.rsplit_once('@') else {
        return false;
    };
    !local.is_empty()
        && !email.chars().any(char::is_whitespace)
        && domain.contains('.')
        && !domain.starts_with('.')
        && !domain.ends_with('.')
        && !domain.contains("..")
}

pub async fn run(ctx: &Ctx) -> Result<()> {
    let settings = Settings::from_env()?;
    let search = SearchClient::from_ctx(ctx)?;
    initialize(&ctx.db, &search, &settings).await?;
    println!("Deployment initialized. The supplied application key was preserved.");
    Ok(())
}

pub async fn initialize(db: &MySqlPool, search: &SearchClient, settings: &Settings) -> Result<()> {
    let marker = settings.marker();
    let install_dir = marker.parent().expect("the marker has a parent");
    fs::create_dir_all(install_dir)
        .with_context(|| format!("creating {}", install_dir.display()))?;
    let lock = File::create(install_dir.join("deploy-init.lock"))?;
    match lock.try_lock() {
        Ok(()) => {}
        Err(TryLockError::WouldBlock) => bail!("another initialization is running"),
        Err(TryLockError::Error(error)) => return Err(error.into()),
    }
    ensure!(
        !marker.exists(),
        "this installation is already initialized ({} exists)",
        marker.display()
    );

    let objects: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()",
    )
    .fetch_one(db)
    .await?;
    ensure!(
        objects == 0,
        "initialization requires an empty database; {objects} existing tables or views will not be changed"
    );

    for directory in &settings.directories {
        prepare_directory(directory)?;
    }

    nntmux_db::migrate::run(db)
        .await
        .context("migration or seeding failed; initialization remains incomplete")?;

    create_admin(db, &settings.admin).await?;
    verify_admin(db, &settings.admin.username).await?;

    for line in search
        .create_indexes(&[], false)
        .await
        .context("search initialization failed; initialization remains incomplete")?
    {
        println!("{line}");
    }

    fs::write(
        &marker,
        format!(
            "deployment initialized on {}\n",
            chrono::Utc::now().to_rfc3339()
        ),
    )
    .context("unable to record successful initialization")?;
    Ok(())
}

fn prepare_directory(directory: &Path) -> Result<()> {
    fs::create_dir_all(directory)
        .with_context(|| format!("creating storage path {}", directory.display()))?;
    // Writability is what matters, and only a real write proves it under any ACL or mount.
    let probe = directory.join(".nntmux-init-probe");
    fs::write(&probe, b"")
        .with_context(|| format!("storage path {} must be writable", directory.display()))?;
    fs::remove_file(&probe)?;
    Ok(())
}

async fn create_admin(db: &MySqlPool, admin: &Admin) -> Result<()> {
    let hash = nntmux_webkit::auth::hash_password(&admin.password)?;
    let mut tx = db.begin().await?;
    let id = nntmux_webkit::user::create_user(
        &mut tx,
        &admin.username,
        &admin.email,
        &hash,
        "",
        ADMIN_ROLE,
        ADMIN_INVITES,
    )
    .await?;
    sqlx::query("UPDATE users SET email_verified_at = NOW() WHERE id = ?")
        .bind(id)
        .execute(&mut *tx)
        .await?;
    tx.commit().await?;
    Ok(())
}

async fn verify_admin(db: &MySqlPool, username: &str) -> Result<()> {
    let admin: Option<(i32, bool)> = sqlx::query_as(
        "SELECT roles_id, email_verified_at IS NOT NULL FROM users WHERE username = ?",
    )
    .bind(username)
    .fetch_optional(db)
    .await?;
    let configured: bool =
        sqlx::query_scalar("SELECT EXISTS(SELECT 1 FROM ingestion_configurations WHERE id = 1)")
            .fetch_one(db)
            .await?;
    ensure!(
        admin == Some((ADMIN_ROLE, true)) && configured,
        "administrator or configuration verification failed"
    );
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn app_key_matches_laravel_aes_256() {
        let encoded = base64::engine::general_purpose::STANDARD.encode([7u8; 32]);
        assert!(validate_app_key(Some(&format!("base64:{encoded}"))).is_ok());
        assert!(validate_app_key(Some(&"k".repeat(32))).is_ok());
        assert!(validate_app_key(None).is_err());
        assert!(validate_app_key(Some("base64:not base64")).is_err());
        let short = base64::engine::general_purpose::STANDARD.encode([7u8; 16]);
        assert!(validate_app_key(Some(&format!("base64:{short}"))).is_err());
        assert!(validate_app_key(Some("short")).is_err());
    }

    #[test]
    fn admin_needs_every_field() {
        let some = |value: &str| Some(value.to_owned());
        assert!(Admin::new(some("admin"), some("a@b.example"), some("123456789012")).is_ok());
        assert!(Admin::new(some(" "), some("a@b.example"), some("123456789012")).is_err());
        assert!(Admin::new(some("admin"), some("not-an-email"), some("123456789012")).is_err());
        assert!(Admin::new(some("admin"), some("a@b.example"), some("12345678901")).is_err());
        assert!(Admin::new(None, some("a@b.example"), some("123456789012")).is_err());
    }

    #[test]
    fn email_shape() {
        for good in ["a@b.co", "first.last+tag@mail.example.org"] {
            assert!(valid_email(good), "{good}");
        }
        for bad in ["", "a@b", "@b.co", "a b@c.co", "a@.co", "a@b.", "a@b..co"] {
            assert!(!valid_email(bad), "{bad}");
        }
    }
}
