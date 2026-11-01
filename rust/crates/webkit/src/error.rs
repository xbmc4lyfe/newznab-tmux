//! Errors a handler can return, each rendered as the matching page, or as JSON when the caller
//! asked for it.

use askama::Template;
use axum::Json;
use axum::http::StatusCode;
use axum::response::{Html, IntoResponse, Response};
use tracing::error;

pub type WebResult<T = Response> = Result<T, WebError>;

#[derive(Debug)]
pub enum WebError {
    Unauthorized,
    Forbidden(Option<String>),
    NotFound,
    PageExpired,
    TooManyRequests,
    /// 403 for a category this account can't see. The name is shown on the page.
    CategoryDisabled { category: String, subcategory: bool },
    Internal(anyhow::Error),
}

impl<E: Into<anyhow::Error>> From<E> for WebError {
    fn from(failure: E) -> Self {
        Self::Internal(failure.into())
    }
}

#[derive(Template)]
#[template(path = "error.html")]
struct ErrorPage<'a> {
    title: &'a str,
    message: &'a str,
}

#[derive(Template)]
#[template(path = "category_disabled.html")]
struct CategoryDisabledPage<'a> {
    category: &'a str,
    subcategory: bool,
}

fn page(status: StatusCode, title: &str, message: &str) -> Response {
    let body = ErrorPage { title, message }.render().unwrap_or_else(|_| title.to_owned());
    (status, Html(body)).into_response()
}

impl WebError {
    pub fn status(&self) -> StatusCode {
        match self {
            Self::Unauthorized => StatusCode::UNAUTHORIZED,
            Self::Forbidden(_) | Self::CategoryDisabled { .. } => StatusCode::FORBIDDEN,
            Self::NotFound => StatusCode::NOT_FOUND,
            Self::PageExpired => StatusCode::from_u16(419).unwrap_or(StatusCode::FORBIDDEN),
            Self::TooManyRequests => StatusCode::TOO_MANY_REQUESTS,
            Self::Internal(_) => StatusCode::INTERNAL_SERVER_ERROR,
        }
    }

    fn message(&self) -> &str {
        match self {
            Self::Unauthorized => "Unauthorized",
            Self::Forbidden(Some(message)) => message,
            Self::Forbidden(None) | Self::CategoryDisabled { .. } => "Forbidden",
            Self::NotFound => "Not Found",
            Self::PageExpired => "Page Expired",
            Self::TooManyRequests => "Too Many Requests",
            Self::Internal(_) => "Server Error",
        }
    }

    /// The same error as `{"message": ...}`, for endpoints JavaScript calls.
    pub fn json(self) -> Response {
        let status = self.status();
        let message = self.message().to_owned();
        self.log();
        (status, Json(serde_json::json!({ "message": message }))).into_response()
    }

    fn log(&self) {
        if let Self::Internal(failure) = self {
            error!(error = format!("{failure:#}"), "request failed");
        }
    }
}

impl IntoResponse for WebError {
    fn into_response(self) -> Response {
        self.log();
        let status = self.status();
        match &self {
            Self::CategoryDisabled { category, subcategory } => {
                let template = CategoryDisabledPage { category, subcategory: *subcategory };
                let body = template.render().unwrap_or_else(|_| "Category Disabled".to_owned());
                (status, Html(body)).into_response()
            }
            _ => page(status, self.message(), self.message()),
        }
    }
}
