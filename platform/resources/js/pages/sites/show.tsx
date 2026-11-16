import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { formatDuration, timeAgo, type EdgeStatus, type Metrics } from '@/lib/seguria';
import { type BreadcrumbItem } from '@/types';
import { SiteMap } from '@/components/site-map';
import { type LatLng } from '@/lib/geo';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Cctv, PenTool, Trash2, VideoOff } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

type Tab = 'resumen' | 'cobertura' | 'camaras' | 'vecinos' | 'ajustes';

interface Schedule {
    days: number[];
    starts_at: string;
    ends_at: string;
}

interface Zone {
    id: number;
    name: string;
    lot: string | null;
    has_polygon: boolean;
    schedules: Schedule[];
}

interface Camera {
    id: number;
    name: string;
    sector: string | null;
    is_online: boolean;
    position: LatLng | null;
    heading: number;
    fov: number;
    range_m: number;
    preview_url: string | null;
    zones: Zone[];
}

interface Resident {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    lot: string | null;
    sector: string | null;
    away_until: string | null;
    last_login_at: string | null;
    last_active_at: string | null;
    active_sessions: number;
    devices: string[];
    push_devices: number;
}

interface Props {
    tab: Tab;
    canManage: boolean;
    site: {
        id: number;
        name: string;
        address: string | null;
        perimeter: LatLng[];
        center: LatLng | null;
        guard_whatsapp: string | null;
        ack_timeout_seconds: number;
        open_incidents: number;
        escalated_incidents: number;
        edges: EdgeStatus[];
        cameras: Camera[];
    };
    residents: Resident[] | null;
    residentsCount: number;
    sectors: string[];
    metrics: Metrics;
}

const DAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

function scheduleText(schedules: Schedule[]): string {
    if (schedules.length === 0) return 'Vigilada las 24 h';
    return schedules
        .map((schedule) => {
            const days = schedule.days.length === 7 ? 'Todos los días' : schedule.days.map((day) => DAYS[day - 1]).join(' ');
            return `${days}, de ${schedule.starts_at} a ${schedule.ends_at}`;
        })
        .join('; ');
}

/* ---------- Header ---------- */

function WatchHeader({ site }: { site: Props['site'] }) {
    const onlineCameras = site.cameras.filter((camera) => camera.is_online).length;
    const edgesDown = site.edges.filter((edge) => !edge.online).length;
    const noEdge = site.edges.length === 0;

    const [tone, sentence] = site.escalated_incidents
        ? (['alarm', `${site.escalated_incidents} incidente(s) sin respuesta de la guardia`] as const)
        : noEdge
          ? (['muted', 'Sin equipo edge conectado: el barrio todavía no está vigilado'] as const)
          : edgesDown
            ? (['alarm', 'El equipo edge no responde: las cámaras no se están analizando'] as const)
            : site.open_incidents
              ? (['pending', `Vigilado, con ${site.open_incidents} incidente(s) en curso`] as const)
              : (['ok', 'Vigilado, sin novedades'] as const);

    return (
        <header className="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 className="font-display text-4xl leading-tight font-semibold">{site.name}</h1>
                {site.address && <p className="text-muted-foreground">{site.address}</p>}
                <p className={cn('mt-2 flex items-center gap-2 font-medium', tone === 'alarm' && 'text-alarm', tone === 'muted' && 'text-muted-foreground')}>
                    <span
                        className={cn(
                            'size-2.5 rounded-full',
                            tone === 'alarm' && 'bg-alarm animate-pulse',
                            tone === 'pending' && 'bg-pending',
                            tone === 'ok' && 'bg-ok',
                            tone === 'muted' && 'bg-muted-foreground',
                        )}
                    />
                    {sentence}
                </p>
            </div>
            <div className="flex items-center gap-2">
                <span className="text-muted-foreground mr-2 flex items-center gap-1.5 text-sm">
                    <Cctv className="size-4" />
                    {onlineCameras} de {site.cameras.length} cámaras online
                </span>
                {site.open_incidents > 0 && (
                    <Button asChild variant="secondary">
                        <Link href="/incidents">Ver incidentes</Link>
                    </Button>
                )}
                <Button asChild>
                    <Link href={`/cameras?site=${site.id}`}>Ver cámaras en vivo</Link>
                </Button>
            </div>
        </header>
    );
}

