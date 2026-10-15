//! PHP's recursive integer normalization, preserving JSON field order and scalar types.
//! The caller still owns format limits, percentage validity, and overflow policy.

use serde::Serialize;
use serde::ser::{self, SerializeMap, SerializeSeq, SerializeStruct};
use serde_json::Value;

type Error = serde_json::Error;
type Result<T> = std::result::Result<T, Error>;

#[derive(Debug)]
enum Node {
    Scalar(Value),
    Sequence(Vec<Node>),
    Object(Vec<(String, Node)>),
}

impl Serialize for Node {
    fn serialize<S: ser::Serializer>(&self, serializer: S) -> std::result::Result<S::Ok, S::Error> {
        match self {
            Self::Scalar(value) => value.serialize(serializer),
            Self::Sequence(values) => values.serialize(serializer),
            Self::Object(values) => {
                let mut map = serializer.serialize_map(Some(values.len()))?;
                for (key, value) in values {
                    map.serialize_entry(key, value)?;
                }
                map.end()
            }
        }
    }
}

impl Node {
    fn is_null(&self) -> bool {
        matches!(self, Self::Scalar(Value::Null))
    }

    fn is_php_integer(&self) -> bool {
        matches!(self, Self::Scalar(Value::Number(number)) if number.is_i64() || (number.is_u64() && number.as_u64().is_some_and(|value| value <= i64::MAX as u64)))
    }

    fn set(values: &mut Vec<(String, Node)>, key: &str, value: bool) {
        let replacement = Self::Scalar(Value::Bool(value));
        if let Some((_, node)) = values.iter_mut().find(|(name, _)| name == key) {
            *node = replacement;
        } else {
            values.push((key.to_owned(), replacement));
        }
    }

    fn normalize(&mut self, unsupported: &mut u64) {
        match self {
            Self::Scalar(_) => {}
            Self::Sequence(values) => {
                for value in values {
                    value.normalize(unsupported);
                }
            }
            Self::Object(values) => {
                let initial = *unsupported;
                // PHP's by-reference foreach visits appended entries as well. The only
                // appended fields here are booleans, outside the integer field lists.
                let mut index = 0;
                while index < values.len() {
                    let (key, value) = &mut values[index];
                    if matches!(value, Self::Sequence(_) | Self::Object(_)) {
                        value.normalize(unsupported);
                    } else if !value.is_null() {
                        match key.as_str() {
                            "input_slice_index" | "logical_file_offset" | "logical_byte_length" => {
                                let flag = format!("{key}_integer_range_valid");
                                let valid = value.is_php_integer();
                                Self::set(values, &flag, valid);
                            }
                            "source_block_size"
                            | "source_block_count"
                            | "source_file_bytes"
                            | "file_length"
                            | "expected_slice_count_from_file_length"
                            | "source_byte_start"
                            | "source_byte_end_exclusive"
                            | "source_bytes_in_slice"
                            | "zero_padding_bytes"
                            | "ifsc_record_index"
                            | "source_slice_checksum_bytes"
                                if !value.is_php_integer() =>
                            {
                                let flag = match key.as_str() {
                                    "file_length" => {
                                        Some(("file_length_integer_range_valid", false))
                                    }
                                    "source_file_bytes" => {
                                        Some(("source_file_bytes_overflow", true))
                                    }
                                    "source_block_count" => {
                                        Some(("source_block_count_overflow", true))
                                    }
                                    _ => None,
                                };
                                *value = Self::Scalar(Value::Null);
                                *unsupported += 1;
                                if let Some((name, valid)) = flag {
                                    Self::set(values, name, valid);
                                }
                            }
                            _ => {}
                        }
                    }
                    index += 1;
                }
                if *unsupported > initial
                    && values.iter().any(|(key, _)| key == "integer_range_valid")
                {
                    Self::set(values, "integer_range_valid", false);
                }
            }
        }
    }
}

/// Build the ordered tree directly from Rust metadata: a JSON decode round trip
/// would risk changing float bits and a serde_json::Value would sort struct keys.
pub fn encode<T: Serialize>(value: &T) -> Result<(String, u64)> {
    let mut node = value.serialize(Builder)?;
    let mut unsupported = 0;
    node.normalize(&mut unsupported);
    Ok((serde_json::to_string(&node)?, unsupported))
}

struct Builder;
struct Sequence(Vec<Node>);
struct Object {
    values: Vec<(String, Node)>,
    key: Option<String>,
}

macro_rules! scalar {
    ($method:ident, $ty:ty) => {
        fn $method(self, value: $ty) -> Result<Node> {
            Ok(Node::Scalar(serde_json::to_value(value)?))
        }
    };
}

