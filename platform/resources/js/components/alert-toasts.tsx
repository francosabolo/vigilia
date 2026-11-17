import { IconCircle } from '@/components/icon-circle';
import { Button } from '@/components/ui/button';
import { LIVE_ALERT_EVENT, type LiveAlert } from '@/lib/alarm';
import { timeAgo } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { BellRing, Info, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';

type Toast = LiveAlert & { at: number };

/**
 * The app's toasts, bottom right: every alert sound comes with one that says what it was and leads to it. Urgent ones
 * (red) stay until they are opened or closed; the rest leave on their own after a minute. While the AI does not
 * answer, a toast says so on top.
 */
export function AlertToasts() {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const { aiUnavailableSince } = usePage<{ aiUnavailableSince?: string | null }>().props;

    useEffect(() => {
        const add = (event: Event) => {
            const alert = (event as CustomEvent<LiveAlert>).detail;
            setToasts((current) => [{ ...alert, at: Date.now() }, ...current.filter((item) => item.key !== alert.key)].slice(0, 4));
        };
        window.addEventListener(LIVE_ALERT_EVENT, add);
        const timer = setInterval(() => setToasts((current) => current.filter((item) => item.urgent || Date.now() - item.at < 60000)), 5000);
        return () => {
            window.removeEventListener(LIVE_ALERT_EVENT, add);
            clearInterval(timer);
        };
    }, []);

    const dismiss = (key: string) => setToasts((current) => current.filter((item) => item.key !== key));
    if (toasts.length === 0 && !aiUnavailableSince) return null;

    return (
        <div className="fixed right-4 bottom-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2" aria-live="assertive">
            {aiUnavailableSince && (
                <div role="alert" className="bg-card border-pending flex gap-3 rounded-2xl border-2 p-4 shadow-lg">
                    <span className="bg-pending/15 text-pending inline-flex size-9 shrink-0 items-center justify-center rounded-full">
                        <TriangleAlert className="size-[18px]" strokeWidth={1.75} />
                    </span>
                    <div className="min-w-0 text-sm">
                        <p className="text-[15px] font-semibold">La IA no responde desde {timeAgo(aiUnavailableSince)}</p>
                        <p className="text-muted-foreground">
                            Todas las detecciones llegan a la guardia sin filtro: puede haber más alertas de lo normal.
                        </p>
                    </div>
                </div>
            )}
            {toasts.map((toast) => (
                <div
                    key={toast.key}
                    role={toast.urgent ? 'alert' : 'status'}
                    className={cn('bg-card flex gap-3 rounded-2xl border p-4 shadow-lg', toast.urgent && 'border-alarm border-2')}
                >
                    {toast.urgent ? (
                        <IconCircle icon={BellRing} tone="alarm" className="shrink-0" />
                    ) : (
                        <IconCircle icon={toast.silent ? Info : BellRing} className="shrink-0" />
                    )}
                    <div className="min-w-0 flex-1 text-sm">
                        <p className="text-[15px] font-semibold">{toast.title}</p>
                        {toast.detail && <p className="text-muted-foreground line-clamp-2">{toast.detail}</p>}
                        <p className="text-muted-foreground text-xs">{new Date(toast.at).toLocaleTimeString('es-AR', { timeStyle: 'short' })}</p>
                        {toast.url && (
                            <Button asChild size="sm" variant={toast.urgent ? 'destructive' : 'outline'} className="mt-2 rounded-full">
                                <Link href={toast.url} onClick={() => dismiss(toast.key)}>
                                    {toast.urgent ? 'Atender ahora' : 'Ver'}
                                </Link>
                            </Button>
                        )}
                    </div>
                    <button
                        type="button"
                        aria-label="Cerrar aviso"
                        onClick={() => dismiss(toast.key)}
                        className="text-muted-foreground hover:text-foreground self-start"
                    >
                        <X className="size-4" />
                    </button>
                </div>
            ))}
        </div>
    );
}
