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
import { ChevronDown, ScrollText } from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { PageHeader } from '@/components/featherui/PageHeader';
import { PageLoading } from '@/components/featherui/PageLoading';
import { useTranslation } from '@/contexts/TranslationContext';
import { formatDateInTz } from '@/lib/dateUtils';
import type { PanelChangelog } from '@/types/changelog';

export default function ChangelogsPage() {
    const { t, locale } = useTranslation();

    const [loaded, setLoaded] = useState<{ locale: string; data: PanelChangelog | null }>();
    const data = loaded?.locale === locale ? loaded.data : undefined;
    useEffect(() => {
        const controller = new AbortController();
        axios
            .get<{ data: PanelChangelog }>('/api/user/changelogs', {
                params: { locale },
                signal: controller.signal,
            })
            .then((response) => setLoaded({ locale, data: response.data.data }))
            .catch((error: unknown) => {
                if (!axios.isCancel(error)) setLoaded({ locale, data: null });
            });
        return () => controller.abort();
    }, [locale]);

    return (
        <div className='space-y-5'>
            <PageHeader title={t('changelogs.title')} description={t('changelogs.description')} icon={ScrollText} />
            {data === undefined ? (
                <PageLoading />
            ) : data === null ? (
                <p role='alert' className='text-destructive'>
                    {t('changelogs.loadFailed')}
                </p>
            ) : !data.enabled ? (
                <p className='text-muted-foreground'>{t('changelogs.disabled')}</p>
            ) : (
                <div className='space-y-3'>
                    {data.releases.map((release, index) => (
                        <details
                            key={release.version}
                            open={index === 0}
                            className='fp-surface group rounded-xl border'
                        >
                            <summary className='flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-4 sm:px-6 [&::-webkit-details-marker]:hidden'>
                                <h2 className='font-semibold'>{release.version}</h2>
                                <span className='bg-muted text-muted-foreground rounded-full px-2.5 py-1 text-xs font-medium'>
                                    {release.channel === 'early_beta'
                                        ? t('changelogs.earlyBeta')
                                        : t('changelogs.stable')}
                                </span>
                                <time className='text-muted-foreground text-sm' dateTime={release.published_at}>
                                    {formatDateInTz(new Date(release.published_at), { locale, timeZone: 'UTC' })}
                                </time>
                                <ChevronDown
                                    className='text-muted-foreground ml-auto h-4 w-4 group-open:rotate-180'
                                    aria-hidden
                                />
                            </summary>
                            <div className='border-t px-4 py-5 sm:px-6'>
                                <div
                                    lang={release.content_locale}
                                    className='prose prose-sm dark:prose-invert max-w-none break-words'
                                >
                                    <ReactMarkdown remarkPlugins={[remarkGfm]}>{release.body}</ReactMarkdown>
                                </div>
                                <a
                                    className='text-primary mt-4 inline-flex text-sm font-medium hover:underline'
                                    href={release.url}
                                    target='_blank'
                                    rel='noopener noreferrer'
                                >
                                    {t('changelogs.github')}
                                </a>
                            </div>
                        </details>
                    ))}
                </div>
            )}
        </div>
    );
}
