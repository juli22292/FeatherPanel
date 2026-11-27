'use client';

import { useState, useEffect } from 'react';
import axios from 'axios';
import MailHtmlFrame from '@/components/common/MailHtmlFrame';
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

    useEffect(() => {
        const controller = new AbortController();
        const debounce = window.setTimeout(() => {
            axios.post('/api/admin/mail-templates/preview', { name, subject, body }, { signal: controller.signal })
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

    if (failed) return <p role='alert' className='text-destructive'>{t('admin.mail_templates.preview.failed')}</p>;
    if (!preview) return <p className='text-muted-foreground'>{t('common.loading')}</p>;

    return (
        <section className='space-y-3'>
            <p className='text-muted-foreground text-sm'>{t('admin.mail_templates.preview.example_data')}</p>
            <p className='break-words font-medium'>{preview.subject}</p>
            {preview.issues.length > 0 && (
                <ul role='alert' className='text-destructive list-inside list-disc text-sm'>
                    {preview.issues.map((issue) => <li key={issue}>{issue}</li>)}
                </ul>
            )}
            <div className='grid gap-3 sm:grid-cols-2'>
                <div className='min-w-0 space-y-2'>
                    <h3 className='text-sm font-medium'>HTML</h3>
                    <MailHtmlFrame html={preview.body} title='HTML' className='h-80 rounded-lg' />
                </div>
                <div className='min-w-0 space-y-2'>
                    <h3 className='text-sm font-medium'>{t('admin.mail_templates.preview.plain_text')}</h3>
                    <pre className='border-border bg-muted/50 h-80 overflow-auto rounded-lg border p-3 text-sm whitespace-pre-wrap break-words'>
                        {plainText(preview.body)}
                    </pre>
                </div>
            </div>
        </section>
    );
}
