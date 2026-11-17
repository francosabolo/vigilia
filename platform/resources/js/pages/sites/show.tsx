import { AddressFields } from '@/components/address-fields';
import { LocationPicker } from '@/components/clients-map';
import { PatrolCard, type PatrolRound } from '@/components/console/patrol-card';
import { ResidentsPanel, type SentMessage } from '@/components/console/residents-panel';
import { EmergencyContacts, type EmergencyContact } from '@/components/emergency-contacts';
import { SectionTitle } from '@/components/icon-circle';
import InputError from '@/components/input-error';
import { ItemActionButton, ItemActionLink, ItemActions } from '@/components/item-action';
import { ResidentVehicles, type Vehicle } from '@/components/resident-vehicles';
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
    formatDateTime,
    formatDuration,
    hasHousesOnMap,
    hasUnits,
    plural,
    siteKinds,
    theSite,
    timeAgo,
    unitNoun,
    zoneKindLabel,
    type EdgeStatus,
    type Metrics,
    type SiteKind,
    type ZoneKind,
} from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { BarChart3, Building2, Cctv, Cpu, Eye, ShieldCheck, Siren, VideoOff } from 'lucide-react';
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
    is_guard_post: boolean;
    is_checkpoint: boolean;
    is_access: boolean;
    is_ptz: boolean;
    watch_motion: boolean;
    fire_watch: boolean;
    siren: { brand: string; host: string; user: string } | null;
    ai_notes: string | null;
    speaker_test: { status: string; result: string | null; at: string } | null;
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
    home: LatLng | null;
    contacts: EmergencyContact[];
    vehicles: Vehicle[];
}

interface Props {
    tab: Tab;
    canManage: boolean;
    isAdmin: boolean;
    clients: { id: number; name: string }[];
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
        city: string | null;
        province: string | null;
        full_address: string;
        client_id: number | null;
        perimeter: LatLng[];
        center: LatLng | null;
        guard_whatsapp: string | null;
        ack_timeout_seconds: number;
        patrol_interval_minutes: number | null;
        guard_post_max_absence_minutes: number | null;
        round_interval_minutes: number | null;
        guard_monitoring_consent_at: string | null;
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
    patrols: PatrolRound[];
    patrolIntervals: number[];
    mediaViews: MediaViewRow[] | null;
    residentMessages: SentMessage[];
    setup: { done: number; total: number } | null;
    testModeUntil: string | null;
}

interface MediaViewRow {
    id: number;
    who: string;
    kind: string;
    subject: string;
    at: string;
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
                        {siteKinds.find((kind) => kind.id === site.kind)?.label}
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
    kind,
}: {
    siteId: number;
    tab: Tab;
    canManage: boolean;
    residentsCount: number;
    camerasCount: number;
    kind: SiteKind;
}) {
    const tabs: { id: Tab; label: string; count?: number }[] = [
        { id: 'resumen', label: 'Resumen' },
        { id: 'cobertura', label: 'Cobertura' },
        { id: 'camaras', label: 'Cámaras y zonas', count: camerasCount },
        // A house, a shop or a warehouse has no neighbours: the people who get the notices.
        { id: 'vecinos', label: hasUnits(kind) ? 'Vecinos' : 'Personas', count: residentsCount },
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
                <code className="bg-surface-2 rounded px-1">php artisan vigilia:edge-create {siteId} edge-1</code> y cargá el token en el equipo.
            </p>
        </div>
    );
}

function Summary({ site, metrics, canManage, patrols }: Pick<Props, 'site' | 'metrics' | 'canManage' | 'patrols'>) {
    usePoll(10000, { only: ['site', 'patrols'] }, { keepAlive: true });

    return (
        <div className="flex flex-col gap-8">
            <SiteMap
                siteId={site.id}
                perimeter={site.perimeter}
                center={site.center}
                cameras={site.cameras}
                canManage={canManage}
                address={site.full_address}
            />

            {site.cameras.length === 0 && <NoCameras siteId={site.id} />}

            {site.cameras.length > 0 && (
                <PatrolCard
                    siteId={site.id}
                    patrols={patrols}
                    intervalMinutes={site.patrol_interval_minutes}
                    canManage={canManage}
                    closingCheck={site.kind === 'store' || site.kind === 'warehouse'}
                />
            )}

            <div className="grid gap-8 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <section>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <SectionTitle icon={BarChart3}>Últimos 7 días</SectionTitle>
                        <ItemActionLink kind="view" href={`/sites/${site.id}/report`}>
                            Informe del mes
                        </ItemActionLink>
                    </div>
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
                    {canManage && <CameraRoles camera={camera} />}
                    <ItemActions>
                        <ItemActionLink kind="view" href={`/cameras/${camera.id}`}>
                            Ver en vivo
                        </ItemActionLink>
                        {canManage && (
                            <ItemActionLink kind="edit" href={`/cameras/${camera.id}/zones`}>
                                Editar zonas
                            </ItemActionLink>
                        )}
                        {canManage && <RemoveCamera camera={camera} />}
                    </ItemActions>
                </div>
            ))}
        </div>
    );
}

