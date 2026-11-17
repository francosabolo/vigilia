/**
 * One sound per incident and change, wherever it comes from (the live console or the notification), and the banner
 * that says why it sounded. The same alert arriving twice (console first, notification seconds later) sounds once.
 */
const recent = new Map<string, number>();
const WINDOW_MS = 5 * 60 * 1000;

export interface LiveAlert {
    key: string;
    title: string;
    detail: string | null;
    url: string | null;
    urgent: boolean;
    /** Information (a report is ready, a weekly summary): a toast without sound. */
    silent?: boolean;
}

export const LIVE_ALERT_EVENT = 'vigilia:alert';

function tone(urgent: boolean): void {
    try {
        const context = new AudioContext();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.connect(gain);
        gain.connect(context.destination);
        if (urgent) {
            // Siren: high and critical incidents, escalations.
            oscillator.type = 'square';
            gain.gain.value = 0.08;
            [880, 660, 880, 660].forEach((frequency, index) => oscillator.frequency.setValueAtTime(frequency, context.currentTime + index * 0.25));
            oscillator.start();
            oscillator.stop(context.currentTime + 1);
        } else {
            // Chime: something to look at, not an emergency.
            oscillator.type = 'sine';
            gain.gain.value = 0.06;
            oscillator.frequency.setValueAtTime(660, context.currentTime);
            oscillator.frequency.setValueAtTime(880, context.currentTime + 0.15);
            oscillator.start();
            oscillator.stop(context.currentTime + 0.35);
        }
    } catch {
        // Audio not available (or the browser waits for a first click): the banner is still there.
    }
}

/**
 * Sounds and shows an alert once. Returns false when the same alert already sounded in the last minutes.
 */
export function raiseAlert(alert: LiveAlert): boolean {
    const now = Date.now();
    for (const [key, at] of recent) if (now - at > WINDOW_MS) recent.delete(key);
    if (recent.has(alert.key)) return false;
    recent.set(alert.key, now);

    if (!alert.silent) tone(alert.urgent);
    window.dispatchEvent(new CustomEvent<LiveAlert>(LIVE_ALERT_EVENT, { detail: alert }));

    return true;
}

/** The key of an incident alert, the same from the console broadcast and from the notification. */
export const incidentAlertKey = (incidentId: number | string, change: 'opened' | 'escalated') => `incident:${incidentId}:${change}`;

/** Kept for other callers (visitor at the gate, safe arrival...): an urgent sound without a banner key of its own. */
export function playAlarm(): void {
    tone(true);
}
