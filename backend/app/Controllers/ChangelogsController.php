<?php

/*
 * This file is part of FeatherPanel.
 *
 * Copyright (C) 2025 MythicalSystems Studios
 * Copyright (C) 2025 FeatherPanel Contributors
 * Copyright (C) 2025 Cassian Gherman (aka NaysKutzu)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See the LICENSE file or <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Config\ConfigInterface;
use App\Helpers\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ChangelogsController
{
    private const NOTES_PREFIX = 'changelog_notes_';

    public function show(bool $admin = false): Response
    {
        $config = App::getInstance(true)->getConfig();
        $enabled = $config->getSetting(ConfigInterface::CHANGELOGS_ENABLED, 'true') === 'true';
        $releases = [];
        if ($admin || $enabled) {
            foreach (self::releases() as $release) {
                $version = $release['version'];
                $override = $config->getSetting(self::NOTES_PREFIX . $version, null);
                $release['body'] = $override ?? $release['body'];
                if ($admin) {
                    $release['edited'] = $override !== null;
                }
                $releases[] = $release;
            }
        }

        return ApiResponse::success(['enabled' => $enabled, 'releases' => $releases]);
    }

    public function setEnabled(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $enabled = $body['enabled'] ?? null;
        if (!is_bool($enabled)) {
            return ApiResponse::error('The enabled setting must be true or false', 'VALIDATION_FAILED', 422);
        }

        $config = App::getInstance(true)->getConfig();
        if (!$config->setSetting(ConfigInterface::CHANGELOGS_ENABLED, $enabled ? 'true' : 'false')) {
            return ApiResponse::error('Could not save changelog settings', 'CHANGELOG_SAVE_FAILED', 500);
        }

        return $this->show(true);
    }

    public function saveNotes(Request $request, string $version): Response
    {
        if (!array_key_exists($version, self::releases())) {
            return ApiResponse::error('Release not found', 'CHANGELOG_RELEASE_NOT_FOUND', 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !array_key_exists('body', $payload)) {
            return ApiResponse::error('Release notes are required', 'VALIDATION_FAILED', 422);
        }
        $body = $payload['body'];
        // Cap bytes for the encrypted settings column, including multi-byte notes.
        if ($body !== null && (!is_string($body) || strlen($body) > 20000 || trim($body) === '')) {
            return ApiResponse::error('Enter up to 20,000 bytes of release notes', 'VALIDATION_FAILED', 422);
        }

        if (!App::getInstance(true)->getConfig()->setSetting(self::NOTES_PREFIX . $version, $body)) {
            return ApiResponse::error('Could not save changelog settings', 'CHANGELOG_SAVE_FAILED', 500);
        }

        return $this->show(true);
    }

    /**
     * @return array<string, array{version: string, published_at: string, channel: string, body: string, url: string}>
     */
    private static function releases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../Services/Updates/fork-changelogs.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
