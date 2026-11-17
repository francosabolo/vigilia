import { SectionTitle } from '@/components/icon-circle';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { plural, timeAgo } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FlaskConical, Scale } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pruebas de IA', href: '/ai/shadow' }];

interface Score {
    recall: number | null;
    false_alarm_rate: number | null;
    positives: number;
    negatives: number;
}

interface Disagreement {
    id: number;
    detection_id: number;
    site: string;
    camera: string;
    at: string;
    snapshot_url: string | null;
    champion: { alerted: boolean; description: string | null };
    candidate: { alerted: boolean; description: string | null; confidence: number | null };
    truth: boolean | null;
    truth_source: 'simulation' | 'incident' | 'review' | null;
}

interface Report {
    challenger: string;
    compared: number;
    agreement: number | null;
    labeled: number;
    champion: Score;
    candidate: Score;
    cost_per_1000: number | null;
    latency_ms: number | null;
    recommendation: { status: 'waiting' | 'promote' | 'reject' | 'tie'; text: string };
    disagreements: Disagreement[];
}

interface Props {
    enabled: boolean;
    current: string;
    production: string;
    challengers: { label: string; count: number }[];
    report: Report;
}

const percent = (value: number | null) => (value === null ? '—' : `${(value * 100).toFixed(1).replace('.', ',')}%`);

