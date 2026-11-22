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
use App\Chat\Node;
use App\Chat\Server;
use App\Chat\Allocation;
use App\Chat\ManagedProxy;
use App\SubuserPermissions;
use App\Chat\ServerActivity;
use App\Helpers\ApiResponse;
use App\Services\Wings\Wings;
use OpenApi\Attributes as OA;
use App\Config\ConfigInterface;
use App\Chat\Proxy as ProxyModel;
use App\Helpers\DaemonCapabilities;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Plugins\Events\Events\ServerProxyEvent;
use App\Services\Servers\ReverseProxyAddresses;
use App\Services\Servers\ReverseProxyEdge;

#[OA\Schema(
    schema: 'ProxyCreateRequest',
    type: 'object',
    required: ['domain', 'port', 'ssl'],
    properties: [
        new OA\Property(property: 'domain', type: 'string', description: 'The domain name for the proxy'),
        new OA\Property(property: 'allocation_id', type: 'integer', description: 'The selected allocation, when multiple allocations share a port'),
        new OA\Property(property: 'port', type: 'string', description: 'The target port to proxy to'),
        new OA\Property(property: 'ssl', type: 'boolean', description: 'Whether to enable SSL/TLS'),
        new OA\Property(property: 'use_lets_encrypt', type: 'boolean', description: 'Whether to use Let\'s Encrypt for SSL'),
        new OA\Property(property: 'client_email', type: 'string', description: 'Email for Let\'s Encrypt registration'),
        new OA\Property(property: 'ssl_cert', type: 'string', description: 'SSL certificate content'),
        new OA\Property(property: 'ssl_key', type: 'string', description: 'SSL private key content'),
    ]
)]
#[OA\Schema(
    schema: 'ProxyDeleteRequest',
    type: 'object',
    required: ['domain', 'port'],
    properties: [
        new OA\Property(property: 'domain', type: 'string', description: 'The domain name of the proxy to delete'),
        new OA\Property(property: 'port', type: 'string', description: 'The port associated with the proxy configuration'),
    ]
)]
class ServerProxyController
{
    use CheckSubuserPermissionsTrait;

    private $app;

    public function __construct()
    {
        $this->app = App::getInstance(true);
    }

