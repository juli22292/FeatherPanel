/*
This file is part of FeatherPanel.

Copyright (C) 2025 MythicalSystems Studios
Copyright (C) 2025 FeatherPanel Contributors
Copyright (C) 2025 Cassian Gherman (aka NaysKutzu)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published
by the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

See the LICENSE file or <https://www.gnu.org/licenses/>.
*/

import { redirect } from 'next/navigation';
import FeatherOS from '@/components/featheros/FeatherOS';
import { settingsApi } from '@/lib/settings-api';
import { isEnabledUnlessExplicitlyFalse } from '@/lib/utils';

/** FeatherOS: desktop-like multi server workspace, opened in its own tab from the panel. */
export default async function FeatherOsPage() {
    const data = await settingsApi.getPublicSettings({ fresh: true });
    if (!isEnabledUnlessExplicitlyFalse(data?.settings?.module_featheros_enabled)) {
        redirect('/dashboard');
    }
    return <FeatherOS />;
}
