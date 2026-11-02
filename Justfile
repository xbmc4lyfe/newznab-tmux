# NNTmux root Justfile. The production stack lives in docker/Justfile (`just docker <recipe>`);
# the shortcuts below forward to it. Rust development recipes follow.
set positional-arguments := true
set shell := ["bash", "-cu"]
# The running just binary, so nested calls work where just is not on PATH (noninteractive SSH).
export JUST := just_executable()

mod docker 'docker/Justfile'

TEST_ENV := "TEST_DATABASE_URL=mysql://root:root@127.0.0.1:33306 TEST_REDIS_URL=redis://127.0.0.1:36379/ TEST_MANTICORE_SQL=127.0.0.1:39306 TEST_MANTICORE_HTTP=127.0.0.1:39308"

# Show available recipes
help *args:
    @"$JUST" --list --justfile "{{justfile()}}"

# Guarded production rollout (docker/bin/deploy)
deploy *args:
    @"$JUST" --justfile "{{justfile()}}" docker deploy "$@"

# What deploy would do, without changing anything
deploy-plan *args:
    @"$JUST" --justfile "{{justfile()}}" docker deploy-plan "$@"

# Build the app image
build *args:
    @"$JUST" --justfile "{{justfile()}}" docker build "$@"

# Start the shared test services (MariaDB, Redis, Manticore) for integration tests
test-services *args:
    docker compose -f rust/compose.test.yml up -d --wait

# Run tests: just test (workspace) or just test -p nntmux-search
test *args: test-services
    cd rust && env {{TEST_ENV}} CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-4}" cargo test --locked "$@"

# The checks a PR needs before review: fmt, clippy, the porting gate
check *args:
    cd rust && cargo fmt --all --check
    cd rust && CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-4}" cargo clippy --workspace --all-targets --locked -- -D warnings
    python3 rust/porting/check.py

# Build the front end into rust/frontend/public/build
frontend *args:
    cd rust/frontend && npm ci && npm run build
