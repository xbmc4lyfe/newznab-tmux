#!/usr/bin/env bash
# Builds sample.mkv: 3 s of 10-bit HEVC with HDR10 colour metadata, an English E-AC-3 5.1 track,
# an AC-3 commentary track, French forced and English SDH subtitles, and named chapters.
# Needs ffmpeg with libx265 (tested with 6.1). Run from this directory.
set -euo pipefail
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cat > "$work/subs.srt" <<'SRT'
1
00:00:00,500 --> 00:00:02,000
Hello.
SRT
cat > "$work/chapters.txt" <<'META'
;FFMETADATA1
title=Sample Movie 2024 2160p UHD BluRay REMUX
[CHAPTER]
TIMEBASE=1/1000
START=0
END=1000
title=Opening Credits
[CHAPTER]
TIMEBASE=1/1000
START=1000
END=2000
title=Chapter 02
[CHAPTER]
TIMEBASE=1/1000
START=2000
END=3000
title=The Heist
META
ffmpeg -hide_banner -loglevel error -y \
  -f lavfi -i "color=c=gray:size=3840x1608:rate=24000/1001:duration=3" \
  -f lavfi -i "sine=frequency=440:duration=3:sample_rate=48000" \
  -i "$work/subs.srt" -i "$work/subs.srt" -i "$work/chapters.txt" \
  -map 0:v -map 1:a -map 1:a -map 2:s -map 3:s -map_metadata 4 -map_chapters 4 \
  -c:v libx265 -preset ultrafast -pix_fmt yuv420p10le \
  -x265-params "log-level=error:hdr10=1:repeat-headers=1:colorprim=bt2020:transfer=smpte2084:colormatrix=bt2020nc:master-display=G(13250,34500)B(7500,3000)R(34000,16000)WP(15635,16450)L(10000000,50):max-cll=1000,400" \
  -color_primaries bt2020 -color_trc smpte2084 -colorspace bt2020nc \
  -c:a:0 eac3 -ac:a:0 6 -b:a:0 192k -c:a:1 ac3 -ac:a:1 2 -b:a:1 96k \
  -c:s srt \
  -metadata:s:a:0 language=eng -metadata:s:a:1 language=eng \
  -metadata:s:a:1 title="Commentary with director Jane Doe and cinematographer John Roe" \
  -disposition:a:0 default -disposition:a:1 comment \
  -metadata:s:s:0 language=fre -metadata:s:s:0 title=Forced -disposition:s:0 forced \
  -metadata:s:s:1 language=eng -metadata:s:s:1 title=SDH -disposition:s:1 hearing_impaired \
  sample.mkv
