import { AccessAuthorizationForm } from '@/components/access-authorization-form';
import { SectionTitle } from '@/components/icon-circle';
import InputError from '@/components/input-error';
import { ItemActionButton } from '@/components/item-action';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { timeAgo } from '@/lib/vigilia';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { DoorOpen, KeyRound, ListChecks, QrCode, ScrollText, ShieldAlert, UserRoundSearch } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Accesos', href: '/access' }];

interface Entry {
    id: number;
    unit: string | null;
    visiting: string | null;
    document: string | null;
    purpose: string | null;
    exited_at: string | null;
    expected_until: string | null;
    overdue: boolean;
    left_with: string | null;
    kind: string;
    kind_label: string;
    name: string;
    plate: string | null;
    status: 'allowed' | 'denied' | 'waiting';
    reason: string | null;
    resident: { name: string; lot: string | null } | null;
    guard: string | null;
    photo_url: string | null;
    at: string;
}

interface Authorization {
    id: number;
    kind: string;
    kind_label: string;
    name: string;
    plate: string | null;
    code: string;
    resident: { name: string; lot: string | null } | null;
    valid_from: string;
    valid_until: string;
    days: number[] | null;
    time_from: string | null;
    time_to: string | null;
    valid_now: boolean;
}

interface Props {
    site: { id: number; name: string; kind: string; has_access_camera: boolean } | null;
    sites?: { id: number; name: string }[];
    waiting?: Entry[];
    entries?: Entry[];
    authorizations?: Authorization[];
    residents?: { id: number; name: string; lot: string | null }[];
    watchlist?: { id: number; plate: string; reason: string | null }[];
    inside?: Entry[];
    q?: string;
    unit?: string;
    units?: string[];
}

const DAY_NAMES = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
const statusText = { allowed: 'Pasó', denied: 'No pasó', waiting: 'Esperando al vecino' };

function when(auth: Authorization): string {
    const dates =
        auth.valid_from === auth.valid_until
            ? new Date(auth.valid_from + 'T12:00').toLocaleDateString('es-AR')
            : `hasta el ${new Date(auth.valid_until + 'T12:00').toLocaleDateString('es-AR')}`;
    const days = auth.days?.length ? auth.days.map((day) => DAY_NAMES[day - 1]).join(', ') : null;
    const hours = auth.time_from || auth.time_to ? `${auth.time_from ?? '00:00'} a ${auth.time_to ?? '23:59'}` : null;
    return [dates, days, hours].filter(Boolean).join(', ');
}

