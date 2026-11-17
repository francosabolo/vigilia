import InputError from '@/components/input-error';
import { ItemActionButton, ItemActionLink, ItemActions } from '@/components/item-action';
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
import { timeAgo } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Search, ShieldAlert, ShieldCheck } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Usuarios', href: '/team' }];

type Role = 'admin' | 'operator' | 'guard' | 'site_admin' | 'client_admin' | 'resident';
type StaffRole = Exclude<Role, 'resident'>;

const ROLES: { value: StaffRole; label: string; help: string }[] = [
    { value: 'operator', label: 'Operador de la central', help: 'Ve todas las propiedades y atiende las alertas escaladas.' },
    { value: 'guard', label: 'Guardia', help: 'Recibe las alertas de su propiedad y responde.' },
    { value: 'site_admin', label: 'Administración del lugar', help: 'Gestiona vecinos, zonas y horarios de una propiedad.' },
    { value: 'client_admin', label: 'Administración del cliente', help: 'Ve todos los lugares de su cliente: incidentes, cámaras e informes.' },
    { value: 'admin', label: 'Administrador de la central', help: 'Todo lo anterior, más usuarios, clientes y el alta de lugares y equipos.' },
];
const ROLE_LABELS: Record<Role, string> = {
    ...(Object.fromEntries(ROLES.map((role) => [role.value, role.label])) as Record<StaffRole, string>),
    resident: 'Vecino',
};
const FILTERS: Role[] = ['admin', 'operator', 'guard', 'site_admin', 'client_admin', 'resident'];
const needsSite = (role: Role) => role === 'guard' || role === 'site_admin';

interface Member {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: Role;
    site: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
    lot: string | null;
    two_factor: boolean;
    last_login_at: string | null;
    is_me: boolean;
}

interface Option {
    id: number;
    name: string;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    members: Paginated<Member>;
    filters: { q: string; role: Role | '' };
    counts: Partial<Record<Role, number>>;
    sites: Option[];
    clients: Option[];
}

