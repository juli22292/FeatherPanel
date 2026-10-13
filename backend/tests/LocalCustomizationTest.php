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

use App\Services\FeatherCloud\FeatherPanelPremium;
use PHPUnit\Framework\TestCase;

final class LocalCustomizationTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        $this->original = getenv('FEATHERPANEL_LOCAL_CUSTOMIZATION');
    }

    protected function tearDown(): void
    {
        if ($this->original === false) {
            putenv('FEATHERPANEL_LOCAL_CUSTOMIZATION');
        } else {
            putenv('FEATHERPANEL_LOCAL_CUSTOMIZATION=' . $this->original);
        }
    }

    public function testLocalCustomizationCanEnableSelfHostedControls(): void
    {
        putenv('FEATHERPANEL_LOCAL_CUSTOMIZATION=true');

        self::assertTrue(FeatherPanelPremium::localCustomizationEnabled());
        self::assertTrue(FeatherPanelPremium::canCustomizeUi());
        self::assertTrue(FeatherPanelPremium::canCustomSidebar());
    }

    public function testLocalCustomizationIsOptInOutsideForkCompose(): void
    {
        putenv('FEATHERPANEL_LOCAL_CUSTOMIZATION=false');

        self::assertFalse(FeatherPanelPremium::localCustomizationEnabled());
    }
}
