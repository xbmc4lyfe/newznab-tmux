//! The Newznab categories this indexer serves: TV and movies, without anime.
//!
//! IDs are NNTmux's, which the catalog keeps as `legacy_category`, so NZBHydra2's category
//! mapping for the old site still applies.

pub(crate) struct Root {
    pub id: u32,
    pub name: &'static str,
    pub subcats: &'static [(u32, &'static str)],
}

pub(crate) const MOVIES: u32 = 2000;
pub(crate) const TV: u32 = 5000;

pub(crate) const ROOTS: &[Root] = &[
    Root {
        id: MOVIES,
        name: "Movies",
        subcats: &[
            (2010, "Foreign"),
            (2030, "SD"),
            (2040, "HD"),
            (2045, "UHD"),
            (2050, "3D"),
            (2060, "BluRay"),
            (2070, "DVD"),
            (2080, "WEB-DL"),
            (2090, "x265"),
            (2999, "Other"),
        ],
    },
    Root {
        id: TV,
        name: "TV",
        subcats: &[
            (5010, "WEB-DL"),
            (5020, "Foreign"),
            (5030, "SD"),
            (5040, "HD"),
            (5045, "UHD"),
            (5060, "Sport"),
            (5080, "Documentary"),
            (5090, "x265"),
            (5999, "Other"),
        ],
    },
];

fn root(id: u32) -> Option<&'static Root> {
    ROOTS.iter().find(|root| root.id == id)
}

/// The root a served category belongs to.
pub(crate) fn parent(id: u32) -> Option<&'static Root> {
    ROOTS
        .iter()
        .find(|root| root.id == id || root.subcats.iter().any(|&(sub, _)| sub == id))
}

/// `TV > HD`, or the root's own name.
pub(crate) fn name(id: u32) -> String {
    match parent(id) {
        Some(root) if root.id == id => root.name.to_owned(),
        Some(root) => {
            let sub = root.subcats.iter().find(|&&(sub, _)| sub == id);
            format!("{} > {}", root.name, sub.map_or("Other", |&(_, name)| name))
        }
        None => "Other".to_owned(),
    }
}

/// Every served category under `roots`, roots included.
fn all_under(roots: &[u32]) -> Vec<u32> {
    let mut out = Vec::new();
    for root in roots.iter().filter_map(|&id| root(id)) {
        out.push(root.id);
        out.extend(root.subcats.iter().map(|&(id, _)| id));
    }
    out
}

/// The categories to search: the requested ones that this indexer serves, with a root standing
/// for all of its subcategories, or everything under `default_roots` when none were requested.
/// Requested categories outside the scope, such as anime or music, match nothing.
pub(crate) fn resolve(requested: &[u32], default_roots: &[u32]) -> Vec<u32> {
    if requested.is_empty() {
        return all_under(default_roots);
    }
    let mut out = Vec::new();
    for &id in requested {
        if root(id).is_some() {
            out.extend(all_under(&[id]));
        } else if parent(id).is_some() {
            out.push(id);
        }
    }
    out.sort_unstable();
    out.dedup();
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_show_the_parent() {
        assert_eq!(name(5040), "TV > HD");
        assert_eq!(name(2000), "Movies");
        assert_eq!(name(2045), "Movies > UHD");
        assert_eq!(name(5070), "Other");
    }

    #[test]
    fn a_root_stands_for_its_subcategories() {
        let tv = resolve(&[5000], &[]);
        assert!(tv.contains(&5000) && tv.contains(&5040) && tv.contains(&5999));
        assert!(!tv.contains(&5070), "anime is out of scope");
        assert!(!tv.contains(&2040));
        assert_eq!(resolve(&[5040, 2045, 5040], &[]), [2045, 5040]);
    }

    #[test]
    fn out_of_scope_categories_match_nothing() {
        assert!(resolve(&[5070, 3000, 7020], &[TV]).is_empty());
        assert_eq!(resolve(&[5070, 5045], &[TV]), [5045]);
    }

    #[test]
    fn no_request_means_the_defaults() {
        assert_eq!(resolve(&[], &[TV]), resolve(&[TV], &[]));
        assert_eq!(resolve(&[], &[MOVIES, TV]).len(), 21);
    }
}
