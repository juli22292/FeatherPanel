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

namespace App\Controllers\User\Vds;

use App\App;
use App\Permissions;
use App\Helpers\PermissionHelper;
use App\Chat\User;
use App\Chat\Database;
use App\Helpers\VmGateway;
use App\Helpers\ApiResponse;
use App\Services\Vm\VdsReauth;
use App\Services\Vm\GuestAccountClient;
use App\Services\Vm\VmGuestAccounts;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class VmUserGuestAccessController
{
    public function handle(Request $request): Response
    {
        $user = $request->attributes->get('user');
        $instance = $request->attributes->get('vmInstance');
        $uuid = $user['uuid'] ?? '';
        $isOwner = $uuid !== '' && ($instance['user_uuid'] ?? null) === $uuid;
        $readOnly = $request->isMethod('GET');
        $isRoot = $uuid !== '' && PermissionHelper::hasPermission($uuid, Permissions::ADMIN_ROOT, $user);
        if (!is_array($instance) || !in_array($instance['vm_type'] ?? null, ['qemu', 'lxc'], true)
            || (!$isOwner && !$isRoot && (!$readOnly || $uuid === '' || !VmGateway::hasAdminAccess($uuid)))) {
            return ApiResponse::error('You do not have access to guest accounts for this instance', 'PERMISSION_DENIED', 403);
        }

        $db = Database::getPdoConnection();
        if ($readOnly) {
            $guest = VmGuestAccounts::connect($instance)?->inspect();
            $owner = $isOwner ? $user : (empty($instance['user_uuid']) ? [] : (User::getUserByUuid($instance['user_uuid']) ?? []));
            [$preferred, $pending] = self::preferredAccount($db, $instance, $owner, $guest);
            $passwords = ($isOwner || $isRoot) && $guest !== null
                ? VmGuestAccounts::passwords($db, $instance, $guest)
                : [];
            $accounts = [];
            foreach ($guest['accounts'] ?? [] as $name => $uid) {
                $name = (string) $name;
                $accounts[] = [
                    'username' => $name,
                    'kind' => $name === 'root' ? 'root' : ($name === $preferred ? 'standard' : 'additional'),
                    'has_password' => isset($passwords[$name]),
                ];
            }

            return self::privateResponse([
                'hostname' => $instance['hostname'],
                'ip' => $instance['ip_address'],
                'ssh_ports' => $guest['ssh_ports'] ?? [],
                'accounts' => $accounts,
                'agent_available' => $guest !== null,
                'preferred_username' => $preferred,
                'setup_pending' => $pending,
                'additional_limit' => VmGuestAccounts::ADDITIONAL_LIMIT,
            ]);
        }

        $input = json_decode($request->getContent(), true);
        $action = $input['action'] ?? null;
        $name = $input['username'] ?? null;
        if (!in_array($action, ['add', 'reset', 'reveal'], true) || !is_string($name) ||
            !preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.@-]{0,62}\$?$/D', $name)) {
            return ApiResponse::error('Invalid guest account or action', 'VALIDATION_FAILED', 422);
        }
        if (!$isOwner && $action !== 'reveal') {
            return ApiResponse::error('Only the owner can change guest accounts for this instance', 'PERMISSION_DENIED', 403);
        }
        if ($reauth = VdsReauth::check($request, 'guest_access')) {
            return $reauth;
        }

        $db->beginTransaction();
        try {
            $vm = VmGuestAccounts::lockInstance($db, (int) $instance['id'], (string) ($instance['user_uuid'] ?? ''));
            if (!$vm) {
                return ApiResponse::error('The instance is currently unavailable', 'PERMISSION_DENIED', 403);
            }
            $client = VmGuestAccounts::connect($vm);
            $guest = $client?->inspect();
            if (!$guest) {
                return self::agentUnavailable();
            }
            if ($action === 'add') {
                return self::add($db, $vm, $user, $client, $guest, $name);
            }
            if (!array_key_exists($name, $guest['accounts'])) {
                return ApiResponse::error('The Linux account was not found in the guest', 'KVM_GUEST_ACCOUNT_NOT_FOUND', 404);
            }
            if ($action === 'reveal') {
                $encrypted = VmGuestAccounts::passwords($db, $vm, $guest)[$name] ?? null;
                if ($encrypted === null) {
                    return ApiResponse::error('No panel-generated password is available for this account', 'KVM_GUEST_PASSWORD_UNAVAILABLE', 404);
                }

                return self::privateResponse(['password' => App::getInstance(true)->decryptValue($encrypted)]);
            }

            $password = VmGuestAccounts::password();
            $hash = VmGuestAccounts::passwordHash($password);
            if (!$client->setPassword($name, $hash)) {
                return self::agentUnavailable();
            }
            VmGuestAccounts::savePassword($db, $vm, $guest, $name, $password, $hash);
            $db->commit();

            return self::privateResponse([]);
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }

    private static function add(\PDO $db, array $vm, array $user, GuestAccountClient $client, array $guest, string $name): Response
    {
        if (!preg_match('/^[a-z][a-z0-9]{0,31}$/D', $name) || $name === 'root') {
            return ApiResponse::error('Use 1–32 lowercase letters or digits, starting with a letter', 'VALIDATION_FAILED', 422);
        }
        if (array_key_exists($name, $guest['accounts'])) {
            return ApiResponse::error('The Linux account already exists', 'KVM_GUEST_ACCOUNT_EXISTS', 409);
        }
        [$preferred] = self::preferredAccount($db, $vm, $user, $guest);
        $additional = count(array_diff(array_keys($guest['accounts']), ['root', $preferred]));
        if ($name !== $preferred && $additional >= VmGuestAccounts::ADDITIONAL_LIMIT) {
            return ApiResponse::error('This VM has reached the limit for additional Linux accounts', 'KVM_GUEST_ACCOUNT_LIMIT', 409);
        }

        $password = VmGuestAccounts::password();
        $hash = VmGuestAccounts::passwordHash($password);
        if (!$client->createAccount($name, $hash)) {
            return self::agentUnavailable();
        }
        $updated = $client->inspect();
        if ($updated === null || $updated['guest_id'] !== $guest['guest_id'] || !isset($updated['accounts'][$name])) {
            return self::agentUnavailable();
        }
        VmGuestAccounts::savePassword($db, $vm, $updated, $name, $password, $hash);
        $db->commit();

        return self::privateResponse([], 201);
    }

    private static function preferredAccount(\PDO $db, array $vm, array $user, ?array $guest): array
    {
        $stmt = $db->prepare('SELECT username, retry_at FROM featherpanel_vm_guest_setup WHERE instance_id = ? AND owner_uuid = ? AND vmid = ?');
        $stmt->execute([$vm['id'], $vm['user_uuid'], $vm['vmid']]);
        if ($setup = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            return [$setup['username'], $setup['retry_at'] !== null];
        }

        $preferred = VmGuestAccounts::defaultUsername((string) ($user['username'] ?? ''));
        $notes = json_decode((string) ($vm['notes'] ?? ''), true);
        $previous = $notes['ci_user'] ?? null;
        if (!isset($guest['accounts'][$preferred]) && is_string($previous) && $previous !== 'root' && isset($guest['accounts'][$previous])) {
            return [$previous, false];
        }

        return [VmGuestAccounts::primaryUsername($preferred, $guest['accounts'] ?? []), false];
    }

    private static function agentUnavailable(): Response
    {
        return ApiResponse::error('The guest account action could not be confirmed. Refresh the account list and check the guest connection.', 'KVM_GUEST_AGENT_UNAVAILABLE', 503);
    }

    private static function privateResponse(array $data, int $status = 200): Response
    {
        $response = ApiResponse::success($data, 'OK', $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
