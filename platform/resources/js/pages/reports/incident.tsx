import { ReportShell } from '@/components/report-shell';
import { actionLabel, formatDateTime, severityLabel, statusLabel, type IncidentDetail } from '@/lib/seguria';

interface Props {
    incident: IncidentDetail;
    narrative: string;
    site: { name: string; address: string | null; client: string | null };
    organization: string;
    generatedAt: string;
}

const resolutionText: Record<string, string> = {
    confirmed: 'Intrusión confirmada',
    false_alarm: 'Falsa alarma',
    recovered: 'El equipo se recuperó',
};

export default function IncidentReport({ incident, narrative, site, organization, generatedAt }: Props) {
    const frames = incident.decisions.flatMap((decision) => decision.frame_urls ?? []).slice(0, 4);
    const images = [
        ...(incident.snapshot_url ? [{ url: incident.snapshot_url, caption: `Captura, ${incident.camera ?? 'cámara'}` }] : []),
        ...frames.map((url, index) => ({ url, caption: `Cuadro ${index + 1} del clip analizado` })),
        ...incident.live_frames.map((frame) => ({ url: frame.url, caption: frame.camera })),
    ];
    const facts: [string, string | null][] = [
        ['Lugar', [site.name, site.address].filter(Boolean).join(', ')],
        ['Cliente', site.client],
        ['Cámara y zona', [incident.camera, incident.zone].filter(Boolean).join(', ') || null],
        ['Detectado', formatDateTime(incident.detected_at)],
        [
            'Recibido por la guardia',
            incident.acknowledged_at
                ? `${formatDateTime(incident.acknowledged_at)}${incident.acknowledged_by ? `, ${incident.acknowledged_by}` : ''}`
                : null,
        ],
        ['Escalado a la central', incident.escalated_at ? formatDateTime(incident.escalated_at) : null],
        ['Cerrado', incident.resolved_at ? formatDateTime(incident.resolved_at) : null],
        ['Estado', incident.resolution ? resolutionText[incident.resolution] : statusLabel[incident.status]],
        ['Severidad', severityLabel[incident.severity]],
    ];

    return (
        <ReportShell
            title={`Informe del incidente ${incident.id}`}
            organization={organization}
            backHref={`/incidents/${incident.id}`}
            backLabel="Volver al incidente"
        >
            <p className="text-sm text-neutral-500">
                Informe del incidente N.º {incident.id}, generado el {formatDateTime(generatedAt)}
            </p>
            <h1 className="font-display mt-1 text-3xl font-semibold">{incident.title}</h1>

            <dl className="mt-6 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[12rem_1fr]">
                {facts
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-neutral-500">{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
            </dl>

            <section className="mt-8">
                <h2 className="mb-2 text-lg font-semibold">Relato</h2>
                <div className="space-y-3 text-[15px] leading-relaxed">
                    {narrative.split(/\n{2,}/).map((paragraph) => (
                        <p key={paragraph}>{paragraph}</p>
                    ))}
                </div>
            </section>

            {images.length > 0 && (
                <section className="mt-8">
                    <h2 className="mb-3 text-lg font-semibold">Imágenes</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {images.map((image) => (
                            <figure key={image.url} className="break-inside-avoid">
                                <img src={image.url} alt={image.caption} className="w-full rounded bg-black" />
                                <figcaption className="mt-1 text-sm text-neutral-600">{image.caption}</figcaption>
                            </figure>
                        ))}
                    </div>
                </section>
            )}

            {incident.route.length >= 2 && (
                <section className="mt-8 break-inside-avoid">
                    <h2 className="mb-2 text-lg font-semibold">Recorrido</h2>
                    <ol className="list-decimal space-y-1 pl-5 text-sm">
                        {incident.route.map((stop) => (
                            <li key={`${stop.camera_id}-${stop.at}`}>
                                {stop.camera}, {formatDateTime(stop.at)}
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            <section className="mt-8">
                <h2 className="mb-2 text-lg font-semibold">Registro completo</h2>
                <table className="w-full text-left text-sm">
                    <tbody className="divide-y divide-neutral-200">
                        {[...incident.actions].reverse().map((action) => (
                            <tr key={action.id} className="break-inside-avoid align-top">
                                <td className="py-2 pr-3 whitespace-nowrap text-neutral-500 tabular-nums">{formatDateTime(action.created_at)}</td>
                                <td className="py-2">
                                    {actionLabel[action.type] ?? action.type}
                                    {action.user && <span className="text-neutral-500">, {action.user}</span>}
                                    {action.notes && <span className="block text-neutral-600">{action.notes}</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <p className="mt-10 border-t border-neutral-200 pt-4 text-xs text-neutral-500">
                Las imágenes son datos personales (Ley 25.326): usalas solo para la denuncia o el reclamo y no las difundas.
            </p>
        </ReportShell>
    );
}
