//! Game releases: console patterns first, then PC scene groups, DLC, repacks.

use std::sync::LazyLock;

use nntmux_categorize::rx;

use super::{Rule, first_match};
use crate::result::NameFixResult;

pub(super) fn check(textstring: &str) -> Option<NameFixResult> {
    first_match(rules(), "Game", textstring)
}

pub(super) fn rules() -> &'static [Rule] {
    static RULES: LazyLock<Vec<Rule>> = LazyLock::new(|| {
        vec![
            // MODERN_CONSOLE
            Rule::bytes(
                "Modern console release",
                0.90,
                rx!(i r"\w[\-\w.',;& ]+(NSW|PS[345P]|PSV|XBSX|XSX|XBOX[._ -]?SERIES[._ -]?[XS]|XBOX[._ -]?ONE|XBOX360?|WiiU?|Switch)[._ -](INTERNAL|PROPER|READNFO|READ[._ -]?NFO|MULTI\d{1,2})?[._ -]?[\-\w.',;& ]+\-[A-Za-z0-9]+$"),
            ),
            // REGION_CONSOLE
            Rule::bytes(
                "Videogames with region",
                0.90,
                rx!(i r"\w[\-\w.',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS[345P]|PSP|PSV|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[\-\w.',;& ]+\w"),
            ),
            // CONSOLE_SCENE_GROUP
            Rule::bytes(
                "Console with scene group",
                0.90,
                rx!(i r"\w[\-\w.',;& ]+(GC|NDS|NGC|PS[345P]|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[._ -](CODEX|DUPLEX|PLAZA|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|TiNYiSO|ANOMALY|iNSOMNi|OneUp|STRANGE|SWAG|SKY|SUXXORS)[\-\w.',;& ]+\w"),
            ),
            // PC_SCENE_GROUP
            Rule::bytes(
                "PC game with scene group",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+(PC|WIN(32|64)?|MAC(OSX?)?|LINUX)[._ -]?(CODEX|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|GOG|PROPHET|TiNYiSO|PLAZA|P2P|SiMPLEX|rG)[\-\w.',;& ]+\w"),
            ),
            // DLC_UPDATE
            Rule::bytes(
                "DLC/Update",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+(DLC|Update|Patch|Hotfix)[._ -](v?\d+[\.\d]*)?[._ -]?(CODEX|SKIDROW|RELOADED|PLAZA|EMPRESS|FLT|GOG|P2P|TiNYiSO)[\-\w.',;& ]+\w"),
            ),
            // OUTLAWS
            Rule::bytes("PC Games -OUTLAWS", 0.85, rx!(i r"\w[\w.',;-].+-OUTLAWS"))
                .then(outlaws_tag),
            // ALIAS
            Rule::bytes("PC Games -ALiAS", 0.85, rx!(i r"\w[\w.',;-].+\-ALiAS")).then(alias_tag),
            // GOG
            Rule::bytes(
                "GOG release",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+[._ -]GOG[._ -]?(Classic|Galaxy)?[\-\w.',;& ]+\w"),
            ),
            // REPACK
            Rule::bytes(
                "Game REPACK",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+[._ -](REPACK|RIP)[._ -](FitGirl|DODI|xatab|R\.G\.|Mechanics)[\-\w.',;& ]+\w"),
            ),
        ]
    });
    &RULES
}

/// The PC scene group names get a platform tag so the name categorizes as a game.
fn outlaws_tag(name: String) -> String {
    name.replace("OUTLAWS", "PC GAME OUTLAWS")
}

fn alias_tag(name: String) -> String {
    name.replace("-ALiAS", " PC GAME ALiAS")
}
