import { AddressFields } from '@/components/address-fields';
import { ClientsMap, LocationPicker } from '@/components/clients-map';
import { SectionTitle } from '@/components/icon-circle';
import InputError from '@/components/input-error';
import { ItemActionButton, ItemActions } from '@/components/item-action';
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
import { fullAddress } from '@/lib/address';
import { type LatLng } from '@/lib/geo';
import { plural, siteNoun, timeAgo, type SiteKind } from '@/lib/vigilia';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Briefcase, Building2, Cctv, MapPin, UserRound } from 'lucide-react';
import { useState, type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clientes', href: '/clients' }];

interface SiteRow {
    id: number;
    client_id: number | null;
    name: string;
    kind: SiteKind;
    position: LatLng | null;
    cameras: number;
    cameras_online: number;
    open_incidents: number;
}

interface ClientAdmin {
    id: number;
    name: string;
    email: string;
    last_login_at: string | null;
}

interface Client {
    id: number;
    name: string;
    address: string | null;
    city: string | null;
    province: string | null;
    position: LatLng | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    notes: string | null;
    sites: SiteRow[];
    admins: ClientAdmin[];
}

interface Props {
    clients: Client[];
    unassignedSites: SiteRow[];
}

export default function ClientsIndex({ clients, unassignedSites }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clientes" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">Clientes</h1>
                        <p className="text-muted-foreground">Las administraciones que contratan el servicio y los lugares de cada una.</p>
                    </div>
                    <ClientDialog />
                </div>

                <ClientsMap clients={clients} />

                {clients.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                        <Briefcase className="text-muted-foreground size-6" />
                        <p className="text-lg font-semibold">Todavía no cargaste clientes</p>
                        <p className="text-muted-foreground max-w-md text-sm">
                            Agregá la administración de cada consorcio o country y asignale sus lugares. Después podés invitar a su gente para que
                            siga los incidentes de sus lugares, y solo esos.
                        </p>
                    </div>
                ) : (
                    clients.map((client) => <ClientCard key={client.id} client={client} unassignedSites={unassignedSites} />)
                )}

                {unassignedSites.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <SectionTitle icon={Building2}>Lugares sin cliente</SectionTitle>
                        <ul className="bg-card divide-y rounded-2xl border">
                            {unassignedSites.map((site) => (
                                <li key={site.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                                    <SiteSummary site={site} />
                                    {clients.length > 0 && <AssignSite site={site} clients={clients} />}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function ClientCard({ client, unassignedSites }: { client: Client; unassignedSites: SiteRow[] }) {
    const contact = [client.contact_name, client.contact_phone, client.contact_email].filter(Boolean).join(', ');
    const fallback = client.position ?? client.sites.find((site) => site.position)?.position ?? null;
    const open = client.sites.reduce((total, site) => total + site.open_incidents, 0);

    return (
        <section className="bg-card flex flex-col rounded-2xl border">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b px-4 py-4">
                <div className="min-w-0">
                    <h2 className="font-display text-2xl font-semibold">{client.name}</h2>
                    <p className="text-muted-foreground text-sm">
                        <MapPin className="mr-1 inline size-3.5" />
                        {fullAddress(client) || 'Sin domicilio'}
                    </p>
                    <p className="text-muted-foreground text-sm">{contact || 'Sin datos de contacto'}</p>
                </div>
                <div className="flex items-center gap-4">
                    {open > 0 && (
                        <span className="text-pending flex items-center gap-1.5 text-sm font-semibold">
                            <span className="bg-pending size-2 rounded-full" />
                            {plural(open, 'incidente abierto', 'incidentes abiertos')}
                        </span>
                    )}
                    <ItemActions>
                        <ClientDialog client={client} fallback={fallback} />
                        <RemoveClient client={client} />
                    </ItemActions>
                </div>
            </header>

            <div className="grid gap-6 p-4 md:grid-cols-2">
                <div className="flex flex-col gap-2">
                    <h3 className="text-sm font-semibold">Lugares</h3>
                    {client.sites.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Todavía no tiene lugares asignados.</p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {client.sites.map((site) => (
                                <li key={site.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2.5">
                                    <SiteSummary site={site} />
                                    <UnassignSite site={site} client={client} />
                                </li>
                            ))}
                        </ul>
                    )}
                    {unassignedSites.length > 0 && <AddSite client={client} sites={unassignedSites} />}
                </div>

                <div className="flex flex-col gap-2">
                    <h3 className="text-sm font-semibold">Su gente en Vigilia</h3>
                    {client.admins.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Nadie de la administración tiene acceso. Invitalos para que vean los incidentes y el informe de sus lugares.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {client.admins.map((admin) => (
                                <li key={admin.id} className="flex items-center gap-3 px-3 py-2.5">
                                    <UserRound className="text-muted-foreground size-4 shrink-0" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold">{admin.name}</p>
                                        <p className="text-muted-foreground truncate text-sm">
                                            {admin.email}, {admin.last_login_at ? `entró ${timeAgo(admin.last_login_at)}` : 'nunca entró'}
                                        </p>
                                    </div>
                                    <RemoveAdmin client={client} admin={admin} />
                                </li>
                            ))}
                        </ul>
                    )}
                    <InviteAdmin client={client} />
                </div>
            </div>
        </section>
    );
}

function UnassignSite({ site, client }: { site: SiteRow; client: Client }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="delete" aria-label={`Quitar ${site.name} de ${client.name}`}>
                    Quitar
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        ¿Quitar {site.name} de {client.name}?
                    </DialogTitle>
                    <DialogDescription>
                        {site.name} sigue vigilado por la central. La gente de {client.name} deja de verlo.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() => router.patch(`/sites/${site.id}/client`, { client_id: null }, { preserveScroll: true })}
                    >
                        Quitar del cliente
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SiteSummary({ site }: { site: SiteRow }) {
    return (
        <div className="flex min-w-48 flex-1 items-center gap-3">
            <Link href={`/sites/${site.id}`} className="min-w-0 flex-1 hover:underline">
                <p className="truncate text-sm font-semibold">{site.name}</p>
                <p className="text-muted-foreground flex items-center gap-1.5 text-sm">
                    <Cctv className="size-3.5" />
                    {site.cameras_online}/{site.cameras} cámaras, {siteNoun(site.kind)}
                </p>
            </Link>
            {site.open_incidents > 0 && (
                <span className="text-pending flex shrink-0 items-center gap-1.5 text-sm font-semibold">
                    <span className="bg-pending size-2 rounded-full" />
                    {site.open_incidents}
                </span>
            )}
        </div>
    );
}

function AddSite({ client, sites }: { client: Client; sites: SiteRow[] }) {
    return (
        <select
            aria-label={`Agregar un lugar a ${client.name}`}
            value=""
            onChange={(e) => e.target.value && router.patch(`/sites/${e.target.value}/client`, { client_id: client.id }, { preserveScroll: true })}
            className="border-input bg-background text-muted-foreground h-9 self-start rounded-md border px-3 text-sm"
        >
            <option value="">Agregar un lugar…</option>
            {sites.map((site) => (
                <option key={site.id} value={site.id}>
                    {site.name}
                </option>
            ))}
        </select>
    );
}

function AssignSite({ site, clients }: { site: SiteRow; clients: Client[] }) {
    return (
        <select
            aria-label={`Asignar ${site.name} a un cliente`}
            value=""
            onChange={(e) => e.target.value && router.patch(`/sites/${site.id}/client`, { client_id: e.target.value }, { preserveScroll: true })}
            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
        >
            <option value="">Asignar a un cliente…</option>
            {clients.map((client) => (
                <option key={client.id} value={client.id}>
                    {client.name}
                </option>
            ))}
        </select>
    );
}

function ClientDialog({ client, fallback = null }: { client?: Client; fallback?: LatLng | null }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: client?.name ?? '',
        address: client?.address ?? '',
        city: client?.city ?? '',
        province: client?.province ?? '',
        lat: client?.position?.[0] ?? (null as number | null),
        lng: client?.position?.[1] ?? (null as number | null),
        contact_name: client?.contact_name ?? '',
        contact_phone: client?.contact_phone ?? '',
        contact_email: client?.contact_email ?? '',
        notes: client?.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!client) form.reset();
            },
        };
        if (client) form.patch(`/clients/${client.id}`, options);
        else form.post('/clients', options);
    }

    const prefix = client ? `client-${client.id}` : 'new-client';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {client ? <ItemActionButton kind="edit" aria-label={`Editar ${client.name}`} /> : <Button>Agregar cliente</Button>}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{client ? `Editar ${client.name}` : 'Agregar cliente'}</DialogTitle>
                        <DialogDescription>La administración del consorcio o del country que contrata el servicio.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${prefix}-name`}>Nombre</Label>
                        <Input
                            id={`${prefix}-name`}
                            placeholder="Administración Pérez o Country Los Álamos"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">Domicilio</p>
                        <AddressFields
                            prefix={prefix}
                            value={form.data}
                            onChange={(field, value) => form.setData(field, value)}
                            errors={form.errors}
                        />
                        {open && (
                            <LocationPicker
                                value={form.data.lat !== null && form.data.lng !== null ? [form.data.lat, form.data.lng] : null}
                                fallback={fallback}
                                address={fullAddress(form.data)}
                                onChange={(point) => form.setData((data) => ({ ...data, lat: point?.[0] ?? null, lng: point?.[1] ?? null }))}
                            />
                        )}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${prefix}-contact`}>Persona de contacto (opcional)</Label>
                        <Input
                            id={`${prefix}-contact`}
                            value={form.data.contact_name}
                            onChange={(e) => form.setData('contact_name', e.target.value)}
                        />
                        <InputError message={form.errors.contact_name} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${prefix}-phone`}>Teléfono</Label>
                            <Input
                                id={`${prefix}-phone`}
                                placeholder="+5491155550000"
                                value={form.data.contact_phone}
                                onChange={(e) => form.setData('contact_phone', e.target.value)}
                            />
                            <InputError message={form.errors.contact_phone} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${prefix}-email`}>Email</Label>
                            <Input
                                id={`${prefix}-email`}
                                type="email"
                                value={form.data.contact_email}
                                onChange={(e) => form.setData('contact_email', e.target.value)}
                            />
                            <InputError message={form.errors.contact_email} />
                        </div>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing || (!!client && !form.isDirty)}>
                            {client ? 'Guardar cambios' : 'Agregar cliente'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function InviteAdmin({ client }: { client: Client }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', email: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/clients/${client.id}/admins`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="self-start">
                    Invitar a la administración
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Invitar a alguien de {client.name}</DialogTitle>
                        <DialogDescription>
                            Ve los incidentes, las cámaras y los informes de los lugares de este cliente, y de ningún otro. Le llega un mail para
                            crear su contraseña.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`invite-${client.id}-name`}>Nombre y apellido</Label>
                        <Input
                            id={`invite-${client.id}-name`}
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`invite-${client.id}-email`}>Email</Label>
                        <Input
                            id={`invite-${client.id}-email`}
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                        />
                        <InputError message={form.errors.email} />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Enviar invitación
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RemoveAdmin({ client, admin }: { client: Client; admin: ClientAdmin }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="delete" aria-label={`Quitar a ${admin.name}`}>
                    Quitar
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Quitar a {admin.name}?</DialogTitle>
                    <DialogDescription>
                        Pierde el acceso a los lugares de {client.name}. Lo que anotó en los incidentes se conserva.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/clients/${client.id}/admins/${admin.id}`, { preserveScroll: true })}>
                        Quitar acceso
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RemoveClient({ client }: { client: Client }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="delete" aria-label={`Eliminar ${client.name}`}>
                    Eliminar
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Eliminar {client.name}?</DialogTitle>
                    <DialogDescription>
                        {client.sites.length > 0
                            ? `Sus ${plural(client.sites.length, 'lugar', 'lugares')} siguen vigilados y quedan sin cliente. `
                            : ''}
                        {client.admins.length > 0 ? `${plural(client.admins.length, 'persona pierde', 'personas pierden')} el acceso. ` : ''}
                        No se puede deshacer.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/clients/${client.id}`, { preserveScroll: true })}>
                        Eliminar cliente
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
