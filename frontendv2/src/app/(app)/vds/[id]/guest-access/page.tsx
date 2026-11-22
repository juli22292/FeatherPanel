/*
This file is part of FeatherPanel.

Copyright (C) 2025 MythicalSystems Studio
Copyright (C) 2025 FeatherPanel Contributors
Copyright (C) 2025 Cassian Gherman (aka NaysKutzu)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published
by the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

See the LICENSE file or <https://www.gnu.org/licenses/>.
*/

'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import axios from 'axios';
import { Eye, EyeOff, Key, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/featherui/Button';
import { Input } from '@/components/featherui/Input';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { VdsPageHeader } from '@/components/vds/VdsPageHeader';
import { VdsPageLoading } from '@/components/vds/VdsPageLoading';
import { isReauthCancelled, useVdsReauth } from '@/components/vds/VdsReauth';
import { useTranslation } from '@/contexts/TranslationContext';
import { useVmInstance } from '@/contexts/VmInstanceContext';
import { getApiErrorMessage } from '@/lib/api-errors';

type GuestAccount = { username: string; kind: 'root' | 'standard' | 'additional'; has_password: boolean };
type GuestInfo = {
    hostname: string | null;
    ip: string | null;
    ssh_ports: number[];
    accounts: GuestAccount[];
    agent_available: boolean;
    preferred_username: string;
    setup_pending: boolean;
    additional_limit: number;
};

export default function ServerInfoPage() {
    const { id } = useParams<{ id: string }>();
    const { instance, loading } = useVmInstance();
    const { t } = useTranslation();
    if (loading) return <VdsPageLoading label={t('common.loading')} />;
    if (!instance?.is_owner) {
        return (
            <div className='space-y-5'>
                <VdsPageHeader title={t('vds.guestAccess.title')} icon={Key} description={instance?.hostname} />
                <section className='bg-card space-y-3 rounded-xl border p-5'>
                    <h2 className='font-semibold'>{t('vds.guestAccess.connection')}</h2>
                    <dl>
                        <dt className='text-muted-foreground text-sm'>{t('vds.guestAccess.ip')}</dt>
                        <dd className='font-mono'>{instance?.ip_address || '—'}</dd>
                    </dl>
                    <p className='text-muted-foreground text-sm'>{t('vds.guestAccess.denied')}</p>
                </section>
            </div>
        );
    }

    return <OwnerServerInfo key={id} id={id} guestType={instance.vm_type} />;
}

function OwnerServerInfo({ id, guestType }: { id: string; guestType: 'qemu' | 'lxc' }) {
    const { t } = useTranslation();
    const withReauth = useVdsReauth();
    const endpoint = '/api/user/vm-instances/' + id + '/guest-access';
    const [info, setInfo] = useState<GuestInfo>();
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string>();
    const [username, setUsername] = useState('');
    const [reset, setReset] = useState<string>();
    const [revealed, setRevealed] = useState<{ username: string; password: string }>();

    const load = useCallback(
        async (signal?: AbortSignal) => {
            setLoading(true);
            setRevealed(undefined);
            setError(undefined);
            try {
                const response = await axios.get<{ data: GuestInfo }>(endpoint, { signal });
                if (!signal?.aborted) setInfo(response.data.data);
            } catch (reason) {
                if (signal?.aborted) return;
                setInfo(undefined);
                setError(getApiErrorMessage(reason, t, 'vds.guestAccess.load_failed'));
            } finally {
                if (!signal?.aborted) setLoading(false);
            }
        },
        [endpoint, t],
    );

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);
        return () => controller.abort();
    }, [load]);

    async function accountAction(action: 'add' | 'reset' | 'reveal', name: string) {
        setBusy(true);
        setRevealed(undefined);
        try {
            const response = await withReauth((headers) =>
                axios.post<{ data: { password?: string } }>(endpoint, { action, username: name }, { headers }),
            );
            if (action === 'reveal' && response.data.data.password) {
                setRevealed({ username: name, password: response.data.data.password });
            } else {
                setUsername('');
                setReset(undefined);
                await load();
                toast.success(t('vds.guestAccess.saved'));
            }
        } catch (reason) {
            if (!isReauthCancelled(reason)) {
                toast.error(getApiErrorMessage(reason, t, 'vds.guestAccess.action_failed'));
                if (action !== 'reveal') {
                    setReset(undefined);
                    await load();
                }
            }
        } finally {
            setBusy(false);
        }
    }

    const standard = info?.accounts.find((account) => account.kind === 'standard');
    const address = info?.ip?.includes(':') ? '[' + info.ip + ']' : info?.ip;
    const port = info?.ssh_ports[0];
    const additionalCount = info?.accounts.filter((account) => account.kind === 'additional').length ?? 0;

    return (
        <div className='space-y-5'>
            <VdsPageHeader
                title={t('vds.guestAccess.title')}
                description={info?.hostname}
                icon={Key}
                actions={
                    <Button variant='outline' disabled={loading || busy} onClick={() => void load()}>
                        <RefreshCw className='h-4 w-4' />
                        {t('vds.guestAccess.refresh')}
                    </Button>
                }
            />
            {loading && !info && <VdsPageLoading label={t('common.loading')} />}
            {error && (
                <p role='alert' className='text-destructive'>
                    {error}
                </p>
            )}
            {info && (
                <>
                    <section className='bg-card rounded-xl border p-5'>
                        <h2 className='mb-4 font-semibold'>{t('vds.guestAccess.connection')}</h2>
                        <dl className='grid gap-4 sm:grid-cols-2'>
                            <div>
                                <dt className='text-muted-foreground text-sm'>{t('vds.guestAccess.ip')}</dt>
                                <dd className='font-mono'>{info.ip || '—'}</dd>
                            </div>
                            <div>
                                <dt className='text-muted-foreground text-sm'>{t('vds.guestAccess.ssh_ports')}</dt>
                                <dd className='font-mono'>{info.ssh_ports.join(', ') || '—'}</dd>
                            </div>
                            {standard && address && port && (
                                <div className='sm:col-span-2'>
                                    <dt className='text-muted-foreground text-sm'>{t('vds.guestAccess.sftp')}</dt>
                                    <dd className='font-mono break-all'>
                                        sftp://{encodeURIComponent(standard.username)}@{address}:{port}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        {info.setup_pending && (
                            <p role='status' className='text-muted-foreground mt-4 text-sm'>
                                {guestType === 'lxc' ? t('vds.guestAccess.pending_lxc') : t('vds.guestAccess.pending')}
                            </p>
                        )}
                        {!info.agent_available && (
                            <p role='status' className='text-muted-foreground mt-4 text-sm'>
                                {guestType === 'lxc'
                                    ? t('vds.guestAccess.connector_unavailable')
                                    : t('vds.guestAccess.agent_unavailable')}
                            </p>
                        )}
                    </section>
                    {info.agent_available && (
                        <section className='bg-card space-y-4 rounded-xl border p-5'>
                            <h2 className='font-semibold'>{t('vds.guestAccess.accounts')}</h2>
                            <p className='text-muted-foreground text-sm'>{t('vds.guestAccess.existing_accounts')}</p>
                            <ul className='divide-y'>
                                {info.accounts.map((account) => {
                                    const show = revealed?.username === account.username;
                                    const label =
                                        account.kind === 'root'
                                            ? t('vds.guestAccess.root')
                                            : account.kind === 'standard'
                                              ? t('vds.guestAccess.standard')
                                              : t('vds.guestAccess.additional');
                                    return (
                                        <li
                                            key={account.username}
                                            className='flex flex-wrap items-center justify-between gap-3 py-3'
                                        >
                                            <div className='w-full min-w-0 sm:w-auto'>
                                                <p className='font-mono break-all'>{account.username}</p>
                                                <p className='text-muted-foreground text-xs'>{label}</p>
                                            </div>
                                            <span className='text-muted-foreground font-mono text-sm break-all'>
                                                {show
                                                    ? revealed.password
                                                    : account.has_password
                                                      ? '••••••••'
                                                      : t('vds.guestAccess.unknown_password')}
                                            </span>
                                            <div className='flex flex-wrap gap-2'>
                                                {account.has_password && (
                                                    <Button
                                                        variant='outline'
                                                        disabled={loading || busy}
                                                        onClick={() =>
                                                            show
                                                                ? setRevealed(undefined)
                                                                : void accountAction('reveal', account.username)
                                                        }
                                                    >
                                                        {show ? (
                                                            <EyeOff className='h-4 w-4' />
                                                        ) : (
                                                            <Eye className='h-4 w-4' />
                                                        )}
                                                        {show ? t('vds.guestAccess.hide') : t('vds.guestAccess.show')}
                                                    </Button>
                                                )}
                                                <Button
                                                    variant='outline'
                                                    disabled={loading || busy}
                                                    onClick={() => setReset(account.username)}
                                                >
                                                    {t('vds.guestAccess.reset')}
                                                </Button>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                            {!standard && !info.setup_pending && (
                                <Button
                                    variant='outline'
                                    disabled={loading || busy}
                                    onClick={() => void accountAction('add', info.preferred_username)}
                                >
                                    {t('vds.guestAccess.create_standard', { username: info.preferred_username })}
                                </Button>
                            )}
                            {additionalCount < info.additional_limit && (
                                <form
                                    className='flex flex-wrap items-end gap-2'
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        void accountAction('add', username);
                                    }}
                                >
                                    <label className='space-y-2'>
                                        <span className='text-sm'>{t('vds.guestAccess.username')}</span>
                                        <Input
                                            value={username}
                                            onChange={(event) => setUsername(event.target.value)}
                                            maxLength={32}
                                        />
                                    </label>
                                    <Button
                                        type='submit'
                                        disabled={loading || busy || !/^[a-z][a-z0-9]{0,31}$/.test(username)}
                                    >
                                        {t('vds.guestAccess.add')}
                                    </Button>
                                </form>
                            )}
                            <p className='text-muted-foreground text-sm'>
                                {t('vds.guestAccess.limit', {
                                    count: String(additionalCount),
                                    limit: String(info.additional_limit),
                                })}
                            </p>
                        </section>
                    )}
                </>
            )}
            <Dialog
                open={reset !== undefined}
                onOpenChange={(open) => {
                    if (!open) setReset(undefined);
                }}
            >
                <DialogHeader>
                    <DialogTitle>{t('vds.guestAccess.reset')}</DialogTitle>
                    <DialogDescription>
                        {t('vds.guestAccess.confirm_reset', { username: reset ?? '' })}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant='outline' disabled={busy} onClick={() => setReset(undefined)}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        disabled={busy}
                        onClick={() => {
                            if (reset) void accountAction('reset', reset);
                        }}
                    >
                        {t('vds.guestAccess.reset')}
                    </Button>
                </DialogFooter>
            </Dialog>
        </div>
    );
}
