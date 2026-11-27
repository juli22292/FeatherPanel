<?php

namespace App\Tests\Mail;

use App\Mail\MailTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class MailTemplateCatalogTest extends TestCase
{
    public function testPreviewRendersHtmlWithEscapingAndSyntheticValues(): void
    {
        $preview = MailTemplateCatalog::preview(
            'email_login_code',
            '{app_name} sign-in',
            '<p>{app_name}: {login_code}</p>',
            'Panel & Admin',
        );

        self::assertSame('Panel & Admin sign-in', $preview['subject']);
        self::assertSame('<p>Panel &amp; Admin: 123456</p>', $preview['body']);
    }

    public function testValidationFindsUnknownAndMissingVariables(): void
    {
        $issues = MailTemplateCatalog::issues('forgot_password', '{reset_url}', '<p>{wrong_url}</p>');

        self::assertCount(3, $issues);
        self::assertSame([], MailTemplateCatalog::issues('forgot_password', '{app_name}', '<a href="{reset_url}">Reset</a>'));
    }

    public function testBuiltInFallbackTemplatesUseVariablesDeclaredByTheirSenders(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../storage/migrations/2026-11-27.00.00-panel-mail-templates.sql');
        preg_match_all("/^    \\('([a-z_]+)', '([^']*)', '([^']*)'\\)/m", $sql, $seeds, PREG_SET_ORDER);
        self::assertGreaterThan(48, count($seeds));
        foreach ($seeds as [, $name, $subject, $body]) {
            self::assertTrue(MailTemplateCatalog::isBuiltIn($name), $name);
            self::assertSame([], MailTemplateCatalog::issues($name, $subject, $body), $name);
        }
    }
}
