use super::{Ctx, category as cat, has_only_fans_marker, hit};
use crate::pcre::contains_ci;
use crate::{Hit, mi, rx};

macro_rules! jacquie {
    () => {
        r"Jacquie[._ -]?Et[._ -]?Michel(?:[._ -]?(?:TV|Elite))?"
    };
}
macro_rules! studios {
    () => {
        concat!(
            r"Brazzers|NaughtyAmerica|RealityKings|Bangbros|BangBros18|TeenFidelity|PornPros|SexArt|WowGirls|Vixen|Blacked|Tushy|Deeper|Bellesa|Defloration|MetArt|MetArtX|TheLifeErotic|VivThomas|JoyMii|Nubiles|NubileFilms|Anilos|FamilyStrokes|X-Art|Babes|Twistys|WetAndPuffy|WowPorn|MomsTeachSex|Mofos|BangBus|Passion-HD|EvilAngel|DorcelClub|Private|Hustler|CherryPimps|PureTaboo|Lady[._ -]?Lyne|TeamSkeet|GirlsWay|SweetSinner|NewSensations|Digital[._ -]?Playground|Wicked|Penthouse|Playboy|Kink|HardX|ArchAngel|JulesJordan|ManuelFerrara|LesbianX|AllAnal|DarkX|Elegant[._ -]?Angel|ZeroTolerance|Score|PornFidelity|Kelly[._ -]?Madison|DDF[._ -]?Network|21Sextury|21Naturals|Colette|SexMex|Bang|SpankBang|PornWorld|LegalPorno|AnalVids|GonzoXXX|RoccoSiffredi|Fake[._ -]?Hub|FakeAgent|FakeTaxi|FakeHostel|PublicAgent|StrandedTeens|Property[._ -]?Sex|Dane[._ -]?Jones|Lets[._ -]?Doe[._ -]?It|Office[._ -]?Obsession|SexyHub|Massage[._ -]?Rooms|Fitness[._ -]?Rooms|Female[._ -]?Agent|MissaX|All[._ -]?Girl[._ -]?Massage|Fantasy[._ -]?Massage|Nurumassage|Soapymassage|Reality[._ -]?Junkies|Perv[._ -]?Mom|Bad[._ -]?Milfs|Milf[._ -]?Body|Step[._ -]?Siblings|Sis[._ -]?Loves[._ -]?Me|Brother[._ -]??Crush|Dad[._ -]?Crush|Mom[._ -]?Knows[._ -]?Best|Bratty[._ -]?Sis|My[._ -]?Family[._ -]?Pies|Family[._ -]?Therapy|Nubiles[._ -]?Porn|Step[._ -]?Fantasy|Caught[._ -]?Fapping|She[._ -]?Will[._ -]?Cheat|Dirty[._ -]?Wives[._ -]?Club|Big[._ -]?Tits[._ -]?Round[._ -]?Asses|Ass[._ -]?Parade|Monsters[._ -]?Of[._ -]?Cock|Brown[._ -]?Bunnies|Teens[._ -]?Love[._ -]?Huge[._ -]?Cocks|Ass[._ -]?Masterpiece|Bang[._ -]?Casting|Holed|Tiny4K|Lubed|POVD|Exotic4K|CastingCouch[._ -]?X|Casting[._ -]?Couch|Creampie[._ -]?Angels|Digital[._ -]?Desire|Femjoy|Hegre|Joymii|Met[._ -]?Art|MPL[._ -]?Studios|Rylsky[._ -]?Art|Showy[._ -]?Beauty|Stunning18|Photodromm|Watch4Beauty|Wow[._ -]?Girls|Yonitale|Mommys[._ -]?Boy|AllOver30|MyFirst|10musume|Caribbeancom|Heyzo|Pacopacomama|1Pondo|TokyoHot|Mommy[._ -]?Blows[._ -]?Best|Milfs[._ -]?Like[._ -]?It[._ -]?Big|Mommy[._ -]?Got[._ -]?Boobs|My[._ -]?Friends[._ -]?Hot[._ -]?Mom|Seduced[._ -]?By[._ -]?A[._ -]?Cougar|Hot[._ -]?Mom[._ -]?Next[._ -]?Door|",
            jacquie!()
        )
    };
}
macro_rules! keywords {
    () => {
        r"Anal|Ass|BBW|BDSM|Blow|Boob|Bukkake|Casting|Couch|Cock|Compilation|Creampie|Cum|Dick|Dildo|Facial|Fetish|Fuck|Gang|Hardcore|Homemade|Horny|Interracial|Lesbian|MILF|Masturbat|Nympho|Oral|Orgasm|Penetrat|Pornstar|POV|Pussy|Riding|Seduct|Sex|Shaved|Slut|Squirt|Suck|Swallow|Threesome|Tits|Titty|Toy|Virgin|Whore"
    };
}
macro_rules! vr_sites {
    () => {
        r"SexBabesVR|LittleCapriceVR|VRoomed|VRMagic|TonightsGirlfriend|NaughtyAmericaVR|BaDoinkVR|WankzVR|VRBangers|StripzVR|RealJamVR|TmwVRnet|MilfVR|KinkVR|CzechVR(?:Fetish)?|HoloGirlsVR|WetVR|XSinsVR|VRCosplayX|BIBIVR|SLR|SexLikeReal|CutiesVR|18VR|PornCornVR|DarkRoomVR|JackAndJillVR|PasstroughVR|JUVR|KIWVR|KAVR|NKKVR|VRSPy|BadoinkVR|SinsVR|FuckPassVR|CRVR|CatalinaCruzVR|StockingsVR|VirtualRealPorn|VRHush|VRConk|SwallowBay"
    };
}
macro_rules! generic_vr {
    () => {
        r"\b[A-Z][A-Za-z0-9]{2,}VR(?:\.com|\.net|\.tv)?\b"
    };
}
macro_rules! vr_devices {
    () => {
        r"GearVR|Oculus|Quest[123]?|PSVR|Vive|Index|Pimax"
    };
}

