//! How many workers each GUID bucket gets. Port of `PostProcessRunner::additionalQueue`.

/// Claim batches per worker before it exits and a fresh one starts.
pub const WORKER_MAX_BATCHES: i64 = 4;

/// One worker per bucket with work, then extra workers for the buckets with the most claimable
/// batches left, up to `max_processes`. `postthreads` stays the hard connection cap unless there
/// are more buckets than threads, which still get one worker each.
pub fn additional_queue(
    buckets: &[(String, i64)],
    max_processes: i64,
    query_limit: i64,
) -> Vec<String> {
    if buckets.is_empty() {
        return Vec::new();
    }
    let per_batch = query_limit.max(1);
    let demand: Vec<i64> = buckets
        .iter()
        .map(|(_, count)| (count.saturating_add(per_batch - 1) / per_batch).max(1))
        .collect();
    let mut allocations = vec![1_i64; buckets.len()];
    let mut queue: Vec<String> = buckets.iter().map(|(bucket, _)| bucket.clone()).collect();
    let total: i64 = demand.iter().sum();
    let target = usize::try_from(max_processes.max(1).min(total))
        .unwrap_or(usize::MAX)
        .max(queue.len());
    while queue.len() < target {
        let mut next = None;
        let mut largest = 0;
        for (index, wanted) in demand.iter().enumerate() {
            let remaining = wanted - allocations[index];
            if remaining > largest {
                largest = remaining;
                next = Some(index);
            }
        }
        let Some(index) = next else {
            break;
        };
        queue.push(buckets[index].0.clone());
        allocations[index] += 1;
    }
    queue
}

#[cfg(test)]
mod tests {
    use super::*;

    fn buckets(list: &[(&str, i64)]) -> Vec<(String, i64)> {
        list.iter().map(|(b, c)| ((*b).to_owned(), *c)).collect()
    }

    #[test]
    fn empty_backlog_starts_nothing() {
        assert!(additional_queue(&[], 8, 25).is_empty());
    }

    #[test]
    fn every_bucket_gets_a_worker_even_past_the_thread_count() {
        let queue = additional_queue(&buckets(&[("a", 1), ("b", 1), ("c", 1)]), 2, 25);
        assert_eq!(queue, ["a", "b", "c"]);
    }

    #[test]
    fn a_hot_bucket_takes_the_spare_threads() {
        let queue = additional_queue(&buckets(&[("a", 100), ("b", 5)]), 4, 25);
        assert_eq!(queue, ["a", "b", "a", "a"]);
    }

    #[test]
    fn spare_threads_stop_at_the_claimable_batches() {
        let queue = additional_queue(&buckets(&[("a", 26), ("b", 1)]), 16, 25);
        assert_eq!(queue, ["a", "b", "a"]);
    }

    #[test]
    fn the_busiest_bucket_goes_first_and_ties_go_to_the_earlier_one() {
        let queue = additional_queue(&buckets(&[("a", 50), ("b", 75)]), 5, 25);
        assert_eq!(queue, ["a", "b", "b", "a", "b"]);
    }
}
