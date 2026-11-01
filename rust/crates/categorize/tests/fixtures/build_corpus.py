#!/usr/bin/env python3
"""Builds corpus.txt: release-like strings from the PHP test suite plus edge cases.
Usage: build_corpus.py <php-final checkout> > corpus.txt"""
import glob, re, sys

ref = sys.argv[1]
names = set()
for path in glob.glob(f"{ref}/tests/**/*.php", recursive=True):
    if not re.search(r"Categor|Hashed|Xxx|Predb|Name|Fix|Release", path):
        continue
    for text in re.findall(r"'([^'\n]{8,200})'", open(path, errors="replace").read()):
        if re.search(r"[._ -]", text) and not re.search(r"[\\$<>=;(){}]|^App|function", text):
            names.add(text)

names |= {
    "0123456789abcdef0123456789abcdef",
    "0123456789abcdef0123456789abcdef01234567.rar",
    "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
    "550e8400-e29b-41d4-a716-446655440000",
    "aB3dE6gH9jK2mN5pQ8sT1vW4yZ7bC0eF",
    "QWERTYUIOPASDFGHJKLZ",
    "xKq7Zp2Lm9Vb4Nc8Rt3Yw6",
    "bcdfghjklmnpqrstvwxz1234",
    "ab12345678",
    '[01/20] - "aB3dE6gH9jK2mN5p.part01.rar" yEnc',
    'N:/NZB [1/5] - "some_lower_case_title.part1.rar"',
    "__NZBSPLIT__deadbeef01__NZBSPLIT__Show_Name+S01E02_1080p.part01.rar",
    '__NZBSPLIT__deadbeef01__NZBSPLIT__Some.Movie.2021.1080p.BluRay.x264-GRP.rar" yEnc (1/20)',
    "OF.Creator.Name.Video.1080p.mp4",
    "OF.Creator_Photosets.1080p",
    "Only Fans Creator 2023 clip",
    "posted by someone - the title of the thing.rar",
    "[Group] Show Title - 08 [1080p][ABCD1234].mkv",
    "don't stop me now.flac",
    "éléphant.rose.2019.flac",
    "日本語のタイトル 2020 1080p",
    "O'Reilly Learning Rust 2nd Edition.pdf",
    "O’Reilly Learning Rust 2nd Edition.pdf",
    "Wicked.2024.1080p.WEB-DL.H264-GRP",
    "Wicked.24.01.15.Performer.Name.XXX.1080p.MP4-KTR",
    "Trailing.Newline.Name.mkv\n",
}
for name in sorted(names):
    print(name.replace("\\", "\\\\").replace("\n", "\\n"))
