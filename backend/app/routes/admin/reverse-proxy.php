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

use App\App;
use App\Controllers\Admin\ReverseProxyController;
use App\Permissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

return function (RouteCollection $routes): void {
    $app = App::getInstance(true);
    $app->registerAdminRoute(
        $routes,
        'admin-reverse-proxy-view',
        '/api/admin/reverse-proxy',
        fn (Request $request) => (new ReverseProxyController())->show(),
        Permissions::ADMIN_SETTINGS_VIEW,
        ['GET'],
    );
    $app->registerAdminRoute(
        $routes,
        'admin-reverse-proxy-edit',
        '/api/admin/reverse-proxy',
        fn (Request $request) => (new ReverseProxyController())->update($request),
        Permissions::ADMIN_SETTINGS_EDIT,
        ['PUT'],
    );
    $app->registerAdminRoute(
        $routes,
        'admin-reverse-proxy-edge-edit',
        '/api/admin/reverse-proxy/edge',
        fn (Request $request) => (new ReverseProxyController())->updateEdge($request),
        Permissions::ADMIN_SETTINGS_EDIT,
        ['PUT'],
    );
};
