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

namespace App\Services\Updates;

final class GitHubReleaseChecker
{
    public function check(string $repository, string $installedVersion): array
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository)) {
            throw new \InvalidArgumentException('Invalid UPDATE_REPOSITORY value');
        }

        $installed = ltrim(trim($installedVersion), 'vV');
        $projectUrl = 'https://github.com/' . $repository;
        $result = [
            'source' => 'github',
            'current' => $this->versionDetails($installed, null, $projectUrl),
            'latest' => null,
            'update_available' => false,
            'last_checked' => date('c'),
            'runtime_php' => PHP_VERSION,
            'current_listed_on_update_server' => false,
            'project' => [
                'name' => 'FeatherPanel',
                'slug' => $repository,
                'github_url' => $projectUrl,
                'default_type' => 'stable',
            ],
        ];

        $releases = $this->fetchReleases($repository);
        if ($releases === null) {
            $result['check_error'] = 'GitHub releases could not be retrieved.';

            return $result;
        }

        $latest = self::latestStableRelease($releases);
        foreach ($releases as $release) {
            if (is_array($release) && self::stableVersion($release) === $installed) {
                $result['current'] = $this->versionDetails($installed, $release, $projectUrl);
                $result['current_listed_on_update_server'] = true;
                break;
            }
        }

        if ($latest !== null) {
            $latestVersion = self::stableVersion($latest);
            $result['latest'] = $this->versionDetails($latestVersion, $latest, $projectUrl);
            $result['update_available'] = (bool) preg_match('/^\d+\.\d+\.\d+$/D', $installed)
                && version_compare($installed, $latestVersion, '<');
        }

        return $result;
    }

    public static function latestStableRelease(array $releases): ?array
    {
        $latest = null;
        $latestVersion = null;
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $version = self::stableVersion($release);
            if ($version !== null && ($latestVersion === null || version_compare($version, $latestVersion, '>'))) {
                $latest = $release;
                $latestVersion = $version;
            }
        }

        return $latest;
    }

    private static function stableVersion(array $release): ?string
    {
        if (!empty($release['draft']) || !empty($release['prerelease'])) {
            return null;
        }
        $tag = (string) ($release['tag_name'] ?? '');

        return preg_match('/^v?(\d+\.\d+\.\d+)$/iD', $tag, $matches) ? $matches[1] : null;
    }

    private function fetchReleases(string $repository): ?array
    {
        $url = 'https://api.github.com/repos/' . $repository . '/releases?per_page=100';
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'FeatherPanel/' . (defined('APP_VERSION') ? APP_VERSION : 'unknown'),
                'header' => "Accept: application/vnd.github+json\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }
        $decoded = json_decode($response, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    private function versionDetails(string $version, ?array $release, string $projectUrl): array
    {
        $tag = (string) ($release['tag_name'] ?? '');

        return [
            'version' => $version,
            'type' => 'Stable',
            'release_name' => (string) (($release['name'] ?? '') ?: ($tag ?: 'FeatherPanel')),
            'release_description' => (string) ($release['body'] ?? ''),
            'description' => (string) ($release['body'] ?? ''),
            'php_version' => PHP_VERSION,
            'is_security_release' => false,
            'github_html_url' => $tag !== ''
                ? $projectUrl . '/releases/tag/' . rawurlencode($tag)
                : null,
            'published_at' => $release['published_at'] ?? null,
            'changelog_added' => [],
            'changelog_fixed' => [],
            'changelog_improved' => [],
            'changelog_updated' => [],
            'changelog_removed' => [],
        ];
    }
}