fn has_keyword(name: &str) -> bool {
    mi!(name, concat!(r"\b(", keywords!(), r")\b"))
}

fn has_studio(name: &str) -> bool {
    mi!(name, concat!(r"\b(", studios!(), r")\b"))
}

fn has_vr_site(name: &str) -> bool {
    mi!(name, concat!(r"\b(", vr_sites!(), r")\b"))
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if !has_only_fans_marker(name) && !looks_like_xxx(name) {
        return None;
    }
    only_fans(name)
        .or_else(|| vr(name))
        .or_else(|| uhd(name))
        .or_else(|| clip_hd(name))
        .or_else(|| mi!(name, r"[ .]PACK[ .]").then_some(hit(cat::XXX_PACK, 0.85, "pack")))
        .or_else(|| clip_sd(name, ctx.poster))
        .or_else(|| mi!(name, r"SDX264XXX|XXX\.HR\.").then_some(hit(cat::XXX_SD, 0.85, "sd")))
        .or_else(|| if ctx.web_dl { web_dl(name) } else { None })
        .or_else(|| x264(name))
        .or_else(|| {
            mi!(name, r"(b[dr]|dvd)rip|detoxication|divx|nympho|pornolation|swe6|tesoro|xvid")
                .then_some(hit(cat::XXX_XVID, 0.8, "xvid"))
        })
        .or_else(|| {
            mi!(name, r"IMAGESET|PICTURESET|ABPEA").then_some(hit(cat::XXX_IMAGESET, 0.9, "imageset"))
        })
        .or_else(|| wmv(name))
        .or_else(|| mi!(name, r"dvdr[^i]|dvd[59]").then_some(hit(cat::XXX_DVD, 0.85, "dvd")))
        .or_else(|| {
            mi!(
                name,
                r"[._ -]Brazzers|Creampie|[._ -]JAV[._ -]|North\.Pole|She[._ -]?Male|Transsexual|OLDER ANGELS"
            )
            .then_some(hit(cat::XXX_OTHER, 0.7, "other"))
        })
}

