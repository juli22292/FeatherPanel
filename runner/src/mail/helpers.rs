//! Shared helpers for mail controllers.

use anyhow::{Result, anyhow};
use ego_tree::iter::Edge;
use once_cell::sync::Lazy;
use scraper::{Html, Node};
use tracing::info;

use crate::types::MailConfig;

pub static HTTP_CLIENT: Lazy<reqwest::Client> = Lazy::new(|| {
    reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(30))
        .user_agent("FeatherPanel-async-runner/1.5")
        .build()
        .expect("failed to build HTTP client for mail providers")
});

/// Sanitise a display name for use in an RFC 5322 `From` header.
pub fn sanitize_display_name(name: &str) -> String {
    let needs_quoting = name.chars().any(|c| {
        matches!(
            c,
            '(' | ')' | '<' | '>' | '[' | ']' | ':' | ';' | '@' | '\\' | ',' | '.' | '"'
        ) || !c.is_ascii()
            || c.is_ascii_control()
    });

    if needs_quoting {
        let escaped = name.replace('\\', "\\\\").replace('"', "\\\"");
        format!("\"{}\"", escaped)
    } else {
        name.to_string()
    }
}

pub fn from_header(config: &MailConfig) -> String {
    format!("{} <{}>", sanitize_display_name(&config.from_name), config.from)
}

/// Converts visible HTML into a text alternative while preserving link destinations.
pub fn html_to_plain_text(html: &str) -> String {
    let document = Html::parse_document(html);
    let mut text = String::new();
    let mut skipped = None;
    let mut link_start = None;

    for edge in document.tree.root().traverse() {
        if let Some(hidden) = skipped {
            if matches!(edge, Edge::Close(node) if node.id() == hidden) {
                skipped = None;
            }
            continue;
        }

        match edge {
            Edge::Open(node) => match node.value() {
                Node::Text(value) => text.extend(value.chars().map(|c| {
                    if c.is_whitespace() { ' ' } else { c }
                })),
                Node::Element(element) => match element.name() {
                    "head" | "style" | "script" | "template" => skipped = Some(node.id()),
                    "a" => link_start = Some(text.len()),
                    "br" => text.push('\n'),
                    "tr" | "li" if text.trim_end_matches(' ').ends_with('\n') => {},
                    name => append_block_break(&mut text, name),
                },
                _ => {}
            },
            Edge::Close(node) => {
                let Some(element) = node.value().as_element() else {
                    continue;
                };
                if element.name() == "a" {
                    if let (Some(start), Some(href)) = (link_start.take(), element.attr("href")) {
                        let url = href.trim();
                        let label = text[start..].trim();
                        if !url.is_empty() && label != url {
                            if label.is_empty() {
                                text.push_str(url);
                            } else {
                                text.push_str(" (");
                                text.push_str(url);
                                text.push(')');
                            }
                        }
                    }
                } else if matches!(element.name(), "td" | "th") {
                    text.push(' ');
                } else {
                    append_block_break(&mut text, element.name());
                }
            }
        }
    }

    let mut lines = Vec::new();
    for line in text.lines() {
        let line = line.split_whitespace().collect::<Vec<_>>().join(" ");
        if !line.is_empty() || lines.last().is_some_and(|previous: &String| !previous.is_empty()) {
            lines.push(line);
        }
    }
    lines.join("\n").trim_end().to_owned()
}

fn append_block_break(text: &mut String, tag: &str) {
    if matches!(tag, "p" | "div" | "h1" | "h2" | "h3" | "h4" | "h5" | "h6") {
        text.push_str("\n\n");
    } else if matches!(tag, "tr" | "li") {
        text.push('\n');
    }
}

pub fn truncate_body(body: &str, max: usize) -> String {
    let trimmed = body.trim();
    if trimmed.chars().count() <= max {
        return trimmed.to_string();
    }
    let shortened: String = trimmed.chars().take(max).collect();
    format!("{}…", shortened)
}

pub async fn ensure_http_ok(provider: &str, response: reqwest::Response) -> Result<()> {
    let status = response.status();
    let body = response.text().await.unwrap_or_default();
    if status.is_success() {
        info!("{} delivery accepted (HTTP {})", provider, status.as_u16());
        return Ok(());
    }
    Err(anyhow!(
        "{} permanent error HTTP {}: {}",
        provider,
        status.as_u16(),
        truncate_body(&body, 500)
    ))
}

pub fn require_api_key(config: &MailConfig, provider: &str) -> Result<()> {
    if config.api_key.trim().is_empty() {
        return Err(anyhow!("{} requires mail_api_key", provider));
    }
    Ok(())
}

pub fn split_ses_credentials(api_key: &str) -> Result<(&str, &str)> {
    let (access, secret) = api_key
        .split_once(':')
        .ok_or_else(|| anyhow!("Amazon SES mail_api_key must be ACCESS_KEY:SECRET_KEY"))?;
    if access.is_empty() || secret.is_empty() {
        return Err(anyhow!("Amazon SES mail_api_key must be ACCESS_KEY:SECRET_KEY"));
    }
    Ok((access, secret))
}

#[cfg(test)]
mod tests {
    use super::html_to_plain_text;

    #[test]
    fn text_alternative_keeps_structure_entities_and_links() {
        let html = r#"<!doctype html><html><head><title>Hidden</title><style>.bad{}</style></head>
            <body><h2>Welcome &amp; hello</h2><script>ignored()</script>
            <p>Open <a HREF='https://example.org/?a=1&amp;b=2'><strong>the panel</strong></a>
            or <a href="https://example.org">https://example.org</a>.</p>
            <div>First<br/>Second &lt;tag&gt; &quot;quoted&quot; &#39;one&#39;&nbsp;two</div>
            <ul><li>One</li><li>Two</li></ul>
            <table><tr><td>A</td><td>B</td></tr><tr><td>C</td></tr></table></body></html>"#;
        assert_eq!(
            html_to_plain_text(html),
            "Welcome & hello\n\nOpen the panel (https://example.org/?a=1&b=2) or https://example.org.\n\nFirst\nSecond <tag> \"quoted\" 'one' two\n\nOne\nTwo\nA B\nC"
        );
    }

    #[test]
    fn text_alternative_handles_fragments_and_quoted_markup() {
        let html = r#"<div data-attr="x > y">Text<!-- comment --><style>hidden</style>
            <a href='https://example.org' aria-label="Open"><img alt="Open"></a>
            <p>Next<br><br><br>Line"#;
        assert_eq!(
            html_to_plain_text(html),
            "Text https://example.org\n\nNext\n\nLine"
        );
    }
}