impl ser::Serializer for Builder {
    type Ok = Node;
    type Error = Error;
    type SerializeSeq = Sequence;
    type SerializeTuple = Sequence;
    type SerializeTupleStruct = Sequence;
    type SerializeTupleVariant = ser::Impossible<Node, Error>;
    type SerializeMap = Object;
    type SerializeStruct = Object;
    type SerializeStructVariant = ser::Impossible<Node, Error>;

    scalar!(serialize_bool, bool);
    scalar!(serialize_i8, i8);
    scalar!(serialize_i16, i16);
    scalar!(serialize_i32, i32);
    scalar!(serialize_i64, i64);
    scalar!(serialize_u8, u8);
    scalar!(serialize_u16, u16);
    scalar!(serialize_u32, u32);
    scalar!(serialize_u64, u64);
    scalar!(serialize_f32, f32);
    scalar!(serialize_f64, f64);
    scalar!(serialize_char, char);
    scalar!(serialize_str, &str);

    fn serialize_bytes(self, value: &[u8]) -> Result<Node> {
        value.serialize(self)
    }
    fn serialize_none(self) -> Result<Node> {
        self.serialize_unit()
    }
    fn serialize_some<T: Serialize + ?Sized>(self, value: &T) -> Result<Node> {
        value.serialize(self)
    }
    fn serialize_unit(self) -> Result<Node> {
        Ok(Node::Scalar(Value::Null))
    }
    fn serialize_unit_struct(self, _: &'static str) -> Result<Node> {
        self.serialize_unit()
    }
    fn serialize_unit_variant(
        self,
        _: &'static str,
        _: u32,
        variant: &'static str,
    ) -> Result<Node> {
        self.serialize_str(variant)
    }
    fn serialize_newtype_struct<T: Serialize + ?Sized>(
        self,
        _: &'static str,
        value: &T,
    ) -> Result<Node> {
        value.serialize(self)
    }
    fn serialize_newtype_variant<T: Serialize + ?Sized>(
        self,
        _: &'static str,
        _: u32,
        variant: &'static str,
        value: &T,
    ) -> Result<Node> {
        Ok(Node::Object(vec![(
            variant.to_owned(),
            value.serialize(self)?,
        )]))
    }
    fn serialize_seq(self, length: Option<usize>) -> Result<Sequence> {
        Ok(Sequence(Vec::with_capacity(length.unwrap_or(0))))
    }
    fn serialize_tuple(self, length: usize) -> Result<Sequence> {
        self.serialize_seq(Some(length))
    }
    fn serialize_tuple_struct(self, _: &'static str, length: usize) -> Result<Sequence> {
        self.serialize_seq(Some(length))
    }
    fn serialize_tuple_variant(
        self,
        _: &'static str,
        _: u32,
        _: &'static str,
        _: usize,
    ) -> Result<Self::SerializeTupleVariant> {
        Err(ser::Error::custom("unsupported PAR2 tuple variant"))
    }
    fn serialize_map(self, length: Option<usize>) -> Result<Object> {
        Ok(Object {
            values: Vec::with_capacity(length.unwrap_or(0)),
            key: None,
        })
    }
    fn serialize_struct(self, _: &'static str, length: usize) -> Result<Object> {
        self.serialize_map(Some(length))
    }
    fn serialize_struct_variant(
        self,
        _: &'static str,
        _: u32,
        _: &'static str,
        _: usize,
    ) -> Result<Self::SerializeStructVariant> {
        Err(ser::Error::custom("unsupported PAR2 struct variant"))
    }
}

impl SerializeSeq for Sequence {
    type Ok = Node;
    type Error = Error;
    fn serialize_element<T: Serialize + ?Sized>(&mut self, value: &T) -> Result<()> {
        self.0.push(value.serialize(Builder)?);
        Ok(())
    }
    fn end(self) -> Result<Node> {
        Ok(Node::Sequence(self.0))
    }
}

impl ser::SerializeTuple for Sequence {
    type Ok = Node;
    type Error = Error;
    fn serialize_element<T: Serialize + ?Sized>(&mut self, value: &T) -> Result<()> {
        SerializeSeq::serialize_element(self, value)
    }
    fn end(self) -> Result<Node> {
        SerializeSeq::end(self)
    }
}

impl ser::SerializeTupleStruct for Sequence {
    type Ok = Node;
    type Error = Error;
    fn serialize_field<T: Serialize + ?Sized>(&mut self, value: &T) -> Result<()> {
        SerializeSeq::serialize_element(self, value)
    }
    fn end(self) -> Result<Node> {
        SerializeSeq::end(self)
    }
}

