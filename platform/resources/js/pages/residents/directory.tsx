import { ResidentsPanel, type PanelResident, type SentMessage } from '@/components/console/residents-panel';
import AppLayout from '@/layouts/app-layout';
import { type LatLng } from '@/lib/geo';
import { hasUnits, type SiteKind } from '@/lib/vigilia';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Vecinos', href: '/residents' }];

interface Props {
    site: { id: number; name: string; kind: SiteKind; perimeter: LatLng[]; center: LatLng | null } | null;
    sites?: { id: number; name: string }[];
    residents?: PanelResident[];
    sectors?: string[];
    messages?: SentMessage[];
    canManage?: boolean;
}

/**
 * L11 — the residents of the property in view, one click from anywhere.
 */
export default function ResidentDirectory({ site, sites = [], residents = [], sectors = [], messages = [], canManage = false }: Props) {
    if (!site) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Vecinos" />
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                    <h1 className="font-display text-3xl font-semibold">Vecinos</h1>
                    <p className="text-muted-foreground">Elegí la propiedad.</p>
                    <ul className="bg-card divide-y rounded-xl border">
                        {sites.map((item) => (
                            <li key={item.id}>
                                <button
                                    type="button"
                                    className="hover:bg-accent w-full px-4 py-3 text-left font-medium"
                                    onClick={() => router.put('/current-site', { site_id: item.id }, { onSuccess: () => router.visit('/residents') })}
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
            <Head title={`Vecinos de ${site.name}`} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">
                            {hasUnits(site.kind) ? 'Vecinos' : 'Personas'} de {site.name}
                        </h1>
                        <p className="text-muted-foreground">
                            Buscá por nombre, unidad, celular o patente. Avisá a uno o a varios, llamá o escribí por WhatsApp.
                        </p>
                    </div>
                    {canManage && (
                        <Link href={`/sites/${site.id}?tab=vecinos`} className="text-sm underline underline-offset-2">
                            Invitar o editar vecinos
                        </Link>
                    )}
                </div>
                {residents.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center">
                        Todavía no hay vecinos cargados en esta propiedad.
                    </p>
                ) : (
                    <ResidentsPanel
                        siteId={site.id}
                        kind={site.kind}
                        perimeter={site.perimeter}
                        center={site.center}
                        residents={residents}
                        sectors={sectors}
                        messages={messages}
                        canManage={canManage}
                        renderUsage={() => null}
                        renderActions={() => null}
                    />
                )}
            </div>
        </AppLayout>
    );
}
