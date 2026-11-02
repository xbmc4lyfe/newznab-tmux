#!/usr/bin/env bash
# Builds the MP4 fixtures: 2 s of H.264 with English AAC stereo, French AC-3 5.1, a Spanish text
# subtitle and a title, once with moov at the front and once at the end. Needs ffmpeg. Run from
# this directory. expected.json is ffprobe's reading of the same file.
set -euo pipefail
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
printf '1\n00:00:00,500 --> 00:00:01,500\nHola.\n' > "$work/subs.srt"
make() {
    ffmpeg -y -v error -f lavfi -i testsrc=size=640x360:rate=24:duration=2 \
        -f lavfi -i sine=frequency=440:duration=2 -f lavfi -i sine=frequency=330:duration=2 \
        -i "$work/subs.srt" -map 0 -map 1 -map 2 -map 3 \
        -c:v libx264 -preset ultrafast -c:a:0 aac -ac:a:0 2 -c:a:1 ac3 -ac:a:1 6 -c:s mov_text \
        -metadata title="Sample.Film.2024.1080p.WEB-DL.H264-GRP" \
        -metadata:s:a:0 language=eng -metadata:s:a:1 language=fra -metadata:s:s:0 language=spa \
        "$@"
}
make -movflags +faststart front.mp4
make tail.mp4
ffprobe -v error -show_format -show_streams -of json front.mp4 > expected.json
