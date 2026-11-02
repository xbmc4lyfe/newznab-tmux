## Testing

- `just test` starts the shared services in `rust/compose.test.yml` (MariaDB on 33306, Redis on 36379, Manticore on 39306 and 39308), sets the `TEST_*` variables, then runs `cargo test --locked`. Extra arguments go to cargo: `just test -p nntmux-search`.
- `just check` runs `cargo fmt --all --check`, clippy with `-D warnings`, and `python3 rust/porting/check.py` (the FFI ban and the ledger gate). CI runs the same checks plus the workspace tests.
- Database tests use `nntmux_testkit::TestDb`. Each test gets its own database on `TEST_DATABASE_URL`, and the test skips itself when the variable is unset. Prefix any Redis keys and Manticore tables the same way, because the services are shared by all worktrees.
- Pure logic needs a golden test. Provider and HTTP clients need `wiremock` tests. No real API or NNTP calls. Tests that only restate the implementation don't count.
- Fixtures come from the PHP reference (`php-final`). Commit them under `rust/crates/<crate>/tests/fixtures/` with the small script that made them. API XML and JSON must match recorded PHP responses byte for byte.
- Set `CARGO_BUILD_JOBS=4` and test one crate with `-p` while working; run the whole workspace before a PR.
- When you port a ledger entry, set it to `ported` in `rust/porting/ledger/<workstream>.toml` in the same PR.
