import { SectionTitle } from '@/components/icon-circle';
import InputError from '@/components/input-error';
import { ItemActionLink } from '@/components/item-action';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { ScanEye } from 'lucide-react';
import { useState } from 'react';

export interface PatrolFinding {
    camera: string;
    kind: string;
    severity: 'baja' | 'media' | 'alta';
    description: string;
    frame_url: string;
}

export interface PatrolRound {
    id: number;
    trigger: 'manual' | 'scheduled' | 'closing';
    requested_by: string | null;
    status: 'running' | 'done' | 'failed';
    summary: string | null;
    cameras_total: number;
    cameras_checked: number;
    started_at: string;
    finished_at: string | null;
    incident_id: number | null;
    findings: PatrolFinding[];
    skipped: { camera_id: number; camera: string; reason: string }[];
}

const severityDot: Record<PatrolFinding['severity'], string> = {
    baja: 'bg-muted-foreground/50',
    media: 'bg-pending',
    alta: 'bg-alarm',
};

const severityText: Record<PatrolFinding['severity'], string> = {
    baja: 'Leve: queda anotada',
    media: 'Avisada a la guardia',
    alta: 'Avisada a la guardia, urgente',
};

function who(round: PatrolRound): string {
    if (round.trigger === 'closing') return `cierre, pedido por ${round.requested_by ?? 'alguien del equipo'}`;
    return round.trigger === 'manual' ? `pedida por ${round.requested_by ?? 'alguien del equipo'}` : 'automática';
}

/**
 * C1 — virtual patrol on a site's summary: the latest round with what it found, the earlier ones, and "do one now".
 */
export function PatrolCard({
    siteId,
    patrols,
    intervalMinutes,
    canManage,
    closingCheck = false,
}: {
    siteId: number;
    patrols: PatrolRound[];
    intervalMinutes: number | null;
    canManage: boolean;
    /** M20 — shops and warehouses: check that nobody stayed inside at closing time. */
    closingCheck?: boolean;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [sending, setSending] = useState(false);
    const [latest, ...earlier] = patrols;
    const running = latest?.status === 'running';

    function start() {
        router.post(`/sites/${siteId}/patrols`, {}, { preserveScroll: true, onFinish: () => setSending(false) });
        setSending(true);
    }

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <SectionTitle icon={ScanEye}>Ronda virtual</SectionTitle>
                    <p className="text-muted-foreground mt-1 text-sm">
                        La IA recorre todas las cámaras y avisa solo lo que está fuera de lo normal.{' '}
                        {intervalMinutes ? `Automática cada ${intervalMinutes} minutos.` : 'Las rondas automáticas están apagadas.'}
                        {canManage && (
                            <>
                                {' '}
                                <Link href={`/sites/${siteId}?tab=ajustes`} className="underline underline-offset-2">
                                    Cambiar
                                </Link>
                            </>
                        )}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {closingCheck && (
                        <Button
                            onClick={() => {
                                router.post(
                                    `/sites/${siteId}/patrols`,
                                    { closing: true },
                                    { preserveScroll: true, onFinish: () => setSending(false) },
                                );
                                setSending(true);
                            }}
                            disabled={running || sending}
                        >
                            Cerrar el local: revisar que no quede nadie
                        </Button>
                    )}
                    <Button variant="outline" onClick={start} disabled={running || sending}>
                        {running ? 'Ronda en curso…' : 'Hacer una ronda ahora'}
                    </Button>
                </div>
            </div>
            <InputError message={errors.patrol} />

            {!latest ? (
                <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-sm">
                    Todavía no se hizo ninguna ronda. Hacé la primera para ver cómo está cada cámara en este momento.
                </p>
            ) : (
                <div className="bg-card flex flex-col gap-4 rounded-2xl border p-4">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className="font-semibold">
                            {running
                                ? `Revisando ${latest.cameras_total} cámaras…`
                                : (latest.summary ?? (latest.status === 'failed' ? 'La ronda no se pudo completar.' : ''))}
                        </p>
                        <p className="text-muted-foreground text-sm">
                            {timeAgo(latest.started_at)}, {who(latest)}
                        </p>
                    </div>

                    {latest.findings.length > 0 && (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {latest.findings.map((finding) => (
                                <li key={finding.frame_url} className="flex gap-3">
                                    <img
                                        src={finding.frame_url}
                                        alt={`${finding.kind} en ${finding.camera}`}
                                        className="aspect-video w-32 shrink-0 rounded-md bg-black object-cover"
                                        loading="lazy"
                                    />
                                    <div className="min-w-0 text-sm">
                                        <p className="font-semibold">
                                            {finding.kind} <span className="text-muted-foreground font-normal">({finding.camera})</span>
                                        </p>
                                        <p>{finding.description}</p>
                                        <p className="text-muted-foreground mt-1 flex items-center gap-1.5">
                                            <span className={cn('size-2 rounded-full', severityDot[finding.severity])} />
                                            {severityText[finding.severity]}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {latest.skipped.length > 0 && (
                        <p className="text-muted-foreground text-sm">
                            Sin revisar: {latest.skipped.map((item) => `${item.camera} (${item.reason.toLowerCase()})`).join(', ')}.
                        </p>
                    )}

                    {latest.incident_id && (
                        <ItemActionLink kind="view" href={`/incidents/${latest.incident_id}`} className="-ml-3 self-start">
                            Ver el incidente
                        </ItemActionLink>
                    )}
                </div>
            )}

            {earlier.length > 0 && (
                <ul className="divide-y text-sm">
                    {earlier.map((round) => (
                        <li key={round.id} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
                            <span className={cn(round.findings.length > 0 && 'font-semibold')}>{round.summary ?? 'Ronda incompleta.'}</span>
                            <span className="text-muted-foreground">
                                {timeAgo(round.started_at)}, {who(round)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
