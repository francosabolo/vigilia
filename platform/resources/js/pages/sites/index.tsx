import InputError from '@/components/input-error';
import { ItemActionHint } from '@/components/item-action';
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
import { timeAgo, type EdgeStatus } from '@/lib/seguria';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Cctv, Cpu } from 'lucide-react';
import { type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Edificios y countries', href: '/sites' }];

interface SiteRow {
    id: number;
    name: string;
    kind: 'building' | 'country';
    open_incidents: number;
    cameras_total: number;
    cameras_online: number;
    edges: EdgeStatus[];
}

export default function SitesIndex({ sites, canCreate }: { sites: SiteRow[]; canCreate: boolean }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edificios y countries" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="font-display text-3xl font-semibold">Edificios y countries</h1>
                    {canCreate && <CreateSite />}
                </div>
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
                                Ver {site.kind === 'country' ? 'country' : 'edificio'}
                            </ItemActionHint>
                        </Link>
                    ))}
                    {sites.length === 0 && (
                        <p className="text-muted-foreground">
                            {canCreate
                                ? 'Agregá el primer edificio o country para empezar a vigilarlo.'
                                : 'No tenés edificios ni countries asignados.'}
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function CreateSite() {
    const form = useForm({ name: '', kind: 'building' as 'building' | 'country', address: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/sites');
    }

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button>Agregar edificio o country</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Agregar edificio o country</DialogTitle>
                        <DialogDescription>Después dibujás el perímetro en el mapa y ubicás las cámaras.</DialogDescription>
                    </DialogHeader>
                    <fieldset className="grid grid-cols-2 gap-2">
                        <legend className="mb-1 text-sm font-medium">Tipo</legend>
                        {(
                            [
                                ['building', 'Edificio', 'Unidades, hall y cocheras'],
                                ['country', 'Country', 'Lotes y perímetro'],
                            ] as const
                        ).map(([value, label, help]) => (
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
                            placeholder={form.data.kind === 'country' ? 'Country Los Álamos' : 'Edificio Arenales 2150'}
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
