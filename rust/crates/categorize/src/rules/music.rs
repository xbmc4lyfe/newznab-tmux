use super::{Ctx, category as cat, has_adult_markers, hit, looks_like_tv_season};
use crate::{Hit, mi};

pub fn should_skip(ctx: &Ctx) -> bool {
    has_adult_markers(ctx.name) || looks_like_tv_season(ctx.name)
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    audiobook(ctx.name)
        .or_else(|| podcast(ctx.name))
        .or_else(|| music_video(ctx))
        .or_else(|| lossless(ctx))
        .or_else(|| mp3(ctx))
        .or_else(|| other(ctx))
}

fn is_foreign(ctx: &Ctx) -> bool {
    ctx.foreign
        && mi!(
            ctx.name,
            r"(?:^|[\s\.\-_])(?:arabic|brazilian|bulgarian|cantonese|chinese|croatian|czech|danish|deutsch|dutch|estonian|finnish|flemish|french|german|greek|hebrew|hungarian|icelandic|indian|iranian|italian|japanese|korean|latin|latvian|lithuanian|macedonian|mandarin|nordic|norwegian|persian|polish|portuguese|romanian|russian|serbian|slovenian|spanish|spanisch|swedish|thai|turkish|ukrainian|vietnamese|ar|bg|bl|cs|cz|da|de|dk|el|es|et|fi|fr|ger|gr|heb|hr|hu|hun|is|it|ita|jp|jap|ko|kor|lt|lv|mk|nl|no|pl|pt|ro|rs|ru|se|sk|sl|sr|sv|th|tr|ua|vi|zh)(?:$|[\s\.\-_])"
        )
}

fn audiobook(name: &str) -> Option<Hit> {
    if mi!(
        name,
        r"(?:^|[^a-zA-Z0-9])(?:Audiobook|Audio\s*Book|Talking\s*Book|ABEE|Audible)"
    ) && (mi!(
        name,
        r"\b(?:Unabridged|Abridged|Narrated|Narrator|MP3|M4A|M4B|AAC|Read\s+By|Tantor|Blackstone|Brilliance|GraphicAudio|Penguin|Audible)\b"
    ) || mi!(name, r"\d+\s*CDs|\d+\s*Hours|Spoken\s+Word")
        || mi!(name, r"\.(mp3|m4a|m4b|aac|flac|ogg|wma)$"))
    {
        return Some(hit(cat::MUSIC_AUDIOBOOK, 0.95, "audiobook"));
    }
    if mi!(name, r"(?:[\(_\[])(?:Audiobook|AB|Unabridged)(?:[\)_\]])")
        || mi!(name, r"Read\s+By\s+[A-Z][a-z]+\s+[A-Z][a-z]+")
    {
        return Some(hit(cat::MUSIC_AUDIOBOOK, 0.9, "audiobook_pattern"));
    }
    if mi!(name, r"(Audiobook|Audio.?Book)") {
        return Some(hit(cat::MUSIC_AUDIOBOOK, 0.85, "audiobook_legacy"));
    }
    None
}

fn podcast(name: &str) -> Option<Hit> {
    if mi!(
        name,
        r"(?:^|[^a-zA-Z0-9])(?:Podcast|Pod[._ -]?cast|Pod[._ -]Show)"
    ) {
        return Some(hit(cat::MUSIC_PODCAST, 0.9, "podcast"));
    }
    if mi!(
        name,
        r"\b(?:NPR|BBC[._ -]Sounds|Gimlet|Wondery|Stitcher|iHeart[._ -]?Radio|Joe[._ -]Rogan|RadioLab|Serial)\b"
    ) && mi!(name, r"\b(?:Podcast|Episode|EP?[._ -]?\d+|Show)\b")
    {
        return Some(hit(cat::MUSIC_PODCAST, 0.85, "podcast_network"));
    }
    if mi!(name, r"podcast") {
        return Some(hit(cat::MUSIC_PODCAST, 0.8, "podcast_simple"));
    }
    None
}

fn music_video(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if mi!(
        name,
        r"(?:^|[^a-zA-Z0-9])(?:Music\s*Video|Concert|Live\s*Show|Tour|Festival|MV|MTV)|\b(?:MVID|MVid)\b"
    ) && (mi!(
        name,
        r"\b(?:720p|1080[pi]|2160p|BDRip|BluRay|DVDRip|HDTV|WebRip|WEB-DL|x264|x265)\b"
    ) || mi!(
        name,
        r"\b(?:Live|Unplugged|Acoustic|World\s*Tour|in\s*Concert|Official\s*Video|Bootleg|Remastered)\b"
    ) || mi!(name, r"\.(mkv|mp4|avi|ts|m2ts|mpg|mpeg|mov|wmv|vob|m4v)$"))
    {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.85, "music_video_foreign")
        } else {
            hit(cat::MUSIC_VIDEO, 0.9, "music_video")
        });
    }
    if mi!(
        name,
        r"^[A-Z0-9][A-Za-z0-9\.\s\&'\(\)\-]+\s+\-\s+[A-Z0-9][A-Za-z0-9\.\s\&'\(\)\-]+.*?\b(720p|1080[pi]|2160p|Bluray|x264|x265)\b"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.8, "music_video_foreign")
        } else {
            hit(cat::MUSIC_VIDEO, 0.8, "music_video_artist")
        });
    }
    None
}

