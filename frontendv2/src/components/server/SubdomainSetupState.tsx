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

'use client';

import { Globe } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/featherui/Button';
import { EmptyState } from '@/components/featherui/EmptyState';
import { useSession } from '@/contexts/SessionContext';
import { useTranslation } from '@/contexts/TranslationContext';
import Permissions from '@/lib/permissions';
import type { ServerSpell, SubdomainOverview } from '@/types/server';

interface SubdomainSetupStateProps {
    overview: SubdomainOverview | null;
    spell?: ServerSpell;
    onBack?: () => void;
}

export function SubdomainSetupState({ overview, spell, onBack }: SubdomainSetupStateProps) {
    const { t } = useTranslation();
    const router = useRouter();
    const { hasPermission } = useSession();
    const requestAllowed = overview?.spell_allowed !== false;
    const canConfigure = overview !== null && hasPermission(Permissions.ADMIN_SUBDOMAINS_EDIT);
    const settingsHref = requestAllowed
        ? `/admin/subdomains${spell ? `?map_spell=${spell.id}` : ''}`
        : '/admin/subdomains/requests';
    const description = !overview
        ? t('serverSubdomains.loadFailed')
        : !requestAllowed
          ? t('serverSubdomains.spellRequestsDisabled')
          : spell?.name
            ? t('serverSubdomains.missingSpellMapping', { spell: spell.name })
            : t('serverSubdomains.noDomainsAvailable');

    return (
        <EmptyState
            icon={Globe}
            title={t('serverSubdomains.noSubdomains')}
            description={description}
            action={
                canConfigure ? (
                    <Button variant='outline' onClick={() => router.push(settingsHref)}>
                        {requestAllowed ? t('serverSubdomains.configureDomainMapping') : t('subdomainRequests.title')}
                    </Button>
                ) : onBack ? (
                    <Button variant='outline' onClick={onBack}>
                        {t('common.goBack')}
                    </Button>
                ) : undefined
            }
        />
    );
}
