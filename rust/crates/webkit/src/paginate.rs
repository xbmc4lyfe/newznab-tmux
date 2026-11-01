//! Page links in the shape of Laravel's paginator: two pages at each end, three either side of
//! the current page, and gaps between.

use crate::config::encode_component;

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum PageLink {
    Page { number: u64, url: String, current: bool },
    Gap,
}

#[derive(Clone, Debug)]
pub struct Pager {
    pub total: u64,
    pub per_page: u64,
    pub current: u64,
    pub last: u64,
    pub first_item: u64,
    pub last_item: u64,
    pub previous_url: Option<String>,
    pub next_url: Option<String>,
    pub links: Vec<PageLink>,
}

fn page_url(path: &str, query: &[(String, String)], page: u64) -> String {
    let mut url = format!("{path}?");
    for (key, value) in query {
        url.push_str(&encode_component(key));
        url.push('=');
        url.push_str(&encode_component(value));
        url.push('&');
    }
    url.push_str(&format!("page={page}"));
    url
}

impl Pager {
    /// `query` is the current query string without `page`, see [`crate::Input::query_pairs`].
    pub fn new(path: &str, query: &[(String, String)], total: u64, per_page: u64, current: u64) -> Self {
        let per_page = per_page.max(1);
        let last = total.div_ceil(per_page).max(1);
        let url = |page: u64| page_url(path, query, page);
        let page = |number: u64| PageLink::Page {
            number,
            url: url(number),
            current: number == current,
        };
        const EACH_SIDE: u64 = 3;
        let window = EACH_SIDE * 2 + 2;
        let mut links = Vec::new();
        if last < window + 6 {
            links.extend((1..=last).map(page));
        } else if current <= window {
            links.extend((1..=window + EACH_SIDE - 1).map(page));
            links.push(PageLink::Gap);
            links.extend((last - 1..=last).map(page));
        } else if current > last.saturating_sub(window) {
            links.extend((1..=2).map(page));
            links.push(PageLink::Gap);
            links.extend((last - (window + EACH_SIDE - 2)..=last).map(page));
        } else {
            links.extend((1..=2).map(page));
            links.push(PageLink::Gap);
            links.extend((current - EACH_SIDE..=current + EACH_SIDE).map(page));
            links.push(PageLink::Gap);
            links.extend((last - 1..=last).map(page));
        }
        let shown = total > 0 && current <= last;
        Self {
            total,
            per_page,
            current,
            last,
            first_item: if shown { (current - 1) * per_page + 1 } else { 0 },
            last_item: if shown { (current * per_page).min(total) } else { 0 },
            previous_url: (current > 1).then(|| url(current - 1)),
            next_url: (current < last).then(|| url(current + 1)),
            links,
        }
    }

    pub fn has_pages(&self) -> bool {
        self.last > 1
    }

    /// The SQL offset for the current page.
    pub fn offset(&self) -> u64 {
        (self.current - 1) * self.per_page
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn numbers(pager: &Pager) -> Vec<String> {
        pager
            .links
            .iter()
            .map(|link| match link {
                PageLink::Page { number, .. } => number.to_string(),
                PageLink::Gap => "…".to_owned(),
            })
            .collect()
    }

    #[test]
    fn short_lists_show_every_page() {
        let pager = Pager::new("/x", &[], 30, 10, 2);
        assert_eq!(numbers(&pager), ["1", "2", "3"]);
        assert_eq!((pager.first_item, pager.last_item), (11, 20));
        assert_eq!(pager.previous_url.as_deref(), Some("/x?page=1"));
        assert_eq!(pager.next_url.as_deref(), Some("/x?page=3"));
    }

    #[test]
    fn long_lists_match_laravels_windows() {
        let at = |page| numbers(&Pager::new("/x", &[], 1000, 10, page)).join(" ");
        assert_eq!(at(1), "1 2 3 4 5 6 7 8 9 10 … 99 100");
        assert_eq!(at(50), "1 2 … 47 48 49 50 51 52 53 … 99 100");
        assert_eq!(at(100), "1 2 … 91 92 93 94 95 96 97 98 99 100");
    }

    #[test]
    fn the_query_string_survives() {
        let query = vec![("t".to_owned(), "5000".to_owned()), ("q".to_owned(), "a b".to_owned())];
        let pager = Pager::new("/browse", &query, 100, 10, 1);
        assert_eq!(pager.next_url.as_deref(), Some("/browse?t=5000&q=a+b&page=2"));
    }

    #[test]
    fn past_the_end_shows_no_items() {
        let pager = Pager::new("/x", &[], 5, 10, 9);
        assert_eq!((pager.first_item, pager.last_item), (0, 0));
        assert_eq!(pager.offset(), 80);
    }
}
