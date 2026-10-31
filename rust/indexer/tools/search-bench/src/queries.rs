//! Builds query classes from real names and works out the true answer for each by brute force.

use crate::corpus::{Doc, Rng, tokens};
use rayon::prelude::*;
use regex::Regex;
use serde::{Deserialize, Serialize};
use std::collections::{HashMap, HashSet};

pub const LIMIT: usize = 100;

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Q {
    pub class: String,
    pub terms: Vec<String>,
    #[serde(default)]
    pub neg: Vec<String>,
    /// Inclusive category range, for example 5000..=5999 for TV.
    pub cat: Option<(u32, u32)>,
    pub min_size: Option<u64>,
    /// The one release this query must find, for the exact-title and typo classes.
    pub target: Option<u32>,
    #[serde(default)]
    pub truth_count: u32,
    /// Newest matches first, up to LIMIT.
    #[serde(default)]
    pub truth_newest: Vec<u32>,
}

impl Q {
    fn new(class: &str, terms: Vec<String>) -> Q {
        Q {
            class: class.into(),
            terms,
            neg: vec![],
            cat: None,
            min_size: None,
            target: None,
            truth_count: 0,
            truth_newest: vec![],
        }
    }

    /// Whether a document satisfies this query under the reference tokenizer.
    pub fn matches(&self, d: &Doc) -> bool {
        let t: HashSet<String> = tokens(&d.name).into_iter().collect();
        self.terms.iter().all(|x| t.contains(x))
            && !self.neg.iter().any(|x| t.contains(x))
            && self.cat.is_none_or(|(lo, hi)| d.cat >= lo && d.cat <= hi)
            && self.min_size.is_none_or(|m| d.size >= m)
    }
}

const COMMON: &[&str] = &[
    "1080p",
    "2160p",
    "720p",
    "x265",
    "x264",
    "hevc",
    "h 264",
    "web dl",
    "bluray",
    "remux",
    "1080p web dl",
    "2160p web dl",
    "2160p remux",
    "1080p bluray remux",
    "bluray 1080p x264",
    "dv hdr 2160p",
    "ddp5 1",
    "truehd 7 1",
    "s01e01",
    "s01e01 1080p",
    "2023",
    "2023 1080p",
    "framestor",
    "cinephiles",
    "2160p remux framestor",
    "flux 1080p",
];

struct Parsed {
    title: Vec<String>,
    ep: Option<String>,
    year: Option<String>,
    res: Option<String>,
    src: Option<String>,
    group: Option<String>,
}

fn parse(name: &str, tv: &Regex, movie: &Regex, group: &Regex) -> Option<Parsed> {
    let all = tokens(name);
    if all.len() < 3
        || all
            .iter()
            .any(|t| t.len() >= 16 && t.chars().all(|c| c.is_ascii_hexdigit()))
    {
        return None;
    }
    let (title, ep, year) = if let Some(c) = tv.captures(name) {
        (
            tokens(&c[1]),
            Some(format!("s{:0>2}e{:0>2}", &c[2], &c[3]).to_lowercase()),
            None,
        )
    } else {
        let c = movie.captures(name)?;
        (tokens(&c[1]), None, Some(c[2].to_string()))
    };
    if title.is_empty() || title.len() > 8 {
        return None;
    }
    let pick = |opts: &[&str]| {
        opts.iter()
            .find(|o| all.iter().any(|t| t == *o))
            .map(|o| o.to_string())
    };
    Some(Parsed {
        title,
        ep: ep.filter(|e| all.contains(e)),
        year,
        res: pick(&["2160p", "1080p", "720p"]),
        src: pick(&["remux", "bluray", "web", "webrip", "hdtv"]),
        group: group.captures(name).map(|c| c[1].to_lowercase()),
    })
}

type MakeQuery = Box<dyn Fn(&Doc, &Parsed, &mut Rng) -> Option<Q>>;

/// Substitutes one letter in the longest title word, giving a realistic one-letter typo.
fn typo(terms: &[String], r: &mut Rng) -> Option<Vec<String>> {
    let (i, w) = terms
        .iter()
        .enumerate()
        .filter(|(_, t)| t.len() >= 5 && t.is_ascii())
        .max_by_key(|(_, t)| t.len())?;
    let mut b = w.clone().into_bytes();
    let at = 1 + r.below(b.len() - 2);
    b[at] = if b[at] == b'x' { b'q' } else { b'x' };
    let mut out = terms.to_vec();
    out[i] = String::from_utf8(b).ok()?;
    Some(out)
}

