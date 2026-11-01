//! Tags for the compiled front end. Vite's manifest maps source names to hashed files; a missing
//! manifest just means no tags, so tests and a bare `cargo run` still serve pages.

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::OnceLock;

use serde::Deserialize;

/// The source files `rust/frontend/vite.config.js` builds.
const ENTRIES: [&str; 2] = ["css/app.css", "js/app.js"];

#[derive(Deserialize)]
struct Entry {
    file: String,
    #[serde(default)]
    css: Vec<String>,
}

#[derive(Clone, Debug, Default)]
pub struct Assets {
    pub styles: Vec<String>,
    pub scripts: Vec<String>,
}

pub fn public_dir() -> PathBuf {
    PathBuf::from(nntmux_core::var_or("NNTMUX_PUBLIC_DIR", "public"))
}

fn parse(manifest: &str) -> Option<Assets> {
    let entries: HashMap<String, Entry> = serde_json::from_str(manifest).ok()?;
    let mut assets = Assets::default();
    for name in ENTRIES {
        let Some(entry) = entries.get(name) else {
            continue;
        };
        let path = format!("/build/{}", entry.file);
        if entry.file.ends_with(".css") {
            assets.styles.push(path);
        } else {
            assets.scripts.push(path);
        }
        assets
            .styles
            .extend(entry.css.iter().map(|css| format!("/build/{css}")));
    }
    Some(assets)
}

pub fn assets() -> &'static Assets {
    static ASSETS: OnceLock<Assets> = OnceLock::new();
    ASSETS.get_or_init(|| {
        let path = public_dir().join("build/manifest.json");
        std::fs::read_to_string(path)
            .ok()
            .and_then(|manifest| parse(&manifest))
            .unwrap_or_default()
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn entries_resolve_to_hashed_files() {
        let assets = parse(
            r#"{"css/app.css":{"file":"assets/app-1.css"},"js/app.js":{"file":"assets/app-2.js","css":["assets/extra-3.css"]},"other.js":{"file":"x.js"}}"#,
        )
        .unwrap();
        assert_eq!(
            assets.styles,
            ["/build/assets/app-1.css", "/build/assets/extra-3.css"]
        );
        assert_eq!(assets.scripts, ["/build/assets/app-2.js"]);
    }
}
