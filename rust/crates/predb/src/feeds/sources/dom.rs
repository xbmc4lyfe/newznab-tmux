//! Tree helpers for the HTML sources. The PHP read the page with libxml and XPath; these walk the
//! HTML5 tree `scraper` builds, with the same child, descendant and class-token semantics.

use std::ops::Deref;

use scraper::{ElementRef, Html, Node};

/// The tree node type `scraper` wraps, named without a direct `ego-tree` dependency.
pub(super) type NodeRef<'a> = <ElementRef<'a> as Deref>::Target;

pub(super) fn parse(html: &str) -> Html {
    Html::parse_document(html)
}

/// The tag name, lowercased by the parser.
pub(super) fn tag<'a>(element: ElementRef<'a>) -> &'a str {
    element.value().name()
}

pub(super) fn is_tag(element: ElementRef<'_>, name: &str) -> bool {
    tag(element) == name
}

/// `./*`.
pub(super) fn children(element: ElementRef<'_>) -> Vec<ElementRef<'_>> {
    element.child_elements().collect()
}

/// `./name`.
pub(super) fn children_named<'a>(element: ElementRef<'a>, name: &str) -> Vec<ElementRef<'a>> {
    element
        .child_elements()
        .filter(|child| is_tag(*child, name))
        .collect()
}

/// `.//*`: descendants without the element itself.
pub(super) fn descendants(element: ElementRef<'_>) -> Vec<ElementRef<'_>> {
    element.descendent_elements().skip(1).collect()
}

/// `//*` over the whole document.
pub(super) fn all_elements(html: &Html) -> Vec<ElementRef<'_>> {
    html.root_element().descendent_elements().collect()
}

/// `getElementsByTagName('*')->length !== 0`.
pub(super) fn has_element_descendants(element: ElementRef<'_>) -> bool {
    element.child_elements().next().is_some()
}

pub(super) fn count_descendants_named(element: ElementRef<'_>, name: &str) -> usize {
    element
        .descendent_elements()
        .skip(1)
        .filter(|descendant| is_tag(*descendant, name))
        .count()
}

/// `$node->textContent`.
pub(super) fn text_content(element: ElementRef<'_>) -> String {
    element.text().collect()
}

pub(super) fn parent_element<'a>(element: ElementRef<'a>) -> Option<ElementRef<'a>> {
    element.parent().and_then(ElementRef::wrap)
}

/// XPath whitespace: space, tab, CR and LF only.
fn is_xml_space(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\r' | '\n')
}

/// XPath `normalize-space()`.
pub(super) fn normalize_space(text: &str) -> String {
    text.split(is_xml_space)
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>()
        .join(" ")
}

/// `contains(concat(" ", normalize-space(@class), " "), " token ")`.
pub(super) fn has_class(element: ElementRef<'_>, token: &str) -> bool {
    element
        .attr("class")
        .is_some_and(|class| class.split(is_xml_space).any(|part| part == token))
}

/// Class membership by PCRE `\s+` splitting of the trimmed attribute, as Gabber's own check did.
pub(super) fn has_class_pcre_space(element: ElementRef<'_>, token: &str) -> bool {
    let class = super::literal::php_trim(element.attr("class").unwrap_or(""));
    class
        .split([' ', '\t', '\n', '\x0b', '\x0c', '\r'])
        .any(|part| part == token)
}

/// Child nodes in order, for the checks that care about stray text and element identity.
pub(super) fn child_nodes<'a>(element: ElementRef<'a>) -> impl Iterator<Item = NodeRef<'a>> {
    element.children()
}

/// Text that is not blank by PHP `trim()`.
pub(super) fn is_visible_text(node: &Node) -> bool {
    node.as_text()
        .is_some_and(|text| !super::literal::php_trim(text).is_empty())
}

/// The element siblings after `element`, in order, as nodes.
pub(super) fn following_nodes<'a>(element: ElementRef<'a>) -> impl Iterator<Item = NodeRef<'a>> {
    element.next_siblings()
}

/// Whether the parser reported a duplicated attribute, which libxml reported as error 42 and
/// html5ever repairs by keeping the first.
pub(super) fn has_duplicate_attribute(html: &Html) -> bool {
    html.errors
        .iter()
        .any(|error| error == "Duplicate attribute")
}

