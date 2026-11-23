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

namespace App\Services\Chatbot\Tools;

use App\Chat\Server;
use App\Helpers\ServerGateway;
use App\Controllers\User\Server\SubdomainController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tool to request a subdomain for a server.
 */
class CreateSubdomainTool implements ToolInterface
{
    public function execute(array $params, array $user, array $pageContext = []): mixed
    {
        // Get server identifier
        $serverIdentifier = $params['server_uuid'] ?? $params['server_name'] ?? null;
        $server = null;

        // If no identifier provided, try to get server from pageContext
        if (!$serverIdentifier && isset($pageContext['server'])) {
            $contextServer = $pageContext['server'];
            $serverUuidShort = $contextServer['uuidShort'] ?? null;

            if ($serverUuidShort) {
                $server = Server::getServerByUuidShort($serverUuidShort);
            }
        }

        // Resolve server if identifier provided
        if ($serverIdentifier && !$server) {
            $server = Server::getServerByUuid($serverIdentifier);

            if (!$server) {
                $server = Server::getServerByUuidShort($serverIdentifier);
            }

            if (!$server) {
                $servers = Server::searchServers(
                    page: 1,
                    limit: 10,
                    search: $serverIdentifier,
                    ownerId: $user['id']
                );
                if (!empty($servers)) {
                    $server = $servers[0];
                }
            }
        }

        if (!$server) {
            return [
                'success' => false,
                'error' => 'Server not found. Please specify a server UUID or name, or ensure you are viewing a server page.',
                'action_type' => 'create_subdomain',
            ];
        }

        // Verify user has access
        if (!ServerGateway::canUserAccessServer($user['uuid'], $server['uuid'])) {
            return [
                'success' => false,
                'error' => 'Access denied to server',
                'action_type' => 'create_subdomain',
            ];
        }

        $request = new Request(content: json_encode([
            'domain_uuid' => $params['domain_uuid'] ?? null,
            'subdomain' => $params['subdomain'] ?? null,
        ], JSON_THROW_ON_ERROR));
        $request->setMethod('PUT');
        $request->attributes->set('user', $user);
        $response = (new SubdomainController())->create($request, $server);
        $result = json_decode((string) $response->getContent(), true);

        return [
            'success' => $response->getStatusCode() === 202,
            'message' => $result['message'] ?? '',
            'error' => $result['error_message'] ?? null,
            'data' => $result['data'] ?? null,
            'action_type' => 'create_subdomain',
        ];
    }

    public function getDescription(): string
    {
        return 'Request a subdomain for a server. Requires domain UUID and subdomain name. An administrator must approve it before DNS records are created.';
    }

    public function getParameters(): array
    {
        return [
            'server_uuid' => 'Server UUID (optional, can use server_name instead)',
            'server_name' => 'Server name (optional, can use server_uuid instead)',
            'domain_uuid' => 'Domain UUID (required)',
            'subdomain' => 'Subdomain name (required, 2-63 characters, letters, numbers, and hyphens only)',
        ];
    }
}
