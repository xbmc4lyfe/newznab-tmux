use super::{Ctx, category as cat, has_adult_markers, has_standalone_season_token, hit};
use crate::{Hit, mi};

macro_rules! anime_groups {
    () => {
        r"(?:^|[.\-_ \[])(URANiME|ANiHLS|HaiKU|ANiURL|SkyAnime|Erai-raws|LostYears|Vodes|SubsPlease|Judas|Ember|EMBER|YuiSubs|ASW|Tsundere-Raws|Anime-Raws|Kekkan|SSA|MoyaiSubs|QCE|Nyanpasu|UCCUSS|NS|DKB|MTBB|NanDesuKa|smol|Cleo|ToshY|Kawaiika-Raws|Beatrice-Raws|Reaktor|FLE|Commie|HorribleSubs|Doki|FFF|UTW|Coalgirls|CoalGuys|Mezashite|Vivid|Kaylith|Underwater|gg|Chihiro|Hatsuyuki|Mazui|WhyNot|Migoto|Doutei|GJM|Asenshi|Inka-Subs|Akatsuki)(?:[.\-_ \]]|$)"
    };
}

pub fn should_skip(ctx: &Ctx) -> bool {
    has_adult_markers(ctx.name)
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    // These can match names that don't follow S01E01 naming, like
    // "[Group] Show - 08 [1080p]".
    if let Some(found) = anime_strong_signals(ctx) {
        return Some(found);
    }
    if !looks_like_tv(ctx) {
        return None;
    }
    anime(ctx)
        .or_else(|| is_sport_event(name).then_some(hit(cat::TV_SPORT, 0.85, "sport")))
        .or_else(|| {
            mi!(name, r"\b(Documentary|Docu[._ -]?Series|DOCU)\b").then_some(hit(
                cat::TV_DOCU,
                0.85,
                "documentary",
            ))
        })
        .or_else(|| foreign(ctx))
        .or_else(|| {
            mi!(
                name,
                r"(S\d+).*(x265).*(rmteam|MeGusta|HETeam|PSA|ONLY|H4S5S|TrollHD|ImE)"
            )
            .then_some(hit(cat::TV_X265, 0.9, "x265_group"))
        })
        .or_else(|| {
            (ctx.web_dl && mi!(name, r"web[._ -]dl|web-?rip")).then_some(hit(
                cat::TV_WEBDL,
                0.85,
                "webdl",
            ))
        })
        .or_else(|| uhd(name))
        .or_else(|| hd(name, ctx.web_dl))
        .or_else(|| sd(name))
        .or_else(|| other(name))
}

fn looks_like_tv(ctx: &Ctx) -> bool {
    let name = ctx.name;
    has_standalone_season_token(name)
        || mi!(name, r"[._ -]s\d{1,3}[._ -]?(e|d(isc)?)\d{1,3}([._ -]|$)")
        || mi!(name, r"[._ -]E\d{1,4}[._ -]")
        || mi!(name, r"[._ -]S\d{1,3}[._ -]?(Complete|COMPLETE|Full|FULL)")
        || mi!(
            name,
            r"[._ -]S\d{1,3}[._ -](480p|720p|1080[pi]|2160p|4K|UHD|WEB|HDTV|BluRay|NF|AMZN|DSNP|ATVP|HMAX)"
        )
        || mi!(name, r"\b(Episode|Ep)[._ -]?\d{1,4}\b")
        || mi!(name, r"\b(HDTV|PDTV|DSR|TVRip|SATRip|DTHRip)\b")
        || mi!(name, r"[._ -](19|20)\d{2}[._ -]\d{2}[._ -]\d{2}[._ -]")
        || is_sport_event(name)
        || mi!(name, anime_groups!())
}

fn anime_strong_signals(ctx: &Ctx) -> Option<Hit> {
    if !ctx.poster.is_empty() && mi!(ctx.poster, r"animetosho\.org") {
        return Some(hit(cat::TV_ANIME, 0.98, "anime_tosho_poster"));
    }
    if !ctx.group.is_empty() && mi!(ctx.group, r"alt\.binaries\..*anime") {
        return Some(hit(cat::TV_ANIME, 0.9, "anime_group_name"));
    }
    if mi!(ctx.name, anime_groups!()) {
        return Some(hit(cat::TV_ANIME, 0.92, "anime_group"));
    }
    if mi!(ctx.name, r"^\[.+\].*\d{2,3}.*\[[a-fA-F0-9]{8}\]") {
        return Some(hit(cat::TV_ANIME, 0.9, "anime_hash"));
    }
    None
}

