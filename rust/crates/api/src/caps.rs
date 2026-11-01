//! Capabilities: what `t=caps` and `/api/v2/capabilities` advertise.

use std::time::Duration;

use anyhow::Result;
use chrono::NaiveDateTime;
use nntmux_runtime::Ctx;
use sha2::{Digest, Sha256};
use sqlx::Row;

use crate::json::{J, Style};
use crate::reply::Reply;
use crate::request::ApiRequest;
use crate::settings::Settings;
use crate::usage::rfc2822;
use crate::xml::XmlWriter;

fn search_entry(params: &str) -> J {
    J::obj([
        ("available", J::str("yes")),
        ("supportedParams", J::str(params)),
    ])
}

fn v1_searching() -> J {
    J::obj([
        (
            "search",
            search_entry("q,group,minsize,maxsize,maxage,cat,limit,offset,attrs,extended,del,sort"),
        ),
        (
            "tv-search",
            search_entry(
                "q,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort",
            ),
        ),
        (
            "movie-search",
            search_entry(
                "q,imdbid,tmdbid,traktid,genre,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort",
            ),
        ),
        (
            "audio-search",
            search_entry("q,cat,minsize,maxsize,maxage,group,limit,offset,attrs,extended,del,sort"),
        ),
        (
            "book-search",
            search_entry(
                "q,title,author,cat,minsize,maxsize,maxage,group,limit,offset,attrs,extended,del,sort",
            ),
        ),
        (
            "anime-search",
            search_entry(
                "q,anidbid,anilistid,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort",
            ),
        ),
    ])
}

fn v2_searching() -> J {
    J::obj([
        (
            "search",
            search_entry("id,group,minsize,maxage,cat,limit,offset,cursor,sort"),
        ),
        (
            "tv-search",
            search_entry(
                "id,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxage,limit,offset,cursor,sort",
            ),
        ),
        (
            "movie-search",
            search_entry("id,imdbid,tmdbid,traktid,cat,minsize,maxage,limit,offset,cursor,sort"),
        ),
        (
            "audio-search",
            search_entry("id,cat,minsize,maxage,group,limit,offset,cursor,sort"),
        ),
        (
            "book-search",
            search_entry("id,cat,minsize,maxage,group,limit,offset,cursor,sort"),
        ),
        (
            "anime-search",
            search_entry("id,anidbid,anilistid,cat,minsize,maxage,limit,offset,cursor,sort"),
        ),
    ])
}

/// The site strapline and keywords, read at most every ten minutes as every v1 response needs them.
async fn site_text(ctx: &Ctx, settings: &Settings) -> Result<(String, String)> {
    const KEY: &str = "site_text";
    if let Some(J::Arr(values)) = settings.local_get(KEY)
        && let [J::Str(strapline), J::Str(keywords)] = values.as_slice()
    {
        return Ok((strapline.clone(), keywords.clone()));
    }
    let row: Option<(String, String)> =
        sqlx::query_as("SELECT strapline, meta_keywords FROM site_configurations LIMIT 1")
            .fetch_optional(&ctx.db)
            .await?;
    let (strapline, keywords) = row.unwrap_or_else(|| {
        (
            "A great usenet indexer".to_owned(),
            "usenet,nzbs,cms,community".to_owned(),
        )
    });
    settings.local_put(
        KEY,
        Duration::from_secs(600),
        J::Arr(vec![J::Str(strapline.clone()), J::Str(keywords.clone())]),
    );
    Ok((strapline, keywords))
}

/// `RegistrationStatusService::resolve`: an active period opens registration whatever the manual
/// status says. Returns `(available, is_open)`.
async fn registration(ctx: &Ctx) -> Result<(bool, bool)> {
    let manual: Option<u8> =
        sqlx::query_scalar("SELECT status FROM registration_configurations LIMIT 1")
            .fetch_optional(&ctx.db)
            .await?;
    // Unknown stored values count as open, as getManualStatus does.
    let manual = manual.filter(|status| *status <= 2).unwrap_or(0);
    let now = crate::usage::now_string();
    let active: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM registration_periods WHERE is_enabled = 1 AND starts_at <= ? AND ends_at >= ?",
    )
    .bind(&now)
    .bind(&now)
    .fetch_one(&ctx.db)
    .await?;
    let effective = if active > 0 { 0 } else { manual };
    Ok((effective != 2, effective == 0))
}

fn registration_json((available, open): (bool, bool)) -> J {
    let yes_no = |value: bool| J::str(if value { "yes" } else { "no" });
    J::obj([("available", yes_no(available)), ("open", yes_no(open))])
}

fn limits(settings: &Settings) -> J {
    J::obj([
        ("max", J::Int(settings.limit_max())),
        ("default", J::Int(settings.limit_default())),
    ])
}

