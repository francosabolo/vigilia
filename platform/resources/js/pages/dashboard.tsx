import { AiActivity } from '@/components/console/ai-activity';
import { IncidentRow } from '@/components/console/incident-row';
import { ShiftHandoverCard, type Handover } from '@/components/console/shift-handover';
import { SectionTitle } from '@/components/icon-circle';
import { ItemActionHint } from '@/components/item-action';
import { PushToggle } from '@/components/push-toggle';
import { ArrivalsPanel, type Arrival } from '@/components/safe-arrival';
import { Button } from '@/components/ui/button';
import { useLiveIncidents } from '@/hooks/use-live-incidents';
import AppLayout from '@/layouts/app-layout';
import { formatDuration, plural, type ActivityItem, type IncidentStatus, type Metrics, type Severity } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { Building2, Cctv, Cpu, Filter, Siren } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Panel', href: '/dashboard' }];

interface OpenIncident {
    id: number;
    title: string;
    status: IncidentStatus;
    severity: Severity;
    site: string;
    detected_at: string;
}

interface SiteStatus {
    id: number;
    name: string;
    cameras: number;
    cameras_online: number;
    open_incidents: number;
    edges_offline: number;
}

interface Props {
    metrics: Metrics;
    openIncidents: OpenIncident[];
    openCount: number;
    escalatedCount: number;
    edgesOffline: number;
    sites: SiteStatus[];
    activity: ActivityItem[];
    vapidPublicKey: string | null;
    channel: string;
    lastHandover: Handover | null;
    arrivals: Arrival[];
    handoverSiteId: number | null;
}

/**
 * Filtered to one property, the live alerts still cover every property: say what is going on in the rest so a sound
 * is never about something this screen does not show.
 */
function ElsewhereNotice() {
    const context = usePage<{ siteContext: { current: { name: string } | null; alerts: { elsewhere?: number } } | null }>().props.siteContext;
    const elsewhere = context?.alerts.elsewhere ?? 0;
    if (!context?.current || elsewhere === 0) return null;

    return (
        <div className="bg-card flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-5 py-4 text-[15px]">
            <span>
                Estás mirando solo {context.current.name}. Hay {elsewhere === 1 ? '1 incidente abierto' : `${elsewhere} incidentes abiertos`} en otras
                propiedades.
            </span>
            <Button
                variant="outline"
                className="rounded-full"
                onClick={() => router.put('/current-site', { site_id: null }, { preserveScroll: true })}
            >
                Ver todas las propiedades
            </Button>
        </div>
    );
}

function WatchStatus({
    openIncidents,
    openCount,
    escalatedCount,
    edgesOffline,
}: Pick<Props, 'openIncidents' | 'openCount' | 'escalatedCount' | 'edgesOffline'>) {
    const first = openIncidents[0];
    const currentSite = usePage<{ siteContext: { current: { name: string } | null } | null }>().props.siteContext?.current?.name;

    if (escalatedCount > 0 && first) {
        return (
            <section className="border-alarm bg-alarm/10 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border-2 p-5">
                <span className="bg-alarm size-4 shrink-0 animate-pulse rounded-full" aria-hidden />
                <div className="min-w-0 flex-1">
                    <h1 className="font-display text-alarm text-3xl leading-tight font-semibold">
                        {escalatedCount === 1
                            ? 'Un incidente sin respuesta de la guardia'
                            : `${escalatedCount} incidentes sin respuesta de la guardia`}
                    </h1>
                    <p className="mt-1 text-[15px]">
                        {first.title} en {first.site}
                    </p>
                </div>
                <Button asChild size="lg" variant="destructive" className="text-base">
                    <Link href={`/incidents/${first.id}`}>Atender ahora</Link>
                </Button>
            </section>
        );
    }

    if (openCount > 0) {
        return (
            <section className="border-pending/60 bg-pending/10 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border p-5">
                <span className="bg-pending size-4 shrink-0 rounded-full" aria-hidden />
                <div className="flex-1">
                    <h1 className="font-display text-3xl leading-tight font-semibold">
                        {openCount === 1 ? 'Un incidente en curso' : `${openCount} incidentes en curso`}
                    </h1>
                    <p className="text-muted-foreground mt-1 text-[15px]">La guardia ya fue avisada. Seguí el avance en la consola.</p>
                </div>
                <Link href="/incidents" className="text-[15px] font-medium underline underline-offset-4">
                    Ir a incidentes
                </Link>
            </section>
        );
    }

    return (
        <section className="border-ok/40 bg-ok/10 flex items-center gap-6 rounded-xl border p-5">
            <span className="bg-ok size-4 shrink-0 rounded-full" aria-hidden />
            <div>
                <h1 className="font-display text-3xl leading-tight font-semibold">Todo en orden{currentSite ? ` en ${currentSite}` : ''}</h1>
                <p className="text-muted-foreground mt-1 text-[15px]">
                    {edgesOffline > 0
                        ? `Atención: ${plural(edgesOffline, 'equipo edge', 'equipos edge')} sin señal.`
                        : 'No hay incidentes abiertos y todos los equipos están conectados.'}
                </p>
            </div>
        </section>
    );
}

