import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDuration, timeAgo, type ActivityItem } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Calidad de la IA', href: '/ai/quality' }];

interface SiteStats {
    site: string;
    reviewed: number;
    missed: number;
    miss_rate: number | null;
    closed: number;
    false_alarms: number;
    false_alarm_rate: number | null;
    alert_p50: number | null;
    alert_p95: number | null;
    alerts_measured: number;
}

interface Closure {
    id: number;
    title: string;
    site: string;
    closed_by: string | null;
    resolved_at: string | null;
    snapshot_url: string | null;
}

interface Props {
    closures: Closure[];
    pending: ActivityItem[];
    pendingCount: number;
    stats: SiteStats[];
}

export default function Quality({ pending, pendingCount, stats, closures }: Props) {
    const label = (id: number, missed: boolean) => router.post(`/ai/quality/${id}`, { missed }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calidad de la IA" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">Calidad de la IA</h1>
                    <p className="text-muted-foreground max-w-3xl">
                        Lo que la IA descarta no le llega a nadie, así que sus errores no se ven solos. Cada lunes se eligen al azar hasta 20
                        detecciones descartadas por propiedad para que una persona confirme si estaban bien descartadas. Las falsas alarmas salen de
                        cómo la guardia cierra cada incidente.
                    </p>
                </div>

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Últimas 8 semanas</h2>
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted-foreground border-b">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">Propiedad</th>
                                    <th className="px-4 py-2.5 font-medium">Intrusiones que se le escaparon</th>
                                    <th className="px-4 py-2.5 font-medium">Falsas alarmas</th>
                                    <th className="px-4 py-2.5 font-medium">Tiempo hasta la alerta (7 días)</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {stats.map((row) => (
                                    <tr key={row.site}>
                                        <td className="px-4 py-3 font-medium">{row.site}</td>
                                        <td className="px-4 py-3">
                                            {row.miss_rate === null ? (
                                                <span className="text-muted-foreground">Sin revisiones todavía</span>
                                            ) : (
                                                <span className={cn('font-semibold tabular-nums', row.missed > 0 && 'text-alarm')}>
                                                    {row.miss_rate}%{' '}
                                                    <span className="text-muted-foreground font-normal">
                                                        ({row.missed} de {row.reviewed} revisadas)
                                                    </span>
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.false_alarm_rate === null ? (
                                                <span className="text-muted-foreground">Sin incidentes cerrados</span>
                                            ) : (
                                                <span className="font-semibold tabular-nums">
                                                    {row.false_alarm_rate}%{' '}
                                                    <span className="text-muted-foreground font-normal">
                                                        ({row.false_alarms} de {row.closed} cerrados)
                                                    </span>
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.alert_p50 === null ? (
                                                <span className="text-muted-foreground">Sin alertas</span>
                                            ) : (
                                                <span className={cn('tabular-nums', (row.alert_p95 ?? 0) > 30 && 'text-alarm font-semibold')}>
                                                    {formatDuration(row.alert_p50)}{' '}
                                                    <span className="text-muted-foreground">
                                                        (el 95 % en menos de {formatDuration(row.alert_p95)})
                                                    </span>
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Con menos de 50 revisadas el porcentaje todavía puede moverse mucho: mirá también la cantidad.
                    </p>
                </section>

                {closures.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-lg font-semibold">Cierres de falsa alarma para revisar ({closures.length})</h2>
                        <p className="text-muted-foreground -mt-1 text-sm">
                            Una muestra de lo que las guardias cerraron como falsa alarma. Si alguno era real, queda registrado en el incidente.
                        </p>
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {closures.map((closure) => (
                                <li key={closure.id} className="bg-card flex flex-col gap-2 rounded-xl border p-3 text-sm">
                                    {closure.snapshot_url && (
                                        <img src={closure.snapshot_url} alt="" className="aspect-video w-full rounded object-cover" />
                                    )}
                                    <p className="font-medium">{closure.title}</p>
                                    <p className="text-muted-foreground">
                                        {closure.site}, cerrado {closure.resolved_at ? timeAgo(closure.resolved_at) : ''} por{' '}
                                        {closure.closed_by ?? 'la guardia'}
                                    </p>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(`/ai/quality/closures/${closure.id}`, { correct: true }, { preserveScroll: true })
                                            }
                                        >
                                            Bien cerrado
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="border-alarm/60 text-alarm hover:bg-alarm/10 hover:text-alarm"
                                            onClick={() =>
                                                router.post(`/ai/quality/closures/${closure.id}`, { correct: false }, { preserveScroll: true })
                                            }
                                        >
                                            Era real
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Para revisar ({pendingCount})</h2>
                    {pending.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-sm">
                            No hay nada pendiente. La próxima muestra sale el lunes.
                        </p>
                    ) : (
                        <ul className="grid gap-4 sm:grid-cols-2">
                            {pending.map((item) => (
                                <li key={item.id} className="bg-card flex flex-col gap-3 rounded-xl border p-3">
                                    {item.snapshot_url && <img src={item.snapshot_url} alt="" className="aspect-video w-full rounded object-cover" />}
                                    {(item.frame_urls?.length ?? 0) > 1 && (
                                        <div className="grid grid-cols-4 gap-1">
                                            {item.frame_urls!.map((url) => (
                                                <img key={url} src={url} alt="" className="aspect-video w-full rounded object-cover" loading="lazy" />
                                            ))}
                                        </div>
                                    )}
                                    <div className="text-sm">
                                        <p className="font-medium">{item.headline}</p>
                                        <p className="text-muted-foreground">
                                            {item.camera} ({item.site}), {timeAgo(item.at)}
                                        </p>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Button variant="outline" onClick={() => label(item.id, false)}>
                                            Bien descartada
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="border-alarm/60 text-alarm hover:bg-alarm/10 hover:text-alarm"
                                            onClick={() => label(item.id, true)}
                                        >
                                            Se le escapó
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