impl SerializeMap for Object {
    type Ok = Node;
    type Error = Error;
    fn serialize_key<T: Serialize + ?Sized>(&mut self, value: &T) -> Result<()> {
        match serde_json::to_value(value)? {
            Value::String(key) => {
                self.key = Some(key);
                Ok(())
            }
            _ => Err(ser::Error::custom("PAR2 map keys must be strings")),
        }
    }
    fn serialize_value<T: Serialize + ?Sized>(&mut self, value: &T) -> Result<()> {
        let key = self
            .key
            .take()
            .ok_or_else(|| <Error as ser::Error>::custom("missing PAR2 map key"))?;
        self.values.push((key, value.serialize(Builder)?));
        Ok(())
    }
    fn end(self) -> Result<Node> {
        Ok(Node::Object(self.values))
    }
}

impl SerializeStruct for Object {
    type Ok = Node;
    type Error = Error;
    fn serialize_field<T: Serialize + ?Sized>(
        &mut self,
        key: &'static str,
        value: &T,
    ) -> Result<()> {
        self.values
            .push((key.to_owned(), value.serialize(Builder)?));
        Ok(())
    }
    fn end(self) -> Result<Node> {
        Ok(Node::Object(self.values))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Serialize)]
    struct OrderedExample {
        z: f64,
        file_length: u64,
        integer_range_valid: bool,
        children: Vec<Value>,
        input_slice_index: u64,
        a: i64,
    }

    #[test]
    fn preserves_struct_order_float_and_optional_unsigned_evidence() {
        let value = OrderedExample {
            z: 1.0 / 3.0,
            file_length: u64::MAX,
            integer_range_valid: true,
            children: vec![
                serde_json::json!({"source_block_count": "3", "integer_range_valid": true}),
            ],
            input_slice_index: u64::MAX,
            a: -7,
        };
        let (json, count) = encode(&value).unwrap();
        assert_eq!(count, 2);
        assert_eq!(
            json,
            concat!(
                "{\"z\":0.3333333333333333,\"file_length\":null,\"integer_range_valid\":false,",
                "\"children\":[{\"integer_range_valid\":false,\"source_block_count\":null,\"source_block_count_overflow\":true}],",
                "\"input_slice_index\":18446744073709551615,\"a\":-7,",
                "\"file_length_integer_range_valid\":false,\"input_slice_index_integer_range_valid\":false}"
            )
        );
    }

    #[test]
    fn preserves_nulls_integers_and_recursive_array_precedence() {
        let value = serde_json::json!({
            "source_block_size": i64::MAX,
            "source_byte_start": -1,
            "source_byte_end_exclusive": null,
            "file_length": [{"zero_padding_bytes": 2.0}],
            "logical_file_offset": null,
            "logical_byte_length": 3,
            "integer_range_valid": true
        });
        let (json, count) = encode(&value).unwrap();
        let result: Value = serde_json::from_str(&json).unwrap();
        assert_eq!(count, 1);
        assert_eq!(result["source_block_size"], i64::MAX);
        assert_eq!(result["source_byte_start"], -1);
        assert!(result["file_length"][0]["zero_padding_bytes"].is_null());
        assert_eq!(result["logical_byte_length_integer_range_valid"], true);
        assert!(
            result
                .get("logical_file_offset_integer_range_valid")
                .is_none()
        );
        assert_eq!(result["integer_range_valid"], false);
    }

    #[test]
    fn overwrites_existing_flags_without_reordering_and_counts_every_type() {
        #[derive(Serialize)]
        struct Flags {
            source_file_bytes_overflow: bool,
            source_file_bytes: bool,
            source_block_count: f64,
            file_length: String,
            file_length_integer_range_valid: bool,
            logical_file_offset_integer_range_valid: bool,
            logical_file_offset: u64,
        }
        let (json, count) = encode(&Flags {
            source_file_bytes_overflow: false,
            source_file_bytes: false,
            source_block_count: 4.0,
            file_length: "4".into(),
            file_length_integer_range_valid: true,
            logical_file_offset_integer_range_valid: false,
            logical_file_offset: 4,
        })
        .unwrap();
        assert_eq!(count, 3);
        assert_eq!(
            json,
            concat!(
                "{\"source_file_bytes_overflow\":true,\"source_file_bytes\":null,",
                "\"source_block_count\":null,\"file_length\":null,\"file_length_integer_range_valid\":false,",
                "\"logical_file_offset_integer_range_valid\":true,\"logical_file_offset\":4,\"source_block_count_overflow\":true}"
            )
        );
    }
}