/// Which half of the closed-structure check refused a page.
#[derive(Debug, PartialEq, Eq)]
pub(super) enum Unclosed {
    Document,
    Scope,
}

/// Requires the publisher's own explicit boundaries before the parser can close a truncated
/// listing for us: the page ends with `</html>` and every tag of interest closes in order.
/// Comments, scripts and styles are ignored first, so tag-like text in them can neither add
/// nor supply a closure. `tags` matches `<tag ...>` and `</tag>` and captures the slash and name.
pub(super) fn closed_listing_structure(
    html: &str,
    tags: &regex::bytes::Regex,
) -> Result<(), Unclosed> {
    use std::sync::LazyLock;

    use regex::bytes::Regex;

    static END: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"(?is-u)</html\s*>\s*\z").expect("pattern"));

    let markup = strip_inert(html);
    if !END.is_match(&markup) {
        return Err(Unclosed::Document);
    }
    let mut stack: Vec<String> = Vec::new();
    let (mut documents, mut bodies) = (0, 0);
    for tag in tags.captures_iter(&markup) {
        let name = String::from_utf8_lossy(&tag[2]).to_ascii_lowercase();
        if tag[1].is_empty() {
            documents += usize::from(name == "html");
            bodies += usize::from(name == "body");
            stack.push(name);
        } else if stack.pop().as_deref() != Some(name.as_str()) {
            return Err(Unclosed::Scope);
        }
    }
    if !stack.is_empty() || documents != 1 || bodies != 1 {
        return Err(Unclosed::Scope);
    }
    Ok(())
}

/// The page without its comments, scripts and styles, which PHP's `preg_replace` removed before
/// looking for tags. An unterminated one runs to the end of the text. Its `\1` back-reference is
/// spelled out as one branch per element.
pub(super) fn strip_inert(html: &str) -> std::borrow::Cow<'_, [u8]> {
    use std::sync::LazyLock;

    use regex::bytes::Regex;

    static INERT: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(
            r"(?is-u)<!--.*?(?:-->|\z)|<script\b[^>]*>.*?(?:</script\s*>|\z)|<style\b[^>]*>.*?(?:</style\s*>|\z)",
        )
        .expect("pattern")
    });
    INERT.replace_all(html.as_bytes(), &b""[..])
}

/// Whether any element is more than `limit` deep (the root counts as one). One pass, each
/// element's depth read from its parent's, so a hostile page can't make it quadratic.
pub(super) fn exceeds_depth(html: &Html, limit: usize) -> bool {
    let mut depths = std::collections::HashMap::new();
    for element in html.root_element().descendent_elements() {
        let parent = element
            .parent()
            .and_then(|parent| depths.get(&parent.id()))
            .copied()
            .unwrap_or(0);
        if parent + 1 > limit {
            return true;
        }
        depths.insert(element.id(), parent + 1);
    }
    false
}

/// The most nested elements a page may open before it is parsed. html5ever scans its stack of open
/// elements for every start tag, so a megabyte of `<div>` costs minutes; libxml simply stopped
/// building the tree at 256 levels. Real listings close their tags, so this is far above them.
pub(super) const MAXIMUM_LEXICAL_DEPTH: usize = 1024;

/// A cheap upper bound on nesting from the tags alone: start tags opened and not yet closed,
/// ignoring void elements and anything inside comments, scripts and styles.
pub(super) fn exceeds_lexical_depth(html: &str, limit: usize) -> bool {
    use std::sync::LazyLock;

    use regex::bytes::Regex;

    static TAG: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r#"(?is-u)<(/?)([a-z][a-z0-9]*)(?:"[^"]*"|'[^']*'|[^'">])*>"#).expect("pattern")
    });
    const VOID: [&[u8]; 14] = [
        b"area", b"base", b"br", b"col", b"embed", b"hr", b"img", b"input", b"link", b"meta",
        b"param", b"source", b"track", b"wbr",
    ];
    let markup = strip_inert(html);
    let mut depth = 0usize;
    for tag in TAG.captures_iter(&markup) {
        if !tag[1].is_empty() {
            depth = depth.saturating_sub(1);
        } else if !VOID.iter().any(|name| tag[2].eq_ignore_ascii_case(name))
            && !tag[0].ends_with(b"/>")
        {
            depth += 1;
            if depth > limit {
                return true;
            }
        }
    }
    false
}
