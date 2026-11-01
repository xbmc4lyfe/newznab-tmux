//! Release categorization: a pipeline of rule sets that each offer a category with a
//! confidence, ported from `app/Services/Categorization`.
//!
//! Owner: ws-naming. See `rust/WORKSTREAMS.md`.

pub mod cat;
pub mod commands;
pub mod names;
pub mod pcre;
pub mod rules;

use anyhow::{Result, bail};
use clap::Subcommand;
use nntmux_runtime::Ctx as RuntimeCtx;
use sqlx::MySqlPool;

use rules::{Ctx, misc};

/// A rule's answer.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct Hit {
    pub category: i32,
    pub confidence: f64,
    pub by: &'static str,
}

impl Hit {
    const NO_MATCH: Self = Self {
        category: cat::OTHER_MISC,
        confidence: 0.0,
        by: "no_match",
    };

    fn is_protected_misc(self) -> bool {
        if self.category == cat::OTHER_HASHED {
            return true;
        }
        self.category == cat::OTHER_MISC
            && (self.by.starts_with("hash_")
                || self.by.starts_with("obfuscated_")
                || self.by.starts_with("gibberish_")
                || self.by == "group_only_low_signal")
    }

    fn overrides(self, other: Self) -> bool {
        if self.category != cat::OTHER_MISC
            && self.category != cat::OTHER_HASHED
            && other.is_protected_misc()
            && self.confidence < 0.6
        {
            return false;
        }
        if self.confidence > other.confidence {
            return true;
        }
        self.confidence == other.confidence
            && self.category != cat::OTHER_MISC
            && other.category == cat::OTHER_MISC
    }

    fn locks_to_misc(self) -> bool {
        self.category == cat::OTHER_HASHED
            || self.by.starts_with("hash_")
            || self.by.starts_with("obfuscated_")
            || self.by.starts_with("gibberish_")
    }
}

/// The pipeline's decision.
#[derive(Clone, Debug, PartialEq)]
pub struct Decision {
    pub category: i32,
    pub confidence: f64,
    pub matched_by: &'static str,
    pub locked_to_misc: bool,
    /// The name the rules saw, after unwrapping and cleanup.
    pub name: String,
}

/// The two ingestion settings that change what the rules return.
#[derive(Clone, Copy, Debug)]
pub struct Categorizer {
    pub categorize_foreign: bool,
    pub categorize_web_dl: bool,
}

impl Categorizer {
    pub async fn load(db: &MySqlPool) -> Result<Self> {
        let row: Option<(bool, bool)> = sqlx::query_as(
            "SELECT categorize_foreign, categorize_web_dl FROM ingestion_configurations LIMIT 1",
        )
        .fetch_optional(db)
        .await?;
        let Some((categorize_foreign, categorize_web_dl)) = row else {
            bail!("ingestion_configurations has no row");
        };
        Ok(Self {
            categorize_foreign,
            categorize_web_dl,
        })
    }

    /// Categorizes a release, looking its group name up by id.
    pub async fn determine(
        &self,
        db: &MySqlPool,
        group_id: i64,
        name: &str,
        poster: &str,
    ) -> Result<Decision> {
        let group: Option<String> =
            sqlx::query_scalar("SELECT name FROM usenet_groups WHERE id = ?")
                .bind(group_id)
                .fetch_optional(db)
                .await?;
        Ok(self.classify(name, group.as_deref().unwrap_or(""), poster))
    }

    /// The pure pipeline, for callers that already hold the group name.
    pub fn classify(&self, name: &str, group: &str, poster: &str) -> Decision {
        let mut name = names::unwrap_nzbsplit(name).unwrap_or_else(|| name.to_owned());
        // Normalizing dots to spaces would erase the explicit OF. site prefix.
        if !rules::has_only_fans_marker(&name)
            && let Some(extracted) = names::extract_obfuscated_subject(&name)
        {
            name = extracted;
        }
        let ctx = Ctx {
            name: &name,
            group,
            poster,
            foreign: self.categorize_foreign,
            web_dl: self.categorize_web_dl,
        };

        let mut best = Hit::NO_MATCH;
        let signals = misc::inspect_signals(ctx.name);
        let mut locked = false;
        if let Some(found) = misc::categorize(&ctx) {
            if found.overrides(best) {
                best = found;
            }
            if found.locks_to_misc() {
                best = found;
                locked = true;
            }
        }

        type Rule = (fn(&Ctx) -> bool, fn(&Ctx) -> Option<Hit>);
        const PIPES: [Rule; 8] = [
            (|_| false, rules::group::categorize),
            (|_| false, rules::xxx::categorize),
            (rules::tv::should_skip, rules::tv::categorize),
            (rules::movie::should_skip, rules::movie::categorize),
            (rules::pc::should_skip, rules::pc::categorize),
            (rules::console::should_skip, rules::console::categorize),
            (rules::music::should_skip, rules::music::categorize),
            (rules::book::should_skip, rules::book::categorize),
        ];
        for (skip, rule) in PIPES {
            if locked || best.confidence >= 0.95 {
                break;
            }
            if skip(&ctx) {
                continue;
            }
            if let Some(found) = rule(&ctx)
                && found.overrides(best)
            {
                best = found;
            }
        }

        // A name with no media signal that only matched on its group is misc.
        if !locked && signals.low_signal && best.by.starts_with("group_name_") {
            best = Hit {
                category: cat::OTHER_MISC,
                confidence: 0.65,
                by: "group_only_low_signal",
            };
            locked = true;
        }

        Decision {
            category: best.category,
            confidence: best.confidence,
            matched_by: best.by,
            locked_to_misc: locked,
            name,
        }
    }
}

