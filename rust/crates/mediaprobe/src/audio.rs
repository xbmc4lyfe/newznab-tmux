//! Object audio from the first frames: Atmos in TrueHD and E-AC-3, DTS:X in DTS-HD MA.
//!
//! The rules follow FFmpeg's parsers (`mlpdec.c`, `ac3_parser.c`, `dca_xll.c`), which set the
//! same profiles ffprobe and players report.

use serde::Serialize;

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, Serialize)]
pub struct AudioFindings {
    pub atmos: bool,
    pub dtsx: bool,
    pub dtsx_imax: bool,
    /// A DTS lossless (XLL, "DTS-HD MA") extension was seen.
    pub dts_hd_ma: bool,
    pub frames: usize,
}

impl AudioFindings {
    pub fn add_frame(&mut self, codec_id: &str, frame: &[u8]) {
        self.frames += 1;
        match codec_id {
            "A_TRUEHD" => self.atmos |= truehd_atmos(frame),
            "A_EAC3" => self.atmos |= eac3_atmos(frame),
            id if id.starts_with("A_DTS") => self.read_dts(frame),
            _ => {}
        }
    }

    fn read_dts(&mut self, frame: &[u8]) {
        const XLL: [u8; 4] = 0x41A2_9547u32.to_be_bytes();
        const X: u32 = 0x0200_0850;
        const X_IMAX: u32 = 0xF140_00D0;
        let Some(xll) = frame.windows(4).position(|w| w == XLL) else {
            return;
        };
        self.dts_hd_ma = true;
        // The X syncword sits dword-aligned after the XLL band data, and FFmpeg compares the IMAX
        // word without its last bit.
        for word in frame[xll + 4..].as_chunks::<4>().0 {
            let word = u32::from_be_bytes(*word);
            if word == X {
                self.dtsx = true;
            } else if word >> 1 == X_IMAX >> 1 {
                self.dtsx = true;
                self.dtsx_imax = true;
            }
        }
    }
}

/// A TrueHD major sync with four substreams and the top bit of `substream_info` set carries a
/// 16-channel presentation, which is Atmos.
pub fn truehd_atmos(frame: &[u8]) -> bool {
    const SYNC: [u8; 4] = [0xF8, 0x72, 0x6F, 0xBA];
    let mut start = 0;
    while let Some(found) = frame[start..].windows(4).position(|w| w == SYNC) {
        let at = start + found;
        if let (Some(&substreams), Some(&info)) = (frame.get(at + 16), frame.get(at + 17))
            && substreams >> 4 == 4
            && info >> 7 == 1
        {
            return true;
        }
        start = at + 1;
    }
    false
}

/// Any E-AC-3 independent syncframe of substream 0 whose additional bitstream info sets
/// `flag_ec3_extension_type_a` (joint object coding).
pub fn eac3_atmos(block: &[u8]) -> bool {
    let mut pos = 0;
    while pos + 5 < block.len() {
        if block[pos] != 0x0B || block[pos + 1] != 0x77 {
            pos += 1;
            continue;
        }
        let frame = &block[pos..];
        match eac3_header(frame) {
            Some((size, atmos)) => {
                if atmos {
                    return true;
                }
                pos += size.max(2);
            }
            None => pos += 2,
        }
    }
    false
}

/// Returns the frame size and whether it signals Atmos, or `None` when this isn't an E-AC-3
/// header (AC-3 frames return `None` too).
fn eac3_header(frame: &[u8]) -> Option<(usize, bool)> {
    let mut bits = Bits::new(frame);
    if bits.read(16)? != 0x0B77 {
        return None;
    }
    let bsid = (u32::from(*frame.get(5)?) >> 3) & 0x1F;
    if !(11..=16).contains(&bsid) {
        return None;
    }
    let frame_type = bits.read(2)?;
    if frame_type == 3 {
        return None;
    }
    let substream = bits.read(3)?;
    let size = ((bits.read(11)? + 1) << 1) as usize;
    let sr_code = bits.read(2)?;
    let num_blocks = if sr_code == 3 {
        bits.read(2)?;
        6
    } else {
        [1, 2, 3, 6][bits.read(2)? as usize]
    };
    let channel_mode = bits.read(3)?;
    let lfe_on = bits.read(1)? == 1;
    if substream != 0 {
        return Some((size, false));
    }
    bits.skip(5)?; // bsid
    let programs = if channel_mode == 0 { 2 } else { 1 };
    for _ in 0..programs {
        bits.skip(5)?; // dialnorm
        if bits.flag()? {
            bits.skip(8)?; // compr
        }
    }
    if frame_type == 1 && bits.flag()? {
        bits.skip(16)?; // channel map
    }
    // Mixing metadata.
    if bits.flag()? {
        if channel_mode > 2 {
            bits.skip(2)?;
            if channel_mode & 1 != 0 {
                bits.skip(6)?;
            }
            if channel_mode & 4 != 0 {
                bits.skip(6)?;
            }
        }
        if lfe_on && bits.flag()? {
            bits.skip(5)?;
        }
        if frame_type == 0 {
            for _ in 0..programs {
                if bits.flag()? {
                    bits.skip(6)?;
                }
            }
            if bits.flag()? {
                bits.skip(6)?;
            }
            match bits.read(2)? {
                1 => bits.skip(5)?,
                2 => bits.skip(12)?,
                3 => {
                    let len = (bits.read(5)? + 2) << 3;
                    bits.skip(len as usize)?;
                }
                _ => {}
            }
            if channel_mode < 2 {
                for _ in 0..programs {
                    if bits.flag()? {
                        bits.skip(14)?;
                    }
                }
            }
            if bits.flag()? {
                for _ in 0..num_blocks {
                    if num_blocks == 1 || bits.flag()? {
                        bits.skip(5)?;
                    }
                }
            }
        }
    }
    // Informational metadata.
    if bits.flag()? {
        bits.skip(5)?;
        if channel_mode == 2 {
            bits.skip(4)?;
        }
        if channel_mode >= 6 {
            bits.skip(2)?;
        }
        for _ in 0..programs {
            if bits.flag()? {
                bits.skip(8)?;
            }
        }
        if sr_code != 3 {
            bits.skip(1)?;
        }
    }
    if frame_type == 0 && num_blocks != 6 {
        bits.skip(1)?;
    }
    if frame_type == 2 && (num_blocks == 6 || bits.flag()?) {
        bits.skip(6)?;
    }
    let mut atmos = false;
    if bits.flag()? {
        let _addbsil = bits.read(6)?;
        bits.skip(7)?;
        atmos = bits.flag()?;
    }
    Some((size, atmos))
}