function SiteDetailsForm({ site, clients, isAdmin }: Pick<Props, 'site' | 'clients' | 'isAdmin'>) {
    const form = useForm({
        name: site.name,
        address: site.address ?? '',
        city: site.city ?? '',
        province: site.province ?? '',
        kind: site.kind,
        client_id: site.client_id ? String(site.client_id) : '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/sites/${site.id}/details`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex max-w-3xl flex-col gap-4">
            <SectionTitle icon={Building2}>Datos del lugar</SectionTitle>
            <div className="grid gap-1.5">
                <Label htmlFor="site-name">Nombre</Label>
                <Input id="site-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                <InputError message={form.errors.name} />
            </div>
            <AddressFields
                prefix="site-details"
                value={form.data}
                onChange={(field, value) => form.setData(field, value)}
                errors={form.errors}
                required
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="site-kind">Tipo</Label>
                    <select
                        id="site-kind"
                        value={form.data.kind}
                        onChange={(e) => form.setData('kind', e.target.value as SiteKind)}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        {siteKinds.map((kind) => (
                            <option key={kind.id} value={kind.id}>
                                {kind.label}
                            </option>
                        ))}
                    </select>
                </div>
                {isAdmin && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="site-client">Cliente</Label>
                        <select
                            id="site-client"
                            value={form.data.client_id}
                            onChange={(e) => form.setData('client_id', e.target.value)}
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="">Sin cliente</option>
                            {clients.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.client_id} />
                    </div>
                )}
            </div>
            <div>
                <Button type="submit" disabled={form.processing || !form.isDirty}>
                    Guardar datos
                </Button>
            </div>
        </form>
    );
}

