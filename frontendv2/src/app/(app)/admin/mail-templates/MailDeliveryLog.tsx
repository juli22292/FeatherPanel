'use client';

import axios from 'axios';
import { useEffect, useState } from 'react';
import { Button } from '@/components/featherui/Button';
import { Sheet, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useTranslation } from '@/contexts/TranslationContext';

type Status = 'pending' | 'sent' | 'failed';
interface Delivery {
    id: number;
    username: string | null;
    template_name: string | null;
    status: Status;
    transport: string | null;
    attempts: number;
    queued_at: number | string;
    processed_at: number | string | null;
    failure_code: string | null;
}

export function MailDeliveryLog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t, locale } = useTranslation();
    const [status, setStatus] = useState<Status | ''>('');
    const [before, setBefore] = useState<number>();
    const [refresh, setRefresh] = useState(0);
    const [data, setData] = useState<{ messages: Delivery[]; next_before: number | null }>();
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        axios.get('/api/admin/mail-templates/delivery-log', {
            params: { status: status || undefined, before },
            signal: controller.signal,
        }).then(({ data }) => {
            setData(data.data);
            setFailed(false);
        }).catch(() => {
            if (!controller.signal.aborted) setFailed(true);
        });
        return () => controller.abort();
    }, [open, status, before, refresh]);

    const time = (value: number | string | null) =>
        value === null ? '—' : new Date(Number(value) * 1000).toLocaleString(locale);
    const reason = (value: string) => {
        if (/^(smtp|http)_\d{3}$/.test(value)) return value.replace('_', ' ').toUpperCase();
        return t('admin.mail_templates.delivery.reason.' + value);
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange} className='max-w-5xl' enableSaveShortcut={false}>
            <SheetHeader>
                <SheetTitle>{t('admin.mail_templates.delivery.title')}</SheetTitle>
                <SheetDescription>{t('admin.mail_templates.delivery.description')}</SheetDescription>
            </SheetHeader>
            <div className='my-5 flex flex-wrap gap-2'>
                {(['', 'pending', 'sent', 'failed'] as const).map((filter) => (
                    <Button key={filter} size='sm' variant={filter === status ? 'default' : 'outline'} onClick={() => {
                        setStatus(filter);
                        setBefore(undefined);
                    }}>
                        {t('admin.mail_templates.delivery.status.' + (filter || 'all'))}
                    </Button>
                ))}
                <Button size='sm' variant='outline' onClick={() => setRefresh((value) => value + 1)}>
                    {t('common.refresh')}
                </Button>
            </div>
            {failed && <p role='alert' className='text-destructive'>{t('admin.mail_templates.delivery.failed')}</p>}
            {!data && !failed && <p>{t('common.loading')}</p>}
            {data && !failed && (
                <>
                    <div className='overflow-x-auto'>
                        <table className='w-full text-left text-sm'>
                            <thead className='border-b text-muted-foreground'>
                                <tr>
                                    {['message', 'queued_at', 'status_label', 'processed_at'].map((field) =>
                                        <th className='p-2 font-medium' key={field}>{t('admin.mail_templates.delivery.' + field)}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {data.messages.map((message) => (
                                    <tr key={message.id} className='border-b align-top'>
                                        <td className='p-2'>
                                            <span className='font-mono'>#{message.id} {message.template_name}</span>
                                            <div className='text-muted-foreground'>{message.username}</div>
                                        </td>
                                        <td className='p-2 whitespace-nowrap'>{time(message.queued_at)}</td>
                                        <td className='p-2'>
                                            {t('admin.mail_templates.delivery.status.' + message.status)}
                                            {message.transport && (
                                                <div className='text-muted-foreground'>
                                                    {message.transport.toUpperCase()} · {t('admin.mail_templates.delivery.attempts', { count: String(message.attempts) })}
                                                </div>
                                            )}
                                            {message.failure_code && <div className='text-destructive'>{reason(message.failure_code)}</div>}
                                        </td>
                                        <td className='p-2 whitespace-nowrap'>{time(message.processed_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {data.messages.length === 0 && <p className='py-4 text-muted-foreground'>{t('admin.mail_templates.delivery.empty')}</p>}
                    <div className='mt-4 flex gap-2'>
                        {before !== undefined && <Button variant='outline' onClick={() => setBefore(undefined)}>{t('admin.mail_templates.delivery.newest')}</Button>}
                        {data.next_before !== null && (
                            <Button variant='outline' onClick={() => setBefore(data.next_before ?? undefined)}>{t('admin.mail_templates.delivery.older')}</Button>
                        )}
                    </div>
                </>
            )}
        </Sheet>
    );
}
