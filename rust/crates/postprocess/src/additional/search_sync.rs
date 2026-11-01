//! `ReleaseSearchSyncCoordinator`: while a release is being processed, its search refreshes
//! collapse into one at the end, and a refresh for work that was rolled back can be dropped.

use std::sync::Arc;

use nntmux_namefix::update::ReleaseSearch;

/// Refreshes requested and run, for the per-release persistence metrics.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct SyncCounts {
    pub requests: u64,
    pub executions: u64,
}

pub struct SearchSync {
    search: Arc<dyn ReleaseSearch>,
    coalesce: bool,
    release_id: Option<i64>,
    pending: bool,
    pub counts: SyncCounts,
}

impl SearchSync {
    pub fn new(search: Arc<dyn ReleaseSearch>, coalesce: bool) -> Self {
        Self {
            search,
            coalesce,
            release_id: None,
            pending: false,
            counts: SyncCounts::default(),
        }
    }

    /// Requests for this release wait for [`Self::finish_release_scope`].
    pub fn begin_release_scope(&mut self, release_id: i64) {
        if !self.coalesce {
            return;
        }
        self.release_id = Some(release_id);
        self.pending = false;
    }

    pub async fn request(&mut self, release_id: i64) {
        self.counts.requests += 1;
        if self.coalesce && self.release_id == Some(release_id) {
            self.pending = true;
            return;
        }
        self.execute(release_id).await;
    }

    /// Drops a waiting refresh, as when the writes it was for rolled back.
    pub fn discard(&mut self, release_id: i64) {
        if self.coalesce && self.release_id == Some(release_id) {
            self.pending = false;
        }
    }

    /// Ends the scope, running its refresh if one is still wanted.
    pub async fn finish_release_scope(&mut self) {
        if !self.coalesce {
            return;
        }
        let release_id = self.release_id.take();
        let pending = std::mem::take(&mut self.pending);
        if let Some(release_id) = release_id
            && pending
        {
            self.execute(release_id).await;
        }
    }

    async fn execute(&mut self, release_id: i64) {
        self.counts.executions += 1;
        self.search.update_release(release_id).await;
    }
}

#[cfg(test)]
mod tests {
    use std::sync::Mutex;

    use async_trait::async_trait;

    use super::*;

    #[derive(Default)]
    struct Recorder(Mutex<Vec<i64>>);

    #[async_trait]
    impl ReleaseSearch for Recorder {
        async fn update_release(&self, release_id: i64) {
            self.0.lock().expect("unpoisoned").push(release_id);
        }
    }

    #[tokio::test]
    async fn requests_in_scope_collapse_and_can_be_discarded() {
        let recorder = Arc::new(Recorder::default());
        let mut sync = SearchSync::new(recorder.clone(), true);
        sync.begin_release_scope(7);
        sync.request(7).await;
        sync.request(7).await;
        sync.request(8).await;
        assert_eq!(*recorder.0.lock().expect("unpoisoned"), [8]);
        sync.finish_release_scope().await;
        assert_eq!(*recorder.0.lock().expect("unpoisoned"), [8, 7]);

        sync.begin_release_scope(9);
        sync.request(9).await;
        sync.discard(9);
        sync.finish_release_scope().await;
        assert_eq!(*recorder.0.lock().expect("unpoisoned"), [8, 7]);
        assert_eq!(
            sync.counts,
            SyncCounts {
                requests: 4,
                executions: 2
            }
        );
    }

    #[tokio::test]
    async fn without_coalescing_every_request_runs() {
        let recorder = Arc::new(Recorder::default());
        let mut sync = SearchSync::new(recorder.clone(), false);
        sync.begin_release_scope(1);
        sync.request(1).await;
        sync.request(1).await;
        sync.finish_release_scope().await;
        assert_eq!(*recorder.0.lock().expect("unpoisoned"), [1, 1]);
    }
}