struct Bits<'a> {
    data: &'a [u8],
    pos: usize,
}

impl<'a> Bits<'a> {
    fn new(data: &'a [u8]) -> Self {
        Self { data, pos: 0 }
    }

    fn read(&mut self, n: usize) -> Option<u32> {
        let mut value = 0u32;
        for _ in 0..n {
            let byte = *self.data.get(self.pos / 8)?;
            let bit = (byte >> (7 - self.pos % 8)) & 1;
            value = (value << 1) | u32::from(bit);
            self.pos += 1;
        }
        Some(value)
    }

    fn flag(&mut self) -> Option<bool> {
        self.read(1).map(|b| b == 1)
    }

    fn skip(&mut self, n: usize) -> Option<()> {
        self.pos += n;
        (self.pos <= self.data.len() * 8).then_some(())
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use super::*;

    /// Writes bits MSB first.
    #[derive(Default)]
    pub struct BitWriter {
        bytes: Vec<u8>,
        bits: usize,
    }

    impl BitWriter {
        pub fn put(&mut self, n: usize, value: u32) {
            for i in (0..n).rev() {
                if self.bits.is_multiple_of(8) {
                    self.bytes.push(0);
                }
                let bit = ((value >> i) & 1) as u8;
                let last = self.bytes.len() - 1;
                self.bytes[last] |= bit << (7 - self.bits % 8);
                self.bits += 1;
            }
        }

        pub fn finish(mut self, size: usize) -> Vec<u8> {
            self.bytes.resize(size, 0);
            self.bytes
        }
    }

    /// A minimal 5.1 independent E-AC-3 syncframe, with or without the JOC flag.
    pub fn eac3_frame(atmos: bool) -> Vec<u8> {
        let size_words = 384u32; // 768 bytes
        let mut w = BitWriter::default();
        w.put(16, 0x0B77);
        w.put(2, 0); // independent
        w.put(3, 0); // substream 0
        w.put(11, size_words - 1);
        w.put(2, 0); // 48 kHz
        w.put(2, 3); // 6 blocks
        w.put(3, 7); // 3/2
        w.put(1, 1); // lfe
        w.put(5, 16); // bsid
        w.put(5, 27); // dialnorm
        w.put(1, 0); // no compr
        w.put(1, 0); // no mixing metadata
        w.put(1, 0); // no informational metadata
        w.put(1, 1); // addbsie
        w.put(6, 1); // addbsil: two bytes
        w.put(7, 0);
        w.put(1, u32::from(atmos));
        w.put(8, 16); // complexity index
        w.finish(size_words as usize * 2)
    }

    #[test]
    fn eac3_joc_is_atmos() {
        let mut block = eac3_frame(true);
        assert!(eac3_atmos(&block));
        block = eac3_frame(false);
        assert!(!eac3_atmos(&block));
        // Two frames, the second with JOC.
        let mut two = eac3_frame(false);
        two.extend(eac3_frame(true));
        assert!(eac3_atmos(&two));
    }

    #[test]
    fn truehd_four_substreams_is_atmos() {
        let mut au = vec![0u8; 4];
        let mut sync = vec![0xF8, 0x72, 0x6F, 0xBA];
        sync.resize(28, 0);
        sync[16] = 0x40; // four substreams
        sync[17] = 0x80; // 16-channel presentation
        au.extend(&sync);
        assert!(truehd_atmos(&au));
        au[4 + 17] = 0x00;
        assert!(!truehd_atmos(&au));
    }

    #[test]
    fn dts_x_after_xll() {
        let mut frame = vec![0x7F, 0xFE, 0x80, 0x01, 0, 0, 0, 0];
        frame.extend(0x41A2_9547u32.to_be_bytes());
        frame.extend([0u8; 8]);
        let mut ma = AudioFindings::default();
        ma.add_frame("A_DTS", &frame);
        assert!(ma.dts_hd_ma && !ma.dtsx);
        frame.extend(0x0200_0850u32.to_be_bytes());
        let mut x = AudioFindings::default();
        x.add_frame("A_DTS", &frame);
        assert!(x.dtsx && !x.dtsx_imax);
    }
}
