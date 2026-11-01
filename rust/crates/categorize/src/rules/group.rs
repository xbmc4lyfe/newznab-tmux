use super::{Ctx, category as cat, hit};
use crate::{Hit, mi};

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    let group = ctx.group;
    // PHP's empty() is also true for the string "0".
    if group.is_empty() || group == "0" {
        return None;
    }
    if mi!(group, r"alt\.binaries\..*anime") {
        return Some(hit(cat::TV_ANIME, 0.8, "group_name_anime"));
    }
    if mi!(group, r"alt\.binaries\..*?(tv|hdtv|tvseries)") {
        return Some(hit(cat::TV_OTHER, 0.6, "group_name_tv"));
    }
    if mi!(group, r"alt\.binaries\..*?(movies?|dvd|bluray|x264)") {
        return Some(hit(cat::MOVIE_OTHER, 0.6, "group_name_movie"));
    }
    if mi!(group, r"alt\.binaries\..*?(erotica|pictures\.erotica|xxx)") {
        return Some(hit(cat::XXX_OTHER, 0.7, "group_name_xxx"));
    }
    if mi!(group, r"alt\.binaries\..*?(sounds?|mp3|music|lossless)") {
        return Some(hit(cat::MUSIC_OTHER, 0.6, "group_name_music"));
    }
    if mi!(group, r"alt\.binaries\..*?(games?|console|psx|nintendo)") {
        return Some(hit(cat::GAME_OTHER, 0.6, "group_name_game"));
    }
    if mi!(group, r"alt\.binaries\..*?(warez|0day|apps?|software)") {
        return Some(hit(cat::PC_0DAY, 0.6, "group_name_pc"));
    }
    if mi!(group, r"alt\.binaries\..*?(e-?book|ebook|comics?)") {
        return Some(hit(cat::BOOKS_EBOOK, 0.5, "group_name_book"));
    }
    None
}
