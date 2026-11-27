<?php

namespace App\Mail;

use App\Chat\MailTemplate;

/** The variables supplied by each built-in sender. */
final class MailTemplateCatalog
{
    private const COMMON = ['app_name', 'app_url', 'dashboard_url', 'email', 'first_name', 'last_name', 'support_url', 'username'];
    private const PLACEHOLDERS = [
        'account_created' => [...self::COMMON, 'password'],
        'account_deleted' => self::COMMON,
        'account_deletion_otp' => [...self::COMMON, 'expires_minutes', 'otp_code'],
        'account_deletion_scheduled' => [...self::COMMON, 'deletion_mode_label', 'scheduled_at'],
        'account_suspended' => [...self::COMMON, 'suspension_reason', 'suspension_time'],
        'account_unsuspended' => [...self::COMMON, 'unsuspend_time', 'unsuspension_time'],
        'api_client_ip_blocked' => [...self::COMMON, 'api_client_id', 'api_client_name', 'blocked_ip'],
        'email_login_code' => [...self::COMMON, 'expires_minutes', 'login_code'],
        'forgot_password' => [...self::COMMON, 'reset_url'],
        'mail_configuration_test' => ['app_name', 'app_url', 'email', 'time', 'username'],
        'node_alert_backup_failed' => ['app_name', 'error', 'error_detail', 'message', 'node_name', 'severity', 'state', 'time', 'title', 'url'],
        'node_alert_disk_full' => ['app_name', 'error', 'error_detail', 'message', 'node_name', 'severity', 'state', 'time', 'title', 'url'],
        'node_alert_node_down' => ['app_name', 'error', 'error_detail', 'message', 'node_name', 'severity', 'state', 'time', 'title', 'url'],
        'node_alert_test' => ['app_name', 'error', 'error_detail', 'message', 'node_name', 'severity', 'state', 'time', 'title', 'url'],
        'notification_backup_completed' => ['app_name', 'details', 'title', 'url'],
        'notification_backup_failed' => ['app_name', 'details', 'title', 'url'],
        'notification_file_transfer_completed' => ['app_name', 'details', 'title', 'url'],
        'notification_file_transfer_failed' => ['app_name', 'details', 'title', 'url'],
        'notification_resource_limit' => ['app_name', 'details', 'title', 'url'],
        'notification_server_crashed' => ['app_name', 'details', 'title', 'url'],
        'notification_storage_disconnected' => ['app_name', 'details', 'title', 'url'],
        'notification_transfer_power_failed' => ['app_name', 'details', 'title', 'url'],
        'notification_vds_backup_failed' => ['app_name', 'details', 'title', 'url'],
        'notification_vds_bandwidth' => ['app_name', 'details', 'title', 'url'],
        'notification_vds_down' => ['app_name', 'details', 'title', 'url'],
        'notification_vds_expiring' => ['app_name', 'details', 'title', 'url'],
        'notification_vds_high_cpu' => ['app_name', 'details', 'title', 'url'],
        'notification_webspace_backup_completed' => ['app_name', 'details', 'title', 'url'],
        'notification_webspace_backup_failed' => ['app_name', 'details', 'title', 'url'],
        'notification_webspace_install_failed' => ['app_name', 'details', 'title', 'url'],
        'notification_webspace_installed' => ['app_name', 'details', 'title', 'url'],
        'notification_webspace_limit' => ['app_name', 'details', 'title', 'url'],
        'notification_webspace_malware' => ['app_name', 'details', 'title', 'url'],
        'security_new_login' => ['app_name', 'device', 'ip_address', 'time', 'username'],
        'server_created' => [...self::COMMON, 'panel_url', 'server_ip', 'server_name'],
        'server_deleted' => [...self::COMMON, 'deletion_time', 'server_name'],
        'server_inactive_archive' => ['app_name', 'days', 'due_at', 'server_name', 'url', 'username'],
        'server_inactive_delete' => ['app_name', 'days', 'due_at', 'server_name', 'url', 'username'],
        'server_inactive_notify' => ['app_name', 'days', 'due_at', 'server_name', 'url', 'username'],
        'server_inactive_suspend' => ['app_name', 'days', 'due_at', 'server_name', 'url', 'username'],
        'server_suspended' => [...self::COMMON, 'server_name', 'suspension_reason'],
        'server_unsuspended' => [...self::COMMON, 'server_name', 'server_url'],
        'ticket_admin_alert' => [...self::COMMON, 'event_label', 'open_tickets_count', 'ticket_owner', 'ticket_title', 'ticket_url'],
        'ticket_closed' => [...self::COMMON, 'ticket_title', 'ticket_url'],
        'ticket_reopened' => [...self::COMMON, 'ticket_title', 'ticket_url'],
        'ticket_replied' => [...self::COMMON, 'replier_name', 'reply_preview', 'ticket_title', 'ticket_url'],
        'user_warning' => [...self::COMMON, 'action_url', 'server_name', 'server_name_clause', 'warning_message', 'warning_title'],
        'verify_email' => [...self::COMMON, 'verify_url'],
        'vm_created' => [...self::COMMON, 'vm_hostname', 'vm_ip', 'vm_password'],
        'vm_deleted' => [...self::COMMON, 'vm_hostname', 'vm_ip', 'vm_password'],
        'vm_reinstall_completed' => [...self::COMMON, 'vm_hostname', 'vm_ip', 'vm_password'],
        'vm_suspended' => [...self::COMMON, 'suspension_reason', 'vm_hostname'],
        'vm_unsuspended' => [...self::COMMON, 'vm_hostname'],
    ];
    private const REQUIRED_BODY = [
        'account_deletion_otp' => 'otp_code',
        'email_login_code' => 'login_code',
        'forgot_password' => 'reset_url',
        'verify_email' => 'verify_url',
    ];
    private const SENSITIVE = ['otp_code', 'login_code', 'password', 'vm_password', 'reset_url', 'verify_url'];
    private const RAW_HTML = [
        'node_alert_backup_failed' => ['error_detail'],
        'node_alert_disk_full' => ['error_detail'],
        'node_alert_node_down' => ['error_detail'],
        'node_alert_test' => ['error_detail'],
        'notification_backup_completed' => ['details'],
        'notification_backup_failed' => ['details'],
        'notification_file_transfer_completed' => ['details'],
        'notification_file_transfer_failed' => ['details'],
        'notification_resource_limit' => ['details'],
        'notification_server_crashed' => ['details'],
        'notification_storage_disconnected' => ['details'],
        'notification_transfer_power_failed' => ['details'],
        'notification_vds_backup_failed' => ['details'],
        'notification_vds_bandwidth' => ['details'],
        'notification_vds_down' => ['details'],
        'notification_vds_expiring' => ['details'],
        'notification_vds_high_cpu' => ['details'],
        'notification_webspace_backup_completed' => ['details'],
        'notification_webspace_backup_failed' => ['details'],
        'notification_webspace_install_failed' => ['details'],
        'notification_webspace_installed' => ['details'],
        'notification_webspace_limit' => ['details'],
        'notification_webspace_malware' => ['details'],
        'ticket_replied' => ['reply_preview'],
        'user_warning' => ['server_name_clause'],
    ];

