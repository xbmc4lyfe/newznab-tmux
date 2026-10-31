//! Splits release IDs into ranges, bounding the NZB bytes each one holds in memory.

/// An inclusive range of release IDs.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct IdRange {
    pub low: i64,
    pub high: i64,
}

#[derive(Debug)]
pub struct Planner {
    max_rows: usize,
    max_decoded_bytes: u64,
    low: i64,
    high: i64,
    rows: usize,
    decoded_bytes: u64,
}

impl Planner {
    pub fn new(max_rows: usize, max_decoded_bytes: u64) -> Self {
        Self {
            max_rows,
            max_decoded_bytes,
            low: 0,
            high: 0,
            rows: 0,
            decoded_bytes: 0,
        }
    }

    /// Adds the next release, in ID order. Returns a range once one is full.
    pub fn push(&mut self, id: i64, decoded_bytes: u64) -> Option<IdRange> {
        let full = self.rows > 0
            && (self.rows >= self.max_rows
                || self.decoded_bytes.saturating_add(decoded_bytes) > self.max_decoded_bytes);
        let closed = if full { self.finish() } else { None };
        if self.rows == 0 {
            self.low = id;
        }
        self.high = id;
        self.rows += 1;
        self.decoded_bytes = self.decoded_bytes.saturating_add(decoded_bytes);
        closed
    }

    /// Takes whatever is left.
    pub fn finish(&mut self) -> Option<IdRange> {
        if self.rows == 0 {
            return None;
        }
        self.rows = 0;
        self.decoded_bytes = 0;
        Some(IdRange {
            low: self.low,
            high: self.high,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn plan(max_rows: usize, max_bytes: u64, rows: &[(i64, u64)]) -> Vec<(i64, i64)> {
        let mut planner = Planner::new(max_rows, max_bytes);
        let mut ranges: Vec<IdRange> = rows
            .iter()
            .filter_map(|&(id, bytes)| planner.push(id, bytes))
            .collect();
        ranges.extend(planner.finish());
        ranges.into_iter().map(|r| (r.low, r.high)).collect()
    }

    #[test]
    fn splits_on_row_count() {
        assert_eq!(plan(2, 100, &[(1, 0), (5, 0), (9, 0)]), [(1, 5), (9, 9)]);
    }

    #[test]
    fn splits_on_bytes_without_exceeding_them() {
        assert_eq!(
            plan(9, 100, &[(1, 60), (2, 40), (3, 1), (4, 100)]),
            [(1, 2), (3, 3), (4, 4)]
        );
    }

    #[test]
    fn one_huge_nzb_still_gets_through() {
        assert_eq!(plan(9, 100, &[(1, 500), (2, 500)]), [(1, 1), (2, 2)]);
    }

    #[test]
    fn nothing_makes_no_ranges() {
        assert_eq!(plan(9, 100, &[]), []);
    }
}
