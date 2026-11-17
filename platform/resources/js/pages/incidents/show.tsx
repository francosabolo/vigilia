import { DecisionTrail } from '@/components/console/decision-trail';
import { FollowIntruder } from '@/components/console/follow-intruder';
import { EmergencyCallDialog, SpeakerDialog } from '@/components/console/incident-response-tools';
import { SeverityText, StatusSignal } from '@/components/incident-badges';
import { IncidentRoute } from '@/components/incident-route';
import { ItemActionLink } from '@/components/item-action';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLiveIncidents } from '@/hooks/use-live-incidents';
import AppLayout from '@/layouts/app-layout';
import { actionLabel, formatDateTime, timeAgo, type IncidentDetail } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Bot, Phone } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Props {
    incident: IncidentDetail;
    channel: string;
}

function Media({ incident }: { incident: IncidentDetail }) {
    if (incident.clip_url) {
        return (
            <video src={incident.clip_url} poster={incident.snapshot_url ?? undefined} controls autoPlay muted loop playsInline className="w-full" />
        );
    }
    if (incident.snapshot_url) {
        return <img src={incident.snapshot_url} alt="Captura del incidente" className="w-full" />;
    }
    return (
        <div className="text-muted-foreground flex aspect-video items-center justify-center text-sm">
            {incident.source === 'detection'
                ? 'El edge no envió imagen de esta detección.'
                : incident.source === 'equipment'
                  ? 'Aviso técnico: el equipo no está enviando imagen.'
                  : 'Aviso sin imagen: lo reportó una persona.'}
        </div>
    );
}

function TamperComparison({ incident }: { incident: IncidentDetail }) {
    const tamper = incident.tamper!;
    const fix = (applyShift: boolean) => router.post(`/incidents/${incident.id}/camera-view`, { apply_shift: applyShift }, { preserveScroll: true });

    return (
        <section className="flex flex-col gap-3">
            <div className="grid gap-2 sm:grid-cols-2">
                {[
                    ['Antes (vista de referencia)', tamper.before_url],
                    ['Ahora', incident.snapshot_url],
                ].map(([label, url]) => (
                    <figure key={label} className="overflow-hidden rounded-lg border bg-black">
                        {url ? (
                            <img src={url} alt={label!} className="w-full" />
                        ) : (
                            <div className="text-muted-foreground flex aspect-video items-center justify-center text-sm">Sin imagen</div>
                        )}
                        <figcaption className="bg-card px-3 py-1.5 text-sm font-medium">{label}</figcaption>
                    </figure>
                ))}
            </div>
            {tamper.can_fix && tamper.view_status !== 'ok' && (
                <div className="bg-card flex flex-col gap-3 rounded-lg border p-4">
                    <p className="text-sm">Cuando la revisen en el lugar, elegí qué pasó con la cámara:</p>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => fix(false)}>
                            Ya la acomodamos: usar la vista actual
                        </Button>
                        {tamper.shift_deg !== null && (
                            <Button variant="secondary" onClick={() => fix(true)}>
                                Fue a propósito: girarla {Math.abs(tamper.shift_deg)}° en el mapa
                            </Button>
                        )}
                    </div>
                </div>
            )}
            {tamper.view_status === 'ok' && <p className="text-ok text-sm">La cámara ya tiene una vista de referencia nueva.</p>}
        </section>
    );
}

