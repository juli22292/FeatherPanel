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

import { useEffect, useState } from 'react';
import axios from 'axios';
import { ScrollText } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/featherui/Button';
import { PageCard } from '@/components/featherui/PageCard';
import { PageHeader } from '@/components/featherui/PageHeader';
import { PageLoading } from '@/components/featherui/PageLoading';
import { Textarea } from '@/components/featherui/Textarea';
import { Switch } from '@/components/ui/switch';
import { useSession } from '@/contexts/SessionContext';
import { useSettings } from '@/contexts/SettingsContext';
import { useTranslation } from '@/contexts/TranslationContext';
import { getApiErrorMessage } from '@/lib/api-errors';
import Permissions from '@/lib/permissions';
import type { PanelChangelog } from '@/types/changelog';

const endpoint = '/api/admin/changelogs';

export default function ChangelogSettingsPage() {
    const { t } = useTranslation();
    const { hasPermission } = useSession();
    const { refetch } = useSettings();
    const canEdit = hasPermission(Permissions.ADMIN_SETTINGS_EDIT);
    const [config, setConfig] = useState<PanelChangelog | null>();
    const [version, setVersion] = useState('');
    const [draft, setDraft] = useState('');
    const [saving, setSaving] = useState(false);
    const selected = config?.releases.find((release) => release.version === version);
    const dirty = !!selected && draft !== selected.body;
    const draftValid = draft.trim().length > 0 && new TextEncoder().encode(draft).length <= 20000;

    useEffect(() => {
        const controller = new AbortController();
        axios
            .get<{ data: PanelChangelog }>(endpoint, { signal: controller.signal })
            .then(({ data }) => {
                setConfig(data.data);
                const initial = data.data.releases[0];
                if (initial) {
                    setVersion(initial.version);
                    setDraft(initial.body);
                }
            })
            .catch((error: unknown) => {
                if (!axios.isCancel(error)) setConfig(null);
            });
        return () => controller.abort();
    }, []);

    async function setVisibility(enabled: boolean) {
        setSaving(true);
        try {
            const { data } = await axios.patch<{ data: PanelChangelog }>(endpoint, { enabled });
            setConfig(data.data);
            await refetch();
            toast.success(t('changelogs.saved'));
        } catch (error) {
            toast.error(getApiErrorMessage(error, t, 'changelogs.saveFailed'));
        } finally {
            setSaving(false);
        }
    }

    async function saveNotes(body: string | null) {
        if (!selected) return;
        setSaving(true);
        try {
            const { data } = await axios.put<{ data: PanelChangelog }>(endpoint + '/' + selected.version, { body });
            setConfig(data.data);
            const saved = data.data.releases.find((release) => release.version === selected.version);
            if (saved) setDraft(saved.body);
            toast.success(t('changelogs.saved'));
        } catch (error) {
            toast.error(getApiErrorMessage(error, t, 'changelogs.saveFailed'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className='space-y-5'>
            <PageHeader
                title={t('changelogs.settings')}
                description={t('changelogs.settingsDescription')}
                icon={ScrollText}
            />
            {config === undefined ? (
                <PageLoading />
            ) : config === null ? (
                <p role='alert' className='text-destructive'>
                    {t('changelogs.loadFailed')}
                </p>
            ) : (
                <>
                    <PageCard>
                        <div className='flex items-center justify-between gap-4'>
                            <div className='space-y-1'>
                                <label htmlFor='changelogs-visible' className='font-medium'>
                                    {t('changelogs.visibility')}
                                </label>
                                <p className='text-muted-foreground text-sm'>{t('changelogs.visibilityHint')}</p>
                            </div>
                            <Switch
                                id='changelogs-visible'
                                checked={config.enabled}
                                disabled={!canEdit || saving}
                                onCheckedChange={(enabled) => void setVisibility(enabled)}
                            />
                        </div>
                    </PageCard>
                    {selected && (
                        <PageCard title={t('changelogs.notes')}>
                            <form
                                className='space-y-4'
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    if (dirty && draftValid && canEdit && !saving) void saveNotes(draft);
                                }}
                            >
                                <label className='block max-w-xs space-y-2'>
                                    <span className='text-sm font-medium'>{t('changelogs.version')}</span>
                                    <select
                                        className='bg-background w-full rounded-md border px-3 py-2 text-sm'
                                        value={version}
                                        disabled={saving || dirty}
                                        onChange={(event) => {
                                            const release = config.releases.find(
                                                (entry) => entry.version === event.target.value,
                                            );
                                            if (release) {
                                                setVersion(release.version);
                                                setDraft(release.body);
                                            }
                                        }}
                                    >
                                        {config.releases.map((release) => (
                                            <option key={release.version} value={release.version}>
                                                {release.version}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className='block space-y-2'>
                                    <span className='text-sm font-medium'>{t('changelogs.markdown')}</span>
                                    <Textarea
                                        rows={12}
                                        value={draft}
                                        disabled={!canEdit || saving}
                                        onChange={(event) => setDraft(event.target.value)}
                                    />
                                </label>
                                {canEdit && (
                                    <div className='flex flex-wrap gap-2'>
                                        <Button type='submit' disabled={saving || !dirty || !draftValid}>
                                            {t('common.save')}
                                        </Button>
                                        {dirty && (
                                            <Button
                                                type='button'
                                                variant='outline'
                                                disabled={saving}
                                                onClick={() => setDraft(selected.body)}
                                            >
                                                {t('common.discard')}
                                            </Button>
                                        )}
                                        {selected.edited && (
                                            <Button
                                                type='button'
                                                variant='outline'
                                                disabled={saving || dirty}
                                                onClick={() => void saveNotes(null)}
                                            >
                                                {t('changelogs.reset')}
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </form>
                        </PageCard>
                    )}
                </>
            )}
        </div>
    );
}
