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

namespace App\Controllers\User;

use App\App;
use App\Cache\Cache;
use App\Chat\VmInstance;
use App\Chat\VmNode;
use App\Config\ConfigInterface;
use App\Helpers\ApiResponse;
use App\Services\Vm\VmInstanceUtil;
use Symfony\Component\HttpFoundation\Response;

final class VmStatusController
{
    public function getStatus(): Response
    {
        $config = App::getInstance(true)->getConfig();
        if ($config->getSetting(ConfigInterface::STATUS_PAGE_ENABLED, 'false') !== 'true'
            || $config->getSetting(ConfigInterface::MODULE_VDS_ENABLED, 'true') !== 'true') {
            return ApiResponse::error('Status page is disabled', 'STATUS_PAGE_DISABLED', 403);
        }

        $showHealth = $config->getSetting(ConfigInterface::STATUS_PAGE_SHOW_NODE_STATUS, 'true') === 'true';
        $showLoad = $config->getSetting(ConfigInterface::STATUS_PAGE_SHOW_LOAD_USAGE, 'true') === 'true';
        $showNodes = $config->getSetting(ConfigInterface::STATUS_PAGE_SHOW_INDIVIDUAL_NODES, 'false') === 'true';
        $data = [];
        if ($showHealth || $showLoad || $showNodes) {
            $data = $this->nodeStats(VmNode::getAllVmNodes(), $showHealth, $showLoad, $showNodes);
        }
        if ($config->getSetting(ConfigInterface::STATUS_PAGE_SHOW_TOTAL_SERVERS, 'true') === 'true') {
            $data['total_servers'] = VmInstance::countAll();
        }

        return ApiResponse::success([
            'enabled' => true,
            'show_raw_values' => $config->getSetting(ConfigInterface::STATUS_PAGE_SHOW_RAW_VALUES, 'false') === 'true',
            'maintenance' => [
                'enabled' => $config->getSetting(ConfigInterface::MAINTENANCE_MODE_ENABLED, 'false') === 'true',
                'message' => (string) $config->getSetting(ConfigInterface::MAINTENANCE_MODE_MESSAGE, ''),
            ],
            'data' => $data,
        ]);
    }

    private function nodeStats(array $nodes, bool $showHealth, bool $showLoad, bool $showNodes): array
    {
        $online = 0;
        $resources = ['total_memory' => 0, 'used_memory' => 0, 'total_disk' => 0, 'used_disk' => 0];
        $cpuTotal = 0.0;
        $rows = [];

        foreach ($nodes as $node) {
            $id = (int) $node['id'];
            $probe = $this->probe($id, $node);
            if ($probe['online']) {
                ++$online;
                $load = $probe['utilization'];
                $resources['total_memory'] += $load['memory_total'];
                $resources['used_memory'] += $load['memory_used'];
                $resources['total_disk'] += $load['disk_total'];
                $resources['used_disk'] += $load['disk_used'];
                $cpuTotal += $load['cpu_percent'];
            }

            if ($showNodes) {
                $row = ['id' => $id, 'name' => $node['name'], 'status' => $probe['online'] ? 'healthy' : 'unhealthy'];
                if ($showLoad) {
                    $row['utilization'] = $probe['utilization'];
                    $row['cpu_count'] = $probe['cpu_count'];
                }
                $rows[] = $row;
            }
        }

        $data = [];
        if ($showHealth || $showLoad) {
            $data['global'] = ['total_nodes' => count($nodes), 'healthy_nodes' => $online, 'unhealthy_nodes' => count($nodes) - $online];
            if ($showLoad) {
                $data['global'] += $resources + ['avg_cpu_percent' => $online > 0 ? round($cpuTotal / $online, 2) : 0];
            }
        }
        if ($showNodes) {
            $data['nodes'] = $rows;
        }

        return $data;
    }

    private function probe(int $id, array $node): array
    {
        $key = 'vds_status_probe:' . $id;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $probe = ['online' => false, 'utilization' => null, 'cpu_count' => null];
        try {
            $node['timeout'] = min(3, max(1, (int) ($node['timeout'] ?? 3)));
            $result = VmInstanceUtil::buildProxmoxClientForNode($node)->apiGet('/api2/json/cluster/resources', ['type' => 'node']);
            if ($result['ok'] && is_array($result['data'] ?? null)) {
                $probe = self::summarizeCluster($result['data']);
            }
        } catch (\Throwable) {
            App::getInstance(true)->getLogger()->warning('VDS status probe failed for panel node ' . $id);
        }

        Cache::put($key, $probe, 1);

        return $probe;
    }

    public static function summarizeCluster(array $nodes): array
    {
        $online = 0;
        $cores = 0;
        $usage = ['cpu_percent' => 0.0, 'memory_total' => 0, 'memory_used' => 0, 'disk_total' => 0, 'disk_used' => 0];

        foreach ($nodes as $node) {
            if (!is_array($node) || ($node['type'] ?? null) !== 'node' || ($node['status'] ?? null) !== 'online') {
                continue;
            }
            ++$online;
            $cores += (int) ($node['maxcpu'] ?? 0);
            $usage['cpu_percent'] += (float) ($node['cpu'] ?? 0) * 100;
            $usage['memory_total'] += (int) ($node['maxmem'] ?? 0);
            $usage['memory_used'] += (int) ($node['mem'] ?? 0);
            $usage['disk_total'] += (int) ($node['maxdisk'] ?? 0);
            $usage['disk_used'] += (int) ($node['disk'] ?? 0);
        }

        if ($online === 0) {
            return ['online' => false, 'utilization' => null, 'cpu_count' => null];
        }

        $usage['cpu_percent'] = round($usage['cpu_percent'] / $online, 2);

        return ['online' => true, 'utilization' => $usage, 'cpu_count' => $cores];
    }
}