/// `nntmux-ctl categorize ...`
#[derive(Debug, Subcommand)]
pub enum Command {
    /// Shows how a release name categorizes (`nntmux:test-categorization`). With no name,
    /// runs a fixed sample list.
    Test {
        #[arg(long)]
        release: Option<String>,
        #[arg(long, default_value = "")]
        poster: String,
        /// The usenet group name, since rules consult it.
        #[arg(long, default_value = "")]
        group: String,
        /// List the rule sets in the order they run.
        #[arg(long)]
        list_categorizers: bool,
    },
    /// Re-categorizes releases by name and group (`nntmux:recategorize-releases`).
    Recategorize(commands::RecategorizeArgs),
    /// Previews or repairs `OF.` releases in XXX categories (`releases:recategorize-onlyfans`).
    RecategorizeOnlyfans {
        /// Apply the corrections. Otherwise preview only.
        #[arg(long)]
        apply: bool,
    },
}

const RULE_SETS: [(&str, u32); 10] = [
    ("Misc", 1),
    ("GroupName", 5),
    ("XXX", 10),
    ("TV", 20),
    ("Movie", 25),
    ("PC", 30),
    ("Console", 35),
    ("Music", 40),
    ("Book", 45),
    ("MiscSafetyNet", 100),
];

const SAMPLES: [&str; 14] = [
    "Game.of.Thrones.S08E06.720p.BluRay.x264-DEMAND",
    "The.Mandalorian.S03E08.2160p.WEB-DL.DDP5.1.Atmos.H.265-FLUX",
    "[SubsPlease] Jujutsu Kaisen - 47 (1080p) [ABC12345].mkv",
    "The.Matrix.Resurrections.2021.2160p.UHD.BluRay.x265-SURCODE",
    "Inception.2010.1080p.BluRay.x264-SPARKS",
    "Oppenheimer.2023.WEB-DL.1080p.H.264.AAC-LOL",
    "Brazzers.23.11.15.Model.Name.XXX.1080p.MP4-KTR",
    "SexBabesVR.23.10.20.Virtual.Reality.VR180.3D.SBS.2160p-VRSins",
    "Cyberpunk.2077.Ultimate.Edition.v2.12.1-RUNE",
    "Elden.Ring.Shadow.of.the.Erdtree.PS5-DUPLEX",
    "Taylor.Swift.Midnights.2022.FLAC-dL",
    "Various.Artists.Now.Thats.What.I.Call.Music.100.2018.MP3.320kbps",
    "Brandon.Sanderson.Mistborn.Trilogy.EPUB",
    "OReilly.Learning.Python.6th.Edition.PDF",
];

pub async fn ctl(ctx: RuntimeCtx, command: Command) -> Result<()> {
    match command {
        Command::Test {
            release,
            poster,
            group,
            list_categorizers,
        } => {
            if list_categorizers {
                for (name, priority) in RULE_SETS {
                    println!("{priority:>4}  {name}");
                }
                return Ok(());
            }
            let categorizer = Categorizer::load(&ctx.db).await?;
            match release {
                Some(release) => {
                    let decision = categorizer.classify(&release, &group, &poster);
                    println!("Release: {release}");
                    if !poster.is_empty() {
                        println!("Poster: {poster}");
                    }
                    println!("Category ID: {}", decision.category);
                    println!("Matched By: {}", decision.matched_by);
                    println!("Confidence: {}", decision.confidence);
                }
                None => {
                    for sample in SAMPLES {
                        let decision = categorizer.classify(sample, &group, &poster);
                        println!(
                            "{:>5}  {:<28}  {sample}",
                            decision.category, decision.matched_by
                        );
                    }
                }
            }
            Ok(())
        }
        Command::Recategorize(args) => commands::recategorize(&ctx, args).await,
        Command::RecategorizeOnlyfans { apply } => {
            commands::recategorize_onlyfans(&ctx, apply).await
        }
    }
}
