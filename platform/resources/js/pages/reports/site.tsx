import { ReportShell } from '@/components/report-shell';
import { formatDateTime, formatDuration, plural, severityLabel, type Metrics, type Severity } from '@/lib/vigilia';
import { router } from '@inertiajs/react';

interface ReportIncident {
    id: number;
    title: string;
    source: string;
    severity: Severity;
    detected_at: string;
    ack_seconds: number | null;
    escalated: boolean;
    resolution: 'confirmed' | 'false_alarm' | 'recovered' | null;
}

interface Props {
    site: { id: number; name: string; kind: 'building' | 'country'; address: string | null; client: string | null };
    organization: string;
    months: string[];
    report: {
        month: string;
        month_label: string;
        is_current: boolean;
        metrics: Metrics;
        by_source: { label: string; count: number }[];
        equipment: { outages: number; hours_down: number };
        patrols: { rounds: number; findings: number };
        incidents: ReportIncident[];
        narrative: string;
    };
}

const resolutionText: Record<string, string> = {
    confirmed: 'Intrusión confirmada',
    false_alarm: 'Falsa alarma',
    recovered: 'Se recuperó',
};

const monthName = (month: string) => new Date(`${month}-15T12:00:00`).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });

export default function SiteReport({ site, organization, months, report }: Props) {
    const m = report.metrics;

    return (
        <ReportShell
            title={`Informe de ${report.month_label}: ${site.name}`}
            organization={organization}
            backHref={`/sites/${site.id}`}
            backLabel={`Volver a ${site.name}`}
            controls={
                <select
                    aria-label="Mes del informe"
                    value={report.month}
                    onChange={(e) => router.get(`/sites/${site.id}/report`, { month: e.target.value })}
                    className="h-9 rounded-md border border-neutral-300 bg-white px-3 text-sm"
                >
                    {months.map((month) => (
                        <option key={month} value={month}>
                            {monthName(month)}
                        </option>
                    ))}
                </select>
            }
        >
            <h1 className="font-display text-3xl font-semibold">Informe de seguridad de {report.month_label}</h1>
            <p className="mt-1 text-neutral-600">
                {site.name}
                {site.address && `, ${site.address}`}
                {site.client && `. Cliente: ${site.client}`}
            </p>
            {report.is_current && <p className="mt-2 text-sm text-amber-700">Mes en curso: los datos son parciales hasta el último día.</p>}

            <div className="mt-6 space-y-3 text-[15px] leading-relaxed">
                {report.narrative.split(/\n{2,}/).map((paragraph) => (
                    <p key={paragraph}>{paragraph}</p>
                ))}
            </div>

            <dl className="mt-8 grid grid-cols-2 gap-x-6 gap-y-5 border-y border-neutral-200 py-6 sm:grid-cols-4">
                {[
                    [m.detections, 'movimientos analizados'],
                    [m.filtered_by_ai + m.outside_schedule, 'descartados sin molestar a nadie'],
                    [m.incidents, 'alertas a la guardia'],
                    [formatDuration(m.avg_ack_seconds), 'respuesta promedio'],
                    [m.confirmed, 'intrusiones confirmadas'],
                    [m.escalated, 'escaladas a la central'],
                    [report.equipment.outages, `cortes de equipos (${report.equipment.hours_down} h)`],
                    [report.patrols.rounds, `rondas virtuales (${plural(report.patrols.findings, 'novedad', 'novedades')})`],
                ].map(([value, label]) => (
                    <div key={label} className="flex flex-col-reverse">
                        <dt className="mt-1 text-sm text-neutral-600">{label}</dt>
                        <dd className="font-display text-3xl leading-none font-semibold tabular-nums">{value}</dd>
                    </div>
                ))}
            </dl>

            <section className="mt-8 break-inside-avoid">
                <h2 className="mb-3 text-lg font-semibold">Alertas del mes</h2>
                {report.incidents.length === 0 ? (
                    <p className="text-neutral-600">No hubo alertas este mes.</p>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-neutral-300 text-neutral-600">
                            <tr>
                                <th className="py-2 pr-3 font-medium">Fecha</th>
                                <th className="py-2 pr-3 font-medium">Alerta</th>
                                <th className="py-2 pr-3 font-medium">Respuesta</th>
                                <th className="py-2 font-medium">Resultado</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-200">
                            {report.incidents.map((incident) => (
                                <tr key={incident.id} className="break-inside-avoid align-top">
                                    <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{formatDateTime(incident.detected_at)}</td>
                                    <td className="py-2 pr-3">
                                        {incident.title}
                                        <span className="block text-neutral-500">
                                            {incident.source}, severidad {severityLabel[incident.severity].toLowerCase()}
                                        </span>
                                    </td>
                                    <td className="py-2 pr-3 whitespace-nowrap">
                                        {formatDuration(incident.ack_seconds)}
                                        {incident.escalated && <span className="block text-neutral-500">pasó a la central</span>}
                                    </td>
                                    <td className="py-2">{incident.resolution ? resolutionText[incident.resolution] : 'Abierto'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            {report.by_source.length > 0 && (
                <p className="mt-6 text-sm text-neutral-600">
                    Origen de las alertas: {report.by_source.map((item) => `${item.label.toLowerCase()} (${item.count})`).join(', ')}.
                </p>
            )}
        </ReportShell>
    );
}
