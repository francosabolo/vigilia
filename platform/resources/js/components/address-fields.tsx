import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PROVINCES } from '@/lib/address';

/**
 * The address as it is written in Argentina: street and number, city, province.
 */
export function AddressFields({
    prefix,
    value,
    onChange,
    errors = {},
    required = false,
}: {
    prefix: string;
    value: { address: string; city: string; province: string };
    onChange: (field: 'address' | 'city' | 'province', value: string) => void;
    errors?: Partial<Record<'address' | 'city' | 'province', string>>;
    required?: boolean;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr]">
            <div className="grid gap-1.5">
                <Label htmlFor={`${prefix}-address`}>Calle y número{required ? '' : ' (opcional)'}</Label>
                <Input
                    id={`${prefix}-address`}
                    placeholder="Av. Santa Fe 1150"
                    value={value.address}
                    onChange={(e) => onChange('address', e.target.value)}
                />
                <InputError message={errors.address} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${prefix}-city`}>Ciudad o localidad</Label>
                <Input id={`${prefix}-city`} placeholder="Pilar" value={value.city} onChange={(e) => onChange('city', e.target.value)} />
                <InputError message={errors.city} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${prefix}-province`}>Provincia</Label>
                <select
                    id={`${prefix}-province`}
                    value={value.province}
                    onChange={(e) => onChange('province', e.target.value)}
                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="">Elegí</option>
                    {PROVINCES.map((province) => (
                        <option key={province} value={province}>
                            {province}
                        </option>
                    ))}
                </select>
                <InputError message={errors.province} />
            </div>
        </div>
    );
}
