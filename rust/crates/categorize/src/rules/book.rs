use super::{Ctx, category as cat, has_adult_markers, hit, looks_like_tv_season};
use crate::{Hit, mi, ms};

// The PHP pattern's ’ is three bytes in a byte-mode class, so only a single byte of it can
// match. `\xE2\x80\x99` keeps that behaviour.
macro_rules! publishers {
    () => {
        r"Apress|Addison[._ -]Wesley|Manning|No[._ -]Starch|O['\xE2\x80\x99]?Reilly|Packt|Pragmatic|Wiley|Wrox"
    };
}

const VIDEO_COURSE: &str = r"\b(?:Udemy|Pluralsight|Lynda\.?com|Coursera|LinkedIn[._ -]?Learning|Skillshare|MasterClass|CBT[._ -]?Nuggets|Digital-?Tutors)\b";

pub fn should_skip(ctx: &Ctx) -> bool {
    let name = ctx.name;
    has_adult_markers(name)
        || mi!(name, r"\.PS4-[A-Z0-9]+$")
        || mi!(
            name,
            r"\b(?:PS[1-5]|PlayStation|Xbox|Switch|Nintendo|Wii|3DS|GameCube)\b"
        )
        || mi!(name, r"\b(?:PPSA\d{4,6}|CUSA\d{5}|XCI|NSP|PKG)\b")
        || mi!(
            name,
            r"\bv?\d+(?:\.\d+){1,4}\b.*\b(?:Multilingual|x64|x86|Portable|Setup|Patch|Install(?:er)?|Crack(?:ed)?|Keygen|Serial|Regged|Pre-?Activated)\b"
        )
        || has_software_markers(name)
        || mi!(
            name,
            r"\b(?:WEB[\.\-_ ]?FLAC|FLAC|MP3|M4A|M4B|AAC|OGG|ALAC|WAV|LOSSLESS|320kbps|256kbps|192kbps|128kbps|VBR|CBR|discography|FALCON|OST|Soundtrack|Vinyl[._ -]?Rip|CD[._ -]?Rip)\b"
        )
        || mi!(name, VIDEO_COURSE)
        || has_video_release_markers(name)
        || mi!(
            name,
            r"\b(?:Ubuntu|Debian|Fedora|Kali|Arch(?:Linux)?|CentOS|OpenSUSE|LinuxMint)\b.*\b(?:ISO|img|amd64|x86_64|installer)\b"
        )
        || mi!(name, r"\b(?:ISO|IMG|DMG|bootable)\b")
        || mi!(name, r"\b(?:Font(?:s)?|Typeface|OpenType|TrueType)\b")
        || mi!(name, r"\.(?:otf|ttf|woff2?)$")
        || looks_like_tv_season(name)
        || mi!(
            name,
            r"\b(19|20)\d{2}\b.*\b(1080p|720p|2160p|BluRay|WEB-DL|BDRip|DVDRip)\b"
        )
}

pub fn categorize(ctx: &Ctx) -> Option<Hit> {
    comic(ctx.name)
        .or_else(|| technical(ctx.name))
        .or_else(|| magazine(ctx.name))
        .or_else(|| ebook(ctx.name))
}

fn comic(name: &str) -> Option<Hit> {
    if mi!(name, r"\b(?:CBR|CBZ|C2C)\b|\.(?:cbr|cbz)$") {
        return Some(hit(cat::BOOKS_COMICS, 0.9, "comic_format"));
    }
    if mi!(
        name,
        r"\b(?:Marvel|DC[._ -]Comics|Image[._ -]Comics|Dark[._ -]Horse|IDW)\b"
    ) && mi!(name, r"\b(?:Comics?|Annual|Issue|Vol|TPB)\b")
    {
        return Some(hit(cat::BOOKS_COMICS, 0.85, "comic_publisher"));
    }
    if mi!(name, r"\b(?:Manga|Manhwa|Manhua|Webtoon)\b") {
        return Some(hit(cat::BOOKS_COMICS, 0.85, "manga"));
    }
    None
}

fn technical(name: &str) -> Option<Hit> {
    if has_software_markers(name) || mi!(name, VIDEO_COURSE) {
        return None;
    }
    if mi!(name, concat!(r"\b(", publishers!(), r")\b")) {
        return Some(hit(cat::BOOKS_TECHNICAL, 0.9, "technical_publisher"));
    }
    if mi!(
        name,
        r"\b(Programming|Python|JavaScript|Java|Database|Linux|DevOps|Machine[._ -]Learning|Data[._ -]Science)\b"
    ) && mi!(
        name,
        r"\b(?:Book|Guide|Tutorial|Learn|Handbook|Cookbook|Reference)\b"
    ) && has_book_corroborator(name)
    {
        return Some(hit(cat::BOOKS_TECHNICAL, 0.85, "technical_subject"));
    }
    None
}

