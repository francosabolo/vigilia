import { EmergencyContacts, whatsappNumber, type EmergencyContact } from '@/components/emergency-contacts';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type LatLng } from '@/lib/geo';
import { hasHousesOnMap, timeAgo, unitNoun, type SiteKind } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { useForm, usePage } from '@inertiajs/react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Megaphone, MessageCircle, Phone, Search } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { MapContainer, Marker, Polygon, Popup, TileLayer } from 'react-leaflet';

export interface PanelResident {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    lot: string | null;
    sector: string | null;
    away_until: string | null;
    last_login_at: string | null;
    push_devices: number;
    home: LatLng | null;
    contacts: EmergencyContact[];
    vehicles: { id: number; plate: string; make_model: string | null; color: string | null }[];
}

export interface SentMessage {
    id: number;
    body: string;
    recipients: number;
    urgent: boolean;
    sender: string | null;
    at: string;
}

type Status = 'all' | 'away' | 'no_app' | 'never';

const STATUS_FILTERS: { id: Status; label: string }[] = [
    { id: 'all', label: 'Todos' },
    { id: 'away', label: 'De viaje' },
    { id: 'no_app', label: 'Sin notificaciones en el celular' },
    { id: 'never', label: 'Nunca entraron' },
];

const houseIcon = (label: string, selected: boolean, away: boolean) =>
    L.divIcon({
        className: '',
        html: `<span style="display:inline-flex;min-width:28px;height:22px;padding:0 6px;align-items:center;justify-content:center;border-radius:6px;font:600 12px/1 system-ui;color:${selected ? 'white' : '#111'};background:${selected ? '#E5252E' : away ? '#fbbf24' : 'white'};border:2px solid ${selected ? 'white' : '#111'};box-shadow:0 1px 3px rgba(0,0,0,.5)">${label.replace(/[<>&"]/g, '')}</span>`,
        iconSize: [28, 22],
        iconAnchor: [14, 11],
    });

/**
 * Who is who in a property, to find and reach residents fast: search, filters, pick one or many and send a notice.
 * Countries and condominios also show every house on the map, clickable.
 */
