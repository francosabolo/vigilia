import { playAlarm } from '@/hooks/use-live-incidents';
import { timeAgo } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { useEchoNotification } from '@laravel/echo-react';
import { Bell, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface InboxItem {
    id: string;
    title: string;
    body: string;
    level: 'info' | 'warning' | 'alarm';
    kind: string;
    read: boolean;
    at: string;
}

interface LivePayload {
    id: string;
    title: string;
    body: string;
    level: InboxItem['level'];
    kind: string;
    url: string | null;
}

export const levelDot: Record<InboxItem['level'], string> = {
    info: 'bg-sky-400',
    warning: 'bg-pending',
    alarm: 'bg-alarm',
};

export function InboxRow({ item, compact = false }: { item: InboxItem; compact?: boolean }) {
    return (
        <Link
            href={`/notifications/${item.id}`}
            className={cn('hover:bg-accent flex gap-3 px-4 py-3 transition-colors', !item.read && 'bg-accent/40')}
        >
            <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', item.read ? 'bg-muted-foreground/30' : levelDot[item.level])} aria-hidden />
            <span className="min-w-0 flex-1">
                <span className={cn('block text-sm', !item.read && 'font-semibold')}>{item.title}</span>
                {item.body && <span className={cn('text-muted-foreground block text-sm', compact && 'line-clamp-2')}>{item.body}</span>}
                <span className="text-muted-foreground block text-xs">{timeAgo(item.at)}</span>
            </span>
        </Link>
    );
}

/**
 * The bell: unread count, the latest notifications, and a live toast when a new one arrives (with the siren for
 * alarms). Works for staff and residents alike.
 */
export function NotificationBell({ className, showAll = true }: { className?: string; showAll?: boolean }) {
    const { auth, unreadNotifications } = usePage<SharedData & { unreadNotifications: number }>().props;
    const [open, setOpen] = useState(false);
    const [unread, setUnread] = useState(unreadNotifications ?? 0);
    const [items, setItems] = useState<InboxItem[] | null>(null);
    const [toast, setToast] = useState<LivePayload | null>(null);
    const panel = useRef<HTMLDivElement>(null);

    useEffect(() => setUnread(unreadNotifications ?? 0), [unreadNotifications]);

    const load = useCallback(async () => {
        const response = await fetch('/notifications/latest', { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = (await response.json()) as { unread: number; items: InboxItem[] };
        setUnread(data.unread);
        setItems(data.items);
    }, []);

    useEchoNotification<LivePayload>(`App.Models.User.${auth.user.id}`, (payload) => {
        setUnread((count) => count + 1);
        setItems(null);
        setToast(payload);
        if (payload.level === 'alarm') playAlarm();
    });

    useEffect(() => {
        if (!toast) return;
        const timer = setTimeout(() => setToast(null), 8000);
        return () => clearTimeout(timer);
    }, [toast]);

    useEffect(() => {
        if (!open) return;
        if (items === null) void load();
        const close = (event: MouseEvent) => {
            if (panel.current && !panel.current.contains(event.target as Node)) setOpen(false);
        };
        const escape = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', escape);
        };
    }, [open, items, load]);

    return (
        <div ref={panel} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-label={unread > 0 ? `Notificaciones: ${unread} sin leer` : 'Notificaciones'}
                aria-expanded={open}
                className="hover:bg-accent relative inline-flex size-9 items-center justify-center rounded-full"
            >
                <Bell className="size-5" />
                {unread > 0 && (
                    <span className="bg-alarm absolute -top-0.5 -right-0.5 min-w-5 rounded-full px-1 text-center text-[11px] leading-5 font-semibold text-white tabular-nums">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div className="bg-popover text-popover-foreground absolute right-0 z-50 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border shadow-lg">
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <p className="font-semibold">Notificaciones</p>
                        {unread > 0 && (
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-foreground text-sm"
                                onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true, onSuccess: () => void load() })}
                            >
                                Marcar todo como leído
                            </button>
                        )}
                    </div>
                    <div className="max-h-[60vh] divide-y overflow-y-auto">
                        {items === null ? (
                            <p className="text-muted-foreground px-4 py-6 text-sm">Cargando…</p>
                        ) : items.length === 0 ? (
                            <p className="text-muted-foreground px-4 py-6 text-sm">
                                No tenés notificaciones. Cuando pase algo en tus propiedades aparece acá.
                            </p>
                        ) : (
                            items.map((item) => <InboxRow key={item.id} item={item} compact />)
                        )}
                    </div>
                    {showAll && (
                        <Link href="/notifications" className="hover:bg-accent block border-t px-4 py-2.5 text-center text-sm font-medium">
                            Ver todas
                        </Link>
                    )}
                </div>
            )}

            {toast && (
                <div
                    role="status"
                    className={cn(
                        'bg-popover fixed right-4 bottom-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] gap-3 rounded-xl border p-4 shadow-lg',
                        toast.level === 'alarm' && 'border-alarm border-2',
                    )}
                >
                    <span className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', levelDot[toast.level])} aria-hidden />
                    <div className="min-w-0 flex-1">
                        <p className="font-semibold">{toast.title}</p>
                        {toast.body && <p className="text-muted-foreground line-clamp-2 text-sm">{toast.body}</p>}
                        {toast.url && (
                            <Link
                                href={`/notifications/${toast.id}`}
                                className="mt-1 inline-block text-sm font-medium underline underline-offset-2"
                                onClick={() => setToast(null)}
                            >
                                Abrir
                            </Link>
                        )}
                    </div>
                    <button
                        type="button"
                        aria-label="Cerrar aviso"
                        onClick={() => setToast(null)}
                        className="text-muted-foreground hover:text-foreground self-start"
                    >
                        <X className="size-4" />
                    </button>
                </div>
            )}
        </div>
    );
}