export default function UsersIndex({ members, filters, counts, sites, clients }: Props) {
    const [query, setQuery] = useState(filters.q);
    const total = Object.values(counts).reduce((sum, count) => sum + (count ?? 0), 0);

    useEffect(() => {
        if (query === filters.q) return;
        const timer = setTimeout(
            () => router.get('/team', { q: query || undefined, role: filters.role || undefined }, { preserveState: true, replace: true }),
            300,
        );
        return () => clearTimeout(timer);
    }, [query, filters.q, filters.role]);

    const filterBy = (role: Role | '') => router.get('/team', { q: filters.q || undefined, role: role || undefined }, { preserveState: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuarios" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">Usuarios</h1>
                        <p className="text-muted-foreground">Quién entra a Vigilia: la central, las guardias, las administraciones y los vecinos.</p>
                    </div>
                    <MemberDialog sites={sites} clients={clients} />
                </div>

                <div className="flex flex-col gap-3">
                    <div className="relative max-w-md">
                        <Search className="text-muted-foreground absolute top-2.5 left-3 size-4" />
                        <Input
                            aria-label="Buscar usuarios"
                            placeholder="Buscar por nombre, email o lote"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            className="pl-9"
                        />
                    </div>
                    <nav className="flex flex-wrap gap-2 text-sm" aria-label="Filtrar por rol">
                        {(['', ...FILTERS] as (Role | '')[]).map((role) => (
                            <button
                                key={role || 'all'}
                                type="button"
                                onClick={() => filterBy(role)}
                                aria-pressed={filters.role === role}
                                className={cn(
                                    'rounded-full border px-3 py-1',
                                    filters.role === role
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'hover:bg-accent text-muted-foreground',
                                )}
                            >
                                {role ? ROLE_LABELS[role] : 'Todos'}{' '}
                                <span className="tabular-nums opacity-70">{role ? (counts[role] ?? 0) : total}</span>
                            </button>
                        ))}
                    </nav>
                </div>

                {members.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                        {filters.q ? `Nadie coincide con «${filters.q}».` : 'No hay usuarios con este rol.'}
                    </p>
                ) : (
                    <ul className="bg-card divide-y rounded-2xl border">
                        {members.data.map((member) => (
                            <MemberRow key={member.id} member={member} sites={sites} clients={clients} />
                        ))}
                    </ul>
                )}

                {members.last_page > 1 && (
                    <nav className="flex flex-wrap items-center gap-1 text-sm" aria-label="Páginas">
                        {members.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    preserveState
                                    className={cn('rounded-md px-3 py-1.5', link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label.replace('&laquo; Previous', '‹ Anterior').replace('Next &raquo;', 'Siguiente ›'),
                                    }}
                                />
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}

function MemberRow({ member, sites, clients }: { member: Member; sites: Option[]; clients: Option[] }) {
    const where =
        member.client?.name ?? member.site?.name ?? (member.role === 'admin' || member.role === 'operator' ? 'Todos los lugares' : 'Sin asignar');

    return (
        <li className="grid items-center gap-x-6 gap-y-2 px-4 py-3 md:grid-cols-[minmax(0,1fr)_13rem_10rem_24rem]">
            <div className="min-w-0">
                <p className="truncate font-semibold">
                    {member.name}
                    {member.is_me && <span className="text-muted-foreground font-normal"> (vos)</span>}
                </p>
                <p className="text-muted-foreground truncate text-sm">{member.email}</p>
            </div>
            <div className="min-w-0 text-sm">
                <p>{ROLE_LABELS[member.role]}</p>
                <p className="text-muted-foreground">
                    {where}
                    {member.role === 'resident' && member.lot && `, ${member.lot}`}
                </p>
            </div>
            <div className="text-sm">
                {member.role !== 'resident' &&
                    (member.two_factor ? (
                        <span className="text-ok flex items-center gap-1.5">
                            <ShieldCheck className="size-4" /> Dos pasos activada
                        </span>
                    ) : (
                        <span className="text-pending flex items-center gap-1.5">
                            <ShieldAlert className="size-4" /> Sin dos pasos
                        </span>
                    ))}
                <p className="text-muted-foreground">{member.last_login_at ? `Entró ${timeAgo(member.last_login_at)}` : 'Nunca entró'}</p>
            </div>
            <ItemActions className="md:justify-end">
                {member.role !== 'admin' && (
                    <ItemActionButton
                        kind="view"
                        aria-label={`Ver Vigilia como ${member.name}`}
                        onClick={() => router.post(`/team/${member.id}/impersonate`)}
                    >
                        Ver como
                    </ItemActionButton>
                )}
                <PersonalData member={member} />
                {member.role === 'resident' ? (
                    member.site && (
                        <ItemActionLink kind="edit" href={`/sites/${member.site.id}?tab=vecinos`}>
                            Editar en Vecinos
                        </ItemActionLink>
                    )
                ) : (
                    <>
                        <MemberDialog sites={sites} clients={clients} member={member} />
                        {!member.is_me && <RemoveMember member={member} />}
                    </>
                )}
            </ItemActions>
        </li>
    );
}

function MemberDialog({ sites, clients, member }: { sites: Option[]; clients: Option[]; member?: Member }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: member?.name ?? '',
        email: member?.email ?? '',
        phone: member?.phone ?? '',
        role: (member?.role ?? 'guard') as StaffRole,
        site_id: member?.site?.id ? String(member.site.id) : '',
        client_id: member?.client?.id ? String(member.client.id) : '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!member) form.reset();
            },
        };
        if (member) form.patch(`/team/${member.id}`, options);
        else form.post('/team', options);
    }

    const prefix = member ? `member-${member.id}` : 'invite';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {member ? <ItemActionButton kind="edit" aria-label={`Editar a ${member.name}`} /> : <Button>Invitar usuario</Button>}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{member ? `Editar a ${member.name}` : 'Invitar usuario'}</DialogTitle>
                        <DialogDescription>
                            {member
                                ? 'El email no se cambia acá: es con el que entra.'
                                : 'Le llega un mail para crear su contraseña. A los vecinos se los invita desde la pestaña Vecinos de su lugar.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-1.5">
                        <Label htmlFor={`${prefix}-name`}>Nombre y apellido</Label>
                        <Input id={`${prefix}-name`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                        <InputError message={form.errors.name} />
                    </div>
                    {!member && (
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${prefix}-email`}>Email</Label>
                            <Input
                                id={`${prefix}-email`}
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                            <InputError message={form.errors.email} />
                        </div>
                    )}

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">Rol</legend>
                        {ROLES.map((role) => (
                            <label
                                key={role.value}
                                className="has-[:checked]:border-foreground has-[:checked]:bg-muted/60 flex cursor-pointer gap-3 rounded-xl border p-3"
                            >
                                <input
                                    type="radio"
                                    name={`${prefix}-role`}
                                    value={role.value}
                                    checked={form.data.role === role.value}
                                    onChange={() => form.setData('role', role.value)}
                                    className="accent-foreground mt-1"
                                />
                                <span>
                                    <span className="block text-sm font-semibold">{role.label}</span>
                                    <span className="text-muted-foreground block text-sm">{role.help}</span>
                                </span>
                            </label>
                        ))}
                        <InputError message={form.errors.role} />
                    </fieldset>

                    {needsSite(form.data.role) && (
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${prefix}-site`}>Propiedad</Label>
                            <select
                                id={`${prefix}-site`}
                                value={form.data.site_id}
                                onChange={(e) => form.setData('site_id', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Elegí dónde trabaja</option>
                                {sites.map((site) => (
                                    <option key={site.id} value={site.id}>
                                        {site.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.site_id} />
                        </div>
                    )}

                    {form.data.role === 'client_admin' && (
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${prefix}-client`}>Cliente</Label>
                            <select
                                id={`${prefix}-client`}
                                value={form.data.client_id}
                                onChange={(e) => form.setData('client_id', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Elegí el cliente</option>
                                {clients.map((client) => (
                                    <option key={client.id} value={client.id}>
                                        {client.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.client_id} />
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor={`${prefix}-phone`}>Celular (opcional, para las alertas por WhatsApp)</Label>
                        <Input
                            id={`${prefix}-phone`}
                            placeholder="+5491155550000"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                        />
                        <InputError message={form.errors.phone} />
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing || (!!member && !form.isDirty)}>
                            {member ? 'Guardar cambios' : 'Enviar invitación'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * J5 — Ley 25.326: download everything kept about a person, or delete it.
 */
function PersonalData({ member }: { member: Member }) {
    const [confirm, setConfirm] = useState('');

    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="view" aria-label={`Datos personales de ${member.name}`}>
                    Datos
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Datos personales de {member.name}</DialogTitle>
                    <DialogDescription>
                        Para atender un pedido de acceso o de borrado (Ley 25.326). El borrado elimina la cuenta, sus contactos, notificaciones y
                        autorizaciones; los incidentes que reportó quedan en el historial sin su nombre.
                    </DialogDescription>
                </DialogHeader>
                <Button asChild variant="outline">
                    <a href={`/team/${member.id}/data`}>Descargar sus datos</a>
                </Button>
                {!member.is_me && (
                    <div className="border-alarm/40 flex flex-col gap-2 rounded-xl border p-3">
                        <Label htmlFor={`erase-${member.id}`}>Para borrar todo, escribí «{member.name}»</Label>
                        <Input id={`erase-${member.id}`} value={confirm} onChange={(e) => setConfirm(e.target.value)} />
                        <Button
                            variant="destructive"
                            disabled={confirm !== member.name}
                            onClick={() => router.delete(`/team/${member.id}/data`, { data: { confirm }, preserveScroll: true })}
                        >
                            Borrar sus datos
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function RemoveMember({ member }: { member: Member }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <ItemActionButton kind="delete" aria-label={`Quitar a ${member.name}`}>
                    Quitar
                </ItemActionButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Quitar a {member.name}?</DialogTitle>
                    <DialogDescription>
                        Pierde el acceso a Vigilia y deja de recibir alertas. Las acciones que registró en los incidentes se conservan.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/team/${member.id}`, { preserveScroll: true })}>
                        Quitar usuario
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
