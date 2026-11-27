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

use App\App;
use App\Chat\VmNode;
use App\Chat\Database;

final class VmGuestAccounts
{
    public const ADDITIONAL_LIMIT = 5;

    public static function connect(array $instance): ?GuestAccountClient
    {
        $nodeId = (int) $instance['vm_node_id'];
        $record = VmNode::getVmNodeById($nodeId);
        if (!$record) {
            return null;
        }

        $vmid = (int) $instance['vmid'];
        $client = VmInstanceUtil::buildProxmoxClientForNode($record);
        if ($instance['vm_type'] === 'lxc') {
            // Resolve the current host so the profile also works after a cluster migration.
            $node = $client->findNodeByVmid($vmid)['node'] ?? null;

            return is_string($node) ? LxcGuestAccess::connect($nodeId, $node, $vmid) : null;
        }

        $node = ($instance['pve_node'] ?? '') ?: ($client->findNodeByVmid($vmid)['node'] ?? null);

        return is_string($node) && $node !== '' ? new KvmGuestAccess($client, $node, $vmid) : null;
    }

    public static function defaultUsername(string $username): string
    {
        $name = substr(preg_replace('/[^a-z0-9]/', '', strtolower($username)), 0, 24);
        if ($name === '' || !ctype_alpha($name[0]) || in_array($name, ['root', 'daemon', 'bin', 'sys', 'sync', 'mail', 'nobody', 'wwwdata', 'systemd', 'sudo', 'wheel', 'admin', 'staff', 'users', 'ssh'], true)) {
            $name = 'user' . $name;
        }

        return $name;
    }

    public static function primaryUsername(string $preferred, array $accounts): string
    {
        if (isset($accounts[$preferred])) {
            return $preferred;
        }
        unset($accounts['root']);
        asort($accounts, SORT_NUMERIC);

        return $accounts === [] ? $preferred : (string) array_key_first($accounts);
    }

    public static function password(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        for ($index = 0; $index < 32; ++$index) {
            $result .= $alphabet[random_int(0, 35)];
        }

        return $result;
    }

    public static function passwordHash(string $password): string
    {
        $hash = crypt($password, '$6$' . bin2hex(random_bytes(8)) . '$');
        if (!str_starts_with($hash, '$6$')) {
            throw new \RuntimeException('Could not generate a Linux password hash');
        }

        return $hash;
    }

