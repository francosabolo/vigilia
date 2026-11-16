import { SectionTitle } from '@/components/icon-circle';
import { type IncidentDetail } from '@/lib/seguria';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Route } from 'lucide-react';
import { MapContainer, Marker, Polyline, TileLayer, Tooltip } from 'react-leaflet';

const stepIcon = (number: number) =>
    L.divIcon({
        className: '',
        html: `<span style="display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:9999px;background:#E5252E;color:#fff;font:600 13px Barlow,sans-serif;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)">${number}</span>`,
        iconSize: [26, 26],
        iconAnchor: [13, 13],
    });

const clock = (iso: string) => new Date(iso).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

/**
 * Someone followed across cameras: the cameras in order on the satellite map, plus the same steps as a list
 * (the list is the source of truth when cameras have no position).
 */
export function IncidentRoute({ route }: { route: IncidentDetail['route'] }) {
    const placed = route.filter((step) => step.position !== null) as (IncidentDetail['route'][number] & { position: [number, number] })[];

    return (
        <section className="flex flex-col gap-3">
            <SectionTitle icon={Route}>Recorrido</SectionTitle>
            <p className="text-muted-foreground text-sm">
                Probablemente la misma persona: la vieron cámaras cercanas, una después de otra. No hay reconocimiento facial.
            </p>
            {placed.length >= 2 && (
                <div className="h-64 overflow-hidden rounded-2xl border">
                    <MapContainer
                        bounds={L.latLngBounds(placed.map((step) => step.position)).pad(0.4)}
                        scrollWheelZoom={false}
                        className="h-full w-full"
                    >
                        <TileLayer
                            url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
                            attribution="Imágenes &copy; Esri"
                            maxNativeZoom={19}
                            maxZoom={21}
                        />
                        <Polyline positions={placed.map((step) => step.position)} pathOptions={{ color: '#E5252E', weight: 3, dashArray: '6 6' }} />
                        {placed.map((step) => (
                            <Marker key={`${step.camera_id}-${step.at}`} position={step.position} icon={stepIcon(route.indexOf(step) + 1)}>
                                <Tooltip direction="top" offset={[0, -14]}>
                                    {step.camera}, {clock(step.at)}
                                </Tooltip>
                            </Marker>
                        ))}
                    </MapContainer>
                </div>
            )}
            <ol className="flex flex-col gap-1.5">
                {route.map((step, index) => (
                    <li key={`${step.camera_id}-${step.at}`} className="flex items-center gap-3 text-sm">
                        <span className="bg-alarm font-display flex size-6 items-center justify-center rounded-full text-white">{index + 1}</span>
                        <span className="font-semibold">{step.camera}</span>
                        <time className="text-muted-foreground ml-auto tabular-nums">{clock(step.at)}</time>
                    </li>
                ))}
            </ol>
        </section>
    );
}
