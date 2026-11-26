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

namespace App\Services\Vm;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

final class LxcGuestAccess implements GuestAccountClient
{
    private const MAX_OUTPUT = 65536;
    private ?SSH2 $ssh = null;
    private ?array $inventory = null;

    private function __construct(private array $profile, private int $vmid)
    {
    }

    public static function isConfigured(int $nodeId, string $pveNode): bool
    {
        return self::profile($nodeId, $pveNode) !== null;
    }

    public static function connect(int $nodeId, string $pveNode, int $vmid): ?self
    {
        $profile = self::profile($nodeId, $pveNode);

        return $profile === null || $vmid < 100 || $vmid > 999999999 ? null : new self($profile, $vmid);
    }

    private static function profile(int $nodeId, string $pveNode): ?array
    {
        $directory = APP_STORAGE_DIR . 'config/lxc-guest-access/';
        $file = $directory . 'nodes.json';
        if (!is_readable($file)) {
            return null;
        }
        $raw = @file_get_contents($file, false, null, 0, self::MAX_OUTPUT);
        $configuration = is_string($raw) ? json_decode($raw, true) : null;
        $profile = $configuration[$nodeId][$pveNode] ?? null;
        if (!is_array($profile)) {
            return null;
        }
        $host = $profile['host'] ?? '';
        $identity = $profile['identity'] ?? '';
        $fingerprint = $profile['host_fingerprint'] ?? '';
        $port = $profile['port'] ?? 22;
        if (!is_string($host) || (filter_var($host, FILTER_VALIDATE_IP) === false && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/D', $host))
            || !is_string($identity) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $identity)
            || !is_string($fingerprint) || !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $fingerprint)
            || !is_int($port) || $port < 1 || $port > 65535
            || !is_readable($directory . $identity)) {
            return null;
        }

        return ['host' => $host, 'port' => $port, 'host_fingerprint' => $fingerprint, 'identity' => $directory . $identity];
    }

    public function inspect(): ?array
    {
        $output = $this->request('inventory');
        $this->inventory = $output === null ? null : VmGuestAccounts::parseInventory($output);

        return $this->inventory;
    }

    public function setPassword(string $username, string $hash): bool
    {
        $uid = $this->inventory['accounts'][$username] ?? null;

        return $uid !== null && $this->request('reset', [
            'guest_id' => $this->inventory['guest_id'],
            'username' => $username,
            'uid' => $uid,
            'hash' => $hash,
        ]) === "ok\n";
    }

    public function createAccount(string $username, string $hash): bool
    {
        return $this->inventory !== null && !isset($this->inventory['accounts'][$username]) && $this->request('add', [
            'guest_id' => $this->inventory['guest_id'],
            'username' => $username,
            'hash' => $hash,
        ]) === "ok\n";
    }

    private function request(string $operation, array $fields = []): ?string
    {
        try {
            if ($this->ssh === null) {
                $host = $this->profile['host'];
                $ssh = new SSH2(str_contains($host, ':') ? '[' . $host . ']' : $host, $this->profile['port'], 9);
                $ssh->setPreferredAlgorithms(['hostkey' => ['ssh-ed25519']]);
                // The connector's response is stdout. SSH login-shell warnings belong to stderr.
                $ssh->enableQuietMode();
                $publicKey = $ssh->getServerPublicHostKey();
                $encodedKey = is_string($publicKey) ? (explode(' ', $publicKey, 3)[1] ?? '') : '';
                $blob = base64_decode($encodedKey, true);
                if (!is_string($blob) || $blob === ''
                    || !hash_equals($this->profile['host_fingerprint'], 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='))) {
                    return null;
                }

                $key = @file_get_contents($this->profile['identity'], false, null, 0, self::MAX_OUTPUT);
                if (!is_string($key) || !$ssh->login('root', PublicKeyLoader::loadPrivateKey($key))) {
                    return null;
                }
                $this->ssh = $ssh;
            }

            $payload = json_encode(['vmid' => $this->vmid, 'operation' => $operation] + $fields, JSON_THROW_ON_ERROR);
            $output = $this->ssh->exec('serverpanel-lxc-v1 ' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '='));

            return is_string($output) && strlen($output) <= self::MAX_OUTPUT && !$this->ssh->isTimeout() && $this->ssh->getExitStatus() === 0
                ? $output : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
