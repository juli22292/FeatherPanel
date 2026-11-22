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

namespace App\Services\Vm;

use App\Services\Proxmox\Proxmox;

final class KvmGuestAccess implements GuestAccountClient
{
    public function __construct(private Proxmox $client, private string $node, private int $vmid)
    {
    }

    public function inspect(): ?array
    {
        $command = <<<'SH'
test "$(uname -s)" = Linux || exit 2
guest=$(cat /etc/machine-id) || exit 2
printf 'guest:%s\n' "$guest"
if test -f /var/lib/cloud/instance/boot-finished; then printf 'cloud:ready\n'; fi
min=$(awk '$1 == "UID_MIN" && $2 ~ /^[0-9]+$/ {print $2; exit}' /etc/login.defs 2>/dev/null)
max=$(awk '$1 == "UID_MAX" && $2 ~ /^[0-9]+$/ {print $2; exit}' /etc/login.defs 2>/dev/null)
test -n "$min" || min=1000
test -n "$max" || max=60000
awk -F: -v min="$min" -v max="$max" '
    ($1 == "root" && $3 == 0) || ($3 >= min && $3 <= max && $3 != 65534) {print $3 ":" $1}
' /etc/passwd || exit 2
if test -r /etc/shadow; then
    while IFS=: read -r name hash ignored; do
        case "$hash" in
            '$6$'*)
                digest=$(printf '%s' "$hash" | sha256sum)
                printf 'digest:%s:%s\n' "$name" "${digest%% *}"
                ;;
        esac
    done < /etc/shadow
fi
if test -x /usr/sbin/sshd; then
    /usr/sbin/sshd -T 2>/dev/null | awk '$1 == "port" {print "port:" $2}'
fi
SH;
        $output = $this->execute($command);

        return $output === null ? null : VmGuestAccounts::parseInventory($output);
    }

    public function setPassword(string $username, string $hash): bool
    {
        return $this->client->guestAgent($this->node, $this->vmid, 'set-user-password', [
            'username' => $username,
            'password' => $hash,
            'crypted' => 1,
        ]) !== null;
    }

    public function createAccount(string $username, string $hash): bool
    {
        $command = <<<'SH'
set -e
PATH=/usr/sbin:/usr/bin:/sbin:/bin
useradd --create-home --shell /bin/sh -- "$1"
chpasswd -e
SH;

        // The login name is a positional parameter. Pass the secret only on stdin.
        return $this->execute($command, [$username], $username . ':' . $hash . "\n") !== null;
    }

    private function execute(string $script, array $arguments = [], ?string $input = null): ?string
    {
        $body = ['command' => ['/bin/sh', '-c', str_replace("\r\n", "\n", $script), 'serverpanel-guest', ...$arguments]];
        if ($input !== null) {
            $body['input-data'] = $input;
        }
        $started = $this->client->guestAgent($this->node, $this->vmid, 'exec', $body);
        $pid = (int) ($started['pid'] ?? 0);
        if ($pid <= 0) {
            return null;
        }
        for ($attempt = 0; $attempt < 12; ++$attempt) {
            $status = $this->client->guestAgent($this->node, $this->vmid, 'exec-status', ['pid' => $pid]);
            if ($status === null) {
                return null;
            }
            if (!empty($status['exited'])) {
                return (int) ($status['exitcode'] ?? -1) === 0 && empty($status['out-truncated'])
                    ? (string) ($status['out-data'] ?? '')
                    : null;
            }
            usleep(150000);
        }

        return null;
    }
}
