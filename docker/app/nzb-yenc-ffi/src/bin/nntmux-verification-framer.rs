//! Persistent bounded NNTP framer; transport stays with the PHP caller.
use std::io::{self, BufReader, BufWriter};
use std::process::ExitCode;

fn main() -> ExitCode {
    let stdin = io::stdin();
    let stdout = io::stdout();
    match nzbyenc::bulk_verification::run(
        BufReader::new(stdin.lock()),
        BufWriter::new(stdout.lock()),
    ) {
        Ok(()) => ExitCode::SUCCESS,
        Err(_) => ExitCode::FAILURE,
    }
}
