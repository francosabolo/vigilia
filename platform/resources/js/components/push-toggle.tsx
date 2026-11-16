import { Button } from '@/components/ui/button';
import { BellOff, BellRing } from 'lucide-react';
import { useEffect, useState } from 'react';

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

function csrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Subscribes this device to Web Push alerts (works on Android and on iOS 16.4+ once installed as an app).
 */
export function PushToggle({ vapidPublicKey }: { vapidPublicKey: string | null }) {
    const supported = typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && !!vapidPublicKey;
    const [subscribed, setSubscribed] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!supported) return;
        navigator.serviceWorker.ready
            .then((registration) => registration.pushManager.getSubscription())
            .then((subscription) => setSubscribed(!!subscription));
    }, [supported]);

    if (!supported) return null;

    async function toggle() {
        setBusy(true);
        try {
            const registration = await navigator.serviceWorker.ready;
            const existing = await registration.pushManager.getSubscription();
            const headers = { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken(), Accept: 'application/json' };

            if (existing) {
                await fetch('/push-subscriptions', { method: 'DELETE', headers, body: JSON.stringify({ endpoint: existing.endpoint }) });
                await existing.unsubscribe();
                setSubscribed(false);
                return;
            }

            if ((await Notification.requestPermission()) !== 'granted') return;

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey!),
            });
            await fetch('/push-subscriptions', { method: 'POST', headers, body: JSON.stringify(subscription.toJSON()) });
            setSubscribed(true);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Button variant="outline" size="sm" onClick={toggle} disabled={busy}>
            {subscribed ? <BellOff className="mr-1 size-4" /> : <BellRing className="mr-1 size-4" />}
            {subscribed ? 'Desactivar alertas en este dispositivo' : 'Activar alertas en este dispositivo'}
        </Button>
    );
}
