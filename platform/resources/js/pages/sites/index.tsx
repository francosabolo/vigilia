import { ItemActionHint } from '@/components/item-action';
import AppLayout from '@/layouts/app-layout';
import { timeAgo, type EdgeStatus } from '@/lib/seguria';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Cctv, Cpu } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Barrios', href: '/sites' }];

interface SiteRow {
    id: number;
    name: string;
    open_incidents: number;
    cameras_total: number;
    cameras_online: number;
    edges: EdgeStatus[];
}

export default function SitesIndex({ sites }: { sites: SiteRow[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Barrios" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
                <h1 className="font-display text-3xl font-semibold">Barrios</h1>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {sites.map((site) => (
                        <Link
                            key={site.id}
                            href={`/sites/${site.id}`}
                            className="group bg-card hover:bg-accent flex flex-col gap-3 rounded-xl border p-4 transition-colors"
                        >
                            <div className="flex items-center justify-between">
                                <h2 className="font-display text-xl font-semibold">{site.name}</h2>
                                {site.open_incidents > 0 && (
                                    <span className="text-pending flex items-center gap-1.5 text-sm font-semibold">
                                        <span className="bg-pending size-2 rounded-full" />
                                        {site.open_incidents} abiertos
                                    </span>
                                )}
                            </div>
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                <Cctv className="size-4" />
                                {site.cameras_online}/{site.cameras_total} cámaras online
                            </div>
                            {site.edges.map((edge) => (
                                <div key={edge.id} className="flex items-center gap-2 text-sm">
                                    <Cpu className="size-4" />
                                    <span className={`size-2 rounded-full ${edge.online ? 'bg-ok' : 'bg-alarm'}`} />
                                    {edge.name}
                                    <span className="text-muted-foreground ml-auto text-xs">
                                        {edge.online ? 'online' : `visto ${timeAgo(edge.last_seen_at)}`}
                                    </span>
                                </div>
                            ))}
                            <ItemActionHint kind="view" className="-ml-2.5 self-start">
                                Ver barrio
                            </ItemActionHint>
                        </Link>
                    ))}
                    {sites.length === 0 && <p className="text-muted-foreground">No tenés barrios asignados.</p>}
                </div>
            </div>
        </AppLayout>
    );
}
