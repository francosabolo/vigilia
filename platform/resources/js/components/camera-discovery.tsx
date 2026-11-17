import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { timeAgo } from '@/lib/vigilia';
import { router, useForm, usePoll } from '@inertiajs/react';
import { useState } from 'react';

export interface DiscoveredCamera {
    address: string;
    name: string | null;
}

export interface CameraSetupRow {
    id: number;
    name: string;
    host: string;
    status: 'pending' | 'sent' | 'done' | 'failed';
    result: string | null;
}

const BRANDS = [
    ['hikvision', 'Hikvision / Ezviz'],
    ['dahua', 'Dahua / Imou'],
    ['tapo', 'TP-Link Tapo'],
    ['reolink', 'Reolink'],
    ['other', 'Otra (URL RTSP)'],
] as const;

const STATUS: Record<CameraSetupRow['status'], string> = {
    pending: 'Esperando al equipo',
    sent: 'Agregándola',
    done: 'Agregada',
    failed: 'No se pudo agregar',
};

/**
 * K8 — find the cameras on the property's network and add them without touching Frigate's config by hand.
 */
export function CameraDiscovery({
    edgeId,
    discovering,
    discovered,
    discoveredAt,
    setups,
    knownHosts,
}: {
    edgeId: number;
    discovering: boolean;
    discovered: DiscoveredCamera[];
    discoveredAt: string | null;
    setups: CameraSetupRow[];
    knownHosts: string[];
}) {
    const waiting = discovering || setups.some((setup) => setup.status === 'pending' || setup.status === 'sent');
    usePoll(3000, { only: ['sites'] }, { autoStart: waiting });
    const [adding, setAdding] = useState<string | null>(null);
    const fresh = discovered.filter(
        (camera) => !knownHosts.includes(camera.address) && !setups.some((setup) => setup.host === camera.address && setup.status !== 'failed'),
    );

    return (
        <div className="bg-muted/50 flex flex-col gap-2 rounded-lg px-3 py-2 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={discovering}
                    onClick={() => router.post(`/edges/${edgeId}/discover`, {}, { preserveScroll: true })}
                >
                    {discovering ? 'Buscando cámaras en la red…' : 'Buscar cámaras en la red'}
                </Button>
                {discoveredAt && !discovering && (
                    <span className="text-muted-foreground">
                        Última búsqueda {timeAgo(discoveredAt)}: {discovered.length === 1 ? '1 cámara' : `${discovered.length} cámaras`}
                        {fresh.length > 0 ? `, ${fresh.length} sin agregar.` : ', todas agregadas.'}
                    </span>
                )}
            </div>
            {fresh.map((camera) => (
                <div key={camera.address} className="flex flex-col gap-2 border-t pt-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            <span className="font-medium">{camera.address}</span>
                            {camera.name && <span className="text-muted-foreground"> · {camera.name}</span>}
                        </span>
                        {adding !== camera.address && (
                            <Button size="sm" onClick={() => setAdding(camera.address)}>
                                Agregar
                            </Button>
                        )}
                    </div>
                    {adding === camera.address && <AddCameraForm edgeId={edgeId} host={camera.address} onDone={() => setAdding(null)} />}
                </div>
            ))}
            {setups.length > 0 && (
                <ul className="flex flex-col gap-0.5 border-t pt-2">
                    {setups.map((setup) => (
                        <li key={setup.id} className="flex flex-wrap justify-between gap-2">
                            <span>
                                {setup.name} <span className="text-muted-foreground">({setup.host})</span>
                            </span>
                            <span
                                className={setup.status === 'failed' ? 'text-alarm' : setup.status === 'done' ? 'text-ok' : 'text-muted-foreground'}
                            >
                                {STATUS[setup.status]}
                                {setup.status === 'failed' && setup.result ? `: ${setup.result}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function AddCameraForm({ edgeId, host, onDone }: { edgeId: number; host: string; onDone: () => void }) {
    const form = useForm({ host, name: '', brand: 'hikvision', user: 'admin', password: '', custom_url: '' });

    return (
        <form
            className="grid gap-2 sm:grid-cols-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/edges/${edgeId}/cameras`, { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <div>
                <Input
                    aria-label="Nombre"
                    placeholder="Nombre, ej: Portón de entrada"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} />
            </div>
            <select
                aria-label="Marca"
                value={form.data.brand}
                onChange={(e) => form.setData('brand', e.target.value)}
                className="border-input bg-background h-9 rounded-md border px-2"
            >
                {BRANDS.map(([value, label]) => (
                    <option key={value} value={value}>
                        {label}
                    </option>
                ))}
            </select>
            <Input
                aria-label="Usuario de la cámara"
                placeholder="Usuario"
                value={form.data.user}
                onChange={(e) => form.setData('user', e.target.value)}
            />
            <div>
                <Input
                    aria-label="Contraseña de la cámara"
                    type="password"
                    autoComplete="off"
                    placeholder="Contraseña de la cámara"
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                />
                <InputError message={form.errors.password} />
            </div>
            {form.data.brand === 'other' && (
                <div className="sm:col-span-2">
                    <Input
                        aria-label="URL RTSP"
                        placeholder="rtsp://{user}:{password}@{host}:554/..."
                        value={form.data.custom_url}
                        onChange={(e) => form.setData('custom_url', e.target.value)}
                    />
                    <InputError message={form.errors.custom_url} />
                </div>
            )}
            <p className="text-muted-foreground text-xs sm:col-span-2">
                La contraseña viaja cifrada al equipo del lugar y se borra de Vigilia en cuanto la cámara queda agregada.
            </p>
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Agregar a Vigilia
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                    Cancelar
                </Button>
            </div>
        </form>
    );
}
