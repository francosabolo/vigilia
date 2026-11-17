import { type Handover } from '@/components/console/shift-handover';
import AppLayout from '@/layouts/app-layout';
import { formatDuration, timeAgo } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Guardias', href: '/guards' }];

interface GuardRow {
    id: number;
    name: string;
    site: string | null;
    alerts: number;
    answered: number;
    avg_ack_seconds: number | null;
    slowest_ack_seconds: number | null;
    escalated: number;
    last_answer_at: string | null;
}

interface Props {
    days: number;
    report: {
        guards: GuardRow[];
        shifts: Record<'day' | 'night', { label: string; alerts: number; avg_ack_seconds: number | null; escalated: number }>;
    };
    handovers: Handover[];
    supervision: {
        site: string;
        round_interval_minutes: number | null;
        guard_post_max_absence_minutes: number | null;
        points: { camera: string; kind: 'post' | 'checkpoint'; last_seen_at: string | null; now_present: boolean | null; detail: string | null }[];
    }[];
}

export default function GuardsIndex({ days, report, handovers, supervision }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Guardias" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">Guardias</h1>
                        <p className="text-muted-foreground">
                            Cuánto tardó cada guardia en responder, por turno, y qué alertas pasaron a la central.
                        </p>
                    </div>
                    <nav className="bg-card flex rounded-lg border p-1 text-sm" aria-label="Período">
                        {[7, 30, 90].map((value) => (
                            <Link
                                key={value}
                                href={`/guards?days=${value}`}
                                preserveState
                                aria-current={days === value ? 'page' : undefined}
                                className={cn(
                                    'rounded-md px-3 py-1.5 font-medium',
                                    days === value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {value} días
                            </Link>
                        ))}
                    </nav>
                </div>

                <section className="grid gap-4 sm:grid-cols-2">
                    {Object.values(report.shifts).map((shift) => (
                        <div key={shift.label} className="bg-card rounded-xl border p-4">
                            <p className="font-semibold">{shift.label}</p>
                            <dl className="mt-3 grid grid-cols-3 gap-3">
                                {[
                                    [shift.alerts, 'alertas'],
                                    [formatDuration(shift.avg_ack_seconds), 'respuesta promedio'],
                                    [shift.escalated, 'a la central'],
                                ].map(([value, label]) => (
                                    <div key={label} className="flex flex-col-reverse">
                                        <dt className="text-muted-foreground mt-1 text-sm">{label}</dt>
                                        <dd className="font-display text-2xl leading-none font-semibold tabular-nums">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    ))}
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Por guardia</h2>
                    {report.guards.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-sm">
                            No hay guardias cargados en estas propiedades. Invitalos desde Usuarios.
                        </p>
                    ) : (
                        <div className="bg-card overflow-x-auto rounded-xl border">
                            <table className="w-full text-left text-sm">
                                <thead className="text-muted-foreground border-b">
                                    <tr>
                                        <th className="px-4 py-2.5 font-medium">Guardia</th>
                                        <th className="px-4 py-2.5 font-medium">Respondió</th>
                                        <th className="px-4 py-2.5 font-medium">Promedio</th>
                                        <th className="px-4 py-2.5 font-medium">Más lenta</th>
                                        <th className="px-4 py-2.5 font-medium">A la central</th>
                                        <th className="px-4 py-2.5 font-medium">Última respuesta</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.guards.map((guard) => (
                                        <tr key={guard.id}>
                                            <td className="px-4 py-3">
                                                <p className="font-medium">{guard.name}</p>
                                                <p className="text-muted-foreground">{guard.site}</p>
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">
                                                {guard.answered} de {guard.alerts}
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">{formatDuration(guard.avg_ack_seconds)}</td>
                                            <td className="px-4 py-3 tabular-nums">{formatDuration(guard.slowest_ack_seconds)}</td>
                                            <td className={cn('px-4 py-3 tabular-nums', guard.escalated > 0 && 'text-alarm font-semibold')}>
                                                {guard.escalated}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3">
                                                {guard.last_answer_at ? timeAgo(guard.last_answer_at) : 'Nunca'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <p className="text-muted-foreground text-sm">
                        «A la central» cuenta las alertas de su propiedad que nadie respondió a tiempo, en cualquiera de los turnos.
                    </p>
                </section>

                {supervision.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-lg font-semibold">Garita y rondas</h2>
                        {supervision.map((site) => (
                            <div key={site.site} className="bg-card rounded-xl border p-4">
                                <p className="font-semibold">{site.site}</p>
                                <p className="text-muted-foreground text-sm">
                                    {[
                                        site.guard_post_max_absence_minutes && `Garita: aviso tras ${site.guard_post_max_absence_minutes} min vacía`,
                                        site.round_interval_minutes && `Ronda cada ${site.round_interval_minutes} min`,
                                    ]
                                        .filter(Boolean)
                                        .join('. ')}
                                </p>
                                {site.points.length === 0 ? (
                                    <p className="text-muted-foreground mt-2 text-sm">
                                        Falta marcar la garita y los puntos de ronda en la pestaña Cámaras.
                                    </p>
                                ) : (
                                    <ul className="mt-2 divide-y text-sm">
                                        {site.points.map((point) => (
                                            <li key={point.camera} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
                                                <span>
                                                    <span className="font-medium">{point.camera}</span>
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        ({point.kind === 'post' ? 'garita' : 'punto de ronda'})
                                                    </span>
                                                </span>
                                                <span
                                                    className={cn(
                                                        point.kind === 'post' && point.now_present === false
                                                            ? 'text-alarm font-medium'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {point.kind === 'post' && point.now_present !== null
                                                        ? point.now_present
                                                            ? 'Con guardia'
                                                            : 'Vacía'
                                                        : point.last_seen_at
                                                          ? `Último paso ${timeAgo(point.last_seen_at)}`
                                                          : 'Sin pasos registrados'}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Entregas de turno</h2>
                    {handovers.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Todavía no hay entregas de turno. Las guardias las hacen desde el panel.</p>
                    ) : (
                        <ul className="bg-card divide-y rounded-xl border text-sm">
                            {handovers.map((handover) => (
                                <li key={handover.id} className="px-4 py-3">
                                    <p className="text-muted-foreground">
                                        {handover.by ?? 'Alguien del equipo'}, {handover.site}, {timeAgo(handover.at)}
                                        {handover.open_incidents > 0 && `, dejó ${handover.open_incidents} abiertos`}
                                    </p>
                                    <p className="mt-1">{handover.summary}</p>
                                    {handover.notes && <p className="mt-1 border-l-2 pl-3">«{handover.notes}»</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
