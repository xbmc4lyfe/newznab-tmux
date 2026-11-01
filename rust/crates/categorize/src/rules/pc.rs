use super::{Ctx, category as cat, has_adult_markers, hit, looks_like_tv_season};
use crate::{Hit, mi};

pub fn should_skip(ctx: &Ctx) -> bool {
    has_adult_markers(ctx.name) || looks_like_tv_season(ctx.name)
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    phone(ctx.name)
        .or_else(|| mac(ctx.name))
        .or_else(|| pc_game(ctx.name, ctx.poster))
        .or_else(|| iso(ctx.name))
        .or_else(|| zero_day(ctx.name))
}

fn phone(name: &str) -> Option<Hit> {
    if mi!(name, r"[^a-z0-9](IPHONE|ITOUCH|IPAD)[._ -]") {
        return Some(hit(cat::PC_PHONE_IOS, 0.9, "ios"));
    }
    if mi!(name, r"[._ -]?(ANDROID)[._ -]") {
        return Some(hit(cat::PC_PHONE_ANDROID, 0.9, "android"));
    }
    if mi!(name, r"[^a-z0-9](symbian|xscale|wm5|wm6)[._ -]") {
        return Some(hit(cat::PC_PHONE_OTHER, 0.85, "phone_other"));
    }
    None
}

fn mac(name: &str) -> Option<Hit> {
    if mi!(name, r"(\b|[._ -])mac([\.\s])?osx(\b|[\-_. ])") {
        return Some(hit(cat::PC_MAC, 0.9, "mac"));
    }
    if mi!(name, r"\b(Mac\s?OS\s?X|macOS)\b") {
        return Some(hit(cat::PC_MAC, 0.9, "macos"));
    }
    None
}

fn pc_game(name: &str, poster: &str) -> Option<Hit> {
    if mi!(
        name,
        r"\b(PS5|PS4|PS3|PlayStation|PS(Vita|V)\b|Xbox\s?(Series|One|360)|XBOX(ONE|360|SERIES|SX|SS)?|XSX|XSS|XBSX|NSW|Switch|WiiU|Wii|3DS|NDS|PSP|PSV(ita)?|GameCube|NGC|CUSA\d{5}|XCI|NSP|PKG)\b"
    ) || mi!(name, r"\b(Mac\s?OS\s?X|macOS)\b")
    {
        return None;
    }
    if mi!(
        name,
        r"\b(S\d{1,4}[._ -]?E\d{1,4}|S\d{1,4}[._ -]?D\d{1,4}|\d{1,2}x\d{2,3}|Season[._ -]?\d{1,3}|Episode[._ -]?\d{1,4}|HDTV|PDTV|DSR|WEB[._ -]?DL|WEB[._ -]?RIP|TVRip)\b"
    ) {
        return None;
    }
    // Scene groups count only at the end of the name; keywords count anywhere.
    if mi!(
        name,
        r"[-.](0x0007|ALiAS|ANOMALY|BACKLASH|BAT|BLASTCiTY|CHRONOS|CODEX|CONSPIRACY|CPY|CROSSFiRE|DARKS(?:iDERS|IDERS)|DELiGHT|DEViANCE|DINOByTES|DOGE|DODI|DVN|ELAMIGOS|EMPRESS|ENiGMA|FANiSO|FAS(?:DOX|iSO)|FCKDRM|FITGIRL|FLT|FLTDOX|GGS|GOG(?:-?GAMES)?|GOLDBERG|HATRED|HI2U|HOODLUM|I_KnoW|iNLAWS|iNTERNAL|JAGUAR|KACZKRULL|KAOS|KaOsKrew|LAZARUS|LiGHTFORCE|MEPHISTO|OUTLAWS|PARADOX|PHOENIX|PLAZA|POSTMORTEM|PROPHET|PROVOKED|RADIUM|RAZOR(?:1911|DOX)|RELOADED|REVOLUTiON|RUNE|SAFARi|SCRUBS|SiLENTGATE|SiMPLEX|SKIDROW|SPLiNTERCELL|STEAMPUNKS|SUXXORS|TENOKE|TiNYiSO|UNLEASHED|VENGEANCE|ViTALiTY|VENOM|VACE|ZEKE|P2P)$"
    ) || mi!(
        name,
        r"PC[ _.-]?GAMES?|\[PC\]|\(PC\)|Steam[._-]?Rip|Retail\s*PC|DRM-?Free"
    ) {
        return Some(hit(cat::PC_GAMES, 0.9, "pc_game"));
    }
    if mi!(poster, r"<PC@MASTER\.RACE>") {
        return Some(hit(cat::PC_GAMES, 0.85, "pc_game_poster"));
    }
    None
}

fn iso(name: &str) -> Option<Hit> {
    if mi!(
        name,
        r"[._ -]([a-zA-Z]{2,10})?iso[ _.-]|[\-. ]([a-z]{2,10})?iso$"
    ) {
        return Some(hit(cat::PC_ISO, 0.85, "iso"));
    }
    if mi!(
        name,
        r"[._ -](DYNAMiCS|INFINITESKILLS|UDEMY|kEISO|PLURALSIGHT|DIGITALTUTORS|TUTSPLUS|OSTraining|PRODEV|CBT\.Nuggets|COMPRISED)"
    ) {
        return Some(hit(cat::PC_ISO, 0.9, "training_iso"));
    }
    None
}

fn zero_day(name: &str) -> Option<Hit> {
    if mi!(name, r"\.(msix|msixbundle|appx|appxbundle|msi)$") {
        return Some(hit(cat::PC_0DAY, 0.9, "0day_msix_installer"));
    }
    if mi!(
        name,
        r"[._ -]exe$|[._ -](utorrent|Virtualbox)[._ -]|\b0DAY\b|incl.+crack| DRM$|>DRM<"
    ) {
        return Some(hit(cat::PC_0DAY, 0.9, "0day_explicit"));
    }
    if mi!(
        name,
        r"[._ -]((32|64)bit|converter|i\d86|key(gen|maker)|freebsd|GAMEGUiDE|hpux|irix|linux|multilingual|Patch|Pro v\d{1,3}|portable|regged|software|solaris|template|unix|win2kxp2k3|win64|win(2k|32|64|all|dows|nt(2k)?(xp)?|xp)|win9x(me|nt)?|x(32|64|86))[._ -]"
    ) {
        return Some(hit(cat::PC_0DAY, 0.85, "0day_system"));
    }
    if mi!(
        name,
        r"\b(Adobe|auto(cad|desk)|-BEAN|Cracked|Cucusoft|CYGNUS|Divx[._ -]Plus|\.(deb|exe)|DIGERATI|FOSI|-FONT|Key(filemaker|gen|maker)|Lynda\.com|lz0|MULTiLANGUAGE|Microsoft\s*(Office|Windows|Server)|MultiOS|-(iNViSiBLE|SPYRAL|SUNiSO|UNION|TE)|v\d{1,3}.*?Pro|[._ -]v\d{1,3}[._ -]|\(x(64|86)\)|Xilisoft)\b"
    ) {
        return Some(hit(cat::PC_0DAY, 0.85, "0day_software"));
    }
    None
}