export function ResidentsPanel({
    siteId,
    kind,
    perimeter,
    center,
    residents,
    sectors,
    messages,
    canManage,
    renderUsage,
    renderActions,
}: {
    siteId: number;
    kind: SiteKind;
    perimeter: LatLng[];
    center: LatLng | null;
    residents: PanelResident[];
    sectors: string[];
    messages: SentMessage[];
    canManage: boolean;
    renderUsage: (resident: PanelResident) => ReactNode;
    renderActions: (resident: PanelResident) => ReactNode;
}) {
    const [query, setQuery] = useState('');
    const [sector, setSector] = useState('');
    const [status, setStatus] = useState<Status>('all');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [composing, setComposing] = useState(false);
    const noun = unitNoun(kind);

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        return residents.filter((resident) => {
            if (q && ![resident.name, resident.email, resident.lot ?? '', resident.phone ?? ''].some((value) => value.toLowerCase().includes(q)))
                return false;
            if (sector && resident.sector !== sector) return false;
            if (status === 'away' && !resident.away_until) return false;
            if (status === 'no_app' && resident.push_devices > 0) return false;
            if (status === 'never' && resident.last_login_at) return false;
            return true;
        });
    }, [residents, query, sector, status]);

    const allVisibleSelected = visible.length > 0 && visible.every((resident) => selected.has(resident.id));
    const toggle = (id: number) =>
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    const toggleVisible = () =>
        setSelected((current) => {
            const next = new Set(current);
            visible.forEach((resident) => (allVisibleSelected ? next.delete(resident.id) : next.add(resident.id)));
            return next;
        });
    const notifyOnly = (id: number) => {
        setSelected(new Set([id]));
        setComposing(true);
    };

    const houses = residents.filter((resident) => resident.home);
    const showMap = hasHousesOnMap(kind);

    return (
        <div className="flex flex-col gap-4">
            {showMap && (
                <div className="h-[420px] overflow-hidden rounded-xl border">
                    <MapContainer
                        center={center ?? houses[0]?.home ?? [-34.6037, -58.3816]}
                        zoom={17}
                        maxZoom={20}
                        className="size-full"
                        style={{ background: '#131418' }}
                    >
                        <TileLayer
                            url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
                            attribution="Imágenes &copy; Esri"
                            maxNativeZoom={19}
                            maxZoom={20}
                        />
                        {perimeter.length > 2 && <Polygon positions={perimeter} pathOptions={{ color: 'white', weight: 2, fillOpacity: 0.04 }} />}
                        {houses.map((resident) => (
                            <Marker
                                key={resident.id}
                                position={resident.home!}
                                icon={houseIcon(resident.lot ?? '·', selected.has(resident.id), !!resident.away_until)}
                            >
                                <Popup>
                                    <div className="flex min-w-56 flex-col gap-1 text-sm">
                                        <strong className="text-base">
                                            {noun} {resident.lot}: {resident.name}
                                        </strong>
                                        <span>{resident.phone ?? 'Sin celular cargado'}</span>
                                        <span>{resident.email}</span>
                                        {resident.away_until && (
                                            <span>De viaje hasta el {new Date(resident.away_until + 'T12:00').toLocaleDateString('es-AR')}</span>
                                        )}
                                        {resident.contacts.length > 0 && (
                                            <span className="mt-1 border-t pt-1">
                                                <span className="block font-medium">Si no responde:</span>
                                                <EmergencyContacts contacts={resident.contacts} compact />
                                            </span>
                                        )}
                                        <span className="flex flex-wrap gap-x-3 gap-y-1 pt-1">
                                            <ContactLinks resident={resident} />
                                            <button type="button" className="font-medium underline" onClick={() => notifyOnly(resident.id)}>
                                                Avisar
                                            </button>
                                            <button type="button" className="font-medium underline" onClick={() => toggle(resident.id)}>
                                                {selected.has(resident.id) ? 'Quitar de la selección' : 'Sumar a la selección'}
                                            </button>
                                        </span>
                                    </div>
                                </Popup>
                            </Marker>
                        ))}
                    </MapContainer>
                </div>
            )}
            {showMap && houses.length < residents.length && (
                <p className="text-muted-foreground -mt-2 text-sm">
                    {residents.length - houses.length} sin ubicar en el mapa{canManage ? ': editalos y tocá su casa en el mapa.' : '.'}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <div className="relative min-w-60 flex-1">
                    <Search className="text-muted-foreground absolute top-2.5 left-3 size-4" />
                    <Input
                        aria-label="Buscar vecinos"
                        placeholder={`Buscar por nombre, ${noun}, celular, email o patente`}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        className="pl-9"
                    />
                </div>
                {sectors.length > 0 && (
                    <select
                        aria-label="Sector"
                        value={sector}
                        onChange={(e) => setSector(e.target.value)}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">Todos los sectores</option>
                        {sectors.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </select>
                )}
                <select
                    aria-label="Estado"
                    value={status}
                    onChange={(e) => setStatus(e.target.value as Status)}
                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    {STATUS_FILTERS.map((filter) => (
                        <option key={filter.id} value={filter.id}>
                            {filter.label}
                        </option>
                    ))}
                </select>
            </div>

            <div
                className={cn(
                    'flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-2.5',
                    selected.size > 0 ? 'border-foreground bg-muted/60' : 'border-dashed',
                )}
            >
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={allVisibleSelected} onChange={toggleVisible} className="accent-foreground size-4" />
                    {selected.size > 0
                        ? `${selected.size} ${selected.size === 1 ? 'elegido' : 'elegidos'}`
                        : `Elegir los ${visible.length} que se ven`}
                </label>
                <div className="flex items-center gap-2">
                    {selected.size > 0 && (
                        <Button variant="ghost" size="sm" onClick={() => setSelected(new Set())}>
                            Limpiar
                        </Button>
                    )}
                    <Button size="sm" disabled={selected.size === 0} onClick={() => setComposing(true)}>
                        <Megaphone className="mr-1 size-4" />
                        Avisar{selected.size > 0 ? ` a ${selected.size}` : ''}
                    </Button>
                </div>
            </div>

            {visible.length === 0 ? (
                <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-center text-sm">Nadie coincide con la búsqueda.</p>
            ) : (
                <ul className="bg-card divide-y rounded-xl border">
                    {visible.map((resident) => (
                        <li
                            key={resident.id}
                            className={cn('flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3', selected.has(resident.id) && 'bg-muted/50')}
                        >
                            <input
                                type="checkbox"
                                aria-label={`Elegir a ${resident.name}`}
                                checked={selected.has(resident.id)}
                                onChange={() => toggle(resident.id)}
                                className="accent-foreground size-4"
                            />
                            <span className="font-display w-14 text-lg font-semibold tabular-nums">{resident.lot ?? '—'}</span>
                            <div className="min-w-44 flex-1">
                                <p className="font-medium">{resident.name}</p>
                                <p className="text-muted-foreground text-sm">
                                    {[
                                        resident.sector,
                                        resident.away_until
                                            ? `de viaje hasta el ${new Date(resident.away_until + 'T12:00').toLocaleDateString('es-AR')}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(', ') || resident.email}
                                </p>
                                {resident.vehicles.length > 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        {resident.vehicles
                                            .map((vehicle) => [vehicle.plate, vehicle.make_model, vehicle.color].filter(Boolean).join(' '))
                                            .join(' · ')}
                                    </p>
                                )}
                                <div className="mt-1.5 rounded-lg border px-2.5 py-1.5">
                                    <p className="text-muted-foreground text-xs font-medium">Contactos de emergencia</p>
                                    <EmergencyContacts contacts={resident.contacts} compact />
                                </div>
                            </div>
                            <div className="min-w-40 text-sm">{renderUsage(resident)}</div>
                            <div className="flex items-center gap-1">
                                <ContactLinks resident={resident} pill />
                                <button
                                    type="button"
                                    onClick={() => notifyOnly(resident.id)}
                                    className="text-muted-foreground hover:bg-accent hover:text-foreground inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-medium"
                                >
                                    <Megaphone className="size-4" /> Avisar
                                </button>
                                {renderActions(resident)}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {messages.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h3 className="text-sm font-semibold">Últimos avisos enviados</h3>
                    <ul className="text-muted-foreground flex flex-col gap-1 text-sm">
                        {messages.map((message) => (
                            <li key={message.id}>
                                <span className="text-foreground">
                                    {message.urgent && 'Urgente: '}«{message.body}»
                                </span>
                                , a {message.recipients} {message.recipients === 1 ? 'vecino' : 'vecinos'}, {message.sender ?? 'alguien del equipo'},{' '}
                                {timeAgo(message.at)}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <ComposeNotice
                open={composing}
                onOpenChange={setComposing}
                siteId={siteId}
                recipients={residents.filter((resident) => selected.has(resident.id))}
                onSent={() => setSelected(new Set())}
            />
        </div>
    );
}

function ContactLinks({ resident, pill = false }: { resident: PanelResident; pill?: boolean }) {
    if (!resident.phone) return null;
    const style = pill
        ? 'text-muted-foreground hover:bg-accent hover:text-foreground inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-medium'
        : 'font-medium underline';

    return (
        <>
            <a href={`tel:${resident.phone}`} className={style}>
                {pill && <Phone className="size-4" />} Llamar
            </a>
            <a href={`https://wa.me/${whatsappNumber(resident.phone)}`} target="_blank" rel="noreferrer" className={style}>
                {pill && <MessageCircle className="size-4" />} WhatsApp
            </a>
        </>
    );
}

function ComposeNotice({
    open,
    onOpenChange,
    siteId,
    recipients,
    onSent,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    siteId: number;
    recipients: PanelResident[];
    onSent: () => void;
}) {
    const form = useForm({ body: '', urgent: false as boolean });
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, resident_ids: recipients.map((resident) => resident.id) }));
        form.post(`/sites/${siteId}/residents/notify`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
                onSent();
            },
        });
    }

    const names = recipients.slice(0, 3).map((resident) => resident.name);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Avisar a {recipients.length === 1 ? recipients[0]?.name : `${recipients.length} vecinos`}</DialogTitle>
                        <DialogDescription>
                            {recipients.length > 1 && `${names.join(', ')}${recipients.length > 3 ? ` y ${recipients.length - 3} más` : ''}. `}
                            Les llega al celular si tienen las notificaciones activadas, y si no, por mail. Queda registrado quién lo mandó.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor="notice-body">Mensaje</Label>
                        <textarea
                            id="notice-body"
                            rows={4}
                            maxLength={500}
                            placeholder="Ej: dejaron abierto el portón de su cochera"
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            autoFocus
                        />
                        <InputError message={form.errors.body ?? errors.resident_ids} />
                    </div>
                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.urgent}
                            onChange={(e) => form.setData('urgent', e.target.checked)}
                            className="accent-foreground mt-0.5 size-4"
                        />
                        <span>
                            <span className="font-semibold">Es urgente</span>
                            <span className="text-muted-foreground block">También les llega por mail aunque tengan la app, y suena como alerta.</span>
                        </span>
                    </label>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing || recipients.length === 0 || !form.data.body.trim()}>
                            Enviar aviso
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
