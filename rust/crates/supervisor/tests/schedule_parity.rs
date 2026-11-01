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
        let cron = task.cadence.cron();
        let found = php
            .iter()
            .any(|row| row["command"] == php_name(task.name) && row["cron"] == cron.as_str());
        assert!(found, "{} ({cron}) isn't in the PHP schedule", task.name);
    }
}