    /**
     * List all proxy configurations for a server.
     */
    #[OA\Get(
        path: '/api/user/servers/{uuidShort}/proxy',
        summary: 'List reverse proxies',
        description: 'Get a list of all reverse proxy configurations for a server.',
        tags: ['User - Server Proxy'],
        parameters: [
            new OA\Parameter(
                name: 'uuidShort',
                in: 'path',
                required: true,
                description: 'Server short UUID',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of proxy configurations'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Server not found'),
        ]
    )]
    public function listProxies(Request $request, int $serverId): Response
    {
        $user = $request->attributes->get('user');
        if (!$user) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        $server = Server::getServerById($serverId);
        if (!$server) {
            return ApiResponse::error('Server not found', 'SERVER_NOT_FOUND', 404);
        }

        // Check if user-made proxy is enabled
        $proxyEnabled = $this->app->getConfig()->getSetting(ConfigInterface::SERVER_ALLOW_USER_MADE_PROXY, 'false');
        if ($proxyEnabled !== 'true') {
            return ApiResponse::error('Proxy management is disabled', 'PROXY_DISABLED', 403);
        }

        $permissionCheck = $this->checkPermission($request, $server, SubuserPermissions::PROXY_READ);
        if ($permissionCheck !== null) {
            return $permissionCheck;
        }

        $proxies = ProxyModel::getByServerId($serverId);

        // Convert boolean fields
        foreach ($proxies as &$proxy) {
            $proxy['ssl'] = (bool) $proxy['ssl'];
            $proxy['use_lets_encrypt'] = (bool) $proxy['use_lets_encrypt'];
            unset($proxy['ssl_cert'], $proxy['ssl_key'], $proxy['client_email']);
        }
        unset($proxy);

        $managed = ManagedProxy::getByServer($serverId);
        $statuses = [];
        if ($managed !== []) {
            try {
                $statuses = ReverseProxyEdge::request('status/' . $server['uuid'])['states'] ?? [];
            } catch (\RuntimeException) {
                // Existing proxies keep serving while the connector is unavailable.
            }
        }
        foreach ($managed as $proxy) {
            $state = $statuses[(string) $proxy['id']] ?? 'unknown';
            $proxies[] = [
                ...$proxy,
                'id' => 'edge:' . $proxy['id'],
                'allocation_id' => (int) $proxy['allocation_id'],
                'port' => (int) $proxy['port'],
                'ssl' => $state === 'active',
                'use_lets_encrypt' => true,
                'managed' => true,
                'status' => $state,
            ];
        }

        return ApiResponse::success([
            'proxies' => $proxies,
            'managed_enabled' => ReverseProxyEdge::settings()['enabled'],
        ], 'Proxy configurations fetched successfully');
    }

    /**
     * Create a new reverse proxy configuration.
     */
    #[OA\Post(
        path: '/api/user/servers/{uuidShort}/proxy/create',
        summary: 'Create reverse proxy',
        description: 'Create a new reverse proxy configuration for a server.',
        tags: ['User - Server Proxy'],
        parameters: [
            new OA\Parameter(
                name: 'uuidShort',
                in: 'path',
                required: true,
                description: 'Server short UUID',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ProxyCreateRequest')
        ),
        responses: [
            new OA\Response(response: 202, description: 'Proxy configuration created successfully'),
            new OA\Response(response: 400, description: 'Bad request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Server not found'),
            new OA\Response(response: 500, description: 'Internal server error'),
            new OA\Response(response: 503, description: 'Service unavailable'),
        ]
    )]
    public function createProxy(Request $request, int $serverId): Response
    {
        $user = $request->attributes->get('user');
        if (!$user) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        $server = Server::getServerById($serverId);
        if (!$server) {
            return ApiResponse::error('Server not found', 'SERVER_NOT_FOUND', 404);
        }

        // Check if user-made proxy is enabled
        $proxyEnabled = $this->app->getConfig()->getSetting(ConfigInterface::SERVER_ALLOW_USER_MADE_PROXY, 'false');
        if ($proxyEnabled !== 'true') {
            return ApiResponse::error('Proxy management is disabled', 'PROXY_DISABLED', 403);
        }

        $permissionCheck = $this->checkPermission($request, $server, SubuserPermissions::PROXY_MANAGE);
        if ($permissionCheck !== null) {
            return $permissionCheck;
        }

        $edge = ReverseProxyEdge::settings();
        if ($edge['enabled']) {
            return $this->createManagedProxy($request, $server, $edge);
        }

        // Check proxy limit
        $currentProxies = ProxyModel::countByServer((int) $server['id']) + count(ManagedProxy::getByServer($serverId));
        $maxAllowed = (int) $this->app->getConfig()->getSetting(ConfigInterface::SERVER_PROXY_MAX_PER_SERVER, '5');
        if ($maxAllowed < 1) {
            $maxAllowed = 1;
        }

        if ($currentProxies >= $maxAllowed) {
            return ApiResponse::error(
                "You have reached the maximum number of proxies ({$maxAllowed}) for this server",
                'PROXY_LIMIT_REACHED',
                400
            );
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return ApiResponse::error('Invalid JSON body', 'INVALID_REQUEST_BODY', 400);
        }

        $validationError = $this->validateCreatePayload($payload);
        if ($validationError !== null) {
            return $validationError;
        }
        if (ManagedProxy::hasDomain(strtolower(trim($payload['domain'])))) {
            return ApiResponse::error('The domain is already registered', 'REVERSE_PROXY_EDGE_CONFLICT', 409);
        }

        $port = (int) $payload['port'];
        $allocation = self::getProxyAllocation($serverId, $port, $payload['allocation_id'] ?? null);
        if (!$allocation) {
            return ApiResponse::error('Port does not belong to this server\'s allocations', 'INVALID_PORT', 400);
        }

        $node = Node::getNodeById($server['node_id']);
        if (!$node) {
            return ApiResponse::error('Node not found', 'NODE_NOT_FOUND', 404);
        }

        if (!DaemonCapabilities::fromNode($node)->supports(DaemonCapabilities::FEATURE_PROXY)) {
            return DaemonCapabilities::unsupportedResponse($node, DaemonCapabilities::FEATURE_PROXY);
        }

        // Get IP from allocation
        $targetIp = $allocation['ip'];

        // If IP is internal (127.0.0.1, 0.0.0.0, localhost), use node's public IPv4
        if (in_array($targetIp, ['127.0.0.1', '0.0.0.0', 'localhost', '::1'], true)) {
            if (!empty($node['public_ip_v4'])) {
                $targetIp = $node['public_ip_v4'];
            } else {
                return ApiResponse::error(
                    'Allocation uses internal IP (' . $targetIp . ') but node does not have a public IPv4 address configured. Please configure public IPv4 in node settings.',
                    'NO_PUBLIC_IP',
                    400
                );
            }
        }

        $wings = $this->createWings($node);

        try {
            $response = $wings->getServer()->createProxy($server['uuid'], [
                'domain' => $payload['domain'],
                'ip' => $targetIp,
                'port' => $payload['port'],
                'ssl' => $payload['ssl'] ?? false,
                'use_lets_encrypt' => $payload['use_lets_encrypt'] ?? false,
                'client_email' => $payload['client_email'] ?? '',
                'ssl_cert' => $payload['ssl_cert'] ?? '',
                'ssl_key' => $payload['ssl_key'] ?? '',
            ]);

            if (!$response->isSuccessful()) {
                $error = $response->getError() ?: 'Unknown error';

                return ApiResponse::error(
                    'Failed to create proxy: ' . $error,
                    'PROXY_CREATE_FAILED',
                    $response->getStatusCode()
                );
            }

            // Store proxy in database
            $proxyId = ProxyModel::create([
                'server_id' => (int) $server['id'],
                'domain' => $payload['domain'],
                'ip' => $targetIp,
                'port' => (int) $payload['port'],
                'ssl' => $payload['ssl'] ? 1 : 0,
                'use_lets_encrypt' => ($payload['use_lets_encrypt'] ?? false) ? 1 : 0,
                'client_email' => $payload['client_email'] ?? null,
                'ssl_cert' => $payload['ssl_cert'] ?? null,
                'ssl_key' => $payload['ssl_key'] ?? null,
            ]);

            if ($proxyId === false) {
                App::getInstance(true)->getLogger()->error('Failed to store proxy in database for server: ' . $server['id']);
                // Don't fail the request, proxy was created in Wings
            }

            $this->logActivity(
                $server,
                $node,
                'proxy_created',
                [
                    'domain' => $payload['domain'],
                    'ip' => $targetIp,
                    'port' => $payload['port'],
                    'ssl' => $payload['ssl'] ?? false,
                    'proxy_id' => $proxyId,
                ],
                $user
            );

            self::emitEvent(ServerProxyEvent::onServerProxyCreated(), [
                'user_uuid' => $user['uuid'] ?? null,
                'server_uuid' => $server['uuid'],
                'proxy_id' => $proxyId ?: null,
                'domain' => $payload['domain'],
                'port' => (int) $payload['port'],
            ]);

            return ApiResponse::success([
                'message' => 'Proxy configuration created successfully',
                'target_ip' => $targetIp,
                'node_public_ipv4' => $node['public_ip_v4'] ?? null,
                'node_public_ipv6' => $node['public_ip_v6'] ?? null,
                'proxy_id' => $proxyId,
            ], 202);
        } catch (\Exception $e) {
            App::getInstance(true)->getLogger()->error('Failed to create proxy: ' . $e->getMessage());

            return ApiResponse::error('Failed to create proxy', 'PROXY_CREATE_FAILED', 500);
        }
    }

    /**
     * Delete a reverse proxy configuration.
     */
    #[OA\Post(
        path: '/api/user/servers/{uuidShort}/proxy/delete',
        summary: 'Delete reverse proxy',
        description: 'Delete an existing reverse proxy configuration for a server.',
        tags: ['User - Server Proxy'],
        parameters: [
            new OA\Parameter(
                name: 'uuidShort',
                in: 'path',
                required: true,
                description: 'Server short UUID',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ProxyDeleteRequest')
        ),
        responses: [
            new OA\Response(response: 202, description: 'Proxy configuration deleted successfully'),
            new OA\Response(response: 400, description: 'Bad request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Server not found'),
            new OA\Response(response: 500, description: 'Internal server error'),
            new OA\Response(response: 503, description: 'Service unavailable'),
        ]
    )]
    public function deleteProxy(Request $request, int $serverId): Response
    {
        $user = $request->attributes->get('user');
        if (!$user) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        $server = Server::getServerById($serverId);
        if (!$server) {
            return ApiResponse::error('Server not found', 'SERVER_NOT_FOUND', 404);
        }

        // Check if user-made proxy is enabled
        $proxyEnabled = $this->app->getConfig()->getSetting(ConfigInterface::SERVER_ALLOW_USER_MADE_PROXY, 'false');
        if ($proxyEnabled !== 'true') {
            return ApiResponse::error('Proxy management is disabled', 'PROXY_DISABLED', 403);
        }

        $permissionCheck = $this->checkPermission($request, $server, SubuserPermissions::PROXY_MANAGE);
        if ($permissionCheck !== null) {
            return $permissionCheck;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return ApiResponse::error('Invalid JSON body', 'INVALID_REQUEST_BODY', 400);
        }

        if (is_string($payload['id'] ?? null) && preg_match('/^edge:([1-9][0-9]{0,9})$/D', $payload['id'], $id)) {
            try {
                $result = ManagedProxy::withLockedProxy($serverId, (int) $id[1], static fn ($proxy) => ReverseProxyEdge::request('delete', [
                    'id' => (int) $proxy['id'],
                    'server' => $server['uuid'],
                    'domain' => $proxy['domain'],
                ]), true);
            } catch (\RuntimeException $error) {
                return $this->edgeError($error);
            }

            return $result === null
                ? ApiResponse::error('Proxy not found', 'PROXY_NOT_FOUND', 404)
                : ApiResponse::success([], 'Proxy configuration deleted successfully');
        }

        // Support deletion by ID or by domain+port
        $proxy = null;
        if (isset($payload['id']) && is_numeric($payload['id'])) {
            $proxy = ProxyModel::getById((int) $payload['id']);
            if (!$proxy || (int) $proxy['server_id'] !== $serverId) {
                return ApiResponse::error('Proxy not found', 'PROXY_NOT_FOUND', 404);
            }
        } elseif (!empty($payload['domain']) && is_string($payload['domain']) && !empty($payload['port'])) {
            $proxy = ProxyModel::getByServerDomainPort(
                $serverId,
                trim($payload['domain']),
                (int) $payload['port']
            );
            if (!$proxy) {
                return ApiResponse::error('Proxy not found', 'PROXY_NOT_FOUND', 404);
            }
        } else {
            return ApiResponse::error('Missing required fields: id or (domain and port)', 'MISSING_REQUIRED_FIELDS', 400);
        }

        $node = Node::getNodeById($server['node_id']);
        if (!$node) {
            return ApiResponse::error('Node not found', 'NODE_NOT_FOUND', 404);
        }

        if (!DaemonCapabilities::fromNode($node)->supports(DaemonCapabilities::FEATURE_PROXY)) {
            return DaemonCapabilities::unsupportedResponse($node, DaemonCapabilities::FEATURE_PROXY);
        }

        $wings = $this->createWings($node);

        try {
            $response = $wings->getServer()->deleteProxy(
                $server['uuid'],
                $proxy['domain'],
                (string) $proxy['port']
            );

            if (!$response->isSuccessful()) {
                $error = $response->getError() ?: 'Unknown error';

                return ApiResponse::error(
                    'Failed to delete proxy: ' . $error,
                    'PROXY_DELETE_FAILED',
                    $response->getStatusCode()
                );
            }

            // Delete proxy from database
            $deleted = ProxyModel::delete((int) $proxy['id']);
            if (!$deleted) {
                App::getInstance(true)->getLogger()->warning('Failed to delete proxy from database for server: ' . $server['id']);
                // Don't fail the request, proxy was deleted in Wings
            }

            $this->logActivity(
                $server,
                $node,
                'proxy_deleted',
                [
                    'domain' => $proxy['domain'],
                    'port' => $proxy['port'],
                    'proxy_id' => $proxy['id'],
                ],
                $user
            );

            self::emitEvent(ServerProxyEvent::onServerProxyDeleted(), [
                'user_uuid' => $user['uuid'] ?? null,
                'server_uuid' => $server['uuid'],
                'proxy_id' => (int) $proxy['id'],
                'domain' => $proxy['domain'],
                'port' => (int) $proxy['port'],
            ]);

            return ApiResponse::success([
                'message' => 'Proxy configuration deleted successfully',
            ], 202);
        } catch (\Exception $e) {
            App::getInstance(true)->getLogger()->error('Failed to delete proxy: ' . $e->getMessage());

            return ApiResponse::error('Failed to delete proxy', 'PROXY_DELETE_FAILED', 500);
        }
    }

    #[OA\Post(
        path: '/api/user/servers/{uuidShort}/proxy/verify-dns',
        summary: 'Verify DNS records',
        description: 'Verify that DNS A record exists and points to the correct IP address.',
        tags: ['User - Server Proxy'],
        parameters: [
            new OA\Parameter(
                name: 'uuidShort',
                in: 'path',
                required: true,
                description: 'Server short UUID',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'domain', type: 'string', description: 'Domain name to verify'),
                    new OA\Property(property: 'port', type: 'string', description: 'Port number of the selected allocation'),
                    new OA\Property(property: 'allocation_id', type: 'integer', description: 'The selected allocation, when multiple allocations share a port'),
                ],
                required: ['domain', 'port']
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'DNS verification result'),
            new OA\Response(response: 400, description: 'Bad request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Server not found'),
        ]
    )]
    public function verifyDns(Request $request, int $serverId): Response
    {
        $user = $request->attributes->get('user');
        if (!$user) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        $server = Server::getServerById($serverId);
        if (!$server) {
            return ApiResponse::error('Server not found', 'SERVER_NOT_FOUND', 404);
        }

        // Check if user-made proxy is enabled
        $proxyEnabled = $this->app->getConfig()->getSetting(ConfigInterface::SERVER_ALLOW_USER_MADE_PROXY, 'false');
        if ($proxyEnabled !== 'true') {
            return ApiResponse::error('Proxy management is disabled', 'PROXY_DISABLED', 403);
        }

        $permissionCheck = $this->checkPermission($request, $server, SubuserPermissions::PROXY_MANAGE);
        if ($permissionCheck !== null) {
            return $permissionCheck;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return ApiResponse::error('Invalid JSON body', 'INVALID_REQUEST_BODY', 400);
        }

        if (empty($payload['domain']) || empty($payload['port']) || !is_string($payload['domain'])) {
            return ApiResponse::error('Domain and port are required', 'MISSING_REQUIRED_FIELDS', 400);
        }

        $domain = trim($payload['domain']);
        $port = (int) $payload['port'];

        // Validate domain format
        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return ApiResponse::error('Invalid domain format', 'INVALID_DOMAIN', 400);
        }

        // Get allocation to determine target IP
        $allocation = self::getProxyAllocation($serverId, $port, $payload['allocation_id'] ?? null);
        if (!$allocation) {
            return ApiResponse::error('Port does not belong to this server\'s allocations', 'INVALID_PORT', 400);
        }

        $edge = ReverseProxyEdge::settings();
        if ($edge['enabled']) {
            $domain = ReverseProxyEdge::domain($domain);
            if ($domain === null) {
                return ApiResponse::error('Invalid domain format', 'INVALID_DOMAIN', 400);
            }
            $expectedIps = ReverseProxyEdge::expectedIps($edge, $server, $allocation);
            if ($expectedIps === null) {
                return ApiResponse::error('The allocation points to a different public proxy', 'REVERSE_PROXY_EDGE_MAPPING', 422);
            }
            try {
                $result = ReverseProxyEdge::request('verify', [
                    'server' => $server['uuid'],
                    'domain' => $domain,
                    'public_ips' => $expectedIps,
                ]);

                return ApiResponse::success(['managed' => true, 'expected_ips' => $expectedIps, ...$result]);
            } catch (\RuntimeException $error) {
                return $this->edgeError($error);
            }
        }

        $node = Node::getNodeById($server['node_id']);
        if (!$node) {
            return ApiResponse::error('Node not found', 'NODE_NOT_FOUND', 404);
        }

        // DNS names point to the public listener. Wings still receives the allocation as its upstream.
        $targetIp = ReverseProxyAddresses::dnsIp(
            $allocation['ip'],
            (int) $server['spell_id'],
            ReverseProxyAddresses::configuredRules(),
            $node['public_ip_v4'] ?? null,
        );
        if ($targetIp === null) {
            return ApiResponse::error('No public IP is configured for this allocation', 'NO_PUBLIC_IP', 400);
        }

        $recordType = filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
        $addressField = $recordType === 'AAAA' ? 'ipv6' : 'ip';
        try {
            $dnsRecords = @dns_get_record($domain, $recordType === 'AAAA' ? DNS_AAAA : DNS_A);
            if ($dnsRecords === false) {
                throw new \RuntimeException('DNS lookup failed');
            }
            $resolvedIps = [];
            $isValid = false;
            $expected = inet_pton($targetIp);

            foreach ($dnsRecords as $record) {
                if (isset($record[$addressField])) {
                    $address = $record[$addressField];
                    $resolvedIps[] = $address;
                    $isValid = $isValid || inet_pton($address) === $expected;
                }
            }

            return ApiResponse::success([
                'verified' => $isValid,
                'domain' => $domain,
                'expected_ip' => $targetIp,
                'resolved_ips' => $resolvedIps,
                'message' => $isValid
                    ? "DNS {$recordType} record is correctly configured"
                    : "DNS {$recordType} record does not point to the expected IP address. Please update your DNS records.",
            ]);
        } catch (\Exception $e) {
            App::getInstance(true)->getLogger()->error('DNS verification failed: ' . $e->getMessage());

            return ApiResponse::success([
                'verified' => false,
                'domain' => $domain,
                'expected_ip' => $targetIp,
                'resolved_ips' => [],
                'message' => 'Failed to resolve DNS records. Please ensure your domain DNS is configured correctly.',
            ]);
        }
    }

    /**
     * Verify DNS records for a domain.
     */
    private function createManagedProxy(Request $request, array $server, array $edge): Response
    {
        $payload = json_decode($request->getContent(), true);
        $domain = ReverseProxyEdge::domain(is_array($payload) ? ($payload['domain'] ?? null) : null);
        if ($domain === null) {
            return ApiResponse::error('Invalid domain format', 'INVALID_DOMAIN', 400);
        }
        $serverId = (int) $server['id'];
        $port = filter_var($payload['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $allocation = $port === false ? null : self::getProxyAllocation($serverId, $port, $payload['allocation_id'] ?? null);
        if ($allocation === null) {
            return ApiResponse::error('Port does not belong to this server\'s allocations', 'INVALID_PORT', 400);
        }
        $expectedIps = ReverseProxyEdge::expectedIps($edge, $server, $allocation);
        if ($expectedIps === null) {
            return ApiResponse::error('The allocation points to a different public proxy', 'REVERSE_PROXY_EDGE_MAPPING', 422);
        }
        $data = ['server' => $server['uuid'], 'domain' => $domain, 'public_ips' => $expectedIps];
        try {
            $verification = ReverseProxyEdge::request('verify', $data);
            if (($verification['verified'] ?? false) !== true) {
                return ApiResponse::error('Verify the domain records before creating the proxy', 'REVERSE_PROXY_EDGE_DNS', 422);
            }
            $id = ManagedProxy::reserve(
                $serverId,
                $allocation,
                $domain,
                max(1, (int) $this->app->getConfig()->getSetting(ConfigInterface::SERVER_PROXY_MAX_PER_SERVER, '5')),
            );
            if ($id === null) {
                return ApiResponse::error('The domain is already registered or the proxy limit was reached', 'REVERSE_PROXY_EDGE_CONFLICT', 409);
            }
            $result = ManagedProxy::withLockedProxy($serverId, $id, static fn ($proxy) => ReverseProxyEdge::request('create', [
                ...$data,
                'id' => $id,
                'ip' => $proxy['ip'],
                'port' => (int) $proxy['port'],
                'email' => $edge['email'],
            ]));

            return $result === null
                ? ApiResponse::error('Proxy not found', 'PROXY_NOT_FOUND', 404)
                : ApiResponse::success(['managed' => true, 'proxy_id' => 'edge:' . $id, ...$result], 'Proxy request accepted', 202);
        } catch (\RuntimeException $error) {
            return $this->edgeError($error);
        }
    }

    private function edgeError(\RuntimeException $error): Response
    {
        return match ($error->getCode()) {
            409 => ApiResponse::error('The domain is unavailable or the proxy is busy', 'REVERSE_PROXY_EDGE_CONFLICT', 409),
            422 => ApiResponse::error('The domain or destination failed the public proxy checks', 'REVERSE_PROXY_EDGE_DNS', 422),
            default => ApiResponse::error('The public proxy connector is unavailable', 'REVERSE_PROXY_EDGE_UNAVAILABLE', 503),
        };
    }

    private static function emitEvent(string $eventName, array $payload): void
    {
        global $eventManager;
        if (isset($eventManager) && $eventManager !== null) {
            $eventManager->emit($eventName, $payload);
        }
    }

    /**
     * Validate the create proxy payload.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $server
     */
    private static function getProxyAllocation(int $serverId, int $port, mixed $id): ?array
    {
        if ($id === null) {
            return Allocation::getByServerIdAndPort($serverId, $port);
        }
        if (!is_int($id) || $id < 1) {
            return null;
        }

        $allocation = Allocation::getById($id);

        return $allocation !== null && (int) $allocation['server_id'] === $serverId && (int) $allocation['port'] === $port
            ? $allocation
            : null;
    }

    private function validateCreatePayload(array $payload): ?Response
    {
        // Check required fields exist (no longer require IP - it's determined from port)
        $requiredFields = ['domain', 'port', 'ssl'];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                return ApiResponse::error(
                    'Missing required field: ' . $field,
                    'MISSING_REQUIRED_FIELDS',
                    400
                );
            }
        }

        // Validate domain - must be string and not empty
        if (!is_string($payload['domain'])) {
            return ApiResponse::error('Domain must be a string', 'INVALID_DOMAIN', 400);
        }

        $domain = trim($payload['domain']);
        if ($domain === '') {
            return ApiResponse::error('Domain is required', 'INVALID_DOMAIN', 400);
        }

        // Validate domain format using PHP's built-in filter
        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return ApiResponse::error('Invalid domain format. Domain must be a valid hostname (e.g., example.com)', 'INVALID_DOMAIN', 400);
        }

        // Additional domain validation
        // Must contain at least one dot
        if (strpos($domain, '.') === false) {
            return ApiResponse::error('Invalid domain format. Domain must contain at least one dot (e.g., example.com)', 'INVALID_DOMAIN', 400);
        }

        // Check domain length (max 253 characters)
        if (strlen($domain) > 253) {
            return ApiResponse::error('Domain is too long. Maximum length is 253 characters', 'INVALID_DOMAIN', 400);
        }

        // Check each label length (max 63 characters per label)
        $labels = explode('.', $domain);
        foreach ($labels as $label) {
            if (strlen($label) > 63) {
                return ApiResponse::error('Domain label is too long. Each part must be 63 characters or less', 'INVALID_DOMAIN', 400);
            }
            if (strlen($label) === 0) {
                return ApiResponse::error('Invalid domain format. Domain labels cannot be empty', 'INVALID_DOMAIN', 400);
            }
        }

        // Validate port - must be string or numeric
        if (!is_string($payload['port']) && !is_numeric($payload['port'])) {
            return ApiResponse::error('Port must be a string or number', 'INVALID_PORT', 400);
        }

        $portStr = trim((string) $payload['port']);
        if ($portStr === '') {
            return ApiResponse::error('Port is required', 'INVALID_PORT', 400);
        }

        $port = (int) $portStr;
        if ($port < 1 || $port > 65535) {
            return ApiResponse::error('Invalid port: must be between 1 and 65535', 'INVALID_PORT', 400);
        }

        // Validate SSL - must be boolean
        if (!is_bool($payload['ssl'])) {
            return ApiResponse::error('SSL must be a boolean value', 'INVALID_SSL', 400);
        }

        // If SSL is enabled, validate SSL-related fields
        if ($payload['ssl'] === true) {
            $useLetsEncrypt = isset($payload['use_lets_encrypt']) && $payload['use_lets_encrypt'] === true;

            if ($useLetsEncrypt) {
                // Require client_email for Let's Encrypt
                if (!isset($payload['client_email']) || !is_string($payload['client_email'])) {
                    return ApiResponse::error('client_email is required when using Let\'s Encrypt', 'MISSING_REQUIRED_FIELDS', 400);
                }

                $email = trim($payload['client_email']);
                if ($email === '') {
                    return ApiResponse::error('client_email is required when using Let\'s Encrypt', 'MISSING_REQUIRED_FIELDS', 400);
                }

                // Validate email format
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return ApiResponse::error('Invalid email format', 'INVALID_EMAIL', 400);
                }
            } else {
                // Require ssl_cert and ssl_key for custom certificates
                if (!isset($payload['ssl_cert']) || !is_string($payload['ssl_cert'])) {
                    return ApiResponse::error('ssl_cert is required when not using Let\'s Encrypt', 'MISSING_REQUIRED_FIELDS', 400);
                }

                if (!isset($payload['ssl_key']) || !is_string($payload['ssl_key'])) {
                    return ApiResponse::error('ssl_key is required when not using Let\'s Encrypt', 'MISSING_REQUIRED_FIELDS', 400);
                }

                $sslCert = trim($payload['ssl_cert']);
                $sslKey = trim($payload['ssl_key']);

                if ($sslCert === '') {
                    return ApiResponse::error('ssl_cert cannot be empty when not using Let\'s Encrypt', 'MISSING_REQUIRED_FIELDS', 400);
                }

                if ($sslKey === '') {
                    return ApiResponse::error('ssl_key cannot be empty when not using Let\'s Encrypt', 'MISSING_REQUIRED_FIELDS', 400);
                }
            }
        }

        return null;
    }

    /**
     * Create a Wings client for a given node.
     */
    private function createWings(array $node): Wings
    {
        return Wings::fromNode($node, 30);
    }

    /**
     * Helper method to log server activity.
     */
    private function logActivity(array $server, array $node, string $event, array $metadata, array $user): void
    {
        ServerActivity::createActivity([
            'server_id' => $server['id'],
            'node_id' => $server['node_id'],
            'user_id' => $user['id'],
            'ip' => $user['last_ip'],
            'event' => $event,
            'metadata' => json_encode($metadata),
        ]);
    }
}