pub fn generate(corpus: &[Doc], per_class: usize, seed: u64) -> Vec<Q> {
    let tv = Regex::new(r"(?i)^(.+?)[ ._-]+s(\d{1,2})e(\d{1,3})(?:[ ._-]|$)").unwrap();
    let movie = Regex::new(r"(?i)^(.+?)[ ._(\[-]+((?:19|20)\d{2})[ ._)\]-]").unwrap();
    let group = Regex::new(r"-([A-Za-z0-9]{2,20})$").unwrap();
    let parsed: Vec<Option<Parsed>> = corpus
        .par_iter()
        .map(|d| parse(&d.name, &tv, &movie, &group))
        .collect();

    let in_tv = |d: &Doc| (5000..=5999).contains(&d.cat);
    let in_movie = |d: &Doc| (2000..=2999).contains(&d.cat);
    const BIG: u64 = 500_000_000;
    let mut r = Rng::new(seed);
    let mut out: Vec<Q> = Vec::new();

    let classes: Vec<(&str, MakeQuery)> = vec![
        (
            "tv_ep",
            Box::new(|_, p, _| {
                Some(Q::new(
                    "tv_ep",
                    [p.title.clone(), vec![p.ep.clone()?]].concat(),
                ))
            }),
        ),
        (
            "tv_ep_res",
            Box::new(|_, p, _| {
                Some(Q::new(
                    "tv_ep_res",
                    [p.title.clone(), vec![p.ep.clone()?, p.res.clone()?]].concat(),
                ))
            }),
        ),
        (
            "tv_title",
            Box::new(|_, p, _| {
                p.ep.as_ref()?;
                Some(Q::new("tv_title", p.title.clone()))
            }),
        ),
        (
            "movie_year",
            Box::new(|_, p, _| {
                Some(Q::new(
                    "movie_year",
                    [p.title.clone(), vec![p.year.clone()?]].concat(),
                ))
            }),
        ),
        (
            "movie_year_q",
            Box::new(|_, p, _| {
                Some(Q::new(
                    "movie_year_q",
                    [
                        p.title.clone(),
                        vec![p.year.clone()?, p.res.clone()?, p.src.clone()?],
                    ]
                    .concat(),
                ))
            }),
        ),
        (
            "group_res",
            Box::new(|_, p, _| Some(Q::new("group_res", vec![p.group.clone()?, p.res.clone()?]))),
        ),
        (
            "tv_ep_filter",
            Box::new(move |d, p, _| {
                if !in_tv(d) || d.size < BIG {
                    return None;
                }
                let mut q = Q::new(
                    "tv_ep_filter",
                    [p.title.clone(), vec![p.ep.clone()?]].concat(),
                );
                q.cat = Some((5000, 5999));
                q.min_size = Some(BIG);
                Some(q)
            }),
        ),
        (
            "movie_filter",
            Box::new(move |d, p, _| {
                if !in_movie(d) || d.size < BIG {
                    return None;
                }
                let mut q = Q::new(
                    "movie_filter",
                    [p.title.clone(), vec![p.year.clone()?]].concat(),
                );
                q.cat = Some((2000, 2999));
                q.min_size = Some(BIG);
                Some(q)
            }),
        ),
        (
            "negation",
            Box::new(|_, p, _| {
                let mut q = Q::new("negation", [p.title.clone(), vec![p.res.clone()?]].concat());
                q.neg = vec!["cam".into(), "hdcam".into()];
                Some(q)
            }),
        ),
        (
            "exact",
            Box::new(|d, _, _| {
                let mut q = Q::new("exact", tokens(&d.name));
                q.target = Some(d.seq);
                Some(q)
            }),
        ),
        (
            "typo",
            Box::new(|d, p, r| {
                let terms = typo(&p.title, r)?;
                let mut q = Q::new(
                    "typo",
                    [terms, vec![p.ep.clone().or_else(|| p.year.clone())?]].concat(),
                );
                q.target = Some(d.seq);
                Some(q)
            }),
        ),
    ];

    for (_, make) in &classes {
        let mut seen = HashSet::new();
        let mut tries = 0;
        let mut got = 0;
        while got < per_class && tries < per_class * 400 {
            tries += 1;
            let i = r.below(corpus.len());
            let Some(p) = &parsed[i] else { continue };
            let Some(q) = make(&corpus[i], p, &mut r) else {
                continue;
            };
            if seen.insert(q.terms.join(" ")) {
                out.push(q);
                got += 1;
            }
        }
    }

    // Common words have huge posting lists, which is the worst case for every engine.
    let copies = (per_class / COMMON.len()).clamp(1, 20);
    for c in COMMON {
        for _ in 0..copies {
            out.push(Q::new("common", tokens(c)));
        }
    }

    // Meilisearch reads only the first 10 query words, so long names get their own class.
    for q in &mut out {
        if q.class == "exact" && q.terms.len() > 10 {
            q.class = "exact_long".into();
        }
    }
    compute_truth(corpus, &mut out);
    out
}

fn compute_truth(corpus: &[Doc], qs: &mut [Q]) {
    let mut post: HashMap<String, Vec<u32>> = HashMap::new();
    for d in corpus {
        let mut t = tokens(&d.name);
        t.sort_unstable();
        t.dedup();
        for x in t {
            post.entry(x).or_default().push(d.seq);
        }
    }
    let empty = Vec::new();
    qs.par_iter_mut().for_each(|q| {
        if q.class == "typo" {
            return; // Judged only by whether the target comes back.
        }
        let mut lists: Vec<&Vec<u32>> = q
            .terms
            .iter()
            .map(|t| post.get(t).unwrap_or(&empty))
            .collect();
        lists.sort_by_key(|l| l.len());
        let neg: Vec<&Vec<u32>> = q.neg.iter().filter_map(|t| post.get(t)).collect();
        let mut count = 0u32;
        let mut newest = Vec::with_capacity(LIMIT);
        // Walk the shortest list from newest to oldest, keeping what every other list also holds.
        for &s in lists[0].iter().rev() {
            if !lists[1..].iter().all(|l| l.binary_search(&s).is_ok())
                || neg.iter().any(|l| l.binary_search(&s).is_ok())
            {
                continue;
            }
            let d = &corpus[s as usize];
            if q.cat.is_some_and(|(lo, hi)| d.cat < lo || d.cat > hi)
                || q.min_size.is_some_and(|m| d.size < m)
            {
                continue;
            }
            count += 1;
            if newest.len() < LIMIT {
                newest.push(s);
            }
        }
        q.truth_count = count;
        q.truth_newest = newest;
    });
}
