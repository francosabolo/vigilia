import { StatusSignal } from '@/components/incident-badges';
import { cn } from '@/lib/utils';
import { severityStripe, timeAgo, type IncidentStatus, type Severity } from '@/lib/seguria';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface Props {
    id: number;
    title: string;
    status: IncidentStatus;
    severity: Severity;
    detectedAt: string;
    meta: ReactNode;
    thumbnail?: ReactNode;
}

/**
 * One incident in a list. The left stripe carries the severity; escalated rows get an alarm tint.
 */
export function IncidentRow({ id, title, status, severity, detectedAt, meta, thumbnail }: Props) {
    return (
        <Link
            href={`/incidents/${id}`}
            className={cn(
                'group relative flex items-center gap-4 overflow-hidden rounded-lg border py-3 pr-4 pl-5 transition-colors',
                status === 'escalated' ? 'border-alarm/60 bg-alarm/10 hover:bg-alarm/15' : 'bg-card hover:bg-accent',
            )}
        >
            <span className={cn('absolute inset-y-0 left-0 w-1.5', severityStripe[severity])} aria-hidden />
            {thumbnail}
            <div className="min-w-0 flex-1">
                <div className="truncate text-[15px] font-semibold">{title}</div>
                <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <StatusSignal status={status} />
                    <span className="truncate">{meta}</span>
                </div>
            </div>
            <time className="font-display text-muted-foreground shrink-0 text-base tabular-nums" dateTime={detectedAt}>
                {timeAgo(detectedAt)}
            </time>
        </Link>
    );
}
