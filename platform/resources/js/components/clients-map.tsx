import { type LatLng } from '@/lib/geo';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { CircleMarker, MapContainer, Marker, TileLayer, Tooltip, useMapEvents } from 'react-leaflet';

const BUENOS_AIRES: LatLng = [-34.6037, -58.3816];
const TILES = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';

const pin = L.divIcon({
    className: '',
    html: '<span style="display:block;width:16px;height:16px;border-radius:9999px;background:#E5252E;border:3px solid white;box-shadow:0 1px 4px rgba(0,0,0,.5)"></span>',
    iconSize: [16, 16],
    iconAnchor: [8, 8],
});

export interface MapClient {
    id: number;
    name: string;
    address: string | null;
    position: LatLng | null;
    sites: { id: number; name: string; position: LatLng | null }[];
}

/**
 * Where each client is (its office) and where its buildings and countries are.
 */
export function ClientsMap({ clients }: { clients: MapClient[] }) {
    const points = clients
        .flatMap((client) => [client.position, ...client.sites.map((site) => site.position)])
        .filter((point): point is LatLng => point !== null);
    if (points.length === 0) return null;

    return (
        <div className="h-72 overflow-hidden rounded-2xl border">
            <MapContainer bounds={L.latLngBounds(points).pad(0.3)} maxZoom={18} scrollWheelZoom={false} className="size-full">
                <TileLayer url={TILES} attribution="Imágenes &copy; Esri" maxNativeZoom={19} maxZoom={20} />
                {clients.map((client) =>
                    client.sites
                        .filter((site) => site.position)
                        .map((site) => (
                            <CircleMarker
                                key={`site-${site.id}`}
                                center={site.position!}
                                radius={6}
                                pathOptions={{ color: 'white', weight: 2, fillColor: '#38bdf8', fillOpacity: 1 }}
                            >
                                <Tooltip>
                                    {site.name} ({client.name})
                                </Tooltip>
                            </CircleMarker>
                        )),
                )}
                {clients
                    .filter((client) => client.position)
                    .map((client) => (
                        <Marker key={`client-${client.id}`} position={client.position!} icon={pin}>
                            <Tooltip direction="top" offset={[0, -8]}>
                                <strong>{client.name}</strong>
                                {client.address && <span className="block">{client.address}</span>}
                            </Tooltip>
                        </Marker>
                    ))}
            </MapContainer>
        </div>
    );
}

function Picker({ onPick }: { onPick: (point: LatLng) => void }) {
    useMapEvents({ click: (event) => onPick([Number(event.latlng.lat.toFixed(7)), Number(event.latlng.lng.toFixed(7))]) });
    return null;
}

/**
 * Click to drop the client's point; click again to move it.
 */
export function LocationPicker({
    value,
    fallback,
    onChange,
}: {
    value: LatLng | null;
    fallback: LatLng | null;
    onChange: (point: LatLng | null) => void;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <div className="h-48 overflow-hidden rounded-xl border">
                <MapContainer center={value ?? fallback ?? BUENOS_AIRES} zoom={value || fallback ? 16 : 11} maxZoom={20} className="size-full">
                    <TileLayer url={TILES} attribution="Imágenes &copy; Esri" maxNativeZoom={19} maxZoom={20} />
                    <Picker onPick={onChange} />
                    {value && <Marker position={value} icon={pin} />}
                </MapContainer>
            </div>
            <p className="text-muted-foreground text-sm">
                {value ? 'Tocá el mapa para mover el punto.' : 'Tocá el mapa para marcar el domicilio (opcional).'}
                {value && (
                    <button type="button" className="ml-2 underline underline-offset-2" onClick={() => onChange(null)}>
                        Quitar punto
                    </button>
                )}
            </p>
        </div>
    );
}
