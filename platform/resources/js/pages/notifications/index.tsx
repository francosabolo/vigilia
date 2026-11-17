import { InboxRow, type InboxItem } from '@/components/notification-bell';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notificaciones', href: '/notifications' }];

interface Props {
    notifications: {
        data: InboxItem[];
        last_page: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
}

export default function NotificationsIndex({ notifications }: Props) {
    const hasUnread = notifications.data.some((item) => !item.read);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notificaciones" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">Notificaciones</h1>
                        <p className="text-muted-foreground">
                            Todo lo que Vigilia te avisó. Elegí si además querés recibirlo por mail o WhatsApp en{' '}
                            <Link href="/settings/notifications" className="underline underline-offset-2">
                                tus preferencias
                            </Link>
                            .
                        </p>
                    </div>
                    {hasUnread && (
                        <Button variant="outline" onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })}>
                            Marcar todo como leído
                        </Button>
                    )}
                </div>

                {notifications.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                        <Bell className="text-muted-foreground size-6" />
                        <p className="font-semibold">Todavía no tenés notificaciones</p>
                        <p className="text-muted-foreground max-w-sm text-sm">Cuando pase algo en tus propiedades te avisamos acá, en el momento.</p>
                    </div>
                ) : (
                    <div className="bg-card divide-y overflow-hidden rounded-2xl border">
                        {notifications.data.map((item) => (
                            <InboxRow key={item.id} item={item} />
                        ))}
                    </div>
                )}

                {notifications.last_page > 1 && (
                    <nav className="flex flex-wrap gap-1 text-sm" aria-label="Páginas">
                        {notifications.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    className={cn('rounded-md px-3 py-1.5', link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label.replace('&laquo; Previous', '‹ Anterior').replace('Next &raquo;', 'Siguiente ›'),
                                    }}
                                />
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