fn lossless(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if mi!(
        name,
        r"(?:^|[^a-zA-Z0-9])(?:FLAC|APE|WAV|ALAC|DSD|DSF|AIFF|PCM|Lossless)|\b(?:FLAC|APE|WAV|ALAC|DSD|DSF|AIFF|PCM)\b"
    ) && (mi!(
        name,
        r"\b(?:24[Bb]it|96kHz|192kHz|Hi[- ]?Res|HD[- ]?Tracks|Vinyl[- ]?Rip|CD[- ]?Rip|WEB[- ]?Rip|HDtracks|Qobuz|Tidal|MQA|SACD)\b"
    ) || mi!(name, r"\.(flac|ape|wav|aiff|dsf|dff|m4a|tak)$"))
    {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.9, "lossless_foreign")
        } else {
            hit(cat::MUSIC_LOSSLESS, 0.9, "lossless")
        });
    }
    if mi!(
        name,
        r#"\[(19|20)\d\d\][._ -]\[FLAC\]|([\(\[])flac([\)\]])|FLAC\-(19|20)\d\d\-[a-z0-9]{1,12}|\.flac"|(19|20)\d\d\sFLAC|[._ -]FLAC.+(19|20)\d\d[._ -]| FLAC$"#
    ) || mi!(
        name,
        r"\d{3,4}kbps[._ -]FLAC|\[FLAC\]|\(FLAC\)|FLACME|FLAC[._ -]\d{3,4}(kbps)?|WEB[._ -]FLAC"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.85, "flac_foreign")
        } else {
            hit(cat::MUSIC_LOSSLESS, 0.85, "flac")
        });
    }
    if mi!(
        name,
        r"\b(?:APE|Monkey's[._ -]Audio|WavPack|WV|TAK|TTA|ALAC|Apple[._ -]Lossless)\b|\.(ape|wv|tak|tta)$"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.85, "lossless_format_foreign")
        } else {
            hit(cat::MUSIC_LOSSLESS, 0.85, "lossless_format")
        });
    }
    None
}

fn mp3(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if mi!(
        name,
        r"(?:^|[^a-zA-Z0-9])(?:MP3|320kbps|256kbps|192kbps|128kbps|CBR|VBR)|\b(?:MP3)\b|[\._-](?:MP3)[\._-]|\.mp3$"
    ) && (mi!(
        name,
        r"\b(?:320|256|192|128)[._-]?kbps|\b(?:320|256|192|128)[._-]?K|\((?:320|256|192|128)\)|\[(?:320|256|192|128)\]|V0|V2|VBR"
    ) || mi!(
        name,
        r"\b(?:CD[._-]?Rip|Web[._-]?Rip|WEB|iTunes|AmazonRip|Spotify[._-]?Rip|MP3\s*\-\s*\d{3}kbps)\b"
    ) || mi!(name, r#"\.(m3u|mp3)"|rip(?:192|256|320)|[._-]FM[._-].+MP3"#))
    {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.85, "mp3_foreign")
        } else {
            hit(cat::MUSIC_MP3, 0.85, "mp3")
        });
    }
    if mi!(
        name,
        r"^[a-zA-Z0-9]{1,12}[._-](19|20)\d\d[._-][a-zA-Z0-9]{1,12}$|[a-z0-9]{1,12}\-(19|20)\d\d\-[a-z0-9]{1,12}"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.75, "mp3_scene_foreign")
        } else {
            hit(cat::MUSIC_MP3, 0.75, "mp3_scene")
        });
    }
    if mi!(
        name,
        r"[\.\-\(\[_ ]\d{2,3}k[\.\-\)\]_ ]|\((192|256|320)\)|(320|cd|eac|vbr)[._-]+mp3|(cd|eac|mp3|vbr)[._-]+320"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.8, "mp3_bitrate_foreign")
        } else {
            hit(cat::MUSIC_MP3, 0.8, "mp3_bitrate")
        });
    }
    None
}

fn other(ctx: &Ctx) -> Option<Hit> {
    let name = ctx.name;
    if mi!(
        name,
        r"(?:^|[^a-zA-Z0-9])(?:Compilation|Various[._ -]Artists|OST|Soundtrack|B-Sides|Greatest[._ -]Hits|Anthology)|\b(?:VA|V\.A|Bonus[._ -]Track|Discography|Box[._ -]Set)\b"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.8, "music_other_foreign")
        } else {
            hit(cat::MUSIC_OTHER, 0.8, "music_other")
        });
    }
    if mi!(
        name,
        r"(?:\d)[._ -](?:CD|Albums|LP)[._ -](?:Set|Compilation)|CD[._ -](Collection|Box|SET)|(\d)-?CD[._ -]"
    ) || mi!(
        name,
        r"Vinyl[._ -](?:24[._ -]96|2496|Collection|RIP)|WEB[._ -](?:Single|Album)|EP[._ -]\d{4}|\bEP\b.+(?:19|20)\d\d|Live[._ -](?:at|At|@)"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.75, "music_album_foreign")
        } else {
            hit(cat::MUSIC_OTHER, 0.75, "music_album")
        });
    }
    if mi!(
        name,
        r"\b(?:Ministry[._ -]of[._ -]Sound|Hed[._ -]Kandi|Cream|Fabric[._ -]Live|Ultra[._ -]Music)\b"
    ) || mi!(
        name,
        r"\b(?:DJ[._ -]Mix|Mixed[._ -]By|Tiesto[._ -]Club|Radio[._ -]Show|Club[._ -]Hits)\b"
    ) {
        return Some(if is_foreign(ctx) {
            hit(cat::MUSIC_FOREIGN, 0.75, "music_dj_foreign")
        } else {
            hit(cat::MUSIC_OTHER, 0.75, "music_dj")
        });
    }
    None
}
