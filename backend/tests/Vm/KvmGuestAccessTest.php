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

namespace Tests\Vm;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use App\Services\Vm\KvmGuestAccess;
use App\Services\Proxmox\Proxmox;
use Symfony\Component\HttpFoundation\Request;
use App\Controllers\User\Vds\VmUserGuestAccessController;
use GuzzleHttp\Psr7\Response as HttpResponse;

final class KvmGuestAccessTest extends TestCase
{
    public function testReadOnlyInventoryListsExistingAccountsAndConfiguredPorts(): void
    {
        $digest = str_repeat('d', 64);
        $inventory = 'guest:' . str_repeat('a', 32) . "\n0:root\n1000:existing.user\n1001:files\n"
            . "port:2222\nport:2222\nport:0\ndigest:existing.user:$digest\n";
        $history = [];
        $guest = $this->guest([
            new HttpResponse(200, [], '{"data":{"pid":47}}'),
            new HttpResponse(200, [], json_encode(['data' => ['exited' => 1, 'exitcode' => 0, 'out-data' => $inventory]], JSON_THROW_ON_ERROR)),
        ], $history);

        $result = $guest->inspect();
        self::assertSame(['root' => 0, 'existing.user' => 1000, 'files' => 1001], $result['accounts']);
        self::assertSame([2222], $result['ssh_ports']);
        self::assertSame(['existing.user' => $digest], $result['digests']);
        self::assertFalse($result['cloud_ready']);
        self::assertCount(2, $history);
        self::assertSame('/api2/json/nodes/node/qemu/104/agent/exec', $history[0]['request']->getUri()->getPath());
        $body = (string) $history[0]['request']->getBody();
        self::assertStringStartsWith('command=%2Fbin%2Fsh&command=-c&command=', $body);
        self::assertStringNotContainsString('input-data=', $body);
        self::assertStringNotContainsString('chpasswd', rawurldecode($body));
        self::assertSame('/api2/json/nodes/node/qemu/104/agent/exec-status', $history[1]['request']->getUri()->getPath());
    }

    public function testPasswordMaterialUsesSeparateRandomLinuxPasswordsAndHashes(): void
    {
        $password = KvmGuestAccess::password();
        self::assertMatchesRegularExpression('/^[a-z0-9]{32}$/D', $password);
        self::assertNotSame($password, KvmGuestAccess::password());
        $hash = KvmGuestAccess::passwordHash($password);
        self::assertStringStartsWith('$6$', $hash);
        self::assertSame($hash, crypt($password, $hash));
        self::assertSame('juliaexample', KvmGuestAccess::defaultUsername('Julia.Example'));
        self::assertNotSame('root', KvmGuestAccess::defaultUsername('ROOT'));
    }

    public function testAccountPasswordsAreSentAsHashesThroughTheGuestAgent(): void
    {
        $history = [];
        $guest = $this->guest([new HttpResponse(200, [], '{"data":{"result":{}}}')], $history);
        $password = KvmGuestAccess::password();
        $hash = KvmGuestAccess::passwordHash($password);
        self::assertTrue($guest->setPassword('existing.user', $hash));
        self::assertCount(1, $history);
        self::assertSame('/api2/json/nodes/node/qemu/104/agent/set-user-password', $history[0]['request']->getUri()->getPath());
        $payload = (string) $history[0]['request']->getBody();
        self::assertStringNotContainsString($password, $payload);
        parse_str($payload, $fields);
        self::assertSame(['username' => 'existing.user', 'password' => $hash, 'crypted' => '1'], $fields);
    }

    public function testSubusersCannotListAccountsOrResetPasswords(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $request = Request::create('/api/user/vm-instances/104/guest-access', $method);
            $request->attributes->set('user', ['uuid' => 'subuser']);
            $request->attributes->set('vmInstance', ['id' => 104, 'user_uuid' => 'owner', 'vm_type' => 'qemu']);
            self::assertSame(403, (new VmUserGuestAccessController())->handle($request)->getStatusCode());
        }
    }

    private function guest(array $responses, array &$history): KvmGuestAccess
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));
        $client = new Proxmox('pve.example.test', 8006, 'https', 'test@pve', 'test', 'placeholder', false);
        (new \ReflectionProperty(Proxmox::class, 'client'))->setValue($client, new Client([
            'base_uri' => 'https://pve.example.test:8006',
            'handler' => $handler,
        ]));

        return new KvmGuestAccess($client, 'node', 104);
    }
}
