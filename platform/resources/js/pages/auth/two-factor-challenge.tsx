import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type ChallengeForm = {
    code: string;
    recovery_code: string;
};

export default function TwoFactorChallenge() {
    const [usingRecovery, setUsingRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<ChallengeForm>({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/two-factor-challenge', { onError: () => reset() });
    };

    function toggle() {
        setUsingRecovery(!usingRecovery);
        reset();
    }

    return (
        <AuthLayout
            title="Verificación en dos pasos"
            description={
                usingRecovery
                    ? 'Escribí uno de los códigos de recuperación que guardaste al activarla. Cada código sirve una sola vez.'
                    : 'Abrí tu app de autenticación y escribí el código de 6 dígitos de Vigilia.'
            }
        >
            <Head title="Verificación en dos pasos" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                {usingRecovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery_code">Código de recuperación</Label>
                        <Input
                            id="recovery_code"
                            autoFocus
                            autoComplete="off"
                            placeholder="abcde-12345"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                        />
                        <InputError message={errors.recovery_code} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="code">Código</Label>
                        <Input
                            id="code"
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={7}
                            placeholder="123 456"
                            className="font-display text-center text-2xl tracking-[0.3em] tabular-nums"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                        />
                        <InputError message={errors.code} />
                    </div>
                )}

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Ingresar
                </Button>

                <button type="button" onClick={toggle} className="text-muted-foreground hover:text-foreground text-sm underline underline-offset-4">
                    {usingRecovery ? 'Usar el código de la app' : 'No tengo el celular: usar un código de recuperación'}
                </button>
            </form>
        </AuthLayout>
    );
}
