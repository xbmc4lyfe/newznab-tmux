//! The rows `MediaInfo::addData` and `ReleaseExtraService::addFromXml` insert from a parsed
//! container, computed without the database. Values are what PDO would have bound. An array
//! can't be bound: PHP only met that when the row wasn't already stored, and then the insert
//! threw, ending the pass, so the writer checks for [`Value::Array`] after its duplicate check.

use super::parse::{Attribute, Container, Node, Track};

/// A bound value, or an array that can't be bound.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Value {
    Str(String),
    Int(i64),
    Null,
    Array,
}

impl Value {
    pub fn text(text: impl Into<String>) -> Self {
        Self::Str(text.into())
    }
}

impl Value {
    fn empty() -> Self {
        Self::text("")
    }

    fn of_attribute(attribute: &Attribute) -> Self {
        attribute.bound().map_or(Self::Array, Self::Str)
    }

    fn of_node(node: Option<Node>) -> Self {
        match node {
            None => Self::Null,
            Some(Node::Str(text)) => Self::Str(text),
            Some(Node::Arr(_)) => Self::Array,
        }
    }

    /// PHP's `.` with this as the left side.
    fn concat(&self, right: &str) -> Self {
        let left = match self {
            Self::Str(text) => text.clone(),
            Self::Int(value) => value.to_string(),
            Self::Null => String::new(),
            Self::Array => "Array".to_owned(),
        };
        Self::Str(left + right)
    }
}

/// `media_infos`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct MediaInfoRow {
    pub movie_name: Value,
    pub file_name: Value,
    pub unique_id: Value,
}

/// `video_data`, in column order after `releases_id`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct VideoRow {
    pub containerformat: Value,
    pub overallbitrate: Value,
    pub videoduration: Value,
    pub videoformat: Value,
    pub videocodec: Value,
    pub videowidth: Value,
    pub videoheight: Value,
    pub videoaspect: Value,
    pub videoframerate: Value,
    pub videolibrary: Value,
}

/// `audio_data`, in column order after `releases_id`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct AudioRow {
    pub audioid: Value,
    pub audioformat: Value,
    pub audiomode: Value,
    pub audiobitratemode: Value,
    pub audiobitrate: Value,
    pub audiochannels: Value,
    pub audiosamplerate: Value,
    pub audiolibrary: Value,
    pub audiolanguage: Value,
    pub audiotitle: Value,
}

/// `release_subtitles`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct SubtitleRow {
    pub subsid: Value,
    pub subslanguage: Value,
}

/// One insert `addFromXml` attempted, in order.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Insert {
    Video(VideoRow),
    Audio(AudioRow),
    Subtitle(SubtitleRow),
}

impl Insert {
    /// Whether some value can't be bound.
    pub fn has_array(&self) -> bool {
        let values: Vec<&Value> = match self {
            Self::Video(row) => vec![
                &row.containerformat,
                &row.overallbitrate,
                &row.videoduration,
                &row.videoformat,
                &row.videocodec,
                &row.videowidth,
                &row.videoheight,
                &row.videoaspect,
                &row.videoframerate,
                &row.videolibrary,
            ],
            Self::Audio(row) => vec![
                &row.audioid,
                &row.audioformat,
                &row.audiomode,
                &row.audiobitratemode,
                &row.audiobitrate,
                &row.audiochannels,
                &row.audiosamplerate,
                &row.audiolibrary,
                &row.audiolanguage,
                &row.audiotitle,
            ],
            Self::Subtitle(row) => vec![&row.subsid, &row.subslanguage],
        };
        values.contains(&&Value::Array)
    }
}

impl MediaInfoRow {
    pub fn has_array(&self) -> bool {
        [&self.movie_name, &self.file_name, &self.unique_id].contains(&&Value::Array)
    }
}

/// `MediaInfo::addData`: no row without a general track.
pub fn media_info_row(container: &Container) -> Option<MediaInfoRow> {
    let general = container.general.as_ref()?;
    let raw = |name: &str| general.get(name).map_or(Value::Null, Value::of_attribute);
    // `unique_id` is a mode object, never equal to '0', so PHP stored its short name.
    Some(MediaInfoRow {
        movie_name: raw("movie_name"),
        file_name: raw("file_name"),
        unique_id: raw("unique_id"),
    })
}

