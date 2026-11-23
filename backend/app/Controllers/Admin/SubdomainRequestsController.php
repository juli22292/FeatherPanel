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

namespace App\Controllers\Admin;

use App\App;
use App\Chat\Spell;
use App\Chat\Server;
use App\Chat\Database;
use App\Helpers\ApiResponse;
use App\Chat\SubdomainDomain;
use App\Chat\SubdomainRequest;
use App\Config\ConfigInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Controllers\User\Server\SubdomainController;

final class SubdomainRequestsController
{
    public function index(Request $request): Response
    {
        $status = $request->query->getString('status', 'pending');
        if (!in_array($status, ['all', 'pending', 'approved', 'rejected'], true)) {
            return ApiResponse::error('Invalid request status', 'VALIDATION_FAILED', 422);
        }

        return ApiResponse::success(SubdomainRequest::listing($status, $request->query->getInt('page', 1)) + [
            'allowed_spell_ids' => SubdomainRequest::configuredSpells() ?? SubdomainRequest::mappedSpells(),
            'spells' => array_map(static fn (array $spell) => ['id' => (int) $spell['id'], 'name' => $spell['name']], Spell::getAllSpells()),
        ]);
    }

    public function updateSpells(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $ids = SubdomainRequest::normalizeSpells($body['spell_ids'] ?? null);
        $available = array_map(static fn (array $spell) => (int) $spell['id'], Spell::getAllSpells());
        if ($ids === null || array_diff($ids, $available) !== []) {
            return ApiResponse::error('Choose valid server spells', 'VALIDATION_FAILED', 422);
        }

        App::getInstance(true)->getConfig()->setSetting(ConfigInterface::SUBDOMAIN_REQUEST_SPELLS, json_encode($ids, JSON_THROW_ON_ERROR));

        return ApiResponse::success(['allowed_spell_ids' => $ids]);
    }

    public function decide(Request $request, int $id): Response
    {
        $body = json_decode($request->getContent(), true);
        $decision = $body['decision'] ?? null;
        $reason = $body['reason'] ?? null;
        if (!in_array($decision, ['approved', 'rejected'], true) || ($reason !== null && (!is_string($reason) || mb_strlen(trim($reason)) > 1000))) {
            return ApiResponse::error('Choose a decision and use at most 1000 characters for the reason', 'VALIDATION_FAILED', 422);
        }
        $reason = is_string($reason) ? trim($reason) : null;
        $reviewer = $request->attributes->get('user');

        $db = Database::getPdoConnection();
        $lookup = $db->prepare('SELECT server_id FROM featherpanel_subdomain_requests WHERE id = ?');
        $lookup->execute([$id]);
        $serverId = (int) $lookup->fetchColumn();

        $db->beginTransaction();
        try {
            if (!$serverId || !SubdomainRequest::lockServer($db, $serverId)) {
                return ApiResponse::error('Subdomain request not found', 'SUBDOMAIN_NOT_FOUND', 404);
            }
            $stmt = $db->prepare('SELECT * FROM featherpanel_subdomain_requests WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return ApiResponse::error('Subdomain request not found', 'SUBDOMAIN_NOT_FOUND', 404);
            }
            if ($row['status'] === $decision) {
                return ApiResponse::success(['status' => $decision, 'reason' => $row['reason']]);
            }
            if ($row['status'] !== 'pending') {
                return ApiResponse::error('This subdomain request has already been reviewed', 'SUBDOMAIN_REQUEST_REVIEWED', 409);
            }

            $db->prepare('UPDATE featherpanel_subdomain_requests SET status = ?, reason = ?, reviewer_uuid = ?, decided_at = NOW() WHERE id = ?')
                ->execute([$decision, $reason === '' ? null : $reason, $reviewer['uuid'] ?? null, $id]);

            if ($decision === 'approved') {
                $server = Server::getServerById($serverId);
                $domain = SubdomainDomain::getDomainById((int) $row['domain_id']);
                if (!$server || !$domain) {
                    return ApiResponse::error('Server or domain unavailable', 'DOMAIN_NOT_AVAILABLE', 404);
                }

                $result = (new SubdomainController())->provisionApproved($request, $server, $domain['uuid'], $row['subdomain']);
                if ($result->getStatusCode() !== Response::HTTP_CREATED) {
                    return $result;
                }
            }

            $db->commit();

            if ($decision === 'approved') {
                try {
                    (new SubdomainController())->notifyApproved($request, $server, $domain, $row['subdomain']);
                } catch (\Throwable $error) {
                    App::getInstance(true)->getLogger()->error('Could not notify listeners of approved subdomain: ' . $error->getMessage());
                }
            }

            return ApiResponse::success(['status' => $decision, 'reason' => $reason]);
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
}
