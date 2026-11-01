//! Responses: the error formats shared by every endpoint, and the conversion to axum's.

use axum::body::Body;
use axum::http::{HeaderName, HeaderValue, StatusCode};
use axum::response::Response;
use bytes::Bytes;

use crate::json::{J, Style};

#[derive(Debug)]
pub struct Reply {
    pub status: u16,
    pub headers: Vec<(&'static str, String)>,
    pub body: Bytes,
}

impl Reply {
    pub fn new(status: u16, content_type: &str, body: impl Into<Bytes>) -> Self {
        Self {
            status,
            headers: vec![("content-type", content_type.to_owned())],
            body: body.into(),
        }
    }

    pub fn empty(status: u16) -> Self {
        Self {
            status,
            headers: Vec::new(),
            body: Bytes::new(),
        }
    }

    pub fn json(status: u16, value: &J, style: Style) -> Self {
        Self::new(status, "application/json", value.encode(style))
    }

    /// `response()->json()` with no flags.
    pub fn message(status: u16, message: &str) -> Self {
        Self::json(status, &J::obj([("message", J::str(message))]), Style::Plain)
    }

    /// The v2 presenter's `{"error": ...}` for validation failures, which sets no X-NNTmux.
    pub fn v2_error(status: u16, message: &str) -> Self {
        Self::json(status, &J::obj([("error", J::str(message))]), Style::Unescaped)
    }

    /// How Laravel renders an HTTP exception to a client wanting JSON: pretty-printed.
    pub fn exception(status: u16, message: &str) -> Self {
        let body = format!(
            "{{\n    \"message\": {}\n}}",
            J::str(message).encode(Style::Plain)
        );
        Self::new(status, "application/json", body)
    }

    pub fn server_error() -> Self {
        Self::exception(500, "Server Error")
    }

    pub fn header(mut self, name: &'static str, value: impl Into<String>) -> Self {
        self.set_header(name, value);
        self
    }

    pub fn set_header(&mut self, name: &'static str, value: impl Into<String>) {
        self.headers.retain(|(existing, _)| *existing != name);
        self.headers.push((name, value.into()));
    }

    pub fn has_header(&self, name: &str) -> bool {
        self.headers.iter().any(|(existing, _)| *existing == name)
    }

    pub fn into_response(self) -> Response {
        let mut response = Response::new(Body::from(self.body));
        *response.status_mut() = StatusCode::from_u16(self.status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR);
        for (name, value) in self.headers {
            // Values that can't be headers come from client input, such as a `t` with a newline.
            if let (Ok(name), Ok(value)) = (HeaderName::from_bytes(name.as_bytes()), HeaderValue::from_str(&value)) {
                response.headers_mut().append(name, value);
            }
        }
        response
    }
}

/// `apiErrorDetails`: default text and HTTP status for a newznab error code.
fn error_details(code: u16) -> (&'static str, u16) {
    match code {
        100 => ("Incorrect user credentials", 401),
        101 => ("Account suspended", 403),
        102 => ("Insufficient privileges/not authorized", 401),
        103 => ("Registration denied", 403),
        104 => ("Registrations are closed", 403),
        105 => ("Invalid registration (Email Address Taken)", 403),
        106 => ("Invalid registration (Email Address Bad Format)", 403),
        107 => ("Registration Failed (Data error)", 400),
        200 => ("Missing parameter", 400),
        201 => ("Incorrect parameter", 400),
        202 => ("No such function", 404),
        203 => ("Function not available", 400),
        300 => ("No such item", 404),
        310 => ("Item already exists", 409),
        500 => ("Request limit reached", 429),
        501 => ("Download limit reached", 429),
        600 => ("Failed to load NZB", 400),
        601 => ("NZB is duplicate", 409),
        602 => ("NZB is for a non-existent group", 400),
        603 => ("NZB failed to write to disk", 500),
        910 => ("API disabled", 401),
        _ => ("Unknown error", 400),
    }
}

/// `apiErrorSafeText`: error text goes into a header, so control characters and newlines go.
fn safe_text(text: &str) -> String {
    let stripped: String = text
        .chars()
        .map(|c| if c.is_control() { ' ' } else { c })
        .collect();
    stripped.trim().to_owned()
}

fn message_for(code: u16, text: &str) -> (String, u16) {
    let (default, status) = error_details(code);
    let message = if text.is_empty() { default } else { text };
    (safe_text(message), status)
}

/// `showApiError`: the newznab XML error.
pub fn xml_error(code: u16, text: &str) -> Reply {
    let (message, status) = message_for(code, text);
    let mut escaped = String::with_capacity(message.len());
    for ch in message.chars() {
        match ch {
            '&' => escaped.push_str("&amp;"),
            '<' => escaped.push_str("&lt;"),
            '>' => escaped.push_str("&gt;"),
            '"' => escaped.push_str("&quot;"),
            '\'' => escaped.push_str("&apos;"),
            c => escaped.push(c),
        }
    }
    let body = format!(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error code=\"{code}\" description=\"{escaped}\"/>\n"
    );
    Reply::new(status, "text/xml; charset=utf-8", body)
        .header("x-nntmux", format!("API ERROR [{code}] {message}"))
}

/// `apiJsonError`: the same error for API v2 and RSS.
pub fn json_error(code: u16, text: &str) -> Reply {
    let (message, status) = message_for(code, text);
    Reply::json(status, &J::obj([("error", J::str(message.clone()))]), Style::Plain)
        .header("x-nntmux", format!("API ERROR [{code}] {message}"))
}

impl Reply {
    pub fn header_if(self, condition: bool, name: &'static str, value: impl Into<String>) -> Self {
        if condition { self.header(name, value) } else { self }
    }
}
