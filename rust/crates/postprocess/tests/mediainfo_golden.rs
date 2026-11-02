//! mediainfo reports against `generate_mediainfo_golden.php`, which ran php-mediainfo's own parser
//! over the samples in `fixtures/mediainfo/` and recorded what `addData` and `addFromXml` insert.

use nntmux_postprocess::mediainfo::{parse, rows};
use rows::{Insert, Value};
use serde_json::Value as Json;

fn golden() -> Json {
    serde_json::from_str(include_str!("fixtures/mediainfo_golden.json")).expect("valid golden JSON")
}

fn check(value: &Value, expected: &Json, what: &str) {
    let matches = match (value, expected) {
        (Value::Str(text), Json::String(wanted)) => text == wanted,
        (Value::Int(number), Json::Number(wanted)) => Some(*number) == wanted.as_i64(),
        (Value::Null, Json::Null) => true,
        (Value::Array, Json::Object(marker)) => marker.contains_key("unbindable"),
        _ => false,
    };
    assert!(matches, "{what}: {value:?} != {expected}");
}

fn check_row(values: &[(&str, &Value)], expected: &Json, what: &str) {
    let expected = expected.as_object().expect("a row");
    assert_eq!(values.len(), expected.len(), "{what}: column count");
    for (column, value) in values {
        check(value, &expected[*column], &format!("{what}.{column}"));
    }
}

#[test]
fn reports_parse_and_store_as_php_did() {
    let golden = golden();
    let cases = golden.as_array().expect("cases");
    for case in cases {
        let name = case["name"].as_str().expect("name");
        let xml = std::fs::read(format!(
            "{}/tests/fixtures/mediainfo/{name}",
            env!("CARGO_MANIFEST_DIR")
        ))
        .expect("fixture");
        let parsed = parse::container(&xml);
        if !case["parse_error"].is_null() {
            assert!(parsed.is_err(), "{name}: should fail to parse");
            continue;
        }
        let container = parsed.unwrap_or_else(|error| panic!("{name}: {error}"));

        let expected = &case["media_info"]["row"];
        match rows::media_info_row(&container) {
            None => assert!(expected.is_null(), "{name}: media info row missing"),
            Some(row) => check_row(
                &[
                    ("movie_name", &row.movie_name),
                    ("file_name", &row.file_name),
                    ("unique_id", &row.unique_id),
                ],
                expected,
                &format!("{name} media_infos"),
            ),
        }

        let inserts = rows::extra(&container);
        let videos: Vec<_> = inserts
            .iter()
            .filter_map(|insert| match insert {
                Insert::Video(row) => Some(row),
                _ => None,
            })
            .collect();
        let audios: Vec<_> = inserts
            .iter()
            .filter_map(|insert| match insert {
                Insert::Audio(row) => Some(row),
                _ => None,
            })
            .collect();
        let subtitles: Vec<_> = inserts
            .iter()
            .filter_map(|insert| match insert {
                Insert::Subtitle(row) => Some(row),
                _ => None,
            })
            .collect();
        let extra = &case["extra"];
        assert_eq!(
            videos.len(),
            extra["video"].as_array().expect("video").len(),
            "{name}"
        );
        assert_eq!(
            audios.len(),
            extra["audio"].as_array().expect("audio").len(),
            "{name}"
        );
        assert_eq!(
            subtitles.len(),
            extra["subs"].as_array().expect("subs").len(),
            "{name}"
        );
        for (index, row) in videos.iter().enumerate() {
            check_row(
                &[
                    ("containerformat", &row.containerformat),
                    ("overallbitrate", &row.overallbitrate),
                    ("videoduration", &row.videoduration),
                    ("videoformat", &row.videoformat),
                    ("videocodec", &row.videocodec),
                    ("videowidth", &row.videowidth),
                    ("videoheight", &row.videoheight),
                    ("videoaspect", &row.videoaspect),
                    ("videoframerate", &row.videoframerate),
                    ("videolibrary", &row.videolibrary),
                ],
                &extra["video"][index],
                &format!("{name} video {index}"),
            );
        }
        for (index, row) in audios.iter().enumerate() {
            check_row(
                &[
                    ("audioid", &row.audioid),
                    ("audioformat", &row.audioformat),
                    ("audiomode", &row.audiomode),
                    ("audiobitratemode", &row.audiobitratemode),
                    ("audiobitrate", &row.audiobitrate),
                    ("audiochannels", &row.audiochannels),
                    ("audiosamplerate", &row.audiosamplerate),
                    ("audiolibrary", &row.audiolibrary),
                    ("audiolanguage", &row.audiolanguage),
                    ("audiotitle", &row.audiotitle),
                ],
                &extra["audio"][index],
                &format!("{name} audio {index}"),
            );
        }
        for (index, row) in subtitles.iter().enumerate() {
            check_row(
                &[("subsid", &row.subsid), ("subslanguage", &row.subslanguage)],
                &extra["subs"][index],
                &format!("{name} subtitle {index}"),
            );
        }
    }
    assert!(cases.len() >= 10);
}
