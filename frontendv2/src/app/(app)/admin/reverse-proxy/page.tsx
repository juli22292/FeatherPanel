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

'use client';

import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import axios from 'axios';
import { ArrowRightLeft, Loader2, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/featherui/Button';
import { Input } from '@/components/featherui/Input';
import { PageCard } from '@/components/featherui/PageCard';
import { PageHeader } from '@/components/featherui/PageHeader';
import { useSession } from '@/contexts/SessionContext';
import { useTranslation } from '@/contexts/TranslationContext';
import { getApiErrorMessage } from '@/lib/api-errors';
import Permissions from '@/lib/permissions';

interface ProxyRule {
    allocation_ip: string;
    public_ip: string;
    spell_ids: number[];
}

interface Spell {
    id: number;
    name: string;
}

interface RuleDraft extends ProxyRule {
    rowId: number;
    allSpells: boolean;
}

const endpoint = '/api/admin/reverse-proxy';
const maxRules = 40;

type EdgeSettings = { enabled: boolean; ipv4: string; ipv6: string; email: string };
const emptyEdge: EdgeSettings = { enabled: false, ipv4: '', ipv6: '', email: '' };

export default function ReverseProxyPage() {
    const { t } = useTranslation();
    const { hasPermission } = useSession();
    const canEdit = hasPermission(Permissions.ADMIN_SETTINGS_EDIT) || hasPermission(Permissions.ADMIN_ROOT);
    const [rules, setRules] = useState<RuleDraft[]>([]);
    const [spells, setSpells] = useState<Spell[]>([]);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [saving, setSaving] = useState(false);
    const [edge, setEdge] = useState<EdgeSettings>(emptyEdge);
    const [edgeAvailable, setEdgeAvailable] = useState(false);
    const [edgeSaving, setEdgeSaving] = useState(false);
    const nextId = useRef(0);

    const toDrafts = useCallback(
        (saved: ProxyRule[]) =>
            saved.map((rule) => ({ ...rule, allSpells: rule.spell_ids.length === 0, rowId: ++nextId.current })),
        [],
    );
    const load = useCallback(async () => {
        setLoading(true);
        setLoadError(false);
        try {
            const { data } = await axios.get<{
                data: { rules: ProxyRule[]; spells: Spell[]; edge: EdgeSettings; edge_available: boolean };
            }>(endpoint);
            setRules(toDrafts(data.data.rules));
            setSpells(data.data.spells);
            setEdge(data.data.edge);
            setEdgeAvailable(data.data.edge_available);
        } catch {
            setLoadError(true);
        } finally {
            setLoading(false);
        }
    }, [toDrafts]);

    useEffect(() => {
        void load();
    }, [load]);

    async function saveEdge(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!canEdit || edgeSaving) return;
        setEdgeSaving(true);
        try {
            const { data } = await axios.put<{ data: { edge: EdgeSettings } }>(endpoint + '/edge', edge);
            setEdge(data.data.edge);
            toast.success(t('reverse_proxy.edge_saved'));
        } catch (error) {
            toast.error(getApiErrorMessage(error, t, 'reverse_proxy.save_failed'));
        } finally {
            setEdgeSaving(false);
        }
    }

    function updateRule(id: number, change: Partial<RuleDraft>) {
        setRules((current) => current.map((rule) => (rule.rowId === id ? { ...rule, ...change } : rule)));
    }

    function addRule() {
        const rowId = ++nextId.current;
        setRules((current) => [
            ...current,
            { rowId, allocation_ip: '', public_ip: '', spell_ids: [], allSpells: false },
        ]);
    }

    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!canEdit || saving) return;
        if (rules.some((rule) => !rule.allSpells && rule.spell_ids.length === 0)) {
            toast.error(t('reverse_proxy.pick_spells'));
            return;
        }

        setSaving(true);
        try {
            const { data } = await axios.put<{ data: { rules: ProxyRule[] } }>(endpoint, {
                rules: rules.map(({ allocation_ip, public_ip, spell_ids, allSpells }) => ({
                    allocation_ip: allocation_ip.trim(),
                    public_ip: public_ip.trim(),
                    spell_ids: allSpells ? [] : spell_ids,
                })),
            });
            setRules(toDrafts(data.data.rules));
            toast.success(t('reverse_proxy.saved'));
        } catch (error) {
            toast.error(getApiErrorMessage(error, t, 'reverse_proxy.save_failed'));
        } finally {
            setSaving(false);
        }
    }

    const filter = search.trim().toLowerCase();
    const visibleSpells = filter ? spells.filter((spell) => spell.name.toLowerCase().includes(filter)) : spells;
    const existingSpellIds = new Set(spells.map((spell) => spell.id));
    return (
        <div className='space-y-6'>
            <PageHeader
                title={t('navigation.items.reverseProxy')}
                description={t('reverse_proxy.description')}
                icon={ArrowRightLeft}
            />
            {!loading && !loadError && (
                <PageCard title={t('reverse_proxy.edge_title')} icon={ArrowRightLeft}>
                    <form onSubmit={saveEdge} className='space-y-4'>
                        <p className='text-muted-foreground text-sm'>{t('reverse_proxy.edge_description')}</p>
                        <p className={edgeAvailable ? 'text-sm text-emerald-500' : 'text-sm text-amber-500'}>
                            {t(edgeAvailable ? 'reverse_proxy.edge_connected' : 'reverse_proxy.edge_unavailable')}
                        </p>
                        <label className='flex items-center gap-2 text-sm'>
                            <input
                                type='checkbox'
                                checked={edge.enabled}
                                disabled={!canEdit || edgeSaving}
                                onChange={(event) => setEdge({ ...edge, enabled: event.target.checked })}
                            />
                            {t('reverse_proxy.edge_enable')}
                        </label>
                        <div className='grid gap-4 md:grid-cols-3'>
                            <Input
                                label={t('reverse_proxy.edge_ipv4')}
                                value={edge.ipv4}
                                disabled={!canEdit || edgeSaving}
                                onChange={(event) => setEdge({ ...edge, ipv4: event.target.value })}
                            />
                            <Input
                                label={t('reverse_proxy.edge_ipv6')}
                                value={edge.ipv6}
                                disabled={!canEdit || edgeSaving}
                                onChange={(event) => setEdge({ ...edge, ipv6: event.target.value })}
                            />
                            <Input
                                type='email'
                                label={t('reverse_proxy.edge_email')}
                                value={edge.email}
                                disabled={!canEdit || edgeSaving}
                                onChange={(event) => setEdge({ ...edge, email: event.target.value })}
                            />
                        </div>
                        {canEdit && (
                            <Button type='submit' disabled={edgeSaving}>
                                {t('reverse_proxy.edge_save')}
                            </Button>
                        )}
                    </form>
                </PageCard>
            )}
            <PageCard title={t('reverse_proxy.rules')} icon={ArrowRightLeft}>
                {loading ? (
                    <Loader2
                        className='text-muted-foreground mx-auto my-8 h-6 w-6 animate-spin'
                        aria-label={t('common.loading')}
                    />
                ) : loadError ? (
                    <div className='space-y-3'>
                        <p role='alert' className='text-destructive text-sm'>
                            {t('reverse_proxy.load_failed')}
                        </p>
                        <Button type='button' variant='outline' onClick={() => void load()}>
                            {t('reverse_proxy.retry')}
                        </Button>
                    </div>
                ) : (
                    <form onSubmit={(event) => void save(event)} className='space-y-5'>
                        <p className='text-muted-foreground text-sm'>{t('reverse_proxy.network_note')}</p>
                        {rules.length > 0 && (
                            <Input
                                label={t('reverse_proxy.filter_spells')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        )}
                        {rules.length === 0 && (
                            <p className='text-muted-foreground text-sm'>{t('reverse_proxy.empty')}</p>
                        )}
                        {rules.map((rule) => (
                            <fieldset
                                key={rule.rowId}
                                disabled={!canEdit || saving}
                                className='border-border space-y-4 rounded-lg border p-4'
                            >
                                <div className='grid gap-4 sm:grid-cols-2'>
                                    <Input
                                        label={t('reverse_proxy.allocation_ip')}
                                        id={'proxy-in-' + rule.rowId}
                                        value={rule.allocation_ip}
                                        onChange={(e) => updateRule(rule.rowId, { allocation_ip: e.target.value })}
                                        maxLength={45}
                                        required
                                    />
                                    <Input
                                        label={t('reverse_proxy.public_ip')}
                                        id={'proxy-out-' + rule.rowId}
                                        value={rule.public_ip}
                                        onChange={(e) => updateRule(rule.rowId, { public_ip: e.target.value })}
                                        maxLength={45}
                                        required
                                    />
                                </div>
                                <fieldset className='space-y-2'>
                                    <legend className='text-sm font-semibold'>{t('reverse_proxy.spells')}</legend>
                                    <label className='flex items-center gap-2 text-sm'>
                                        <input
                                            type='checkbox'
                                            checked={rule.allSpells}
                                            onChange={(e) => updateRule(rule.rowId, { allSpells: e.target.checked })}
                                        />
                                        {t('reverse_proxy.all_spells')}
                                    </label>
                                    {!rule.allSpells && (
                                        <div className='border-border grid max-h-44 gap-2 overflow-auto rounded-md border p-3 sm:grid-cols-2'>
                                            {[
                                                ...visibleSpells,
                                                ...rule.spell_ids
                                                    .filter((id) => !existingSpellIds.has(id))
                                                    .map((id) => ({
                                                        id,
                                                        name: t('reverse_proxy.deleted_spell', { id: String(id) }),
                                                    })),
                                            ].map((spell) => (
                                                <label className='flex items-center gap-2 text-sm' key={spell.id}>
                                                    <input
                                                        type='checkbox'
                                                        checked={rule.spell_ids.includes(spell.id)}
                                                        onChange={(e) =>
                                                            updateRule(rule.rowId, {
                                                                spell_ids: e.target.checked
                                                                    ? [...rule.spell_ids, spell.id]
                                                                    : rule.spell_ids.filter((id) => id !== spell.id),
                                                            })
                                                        }
                                                    />
                                                    {spell.name}
                                                </label>
                                            ))}
                                            {visibleSpells.length === 0 && (
                                                <p className='text-muted-foreground text-sm'>
                                                    {t('reverse_proxy.no_matching_spells')}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </fieldset>
                                {canEdit && (
                                    <Button
                                        type='button'
                                        variant='outline'
                                        onClick={() =>
                                            setRules((current) => current.filter((row) => row.rowId !== rule.rowId))
                                        }
                                    >
                                        <Trash2 className='mr-2 h-4 w-4' />
                                        {t('reverse_proxy.remove_rule')}
                                    </Button>
                                )}
                            </fieldset>
                        ))}
                        {canEdit && (
                            <div className='flex flex-wrap gap-2'>
                                <Button
                                    type='button'
                                    variant='outline'
                                    onClick={addRule}
                                    disabled={saving || rules.length >= maxRules}
                                >
                                    <Plus className='mr-2 h-4 w-4' />
                                    {t('reverse_proxy.add_rule')}
                                </Button>
                                <Button type='submit' disabled={saving}>
                                    {t('reverse_proxy.save')}
                                </Button>
                            </div>
                        )}
                    </form>
                )}
            </PageCard>
        </div>
    );
}
