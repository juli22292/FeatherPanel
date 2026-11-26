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

import { useEffect, useRef } from 'react';
import { useLiveRefresh } from '@/hooks/useLiveRefresh';

export function useTicketLiveRefresh(topic: string | undefined, refresh: () => void) {
    const status = useLiveRefresh({
        topics: [topic],
        types: ['ticket.updated'],
        refresh,
        pollMs: 6000,
        enabled: Boolean(topic),
    });
    const connectedTopic = useRef<string | undefined>(undefined);

    // Catch replies sent between the initial fetch and the first live connection.
    // Subsequent reconnects are already handled by useLiveRefresh.
    useEffect(() => {
        if (!topic || status !== 'open' || connectedTopic.current === topic) return;
        connectedTopic.current = topic;
        refresh();
    }, [topic, status, refresh]);

    // Reconcile even a connected stream when an update could not be published.
    useEffect(() => {
        if (!topic || status !== 'open') return;
        const timer = window.setInterval(() => {
            if (document.visibilityState !== 'hidden') refresh();
        }, 30_000);
        return () => window.clearInterval(timer);
    }, [topic, status, refresh]);
}
