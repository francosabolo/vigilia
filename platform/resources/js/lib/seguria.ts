export type Severity = 'low' | 'medium' | 'high' | 'critical';
export type IncidentStatus = 'new' | 'notified' | 'acknowledged' | 'escalated' | 'resolved';

export interface IncidentSummary {
    id: number;
    source: 'detection' | 'resident_report' | 'panic' | 'tamper' | 'equipment' | 'patrol';
    title: string;
    status: IncidentStatus;
    severity: Severity;
    site: { id: number; name: string; address?: string | null };
    camera: string;
    thumbnail_url: string | null;
    detected_at: string;
    escalated_at: string | null;
}

export interface IncidentAction {
    id: number;
    type: string;
    channel: string | null;
    notes: string | null;
    user: string | null;
    created_at: string | null;
}

export interface IncidentDetail extends IncidentSummary {
    description: string | null;
    ai_confidence: number | null;
    zone: string | null;
    sector: string | null;
    snapshot_url: string | null;
    clip_url: string | null;
    acknowledged_at: string | null;
    acknowledged_by: string | null;
    resolved_at: string | null;
    resolution: 'confirmed' | 'false_alarm' | 'recovered' | null;
    decisions: ActivityItem[];
    tamper: {
        camera_id: number;
        before_url: string | null;
        shift_deg: number | null;
        view_status: 'ok' | 'moved' | 'covered';
        can_fix: boolean;
    } | null;
    reporter: { name: string; lot: string | null; phone: string | null } | null;
    /** Cameras that saw the person, in order (only when followed across 2+ cameras). */
    route: { camera_id: number; camera: string; position: [number, number] | null; at: string }[];
    /** A10 — following the intruder while the incident is open. */
    follow?: {
        current: { id: number; name: string; sector: string | null; preview_url: string | null; seen_at: string };
        heading: string | null;
        look_for: string | null;
        next: { id: number; name: string; sector: string | null; preview_url: string | null; distance_m: number | null; ahead: boolean }[];
        sightings: number;
    } | null;
    live_frames: { camera: string; url: string }[];
    actions: IncidentAction[];
}

export interface Metrics {
    detections: number;
    filtered_by_ai: number;
    outside_schedule: number;
    incidents: number;
    confirmed: number;
    false_alarms: number;
    escalated: number;
    avg_ack_seconds: number | null;
    noise_reduction: number | null;
}

export interface EdgeStatus {
    id: number;
    name: string;
    version: string | null;
    last_seen_at: string | null;
    online: boolean;
}

export interface TraceStep {
    step: 'detector' | 'zone' | 'schedule' | 'ai' | 'decision';
    agent: string;
    result: 'ok' | 'stop' | 'doubt' | 'alert';
    detail: string;
    at: string;
}

export interface ActivityItem {
    id: number;
    site: string;
    camera: string;
    label: string;
    outcome: 'analyzing' | 'ignored' | 'dismissed' | 'alert';
    headline: string;
    agent: string;
    confidence: number | null;
    incident_id: number | null;
    at: string;
    snapshot_url: string | null;
    /** Frames of the clip the AI analyzed, in time order (bridge 0.4+). */
    frame_urls?: string[];
    trace: TraceStep[];
}

export type SiteKind = 'building' | 'condominium' | 'country' | 'house' | 'apartment' | 'store' | 'warehouse';

/** Buildings, condominios and countries: the interface uses each one's own words. */
export function siteNoun(kind: SiteKind | null | undefined): string {
    return siteKinds.find((item) => item.id === kind)?.noun ?? 'propiedad';
}

export const siteKinds: { id: SiteKind; label: string; noun: string; help: string; placeholder: string }[] = [
    { id: 'building', label: 'Edificio', noun: 'edificio', help: 'Unidades por piso, hall y cocheras', placeholder: 'Edificio Arenales 2150' },
    { id: 'condominium', label: 'Condominio', noun: 'condominio', help: 'Casas o dúplex en un mismo predio', placeholder: 'Condominio Las Glorias' },
    { id: 'country', label: 'Country', noun: 'country', help: 'Lotes y perímetro', placeholder: 'Country Los Álamos' },
    { id: 'house', label: 'Casa', noun: 'casa', help: 'Una vivienda con su terreno', placeholder: 'Casa Pérez' },
    { id: 'apartment', label: 'Departamento', noun: 'departamento', help: 'Una unidad dentro de un edificio', placeholder: 'Departamento Juncal 4B' },
    { id: 'store', label: 'Local', noun: 'local', help: 'Comercio u oficina a la calle', placeholder: 'Local Av. Cabildo 1500' },
    { id: 'warehouse', label: 'Nave', noun: 'nave', help: 'Depósito, galpón o planta', placeholder: 'Nave Parque Industrial Pilar' },
];

/** Houses on their own plot (country, condominio) are placed on the map; flats in a tower are not. */
export function hasHousesOnMap(kind: SiteKind | null | undefined): boolean {
    return kind === 'country' || kind === 'condominium';
}

/** "el edificio" / "la casa" / "la nave" */
export function theSite(kind: SiteKind | null | undefined): string {
    const noun = siteNoun(kind);
    return `${['casa', 'nave', 'propiedad'].includes(noun) ? 'la' : 'el'} ${noun}`;
}

