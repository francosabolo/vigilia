import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDuration } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Negocio', href: '/business' }];

interface SiteRow {
    id: number;
    name: string;
    client: string | null;
    cameras: number;
    fee: number;
    ai_calls: number;
    ai_cost_usd: number;
    ai_cost: number;
    alerts: number;
    escalated: number;
    saved_hours: number;
    saved_value: number;
    margin: number;
}

interface ClientRow {
    id: number;
    name: string;
    sites: number;
    cameras: number;
    fee: number;
    ai_cost: number;
    price_per_camera: number | null;
    sla_ack_seconds: number | null;
    sla: { answered_in_time: number; alerts: number; percent: number | null } | null;
}

interface Props {
    month: string;
    organization: {
        currency: string;
        price_per_camera: number | null;
        operator_hour_cost: number | null;
        usd_rate: number | null;
        brand_name: string | null;
        brand_color: string | null;
    };
    report: {
        sites: SiteRow[];
        clients: ClientRow[];
        totals: { fee: number; ai_cost: number; ai_cost_usd: number; saved_hours: number; margin: number };
    };
}

export default function Business({ month, organization, report }: Props) {
    const money = (value: number) => value.toLocaleString('es-AR', { style: 'currency', currency: organization.currency, maximumFractionDigits: 0 });
    const missing = organization.price_per_camera === null || organization.usd_rate === null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Negocio" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">Negocio</h1>
                        <p className="text-muted-foreground">Qué facturar a cada cliente, si se cumple lo prometido y cuánto deja cada propiedad.</p>
                    </div>
                    <Input
                        type="month"
                        aria-label="Mes"
                        value={month}
                        onChange={(e) => e.target.value && router.get('/business', { month: e.target.value }, { preserveState: true })}
                        className="w-44"
                    />
                </div>

                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    {[
                        [money(report.totals.fee), 'a facturar en el mes'],
                        [`${money(report.totals.ai_cost)} (US$ ${report.totals.ai_cost_usd})`, 'costo estimado de IA'],
                        [`${report.totals.saved_hours} h`, 'de operador ahorradas'],
                        [money(report.totals.margin), 'margen después de la IA'],
                    ].map(([value, label]) => (
                        <div key={label} className="bg-card flex flex-col-reverse rounded-xl border p-4">
                            <dt className="text-muted-foreground mt-1 text-sm">{label}</dt>
                            <dd className="font-display text-2xl font-semibold tabular-nums">{value}</dd>
                        </div>
                    ))}
                </dl>

                <Settings organization={organization} missing={missing} />

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Clientes</h2>
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted-foreground border-b">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">Cliente</th>
                                    <th className="px-4 py-2.5 font-medium">Cámaras</th>
                                    <th className="px-4 py-2.5 font-medium">Precio por cámara</th>
                                    <th className="px-4 py-2.5 font-medium">A facturar</th>
                                    <th className="px-4 py-2.5 font-medium">Respuesta prometida</th>
                                    <th className="px-4 py-2.5 font-medium">Cumplimiento</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {report.clients.map((client) => (
                                    <ClientRowView key={client.id} client={client} money={money} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Por propiedad</h2>
                    <div className="bg-card overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted-foreground border-b">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">Propiedad</th>
                                    <th className="px-4 py-2.5 font-medium">Cámaras</th>
                                    <th className="px-4 py-2.5 font-medium">Abono</th>
                                    <th className="px-4 py-2.5 font-medium">Análisis de IA</th>
                                    <th className="px-4 py-2.5 font-medium">Costo IA</th>
                                    <th className="px-4 py-2.5 font-medium">Alertas</th>
                                    <th className="px-4 py-2.5 font-medium">Horas ahorradas</th>
                                    <th className="px-4 py-2.5 font-medium">Margen</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {report.sites.map((site) => (
                                    <tr key={site.id}>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{site.name}</p>
                                            <p className="text-muted-foreground">{site.client ?? 'Sin cliente'}</p>
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">{site.cameras}</td>
                                        <td className="px-4 py-3 tabular-nums">{money(site.fee)}</td>
                                        <td className="px-4 py-3 tabular-nums">{site.ai_calls.toLocaleString('es-AR')}</td>
                                        <td className="px-4 py-3 tabular-nums">{money(site.ai_cost)}</td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {site.alerts}
                                            {site.escalated > 0 && <span className="text-muted-foreground"> ({site.escalated} a la central)</span>}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {site.saved_hours} h
                                            {site.saved_value > 0 && <span className="text-muted-foreground"> ({money(site.saved_value)})</span>}
                                        </td>
                                        <td className={cn('px-4 py-3 font-semibold tabular-nums', site.margin < 0 && 'text-alarm')}>
                                            {money(site.margin)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Los costos de IA son estimados: cada análisis por su costo promedio, convertido al tipo de cambio cargado. Las horas ahorradas
                        cuentan lo que tardaría un operador en mirar cada movimiento que la IA descartó.
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}

function Settings({ organization, missing }: { organization: Props['organization']; missing: boolean }) {
    const form = useForm({
        price_per_camera: organization.price_per_camera ?? '',
        operator_hour_cost: organization.operator_hour_cost ?? '',
        usd_rate: organization.usd_rate ?? '',
        brand_name: organization.brand_name ?? '',
        brand_color: organization.brand_color ?? '#e5252e',
    });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.patch('/business/settings', { preserveScroll: true });
            }}
            className={cn('grid gap-4 rounded-xl border p-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end', missing && 'border-pending bg-pending/5')}
        >
            {missing && (
                <p className="text-sm font-medium sm:col-span-4">
                    Cargá el precio por cámara y el tipo de cambio para ver la facturación y el margen.
                </p>
            )}
            <div className="grid gap-1.5">
                <Label htmlFor="price">Precio por cámara por mes ({organization.currency})</Label>
                <Input
                    id="price"
                    type="number"
                    min="0"
                    value={form.data.price_per_camera}
                    onChange={(e) => form.setData('price_per_camera', e.target.value === '' ? '' : Number(e.target.value))}
                />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="operator">Costo de una hora de operador ({organization.currency})</Label>
                <Input
                    id="operator"
                    type="number"
                    min="0"
                    value={form.data.operator_hour_cost}
                    onChange={(e) => form.setData('operator_hour_cost', e.target.value === '' ? '' : Number(e.target.value))}
                />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="usd">Dólar ({organization.currency} por US$ 1)</Label>
                <Input
                    id="usd"
                    type="number"
                    min="0"
                    value={form.data.usd_rate}
                    onChange={(e) => form.setData('usd_rate', e.target.value === '' ? '' : Number(e.target.value))}
                />
            </div>
            <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="brand-name">Nombre de tu empresa en la app (marca propia)</Label>
                <Input
                    id="brand-name"
                    placeholder="Ej: Seguridad del Norte"
                    value={form.data.brand_name}
                    onChange={(e) => form.setData('brand_name', e.target.value)}
                />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="brand-color">Color de tu marca</Label>
                <input
                    id="brand-color"
                    type="color"
                    value={form.data.brand_color}
                    onChange={(e) => form.setData('brand_color', e.target.value)}
                    className="h-9 w-full cursor-pointer rounded-md border"
                />
            </div>
            <Button type="submit" variant="outline" disabled={form.processing || !form.isDirty}>
                Guardar
            </Button>
        </form>
    );
}

function ClientRowView({ client, money }: { client: ClientRow; money: (value: number) => string }) {
    const form = useForm({ price_per_camera: client.price_per_camera ?? '', sla_ack_seconds: client.sla_ack_seconds ?? '' });
    const save = () =>
        router.patch(
            `/business/clients/${client.id}`,
            {
                price_per_camera: form.data.price_per_camera === '' ? null : form.data.price_per_camera,
                sla_ack_seconds: form.data.sla_ack_seconds === '' ? null : form.data.sla_ack_seconds,
            },
            { preserveScroll: true },
        );

    return (
        <tr>
            <td className="px-4 py-3">
                <p className="font-medium">{client.name}</p>
                <p className="text-muted-foreground">{client.sites === 1 ? '1 propiedad' : `${client.sites} propiedades`}</p>
            </td>
            <td className="px-4 py-3 tabular-nums">{client.cameras}</td>
            <td className="px-4 py-3">
                <Input
                    aria-label={`Precio por cámara de ${client.name}`}
                    type="number"
                    min="0"
                    placeholder="El general"
                    value={form.data.price_per_camera}
                    onChange={(e) => form.setData('price_per_camera', e.target.value === '' ? '' : Number(e.target.value))}
                    onBlur={() => form.isDirty && save()}
                    className="h-8 w-32"
                />
            </td>
            <td className="px-4 py-3 font-semibold tabular-nums">{money(client.fee)}</td>
            <td className="px-4 py-3">
                <select
                    aria-label={`Respuesta prometida a ${client.name}`}
                    value={form.data.sla_ack_seconds}
                    onChange={(e) => {
                        form.setData('sla_ack_seconds', e.target.value === '' ? '' : Number(e.target.value));
                        router.patch(
                            `/business/clients/${client.id}`,
                            {
                                price_per_camera: form.data.price_per_camera === '' ? null : form.data.price_per_camera,
                                sla_ack_seconds: e.target.value === '' ? null : Number(e.target.value),
                            },
                            { preserveScroll: true },
                        );
                    }}
                    className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                >
                    <option value="">Sin SLA</option>
                    {[60, 120, 180, 300, 600].map((seconds) => (
                        <option key={seconds} value={seconds}>
                            {formatDuration(seconds)}
                        </option>
                    ))}
                </select>
            </td>
            <td className="px-4 py-3">
                {client.sla === null ? (
                    <span className="text-muted-foreground">—</span>
                ) : client.sla.percent === null ? (
                    <span className="text-muted-foreground">Sin alertas</span>
                ) : (
                    <span
                        className={cn(
                            'font-semibold tabular-nums',
                            client.sla.percent >= 95 ? 'text-ok' : client.sla.percent >= 80 ? 'text-pending' : 'text-alarm',
                        )}
                    >
                        {client.sla.percent}%{' '}
                        <span className="text-muted-foreground font-normal">
                            ({client.sla.answered_in_time} de {client.sla.alerts})
                        </span>
                    </span>
                )}
            </td>
        </tr>
    );
}
