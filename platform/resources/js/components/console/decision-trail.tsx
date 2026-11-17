import { type ActivityItem, type TraceStep } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

const RESULT_DOT: Record<TraceStep['result'], string> = {
    ok: 'bg-ok',
    stop: 'bg-muted-foreground',
    doubt: 'bg-pending',
    alert: 'bg-alarm',
};

/**
 * How the system decided, step by step: detector → rules → AI → decision. It is a real sequence, so it is numbered.
 */
export function DecisionTrail({ item }: { item: ActivityItem }) {
    return (
        <div className="flex flex-col gap-4">
            {item.frame_urls && item.frame_urls.length >= 2 ? (
                <figure className="flex flex-col gap-1.5">
                    <ol className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                        {item.frame_urls.map((url, index) => (
                            <li key={url} className="relative">
                                <img
                                    src={url}
                                    alt={`Cuadro ${index + 1} de ${item.camera}`}
                                    className="aspect-video w-full rounded-md border bg-black object-cover"
                                />
                                <span className="font-display absolute top-1 left-1 rounded bg-black/70 px-1.5 text-xs text-white tabular-nums">
                                    {index + 1}
                                </span>
                            </li>
                        ))}
                    </ol>
                    <figcaption className="text-muted-foreground text-xs">
                        Los {item.frame_urls.length} cuadros del clip que analizó la IA, en orden.
                    </figcaption>
                </figure>
            ) : (
                item.snapshot_url && <img src={item.snapshot_url} alt={`Captura de ${item.camera}`} className="w-full rounded-lg border bg-black" />
            )}
            <ol className="flex flex-col gap-3">
                {item.trace.map((step, index) => (
                    <li key={index} className="flex gap-3">
                        <span className="font-display bg-surface-2 flex size-6 shrink-0 items-center justify-center rounded-full text-sm">
                            {index + 1}
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2 text-sm font-semibold">
                                <span className={cn('size-2 rounded-full', RESULT_DOT[step.result])} aria-hidden />
                                {step.agent}
                                <time className="text-muted-foreground ml-auto text-xs font-normal tabular-nums">
                                    {new Date(step.at).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                                </time>
                            </div>
                            <p className="text-muted-foreground mt-0.5 text-sm">{step.detail}</p>
                        </div>
                    </li>
                ))}
                {item.trace.length === 0 && (
                    <li className="text-muted-foreground text-sm">Esta detección es anterior al registro de trazabilidad.</li>
                )}
            </ol>
            {item.incident_id && (
                <Link href={`/incidents/${item.incident_id}`} className="text-sm font-medium underline underline-offset-4">
                    Ir al incidente #{item.incident_id}
                </Link>
            )}
        </div>
    );
}
