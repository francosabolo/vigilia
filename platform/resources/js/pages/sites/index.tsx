import InputError from '@/components/input-error';
import { ItemActionHint, ItemActionLink, ItemActions } from '@/components/item-action';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { siteKinds, siteNoun, timeAgo, type EdgeStatus, type SiteKind } from '@/lib/seguria';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Cctv, Cpu } from 'lucide-react';
import { type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Propiedades', href: '/sites' }];

interface SiteRow {
    id: number;
    name: string;
    kind: SiteKind;
    client: string | null;
    open_incidents: number;
    cameras_total: number;
    cameras_online: number;
    edges: EdgeStatus[];
}

interface ClientOption {
    id: number;
    name: string;
}

export default function SitesIndex({ sites, canCreate, clients }: { sites: SiteRow[]; canCreate: boolean; clients: ClientOption[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Propiedades" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="font-display text-3xl font-semibold">Propiedades</h1>
                    {canCreate && (
                        <div className="flex gap-2">
                            <CreateSite clients={clients} />
                            <Button asChild>
                                <Link href="/onboarding">Alta guiada</Link>
                            </Button>
                        </div>
                    )}
                </div>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {sites.map((site) => (
                        <div key={site.id} className="bg-card flex flex-col rounded-xl border">
                            <Link
                                href={`/sites/${site.id}`}
                                className="group hover:bg-accent flex flex-1 flex-col gap-3 rounded-t-xl p-4 transition-colors"
                            >
                                <div className="flex items-center justify-between">
                                    <div className="min-w-0">
                                        <h2 className="font-display truncate text-xl font-semibold">{site.name}</h2>
                                        {site.client && <p className="text-muted-foreground truncate text-sm">{site.client}</p>}
                                    </div>
                                    {site.open_incidents > 0 && (
                                        <span className="text-pending flex shrink-0 items-center gap-1.5 text-sm font-semibold">
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
                                    Ver {siteNoun(site.kind)}
                                </ItemActionHint>
                            </Link>
                            {canCreate && (
                                <ItemActions className="border-t px-2 py-1.5">
                                    <ItemActionLink kind="edit" href={`/sites/${site.id}?tab=ajustes`}>
                                        Editar o dar de baja
                                    </ItemActionLink>
                                </ItemActions>
                            )}
                        </div>
                    ))}
                    {sites.length === 0 && (
                        <p className="text-muted-foreground">
                            {canCreate ? 'Agregá la primera propiedad para empezar a vigilarlo.' : 'No tenés edificios ni countries asignados.'}
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function CreateSite({ clients }: { clients: ClientOption[] }) {
    const form = useForm({ name: '', kind: 'building' as SiteKind, address: '', client_id: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/sites');
    }

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">Alta rápida</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Agregar propiedad</DialogTitle>
                        <DialogDescription>Después dibujás el perímetro en el mapa y ubicás las cámaras.</DialogDescription>
                    </DialogHeader>
                    <fieldset className="grid gap-2 sm:grid-cols-3">
                        <legend className="mb-1 text-sm font-medium">Tipo</legend>
                        {siteKinds.map(({ id: value, label, help }) => (
                            <label
                                key={value}
                                className="has-[:checked]:border-foreground has-[:checked]:bg-muted/60 flex cursor-pointer gap-3 rounded-xl border p-3"
                            >
                                <input
                                    type="radio"
                                    name="kind"
                                    checked={form.data.kind === value}
                                    onChange={() => form.setData('kind', value)}
                                    className="accent-foreground mt-1"
                                />
                                <span>
                                    <span className="block text-sm font-semibold">{label}</span>
                                    <span className="text-muted-foreground block text-sm">{help}</span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <div className="grid gap-1.5">
                        <Label htmlFor="site-name">Nombre</Label>
                        <Input
                            id="site-name"
                            placeholder={siteKinds.find((kind) => kind.id === form.data.kind)?.placeholder}
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="site-address">Dirección</Label>
                        <Input id="site-address" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                        <InputError message={form.errors.address} />
                    </div>
                    {clients.length > 0 && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="site-client">Cliente</Label>
                            <select
                                id="site-client"
                                value={form.data.client_id}
                                onChange={(e) => form.setData('client_id', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Sin cliente por ahora</option>
                                {clients.map((client) => (
                                    <option key={client.id} value={client.id}>
                                        {client.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.client_id} />
                        </div>
                    )}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Agregar y dibujar el perímetro
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
