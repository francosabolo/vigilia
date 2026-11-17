import { IncidentRow } from '@/components/console/incident-row';
import { useLiveIncidents } from '@/hooks/use-live-incidents';
import AppLayout from '@/layouts/app-layout';
import { type IncidentSummary } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Camera, Siren, UserRound } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Incidentes', href: '/incidents' }];

interface Props {
    incidents: IncidentSummary[];
    filter: 'open' | 'all';
    channel: string;
}

function Thumbnail({ incident }: { incident: IncidentSummary }) {
    return (
        <div className="bg-background hidden aspect-video w-36 shrink-0 items-center justify-center overflow-hidden rounded-md sm:flex">
            {incident.thumbnail_url ? (
                <img src={incident.thumbnail_url} alt="" className="size-full object-cover" loading="lazy" />
            ) : incident.source === 'panic' ? (
                <Siren className="text-alarm size-6" />
            ) : incident.source === 'resident_report' ? (
                <UserRound className="text-muted-foreground size-6" />
            ) : (
                <Camera className="text-muted-foreground size-6" />
            )}
        </div>
    );
}

export default function IncidentsIndex({ incidents, filter, channel }: Props) {
    useLiveIncidents(channel, ['incidents', 'siteContext']);
    const { siteContext } = usePage<SharedData>().props;
    const showSite = !siteContext?.current;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Incidentes" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="font-display text-3xl font-semibold">Incidentes</h1>
                    <nav className="bg-card flex rounded-lg border p-1 text-sm" aria-label="Filtro">
                        {(['open', 'all'] as const).map((value) => (
                            <Link
                                key={value}
                                href={`/incidents?filter=${value}`}
                                preserveState
                                aria-current={filter === value ? 'page' : undefined}
                                className={cn(
                                    'rounded-md px-4 py-1.5 font-medium',
                                    filter === value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {value === 'open' ? 'Abiertos' : 'Todos'}
                            </Link>
                        ))}
                    </nav>
                </div>

                {incidents.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-12 text-center">
                        <span className="bg-ok size-3 rounded-full" />
                        <p className="text-lg font-semibold">{filter === 'open' ? 'Ningún incidente abierto' : 'Todavía no hubo incidentes'}</p>
                        <p className="text-muted-foreground max-w-sm text-sm">
                            La IA sigue mirando todas las cámaras. Si confirma algo, aparece acá en el momento y suena la alarma.
                        </p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-2">
                        {incidents.map((incident) => (
                            <IncidentRow
                                key={incident.id}
                                id={incident.id}
                                title={incident.title}
                                status={incident.status}
                                severity={incident.severity}
                                detectedAt={incident.detected_at}
                                meta={showSite ? `${incident.site.name}, ${incident.camera}` : incident.camera}
                                thumbnail={<Thumbnail incident={incident} />}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
