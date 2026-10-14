//! Persistent byte protocol for PHP callers without FFI: A + u32be + ID, or V.
use nzbyenc::article_fingerprint::Accumulator;
use std::io::{self, BufReader, BufWriter, Read, Write};
use std::process::ExitCode;

const MAX_MESSAGE_ID_BYTES: usize = 16 * 1024 * 1024;

fn run(mut reader: impl Read, mut writer: impl Write) -> io::Result<()> {
    let mut accumulator = Accumulator::new();
    loop {
        let mut command = [0];
        match reader.read_exact(&mut command) {
            Ok(()) => {}
            Err(error) if error.kind() == io::ErrorKind::UnexpectedEof => return Ok(()),
            Err(error) => return Err(error),
        }
        match command[0] {
            b'A' => {
                let mut length = [0; 4];
                reader.read_exact(&mut length)?;
                let length = u32::from_be_bytes(length) as usize;
                if length > MAX_MESSAGE_ID_BYTES {
                    return Err(io::Error::new(
                        io::ErrorKind::InvalidData,
                        "Article identity input exceeds limit",
                    ));
                }
                let mut message_id = vec![0; length];
                reader.read_exact(&mut message_id)?;
                accumulator.add(&message_id);
            }
            b'V' => {
                if let Some(value) = accumulator.value() {
                    let mut response = [1; 41];
                    response[1..].copy_from_slice(&value);
                    writer.write_all(&response)?;
                } else {
                    writer.write_all(&[0])?;
                }
                writer.flush()?;
            }
            _ => {
                return Err(io::Error::new(
                    io::ErrorKind::InvalidData,
                    "Invalid article identity command",
                ));
            }
        }
    }
}

fn main() -> ExitCode {
    let stdin = io::stdin();
    let stdout = io::stdout();
    match run(BufReader::new(stdin.lock()), BufWriter::new(stdout.lock())) {
        Ok(()) => ExitCode::SUCCESS,
        Err(error) => {
            eprintln!("Article identity worker failed: {error}");
            ExitCode::FAILURE
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn add(input: &mut Vec<u8>, id: &[u8]) {
        input.push(b'A');
        input.extend_from_slice(&(id.len() as u32).to_be_bytes());
        input.extend_from_slice(id);
    }

    fn response(hex: &str) -> Vec<u8> {
        let mut bytes = vec![1];
        bytes.extend((0..40).map(|i| u8::from_str_radix(&hex[i * 2..i * 2 + 2], 16).unwrap()));
        bytes
    }

    #[test]
    fn empty_and_clean_eof_return_no_identity() {
        let mut output = Vec::new();
        run(b"VV".as_slice(), &mut output).unwrap();
        assert_eq!(output, [0, 0]);
        run(b"".as_slice(), &mut output).unwrap();
        assert_eq!(output, [0, 0]);
    }

    #[test]
    fn values_preserve_literal_preimage_and_incremental_deduplication() {
        let mut input = Vec::new();
        add(&mut input, b" \" <a@host> \" ");
        input.push(b'V');
        add(&mut input, b"a@host");
        input.push(b'V');
        add(&mut input, b"b@host");
        input.extend_from_slice(b"VV");
        let mut output = Vec::new();
        run(input.as_slice(), &mut output).unwrap();
        let one = response(
            "0000000000000001de4eace36038ab6329a6298eac39c89a243013a446923d3b70358c82f712f388",
        );
        let two = response(
            "00000000000000027cb8414b5de1fc619a1120f24493e67ebab04cf203f8a6f22212cd35c0034e47",
        );
        assert_eq!(output, [one.clone(), one, two.clone(), two].concat());
    }

    #[test]
    fn invalid_ids_permanently_remove_identity() {
        let mut input = Vec::new();
        add(&mut input, b"a@host");
        add(&mut input, b"bad id");
        input.push(b'V');
        add(&mut input, b"b@host");
        input.push(b'V');
        let mut output = Vec::new();
        run(input.as_slice(), &mut output).unwrap();
        assert_eq!(output, [0, 0]);
    }

    #[test]
    fn invalid_or_truncated_commands_fail_without_a_response() {
        for input in [b"X".as_slice(), b"A", b"A\0\0\0", b"A\0\0\0\x04ab"] {
            let mut output = Vec::new();
            let error = run(input, &mut output).unwrap_err();
            assert!(matches!(
                error.kind(),
                io::ErrorKind::InvalidData | io::ErrorKind::UnexpectedEof
            ));
            assert!(output.is_empty());
        }
    }

    #[test]
    fn oversized_length_fails_before_reading_or_allocating_payload() {
        let mut output = Vec::new();
        let error = run(b"A\x01\0\0\x01".as_slice(), &mut output).unwrap_err();
        assert_eq!(error.kind(), io::ErrorKind::InvalidData);
        assert!(output.is_empty());
    }

    struct BrokenReader;

    impl Read for BrokenReader {
        fn read(&mut self, _buffer: &mut [u8]) -> io::Result<usize> {
            Err(io::Error::other("read failed"))
        }
    }

    #[derive(Default)]
    struct Writer {
        bytes: Vec<u8>,
        flushes: usize,
        fail_write: bool,
        fail_flush: bool,
    }

    impl Write for Writer {
        fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
            if self.fail_write {
                return Err(io::Error::other("write failed"));
            }
            self.bytes.extend_from_slice(bytes);
            Ok(bytes.len())
        }

        fn flush(&mut self) -> io::Result<()> {
            if self.fail_flush {
                return Err(io::Error::other("flush failed"));
            }
            self.flushes += 1;
            Ok(())
        }
    }

    #[test]
    fn each_value_flushes_its_response() {
        let mut writer = Writer::default();
        run(b"VV".as_slice(), &mut writer).unwrap();
        assert_eq!(writer.bytes, [0, 0]);
        assert_eq!(writer.flushes, 2);
    }

    #[test]
    fn read_write_and_flush_failures_propagate() {
        assert_eq!(
            run(BrokenReader, Vec::new()).unwrap_err().to_string(),
            "read failed"
        );
        for (writer, expected) in [
            (
                Writer {
                    fail_write: true,
                    ..Writer::default()
                },
                "write failed",
            ),
            (
                Writer {
                    fail_flush: true,
                    ..Writer::default()
                },
                "flush failed",
            ),
        ] {
            assert_eq!(
                run(b"V".as_slice(), writer).unwrap_err().to_string(),
                expected
            );
        }
    }
}
