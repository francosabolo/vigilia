import { SectionTitle } from '@/components/icon-circle';
import InputError from '@/components/input-error';
import { ItemActionButton, ItemActionLink, ItemActions } from '@/components/item-action';
import { SiteMap } from '@/components/site-map';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type LatLng } from '@/lib/geo';
import {
    formatDuration,
    plural,
    theSite,
    timeAgo,
    unitNoun,
    zoneKindLabel,
    type EdgeStatus,
    type Metrics,
    type SiteKind,
    type ZoneKind,
} from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { BarChart3, Cctv, Cpu, Siren, VideoOff } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

type Tab = 'resumen' | 'cobertura' | 'camaras' | 'vecinos' | 'ajustes';

interface Schedule {
    days: number[];
    starts_at: string;
    ends_at: string;
}

type Autonomy = 'calibration' | 'assisted' | 'deterrence';

interface Zone {
    id: number;
    name: string;
    kind: ZoneKind;
    autonomy: Autonomy;
    autonomy_eligibility: { eligible: boolean; alerts: number; false_alarms: number; false_alarm_rate: number | null; reason: string } | null;
    lot: string | null;
    has_polygon: boolean;
    schedules: Schedule[];
}

interface Camera {
    id: number;
    name: string;
    sector: string | null;
    is_online: boolean;
    view_status: 'ok' | 'moved' | 'covered';
    view_shift_deg: number | null;
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
    canManageEdges: boolean;
    newEdge: { id: number; name: string; token: string } | null;
    edgeServerUrl: string;
    aiUsage: { used: number; cap: number } | null;
    site: {
        id: number;
        name: string;
        kind: SiteKind;
        is_simulated: boolean;
        address: string | null;
        perimeter: LatLng[];
        center: LatLng | null;
        guard_whatsapp: string | null;
        ack_timeout_seconds: number;
        open_incidents: number;
        escalated_incidents: number;
        escalated_incident_id: number | null;
        edges: EdgeStatus[];
        cameras: Camera[];
    };
    residents: Resident[] | null;
    residentsCount: number;
    sectors: string[];
    metrics: Metrics;
}

const DAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

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
        ? (['alarm', `${plural(site.escalated_incidents, 'incidente', 'incidentes')} sin respuesta de la guardia`] as const)
        : noEdge
          ? (['muted', `Sin equipo edge conectado: ${theSite(site.kind)} todavía no está vigilado`] as const)
          : edgesDown
            ? (['alarm', 'El equipo edge no responde: las cámaras no se están analizando'] as const)
            : site.open_incidents
              ? (['pending', `Vigilado, con ${plural(site.open_incidents, 'incidente', 'incidentes')} en curso`] as const)
              : (['ok', 'Vigilado, sin novedades'] as const);

    return (
        <header className="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 className="font-display flex items-center gap-3 text-4xl leading-tight font-semibold">
                    {site.name}
                    <span className="bg-surface-2 text-muted-foreground rounded-full px-2.5 py-0.5 font-sans text-sm font-medium">
                        {site.kind === 'country' ? 'Country' : 'Edificio'}
                    </span>
                    {site.is_simulated && (
                        <span className="bg-surface-2 text-muted-foreground rounded px-2 py-0.5 font-sans text-sm font-medium">Simulado</span>
                    )}
                </h1>
                {site.address && <p className="text-muted-foreground">{site.address}</p>}
                <p
                    className={cn(
                        'mt-2 flex items-center gap-2 font-medium',
                        tone === 'alarm' && 'text-alarm',
                        tone === 'muted' && 'text-muted-foreground',
                    )}
                >
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
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                {/* Header links are navigation, not actions: they never compete with the tab's primary action. */}
                <Link
                    href={`/cameras?site=${site.id}`}
                    className="hover:text-foreground text-muted-foreground flex items-center gap-1.5 text-[15px] font-medium underline-offset-4 hover:underline"
                >
                    <Cctv className="size-4" />
                    Cámaras en vivo
                    <span className="tabular-nums">
                        ({onlineCameras}/{site.cameras.length})
                    </span>
                </Link>
                {site.open_incidents > 0 && !site.escalated_incident_id && (
                    <Link
                        href="/incidents"
                        className="hover:text-foreground text-muted-foreground text-[15px] font-medium underline-offset-4 hover:underline"
                    >
                        Incidentes en curso
                    </Link>
                )}
                {site.escalated_incident_id && (
                    <Button asChild variant="destructive" size="lg">
                        <Link href={`/incidents/${site.escalated_incident_id}`}>
                            <Siren className="mr-1 size-4" /> Atender incidente
                        </Link>
                    </Button>
                )}
            </div>
        </header>
    );
}

