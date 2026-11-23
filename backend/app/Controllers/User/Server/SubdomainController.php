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

namespace App\Controllers\User\Server;

use App\App;
use App\Permissions;
use App\Chat\Database;
use App\Helpers\PermissionHelper;
use App\Chat\Node;
use App\Chat\Subdomain;
use App\Chat\Allocation;
use App\SubuserPermissions;
use App\Chat\ServerActivity;
use App\Helpers\ApiResponse;
use App\Chat\SubdomainDomain;
use App\Chat\SubdomainRequest;
use OpenApi\Attributes as OA;
use App\Config\ConfigInterface;
use App\CloudFlare\CloudFlareRealIP;
use Symfony\Component\HttpFoundation\Request;
use App\Plugins\Events\Events\SubdomainsEvent;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Subdomain\CloudflareSubdomainService;

#[OA\Schema(
    schema: 'ServerSubdomainAvailableDomain',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'domain', type: 'string'),
        new OA\Property(property: 'protocol_service', type: 'string', nullable: true),
        new OA\Property(property: 'protocol_type', type: 'string'),
        new OA\Property(property: 'priority', type: 'integer'),
        new OA\Property(property: 'weight', type: 'integer'),
        new OA\Property(property: 'ttl', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'ServerSubdomainEntry',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'domain', type: 'string'),
        new OA\Property(property: 'subdomain', type: 'string'),
        new OA\Property(property: 'record_type', type: 'string'),
        new OA\Property(property: 'port', type: 'integer', nullable: true),
        new OA\Property(property: 'created_at', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'ServerSubdomainOverview',
    type: 'object',
    properties: [
        new OA\Property(property: 'max_allowed', type: 'integer'),
        new OA\Property(property: 'current_total', type: 'integer'),
        new OA\Property(property: 'domains', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerSubdomainAvailableDomain')),
        new OA\Property(property: 'subdomains', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerSubdomainEntry')),
    ]
)]
class SubdomainController
{
    use CheckSubuserPermissionsTrait;

    #[OA\Get(
        path: '/api/user/servers/{uuidShort}/subdomains',
        summary: 'List subdomains and available domains',
        description: 'Returns the subdomains created for a server and the domains available for its spell.',
        tags: ['User - Server Subdomains'],
        parameters: [
            new OA\Parameter(name: 'uuidShort', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subdomains retrieved successfully'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Server not found'),
        ]
    )]
    public function index(Request $request, array $server): Response
    {
        $canBypassApproval = $this->canBypassApproval($request);
        if (!$canBypassApproval && ($permission = $this->checkPermission($request, $server, SubuserPermissions::SUBDOMAIN_MANAGE)) !== null) {
            return $permission;
        }

        if (!$this->isSubdomainManagementEnabled()) {
            return ApiResponse::error('Subdomain management is disabled', 'SUBDOMAINS_DISABLED', Response::HTTP_FORBIDDEN);
        }

        $config = App::getInstance(true)->getConfig();
        $maxAllowed = (int) $config->getSetting(ConfigInterface::SUBDOMAIN_MAX_PER_SERVER, '1');
        if ($maxAllowed < 1) {
            $maxAllowed = 1;
        }

        $domains = array_map(function (array $domain) use ($server): array {
            $mapping = $this->getSpellMappingForDomain($domain['id'], (int) $server['spell_id']);

            return [
                'uuid' => $domain['uuid'],
                'domain' => $domain['domain'],
                'protocol_service' => $mapping['protocol_service'] ?? null,
                'protocol_type' => $mapping['protocol_type'] ?? 'tcp',
                'priority' => $mapping['priority'] ?? 1,
                'weight' => $mapping['weight'] ?? 1,
                'ttl' => $mapping['ttl'] ?? 120,
            ];
        }, ($canBypassApproval || SubdomainRequest::allowsSpell((int) $server['spell_id'])) ? SubdomainDomain::getActiveDomainsForSpell((int) $server['spell_id']) : []);

        $subdomains = array_map(function (array $entry): array {
            $domain = SubdomainDomain::getDomainById((int) $entry['domain_id']);

            return [
                'uuid' => $entry['uuid'],
                'domain' => $domain['domain'] ?? '',
                'subdomain' => $entry['subdomain'],
                'record_type' => $entry['record_type'],
                'port' => $entry['port'],
                'created_at' => $entry['created_at'] ?? null,
            ];
        }, Subdomain::getByServerId((int) $server['id']));

        return ApiResponse::success([
            'overview' => [
                'max_allowed' => $maxAllowed,
                'current_total' => count($subdomains),
                'pending_total' => SubdomainRequest::pendingCount((int) $server['id']),
                'can_bypass_approval' => $canBypassApproval,
                'domains' => $domains,
                'subdomains' => $subdomains,
                'requests' => SubdomainRequest::forServer((int) $server['id']),
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/user/servers/{uuidShort}/subdomains',
        summary: 'Request a subdomain for administrator approval',
        tags: ['User - Server Subdomains'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['domain_uuid', 'subdomain'],
                properties: [
                    new OA\Property(property: 'domain_uuid', type: 'string'),
                    new OA\Property(property: 'subdomain', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Subdomain created by a root administrator'),
            new OA\Response(response: 202, description: 'Subdomain request is pending approval'),
            new OA\Response(response: 400, description: 'Validation failed'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Domain not found'),
        ]
    )]
    public function create(Request $request, array $server): Response
    {
        $canBypassApproval = $this->canBypassApproval($request);
        if (!$canBypassApproval && ($permission = $this->checkPermission($request, $server, SubuserPermissions::SUBDOMAIN_MANAGE)) !== null) {
            return $permission;
        }

        if (!$this->isSubdomainManagementEnabled()) {
            return ApiResponse::error('Subdomain management is disabled', 'SUBDOMAINS_DISABLED', Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ApiResponse::error('Invalid JSON payload', 'INVALID_JSON', Response::HTTP_BAD_REQUEST);
        }

        $domainUuid = is_string($payload['domain_uuid'] ?? null) ? trim($payload['domain_uuid']) : '';
        $label = is_string($payload['subdomain'] ?? null) ? strtolower(trim($payload['subdomain'])) : '';

        return $canBypassApproval
            ? $this->provisionDirectly($request, $server, $domainUuid, $label)
            : $this->processRequest($request, $server, $domainUuid, $label, false);
    }

    private function canBypassApproval(Request $request): bool
    {
        $user = $request->attributes->get('user');
        $uuid = $user['uuid'] ?? '';

        return $uuid !== '' && PermissionHelper::hasPermission($uuid, Permissions::ADMIN_ROOT, $user);
    }

    private function provisionDirectly(Request $request, array $server, string $domainUuid, string $label): Response
    {
        $db = Database::getPdoConnection();
        $db->beginTransaction();
        try {
            if (!SubdomainRequest::lockServer($db, (int) $server['id'])) {
                return ApiResponse::error('Server not found', 'SERVER_NOT_FOUND', 404);
            }
            $response = $this->processRequest($request, $server, $domainUuid, $label, true, true);
            if ($response->getStatusCode() !== Response::HTTP_CREATED) {
                return $response;
            }
            $db->commit();
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }

        try {
            $domain = SubdomainDomain::getDomainByUuid($domainUuid);
            if ($domain !== null) {
                $this->notifyApproved($request, $server, $domain, $label);
            }
        } catch (\Throwable $error) {
            App::getInstance(true)->getLogger()->error('Could not notify listeners of created subdomain: ' . $error->getMessage());
        }

        return $response;
    }

    public function provisionApproved(Request $request, array $server, string $domainUuid, string $label): Response
    {
        return $this->processRequest($request, $server, $domainUuid, $label, true);
    }

    private function processRequest(Request $request, array $server, string $domainUuid, string $label, bool $approved, bool $approvalBypassed = false): Response
    {
        if (!$this->isSubdomainManagementEnabled() || (!$approvalBypassed && !SubdomainRequest::allowsSpell((int) $server['spell_id']))) {
            return ApiResponse::error('Subdomain requests are disabled for this server spell', 'DOMAIN_NOT_ALLOWED', Response::HTTP_FORBIDDEN);
        }

        if ($domainUuid === '' || $label === '') {
            return ApiResponse::error('Domain UUID and subdomain are required', 'VALIDATION_FAILED', Response::HTTP_BAD_REQUEST);
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/D', $label)) {
            return ApiResponse::error('Use 2–63 letters, numbers and hyphens, starting and ending with a letter or number', 'INVALID_SUBDOMAIN', Response::HTTP_BAD_REQUEST);
        }

        $domain = SubdomainDomain::getDomainByUuid($domainUuid);
        if (!$domain || (int) $domain['is_active'] !== 1) {
            return ApiResponse::error('Domain not found or inactive', 'DOMAIN_NOT_AVAILABLE', Response::HTTP_NOT_FOUND);
        }

        $domainId = (int) $domain['id'];
        if ($approvalBypassed) {
            $db = Database::getPdoConnection();
            if (!SubdomainRequest::lockDomain($db, $domainId)) {
                return ApiResponse::error('Domain not found or inactive', 'DOMAIN_NOT_AVAILABLE', Response::HTTP_NOT_FOUND);
            }
            if (SubdomainRequest::hasPendingLabel($db, $domainId, $label)) {
                return ApiResponse::error('This subdomain is already reserved', 'SUBDOMAIN_EXISTS', Response::HTTP_CONFLICT);
            }
        }

        $mapping = $this->getSpellMappingForDomain($domainId, (int) $server['spell_id']);
        if ($mapping === null) {
            return ApiResponse::error('Domain is not available for this server spell', 'DOMAIN_NOT_ALLOWED', Response::HTTP_BAD_REQUEST);
        }

        $config = App::getInstance(true)->getConfig();
        $maxAllowed = (int) $config->getSetting(ConfigInterface::SUBDOMAIN_MAX_PER_SERVER, '1');
        if ($maxAllowed < 1) {
            $maxAllowed = 1;
        }

        if (Subdomain::countByServer((int) $server['id']) >= $maxAllowed) {
            return ApiResponse::error('You have reached the maximum number of subdomains for this server', 'SUBDOMAIN_LIMIT_REACHED', Response::HTTP_BAD_REQUEST);
        }

        if (Subdomain::getByDomainAndLabel((int) $domain['id'], $label) !== null) {
            return ApiResponse::error('This subdomain is already in use for the selected domain', 'SUBDOMAIN_EXISTS', Response::HTTP_CONFLICT);
        }

        if (!$approved) {
            $user = $request->attributes->get('user');
            if (!is_string($user['uuid'] ?? null) || $user['uuid'] === '') {
                return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', Response::HTTP_UNAUTHORIZED);
            }
            $id = SubdomainRequest::enqueue((int) $server['id'], (int) $domain['id'], $label, $user['uuid'], $maxAllowed);
            if ($id < 0) {
                return ApiResponse::error('You have reached the maximum number of subdomains or pending requests', 'SUBDOMAIN_LIMIT_REACHED', Response::HTTP_BAD_REQUEST);
            }
            if ($id === 0) {
                return ApiResponse::error('This subdomain is already reserved', 'SUBDOMAIN_EXISTS', Response::HTTP_CONFLICT);
            }

            return ApiResponse::success(['request_id' => $id, 'status' => 'pending'], 'Subdomain request submitted for approval', Response::HTTP_ACCEPTED);
        }

        $allocation = Allocation::getAllocationById((int) $server['allocation_id']);
        if (!$allocation) {
            return ApiResponse::error('Server primary allocation not found', 'PRIMARY_ALLOCATION_NOT_FOUND', Response::HTTP_BAD_REQUEST);
        }

        $accountId = trim((string) ($domain['cloudflare_account_id'] ?? ''));
        if ($accountId === '') {
            return ApiResponse::error('Domain is missing a Cloudflare account ID', 'CLOUDFLARE_ACCOUNT_ID_MISSING', Response::HTTP_BAD_REQUEST);
        }

        $service = CloudflareSubdomainService::fromConfig($accountId);
        if (!$service->isAvailable()) {
            return ApiResponse::error('Cloudflare integration is not configured', 'CLOUDFLARE_NOT_CONFIGURED', Response::HTTP_BAD_REQUEST);
        }

        $zoneId = $domain['cloudflare_zone_id'] ?? null;
        if (!$zoneId) {
            $zoneId = $service->resolveZoneId($domain['domain']);
            if (!$zoneId) {
                return ApiResponse::error('Failed to resolve Cloudflare zone for domain', 'CLOUDFLARE_ZONE_ERROR', Response::HTTP_BAD_REQUEST);
            }
            SubdomainDomain::updateCloudflareZoneId((int) $domain['id'], $zoneId);
        }

        $protocolService = $mapping['protocol_service'] ?? null;
        $recordType = $protocolService ? 'SRV' : 'CNAME';

        // If ip_alias is not set, use the node public ipv4; else use ip_alias or fallback to allocation ip
        if (!empty($allocation['ip_alias'])) {
            // Just use those assuming it exists in cloudflare already!
            $target = $allocation['ip_alias'];
        } elseif (!empty($allocation['node_id'])) {
            $node = Node::getNodeById((int) $allocation['node_id']);
            if (!empty($node['public_ip_v4'])) {
                $target = $node['public_ip_v4'];
            } else {
                $target = $allocation['ip'];
            }
        } else {
            $target = $allocation['ip'];
        }

        $port = (int) ($allocation['port'] ?? 0);
        $ttl = (int) ($mapping['ttl'] ?? 120);

        $fqdn = $label . '.' . $domain['domain'];

        $srvRecordName = $recordType === 'SRV' ? ($protocolService . '._' . ($mapping['protocol_type'] ?? 'tcp') . '.' . $fqdn) : $fqdn;

        if (!$service->ensureRecordDoesNotExist($zoneId, $recordType, $srvRecordName)) {
            return ApiResponse::error('A DNS record already exists for this subdomain', 'RECORD_EXISTS', Response::HTTP_CONFLICT);
        }

        $recordId = null;
        $addressRecordId = null;

        if ($recordType === 'CNAME') {
            $recordId = $service->createCnameRecord($zoneId, $fqdn, $target, $ttl);
        } else {
            $protocolType = $mapping['protocol_type'] ?? 'tcp';
            if ($port <= 0) {
                return ApiResponse::error('Server primary allocation is missing port information required for SRV records', 'ALLOCATION_PORT_MISSING', Response::HTTP_BAD_REQUEST);
            }

            $srvTarget = $target;
            $ipVersion = null;
            if (filter_var($target, FILTER_VALIDATE_IP) !== false) {
                $ipVersion = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'A';
                if (!$service->ensureRecordDoesNotExist($zoneId, $ipVersion, $fqdn)) {
                    return ApiResponse::error('An address record already exists for this subdomain', 'ADDRESS_RECORD_EXISTS', Response::HTTP_CONFLICT);
                }
                $addressRecordId = $service->createAddressRecord($zoneId, $fqdn, $target, $ipVersion, $ttl);
                if (!$addressRecordId) {
                    return ApiResponse::error('Failed to create address record for SRV target', 'CLOUDFLARE_ADDRESS_CREATE_FAILED', Response::HTTP_BAD_REQUEST);
                }
                $srvTarget = $fqdn;
            }

            $recordId = $service->createSrvRecord(
                $zoneId,
                $protocolService,
                $protocolType,
                $label,
                $domain['domain'],
                $srvTarget,
                $port,
                (int) ($mapping['priority'] ?? 1),
                (int) ($mapping['weight'] ?? 1),
                $ttl
            );
        }

        if (!$recordId) {
            if ($addressRecordId !== null) {
                $service->deleteRecord($zoneId, $addressRecordId);
            }
            return ApiResponse::error('Failed to create DNS record on Cloudflare', 'CLOUDFLARE_CREATE_FAILED', Response::HTTP_BAD_REQUEST);
        }

        try {
            $subdomainId = Subdomain::create([
                'server_id' => (int) $server['id'],
                'domain_id' => (int) $domain['id'],
                'spell_id' => (int) $server['spell_id'],
                'subdomain' => $label,
                'record_type' => $recordType,
                'port' => $recordType === 'SRV' ? $port : null,
                'cloudflare_record_id' => $recordId,
            ]);
        } catch (\PDOException $error) {
            App::getInstance(true)->getLogger()->error('Could not save approved subdomain: ' . $error->getMessage());
            $subdomainId = false;
        }

        if ($subdomainId === false) {
            $service->deleteRecord($zoneId, $recordId);
            if ($addressRecordId !== null) {
                $service->deleteRecord($zoneId, $addressRecordId);
            }
            return ApiResponse::error('Failed to persist subdomain entry', 'SUBDOMAIN_CREATE_FAILED', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $created = Subdomain::getByDomainAndLabel((int) $domain['id'], $label);
        $formatted = null;
        if ($created) {
            $formatted = [
                'uuid' => $created['uuid'],
                'domain' => $domain['domain'],
                'subdomain' => $created['subdomain'],
                'record_type' => $created['record_type'],
                'port' => $created['port'],
                'created_at' => $created['created_at'] ?? null,
            ];
        }

        return ApiResponse::success([
            'subdomain' => $formatted,
            'approval_bypassed' => $approvalBypassed,
        ], 'Subdomain created successfully', Response::HTTP_CREATED);
    }

    public function notifyApproved(Request $request, array $server, array $domain, string $label): void
    {
        $this->logServerActivity($request, $server, 'server:subdomain.create', [
            'domain' => $domain['domain'],
            'subdomain' => $label,
        ]);

        // Emit event
        global $eventManager;
        if (isset($eventManager) && $eventManager !== null) {
            $created = Subdomain::getByDomainAndLabel((int) $domain['id'], $label);
            if ($created) {
                $eventManager->emit(
                    SubdomainsEvent::onSubdomainCreated(),
                    [
                        'subdomain_uuid' => $created['uuid'],
                        'subdomain_data' => $created,
                        'server_data' => $server,
                        'user' => $request->attributes->get('user'),
                    ]
                );
            }
        }
    }

    #[OA\Delete(
        path: '/api/user/servers/{uuidShort}/subdomains/{uuid}',
        summary: 'Delete a subdomain',
        tags: ['User - Server Subdomains'],
        parameters: [
            new OA\Parameter(name: 'uuidShort', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subdomain deleted successfully'),
            new OA\Response(response: 404, description: 'Subdomain not found'),
        ]
    )]
    public function delete(Request $request, array $server, string $uuid): Response
    {
        $canBypassApproval = $this->canBypassApproval($request);
        if (!$canBypassApproval && ($permission = $this->checkPermission($request, $server, SubuserPermissions::SUBDOMAIN_MANAGE)) !== null) {
            return $permission;
        }

        if (!$this->isSubdomainManagementEnabled()) {
            return ApiResponse::error('Subdomain management is disabled', 'SUBDOMAINS_DISABLED', Response::HTTP_FORBIDDEN);
        }

        $subdomain = Subdomain::getByUuidAndServer($uuid, (int) $server['id']);
        if (!$subdomain) {
            return ApiResponse::error('Subdomain not found', 'SUBDOMAIN_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $domain = SubdomainDomain::getDomainById((int) $subdomain['domain_id']);
        if (!$domain) {
            return ApiResponse::error('Domain not found', 'DOMAIN_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $accountId = trim((string) ($domain['cloudflare_account_id'] ?? ''));
        if ($accountId === '') {
            return ApiResponse::error('Domain is missing a Cloudflare account ID', 'CLOUDFLARE_ACCOUNT_ID_MISSING', Response::HTTP_BAD_REQUEST);
        }

        $service = CloudflareSubdomainService::fromConfig($accountId);
        $zoneId = $domain['cloudflare_zone_id'] ?? null;
        if ($service->isAvailable() && $zoneId) {
            $recordId = $subdomain['cloudflare_record_id'] ?? null;
            if ($recordId) {
                $service->deleteRecord($zoneId, $recordId);
            } else {
                $mapping = $this->getSpellMappingForDomain((int) $domain['id'], (int) $server['spell_id']);
                $recordName = $subdomain['record_type'] === 'SRV'
                    ? (($mapping['protocol_service'] ?? '') . '._' . ($mapping['protocol_type'] ?? 'tcp') . '.' . $subdomain['subdomain'] . '.' . $domain['domain'])
                    : $subdomain['subdomain'] . '.' . $domain['domain'];
                $service->deleteRecordByName($zoneId, $subdomain['record_type'], $recordName);
            }

            if ($subdomain['record_type'] === 'SRV') {
                $allocation = Allocation::getAllocationById((int) $server['allocation_id']);
                $ipAlias = $allocation['ip_alias'] ?? '';
                $requiresAddressCleanup = $ipAlias === '' || filter_var($ipAlias, FILTER_VALIDATE_IP) !== false;

                if ($requiresAddressCleanup) {
                    $hostName = $subdomain['subdomain'] . '.' . $domain['domain'];
                    $service->deleteRecordByName($zoneId, 'A', $hostName);
                    $service->deleteRecordByName($zoneId, 'AAAA', $hostName);
                }
            }
        }

        Subdomain::deleteByUuid($uuid);

        $this->logServerActivity($request, $server, 'server:subdomain.delete', [
            'domain' => $domain['domain'],
            'subdomain' => $subdomain['subdomain'],
        ]);

        // Emit event
        global $eventManager;
        if (isset($eventManager) && $eventManager !== null) {
            $eventManager->emit(
                SubdomainsEvent::onSubdomainDeleted(),
                [
                    'subdomain_uuid' => $uuid,
                    'subdomain_data' => $subdomain,
                    'server_data' => $server,
                    'user' => $request->attributes->get('user'),
                ]
            );
        }

        return ApiResponse::success([], 'Subdomain deleted successfully');
    }

    private function getSpellMappingForDomain(int $domainId, int $spellId): ?array
    {
        foreach (SubdomainDomain::getSpellMappings($domainId) as $mapping) {
            if ((int) $mapping['spell_id'] === $spellId) {
                return $mapping;
            }
        }

        return null;
    }

    private function isSubdomainManagementEnabled(): bool
    {
        $config = App::getInstance(true)->getConfig();

        return $config->getSetting(ConfigInterface::SERVER_ALLOW_USER_MADE_SUBDOMAINS, 'false') === 'true';
    }

    private function logServerActivity(Request $request, array $server, string $event, array $metadata = []): void
    {
        $user = $request->attributes->get('user');
        $userId = $user['id'] ?? null;

        ServerActivity::createActivity([
            'server_id' => (int) $server['id'],
            'node_id' => (int) $server['node_id'],
            'user_id' => $userId,
            'ip' => CloudFlareRealIP::getRealIP(),
            'event' => $event,
            'metadata' => $metadata,
        ]);
    }
}
