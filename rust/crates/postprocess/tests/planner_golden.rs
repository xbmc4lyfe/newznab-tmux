//! The work planner against output recorded from the PHP classes. See
//! `fixtures/generate_planner_golden.php`.

use nntmux_postprocess::additional::planner::{self, NzbFile, WorkPlan, image_rank};
use nntmux_postprocess::config::PlannerConfig;
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/planner_golden.json")).expect("valid JSON")
}

#[test]
fn plans_match_php() {
    let golden = golden();
    let cases = golden["cases"].as_array().expect("cases");
    assert!(cases.len() > 40);
    for case in cases {
        let config: PlannerConfig = serde_json::from_value(case["config"].clone()).expect("config");
        let nzb: Vec<NzbFile> = serde_json::from_value(case["nzb"].clone()).expect("nzb");
        let plan: WorkPlan = planner::plan(&config, &nzb, case["group"].as_str().expect("group"));
        let actual = serde_json::to_value(&plan).expect("plan");
        assert_eq!(
            actual, case["plan"],
            "{} / {}",
            case["name"], case["profile"]
        );
    }
}

#[test]
fn volume_sets_match_php() {
    for case in golden()["sets"].as_array().expect("sets") {
        let subject = case["subject"].as_str().expect("subject");
        assert_eq!(planner::file_name(subject), case["file_name"], "{subject}");
        assert_eq!(
            planner::volume_set_key(subject),
            case["set_key"],
            "{subject}"
        );
    }
}

#[test]
fn image_ranks_match_php() {
    for case in golden()["ranks"].as_array().expect("ranks") {
        let name = case["name"].as_str().expect("name");
        assert_eq!(
            i64::from(image_rank(name)),
            case["rank"].as_i64().expect("rank"),
            "{name}"
        );
    }
}
