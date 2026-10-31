//! Runs against a real, disposable MariaDB, and only when one is named:
//! `INDEXER_IMPORT_TEST_MARIADB=host:port` with `INDEXER_IMPORT_TEST_ROOT_PASSWORD`.
//! Each test makes its own database. Never point this at a database you care about.

use std::io::Write;
use std::sync::atomic::{AtomicU32, Ordering};

use flate2::{Compression, write::GzEncoder};
use indexer_config::Secret;
use indexer_import::{
    Credentials, ImportOptions, ImportReport, Source, SourceConfig, Tls, VerifyReport, run, verify,
};
use indexer_store::{Catalog, NzbStore, StorePaths};
use mysql_async::prelude::Queryable;
use mysql_async::{Conn, OptsBuilder};
use sha2::{Digest, Sha256};
use tempfile::TempDir;

const MIN_SIZE: u64 = 500_000_000;
const RO_USER: &str = "import_test_ro";
const RO_PASSWORD: &str = "import-test-only";
static NEXT_DB: AtomicU32 = AtomicU32::new(0);

struct Fixture {
    host: String,
    port: u16,
    database: String,
    admin: Conn,
    source: Source,
    dir: TempDir,
}

fn gzip(data: &[u8]) -> Vec<u8> {
    let mut encoder = GzEncoder::new(Vec::new(), Compression::fast());
    encoder.write_all(data).unwrap();
    encoder.finish().unwrap()
}

fn digest(xml: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(b"nntmux:nzb-archive-naming:1\0");
    hasher.update(xml);
    hex::encode(hasher.finalize())
}

fn xml(release: u32) -> Vec<u8> {
    format!(
        "<nzb><file release=\"{release}\"/>{}</nzb>",
        "<segment/>".repeat(1000)
    )
    .into_bytes()
}

impl Fixture {
    /// None when no test server is named, so a plain `cargo test` skips these.
    async fn start() -> Option<Self> {
        let target = std::env::var("INDEXER_IMPORT_TEST_MARIADB").ok()?;
        let root = std::env::var("INDEXER_IMPORT_TEST_ROOT_PASSWORD").unwrap();
        let (host, port) = target.split_once(':').unwrap();
        let port: u16 = port.parse().unwrap();
        let database = format!(
            "import_test_{}_{}",
            std::process::id(),
            NEXT_DB.fetch_add(1, Ordering::Relaxed)
        );
        let opts = OptsBuilder::default()
            .ip_or_hostname(host.to_owned())
            .tcp_port(port)
            .user(Some("root"))
            .pass(Some(root));
        let mut admin = Conn::new(opts).await.unwrap();
        admin
            .query_drop(format!(
                "CREATE DATABASE {database} CHARACTER SET utf8mb4;
                 CREATE USER IF NOT EXISTS '{RO_USER}'@'%' IDENTIFIED BY '{RO_PASSWORD}';
                 GRANT SELECT ON {database}.* TO '{RO_USER}'@'%';
                 USE {database};
                 {}",
                include_str!("fixture.sql")
            ))
            .await
            .unwrap();
        // Multi-statement results must be drained before the next query.
        admin.query_drop("SELECT 1").await.unwrap();

        for release in [1_u32, 2, 3, 5, 7, 8] {
            let xml = xml(release);
            let stored_digest = if release == 3 {
                "0".repeat(64)
            } else {
                digest(&xml)
            };
            admin
                .exec_drop(
                    "INSERT INTO release_nzb_contents
                     (guid, releases_id, body, source_digest, generation, decoded_bytes)
                     VALUES (?, ?, ?, ?, 'g', ?)",
                    (
                        format!("guid-{release}"),
                        release,
                        gzip(&xml),
                        stored_digest,
                        xml.len(),
                    ),
                )
                .await
                .unwrap();
        }

        let source = source_for(host, port, &database, RO_USER, RO_PASSWORD);
        Some(Self {
            host: host.to_owned(),
            port,
            database,
            admin,
            source,
            dir: tempfile::tempdir().unwrap(),
        })
    }

