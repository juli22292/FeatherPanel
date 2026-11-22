<?php

declare(strict_types=1);

use App\Helpers\ServerSubdomainRequirementHelper;
use App\Services\Servers\ReverseProxyAddresses;
use PHPUnit\Framework\TestCase;

final class ReverseProxyAddressesTest extends TestCase
{
    public function testRulesMatchOnlyTheirAllocationIpAndSpells(): void
    {
        $rules = ReverseProxyAddresses::normalizeRules([
            ['allocation_ip' => '10.0.0.20', 'public_ip' => '203.0.113.10', 'spell_ids' => [12]],
            ['allocation_ip' => '10.0.0.20', 'public_ip' => '203.0.113.11', 'spell_ids' => [34]],
            ['allocation_ip' => 'fd00:0:0:0:0:0:0:20', 'public_ip' => '2001:db8::10', 'spell_ids' => []],
        ]);
        self::assertNotNull($rules);
        self::assertSame('203.0.113.10', ReverseProxyAddresses::publicIp('10.0.0.20', 12, $rules));
        self::assertSame('203.0.113.11', ReverseProxyAddresses::publicIp('10.0.0.20', 34, $rules));
        self::assertNull(ReverseProxyAddresses::publicIp('10.0.0.20', 56, $rules));
        self::assertNull(ReverseProxyAddresses::publicIp('10.0.0.21', 12, $rules));
        self::assertSame('2001:db8::10', ReverseProxyAddresses::publicIp('fd00::20', 56, $rules));
    }

    public function testInvalidAddressesAndOverlappingSpellSelectionsAreRejected(): void
    {
        $rule = ['allocation_ip' => '10.0.0.20', 'public_ip' => '203.0.113.10', 'spell_ids' => [12]];
        self::assertNull(ReverseProxyAddresses::normalizeRules([$rule, $rule]));
        self::assertNull(ReverseProxyAddresses::normalizeRules([$rule, [...$rule, 'spell_ids' => []]]));
        self::assertNull(ReverseProxyAddresses::normalizeRules([[...$rule, 'public_ip' => 'example.com:25565']]));
        self::assertNull(ReverseProxyAddresses::normalizeRules([[...$rule, 'spell_ids' => ['12']]]));
    }

    public function testSubdomainEnforcementAlsoHidesThePublicProxyIp(): void
    {
        $allocation = ['ip' => '10.0.0.20', 'public_ip' => '203.0.113.10', 'port' => 25565];
        $redacted = ServerSubdomainRequirementHelper::redactAllocation($allocation);
        self::assertArrayNotHasKey('public_ip', $redacted);
        self::assertNull($redacted['ip']);
        self::assertSame(25565, $redacted['port']);
    }
}