async fn groups(ctx: &Ctx) -> Result<J> {
    let rows = sqlx::query(
        "SELECT name, description, last_updated FROM usenet_groups WHERE active = 1 ORDER BY name",
    )
    .fetch_all(&ctx.db)
    .await?;
    let mut groups = Vec::with_capacity(rows.len());
    for row in rows {
        let description: Option<String> = row.try_get("description")?;
        let updated: Option<NaiveDateTime> = row.try_get("last_updated")?;
        groups.push(J::obj([
            ("name", J::str(row.try_get::<String, _>("name")?)),
            ("description", J::str(description.unwrap_or_default())),
            (
                "lastupdate",
                J::str(updated.map(rfc2822).unwrap_or_default()),
            ),
        ]));
    }
    Ok(J::Arr(groups))
}

async fn genres(ctx: &Ctx) -> Result<J> {
    let rows = sqlx::query("SELECT id, title, type FROM genres WHERE disabled = 0 ORDER BY title")
        .fetch_all(&ctx.db)
        .await?;
    let mut genres = Vec::with_capacity(rows.len());
    for row in rows {
        let kind: Option<i32> = row.try_get("type")?;
        genres.push(J::obj([
            ("id", J::Int(i64::from(row.try_get::<u32, _>("id")?))),
            ("name", J::str(row.try_get::<String, _>("title")?)),
            ("categoryid", J::Int(i64::from(kind.unwrap_or(0)))),
        ]));
    }
    Ok(J::Arr(genres))
}

/// `Category::getForMenu`: each root with its categories, roots without any left out.
async fn menu_categories(ctx: &Ctx) -> Result<J> {
    let roots: Vec<(u64, String)> = sqlx::query_as("SELECT id, title FROM root_categories")
        .fetch_all(&ctx.db)
        .await?;
    let rows = sqlx::query("SELECT id, title, root_categories_id, description FROM categories")
        .fetch_all(&ctx.db)
        .await?;
    let mut out = Vec::new();
    for (id, title) in roots {
        let mut children = Vec::new();
        for row in &rows {
            let root: Option<u64> = row.try_get("root_categories_id")?;
            if root == Some(id) {
                children.push(J::obj([
                    ("id", J::Int(i64::from(row.try_get::<u32, _>("id")?))),
                    ("title", J::str(row.try_get::<String, _>("title")?)),
                    ("root_categories_id", J::Int(i64::try_from(id)?)),
                    ("description", J::opt_str(row.try_get("description")?)),
                ]));
            }
        }
        if !children.is_empty() {
            out.push(J::obj([
                ("id", J::Int(i64::try_from(id)?)),
                ("title", J::str(title)),
                ("categories", J::Arr(children)),
            ]));
        }
    }
    Ok(J::Arr(out))
}

/// The v2 form: active roots with `{id: title}` maps of their categories.
async fn api_categories(ctx: &Ctx) -> Result<J> {
    let roots: Vec<(u64, String)> =
        sqlx::query_as("SELECT id, title FROM root_categories WHERE status = 1")
            .fetch_all(&ctx.db)
            .await?;
    let mut out = Vec::with_capacity(roots.len());
    for (id, title) in roots {
        let children: Vec<(u32, String)> =
            sqlx::query_as("SELECT id, title FROM categories WHERE root_categories_id = ?")
                .bind(id)
                .fetch_all(&ctx.db)
                .await?;
        let subcategories = if children.is_empty() {
            J::Arr(Vec::new())
        } else {
            J::Obj(
                children
                    .into_iter()
                    .map(|(id, title)| (id.to_string(), J::Str(title)))
                    .collect(),
            )
        };
        out.push(J::obj([
            ("id", J::Int(i64::try_from(id)?)),
            ("name", J::str(title)),
            ("subcategories", subcategories),
        ]));
    }
    Ok(J::Arr(out))
}

/// The server block a v1 response needs, without the catalogs only caps sends.
pub struct Server {
    pub title: String,
    pub strapline: String,
    pub email: String,
    pub meta: String,
    pub url: String,
}

impl Server {
    pub async fn load(ctx: &Ctx, settings: &Settings, url: String) -> Result<Self> {
        let (strapline, meta) = site_text(ctx, settings).await?;
        Ok(Self {
            title: settings.app_name.clone(),
            strapline,
            email: settings.mail_from.clone(),
            meta,
            url,
        })
    }

    fn json(&self) -> J {
        J::obj([
            ("title", J::str(self.title.clone())),
            ("strapline", J::str(self.strapline.clone())),
            ("email", J::str(self.email.clone())),
            ("meta", J::str(self.meta.clone())),
            ("url", J::str(self.url.clone())),
            (
                "image",
                J::str(format!("{}/assets/images/tmux_logo.png", self.url)),
            ),
        ])
    }
}

fn scalar(value: &J) -> String {
    match value {
        J::Str(text) => text.clone(),
        J::Int(number) => number.to_string(),
        _ => String::new(),
    }
}

fn entries(value: &J) -> &[(String, J)] {
    match value {
        J::Obj(entries) => entries,
        _ => &[],
    }
}

fn items(value: &J) -> &[J] {
    match value {
        J::Arr(items) => items,
        _ => &[],
    }
}

fn field<'a>(value: &'a J, name: &str) -> Option<&'a J> {
    entries(value)
        .iter()
        .find(|(key, _)| key == name)
        .map(|(_, value)| value)
}

