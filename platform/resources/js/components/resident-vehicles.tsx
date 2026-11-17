import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router, useForm } from '@inertiajs/react';
import { Car, X } from 'lucide-react';

export interface Vehicle {
    id: number;
    plate: string;
    make_model: string | null;
    color: string | null;
}

/**
 * F10 — a resident's cars: plate, make and model, color. The gate recognizes them and the search finds the owner.
 */
export function ResidentVehicles({
    vehicles,
    addAction,
    removeAction,
}: {
    vehicles: Vehicle[];
    addAction?: string;
    removeAction?: (vehicle: Vehicle) => string;
}) {
    const form = useForm({ plate: '', make_model: '', color: '' });

    return (
        <div className="flex flex-col gap-2 text-sm">
            {vehicles.length === 0 && <p className="text-muted-foreground">Sin vehículos cargados.</p>}
            {vehicles.map((vehicle) => (
                <div key={vehicle.id} className="flex items-center gap-2">
                    <Car className="text-muted-foreground size-4" />
                    <span className="font-display font-semibold tracking-wider">{vehicle.plate}</span>
                    <span className="text-muted-foreground flex-1">{[vehicle.make_model, vehicle.color].filter(Boolean).join(', ')}</span>
                    {removeAction && (
                        <button
                            type="button"
                            aria-label={`Quitar ${vehicle.plate}`}
                            className="text-muted-foreground hover:text-alarm"
                            onClick={() => router.delete(removeAction(vehicle), { preserveScroll: true })}
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
            ))}
            {addAction && vehicles.length < 6 && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(addAction, { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                    className="mt-1 grid gap-2 sm:grid-cols-[8rem_1fr_7rem_auto]"
                >
                    <Input
                        aria-label="Patente"
                        placeholder="Patente"
                        value={form.data.plate}
                        onChange={(e) => form.setData('plate', e.target.value.toUpperCase())}
                    />
                    <Input
                        aria-label="Marca y modelo"
                        placeholder="Marca y modelo"
                        value={form.data.make_model}
                        onChange={(e) => form.setData('make_model', e.target.value)}
                    />
                    <Input aria-label="Color" placeholder="Color" value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} />
                    <Button type="submit" variant="outline" disabled={form.processing || !form.data.plate.trim()}>
                        Agregar
                    </Button>
                    <InputError message={form.errors.plate} className="sm:col-span-4" />
                </form>
            )}
        </div>
    );
}
