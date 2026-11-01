//! `XML_Response` and `ReleaseItemPayloadBuilder`: release rows as newznab XML or JSON.

use crate::caps::Server;
use crate::json::{J, Style};
use crate::reply::{Reply, xml_error};
use crate::rows::{Row, imdb_id_is_valid, is_empty, parse_time, rss_date, scalar_text};
use crate::xml::XmlWriter;

/// `$params` in the controllers: the requester's limits and how to build their links.
pub struct Params {
    pub uid: i64,
    pub token: String,
    pub extended: bool,
    pub delete_from_cart: bool,
    /// RSS passes `dl`; the API never does, and an unset `dl` means include the enclosure.
    pub include_enclosure: bool,
    pub api_limit: i64,
    pub requests: i64,
    pub download_limit: i64,
    pub grabs: i64,
    pub oldest_api: String,
    pub oldest_grab: String,
}

#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Namespace {
    Newznab,
    Nntmux,
}

impl Namespace {
    fn prefix(self) -> &'static str {
        match self {
            Self::Newznab => "newznab",
            Self::Nntmux => "nntmux",
        }
    }
}

struct Payload {
    title: J,
    guid: String,
    link: String,
    comments: String,
    pub_date: String,
    category: J,
    enclosure: Option<(String, J)>,
    attr: Vec<(String, J)>,
}

fn payload(row: &Row, params: &Params, url: &str, namespace: Namespace) -> Payload {
    let guid = row.text("guid");
    let del = if params.delete_from_cart {
        "&del=1"
    } else {
        ""
    };
    let link = format!("{url}/getnzb?id={guid}.nzb&r={}{del}", params.token);
    let added = parse_time(&row.text("adddate")).unwrap_or_default();
    Payload {
        title: row.or_null("searchname"),
        guid: format!("{url}/details/{guid}"),
        comments: format!("{url}/details/{guid}#comments"),
        pub_date: rss_date(added),
        category: row.or_null("category_name"),
        enclosure: params
            .include_enclosure
            .then(|| (link.clone(), row.or_null("size"))),
        attr: attributes(row, params, url, namespace),
        link,
    }
}

fn attributes(row: &Row, params: &Params, url: &str, namespace: Namespace) -> Vec<(String, J)> {
    let mut attrs: Vec<(String, J)> = vec![
        ("category".to_owned(), row.or_null("categories_id")),
        ("size".to_owned(), row.or_null("size")),
    ];
    let push =
        |attrs: &mut Vec<(String, J)>, name: &str, value: J| attrs.push((name.to_owned(), value));
    if !is_empty(row.get("coverurl")) {
        push(
            &mut attrs,
            "coverurl",
            J::Str(format!("{url}/covers/{}", row.text("coverurl"))),
        );
    }
    if !params.extended {
        return attrs;
    }
    push(&mut attrs, "files", row.or_null("totalpart"));
    if row.get("articles_expected").is_some() {
        for key in [
            "articles_found",
            "articles_expected",
            "files_found",
            "files_expected",
        ] {
            push(&mut attrs, key, row.or_null(key));
        }
    }
    if namespace == Namespace::Newznab
        && (row.positive("videos_id") || row.positive("tv_episodes_id"))
    {
        for (name, value) in tv_attributes(row) {
            match attrs.iter_mut().find(|(existing, _)| *existing == name) {
                Some(entry) => entry.1 = value,
                None => attrs.push((name, value)),
            }
        }
    }
    if row.get("imdbid").is_some() && imdb_id_is_valid(&row.text("imdbid")) {
        push(&mut attrs, "imdb", row.or_null("imdbid"));
    }
    if row.positive("anidbid") {
        push(&mut attrs, "anidbid", row.or_null("anidbid"));
    }
    if row.positive("predb_id") {
        push(&mut attrs, "prematch", J::str("1"));
    }
    if row.int("nfostatus") == 1 {
        // The missing slash is in the PHP too, and clients may have learned this form.
        push(
            &mut attrs,
            "info",
            J::Str(format!(
                "{url}api?t=info&id={}&r={}",
                row.text("guid"),
                params.token
            )),
        );
    }
    push(&mut attrs, "grabs", row.or_null("grabs"));
    push(&mut attrs, "comments", row.or_null("comments"));
    push(&mut attrs, "password", row.or_null("passwordstatus"));
    let posted = parse_time(&row.text("postdate")).or_else(|| parse_time(&row.text("adddate")));
    if let Some(time) = posted {
        push(&mut attrs, "usenetdate", J::Str(rss_date(time)));
    }
    if !is_empty(row.get("group_name")) {
        push(&mut attrs, "group", row.or_null("group_name"));
    }
    attrs
}

