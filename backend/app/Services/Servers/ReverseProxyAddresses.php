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

namespace App\Services\Servers;

use App\App;
use App\Config\ConfigInterface;

/**
 * Public connection addresses advertised for game allocations. These rules never
 * alter daemon allocations, bind addresses, DNS, or proxy configuration.
 */
final class ReverseProxyAddresses
{
    public const MAX_RULES = 40;
    public const MAX_SPELLS_PER_RULE = 100;

    public static function publicIp(mixed $allocationIp, int $spellId, array $rules): ?string
    {
        $ip = self::normalizeIp($allocationIp);
        if ($ip === null) {
            return null;
        }

        foreach ($rules as $rule) {
            if ($rule['allocation_ip'] === $ip
                && ($rule['spell_ids'] === [] || in_array($spellId, $rule['spell_ids'], true))) {
                return $rule['public_ip'];
            }
        }

        return null;
    }

    public static function configuredRules(): array
    {
        $value = App::getInstance(true)->getConfig()->getSetting(ConfigInterface::SERVER_REVERSE_PROXY_RULES, '[]');

        return self::normalizeRules(json_decode($value ?? '[]', true)) ?? [];
    }

    public static function dnsIp(string $allocationIp, int $spellId, array $rules, ?string $nodePublicIp): ?string
    {
        return self::publicIp($allocationIp, $spellId, $rules)
            ?? (in_array($allocationIp, ['127.0.0.1', '0.0.0.0', 'localhost', '::1'], true)
                ? self::normalizeIp($nodePublicIp)
                : self::normalizeIp($allocationIp));
    }

    public static function normalizeRules(mixed $rules): ?array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > self::MAX_RULES) {
            return null;
        }

        $normalized = [];
        $scopes = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                return null;
            }
            $allocationIp = self::normalizeIp($rule['allocation_ip'] ?? null);
            $publicIp = self::normalizeIp($rule['public_ip'] ?? null);
            $spellIds = $rule['spell_ids'] ?? null;
            if ($allocationIp === null || $publicIp === null || !is_array($spellIds)
                || !array_is_list($spellIds) || count($spellIds) > self::MAX_SPELLS_PER_RULE) {
                return null;
            }
            foreach ($spellIds as $id) {
                if (!is_int($id) || $id < 1) {
                    return null;
                }
            }
            $spellIds = array_values(array_unique($spellIds));
            foreach ($scopes[$allocationIp] ?? [] as $existing) {
                if ($spellIds === [] || $existing === [] || array_intersect($spellIds, $existing) !== []) {
                    return null;
                }
            }
            $scopes[$allocationIp][] = $spellIds;
            $normalized[] = ['allocation_ip' => $allocationIp, 'public_ip' => $publicIp, 'spell_ids' => $spellIds];
        }

        return $normalized;
    }

    private static function normalizeIp(mixed $input): ?string
    {
        if (!is_string($input) || !filter_var($value = trim($input), FILTER_VALIDATE_IP)) {
            return null;
        }

        return inet_ntop(inet_pton($value)) ?: null;
    }
}
