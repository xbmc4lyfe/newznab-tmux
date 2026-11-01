use super::{Ctx, category as cat, has_adult_markers, hit, looks_like_tv_season};
use crate::{Hit, mi};

pub fn should_skip(ctx: &Ctx) -> bool {
    has_adult_markers(ctx.name) || looks_like_tv_season(ctx.name)
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if mi!(name, r"^PS4[_\.\-]")
        || mi!(name, r"CUSA\d{5}")
        || mi!(name, r"\.PS4-DUPLEX$")
        || mi!(name, r"\bPS4\b|PlayStation\s*4")
    {
        return Some(hit(cat::GAME_PS4, 0.9, "ps4"));
    }
    if mi!(name, r"\bPS3\b|PlayStation\s*3") {
        return Some(hit(cat::GAME_PS3, 0.9, "ps3"));
    }
    if mi!(name, r"\bPS\s?Vita\b|PSV(ita)?\b") {
        return Some(hit(cat::GAME_PSVITA, 0.9, "psvita"));
    }
    if mi!(name, r"\bPSP\b|PlayStation\s*Portable") {
        return Some(hit(cat::GAME_PSP, 0.9, "psp"));
    }
    if mi!(
        name,
        r"\b(XboxOne|XBOX\s*One|XBONE|XB1|Xbox\s*Series[._ -]?[SX]|XSX|XSS)\b"
    ) {
        return Some(hit(cat::GAME_XBOXONE, 0.9, "xboxone"));
    }
    if mi!(name, r"\b(Xbox360|XBOX360|X360)\b") {
        return Some(hit(cat::GAME_XBOX360, 0.9, "xbox360"));
    }
    if mi!(name, r"\bXBOX\b") && !mi!(name, r"\b(XBOX\s?360|XBOX\s?ONE|Series)\b") {
        return Some(hit(cat::GAME_XBOX, 0.85, "xbox"));
    }
    if mi!(name, r"\bWii\s*U\b|WiiU") {
        return Some(hit(cat::GAME_WIIU, 0.9, "wiiu"));
    }
    if mi!(name, r"\bWii\b") && !mi!(name, r"WiiU") {
        return Some(hit(cat::GAME_WII, 0.85, "wii"));
    }
    if mi!(name, r"\b3DS\b|Nintendo\s*3DS") {
        return Some(hit(cat::GAME_3DS, 0.9, "3ds"));
    }
    if mi!(name, r"\bNDS\b|Nintendo\s*DS") {
        return Some(hit(cat::GAME_NDS, 0.9, "nds"));
    }
    if mi!(
        name,
        r"\b(PS[12X]|PS2|SNES|NES|SEGA|GB[AC]?|Dreamcast|Saturn|Atari|N64)\b"
    ) && mi!(name, r"\b(EUR|JP|JPN|NTSC|PAL|USA|ROM)\b")
    {
        return Some(hit(cat::GAME_OTHER, 0.8, "retro_console"));
    }
    None
}
