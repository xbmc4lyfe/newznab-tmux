use anyhow::Result;
use chrono::NaiveDateTime;
use nntmux_admin::settings::{self, Domain, Kind, Value};
use nntmux_testkit::TestDb;

fn at(raw: &str) -> NaiveDateTime {
    NaiveDateTime::parse_from_str(raw, "%Y-%m-%d %H:%M:%S").expect("a timestamp")
}

fn pairs(values: &[&str]) -> Vec<String> {
    values.iter().map(|value| (*value).to_owned()).collect()
}

fn value(shown: &[(settings::Field, Option<String>)], column: &str) -> Option<String> {
    shown
        .iter()
        .find(|(field, _)| field.column == column)
        .and_then(|(_, value)| value.clone())
}

#[test]
fn fields_follow_the_settings_catalog() -> Result<()> {
    let field = |domain: Domain, column: &str| domain.field(column).map(|field| field.kind);
    assert_eq!(field(Domain::Ingestion, "part_repair")?, Kind::Boolean);
    assert_eq!(
        field(Domain::Ingestion, "min_size_to_form_release")?,
        Kind::Bytes
    );
    assert_eq!(field(Domain::Ingestion, "safe_backfill_date")?, Kind::Date);
    assert_eq!(
        field(Domain::PostProcessing, "inner_file_blacklist")?,
        Kind::Regex
    );
    assert_eq!(field(Domain::Site, "site_logo")?, Kind::Upload);
    assert_eq!(
        field(Domain::Tmux, "niceness")?,
        Kind::Integer { min: -20, max: 19 }
    );
    assert_eq!(
        field(Domain::Ingestion, "completion_percent")?,
        Kind::Integer { min: 0, max: 100 }
    );
    assert_eq!(
        field(Domain::Ingestion, "binary_threads")?,
        Kind::Integer {
            min: 1,
            max: 65_535
        }
    );
    assert!(matches!(
        field(Domain::Metadata, "movie_lookup")?,
        Kind::Choice(_)
    ));
    assert!(Domain::Site.field("binary_threads").is_err());
    assert!(Domain::Tmux.field("cleanup_rules").is_err());
    let total: usize = Domain::ALL.iter().map(|domain| domain.fields().len()).sum();
    assert_eq!(total, 13 + 1 + 36 + 29 + 15 + 42);
    Ok(())
}

#[test]
fn values_are_checked_like_the_settings_form() -> Result<()> {
    let parse = |domain: Domain, column: &str, text: &str| domain.field(column)?.parse(text);
    assert_eq!(
        parse(Domain::Ingestion, "part_repair", "off")?,
        Value::Integer(0)
    );
    assert_eq!(
        parse(Domain::Ingestion, "max_size_to_form_release", "2GB")?,
        Value::Integer(2 << 30)
    );
    assert_eq!(
        parse(Domain::Ingestion, "min_size_to_form_release", "1.5MB")?,
        Value::Integer(1_572_864)
    );
    assert!(parse(Domain::Ingestion, "max_size_to_form_release", "-500MB").is_err());
    assert!(parse(Domain::Ingestion, "max_size_to_form_release", "0.0000001MB").is_err());
    assert_eq!(
        parse(Domain::Ingestion, "max_size_to_form_release", "0GB")?,
        Value::Integer(0)
    );
    assert_eq!(
        parse(Domain::Metadata, "tv_lookup", "renamed releases only")?,
        Value::Integer(2)
    );
    assert_eq!(
        parse(Domain::Tmux, "backfill_mode", "4")?,
        Value::Integer(4)
    );
    assert!(parse(Domain::Tmux, "backfill_mode", "2").is_err());
    assert_eq!(parse(Domain::Tmux, "niceness", "-5")?, Value::Integer(-5));
    assert!(parse(Domain::Tmux, "niceness", "20").is_err());
    assert!(parse(Domain::Ingestion, "binary_threads", "0").is_err());
    assert!(parse(Domain::Ingestion, "binary_threads", "70000").is_err());
    assert!(parse(Domain::Ingestion, "completion_percent", "101").is_err());
    assert_eq!(
        parse(Domain::Ingestion, "safe_backfill_date", "2020-01-31")?,
        Value::Text("2020-01-31".to_owned())
    );
    assert!(parse(Domain::Ingestion, "safe_backfill_date", "2020-02-31").is_err());
    assert!(
        parse(
            Domain::PostProcessing,
            "inner_file_blacklist",
            "/(unclosed/i"
        )
        .is_err()
    );
    assert!(parse(Domain::PostProcessing, "inner_file_blacklist", "").is_err());
    assert_eq!(parse(Domain::Tmux, "redis_args", "")?, Value::Null);
    assert_eq!(
        parse(Domain::Site, "dereferrer_link", "")?,
        Value::Text(String::new())
    );
    assert!(parse(Domain::Site, "title", " ").is_err());
    assert!(parse(Domain::Site, "title", &"x".repeat(256)).is_err());
    assert!(parse(Domain::Site, "site_logo", "logo.png").is_err());
    assert!(settings::assignments(Domain::Site, &pairs(&["title"])).is_err());
    assert!(settings::assignments(Domain::Site, &pairs(&["title=a", "title=b"])).is_err());
    Ok(())
}

