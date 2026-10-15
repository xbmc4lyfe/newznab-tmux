//! Rejected experiment only. Never installed or called by production.
use std::{env, fs::File, process::ExitCode};
fn main() -> ExitCode {
    let args: Vec<_> = env::args_os().collect();
    let result = (|| -> std::io::Result<()> {
        if args.len() != 2 {
            return Err(std::io::Error::other("argument count"));
        }
        let file = File::open(&args[1])?;
        if !file.metadata()?.is_dir() {
            return Err(std::io::Error::other("not a directory"));
        }
        file.sync_all()
    })();
    println!("{{\"ok\":{}}}", result.is_ok());
    if result.is_ok() {
        ExitCode::SUCCESS
    } else {
        ExitCode::from(74)
    }
}
