//! Movie releases: 4K/UHD first, then the standard patterns, with the generic year/resolution/source pattern last.

use std::sync::LazyLock;

use nntmux_categorize::rx;

use super::{Rule, first_match};
use crate::result::NameFixResult;

pub(super) fn check(textstring: &str) -> Option<NameFixResult> {
    first_match(rules(), "Movie", textstring)
}

pub(super) fn rules() -> &'static [Rule] {
    static RULES: LazyLock<Vec<Rule>> = LazyLock::new(|| {
        vec![
            // UHD_HDR
            Rule::bytes(
                "4K/UHD with HDR",
                0.95,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K|UHD)[._ -](HDR10\+?|DV|Dolby[._ -]?Vision|HLG)?[._ -]?(REMUX|BluRay|WEB-?DL|WEB-?RIP|UHD[._ -]?BluRay)[._ -](HEVC|x265|H\.?265|AV1)[._ -]?(Atmos|DTS[._ -]?(HD)?|TrueHD)?[\-\w.',;& ]+\w"),
            ),
            // UHD_REMUX
            Rule::bytes(
                "4K REMUX",
                0.95,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K)[._ -](REMUX|Complete[._ -]?UHD)[._ -](HEVC|x265|H\.?265)[\-\w.',;& ]+\w"),
            ),
            // STREAMING_4K
            Rule::bytes(
                "4K Streaming service",
                0.95,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](HDR10\+?|DV)?[._ -]?(HEVC|x265|H\.?265)[\-\w.',;& ]+\w"),
            ),
            // STREAMING_HD
            Rule::bytes(
                "HD Streaming service",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](1080p|720p)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](x264|x265|H\.?264|H\.?265|HEVC)[\-\w.',;& ]+\w"),
            ),
            // YEAR_RES_VCODEC
            Rule::bytes(
                "Title.year.Text.res.vcod.group",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w"),
            ),
            // YEAR_SOURCE_VCODEC_RES
            Rule::bytes(
                "Title.year.source.vcodec.res.group",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[._ -](480|720|1080|2160)[ip]?[\-\w.',;& ]+\w"),
            ),
            // YEAR_SOURCE_VCODEC_ACODEC
            Rule::bytes(
                "Title.year.source.vcodec.acodec.group",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[\-\w.',;& ]+\w"),
            ),
            // RES_SOURCE_ACODEC_VCODEC
            Rule::bytes(
                "Title.year.resolution.source.acodec.vcodec.group",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w"),
            ),
            // RES_ACODEC_SOURCE_YEAR
            Rule::bytes(
                "Title.resolution.acodec.eptitle.source.year.group",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[\-\w.',;& ]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -]((19|20)\d\d)[\-\w.',;& ]+\w"),
            ),
            // MULTI_LANGUAGE
            Rule::bytes(
                "Title.language.year.acodec.src",
                0.85,
                rx!(i r"\w[\-\w.',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish|MULTi)[._ -]((19|20)\d\d)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[\-\w.',;& ]+\w"),
            ),
            // GENERIC
            Rule::bytes(
                "Title.year.res.source.group",
                0.70,
                rx!(i r"\w[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+(480|720|1080|2160)[ip]?[\-\w.',;& ]+(BluRay|BDRip|DVDRip|HDTV|WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w"),
            ),
        ]
    });
    &RULES
}