function Tabs({
    siteId,
    tab,
    canManage,
    residentsCount,
    camerasCount,
}: {
    siteId: number;
    tab: Tab;
    canManage: boolean;
    residentsCount: number;
    camerasCount: number;
}) {
    const tabs: { id: Tab; label: string; count?: number }[] = [
        { id: 'resumen', label: 'Resumen' },
        { id: 'cobertura', label: 'Cobertura' },
        { id: 'camaras', label: 'Cámaras y zonas', count: camerasCount },
        { id: 'vecinos', label: 'Vecinos', count: residentsCount },
        ...(canManage ? [{ id: 'ajustes' as Tab, label: 'Ajustes' }] : []),
    ];

    return (
        <nav className="flex gap-1 overflow-x-auto border-b [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Secciones">
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
            <p className="text-lg font-semibold">Todavía no hay cámaras conectadas</p>
            <p className="text-muted-foreground mx-auto mt-1 max-w-md text-sm">
                Las cámaras aparecen solas cuando se conecta el equipo edge del lugar. Para registrar uno, corré{' '}
                <code className="bg-surface-2 rounded px-1">php artisan seguria:edge-create {siteId} edge-1</code> y cargá el token en el equipo.
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
                    <SectionTitle icon={BarChart3} className="mb-3">
                        Últimos 7 días
                    </SectionTitle>
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
                    <SectionTitle icon={Cpu} className="mb-3">
                        Equipos edge
                    </SectionTitle>
                    {site.edges.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Ninguno conectado.</p>
                    ) : (
                        <ul className="flex flex-col gap-2 text-sm">
                            {site.edges.map((edge) => (
                                <li key={edge.id} className="flex items-center gap-2">
                                    <span className={cn('size-2 rounded-full', edge.online ? 'bg-ok' : 'bg-alarm')} />
                                    <span className="font-medium">{edge.name}</span>
                                    <span className="text-muted-foreground ml-auto">
                                        {edge.online ? 'conectado' : `sin señal ${timeAgo(edge.last_seen_at)}`}
                                    </span>
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
                        {camera.view_status !== 'ok' && (
                            <p className="text-alarm mt-1 text-sm font-medium">
                                {camera.view_status === 'covered'
                                    ? 'Parece tapada o encandilada.'
                                    : `Parece movida${camera.view_shift_deg ? ` (unos ${Math.abs(camera.view_shift_deg)}°)` : ''}. No cuenta como cobertura hasta revisarla.`}
                            </p>
                        )}
                        {camera.zones.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">Sin zonas: se analiza la imagen completa, las 24 h.</p>
                        ) : (
                            <ul className="mt-2 flex flex-col gap-1.5 text-sm">
                                {camera.zones.map((zone) => (
                                    <li key={zone.id}>
                                        <span className="font-medium">{zone.name}</span>
                                        <span className="text-muted-foreground"> ({zoneKindLabel[zone.kind].toLowerCase()})</span>
                                        {zone.lot && (
                                            <span className="text-muted-foreground">
                                                , {unitNoun(site.kind)} {zone.lot}
                                            </span>
                                        )}
                                        <span className="text-muted-foreground">. {scheduleText(zone.schedules)}.</span>
                                        {!zone.has_polygon && <span className="text-pending"> Falta dibujarla.</span>}{' '}
                                        <AutonomyControl zone={zone} canManage={canManage} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <ItemActions>
                        <ItemActionLink kind="view" href={`/cameras/${camera.id}`}>
                            Ver en vivo
                        </ItemActionLink>
                        {canManage && (
                            <ItemActionLink kind="edit" href={`/cameras/${camera.id}/zones`}>
                                Editar zonas
                            </ItemActionLink>
                        )}
                    </ItemActions>
                </div>
            ))}
        </div>
    );
}

/* ---------- Vecinos ---------- */

function InviteResident({ siteId, sectors, alarmActive, kind }: { siteId: number; sectors: string[]; alarmActive: boolean; kind: SiteKind }) {
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
                <Button variant={alarmActive ? 'secondary' : 'default'}>Invitar vecino</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Invitar vecino</DialogTitle>
                        <DialogDescription>Le llega un mail para crear su contraseña y entrar a la app de vecinos.</DialogDescription>
                    </DialogHeader>
                    {field(
                        'name',
                        'Nombre y apellido',
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />,
                    )}
                    {field(
                        'email',
                        'Email',
                        <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />,
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        {field(
                            'lot',
                            capitalize(unitNoun(kind)),
                            <Input id="lot" value={form.data.lot} onChange={(e) => form.setData('lot', e.target.value)} />,
                        )}
                        {field(
                            'sector',
                            'Sector',
                            <select
                                id="sector"
                                value={form.data.sector}
                                onChange={(e) => form.setData('sector', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Sin sector</option>
                                {sectors.map((sector) => (
                                    <option key={sector} value={sector}>
                                        {sector}
                                    </option>
                                ))}
                            </select>,
                        )}
                    </div>
                    {field(
                        'phone',
                        'Celular (opcional, para que la guardia pueda llamarlo)',
                        <Input
                            id="phone"
                            placeholder="+5491155550000"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                        />,
                    )}
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

function EditResident({ siteId, resident, sectors, kind }: { siteId: number; resident: Resident; sectors: string[]; kind: SiteKind }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: resident.name, lot: resident.lot ?? '', sector: resident.sector ?? '', phone: resident.phone ?? '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/sites/${siteId}/residents/${resident.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <ItemActionButton kind="edit" aria-label={`Editar a ${resident.name}`} />
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Editar a {resident.name}</DialogTitle>
                        <DialogDescription>El email no se cambia acá: es con el que entra a la app.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`name-${resident.id}`}>Nombre y apellido</Label>
                        <Input id={`name-${resident.id}`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`lot-${resident.id}`}>{capitalize(unitNoun(kind))}</Label>
                            <Input id={`lot-${resident.id}`} value={form.data.lot} onChange={(e) => form.setData('lot', e.target.value)} />
                            <InputError message={form.errors.lot} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`sector-${resident.id}`}>Sector</Label>
                            <select
                                id={`sector-${resident.id}`}
                                value={form.data.sector}
                                onChange={(e) => form.setData('sector', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Sin sector</option>
                                {sectors.map((sector) => (
                                    <option key={sector} value={sector}>
                                        {sector}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`phone-${resident.id}`}>Celular</Label>
                        <Input id={`phone-${resident.id}`} value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        <InputError message={form.errors.phone} />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing || !form.isDirty}>
                            Guardar cambios
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
                <ItemActionButton kind="delete" aria-label={`Quitar a ${resident.name}`}>
                    Quitar
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Quitar a {resident.name}?</DialogTitle>
                    <DialogDescription>
                        Pierde el acceso a la app de vecinos: el botón de pánico, los avisos y el modo vacaciones. Lo podés volver a invitar cuando
                        quieras.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() => router.delete(`/sites/${siteId}/residents/${resident.id}`, { preserveScroll: true })}
                    >
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
                {resident.push_devices > 0
                    ? `Recibe alertas en ${resident.push_devices === 1 ? '1 dispositivo' : `${resident.push_devices} dispositivos`}.`
                    : 'Sin alertas activadas.'}
            </div>
        </div>
    );
}

function ResidentsTab({ site, residents, sectors, canManage }: Pick<Props, 'site' | 'sectors' | 'canManage'> & { residents: Resident[] }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-muted-foreground max-w-xl text-sm">
                    Los vecinos usan su propia app: botón de pánico, avisos y modo vacaciones. No ven cámaras ni imágenes.
                </p>
                {canManage && <InviteResident siteId={site.id} sectors={sectors} alarmActive={!!site.escalated_incident_id} kind={site.kind} />}
            </div>

            {residents.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center">
                    <p className="text-lg font-semibold">Todavía no hay vecinos en la app</p>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {canManage ? `Invitá al primero con su email y su ${unitNoun(site.kind)}.` : 'La administración los puede invitar.'}
                    </p>
                </div>
            ) : (
                <div className="bg-card overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-[15px]">
                        <thead className="text-muted-foreground border-b text-sm">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">{capitalize(unitNoun(site.kind))}</th>
                                <th className="px-4 py-2.5 font-medium">Vecino</th>
                                <th className="hidden px-4 py-2.5 font-medium md:table-cell">Sector</th>
                                <th className="hidden px-4 py-2.5 font-medium md:table-cell">Celular</th>
                                <th className="px-4 py-2.5 font-medium">Uso de la app</th>
                                <th className="hidden px-4 py-2.5 font-medium lg:table-cell">Estado</th>
                                {canManage && <th className="px-4 py-2.5 text-right font-medium">Acciones</th>}
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
                                        {resident.away_until ? (
                                            `De vacaciones hasta el ${new Date(resident.away_until + 'T12:00').toLocaleDateString('es-AR')}`
                                        ) : (
                                            <span className="text-muted-foreground">En casa</span>
                                        )}
                                    </td>
                                    {canManage && (
                                        <td className="px-2 py-3">
                                            <ItemActions className="justify-end">
                                                <EditResident siteId={site.id} resident={resident} sectors={sectors} kind={site.kind} />
                                                <RemoveResident siteId={site.id} resident={resident} />
                                            </ItemActions>
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

function SettingsTab({ site, aiUsage }: Pick<Props, 'site' | 'aiUsage'>) {
    const alarmActive = !!site.escalated_incident_id;
    const { retentionDays } = usePage<{ retentionDays: number }>().props;
    const form = useForm({ guard_whatsapp: site.guard_whatsapp ?? '', ack_timeout_seconds: Math.round(site.ack_timeout_seconds / 60) * 60 });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/sites/${site.id}`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex max-w-xl flex-col gap-6">
            <div className="grid gap-1.5">
                <Label htmlFor="guard_whatsapp">WhatsApp de la guardia</Label>
                <Input
                    id="guard_whatsapp"
                    placeholder="+5491155550000"
                    value={form.data.guard_whatsapp}
                    onChange={(e) => form.setData('guard_whatsapp', e.target.value)}
                />
                <p className="text-muted-foreground text-sm">
                    Recibe cada alerta con la foto y los botones para responder. Es el celular de la garita, no el de una persona.
                </p>
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

            {aiUsage && aiUsage.cap > 0 && (
                <div className="bg-muted/60 grid gap-2 rounded-2xl p-4">
                    <div className="flex items-baseline justify-between gap-3">
                        <p className="text-sm font-semibold">Verificaciones de IA hoy</p>
                        <p className="font-display text-lg tabular-nums">
                            {aiUsage.used.toLocaleString('es-AR')}{' '}
                            <span className="text-muted-foreground text-sm">de {aiUsage.cap.toLocaleString('es-AR')}</span>
                        </p>
                    </div>
                    <div className="bg-background h-1.5 overflow-hidden rounded-full">
                        <div
                            className={cn('h-full rounded-full', aiUsage.used >= aiUsage.cap ? 'bg-alarm' : 'bg-foreground/70')}
                            style={{ width: `${Math.min(100, (aiUsage.used / aiUsage.cap) * 100)}%` }}
                        />
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {aiUsage.used >= aiUsage.cap
                            ? 'Se alcanzó el tope de hoy: las detecciones se alertan sin el filtro de IA hasta mañana. Revisá qué cámara genera tantas.'
                            : 'Al llegar al tope, las detecciones se siguen alertando, pero sin el filtro de IA, y avisamos por mail a los administradores.'}
                    </p>
                </div>
            )}

            <div className="bg-muted/60 grid gap-1 rounded-2xl p-4">
                <p className="text-sm font-semibold">Imágenes guardadas por {retentionDays} días</p>
                <p className="text-muted-foreground text-sm">
                    Pasado ese plazo se borran solas. Las de incidentes abiertos o confirmados como intrusión se conservan como evidencia.
                </p>
            </div>

            <div className="flex items-center gap-3">
                <Button variant={alarmActive ? 'secondary' : 'default'} disabled={form.processing || !form.isDirty}>
                    Guardar ajustes
                </Button>
                {form.recentlySuccessful && <span className="text-ok text-sm">Guardado</span>}
            </div>
        </form>
    );
}

/* ---------- Autonomía por zona ---------- */

const AUTONOMY: Record<Autonomy, { label: string; help: string }> = {
    calibration: { label: 'Calibración', help: 'La IA analiza y registra, pero no avisa a nadie. Para la primera semana de una zona nueva.' },
    assisted: { label: 'Asistida', help: 'La IA descarta lo obvio y alerta a la guardia, que decide qué hacer.' },
    deterrence: {
        label: 'Disuasión automática',
        help: 'Además, ante una amenaza clara, activa enseguida el altavoz o la sirena de la cámara, sin esperar a la guardia.',
    },
};

function AutonomyControl({ zone, canManage }: { zone: Zone; canManage: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ autonomy: zone.autonomy });
    const eligibility = zone.autonomy_eligibility;

    const badge = (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-xs font-semibold',
                zone.autonomy === 'calibration' && 'bg-pending/15 text-pending',
                zone.autonomy === 'assisted' && 'bg-muted text-muted-foreground',
                zone.autonomy === 'deterrence' && 'bg-alarm/15 text-alarm',
            )}
        >
            {AUTONOMY[zone.autonomy].label}
        </span>
    );

    if (!canManage) return badge;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <button type="button" className="inline-flex items-center gap-1" aria-label={`Cambiar el nivel de ${zone.name}`}>
                    {badge}
                    <span className="text-muted-foreground text-xs underline underline-offset-2">Cambiar</span>
                </button>
            </DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(`/zones/${zone.id}/autonomy`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                    className="flex flex-col gap-4"
                >
                    <DialogHeader>
                        <DialogTitle>Qué hace sola la IA en {zone.name}</DialogTitle>
                        <DialogDescription>
                            Los niveles se ganan con datos: la disuasión automática pide un historial de alertas confiables.
                        </DialogDescription>
                    </DialogHeader>
                    <fieldset className="grid gap-2">
                        {(Object.keys(AUTONOMY) as Autonomy[]).map((level) => {
                            const locked = level === 'deterrence' && zone.autonomy !== 'deterrence' && !eligibility?.eligible;
                            return (
                                <label
                                    key={level}
                                    className={cn(
                                        'has-[:checked]:border-foreground has-[:checked]:bg-muted/60 flex gap-3 rounded-xl border p-3',
                                        locked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name={`autonomy-${zone.id}`}
                                        checked={form.data.autonomy === level}
                                        disabled={locked}
                                        onChange={() => form.setData('autonomy', level)}
                                        className="accent-foreground mt-1"
                                    />
                                    <span>
                                        <span className="block text-sm font-semibold">{AUTONOMY[level].label}</span>
                                        <span className="text-muted-foreground block text-sm">{AUTONOMY[level].help}</span>
                                        {level === 'deterrence' && eligibility && (
                                            <span className={cn('mt-1 block text-sm', eligibility.eligible ? 'text-ok' : 'text-pending')}>
                                                {eligibility.eligible ? 'La zona la ganó: ' : 'Todavía no: '}
                                                {eligibility.reason}
                                            </span>
                                        )}
                                    </span>
                                </label>
                            );
                        })}
                    </fieldset>
                    <InputError message={form.errors.autonomy} />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing || !form.isDirty}>
                            Guardar nivel
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ---------- Equipos edge ---------- */

function EdgesSection({ site, newEdge, serverUrl }: Pick<Props, 'site' | 'newEdge'> & { serverUrl: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/sites/${site.id}/edges`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <section className="flex max-w-2xl flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <SectionTitle icon={Cpu}>Equipos edge</SectionTitle>
                    <p className="text-muted-foreground mt-1 text-sm">
                        El equipo instalado en el lugar que analiza las cámaras y envía las detecciones.
                    </p>
                </div>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button variant="secondary">Agregar equipo edge</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <DialogHeader>
                                <DialogTitle>Agregar equipo edge</DialogTitle>
                                <DialogDescription>Te mostramos una clave de conexión una sola vez para cargarla en el equipo.</DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-1.5">
                                <Label htmlFor="edge-name">Nombre</Label>
                                <Input
                                    id="edge-name"
                                    placeholder="Garita principal"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    autoFocus
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="ghost">
                                        Cancelar
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={form.processing}>
                                    Crear y ver la clave
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            {newEdge && (
                <div className="border-foreground/20 bg-muted/60 grid gap-3 rounded-2xl border p-4">
                    <div>
                        <p className="font-semibold">Clave de conexión de {newEdge.name}</p>
                        <p className="text-muted-foreground text-sm">
                            Copiala ahora en el archivo <code>.env</code> del equipo: no la vamos a volver a mostrar. Si se pierde, quitá el equipo y
                            creá uno nuevo.
                        </p>
                    </div>
                    <pre className="bg-background overflow-x-auto rounded-lg p-3 font-mono text-xs select-all">
                        {`SEGURIA_API_URL=${serverUrl}\nSEGURIA_TOKEN=${newEdge.token}`}
                    </pre>
                </div>
            )}

            {site.edges.length === 0 ? (
                <p className="text-muted-foreground text-sm">Todavía no hay equipos. Sin un equipo edge, las cámaras de este lugar no se analizan.</p>
            ) : (
                <ul className="bg-card divide-y rounded-2xl border">
                    {site.edges.map((edge) => (
                        <li key={edge.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                            <span className={cn('size-2.5 rounded-full', edge.online ? 'bg-ok' : 'bg-alarm')} />
                            <div className="min-w-40 flex-1">
                                <p className="font-semibold">{edge.name}</p>
                                <p className="text-muted-foreground text-sm">
                                    {edge.last_seen_at
                                        ? edge.online
                                            ? 'En línea'
                                            : `Sin señal desde ${timeAgo(edge.last_seen_at)}`
                                        : 'Esperando la primera conexión'}
                                    {edge.version && `. Versión ${edge.version}`}
                                </p>
                            </div>
                            <RemoveEdge siteId={site.id} edge={edge} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function RemoveEdge({ siteId, edge }: { siteId: number; edge: EdgeStatus }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="delete" aria-label={`Quitar ${edge.name}`}>
                    Quitar
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Quitar {edge.name}?</DialogTitle>
                    <DialogDescription>
                        Su clave deja de funcionar y las cámaras que analiza dejan de enviar detecciones hasta que conectes otro equipo.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/sites/${siteId}/edges/${edge.id}`, { preserveScroll: true })}>
                        Quitar equipo
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ---------- Page ---------- */

export default function SiteShow({
    tab,
    canManage,
    canManageEdges,
    newEdge,
    edgeServerUrl,
    aiUsage,
    site,
    residents,
    residentsCount,
    sectors,
    metrics,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Edificios y countries', href: '/sites' },
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
                    <SiteMap
                        siteId={site.id}
                        perimeter={site.perimeter}
                        center={site.center}
                        cameras={site.cameras}
                        canManage={canManage}
                        variant="coverage"
                    />
                )}
                {tab === 'camaras' && <CamerasTab site={site} canManage={canManage} />}
                {tab === 'vecinos' && residents && <ResidentsTab site={site} residents={residents} sectors={sectors} canManage={canManage} />}
                {tab === 'ajustes' && canManage && (
                    <div className="flex flex-col gap-10">
                        <SettingsTab site={site} aiUsage={aiUsage} />
                        {canManageEdges && <EdgesSection site={site} newEdge={newEdge} serverUrl={edgeServerUrl} />}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
