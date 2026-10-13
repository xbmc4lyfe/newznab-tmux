## Release lifecycle and cleanup

- Check source, runtime image, schema, and live publication separately. A successful build is not image/source parity or proof deployed handlers executed; compare relevant runtime source hashes with reviewed source.
- For release admission/reset changes, exercise real coordinator/transaction boundaries: rollback, after-commit failures, expired claims, implicit commits, concurrent admission, and skipped sibling ranges as relevant. Distinguish simulated durable interruption from an actual process-kill test.
- Preserve ownership until the outer transaction commits or rolls back; preserve filesystem terminal state on database lookup/commit failure.
- Verify new NZBs in fresh scratch by reconstructing from their actual article IDs. Retained reconstructed files alone do not prove emitted NZB sufficiency; validate cached article integrity first.
- Cleanup preserves active/newer versions and stable lock inodes, handles symlinks safely, and retries interrupted/failed work fairly under new arrivals. State bounds precisely: deletion/database budgets do not bound linear top-level metadata enumeration. Cover older direct callers when changing dependencies or exit contracts.
