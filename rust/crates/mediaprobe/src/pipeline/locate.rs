//! Which posted files hold the main video, and in what order to read them.

use std::collections::BTreeMap;

/// A file in the NZB: its name from the subject and its segments' message-IDs in file order.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct PostFile {
    pub name: String,
    pub segments: Vec<String>,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Layout {
    /// A video file posted as it is.
    Direct(PostFile),
    /// Pieces that concatenate to the file: `movie.mkv.001`, `.002`, ... in order.
    Split(Vec<PostFile>),
    /// RAR volumes in order, from their names.
    Rar(Vec<PostFile>),
    /// Names say nothing (obfuscated). The largest files first; the first article of each says
    /// what it is.
    Unknown(Vec<PostFile>),
}

const VIDEO: &[&str] = &["mkv", "mp4", "m4v", "m2ts", "ts", "avi", "webm", "mov"];

fn extension(name: &str) -> Option<&str> {
    name.rsplit_once('.').map(|(_, ext)| ext)
}

fn digits(s: &str) -> Option<u32> {
    (!s.is_empty() && s.len() <= 4 && s.bytes().all(|b| b.is_ascii_digit()))
        .then(|| s.parse().ok())
        .flatten()
}

/// `(set stem, volume number)` for a RAR volume name.
fn rar_volume(name: &str) -> Option<(String, u32)> {
    let (stem, ext) = name.rsplit_once('.')?;
    if ext == "rar" {
        // movie.part01.rar / movie.part1.rar, or movie.rar (first of the old style).
        if let Some((base, part)) = stem.rsplit_once(".part")
            && let Some(number) = digits(part)
        {
            return Some((base.to_string(), number));
        }
        return Some((stem.to_string(), 0));
    }
    // movie.r00 follows movie.rar.
    let number = ext.strip_prefix('r').and_then(digits)?;
    Some((stem.to_string(), number + 1))
}

/// `(file name, piece number)` for `movie.mkv.001`.
fn split_piece(name: &str) -> Option<(String, u32)> {
    let (stem, ext) = name.rsplit_once('.')?;
    let number = digits(ext).filter(|_| ext.len() == 3)?;
    let inner = extension(stem)?;
    VIDEO.contains(&inner).then(|| (stem.to_string(), number))
}

fn total_segments(files: &[PostFile]) -> usize {
    files.iter().map(|f| f.segments.len()).sum()
}

/// Picks the layout holding the most segments, which is the main video in any post worth
/// probing (samples, NFOs and PAR2 are small).
pub fn locate(files: &[PostFile]) -> Option<Layout> {
    let mut best: Option<(usize, Layout)> = None;
    let mut consider = |layout: Layout| {
        let size = match &layout {
            Layout::Direct(file) => file.segments.len(),
            Layout::Split(files) | Layout::Rar(files) | Layout::Unknown(files) => {
                total_segments(files)
            }
        };
        if best.as_ref().is_none_or(|(best, _)| size > *best) {
            best = Some((size, layout));
        }
    };

    let mut rar_sets: BTreeMap<String, Vec<(u32, &PostFile)>> = BTreeMap::new();
    let mut split_sets: BTreeMap<String, Vec<(u32, &PostFile)>> = BTreeMap::new();
    let mut unknown = Vec::new();
    for file in files.iter().filter(|f| !f.segments.is_empty()) {
        let name = file.name.to_ascii_lowercase();
        if name.contains("sample") && !name.contains(".rar") {
            continue;
        }
        if let Some((set, number)) = rar_volume(&name) {
            rar_sets.entry(set).or_default().push((number, file));
        } else if let Some((set, number)) = split_piece(&name) {
            split_sets.entry(set).or_default().push((number, file));
        } else if extension(&name).is_some_and(|ext| VIDEO.contains(&ext)) {
            consider(Layout::Direct(file.clone()));
        } else if !matches!(
            extension(&name),
            Some("par2" | "nfo" | "sfv" | "nzb" | "txt" | "jpg" | "png" | "srr" | "srt")
        ) {
            unknown.push(file);
        }
    }
    for (_, mut volumes) in rar_sets {
        volumes.sort_by_key(|(number, _)| *number);
        consider(Layout::Rar(volumes.into_iter().map(|(_, f)| f.clone()).collect()));
    }
    for (_, mut pieces) in split_sets {
        pieces.sort_by_key(|(number, _)| *number);
        consider(Layout::Split(pieces.into_iter().map(|(_, f)| f.clone()).collect()));
    }
    if !unknown.is_empty() {
        unknown.sort_by_key(|f| std::cmp::Reverse(f.segments.len()));
        consider(Layout::Unknown(unknown.into_iter().cloned().collect()));
    }
    best.map(|(_, layout)| layout)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn file(name: &str, segments: usize) -> PostFile {
        PostFile {
            name: name.into(),
            segments: (0..segments).map(|i| format!("<{name}{i}@x>")).collect(),
        }
    }

    #[test]
    fn orders_new_and_old_style_rar() {
        let files = vec![
            file("Movie.part02.rar", 100),
            file("Movie.part10.rar", 50),
            file("Movie.part01.rar", 100),
            file("Movie.par2", 1),
            file("Movie.nfo", 1),
        ];
        let Some(Layout::Rar(volumes)) = locate(&files) else {
            panic!("not rar");
        };
        let names: Vec<_> = volumes.iter().map(|v| v.name.as_str()).collect();
        assert_eq!(names, ["Movie.part01.rar", "Movie.part02.rar", "Movie.part10.rar"]);

        let old = vec![file("x.r01", 9), file("x.rar", 9), file("x.r00", 9)];
        let Some(Layout::Rar(volumes)) = locate(&old) else {
            panic!("not rar");
        };
        let names: Vec<_> = volumes.iter().map(|v| v.name.as_str()).collect();
        assert_eq!(names, ["x.rar", "x.r00", "x.r01"]);
    }

    #[test]
    fn direct_and_split_video() {
        let files = vec![file("Show.S01E01.mkv", 300), file("Sample.mkv", 10)];
        assert!(matches!(locate(&files), Some(Layout::Direct(f)) if f.name == "Show.S01E01.mkv"));
        let split = vec![file("a.mkv.002", 10), file("a.mkv.001", 10)];
        let Some(Layout::Split(pieces)) = locate(&split) else {
            panic!("not split");
        };
        assert_eq!(pieces[0].name, "a.mkv.001");
    }

    #[test]
    fn obfuscated_names_are_unknown_largest_first() {
        let files = vec![
            file("a8f9e2c1", 10),
            file("77bd03aa", 200),
            file("77bd03aa.par2", 3),
        ];
        let Some(Layout::Unknown(files)) = locate(&files) else {
            panic!("not unknown");
        };
        assert_eq!(files[0].name, "77bd03aa");
    }
}