export default function Shadow({ enabled, current, production, challengers, report, fromSimulation }: Props & { fromSimulation: boolean }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pruebas de IA" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4 md:p-6">
                <div className="max-w-3xl">
                    <h1 className="font-display text-3xl font-semibold">Pruebas de IA</h1>
                    <p className="text-muted-foreground mt-1">
                        Un retador (otro modelo u otro prompt) mira en segundo plano las mismas detecciones que producción, sin decidir nada. Acá ves
                        si conviene cambiarlo antes de que afecte a una sola guardia.
                    </p>
                </div>

                {fromSimulation && (
                    <p className="border-pending bg-pending/10 max-w-3xl rounded-lg border px-4 py-3 text-sm">
                        <strong>Estos números salen de la simulación de demo.</strong> En las propiedades simuladas, «producción» no es Claude sino un
                        verificador simulado que se equivoca a propósito, así que la comparación no sirve para decidir. Con cámaras reales, acá se
                        compara el modelo real contra el retador sobre las mismas detecciones.
                    </p>
                )}

                {report.compared === 0 ? (
                    <Empty enabled={enabled} current={current} />
                ) : (
                    <>
                        {challengers.length > 1 && (
                            <div className="flex flex-wrap gap-2">
                                {challengers.map((challenger) => (
                                    <Button
                                        key={challenger.label}
                                        size="sm"
                                        variant={challenger.label === report.challenger ? 'default' : 'secondary'}
                                        onClick={() => router.get('/ai/shadow', { challenger: challenger.label }, { preserveScroll: true })}
                                    >
                                        {challenger.label} ({challenger.count})
                                    </Button>
                                ))}
                            </div>
                        )}

                        <Recommendation recommendation={report.recommendation} />

                        <section className="grid gap-4 md:grid-cols-2">
                            <Side title="Producción" subtitle={production} score={report.champion} />
                            <Side
                                title="Retador"
                                subtitle={report.challenger}
                                score={report.candidate}
                                extra={
                                    <>
                                        {report.cost_per_1000 !== null && (
                                            <Stat
                                                label="Costo cada 1.000 análisis"
                                                value={`US$ ${report.cost_per_1000.toFixed(2).replace('.', ',')}`}
                                            />
                                        )}
                                        {report.latency_ms !== null && (
                                            <Stat
                                                label="Tiempo de respuesta (mediana)"
                                                value={`${(report.latency_ms / 1000).toFixed(1).replace('.', ',')} s`}
                                            />
                                        )}
                                    </>
                                }
                            />
                        </section>

                        <p className="text-muted-foreground text-sm">
                            {plural(report.compared, 'detección comparada', 'detecciones comparadas')}. Coinciden en el {percent(report.agreement)}.{' '}
                            {plural(report.labeled, 'tiene', 'tienen')} resultado conocido: incidentes cerrados, simulación o revisión de una persona.
                        </p>

                        <section className="flex flex-col gap-3">
                            <SectionTitle icon={Scale}>Donde no coinciden</SectionTitle>
                            {report.disagreements.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Por ahora dicen lo mismo en todas las detecciones.</p>
                            ) : (
                                <ul className="grid gap-4 lg:grid-cols-2">
                                    {report.disagreements.map((item) => (
                                        <DisagreementCard key={item.id} item={item} />
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function Empty({ enabled, current }: { enabled: boolean; current: string }) {
    return (
        <div className="bg-muted/60 flex max-w-3xl flex-col gap-3 rounded-2xl p-6">
            <SectionTitle icon={FlaskConical}>Todavía no hay comparaciones</SectionTitle>
            <p className="text-sm">
                Retador configurado: <span className="font-semibold">{current}</span>.
            </p>
            {enabled ? (
                <p className="text-muted-foreground text-sm">
                    El modo sombra está prendido: las comparaciones aparecen a medida que lleguen detecciones.
                </p>
            ) : (
                <p className="text-muted-foreground text-sm">
                    El modo sombra está apagado. Prendelo con <code>SEGURIA_SHADOW=true</code>, o corré el retador sobre detecciones pasadas con{' '}
                    <code>php artisan seguria:shadow-run</code>.
                </p>
            )}
        </div>
    );
}

function Recommendation({ recommendation }: { recommendation: Report['recommendation'] }) {
    return (
        <div
            className={cn(
                'rounded-2xl border p-4 text-sm font-semibold',
                recommendation.status === 'promote' && 'border-ok/50 bg-ok/10',
                recommendation.status === 'reject' && 'border-alarm/50 bg-alarm/10',
                (recommendation.status === 'waiting' || recommendation.status === 'tie') && 'bg-muted/60',
            )}
        >
            {recommendation.text}
        </div>
    );
}

function Side({ title, subtitle, score, extra }: { title: string; subtitle: string; score: Score; extra?: React.ReactNode }) {
    return (
        <div className="bg-card flex flex-col gap-4 rounded-2xl border p-5">
            <div>
                <h2 className="font-display text-xl font-semibold">{title}</h2>
                <p className="text-muted-foreground text-sm">{subtitle}</p>
            </div>
            <div className="grid grid-cols-2 gap-4">
                <Stat label={`Intrusiones detectadas (de ${score.positives})`} value={percent(score.recall)} />
                <Stat label={`Falsas alarmas que pasan (de ${score.negatives})`} value={percent(score.false_alarm_rate)} />
                {extra}
            </div>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="font-display text-3xl font-semibold tabular-nums">{value}</p>
            <p className="text-muted-foreground text-sm">{label}</p>
        </div>
    );
}

function Verdict({ who, alerted, description }: { who: string; alerted: boolean; description: string | null }) {
    return (
        <div className="grid gap-0.5">
            <p className="text-sm">
                <span className="font-semibold">{who}:</span>{' '}
                <span className={alerted ? 'text-alarm font-semibold' : 'text-ok font-semibold'}>{alerted ? 'alertaba' : 'descartaba'}</span>
            </p>
            {description && <p className="text-muted-foreground text-sm">{description}</p>}
        </div>
    );
}

function DisagreementCard({ item }: { item: Disagreement }) {
    const label = (threat: boolean) => router.post(`/ai/shadow/detections/${item.detection_id}/label`, { threat }, { preserveScroll: true });
    const right = item.truth === null ? null : item.truth === item.candidate.alerted ? 'Retador' : 'Producción';

    return (
        <li className="bg-card flex flex-col overflow-hidden rounded-2xl border">
            {item.snapshot_url ? (
                <img src={item.snapshot_url} alt={`Captura de ${item.camera}`} className="aspect-video w-full object-cover" loading="lazy" />
            ) : (
                <div className="bg-muted text-muted-foreground flex aspect-video items-center justify-center text-sm">
                    Imagen borrada por retención
                </div>
            )}
            <div className="flex flex-1 flex-col gap-3 p-4">
                <p className="text-muted-foreground text-sm">
                    {item.site}, {item.camera}. {timeAgo(item.at)}
                </p>
                <Verdict who="Producción" alerted={item.champion.alerted} description={item.champion.description} />
                <Verdict who="Retador" alerted={item.candidate.alerted} description={item.candidate.description} />

                <div className="mt-auto border-t pt-3">
                    {item.truth !== null ? (
                        <p className="text-sm">
                            {item.truth ? 'Era una amenaza' : 'No era una amenaza'}: acertó el{' '}
                            <span className="font-semibold">{right?.toLowerCase()}</span>.
                            {item.truth_source === 'review' && (
                                <button
                                    type="button"
                                    onClick={() => label(!item.truth)}
                                    className="text-muted-foreground ml-2 underline underline-offset-4"
                                >
                                    Corregir
                                </button>
                            )}
                        </p>
                    ) : (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-semibold">¿Qué era?</span>
                            <Button size="sm" variant="secondary" onClick={() => label(true)}>
                                Una amenaza
                            </Button>
                            <Button size="sm" variant="secondary" onClick={() => label(false)}>
                                Nada para alertar
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </li>
    );
}