/// PHP's `realDuration`.
fn real_duration(milliseconds: i64) -> String {
    let time = (milliseconds as f64 / 1000.0).round();
    let hours = (time / 3600.0).floor() as i64;
    let minutes = ((time / 60.0) as i64 % 60) as f64;
    let seconds = time as i64 % 60;
    format!("{hours:02}h:{:02}m:{seconds:02}s", minutes.floor() as i64)
}

fn full_name(track: &Track, name: &str) -> Option<String> {
    track
        .get(name)
        .and_then(Attribute::full_name)
        .map(str::to_owned)
}

fn text_value(track: &Track, name: &str) -> Option<String> {
    track
        .get(name)
        .and_then(Attribute::text_value)
        .map(str::to_owned)
}

fn raw(track: &Track, name: &str) -> Option<Node> {
    match track.get(name) {
        Some(Attribute::Raw(node)) => Some(node.clone()),
        _ => None,
    }
}

/// `is_array($x) ? implode($x) : $x`.
fn imploded(value: &RawOrText) -> Value {
    match value {
        RawOrText::Text(text) | RawOrText::Node(Node::Str(text)) => Value::Str(text.clone()),
        RawOrText::Int(value) => Value::Int(*value),
        RawOrText::Node(array @ Node::Arr(_)) => Value::Str(array.implode("")),
    }
}

/// A loop variable of `addFromXml`: a string it set, a number, or an attribute's raw value.
#[derive(Clone, Debug)]
enum RawOrText {
    Text(String),
    Int(i64),
    Node(Node),
}

