import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback } from 'react';

interface IncidentChangedPayload {
    change: string;
    incident: { id: number; site_id: number; status: string; severity: string; title: string };
}

/**
 * Short siren with the Web Audio API (no asset needed). Browsers only allow it after a user interaction.
 */
export function playAlarm(): void {
    try {
        const context = new AudioContext();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'square';
        oscillator.connect(gain);
        gain.connect(context.destination);
        gain.gain.value = 0.08;
        [880, 660, 880, 660].forEach((frequency, index) => oscillator.frequency.setValueAtTime(frequency, context.currentTime + index * 0.25));
        oscillator.start();
        oscillator.stop(context.currentTime + 1);
    } catch {
        // Audio not available: the visual alert is enough.
    }
}

/**
 * Listens to the user's private channel and reloads the given props whenever an incident changes.
 * Plays the siren when an incident opens or escalates.
 */
export function useLiveIncidents(channel: string, only: string[], onChange?: (payload: IncidentChangedPayload) => void) {
    const onlyKey = only.join(',');

    const handler = useCallback(
        (payload: IncidentChangedPayload) => {
            if (payload.change === 'opened' || payload.change === 'escalated') {
                playAlarm();
            }
            onChange?.(payload);
            router.reload({ only: onlyKey.split(',') });
        },
        [onlyKey, onChange],
    );

    useEcho<IncidentChangedPayload>(channel, '.incident.changed', handler, [handler]);
}
