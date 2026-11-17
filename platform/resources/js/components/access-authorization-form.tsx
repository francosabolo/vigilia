import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

const DAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
const today = () => new Date().toLocaleDateString('en-CA');

/**
 * D1/D2 — who may come in: a visit for a day, or a provider / domestic staff on set days and hours until a date.
 */
export function AccessAuthorizationForm({
    action,
    residents,
    onDone,
}: {
    action: string;
    residents?: { id: number; name: string; lot: string | null }[];
    onDone?: () => void;
}) {
    const form = useForm({
        kind: 'visit' as 'visit' | 'provider' | 'staff',
        name: '',
        document: '',
        plate: '',
        resident_id: '',
        valid_from: today(),
        valid_until: today(),
        days: [] as number[],
        time_from: '',
        time_to: '',
        notes: '',
    });
    const recurring = form.data.kind !== 'visit';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            valid_until: data.kind === 'visit' ? data.valid_from : data.valid_until,
            days: data.kind === 'visit' ? null : data.days,
            time_from: data.time_from || null,
            time_to: data.time_to || null,
            resident_id: data.resident_id || null,
        }));
        form.post(action, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone?.();
            },
        });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <fieldset className="grid grid-cols-3 gap-2 text-sm">
                <legend className="mb-1 text-sm font-medium">Quién viene</legend>
                {(
                    [
                        ['visit', 'Visita'],
                        ['provider', 'Proveedor'],
                        ['staff', 'Personal doméstico'],
                    ] as const
                ).map(([value, label]) => (
                    <label
                        key={value}
                        className="has-[:checked]:border-foreground has-[:checked]:bg-muted/60 flex cursor-pointer items-center gap-2 rounded-lg border p-2"
                    >
                        <input
                            type="radio"
                            checked={form.data.kind === value}
                            onChange={() => form.setData('kind', value)}
                            className="accent-foreground"
                        />
                        {label}
                    </label>
                ))}
            </fieldset>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="auth-name">Nombre y apellido</Label>
                    <Input id="auth-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <InputError message={form.errors.name} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="auth-plate">Patente (si viene en auto)</Label>
                    <Input id="auth-plate" value={form.data.plate} onChange={(e) => form.setData('plate', e.target.value.toUpperCase())} />
                </div>
                {recurring && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="auth-document">DNI (opcional)</Label>
                        <Input id="auth-document" value={form.data.document} onChange={(e) => form.setData('document', e.target.value)} />
                    </div>
                )}
                {residents && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="auth-resident">A qué vecino visita (opcional)</Label>
                        <select
                            id="auth-resident"
                            value={form.data.resident_id}
                            onChange={(e) => form.setData('resident_id', e.target.value)}
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="">Toda la propiedad</option>
                            {residents.map((resident) => (
                                <option key={resident.id} value={resident.id}>
                                    {resident.lot ? `${resident.lot}, ` : ''}
                                    {resident.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                <div className="grid gap-1.5">
                    <Label htmlFor="auth-from">{recurring ? 'Desde' : 'Día'}</Label>
                    <Input id="auth-from" type="date" value={form.data.valid_from} onChange={(e) => form.setData('valid_from', e.target.value)} />
                    <InputError message={form.errors.valid_from} />
                </div>
                {recurring && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="auth-until">Hasta</Label>
                        <Input
                            id="auth-until"
                            type="date"
                            value={form.data.valid_until}
                            onChange={(e) => form.setData('valid_until', e.target.value)}
                        />
                        <InputError message={form.errors.valid_until} />
                    </div>
                )}
            </div>
            {recurring && (
                <fieldset>
                    <legend className="mb-1.5 text-sm font-medium">Días (ninguno = todos)</legend>
                    <div className="flex gap-1.5">
                        {DAYS.map((label, index) => {
                            const day = index + 1;
                            const active = form.data.days.includes(day);
                            return (
                                <button
                                    type="button"
                                    key={day}
                                    aria-pressed={active}
                                    onClick={() =>
                                        form.setData('days', active ? form.data.days.filter((d) => d !== day) : [...form.data.days, day].sort())
                                    }
                                    className={cn(
                                        'size-9 rounded-full border text-sm font-medium',
                                        active && 'bg-primary text-primary-foreground border-primary',
                                    )}
                                >
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                </fieldset>
            )}
            <div className="grid grid-cols-2 gap-3">
                <div className="grid gap-1.5">
                    <Label htmlFor="auth-time-from">Desde la hora (opcional)</Label>
                    <Input id="auth-time-from" type="time" value={form.data.time_from} onChange={(e) => form.setData('time_from', e.target.value)} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="auth-time-to">Hasta la hora (opcional)</Label>
                    <Input id="auth-time-to" type="time" value={form.data.time_to} onChange={(e) => form.setData('time_to', e.target.value)} />
                    <InputError message={form.errors.time_to} />
                </div>
            </div>
            <div>
                <Button type="submit" disabled={form.processing || !form.data.name.trim()}>
                    Autorizar y generar código
                </Button>
            </div>
        </form>
    );
}
