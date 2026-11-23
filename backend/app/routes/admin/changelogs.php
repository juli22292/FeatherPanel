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
use App\Permissions;
use App\Controllers\ChangelogsController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $routes): void {
    $app = App::getInstance(true);
    $app->registerAdminRoute(
        $routes,
        'admin-changelogs-view',
        '/api/admin/changelogs',
        static fn () => (new ChangelogsController())->show(true),
        Permissions::ADMIN_SETTINGS_VIEW,
        ['GET'],
    );
    $app->registerAdminRoute(
        $routes,
        'admin-changelogs-toggle',
        '/api/admin/changelogs',
        static fn (Request $request) => (new ChangelogsController())->setEnabled($request),
        Permissions::ADMIN_SETTINGS_EDIT,
        ['PATCH'],
    );
    $app->registerAdminRoute(
        $routes,
        'admin-changelogs-save',
        '/api/admin/changelogs/{version}',
        static fn (Request $request, array $args) => (new ChangelogsController())->saveNotes($request, $args['version']),
        Permissions::ADMIN_SETTINGS_EDIT,
        ['PUT'],
    );
};
