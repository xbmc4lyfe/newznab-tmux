//! Compiles every pattern in the rule sources, so a typo in a branch the golden corpus never
//! reaches can't first show up as a panic in production. Fragments that `concat!` joins are
//! unbalanced on their own, so they're skipped; the golden test compiles the joined forms.

use nntmux_categorize::pcre::compile;

fn balanced(pattern: &str) -> bool {
    let mut depth = 0i32;
    let mut chars = pattern.chars();
    while let Some(c) = chars.next() {
        match c {
            '\\' => {
                chars.next();
            }
            '(' => depth += 1,
            ')' => depth -= 1,
            _ => {}
        }
        if depth < 0 {
            return false;
        }
    }
    depth == 0
}

#[test]
fn all_literal_patterns_compile() {
    let plain = regex::Regex::new(r#"\br"([^"\n]*)""#).expect("finder");
    let hashed = regex::Regex::new(r##"\br#"(.*?)"#"##).expect("finder");
    let mut count = 0;
    for source in [
        include_str!("../src/names.rs"),
        include_str!("../src/rules/mod.rs"),
        include_str!("../src/rules/book.rs"),
        include_str!("../src/rules/console.rs"),
        include_str!("../src/rules/group.rs"),
        include_str!("../src/rules/misc.rs"),
        include_str!("../src/rules/movie.rs"),
        include_str!("../src/rules/music.rs"),
        include_str!("../src/rules/pc.rs"),
        include_str!("../src/rules/tv.rs"),
        include_str!("../src/rules/xxx.rs"),
    ] {
        for caps in plain
            .captures_iter(source)
            .chain(hashed.captures_iter(source))
        {
            // `\p{Lu}` is the one Unicode-aware pattern, and it has its own compiler.
            if balanced(&caps[1]) && !caps[1].contains(r"\p{") {
                compile(&caps[1], true);
                count += 1;
            }
        }
    }
    assert!(count > 200, "only found {count} patterns");
}