fn write_attrs(writer: &mut XmlWriter, name: &str, value: &J) {
    writer.start(name);
    for (key, attribute) in entries(value) {
        writer.attr(key, &scalar(attribute));
    }
    writer.end();
}

/// `html_entity_decode` for the entities category titles can realistically hold.
fn decode_entities(text: &str) -> String {
    if !text.contains('&') {
        return text.to_owned();
    }
    text.replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#039;", "'")
        .replace("&#39;", "'")
        .replace("&amp;", "&")
}

fn write_category(writer: &mut XmlWriter, tag: &str, category: &J) {
    writer.start(tag);
    writer.attr("id", &field(category, "id").map(scalar).unwrap_or_default());
    writer.attr(
        "name",
        &decode_entities(&field(category, "title").map(scalar).unwrap_or_default()),
    );
    let description = field(category, "description")
        .map(scalar)
        .unwrap_or_default();
    // PHP's `empty` treats "0" as empty too.
    if !description.is_empty() && description != "0" {
        writer.attr("description", &decode_entities(&description));
    }
    for child in field(category, "categories").map(items).unwrap_or_default() {
        write_category(writer, "subcat", child);
    }
    writer.end();
}

/// `t=caps`, as XML or JSON.
pub async fn v1(ctx: &Ctx, settings: &Settings, request: &ApiRequest, xml: bool) -> Result<Reply> {
    let server = Server::load(ctx, settings, request.base_url()).await?;
    let registration = registration_json(registration(ctx).await?);
    let categories = menu_categories(ctx).await?;
    let groups = groups(ctx).await?;
    let genres = genres(ctx).await?;
    let searching = v1_searching();
    if !xml {
        let body = J::obj([
            ("server", server.json()),
            ("limits", limits(settings)),
            ("registration", registration),
            ("searching", searching),
            ("categories", categories),
            ("groups", groups),
            ("genres", genres),
        ]);
        return Ok(Reply::json(200, &body, Style::Unescaped));
    }
    let mut writer = XmlWriter::document();
    writer.start("caps");
    write_attrs(&mut writer, "server", &server.json());
    write_attrs(&mut writer, "limits", &limits(settings));
    write_attrs(&mut writer, "registration", &registration);
    writer.start("searching");
    for (name, entry) in entries(&searching) {
        write_attrs(&mut writer, name, entry);
    }
    writer.end();
    writer.start("categories");
    for category in items(&categories) {
        write_category(&mut writer, "category", category);
    }
    writer.end();
    writer.start("groups");
    for group in items(&groups) {
        writer.start("group");
        writer.attr(
            "name",
            &field(group, "name").map(scalar).unwrap_or_default(),
        );
        writer.attr(
            "description",
            &field(group, "description").map(scalar).unwrap_or_default(),
        );
        let updated = field(group, "lastupdate").map(scalar).unwrap_or_default();
        if !updated.is_empty() {
            writer.attr("lastupdate", &updated);
        }
        writer.end();
    }
    writer.end();
    writer.start("genres");
    for genre in items(&genres) {
        writer.start("genre");
        writer.attr("id", &field(genre, "id").map(scalar).unwrap_or_default());
        writer.attr(
            "name",
            &field(genre, "name").map(scalar).unwrap_or_default(),
        );
        writer.attr(
            "categoryid",
            &field(genre, "categoryid").map(scalar).unwrap_or_default(),
        );
        writer.end();
    }
    writer.end();
    writer.end();
    Ok(Reply::new(200, "text/xml; charset=utf-8", writer.finish()))
}

/// `/api/v2/capabilities`: public, cacheable, and answering `If-None-Match`.
pub async fn v2(ctx: &Ctx, settings: &Settings, request: &ApiRequest) -> Result<Reply> {
    let (strapline, _) = site_text(ctx, settings).await?;
    let body = J::obj([
        (
            "server",
            J::obj([
                ("title", J::str(settings.app_name.clone())),
                ("strapline", J::str(strapline)),
                ("email", J::str(settings.mail_from.clone())),
                ("url", J::str(request.base_url())),
            ]),
        ),
        ("limits", limits(settings)),
        ("searching", v2_searching()),
        ("categories", api_categories(ctx).await?),
        ("groups", groups(ctx).await?),
        ("genres", genres(ctx).await?),
        ("registration", registration_json(registration(ctx).await?)),
    ]);
    let encoded = body.encode(Style::Unescaped);
    let etag = format!("\"{}\"", hex::encode(Sha256::digest(encoded.as_bytes())));
    let matched = request.header("if-none-match").is_some_and(|sent| {
        sent.split(',').any(|candidate| {
            let candidate = candidate.trim();
            candidate == "*" || candidate.trim_start_matches("W/") == etag
        })
    });
    let reply = if matched {
        Reply::empty(304)
    } else {
        Reply::new(200, "application/json", encoded)
    };
    Ok(reply
        .header("cache-control", "max-age=300, public")
        .header("etag", etag))
}
