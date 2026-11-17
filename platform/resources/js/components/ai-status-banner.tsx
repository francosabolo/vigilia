import { timeAgo } from '@/lib/seguria';
import { usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';

/**
 * M24 — the AI stopped answering: nothing is lost (alerts go out without the filter), but the console says so.
 */
export function AiStatusBanner() {
    const { aiUnavailableSince } = usePage<{ aiUnavailableSince: string | null }>().props;
    if (!aiUnavailableSince) return null;

    return (
        <div role="alert" className="bg-alarm/90 flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white">
            <TriangleAlert className="size-4" />
            La IA no responde desde {timeAgo(aiUnavailableSince)}: todas las detecciones llegan a la guardia sin el filtro. Puede haber más alertas de
            lo normal.
        </div>
    );
}
