//! A migrated database with collections to archive.

use anyhow::Result;
use nntmux_testkit::TestDb;
use nntmux_verify::cache::Cache;
use nntmux_verify::config::VerificationConfig;
use nntmux_verify::context::Verify;
use rand::Rng;
use sqlx::MySqlPool;

pub struct Harness {
    db: TestDb,
    pub verify: Verify,
}

impl Harness {
    pub async fn start() -> Result<Option<Self>> {
        Self::start_with(|_| {}).await
    }

    pub async fn start_with(
        configure: impl FnOnce(&mut VerificationConfig),
    ) -> Result<Option<Self>> {
        let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
        else {
            eprintln!("TEST_DATABASE_URL or TEST_REDIS_URL is unset, skipping");
            return Ok(None);
        };
        let redis = redis::Client::open(redis_url)?
            .get_connection_manager()
            .await?;
        let prefix = format!("t_{:016x}:", rand::rng().random::<u64>());
        let mut config = VerificationConfig::for_tests(std::env::temp_dir().join(&prefix));
        configure(&mut config);
        sqlx::query(
            "INSERT INTO release_verification_state (id, pending_bytes, audit_cursor) VALUES (1, 0, 0) \
             ON DUPLICATE KEY UPDATE pending_bytes = 0",
        )
        .execute(&db.pool)
        .await?;
        // The seed data has this name under another id.
        sqlx::query("DELETE FROM usenet_groups WHERE name = 'alt.binaries.test' AND id <> 1")
            .execute(&db.pool)
            .await?;
        sqlx::query(
            "INSERT INTO usenet_groups (id, name, active, backfill) VALUES (1, 'alt.binaries.test', 1, 0) \
             ON DUPLICATE KEY UPDATE name = VALUES(name)",
        )
        .execute(&db.pool)
        .await?;
        let verify = Verify::new(db.pool.clone(), Cache::new(redis, prefix), config);
        Ok(Some(Self { db, verify }))
    }

    pub fn db(&self) -> MySqlPool {
        self.db.pool.clone()
    }

    /// The same harness under another configuration.
    pub fn with_config(&self, configure: impl FnOnce(&mut VerificationConfig)) -> Verify {
        let mut config = (*self.verify.config).clone();
        configure(&mut config);
        Verify::new(self.db.pool.clone(), self.verify.cache.clone(), config)
    }

    pub async fn finish(self) -> Result<()> {
        self.db.drop_database().await
    }

    pub fn source(&self, hash: &str) -> String {
        hash.repeat(20)
    }

    /// Adds a collection with these files: `(name, totalparts, part numbers)`.
    pub async fn collection(
        &self,
        hash: &str,
        totalfiles: u32,
        files: &[(&str, u32, &[u32])],
        filecheck: i8,
    ) -> Result<i64> {
        self.collection_with_ids(hash, totalfiles, "msg", files, filecheck)
            .await
    }

    /// As [`Self::collection`], with message IDs under another prefix.
    pub async fn collection_with_ids(
        &self,
        hash: &str,
        totalfiles: u32,
        id_prefix: &str,
        files: &[(&str, u32, &[u32])],
        filecheck: i8,
    ) -> Result<i64> {
        let collection = sqlx::query(
            "INSERT INTO collections (subject, fromname, date, xref, totalfiles, groups_id, collectionhash, \
             dateadded, last_seen_at, filecheck, filesize) \
             VALUES ('Some Subject', 'poster@example.com', '2026-10-31 12:00:00', '', ?, 1, ?, UTC_TIMESTAMP(), \
             UTC_TIMESTAMP(), ?, 100)",
        )
        .bind(totalfiles)
        .bind(hex::decode(self.source(hash))?)
        .bind(filecheck)
        .execute(&self.db.pool)
        .await?
        .last_insert_id();
        for (name, totalparts, parts) in files {
            let binary = sqlx::query(
                "INSERT INTO binaries (binaryhash, name, collections_id, totalparts, currentparts, partsize) \
                 VALUES (UNHEX(MD5(?)), ?, ?, ?, ?, 100)",
            )
            .bind(name)
            .bind(format!("{name}.rar"))
            .bind(collection)
            .bind(totalparts)
            .bind(parts.len() as u32)
            .execute(&self.db.pool)
            .await?
            .last_insert_id();
            for part in *parts {
                sqlx::query(
                    "INSERT INTO parts (binaries_id, messageid, number, partnumber, size) VALUES (?, ?, 1, ?, 100)",
                )
                .bind(binary)
                .bind(format!("{id_prefix}-{hash}-{name}-{part}@example.com"))
                .bind(part)
                .execute(&self.db.pool)
                .await?;
            }
        }
        Ok(collection as i64)
    }

    /// Publishes a candidate as a partial release that keeps its inventory.
    pub async fn published_partial(&self, candidate: u64, path: &str) -> Result<u64> {
        let release = sqlx::query(
            "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, adddate, postdate, \
             nzbstatus, articles_found, articles_expected, late_article_candidate_id) \
             VALUES ('n', 's', 1, 'd0d0d0d0-0000-0000-0000-000000000001', 'd', 100, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, 1, 2, ?)",
        )
        .bind(candidate)
        .execute(&self.db.pool)
        .await?
        .last_insert_id();
        sqlx::query(
            "UPDATE release_verification_candidates SET status = 'sampled', release_id = ?, verified_at = UTC_TIMESTAMP(), \
             retained_for_release_id = ?, retained_last_unique_at = last_unique_at, manifest_path = ? WHERE id = ?",
        )
        .bind(release)
        .bind(release)
        .bind(path)
        .bind(candidate)
        .execute(&self.db.pool)
        .await?;
        Ok(release)
    }

    pub async fn count(&self, table: &str) -> Result<i64> {
        Ok(
            sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}")))
                .fetch_one(&self.db.pool)
                .await?,
        )
    }

    pub async fn pending_bytes(&self) -> Result<u64> {
        Ok(
            sqlx::query_scalar("SELECT pending_bytes FROM release_verification_state WHERE id = 1")
                .fetch_one(&self.db.pool)
                .await?,
        )
    }
}