fn looks_like_xxx(name: &str) -> bool {
    if mi!(name, r"\bXXX\b") {
        return true;
    }
    let studio = rx!(i concat!(r"\b(", studios!(), r")\b"));
    if let Some(caps) = studio.captures(name.as_bytes()) {
        let matched = &caps[1];
        // A studio word that opens a dated movie name, like Wicked.2024.1080p.WEB-DL, is a
        // movie unless something else says otherwise.
        let bytes = name.as_bytes();
        let prefixed = bytes.len() > matched.len()
            && bytes[..matched.len()].eq_ignore_ascii_case(matched)
            && matches!(bytes[matched.len()], b'.' | b'-' | b'_' | b' ');
        if prefixed {
            let rest = &bytes[matched.len() + 1..];
            let movie_shape = rx!(i
                r"^(?:[A-Za-z]+[.\-_ ])*(?:19|20)\d{2}[.\-_ ](?:720p|1080p|2160p|4K|UHD)"
            );
            if movie_shape.is_match(rest)
                && mi!(
                    name,
                    r"\b(WEB-?DL|WEBRip|BluRay|BDRip|HDRip|HDTV|DVDRip|Remux|PROPER|REPACK|HC|KORSUB)\b"
                )
                && !has_keyword(name)
            {
                return false;
            }
        }
        return true;
    }
    if has_vr_site(name) || mi!(name, generic_vr!()) {
        return true;
    }
    if mi!(name, concat!(r"\b(?:", vr_devices!(), r")\b"))
        && mi!(name, r"\b(720p|1080p|2160p|4K|VR180|VR360)\b")
    {
        return true;
    }
    if has_keyword(name) && mi!(name, r"\b(720p|1080p|2160p|4k|mp4|mkv|avi|wmv)\b") {
        return true;
    }
    // The studio branch above already returned, so the PHP JAV+studio check can't fire.
    if mi!(
        name,
        r"^[A-Za-z]+[.\-_ ](19|20)?\d{2}[.\-_ ]\d{2}[.\-_ ]\d{2}[.\-_ ][A-Za-z]"
    ) {
        if has_keyword(name) {
            return true;
        }
        if mi!(name, r"\d{2}[.\-_ ]([a-z]+)[.\-_ ]([a-z]+)[.\-_ ]")
            && !mi!(name, r"\b(S\d{1,2}E\d{1,2}|Episode|Season|HDTV|PDTV)\b")
        {
            return true;
        }
    }
    false
}

fn only_fans(name: &str) -> Option<Hit> {
    let spaced = name.replace('_', " ");
    if mi!(
        spaced,
        r"\b(photo(?:set)?s?|image(?:set)?s?|pics?|wallpapers?|collections?|packs?)\b"
    ) && !mi!(
        spaced,
        r"\b(mp4|mkv|mov|wmv|avi|webm|h\.?264|x264|h\.?265|x265)\b"
    ) {
        return None;
    }
    has_only_fans_marker(name).then_some(hit(cat::XXX_ONLYFANS, 0.95, "onlyfans"))
}

fn vr(name: &str) -> Option<Hit> {
    let known = has_vr_site(name);
    let generic = mi!(name, generic_vr!());
    let device = mi!(name, concat!(r"\b(?:", vr_devices!(), r")\b"));
    let vr180 = mi!(name, r"\bVR(?:180|360)\b");
    if !vr180 && !(known || generic) && !device {
        return None;
    }
    if known {
        return Some(hit(cat::XXX_VR, 0.95, "vr_site"));
    }
    if generic {
        return Some(hit(cat::XXX_VR, 0.9, "vr_site_generic"));
    }
    let xxx = mi!(name, r"\bXXX\b");
    if device && (xxx || has_keyword(name) || has_studio(name)) {
        return Some(hit(cat::XXX_VR, 0.9, "vr_device"));
    }
    if vr180 && (xxx || has_keyword(name) || has_vr_site(name)) {
        return Some(hit(cat::XXX_VR, 0.9, "vr_180_360"));
    }
    None
}

fn uhd(name: &str) -> Option<Hit> {
    if !mi!(name, r"\b(2160p|4k|UHD|Ultra[._ -]?HD)\b") {
        return None;
    }
    if !(mi!(name, r"\bXXX\b")
        || has_studio(name)
        || mi!(
            name,
            r"\b(Hardcore|Porn|Sex|Anal|Creampie|MILF|Lesbian|Teen|Interracial)\b"
        ))
    {
        return None;
    }
    if mi!(
        name,
        r"XXX.+2160p[\w\-.]+M[PO][V4]-(KTR|GUSH|FaiLED|SEXORS|hUSHhUSH|YAPG|WRB|NBQ|FETiSH)"
    ) {
        return Some(hit(cat::XXX_UHD, 0.95, "uhd_group"));
    }
    Some(hit(cat::XXX_UHD, 0.9, "uhd"))
}

