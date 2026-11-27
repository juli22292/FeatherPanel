'use client';

import { useEffect, useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/components/featherui/Button';
import { Input } from '@/components/featherui/Input';
import { Sheet, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useTranslation } from '@/contexts/TranslationContext';
import { getApiErrorMessage } from '@/lib/api-errors';

interface UpdateEntry {
    id: string;
    action: 'update' | 'restore';
    from: string;
    to: string;
    result: 'running' | 'success' | 'failed';
    started_at: string;
    finished_at: string | null;
    migrations: string[] | null;
}

interface History {
    available: boolean;
    busy: boolean;
    entries: UpdateEntry[];
    restore: { id: string; version: string } | null;
}

export function UpdateHistory() {
    const { t, locale } = useTranslation();
    const [open, setOpen] = useState(false);
    const [failure, setFailure] = useState(false);
    const [history, setHistory] = useState<History>();
    const [confirmation, setConfirmation] = useState('');
    const [restoring, setRestoring] = useState(false);
    const [refresh, setRefresh] = useState(0);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        let timer: number;
        const fetchHistory = async () => {
            try {
                const { data } = await axios.get('/api/admin/settings/docker/update-history', {
                    signal: controller.signal,
                });
                if (!controller.signal.aborted) {
                    setHistory(data.data);
                    setFailure(false);
                }
            } catch {
                if (!controller.signal.aborted) setFailure(true);
            } finally {
                if (!controller.signal.aborted) timer = window.setTimeout(fetchHistory, 8000);
            }
        };
        void fetchHistory();
        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, refresh]);

    const restore = history?.restore;
    const actionLabels = {
        update: t('serverpanel_update_history.update'),
        restore: t('serverpanel_update_history.restore'),
    };
    const resultLabels = {
        running: t('serverpanel_update_history.running'),
        success: t('serverpanel_update_history.success'),
        failed: t('serverpanel_update_history.failed'),
    };
    const submitRestore = async () => {
        if (!restore || confirmation !== restore.version) return;
        setRestoring(true);
        try {
            await axios.post('/api/admin/settings/docker/restore', { id: restore.id, version: confirmation });
            toast.success(t('serverpanel_update_history.started'));
            setConfirmation('');
            setRefresh((value) => value + 1);
        } catch (error) {
            toast.error(getApiErrorMessage(error, t, 'serverpanel_update_history.error'));
        } finally {
            setRestoring(false);
        }
    };

    return (
        <>
            <Button
                variant='outline'
                size='sm'
                onClick={() => {
                    setConfirmation('');
                    setOpen(true);
                }}
            >
                {t('serverpanel_update_history.title')}
            </Button>
            <Sheet open={open} onOpenChange={setOpen} enableSaveShortcut={false}>
                <SheetHeader>
                    <SheetTitle>{t('serverpanel_update_history.title')}</SheetTitle>
                    <SheetDescription>{t('serverpanel_update_history.description')}</SheetDescription>
                </SheetHeader>
                <div className='space-y-4 py-5'>
                    {failure && (
                        <p role='alert' className='text-destructive'>
                            {t('serverpanel_update_history.error')}
                        </p>
                    )}
                    {!failure && !history && <p>{t('common.loading')}</p>}
                    {history?.available === false && (
                        <p className='text-muted-foreground'>
                            {t('serverpanel_update_history.unavailable')}{' '}
                            <a
                                href='https://github.com/juli22292/FeatherPanel/blob/v1.5.7/docs/updates.md#update-verlauf-und-wiederherstellung-ab-157'
                                className='text-primary underline'
                                target='_blank'
                                rel='noreferrer'
                            >
                                {t('serverpanel_update_history.setup')}
                            </a>
                        </p>
                    )}
                    {history?.available && history.entries.length === 0 && (
                        <p className='text-muted-foreground'>{t('serverpanel_update_history.empty')}</p>
                    )}
                    {history?.available && history.busy && (
                        <p role='status'>{t('serverpanel_update_history.running')}</p>
                    )}
                    {restore && !history?.busy && (
                        <div className='border-destructive/40 space-y-2 rounded-xl border p-4'>
                            <h3 className='font-semibold'>
                                {t('serverpanel_update_history.restore_version', { version: restore.version })}
                            </h3>
                            <p className='text-muted-foreground text-sm'>
                                {t('serverpanel_update_history.restore_warning')}
                            </p>
                            <label htmlFor='confirm-panel-restore' className='block text-sm'>
                                {t('serverpanel_update_history.confirm', { version: restore.version })}
                            </label>
                            <Input
                                id='confirm-panel-restore'
                                autoComplete='off'
                                value={confirmation}
                                onChange={(event) => setConfirmation(event.target.value)}
                            />
                            <Button
                                variant='destructive'
                                disabled={confirmation !== restore.version}
                                loading={restoring}
                                onClick={() => void submitRestore()}
                            >
                                {t('serverpanel_update_history.restore')}
                            </Button>
                        </div>
                    )}
                    {history?.available &&
                        history.entries.map((entry) => (
                            <article key={entry.id} className='fp-surface space-y-1 rounded-xl border p-4'>
                                <h3 className='font-semibold'>
                                    {actionLabels[entry.action]} {entry.from} → {entry.to}
                                </h3>
                                <p className='text-muted-foreground text-sm'>
                                    {new Date(entry.finished_at ?? entry.started_at).toLocaleString(locale)} ·{' '}
                                    {resultLabels[entry.result]}
                                </p>
                                {entry.action === 'update' &&
                                    entry.result === 'success' &&
                                    (entry.migrations?.length ? (
                                        <details className='text-sm'>
                                            <summary className='cursor-pointer'>
                                                {t('serverpanel_update_history.migrations')}
                                            </summary>
                                            <ul className='list-inside list-disc break-words'>
                                                {entry.migrations.map((script) => (
                                                    <li key={script}>{script}</li>
                                                ))}
                                            </ul>
                                        </details>
                                    ) : (
                                        <p className='text-muted-foreground text-sm'>
                                            {entry.migrations === null
                                                ? t('serverpanel_update_history.unknown_migrations')
                                                : t('serverpanel_update_history.no_migrations')}
                                        </p>
                                    ))}
                            </article>
                        ))}
                </div>
            </Sheet>
        </>
    );
}
