//! `DonorMatchSelector`: picks the release whose name a candidate may take over. Donors are
//! rows from the CRC32, hash and size queries; only the columns the selectors read are kept.

use nntmux_categorize::pcre::{PHP_WS, trim_chars};
use nntmux_predb::filename::{exact_value, has_control};

/// A donor row. A field is `None` where the PHP object doesn't have the property (or it is
/// null), which matters for the `??` chains below.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Donor {
    pub releases_id: Option<i64>,
    pub id: Option<i64>,
    pub relsize: Option<i64>,
    pub size: Option<i64>,
    pub searchname: Option<String>,
    pub predb_id: Option<i64>,
    pub canonical_title: Option<String>,
}

/// `selectCanonical`: the lowest release id among donors that all vouch for the same PreDB
/// row and the same canonical title, with `searchname` replaced by that title. Any donor that
/// doesn't, or more than 256 donors, selects nothing.
pub fn select_canonical(donors: &[Donor]) -> Option<Donor> {
    if donors.len() > 256 {
        return None;
    }
    let mut identity: Option<String> = None;
    let mut selected: Option<Donor> = None;
    for donor in donors {
        let title = donor.canonical_title.as_deref().unwrap_or("");
        let search_name = donor.searchname.as_deref().unwrap_or("");
        let predb_id = donor.predb_id.unwrap_or(0);
        if predb_id <= 0
            || trim_chars(title, PHP_WS).is_empty()
            || title.chars().count() > 255
            || has_control(title)
            || exact_value(title) != exact_value(search_name)
        {
            return None;
        }
        let current = format!("{predb_id}:{}", exact_value(title));
        if identity.as_ref().is_some_and(|seen| *seen != current) {
            return None;
        }
        identity = Some(current);
        let lowest = selected
            .as_ref()
            .is_none_or(|best| donor.releases_id.unwrap_or(0) < best.releases_id.unwrap_or(0));
        if lowest {
            let mut chosen = donor.clone();
            chosen.searchname = Some(title.to_owned());
            selected = Some(chosen);
        }
    }
    selected
}

/// `select`: the donor closest in size to `target_size` within `tolerance_percent` of the
/// donor's own size, the lower release id winning a tie. A donor without a size is skipped.
pub fn select(donors: &[Donor], target_size: i64, tolerance_percent: i64) -> Option<&Donor> {
    if target_size <= 0 {
        return None;
    }
    let mut best: Option<(i128, i64, &Donor)> = None;
    for donor in donors {
        let size = donor.relsize.or(donor.size).unwrap_or(0);
        if size <= 0 {
            continue;
        }
        let difference = (i128::from(size) - i128::from(target_size)).abs();
        let percent = difference as f64 / size as f64 * 100.0;
        if percent > tolerance_percent as f64 {
            continue;
        }
        let id = donor.releases_id.or(donor.id).unwrap_or(i64::MAX);
        // usort is stable, so only a strictly smaller (difference, id) displaces the first.
        if best.is_none_or(|(d, i, _)| (difference, id) < (d, i)) {
            best = Some((difference, id, donor));
        }
    }
    best.map(|(_, _, donor)| donor)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sized(releases_id: i64, relsize: i64) -> Donor {
        Donor {
            releases_id: Some(releases_id),
            relsize: Some(relsize),
            ..Donor::default()
        }
    }

    #[test]
    fn closest_size_wins_and_the_lower_id_breaks_ties() {
        let donors = [sized(20, 950), sized(10, 1050), sized(30, 900)];
        assert_eq!(
            select(&donors, 1000, 10).and_then(|d| d.releases_id),
            Some(10)
        );
    }

    #[test]
    fn zero_sized_out_of_tolerance_and_empty_targets_select_nothing() {
        assert_eq!(select(&[sized(10, 0), sized(20, 500)], 1000, 10), None);
        assert_eq!(select(&[sized(30, 1000)], 0, 10), None);
        assert_eq!(select(&[], 1000, 10), None);
    }

    #[test]
    fn size_falls_back_to_the_size_column_and_id_to_the_row_id() {
        let donors = [
            Donor {
                id: Some(5),
                size: Some(1000),
                ..Donor::default()
            },
            Donor {
                releases_id: Some(4),
                size: Some(1000),
                ..Donor::default()
            },
        ];
        assert_eq!(
            select(&donors, 1000, 5).and_then(|d| d.releases_id),
            Some(4)
        );
    }

    fn vouched(releases_id: i64, predb_id: i64, title: &str) -> Donor {
        Donor {
            releases_id: Some(releases_id),
            predb_id: Some(predb_id),
            canonical_title: Some(title.to_owned()),
            searchname: Some(title.to_lowercase()),
            ..Donor::default()
        }
    }

    #[test]
    fn canonical_selection_needs_every_donor_to_agree() {
        let title = "Some.Movie.2020.1080p-GRP";
        let chosen = select_canonical(&[vouched(5, 1, title), vouched(3, 1, title)]).unwrap();
        assert_eq!(chosen.releases_id, Some(3));
        // The selected donor's search name is the canonical spelling.
        assert_eq!(chosen.searchname.as_deref(), Some(title));
        assert_eq!(
            select_canonical(&[vouched(5, 1, title), vouched(3, 2, title)]),
            None
        );
        assert_eq!(select_canonical(&[vouched(5, 0, title)]), None);
        assert_eq!(select_canonical(&[]), None);
        let many: Vec<Donor> = (0..257).map(|id| vouched(id, 9, title)).collect();
        assert_eq!(select_canonical(&many), None);
        assert!(select_canonical(&many[..256]).is_some());
    }
}