fn magazine(name: &str) -> Option<Hit> {
    let has_title = mi!(
        name,
        r"\b(Forbes|Fortune|GQ|National[._ -]Geographic|Newsweek|Vogue|Wired|The[._ -]?Economist|New[._ -]?Yorker|Scientific[._ -]?American|Popular[._ -]?Mechanics|Cosmopolitan|Elle|Esquire|Vanity[._ -]?Fair|Rolling[._ -]?Stone|Entertainment[._ -]?Weekly|People|Playboy)\b"
    );
    let has_issue_number = mi!(name, r"(?:^|[._ -])Issue[._ -]?\d{1,4}(?:$|[._ -,])");
    let has_date = mi!(
        name,
        r"\b(?:19|20)\d{2}\b|(?:^|[._ -])(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[._ -]?(?:19|20)?\d{2}\b"
    );
    let has_frequency = mi!(name, r"[._ -](Monthly|Weekly|Quarterly|Annual)[._ -]");
    let has_issue_title = mi!(
        name,
        r"\b([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+)*)\s*-\s*Issue\s+\d+"
    );
    let has_mcn = mi!(
        name,
        r"\bMCN[._ -](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec|January|February|March|April|June|July|August|September|October|November|December)[._ -]\d{1,2}[._ -](?:19|20)\d{2}\b"
    ) && mi!(name, r"\bMAGAZINE\b");

    if (has_frequency && (has_issue_number || has_date || has_title))
        || (has_issue_number && (has_date || has_title || has_issue_title))
        || has_mcn
    {
        return Some(hit(cat::BOOKS_MAGAZINES, 0.9, "magazine_frequency"));
    }
    has_title.then_some(hit(cat::BOOKS_MAGAZINES, 0.85, "magazine_title"))
}

fn ebook(name: &str) -> Option<Hit> {
    if has_software_markers(name) {
        return None;
    }
    if mi!(name, r"\.(EPUB|MOBI|AZW\d?|FB2|DJVU|LIT)$") {
        return Some(hit(cat::BOOKS_EBOOK, 0.9, "ebook_format"));
    }
    if mi!(name, r"\b(EPUB|MOBI|AZW\d?|FB2|DJVU|LIT)\b") {
        return Some(hit(cat::BOOKS_EBOOK, 0.85, "ebook_indicator"));
    }
    if mi!(name, r"\.PDF$") && has_book_corroborator(name) {
        return Some(hit(cat::BOOKS_EBOOK, 0.75, "ebook_pdf_format"));
    }
    if mi!(name, r"\bPDF\b") && has_book_corroborator(name) {
        return Some(hit(cat::BOOKS_EBOOK, 0.65, "ebook_pdf_indicator"));
    }
    if mi!(name, r"\b(E-?book|Kindle|Kobo|Nook)\b") {
        return Some(hit(cat::BOOKS_EBOOK, 0.8, "ebook_platform"));
    }
    if has_book_corroborator(name) && ms!(name, r"\b(19|20)\d{2}\b") {
        return Some(hit(cat::BOOKS_EBOOK, 0.6, "ebook_book_signals"));
    }
    None
}

fn has_book_corroborator(name: &str) -> bool {
    mi!(
        name,
        r"\b(?:ISBN(?:-1[03])?|Edition|Ed\.|Author|Novel|Volume|Vol\.|Chapter|Press|Paperback|Hardcover|E-?book|Kindle|Kobo)\b"
    ) || mi!(name, concat!(r"\b(?:", publishers!(), r")\b"))
        || mi!(name, r"\.(?:epub|mobi|azw\d?|fb2|djvu|lit|pdf)$")
}

fn has_software_markers(name: &str) -> bool {
    mi!(
        name,
        r"\b(?:Setup|Install(?:er)?|Portable|Crack(?:ed)?|Keygen|Serial|Regged|Pre-?Activated|Patch(?:ed)?|NFO|ReadNFO|Win(?:dows)?|macOS|Linux|x64|x86|amd64|AIO|Retail|Incl)\b"
    ) || mi!(
        name,
        r"\b(?:Adobe|Foxit|Autodesk|AutoCAD|Corel|Microsoft[._ -]Office|Office(?:365)?|Visual[._ -]Studio|IntelliJ|PyCharm|VMware|VirtualBox)\b"
    ) || mi!(name, r"\.(?:exe|msi|dmg|pkg)$")
}

fn has_video_release_markers(name: &str) -> bool {
    mi!(name, r"\b(?:720p|1080p|2160p|4k)\b")
        && mi!(
            name,
            r"\b(?:WEB[._ -]?DL|WEBRip|HDTV|BluRay|BDRip|DVDRip|x264|x265|HEVC|HDR|Remux|AVC)\b"
        )
}
