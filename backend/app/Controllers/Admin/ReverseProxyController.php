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
use App\Config\ConfigInterface;
use App\Helpers\ApiResponse;
use App\Helpers\DemoCloudHelper;
use App\Services\Servers\ReverseProxyAddresses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReverseProxyController
{
    public function show(): Response
    {
        $spells = array_map(static fn (array $spell): array => [
            'id' => (int) $spell['id'],
            'name' => (string) $spell['name'],
        ], Spell::getAllSpells());

        return ApiResponse::success(['rules' => ReverseProxyAddresses::configuredRules(), 'spells' => $spells]);
    }

    public function update(Request $request): Response
    {
        if (DemoCloudHelper::isBlocked()) {
            return DemoCloudHelper::denyResponse();
        }

        $body = json_decode($request->getContent(), true);
        $rules = ReverseProxyAddresses::normalizeRules(is_array($body) ? ($body['rules'] ?? null) : null);
        if ($rules === null) {
            return ApiResponse::error('Use valid IP addresses and avoid overlapping spell selections.', 'INVALID_REVERSE_PROXY_RULES', 422);
        }
        $spellIds = $rules === [] ? [] : array_values(array_unique(array_merge(...array_column($rules, 'spell_ids'))));
        if ($spellIds !== [] && count(Spell::getSpellsByIds($spellIds)) !== count($spellIds)) {
            return ApiResponse::error('Select only spells that exist.', 'UNKNOWN_REVERSE_PROXY_SPELL', 422);
        }

        $encoded = json_encode($rules, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 24000) {
            return ApiResponse::error('Use fewer reverse proxy rules or spells.', 'INVALID_REVERSE_PROXY_RULES', 422);
        }
        if (!App::getInstance(true)->getConfig()->setSetting(ConfigInterface::SERVER_REVERSE_PROXY_RULES, $encoded)) {
            return ApiResponse::error('Could not save reverse proxy rules.', 'REVERSE_PROXY_SAVE_FAILED', 500);
        }

        return ApiResponse::success(['rules' => $rules]);
    }
}
