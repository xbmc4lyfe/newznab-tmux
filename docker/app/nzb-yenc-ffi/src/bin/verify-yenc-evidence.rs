#[path = "../abi_receipts.rs"]
#[allow(dead_code)]
mod abi_receipts;
#[path = "../benchmark.rs"]
#[allow(dead_code)]
mod benchmark;
#[path = "../evidence.rs"]
mod evidence;
#[path = "../tooling.rs"]
#[allow(dead_code)]
mod tooling;

#[derive(Debug, PartialEq)]
struct Options {
    directory: String,
    source: String,
    candidate: Option<String>,
}

fn parse_options(arguments: impl IntoIterator<Item = String>) -> Result<Options, String> {
    let mut arguments = arguments.into_iter();
    let mut directory = None;
    let mut source = None;
    let mut candidate = None;
    while let Some(name) = arguments.next() {
        let value = arguments.next().ok_or("option needs value")?;
        let slot = match name.as_str() {
            "--directory" => &mut directory,
            "--source-root" => &mut source,
            "--candidate" => &mut candidate,
            _ => return Err(format!("unknown option {name}")),
        };
        if slot.replace(value).is_some() {
            return Err(format!("duplicate option {name}"));
        }
    }
    Ok(Options {
        directory: directory.ok_or("missing --directory")?,
        source: source.ok_or("missing --source-root")?,
        candidate,
    })
}

fn main() {
    let result = parse_options(std::env::args().skip(1))
        .and_then(|options| {
            let directory = std::path::Path::new(&options.directory);
            let source = std::path::Path::new(&options.source);
            match options.candidate {
                Some(candidate) => evidence::verify_with_candidate(
                    directory,
                    source,
                    std::path::Path::new(&candidate),
                ),
                None => evidence::verify(directory, source),
            }
        })
        .and_then(tooling::with_adoption_eligibility);
    match result {
        Ok(report) => println!("{report}"),
        Err(error) => {
            eprintln!("evidence verification failed: {error}");
            std::process::exit(1);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn options(args: &[&str]) -> Result<Options, String> {
        parse_options(args.iter().map(|arg| (*arg).into()))
    }

    #[test]
    fn candidate_option_is_optional_and_accepts_any_option_order() {
        let legacy = options(&["--directory", "bundle", "--source-root", "source"]).unwrap();
        assert_eq!(legacy.candidate, None);
        let bound = options(&[
            "--candidate",
            "lib.so",
            "--source-root",
            "source",
            "--directory",
            "bundle",
        ])
        .unwrap();
        assert_eq!(
            bound,
            Options {
                candidate: Some("lib.so".into()),
                ..legacy
            }
        );
    }

    #[test]
    fn candidate_option_rejects_duplicate_unknown_and_missing_arguments() {
        assert!(
            options(&[
                "--directory",
                "bundle",
                "--source-root",
                "source",
                "--candidate",
                "one",
                "--candidate",
                "two"
            ])
            .unwrap_err()
            .contains("duplicate option --candidate")
        );
        assert!(
            options(&[
                "--directory",
                "bundle",
                "--source-root",
                "source",
                "--unknown",
                "value"
            ])
            .unwrap_err()
            .contains("unknown option")
        );
        assert!(
            options(&[
                "--directory",
                "bundle",
                "--source-root",
                "source",
                "--candidate"
            ])
            .is_err()
        );
        assert!(options(&["--candidate", "lib.so", "--directory", "bundle"]).is_err());
        assert!(options(&["--candidate", "lib.so", "--source-root", "source"]).is_err());
    }
}
