import InputError from '@/components/input-error';
import { ItemActionButton, ItemActions } from '@/components/item-action';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { objectLabels, plural, zoneKindLabel, zoneKinds, type ZoneKind } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePoll } from '@inertiajs/react';
import { AppWindow, CheckCircle2, DoorOpen, Fence, Loader2, RotateCcw, Target, Trash2, Undo2, VideoOff, X, type LucideIcon } from 'lucide-react';
import { useRef, useState, type FormEvent, type MouseEvent, type PointerEvent } from 'react';

type Point = [number, number];

interface ZoneData {
    id: number;
    name: string;
    frigate_zone: string;
    lot: string | null;
    kind: ZoneKind;
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

/**
 * Adds a vertex where it disturbs the shape the least: on the closest side (cheapest insertion),
 * so the polygon never crosses itself just because points were clicked out of order.
 */
function insertPoint(points: Point[], point: Point): Point[] {
    if (points.length < 3) return [...points, point];
    // Measure in a 16:9 space so horizontal and vertical distances compare fairly.
    const distance = (a: Point, b: Point) => Math.hypot((a[0] - b[0]) * 16, (a[1] - b[1]) * 9);
    let best = 0;
    let bestCost = Infinity;
    points.forEach((current, i) => {
        const next = points[(i + 1) % points.length];
        const cost = distance(current, point) + distance(point, next) - distance(current, next);
        if (cost < bestCost) {
            bestCost = cost;
            best = i;
        }
    });
    return [...points.slice(0, best + 1), point, ...points.slice(best + 1)];
}

/** Thumbnail of the zone's actual shape, so the row reads as "this area", not as a checkbox. */
function ZoneShape({ points, color }: { points: Point[]; color: string }) {
    return (
        <svg viewBox="0 0 32 18" className="bg-surface-2 h-[18px] w-8 shrink-0 rounded-sm" aria-hidden>
            {points.length >= 3 ? (
                <polygon
                    points={points.map(([x, y]) => `${x * 32},${y * 18}`).join(' ')}
                    fill={color}
                    fillOpacity={0.35}
                    stroke={color}
                    strokeWidth={1.2}
                />
            ) : (
                <rect x={1} y={1} width={30} height={16} fill="none" stroke={color} strokeWidth={1} strokeDasharray="2 2" />
            )}
        </svg>
    );
}

function DeleteZone({ zone }: { zone: ZoneData }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="delete" aria-label={`Borrar zona ${zone.name}`} />
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Borrar la zona {zone.name}?</DialogTitle>
                    <DialogDescription>
                        Se borran el dibujo y sus horarios. Si era la única zona de la cámara, se pasa a vigilar la imagen completa.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/zones/${zone.id}`, { preserveScroll: true })}>
                        Borrar zona
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const KIND_ICONS: Record<ZoneKind, LucideIcon> = { perimeter: Fence, door: DoorOpen, window: AppWindow, sensitive: Target };

const COLORS = ['#7aa2ff', '#34c77b', '#c084fc', '#f472b6', '#38bdf8', '#a3e635'];
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
            {zone.schedules.length === 0 && (
                <p className="text-muted-foreground text-sm">Las 24 h, todos los días. Agregá un horario si solo hay que vigilarla de noche.</p>
            )}
            {zone.schedules.map((schedule) => (
                <div key={schedule.id} className="flex items-center gap-2 text-sm">
                    <span>{schedule.days.length === 7 ? 'Todos los días' : schedule.days.map((day) => DAYS[day - 1]).join(' ')}</span>
                    <span className="tabular-nums">
                        de {schedule.starts_at} a {schedule.ends_at}
                    </span>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="ml-auto size-7"
                        aria-label="Quitar horario"
                        onClick={() => router.delete(`/schedules/${schedule.id}`, { preserveScroll: true })}
                    >
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
                                onClick={() =>
                                    form.setData('days', active ? form.data.days.filter((d) => d !== iso) : [...form.data.days, iso].sort())
                                }
                                className={cn('size-7 rounded text-xs', active ? 'bg-primary text-primary-foreground' : 'border')}
                            >
                                {day}
                            </button>
                        );
                    })}
                </div>
                <Input
                    type="time"
                    aria-label="Desde"
                    className="w-28"
                    value={form.data.starts_at}
                    onChange={(e) => form.setData('starts_at', e.target.value)}
                />
                <Input
                    type="time"
                    aria-label="Hasta"
                    className="w-28"
                    value={form.data.ends_at}
                    onChange={(e) => form.setData('ends_at', e.target.value)}
                />
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
    const canvasRef = useRef<HTMLDivElement>(null);
    const draggedRef = useRef(false);
    const [dragIndex, setDragIndex] = useState<number | null>(null);

    const form = useForm({
        name: '',
        kind: 'perimeter' as ZoneKind,
        lot: '',
        watched_labels: ['person', 'car', 'motorcycle'] as string[],
        coordinates: [] as Point[],
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Propiedades', href: '/sites' },
        { title: camera.site.name, href: `/sites/${camera.site.id}` },
        { title: `Zonas de ${camera.name}`, href: `/cameras/${camera.id}/zones` },
    ];

    function startEditing(zone: ZoneData | null) {
        setEditingId(zone ? zone.id : 'new');
        form.clearErrors();
        form.setData({
            name: zone?.name ?? '',
            kind: zone?.kind ?? 'perimeter',
            lot: zone?.lot ?? '',
            watched_labels: zone?.watched_labels.length ? zone.watched_labels : ['person', 'car', 'motorcycle'],
            coordinates: zone?.coordinates ?? [],
        });
    }

    function toPoint(clientX: number, clientY: number): Point {
        const rect = canvasRef.current!.getBoundingClientRect();
        const x = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
        const y = Math.min(1, Math.max(0, (clientY - rect.top) / rect.height));
        return [Number(x.toFixed(4)), Number(y.toFixed(4))];
    }

    function addPoint(event: MouseEvent<HTMLDivElement>) {
        if (editingId === null || !canvasRef.current) return;
        if (draggedRef.current) {
            draggedRef.current = false;
            return;
        }
        form.setData('coordinates', insertPoint(form.data.coordinates, toPoint(event.clientX, event.clientY)));
    }

    function removePoint(index: number) {
        form.setData(
            'coordinates',
            form.data.coordinates.filter((_, i) => i !== index),
        );
    }

    function startDrag(index: number, event: PointerEvent<HTMLButtonElement>) {
        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        setDragIndex(index);
    }

    function drag(event: PointerEvent<HTMLButtonElement>) {
        if (dragIndex === null) return;
        draggedRef.current = true;
        const point = toPoint(event.clientX, event.clientY);
        form.setData(
            'coordinates',
            form.data.coordinates.map((current, i) => (i === dragIndex ? point : current)),
        );
    }

    function endDrag(event: PointerEvent<HTMLButtonElement>) {
        event.currentTarget.releasePointerCapture(event.pointerId);
        setDragIndex(null);
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
                                <>
                                    <CheckCircle2 className="text-ok size-4" /> Zonas aplicadas en el edge
                                </>
                            ) : (
                                <>
                                    <Loader2 className="text-pending size-4 animate-spin" /> Aplicando cambios en el edge…
                                </>
                            )}
                        </span>
                    </div>

                    <div className="relative aspect-video overflow-hidden rounded-xl bg-black select-none">
                        {camera.preview_url ? (
                            <img src={camera.preview_url} alt={camera.name} className="absolute inset-0 size-full object-cover" draggable={false} />
                        ) : (
                            <div className="text-muted-foreground absolute inset-0 flex flex-col items-center justify-center gap-2">
                                <VideoOff className="size-8" />
                                <span className="text-sm">Pidiendo imagen al edge… (podés dibujar igual)</span>
                            </div>
                        )}
                        {/* Polygons: the SVG stretches with the image; handles are HTML so they stay round. */}
                        <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="pointer-events-none absolute inset-0 size-full">
                            {zones
                                .filter((zone) => zone.id !== editingId)
                                .map((zone, index) => (
                                    <polygon
                                        key={zone.id}
                                        points={toPoints(zone.coordinates)}
                                        fill={COLORS[index % COLORS.length]}
                                        fillOpacity={0.2}
                                        stroke={COLORS[index % COLORS.length]}
                                        strokeWidth={1.5}
                                        vectorEffect="non-scaling-stroke"
                                    />
                                ))}
                            {editingId !== null && form.data.coordinates.length > 1 && (
                                <polygon
                                    points={toPoints(form.data.coordinates)}
                                    fill="#f5a524"
                                    fillOpacity={0.25}
                                    stroke="#f5a524"
                                    strokeWidth={2}
                                    vectorEffect="non-scaling-stroke"
                                />
                            )}
                        </svg>

                        <div ref={canvasRef} className={cn('absolute inset-0', editingId !== null && 'cursor-crosshair')} onClick={addPoint}>
                            {editingId !== null &&
                                form.data.coordinates.map(([x, y], index) => (
                                    <div
                                        key={index}
                                        className="group absolute -translate-x-1/2 -translate-y-1/2"
                                        style={{ left: `${x * 100}%`, top: `${y * 100}%` }}
                                    >
                                        <button
                                            type="button"
                                            aria-label={`Punto ${index + 1}: arrastrá para moverlo`}
                                            onPointerDown={(event) => startDrag(index, event)}
                                            onPointerMove={drag}
                                            onPointerUp={endDrag}
                                            onClick={(event) => event.stopPropagation()}
                                            className={cn(
                                                'block size-4 touch-none rounded-full border-2 border-[#1b1d22] bg-[#f5a524] shadow',
                                                dragIndex === index ? 'scale-125 cursor-grabbing' : 'cursor-grab',
                                            )}
                                        />
                                        <button
                                            type="button"
                                            aria-label={`Borrar punto ${index + 1}`}
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                removePoint(index);
                                            }}
                                            className="bg-alarm absolute -top-3 -right-3 flex size-5 items-center justify-center rounded-full text-white shadow ring-2 ring-[#1b1d22] focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                                        >
                                            <X className="size-3" strokeWidth={3} />
                                        </button>
                                    </div>
                                ))}
                        </div>
                    </div>
                    {editingId !== null ? (
                        <ol className="text-muted-foreground grid gap-1 text-sm sm:grid-cols-3">
                            <li>
                                <span className="text-foreground font-medium">Agregar:</span> click sobre la imagen. El punto se suma al lado más
                                cercano de la figura.
                            </li>
                            <li>
                                <span className="text-foreground font-medium">Mover:</span> arrastrá cualquier punto.
                            </li>
                            <li>
                                <span className="text-foreground font-medium">Borrar:</span> pasá el mouse por el punto y tocá la ×.
                            </li>
                        </ol>
                    ) : (
                        <p className="text-muted-foreground text-sm">Elegí una zona de la lista para editarla o creá una nueva.</p>
                    )}
                </div>

                <div className="flex flex-col gap-4">
                    {editingId !== null ? (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">{editing ? `Editar ${editing.name}` : 'Nueva zona'}</CardTitle>
                                <CardDescription>{plural(form.data.coordinates.length, 'punto marcado', 'puntos marcados')}</CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                <fieldset>
                                    <legend className="mb-1.5 text-sm font-medium">Qué es</legend>
                                    <div className="grid grid-cols-2 gap-1.5">
                                        {zoneKinds.map((kind) => {
                                            const Icon = KIND_ICONS[kind.id];
                                            const active = form.data.kind === kind.id;
                                            return (
                                                <button
                                                    type="button"
                                                    key={kind.id}
                                                    aria-pressed={active}
                                                    onClick={() => form.setData({ ...form.data, kind: kind.id, watched_labels: kind.labels })}
                                                    className={cn(
                                                        'flex items-center gap-2 rounded-md border px-3 py-2 text-left text-sm',
                                                        active
                                                            ? 'border-foreground bg-accent font-medium'
                                                            : 'text-muted-foreground hover:text-foreground',
                                                    )}
                                                >
                                                    <Icon className="size-4 shrink-0" />
                                                    {kind.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {form.data.kind !== 'perimeter' && (
                                        <p className="text-muted-foreground mt-1.5 text-xs">
                                            Una persona en esta zona genera una alerta crítica, a cualquier hora en que esté armada.
                                        </p>
                                    )}
                                </fieldset>
                                <Input
                                    placeholder={`Nombre (${zoneKinds.find((kind) => kind.id === form.data.kind)?.example.toLowerCase()})`}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                                <InputError message={form.errors.name} />
                                <Input
                                    placeholder="Lote que cubre (opcional, para modo vacaciones)"
                                    value={form.data.lot}
                                    onChange={(e) => form.setData('lot', e.target.value)}
                                />
                                <div>
                                    <div className="mb-1 text-sm font-medium">Qué vigilar</div>
                                    <div className="flex flex-wrap gap-1">
                                        {Object.entries(objectLabels).map(([value, label]) => (
                                            <button
                                                type="button"
                                                key={value}
                                                onClick={() => toggleLabel(value)}
                                                className={cn(
                                                    'rounded-full border px-2 py-0.5 text-xs',
                                                    form.data.watched_labels.includes(value) && 'bg-primary text-primary-foreground',
                                                )}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                                <InputError message={form.errors.coordinates} />
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => form.setData('coordinates', form.data.coordinates.slice(0, -1))}
                                        disabled={!form.data.coordinates.length}
                                    >
                                        <Undo2 className="mr-1 size-4" /> Deshacer punto
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => form.setData('coordinates', [])}
                                        disabled={!form.data.coordinates.length}
                                    >
                                        <RotateCcw className="mr-1 size-4" /> Borrar dibujo
                                    </Button>
                                </div>
                                {editing && <Schedules zone={editing} />}
                                <div className="flex gap-2">
                                    <Button onClick={save} disabled={form.processing || form.data.coordinates.length < 3 || !form.data.name}>
                                        Guardar zona
                                    </Button>
                                    <Button variant="ghost" onClick={() => setEditingId(null)}>
                                        Cancelar
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        <Button onClick={() => startEditing(null)}>+ Nueva zona</Button>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Zonas de esta cámara</CardTitle>
                            <CardDescription>
                                Solo se analizan detecciones dentro de estas áreas. Sin zonas, se vigila la imagen completa.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {zones.length === 0 && <p className="text-muted-foreground text-sm">Todavía no hay zonas.</p>}
                            {zones.map((zone, index) => (
                                <div
                                    key={zone.id}
                                    className={cn(
                                        'hover:bg-accent flex items-center gap-1 rounded-lg border pr-1 text-sm',
                                        editingId === zone.id && 'border-primary bg-accent',
                                    )}
                                >
                                    <button
                                        className="flex min-w-0 flex-1 items-center gap-3 p-2 text-left"
                                        onClick={() => startEditing(zone)}
                                        tabIndex={-1}
                                    >
                                        <ZoneShape points={zone.coordinates} color={COLORS[index % COLORS.length]} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">{zone.name}</span>
                                            <span className="text-muted-foreground block truncate text-xs">
                                                {zoneKindLabel[zone.kind]}
                                                {', '}
                                                {zone.coordinates.length < 3 ? (
                                                    <span className="text-pending">falta dibujarla</span>
                                                ) : zone.schedules.length ? (
                                                    'con horario de armado'
                                                ) : (
                                                    'vigilada las 24 h'
                                                )}
                                                {zone.lot && `, lote ${zone.lot}`}
                                            </span>
                                        </span>
                                    </button>
                                    <ItemActions>
                                        <ItemActionButton kind="edit" onClick={() => startEditing(zone)} aria-label={`Editar zona ${zone.name}`} />
                                        <DeleteZone zone={zone} />
                                    </ItemActions>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