    public static function isBuiltIn(string $name): bool
    {
        return isset(self::PLACEHOLDERS[$name]);
    }

    public static function describe(array $template): array
    {
        $name = $template['name'];
        $template['built_in'] = self::isBuiltIn($name);
        $template['placeholders'] = self::PLACEHOLDERS[$name] ?? [];
        $template['required_body_placeholder'] = self::REQUIRED_BODY[$name] ?? null;

        return $template;
    }

    public static function issues(string $name, string $subject, string $body): array
    {
        if (!self::isBuiltIn($name)) {
            return [];
        }

        $issues = [];
        preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $subject . "\n" . $body, $tokens);
        $unknown = array_values(array_diff(array_unique($tokens[1]), self::PLACEHOLDERS[$name]));
        if ($unknown !== []) {
            $issues[] = 'Unknown placeholders: {' . implode('}, {', $unknown) . '}';
        }
        preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $subject, $subjectTokens);
        if (array_intersect($subjectTokens[1], self::SENSITIVE) !== []) {
            $issues[] = 'Use links, passwords, and one-time codes in the message body only.';
        }
        $required = self::REQUIRED_BODY[$name] ?? null;
        if ($required !== null && !str_contains($body, '{' . $required . '}')) {
            $issues[] = 'The message body requires {' . $required . '}.';
        }

        return $issues;
    }

    /** Render a stored template using the same escaping rules as existing senders. */
    public static function render(string $name, array $values): array
    {
        $template = MailTemplate::getByName($name) ?? throw new \RuntimeException("Missing mail template $name.");

        return self::format($name, $template['subject'], $template['body'], $values);
    }

    /** Use synthetic data exclusively, including for credentials and one-time codes. */
    public static function preview(string $name, string $subject, string $body, string $appName): array
    {
        $values = [];
        foreach (self::PLACEHOLDERS[$name] ?? [] as $key) {
            $values[$key] = match (true) {
                $key === 'app_name' => $appName,
                $key === 'email' => 'alex@example.test',
                $key === 'username' => 'alex',
                $key === 'first_name' => 'Alex',
                $key === 'last_name' => 'Example',
                in_array($key, ['ip_address', 'server_ip', 'vm_ip', 'blocked_ip'], true) => '192.0.2.10',
                in_array($key, ['login_code', 'otp_code'], true) => '123456',
                $key === 'password' || $key === 'vm_password' => 'example-test-password',
                str_ends_with($key, 'url') => 'https://example.test/' . str_replace('_', '-', $key),
                in_array($key, self::RAW_HTML[$name] ?? [], true) => '<p>Example ' . str_replace('_', ' ', $key) . '</p>',
                $key === 'expires_minutes' => '10',
                str_contains($key, 'time') || str_ends_with($key, '_at') => '2026-01-01 12:00',
                default => ucfirst(str_replace('_', ' ', $key)) . ' example',
            };
        }

        return self::format($name, $subject, $body, $values);
    }

    private static function format(string $name, string $subject, string $body, array $values): array
    {
        $raw = self::RAW_HTML[$name] ?? [];

        return [
            'subject' => trim(preg_replace('/[\r\n]+/', ' ', MailTemplateRenderer::renderText($subject, $values, $raw))),
            'body' => MailTemplateRenderer::renderHtml($body, $values, $raw),
        ];
    }
}
