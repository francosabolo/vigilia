import InputError from '@/components/input-error';
import { SiteMap } from '@/components/site-map';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type LatLng } from '@/lib/geo';
import { hasUnits, siteKinds, siteNoun, timeAgo, unitNoun, type SiteKind } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

type Step = 'propiedad' | 'ubicacion' | 'equipo' | 'camaras' | 'guardia' | 'vecinos' | 'prueba';

const STEP_LABELS: Record<Step, string> = {
    propiedad: 'La propiedad',
    ubicacion: 'Dónde está',
    equipo: 'Equipo edge',
    camaras: 'Cámaras',
    guardia: 'Quién recibe las alertas',
    vecinos: 'Vecinos',
    prueba: 'Prueba',
};

interface Checklist {
    id: string;
    done: boolean;
}

interface OnboardingSite {
    id: number;
    name: string;
    kind: SiteKind;
    perimeter: LatLng[];
    center: LatLng | null;
    guard_whatsapp: string | null;
    ack_timeout_seconds: number;
    edges: { id: number; name: string; online: boolean; last_seen_at: string | null }[];
    cameras: {
        id: number;
        name: string;
        is_online: boolean;
        placed: boolean;
        zones: number;
        is_access: boolean;
        is_guard_post: boolean;
        is_checkpoint: boolean;
    }[];
}

interface Props {
    step: Step;
    steps: Step[];
    site: OnboardingSite | null;
    clients: { id: number; name: string }[];
    checklist?: Checklist[];
    newEdge?: { id: number; name: string; token: string } | null;
    edgeServerUrl?: string;
    guards?: { id: number; name: string; email: string; phone: string | null }[];
    residentsCount?: number;
    canManageEdges?: boolean;
    isAdmin?: boolean;
}

const done = (checklist: Checklist[] | undefined, id: string) => !!checklist?.find((item) => item.id === id)?.done;

function stepDone(step: Step, props: Props): boolean {
    const { site, checklist, residentsCount = 0 } = props;
    if (!site) return false;
    return {
        propiedad: true,
        ubicacion: done(checklist, 'perimeter'),
        equipo: done(checklist, 'edge_online'),
        camaras: done(checklist, 'cameras') && done(checklist, 'zones'),
        guardia: done(checklist, 'guard'),
        vecinos: residentsCount > 0,
        prueba: done(checklist, 'test'),
    }[step];
}

/**
 * K15 — guided setup of a property, from creating it to the walk test, one step at a time.
 */