function ArchiveSite({ site }: { site: Props['site'] }) {
    const form = useForm({ confirm: '' });

    return (
        <section className="border-alarm/40 flex max-w-xl flex-col gap-3 rounded-2xl border p-4">
            <h2 className="font-semibold">Dar de baja {theSite(site.kind)}</h2>
            <p className="text-muted-foreground text-sm">
                Para cuando el lugar deja el servicio. Se borran sus imágenes, los vecinos pierden la cuenta, los equipos edge dejan de conectarse y
                deja de verse en la consola. El historial de incidentes queda guardado.
            </p>
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="outline" className="border-alarm/60 text-alarm hover:bg-alarm/10 hover:text-alarm self-start">
                        Dar de baja
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.delete(`/sites/${site.id}`);
                        }}
                        className="flex flex-col gap-4"
                    >
                        <DialogHeader>
                            <DialogTitle>¿Dar de baja {site.name}?</DialogTitle>
                            <DialogDescription>No se puede deshacer: las imágenes se borran y los vecinos pierden el acceso.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-1.5">
                            <Label htmlFor="archive-confirm">Escribí «{site.name}» para confirmar</Label>
                            <Input
                                id="archive-confirm"
                                value={form.data.confirm}
                                onChange={(e) => form.setData('confirm', e.target.value)}
                                autoFocus
                            />
                            <InputError message={form.errors.confirm} />
                        </div>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="ghost">
                                    Cancelar
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant="destructive" disabled={form.processing || form.data.confirm !== site.name}>
                                Dar de baja
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function MediaViewsSection({ views }: { views: MediaViewRow[] }) {
    return (
        <section className="flex max-w-3xl flex-col gap-3">
            <SectionTitle icon={Eye}>Quién vio imágenes</SectionTitle>
            <p className="text-muted-foreground text-sm">
                Las imágenes de personas son datos personales: cada vez que alguien abre una foto, un clip o una cámara en vivo queda registrado acá.
            </p>
            {views.length === 0 ? (
                <p className="text-muted-foreground text-sm">Nadie abrió imágenes de este lugar todavía.</p>
            ) : (
                <ul className="bg-card divide-y rounded-xl border text-sm">
                    {views.map((view) => (
                        <li key={view.id} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-4 py-2.5">
                            <span>
                                <span className="font-semibold">{view.who}</span> <span className="text-muted-foreground">abrió</span> {view.subject}
                                <span className="text-muted-foreground"> ({view.kind.toLowerCase()})</span>
                            </span>
                            <time className="text-muted-foreground" dateTime={view.at}>
                                {formatDateTime(view.at)}
                            </time>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function CameraNotes({ camera }: { camera: Camera }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ ai_notes: camera.ai_notes ?? '' });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <button type="button" className="text-muted-foreground hover:text-foreground text-left text-sm underline underline-offset-2">
                    {camera.ai_notes ? 'Notas para la IA' : 'Agregar notas para la IA'}
                </button>
            </DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(`/cameras/${camera.id}/notes`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                    className="flex flex-col gap-4"
                >
                    <DialogHeader>
                        <DialogTitle>Lo que la IA tiene que saber de {camera.name}</DialogTitle>
                        <DialogDescription>
                            Lo lee en cada análisis de esta cámara. También se suma lo que las guardias anotan al cerrar una falsa alarma.
                        </DialogDescription>
                    </DialogHeader>
                    <textarea
                        aria-label="Notas para la IA"
                        rows={6}
                        maxLength={1200}
                        placeholder="Ej: el perro del lote 12 pasa todas las noches. El jardinero viene los martes de 8 a 12."
                        value={form.data.ai_notes}
                        onChange={(e) => form.setData('ai_notes', e.target.value)}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing || !form.isDirty}>
                            Guardar notas
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const SIREN_BRANDS = [
    ['none', 'Sin sirena'],
    ['hikvision', 'Hikvision (salida de alarma)'],
    ['dahua', 'Dahua (sirena)'],
    ['reolink', 'Reolink (sirena)'],
] as const;

/**
 * K11 — the camera's own siren, set up from the console. The password is never shown back.
 */
function SirenSetup({ camera }: { camera: Camera }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ brand: camera.siren?.brand ?? 'none', host: camera.siren?.host ?? '', user: camera.siren?.user ?? 'admin', password: '' });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <button type="button" className="text-muted-foreground hover:text-foreground text-left text-sm underline underline-offset-2">
                    {camera.siren ? `Sirena: ${camera.siren.brand}` : 'Configurar sirena'}
                </button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/cameras/${camera.id}/siren`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Sirena de {camera.name}</DialogTitle>
                        <DialogDescription>
                            La disuasión y «Probar altavoz» la hacen sonar a través del equipo del lugar. Para un mensaje hablado hace falta
                            configurar el altavoz en el equipo.
                        </DialogDescription>
                    </DialogHeader>
                    <select
                        aria-label="Marca"
                        value={form.data.brand}
                        onChange={(e) => form.setData('brand', e.target.value)}
                        className="border-input bg-background h-9 rounded-md border px-2"
                    >
                        {SIREN_BRANDS.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    {form.data.brand !== 'none' && (
                        <div className="grid gap-2 sm:grid-cols-3">
                            <Input
                                aria-label="IP de la cámara"
                                placeholder="192.168.1.64"
                                value={form.data.host}
                                onChange={(e) => form.setData('host', e.target.value)}
                            />
                            <Input
                                aria-label="Usuario"
                                placeholder="Usuario"
                                value={form.data.user}
                                onChange={(e) => form.setData('user', e.target.value)}
                            />
                            <Input
                                aria-label="Contraseña"
                                type="password"
                                autoComplete="off"
                                placeholder={camera.siren ? 'Sin cambios' : 'Contraseña'}
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                        </div>
                    )}
                    <InputError message={form.errors.host ?? form.errors.user} />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Guardar sirena
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CameraRoles({ camera }: { camera: Camera }) {
    const toggle = (role: 'is_guard_post' | 'is_checkpoint' | 'is_access' | 'is_ptz' | 'watch_motion' | 'fire_watch') =>
        router.patch(
            `/cameras/${camera.id}/roles`,
            {
                is_guard_post: camera.is_guard_post,
                is_checkpoint: camera.is_checkpoint,
                is_access: camera.is_access,
                is_ptz: camera.is_ptz,
                watch_motion: camera.watch_motion,
                fire_watch: camera.fire_watch,
                [role]: !camera[role],
            },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-col gap-1.5 text-sm sm:w-40">
            {(
                [
                    ['is_access', 'Cámara del acceso'],
                    ['is_guard_post', 'Es la garita'],
                    ['is_checkpoint', 'Punto de ronda'],
                    ['is_ptz', 'Se mueve sola (PTZ)'],
                    ['watch_motion', 'Avisar movimiento aunque no vea a nadie'],
                    ['fire_watch', 'Vigilar humo y fuego'],
                ] as const
            ).map(([role, label]) => (
                <label key={role} className="flex items-center gap-2">
                    <input type="checkbox" checked={camera[role]} onChange={() => toggle(role)} className="accent-foreground size-4" />
                    {label}
                </label>
            ))}
            <CameraNotes camera={camera} />
            <SirenSetup camera={camera} />
            <button
                type="button"
                className="text-muted-foreground hover:text-foreground text-left text-sm underline underline-offset-2"
                onClick={() => router.post(`/cameras/${camera.id}/speaker-test`, {}, { preserveScroll: true })}
            >
                Probar altavoz
            </button>
            {camera.speaker_test && (
                <span
                    className={cn(
                        'text-xs',
                        camera.speaker_test.status === 'done'
                            ? 'text-ok'
                            : camera.speaker_test.status === 'failed'
                              ? 'text-alarm'
                              : 'text-muted-foreground',
                    )}
                >
                    {camera.speaker_test.status === 'done'
                        ? 'El altavoz funcionó'
                        : camera.speaker_test.status === 'failed'
                          ? (camera.speaker_test.result ?? 'El altavoz no respondió')
                          : 'Prueba enviada, esperando al equipo…'}
                </span>
            )}
        </div>
    );
}

function GuardSupervisionSection({ site }: { site: Props['site'] }) {
    const form = useForm({
        guard_post_max_absence_minutes: site.guard_post_max_absence_minutes ?? ('' as number | ''),
        round_interval_minutes: site.round_interval_minutes ?? ('' as number | ''),
        consent: false as boolean,
    });
    const posts = site.cameras.filter((camera) => camera.is_guard_post).length;
    const checkpoints = site.cameras.filter((camera) => camera.is_checkpoint).length;
    const enabling = form.data.guard_post_max_absence_minutes !== '' || form.data.round_interval_minutes !== '';

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    guard_post_max_absence_minutes: data.guard_post_max_absence_minutes === '' ? null : data.guard_post_max_absence_minutes,
                    round_interval_minutes: data.round_interval_minutes === '' ? null : data.round_interval_minutes,
                }));
                form.patch(`/sites/${site.id}/guard-supervision`, { preserveScroll: true });
            }}
            className="flex max-w-xl flex-col gap-4"
        >
            <SectionTitle icon={ShieldCheck}>Control de guardias</SectionTitle>
            <p className="text-muted-foreground -mt-2 text-sm">
                La IA confirma que la garita tiene a alguien y que las rondas pasan por cada punto. Avisa a la administración y a la central, nunca al
                guardia. Marcá la garita y los puntos de ronda en la pestaña Cámaras ({posts} garita, {checkpoints} puntos de ronda).
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="post-absence">Avisar si la garita queda vacía más de</Label>
                    <select
                        id="post-absence"
                        value={form.data.guard_post_max_absence_minutes}
                        onChange={(e) => form.setData('guard_post_max_absence_minutes', e.target.value === '' ? '' : Number(e.target.value))}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">No controlar</option>
                        {[10, 20, 30, 60].map((minutes) => (
                            <option key={minutes} value={minutes}>
                                {minutes} minutos
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="round-interval">Ronda física cada</Label>
                    <select
                        id="round-interval"
                        value={form.data.round_interval_minutes}
                        onChange={(e) => form.setData('round_interval_minutes', e.target.value === '' ? '' : Number(e.target.value))}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">No controlar</option>
                        {[30, 60, 120, 240].map((minutes) => (
                            <option key={minutes} value={minutes}>
                                {minutes < 60 ? `${minutes} minutos` : minutes === 60 ? '1 hora' : `${minutes / 60} horas`}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            {enabling && !site.guard_monitoring_consent_at && (
                <label className="flex items-start gap-3 rounded-xl border p-3 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.consent}
                        onChange={(e) => form.setData('consent', e.target.checked)}
                        className="accent-foreground mt-0.5 size-4"
                    />
                    <span>
                        Los guardias fueron informados por escrito de que las cámaras controlan su presencia y sus rondas.
                        <InputError message={form.errors.consent} />
                    </span>
                </label>
            )}
            {site.guard_monitoring_consent_at && (
                <p className="text-muted-foreground text-sm">
                    Consentimiento confirmado el {new Date(site.guard_monitoring_consent_at).toLocaleDateString('es-AR')}.
                </p>
            )}
            <div>
                <Button type="submit" disabled={form.processing || !form.isDirty}>
                    Guardar control de guardias
                </Button>
            </div>
        </form>
    );
}

function RemoveCamera({ camera }: { camera: Camera }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="delete" aria-label={`Quitar la cámara ${camera.name}`}>
                    Quitar
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Quitar la cámara {camera.name}?</DialogTitle>
                    <DialogDescription>
                        Se deja de analizar y desaparece del mapa y de la ronda. Sus incidentes y detecciones se conservan como evidencia. Sacala
                        también de la configuración del equipo edge para que deje de grabar.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/cameras/${camera.id}`, { preserveScroll: true })}>
                        Quitar cámara
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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

function EditResident({
    siteId,
    resident,
    sectors,
    kind,
    center,
    trigger,
}: {
    siteId: number;
    resident: Resident;
    sectors: string[];
    kind: SiteKind;
    center?: LatLng | null;
    trigger?: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: resident.name,
        lot: resident.lot ?? '',
        sector: resident.sector ?? '',
        phone: resident.phone ?? '',
        home_lat: resident.home?.[0] ?? (null as number | null),
        home_lng: resident.home?.[1] ?? (null as number | null),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/sites/${siteId}/residents/${resident.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger ?? <ItemActionButton kind="edit" aria-label={`Editar a ${resident.name}`} />}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
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
                    {hasHousesOnMap(kind) && open && (
                        <div className="grid gap-1.5">
                            <Label>Casa en el mapa</Label>
                            <LocationPicker
                                value={form.data.home_lat !== null && form.data.home_lng !== null ? [form.data.home_lat, form.data.home_lng] : null}
                                fallback={center ?? null}
                                onChange={(point) =>
                                    form.setData((data) => ({ ...data, home_lat: point?.[0] ?? null, home_lng: point?.[1] ?? null }))
                                }
                            />
                            <InputError message={form.errors.home_lat} />
                        </div>
                    )}
                    <div className="grid gap-1.5">
                        <Label>Vehículos</Label>
                        <ResidentVehicles
                            vehicles={resident.vehicles}
                            addAction={`/sites/${siteId}/residents/${resident.id}/vehicles`}
                            removeAction={(vehicle) => `/sites/${siteId}/vehicles/${vehicle.id}`}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Contactos de emergencia</Label>
                        <EmergencyContacts
                            contacts={resident.contacts}
                            addAction={`/sites/${siteId}/residents/${resident.id}/contacts`}
                            removeAction={(contact) => `/sites/${siteId}/contacts/${contact.id}`}
                        />
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

function ResidentsTab({
    site,
    residents,
    sectors,
    canManage,
    messages,
}: Pick<Props, 'site' | 'sectors' | 'canManage'> & { residents: Resident[]; messages: SentMessage[] }) {
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
                <ResidentsPanel
                    siteId={site.id}
                    kind={site.kind}
                    perimeter={site.perimeter}
                    center={site.center}
                    residents={residents}
                    sectors={sectors}
                    messages={messages}
                    canManage={canManage}
                    renderUsage={(resident) => <AppUsage resident={resident as Resident} />}
                    renderActions={(resident) =>
                        canManage && (
                            <>
                                <EditResident
                                    siteId={site.id}
                                    resident={resident as Resident}
                                    sectors={sectors}
                                    kind={site.kind}
                                    center={site.center}
                                />
                                <RemoveResident siteId={site.id} resident={resident as Resident} />
                            </>
                        )
                    }
                />
            )}
        </div>
    );
}

/* ---------- Ajustes ---------- */

function SettingsTab({ site, aiUsage, patrolIntervals }: Pick<Props, 'site' | 'aiUsage' | 'patrolIntervals'>) {
    const alarmActive = !!site.escalated_incident_id;
    const { retentionDays } = usePage<{ retentionDays: number }>().props;
    const form = useForm({
        guard_whatsapp: site.guard_whatsapp ?? '',
        ack_timeout_seconds: Math.round(site.ack_timeout_seconds / 60) * 60,
        patrol_interval_minutes: site.patrol_interval_minutes ?? ('' as number | ''),
    });

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

            <div className="grid gap-1.5">
                <Label htmlFor="patrol_interval_minutes">Ronda virtual automática</Label>
                <select
                    id="patrol_interval_minutes"
                    value={form.data.patrol_interval_minutes}
                    onChange={(e) => form.setData('patrol_interval_minutes', e.target.value === '' ? '' : Number(e.target.value))}
                    className="border-input bg-background h-9 max-w-48 rounded-md border px-3 text-sm"
                >
                    <option value="">Apagada</option>
                    {patrolIntervals.map((minutes) => (
                        <option key={minutes} value={minutes}>
                            Cada {minutes < 60 ? `${minutes} minutos` : minutes === 60 ? 'hora' : `${minutes / 60} horas`}
                        </option>
                    ))}
                </select>
                <p className="text-muted-foreground text-sm">
                    La IA revisa cada cámara en cada ronda: {site.cameras.length} cámaras cada hora suman {site.cameras.length * 24} revisiones por
                    día del tope diario de IA.
                </p>
                <InputError message={form.errors.patrol_interval_minutes} />
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
                        {`VIGILIA_API_URL=${serverUrl}\nVIGILIA_TOKEN=${newEdge.token}`}
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
    isAdmin,
    clients,
    canManageEdges,
    newEdge,
    edgeServerUrl,
    aiUsage,
    site,
    residents,
    residentsCount,
    sectors,
    metrics,
    patrols,
    patrolIntervals,
    mediaViews,
    residentMessages,
    setup,
    testModeUntil,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Propiedades', href: '/sites' },
        { title: site.name, href: `/sites/${site.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={site.name} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <WatchHeader site={site} />
                {testModeUntil && new Date(testModeUntil) > new Date() && (
                    <Link
                        href={`/sites/${site.id}/setup#prueba`}
                        className="border-pending/50 bg-pending/10 rounded-lg border px-4 py-2 text-sm font-medium"
                    >
                        Prueba de instalación en curso: las detecciones no avisan a la guardia. Ver la prueba
                    </Link>
                )}
                {setup && setup.done < setup.total && (
                    <Link
                        href={`/sites/${site.id}/setup`}
                        className="bg-card hover:bg-accent flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-2.5 text-sm"
                    >
                        <span>
                            <span className="font-semibold">Instalación sin terminar:</span> {setup.done} de {setup.total} pasos hechos.
                        </span>
                        <span className="font-medium underline underline-offset-2">Seguir con la instalación</span>
                    </Link>
                )}
                <Tabs
                    siteId={site.id}
                    tab={tab}
                    canManage={canManage}
                    residentsCount={residentsCount}
                    camerasCount={site.cameras.length}
                    kind={site.kind}
                />

                {tab === 'resumen' && <Summary site={site} metrics={metrics} canManage={canManage} patrols={patrols} />}
                {tab === 'cobertura' && (
                    <SiteMap
                        siteId={site.id}
                        perimeter={site.perimeter}
                        center={site.center}
                        cameras={site.cameras}
                        canManage={canManage}
                        variant="coverage"
                        address={site.full_address}
                    />
                )}
                {tab === 'camaras' && <CamerasTab site={site} canManage={canManage} />}
                {tab === 'vecinos' && residents && (
                    <ResidentsTab site={site} residents={residents} sectors={sectors} canManage={canManage} messages={residentMessages} />
                )}
                {tab === 'ajustes' && canManage && (
                    <div className="flex flex-col gap-10">
                        <ItemActionLink kind="view" href={`/sites/${site.id}/setup`} className="-ml-3 self-start">
                            Instalación y prueba de cámaras
                        </ItemActionLink>
                        <SiteDetailsForm site={site} clients={clients} isAdmin={isAdmin} />
                        <SettingsTab site={site} aiUsage={aiUsage} patrolIntervals={patrolIntervals} />
                        <GuardSupervisionSection site={site} />
                        {canManageEdges && <EdgesSection site={site} newEdge={newEdge} serverUrl={edgeServerUrl} />}
                        {mediaViews && <MediaViewsSection views={mediaViews} />}
                        {isAdmin && <ArchiveSite site={site} />}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
