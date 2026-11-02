#!/usr/bin/env python3
"""Writes a three-volume stored RAR 4 set holding a sample, a split feature, a compressed video
and an NFO, for the media-source stage's golden tests. Run from this directory."""
import struct, zlib, pathlib

out = pathlib.Path('media/stored')
out.mkdir(parents=True, exist_ok=True)
clip = pathlib.Path('media/clip.mkv').read_bytes()

def block(kind, flags, body, add=b''):
    size = 7 + len(body)
    head = struct.pack('<BHH', kind, flags, size) + body
    return struct.pack('<H', zlib.crc32(head) & 0xFFFF) + head + add

def main_header(flags):
    return block(0x73, flags, b'\0' * 6)

def file_block(name, data, unp_size, flags=0, method=0x30):
    name = name.encode()
    body = struct.pack('<IIBIIBBHI', len(data), unp_size, 3, zlib.crc32(data), 0x5A2B0000, 29, method, len(name), 0x20) + name
    return block(0x74, 0x8000 | flags, body, data)

def end_block(flags):
    return block(0x7B, flags, b'')

MARKER = b'Rar!\x1a\x07\x00'
sample = clip
# The feature: the clip five times over, cut into three volumes.
feature = clip * 5
third = len(feature) // 3
parts = [feature[:third], feature[third:2 * third], feature[2 * third:]]
volumes = [
    MARKER + main_header(0x0101)
    + file_block('Movie.2023.1080p.BluRay.x264-GRP.nfo', b'nfo text\r\n', 10)
    + file_block('Sample/movie-sample.mkv', sample, len(sample))
    + file_block('Extras/packed.mkv', b'x' * 100, 400, method=0x33)
    + file_block('Movie.2023.1080p.BluRay.x264-GRP.mkv', parts[0], len(feature), flags=0x02)
    + end_block(0x0001),
    MARKER + main_header(0x0001)
    + file_block('Movie.2023.1080p.BluRay.x264-GRP.mkv', parts[1], len(feature), flags=0x03)
    + end_block(0x0001),
    MARKER + main_header(0x0001)
    + file_block('Movie.2023.1080p.BluRay.x264-GRP.mkv', parts[2], len(feature), flags=0x01)
    + end_block(0x0000),
]
for number, volume in enumerate(volumes, 1):
    (out / f'movie.part{number}.rar').write_bytes(volume)