function Funnel({ metrics }: { metrics: Metrics }) {
    const steps = [
        { value: metrics.detections, label: 'movimientos detectados en el edge' },
        { value: metrics.filtered_by_ai + metrics.outside_schedule, label: 'descartados sin molestar a nadie' },
        { value: metrics.incidents, label: 'llegaron a la guardia' },
        { value: metrics.confirmed, label: 'intrusiones confirmadas' },
    ];

    return (
        <section className="bg-card rounded-xl border p-5">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <SectionTitle icon={Filter}>Qué filtró la IA esta semana</SectionTitle>
                <span className="text-muted-foreground text-sm">
                    Respuesta promedio de la guardia: <span className="text-foreground font-semibold">{formatDuration(metrics.avg_ack_seconds)}</span>
                </span>
            </div>
            <ol className="mt-4 grid grid-cols-2 gap-y-4 md:grid-cols-4">
                {steps.map((step, index) => (
                    <li key={step.label} className={cn('pr-4', index > 0 && 'md:border-l md:pl-5')}>
                        <div
                            className={cn(
                                'font-display text-5xl leading-none font-semibold tabular-nums',
                                index === 3 && step.value > 0 && 'text-alarm',
                            )}
                        >
                            {step.value}
                        </div>
                        <div className="text-muted-foreground mt-2 text-sm">{step.label}</div>
                    </li>
                ))}
            </ol>
        </section>
    );
}

export default function Dashboard({
    metrics,
    openIncidents,
    openCount,
    escalatedCount,
    edgesOffline,
    sites,
    activity,
    vapidPublicKey,
    channel,
    lastHandover,
    handoverSiteId,
    arrivals,
}: Props) {
    usePoll(arrivals.length > 0 ? 10000 : 30000, { only: ['arrivals'] });
    useLiveIncidents(channel, ['metrics', 'openIncidents', 'openCount', 'escalatedCount', 'sites', 'siteContext', 'lastHandover']);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Panel" />
            <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-5 p-4 md:p-6">
                <ArrivalsPanel arrivals={arrivals} />
                <WatchStatus openIncidents={openIncidents} openCount={openCount} escalatedCount={escalatedCount} edgesOffline={edgesOffline} />
                <ElsewhereNotice />

                <div className="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(340px,2fr)]">
                    <div className="flex min-w-0 flex-col gap-5">
                        <section className="flex flex-col gap-3">
                            <div className="flex items-baseline justify-between">
                                <SectionTitle icon={Siren}>Incidentes abiertos</SectionTitle>
                                {openCount > openIncidents.length && (
                                    <Link
                                        href="/incidents"
                                        className="text-muted-foreground hover:text-foreground text-sm underline-offset-4 hover:underline"
                                    >
                                        Ver los {openCount}
                                    </Link>
                                )}
                            </div>
                            {openIncidents.length === 0 ? (
                                <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-sm">
                                    Sin incidentes abiertos. Cuando la IA confirme una intrusión, aparece acá y suena la alarma.
                                </p>
                            ) : (
                                openIncidents.map((incident) => (
                                    <IncidentRow
                                        key={incident.id}
                                        id={incident.id}
                                        title={incident.title}
                                        status={incident.status}
                                        severity={incident.severity}
                                        detectedAt={incident.detected_at}
                                        meta={incident.site}
                                    />
                                ))
                            )}
                        </section>
                        <section className="flex flex-col gap-3">
                            <SectionTitle icon={Building2}>Propiedades</SectionTitle>
                            <div className="bg-card divide-y rounded-lg border">
                                {sites.map((site) => {
                                    const healthy = site.edges_offline === 0 && site.cameras_online === site.cameras;
                                    return (
                                        <Link
                                            key={site.id}
                                            href={`/sites/${site.id}`}
                                            className="group hover:bg-accent flex items-center gap-3 px-4 py-3"
                                        >
                                            <span
                                                className={cn(
                                                    'size-2.5 rounded-full',
                                                    site.open_incidents > 0 ? 'bg-pending' : healthy ? 'bg-ok' : 'bg-alarm',
                                                )}
                                                aria-hidden
                                            />
                                            <span className="flex-1 truncate font-medium">{site.name}</span>
                                            <span className="text-muted-foreground flex items-center gap-1 text-sm" title="Cámaras online">
                                                <Cctv className="size-4" />
                                                {site.cameras_online}/{site.cameras}
                                            </span>
                                            {site.edges_offline > 0 && (
                                                <span className="text-alarm flex items-center gap-1 text-sm">
                                                    <Cpu className="size-4" /> sin señal
                                                </span>
                                            )}
                                            <ItemActionHint kind="view" />
                                        </Link>
                                    );
                                })}
                            </div>
                            <PushToggle vapidPublicKey={vapidPublicKey} />
                        </section>
                        <ShiftHandoverCard last={lastHandover} siteId={handoverSiteId} />
                        <Funnel metrics={metrics} />
                    </div>

                    <div className="lg:sticky lg:top-4 lg:max-h-[calc(100vh-6rem)] lg:self-start [&>section]:max-h-[inherit]">
                        <AiActivity initial={activity} channel={channel} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
