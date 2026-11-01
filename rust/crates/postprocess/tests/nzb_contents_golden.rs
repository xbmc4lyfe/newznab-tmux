//! The NZB file list, source inventory and natural sort against PHP's output, from
//! `fixtures/generate_nzb_contents_golden.php`.

use nntmux_postprocess::additional::nzb_contents::{
    SourceFile, parse_file_list, source_file_inventory, strnatcmp,
};
use nntmux_postprocess::additional::planner::NzbFile;
use serde::Deserialize;

#[derive(Deserialize)]
struct Golden {
    documents: Vec<Document>,
    strnatcmp: Vec<(String, String, i8)>,
}

#[derive(Deserialize)]
struct Document {
    name: String,
    xml: String,
    files: Vec<NzbFile>,
    inventory: Option<Vec<SourceFile>>,
}

fn golden() -> Golden {
    serde_json::from_str(include_str!("fixtures/nzb_contents_golden.json")).expect("valid JSON")
}

#[test]
fn file_lists_and_inventories_match_php() {
    for document in golden().documents {
        let files = parse_file_list(document.xml.as_bytes());
        assert_eq!(files, document.files, "{}: file list", document.name);
        let inventory = if files.is_empty() {
            None
        } else {
            source_file_inventory(document.xml.as_bytes(), &files)
        };
        assert_eq!(
            inventory, document.inventory,
            "{}: inventory",
            document.name
        );
    }
}

#[test]
fn natural_order_matches_php() {
    for (left, right, expected) in golden().strnatcmp {
        let expected = expected.cmp(&0);
        assert_eq!(
            strnatcmp(left.as_bytes(), right.as_bytes()),
            expected,
            "strnatcmp({left:?}, {right:?})"
        );
    }
}
