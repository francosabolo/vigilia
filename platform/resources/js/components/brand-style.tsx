import { usePage } from '@inertiajs/react';

/**
 * H3 — applies the security company's color to the brand token (logo, accents) everywhere.
 */
export function BrandStyle() {
    const { brand } = usePage<{ brand: { name: string | null; color: string | null } | null }>().props;
    if (!brand?.color) return null;

    return <style>{`:root, .dark, .theme-vecinos { --brand-red: ${brand.color}; }`}</style>;
}
