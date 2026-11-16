import { ItemActionLink, ItemActions } from '@/components/item-action';
import AppLayout from '@/layouts/app-layout';
import { timeAgo, zoneKindLabel, type ZoneKind } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/react';
import { VideoOff } from 'lucide-react';

interface Props {
    camera: {
        id: number;
        name: string;
        sector: string | null;
        is_online: boolean;
        view_status: 'ok' | 'moved' | 'covered';
        preview_url: string | null;
        preview_at: string | null;
        zones: { name: string; kind: ZoneKind }[];
        site: { id: number; name: string };
    };
    siblings: { id: number; name: string; sector: string | null; is_online: boolean }[];
    canManage: boolean;
    interval: number;
}

export default function CameraShow({ camera, siblings, canManage, interval }: Props) {
    // Each poll keeps the site "watched", so the edge keeps sending frames of this camera.
    usePoll(interval * 1000, { only: ['camera'] }, { keepAlive: true });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: camera.site.name, href: `/sites/${camera.site.id}` },
        { title: camera.name, href: `/cameras/${camera.id}` },
    ];
    const stale = !camera.preview_at || Date.now() - new Date(camera.preview_at).getTime() > 30_000;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${camera.name} en vivo`} />
            <div className="mx-auto grid w-full max-w-7xl gap-6 p-4 md:p-6 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div className="flex min-w-0 flex-col gap-3">
                    <header className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h1 className="font-display text-4xl leading-tight font-semibold">{camera.name}</h1>
                            <p className="text-muted-foreground flex items-center gap-2">
                                <span className={cn('size-2 rounded-full', camera.is_online && !stale ? 'bg-ok' : 'bg-alarm')} />
                                {camera.is_online && !stale
                                    ? 'En vivo'
                                    : `Sin imagen nueva${camera.preview_at ? ` desde ${timeAgo(camera.preview_at)}` : ''}`}
                                {camera.sector && `, ${camera.sector}`}
                            </p>
                        </div>
                        {canManage && (
                            <ItemActions>
                                <ItemActionLink kind="edit" href={`/cameras/${camera.id}/zones`}>
                                    Editar zonas
                                </ItemActionLink>
                            </ItemActions>
                        )}
                    </header>

                    {camera.view_status !== 'ok' && (
                        <p className="border-alarm bg-alarm/10 text-alarm rounded-lg border px-4 py-3 font-medium">
                            {camera.view_status === 'covered'
                                ? 'La cámara parece tapada o encandilada.'
                                : 'La cámara parece haberse movido: lo que ve ya no coincide con su vista de referencia.'}
                        </p>
                    )}

                    <div className="overflow-hidden rounded-xl border bg-black">
                        {camera.preview_url ? (
                            <img src={camera.preview_url} alt={`${camera.name} en vivo`} className={cn('w-full', stale && 'opacity-50 grayscale')} />
                        ) : (
                            <div className="text-muted-foreground flex aspect-video flex-col items-center justify-center gap-2">
                                <VideoOff className="size-8" />
                                Esperando imagen del edge…
                            </div>
                        )}
                    </div>
                    <p className="text-muted-foreground text-sm">
                        La imagen se actualiza cada {interval} segundos mientras esta pantalla está abierta.
                        {camera.zones.length > 0 &&
                            ` Zonas vigiladas: ${camera.zones.map((zone) => `${zone.name} (${zoneKindLabel[zone.kind].toLowerCase()})`).join(', ')}.`}
                    </p>
                </div>

                <aside className="flex flex-col gap-2">
                    <h2 className="font-semibold">Otras cámaras de {camera.site.name}</h2>
                    <nav className="bg-card divide-y rounded-lg border">
                        {siblings.map((sibling) => (
                            <Link
                                key={sibling.id}
                                href={`/cameras/${sibling.id}`}
                                aria-current={sibling.id === camera.id ? 'page' : undefined}
                                className={cn(
                                    'hover:bg-accent flex items-center gap-2 px-3 py-2.5 text-sm',
                                    sibling.id === camera.id && 'bg-accent font-semibold',
                                )}
                            >
                                <span className={cn('size-2 rounded-full', sibling.is_online ? 'bg-ok' : 'bg-alarm')} />
                                <span className="flex-1 truncate">{sibling.name}</span>
                                {sibling.sector && <span className="text-muted-foreground truncate text-xs">{sibling.sector}</span>}
                            </Link>
                        ))}
                    </nav>
                    <Link
                        href={`/cameras?site=${camera.site.id}`}
                        className="text-muted-foreground hover:text-foreground mt-1 text-sm underline-offset-4 hover:underline"
                    >
                        Ver todas en mosaico
                    </Link>
                </aside>
            </div>
        </AppLayout>
    );
}
