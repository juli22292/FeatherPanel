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

namespace App\Chat;

final class ManagedProxy
{
    public static function getByServer(int $serverId): array
    {
        $stmt = Database::getPdoConnection()->prepare('SELECT * FROM featherpanel_managed_proxies WHERE server_id = ? ORDER BY id DESC');
        $stmt->execute([$serverId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function hasDomain(string $domain): bool
    {
        $stmt = Database::getPdoConnection()->prepare('SELECT 1 FROM featherpanel_managed_proxies WHERE domain = ?');
        $stmt->execute([$domain]);

        return (bool) $stmt->fetchColumn();
    }

    public static function reserve(int $serverId, array $allocation, string $domain, int $limit): ?int
    {
        $pdo = Database::getPdoConnection();
        $pdo->beginTransaction();
        try {
            $server = $pdo->prepare('SELECT id FROM featherpanel_servers WHERE id = ? FOR UPDATE');
            $server->execute([$serverId]);
            if (!$server->fetchColumn()) {
                return null;
            }

            $existing = $pdo->prepare('SELECT id, server_id, allocation_id FROM featherpanel_managed_proxies WHERE domain = ?');
            $existing->execute([$domain]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return (int) $row['server_id'] === $serverId && (int) $row['allocation_id'] === (int) $allocation['id']
                    ? (int) $row['id']
                    : null;
            }

            $legacy = $pdo->prepare('SELECT 1 FROM featherpanel_server_proxies WHERE domain = ? LIMIT 1');
            $legacy->execute([$domain]);
            if ($legacy->fetchColumn() || count(self::getByServer($serverId)) + Proxy::countByServer($serverId) >= $limit) {
                return null;
            }

            $insert = $pdo->prepare('INSERT INTO featherpanel_managed_proxies (server_id, allocation_id, domain, ip, port) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$serverId, $allocation['id'], $domain, $allocation['ip'], $allocation['port']]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();

            return $id;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                return null;
            }

            throw $e;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public static function withLockedProxy(int $serverId, int $id, callable $operation, bool $delete = false): ?array
    {
        $pdo = Database::getPdoConnection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM featherpanel_managed_proxies WHERE id = ? AND server_id = ? FOR UPDATE');
            $stmt->execute([$id, $serverId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $result = $operation($row);
            if ($delete) {
                $pdo->prepare('DELETE FROM featherpanel_managed_proxies WHERE id = ?')->execute([$id]);
            }
            $pdo->commit();

            return $result;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
