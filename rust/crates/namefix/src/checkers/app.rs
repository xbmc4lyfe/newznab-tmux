//! Software releases: keygen/crack, vendors, platforms, then the generic versioned pattern.

use std::sync::LazyLock;

use nntmux_categorize::rx;

use super::{Rule, first_match};
use crate::result::NameFixResult;

pub(super) fn check(textstring: &str) -> Option<NameFixResult> {
    first_match(rules(), "App", textstring)
}

pub(super) fn rules() -> &'static [Rule] {
    static RULES: LazyLock<Vec<Rule>> = LazyLock::new(|| {
        vec![
            // WITH_KEYGEN
            Rule::bytes(
                "Apps with keygen/patch",
                0.90,
                rx!(i r"\w[\-\w.',;& ]+(\d{1,10}|v\d+[\.\d]*|Linux|UNIX|MacOS)[._ -](RPM|DEB)?[._ -]?(X64|X86|ARM64)?[._ -]?(Incl|With)?[._ -]?(Keygen|Patch|Crack|Serial|License)[\-\w.',;& ]+\w"),
            ),
            // ADOBE
            Rule::bytes(
                "Adobe apps",
                0.85,
                rx!(i r"\w[\-\w.',;& ]*(Adobe|Photoshop|Illustrator|Premiere|After[._ -]?Effects|InDesign|Lightroom)[._ -][\-\w.',;& ]+\w"),
            ),
            // MICROSOFT
            Rule::bytes(
                "Microsoft apps",
                0.85,
                rx!(i r"\w[\-\w.',;& ]*(Microsoft|Office|Windows|Visual[._ -]?Studio)[._ -]\d{2,4}[._ -][\-\w.',;& ]+\w"),
            ),
            // WINDOWS
            Rule::bytes(
                "Windows apps",
                0.80,
                rx!(i r"\w[\-\w.',;& ]+\d{1,8}[._ -](winall|win32|win64|x64|x86)[._ -]?(freeware|portable|repack)?[\-\w.',;& ]+\w"),
            ),
            // MACOS
            Rule::bytes(
                "macOS apps",
                0.80,
                rx!(i r"\w[\-\w.',;& ]+(MacOS|Mac[._ -]?OS[._ -]?X|OSX)[._ -][\-\w.',;& ]+\w"),
            ),
            // LINUX
            Rule::bytes(
                "Linux apps",
                0.80,
                rx!(i r"\w[\-\w.',;& ]+(Linux|Ubuntu|Debian|CentOS|RHEL|Fedora)[._ -](x64|x86|arm64)?[\-\w.',;& ]+\w"),
            ),
            // WITH_VERSION
            Rule::bytes(
                "Software with version",
                0.70,
                rx!(i r"\w[\-\w.',;& ]+[._ -]v?\d+[\.\d]+[._ -](Multilingual|MULTi|Portable|Repack|Cracked)[\-\w.',;& ]+\w"),
            ),
        ]
    });
    &RULES
}
