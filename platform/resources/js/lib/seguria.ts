export type Severity = 'low' | 'medium' | 'high' | 'critical';
export type IncidentStatus = 'new' | 'notified' | 'acknowledged' | 'escalated' | 'resolved';

export interface IncidentSummary {
    id: number;
    source: 'detection' | 'resident_report' | 'panic' | 'tamper';
    title: string;
    status: IncidentStatus;
    severity: Severity;
    site: { id: number; name: string };
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
    resolution: 'confirmed' | 'false_alarm' | null;
    decisions: ActivityItem[];
    tamper: {
        camera_id: number;
        before_url: string | null;
        shift_deg: number | null;
        view_status: 'ok' | 'moved' | 'covered';
        can_fix: boolean;
    } | null;
    reporter: { name: string; lot: string | null; phone: string | null } | null;
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
    trace: TraceStep[];
}

export type SiteKind = 'building' | 'country';

/** Seguria works with buildings (consorcios) and countries: the interface uses each one's own words. */
export function siteNoun(kind: SiteKind | null | undefined): string {
    return kind === 'country' ? 'country' : 'edificio';
}

/** "el edificio" / "el country" */
export function theSite(kind: SiteKind | null | undefined): string {
    return `el ${siteNoun(kind)}`;
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

export type ZoneKind = 'perimeter' | 'door' | 'window' | 'sensitive';

export const zoneKinds: { id: ZoneKind; label: string; example: string; labels: string[] }[] = [
    { id: 'perimeter', label: 'Perímetro', example: 'Ej: alambrado norte', labels: ['person', 'car', 'motorcycle'] },
    { id: 'door', label: 'Puerta o portón', example: 'Ej: puerta de servicio', labels: ['person'] },
    { id: 'window', label: 'Ventana', example: 'Ej: ventanal del SUM', labels: ['person'] },
    { id: 'sensitive', label: 'Punto sensible', example: 'Ej: tablero eléctrico', labels: ['person'] },
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
    return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
}