    fn options(&self, limit: Option<u64>) -> ImportOptions {
        ImportOptions {
            data_dir: self.dir.path().to_owned(),
            min_size: MIN_SIZE,
            batch_rows: 2,
            batch_decoded_bytes: 1_000_000,
            concurrency: 3,
            limit,
        }
    }

    async fn import(&self) -> ImportReport {
        run(&self.source, &self.options(None)).await.unwrap()
    }

    async fn check(&self) -> VerifyReport {
        verify(&self.source, self.dir.path(), 2, 1_000_000, 3)
            .await
            .unwrap()
    }

    fn paths(&self) -> StorePaths {
        StorePaths::under(self.dir.path())
    }

    fn catalog(&self) -> Catalog {
        Catalog::open(&self.paths().catalog).unwrap()
    }

    async fn checksums(&mut self) -> Vec<(String, Option<u64>)> {
        let tables = [
            "releases",
            "predb",
            "release_nzb_contents",
            "release_files",
            "release_nfos",
            "usenet_groups",
        ];
        let sql = format!("CHECKSUM TABLE {}", tables.join(", "));
        self.admin.query(sql).await.unwrap()
    }

    async fn finish(mut self) {
        self.source.clone().disconnect().await.unwrap();
        self.admin
            .query_drop(format!("DROP DATABASE {}", self.database))
            .await
            .unwrap();
    }
}

fn source_for(host: &str, port: u16, database: &str, user: &str, password: &str) -> Source {
    Source::connect(&SourceConfig {
        host: host.to_owned(),
        port,
        database: database.to_owned(),
        credentials: Credentials {
            user: user.to_owned(),
            password: Secret::new(password.to_owned()),
        },
        tls: Tls::Disabled,
        max_connections: 4,
    })
    .unwrap()
}

