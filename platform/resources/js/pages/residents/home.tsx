import AppLogoIcon from '@/components/app-logo-icon';
import { IconCircle } from '@/components/icon-circle';
import { PushToggle } from '@/components/push-toggle';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDuration, theSite, timeAgo, unitNoun, type IncidentStatus, type Metrics, type SiteKind } from '@/lib/seguria';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Bell, CheckCircle2, Eye, LogOut, Plane, ShieldCheck, Siren } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

interface Notice {
    id: number;
    kind: 'detection' | 'resident_report' | 'panic';
    mine: boolean;
    sector: string | null;
    status: IncidentStatus;
    resolution: 'confirmed' | 'false_alarm' | null;
    detected_at: string;
}

interface Props {
    resident: { name: string; lot: string | null; sector: string | null; away_until: string | null; site: string | null; site_kind: SiteKind };
    sectors: string[];
    notices: Notice[];
    metrics: Metrics;
    vapidPublicKey: string | null;
}

function noticeText(notice: Notice): string {
    const where = notice.sector ? ` en el sector ${notice.sector}` : '';
    if (notice.kind === 'panic') return notice.mine ? 'Activaste el botón de pánico' : `Alerta de pánico${where}`;
    if (notice.kind === 'resident_report') return notice.mine ? `Tu aviso${where}` : `Aviso de un vecino${where}`;
    return `Alerta de perímetro${where}`;
}

function noticeStatus(notice: Notice): string {
    if (notice.status === 'resolved') return notice.resolution === 'false_alarm' ? 'Cerrado: era una falsa alarma' : 'Cerrado: la guardia intervino';
    if (notice.status === 'acknowledged') return 'La guardia está en camino';
    return 'La guardia fue avisada';
}

function PanicButton() {
    const [armed, setArmed] = useState(false);
    const [sent, setSent] = useState(false);

    useEffect(() => {
        if (!armed) return;
        const timeout = setTimeout(() => setArmed(false), 5000);
        return () => clearTimeout(timeout);
    }, [armed]);

    if (sent) {
        return (
            <div className="bg-alarm flex flex-col items-center gap-2 rounded-2xl p-6 text-center text-white">
                <CheckCircle2 className="size-10" />
                <p className="text-lg font-semibold">Alerta enviada</p>
                <p className="text-sm text-white/90">La guardia y la central ya fueron avisadas.</p>
            </div>
        );
    }

    return (
        <button
            onClick={() => {
                if (!armed) return setArmed(true);
                router.post('/vecinos/panic', {}, { preserveScroll: true, onSuccess: () => setSent(true) });
            }}
            className={`bg-alarm flex min-h-40 w-full flex-col items-center justify-center gap-2 rounded-2xl p-6 text-white transition-[filter] active:brightness-90 ${armed ? 'ring-alarm/40 ring-8' : ''}`}
        >
            <Siren className="size-12" />
            <span className="font-display text-3xl font-semibold">{armed ? 'Tocá de nuevo para confirmar' : 'Botón de pánico'}</span>
            <span className="text-sm text-white/90">{armed ? 'Se cancela solo en 5 segundos' : 'Avisa al instante a la guardia y a la central'}</span>
        </button>
    );
}

function ReportForm({ sectors, defaultSector }: { sectors: string[]; defaultSector: string | null }) {
    const [sent, setSent] = useState(false);
    const form = useForm({ sector: defaultSector ?? sectors[0] ?? '', notes: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/vecinos/report', {
            preserveScroll: true,
            onSuccess: () => {
                setSent(true);
                form.reset('notes');
            },
        });
    }

    if (sent) {
        return (
            <div className="flex items-start gap-3 text-sm">
                <CheckCircle2 className="text-ok size-5 shrink-0" />
                <div>
                    <p className="font-medium">Gracias, recibimos tu aviso.</p>
                    <p className="text-muted-foreground">La guardia ya lo tiene y la IA está revisando las cámaras de ese sector.</p>
                    <button className="text-primary mt-2 underline" onClick={() => setSent(false)}>
                        Enviar otro aviso
                    </button>
                </div>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <select
                value={form.data.sector}
                onChange={(e) => form.setData('sector', e.target.value)}
                className="border-input bg-background rounded-lg border px-3 py-3 text-base"
            >
                {sectors.map((sector) => (
                    <option key={sector} value={sector}>
                        {sector}
                    </option>
                ))}
            </select>
            <textarea
                value={form.data.notes}
                onChange={(e) => form.setData('notes', e.target.value)}
                placeholder="¿Qué viste? (opcional) Ej: una persona caminando por el lado de afuera del alambrado"
                rows={3}
                maxLength={500}
                className="border-input bg-background rounded-lg border px-3 py-3 text-base"
            />
            {form.errors.sector && <p className="text-alarm text-sm">{form.errors.sector}</p>}
            <Button size="lg" className="h-12 text-base" disabled={form.processing || !form.data.sector}>
                Enviar aviso a la guardia
            </Button>
        </form>
    );
}