fn anime(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if !ctx.poster.is_empty() && mi!(ctx.poster, r"animetosho\.org") {
        return Some(hit(cat::TV_ANIME, 0.98, "anime_tosho_poster"));
    }
    if mi!(name, r"[._ -]Anime[._ -]") {
        return Some(hit(cat::TV_ANIME, 0.95, "anime_pattern"));
    }
    if mi!(name, anime_groups!()) {
        return Some(hit(cat::TV_ANIME, 0.95, "anime_group"));
    }
    if mi!(name, r"^\[.+\].*\d{2,3}.*\[[a-fA-F0-9]{8}\]") {
        return Some(hit(cat::TV_ANIME, 0.9, "anime_hash"));
    }
    // Japanese "no" particle with an episode-only pattern or a roman numeral season, like
    // Shingeki.no.Kyojin.
    if mi!(name, r"[._ ]no[._ ]")
        && (mi!(name, r"[._ ](I{1,3}|IV|V|VI{0,3}|IX|X)[._ ]?E\d{1,4}[._ ]")
            || (mi!(name, r"[._ ]E\d{1,4}[._ ]") && !mi!(name, r"[._ ]S\d{1,3}[._ ]?E\d")))
    {
        return Some(hit(cat::TV_ANIME, 0.9, "anime_japanese_title"));
    }
    if mi!(name, r"[._ -]E\d{1,4}[._ -]")
        && mi!(name, r"\b(BluRay|BD|BDRip)\b")
        && !mi!(name, r"\bS\d{1,3}\b")
    {
        return Some(hit(cat::TV_ANIME, 0.8, "anime_episode_bluray"));
    }
    if mi!(name, r"[._ ](I{1,3}|IV|V|VI{0,3}|IX|X)[._ ]E\d{1,4}[._ ]")
        && !mi!(name, r"[._ ]S\d{1,3}[._ ]?E\d")
    {
        return Some(hit(cat::TV_ANIME, 0.85, "anime_roman_numeral_season"));
    }
    None
}

fn foreign(ctx: &Ctx) -> Option<Hit> {
    (ctx.foreign
        && mi!(
            ctx.name,
            r"(danish|flemish|Deutsch|dutch|french|german|hebrew|nl[._ -]?sub|dub(bed|s)?|\.NL|norwegian|swedish|swesub|spanish|Staffel)[._ -]|\(german\)|Multisub"
        ))
    .then_some(hit(cat::TV_FOREIGN, 0.8, "foreign_language"))
}

fn uhd(name: &str) -> Option<Hit> {
    if mi!(name, r"S\d+[._ -]?E\d+") && mi!(name, r"2160p") {
        return Some(hit(cat::TV_UHD, 0.9, "uhd_episode"));
    }
    if mi!(name, r"[._ -]S\d+[._ -].*2160p") {
        return Some(hit(cat::TV_UHD, 0.9, "uhd_season"));
    }
    if mi!(
        name,
        r"(S\d+).*(2160p).*(Netflix|Amazon|NF|AMZN).*(TrollUHD|NTb|VLAD|DEFLATE|POFUDUK|CMRG)"
    ) {
        return Some(hit(cat::TV_UHD, 0.9, "uhd_streaming"));
    }
    None
}

fn hd(name: &str, web_dl: bool) -> Option<Hit> {
    if mi!(name, r"1080([ip])|720p|bluray") {
        return Some(hit(cat::TV_HD, 0.85, "hd_resolution"));
    }
    if !web_dl && mi!(name, r"web[._ -]dl|web-?rip") {
        return Some(hit(cat::TV_HD, 0.8, "hd_webdl_fallback"));
    }
    None
}

fn sd(name: &str) -> Option<Hit> {
    if mi!(
        name,
        r"(360|480|576)p|Complete[._ -]Season|dvdr(ip)?|dvd5|dvd9|\.pdtv|SD[._ -]TV|TVRip|NTSC|BDRip|hdtv|xvid"
    ) {
        return Some(hit(cat::TV_SD, 0.8, "sd_format"));
    }
    if mi!(name, r"(([HP])D[._ -]?TV|DSR|WebRip)[._ -]x264") {
        return Some(hit(cat::TV_SD, 0.8, "sd_codec"));
    }
    None
}

fn other(name: &str) -> Option<Hit> {
    if mi!(name, r"[._ -]s\d{1,3}[._ -]?(e|d(isc)?)\d{1,3}([._ -]|$)") {
        return Some(hit(cat::TV_OTHER, 0.6, "tv_other"));
    }
    if has_standalone_season_token(name) {
        return Some(hit(cat::TV_OTHER, 0.6, "tv_season_pack"));
    }
    None
}

fn is_sport_event(name: &str) -> bool {
    mi!(
        name,
        r"\b(NFL|NBA|NHL|MLB|MLS|EPL|UFC|WWE|Boxing|F1|Formula[._ -]?1|NASCAR|PGA|Tennis|Golf|Soccer|Football|Cricket|Rugby|Olympics?|Olympic[._ -]?Games?|Paralympics?)\b"
    ) && mi!(
        name,
        r"\d{4}|\b(Season|Week|Round|Match|Game|vs|Playoffs?|Finals?|Qualifying|Opening|Closing|Ceremony|Championship)\b"
    )
}
