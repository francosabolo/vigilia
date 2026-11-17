import { incidentAlertKey, raiseAlert } from '@/lib/alarm';
import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback } from 'react';

export { playAlarm } from '@/lib/alarm';

interface IncidentChangedPayload {
    change: string;
    incident: { id: number; site_id: number; site?: string; status: string; severity: string; title: string };
}

/**
 * Listens to the user's private channel and reloads the given props whenever an incident changes.
 * When an incident opens or escalates it sounds once (siren if high, critical or escalated; a chime otherwise) and
 * shows the banner with what it is, so the sound always matches something on screen.
 */
export function useLiveIncidents(channel: string, only: string[], onChange?: (payload: IncidentChangedPayload) => void) {
    const onlyKey = only.join(',');
    const currentSiteId = usePage<{ siteContext: { current: { id: number } | null } | null }>().props.siteContext?.current?.id ?? null;

    const handler = useCallback(
        (payload: IncidentChangedPayload) => {
            if (payload.change === 'opened' || payload.change === 'escalated') {
                const { incident } = payload;
                const elsewhere = currentSiteId !== null && incident.site_id !== currentSiteId;
                const urgent = payload.change === 'escalated' || incident.severity === 'high' || incident.severity === 'critical';
                // Filtered to one property: an urgent alert elsewhere still sounds (and says so); a minor one does not.
                if (elsewhere && !urgent) {
                    onChange?.(payload);
                    router.reload({ only: onlyKey.split(',') });
                    return;
                }
                raiseAlert({
                    key: incidentAlertKey(incident.id, payload.change),
                    title: (payload.change === 'escalated' ? 'Escalado a la central: ' : 'Nueva alerta: ') + incident.title,
                    detail: elsewhere ? `En ${incident.site ?? 'otra propiedad'}, no es la propiedad que estás mirando.` : (incident.site ?? null),
                    url: `/incidents/${incident.id}`,
                    urgent,
                });
            }
            onChange?.(payload);
            router.reload({ only: onlyKey.split(',') });
        },
        [onlyKey, onChange, currentSiteId],
    );

    useEcho<IncidentChangedPayload>(channel, '.incident.changed', handler, [handler]);
}
