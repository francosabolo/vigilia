import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { CAMERA_MODELS, fovPolygon, perimeterCoverage, planCoverage, type CameraPlacement, type LatLng } from '@/lib/geo';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { MapPin, VideoOff } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CircleMarker, MapContainer, Polygon, Polyline, TileLayer, Tooltip, useMap, useMapEvents } from 'react-leaflet';

export interface MapCamera extends CameraPlacement {
    name: string;
    sector: string | null;
    preview_url: string | null;
}

interface Props {
    siteId: number;
    perimeter: LatLng[];
    center: LatLng | null;
    cameras: MapCamera[];
    canManage: boolean;
    /** 'overview' shows cameras and lets managers edit; 'coverage' finds blind spots and suggests cameras. */
    variant?: 'overview' | 'coverage';
}

type Mode = 'view' | 'perimeter' | 'camera';

const DEFAULT_CENTER: LatLng = [-34.4587, -58.9142];
const COLORS = { covered: '#34c77b', gap: '#ef3640', cone: '#ffffff', coneOffline: '#ef3640', draft: '#f5a524' };

function FitBounds({ points }: { points: LatLng[] }) {
    const map = useMap();
    const key = points.map((point) => point.join(',')).join('|');

    useEffect(() => {
        if (points.length > 1) map.fitBounds(L.latLngBounds(points), { padding: [30, 30], maxZoom: 19 });
        // Only refit when the set of points changes, not on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return null;
}

function ClickHandler({ onClick }: { onClick: (point: LatLng) => void }) {
    useMapEvents({ click: (event) => onClick([Number(event.latlng.lat.toFixed(7)), Number(event.latlng.lng.toFixed(7))]) });
    return null;
}

function CenterReporter({ onMove }: { onMove: (center: LatLng) => void }) {
    const map = useMapEvents({ moveend: () => onMove([map.getCenter().lat, map.getCenter().lng]) });
    return null;
}

export function SiteMap({ siteId, perimeter, center, cameras, canManage, variant = 'overview' }: Props) {
    const [mode, setMode] = useState<Mode>('view');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [draftPerimeter, setDraftPerimeter] = useState<LatLng[]>(perimeter);
    const [placement, setPlacement] = useState<MapCamera | null>(null);
    const [mapCenter, setMapCenter] = useState<LatLng>(center ?? DEFAULT_CENTER);

    const liveCameras = cameras.map((camera) => (placement && camera.id === placement.id ? placement : camera));
    const placed = liveCameras.filter((camera) => camera.position);
    const unplaced = liveCameras.filter((camera) => !camera.position);
    const shownPerimeter = mode === 'perimeter' ? draftPerimeter : perimeter;

    const coverage = useMemo(() => perimeterCoverage(shownPerimeter, liveCameras), [shownPerimeter, liveCameras]);
    const selected = liveCameras.find((camera) => camera.id === selectedId) ?? null;

    const [modelId, setModelId] = useState(CAMERA_MODELS[0].id);
    const [focus, setFocus] = useState<LatLng[] | null>(null);
    const model = CAMERA_MODELS.find((candidate) => candidate.id === modelId)!;
    const plan = useMemo(() => (variant === 'coverage' ? planCoverage(perimeter, cameras, model) : null), [variant, perimeter, cameras, model]);

    const fitPoints: LatLng[] = focus ?? [...perimeter, ...cameras.flatMap((camera) => (camera.position ? [camera.position] : []))];

    function handleMapClick(point: LatLng) {
        if (mode === 'perimeter') setDraftPerimeter([...draftPerimeter, point]);
        if (mode === 'camera' && placement) setPlacement({ ...placement, position: point });
    }

    function startPlacing(camera: MapCamera) {
        setMode('camera');
        setSelectedId(camera.id);
        setPlacement({ ...camera });
    }

    function savePlacement() {
        if (!placement?.position) return;
        router.patch(
            `/cameras/${placement.id}/placement`,
            { lat: placement.position[0], lng: placement.position[1], heading: placement.heading, fov: placement.fov, range_m: placement.range_m },
            { preserveScroll: true, onSuccess: () => { setMode('view'); setPlacement(null); } },
        );
    }

    function savePerimeter() {
        router.patch(`/sites/${siteId}/perimeter`, { perimeter: draftPerimeter, center: mapCenter }, { preserveScroll: true, onSuccess: () => setMode('view') });
    }

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div className={cn('relative h-[460px] overflow-hidden rounded-xl border md:h-[560px]', mode !== 'view' && 'ring-pending ring-2')}>
                <MapContainer center={center ?? DEFAULT_CENTER} zoom={17} maxZoom={20} className="size-full" style={{ background: '#131418' }}>
                    <TileLayer
                        url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
                        attribution="Imágenes &copy; Esri"
                        maxNativeZoom={19}
                        maxZoom={20}
                    />
                    <FitBounds points={fitPoints} />
                    <ClickHandler onClick={handleMapClick} />
                    <CenterReporter onMove={setMapCenter} />

                    {placed.map((camera) => (
                        <Polygon
                            key={`cone-${camera.id}`}
                            positions={fovPolygon(camera)}
                            pathOptions={{
                                color: camera.is_online ? COLORS.cone : COLORS.coneOffline,
                                weight: camera.id === selectedId ? 2 : 1,
                                fillOpacity: camera.id === selectedId ? 0.28 : 0.14,
                                dashArray: camera.is_online ? undefined : '4 4',
                            }}
                            eventHandlers={{ click: () => mode === 'view' && setSelectedId(camera.id) }}
                        />
                    ))}

                    {mode === 'perimeter'
                        ? draftPerimeter.length > 0 && <Polygon positions={draftPerimeter} pathOptions={{ color: COLORS.draft, weight: 3, fillOpacity: 0.05, dashArray: '6 4' }} />
                        : coverage.segments.map((segment, index) => (
                              <Polyline key={index} positions={segment.points} pathOptions={{ color: segment.covered ? COLORS.covered : COLORS.gap, weight: 5, opacity: 0.95 }} />
                          ))}

                    {plan?.suggestions.map((suggestion, index) => (
                        <Polygon
                            key={`suggestion-${index}`}
                            positions={fovPolygon({ id: -1, is_online: true, ...suggestion })}
                            pathOptions={{ color: COLORS.draft, weight: 2, fillOpacity: 0.12, dashArray: '6 5' }}
                        />
                    ))}
                    {plan?.suggestions.map((suggestion, index) => (
                        <CircleMarker key={`suggestion-marker-${index}`} center={suggestion.position} radius={9} pathOptions={{ color: COLORS.draft, weight: 2, fillColor: '#131418', fillOpacity: 1 }}>
                            <Tooltip direction="center" permanent className="!border-0 !bg-transparent !p-0 !font-semibold !text-[#f5a524] !shadow-none">
                                {index + 1}
                            </Tooltip>
                        </CircleMarker>
                    ))}

                    {placed.map((camera) => (
                        <CircleMarker
                            key={`marker-${camera.id}`}
                            center={camera.position!}
                            radius={camera.id === selectedId ? 10 : 8}
                            pathOptions={{ color: '#131418', weight: 2, fillColor: camera.is_online ? '#ffffff' : COLORS.gap, fillOpacity: 1 }}
                            eventHandlers={{ click: () => mode === 'view' && setSelectedId(camera.id) }}
                        >
                            <Tooltip direction="top" offset={[0, -8]} permanent={camera.id === selectedId}>
                                {camera.name}
                            </Tooltip>
                        </CircleMarker>
                    ))}
                </MapContainer>

                {mode !== 'view' && (
                    <div className="bg-card/95 absolute top-3 left-1/2 z-[1000] -translate-x-1/2 rounded-lg border px-4 py-2 text-sm shadow-lg">
                        {mode === 'perimeter' ? 'Hacé click siguiendo el borde del barrio para marcar el perímetro.' : 'Hacé click donde está instalada la cámara.'}
                    </div>
                )}
            </div>

            <aside className="flex flex-col gap-4">
                {plan ? (
                    <CoveragePlan
                        plan={plan}
                        cameras={cameras}
                        hasPerimeter={perimeter.length >= 3}
                        modelId={modelId}
                        onModelChange={setModelId}
                        onFocus={setFocus}
                    />
                ) : mode === 'perimeter' ? (
                    <section className="bg-card flex flex-col gap-3 rounded-xl border p-4">
                        <h3 className="font-semibold">Dibujar perímetro</h3>
                        <p className="text-muted-foreground text-sm">{draftPerimeter.length} puntos marcados. Cerrá la vuelta completa al barrio; el último punto se une con el primero.</p>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" onClick={() => setDraftPerimeter(draftPerimeter.slice(0, -1))} disabled={!draftPerimeter.length}>
                                Deshacer punto
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => setDraftPerimeter([])} disabled={!draftPerimeter.length}>
                                Empezar de nuevo
                            </Button>
                        </div>
                        <div className="flex gap-2">
                            <Button onClick={savePerimeter} disabled={draftPerimeter.length > 0 && draftPerimeter.length < 3}>
                                Guardar perímetro
                            </Button>
                            <Button variant="ghost" onClick={() => { setDraftPerimeter(perimeter); setMode('view'); }}>
                                Cancelar
                            </Button>
                        </div>
                    </section>
                ) : mode === 'camera' && placement ? (
                    <section className="bg-card flex flex-col gap-4 rounded-xl border p-4">
                        <h3 className="font-semibold">Ubicar {placement.name}</h3>
                        {!placement.position && <p className="text-pending text-sm">Primero hacé click en el mapa donde está la cámara.</p>}
                        {[
                            { key: 'heading' as const, label: 'Hacia dónde apunta', min: 0, max: 359, unit: '°' },
                            { key: 'fov' as const, label: 'Ángulo de visión', min: 10, max: 360, unit: '°' },
                            { key: 'range_m' as const, label: 'Alcance útil de noche', min: 5, max: 150, unit: ' m' },
                        ].map((control) => (
                            <div key={control.key} className="grid gap-1.5">
                                <Label htmlFor={control.key} className="flex justify-between">
                                    {control.label}
                                    <span className="font-display tabular-nums">
                                        {placement[control.key]}
                                        {control.unit}
                                    </span>
                                </Label>
                                <input
                                    id={control.key}
                                    type="range"
                                    min={control.min}
                                    max={control.max}
                                    value={placement[control.key]}
                                    onChange={(event) => setPlacement({ ...placement, [control.key]: Number(event.target.value) })}
                                    className="accent-white"
                                />
                            </div>
                        ))}
                        <div className="flex gap-2">
                            <Button onClick={savePlacement} disabled={!placement.position}>
                                Guardar ubicación
                            </Button>
                            <Button variant="ghost" onClick={() => { setPlacement(null); setMode('view'); }}>
                                Cancelar
                            </Button>
                        </div>
                    </section>
                ) : selected ? (
                    <section className="bg-card overflow-hidden rounded-xl border">
                        <div className="aspect-video bg-black">
                            {selected.preview_url ? (
                                <img src={selected.preview_url} alt={selected.name} className="size-full object-cover" />
                            ) : (
                                <div className="text-muted-foreground flex size-full items-center justify-center">
                                    <VideoOff className="size-6" />
                                </div>
                            )}
                        </div>
                        <div className="flex flex-col gap-3 p-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className={cn('size-2 rounded-full', selected.is_online ? 'bg-ok' : 'bg-alarm')} />
                                    <span className="font-display text-xl font-semibold">{selected.name}</span>
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {selected.sector ? `${selected.sector}. ` : ''}Ve {selected.fov}° hasta {selected.range_m} m.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild size="sm">
                                    <Link href={`/cameras?site=${siteId}`}>Ver en vivo</Link>
                                </Button>
                                {canManage && (
                                    <>
                                        <Button size="sm" variant="secondary" onClick={() => startPlacing(selected)}>
                                            Mover o reorientar
                                        </Button>
                                        <Button asChild size="sm" variant="ghost">
                                            <Link href={`/cameras/${selected.id}/zones`}>Zonas</Link>
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </section>
                ) : (
                    <section className="bg-card flex flex-col gap-3 rounded-xl border p-4">
                        <h3 className="font-semibold">Cobertura del perímetro</h3>
                        {perimeter.length >= 3 ? (
                            <>
                                <p className="font-display text-5xl leading-none font-semibold tabular-nums">{Math.round(coverage.coveredRatio * 100)}%</p>
                                <p className="text-muted-foreground text-sm">
                                    de {Math.round(coverage.lengthM)} m de perímetro lo ve al menos una cámara online. Los tramos en rojo no los ve ninguna.
                                </p>
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">Todavía no está dibujado el perímetro del barrio.</p>
                        )}
                        <p className="text-muted-foreground text-sm">Tocá una cámara en el mapa para verla.</p>
                    </section>
                )}

                {canManage && mode === 'view' && variant === 'overview' && (
                    <section className="bg-card flex flex-col gap-3 rounded-xl border p-4">
                        <Button variant="secondary" onClick={() => { setDraftPerimeter(perimeter); setMode('perimeter'); setSelectedId(null); }}>
                            {perimeter.length ? 'Editar perímetro' : 'Dibujar perímetro'}
                        </Button>
                        {unplaced.length > 0 && (
                            <div>
                                <p className="text-muted-foreground mb-2 text-sm">Cámaras sin ubicar en el mapa:</p>
                                <ul className="flex flex-col gap-1">
                                    {unplaced.map((camera) => (
                                        <li key={camera.id}>
                                            <button onClick={() => startPlacing(camera)} className="hover:bg-accent flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm">
                                                <MapPin className="text-pending size-4" />
                                                {camera.name}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}
            </aside>
        </div>
    );
}

function CoveragePlan({
    plan,
    cameras,
    hasPerimeter,
    modelId,
    onModelChange,
    onFocus,
}: {
    plan: ReturnType<typeof planCoverage>;
    cameras: MapCamera[];
    hasPerimeter: boolean;
    modelId: string;
    onModelChange: (id: string) => void;
    onFocus: (points: LatLng[] | null) => void;
}) {
    if (!hasPerimeter) {
        return (
            <section className="bg-card rounded-xl border p-4">
                <h3 className="font-semibold">Falta el perímetro</h3>
                <p className="text-muted-foreground mt-1 text-sm">Dibujalo en la pestaña Resumen para calcular qué tramos no ve ninguna cámara.</p>
            </section>
        );
    }

    const cameraName = (id: number) => cameras.find((camera) => camera.id === id)?.name;
    let counter = 0;

    return (
        <>
            <section className="bg-card flex flex-col gap-4 rounded-xl border p-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <p className="font-display text-4xl leading-none font-semibold tabular-nums">{Math.round(plan.current.coveredRatio * 100)}%</p>
                        <p className="text-muted-foreground mt-1 text-sm">cubierto hoy</p>
                    </div>
                    {plan.suggestions.length > 0 && (
                        <div>
                            <p className="font-display text-pending text-4xl leading-none font-semibold tabular-nums">{Math.round(plan.projectedRatio * 100)}%</p>
                            <p className="text-muted-foreground mt-1 text-sm">
                                con {plan.suggestions.length} {plan.suggestions.length === 1 ? 'cámara nueva' : 'cámaras nuevas'}
                            </p>
                        </div>
                    )}
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="model">Modelo para las sugerencias</Label>
                    <select id="model" value={modelId} onChange={(event) => onModelChange(event.target.value)} className="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        {CAMERA_MODELS.map((candidate) => (
                            <option key={candidate.id} value={candidate.id}>
                                {candidate.name} ({candidate.fov}°, {candidate.range_m} m)
                            </option>
                        ))}
                    </select>
                </div>
            </section>

            <section className="bg-card flex flex-col gap-2 rounded-xl border p-4">
                <h3 className="font-semibold">Puntos ciegos</h3>
                {plan.gaps.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Ninguno: todo el perímetro lo ve al menos una cámara online.</p>
                ) : (
                    <ol className="flex flex-col gap-1">
                        {plan.gaps.map((gap, index) => {
                            const suggested = plan.suggestions.filter((suggestion) => suggestion.gapIndex === index).length;
                            const first = counter + 1;
                            counter += suggested;
                            const near = gap.nearestCamera ? cameraName(gap.nearestCamera.id) : null;
                            return (
                                <li key={index}>
                                    <button onClick={() => onFocus(gap.points)} className="hover:bg-accent w-full rounded-md px-2 py-2 text-left text-sm">
                                        <span className="font-medium">Tramo de {Math.round(gap.lengthM)} m sin cubrir</span>
                                        {near && <span className="text-muted-foreground">, a {Math.round(gap.nearestCamera!.distanceM)} m de {near}</span>}
                                        <span className="text-pending block">
                                            {suggested === 1 ? `Sugerencia ${first}` : `Sugerencias ${first} a ${first + suggested - 1}`}: {suggested} {suggested === 1 ? 'cámara' : 'cámaras'} sobre el alambrado
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                )}
                {plan.gaps.length > 0 && (
                    <button onClick={() => onFocus(null)} className="text-muted-foreground hover:text-foreground self-start text-sm underline-offset-4 hover:underline">
                        Ver todo el barrio
                    </button>
                )}
                <p className="text-muted-foreground text-xs">Las cámaras sin señal no cuentan como cobertura. El cálculo es una estimación: confirmalo recorriendo el perímetro de noche.</p>
            </section>
        </>
    );
}