export default function AccessIndex({
    site,
    sites = [],
    waiting = [],
    entries = [],
    authorizations = [],
    residents = [],
    watchlist = [],
    inside = [],
    q = '',
    unit = '',
    units = [],
}: Props) {
    const { flash } = usePage<{ flash: { notice: string | null } }>().props;
    usePoll(waiting.length > 0 ? 3000 : 15000, { only: ['waiting', 'entries', 'inside'] });

    if (!site) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Accesos" />
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                    <h1 className="font-display text-3xl font-semibold">Accesos</h1>
                    <p className="text-muted-foreground">Elegí la propiedad de la guardia que vas a atender.</p>
                    <ul className="bg-card divide-y rounded-xl border">
                        {sites.map((item) => (
                            <li key={item.id}>
                                <button
                                    type="button"
                                    className="hover:bg-accent w-full px-4 py-3 text-left font-medium"
                                    onClick={() => router.put('/current-site', { site_id: item.id }, { onSuccess: () => router.visit('/access') })}
                                >
                                    {item.name}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accesos" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">Accesos de {site.name}</h1>
                    <p className="text-muted-foreground">
                        Validá el código de cada visita, avisá al vecino cuando alguien llega sin aviso y dejá registrado quién entró.
                        {!site.has_access_camera &&
                            ' Marcá la cámara del acceso en la pestaña Cámaras para guardar la foto y la patente de cada ingreso.'}
                    </p>
                </div>

                {flash?.notice && (
                    <p
                        role="status"
                        className={cn(
                            'rounded-xl border-2 px-4 py-3 text-lg font-semibold',
                            flash.notice.startsWith('No puede') ? 'border-alarm bg-alarm/10 text-alarm' : 'border-ok bg-ok/10',
                        )}
                    >
                        {flash.notice}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <RegisterVisit siteId={site.id} residents={residents} unitNoun={site.kind === 'country' ? 'lote' : 'unidad'} />
                    <CheckIn siteId={site.id} />
                </div>

                {inside.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <SectionTitle icon={DoorOpen}>Adentro ahora ({inside.length})</SectionTitle>
                        <ul className="bg-card divide-y rounded-xl border text-sm">
                            {inside.map((entry) => (
                                <li key={entry.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5">
                                    <span className="min-w-48 flex-1">
                                        <span className="font-medium">{entry.name}</span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            → {entry.unit ?? '—'}
                                            {entry.visiting && ` (${entry.visiting})`}
                                            {entry.plate && `, patente ${entry.plate}`}
                                        </span>
                                    </span>
                                    {entry.overdue ? (
                                        <span className="bg-pending/15 text-pending rounded-full px-2.5 py-0.5 font-medium">
                                            Debía salir {entry.expected_until ? timeAgo(entry.expected_until) : ''}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">entró {timeAgo(entry.at)}</span>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(`/access/entries/${entry.id}/exit`, {}, { preserveScroll: true })}
                                    >
                                        Marcar salida
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {waiting.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <SectionTitle icon={UserRoundSearch}>Esperando respuesta del vecino</SectionTitle>
                        <ul className="border-pending/60 divide-y rounded-xl border-2">
                            {waiting.map((entry) => (
                                <li key={entry.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                    {entry.photo_url && <img src={entry.photo_url} alt="" className="aspect-video w-28 rounded object-cover" />}
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold">{entry.name}</p>
                                        <p className="text-muted-foreground text-sm">
                                            Visita a {entry.resident?.name}
                                            {entry.resident?.lot && ` (${entry.resident.lot})`}, {timeAgo(entry.at)}
                                            {entry.plate && `, patente ${entry.plate}`}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        onClick={() => router.post(`/access/entries/${entry.id}/answer`, { allow: true }, { preserveScroll: true })}
                                    >
                                        Dejar pasar
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(`/access/entries/${entry.id}/answer`, { allow: false }, { preserveScroll: true })}
                                    >
                                        No dejar pasar
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <SectionTitle icon={ListChecks}>Autorizados</SectionTitle>
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="outline">Autorizar a alguien</Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[90vh] overflow-y-auto">
                                <DialogHeader>
                                    <DialogTitle>Autorizar el ingreso</DialogTitle>
                                    <DialogDescription>Genera un código de 6 números para presentar en la guardia.</DialogDescription>
                                </DialogHeader>
                                <AccessAuthorizationForm action={`/access/${site.id}/authorizations`} residents={residents} />
                            </DialogContent>
                        </Dialog>
                    </div>
                    {authorizations.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-sm">
                            No hay nadie autorizado. Los vecinos invitan a sus visitas desde su app; los proveedores y el personal se cargan acá.
                        </p>
                    ) : (
                        <ul className="bg-card divide-y rounded-xl border text-sm">
                            {authorizations.map((auth) => (
                                <li key={auth.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                                    <span className="font-display w-20 text-lg font-semibold tabular-nums">{auth.code}</span>
                                    <div className="min-w-48 flex-1">
                                        <p className="font-medium">
                                            {auth.name} <span className="text-muted-foreground font-normal">({auth.kind_label.toLowerCase()})</span>
                                        </p>
                                        <p className="text-muted-foreground">
                                            {when(auth)}
                                            {auth.resident && `. Para ${auth.resident.name}${auth.resident.lot ? ` (${auth.resident.lot})` : ''}`}
                                            {auth.plate && `. Patente ${auth.plate}`}
                                        </p>
                                    </div>
                                    <span className={cn('text-sm', auth.valid_now ? 'text-ok' : 'text-muted-foreground')}>
                                        {auth.valid_now ? 'Puede pasar ahora' : 'Fuera de horario'}
                                    </span>
                                    <ItemActionButton
                                        kind="delete"
                                        aria-label={`Cancelar la autorización de ${auth.name}`}
                                        onClick={() => router.delete(`/access/authorizations/${auth.id}`, { preserveScroll: true })}
                                    >
                                        Cancelar
                                    </ItemActionButton>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <Watchlist siteId={site.id} plates={watchlist} />

                <EntryLog entries={entries} q={q} unit={unit} units={units} />
            </div>
        </AppLayout>
    );
}

function CheckIn({ siteId }: { siteId: number }) {
    const form = useForm({ code: '', plate: '' });
    const [scanning, setScanning] = useState(false);

    function submit(event?: FormEvent) {
        event?.preventDefault();
        form.post(`/access/${siteId}/check-in`, { preserveScroll: true, onSuccess: () => form.reset() });
    }

    return (
        <section className="bg-card flex flex-col gap-3 rounded-2xl border p-4">
            <SectionTitle icon={KeyRound}>Validar código</SectionTitle>
            <form onSubmit={submit} className="flex flex-col gap-3">
                <div className="flex gap-2">
                    <Input
                        aria-label="Código de ingreso"
                        inputMode="numeric"
                        placeholder="Código de 6 números"
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value.replace(/\s/g, ''))}
                        className="font-display h-12 text-2xl tracking-widest"
                        autoFocus
                    />
                    <Button type="button" variant="outline" className="h-12" onClick={() => setScanning(true)} aria-label="Escanear QR">
                        <QrCode className="size-5" />
                    </Button>
                </div>
                <Input
                    placeholder="Patente (opcional)"
                    value={form.data.plate}
                    onChange={(e) => form.setData('plate', e.target.value.toUpperCase())}
                />
                <InputError message={form.errors.code} />
                <Button type="submit" disabled={form.processing || form.data.code.length < 6} className="h-11">
                    <DoorOpen className="mr-1 size-4" /> Validar
                </Button>
            </form>
            {scanning && (
                <QrScanner
                    onClose={() => setScanning(false)}
                    onCode={(code) => {
                        setScanning(false);
                        form.setData('code', code);
                        router.post(
                            `/access/${siteId}/check-in`,
                            { code, plate: form.data.plate },
                            { preserveScroll: true, onSuccess: () => form.reset() },
                        );
                    }}
                />
            )}
        </section>
    );
}

/**
 * Reads the visit's QR with the device camera (BarcodeDetector, available in Chrome on Android and desktop).
 */
function QrScanner({ onCode, onClose }: { onCode: (code: string) => void; onClose: () => void }) {
    const video = useRef<HTMLVideoElement>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const Detector = (
            window as unknown as {
                BarcodeDetector?: new (options: { formats: string[] }) => { detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]> };
            }
        ).BarcodeDetector;
        if (!Detector) {
            setError('Este navegador no puede leer QR. Escribí el código de 6 números.');
            return;
        }
        let stream: MediaStream | null = null;
        let stopped = false;
        const detector = new Detector({ formats: ['qr_code'] });

        navigator.mediaDevices
            .getUserMedia({ video: { facingMode: 'environment' } })
            .then(async (media) => {
                stream = media;
                if (!video.current) return;
                video.current.srcObject = media;
                await video.current.play();
                const tick = async () => {
                    if (stopped || !video.current) return;
                    const codes = await detector.detect(video.current).catch(() => []);
                    if (codes[0]?.rawValue) onCode(codes[0].rawValue);
                    else requestAnimationFrame(tick);
                };
                tick();
            })
            .catch(() => setError('No se pudo usar la cámara. Escribí el código de 6 números.'));

        return () => {
            stopped = true;
            stream?.getTracks().forEach((track) => track.stop());
        };
    }, [onCode]);

    return (
        <div className="flex flex-col gap-2">
            {error ? (
                <p className="text-alarm text-sm">{error}</p>
            ) : (
                <video ref={video} className="aspect-video w-full rounded-lg bg-black object-cover" muted playsInline />
            )}
            <Button variant="ghost" onClick={onClose}>
                Cerrar la cámara
            </Button>
        </div>
    );
}

/**
 * D5 — the one form at the gate: who arrives, to which unit, to see whom. Let in and log, or ask the resident first.
 */
function RegisterVisit({
    siteId,
    residents,
    unitNoun,
}: {
    siteId: number;
    residents: { id: number; name: string; lot: string | null }[];
    unitNoun: string;
}) {
    const form = useForm({
        unit: '',
        resident_id: '' as number | '',
        visiting: '',
        name: '',
        document: '',
        plate: '',
        purpose: '',
        ask: false as boolean,
    });
    const atUnit = residents.filter(
        (resident) => resident.lot && form.data.unit.trim() && resident.lot.toLowerCase() === form.data.unit.trim().toLowerCase(),
    );
    const submit = (ask: boolean) => {
        form.transform((data) => ({ ...data, ask, resident_id: data.resident_id === '' ? null : data.resident_id }));
        form.post(`/access/${siteId}/visits`, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <section className="bg-card flex flex-col gap-4 rounded-2xl border p-4">
            <SectionTitle icon={UserRoundSearch}>Registrar una visita</SectionTitle>
            <div className="grid gap-3 sm:grid-cols-[11rem_1fr]">
                <div className="grid gap-1.5">
                    <Label htmlFor="visit-unit">{unitNoun === 'lote' ? 'Lote' : 'Unidad'}</Label>
                    <Input
                        id="visit-unit"
                        list="visit-units"
                        placeholder={unitNoun === 'lote' ? '42' : '4C'}
                        value={form.data.unit}
                        onChange={(e) => form.setData((data) => ({ ...data, unit: e.target.value.toUpperCase(), resident_id: '' }))}
                        className="font-display pr-2 text-lg [&::-webkit-calendar-picker-indicator]:hidden"
                    />
                    <datalist id="visit-units">
                        {[...new Set(residents.map((resident) => resident.lot).filter(Boolean))].map((lot) => (
                            <option key={lot} value={lot!} />
                        ))}
                    </datalist>
                    <InputError message={form.errors.unit} />
                </div>
                <div className="grid gap-1.5">
                    <Label>A quién visita</Label>
                    {atUnit.length > 0 ? (
                        <div className="flex flex-wrap gap-2">
                            {atUnit.map((resident) => (
                                <button
                                    key={resident.id}
                                    type="button"
                                    aria-pressed={form.data.resident_id === resident.id}
                                    onClick={() => form.setData((data) => ({ ...data, resident_id: resident.id, visiting: '' }))}
                                    className={cn(
                                        'rounded-full border px-3 py-1.5 text-sm',
                                        form.data.resident_id === resident.id
                                            ? 'bg-primary text-primary-foreground border-primary'
                                            : 'hover:bg-accent',
                                    )}
                                >
                                    {resident.name}
                                </button>
                            ))}
                            <Input
                                aria-label="Otra persona"
                                placeholder="Otra persona"
                                value={form.data.visiting}
                                onChange={(e) => form.setData((data) => ({ ...data, visiting: e.target.value, resident_id: '' }))}
                                className="h-9 w-40"
                            />
                        </div>
                    ) : (
                        <Input
                            placeholder={form.data.unit ? `Nadie registrado en ${form.data.unit}: escribí el nombre` : 'Primero escribí la unidad'}
                            value={form.data.visiting}
                            onChange={(e) => form.setData('visiting', e.target.value)}
                        />
                    )}
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="visit-name">Quién llega</Label>
                    <Input
                        id="visit-name"
                        placeholder="Nombre y apellido"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={form.errors.name} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="visit-document">DNI (opcional)</Label>
                    <Input
                        id="visit-document"
                        inputMode="numeric"
                        value={form.data.document}
                        onChange={(e) => form.setData('document', e.target.value)}
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="visit-plate">Patente (opcional)</Label>
                    <Input id="visit-plate" value={form.data.plate} onChange={(e) => form.setData('plate', e.target.value.toUpperCase())} />
                </div>
                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="visit-purpose">Motivo (opcional)</Label>
                    <Input
                        id="visit-purpose"
                        placeholder="Ej: visita, delivery, plomero"
                        value={form.data.purpose}
                        onChange={(e) => form.setData('purpose', e.target.value)}
                    />
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button onClick={() => submit(false)} disabled={form.processing || !form.data.name.trim() || !form.data.unit.trim()}>
                    <DoorOpen className="mr-1 size-4" /> Dejar pasar y registrar
                </Button>
                {form.data.resident_id !== '' && (
                    <Button variant="outline" onClick={() => submit(true)} disabled={form.processing || !form.data.name.trim()}>
                        Avisar al vecino primero
                    </Button>
                )}
            </div>
        </section>
    );
}

/**
 * A7 — plates the guard is warned about when they reach the access.
 */
function Watchlist({ siteId, plates }: { siteId: number; plates: { id: number; plate: string; reason: string | null }[] }) {
    const form = useForm({ plate: '', reason: '' });

    return (
        <section className="flex flex-col gap-3">
            <SectionTitle icon={ShieldAlert}>Patentes en alerta</SectionTitle>
            <p className="text-muted-foreground -mt-1 text-sm">
                Si una de estas patentes llega al acceso, la IA la lee en la foto y avisa a la guardia y a la central.
            </p>
            {plates.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {plates.map((plate) => (
                        <li key={plate.id} className="bg-card flex items-center gap-2 rounded-full border py-1 pr-1 pl-3 text-sm">
                            <span className="font-display font-semibold tracking-wider">{plate.plate}</span>
                            {plate.reason && <span className="text-muted-foreground">{plate.reason}</span>}
                            <ItemActionButton
                                kind="delete"
                                aria-label={`Quitar ${plate.plate} de la lista`}
                                onClick={() => router.delete(`/access/watchlist/${plate.id}`, { preserveScroll: true })}
                            >
                                Quitar
                            </ItemActionButton>
                        </li>
                    ))}
                </ul>
            )}
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/access/${siteId}/watchlist`, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="flex flex-wrap gap-2"
            >
                <Input
                    aria-label="Patente"
                    placeholder="Patente"
                    value={form.data.plate}
                    onChange={(e) => form.setData('plate', e.target.value.toUpperCase())}
                    className="w-36"
                />
                <Input
                    aria-label="Motivo"
                    placeholder="Motivo (opcional)"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    className="max-w-xs"
                />
                <Button type="submit" variant="outline" disabled={form.processing || !form.data.plate.trim()}>
                    Agregar a la lista
                </Button>
            </form>
        </section>
    );
}

function EntryLog({ entries, q, unit, units }: { entries: Entry[]; q: string; unit: string; units: string[] }) {
    const [query, setQuery] = useState(q);
    const reload = (next: { q?: string; unit?: string }) =>
        router.get(
            '/access',
            { q: (next.q ?? query) || undefined, unit: (next.unit ?? unit) || undefined },
            { preserveState: true, replace: true, only: ['entries', 'q', 'unit'] },
        );

    useEffect(() => {
        if (query === q) return;
        const timer = setTimeout(() => reload({ q: query }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, q]);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <SectionTitle icon={ScrollText}>Registro de ingresos</SectionTitle>
                <div className="flex flex-wrap gap-2">
                    <select
                        aria-label="Unidad"
                        value={unit}
                        onChange={(e) => reload({ unit: e.target.value })}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">Todas las unidades</option>
                        {units.map((item) => (
                            <option key={item} value={item}>
                                {item}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Buscar en el registro"
                        placeholder="Nombre, a quién visitó o patente"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        className="w-64"
                    />
                </div>
            </div>
            {entries.length === 0 ? (
                <p className="text-muted-foreground text-sm">{q ? `Nadie coincide con «${q}».` : 'Todavía no hay ingresos registrados.'}</p>
            ) : (
                <ul className="bg-card divide-y rounded-xl border text-sm">
                    {entries.map((entry) => (
                        <li key={entry.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5">
                            {entry.photo_url ? (
                                <img src={entry.photo_url} alt="" className="aspect-video w-24 rounded object-cover" loading="lazy" />
                            ) : (
                                <span className="bg-muted aspect-video w-24 rounded" aria-hidden />
                            )}
                            <div className="min-w-44 flex-1">
                                <p className="font-medium">
                                    {entry.name}
                                    {entry.unit && (
                                        <span className="font-normal">
                                            {' '}
                                            → {entry.unit}
                                            {entry.visiting && ` (${entry.visiting})`}
                                        </span>
                                    )}
                                    <span className="text-muted-foreground font-normal"> ({entry.kind_label.toLowerCase()})</span>
                                </p>
                                <p className="text-muted-foreground">
                                    {[
                                        entry.purpose,
                                        entry.plate && `Patente ${entry.plate}`,
                                        entry.document && `DNI ${entry.document}`,
                                        entry.guard && `atendió ${entry.guard}`,
                                        entry.exited_at && `salió ${timeAgo(entry.exited_at)}`,
                                        entry.left_with && `salió con ${entry.left_with}`,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                </p>
                                {entry.reason && entry.status !== 'allowed' && <p className="text-alarm">{entry.reason}</p>}
                            </div>
                            <span
                                className={cn(
                                    'font-medium',
                                    entry.status === 'allowed' ? 'text-ok' : entry.status === 'denied' ? 'text-alarm' : 'text-pending',
                                )}
                            >
                                {statusText[entry.status]}
                            </span>
                            <span className="text-muted-foreground w-28 text-right">{timeAgo(entry.at)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
