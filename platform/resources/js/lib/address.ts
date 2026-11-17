import { type LatLng } from '@/lib/geo';

export const PROVINCES = [
    'Ciudad Autónoma de Buenos Aires',
    'Buenos Aires',
    'Catamarca',
    'Chaco',
    'Chubut',
    'Córdoba',
    'Corrientes',
    'Entre Ríos',
    'Formosa',
    'Jujuy',
    'La Pampa',
    'La Rioja',
    'Mendoza',
    'Misiones',
    'Neuquén',
    'Río Negro',
    'Salta',
    'San Juan',
    'San Luis',
    'Santa Cruz',
    'Santa Fe',
    'Santiago del Estero',
    'Tierra del Fuego',
    'Tucumán',
];

const cache = new Map<string, LatLng | null>();

/**
 * Finds an address on the map with OpenStreetMap's public search (Nominatim), only in Argentina. Called from the
 * browser when a map needs to start somewhere; null when it is not found.
 */
export async function geocode(address: string): Promise<LatLng | null> {
    const query = address.trim();
    if (query.length < 4) return null;
    if (cache.has(query)) return cache.get(query)!;

    try {
        const response = await fetch(
            `https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ar&q=${encodeURIComponent(query)}`,
            {
                headers: { Accept: 'application/json' },
            },
        );
        const results = (await response.json()) as { lat: string; lon: string }[];
        const point: LatLng | null = results[0] ? [Number(results[0].lat), Number(results[0].lon)] : null;
        cache.set(query, point);
        return point;
    } catch {
        return null;
    }
}

export function fullAddress(parts: { address?: string | null; city?: string | null; province?: string | null }): string {
    return [parts.address, parts.city, parts.province].filter(Boolean).join(', ');
}
