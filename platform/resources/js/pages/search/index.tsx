import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo, type ActivityItem } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Buscar', href: '/search' }];

interface Props {
    q: string;
    days: number;
    results: {
        detections: ActivityItem[];
        incidents: { id: number; title: string; site: string; description: string | null; at: string }[];
        entries: { id: number; name: string; plate: string | null; status: string; kind_label: string; photo_url: string | null; at: string }[];
        vehicles: {
            id: number;
            plate: string;
            make_model: string | null;
            color: string | null;
            owner: string;
            lot: string | null;
            phone: string | null;
            site: string | null;
            site_id: number;
        }[];
    };
}

const EXAMPLES = ['campera roja', 'mochila', 'auto blanco', 'moto', 'dos personas'];

export default function SearchPage({ q, days, results }: Props) {
    const [query, setQuery] = useState(q);
    const total = results.detections.length + results.incidents.length + results.entries.length + results.vehicles.length;
    const search = (value: string, period = days) =>
        router.get('/search', { q: value || undefined, days: period }, { preserveState: true, replace: true });

    useEffect(() => {
        if (query === q) return;
        const timer = setTimeout(() => search(query), 400);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Buscar" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">Buscar en lo grabado</h1>
                    <p className="text-muted-foreground">
                        Busca en lo que la IA describió de cada detección, en los incidentes, en el registro de accesos y en las patentes de los
                        vecinos. Tienen que aparecer todas las palabras.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <div className="relative min-w-64 flex-1">
                        <Search className="text-muted-foreground absolute top-3 left-3 size-4" />
                        <Input
                            aria-label="Qué buscás"
                            placeholder="Ej: hombre con campera roja"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            className="h-10 pl-9"
                            autoFocus
                        />
                    </div>
                    <select
                        aria-label="Período"
                        value={days}
                        onChange={(e) => search(query, Number(e.target.value))}
                        className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                    >
                        <option value={1}>Últimas 24 horas</option>
                        <option value={7}>Últimos 7 días</option>
                        <option value={30}>Últimos 30 días</option>
                        <option value={90}>Últimos 90 días</option>
                    </select>
                </div>

                {!q ? (
                    <p className="text-muted-foreground text-sm">
                        Probá con:{' '}
                        {EXAMPLES.map((example, index) => (
                            <span key={example}>
                                {index > 0 && ', '}
                                <button type="button" className="underline underline-offset-2" onClick={() => setQuery(example)}>
                                    {example}
                                </button>
                            </span>
                        ))}
                        .
                    </p>
                ) : total === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center">Nada coincide con «{q}» en este período.</p>
                ) : (
                    <>
                        {results.vehicles.length > 0 && (
                            <section className="flex flex-col gap-3">
                                <h2 className="font-semibold">Vehículos de vecinos ({results.vehicles.length})</h2>
                                <ul className="bg-card divide-y rounded-xl border text-sm">
                                    {results.vehicles.map((vehicle) => (
                                        <li key={vehicle.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                                            <span className="font-display w-24 text-lg font-semibold tracking-wider">{vehicle.plate}</span>
                                            <span className="flex-1">
                                                <span className="font-medium">{vehicle.owner}</span>
                                                <span className="text-muted-foreground">
                                                    {vehicle.lot && `, ${vehicle.lot}`}, {vehicle.site}
                                                    {(vehicle.make_model || vehicle.color) &&
                                                        `. ${[vehicle.make_model, vehicle.color].filter(Boolean).join(', ')}`}
                                                </span>
                                            </span>
                                            {vehicle.phone && (
                                                <a href={`tel:${vehicle.phone}`} className="underline underline-offset-2">
                                                    Llamar
                                                </a>
                                            )}
                                            <Link href={`/sites/${vehicle.site_id}?tab=vecinos`} className="underline underline-offset-2">
                                                Ver en Vecinos
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                        {results.detections.length > 0 && (
                            <section className="flex flex-col gap-3">
                                <h2 className="font-semibold">Detecciones ({results.detections.length})</h2>
                                <ul className="grid gap-3 sm:grid-cols-2">
                                    {results.detections.map((item) => (
                                        <li key={item.id} className="bg-card flex gap-3 rounded-xl border p-3">
                                            {item.snapshot_url ? (
                                                <img
                                                    src={item.snapshot_url}
                                                    alt=""
                                                    className="aspect-video w-32 shrink-0 rounded object-cover"
                                                    loading="lazy"
                                                />
                                            ) : (
                                                <span className="bg-muted aspect-video w-32 shrink-0 rounded" />
                                            )}
                                            <div className="min-w-0 text-sm">
                                                <p className="font-medium">{item.headline}</p>
                                                <p className="text-muted-foreground">
                                                    {item.camera} ({item.site}), {timeAgo(item.at)}
                                                </p>
                                                {item.incident_id && (
                                                    <Link href={`/incidents/${item.incident_id}`} className="underline underline-offset-2">
                                                        Ver el incidente
                                                    </Link>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                        {results.incidents.length > 0 && (
                            <section className="flex flex-col gap-3">
                                <h2 className="font-semibold">Incidentes ({results.incidents.length})</h2>
                                <ul className="bg-card divide-y rounded-xl border text-sm">
                                    {results.incidents.map((incident) => (
                                        <li key={incident.id}>
                                            <Link href={`/incidents/${incident.id}`} className="hover:bg-accent block px-4 py-3">
                                                <p className="font-medium">{incident.title}</p>
                                                <p className="text-muted-foreground">
                                                    {incident.site}, {timeAgo(incident.at)}
                                                    {incident.description && `. ${incident.description}`}
                                                </p>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                        {results.entries.length > 0 && (
                            <section className="flex flex-col gap-3">
                                <h2 className="font-semibold">Accesos ({results.entries.length})</h2>
                                <ul className="bg-card divide-y rounded-xl border text-sm">
                                    {results.entries.map((entry) => (
                                        <li key={entry.id} className="flex items-center gap-3 px-4 py-2.5">
                                            {entry.photo_url && (
                                                <img src={entry.photo_url} alt="" className="aspect-video w-20 rounded object-cover" />
                                            )}
                                            <span className="flex-1">
                                                {entry.name} <span className="text-muted-foreground">({entry.kind_label.toLowerCase()})</span>
                                                {entry.plate && <span className="text-muted-foreground">, patente {entry.plate}</span>}
                                            </span>
                                            <span className={cn(entry.status === 'allowed' ? 'text-ok' : 'text-alarm')}>
                                                {entry.status === 'allowed' ? 'Pasó' : 'No pasó'}
                                            </span>
                                            <span className="text-muted-foreground">{timeAgo(entry.at)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
