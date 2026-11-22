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

final class ReverseProxyEdge
{
    private const DEFAULTS = ['enabled' => false, 'ipv4' => '', 'ipv6' => '', 'email' => ''];
    private const SOCKET = '/run/serverpanel-edge/api.sock';

    public static function settings(): array
    {
        $raw = App::getInstance(true)->getConfig()->getSetting(ConfigInterface::SERVER_REVERSE_PROXY_EDGE, '{}');
        $decoded = json_decode($raw ?? '{}', true);

        return self::normalizeSettings($decoded) ?? self::DEFAULTS;
    }

    public static function normalizeSettings(mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }
        $values = array_replace(self::DEFAULTS, array_intersect_key($input, self::DEFAULTS));
        if (!is_bool($values['enabled'])) {
            return null;
        }
        foreach (['ipv4' => FILTER_FLAG_IPV4, 'ipv6' => FILTER_FLAG_IPV6] as $key => $flag) {
            if (!is_string($values[$key])) {
                return null;
            }
            $values[$key] = trim($values[$key]);
            if ($values[$key] !== '') {
                if (!filter_var($values[$key], FILTER_VALIDATE_IP, $flag)) {
                    return null;
                }
                $values[$key] = inet_ntop(inet_pton($values[$key]));
            }
        }
        if (!is_string($values['email'])) {
            return null;
        }
        $values['email'] = trim($values['email']);
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if ($values['enabled'] && ($values['email'] === '' || ($values['ipv4'] === '' && $values['ipv6'] === ''))) {
            return null;
        }

        return $values;
    }

    public static function domain(mixed $input): ?string
    {
        if (!is_string($input)) {
            return null;
        }
        $domain = strtolower(rtrim(trim($input), '.'));
        if (strlen($domain) > 234 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $domain)) {
            return null;
        }
        $panelHost = parse_url((string) App::getInstance(true)->getConfig()->getSetting(ConfigInterface::APP_URL, ''), PHP_URL_HOST);
        if (is_string($panelHost) && strcasecmp($panelHost, $domain) === 0) {
            return null;
        }

        return $domain;
    }

    public static function expectedIps(array $settings, array $server, array $allocation): ?array
    {
        $addresses = array_values(array_filter([$settings['ipv4'], $settings['ipv6']]));
        $mapped = ReverseProxyAddresses::publicIp($allocation['ip'] ?? null, (int) $server['spell_id'], ReverseProxyAddresses::configuredRules());

        return $mapped === null || in_array($mapped, $addresses, true) ? $addresses : null;
    }

    public static function request(string $path, ?array $payload = null): array
    {
        $handle = curl_init('http://localhost/v1/' . $path);
        if ($handle === false) {
            throw new \RuntimeException('Managed proxy socket is unavailable');
        }
        $options = [
            CURLOPT_UNIX_SOCKET_PATH => self::SOCKET,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $response = is_string($body) && strlen($body) <= 131072 ? json_decode($body, true) : null;
        if (!is_array($response) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Managed proxy service rejected the request', $status);
        }

        return $response;
    }
}
