//! The pattern checkers behind `NameCheckerService::check`: TV, Movie, Game and App, tried in
//! that priority order (10, 20, 30, 40), each running its patterns in the order the PHP
//! `check()` override does. The first match wins and its text becomes the new name.
//!
//! Every checker overrides `AbstractNameChecker::check`, so the `getAllPatterns()` lists and
//! `getPatterns()` are never read and aren't ported. The PHP's `R&C` and `tvp` file patterns
//! aren't here either; they live in the file extractor.

mod app;
mod game;
mod movie;
mod tv;

use nntmux_core::pcre::Pcre;
use regex::bytes::Regex;

use crate::compat::{caps, pcre_caps};
use crate::result::NameFixResult;

/// `NameCheckerService::check`: the first checker with a match, in priority order.
pub fn check(textstring: &str) -> Option<NameFixResult> {
    tv::check(textstring)
        .or_else(|| movie::check(textstring))
        .or_else(|| game::check(textstring))
        .or_else(|| app::check(textstring))
}

/// Patterns with lookaround need the `Pcre` engine; the rest are byte regexes.
enum Re {
    Bytes(&'static Regex),
    Look(&'static Pcre),
}

/// One pattern with the method label and confidence it reports.
struct Rule {
    method: &'static str,
    confidence: f64,
    re: Re,
    /// `str_replace` fix-ups applied to the matched text.
    after: Option<fn(String) -> String>,
}

impl Rule {
    fn bytes(method: &'static str, confidence: f64, re: &'static Regex) -> Self {
        Self {
            method,
            confidence,
            re: Re::Bytes(re),
            after: None,
        }
    }

    fn look(method: &'static str, confidence: f64, re: &'static Pcre) -> Self {
        Self {
            method,
            confidence,
            re: Re::Look(re),
            after: None,
        }
    }

    fn then(mut self, after: fn(String) -> String) -> Self {
        self.after = Some(after);
        self
    }

    /// `preg_match` and `$matches[0]` as a result for `checker`.
    fn apply(&self, checker: &str, textstring: &str) -> Option<NameFixResult> {
        let found = match self.re {
            Re::Bytes(re) => caps(re, textstring)?,
            Re::Look(re) => pcre_caps(re, textstring)?,
        };
        let mut name = found.whole();
        if let Some(after) = self.after {
            name = after(name);
        }
        Some(NameFixResult::new(
            name,
            self.method,
            checker,
            self.confidence,
        ))
    }
}

fn first_match(rules: &[Rule], checker: &str, textstring: &str) -> Option<NameFixResult> {
    rules
        .iter()
        .find_map(|rule| rule.apply(checker, textstring))
}
