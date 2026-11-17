import { severityLabel, statusDot, statusLabel, type IncidentStatus, type Severity } from '@/lib/vigilia';
import { cn } from '@/lib/utils';

export function StatusSignal({ status, className }: { status: IncidentStatus; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-sm',
                status === 'escalated' ? 'text-alarm font-semibold' : 'text-muted-foreground',
                className,
            )}
        >
            <span className={cn('size-2 rounded-full', statusDot[status])} />
            {statusLabel[status]}
        </span>
    );
}

export function SeverityText({ severity, className }: { severity: Severity; className?: string }) {
    return (
        <span
            className={cn(
                'font-display text-sm font-semibold',
                severity === 'critical' || severity === 'high' ? 'text-alarm' : severity === 'medium' ? 'text-pending' : 'text-muted-foreground',
                className,
            )}
        >
            Severidad {severityLabel[severity].toLowerCase()}
        </span>
    );
}
