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
import { useRouter } from 'next/navigation';
import axios from 'axios';
import { ClipboardList } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/featherui/Button';
import { PageCard } from '@/components/featherui/PageCard';
import { PageHeader } from '@/components/featherui/PageHeader';
import { Textarea } from '@/components/featherui/Textarea';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useSession } from '@/contexts/SessionContext';
import { useTranslation } from '@/contexts/TranslationContext';
import { getApiErrorMessage } from '@/lib/api-errors';
import Permissions from '@/lib/permissions';
import type { SubdomainRequestEntry } from '@/types/server';

type RequestRow = SubdomainRequestEntry & { server_name: string; requester: string | null };
type RequestList = {
    requests: RequestRow[];
    total: number;
    allowed_spell_ids: number[];
    spells: { id: number; name: string }[];
};
type Filter = 'all' | RequestRow['status'];
type Decision = 'approved' | 'rejected';

const endpoint = '/api/admin/subdomains/requests';
const pageSize = 50;

export default function AdminSubdomainRequests() {
    const { t } = useTranslation();
    const { hasPermission } = useSession();
    const router = useRouter();
    const canEdit = hasPermission(Permissions.ADMIN_SUBDOMAINS_EDIT);
    const [queue, setQueue] = useState<RequestList>();
    const [selection, setSelection] = useState<number[]>();
    const [filter, setFilter] = useState<Filter>('pending');
    const [page, setPage] = useState(1);
    const [refresh, setRefresh] = useState(0);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [review, setReview] = useState<RequestRow>();
    const [reason, setReason] = useState('');
    const [deciding, setDeciding] = useState(false);
    const labels = {
        all: t('subdomainRequests.all'),
        pending: t('subdomainRequests.pending'),
        approved: t('subdomainRequests.approved'),
        rejected: t('subdomainRequests.rejected'),
    };

    useEffect(() => {
        const controller = new AbortController();
        axios
            .get<{ data: RequestList }>(endpoint, { params: { status: filter, page }, signal: controller.signal })
            .then(({ data: { data } }) => {
                const lastPage = Math.max(1, Math.ceil(data.total / pageSize));
                if (page > lastPage) {
                    setPage(lastPage);
                    return;
                }
                setQueue(data);
                setSelection((current) => current ?? data.allowed_spell_ids);
            })
            .catch((error: unknown) => {
                if (!axios.isCancel(error)) {
                    setQueue(undefined);
                    toast.error(getApiErrorMessage(error, t, 'subdomainRequests.loadFailed'));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [filter, page, refresh, t]);

    async function saveSpells() {
        if (!canEdit) return;
        setSaving(true);
        try {
            await axios.patch(endpoint + '/spells', { spell_ids: selection });
            toast.success(t('subdomainRequests.saved'));
        } catch (error) {
            toast.error(getApiErrorMessage(error, t, 'subdomainRequests.saveFailed'));
        } finally {
            setSaving(false);
        }
    }

    async function decide(decision: Decision) {
        if (!review || !canEdit || deciding) return;
        setDeciding(true);
        try {
            await axios.post(endpoint + '/' + review.id + '/decision', { decision, reason: reason.trim() });
            setReview(undefined);
            setLoading(true);
            setRefresh((value) => value + 1);
            toast.success(t('subdomainRequests.updated'));
        } catch (error) {
            toast.error(getApiErrorMessage(error, t, 'subdomainRequests.reviewFailed'));
        } finally {
            setDeciding(false);
        }
    }

    function changePage(next: number) {
        setLoading(true);
        setPage(next);
    }

    return (
        <div className='space-y-6'>
            <PageHeader
                title={t('subdomainRequests.title')}
                description={t('subdomainRequests.description')}
                icon={ClipboardList}
                actions={
                    <Button variant='outline' onClick={() => router.push('/admin/subdomains')}>
                        {t('admin.subdomains.title')}
                    </Button>
                }
            />
            {queue && selection !== undefined && (
                <PageCard title={t('subdomainRequests.spells')} description={t('subdomainRequests.spellDescription')}>
                    <form
                        className='space-y-4'
                        onSubmit={(event) => {
                            event.preventDefault();
                            void saveSpells();
                        }}
                    >
                        <div className='grid max-h-72 gap-2 overflow-auto sm:grid-cols-2 lg:grid-cols-3'>
                            {queue.spells.map((spell) => (
                                <label key={spell.id} className='flex items-start gap-2 rounded-md border p-2.5'>
                                    <input
                                        type='checkbox'
                                        className='accent-primary mt-1 shrink-0'
                                        checked={selection.includes(spell.id)}
                                        disabled={!canEdit || saving}
                                        onChange={(event) => {
                                            const checked = event.target.checked;
                                            setSelection((current = []) =>
                                                checked
                                                    ? [...current, spell.id]
                                                    : current.filter((id) => id !== spell.id),
                                            );
                                        }}
                                    />
                                    <span className='break-words'>{spell.name}</span>
                                </label>
                            ))}
                        </div>
                        {canEdit && (
                            <Button type='submit' disabled={saving}>
                                {t('common.save')}
                            </Button>
                        )}
                    </form>
                </PageCard>
            )}
            <PageCard title={t('subdomainRequests.title')}>
                <div className='flex flex-wrap gap-2'>
                    {(Object.keys(labels) as Filter[]).map((status) => (
                        <Button
                            key={status}
                            size='sm'
                            variant={filter === status ? 'default' : 'outline'}
                            aria-pressed={filter === status}
                            onClick={() => {
                                if (filter === status) return;
                                changePage(1);
                                setFilter(status);
                            }}
                        >
                            {labels[status]}
                        </Button>
                    ))}
                </div>
                {loading ? (
                    <p role='status' className='text-muted-foreground mt-4 text-sm'>
                        {t('common.loading')}
                    </p>
                ) : queue?.requests.length ? (
                    <ul className='mt-4 divide-y'>
                        {queue.requests.map((item) => (
                            <li key={item.id} className='flex flex-wrap items-start justify-between gap-3 py-3'>
                                <div className='min-w-0 space-y-1'>
                                    <p className='font-mono font-medium break-all'>
                                        {item.subdomain}.{item.domain}
                                    </p>
                                    <p className='text-muted-foreground text-sm'>
                                        {[item.server_name, item.requester, labels[item.status]]
                                            .filter(Boolean)
                                            .join(' / ')}
                                    </p>
                                    {item.reason && (
                                        <p className='text-sm break-words whitespace-pre-wrap'>{item.reason}</p>
                                    )}
                                </div>
                                {canEdit && item.status === 'pending' && (
                                    <Button
                                        variant='outline'
                                        size='sm'
                                        onClick={() => {
                                            setReason('');
                                            setReview(item);
                                        }}
                                    >
                                        {t('subdomainRequests.review')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className='text-muted-foreground mt-4 text-sm'>
                        {queue ? t('subdomainRequests.empty') : t('subdomainRequests.loadFailed')}
                    </p>
                )}
                {!!queue && (page > 1 || queue.total > page * pageSize) && (
                    <div className='mt-4 flex flex-wrap items-center gap-2'>
                        <Button variant='outline' disabled={loading || page <= 1} onClick={() => changePage(page - 1)}>
                            {t('common.previous')}
                        </Button>
                        <span className='text-sm'>{page}</span>
                        <Button
                            variant='outline'
                            disabled={loading || queue.total <= page * pageSize}
                            onClick={() => changePage(page + 1)}
                        >
                            {t('common.next')}
                        </Button>
                    </div>
                )}
            </PageCard>
            <Dialog
                open={!!review}
                onOpenChange={(open) => {
                    if (!open && !deciding) setReview(undefined);
                }}
            >
                <DialogHeader>
                    <DialogTitle>{t('subdomainRequests.review')}</DialogTitle>
                    <DialogDescription>
                        {review?.subdomain}.{review?.domain}
                    </DialogDescription>
                </DialogHeader>
                <label className='space-y-2'>
                    <span className='text-sm'>{t('subdomainRequests.reason')}</span>
                    <Textarea
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        maxLength={1000}
                        disabled={deciding}
                    />
                </label>
                <DialogFooter>
                    <Button variant='destructive' disabled={deciding} onClick={() => void decide('rejected')}>
                        {t('subdomainRequests.reject')}
                    </Button>
                    <Button disabled={deciding} onClick={() => void decide('approved')}>
                        {t('subdomainRequests.approve')}
                    </Button>
                </DialogFooter>
            </Dialog>
        </div>
    );
}
