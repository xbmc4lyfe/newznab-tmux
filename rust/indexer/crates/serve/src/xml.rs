//! Newznab XML: capabilities, RSS result pages and errors.

use std::fmt::Write;

use crate::categories::{self, ROOTS};
use crate::engine::Item;

const HEADER: &str = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";

/// Escapes text for an element or a double-quoted attribute. Drops characters XML 1.0 forbids,
/// which old names can hold.
pub(crate) fn escape(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    for c in text.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&apos;"),
            '\t' | '\n' | '\r' => out.push(c),
            c if c < ' ' || matches!(c, '\u{fffe}' | '\u{ffff}') => {}
            c => out.push(c),
        }
    }
    out
}

pub(crate) fn error(code: u16, description: &str) -> String {
    format!(
        "{HEADER}<error code=\"{code}\" description=\"{}\"/>\n",
        escape(description)
    )
}

pub(crate) fn caps(max_results: usize) -> String {
    let mut out = String::from(HEADER);
    out.push_str("<caps>\n");
    out.push_str("<server version=\"1.0\" title=\"indexer\" strapline=\"TV and movies\"/>\n");
    let _ = writeln!(
        out,
        "<limits max=\"{max_results}\" default=\"{max_results}\"/>"
    );
    out.push_str("<registration available=\"no\" open=\"no\"/>\n");
    out.push_str("<searching>\n");
    for (name, available, params) in [
        ("search", "yes", "q"),
        (
            "tv-search",
            "yes",
            "q,season,ep,tvdbid,tvmazeid,rid,traktid,tmdbid,imdbid",
        ),
        ("movie-search", "yes", "q,imdbid,tmdbid,traktid"),
        ("audio-search", "no", ""),
        ("book-search", "no", ""),
    ] {
        let _ = writeln!(
            out,
            "<{name} available=\"{available}\" supportedParams=\"{params}\"/>"
        );
    }
    out.push_str("</searching>\n<categories>\n");
    for root in ROOTS {
        let _ = writeln!(
            out,
            "<category id=\"{}\" name=\"{}\">",
            root.id,
            escape(root.name)
        );
        for &(id, name) in root.subcats {
            let _ = writeln!(out, "<subcat id=\"{id}\" name=\"{}\"/>", escape(name));
        }
        out.push_str("</category>\n");
    }
    out.push_str("</categories>\n</caps>\n");
    out
}

/// Where links in a page point.
pub(crate) struct Links<'a> {
    /// Scheme and host, without a trailing slash.
    pub base: &'a str,
    pub apikey: &'a str,
}

impl Links<'_> {
    fn nzb(&self, guid: &str) -> String {
        format!(
            "{}/api?t=get&id={}&apikey={}",
            self.base,
            percent_encode(guid),
            percent_encode(self.apikey)
        )
    }

    fn details(&self, guid: &str) -> String {
        format!(
            "{}/api?t=details&id={}&apikey={}",
            self.base,
            percent_encode(guid),
            percent_encode(self.apikey)
        )
    }
}

fn percent_encode(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    for byte in text.bytes() {
        if byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.' | b'~') {
            out.push(byte as char);
        } else {
            let _ = write!(out, "%{byte:02X}");
        }
    }
    out
}

pub(crate) fn results(links: &Links<'_>, offset: usize, total: u64, items: &[Item]) -> String {
    let mut out = String::with_capacity(1024 + items.len() * 1536);
    out.push_str(HEADER);
    out.push_str(
        "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\" \
         xmlns:newznab=\"http://www.newznab.com/DTD/2010/feeds/attributes/\">\n<channel>\n",
    );
    let base = escape(links.base);
    let _ = writeln!(
        out,
        "<title>indexer</title>\n<description>indexer API results</description>\n\
         <link>{base}/</link>\n<language>en-us</language>\n\
         <atom:link href=\"{base}/api\" rel=\"self\" type=\"application/rss+xml\"/>\n\
         <newznab:response offset=\"{offset}\" total=\"{total}\"/>"
    );
    for item in items {
        write_item(&mut out, links, item);
    }
    out.push_str("</channel>\n</rss>\n");
    out
}