    public static function parseInventory(string $output): ?array
    {
        $result = ['guest_id' => '', 'accounts' => [], 'digests' => [], 'ssh_ports' => [], 'cloud_ready' => false];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^guest:([0-9a-f]{32})$/D', $line, $match)) {
                $result['guest_id'] = $match[1];
            } elseif ($line === 'cloud:ready') {
                $result['cloud_ready'] = true;
            } elseif (preg_match('/^port:([0-9]{1,5})$/D', $line, $match) && (int) $match[1] >= 1 && (int) $match[1] <= 65535) {
                $result['ssh_ports'][] = (int) $match[1];
            } elseif (preg_match('/^digest:([a-zA-Z0-9_][a-zA-Z0-9_.@-]{0,62}\$?):([0-9a-f]{64})$/D', $line, $match)) {
                $result['digests'][$match[1]] = $match[2];
            } elseif (preg_match('/^([0-9]{1,10}):([a-zA-Z0-9_][a-zA-Z0-9_.@-]{0,62}\$?)$/D', $line, $match)) {
                $result['accounts'][$match[2]] = (int) $match[1];
            }
        }
        if ($result['guest_id'] === '' || ($result['accounts']['root'] ?? null) !== 0) {
            return null;
        }
        $result['ssh_ports'] = array_values(array_unique($result['ssh_ports']));

        return $result;
    }

    public static function passwords(\PDO $db, array $vm, array $guest): array
    {
        $stmt = $db->prepare('SELECT username, guest_uid, password_digest, password_encrypted FROM featherpanel_vm_guest_passwords WHERE instance_id = ? AND owner_uuid = ? AND vmid = ? AND guest_id = ?');
        $stmt->execute([$vm['id'], $vm['user_uuid'] ?? '', $vm['vmid'], $guest['guest_id']]);
        $passwords = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($guest['accounts'][$row['username']] ?? null) === (int) $row['guest_uid']
                && hash_equals($row['password_digest'], $guest['digests'][$row['username']] ?? '')) {
                $passwords[$row['username']] = $row['password_encrypted'];
            }
        }

        return $passwords;
    }

    public static function managesLxcRootPassword(array $vm): bool
    {
        if (($vm['vm_type'] ?? null) !== 'lxc') {
            return false;
        }
        $params = [$vm['id'], $vm['user_uuid'] ?? '', $vm['vmid']];
        $stmt = Database::getPdoConnection()->prepare(
            "SELECT 1 FROM featherpanel_vm_guest_passwords WHERE instance_id = ? AND owner_uuid = ? AND vmid = ? AND username = 'root'
             UNION ALL
             SELECT 1 FROM featherpanel_vm_guest_setup WHERE instance_id = ? AND owner_uuid = ? AND vmid = ?
             LIMIT 1"
        );
        $stmt->execute([...$params, ...$params]);

        return (bool) $stmt->fetchColumn();
    }

    public static function savePassword(\PDO $db, array $vm, array $guest, string $username, string $password, string $hash): void
    {
        $encrypted = App::getInstance(true)->encryptValue($password);
        $db->prepare('INSERT INTO featherpanel_vm_guest_passwords (instance_id, owner_uuid, vmid, username, guest_id, guest_uid, password_digest, password_encrypted)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE owner_uuid = VALUES(owner_uuid), vmid = VALUES(vmid), guest_id = VALUES(guest_id),
                         guest_uid = VALUES(guest_uid), password_digest = VALUES(password_digest), password_encrypted = VALUES(password_encrypted)')
            ->execute([$vm['id'], $vm['user_uuid'] ?? '', $vm['vmid'], $username, $guest['guest_id'], $guest['accounts'][$username], hash('sha256', $hash), $encrypted]);
    }

    public static function lockInstance(\PDO $db, int $id, string $owner): ?array
    {
        $stmt = $db->prepare("SELECT * FROM featherpanel_vm_instances WHERE id = ? AND COALESCE(user_uuid, '') = ? AND vm_type IN ('qemu', 'lxc')
                            AND suspended = 0 AND status NOT IN ('creating', 'reinstalling', 'restoring', 'deleting', 'suspended') FOR UPDATE");
        $stmt->execute([$id, $owner]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function provisionPending(): void
    {
        $db = Database::getPdoConnection();
        $rows = $db->query("SELECT g.* FROM featherpanel_vm_guest_setup g
            INNER JOIN featherpanel_vm_instances i ON i.id = g.instance_id AND i.vmid = g.vmid AND i.user_uuid = g.owner_uuid
            WHERE g.retry_at <= NOW() AND i.status = 'running' AND i.suspended = 0 ORDER BY g.retry_at LIMIT 4")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $db->prepare('UPDATE featherpanel_vm_guest_setup SET retry_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE instance_id = ?')
                ->execute([$row['instance_id']]);
            try {
                self::provisionNewInstance($db, $row);
            } catch (\Throwable) {
                App::getInstance(true)->getLogger()->warning('Guest access setup failed for instance ' . (int) $row['instance_id']);
            }
        }
    }

    private static function provisionNewInstance(\PDO $db, array $row): void
    {
        $db->beginTransaction();
        try {
            $vm = self::lockInstance($db, (int) $row['instance_id'], $row['owner_uuid']);
            if ($vm === null || (int) $vm['vmid'] !== (int) $row['vmid']) {
                return;
            }
            $stmt = $db->prepare('SELECT username FROM featherpanel_vm_guest_setup WHERE instance_id = ? AND vmid = ? AND retry_at IS NOT NULL');
            $stmt->execute([$vm['id'], $vm['vmid']]);
            $username = $stmt->fetchColumn();
            if (!is_string($username)) {
                return;
            }
            $client = self::connect($vm);
            $guest = $client?->inspect();
            if (!$guest || ($vm['vm_type'] === 'qemu' && (!$guest['cloud_ready'] || !isset($guest['accounts'][$username])))) {
                return;
            }

            $saved = self::passwords($db, $vm, $guest);
            foreach ([$username, 'root'] as $account) {
                if (isset($saved[$account])) {
                    continue;
                }
                $password = self::password();
                $hash = self::passwordHash($password);
                if (isset($guest['accounts'][$account])) {
                    $changed = $client->setPassword($account, $hash);
                } else {
                    $changed = $client->createAccount($account, $hash);
                    $updated = $changed ? $client->inspect() : null;
                    $changed = $updated !== null && $updated['guest_id'] === $guest['guest_id']
                        && isset($updated['accounts'][$account]);
                    if ($changed) {
                        $guest = $updated;
                    }
                }
                if (!$changed) {
                    $db->commit();

                    return;
                }
                self::savePassword($db, $vm, $guest, $account, $password, $hash);
            }
            $db->prepare('UPDATE featherpanel_vm_guest_setup SET retry_at = NULL WHERE instance_id = ?')->execute([$vm['id']]);
            $db->commit();
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
}
