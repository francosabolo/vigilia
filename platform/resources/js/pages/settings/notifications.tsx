import HeadingSmall from '@/components/heading-small';
import { PushToggle } from '@/components/push-toggle';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notificaciones', href: '/settings/notifications' }];

type Channel = 'email' | 'whatsapp' | 'push';

interface Props {
    preferences: Record<Channel, boolean>;
    hasPhone: boolean;
    pushDevices: number;
    vapidPublicKey: string | null;
}

export default function NotificationPreferences({ preferences, hasPhone, pushDevices, vapidPublicKey }: Props) {
    const form = useForm(preferences);

    const channels: { id: Channel; label: string; help: string }[] = [
        {
            id: 'push',
            label: 'En el celular o la computadora',
            help:
                pushDevices > 0
                    ? `Activadas en ${pushDevices === 1 ? '1 dispositivo' : `${pushDevices} dispositivos`}. Llegan aunque Vigilia esté cerrada.`
                    : 'Todavía no las activaste en ningún dispositivo: usá el botón de abajo.',
        },
        {
            id: 'whatsapp',
            label: 'WhatsApp',
            help: hasPhone
                ? 'Las alertas de incidentes, con la foto y los botones para responder.'
                : 'Cargá tu celular en el perfil para recibirlas.',
        },
        { id: 'email', label: 'Mail', help: 'Informes mensuales y avisos del sistema, como el tope diario de IA.' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notificaciones" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Notificaciones"
                        description="Todo te llega siempre a la campanita de Vigilia. Elegí por dónde más querés enterarte."
                    />
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.patch('/settings/notifications', { preserveScroll: true });
                        }}
                        className="space-y-4"
                    >
                        {channels.map((channel) => (
                            <label key={channel.id} className="flex cursor-pointer items-start gap-3 rounded-xl border p-4">
                                <input
                                    type="checkbox"
                                    checked={form.data[channel.id]}
                                    onChange={(e) => form.setData(channel.id, e.target.checked)}
                                    className="accent-foreground mt-1 size-4"
                                />
                                <span>
                                    <span className="block font-semibold">{channel.label}</span>
                                    <span className="text-muted-foreground block text-sm">{channel.help}</span>
                                </span>
                            </label>
                        ))}
                        <div className="flex items-center gap-3">
                            <Button disabled={form.processing || !form.isDirty}>Guardar preferencias</Button>
                            {form.recentlySuccessful && <p className="text-muted-foreground text-sm">Guardado.</p>}
                        </div>
                    </form>

                    <div className="space-y-2">
                        <HeadingSmall
                            title="Este dispositivo"
                            description="Activá las notificaciones en el navegador o el celular que estás usando ahora."
                        />
                        <PushToggle vapidPublicKey={vapidPublicKey} />
                    </div>

                    <p className="text-muted-foreground text-sm">
                        <Link href="/notifications" className="underline underline-offset-2">
                            Ver todas tus notificaciones
                        </Link>
                    </p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