fn tv_attributes(row: &Row) -> Vec<(String, J)> {
    let mut attrs = Vec::new();
    let mut push = |name: &str, value: J| attrs.push((name.to_owned(), value));
    if !is_empty(row.get("title")) {
        push("title", row.or_null("title"));
    }
    if row.positive("series") {
        push("season", row.or_null("series"));
    }
    if !is_empty(row.get("episode")) && row.positive("episode") {
        push("episode", row.or_null("episode"));
    }
    if !is_empty(row.get("firstaired")) {
        push("tvairdate", row.or_null("firstaired"));
    }
    for (source, target) in [
        ("tvdb", "tvdbid"),
        ("trakt", "traktid"),
        ("tvmaze", "tvmazeid"),
        ("tmdb", "tmdbid"),
    ] {
        if row.positive(source) {
            push(target, row.or_null(source));
        }
    }
    if row.positive("tvrage") {
        push("tvrageid", row.or_null("tvrage"));
        push("rageid", row.or_null("tvrage"));
    }
    if row.get("imdb").is_some() && imdb_id_is_valid(&row.text("imdb")) {
        push("imdbid", row.or_null("imdb"));
    }
    attrs
}

/// `ReleaseRows::totalRows`: `_totalrows` from the first row, or how many there are.
pub fn total_rows(rows: &[Row]) -> i64 {
    match rows.first().and_then(|row| row.get("_totalrows")) {
        Some(total) => crate::rows::scalar_int(total),
        None => i64::try_from(rows.len()).unwrap_or(i64::MAX),
    }
}

/// `t=` search and details output, as XML or JSON.
pub fn api_output(rows: &[Row], params: &Params, server: &Server, offset: i64, xml: bool) -> Reply {
    if xml {
        let body = write_feed(
            rows,
            params,
            server,
            offset,
            Namespace::Newznab,
            &|_, _, _| {},
        );
        return Reply::new(200, "text/xml; charset=utf-8", body);
    }
    let mut limits = vec![
        ("apicurrent".to_owned(), J::Int(params.requests)),
        ("apimax".to_owned(), J::Int(params.api_limit)),
        ("grabcurrent".to_owned(), J::Int(params.grabs)),
        ("grabmax".to_owned(), J::Int(params.download_limit)),
    ];
    if !params.oldest_api.is_empty() {
        limits.push((
            "apioldesttime".to_owned(),
            J::Str(params.oldest_api.clone()),
        ));
    }
    if !params.oldest_grab.is_empty() {
        limits.push((
            "graboldesttime".to_owned(),
            J::Str(params.oldest_grab.clone()),
        ));
    }
    let items = rows
        .iter()
        .map(|row| {
            let item = payload(row, params, &server.url, Namespace::Newznab);
            let description = item.title.clone();
            let mut entries = vec![
                ("title".to_owned(), item.title),
                ("guid".to_owned(), J::Str(item.guid)),
                ("link".to_owned(), J::Str(item.link)),
                ("comments".to_owned(), J::Str(item.comments)),
                ("pubDate".to_owned(), J::Str(item.pub_date)),
                ("category".to_owned(), item.category),
                ("description".to_owned(), description),
            ];
            if let Some((url, length)) = item.enclosure {
                entries.push((
                    "enclosure".to_owned(),
                    J::obj([
                        ("url", J::Str(url)),
                        ("length", length),
                        ("type", J::str("application/x-nzb")),
                    ]),
                ));
            }
            entries.push(("attr".to_owned(), J::Obj(item.attr)));
            J::Obj(entries)
        })
        .collect();
    let body = J::Obj(vec![
        ("offset".to_owned(), J::Int(offset)),
        ("total".to_owned(), J::Int(total_rows(rows))),
        ("apilimits".to_owned(), J::Obj(limits)),
        ("item".to_owned(), J::Arr(items)),
    ]);
    Reply::json(200, &body, Style::Unescaped)
}