#[tokio::test]
async fn imports_everything_above_the_floor_and_verifies_clean() {
    let Some(mut fx) = Fixture::start().await else {
        return;
    };
    let before = fx.checksums().await;

    let report = fx.import().await;
    assert!(report.complete);
    assert_eq!(report.rows["releases"], 6);
    assert_eq!(report.rows["predb"], 2);
    assert_eq!(report.rows["usenet_groups"], 2);
    assert_eq!(report.rows["legacy_videos"], 2);
    assert_eq!(report.rows["legacy_tv_episodes"], 1);
    assert_eq!(report.rows["legacy_movies"], 1);
    assert_eq!(report.rows["par_file_hash_inventories"], 1);
    assert_eq!(
        report.rows["release_groups"], 2,
        "the small release's row stays behind"
    );
    assert_eq!(report.rows["release_files"], 2);
    assert_eq!(report.rows["release_nfos"], 1);
    assert_eq!(report.nzbs, 5, "1, 2, 3, 7 and 8");
    assert_eq!(report.anomalies["nzb_missing"], 1);
    assert_eq!(report.anomalies["nzb_digest_mismatch"], 1);

    let check = fx.check().await;
    assert!(check.clean(), "{check:#?}");
    assert_eq!(check.nzbs_compared, 5);
    assert_eq!(check.flagged_nzbs["nzb_digest_mismatch"], 1);
    assert!(
        check
            .counts
            .iter()
            .all(|(_, expected, actual)| expected == actual)
    );

    let catalog = fx.catalog();
    let got = catalog.release_by_id(1).unwrap().unwrap();
    assert_eq!(got.tvdb_id, Some(121_361));
    assert_eq!(got.tvmaze_id, Some(82));
    assert_eq!(got.trakt_id, Some(1390));
    assert_eq!(got.tvrage_id, Some(24_493));
    assert_eq!(got.imdb_id.as_deref(), Some("0944947"));
    assert_eq!((got.season, got.episode), (Some(3), Some(9)));
    assert_eq!(got.predb_id, Some(100));
    assert_eq!(got.posted_at, Some(1_790_856_000));

    let avengers = catalog.release_by_guid("guid-3").unwrap().unwrap();
    assert_eq!(avengers.imdb_id.as_deref(), Some("0848228"));
    assert_eq!(avengers.tmdb_id, Some(24_428));
    assert_eq!(avengers.posted_at, None);

    // Allen's rule: one big MKV gets through every gate, and its IDs stay absent.
    let remux = catalog.release_by_guid("guid-2").unwrap().unwrap();
    assert_eq!(remux.size_bytes, 64_424_509_440);
    assert_eq!(remux.imdb_id, None);
    assert_eq!(remux.tvdb_id, None);
    assert_eq!(remux.predb_id, None);
    assert_eq!(catalog.release_by_guid("guid-5").unwrap(), None);

    let nzbs = NzbStore::open_read_only(&fx.paths().nzbs).unwrap();
    let stored = nzbs.get(2).unwrap().unwrap();
    assert_eq!(
        indexer_store::gunzip_capped(&stored.body, 1 << 20).unwrap(),
        xml(2)
    );
    assert!(nzbs.get(5).unwrap().is_none());

    // Dates outside what UNIX_TIMESTAMP can hold keep their real value.
    let time = |column: &str, id: i64| -> Option<i64> {
        catalog
            .conn()
            .query_row(
                &format!("SELECT {column} FROM releases WHERE id = ?1"),
                [id],
                |row| row.get(0),
            )
            .unwrap()
    };
    assert_eq!(time("posted_at", 7), Some(0));
    assert_eq!(time("added_at", 7), Some(-1));
    assert_eq!(time("posted_at", 8), Some(7_258_118_400));
    let pre_at: Option<i64> = catalog
        .conn()
        .query_row("SELECT pre_at FROM predb WHERE id = 101", [], |row| {
            row.get(0)
        })
        .unwrap();
    assert_eq!(pre_at, Some(-144_720_000));
    let started: String = catalog
        .conn()
        .query_row(
            "SELECT started FROM legacy_videos WHERE id = 11",
            [],
            |row| row.get(0),
        )
        .unwrap();
    assert_eq!(started, "1951-10-15 00:00:00");
    let (fingerprint, inner): (Option<String>, i64) = catalog
        .conn()
        .query_row(
            "SELECT legacy_video_id, inner_file_count FROM releases WHERE id = 1",
            [],
            |row| {
                Ok((
                    row.get::<_, Option<i64>>(0)?.map(|id| id.to_string()),
                    row.get(1)?,
                ))
            },
        )
        .unwrap();
    assert_eq!((fingerprint.as_deref(), inner), (Some("10"), 0));

    assert_eq!(fx.checksums().await, before, "the source must not change");
    fx.finish().await;
}

#[tokio::test]
async fn a_stopped_import_resumes_without_repeating_or_missing_anything() {
    let Some(fx) = Fixture::start().await else {
        return;
    };

    let first = run(&fx.source, &fx.options(Some(2))).await.unwrap();
    assert!(!first.complete);
    assert_eq!(first.rows["releases"], 2);

    let partial = fx.check().await;
    assert!(!partial.clean(), "a partial import is not verified");
    assert_eq!(partial.problem_count, 0, "{partial:#?}");
    assert_eq!(partial.nzbs_compared, 2);

    let second = fx.import().await;
    assert!(second.complete);
    assert_eq!(second.rows["releases"], 4);
    let check = fx.check().await;
    assert!(check.clean(), "{check:#?}");

    let third = fx.import().await;
    assert_eq!(third.rows.get("releases"), None, "nothing is left to copy");
    let anomalies: i64 = fx
        .catalog()
        .conn()
        .query_row("SELECT COUNT(*) FROM import_anomalies", [], |row| {
            row.get(0)
        })
        .unwrap();
    assert_eq!(anomalies, 2, "resuming does not repeat anomalies");
    fx.finish().await;
}

