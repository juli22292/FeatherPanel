//! SMTP mail controller (lettre).

use anyhow::{Context, Result};
use lettre::message::{Mailbox, MultiPart};
use lettre::transport::smtp::authentication::Credentials;
use lettre::transport::smtp::client::{Tls, TlsParameters};
use lettre::{Message, SmtpTransport, Transport};
use once_cell::sync::Lazy;
use std::collections::BTreeSet;
use std::net::{IpAddr, ToSocketAddrs};
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;
use tracing::{debug, info, warn};

use crate::mail::controller::MailController;
use crate::mail::helpers::{from_header, html_to_plain_text};
use crate::types::MailConfig;

static PREFER_IPV4_SMTP: Lazy<AtomicBool> = Lazy::new(|| AtomicBool::new(false));

pub struct SmtpController;

impl MailController for SmtpController {
    fn id(&self) -> &'static str {
        "smtp"
    }

    fn name(&self) -> &'static str {
        "SMTP"
    }

    async fn send(
        &self,
        config: &MailConfig,
        to: &str,
        subject: &str,
        body: &str,
    ) -> Result<()> {
        let email = build_message(config, to, subject, body)?;
        let creds = Credentials::new(config.user.clone(), config.pass.clone());

        let lookup_target = format!("{}:{}", config.host, config.port);
        let resolved_addrs = lookup_target
            .to_socket_addrs()
            .with_context(|| format!("DNS resolution failed for SMTP host {}", config.host))?
            .map(|addr| addr.ip())
            .collect::<BTreeSet<IpAddr>>();
        let ipv4_addrs = resolved_addrs
            .iter()
            .filter_map(|ip| match ip {
                IpAddr::V4(v4) => Some(v4.to_string()),
                IpAddr::V6(_) => None,
            })
            .collect::<Vec<String>>();

        let ipv4_count = resolved_addrs
            .iter()
            .filter(|ip| matches!(ip, IpAddr::V4(_)))
            .count();
        let ipv6_count = resolved_addrs
            .iter()
            .filter(|ip| matches!(ip, IpAddr::V6(_)))
            .count();

        info!(
            "SMTP target {}:{} resolved (ipv4={}, ipv6={})",
            config.host, config.port, ipv4_count, ipv6_count
        );
        debug!("SMTP resolved addresses for {}: {:?}", config.host, resolved_addrs);

        if ipv4_count == 0 && ipv6_count > 0 {
            warn!(
                "SMTP host {} resolves to IPv6 only. If container IPv6 routing is disabled, sending will fail with ENETUNREACH.",
                config.host
            );
        }

        info!(
            "Sending SMTP mail to {} via {}:{} (encryption={})",
            to, config.host, config.port, config.encryption
        );

        let prefer_ipv4 = PREFER_IPV4_SMTP.load(Ordering::Relaxed);
        let primary_connect_host = if prefer_ipv4 && !ipv4_addrs.is_empty() {
            ipv4_addrs[0].as_str()
        } else {
            config.host.as_str()
        };

        if prefer_ipv4 && primary_connect_host != config.host {
            info!(
                "Using cached IPv4 preference for SMTP route: {} (TLS host {})",
                primary_connect_host, config.host
            );
        }

        let primary_send =
            Self::send_via_host(config, &creds, &email, primary_connect_host, &config.host);
        if let Err(primary_error) = primary_send {
            let primary_error_str = primary_error.to_string();
            let should_try_ipv4_fallback = primary_connect_host == config.host
                && primary_error_str.contains("Network is unreachable")
                && ipv4_count > 0
                && ipv6_count > 0;

            if should_try_ipv4_fallback {
                PREFER_IPV4_SMTP.store(true, Ordering::Relaxed);
                let fallback_host = ipv4_addrs[0].clone();
                warn!(
                    "Primary SMTP send failed with ENETUNREACH; retrying via IPv4 {} with TLS host {}",
                    fallback_host, config.host
                );

                Self::send_via_host(config, &creds, &email, &fallback_host, &config.host)
                    .with_context(|| {
                        format!(
                            "SMTP send failed via {}:{} (encryption={}, ipv4={}, ipv6={})",
                            config.host, config.port, config.encryption, ipv4_count, ipv6_count
                        )
                    })?;
            } else {
                return Err(primary_error.context(format!(
                    "SMTP send failed via {}:{} (encryption={}, ipv4={}, ipv6={})",
                    config.host, config.port, config.encryption, ipv4_count, ipv6_count
                )));
            }
        }

        info!("SMTP delivery accepted for {}", to);
        Ok(())
    }
}

