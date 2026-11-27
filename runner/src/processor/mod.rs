use anyhow::Result;
use sqlx::MySqlPool;
use std::time::Duration;
use tracing::{error, info, warn};

use crate::database;
use crate::mail;
pub mod vm;
pub mod create;
pub mod cidr;

pub use vm::process_vm_task;

pub async fn process_mail(pool: &MySqlPool, queue_id: &str) -> Result<()> {
    info!("🔄 Processing mail queue_id: {}", queue_id);

    // Check MySQL connection health
    if let Err(e) = sqlx::query("SELECT 1").fetch_one(pool).await {
        error!("❌ MySQL connection lost: {}", e);
        anyhow::bail!("MySQL connection lost, will retry on reconnect");
    }

    // Acquire lock atomically to avoid duplicate processing across workers.
    if !database::try_lock_mail(pool, queue_id).await? {
        info!(
            "ℹ️  Mail {} already locked or no longer pending, skipping",
            queue_id
        );
        return Ok(());
    }

    // Get mail queue entry
    let mail_queue = match database::get_mail_queue(pool, queue_id).await? {
        Some(m) => m,
        None => {
            warn!("⚠️  Mail {} not found or not pending", queue_id);
            return Ok(());
        }
    };

    // Get mail list entry
    let mail_list = match database::get_mail_list(pool, queue_id).await? {
        Some(m) => m,
        None => {
            error!("❌ MailList entry not found for {}", queue_id);
            database::finish_mail(pool, queue_id, None, 0, Some("recipient_missing")).await?;
            return Ok(());
        }
    };

    // Get user email
    let user = match database::get_user(pool, &mail_list.user_uuid).await? {
        Some(u) => u,
        None => {
            error!("❌ User not found for uuid {}", mail_list.user_uuid);
            database::finish_mail(pool, queue_id, None, 0, Some("recipient_missing")).await?;
            return Ok(());
        }
    };

    // Get mail config (SMTP or HTTP API transport)
    let smtp_config = database::get_smtp_config(pool).await?;
    if !smtp_config.enabled {
        warn!("⚠️  Mail {} not sent because email is disabled", queue_id);
        database::finish_mail(pool, queue_id, None, 0, Some("mail_disabled")).await?;
        return Ok(());
    }
    if smtp_config.transport != "smtp" && smtp_config.api_key.trim().is_empty() {
        error!(
            "❌ Mail {} failed: mail_transport={} requires mail_api_key",
            queue_id, smtp_config.transport
        );
        database::finish_mail(pool, queue_id, None, 0, Some("api_key_missing")).await?;
        return Ok(());
    }

    // Send mail with retries
    let max_retries = 3;
    let mut attempts = 0;
    let mut failure = Some("transport_error".to_owned());

    for attempt in 1..=max_retries {
        attempts = attempt;
        info!(
            "📧 Attempt {}/{} sending to {}",
            attempt, max_retries, user.email
        );

        match tokio::time::timeout(
            Duration::from_secs(35),
            mail::send_email(&smtp_config, &user.email, &mail_queue.subject, &mail_queue.body),
        )
        .await
        {
            Ok(Ok(_)) => {
                failure = None;
                info!("✅ Mail sent to {}", user.email);
                break;
            }
            Ok(Err(e)) => {
                failure = Some(delivery_failure(&e));
                let err_str = format!("{:#}", e);
                let err_chain = format!("{:#}", e);
                error!(
                    "❌ Attempt {} failed for queue_id {}: {}",
                    attempt, queue_id, err_chain
                );

                let err_lower = err_str.to_lowercase();
                if err_lower.contains("permanent error") || err_lower.contains("550") {
                    warn!("⚠️ Fatal SMTP error encountered. Aborting retries for this mail.");
                    break;
                }

                if attempt < max_retries {
                    tokio::time::sleep(tokio::time::Duration::from_secs(2)).await;
                }
            }
            Err(_) => {
                failure = Some("timeout".into());
                error!(
                    "❌ Attempt {} timed out while sending mail {}",
                    attempt, queue_id
                );

                if attempt < max_retries {
                    tokio::time::sleep(tokio::time::Duration::from_secs(2)).await;
                }
            }
        }
    }

    database::finish_mail(
        pool,
        queue_id,
        Some(&smtp_config.transport),
        attempts,
        failure.as_deref(),
    ).await?;

    Ok(())
}

fn delivery_failure(error: &anyhow::Error) -> String {
    // Never persist raw SMTP or provider responses in the delivery log.
    if let Some(smtp) = error.downcast_ref::<lettre::transport::smtp::Error>() {
        if let Some(code) = smtp.status() {
            return format!("smtp_{code}");
        }
        if smtp.is_timeout() {
            return "timeout".into();
        }
        if smtp.is_tls() {
            return "smtp_tls".into();
        }
        return "smtp_connection".into();
    }
    if let Some(http) = error.downcast_ref::<reqwest::Error>() {
        if let Some(status) = http.status() {
            return format!("http_{}", status.as_u16());
        }
        if http.is_timeout() {
            return "timeout".into();
        }
    }
    "transport_error".into()
}