export default function Onboarding(props: Props) {
    const { step, steps, site } = props;
    const { flash } = usePage<{ flash: { notice: string | null } }>().props;
    usePoll(['equipo', 'camaras', 'prueba'].includes(step) ? 4000 : 60000, { only: ['site', 'checklist', 'residentsCount', 'guards'] });

    const index = steps.indexOf(step);
    const go = (target: Step) => site && router.visit(`/sites/${site.id}/onboarding/${target}`);
    const next = steps[index + 1];
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Propiedades', href: '/sites' },
        { title: site ? `Alta guiada: ${site.name}` : 'Nueva propiedad', href: site ? `/sites/${site.id}/onboarding/${step}` : '/onboarding' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={site ? `Alta guiada: ${site.name}` : 'Nueva propiedad'} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <ol className="flex flex-wrap gap-2 text-sm" aria-label="Pasos">
                    {steps.map((item, position) => {
                        const complete = stepDone(item, props);
                        return (
                            <li key={item}>
                                <button
                                    type="button"
                                    disabled={!site}
                                    onClick={() => go(item)}
                                    aria-current={item === step ? 'step' : undefined}
                                    className={cn(
                                        'flex items-center gap-2 rounded-full border px-3 py-1.5',
                                        item === step ? 'border-foreground bg-foreground text-background font-semibold' : 'hover:bg-accent',
                                        !site && item !== step && 'opacity-50',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-5 items-center justify-center rounded-full text-xs',
                                            complete ? 'bg-ok text-white' : item === step ? 'bg-background text-foreground' : 'bg-muted',
                                        )}
                                    >
                                        {complete ? <Check className="size-3.5" /> : position + 1}
                                    </span>
                                    {STEP_LABELS[item]}
                                </button>
                            </li>
                        );
                    })}
                </ol>

                {flash?.notice && <p className="border-ok bg-ok/10 rounded-lg border px-4 py-2 text-sm font-medium">{flash.notice}</p>}

                <section className="bg-card flex flex-col gap-5 rounded-2xl border p-5 md:p-6">
                    {step === 'propiedad' && <PropertyStep clients={props.clients} />}
                    {site && step === 'ubicacion' && <LocationStep site={site} />}
                    {site && step === 'equipo' && <EdgeStep {...props} site={site} />}
                    {site && step === 'camaras' && <CamerasStep site={site} />}
                    {site && step === 'guardia' && <GuardStep site={site} guards={props.guards ?? []} isAdmin={!!props.isAdmin} />}
                    {site && step === 'vecinos' && <ResidentsStep site={site} count={props.residentsCount ?? 0} />}
                    {site && step === 'prueba' && <TestStep site={site} tested={done(props.checklist, 'test')} />}

                    {site && (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                            {index > 1 ? (
                                <Button variant="ghost" onClick={() => go(steps[index - 1])}>
                                    Atrás
                                </Button>
                            ) : (
                                <span />
                            )}
                            {next ? (
                                <Button onClick={() => go(next)}>{stepDone(step, props) ? 'Siguiente' : 'Seguir sin terminar este paso'}</Button>
                            ) : (
                                <Button asChild>
                                    <Link href={`/sites/${site.id}`}>Ir a {siteNoun(site.kind)}</Link>
                                </Button>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function StepTitle({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <h1 className="font-display text-2xl font-semibold">{title}</h1>
            <p className="text-muted-foreground max-w-2xl">{children}</p>
        </div>
    );
}

function PropertyStep({ clients }: { clients: { id: number; name: string }[] }) {
    const form = useForm({ name: '', kind: 'building' as SiteKind, address: '', client_id: '' });

    return (
        <form
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                form.post('/sites');
            }}
            className="flex flex-col gap-5"
        >
            <StepTitle title="¿Qué vamos a vigilar?">
                Elegí el tipo de propiedad y cargá su nombre y dirección. En los próximos pasos la dejamos funcionando.
            </StepTitle>
            <fieldset className="grid gap-2 sm:grid-cols-4">
                {siteKinds.map((kind) => (
                    <label
                        key={kind.id}
                        className="has-[:checked]:border-foreground has-[:checked]:bg-muted/60 flex cursor-pointer flex-col rounded-xl border p-3"
                    >
                        <span className="flex items-center gap-2 font-semibold">
                            <input
                                type="radio"
                                checked={form.data.kind === kind.id}
                                onChange={() => form.setData('kind', kind.id)}
                                className="accent-foreground"
                            />
                            {kind.label}
                        </span>
                        <span className="text-muted-foreground text-sm">{kind.help}</span>
                    </label>
                ))}
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="onb-name">Nombre</Label>
                    <Input
                        id="onb-name"
                        placeholder={siteKinds.find((kind) => kind.id === form.data.kind)?.placeholder}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        autoFocus
                    />
                    <InputError message={form.errors.name} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="onb-address">Dirección</Label>
                    <Input id="onb-address" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                    <InputError message={form.errors.address} />
                </div>
                {clients.length > 0 && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="onb-client">Cliente</Label>
                        <select
                            id="onb-client"
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
                    </div>
                )}
            </div>
            <div>
                <Button type="submit" disabled={form.processing || !form.data.name.trim() || !form.data.address.trim()}>
                    Crear y seguir
                </Button>
            </div>
        </form>
    );
}

function LocationStep({ site }: { site: OnboardingSite }) {
    return (
        <>
            <StepTitle title="¿Dónde está?">
                Buscá la propiedad en el mapa y tocá «Editar perímetro» para marcar sus esquinas. Así medimos qué parte ven las cámaras y ubicamos
                cada una.
            </StepTitle>
            <SiteMap siteId={site.id} perimeter={site.perimeter} center={site.center} cameras={[]} canManage />
        </>
    );
}

function CopyBlock({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <div className="relative">
            <pre className="bg-muted overflow-x-auto rounded-lg p-4 text-sm">{text}</pre>
            <Button
                type="button"
                size="sm"
                variant="secondary"
                className="absolute top-2 right-2"
                onClick={() => navigator.clipboard.writeText(text).then(() => setCopied(true))}
            >
                <Copy className="mr-1 size-3.5" /> {copied ? 'Copiado' : 'Copiar'}
            </Button>
        </div>
    );
}

function EdgeStep({ site, newEdge, edgeServerUrl, canManageEdges }: Props & { site: OnboardingSite }) {
    const form = useForm({ name: 'edge-guardia' });
    const online = site.edges.some((edge) => edge.online);

    return (
        <>
            <StepTitle title="Conectá el equipo edge">
                Es la caja que va en la propiedad, junto a las cámaras: analiza el video ahí mismo y manda a Vigilia solo lo que importa. Funciona
                detrás de cualquier router, sin abrir puertos.
            </StepTitle>
            {site.edges.length === 0 && !newEdge && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/sites/${site.id}/edges`, { preserveScroll: true });
                    }}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="edge-name">Nombre del equipo</Label>
                        <Input id="edge-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </div>
                    <Button type="submit" disabled={form.processing || !canManageEdges}>
                        Registrar el equipo
                    </Button>
                    {!canManageEdges && <p className="text-muted-foreground text-sm">Lo registra la central.</p>}
                </form>
            )}
            {newEdge && (
                <div className="flex flex-col gap-2">
                    <p className="font-semibold">1. En el equipo, guardá esto en el archivo edge/.env (la clave se muestra una sola vez):</p>
                    <CopyBlock text={`SEGURIA_API_URL=${edgeServerUrl}\nSEGURIA_TOKEN=${newEdge.token}`} />
                    <p className="font-semibold">2. Encendé el equipo:</p>
                    <CopyBlock text="cd edge && docker compose up -d" />
                </div>
            )}
            <div className={cn('rounded-xl border p-4', online ? 'border-ok bg-ok/10' : 'border-dashed')}>
                {site.edges.length === 0 ? (
                    <p className="text-muted-foreground">Todavía no hay ningún equipo registrado.</p>
                ) : (
                    site.edges.map((edge) => (
                        <p key={edge.id} className="flex items-center gap-2">
                            <span className={cn('size-2.5 rounded-full', edge.online ? 'bg-ok' : 'bg-pending animate-pulse')} />
                            <span className="font-medium">{edge.name}</span>
                            <span className="text-muted-foreground">
                                {edge.online
                                    ? 'conectado'
                                    : edge.last_seen_at
                                      ? `sin señal desde ${timeAgo(edge.last_seen_at)}`
                                      : 'esperando que se conecte…'}
                            </span>
                        </p>
                    ))
                )}
            </div>
        </>
    );
}

function CamerasStep({ site }: { site: OnboardingSite }) {
    const roles = [
        ['is_access', 'Acceso'],
        ['is_guard_post', 'Garita'],
        ['is_checkpoint', 'Punto de ronda'],
    ] as const;

    return (
        <>
            <StepTitle title="Revisá las cámaras">
                Aparecen solas cuando el equipo las ve. Para cada una: dibujá qué zona vigilar, ubicala en el mapa y marcá si es la del acceso, la
                garita o un punto de ronda.
            </StepTitle>
            {site.cameras.length === 0 ? (
                <p className="text-muted-foreground rounded-xl border border-dashed p-6">Esperando que el equipo reporte las cámaras…</p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {site.cameras.map((camera) => (
                        <li key={camera.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                            <div className="min-w-40 flex-1">
                                <p className="flex items-center gap-2 font-semibold">
                                    <span className={cn('size-2 rounded-full', camera.is_online ? 'bg-ok' : 'bg-alarm')} />
                                    {camera.name}
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {camera.zones > 0 ? `${camera.zones} zonas` : 'Sin zonas'}, {camera.placed ? 'ubicada en el mapa' : 'sin ubicar'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-3 text-sm">
                                {roles.map(([role, label]) => (
                                    <label key={role} className="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            checked={camera[role]}
                                            onChange={() =>
                                                router.patch(
                                                    `/cameras/${camera.id}/roles`,
                                                    {
                                                        is_access: camera.is_access,
                                                        is_guard_post: camera.is_guard_post,
                                                        is_checkpoint: camera.is_checkpoint,
                                                        [role]: !camera[role],
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="accent-foreground size-4"
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            <div className="flex gap-2">
                                <Button asChild size="sm" variant={camera.zones > 0 ? 'ghost' : 'outline'}>
                                    <Link href={`/cameras/${camera.id}/zones`}>Dibujar zonas</Link>
                                </Button>
                                <Button asChild size="sm" variant={camera.placed ? 'ghost' : 'outline'}>
                                    <Link href={`/sites/${site.id}?tab=cobertura`}>Ubicar</Link>
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

function GuardStep({ site, guards, isAdmin }: { site: OnboardingSite; guards: NonNullable<Props['guards']>; isAdmin: boolean }) {
    const settings = useForm({ guard_whatsapp: site.guard_whatsapp ?? '', ack_timeout_seconds: site.ack_timeout_seconds });
    const invite = useForm({ name: '', email: '', phone: '', role: 'guard', site_id: String(site.id) });

    return (
        <>
            <StepTitle title="¿Quién recibe las alertas?">
                Cuando la IA confirma algo, avisa primero a la guardia de la propiedad. Si nadie responde a tiempo, pasa a la central.
            </StepTitle>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    settings.patch(`/sites/${site.id}`, { preserveScroll: true });
                }}
                className="grid gap-4 sm:grid-cols-[1fr_14rem_auto] sm:items-end"
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="onb-whatsapp">WhatsApp de la garita</Label>
                    <Input
                        id="onb-whatsapp"
                        placeholder="+5491155550000"
                        value={settings.data.guard_whatsapp}
                        onChange={(e) => settings.setData('guard_whatsapp', e.target.value)}
                    />
                    <InputError message={settings.errors.guard_whatsapp} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="onb-ack">Pasar a la central después de</Label>
                    <select
                        id="onb-ack"
                        value={settings.data.ack_timeout_seconds}
                        onChange={(e) => settings.setData('ack_timeout_seconds', Number(e.target.value))}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        {[60, 120, 180, 300, 600].map((seconds) => (
                            <option key={seconds} value={seconds}>
                                {seconds / 60} {seconds === 60 ? 'minuto' : 'minutos'}
                            </option>
                        ))}
                    </select>
                </div>
                <Button type="submit" variant="outline" disabled={settings.processing || !settings.isDirty}>
                    Guardar
                </Button>
            </form>

            <div className="flex flex-col gap-3">
                <p className="font-semibold">Guardias con cuenta ({guards.length})</p>
                {guards.map((guard) => (
                    <p key={guard.id} className="text-sm">
                        {guard.name} <span className="text-muted-foreground">{guard.email}</span>
                    </p>
                ))}
                {isAdmin && (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            invite.post('/team', { preserveScroll: true, onSuccess: () => invite.reset('name', 'email', 'phone') });
                        }}
                        className="grid gap-2 sm:grid-cols-[1fr_1fr_12rem_auto]"
                    >
                        <Input
                            aria-label="Nombre del guardia"
                            placeholder="Nombre y apellido"
                            value={invite.data.name}
                            onChange={(e) => invite.setData('name', e.target.value)}
                        />
                        <Input
                            aria-label="Email del guardia"
                            type="email"
                            placeholder="Email"
                            value={invite.data.email}
                            onChange={(e) => invite.setData('email', e.target.value)}
                        />
                        <Input
                            aria-label="Celular del guardia"
                            placeholder="Celular (opcional)"
                            value={invite.data.phone}
                            onChange={(e) => invite.setData('phone', e.target.value)}
                        />
                        <Button type="submit" variant="outline" disabled={invite.processing || !invite.data.name.trim() || !invite.data.email.trim()}>
                            Invitar guardia
                        </Button>
                        <InputError message={invite.errors.email ?? invite.errors.name ?? invite.errors.phone} className="sm:col-span-4" />
                    </form>
                )}
            </div>
        </>
    );
}

function ResidentsStep({ site, count }: { site: OnboardingSite; count: number }) {
    const form = useForm({ lines: '' });
    const units = hasUnits(site.kind);

    return (
        <>
            <StepTitle title={units ? 'Invitá a los vecinos' : 'Invitá a las personas que reciben los avisos'}>
                Pegá la lista como la tenés en la planilla: una persona por línea con nombre, email, {unitNoun(site.kind)} y celular, separados por
                coma, punto y coma o tabulación. A cada uno le llega un mail para entrar a la app. Ya hay {count} invitados.
            </StepTitle>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/sites/${site.id}/residents/import`, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="flex flex-col gap-3"
            >
                <textarea
                    aria-label="Lista de vecinos"
                    rows={8}
                    placeholder={`Marta Ruiz, marta@ejemplo.com, ${units ? '4B' : ''}, +5491155550000\nJuan Pérez, juan@ejemplo.com, ${units ? '7A' : ''}, +5491155550001`}
                    value={form.data.lines}
                    onChange={(e) => form.setData('lines', e.target.value)}
                    className="border-input bg-background rounded-md border px-3 py-2 font-mono text-sm"
                />
                <InputError message={form.errors.lines} />
                <div>
                    <Button type="submit" disabled={form.processing || !form.data.lines.trim()}>
                        Invitar a{' '}
                        {form.data.lines.trim()
                            ? form.data.lines
                                  .trim()
                                  .split('\n')
                                  .filter((line) => line.trim()).length
                            : 0}
                    </Button>
                </div>
            </form>
        </>
    );
}

function TestStep({ site, tested }: { site: OnboardingSite; tested: boolean }) {
    return (
        <>
            <StepTitle title="Probalo caminando">
                Encendé la prueba y caminá frente a cada cámara. Durante 15 minutos la IA analiza todo pero no avisa a nadie, y te muestra qué vio en
                cada zona.
            </StepTitle>
            <div className="flex flex-wrap gap-3">
                <Button
                    onClick={() =>
                        router.post(`/sites/${site.id}/test-mode`, {}, { onSuccess: () => router.visit(`/sites/${site.id}/setup#prueba`) })
                    }
                >
                    Empezar la prueba
                </Button>
                <Button asChild variant="outline">
                    <Link href={`/sites/${site.id}/setup`}>Ver la lista completa de instalación</Link>
                </Button>
            </div>
            {tested && <p className="text-ok font-medium">Todas las cámaras ya se probaron. {siteNoun(site.kind)} está listo para vigilar.</p>}
        </>
    );
}