fn build_message(config: &MailConfig, to: &str, subject: &str, html: &str) -> Result<Message> {
    let sender = from_header(config);
    let from: Mailbox = sender
        .parse()
        .with_context(|| format!("Invalid SMTP From address '{}'", sender))?;
    let message_id = format!("<{}@{}>", uuid::Uuid::new_v4(), from.email.domain());

    Ok(Message::builder()
        .from(from)
        .to(to.parse().with_context(|| format!("Invalid To address '{}'", to))?)
        .subject(subject)
        .date_now()
        .message_id(Some(message_id))
        .multipart(MultiPart::alternative_plain_html(
            html_to_plain_text(html),
            html.to_string(),
        ))?)
}

impl SmtpController {
    fn send_via_host(
        config: &MailConfig,
        creds: &Credentials,
        email: &Message,
        connect_host: &str,
        tls_host: &str,
    ) -> Result<()> {
        let tls_params = TlsParameters::new(tls_host.to_string())?;
        let mut builder = SmtpTransport::builder_dangerous(connect_host)
            .credentials(creds.clone())
            .port(config.port)
            .timeout(Some(Duration::from_secs(30)));

        // lettre: Wrapper = implicit TLS (SMTPS / port 465), Required = STARTTLS (port 587).
        builder = match config.encryption.to_ascii_lowercase().as_str() {
            "ssl" => builder.tls(Tls::Wrapper(tls_params)),
            "starttls" | "tls" => builder.tls(Tls::Required(tls_params)),
            "none" | "off" | "false" => builder.tls(Tls::None),
            other => {
                warn!(
                    "Unknown SMTP encryption '{}', falling back to STARTTLS (Tls::Required)",
                    other
                );
                builder.tls(Tls::Required(tls_params))
            }
        };

        let mailer = builder.build();
        mailer.send(email)?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn smtp_message_has_both_parts_and_standard_headers() {
        let config = MailConfig {
            transport: "smtp".into(),
            host: "smtp.example.net".into(),
            port: 587,
            user: String::new(),
            pass: String::new(),
            from: "noreply@example.net".into(),
            from_name: "ServerPanel".into(),
            encryption: "starttls".into(),
            enabled: true,
            api_key: String::new(),
            api_domain: String::new(),
            api_region: String::new(),
        };

        let html = "<p>Hello <strong>world</strong></p>";
        let first = build_message(&config, "reader@example.org", "Panel mail", html).unwrap();
        let second = build_message(&config, "reader@example.org", "Panel mail", html).unwrap();
        let raw = String::from_utf8(first.formatted()).unwrap();
        let second_raw = String::from_utf8(second.formatted()).unwrap();
        for expected in [
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative",
            "Content-Type: text/plain",
            "Content-Type: text/html",
            "Date: ",
            "From: ServerPanel <noreply@example.net>",
            "Hello world",
            html,
        ] {
            assert!(raw.contains(expected), "missing {expected} in {raw}");
        }
        let message_id = raw.lines().find(|line| line.starts_with("Message-ID: <")).unwrap();
        assert!(message_id.ends_with("@example.net>"));
        assert!(!second_raw.contains(message_id));
    }
}
