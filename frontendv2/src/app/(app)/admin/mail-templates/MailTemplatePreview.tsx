'use client';

import { useState, useEffect } from 'react';
import axios from 'axios';
import MailHtmlFrame from '@/components/common/MailHtmlFrame';
import { Button } from '@/components/featherui/Button';
import { useTranslation } from '@/contexts/TranslationContext';

export interface Draft {
    name: string;
    subject: string;
    body: string;
}

interface RenderedMail {
    subject: string;
    body: string;
    issues: string[];
}

function plainText(html: string) {
    const template = document.createElement('template');
    template.innerHTML = html;
    template.content.querySelectorAll('head, title, script, style, template').forEach((node) => node.remove());
    const nodes = document.createTreeWalker(template.content, NodeFilter.SHOW_TEXT);
    for (let text = nodes.nextNode(); text; text = nodes.nextNode()) {
        text.nodeValue = text.nodeValue?.replace(/\s/g, ' ') ?? '';
    }
    for (const link of template.content.querySelectorAll('a[href]')) {
        const label = link.textContent?.trim();
        const url = link.getAttribute('href')?.trim();
        if (url && url !== label) link.append(label ? ' (' + url + ')' : url);
    }
    for (const cell of template.content.querySelectorAll('td, th')) cell.append(' ');
    for (const line of template.content.querySelectorAll('tr, li')) line.after('\n');
    for (const block of template.content.querySelectorAll('p, div, h1, h2, h3, h4, h5, h6')) {
        block.before('\n\n');
        block.after('\n\n');
    }
    for (const br of template.content.querySelectorAll('br')) br.replaceWith('\n');
    return (template.content.textContent || '')
        .split('\n')
        .map((line) => line.replace(/\s+/g, ' ').trim())
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

export function MailTemplatePreview({ name, subject, body }: Draft) {
    const { t } = useTranslation();
    const [preview, setPreview] = useState<RenderedMail>();
    const [failed, setFailed] = useState(false);
    const [format, setFormat] = useState<'html' | 'plain'>('html');

    useEffect(() => {
        const controller = new AbortController();
        const debounce = window.setTimeout(() => {
            axios
                .post('/api/admin/mail-templates/preview', { name, subject, body }, { signal: controller.signal })
                .then(({ data }) => {
                    if (controller.signal.aborted) return;
                    setPreview(data.data);
                    setFailed(false);
                })
                .catch(() => {
                    if (!controller.signal.aborted) setFailed(true);
                });
        }, 300);
        return () => {
            window.clearTimeout(debounce);
            controller.abort();
        };
    }, [name, subject, body]);

    if (failed)
        return (
            <p role='alert' className='text-destructive'>
                {t('admin.mail_templates.preview.failed')}
            </p>
        );
    if (!preview) return <p className='text-muted-foreground'>{t('common.loading')}</p>;

    return (
        <section className='flex min-w-0 flex-col gap-3'>
            <div className='flex flex-wrap items-center justify-between gap-2'>
                <h3 className='text-sm font-medium'>{t('admin.mail_templates.form.live_preview')}</h3>
                <div className='flex gap-1'>
                    <Button
                        type='button'
                        size='sm'
                        variant={format === 'html' ? 'secondary' : 'ghost'}
                        aria-pressed={format === 'html'}
                        onClick={() => setFormat('html')}
                    >
                        HTML
                    </Button>
                    <Button
                        type='button'
                        size='sm'
                        variant={format === 'plain' ? 'secondary' : 'ghost'}
                        aria-pressed={format === 'plain'}
                        onClick={() => setFormat('plain')}
                    >
                        {t('admin.mail_templates.preview.plain_text')}
                    </Button>
                </div>
            </div>
            <p className='text-muted-foreground text-sm'>{t('admin.mail_templates.preview.example_data')}</p>
            <p className='font-medium break-words'>{preview.subject}</p>
            {preview.issues.length > 0 && (
                <ul role='alert' className='text-destructive list-inside list-disc text-sm'>
                    {preview.issues.map((issue) => (
                        <li key={issue}>{issue}</li>
                    ))}
                </ul>
            )}
            <div className='border-border/50 flex min-h-[480px] flex-1 flex-col overflow-hidden rounded-2xl border shadow-xl lg:min-h-[600px]'>
                {format === 'html' ? (
                    <MailHtmlFrame
                        html={preview.body}
                        title={t('admin.mail_templates.form.live_preview')}
                        className='block h-[480px] flex-1 lg:min-h-[600px]'
                    />
                ) : (
                    <pre className='bg-muted/50 h-[480px] overflow-auto p-4 text-sm break-words whitespace-pre-wrap lg:h-[600px]'>
                        {plainText(preview.body)}
                    </pre>
                )}
            </div>
        </section>
    );
}