#[tokio::test]
async fn verify_catches_each_kind_of_damage() {
    let Some(fx) = Fixture::start().await else {
        return;
    };
    fx.import().await;
    assert!(fx.check().await.clean());

    let catalog = fx.catalog();
    let damage = |sql: &str| {
        catalog.conn().execute(sql, []).unwrap();
    };

    damage("UPDATE releases SET size_bytes = size_bytes + 1 WHERE id = 1");
    let check = fx.check().await;
    assert!(!check.clean());
    assert!(
        check.problems.iter().any(|p| p.starts_with("releases:")),
        "{check:#?}"
    );
    damage("UPDATE releases SET size_bytes = size_bytes - 1 WHERE id = 1");
    assert!(fx.check().await.clean());

    damage("DELETE FROM release_files WHERE release_id = 2");
    let check = fx.check().await;
    assert!(
        check
            .problems
            .iter()
            .any(|p| p.starts_with("release_files:")),
        "{check:#?}"
    );

    damage("INSERT INTO release_files VALUES (2, 'wrong.mkv', 1, '', 0)");
    assert!(!fx.check().await.clean());
    damage("DELETE FROM release_files WHERE release_id = 2");
    damage("INSERT INTO release_files VALUES (2, 'a1.mkv', 64424509440, '', 0)");
    assert!(fx.check().await.clean());

    let nzbs = NzbStore::open(&fx.paths().nzbs).unwrap();
    let stored = nzbs.get(7).unwrap().unwrap();
    let mut tampered = stored.body.clone();
    let last = tampered.len() - 1;
    tampered[last] ^= 1;
    nzbs.conn()
        .execute(
            "UPDATE nzbs SET body = ?1 WHERE release_id = 7",
            [&tampered],
        )
        .unwrap();
    let check = fx.check().await;
    assert!(
        check.problems.iter().any(|p| p.contains("bytes differ")),
        "{check:#?}"
    );
    nzbs.conn()
        .execute(
            "UPDATE nzbs SET body = ?1 WHERE release_id = 7",
            [&stored.body],
        )
        .unwrap();

    nzbs.conn()
        .execute("DELETE FROM nzbs WHERE release_id = 8", [])
        .unwrap();
    let check = fx.check().await;
    assert!(
        check
            .problems
            .iter()
            .any(|p| p.contains("missing from the store")),
        "{check:#?}"
    );
    drop(nzbs);
    fx.finish().await;
}

#[tokio::test]
async fn a_date_that_cannot_be_represented_is_a_finding() {
    let Some(mut fx) = Fixture::start().await else {
        return;
    };
    fx.admin
        .query_drop(
            "SET SESSION sql_mode = '';
             UPDATE releases SET postdate = '0000-00-00 00:00:00' WHERE id = 2;",
        )
        .await
        .unwrap();
    fx.admin
        .query_drop("SET SESSION sql_mode = DEFAULT")
        .await
        .unwrap();
    fx.import().await;
    let check = fx.check().await;
    assert!(!check.clean());
    assert!(
        check
            .problems
            .iter()
            .any(|p| p.starts_with("releases.posted_at: 1 source dates")),
        "{check:#?}"
    );
    fx.finish().await;
}

#[tokio::test]
async fn a_source_table_nobody_decided_about_stops_verification() {
    let Some(mut fx) = Fixture::start().await else {
        return;
    };
    fx.import().await;
    assert!(fx.check().await.clean());
    fx.admin
        .query_drop("CREATE TABLE surprise (id INT PRIMARY KEY)")
        .await
        .unwrap();
    fx.admin
        .query_drop("ALTER TABLE predb ADD COLUMN surprise INT")
        .await
        .unwrap();
    let check = fx.check().await;
    assert!(!check.clean());
    assert!(
        check.problems.iter().any(|p| p.starts_with("surprise:")),
        "{check:#?}"
    );
    assert!(
        check
            .problems
            .iter()
            .any(|p| p.starts_with("predb.surprise:")),
        "{check:#?}"
    );
    fx.finish().await;
}