/// `ReleaseExtraService::addFromXml`. Its loop variables carry over from one track to the next,
/// as PHP's did.
pub fn extra(container: &Container) -> Vec<Insert> {
    let mut inserts = Vec::new();
    let Some(general) = &container.general else {
        return inserts;
    };
    let container_format = full_name(general, "format").unwrap_or_default();
    let mut overall_bit_rate = Value::empty();
    let mut duration: Option<i64> = None;
    let mut format = String::new();
    let mut codec = Value::empty();
    let mut width = Value::empty();
    let mut height = Value::empty();
    let mut aspect = String::new();
    let mut frame_rate = String::new();
    let mut library = Value::empty();
    let mut bit_rate = String::new();
    for video in &container.videos {
        if let Some(Attribute::Duration(milliseconds)) = video.get("duration") {
            duration = Some(*milliseconds);
        }
        if let Some(attribute) = video.get("bit_rate") {
            overall_bit_rate = Value::of_attribute(attribute);
        }
        if let Some(name) = full_name(video, "format") {
            format = name;
        }
        if let Some(node) = raw(video, "codec_id") {
            codec = Value::of_node(Some(node));
        }
        if let Some(value) = video.get("width").and_then(Attribute::rounded) {
            width = Value::Int(value);
        }
        if let Some(value) = video.get("height").and_then(Attribute::rounded) {
            height = Value::Int(value);
        }
        if let Some(text) = text_value(video, "display_aspect_ratio") {
            aspect = text;
        }
        if let Some(text) = text_value(video, "frame_rate") {
            frame_rate = text;
        }
        if let Some(node) = raw(video, "encoded_library") {
            library = Value::of_node(Some(node));
        }
        if let Some(node) = raw(video, "encoded_library_version") {
            library = library.concat(&node.concat_text());
        }
        if let Some(name) = full_name(video, "writing_library") {
            library = Value::Str(name);
        }
        if let Some(text) = text_value(video, "nominal_bit_rate") {
            bit_rate = text;
        }
        if !(bit_rate.is_empty() || bit_rate == "0") {
            overall_bit_rate = Value::Str(bit_rate.clone());
        }
        inserts.push(Insert::Video(VideoRow {
            containerformat: Value::text(&container_format),
            overallbitrate: overall_bit_rate.clone(),
            videoduration: Value::Str(duration.map(real_duration).unwrap_or_default()),
            videoformat: Value::text(&format),
            videocodec: codec.clone(),
            videowidth: width.clone(),
            videoheight: height.clone(),
            videoaspect: Value::text(&aspect),
            videoframerate: Value::text(&frame_rate),
            videolibrary: library.clone(),
        }));
    }

    let mut audio_id = RawOrText::Int(1);
    let mut audio_format = RawOrText::Text(String::new());
    let mut audio_mode = RawOrText::Text(String::new());
    let mut bit_rate_mode = RawOrText::Text(String::new());
    let mut audio_bit_rate = RawOrText::Text(String::new());
    let mut channels = RawOrText::Text(String::new());
    let mut sample_rate = RawOrText::Text(String::new());
    let mut audio_library = RawOrText::Text(String::new());
    let mut language: Option<Node> = None;
    let mut title = RawOrText::Text(String::new());
    for audio in &container.audios {
        if let Some(name) = full_name(audio, "id") {
            audio_id = RawOrText::Text(name);
        }
        if let Some(name) = full_name(audio, "format") {
            audio_format = RawOrText::Text(name);
        }
        if let Some(name) = full_name(audio, "format_settings_sbr") {
            audio_mode = RawOrText::Text(name);
        }
        if let Some(name) = full_name(audio, "bit_rate_mode") {
            bit_rate_mode = RawOrText::Text(name);
        }
        if let Some(text) = text_value(audio, "bit_rate") {
            audio_bit_rate = RawOrText::Text(text);
        }
        if let Some(value) = audio.get("channel_s").and_then(Attribute::rounded) {
            channels = RawOrText::Int(value);
        }
        if let Some(text) = text_value(audio, "sampling_rate") {
            sample_rate = RawOrText::Text(text);
        }
        if let Some(node) = raw(audio, "encoded_library") {
            audio_library = RawOrText::Node(node);
        }
        if let Some(node) = raw(audio, "language") {
            language = Some(node);
        }
        if let Some(node) = raw(audio, "title") {
            title = RawOrText::Node(node);
        }
        let language_value = match &language {
            Some(node) if !node.is_empty() => Value::of_node(node.index(1)),
            _ => Value::empty(),
        };
        inserts.push(Insert::Audio(AudioRow {
            audioid: imploded(&audio_id),
            audioformat: imploded(&audio_format),
            audiomode: imploded(&audio_mode),
            audiobitratemode: imploded(&bit_rate_mode),
            audiobitrate: imploded(&audio_bit_rate),
            audiochannels: imploded(&channels),
            audiosamplerate: imploded(&sample_rate),
            audiolibrary: imploded(&audio_library),
            audiolanguage: language_value,
            audiotitle: imploded(&title),
        }));
    }

    for subtitle in &container.subtitles {
        let id = match full_name(subtitle, "id") {
            Some(name) => Value::Str(name),
            None => Value::Int(1),
        };
        let language = raw(subtitle, "language").unwrap_or(Node::Str("Unknown".to_owned()));
        let mut chosen = Value::empty();
        if !language.is_empty() {
            let second = language.index(1);
            if second.as_ref().is_some_and(|node| !node.is_empty()) {
                chosen = Value::of_node(second);
            } else {
                let first = language.index(0);
                if first.as_ref().is_some_and(|node| !node.is_empty()) {
                    chosen = Value::of_node(first);
                }
            }
        }
        inserts.push(Insert::Subtitle(SubtitleRow {
            subsid: id,
            subslanguage: chosen,
        }));
    }
    inserts
}

#[cfg(test)]
mod tests {
    use super::real_duration;

    #[test]
    fn durations_format_as_php() {
        assert_eq!(real_duration(7_845_087), "02h:10m:45s");
        assert_eq!(real_duration(2_712_300), "00h:45m:12s");
        assert_eq!(real_duration(6), "00h:00m:00s");
        assert_eq!(real_duration(500), "00h:00m:01s");
    }
}