function ResponsePanel({ incident }: { incident: IncidentDetail }) {
    const [confirming, setConfirming] = useState<'confirmed' | 'false_alarm' | 'recovered' | null>(null);
    const [lesson, setLesson] = useState('');
    const post = (url: string, data = {}) => router.post(url, data, { preserveScroll: true, onFinish: () => setConfirming(null) });

    if (incident.status === 'resolved') {
        return (
            <div className="flex items-start gap-3">
                <span className="bg-ok mt-1.5 size-2.5 rounded-full" />
                <div>
                    <p className="font-semibold">
                        {incident.resolution === 'confirmed'
                            ? 'Cerrado como intrusión confirmada'
                            : incident.resolution === 'recovered'
                              ? 'Cerrado: el equipo volvió a funcionar'
                              : 'Cerrado como falsa alarma'}
                    </p>
                    <p className="text-muted-foreground text-sm">{timeAgo(incident.resolved_at)}</p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {!incident.acknowledged_at ? (
                <Button size="lg" className="h-12 text-base" onClick={() => post(`/incidents/${incident.id}/acknowledge`)}>
                    Recibido, voy en camino
                </Button>
            ) : (
                <p className="text-sm">
                    <span className="font-semibold">{incident.acknowledged_by ?? 'La guardia'}</span> lo tomó {timeAgo(incident.acknowledged_at)}.
                </p>
            )}

            {confirming ? (
                <div className="bg-surface-2 flex flex-col gap-2 rounded-lg p-3">
                    <p className="text-sm">
                        {confirming === 'confirmed'
                            ? '¿Cerrar como intrusión confirmada?'
                            : confirming === 'recovered'
                              ? '¿Cerrar? Confirmá que el equipo ya funciona.'
                              : '¿Cerrar como falsa alarma?'}
                    </p>
                    {confirming === 'false_alarm' && incident.source === 'detection' && (
                        <Input
                            aria-label="Qué era, para que la IA lo tenga en cuenta"
                            placeholder="¿Qué era? Ej: el perro del lote 12 (opcional)"
                            value={lesson}
                            onChange={(e) => setLesson(e.target.value)}
                            maxLength={200}
                        />
                    )}
                    {confirming === 'false_alarm' && incident.source === 'detection' && (
                        <div className="flex flex-wrap gap-1.5">
                            {['Era un vecino', 'Personal de la casa o de obra', 'Un animal', 'Lluvia, sombra o insectos', 'Un auto que pasaba'].map(
                                (quick) => (
                                    <button
                                        key={quick}
                                        type="button"
                                        onClick={() => setLesson(quick)}
                                        className={cn(
                                            'rounded-full border px-2.5 py-1 text-xs',
                                            lesson === quick ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-accent',
                                        )}
                                    >
                                        {quick}
                                    </button>
                                ),
                            )}
                        </div>
                    )}
                    {confirming === 'false_alarm' && lesson.trim() && (
                        <p className="text-muted-foreground text-xs">La IA lo va a tener en cuenta la próxima vez en esta cámara.</p>
                    )}
                    <div className="flex gap-2">
                        <Button
                            variant={confirming === 'confirmed' ? 'destructive' : 'secondary'}
                            onClick={() =>
                                post(`/incidents/${incident.id}/resolve`, {
                                    resolution: confirming,
                                    lesson: confirming === 'false_alarm' ? lesson : null,
                                })
                            }
                        >
                            Sí, cerrar
                        </Button>
                        <Button variant="ghost" onClick={() => setConfirming(null)}>
                            Volver
                        </Button>
                    </div>
                </div>
            ) : incident.source === 'equipment' ? (
                <Button variant="outline" onClick={() => setConfirming('recovered')}>
                    Ya funciona, cerrar aviso
                </Button>
            ) : (
                <div className="grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        className="border-alarm/60 text-alarm hover:bg-alarm/10 hover:text-alarm"
                        onClick={() => setConfirming('confirmed')}
                    >
                        Fue una intrusión
                    </Button>
                    <Button variant="outline" onClick={() => setConfirming('false_alarm')}>
                        Falsa alarma
                    </Button>
                </div>
            )}
        </div>
    );
}

export default function IncidentShow({ incident, channel }: Props) {
    useLiveIncidents(channel, ['incident', 'siteContext']);
    const note = useForm({ notes: '' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Incidentes', href: '/incidents' },
        { title: incident.title, href: `/incidents/${incident.id}` },
    ];

    function submitNote(event: FormEvent) {
        event.preventDefault();
        note.post(`/incidents/${incident.id}/notes`, { preserveScroll: true, onSuccess: () => note.reset() });
    }

    const place = [incident.site.name, incident.camera, incident.zone ? `zona ${incident.zone}` : null].filter(Boolean).join(', ');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={incident.title} />
            <div className="mx-auto grid w-full max-w-7xl flex-1 gap-6 p-4 md:p-6 lg:grid-cols-[minmax(0,1fr)_380px]">
                <div className="flex min-w-0 flex-col gap-4">
                    <header>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <StatusSignal status={incident.status} />
                            <SeverityText severity={incident.severity} />
                        </div>
                        <h1 className="font-display mt-1 text-3xl leading-tight font-semibold md:text-4xl">{incident.title}</h1>
                        <p className="text-muted-foreground mt-1">
                            {place}. Detectado el {formatDateTime(incident.detected_at)}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {incident.status !== 'resolved' && incident.source !== 'equipment' && (
                                <>
                                    {incident.camera && <SpeakerDialog incident={incident} />}
                                    <EmergencyCallDialog incident={incident} address={incident.site.address ?? null} />
                                </>
                            )}
                            <ItemActionLink kind="view" href={`/incidents/${incident.id}/report`}>
                                Informe para denuncia o seguro
                            </ItemActionLink>
                            <a
                                href={`/incidents/${incident.id}/evidence`}
                                className="text-muted-foreground hover:bg-accent hover:text-foreground inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-medium"
                            >
                                Descargar evidencia
                            </a>
                        </div>
                    </header>

                    {incident.tamper ? (
                        <TamperComparison incident={incident} />
                    ) : incident.source === 'detection' || incident.live_frames.length === 0 ? (
                        <div className={cn('overflow-hidden rounded-xl border bg-black', incident.status === 'escalated' && 'border-alarm border-2')}>
                            <Media incident={incident} />
                        </div>
                    ) : null}

                    {incident.follow && <FollowIntruder follow={incident.follow} />}

                    {incident.route.length >= 2 && <IncidentRoute route={incident.route} />}

                    {incident.reporter && (
                        <div className="border-pending/50 bg-pending/10 flex flex-wrap items-center gap-3 rounded-lg border p-4">
                            <div className="flex-1">
                                <p className="font-semibold">{incident.source === 'panic' ? 'Botón de pánico' : 'Aviso de un vecino'}</p>
                                <p className="text-sm">
                                    {incident.reporter.name}
                                    {incident.reporter.lot && `, lote ${incident.reporter.lot}`}
                                </p>
                            </div>
                            {incident.reporter.phone && (
                                <Button asChild variant="secondary">
                                    <a href={`tel:${incident.reporter.phone}`}>
                                        <Phone className="mr-1 size-4" /> Llamar
                                    </a>
                                </Button>
                            )}
                        </div>
                    )}

                    {incident.source !== 'detection' && incident.live_frames.length > 0 && (
                        <section>
                            <h2 className="mb-2 font-semibold">
                                {incident.source === 'patrol' ? 'Lo que vio la ronda virtual' : 'Cámaras del sector al momento del aviso'}
                            </h2>
                            <div className={cn('grid gap-2', incident.live_frames.length > 1 && 'sm:grid-cols-2')}>
                                {incident.live_frames.map((frame) => (
                                    <figure key={frame.url} className="overflow-hidden rounded-lg border bg-black">
                                        <img src={frame.url} alt={frame.camera} className="w-full" />
                                        <figcaption className="font-display bg-card px-3 py-1.5 text-sm">{frame.camera}</figcaption>
                                    </figure>
                                ))}
                            </div>
                        </section>
                    )}

                    <section className="bg-card flex gap-3 rounded-lg border p-4">
                        <Bot className="text-muted-foreground mt-0.5 size-5 shrink-0" />
                        <div>
                            <p className="text-[15px]">{incident.description ?? 'Sin descripción.'}</p>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {incident.ai_confidence !== null
                                    ? `Análisis de IA con ${Math.round(incident.ai_confidence * 100)}% de confianza.`
                                    : 'Sin verificación de IA.'}
                            </p>
                        </div>
                    </section>

                    {incident.decisions.length > 0 && (
                        <section className="flex flex-col gap-2">
                            <h2 className="font-semibold">Cómo se decidió</h2>
                            <p className="text-muted-foreground text-sm">
                                {incident.decisions.length === 1
                                    ? 'El recorrido de la detección que abrió este incidente.'
                                    : `Este incidente junta ${incident.decisions.length} detecciones. Cada una tiene su recorrido.`}
                            </p>
                            {incident.decisions.map((decision, index) => (
                                <details key={decision.id} open={index === 0} className="bg-card group rounded-lg border">
                                    <summary className="hover:bg-accent flex cursor-pointer list-none items-center gap-3 rounded-lg px-4 py-3 text-sm">
                                        <span className="font-medium">
                                            {new Date(decision.at).toLocaleTimeString('es-AR', {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                                second: '2-digit',
                                            })}
                                            , {decision.camera}
                                        </span>
                                        <span className="text-muted-foreground flex-1 truncate">{decision.headline}</span>
                                        <span className="text-muted-foreground text-xs group-open:hidden">Ver</span>
                                    </summary>
                                    <div className="border-t p-4">
                                        <DecisionTrail item={decision} />
                                    </div>
                                </details>
                            ))}
                        </section>
                    )}
                </div>

                <aside className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
                    <section className="bg-card rounded-xl border p-4">
                        <h2 className="mb-3 font-semibold">Respuesta</h2>
                        <ResponsePanel incident={incident} />
                    </section>

                    <section className="bg-card rounded-xl border p-4">
                        <h2 className="mb-3 font-semibold">Bitácora</h2>
                        <form onSubmit={submitNote} className="mb-4 flex gap-2">
                            <label htmlFor="note" className="sr-only">
                                Nota para la bitácora
                            </label>
                            <input
                                id="note"
                                value={note.data.notes}
                                onChange={(event) => note.setData('notes', event.target.value)}
                                placeholder="Ej: se llamó al 911"
                                className="border-input bg-background flex-1 rounded-md border px-3 py-2 text-sm"
                            />
                            <Button type="submit" variant="secondary" disabled={note.processing || !note.data.notes}>
                                Anotar
                            </Button>
                        </form>
                        <ol className="before:bg-border relative flex flex-col gap-4 pl-5 before:absolute before:inset-y-1 before:left-1 before:w-px">
                            {incident.actions.map((action) => (
                                <li key={action.id} className="relative text-sm">
                                    <span className="bg-muted-foreground absolute top-1.5 -left-[18px] size-2 rounded-full" aria-hidden />
                                    <p className="font-medium">{actionLabel[action.type] ?? action.type}</p>
                                    {action.notes && <p className="text-muted-foreground mt-0.5">{action.notes}</p>}
                                    <p className="text-muted-foreground mt-0.5 text-xs">
                                        {formatDateTime(action.created_at)}
                                        {action.user && `, ${action.user}`}
                                        {action.channel === 'whatsapp' && ', por WhatsApp'}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>
                </aside>
            </div>
        </AppLayout>
    );
}
