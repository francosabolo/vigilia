import { IconCircle } from '@/components/icon-circle';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { timeAgo } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Car, Phone } from 'lucide-react';

export interface Arrival {
    id: number;
    status: 'arriving' | 'arrived' | 'unconfirmed';
    resident: { id: number; name: string; lot: string | null; phone: string | null } | null;
    expected_by: string;
    started_at: string;
}

const clock = (iso: string) => new Date(iso).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });

/**
 * F9 in the resident's app: "Estoy llegando" and "Ya entré".
 */
export function SafeArrivalCard({ arrival }: { arrival: Arrival | null }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-3 text-lg">
                    <IconCircle icon={Car} /> Llegada segura
                </CardTitle>
                <CardDescription>
                    Avisá cuando estés llegando: la guardia mira tu portón por cámara hasta que entres. Si no confirmás, te llaman.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {arrival ? (
                    <div className="flex flex-col gap-3">
                        <p className="font-medium">La guardia te está esperando hasta las {clock(arrival.expected_by)}.</p>
                        <Button size="lg" onClick={() => router.post(`/vecinos/llegada/${arrival.id}/listo`, {}, { preserveScroll: true })}>
                            Ya entré
                        </Button>
                    </div>
                ) : (
                    <div className="grid grid-cols-4 gap-2">
                        {[5, 10, 15, 30].map((minutes) => (
                            <Button
                                key={minutes}
                                variant="outline"
                                onClick={() => router.post('/vecinos/llegada', { minutes }, { preserveScroll: true })}
                            >
                                En {minutes} min
                            </Button>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * F9 on the console: residents on their way in. Red when the time passed without confirmation.
 */
export function ArrivalsPanel({ arrivals }: { arrivals: Arrival[] }) {
    if (arrivals.length === 0) return null;

    return (
        <section className="flex flex-col gap-2">
            <h2 className="flex items-center gap-2 font-semibold">
                <Car className="size-5" /> Vecinos llegando
            </h2>
            <ul className="divide-y rounded-lg border">
                {arrivals.map((arrival) => (
                    <li
                        key={arrival.id}
                        className={cn(
                            'flex flex-wrap items-center gap-3 px-4 py-3',
                            arrival.status === 'unconfirmed' ? 'bg-alarm/10' : 'bg-pending/10',
                        )}
                    >
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold">
                                {arrival.resident?.name}
                                {arrival.resident?.lot && ` (${arrival.resident.lot})`}
                            </p>
                            <p className={cn('text-sm', arrival.status === 'unconfirmed' ? 'text-alarm font-medium' : 'text-muted-foreground')}>
                                {arrival.status === 'unconfirmed'
                                    ? `No confirmó que entró: esperaba llegar a las ${clock(arrival.expected_by)}. Llamalo.`
                                    : `Avisó ${timeAgo(arrival.started_at)}, llega antes de las ${clock(arrival.expected_by)}. Mirá su portón y el acceso.`}
                            </p>
                        </div>
                        {arrival.resident?.phone && (
                            <Button asChild size="sm" variant="outline">
                                <a href={`tel:${arrival.resident.phone}`}>
                                    <Phone className="mr-1 size-4" /> Llamar
                                </a>
                            </Button>
                        )}
                        <Button size="sm" onClick={() => router.post(`/arrivals/${arrival.id}/arrived`, {}, { preserveScroll: true })}>
                            Ya entró
                        </Button>
                    </li>
                ))}
            </ul>
        </section>
    );
}