#[tokio::test]
async fn settings_are_shown_and_changed() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let now = at("2026-11-02 12:00:00");

    let site = settings::show(&db.pool, Domain::Site).await?;
    assert_eq!(value(&site, "title").as_deref(), Some("NNTmux"));
    assert_eq!(value(&site, "site_logo"), None);

    let values = settings::assignments(
        Domain::Ingestion,
        &pairs(&[
            "min_size_to_form_release=100MB",
            "max_size_to_form_release=50GB",
            "part_repair=0",
            "backfill_order=Oldest",
            "safe_backfill_date=2015-01-01",
        ]),
    )?;
    settings::update(&db.pool, Domain::Ingestion, &values, now).await?;
    let ingestion = settings::show(&db.pool, Domain::Ingestion).await?;
    assert_eq!(
        value(&ingestion, "min_size_to_form_release").as_deref(),
        Some("104857600")
    );
    assert_eq!(
        value(&ingestion, "max_size_to_form_release").as_deref(),
        Some("53687091200")
    );
    assert_eq!(value(&ingestion, "part_repair").as_deref(), Some("0"));
    assert_eq!(value(&ingestion, "backfill_order").as_deref(), Some("2"));
    assert_eq!(
        value(&ingestion, "safe_backfill_date").as_deref(),
        Some("2015-01-01")
    );
    let stamped: Option<String> = sqlx::query_scalar(
        "SELECT CAST(updated_at AS CHAR) FROM ingestion_configurations WHERE id = 1",
    )
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(stamped.as_deref(), Some("2026-11-02 12:00:00"));

    // A maximum below the stored minimum is refused, and nothing changes.
    let shrink = settings::assignments(
        Domain::Ingestion,
        &pairs(&["max_size_to_form_release=50MB", "part_repair=1"]),
    )?;
    assert!(
        settings::update(&db.pool, Domain::Ingestion, &shrink, now)
            .await
            .is_err()
    );
    let unchanged = settings::show(&db.pool, Domain::Ingestion).await?;
    assert_eq!(value(&unchanged, "part_repair").as_deref(), Some("0"));
    // Zero means unlimited.
    let unlimited =
        settings::assignments(Domain::Ingestion, &pairs(&["max_size_to_form_release=0"]))?;
    settings::update(&db.pool, Domain::Ingestion, &unlimited, now).await?;

    // Nullable text clears to NULL.
    let args = settings::assignments(Domain::Tmux, &pairs(&["redis_args="]))?;
    settings::update(&db.pool, Domain::Tmux, &args, now).await?;
    let tmux = settings::show(&db.pool, Domain::Tmux).await?;
    assert_eq!(value(&tmux, "redis_args"), None);

    let site = settings::assignments(
        Domain::Site,
        &pairs(&["title=My indexer", "terms=", "trailers_display=no"]),
    )?;
    settings::update(&db.pool, Domain::Site, &site, now).await?;
    let site = settings::show(&db.pool, Domain::Site).await?;
    assert_eq!(value(&site, "title").as_deref(), Some("My indexer"));
    assert_eq!(value(&site, "terms").as_deref(), Some(""));
    assert_eq!(value(&site, "trailers_display").as_deref(), Some("0"));

    db.drop_database().await
}

#[tokio::test]
async fn tmux_rules_and_colors_stay_consistent() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let now = at("2026-11-02 12:00:00");
    assert!(
        settings::color_exclusions(&db.pool)
            .await?
            .starts_with(&[4, 8, 9, 11, 15])
    );

    settings::set_cleanup_rules(&db.pool, &pairs(&["sample", "codec", "sample"]), now).await?;
    assert_eq!(
        settings::cleanup_rules(&db.pool).await?,
        ["codec", "sample"]
    );
    assert!(
        settings::set_cleanup_rules(&db.pool, &pairs(&["bogus"]), now)
            .await
            .is_err()
    );
    assert_eq!(
        settings::cleanup_rules(&db.pool).await?,
        ["codec", "sample"]
    );
    settings::set_cleanup_rules(&db.pool, &[], now).await?;
    assert!(settings::cleanup_rules(&db.pool).await?.is_empty());

    // The seeded range is 1..=250, so 0 and 251 are outside it.
    assert!(
        settings::set_color_exclusions(&db.pool, &[0], now)
            .await
            .is_err()
    );
    assert!(
        settings::set_color_exclusions(&db.pool, &[251], now)
            .await
            .is_err()
    );
    settings::set_color_exclusions(&db.pool, &[20, 30, 20], now).await?;
    assert_eq!(settings::color_exclusions(&db.pool).await?, vec![20, 30]);

    // Narrowing the range past an exclusion, or inverting it, is refused.
    let narrow = settings::assignments(Domain::Tmux, &pairs(&["colors_end=25"]))?;
    assert!(
        settings::update(&db.pool, Domain::Tmux, &narrow, now)
            .await
            .is_err()
    );
    let inverted = settings::assignments(
        Domain::Tmux,
        &pairs(&["colors_start=200", "colors_end=100"]),
    )?;
    assert!(
        settings::update(&db.pool, Domain::Tmux, &inverted, now)
            .await
            .is_err()
    );
    let wider = settings::assignments(Domain::Tmux, &pairs(&["colors_end=255"]))?;
    settings::update(&db.pool, Domain::Tmux, &wider, now).await?;
    let tmux = settings::show(&db.pool, Domain::Tmux).await?;
    assert_eq!(value(&tmux, "colors_end").as_deref(), Some("255"));

    db.drop_database().await
}
