import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';

/**
 * Line icon inside a thin circle, used to mark the key sections of a screen. Graphite by default: red is
 * reserved for the brand mark and alarms (DESIGN.md).
 */
export function IconCircle({ icon: Icon, tone = 'default', className }: { icon: LucideIcon; tone?: 'default' | 'alarm' | 'ok'; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex size-9 shrink-0 items-center justify-center rounded-full border-[1.5px]',
                tone === 'default' && 'border-foreground/70 text-foreground',
                tone === 'alarm' && 'border-alarm text-alarm',
                tone === 'ok' && 'border-ok text-ok',
                className,
            )}
            aria-hidden
        >
            <Icon className="size-[18px]" strokeWidth={1.75} />
        </span>
    );
}

/** Section title with its circle icon. */
export function SectionTitle({ icon, children, className }: { icon: LucideIcon; children: React.ReactNode; className?: string }) {
    return (
        <h2 className={cn('flex items-center gap-3 text-lg font-semibold', className)}>
            <IconCircle icon={icon} />
            {children}
        </h2>
    );
}