/// RSS feeds have no JSON form: `returnArray` gives false and the presenter answers with a 201.
pub fn rss_json_refused() -> Reply {
    xml_error(201, "")
}

/// Writes an item's description. RSS builds HTML here, the API repeats the name.
pub type Description<'a> = &'a dyn Fn(&mut XmlWriter, &Row, &str);

pub fn write_feed(
    rows: &[Row],
    params: &Params,
    server: &Server,
    offset: i64,
    namespace: Namespace,
    description: Description<'_>,
) -> String {
    let prefix = namespace.prefix();
    let mut xml = XmlWriter::document();
    xml.start("rss");
    xml.attr("version", "2.0");
    xml.attr("xmlns:atom", "http://www.w3.org/2005/Atom");
    let (dtd, path, tag) = match namespace {
        Namespace::Newznab => (
            "http://www.newznab.com/DTD/2010/feeds/attributes/".to_owned(),
            "/apihelp/",
            "API",
        ),
        Namespace::Nntmux => (format!("{}/rss-info/", server.url), "/rss-info/", "RSS"),
    };
    xml.attr(&format!("xmlns:{prefix}"), &dtd);
    xml.attr("encoding", "utf-8");
    xml.start("channel");
    if namespace == Namespace::Nntmux {
        xml.start("atom:link");
        xml.attr("href", &format!("{}/rss", server.url));
        xml.attr("rel", "self");
        xml.attr("type", "application/rss+xml");
        xml.end();
    }
    xml.element("title", &server.title);
    xml.element("description", &format!("{} {tag} Details", server.title));
    xml.element("link", &server.url);
    xml.element("language", "en-gb");
    xml.element("webMaster", &format!("{} {}", server.email, server.title));
    xml.element("category", &server.meta);
    xml.element("generator", "nntmux");
    xml.element("ttl", "10");
    xml.element("docs", &format!("{}{path}", server.url));
    xml.start("image");
    xml.attr(
        "url",
        &format!("{}/assets/images/tmux_logo.png", server.url),
    );
    xml.attr("title", &server.title);
    xml.attr("link", &server.url);
    xml.attr(
        "description",
        &format!("Visit {} - {}", server.title, server.strapline),
    );
    xml.end();
    xml.start(&format!("{prefix}:response"));
    xml.attr("offset", &offset.to_string());
    xml.attr("total", &total_rows(rows).to_string());
    xml.end();
    xml.start(&format!("{prefix}:apilimits"));
    xml.attr("apicurrent", &params.requests.to_string());
    xml.attr("apimax", &params.api_limit.to_string());
    xml.attr("grabcurrent", &params.grabs.to_string());
    xml.attr("grabmax", &params.download_limit.to_string());
    if !params.oldest_api.is_empty() {
        xml.attr("apioldesttime", &params.oldest_api);
    }
    if !params.oldest_grab.is_empty() {
        xml.attr("graboldesttime", &params.oldest_grab);
    }
    xml.end();
    for row in rows {
        let item = payload(row, params, &server.url, namespace);
        xml.start("item");
        xml.element("title", &scalar_text(&item.title));
        xml.start("guid");
        xml.attr("isPermaLink", "true");
        xml.text(&item.guid);
        xml.end();
        xml.element("link", &item.link);
        xml.element("comments", &item.comments);
        xml.element("pubDate", &item.pub_date);
        xml.element("category", &scalar_text(&item.category));
        match namespace {
            Namespace::Newznab => xml.element("description", &scalar_text(&item.title)),
            Namespace::Nntmux => description(&mut xml, row, &server.url),
        }
        if let Some((url, length)) = &item.enclosure {
            xml.start("enclosure");
            xml.attr("url", url);
            xml.attr("length", &scalar_text(length));
            xml.attr("type", "application/x-nzb");
            xml.end();
        }
        for (name, value) in &item.attr {
            xml.start(&format!("{prefix}:attr"));
            xml.attr("name", name);
            xml.attr("value", &scalar_text(value));
            xml.end();
        }
        xml.end();
    }
    xml.end();
    xml.end();
    xml.finish()
}
