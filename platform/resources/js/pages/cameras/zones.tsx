import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { objectLabels } from '@/lib/seguria';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePoll } from '@inertiajs/react';
import { CheckCircle2, Loader2, RotateCcw, Trash2, Undo2, VideoOff } from 'lucide-react';
import { useRef, useState, type FormEvent, type MouseEvent } from 'react';

type Point = [number, number];

interface ZoneData {
    id: number;
    name: string;
    frigate_zone: string;
    lot: string | null;
    watched_labels: string[];
    coordinates: Point[];
    schedules: { id: number; days: number[]; starts_at: string; ends_at: string }[];
}

interface Props {
    camera: { id: number; name: string; sector: string | null; site: { id: number; name: string }; preview_url: string | null };
    zones: ZoneData[];
    synced: boolean | null;
    interval: number;
}

const COLORS = ['#ffffff', '#f5a524', '#34c77b', '#7aa2ff', '#c084fc', '#f472b6'];
const DAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

function Schedules({ zone }: { zone: ZoneData }) {
    const form = useForm({ days: [1, 2, 3, 4, 5, 6, 7], starts_at: '20:00', ends_at: '07:00' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/zones/${zone.id}/schedules`, { preserveScroll: true });
    }

    return (
        <div className="flex flex-col gap-2 border-t pt-3">
            <div className="text-sm font-medium">Cuándo está armada</div>
            {zone.schedules.length === 0 && <p className="text-muted-foreground text-sm">Las 24 h, todos los días. Agregá un horario si solo hay que vigilarla de noche.</p>}
            {zone.schedules.map((schedule) => (
                <div key={schedule.id} className="flex items-center gap-2 text-sm">
                    <span>{schedule.days.length === 7 ? 'Todos los días' : schedule.days.map((day) => DAYS[day - 1]).join(' ')}</span>
                    <span className="tabular-nums">
                        de {schedule.starts_at} a {schedule.ends_at}
                    </span>
                    <Button size="icon" variant="ghost" className="ml-auto size-7" aria-label="Quitar horario" onClick={() => router.delete(`/schedules/${schedule.id}`, { preserveScroll: true })}>
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            ))}
            <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                <div className="flex gap-0.5">
                    {DAYS.map((day, index) => {
                        const iso = index + 1;
                        const active = form.data.days.includes(iso);
                        return (
                            <button
                                type="button"
                                key={day}
                                aria-pressed={active}
                                onClick={() => form.setData('days', active ? form.data.days.filter((d) => d !== iso) : [...form.data.days, iso].sort())}
                                className={cn('size-7 rounded text-xs', active ? 'bg-primary text-primary-foreground' : 'border')}
                            >
                                {day}
                            </button>
                        );
                    })}
                </div>
                <Input type="time" aria-label="Desde" className="w-28" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                <Input type="time" aria-label="Hasta" className="w-28" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                <Button size="sm" variant="secondary" disabled={form.processing || form.data.days.length === 0}>
                    Agregar horario
                </Button>
                <InputError message={form.errors.days || form.errors.ends_at} />
            </form>
        </div>
    );
}

export default function ZoneEditor({ camera, zones, synced, interval }: Props) {
    // Keeps the edge sending frames while the editor is open.
    usePoll(interval * 1000, { only: ['camera', 'synced'] }, { keepAlive: true });

    const [editingId, setEditingId] = useState<number | 'new' | null>(zones.length ? null : 'new');
    const editing = typeof editingId === 'number' ? zones.find((zone) => zone.id === editingId) : undefined;
    const svgRef = useRef<SVGSVGElement>(null);

    const form = useForm({
        name: '',
        lot: '',
        watched_labels: ['person', 'car', 'motorcycle'] as string[],
        coordinates: [] as Point[],
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Barrios', href: '/sites' },
        { title: camera.site.name, href: `/sites/${camera.site.id}` },
        { title: `Zonas de ${camera.name}`, href: `/cameras/${camera.id}/zones` },
    ];

    function startEditing(zone: ZoneData | null) {
        setEditingId(zone ? zone.id : 'new');
        form.clearErrors();
        form.setData({
            name: zone?.name ?? '',
            lot: zone?.lot ?? '',
            watched_labels: zone?.watched_labels.length ? zone.watched_labels : ['person', 'car', 'motorcycle'],
            coordinates: zone?.coordinates ?? [],
        });
    }

    function addPoint(event: MouseEvent<SVGSVGElement>) {
        if (editingId === null || !svgRef.current) return;
        const rect = svgRef.current.getBoundingClientRect();
        const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
        const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
        form.setData('coordinates', [...form.data.coordinates, [Number(x.toFixed(4)), Number(y.toFixed(4))]]);
    }

    function save() {
        const options = { preserveScroll: true, onSuccess: () => setEditingId(null) };
        if (editing) {
            form.patch(`/zones/${editing.id}`, options);
        } else {
            form.post(`/cameras/${camera.id}/zones`, options);
        }
    }

    function toggleLabel(label: string) {
        const labels = form.data.watched_labels;
        form.setData('watched_labels', labels.includes(label) ? labels.filter((l) => l !== label) : [...labels, label]);
    }

    const toPoints = (points: Point[]) => points.map(([x, y]) => `${x * 100},${y * 100}`).join(' ');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Zonas de ${camera.name}`} />
            <div className="grid gap-4 p-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="font-display text-3xl font-semibold">{camera.name}</h1>
                        {camera.sector && <span className="text-muted-foreground">{camera.sector}</span>}
                        <span className="ml-auto flex items-center gap-1 text-sm">
                            {synced === null ? (
                                <span className="text-muted-foreground">Sin edge asignado</span>
                            ) : synced ? (
                                <><CheckCircle2 className="size-4 text-ok" /> Zonas aplicadas en el edge</>
                            ) : (
                                <><Loader2 className="size-4 animate-spin text-pending" /> Aplicando cambios en el edge…</>
                            )}
                        </span>
                    </div>

                    <div className="relative aspect-video overflow-hidden rounded-xl bg-black select-none">
                        {camera.preview_url ? (
                            <img src={camera.preview_url} alt={camera.name} className="absolute inset-0 size-full object-cover" draggable={false} />
                        ) : (
                            <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-muted-foreground">
                                <VideoOff className="size-8" />
                                <span className="text-sm">Pidiendo imagen al edge… (podés dibujar igual)</span>
                            </div>
                        )}
                        <svg
                            ref={svgRef}
                            viewBox="0 0 100 100"
                            preserveAspectRatio="none"
                            className={cn('absolute inset-0 size-full', editingId !== null && 'cursor-crosshair')}
                            onClick={addPoint}
                        >
                            {zones.filter((zone) => zone.id !== editingId).map((zone, index) => (
                                <polygon key={zone.id} points={toPoints(zone.coordinates)} fill={COLORS[index % COLORS.length]} fillOpacity={0.25} stroke={COLORS[index % COLORS.length]} strokeWidth={0.4} vectorEffect="non-scaling-stroke" />
                            ))}
                            {editingId !== null && form.data.coordinates.length > 0 && (
                                <>
                                    <polygon points={toPoints(form.data.coordinates)} fill="#facc15" fillOpacity={0.3} stroke="#facc15" strokeWidth={2} vectorEffect="non-scaling-stroke" strokeDasharray="4 2" />
                                    {form.data.coordinates.map(([x, y], index) => (
                                        <circle key={index} cx={x * 100} cy={y * 100} r={0.8} fill="#facc15" stroke="#000" strokeWidth={0.2} />
                                    ))}
                                </>
                            )}
                        </svg>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {editingId !== null
                            ? 'Hacé click sobre la imagen para marcar los vértices del área vigilada (por ejemplo, la franja del alambrado). Mínimo 3 puntos.'
                            : 'Elegí una zona para editarla o creá una nueva.'}
                    </p>
                </div>

                <div className="flex flex-col gap-4">
                    {editingId !== null ? (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">{editing ? `Editar ${editing.name}` : 'Nueva zona'}</CardTitle>
                                <CardDescription>{form.data.coordinates.length} punto(s) marcados</CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                <Input placeholder="Nombre (ej. Alambrado norte)" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                <InputError message={form.errors.name} />
                                <Input placeholder="Lote que cubre (opcional, para modo vacaciones)" value={form.data.lot} onChange={(e) => form.setData('lot', e.target.value)} />
                                <div>
                                    <div className="mb-1 text-sm font-medium">Qué vigilar</div>
                                    <div className="flex flex-wrap gap-1">
                                        {Object.entries(objectLabels).map(([value, label]) => (
                                            <button type="button" key={value} onClick={() => toggleLabel(value)} className={cn('rounded-full border px-2 py-0.5 text-xs', form.data.watched_labels.includes(value) && 'bg-primary text-primary-foreground')}>
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                                <InputError message={form.errors.coordinates} />
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" onClick={() => form.setData('coordinates', form.data.coordinates.slice(0, -1))} disabled={!form.data.coordinates.length}>
                                        <Undo2 className="mr-1 size-4" /> Deshacer punto
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => form.setData('coordinates', [])} disabled={!form.data.coordinates.length}>
                                        <RotateCcw className="mr-1 size-4" /> Borrar dibujo
                                    </Button>
                                </div>
                                {editing && <Schedules zone={editing} />}
                                <div className="flex gap-2">
                                    <Button onClick={save} disabled={form.processing || form.data.coordinates.length < 3 || !form.data.name}>Guardar zona</Button>
                                    <Button variant="ghost" onClick={() => setEditingId(null)}>Cancelar</Button>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        <Button onClick={() => startEditing(null)}>+ Nueva zona</Button>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Zonas de esta cámara</CardTitle>
                            <CardDescription>Solo se analizan detecciones dentro de estas áreas. Sin zonas, se vigila la imagen completa.</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {zones.length === 0 && <p className="text-muted-foreground text-sm">Todavía no hay zonas.</p>}
                            {zones.map((zone, index) => (
                                <div key={zone.id} className={cn('flex items-center gap-2 rounded-lg border p-2 text-sm', editingId === zone.id && 'border-primary')}>
                                    <span className="size-3 rounded-sm" style={{ background: COLORS[index % COLORS.length] }} />
                                    <button className="flex-1 text-left font-medium" onClick={() => startEditing(zone)}>
                                        {zone.name}
                                        {zone.lot && <span className="text-muted-foreground font-normal">, lote {zone.lot}</span>}
                                        {zone.coordinates.length < 3 && <span className="text-pending font-normal">, falta dibujarla</span>}
                                    </button>
                                    <Button size="icon" variant="ghost" className="size-7" onClick={() => router.delete(`/zones/${zone.id}`, { preserveScroll: true })}>
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
