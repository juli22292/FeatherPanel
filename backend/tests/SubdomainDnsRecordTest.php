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

use App\Services\Subdomain\CloudflareSubdomainService;
use PHPUnit\Framework\TestCase;

final class SubdomainDnsRecordTest extends TestCase
{
    public function testNonSrvMappingsUseTheRecordTypeRequiredByEachDestination(): void
    {
        self::assertSame('A', CloudflareSubdomainService::recordType('192.0.2.12', null));
        self::assertSame('AAAA', CloudflareSubdomainService::recordType('2001:db8::12', null));
        self::assertSame('CNAME', CloudflareSubdomainService::recordType('node.example.com', null));
    }

    public function testConfiguredSrvServiceKeepsTheSrvRecordTypeWithBothTargets(): void
    {
        self::assertSame('SRV', CloudflareSubdomainService::recordType('192.0.2.12', '_minecraft'));
        self::assertSame('SRV', CloudflareSubdomainService::recordType('node.example.com', '_minecraft'));
    }
}
