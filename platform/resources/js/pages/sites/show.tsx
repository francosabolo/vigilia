import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { formatDuration, objectLabels, timeAgo, type EdgeStatus, type Metrics } from '@/lib/seguria';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { PenTool, Trash2 } from 'lucide-react';
import { type FormEvent } from 'react';

interface Schedule {
    id: number;
    days: number[];
    starts_at: string;
    ends_at: string;
}

interface Zone {
    id: number;
    name: string;
    lot: string | null;
    has_polygon: boolean;
    frigate_zone: string;
    watched_labels: string[];
    schedules: Schedule[];
}

interface Camera {
    id: number;
    name: string;
    frigate_name: string;
    sector: string | null;
    is_online: boolean;
    last_seen_at: string | null;
    zones: Zone[];
}

interface Props {
    site: {
        id: number;
        name: string;
        address: string | null;
        timezone: string;
        guard_whatsapp: string | null;
        ack_timeout_seconds: number;
        edges: EdgeStatus[];
        cameras: Camera[];
    };
    residents: { id: number; name: string; email: string; lot: string | null; sector: string | null; away: boolean }[];
    sectors: string[];
    metrics: Metrics;
    canManage: boolean;
}

const DAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

function SiteSettings({ site }: { site: Props['site'] }) {
    const form = useForm({ guard_whatsapp: site.guard_whatsapp ?? '', ack_timeout_seconds: site.ack_timeout_seconds });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/sites/${site.id}`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <div className="grid gap-1">
                <Label htmlFor="guard_whatsapp">WhatsApp de la guardia</Label>
                <Input id="guard_whatsapp" placeholder="+5491155550000" value={form.data.guard_whatsapp} onChange={(e) => form.setData('guard_whatsapp', e.target.value)} />
                <InputError message={form.errors.guard_whatsapp} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="ack_timeout_seconds">Escalar a la central si no responde en (seg.)</Label>
                <Input id="ack_timeout_seconds" type="number" min={30} max={1800} value={form.data.ack_timeout_seconds} onChange={(e) => form.setData('ack_timeout_seconds', Number(e.target.value))} />
                <InputError message={form.errors.ack_timeout_seconds} />
            </div>
            <Button disabled={form.processing}>Guardar</Button>
        </form>
    );
}

function ScheduleForm({ zone }: { zone: Zone }) {
    const form = useForm({ days: [1, 2, 3, 4, 5, 6, 7], starts_at: '22:00', ends_at: '06:00' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/zones/${zone.id}/schedules`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
            <div className="flex gap-0.5">
                {DAYS.map((day, index) => {
                    const iso = index + 1;
                    const active = form.data.days.includes(iso);
                    return (
                        <button
                            type="button"
                            key={day}
                            onClick={() => form.setData('days', active ? form.data.days.filter((d) => d !== iso) : [...form.data.days, iso].sort())}
                            className={`size-7 rounded text-xs ${active ? 'bg-primary text-primary-foreground' : 'border'}`}
                        >
                            {day}
                        </button>
                    );
                })}
            </div>
            <Input type="time" className="w-28" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
            <span className="text-muted-foreground text-sm">a</span>
            <Input type="time" className="w-28" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
            <Button size="sm" variant="secondary" disabled={form.processing}>
                Agregar horario
            </Button>
            <InputError message={form.errors.days || form.errors.ends_at} />
        </form>
    );
}