/** Several homes with their own residents (edificio, condominio, country); a house, a flat, a shop or a warehouse is one. */
export function hasUnits(kind: SiteKind | null | undefined): boolean {
    return kind === 'building' || kind === 'condominium' || kind === 'country';
}

/** What a resident's home is called: unidad (4B) in a building, lote (42) in a country. */
export function unitNoun(kind: SiteKind | null | undefined): string {
    return kind === 'country' ? 'lote' : 'unidad';
}

export const objectLabels: Record<string, string> = {
    person: 'Personas',
    car: 'Autos',
    motorcycle: 'Motos',
    bicycle: 'Bicis',
    truck: 'Camiones',
    dog: 'Perros',
};

export type ZoneKind = 'perimeter' | 'door' | 'window' | 'sensitive' | 'garage' | 'common_area' | 'pool' | 'construction' | 'privacy';

export const zoneKinds: { id: ZoneKind; label: string; example: string; labels: string[] }[] = [
    { id: 'perimeter', label: 'Perímetro', example: 'Ej: alambrado norte', labels: ['person', 'car', 'motorcycle'] },
    { id: 'door', label: 'Puerta o portón', example: 'Ej: puerta de servicio', labels: ['person'] },
    { id: 'window', label: 'Ventana', example: 'Ej: ventanal del SUM', labels: ['person'] },
    { id: 'sensitive', label: 'Punto sensible', example: 'Ej: tablero eléctrico', labels: ['person'] },
    { id: 'garage', label: 'Cochera', example: 'Ej: cochera del subsuelo', labels: ['person', 'car', 'motorcycle'] },
    { id: 'common_area', label: 'Espacio común', example: 'Ej: hall de entrada', labels: ['person'] },
    { id: 'pool', label: 'Pileta', example: 'Ej: pileta del club house', labels: ['person'] },
    { id: 'construction', label: 'Obra', example: 'Ej: obra del lote 30', labels: ['person'] },
    { id: 'privacy', label: 'Privacidad', example: 'Ej: la calle o la ventana del vecino', labels: [] },
];

export const zoneKindLabel: Record<ZoneKind, string> = Object.fromEntries(zoneKinds.map((kind) => [kind.id, kind.label])) as Record<ZoneKind, string>;

export const severityLabel: Record<Severity, string> = {
    low: 'Baja',
    medium: 'Media',
    high: 'Alta',
    critical: 'Crítica',
};

/** Left stripe of an incident row: the severity is read at a glance, like a traffic light. */
export const severityStripe: Record<Severity, string> = {
    low: 'bg-muted-foreground/40',
    medium: 'bg-pending',
    high: 'bg-alarm/80',
    critical: 'bg-alarm',
};

export const statusLabel: Record<IncidentStatus, string> = {
    new: 'Nuevo',
    notified: 'Esperando a la guardia',
    acknowledged: 'Guardia en camino',
    escalated: 'Escalado a la central',
    resolved: 'Cerrado',
};

/** Signal dot of each status. Only open states use color; closed ones go quiet. */
export const statusDot: Record<IncidentStatus, string> = {
    new: 'bg-pending',
    notified: 'bg-pending',
    acknowledged: 'bg-sky-400',
    escalated: 'bg-alarm animate-pulse',
    resolved: 'bg-ok',
};

export const actionLabel: Record<string, string> = {
    detected: 'Detección analizada',
    detection_added: 'Nueva detección agrupada',
    notified: 'Guardia notificada',
    acknowledged: 'Recibido / en atención',
    escalated: 'Escalado a la central',
    confirmed: 'Cerrado: intrusión confirmada',
    false_alarm: 'Cerrado: falsa alarma',
    recovered: 'Cerrado: el equipo se recuperó solo',
    tracked: 'Vista por otra cámara del recorrido',
    deterred: 'Disuasión automática enviada',
    deterrence_done: 'El altavoz de la cámara reprodujo el aviso',
    deterrence_failed: 'La disuasión no se pudo reproducir',
    autonomy_demoted: 'La zona volvió a Asistida',
    equipment_down: 'Equipo sin señal',
    patrol: 'Novedad de la ronda virtual',
    speaker_message: 'Mensaje por el altavoz',
    note: 'Nota',
    panic: 'Botón de pánico activado por un vecino',
    resident_report: 'Reporte de un vecino',
    ai_review_requested: 'IA revisando cámaras del sector',
    ai_review: 'Revisión IA de cámara en vivo',
    tamper: 'Cámara movida o tapada',
    view_rebaselined: 'Nueva vista de referencia',
    view_rotated: 'Cámara girada en el mapa',
};

/** "1 incidente", "3 incidentes". Never "incidente(s)": it reads like system output. */
export function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
    return `${count} ${count === 1 ? singular : pluralForm}`;
}

const relative = new Intl.RelativeTimeFormat('es-AR', { numeric: 'auto' });

export function timeAgo(iso: string | null): string {
    if (!iso) return '—';
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);
    if (abs < 60) return relative.format(seconds, 'second');
    if (abs < 3600) return relative.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return relative.format(Math.round(seconds / 3600), 'hour');
    return relative.format(Math.round(seconds / 86400), 'day');
}

export function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'medium' });
}

export function formatDuration(seconds: number | null): string {
    if (seconds === null) return '—';
    if (seconds < 60) return `${seconds} s`;
    if (seconds >= 3600) return `${Math.floor(seconds / 3600)} h ${Math.round((seconds % 3600) / 60)} min`;
    return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
}