function AwayMode({ awayUntil, hasLot }: { awayUntil: string | null; hasLot: boolean }) {
    const form = useForm({ away_until: awayUntil ?? '' });

    if (!hasLot) {
        return <p className="text-muted-foreground text-sm">Pedile a la administración que cargue tu unidad o lote para usar el modo vacaciones.</p>;
    }

    if (awayUntil) {
        return (
            <div className="flex flex-col gap-3 text-sm">
                <p>
                    <span className="font-medium">Activado hasta el {new Date(awayUntil + 'T12:00').toLocaleDateString('es-AR')}.</span> Cualquier
                    persona que la IA vea en tu lote genera una alerta a la guardia.
                </p>
                <Button variant="outline" onClick={() => router.put('/vecinos/away', { away_until: null }, { preserveScroll: true })}>
                    Ya volví, desactivar
                </Button>
            </div>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put('/vecinos/away', { preserveScroll: true });
            }}
            className="flex flex-col gap-2 sm:flex-row"
        >
            <input
                type="date"
                value={form.data.away_until}
                min={new Date(Date.now() + 86400000).toISOString().slice(0, 10)}
                onChange={(e) => form.setData('away_until', e.target.value)}
                className="border-input bg-background flex-1 rounded-lg border px-3 py-3 text-base"
                required
            />
            <Button variant="secondary" disabled={form.processing || !form.data.away_until}>
                Activar hasta esa fecha
            </Button>
            {form.errors.away_until && <p className="text-alarm text-sm">{form.errors.away_until}</p>}
        </form>
    );
}

export default function ResidentHome({ resident, sectors, notices, metrics, vapidPublicKey }: Props) {
    usePoll(15000, { only: ['notices'] });

    return (
        <div className="theme-vecinos min-h-screen text-[15px]">
            <Head title="Vecinos" />
            <header className="bg-card flex items-center gap-3 border-b px-4 py-3">
                <AppLogoIcon className="text-brand size-7" />
                <div className="min-w-0 flex-1">
                    <div className="font-display truncate text-lg font-semibold">{resident.site}</div>
                    <div className="text-muted-foreground truncate text-sm">
                        {resident.name}
                        {resident.lot && `, ${unitNoun(resident.site_kind)} ${resident.lot}`}
                    </div>
                </div>
                <Link href="/logout" method="post" as="button" className="text-muted-foreground" aria-label="Salir">
                    <LogOut className="size-5" />
                </Link>
            </header>

            <main className="mx-auto flex max-w-xl flex-col gap-4 p-4">
                <PanicButton />

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-3 text-lg">
                            <IconCircle icon={Eye} /> Vi algo raro
                        </CardTitle>
                        <CardDescription>Avisás a la guardia y la IA revisa al instante las cámaras de ese sector.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {sectors.length ? (
                            <ReportForm sectors={sectors} defaultSector={resident.sector} />
                        ) : (
                            <p className="text-muted-foreground text-sm">Todavía no hay sectores configurados.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-3 text-lg">
                            <IconCircle icon={Plane} /> Modo vacaciones
                        </CardTitle>
                        <CardDescription>Mientras no estás, tu casa queda vigilada las 24 h.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <AwayMode awayUntil={resident.away_until} hasLot={!!resident.lot} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-3 text-lg">
                            <IconCircle icon={Bell} /> Avisos de tu sector
                        </CardTitle>
                        <CardDescription>Últimos 7 días</CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <PushToggle vapidPublicKey={vapidPublicKey} />
                        {notices.length === 0 && <p className="text-muted-foreground text-sm">Sin novedades en tu sector esta semana.</p>}
                        {notices.map((notice) => (
                            <div key={notice.id} className="flex items-start gap-3 border-t pt-3 text-sm first:border-t-0">
                                <span className={`mt-1.5 size-2.5 shrink-0 rounded-full ${notice.status === 'resolved' ? 'bg-ok' : 'bg-pending'}`} />
                                <div className="flex-1">
                                    <div className="font-medium">{noticeText(notice)}</div>
                                    <div className="text-muted-foreground">
                                        {noticeStatus(notice)}, {timeAgo(notice.detected_at)}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-3 text-lg">
                            <IconCircle icon={ShieldCheck} /> Así cuidamos {theSite(resident.site_kind)}
                        </CardTitle>
                        <CardDescription>Últimos 30 días</CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <div className="font-display text-4xl font-semibold tabular-nums">{metrics.detections}</div>movimientos analizados por la
                            IA
                        </div>
                        <div>
                            <div className="font-display text-4xl font-semibold tabular-nums">{metrics.incidents}</div>alertas a la guardia
                        </div>
                        <div>
                            <div className="font-display text-4xl font-semibold tabular-nums">{metrics.confirmed}</div>intrusiones frenadas
                        </div>
                        <div>
                            <div className="font-display text-4xl font-semibold tabular-nums">{formatDuration(metrics.avg_ack_seconds)}</div>respuesta
                            promedio
                        </div>
                    </CardContent>
                </Card>

                <p className="text-muted-foreground px-2 text-center text-xs">
                    Por privacidad, en esta app no se ven cámaras ni imágenes de personas. Las imágenes solo las ve el personal de seguridad.
                </p>
            </main>
        </div>
    );
}