fn write_item(out: &mut String, links: &Links<'_>, item: &Item) {
    let title = escape(&item.title);
    let guid = escape(&item.guid);
    let nzb = escape(&links.nzb(&item.guid));
    let posted = item.posted_at.or(item.added_at).unwrap_or(0);
    let date = rfc2822(posted);
    let category = escape(&categories::name(item.category));
    let _ = write!(
        out,
        "<item>\n<title>{title}</title>\n<guid isPermaLink=\"false\">{guid}</guid>\n\
         <link>{nzb}</link>\n<comments>{}</comments>\n<pubDate>{date}</pubDate>\n\
         <category>{category}</category>\n<description>{title}</description>\n\
         <enclosure url=\"{nzb}\" length=\"{}\" type=\"application/x-nzb\"/>\n",
        escape(&links.details(&item.guid)),
        item.size_bytes.max(0),
    );
    let mut attr = |name: &str, value: &str| {
        let _ = writeln!(
            out,
            "<newznab:attr name=\"{name}\" value=\"{}\"/>",
            escape(value)
        );
    };
    if let Some(root) = categories::parent(item.category)
        && root.id != item.category
    {
        attr("category", &root.id.to_string());
    }
    attr("category", &item.category.to_string());
    attr("size", &item.size_bytes.max(0).to_string());
    attr("guid", &item.guid);
    if let Some(files) = item.files {
        attr("files", &files.to_string());
    }
    attr("grabs", &item.grabs.to_string());
    if let Some(poster) = &item.poster {
        attr("poster", poster);
    }
    if let Some(group) = &item.group {
        attr("group", group);
    }
    attr("usenetdate", &date);
    attr("password", if item.passworded { "1" } else { "0" });
    if let Some(imdb) = &item.imdb_id {
        attr("imdb", imdb.strip_prefix("tt").unwrap_or(imdb));
    }
    for (name, value) in [
        ("tmdbid", item.tmdb_id),
        ("tvdbid", item.tvdb_id),
        ("tvmazeid", item.tvmaze_id),
        ("traktid", item.trakt_id),
        ("rageid", item.tvrage_id),
        ("season", item.season),
        ("episode", item.episode),
    ] {
        if let Some(value) = value {
            attr(name, &value.to_string());
        }
    }
    out.push_str("</item>\n");
}

/// `Tue, 01 Jan 2030 00:00:00 +0000`, the format RSS and Newznab use.
pub(crate) fn rfc2822(unix_seconds: i64) -> String {
    const DAYS: [&str; 7] = ["Thu", "Fri", "Sat", "Sun", "Mon", "Tue", "Wed"];
    const MONTHS: [&str; 12] = [
        "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
    ];
    let days = unix_seconds.div_euclid(86_400);
    let seconds = unix_seconds.rem_euclid(86_400);
    let (year, month, day) = civil_from_days(days);
    format!(
        "{}, {day:02} {} {year:04} {:02}:{:02}:{:02} +0000",
        DAYS[days.rem_euclid(7) as usize],
        MONTHS[(month - 1) as usize],
        seconds / 3600,
        seconds / 60 % 60,
        seconds % 60
    )
}

/// Howard Hinnant's algorithm: days since 1970-01-01 to a proleptic Gregorian date.
fn civil_from_days(days: i64) -> (i64, i64, i64) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = doy - (153 * mp + 2) / 5 + 1;
    let month = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = yoe + era * 400 + i64::from(month <= 2);
    (year, month, day)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn dates_are_rfc_2822() {
        assert_eq!(rfc2822(0), "Thu, 01 Jan 1970 00:00:00 +0000");
        assert_eq!(rfc2822(1_778_068_800), "Wed, 06 May 2026 12:00:00 +0000");
        assert_eq!(rfc2822(951_782_400), "Tue, 29 Feb 2000 00:00:00 +0000");
        assert_eq!(rfc2822(-1), "Wed, 31 Dec 1969 23:59:59 +0000");
    }

    #[test]
    fn escaping_covers_markup_and_drops_control_characters() {
        assert_eq!(
            escape("A & <B> \"c\" 'd'\u{1}"),
            "A &amp; &lt;B&gt; &quot;c&quot; &apos;d&apos;"
        );
    }

    #[test]
    fn caps_lists_only_tv_and_movies() {
        let caps = caps(100);
        assert!(caps.contains("<limits max=\"100\" default=\"100\"/>"));
        assert!(caps.contains("<tv-search available=\"yes\" supportedParams=\"q,season,ep,tvdbid,tvmazeid,rid,traktid,tmdbid,imdbid\"/>"));
        assert!(caps.contains("<subcat id=\"2045\" name=\"UHD\"/>"));
        assert!(!caps.contains("5070"));
        assert!(!caps.contains("id=\"3000\""));
    }
}
