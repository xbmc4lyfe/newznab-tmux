//! The header roles' post-processing kill switch against the real schema.

use anyhow::Result;
use nntmux_ingest::run::{post_process_held, role_settings};
use nntmux_ops::processing_stats::total_work;
use nntmux_testkit::TestDb;

#[tokio::test]
async fn the_post_process_backlog_holds_header_roles() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let db = &test.pool;
    for statement in [
        "UPDATE tmux_configurations SET post_mode = 3, post_non_mode = 1, sequential_mode = 0",
        "UPDATE metadata_configurations SET tv_lookup = 1",
        "INSERT INTO releases (id, name, searchname, fromname, adddate, postdate, guid, leftguid, groups_id, categories_id, size, nzbstatus, nfostatus, passwordstatus, haspreview) \
         VALUES (1, 'n1', 's1', 'f', NOW(), NOW(), 'a1', 'a', 1, 5010, 5000000, 1, -1, -1, -1)",
    ] {
        sqlx::query(statement).execute(db).await?;
    }
    let work = total_work(db).await?;
    assert!(work > 0);
    let held_at = |limit: i64| async move {
        sqlx::query("UPDATE tmux_configurations SET post_process_kill_threshold = ?")
            .bind(limit)
            .execute(db)
            .await?;
        post_process_held(db, &role_settings(db).await?).await
    };
    assert!(!held_at(0).await?, "zero turns the switch off");
    assert!(!held_at(work).await?, "at the limit isn't over it");
    assert!(held_at(work - 1).await?, "over the limit holds");
    test.drop_database().await?;
    Ok(())
}
