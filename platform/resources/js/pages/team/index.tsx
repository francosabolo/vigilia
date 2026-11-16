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
import { timeAgo } from '@/lib/seguria';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Headset, ShieldAlert, ShieldCheck, Users } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Equipo', href: '/team' }];

type Role = 'admin' | 'operator' | 'guard' | 'site_admin';

const ROLES: { value: Role; label: string; help: string }[] = [
    { value: 'operator', label: 'Operador de la central', help: 'Ve todos los edificios y countries y atiende las alertas escaladas.' },
    { value: 'guard', label: 'Guardia', help: 'Recibe las alertas de su edificio o country y responde.' },
    { value: 'site_admin', label: 'Administración del lugar', help: 'Gestiona vecinos, zonas y horarios de su edificio o country.' },
    { value: 'admin', label: 'Administrador de la central', help: 'Todo lo anterior, más el equipo y el alta de lugares y equipos.' },
];
const roleLabel = (role: Role) => ROLES.find((item) => item.value === role)?.label ?? role;
const needsSite = (role: Role) => role === 'guard' || role === 'site_admin';

interface Member {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: Role;
    site: { id: number; name: string } | null;
    two_factor: boolean;
    last_login_at: string | null;
    is_me: boolean;
}

interface SiteOption {
    id: number;
    name: string;
}

export default function TeamIndex({ members, sites }: { members: Member[]; sites: SiteOption[] }) {
    const central = members.filter((member) => !needsSite(member.role));
    const onSite = members.filter((member) => needsSite(member.role));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Equipo" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">Equipo</h1>
                        <p className="text-muted-foreground">Quién atiende las alertas y quién configura cada lugar.</p>
                    </div>
                    <MemberDialog sites={sites} />
                </div>

                <MemberList title="Central" icon={Headset} members={central} sites={sites} empty="Todavía no hay nadie en la central." />
                <MemberList
                    title="En los edificios y countries"
                    icon={Users}
                    members={onSite}
                    sites={sites}
                    empty="Invitá a las guardias para que reciban las alertas de su lugar."
                />
            </div>
        </AppLayout>
    );
}

function MemberList({
    title,
    icon,
    members,
    sites,
    empty,
}: {
    title: string;
    icon: typeof Users;
    members: Member[];
    sites: SiteOption[];
    empty: string;
}) {
    return (
        <section className="flex flex-col gap-3">
            <SectionTitle icon={icon}>{title}</SectionTitle>
            {members.length === 0 ? (
                <p className="text-muted-foreground text-sm">{empty}</p>
            ) : (
                <ul className="bg-card divide-y rounded-2xl border">
                    {members.map((member) => (
                        <li key={member.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                            <div className="min-w-48 flex-1">
                                <p className="font-semibold">
                                    {member.name}
                                    {member.is_me && <span className="text-muted-foreground font-normal"> (vos)</span>}
                                </p>
                                <p className="text-muted-foreground text-sm">{member.email}</p>
                            </div>
                            <div className="min-w-44 text-sm">
                                <p>{roleLabel(member.role)}</p>
                                {member.site && <p className="text-muted-foreground">{member.site.name}</p>}
                            </div>
                            <div className="min-w-40 text-sm">
                                {member.two_factor ? (
                                    <span className="text-ok flex items-center gap-1.5">
                                        <ShieldCheck className="size-4" /> Dos pasos activada
                                    </span>
                                ) : (
                                    <span className="text-pending flex items-center gap-1.5">
                                        <ShieldAlert className="size-4" /> Sin dos pasos
                                    </span>
                                )}
                                <p className="text-muted-foreground">
                                    {member.last_login_at ? `Entró ${timeAgo(member.last_login_at)}` : 'Nunca entró'}
                                </p>
                            </div>
                            <ItemActions>
                                <MemberDialog sites={sites} member={member} />
                                {!member.is_me && <RemoveMember member={member} />}
                            </ItemActions>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Invite (no member) or edit (member) — same fields, the e-mail only on invite. */
function MemberDialog({ sites, member }: { sites: SiteOption[]; member?: Member }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: member?.name ?? '',
        email: member?.email ?? '',
        phone: member?.phone ?? '',
        role: (member?.role ?? 'guard') as Role,
        site_id: member?.site?.id ? String(member.site.id) : '',
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
                {member ? <ItemActionButton kind="edit" aria-label={`Editar a ${member.name}`} /> : <Button>Invitar al equipo</Button>}
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{member ? `Editar a ${member.name}` : 'Invitar al equipo'}</DialogTitle>
                        <DialogDescription>
                            {member ? 'El email no se cambia acá: es con el que entra.' : 'Le llega un mail para crear su contraseña.'}
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
                            <Label htmlFor={`${prefix}-site`}>Edificio o country</Label>
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
                        Pierde el acceso a Seguria y deja de recibir alertas. Las acciones que registró en los incidentes se conservan.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => router.delete(`/team/${member.id}`, { preserveScroll: true })}>
                        Quitar del equipo
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
