import { timeAgo } from '@/lib/seguria';
import { cn } from '@/lib/utils';
import { usePoll } from '@inertiajs/react';
import { ArrowUpRight, Crosshair, VideoOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface FollowCamera {
    id: number;
    name: string;
    sector: string | null;
    preview_url: string | null;
    distance_m?: number | null;
    ahead?: boolean;
    seen_at?: string;
}

export interface Follow {
    current: FollowCamera & { seen_at: string };
    heading: string | null;
    look_for: string | null;
    next: FollowCamera[];
    sightings: number;
}

/** Re-requests the camera's latest frame every few seconds while the incident is open. */
function LivePreview({ camera, big = false }: { camera: FollowCamera; big?: boolean }) {
    const [tick, setTick] = useState(0);
    useEffect(() => {
        const timer = setInterval(() => setTick((value) => value + 1), big ? 2000 : 4000);
        return () => clearInterval(timer);
    }, [big]);

    return camera.preview_url ? (
        <img
            src={`${camera.preview_url}&r=${tick}`}
            alt={`En vivo: ${camera.name}`}
            className="aspect-video w-full rounded-lg bg-black object-cover"
        />
    ) : (
        <div className="text-muted-foreground flex aspect-video w-full items-center justify-center rounded-lg bg-black text-sm">
            <VideoOff className="mr-2 size-4" /> Esperando imagen en vivo…
        </div>
    );
}

/**
 * A10 — follow the intruder across cameras: the camera where they were seen last, live, the description to look
 * for, which way they were going and the next cameras to watch. When another camera sees them, it switches by itself.
 */
export function FollowIntruder({ follow }: { follow: Follow }) {
    usePoll(5000, { only: ['incident'] });
    const currentId = follow.current.id;
    const lastCamera = useRef(currentId);
    const [moved, setMoved] = useState(false);

    useEffect(() => {
        if (currentId !== lastCamera.current) {
            lastCamera.current = currentId;
            setMoved(true);
            const timer = setTimeout(() => setMoved(false), 6000);
            return () => clearTimeout(timer);
        }
    }, [currentId]);

    return (
        <section
            className={cn('flex flex-col gap-3 rounded-xl border-2 p-4 transition-colors', moved ? 'border-alarm bg-alarm/5' : 'border-pending/60')}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-lg font-semibold">
                    <Crosshair className="size-5" /> Seguir al intruso
                </h2>
                <p className={cn('text-sm', moved ? 'text-alarm font-semibold' : 'text-muted-foreground')}>
                    {moved ? `Apareció en ${follow.current.name}` : `Última vez en ${follow.current.name}, ${timeAgo(follow.current.seen_at)}`}
                    {follow.heading && `, iba hacia ${follow.heading}`}
                </p>
            </div>
            {follow.look_for && (
                <p className="bg-muted rounded-lg px-3 py-2 text-sm">
                    <span className="font-semibold">Buscá a: </span>
                    {follow.look_for}
                </p>
            )}
            <LivePreview camera={follow.current} big />
            {follow.next.length > 0 && (
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-medium">Dónde puede aparecer ahora</p>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {follow.next.map((camera) => (
                            <figure key={camera.id} className="flex flex-col gap-1">
                                <LivePreview camera={camera} />
                                <figcaption className="flex items-center gap-1 text-sm">
                                    {camera.ahead && <ArrowUpRight className="text-pending size-4" aria-label="Hacia donde iba" />}
                                    <span className="font-medium">{camera.name}</span>
                                    {camera.distance_m !== null && camera.distance_m !== undefined && (
                                        <span className="text-muted-foreground">a {camera.distance_m} m</span>
                                    )}
                                </figcaption>
                            </figure>
                        ))}
                    </div>
                </div>
            )}
        </section>
    );
}
