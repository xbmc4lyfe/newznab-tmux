//! Every scheduled task keeps the cron expression the PHP schedule gave it. The fixture comes from
//! `fixtures/dump_schedule.php`.

use nntmux_supervisor::schedule::tasks;
use serde_json::Value;

/// Closures had no name in the PHP schedule.
fn php_name(task: &str) -> &str {
    match task {
        "user-activity-stats-cleanup" | "user-activity-hourly-stats-cleanup" => "closure",
        other => other,
    }
}

#[test]
fn tasks_match_the_php_schedule() {
    let php: Vec<Value> =
        serde_json::from_str(include_str!("fixtures/schedule.json")).expect("valid fixture");
    for task in tasks() {
        // Replaces what the tmux monitor computed on its own clock, so PHP had no schedule entry.
        if task.name == "processing-stats" {
            continue;
        }
        // `routes/console.php` added these jobs only with `PURGE_INACTIVE_USERS=true`, so the
        // default-environment dump has no row for them. Both were `->daily()`.
        if matches!(task.name, "RemoveInactiveAccounts" | "PurgeDeletedAccounts") {
            assert_eq!(task.cadence.cron(), "0 0 * * *", "{}", task.name);
            continue;
        }
        let cron = task.cadence.cron();
        let found = php
            .iter()
            .any(|row| row["command"] == php_name(task.name) && row["cron"] == cron.as_str());
        assert!(found, "{} ({cron}) isn't in the PHP schedule", task.name);
    }
}
