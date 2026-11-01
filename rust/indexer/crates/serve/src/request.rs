//! Turns a query string into one Newznab function call, or the error to answer with.

use crate::categories::{self, MOVIES, TV};
use crate::params::Params;

/// Newznab error codes, answered as `<error code=".." description=".."/>`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) enum ApiError {
    Credentials,
    Missing(&'static str),
    Incorrect(&'static str),
    NoFunction,
    NoItem,
    Internal,
}

impl ApiError {
    pub(crate) fn code(&self) -> u16 {
        match self {
            Self::Credentials => 100,
            Self::Missing(_) => 200,
            Self::Incorrect(_) => 201,
            Self::NoFunction => 202,
            Self::NoItem => 300,
            Self::Internal => 900,
        }
    }

    pub(crate) fn description(&self) -> String {
        match self {
            Self::Credentials => "Incorrect user credentials".to_owned(),
            Self::Missing(name) => format!("Missing parameter ({name})"),
            Self::Incorrect(name) => format!("Incorrect parameter ({name})"),
            Self::NoFunction => "No such function".to_owned(),
            Self::NoItem => "No such item".to_owned(),
            Self::Internal => "Unknown error".to_owned(),
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum Kind {
    Search,
    Tv,
    Movie,
}

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum Function {
    Caps,
    Search(Box<Search>),
    Get(String),
    Details(String),
}

/// The function's name for the `type` metric label. Unknown names share one value.
pub(crate) fn function_label(params: &Params) -> &'static str {
    match params.get("t").map(str::to_ascii_lowercase).as_deref() {
        Some("caps") => "caps",
        Some("search") => "search",
        Some("tvsearch" | "tv") => "tvsearch",
        Some("movie" | "movies") => "movie",
        Some("get") => "get",
        Some("details") => "details",
        _ => "unknown",
    }
}

/// IDs from an outside database. A release matches when any one of them does.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub(crate) struct Ids {
    pub tvdb: Option<i64>,
    pub tvmaze: Option<i64>,
    pub tvrage: Option<i64>,
    pub trakt: Option<i64>,
    pub tmdb: Option<i64>,
    /// Digits only, zero-padded to seven as NNTmux stored them.
    pub imdb: Option<String>,
}

impl Ids {
    pub(crate) fn is_empty(&self) -> bool {
        *self == Self::default()
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum Episode {
    Number(u32),
    /// A daily show's air date, sent as `season=2024&ep=10/15`.
    Date {
        year: u32,
        month: u32,
        day: u32,
    },
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct Search {
    pub kind: Kind,
    pub text: String,
    pub ids: Ids,
    pub season: Option<u32>,
    pub episode: Option<Episode>,
    /// Served categories to match, never empty unless nothing requested is served.
    pub categories: Vec<u32>,
    pub min_size: Option<u64>,
    pub max_size: Option<u64>,
    pub max_age_days: Option<u32>,
    pub offset: usize,
    pub limit: usize,
}

/// The deepest page a client can ask for, which bounds the work one request can cause.
pub(crate) const MAX_OFFSET: usize = 100_000;

pub(crate) fn parse(params: &Params, max_results: usize) -> Result<Function, ApiError> {
    let function = params.get("t").ok_or(ApiError::Missing("t"))?;
    let kind = match function.to_ascii_lowercase().as_str() {
        "caps" => return Ok(Function::Caps),
        "get" => return Ok(Function::Get(guid(params)?)),
        "details" => return Ok(Function::Details(guid(params)?)),
        "search" => Kind::Search,
        "tvsearch" | "tv" => Kind::Tv,
        "movie" | "movies" => Kind::Movie,
        _ => return Err(ApiError::NoFunction),
    };
    Ok(Function::Search(Box::new(search(
        params,
        kind,
        max_results,
    )?)))
}

fn guid(params: &Params) -> Result<String, ApiError> {
    let id = params.get("id").ok_or(ApiError::Missing("id"))?;
    // Some clients add the extension the old site used in its links.
    let id = id.strip_suffix(".nzb").unwrap_or(id);
    if id.is_empty() || id.len() > 64 || !id.bytes().all(|b| b.is_ascii_alphanumeric()) {
        return Err(ApiError::Incorrect("id"));
    }
    Ok(id.to_owned())
}

fn search(params: &Params, kind: Kind, max_results: usize) -> Result<Search, ApiError> {
    let ids = match kind {
        Kind::Search => Ids::default(),
        Kind::Tv => Ids {
            tvdb: id(params, "tvdbid")?,
            tvmaze: id(params, "tvmazeid")?,
            tvrage: id(params, "rid")?,
            trakt: id(params, "traktid")?,
            tmdb: id(params, "tmdbid")?,
            imdb: imdb(params)?,
        },
        Kind::Movie => Ids {
            trakt: id(params, "traktid")?,
            tmdb: id(params, "tmdbid")?,
            imdb: imdb(params)?,
            ..Ids::default()
        },
    };
    let (season, episode) = if kind == Kind::Tv {
        season_and_episode(params)?
    } else {
        (None, None)
    };
    let defaults: &[u32] = match kind {
        Kind::Search => &[MOVIES, TV],
        Kind::Tv => &[TV],
        Kind::Movie => &[MOVIES],
    };
    let requested = match params.get("cat") {
        None => Vec::new(),
        Some(list) => list
            .split(',')
            .map(str::trim)
            .filter(|c| !c.is_empty())
            .map(|c| c.parse().map_err(|_| ApiError::Incorrect("cat")))
            .collect::<Result<_, _>>()?,
    };
    let limit = match number::<usize>(params, "limit")? {
        None | Some(0) => max_results,
        Some(limit) => limit.min(max_results),
    };
    let offset = number::<usize>(params, "offset")?.unwrap_or(0);
    if offset > MAX_OFFSET {
        return Err(ApiError::Incorrect("offset"));
    }
    Ok(Search {
        kind,
        text: params.get("q").unwrap_or("").to_owned(),
        ids,
        season,
        episode,
        categories: categories::resolve(&requested, defaults),
        min_size: number(params, "minsize")?,
        max_size: number(params, "maxsize")?,
        max_age_days: number(params, "maxage")?.filter(|&days| days > 0),
        offset,
        limit,
    })
}

fn number<T: std::str::FromStr>(
    params: &Params,
    name: &'static str,
) -> Result<Option<T>, ApiError> {
    params
        .get(name)
        .map(|v| v.parse().map_err(|_| ApiError::Incorrect(name)))
        .transpose()
}

/// A positive outside ID. `0`, as some clients send for an unknown one, counts as absent.
fn id(params: &Params, name: &'static str) -> Result<Option<i64>, ApiError> {
    match number::<i64>(params, name)? {
        Some(id) if id < 0 => Err(ApiError::Incorrect(name)),
        Some(0) | None => Ok(None),
        Some(id) => Ok(Some(id)),
    }
}

fn imdb(params: &Params) -> Result<Option<String>, ApiError> {
    let Some(raw) = params.get("imdbid") else {
        return Ok(None);
    };
    let digits = raw
        .strip_prefix("tt")
        .or_else(|| raw.strip_prefix("TT"))
        .unwrap_or(raw);
    if digits.is_empty() || digits.len() > 10 || !digits.bytes().all(|b| b.is_ascii_digit()) {
        return Err(ApiError::Incorrect("imdbid"));
    }
    let id: u64 = digits.parse().map_err(|_| ApiError::Incorrect("imdbid"))?;
    Ok((id > 0).then(|| format!("{id:07}")))
}

fn season_and_episode(params: &Params) -> Result<(Option<u32>, Option<Episode>), ApiError> {
    let season = params
        .get("season")
        .map(|s| {
            let digits = s.strip_prefix(['s', 'S']).unwrap_or(s);
            digits
                .parse::<u32>()
                .map_err(|_| ApiError::Incorrect("season"))
        })
        .transpose()?;
    let Some(ep) = params.get("ep") else {
        return Ok((season, None));
    };
    if let Some((month, day)) = ep.split_once('/') {
        let date = match (season, month.parse::<u32>(), day.parse::<u32>()) {
            (Some(year), Ok(month), Ok(day))
                if (1900..=9999).contains(&year)
                    && (1..=12).contains(&month)
                    && (1..=31).contains(&day) =>
            {
                Episode::Date { year, month, day }
            }
            _ => return Err(ApiError::Incorrect("ep")),
        };
        return Ok((None, Some(date)));
    }
    let digits = ep.strip_prefix(['e', 'E']).unwrap_or(ep);
    let number = digits.parse().map_err(|_| ApiError::Incorrect("ep"))?;
    Ok((season, Some(Episode::Number(number))))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn parse_str(query: &str) -> Result<Function, ApiError> {
        parse(&Params::parse(query), 100)
    }

    fn search_of(query: &str) -> Search {
        match parse_str(query).unwrap() {
            Function::Search(search) => *search,
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn functions_and_their_aliases() {
        assert_eq!(parse_str("t=caps"), Ok(Function::Caps));
        assert_eq!(parse_str("t=CAPS"), Ok(Function::Caps));
        assert_eq!(search_of("t=tv").kind, Kind::Tv);
        assert_eq!(search_of("t=movie").kind, Kind::Movie);
        assert_eq!(parse_str("t=music"), Err(ApiError::NoFunction));
        assert_eq!(parse_str("q=x"), Err(ApiError::Missing("t")));
        assert_eq!(
            parse_str("t=get&id=abc123.nzb"),
            Ok(Function::Get("abc123".into()))
        );
        assert_eq!(parse_str("t=get"), Err(ApiError::Missing("id")));
        assert_eq!(
            parse_str("t=details&id=a'b"),
            Err(ApiError::Incorrect("id"))
        );
    }

    #[test]
    fn tv_ids_season_and_episode() {
        let s = search_of(
            "t=tvsearch&tvdbid=121361&rid=0&tvmazeid=82&season=S03&ep=E02&imdbid=tt0944947",
        );
        assert_eq!(s.ids.tvdb, Some(121_361));
        assert_eq!(s.ids.tvrage, None);
        assert_eq!(s.ids.tvmaze, Some(82));
        assert_eq!(s.ids.imdb.as_deref(), Some("0944947"));
        assert_eq!(s.season, Some(3));
        assert_eq!(s.episode, Some(Episode::Number(2)));
        assert!(s.categories.contains(&5040) && !s.categories.contains(&2040));
    }

    #[test]
    fn daily_episodes_are_dates() {
        let s = search_of("t=tvsearch&q=show&season=2024&ep=10/15");
        assert_eq!(s.season, None);
        assert_eq!(
            s.episode,
            Some(Episode::Date {
                year: 2024,
                month: 10,
                day: 15
            })
        );
        assert_eq!(
            parse_str("t=tvsearch&season=3&ep=10/15"),
            Err(ApiError::Incorrect("ep"))
        );
    }

    #[test]
    fn movie_ids_and_ignored_tv_params() {
        let s = search_of("t=movie&imdbid=133093&tmdbid=603&tvdbid=5&season=1");
        assert_eq!(s.ids.imdb.as_deref(), Some("0133093"));
        assert_eq!(s.ids.tmdb, Some(603));
        assert_eq!(s.ids.tvdb, None);
        assert_eq!(s.season, None);
        assert!(s.categories.contains(&2045) && !s.categories.contains(&5040));
        assert!(search_of("t=search&imdbid=1").ids.is_empty());
    }

    #[test]
    fn paging_and_filters() {
        let s = search_of(
            "t=search&q=x&limit=500&offset=200&minsize=10&maxsize=20&maxage=3&cat=5040,%202045",
        );
        assert_eq!((s.limit, s.offset), (100, 200));
        assert_eq!(
            (s.min_size, s.max_size, s.max_age_days),
            (Some(10), Some(20), Some(3))
        );
        assert_eq!(s.categories, [2045, 5040]);
        assert_eq!(search_of("t=search&limit=0").limit, 100);
        assert_eq!(search_of("t=search&maxage=0").max_age_days, None);
    }

    #[test]
    fn bad_numbers_are_incorrect_parameters() {
        for (query, name) in [
            ("t=search&limit=x", "limit"),
            ("t=search&offset=-1", "offset"),
            ("t=search&offset=100001", "offset"),
            ("t=search&cat=tv", "cat"),
            ("t=tvsearch&tvdbid=-4", "tvdbid"),
            ("t=tvsearch&season=one", "season"),
            ("t=movie&imdbid=tt", "imdbid"),
            ("t=movie&imdbid=nm123", "imdbid"),
        ] {
            assert_eq!(parse_str(query), Err(ApiError::Incorrect(name)), "{query}");
        }
    }
}
