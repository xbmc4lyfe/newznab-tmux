//! The name, PreDB link and category a verified release publishes with.

use std::sync::LazyLock;

use anyhow::Result;
use nntmux_categorize::Categorizer;
use nntmux_core::pcre::Pcre;
use nntmux_predb::filename::exact_value;
use nntmux_predb::{PredbIndex, match_pre};
use sqlx::MySqlPool;
use unicode_normalization::UnicodeNormalization;

use crate::cleaning::{Cleaned, ReleaseCleaner, release_name_from_file};
use crate::manifest::{IdentityHint, Manifest};

const OTHER_MISC: i64 = 10;

static NOT_THE_RELEASE: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/(?:^|[._\-\s])(?:sample|proof|thumbs?|subs?|subtitles)(?:[._\-\s]|$)/i")
        .expect("valid")
});
static RELEASE_LIKE: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(
        r"/\b(?:s\d{1,3}e\d{1,3}|(?:19|20)\d{2}|720p|1080p|2160p|flac|mp3|\d{2,3}kbps|v\d+\.\d+)\b/i",
    )
    .expect("valid")
});
static SEPARATORS: LazyLock<Pcre> = LazyLock::new(|| Pcre::new(r"/[._\s]+/u").expect("valid"));

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Metadata {
    pub name: String,
    pub searchname: String,
    pub fromname: String,
    pub predb_id: i64,
    pub isrenamed: bool,
    pub categories_id: i64,
    pub naming_regex_id: i64,
}

/// `PredbIdentityKey::normalize`: what makes two names the same identity.
fn identity_key(value: &str) -> Option<String> {
    if value.is_empty() || value.len() > 512 {
        return None;
    }
    let composed: String = value.nfc().collect();
    let lowered = composed.to_lowercase();
    let trimmed = crate::nzb::fingerprint::php_trim(&lowered);
    Some(String::from_utf8_lossy(&SEPARATORS.replace_all(trimmed.as_bytes(), b".")).into_owned())
}

/// The release name the spot-checked files agree on, or `None` when they don't name one.
pub fn payload_identity(hints: &[IdentityHint]) -> Option<String> {
    if hints.len() > 4096 {
        return None;
    }
    let mut found: Option<(String, String)> = None;
    for hint in hints {
        if !matches!(hint.source.as_str(), "yenc" | "par2")
            || NOT_THE_RELEASE.is_match(hint.name.as_bytes())
        {
            continue;
        }
        let Some(name) = release_name_from_file(&hint.name.replace('_', " ")) else {
            continue;
        };
        if !RELEASE_LIKE.is_match(name.as_bytes()) {
            continue;
        }
        let Some(key) = identity_key(&name) else {
            continue;
        };
        match &found {
            Some((existing, _)) if *existing != key => return None,
            Some(_) => {}
            None => found = Some((key, name)),
        }
    }
    found.map(|(_, name)| name)
}

/// Resolves a manifest's metadata. The handles are shared by every publication on a worker.
pub struct Resolver<'a> {
    pub db: &'a MySqlPool,
    pub cleaner: &'a ReleaseCleaner,
    pub predb: &'a PredbIndex,
    pub categorizer: &'a Categorizer,
}

impl Resolver<'_> {
    pub async fn resolve(&self, manifest: &Manifest) -> Result<Metadata> {
        let source = manifest.metadata_source.as_deref();
        let collection = source.is_none_or(|source| source == "collection");
        let import = source == Some("import");
        let subject = manifest.subject.as_str();
        let display = match &manifest.original_subject {
            Some(original) if import => original.as_str(),
            _ => subject,
        };
        let raw_name: String = if collection {
            subject
                .chars()
                .filter(|c| !matches!(c, '#' | '@' | '$' | '%' | '^' | '§' | '¨' | '©' | 'Ö'))
                .collect()
        } else {
            display.to_owned()
        };
        let from = if collection {
            manifest.fromname.trim_matches('\'').to_owned()
        } else {
            manifest.fromname.clone()
        };
        let group = if manifest.groups_id > 0 {
            sqlx::query_scalar::<_, String>("SELECT name FROM usenet_groups WHERE id = ?")
                .bind(manifest.groups_id)
                .fetch_optional(self.db)
                .await?
                .unwrap_or_default()
        } else {
            manifest.groups.first().cloned().unwrap_or_default()
        };
        let name_override = import && manifest.import_name_override && !subject.is_empty();
        let cleaned = if name_override {
            None
        } else {
            let (clean_subject, poster) = if collection {
                (subject, manifest.fromname.as_str())
            } else {
                (raw_name.as_str(), from.as_str())
            };
            Some(
                self.cleaner
                    .clean(self.db, clean_subject, poster, &group)
                    .await?,
            )
        };
        let (mut name, mut proper, mut predb, regex_id) = match cleaned {
            None => (subject.to_owned(), true, 0, 0),
            Some(Cleaned::Text(text)) => {
                let name = if text.is_empty() {
                    raw_name.clone()
                } else {
                    text
                };
                (name, true, 0, 0)
            }
            Some(Cleaned::Named {
                name,
                proper,
                predb,
                regex_id,
            }) => (name, proper, predb.max(0), regex_id),
        };
        if predb > 0 {
            let stored: Option<String> = sqlx::query_scalar("SELECT title FROM predb WHERE id = ?")
                .bind(predb)
                .fetch_optional(self.db)
                .await?;
            if stored.is_none_or(|title| exact_value(&title) != exact_value(&name)) {
                predb = 0;
            }
        }
        if predb == 0
            && !name.is_empty()
            && let Some(found) = match_pre(self.db, self.predb, &name).await?
        {
            name = found.title;
            predb = found.predb_id as i64;
            proper = true;
        }
        if !proper
            && predb == 0
            && let Some(candidate) =
                payload_identity(manifest.identity_hints.as_deref().unwrap_or_default())
        {
            let existing = release_name_from_file(&name);
            if existing.is_none()
                || existing.as_deref().and_then(identity_key) == identity_key(&candidate)
            {
                name = candidate;
                proper = true;
                if let Some(found) = match_pre(self.db, self.predb, &name).await?
                    && exact_value(&found.title) == exact_value(&name)
                {
                    name = found.title;
                    predb = found.predb_id as i64;
                }
            }
        }
        let categories_id = self.category(manifest, import, &name, &from).await?;
        let keeps_name = if import {
            !name.is_empty()
        } else {
            !name.is_empty() && name != "0"
        };
        Ok(Metadata {
            searchname: if keeps_name { name } else { raw_name.clone() },
            name: raw_name,
            fromname: from,
            predb_id: predb,
            isrenamed: proper,
            categories_id,
            naming_regex_id: regex_id,
        })
    }

    async fn category(
        &self,
        manifest: &Manifest,
        import: bool,
        name: &str,
        from: &str,
    ) -> Result<i64> {
        if import && let Some(explicit) = manifest.categories_id.filter(|id| *id >= 1) {
            let active: Option<i32> =
                sqlx::query_scalar("SELECT 1 FROM categories WHERE id = ? AND status = 1")
                    .bind(explicit)
                    .fetch_optional(self.db)
                    .await?;
            if active.is_some() {
                return Ok(explicit);
            }
        }
        let decision = self
            .categorizer
            .determine(self.db, manifest.groups_id, name, from)
            .await?;
        Ok(match i64::from(decision.category) {
            0 => OTHER_MISC,
            category => category,
        })
    }
}
