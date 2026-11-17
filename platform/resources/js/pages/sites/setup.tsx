import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { siteNoun, timeAgo, type SiteKind } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePoll } from '@inertiajs/react';
import { CheckCircle2, Circle, Footprints } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Step {
    id: string;
    title: string;
    detail: string;
    done: boolean;
    optional: boolean;
    href: string | null;
}

interface TestResult {
    camera_id: number;
    camera: string;
    at: string | null;
    result: 'pending' | 'seen' | 'outside';
    detail: string;
    zones: string[];
}

interface Props {
    site: { id: number; name: string; kind: SiteKind };
    steps: Step[];
    testModeUntil: string | null;
    testResults: TestResult[];
}

function useCountdown(until: string | null): string | null {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        if (!until) return;
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, [until]);
    if (!until) return null;
    const seconds = Math.max(0, Math.round((new Date(until).getTime() - now) / 1000));
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

export default function SiteSetup({ site, steps, testModeUntil, testResults }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Propiedades', href: '/sites' },
        { title: site.name, href: `/sites/${site.id}` },
        { title: 'Instalación', href: `/sites/${site.id}/setup` },
    ];
    const required = steps.filter((step) => !step.optional);
    const done = required.filter((step) => step.done).length;
    const remaining = useCountdown(testModeUntil);
    const testing = remaining !== null && remaining !== '0:00';

    usePoll(testing ? 3000 : 15000, { only: ['steps', 'testModeUntil', 'testResults'] });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Instalación: ${site.name}`} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">Instalación de {site.name}</h1>
                    <p className="text-muted-foreground">
                        {done === required.length
                            ? `Listo: ${siteNoun(site.kind)} está vigilado de punta a punta.`
                            : `${done} de ${required.length} pasos hechos. Cada paso se marca solo cuando está bien.`}
                    </p>
                    <div className="bg-muted mt-3 h-2 overflow-hidden rounded-full">
                        <div
                            className="bg-ok h-full rounded-full transition-all"
                            style={{ width: `${(done / Math.max(1, required.length)) * 100}%` }}
                        />
                    </div>
                </div>

                <ol className="bg-card divide-y rounded-2xl border">
                    {steps.map((step, index) => (
                        <li key={step.id} className="flex items-start gap-3 px-4 py-3">
                            {step.done ? (
                                <CheckCircle2 className="text-ok mt-0.5 size-5 shrink-0" aria-label="Hecho" />
                            ) : (
                                <Circle className="text-muted-foreground mt-0.5 size-5 shrink-0" aria-label="Pendiente" />
                            )}
                            <div className="min-w-0 flex-1">
                                <p className={cn('font-semibold', step.done && 'text-muted-foreground')}>
                                    {index + 1}. {step.title}
                                    {step.optional && <span className="text-muted-foreground font-normal"> (opcional)</span>}
                                </p>
                                <p className="text-muted-foreground text-sm">{step.detail}</p>
                            </div>
                            {!step.done && step.href && (
                                <Link href={step.href} className="text-sm font-medium underline underline-offset-2">
                                    Hacerlo
                                </Link>
                            )}
                        </li>
                    ))}
                </ol>

                <section id="prueba" className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="flex items-center gap-2 text-xl font-semibold">
                                <Footprints className="size-5" /> Prueba de instalación
                            </h2>
                            <p className="text-muted-foreground max-w-xl text-sm">
                                Durante 15 minutos la IA analiza todo como siempre, sin horarios y sin avisar a nadie. Caminá frente a cada cámara y
                                mirá acá qué vio y en qué zona.
                            </p>
                        </div>
                        {testing ? (
                            <div className="flex items-center gap-3">
                                <span className="text-pending font-display text-2xl tabular-nums">{remaining}</span>
                                <Button variant="outline" onClick={() => router.delete(`/sites/${site.id}/test-mode`, { preserveScroll: true })}>
                                    Terminar la prueba
                                </Button>
                            </div>
                        ) : (
                            <Button onClick={() => router.post(`/sites/${site.id}/test-mode`, {}, { preserveScroll: true })}>
                                Empezar la prueba
                            </Button>
                        )}
                    </div>

                    {testing && (
                        <p className="border-pending/50 bg-pending/10 rounded-lg border px-4 py-2 text-sm">
                            Prueba en curso: si pasa algo real en este momento, la guardia no se entera. Terminala apenas termines de caminar.
                        </p>
                    )}

                    <ul className="grid gap-3 sm:grid-cols-2">
                        {testResults.map((result) => (
                            <li
                                key={result.camera_id}
                                className={cn(
                                    'rounded-xl border p-4',
                                    result.result === 'seen' && 'border-ok/60 bg-ok/5',
                                    result.result === 'outside' && 'border-pending/60 bg-pending/5',
                                )}
                            >
                                <p className="flex items-center justify-between gap-2 font-semibold">
                                    {result.camera}
                                    <span
                                        className={cn(
                                            'text-sm font-medium',
                                            result.result === 'seen'
                                                ? 'text-ok'
                                                : result.result === 'outside'
                                                  ? 'text-pending'
                                                  : 'text-muted-foreground',
                                        )}
                                    >
                                        {result.result === 'seen' ? 'Te vio' : result.result === 'outside' ? 'Fuera de zona' : 'Sin probar'}
                                    </span>
                                </p>
                                <p className="text-muted-foreground mt-1 text-sm">{result.detail}</p>
                                {result.at && (
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {result.zones.length > 0 ? `Zona ${result.zones.join(', ')}, ` : ''}
                                        {timeAgo(result.at)}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </AppLayout>
    );
}
