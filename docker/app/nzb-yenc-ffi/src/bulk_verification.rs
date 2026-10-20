//! Bounded NNTP response framing. PHP retains transport, authentication and selection.
use serde::{Deserialize, Serialize};
use std::io::{self, Read, Write};

const MAX_INIT: usize = 131_072;
const MAX_FEED: usize = 65_536;
const MAX_LINE: usize = 65_536;
const MAX_TOTAL: usize = 64 * 1024 * 1024;
const MAX_ARTICLE: usize = 16 * 1024 * 1024;

#[derive(Clone, Copy, Default, Deserialize)]
#[serde(rename_all = "lowercase")]
enum Mode {
    #[default]
    Body,
    Stat,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct ConnectionConfig {
    ids: Vec<String>,
    #[serde(default)]
    mode: Mode,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Config {
    connections: Vec<ConnectionConfig>,
    maximum_bytes: u32,
    buffer_bytes: u32,
}

struct Connection {
    ids: Vec<String>,
    mode: Mode,
    position: usize,
    in_body: bool,
    line: Vec<u8>,
    line_charged: usize,
    body: Vec<u8>,
    error: Option<&'static str>,
}

impl Connection {
    fn done(&self) -> bool {
        self.error.is_some() || self.position == self.ids.len()
    }

    fn fail(&mut self, error: &'static str) {
        self.error = Some(error);
        self.line = Vec::new();
        self.body = Vec::new();
        self.line_charged = 0;
    }
}

#[derive(Serialize)]
struct ArticleResult {
    /// Request position within this connection, including missing requests.
    index: usize,
    status: &'static str,
    length: usize,
}

#[derive(Serialize)]
struct Metadata {
    done: bool,
    error: Option<&'static str>,
    results: Vec<ArticleResult>,
}

struct Reply {
    metadata: Metadata,
    bodies: Vec<Vec<u8>>,
}

struct Worker {
    connections: Vec<Connection>,
    maximum_bytes: usize,
    buffer_bytes: usize,
    // Cumulative accepted body bytes, including incomplete lines on every connection.
    // Returning a body never refunds this budget; failed partial bodies also stay charged.
    used: usize,
}

fn invalid(message: &'static str) -> io::Error {
    io::Error::new(io::ErrorKind::InvalidData, message)
}

impl Worker {
    fn new(config: Config) -> io::Result<Self> {
        let maximum_bytes = config.maximum_bytes as usize;
        let buffer_bytes = config.buffer_bytes as usize;
        if maximum_bytes == 0
            || maximum_bytes > MAX_ARTICLE
            || buffer_bytes < maximum_bytes
            || buffer_bytes > MAX_TOTAL
            || config.connections.is_empty()
            || config.connections.len() > 8
        {
            return Err(invalid("Invalid verification configuration"));
        }
        let mut count = 0usize;
        let mut connections = Vec::new();
        for connection in config.connections {
            count = count
                .checked_add(connection.ids.len())
                .ok_or_else(|| invalid("Too many verification requests"))?;
            if connection.ids.len() > 16
                || count > 128
                || connection.ids.iter().any(|id| {
                    id.is_empty()
                        || id
                            .chars()
                            .any(|c| c.is_whitespace() || c.is_control() || matches!(c, '<' | '>'))
                })
            {
                return Err(invalid("Invalid verification requests"));
            }
            connections.push(Connection {
                ids: connection.ids,
                mode: connection.mode,
                position: 0,
                in_body: false,
                line: Vec::new(),
                line_charged: 0,
                body: Vec::new(),
                error: None,
            });
        }
        Ok(Self {
            connections,
            maximum_bytes,
            buffer_bytes,
            used: 0,
        })
    }

    fn feed(&mut self, index: usize, mut input: &[u8]) -> io::Result<Reply> {
        if input.len() > MAX_FEED {
            return Err(invalid("Verification feed exceeds limit"));
        }
        let connection = self
            .connections
            .get_mut(index)
            .ok_or_else(|| invalid("Invalid verification connection index"))?;
        let mut results = Vec::new();
        let mut bodies = Vec::new();
        while !input.is_empty() && connection.error.is_none() {
            if connection.done() {
                connection.fail("Unexpected data after verification pipeline");
                break;
            }
            let end = input.iter().position(|&byte| byte == b'\n');
            let take = end.map_or(input.len(), |end| end + 1);
            let Some(length) = connection.line.len().checked_add(take) else {
                connection.fail("Verification line exceeds limit");
                break;
            };
            if length > MAX_LINE || (length == MAX_LINE && end.is_none()) {
                // A line this long cannot be a dot terminator. Prefer proven article
                // overflow so retry classification never depends on socket chunking.
                if connection.in_body {
                    let mut prefix = connection.line.iter().chain(input[..take].iter());
                    let stuffed = prefix.next() == Some(&b'.') && prefix.next() == Some(&b'.');
                    let contribution = length - usize::from(stuffed);
                    if connection
                        .body
                        .len()
                        .checked_add(contribution)
                        .is_none_or(|size| size > self.maximum_bytes)
                    {
                        connection.fail("Verification article exceeds byte budget");
                        break;
                    }
                }
                connection.fail("Verification line exceeds limit");
                break;
            }
            if connection.line.try_reserve(take).is_err() {
                connection.fail("Cannot allocate verification line");
                break;
            }
            connection.line.extend_from_slice(&input[..take]);
            input = &input[take..];

            if connection.in_body {
                // A pending '.' or '.\r' might still become the terminator. Every other
                // byte is already body data; charge it before accepting another feed.
                let line = connection.line.as_slice();
                let terminator = matches!(line, b"." | b".\r" | b".\n" | b".\r\n");
                let contribution = if terminator {
                    0
                } else {
                    line.len() - usize::from(line.starts_with(b".."))
                };
                let article_size = connection.body.len().checked_add(contribution);
                let charge = contribution.checked_sub(connection.line_charged);
                let total = charge.and_then(|charge| self.used.checked_add(charge));
                if article_size.is_none_or(|size| size > self.maximum_bytes) {
                    connection.fail("Verification article exceeds byte budget");
                    break;
                }
                if total.is_none_or(|size| size > self.buffer_bytes) {
                    connection.fail("Verification pipeline exceeds byte budget");
                    break;
                }
                // Both checked values were validated above; avoid unchecked arithmetic.
                if let Some(total) = total {
                    self.used = total;
                }
                connection.line_charged = contribution;
            }
            if end.is_none() {
                break;
            }
            let mut line = std::mem::take(&mut connection.line);
            let outcome = process_line(
                connection,
                &line,
                self.maximum_bytes,
                &mut results,
                &mut bodies,
            );
            line.clear();
            connection.line = line;
            connection.line_charged = 0;
            if let Err(error) = outcome {
                connection.fail(error);
            }
        }
        Ok(Reply {
            metadata: Metadata {
                done: connection.done(),
                error: connection.error,
                results,
            },
            bodies,
        })
    }
}

fn process_line(
    connection: &mut Connection,
    line: &[u8],
    maximum_bytes: usize,
    results: &mut Vec<ArticleResult>,
    bodies: &mut Vec<Vec<u8>>,
) -> Result<(), &'static str> {
    let status;
    let mut length = 0;
    if connection.in_body {
        if !matches!(line, b".\r\n" | b".\n") {
            let payload = if line.starts_with(b"..") {
                &line[1..]
            } else {
                line
            };
            let needed = connection
                .body
                .len()
                .checked_add(payload.len())
                .ok_or("Verification body size overflow")?;
            if needed > maximum_bytes {
                return Err("Verification article exceeds byte budget");
            }
            if needed > connection.body.capacity() {
                let target = needed
                    .checked_next_power_of_two()
                    .unwrap_or(maximum_bytes)
                    .min(maximum_bytes);
                connection
                    .body
                    .try_reserve_exact(target - connection.body.len())
                    .map_err(|_| "Cannot allocate verification body")?;
            }
            connection.body.extend_from_slice(payload);
            return Ok(());
        }
        status = "body";
        length = connection.body.len();
        bodies.push(std::mem::take(&mut connection.body));
        connection.in_body = false;
    } else {
        let text = line.strip_suffix(b"\n").unwrap_or(line);
        let text = text.strip_suffix(b"\r").unwrap_or(text);
        if text.len() < 3
            || !text[..3].iter().all(u8::is_ascii_digit)
            || (text.len() > 3 && text[3] != b' ')
            || text.iter().any(|&byte| byte < 32 || byte == 127)
        {
            return Err("Malformed verification status");
        }
        match &text[..3] {
            b"430" | b"423" => status = "missing",
            code if matches!(
                (connection.mode, code),
                (Mode::Body, b"222") | (Mode::Stat, b"223")
            ) =>
            {
                let mut fields = text.split(|&byte| byte == b' ').filter(|f| !f.is_empty());
                fields.next();
                let number = fields.next().ok_or("Malformed verification status")?;
                let identity = fields.next().ok_or("Malformed verification status")?;
                let expected = connection
                    .ids
                    .get(connection.position)
                    .ok_or("Unexpected verification response")?;
                if number.is_empty() || !number.iter().all(u8::is_ascii_digit) {
                    return Err("Malformed verification status");
                }
                if identity
                    .strip_prefix(b"<")
                    .and_then(|id| id.strip_suffix(b">"))
                    != Some(expected.as_bytes())
                {
                    return Err("Verification response identity differs from request");
                }
                if matches!(connection.mode, Mode::Body) {
                    connection.in_body = true;
                    return Ok(());
                }
                status = "present";
            }
            _ => return Err("Unexpected verification status"),
        }
    }
    results.push(ArticleResult {
        index: connection.position,
        status,
        length,
    });
    connection.position = connection
        .position
        .checked_add(1)
        .ok_or("Verification request position overflow")?;
    Ok(())
}

fn read_length(reader: &mut impl Read) -> io::Result<usize> {
    let mut bytes = [0; 4];
    reader.read_exact(&mut bytes)?;
    Ok(u32::from_be_bytes(bytes) as usize)
}

/// Run one persistent worker. Only protocol bytes go to `writer`; errors contain no
/// configuration, IDs, response lines, or credentials. The caller owns transport EOF.
pub fn run(mut reader: impl Read, mut writer: impl Write) -> io::Result<()> {
    let length = read_length(&mut reader)?;
    if length == 0 || length > MAX_INIT {
        return Err(invalid("Verification initialization exceeds limit"));
    }
    let mut init = vec![0; length];
    reader.read_exact(&mut init)?;
    let config = serde_json::from_slice(&init)
        .map_err(|_| invalid("Invalid verification initialization"))?;
    let mut worker = Worker::new(config)?;
    drop(init);
    writer.write_all(&[0])?;
    writer.flush()?;
    let mut data = [0; MAX_FEED];
    loop {
        let mut index = [0];
        match reader.read_exact(&mut index) {
            Ok(()) => {}
            Err(error) if error.kind() == io::ErrorKind::UnexpectedEof => return Ok(()),
            Err(error) => return Err(error),
        }
        if usize::from(index[0]) >= worker.connections.len() {
            return Err(invalid("Invalid verification connection index"));
        }
        let length = read_length(&mut reader)?;
        if length > MAX_FEED {
            return Err(invalid("Verification feed exceeds limit"));
        }
        reader.read_exact(&mut data[..length])?;
        let reply = worker.feed(usize::from(index[0]), &data[..length])?;
        let metadata = serde_json::to_vec(&reply.metadata)
            .map_err(|_| invalid("Cannot serialize verification result"))?;
        let length = u32::try_from(metadata.len())
            .map_err(|_| invalid("Verification metadata exceeds limit"))?;
        writer.write_all(&length.to_be_bytes())?;
        writer.write_all(&metadata)?;
        for body in reply.bodies {
            writer.write_all(&body)?;
        }
        writer.flush()?;
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::{Value, json};

    fn config(ids: &[&str]) -> Value {
        json!({"connections": [{"ids": ids}], "maximum_bytes": 1024, "buffer_bytes": 8192})
    }

    fn worker(value: Value) -> Worker {
        Worker::new(serde_json::from_value(value).unwrap()).unwrap()
    }

    fn metadata(reply: &Reply) -> Value {
        serde_json::to_value(&reply.metadata).unwrap()
    }

    fn assert_error(reply: &Reply) {
        assert!(reply.metadata.done);
        assert!(reply.metadata.error.is_some());
    }

    #[test]
    fn bulk_verification_every_split_and_byte_at_a_time_preserve_bodies_and_missing_middle() {
        let input =
            b"222 1 <a@b> body\r\n..abc\r\n...\n\0\xff\n.\r\n430 missing\n222 3 <c@d>\nlast\n.\n";
        let expected = [b".abc\r\n..\n\0\xff\n".to_vec(), b"last\n".to_vec()];
        for split in 0..=input.len() {
            let mut worker = worker(config(&["a@b", "missing@b", "c@d"]));
            let first = worker.feed(0, &input[..split]).unwrap();
            let second = worker.feed(0, &input[split..]).unwrap();
            assert!(first.metadata.error.is_none(), "split {split}");
            assert!(second.metadata.error.is_none(), "split {split}");
            assert!(second.metadata.done);
            let results = first
                .metadata
                .results
                .into_iter()
                .chain(second.metadata.results)
                .map(|result| (result.index, result.status, result.length))
                .collect::<Vec<_>>();
            assert_eq!(
                results,
                [(0, "body", 12), (1, "missing", 0), (2, "body", 5)]
            );
            assert_eq!(
                first
                    .bodies
                    .into_iter()
                    .chain(second.bodies)
                    .collect::<Vec<_>>(),
                expected
            );
            assert!(worker.connections[0].body.is_empty());
        }
        let mut worker = worker(config(&["a@b", "missing@b", "c@d"]));
        let mut bodies = Vec::new();
        let mut indices = Vec::new();
        for byte in input {
            let reply = worker.feed(0, &[*byte]).unwrap();
            assert!(reply.metadata.error.is_none());
            indices.extend(reply.metadata.results.iter().map(|result| result.index));
            bodies.extend(reply.bodies);
        }
        assert_eq!(indices, [0, 1, 2]);
        assert_eq!(bodies, expected);
        assert!(worker.connections[0].done());
    }

    #[test]
    fn bulk_verification_article_overflow_wins_over_line_overflow_at_every_feed_split() {
        for (first, stuffed) in [5_000, 20_000, 32_000, 40_000]
            .into_iter()
            .flat_map(|first| [(first, false), (first, true)])
        {
            let mut value = config(&["a@b", "b@b"]);
            value["maximum_bytes"] = json!(32_768);
            value["buffer_bytes"] = json!(131_072);
            let mut worker = worker(value);
            assert!(
                worker
                    .feed(0, b"222 0 <a@b> body\r\n")
                    .unwrap()
                    .metadata
                    .error
                    .is_none()
            );
            let mut body = vec![b'x'; 70_000];
            if stuffed {
                body[..2].copy_from_slice(b"..");
            }
            worker.feed(0, &body[..first]).unwrap();
            let reply = worker.feed(0, &body[first..]).unwrap();
            assert_eq!(
                reply.metadata.error,
                Some("Verification article exceeds byte budget"),
                "split {first}"
            );
            assert!(reply.metadata.results.is_empty());
        }
    }

    #[test]
    fn bulk_verification_error_keeps_prior_results_and_is_permanent() {
        let mut worker = worker(config(&["a@b", "b@b", "c@b"]));
        let reply = worker
            .feed(0, b"222 1 <a@b>\nabc\n.\n222 2 <wrong@b>\nignored\n.\n")
            .unwrap();
        assert_error(&reply);
        assert_eq!(reply.bodies, [b"abc\n".to_vec()]);
        assert_eq!(
            metadata(&reply)["results"],
            json!([{"index": 0, "status": "body", "length": 4}])
        );
        let again = worker
            .feed(0, b"222 2 <b@b>\nx\n.\n222 3 <c@b>\ny\n.\n")
            .unwrap();
        assert_error(&again);
        assert_eq!(again.metadata.error, reply.metadata.error);
        assert!(again.metadata.results.is_empty());
        assert!(again.bodies.is_empty());
        assert!(worker.connections[0].body.is_empty());
        assert!(worker.connections[0].line.is_empty());
    }

    #[test]
    fn bulk_verification_stat_identity_and_order_are_checked() {
        let mut value = config(&["a@b", "b@b", "c@b", "d@b"]);
        value["connections"][0]["mode"] = json!("stat");
        let reply = worker(value.clone())
            .feed(
                0,
                b"223 1 <a@b> present\r\n423 no article\n430 missing\n223 4 <d@b>\n",
            )
            .unwrap();
        assert!(reply.metadata.done);
        assert!(reply.metadata.error.is_none());
        assert_eq!(
            reply
                .metadata
                .results
                .iter()
                .map(|r| (r.index, r.status, r.length))
                .collect::<Vec<_>>(),
            [
                (0, "present", 0),
                (1, "missing", 0),
                (2, "missing", 0),
                (3, "present", 0)
            ]
        );
        assert!(reply.bodies.is_empty());
        for bad in [
            b"223 1 <b@b>\n".as_slice(),
            b"222 1 <a@b>\n",
            b"223 nope <a@b>\n",
            b"223 1 text <a@b>\n",
            b"223 1 <A@b>\n",
        ] {
            let reply = worker(value.clone()).feed(0, bad).unwrap();
            assert_error(&reply);
            assert!(reply.metadata.results.is_empty());
        }
    }

    #[test]
    fn bulk_verification_rejects_malformed_and_unexpected_statuses() {
        for bad in [
            b"223 1 <a@b>\n".as_slice(),
            b"220 1 <a@b>\n",
            b"2220 1 <a@b>\n",
            b"22 1 <a@b>\n",
            b"222 <a@b>\n",
            b"222 1 a@b\n",
            b"222 1 <<a@b>>\n",
            b"222 1 <a@b>\rtext\n",
            b"222\t1 <a@b>\n",
            b"500 error\n",
        ] {
            let reply = worker(config(&["a@b"])).feed(0, bad).unwrap();
            assert_error(&reply);
            assert!(reply.metadata.results.is_empty());
        }
    }

    #[test]
    fn bulk_verification_rejects_invalid_ids_and_configuration() {
        for id in [
            "", " ", "a b", "a\tb", "a\rb", "a\nb", "a\0b", "a\u{7f}b", "a<b", "a>b", "a\u{a0}b",
            "a\u{85}b",
        ] {
            let value = config(&[id]);
            assert!(
                Worker::new(serde_json::from_value(value).unwrap()).is_err(),
                "{id:?}"
            );
        }
        for (maximum, buffer) in [
            (0, 1),
            (1, 0),
            (2, 1),
            (MAX_ARTICLE + 1, MAX_TOTAL),
            (1, MAX_TOTAL + 1),
        ] {
            let mut value = config(&["a@b"]);
            value["maximum_bytes"] = json!(maximum);
            value["buffer_bytes"] = json!(buffer);
            assert!(Worker::new(serde_json::from_value(value).unwrap()).is_err());
        }
        for connections in [json!([]), json!(vec![json!({"ids": ["a@b"]}); 9])] {
            let mut value = config(&["a@b"]);
            value["connections"] = connections;
            assert!(Worker::new(serde_json::from_value(value).unwrap()).is_err());
        }
        let mut value = config(&["a@b"]);
        value["connections"][0]["ids"] = json!(vec!["a@b"; 17]);
        assert!(Worker::new(serde_json::from_value(value).unwrap()).is_err());
        let mut value = config(&["a@b"]);
        value["connections"] = json!(vec![json!({"ids": vec!["a@b"; 16]}); 8]);
        assert_eq!(worker(value).connections.len(), 8);
    }

    #[test]
    fn bulk_verification_article_budget_includes_partial_lines_and_allows_terminator_at_limit() {
        let mut value = config(&["a@b"]);
        value["maximum_bytes"] = json!(4);
        let mut at_limit = worker(value.clone());
        let first = at_limit.feed(0, b"222 1 <a@b>\nabc\n.\r").unwrap();
        assert!(!first.metadata.done);
        let reply = at_limit.feed(0, b"\n").unwrap();
        assert!(reply.metadata.error.is_none());
        assert_eq!(reply.bodies, [b"abc\n".to_vec()]);
        let mut over = worker(value);
        let reply = over.feed(0, b"222 1 <a@b>\nabcd").unwrap();
        assert!(reply.metadata.error.is_none());
        let reply = over.feed(0, b"e").unwrap();
        assert_error(&reply);
        assert!(reply.metadata.results.is_empty());
    }

    #[test]
    fn bulk_verification_global_budget_counts_returned_bodies_and_other_connections_partial_lines()
    {
        let value = json!({"connections": [{"ids": ["a@b", "b@b"]}, {"ids": ["c@b"]}], "maximum_bytes": 4, "buffer_bytes": 6});
        let mut worker = worker(value);
        assert!(
            worker
                .feed(1, b"222 1 <c@b>\nxy")
                .unwrap()
                .metadata
                .error
                .is_none()
        );
        assert_eq!(worker.used, 2);
        let reply = worker.feed(0, b"222 1 <a@b>\nabc\n.\n").unwrap();
        assert!(reply.metadata.error.is_none());
        assert_eq!(reply.bodies, [b"abc\n".to_vec()]);
        assert_eq!(worker.used, 6);
        let reply = worker.feed(0, b"222 2 <b@b>\nz").unwrap();
        assert_error(&reply);
        let reply = worker.feed(1, b"\n.\n").unwrap();
        assert_error(&reply);
        assert_eq!(worker.used, 6);
    }

    #[test]
    fn bulk_verification_line_limits_apply_to_status_body_and_fragmented_lines() {
        let mut value = config(&["a@b"]);
        value["maximum_bytes"] = json!(MAX_ARTICLE);
        value["buffer_bytes"] = json!(MAX_TOTAL);
        let mut status = b"222 1 <a@b> ".to_vec();
        status.resize(MAX_LINE - 1, b'x');
        status.push(b'\n');
        let mut exact = worker(value.clone());
        assert!(exact.feed(0, &status).unwrap().metadata.error.is_none());
        let mut body = vec![b'x'; MAX_LINE - 1];
        body.push(b'\n');
        assert!(exact.feed(0, &body).unwrap().metadata.error.is_none());
        assert_eq!(exact.feed(0, b".\n").unwrap().bodies[0].len(), MAX_LINE);
        for prefix in [b"".as_slice(), b"222 1 <a@b>\n"] {
            let mut over = worker(value.clone());
            over.feed(0, prefix).unwrap();
            assert!(
                !over
                    .feed(0, &vec![b'x'; MAX_LINE - 1])
                    .unwrap()
                    .metadata
                    .done
            );
            assert_error(&over.feed(0, b"x").unwrap());
        }
    }

    #[test]
    fn bulk_verification_trailing_data_fails_in_same_or_later_feed_without_reemitting() {
        for trailing in [b"extra".as_slice(), b"\r\n", b"223 1 <a@b>\n"] {
            let mut same = worker(config(&["a@b"]));
            let data = [b"222 1 <a@b>\n.\n".as_slice(), trailing].concat();
            let reply = same.feed(0, &data).unwrap();
            assert_error(&reply);
            assert_eq!(reply.metadata.results.len(), 1);
            let mut later = worker(config(&["a@b"]));
            assert!(later.feed(0, b"430 missing\n").unwrap().metadata.done);
            let reply = later.feed(0, trailing).unwrap();
            assert_error(&reply);
            assert!(reply.metadata.results.is_empty());
        }
    }

    fn init(value: Value) -> Vec<u8> {
        let json = serde_json::to_vec(&value).unwrap();
        let mut input = (json.len() as u32).to_be_bytes().to_vec();
        input.extend(json);
        input
    }

    fn feed(input: &mut Vec<u8>, connection: u8, bytes: &[u8]) {
        input.push(connection);
        input.extend((bytes.len() as u32).to_be_bytes());
        input.extend(bytes);
    }

    fn read_reply(reader: &mut &[u8]) -> (Value, Vec<u8>) {
        let length = read_length(reader).unwrap();
        let value: Value = serde_json::from_slice(&reader[..length]).unwrap();
        *reader = &reader[length..];
        let body_length = value["results"]
            .as_array()
            .unwrap()
            .iter()
            .map(|r| r["length"].as_u64().unwrap() as usize)
            .sum::<usize>();
        let body = reader[..body_length].to_vec();
        *reader = &reader[body_length..];
        (value, body)
    }

    #[test]
    fn bulk_verification_wire_protocol_flushes_handshake_and_each_reply() {
        #[derive(Default)]
        struct Writer {
            data: Vec<u8>,
            flushes: usize,
        }
        impl Write for Writer {
            fn write(&mut self, data: &[u8]) -> io::Result<usize> {
                self.data.extend(data);
                Ok(data.len())
            }
            fn flush(&mut self) -> io::Result<()> {
                self.flushes += 1;
                Ok(())
            }
        }
        let mut input = init(config(&["a@b", "b@b"]));
        feed(&mut input, 0, b"222 1 <a@b>\n\0\xff\n.\n");
        feed(&mut input, 0, b"430 missing\n");
        let mut output = Writer::default();
        run(input.as_slice(), &mut output).unwrap();
        assert_eq!(output.flushes, 3);
        assert_eq!(output.data[0], 0);
        let mut reader = &output.data[1..];
        let (first, body) = read_reply(&mut reader);
        assert_eq!(
            first,
            json!({"done": false, "error": null, "results": [{"index": 0, "status": "body", "length": 3}]})
        );
        assert_eq!(body, b"\0\xff\n");
        let (second, body) = read_reply(&mut reader);
        assert_eq!(
            second,
            json!({"done": true, "error": null, "results": [{"index": 1, "status": "missing", "length": 0}]})
        );
        assert!(body.is_empty());
        assert!(reader.is_empty());
    }

    #[test]
    fn bulk_verification_corrupt_protocol_and_invalid_init_fail_closed() {
        for bytes in [
            vec![],
            vec![0],
            vec![0, 0, 0],
            0u32.to_be_bytes().to_vec(),
            ((MAX_INIT + 1) as u32).to_be_bytes().to_vec(),
            [1u32.to_be_bytes().as_slice(), b"{"].concat(),
        ] {
            let mut output = Vec::new();
            assert!(run(bytes.as_slice(), &mut output).is_err());
            assert!(output.is_empty());
        }
        for suffix in [
            vec![1],
            vec![0],
            vec![0, 0, 0],
            [vec![0], ((MAX_FEED + 1) as u32).to_be_bytes().to_vec()].concat(),
            vec![0, 0, 0, 0, 2, b'x'],
        ] {
            let mut input = init(config(&["a@b"]));
            input.extend(suffix);
            let mut output = Vec::new();
            assert!(run(input.as_slice(), &mut output).is_err());
            assert_eq!(output, [0]);
        }
        for bad in [
            json!({}),
            json!({"connections": [], "maximum_bytes": 1, "buffer_bytes": 1}),
            json!({"connections": [{"ids": ["secret\nid"]}], "maximum_bytes": 1, "buffer_bytes": 1}),
        ] {
            let mut output = Vec::new();
            assert!(run(init(bad).as_slice(), &mut output).is_err());
            assert!(output.is_empty());
        }
    }
}
