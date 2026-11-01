//! Pages were converted from Blade, and a stray directive compiles fine in askama. Left in the
//! markup it breaks Alpine at runtime, so nothing Blade may remain.

use std::fs;
use std::path::{Path, PathBuf};

fn html_files(dir: &Path, found: &mut Vec<PathBuf>) {
    for entry in fs::read_dir(dir).unwrap() {
        let path = entry.unwrap().path();
        if path.is_dir() {
            html_files(&path, found);
        } else if path.extension().is_some_and(|extension| extension == "html") {
            found.push(path);
        }
    }
}

#[test]
fn no_blade_is_left_in_the_templates() {
    let root = Path::new(env!("CARGO_MANIFEST_DIR"));
    let mut files = Vec::new();
    html_files(&root.join("templates"), &mut files);
    html_files(&root.join("../webkit/templates"), &mut files);
    let banned = ["<x-page", "<x-empty", "<x-release", "<x-report", "<x-slot", "<x-cover", "<x-inline", "<x-view", "<x-sort", "{{ $", "{!!", "@php", "@foreach", "@include", "@forelse", "@unless", "@isset", "@endif", "@selected", "TODO(", "#ROUTE"];
    let mut problems = Vec::new();
    for file in files {
        let text = fs::read_to_string(&file).unwrap();
        for word in banned {
            if text.contains(word) {
                problems.push(format!("{}: {word}", file.display()));
            }
        }
    }
    assert!(problems.is_empty(), "Blade left in templates:\n{}", problems.join("\n"));
}