fn clip_hd(name: &str) -> Option<Hit> {
    if mi!(
        name,
        r"^(Complete|Pack|Collection|Anthology|Siterip|SiteRip)\b"
    ) {
        return None;
    }
    if mi!(name, r"\b(S\d{1,2}E\d{1,2}|S\d{1,2}|Season\s\d{1,2})\b") {
        return None;
    }
    let has_hd = mi!(name, r"\b(720p|1080p|2160p|HD|4K)\b");

    // Expand separators only for the explicit new site; shared studio words can be titles.
    if mi!(
        name,
        concat!(
            r"^(?:(?:",
            studios!(),
            r")\.|",
            jacquie!(),
            r"[.\-_ ])([A-Z][a-z]+).*?(720p|1080p|2160p|HD|4K)"
        )
    ) {
        return Some(hit(cat::XXX_CLIPHD, 0.9, "clip_hd_studio"));
    }
    if mi!(
        name,
        concat!(
            r"^(",
            studios!(),
            r")[.\-_ ](19|20)?\d{2}[.\-_ ]\d{2}[.\-_ ]\d{2}"
        )
    ) {
        return Some(if has_hd {
            hit(cat::XXX_CLIPHD, 0.95, "clip_hd_studio_date")
        } else {
            hit(cat::XXX_X264, 0.85, "studio_date")
        });
    }
    let not_tv = !mi!(name, r"\b(S\d{2}E\d{2}|Documentary|Series)\b");
    if mi!(
        name,
        r"^([A-Z][a-zA-Z0-9]+)[.\-_ ](19|20)\d{2}[.\-_ ]\d{2}[.\-_ ]\d{2}[.\-_ ]"
    ) && not_tv
        && (has_hd || has_keyword(name))
    {
        return Some(hit(cat::XXX_CLIPHD, 0.85, "clip_hd_date_4digit"));
    }
    if mi!(
        name,
        r"^([A-Z][a-zA-Z0-9]+)\.(\d{2})\.(\d{2})\.(\d{2})\..*?(720p|1080p|2160p|HD|4K)"
    ) && not_tv
    {
        return Some(hit(cat::XXX_CLIPHD, 0.85, "clip_hd_date"));
    }
    if mi!(name, concat!(r"^(", studios!(), r")[.\-_ ](\d{6})")) {
        return Some(if has_hd {
            hit(cat::XXX_CLIPHD, 0.9, "clip_hd_jav_date")
        } else {
            hit(cat::XXX_X264, 0.85, "jav_date")
        });
    }
    if mi!(
        name,
        concat!(
            r"^(",
            studios!(),
            r")[.\-_ ].*\bXXX\b.*?(720p|1080p|2160p|HD|4K)"
        )
    ) {
        return Some(hit(cat::XXX_CLIPHD, 0.9, "clip_hd_studio_xxx"));
    }
    if mi!(
        name,
        r"\b(XXX|MILF|Anal|Sex|Porn)[._ -]+(720p|1080p|2160p|HD|4K)\b"
    ) || mi!(
        name,
        r"\b(720p|1080p|2160p|HD|4K)[._ -]+(XXX|MILF|Anal|Sex|Porn)\b"
    ) {
        return Some(hit(cat::XXX_CLIPHD, 0.8, "clip_hd_xxx"));
    }
    None
}

fn clip_sd(name: &str, poster: &str) -> Option<Hit> {
    if mi!(poster, r"anon@y[.]com|@md-hobbys[.]com|oz@lot[.]com") {
        return Some(hit(cat::XXX_CLIPSD, 0.85, "clip_sd_poster"));
    }
    if mi!(name, r"(iPT\sTeam|KLEENEX)") || contains_ci(name, "SDPORN") {
        return Some(hit(cat::XXX_CLIPSD, 0.85, "clip_sd"));
    }
    None
}

fn web_dl(name: &str) -> Option<Hit> {
    if mi!(name, r"\b(S\d{1,2}E\d{1,2})\b") {
        return None;
    }
    (mi!(name, r"web[._ -]dl|web-?rip")
        && (has_keyword(name) || has_studio(name) || mi!(name, r"\b(XXX|Porn|Adult|JAV|Hentai)\b")))
    .then_some(hit(cat::XXX_WEBDL, 0.85, "webdl"))
}

fn x264(name: &str) -> Option<Hit> {
    if mi!(name, r"\b(x265|hevc)\b")
        || !mi!(name, r"\b((x|h)[\.\-_ ]?264|AVC)\b")
        || mi!(name, r"\bwmv\b|S\d{1,2}E\d{1,2}|\d+x\d+")
    {
        return None;
    }
    mi!(
        name,
        concat!(
            r"\bXXX\b|a\.b\.erotica|BangBros|",
            jacquie!(),
            r"|Cum|Defloration|Err?oticax?|JoyMii|MetArt|Nubiles|Porn|SexArt|Tushy|Vixen|JAV|Brazzers|NaughtyAmerica|RealityKings"
        )
    )
    .then_some(hit(cat::XXX_X264, 0.85, "x264"))
}

fn wmv(name: &str) -> Option<Hit> {
    if mi!(
        name,
        r"\b(720p|1080p|2160p|x264|x265|h264|h265|hevc|XviD|MP4-|\.mp4)[._ -]"
    ) {
        return None;
    }
    mi!(
        name,
        r"\b(WMV|Windows\s?Media\s?Video)\b|\.wmv$|[._ -]wmv[._ -]"
    )
    .then_some(hit(cat::XXX_WMV, 0.8, "wmv"))
}
