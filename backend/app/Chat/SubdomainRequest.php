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

use App\App;
use App\Config\ConfigInterface;

final class SubdomainRequest
{
    public static function configuredSpells(): ?array
    {
        $raw = App::getInstance(true)->getConfig()->getSetting(ConfigInterface::SUBDOMAIN_REQUEST_SPELLS, '');
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::normalizeSpells(json_decode($raw, true)) ?? [];
    }

    public static function normalizeSpells(mixed $spells): ?array
    {
        if (!is_array($spells) || !array_is_list($spells)) {
            return null;
        }
        foreach ($spells as $id) {
            if (!is_int($id) || $id < 1) {
                return null;
            }
        }

        return array_values(array_unique($spells));
    }

    public static function allowsSpell(int $spellId): bool
    {
        $spells = self::configuredSpells();

        return $spells === null || in_array($spellId, $spells, true);
    }

    public static function mappedSpells(): array
    {
        return array_map('intval', Database::getPdoConnection()->query(
            'SELECT DISTINCT m.spell_id FROM featherpanel_subdomain_manager_domain_spells m
             JOIN featherpanel_subdomain_manager_domains d ON d.id = m.domain_id WHERE d.is_active = 1 ORDER BY m.spell_id'
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function forServer(int $serverId): array
    {
        $stmt = Database::getPdoConnection()->prepare(
            'SELECT r.id, r.subdomain, d.domain, r.status, r.reason, r.created_at, r.decided_at
             FROM featherpanel_subdomain_requests r
             JOIN featherpanel_subdomain_manager_domains d ON d.id = r.domain_id
             WHERE r.server_id = ? ORDER BY r.id DESC LIMIT 100'
        );
        $stmt->execute([$serverId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function pendingCount(int $serverId): int
    {
        $stmt = Database::getPdoConnection()->prepare("SELECT COUNT(*) FROM featherpanel_subdomain_requests WHERE server_id = ? AND status = 'pending'");
        $stmt->execute([$serverId]);

        return (int) $stmt->fetchColumn();
    }

    public static function listing(string $status, int $page): array
    {
        $db = Database::getPdoConnection();
        $filter = $status === 'all' ? '' : ' WHERE r.status = ?';
        $params = $filter === '' ? [] : [$status];
        $count = $db->prepare('SELECT COUNT(*) FROM featherpanel_subdomain_requests r' . $filter);
        $count->execute($params);
        $offset = (max(1, min(100000, $page)) - 1) * 50;
        $stmt = $db->prepare(
            'SELECT r.id, r.subdomain, r.status, r.reason, r.created_at, r.decided_at,
                    d.domain, s.name AS server_name, u.username AS requester
             FROM featherpanel_subdomain_requests r
             JOIN featherpanel_subdomain_manager_domains d ON d.id = r.domain_id
             JOIN featherpanel_servers s ON s.id = r.server_id
             LEFT JOIN featherpanel_users u ON u.uuid = r.requester_uuid'
            . $filter . ' ORDER BY r.id DESC LIMIT 50 OFFSET ' . $offset
        );
        $stmt->execute($params);

        return ['requests' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    public static function lockServer(\PDO $db, int $id): bool
    {
        $stmt = $db->prepare('SELECT id FROM featherpanel_servers WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public static function enqueue(int $serverId, int $domainId, string $label, string $requester, int $limit): int
    {
        $db = Database::getPdoConnection();
        $db->beginTransaction();
        try {
            if (!self::lockServer($db, $serverId)) {
                return 0;
            }
            if (Subdomain::countByServer($serverId) + self::pendingCount($serverId) >= $limit) {
                return -1;
            }
            if (Subdomain::getByDomainAndLabel($domainId, $label) !== null) {
                return 0;
            }
            $stmt = $db->prepare('INSERT INTO featherpanel_subdomain_requests (server_id, domain_id, requester_uuid, subdomain) VALUES (?, ?, ?, ?)');
            $stmt->execute([$serverId, $domainId, $requester, $label]);
            $id = (int) $db->lastInsertId();
            $db->commit();

            return $id;
        } catch (\PDOException $error) {
            if (($error->errorInfo[0] ?? '') !== '23000') {
                throw $error;
            }

            return 0;
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
}
