import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Verificación en dos pasos', href: '/settings/two-factor' }];

interface Props {
    enabled: boolean;
    required: boolean;
    setup: { qr: string; secret: string } | null;
    recoveryCodes: string[] | null;
    recoveryCodesLeft: number | null;
}

export default function TwoFactor({ enabled, required, setup, recoveryCodes, recoveryCodesLeft }: Props) {
    const forced = required && !enabled;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Verificación en dos pasos" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Verificación en dos pasos"
                        description="Además de la contraseña, al ingresar te pedimos un código que genera tu celular. Si alguien consigue tu contraseña, igual no puede entrar."
                    />

                    {forced && (
                        <div className="border-alarm/40 bg-alarm/10 rounded-2xl border p-4 text-sm">
                            <p className="font-semibold">Tu rol la exige para usar la consola</p>
                            <p className="text-muted-foreground mt-1">
                                Desde la consola se ven las cámaras de todos los edificios y countries. Activala para seguir.
                            </p>
                        </div>
                    )}

                    {recoveryCodes && <RecoveryCodes codes={recoveryCodes} />}

                    {enabled ? <Enabled required={required} codesLeft={recoveryCodesLeft ?? 0} /> : setup ? <Setup setup={setup} /> : <Start />}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function Start() {
    const [processing, setProcessing] = useState(false);

    return (
        <div className="grid gap-4">
            <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                <li>Instalá una app de autenticación en tu celular: Google Authenticator, 1Password o Authy.</li>
                <li>Escaneá el código QR que te vamos a mostrar.</li>
                <li>Escribí el código de 6 dígitos que aparece en la app.</li>
            </ol>
            <div>
                <Button
                    disabled={processing}
                    onClick={() =>
                        router.post('/settings/two-factor', {}, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) })
                    }
                >
                    Activar verificación en dos pasos
                </Button>
            </div>
        </div>
    );
}

function Setup({ setup }: { setup: { qr: string; secret: string } }) {
    const form = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/settings/two-factor/confirm', { preserveScroll: true, onError: () => form.reset() });
    };

    return (
        <div className="grid gap-5">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                {/* QR is generated server-side as SVG from our own secret. */}
                <div className="w-fit rounded-2xl bg-white p-3" dangerouslySetInnerHTML={{ __html: setup.qr }} />
                <div className="grid gap-2 text-sm">
                    <p>Escaneá este código con la app de autenticación.</p>
                    <p className="text-muted-foreground">¿No podés escanearlo? Cargá esta clave a mano:</p>
                    <code className="bg-muted rounded-lg px-3 py-2 font-mono text-xs break-all select-all">{setup.secret}</code>
                </div>
            </div>

            <form onSubmit={submit} className="grid max-w-xs gap-2">
                <Label htmlFor="code">Código de 6 dígitos</Label>
                <Input
                    id="code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    autoFocus
                    maxLength={7}
                    placeholder="123 456"
                    className="font-display text-xl tracking-[0.3em] tabular-nums"
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value)}
                />
                <InputError message={form.errors.code} />
                <div>
                    <Button disabled={form.processing || form.data.code.length < 6}>Confirmar y activar</Button>
                </div>
            </form>
        </div>
    );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    return (
        <div className="bg-muted/60 grid gap-3 rounded-2xl p-4">
            <div>
                <p className="font-semibold">Guardá estos códigos de recuperación</p>
                <p className="text-muted-foreground text-sm">
                    Si perdés el celular, cada uno te deja ingresar una vez. Es la única vez que los mostramos: guardalos en tu gestor de contraseñas.
                </p>
            </div>
            <ul className="grid grid-cols-2 gap-2 font-mono text-sm">
                {codes.map((code) => (
                    <li key={code} className="bg-background rounded-lg px-3 py-1.5 text-center select-all">
                        {code}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Enabled({ required, codesLeft }: { required: boolean; codesLeft: number }) {
    const [action, setAction] = useState<'codes' | 'disable' | null>(null);
    const form = useForm({ password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setAction(null), onFinish: () => form.reset() };
        if (action === 'codes') form.post('/settings/two-factor/recovery-codes', options);
        else form.delete('/settings/two-factor', options);
    };

    return (
        <div className="grid gap-4">
            <div className="flex items-center gap-3">
                <span className="bg-ok/15 text-ok flex size-10 items-center justify-center rounded-full">
                    <ShieldCheck className="size-5" />
                </span>
                <div>
                    <p className="font-semibold">Activada</p>
                    <p className="text-muted-foreground text-sm">
                        Te quedan {codesLeft} {codesLeft === 1 ? 'código' : 'códigos'} de recuperación.
                    </p>
                </div>
            </div>

            {action ? (
                <form onSubmit={submit} className="bg-muted/60 grid max-w-sm gap-2 rounded-2xl p-4">
                    <Label htmlFor="password">
                        {action === 'codes' ? 'Para generar códigos nuevos, confirmá tu contraseña' : 'Para desactivarla, confirmá tu contraseña'}
                    </Label>
                    <Input
                        id="password"
                        type="password"
                        autoFocus
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                    <InputError message={form.errors.password} />
                    <div className="flex gap-2">
                        <Button variant={action === 'disable' ? 'destructive' : 'default'} disabled={form.processing}>
                            {action === 'codes' ? 'Generar códigos nuevos' : 'Desactivar'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setAction(null)}>
                            Volver
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => setAction('codes')}>
                        Generar códigos de recuperación nuevos
                    </Button>
                    {!required && (
                        <Button variant="ghost" onClick={() => setAction('disable')}>
                            Desactivar
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