#[tokio::test]
async fn tls_trusts_only_the_given_ca_and_checks_the_name() {
    let (Ok(target), Ok(ca), Ok(wrong_ca), Ok(root)) = (
        std::env::var("INDEXER_IMPORT_TEST_TLS_MARIADB"),
        std::env::var("INDEXER_IMPORT_TEST_TLS_CA"),
        std::env::var("INDEXER_IMPORT_TEST_TLS_WRONG_CA"),
        std::env::var("INDEXER_IMPORT_TEST_ROOT_PASSWORD"),
    ) else {
        return;
    };
    let (host, port) = target.split_once(':').unwrap();
    let port: u16 = port.parse().unwrap();
    let connect = |host: &str, ca: &str| {
        Source::connect(&SourceConfig {
            host: host.to_owned(),
            port,
            database: "mysql".to_owned(),
            credentials: Credentials {
                user: "root".to_owned(),
                password: Secret::new(root.clone()),
            },
            tls: Tls::VerifyCa(ca.into()),
            max_connections: 1,
        })
        .unwrap()
    };
    let cipher = "SELECT VARIABLE_VALUE FROM information_schema.SESSION_STATUS \
                  WHERE VARIABLE_NAME = 'Ssl_cipher'";

    let good = connect(host, &ca);
    let ciphers: Vec<String> = good.select(cipher, ()).await.unwrap();
    assert!(!ciphers[0].is_empty(), "the session must be encrypted");
    good.disconnect().await.unwrap();

    let result: anyhow::Result<Vec<String>> = connect(host, &wrong_ca).select(cipher, ()).await;
    assert!(
        result.is_err(),
        "a certificate from another CA must be refused"
    );

    // The certificate names 127.0.0.1, not localhost.
    let result: anyhow::Result<Vec<String>> = connect("localhost", &ca).select(cipher, ()).await;
    assert!(
        result.is_err(),
        "a certificate for another name must be refused"
    );
}

#[tokio::test]
async fn verify_notices_the_source_changing() {
    let Some(mut fx) = Fixture::start().await else {
        return;
    };
    fx.import().await;
    fx.admin
        .query_drop(
            "INSERT INTO releases (id, name, searchname, size, guid, completion)
             VALUES (9, 'new', 'new', 900000000, 'guid-9', 100)",
        )
        .await
        .unwrap();
    let check = fx.check().await;
    assert!(!check.clean());
    assert!(
        check.problems.iter().any(|p| p.starts_with("releases:")),
        "{check:#?}"
    );
    fx.finish().await;
}

#[tokio::test]
async fn sessions_stay_read_only_even_with_a_login_that_could_write() {
    let Some(fx) = Fixture::start().await else {
        return;
    };
    let root = std::env::var("INDEXER_IMPORT_TEST_ROOT_PASSWORD").unwrap();
    let powerful = source_for(&fx.host, fx.port, &fx.database, "root", &root);
    for _ in 0..10 {
        let read_only: Vec<i64> = powerful
            .select("SELECT @@session.tx_read_only", ())
            .await
            .unwrap();
        assert_eq!(read_only, [1]);
    }
    let refused = powerful
        .select_values("DELETE FROM releases", ())
        .await
        .unwrap_err();
    assert!(refused.to_string().contains("not a SELECT"), "{refused}");

    let mut admin_side = fx.admin;
    let count: Option<u64> = admin_side
        .query_first("SELECT COUNT(*) FROM releases")
        .await
        .unwrap();
    assert_eq!(count, Some(7));

    // The second layer: a read-only session is refused by the server itself.
    admin_side
        .query_drop("SET SESSION TRANSACTION READ ONLY")
        .await
        .unwrap();
    let refused = admin_side
        .query_drop("DELETE FROM releases")
        .await
        .unwrap_err();
    assert!(refused.to_string().contains("READ ONLY"), "{refused}");
    admin_side
        .query_drop("SET SESSION TRANSACTION READ WRITE")
        .await
        .unwrap();
    powerful.disconnect().await.unwrap();
    fx.source.disconnect().await.unwrap();
    admin_side
        .query_drop(format!("DROP DATABASE {}", fx.database))
        .await
        .unwrap();
}
