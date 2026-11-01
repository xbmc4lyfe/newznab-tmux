//! Rules with lookaround must still find their match in NFO-sized text.

use nntmux_namefix::checkers::check;
use nntmux_namefix::extractors::file::extract_from_file;
use nntmux_namefix::extractors::nfo::{check_for_movie, identity_text};

fn prose(bytes: usize) -> String {
    let line = "the quick brown fox jumps over the lazy dog again and again\n";
    line.repeat(bytes / line.len())
}

#[test]
fn the_first_tv_rule_still_wins_in_a_large_nfo() {
    for size in [9_000, 16_000, 25_000, 64_000] {
        let nfo = format!(
            "{}Release Name : Some.Show.S01E02.720p.HDTV.x264-GRP\n{}",
            prose(size / 2),
            prose(size / 2)
        );
        let found = check(&identity_text(&nfo)).expect("a match");
        assert_eq!(
            found.new_name, "Some.Show.S01E02.720p.HDTV.x264-GRP",
            "{size}"
        );
        assert_eq!(
            found.method,
            "Title.SxxExx.Text.resolution.source.vcodec.group"
        );
    }
}

#[test]
fn the_earliest_line_gives_the_match() {
    let text = "One.Show.S01E01.720p.HDTV.x264-AAA\nTwo.Show.S01E02.720p.HDTV.x264-BBB\n";
    assert_eq!(
        check(text).expect("a match").new_name,
        "One.Show.S01E01.720p.HDTV.x264-AAA"
    );
}

#[test]
fn movie_rules_survive_a_large_nfo() {
    let art = "   *   **   ***   the   quick   brown   fox   \n".repeat(1_400);
    let nfo = format!("{art}Title : Some.Movie.2010.720p.BluRay.x264-GRP  \n{art}");
    let found = check_for_movie(&nfo).expect("a match");
    assert_eq!(found.new_name, "Some.Movie.2010.720p.BluRay.x264-GRP");
}

#[test]
fn only_one_final_newline_is_forgiven() {
    let one = extract_from_file("a/Show.Name_1990_S16E27_AMZN.WEB-DL.mkv\n");
    let two = extract_from_file("a/Show.Name_1990_S16E27_AMZN.WEB-DL.mkv\n\n");
    assert!(one.is_some());
    assert!(two.is_none());
}
