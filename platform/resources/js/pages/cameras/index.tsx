import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, usePoll } from '@inertiajs/react';
import { Maximize2, VideoOff, X } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cámaras', href: '/cameras' }];

interface CameraTile {
    id: number;
    name: string;
    sector: string | null;
    is_online: boolean;
    preview_url: string | null;
    preview_at: string | null;
}

interface Props {
    sites: { id: number; name: string; cameras: CameraTile[] }[];
    interval: number;
}

function isStale(previewAt: string | null): boolean {
    return !previewAt || Date.now() - new Date(previewAt).getTime() > 30_000;
}

function Tile({ camera, onExpand }: { camera: CameraTile; onExpand: () => void }) {
    const stale = isStale(camera.preview_at);

    return (
        <button
            onClick={onExpand}
            className="group focus-visible:ring-ring relative aspect-video overflow-hidden rounded-md bg-black text-left focus-visible:ring-2"
        >
            {camera.preview_url ? (
                <img src={camera.preview_url} alt={camera.name} className={cn('size-full object-cover', stale && 'opacity-40 grayscale')} />
            ) : (
                <div className="text-muted-foreground flex size-full flex-col items-center justify-center gap-2">
                    <VideoOff className="size-8" />
                    <span className="text-xs">Esperando imagen del edge…</span>
                </div>
            )}
            <div className="absolute inset-x-0 top-0 flex items-center gap-2 bg-gradient-to-b from-black/70 to-transparent p-2 text-xs text-white">
                <span className={cn('size-2 rounded-full', camera.is_online && !stale ? 'bg-ok' : 'bg-muted-foreground')} />
                <span className="font-display text-sm font-semibold">{camera.name}</span>
                {camera.sector && <span className="text-white/70">{camera.sector}</span>}
                <span className="ml-auto flex items-center gap-1 rounded bg-black/50 px-1.5 py-0.5 text-xs">
                    <Maximize2 className="size-3.5" /> Ampliar
                </span>
            </div>
            {stale && camera.preview_at && (
                <div className="text-pending absolute inset-x-0 bottom-0 p-2 text-xs">Sin imagen nueva hace más de 30 s</div>
            )}
        </button>
    );
}

export default function CamerasIndex({ sites, interval }: Props) {
    // Each poll keeps the site marked as "watched" so the edge keeps sending frames.
    usePoll(interval * 1000, { only: ['sites'] }, { keepAlive: true });
    const [expanded, setExpanded] = useState<CameraTile | null>(null);

    const total = sites.reduce((count, site) => count + site.cameras.length, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cámaras" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">Cámaras</h1>
                    <p className="text-muted-foreground text-sm">
                        {total} cámaras. La imagen se actualiza cada {interval} segundos mientras esta pantalla está abierta; el video completo se ve
                        desde la red del edificio o country.
                    </p>
                </div>

                {sites.map((site) => (
                    <section key={site.id} className="flex flex-col gap-3">
                        {sites.length > 1 && <h2 className="text-lg font-semibold">{site.name}</h2>}
                        {site.cameras.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Todavía no tiene cámaras conectadas.</p>
                        ) : (
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                {site.cameras.map((camera) => (
                                    <Tile key={camera.id} camera={camera} onExpand={() => setExpanded(camera)} />
                                ))}
                            </div>
                        )}
                    </section>
                ))}
            </div>

            {expanded && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4" onClick={() => setExpanded(null)}>
                    <button className="absolute top-4 right-4 text-white" aria-label="Cerrar">
                        <X className="size-6" />
                    </button>
                    {(() => {
                        const live = sites.flatMap((site) => site.cameras).find((camera) => camera.id === expanded.id) ?? expanded;
                        return live.preview_url ? (
                            <img src={live.preview_url} alt={live.name} className="max-h-full max-w-full rounded-lg" />
                        ) : (
                            <p className="text-white">Sin imagen todavía.</p>
                        );
                    })()}
                </div>
            )}
        </AppLayout>
    );
}
