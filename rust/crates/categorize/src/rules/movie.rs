use super::{Ctx, category as cat, has_adult_markers, has_standalone_season_token, hit};
use crate::pcre::contains_ci;
use crate::{Hit, mi};

pub fn should_skip(ctx: &Ctx) -> bool {
    let name = ctx.name;
    has_adult_markers(name)
        || has_standalone_season_token(name)
        || mi!(
            name,
            r"[._ -]S\d{1,3}[._ -]?(E\d|D\d|Complete|Full|1080|720|480|2160|WEB|HDTV|BluRay|NF|AMZN)"
        )
        || mi!(name, r"[._ -]E\d{1,4}[._ -]")
        || mi!(
            name,
            r"(?:^|[.\-_ \[])(URANiME|ANiHLS|HaiKU|ANiURL|SkyAnime|Erai-raws|LostYears|Vodes|SubsPlease|Judas|Ember|YuiSubs|ASW|Tsundere-Raws|Anime-Raws)(?:[.\-_ \]]|$)"
        )
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if !mi!(
        name,
        r"[._ -]AVC|[BH][DR]RIP|(Bluray|Blu-Ray)|BD[._ -]?(25|50)?|\bBR\b|Camrip|[._ -]\d{4}[._ -].+(720p|1080p|Cam|HDTS|2160p)|DIVX|[._ -]DVD[._ -]|DVD-?(5|9|R|Rip)|Untouched|VHSRip|XVID|[._ -](DTS|TVrip|webrip|WEBDL|WEB-DL)[._ -]|\b(2160)p\b.*\b(Netflix|Amazon|NF|AMZN|Disney)\b"
    ) {
        return None;
    }
    (if ctx.foreign { foreign(name) } else { None })
        .or_else(|| {
            mi!(
                name,
                r"(\w+[\.-_\s]+).*(x265).*(Tigole|SESKAPiLE|CHD|IAMABLE|THREESOME|OohLaLa|DEFLATE|NCmt)"
            )
            .then_some(hit(cat::MOVIE_X265, 0.9, "x265_group"))
        })
        .or_else(|| uhd(name))
        .or_else(|| {
            mi!(
                name,
                r"[._ -]3D\s?[\.\-_\[ ](1080p|(19|20)\d\d|AVC|BD(25|50)|Blu[._ -]?ray|CEE|Complete|GER|MVC|MULTi|SBS|H(-)?SBS)[._ -]"
            )
            .then_some(hit(cat::MOVIE_3D, 0.9, "3d"))
        })
        .or_else(|| {
            (mi!(
                name,
                r"bluray-|[._ -]bd?[._ -]?(25|50)|blu-ray|Bluray\s-\sUntouched|[._ -]untouched[._ -]"
            ) && !mi!(name, r"SecretUsenet\.com$"))
            .then_some(hit(cat::MOVIE_BLURAY, 0.9, "bluray"))
        })
        .or_else(|| {
            mi!(name, r"(dvd\-?r|[._ -]dvd|dvd9|dvd5|[._ -]r5)[._ -]")
                .then_some(hit(cat::MOVIE_DVD, 0.85, "dvd"))
        })
        .or_else(|| {
            (ctx.web_dl && mi!(name, r"web[._ -]dl|web-?rip"))
                .then_some(hit(cat::MOVIE_WEBDL, 0.85, "webdl"))
        })
        .or_else(|| {
            if mi!(name, r"720p|1080p|AVC|VC1|VC-1|web-dl|wmvhd|x264|XvidHD|bdrip") {
                Some(hit(cat::MOVIE_HD, 0.85, "hd"))
            } else if !ctx.web_dl && mi!(name, r"web[._ -]dl|web-?rip") {
                Some(hit(cat::MOVIE_HD, 0.8, "hd_webdl_fallback"))
            } else {
                None
            }
        })
        .or_else(|| {
            mi!(name, r"(divx|dvdscr|extrascene|dvdrip|\.CAM|HDTS(-LINE)?|vhsrip|xvid(vd)?)[._ -]")
                .then_some(hit(cat::MOVIE_SD, 0.8, "sd"))
        })
        .or_else(|| {
            mi!(name, r"[._ -]cam[._ -]").then_some(hit(cat::MOVIE_OTHER, 0.6, "cam"))
        })
}

fn foreign(name: &str) -> Option<Hit> {
    if mi!(
        name,
        r"(danish|flemish|Deutsch|dutch|french|german|heb|hebrew|nl[._ -]?sub|dub(bed|s)?|\.NL|norwegian|swedish|swesub|spanish|Staffel)[._ -]|\(german\)|Multisub"
    ) {
        return Some(hit(cat::MOVIE_FOREIGN, 0.8, "foreign_language"));
    }
    if contains_ci(name, "Castellano") {
        return Some(hit(cat::MOVIE_FOREIGN, 0.8, "foreign_castellano"));
    }
    if mi!(
        name,
        r"(720p|1080p|AC3|AVC|DIVX|DVD(5|9|RIP|R)|XVID)[._ -](Dutch|French|German|ITA)|\(?(Dutch|French|German|ITA)\)?[._ -](720P|1080p|AC3|AVC|DIVX|DVD(5|9|RIP|R)|WEB(-DL|-?RIP)|HD[._ -]|XVID)"
    ) {
        return Some(hit(cat::MOVIE_FOREIGN, 0.85, "foreign_pattern"));
    }
    None
}

fn uhd(name: &str) -> Option<Hit> {
    // TV shows in streaming-group naming.
    if mi!(
        name,
        r"(S\d+).*(2160p).*(Netflix|Amazon|NF|AMZN).*(TrollUHD|NTb|VLAD|DEFLATE|CMRG)"
    ) {
        return None;
    }
    (contains_ci(name, "2160p")
        || mi!(name, r"\b(UHD|Ultra[._ -]HD|4K)\b")
        || (mi!(name, r"\b(HDR|HDR10|HDR10\+|Dolby[._ -]?Vision)\b")
            && mi!(name, r"\b(HEVC|H\.?265|x265)\b"))
        || (contains_ci(name, "UHD") && mi!(name, r"\b(BR|BluRay|Blu[._ -]?Ray)\b")))
    .then_some(hit(cat::MOVIE_UHD, 0.9, "uhd"))
}
