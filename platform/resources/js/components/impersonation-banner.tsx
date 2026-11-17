import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';

const ROLE_LABELS: Record<string, string> = {
    operator: 'operador de la central',
    guard: 'guardia',
    site_admin: 'administración del lugar',
    client_admin: 'administración del cliente',
    resident: 'vecino',
};

/**
 * L10 — always visible while the admin is seeing Vigilia as someone else.
 */
export function ImpersonationBanner() {
    const { impersonating } = usePage<{ impersonating: { name: string; role: string } | null }>().props;
    if (!impersonating) return null;

    return (
        <div
            role="status"
            className="bg-pending text-foreground sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 px-4 py-2 text-sm"
        >
            <span className="flex items-center gap-2 font-medium">
                <Eye className="size-4" />
                Estás viendo Vigilia como {impersonating.name} ({ROLE_LABELS[impersonating.role] ?? impersonating.role}). Lo que hagas queda a su
                nombre.
            </span>
            <Button size="sm" variant="secondary" onClick={() => router.post('/impersonation/leave')}>
                Volver a mi cuenta
            </Button>
        </div>
    );
}