function Tabs({ siteId, tab, canManage, residentsCount, camerasCount }: { siteId: number; tab: Tab; canManage: boolean; residentsCount: number; camerasCount: number }) {
    const tabs: { id: Tab; label: string; count?: number }[] = [
        { id: 'resumen', label: 'Resumen' },
        { id: 'cobertura', label: 'Cobertura' },
        { id: 'camaras', label: 'Cámaras y zonas', count: camerasCount },
        { id: 'vecinos', label: 'Vecinos', count: residentsCount },
        ...(canManage ? [{ id: 'ajustes' as Tab, label: 'Ajustes' }] : []),
    ];

    return (
        <nav className="flex gap-1 overflow-x-auto border-b" aria-label="Secciones del barrio">
            {tabs.map((item) => (
                <Link
                    key={item.id}
                    href={`/sites/${siteId}?tab=${item.id}`}
                    preserveScroll
                    aria-current={tab === item.id ? 'page' : undefined}
                    className={cn(
                        '-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-[15px] font-medium whitespace-nowrap',
                        tab === item.id ? 'border-foreground text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                    )}
                >
                    {item.label}
                    {item.count !== undefined && <span className="text-muted-foreground text-sm tabular-nums">{item.count}</span>}
                </Link>
            ))}
        </nav>
    );
}

/* ---------- Resumen ---------- */

function NoCameras({ siteId }: { siteId: number }) {
    return (
        <div className="rounded-xl border border-dashed p-8 text-center">
            <p className="text-lg font-semibold">Este barrio todavía no tiene cámaras</p>
            <p className="text-muted-foreground mx-auto mt-1 max-w-md text-sm">
                Las cámaras aparecen solas cuando el equipo edge del barrio se conecta. Para registrar uno, corré <code className="bg-surface-2 rounded px-1">php artisan seguria:edge-create {siteId} edge-1</code> y cargá el token en el equipo.
            </p>
        </div>
    );
}

function Summary({ site, metrics, canManage }: Pick<Props, 'site' | 'metrics' | 'canManage'>) {
    usePoll(10000, { only: ['site'] }, { keepAlive: true });

    return (
        <div className="flex flex-col gap-8">
            <SiteMap siteId={site.id} perimeter={site.perimeter} center={site.center} cameras={site.cameras} canManage={canManage} />

            {site.cameras.length === 0 && <NoCameras siteId={site.id} />}

            <div className="grid gap-8 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <section>
                    <h2 className="mb-3 text-lg font-semibold">Últimos 7 días</h2>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-5 sm:grid-cols-4">
                        {[
                            [metrics.detections, 'movimientos analizados'],
                            [metrics.filtered_by_ai + metrics.outside_schedule, 'descartados'],
                            [metrics.incidents, 'alertas a la guardia'],
                            [formatDuration(metrics.avg_ack_seconds), 'respuesta promedio'],
                        ].map(([value, label]) => (
                            <div key={label} className="flex flex-col-reverse">
                                <dt className="text-muted-foreground mt-1 text-sm">{label}</dt>
                                <dd className="font-display text-4xl leading-none font-semibold tabular-nums">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
                <section>
                    <h2 className="mb-3 text-lg font-semibold">Equipos edge</h2>
                    {site.edges.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Ninguno conectado.</p>
                    ) : (
                        <ul className="flex flex-col gap-2 text-sm">
                            {site.edges.map((edge) => (
                                <li key={edge.id} className="flex items-center gap-2">
                                    <span className={cn('size-2 rounded-full', edge.online ? 'bg-ok' : 'bg-alarm')} />
                                    <span className="font-medium">{edge.name}</span>
                                    <span className="text-muted-foreground ml-auto">{edge.online ? 'conectado' : `sin señal ${timeAgo(edge.last_seen_at)}`}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </div>
    );
}

/* ---------- Cámaras y zonas ---------- */

function CamerasTab({ site, canManage }: Pick<Props, 'site' | 'canManage'>) {
    if (site.cameras.length === 0) return <NoCameras siteId={site.id} />;

    return (
        <div className="bg-card divide-y rounded-xl border">
            {site.cameras.map((camera) => (
                <div key={camera.id} className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start">
                    <div className="aspect-video w-full shrink-0 overflow-hidden rounded-md bg-black sm:w-48">
                        {camera.preview_url ? (
                            <img src={camera.preview_url} alt={camera.name} className="size-full object-cover" />
                        ) : (
                            <div className="text-muted-foreground flex size-full items-center justify-center">
                                <VideoOff className="size-5" />
                            </div>
                        )}
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <span className={cn('size-2 rounded-full', camera.is_online ? 'bg-ok' : 'bg-alarm')} />
                            <span className="font-display text-xl font-semibold">{camera.name}</span>
                            {camera.sector && <span className="text-muted-foreground">{camera.sector}</span>}
                        </div>
                        {camera.zones.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">Sin zonas: se analiza la imagen completa, las 24 h.</p>
                        ) : (
                            <ul className="mt-2 flex flex-col gap-1.5 text-sm">
                                {camera.zones.map((zone) => (
                                    <li key={zone.id}>
                                        <span className="font-medium">{zone.name}</span>
                                        {zone.lot && <span className="text-muted-foreground">, lote {zone.lot}</span>}
                                        <span className="text-muted-foreground">. {scheduleText(zone.schedules)}.</span>
                                        {!zone.has_polygon && <span className="text-pending"> Falta dibujarla.</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    {canManage && (
                        <Button asChild variant="secondary" className="shrink-0">
                            <Link href={`/cameras/${camera.id}/zones`}>
                                <PenTool className="mr-1 size-4" /> Editar zonas y horarios
                            </Link>
                        </Button>
                    )}
                </div>
            ))}
        </div>
    );
}

/* ---------- Vecinos ---------- */

function InviteResident({ siteId, sectors }: { siteId: number; sectors: string[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', email: '', lot: '', sector: sectors[0] ?? '', phone: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/sites/${siteId}/residents`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    }

    const field = (id: keyof typeof form.data, label: string, input: ReactNode) => (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {input}
            <InputError message={form.errors[id]} />
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>Invitar vecino</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Invitar vecino</DialogTitle>
                        <DialogDescription>Le llega un mail para crear su contraseña y entrar a la app de vecinos.</DialogDescription>
                    </DialogHeader>
                    {field('name', 'Nombre y apellido', <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />)}
                    {field('email', 'Email', <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />)}
                    <div className="grid grid-cols-2 gap-3">
                        {field('lot', 'Lote', <Input id="lot" value={form.data.lot} onChange={(e) => form.setData('lot', e.target.value)} />)}
                        {field(
                            'sector',
                            'Sector',
                            <select id="sector" value={form.data.sector} onChange={(e) => form.setData('sector', e.target.value)} className="border-input bg-background h-9 rounded-md border px-3 text-sm">
                                <option value="">Sin sector</option>
                                {sectors.map((sector) => (
                                    <option key={sector} value={sector}>
                                        {sector}
                                    </option>
                                ))}
                            </select>,
                        )}
                    </div>
                    {field('phone', 'Celular (opcional, para que la guardia pueda llamarlo)', <Input id="phone" placeholder="+5491155550000" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />)}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Enviar invitación
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RemoveResident({ siteId, resident }: { siteId: number; resident: Resident }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button size="icon" variant="ghost" className="size-8" aria-label={`Quitar a ${resident.name}`}>
                    <Trash2 className="size-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Quitar a {resident.name}?</DialogTitle>
                    <DialogDescription>Pierde el acceso a la app de vecinos: el botón de pánico, los avisos y el modo vacaciones. Lo podés volver a invitar cuando quieras.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/sites/${siteId}/residents/${resident.id}`, { preserveScroll: true })}>
                        Quitar vecino
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AppUsage({ resident }: { resident: Resident }) {
    if (!resident.last_login_at && resident.active_sessions === 0) {
        return <span className="text-pending">Nunca entró</span>;
    }

    return (
        <div>
            <div className="flex items-center gap-1.5">
                <span className={cn('size-2 rounded-full', resident.active_sessions > 0 ? 'bg-ok' : 'bg-muted-foreground')} />
                {resident.active_sessions > 0
                    ? `Con sesión abierta en ${resident.active_sessions === 1 ? '1 dispositivo' : `${resident.active_sessions} dispositivos`}`
                    : `Último ingreso ${timeAgo(resident.last_login_at)}`}
            </div>
            <div className="text-muted-foreground">
                {resident.devices.length > 0 && `${resident.devices.join(', ')}. `}
                {resident.push_devices > 0 ? `Recibe alertas en ${resident.push_devices === 1 ? '1 dispositivo' : `${resident.push_devices} dispositivos`}.` : 'Sin alertas activadas.'}
            </div>
        </div>
    );
}

function ResidentsTab({ site, residents, sectors, canManage }: Pick<Props, 'site' | 'sectors' | 'canManage'> & { residents: Resident[] }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-muted-foreground max-w-xl text-sm">Los vecinos usan su propia app: botón de pánico, avisos y modo vacaciones. No ven cámaras ni imágenes.</p>
                {canManage && <InviteResident siteId={site.id} sectors={sectors} />}
            </div>

            {residents.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center">
                    <p className="text-lg font-semibold">Todavía no hay vecinos en la app</p>
                    <p className="text-muted-foreground mt-1 text-sm">{canManage ? 'Invitá al primero con su email y su lote.' : 'La administración del barrio los puede invitar.'}</p>
                </div>
            ) : (
                <div className="bg-card overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-[15px]">
                        <thead className="text-muted-foreground border-b text-sm">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">Lote</th>
                                <th className="px-4 py-2.5 font-medium">Vecino</th>
                                <th className="hidden px-4 py-2.5 font-medium md:table-cell">Sector</th>
                                <th className="hidden px-4 py-2.5 font-medium md:table-cell">Celular</th>
                                <th className="px-4 py-2.5 font-medium">Uso de la app</th>
                                <th className="hidden px-4 py-2.5 font-medium lg:table-cell">Estado</th>
                                {canManage && <th className="w-12" />}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {residents.map((resident) => (
                                <tr key={resident.id}>
                                    <td className="font-display px-4 py-3 text-lg font-semibold tabular-nums">{resident.lot ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{resident.name}</div>
                                        <div className="text-muted-foreground text-sm">{resident.email}</div>
                                    </td>
                                    <td className="text-muted-foreground hidden px-4 py-3 md:table-cell">{resident.sector ?? '—'}</td>
                                    <td className="text-muted-foreground hidden px-4 py-3 md:table-cell">{resident.phone ?? '—'}</td>
                                    <td className="px-4 py-3 text-sm">
                                        <AppUsage resident={resident} />
                                    </td>
                                    <td className="hidden px-4 py-3 text-sm lg:table-cell">
                                        {resident.away_until ? `De vacaciones hasta el ${new Date(resident.away_until + 'T12:00').toLocaleDateString('es-AR')}` : <span className="text-muted-foreground">En casa</span>}
                                    </td>
                                    {canManage && (
                                        <td className="px-2 py-3">
                                            <RemoveResident siteId={site.id} resident={resident} />
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

/* ---------- Ajustes ---------- */

function SettingsTab({ site }: Pick<Props, 'site'>) {
    const form = useForm({ guard_whatsapp: site.guard_whatsapp ?? '', ack_timeout_seconds: Math.round(site.ack_timeout_seconds / 60) * 60 });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/sites/${site.id}`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex max-w-xl flex-col gap-6">
            <div className="grid gap-1.5">
                <Label htmlFor="guard_whatsapp">WhatsApp de la guardia</Label>
                <Input id="guard_whatsapp" placeholder="+5491155550000" value={form.data.guard_whatsapp} onChange={(e) => form.setData('guard_whatsapp', e.target.value)} />
                <p className="text-muted-foreground text-sm">Recibe cada alerta con la foto y los botones para responder. Es el celular de la garita, no el de una persona.</p>
                <InputError message={form.errors.guard_whatsapp} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="ack_timeout_seconds">Si la guardia no responde, escalar a la central después de</Label>
                <select
                    id="ack_timeout_seconds"
                    value={form.data.ack_timeout_seconds}
                    onChange={(e) => form.setData('ack_timeout_seconds', Number(e.target.value))}
                    className="border-input bg-background h-9 max-w-48 rounded-md border px-3 text-sm"
                >
                    {[60, 120, 180, 300, 600].map((seconds) => (
                        <option key={seconds} value={seconds}>
                            {seconds / 60} {seconds === 60 ? 'minuto' : 'minutos'}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.ack_timeout_seconds} />
            </div>

            <div className="flex items-center gap-3">
                <Button disabled={form.processing || !form.isDirty}>Guardar ajustes</Button>
                {form.recentlySuccessful && <span className="text-ok text-sm">Guardado</span>}
            </div>
        </form>
    );
}

/* ---------- Page ---------- */

export default function SiteShow({ tab, canManage, site, residents, residentsCount, sectors, metrics }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Barrios', href: '/sites' },
        { title: site.name, href: `/sites/${site.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={site.name} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <WatchHeader site={site} />
                <Tabs siteId={site.id} tab={tab} canManage={canManage} residentsCount={residentsCount} camerasCount={site.cameras.length} />

                {tab === 'resumen' && <Summary site={site} metrics={metrics} canManage={canManage} />}
                {tab === 'cobertura' && (
                    <SiteMap siteId={site.id} perimeter={site.perimeter} center={site.center} cameras={site.cameras} canManage={canManage} variant="coverage" />
                )}
                {tab === 'camaras' && <CamerasTab site={site} canManage={canManage} />}
                {tab === 'vecinos' && residents && <ResidentsTab site={site} residents={residents} sectors={sectors} canManage={canManage} />}
                {tab === 'ajustes' && canManage && <SettingsTab site={site} />}
            </div>
        </AppLayout>
    );
}
