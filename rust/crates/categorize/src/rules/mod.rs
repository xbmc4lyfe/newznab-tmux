//! One module for each categorizer, in the order the pipeline runs them.

pub mod book;
pub mod console;
pub mod group;
pub mod misc;
pub mod movie;
pub mod music;
pub mod pc;
pub mod tv;
pub mod xxx;

use crate::Hit;

/// What a rule sees. `name` is already unwrapped and cleaned.
pub struct Ctx<'a> {
    pub name: &'a str,
    pub group: &'a str,
    pub poster: &'a str,
    pub foreign: bool,
    pub web_dl: bool,
}

pub(crate) const fn hit(category: i32, confidence: f64, by: &'static str) -> Hit {
    Hit {
        category,
        confidence,
        by,
    }
}

pub fn has_standalone_season_token(name: &str) -> bool {
    crate::mi!(name, r"(?:^|[._ -])S\d{1,3}(?:$|[._ -])")
}

pub fn has_only_fans_marker(name: &str) -> bool {
    name.starts_with("OF.") || crate::mi!(name, r"\bOnly[-_ ]?Fans\b")
}

pub fn has_adult_markers(name: &str) -> bool {
    if has_only_fans_marker(name) {
        return true;
    }
    if crate::mi!(
        name,
        r"\b(XXX|Porn|Anal|Brazzers|BangBros|Bangbros|NaughtyAmerica|RealityKings|Tushy|Vixen|Blacked|OnlyFans|MetArt|JoyMii|Creampie|MP4-XXX|PureTaboo|Lady[._ -]?Lyne|TeamSkeet|GirlsWay|EvilAngel|Kink|FakeHub|FakeTaxi|SexArt|Nubiles|Defloration|Deeper|Bellesa|Twistys|Mofos|MissaX|LegalPorno|AnalVids|JAV|Hentai|RoccoSiffredi|DivineBitches|Device[._ -]?Bondage|Hogtied|Wired[._ -]?Pussy|Fucking[._ -]?Machines|Ultimate[._ -]?Surrender|Public[._ -]?Disgrace|Sex[._ -]?And[._ -]?Submission|Bound[._ -]?Gang[._ -]?Bangs|Electro[._ -]?Sluts|Whipped[._ -]?Ass|TS[._ -]?Seduction|Infernal[._ -]?Restraints|Sexually[._ -]?Broken)\b"
    ) {
        return true;
    }
    crate::mi!(
        name,
        r"\b(Fuck|Fucked|Fucking|Cock|Dick|Pussy|Cum|Cumshot|Blowjob|Handjob|MILF|Teen|Lesbian|Threesome|Gangbang|Hardcore|Interracial)\b"
    ) && crate::mi!(name, r"\b(720p|1080p|2160p|4k|mp4)\b")
}

/// The shared "this is a TV season" skip that PC, console, music and book rules use.
pub(crate) fn looks_like_tv_season(name: &str) -> bool {
    crate::mi!(
        name,
        r"[._ -]S\d{1,3}[._ -]?(E\d|Complete|Full|1080|720|480|2160|WEB|HDTV|BluRay)"
    )
}

pub(crate) use crate::cat as category;
