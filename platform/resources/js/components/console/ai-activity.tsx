import { DecisionTrail } from '@/components/console/decision-trail';
import { SectionTitle } from '@/components/icon-circle';
import { ItemActionHint } from '@/components/item-action';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { timeAgo, type ActivityItem } from '@/lib/vigilia';
import { cn } from '@/lib/utils';
import { useEcho } from '@laravel/echo-react';
import { Ban, Eye, ShieldCheck, Siren, Sparkles } from 'lucide-react';
import { useCallback, useState } from 'react';

const ICONS = { analyzing: Eye, ignored: Ban, dismissed: ShieldCheck, alert: Siren };

function Row({ item, onOpen }: { item: ActivityItem; onOpen: () => void }) {
    const Icon = ICONS[item.outcome];

    return (
        <li>
            <button
                onClick={onOpen}
                disabled={item.outcome === 'analyzing'}
                className={cn(
                    'group hover:bg-accent flex w-full items-start gap-3 px-4 py-3 text-left transition-colors',
                    item.outcome === 'alert' && 'bg-alarm/10',
                )}
            >
                <span
                    className={cn(
                        'mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full',
                        item.outcome === 'alert' && 'bg-alarm text-white',
                        item.outcome === 'analyzing' && 'bg-pending/20 text-pending',
                        (item.outcome === 'dismissed' || item.outcome === 'ignored') && 'bg-surface-2 text-muted-foreground',
                    )}
                >
                    <Icon className={cn('size-4', item.outcome === 'analyzing' && 'animate-pulse')} />
                </span>
                <span className="min-w-0 flex-1">
                    <span className={cn('block text-sm', item.outcome === 'alert' && 'font-semibold')}>{item.headline}</span>
                    <span className="text-muted-foreground mt-0.5 block truncate text-xs">
                        {item.agent}, {item.camera} ({item.site}), {item.outcome === 'analyzing' ? 'ahora' : timeAgo(item.at)}
                        {item.confidence !== null && item.outcome !== 'analyzing' && `, ${Math.round(item.confidence * 100)}%`}
                    </span>
                </span>
                {item.outcome !== 'analyzing' && <ItemActionHint kind="view" className="hidden xl:inline-flex" />}
            </button>
        </li>
    );
}

/**
 * "La IA ahora": what the system is looking at and what it decides, live, in plain language.
 * Every line opens the full decision trail.
 */
export function AiActivity({ initial, channel }: { initial: ActivityItem[]; channel: string }) {
    const [items, setItems] = useState(initial);
    const [open, setOpen] = useState<ActivityItem | null>(null);

    const onActivity = useCallback((payload: { item: ActivityItem }) => {
        setItems((current) => [payload.item, ...current.filter((item) => item.id !== payload.item.id)].slice(0, 40));
    }, []);
    useEcho<{ item: ActivityItem }>(channel, '.detection.activity', onActivity, [onActivity]);

    const counts = items.reduce((acc, item) => ({ ...acc, [item.outcome]: (acc[item.outcome] ?? 0) + 1 }), {} as Record<string, number>);

    return (
        <section className="bg-card flex min-h-0 flex-col rounded-xl border">
            <header className="border-b px-4 py-3">
                <SectionTitle icon={Sparkles}>
                    La IA ahora
                    <span className="text-ok flex items-center gap-1.5 text-xs font-medium">
                        <span className="bg-ok size-2 animate-pulse rounded-full" aria-hidden />
                        en vivo
                    </span>
                </SectionTitle>
                <p className="text-muted-foreground text-sm">
                    Lo que miran y deciden los agentes, en vivo.
                    {items.length > 0 &&
                        ` Últimas ${items.length}: ${counts.alert ?? 0} alertas, ${(counts.dismissed ?? 0) + (counts.ignored ?? 0)} descartadas.`}
                </p>
            </header>
            {items.length === 0 ? (
                <p className="text-muted-foreground p-6 text-sm">
                    Todavía no hubo movimiento. Cuando un edge detecte algo, vas a ver acá cómo lo analiza la IA.
                </p>
            ) : (
                <ul className="divide-y overflow-y-auto" aria-live="polite">
                    {items.map((item) => (
                        <Row key={item.id} item={item} onOpen={() => setOpen(item)} />
                    ))}
                </ul>
            )}

            <Dialog open={open !== null} onOpenChange={(value) => !value && setOpen(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    {open && (
                        <>
                            <DialogHeader>
                                <DialogTitle>Cómo se decidió</DialogTitle>
                                <DialogDescription>
                                    {open.camera}, {open.site}. {open.headline}
                                </DialogDescription>
                            </DialogHeader>
                            <DecisionTrail item={open} />
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
