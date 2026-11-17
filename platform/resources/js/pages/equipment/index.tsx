import { CameraDiscovery, type CameraSetupRow, type DiscoveredCamera } from '@/components/camera-discovery';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Cctv, Cpu } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Equipos', href: '/equipment' }];

interface Device {
    type: 'camera' | 'edge';
    id: number;
    name: string;
    model: string | null;
    serial: string | null;
    installed_at: string | null;
    warranty_until: string | null;
    framing_review: { rating: string; recommendations: string[] } | null;
    framing_reviewed_at: string | null;
    version?: string | null;
    update_requested_at?: string | null;
    online?: boolean;
    host?: string | null;
    discovering?: boolean;
    discovered?: DiscoveredCamera[];
    discovered_at?: string | null;
    setups?: CameraSetupRow[];
}

interface Props {
    latestEdgeVersion: string;
    sites: { id: number; name: string; client: string | null; devices: Device[] }[];
}

const soon = (date: string | null) => !!date && new Date(date + 'T12:00') < new Date(Date.now() + 60 * 86400 * 1000);

/**
 * K13 inventory, K10 framing review, K12 camera replacement and E6 edge updates, per property.
 */
export default function Equipment({ latestEdgeVersion, sites }: Props) {
    const { flash } = usePage<{ flash: { notice: string | null } }>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Equipos" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">Equipos</h1>
                    <p className="text-muted-foreground">
                        Cada cámara y equipo edge con su modelo, número de serie y garantía. La IA revisa el encuadre y desde acá se reemplaza una
                        cámara rota o se actualiza un equipo.
                    </p>
                </div>
                {flash?.notice && <p className="border-ok bg-ok/10 rounded-lg border px-4 py-2 text-sm">{flash.notice}</p>}
                {sites.map((site) => (
                    <section key={site.id} className="flex flex-col gap-2">
                        <h2 className="text-lg font-semibold">
                            {site.name}
                            {site.client && <span className="text-muted-foreground font-normal"> · {site.client}</span>}
                        </h2>
                        <ul className="bg-card divide-y rounded-xl border">
                            {site.devices.map((device) => (
                                <DeviceRow
                                    key={`${device.type}-${device.id}`}
                                    device={device}
                                    latest={latestEdgeVersion}
                                    siblings={site.devices.filter((other) => other.type === 'camera' && other.id !== device.id)}
                                    knownHosts={site.devices.map((other) => other.host).filter((host): host is string => !!host)}
                                />
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}

function DeviceRow({ device, latest, siblings, knownHosts }: { device: Device; latest: string; siblings: Device[]; knownHosts: string[] }) {
    const form = useForm({
        model: device.model ?? '',
        serial: device.serial ?? '',
        installed_at: device.installed_at ?? '',
        warranty_until: device.warranty_until ?? '',
    });
    const [replacing, setReplacing] = useState(false);
    const save = () => form.isDirty && form.patch(`/equipment/${device.type}/${device.id}`, { preserveScroll: true });
    const Icon = device.type === 'edge' ? Cpu : Cctv;

    return (
        <li className="flex flex-col gap-2 px-4 py-3">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span className="flex min-w-40 flex-1 items-center gap-2 font-medium">
                    <Icon className="text-muted-foreground size-4" />
                    {device.name}
                    {device.type === 'camera' && <span className={cn('size-2 rounded-full', device.online ? 'bg-ok' : 'bg-alarm')} />}
                    {device.type === 'edge' && <span className="text-muted-foreground text-sm font-normal">versión {device.version ?? '—'}</span>}
                </span>
                <Input
                    aria-label="Modelo"
                    placeholder="Modelo"
                    value={form.data.model}
                    onChange={(e) => form.setData('model', e.target.value)}
                    onBlur={save}
                    className="h-8 w-40"
                />
                <Input
                    aria-label="Número de serie"
                    placeholder="N.º de serie"
                    value={form.data.serial}
                    onChange={(e) => form.setData('serial', e.target.value)}
                    onBlur={save}
                    className="h-8 w-36"
                />
                <label className="text-muted-foreground flex items-center gap-1 text-xs">
                    Instalado
                    <Input
                        type="date"
                        value={form.data.installed_at}
                        onChange={(e) => form.setData('installed_at', e.target.value)}
                        onBlur={save}
                        className="h-8 w-36"
                    />
                </label>
                <label
                    className={cn(
                        'flex items-center gap-1 text-xs',
                        soon(form.data.warranty_until) ? 'text-pending font-semibold' : 'text-muted-foreground',
                    )}
                >
                    Garantía hasta
                    <Input
                        type="date"
                        value={form.data.warranty_until}
                        onChange={(e) => form.setData('warranty_until', e.target.value)}
                        onBlur={save}
                        className="h-8 w-36"
                    />
                </label>
                {device.type === 'edge' && device.version !== latest && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!!device.update_requested_at}
                        onClick={() => router.post(`/edges/${device.id}/update`, {}, { preserveScroll: true })}
                    >
                        {device.update_requested_at ? `Actualización pedida ${timeAgo(device.update_requested_at)}` : `Actualizar a ${latest}`}
                    </Button>
                )}
                {device.type === 'camera' && (
                    <>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.post(`/cameras/${device.id}/framing`, {}, { preserveScroll: true })}
                        >
                            Revisar encuadre con IA
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setReplacing((value) => !value)}>
                            Reemplazar
                        </Button>
                    </>
                )}
            </div>
            {device.type === 'edge' && (
                <CameraDiscovery
                    edgeId={device.id}
                    discovering={!!device.discovering}
                    discovered={device.discovered ?? []}
                    discoveredAt={device.discovered_at ?? null}
                    setups={device.setups ?? []}
                    knownHosts={knownHosts}
                />
            )}
            {device.framing_review && (
                <div className="bg-muted rounded-lg px-3 py-2 text-sm">
                    <span className="font-semibold">Encuadre {device.framing_review.rating}</span>
                    {device.framing_reviewed_at && <span className="text-muted-foreground"> · revisado {timeAgo(device.framing_reviewed_at)}</span>}
                    {device.framing_review.recommendations.length > 0 && (
                        <ul className="mt-1 list-disc pl-5">
                            {device.framing_review.recommendations.map((recommendation) => (
                                <li key={recommendation}>{recommendation}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            {replacing && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span>La cámara nueva, ya reportada por el equipo:</span>
                    <select
                        aria-label="Cámara nueva"
                        defaultValue=""
                        onChange={(e) =>
                            e.target.value &&
                            router.post(
                                `/cameras/${device.id}/replace`,
                                { new_camera_id: Number(e.target.value) },
                                { preserveScroll: true, onSuccess: () => setReplacing(false) },
                            )
                        }
                        className="border-input bg-background h-8 rounded-md border px-2"
                    >
                        <option value="">Elegí cuál la reemplaza</option>
                        {siblings.map((sibling) => (
                            <option key={sibling.id} value={sibling.id}>
                                {sibling.name}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted-foreground">Conserva las zonas, la ubicación y el historial de {device.name}.</span>
                </div>
            )}
        </li>
    );
}
