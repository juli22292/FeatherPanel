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

use App\Services\Updates\GitHubReleaseChecker;
use PHPUnit\Framework\TestCase;

final class GitHubReleaseCheckerTest extends TestCase
{
    public function testLatestStableSemverIgnoresDraftsAndPrereleases(): void
    {
        $releases = [
            ['tag_name' => 'v1.5.0-rc.1', 'draft' => false, 'prerelease' => true],
            ['tag_name' => 'v1.4.9', 'draft' => true, 'prerelease' => false],
            ['tag_name' => 'v1.4.2', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v1.4.10', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'nightly', 'draft' => false, 'prerelease' => false],
        ];

        self::assertSame('v1.4.10', GitHubReleaseChecker::latestStableRelease($releases)['tag_name']);
    }

    public function testFourthComponentIsAStableUpdate(): void
    {
        $releases = [
            ['tag_name' => 'v1.4.3', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v1.4.3.1', 'draft' => false, 'prerelease' => false],
            ['tag_name' => 'v1.4.3.2', 'draft' => false, 'prerelease' => false],
        ];

        self::assertSame('v1.4.3.2', GitHubReleaseChecker::latestStableRelease($releases)['tag_name']);
    }

    public function testReturnsNoReleaseWhenOnlyPrereleasesExist(): void
    {
        self::assertNull(GitHubReleaseChecker::latestStableRelease([
            ['tag_name' => 'v2.0.0-beta.1', 'prerelease' => true],
        ]));
    }

    public function testRejectsInvalidRepositoryBeforeNetworkRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new GitHubReleaseChecker())->check('https://example.com/other', 'v1.4.1');
    }
}