function ResidentForm({ siteId, sectors }: { siteId: number; sectors: string[] }) {
    const form = useForm({ name: '', email: '', lot: '', sector: sectors[0] ?? '', phone: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/sites/${siteId}/residents`, { preserveScroll: true, onSuccess: () => form.reset() });
    }

    return (
        <form onSubmit={submit} className="grid gap-2 rounded-lg border border-dashed p-3 sm:grid-cols-2 lg:grid-cols-6">
            <Input placeholder="Nombre" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="lg:col-span-2" />
            <Input placeholder="Email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className="lg:col-span-2" />
            <Input placeholder="Lote" value={form.data.lot} onChange={(e) => form.setData('lot', e.target.value)} />
            <select value={form.data.sector} onChange={(e) => form.setData('sector', e.target.value)} className="border-input bg-background rounded-md border px-3 text-sm">
                <option value="">Sin sector</option>
                {sectors.map((sector) => (
                    <option key={sector} value={sector}>{sector}</option>
                ))}
            </select>
            <Input placeholder="Celular (opcional)" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className="lg:col-span-2" />
            <Button disabled={form.processing} className="lg:col-span-2">Invitar vecino</Button>
            <InputError message={form.errors.name || form.errors.email || form.errors.lot || form.errors.phone} className="lg:col-span-6" />
        </form>
    );
}

export default function SiteShow({ site, residents, sectors, metrics, canManage }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Barrios', href: '/sites' },
        { title: site.name, href: `/sites/${site.id}` },
    ];

    const remove = (url: string) => router.delete(url, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={site.name} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">{site.name}</h1>
                    <p className="text-muted-foreground text-sm">{site.address}</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Reporte de los últimos 7 días</CardTitle>
                        <CardDescription>Lo que la IA filtró antes de molestar a la guardia</CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <div><div className="text-2xl font-semibold tabular-nums">{metrics.detections}</div>detecciones del edge</div>
                        <div><div className="text-2xl font-semibold tabular-nums">{metrics.filtered_by_ai}</div>descartadas por IA</div>
                        <div><div className="text-2xl font-semibold tabular-nums">{metrics.incidents}</div>incidentes ({metrics.confirmed} confirmados)</div>
                        <div><div className="text-2xl font-semibold tabular-nums">{formatDuration(metrics.avg_ack_seconds)}</div>respuesta promedio</div>
                    </CardContent>
                </Card>

                {canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Respuesta y escalamiento</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <SiteSettings site={site} />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Equipos edge</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2 text-sm">
                        {site.edges.map((edge) => (
                            <div key={edge.id} className="flex items-center gap-2">
                                <span className={`size-2 rounded-full ${edge.online ? 'bg-ok' : 'bg-alarm'}`} />
                                {edge.name} {edge.version && <span className="text-muted-foreground">v{edge.version}</span>}
                                <span className="text-muted-foreground ml-auto">último heartbeat {timeAgo(edge.last_seen_at)}</span>
                            </div>
                        ))}
                        {site.edges.length === 0 && <p className="text-muted-foreground">Registrá un equipo con <code>php artisan seguria:edge-create {site.id} edge-1</code>.</p>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Vecinos ({residents.length})</CardTitle>
                        <CardDescription>Usan la app de vecinos: pánico, avisos y modo vacaciones. No ven cámaras ni imágenes.</CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        {residents.length > 0 && (
                            <div className="divide-y text-sm">
                                {residents.map((resident) => (
                                    <div key={resident.id} className="flex items-center gap-3 py-2">
                                        <span className="w-16 font-medium tabular-nums">Lote {resident.lot ?? '—'}</span>
                                        <span className="flex-1 truncate">{resident.name} <span className="text-muted-foreground ml-2">{resident.email}</span></span>
                                        {resident.sector && <span className="text-muted-foreground hidden sm:inline">{resident.sector}</span>}
                                        {resident.away && <span className="bg-surface-2 rounded px-1.5 text-xs">de vacaciones</span>}
                                        {canManage && (
                                            <Button size="icon" variant="ghost" className="size-7" onClick={() => remove(`/sites/${site.id}/residents/${resident.id}`)}>
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                        {canManage && <ResidentForm siteId={site.id} sectors={sectors} />}
                    </CardContent>
                </Card>

                <div className="grid gap-4 xl:grid-cols-2">
                    {site.cameras.map((camera) => (
                        <Card key={camera.id}>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span className={`size-2 rounded-full ${camera.is_online ? 'bg-ok' : 'bg-alarm'}`} />
                                    {camera.name}
                                    <span className="text-muted-foreground text-xs font-normal">{camera.frigate_name}</span>
                                </CardTitle>
                                <CardDescription>
                                    {camera.zones.length === 0 ? 'Sin zonas: se vigila la imagen completa, las 24 h.' : `${camera.zones.length} zona(s) vigilada(s)`}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                {camera.zones.map((zone) => (
                                    <div key={zone.id} className="flex flex-col gap-2 rounded-lg border p-3">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">{zone.name}</span>
                                            <code className="text-muted-foreground text-xs">{zone.frigate_zone}</code>
                                            {zone.lot && <span className="bg-surface-2 rounded px-1.5 text-xs">Lote {zone.lot}</span>}
                                            {!zone.has_polygon && <span className="text-xs text-pending">sin polígono</span>}
                                            <span className="text-muted-foreground text-xs">{zone.watched_labels.map((l) => objectLabels[l] ?? l).join(', ')}</span>
                                            {canManage && (
                                                <Button size="icon" variant="ghost" className="ml-auto size-7" onClick={() => remove(`/zones/${zone.id}`)}>
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            )}
                                        </div>
                                        <div className="flex flex-col gap-1 text-sm">
                                            {zone.schedules.length === 0 && <span className="text-muted-foreground">Armada las 24 h.</span>}
                                            {zone.schedules.map((schedule) => (
                                                <div key={schedule.id} className="flex items-center gap-2">
                                                    <span>{schedule.days.map((d) => DAYS[d - 1]).join(' ')}</span>
                                                    <span className="tabular-nums">{schedule.starts_at}–{schedule.ends_at}</span>
                                                    {canManage && (
                                                        <Button size="icon" variant="ghost" className="size-6" onClick={() => remove(`/schedules/${schedule.id}`)}>
                                                            <Trash2 className="size-3" />
                                                        </Button>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                        {canManage && <ScheduleForm zone={zone} />}
                                    </div>
                                ))}
                                {canManage && (
                                    <Button asChild variant="secondary" size="sm" className="self-start">
                                        <Link href={`/cameras/${camera.id}/zones`}>
                                            <PenTool className="mr-1 size-4" /> Dibujar zonas sobre la imagen
                                        </Link>
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
