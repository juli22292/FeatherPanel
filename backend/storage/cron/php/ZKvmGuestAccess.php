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

namespace App\Cron;

use App\App;
use App\Config\ConfigInterface;
use App\Services\Vm\KvmGuestAccess;

class ZKvmGuestAccess implements TimeTask
{
    public function run()
    {
        if (App::getInstance(false, true)->getConfig()->getSetting(ConfigInterface::MODULE_VDS_ENABLED, 'true') === 'false') {
            return;
        }

        (new Cron('kvm-guest-access', '1M'))->runIfDue(KvmGuestAccess::provisionPending(...), getenv('FP_CRON_FORCE') === '1');
    }
}
