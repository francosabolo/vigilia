import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Eye, Maximize2, Pencil, Trash2, type LucideIcon } from 'lucide-react';
import { forwardRef, type ComponentProps, type ReactNode } from 'react';

/**
 * Design system: the actions available on any element on screen (a row, a card, a camera, a zone).
 * Always icon + verb, always on the right of the element, always the same verbs:
 *   view   → "Ver"     (open the element)
 *   edit   → "Editar"  (change it)
 *   delete → "Borrar" / "Quitar" (always behind a confirmation dialog)
 *   expand → "Ampliar" (bigger view, same screen)
 * See DESIGN.md › Acciones sobre elementos.
 */
export type ItemActionKind = 'view' | 'edit' | 'delete' | 'expand';

const ICONS: Record<ItemActionKind, LucideIcon> = { view: Eye, edit: Pencil, delete: Trash2, expand: Maximize2 };
const LABELS: Record<ItemActionKind, string> = { view: 'Ver', edit: 'Editar', delete: 'Borrar', expand: 'Ampliar' };

const base =
    'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md px-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-2';

function classes(kind: ItemActionKind, className?: string) {
    return cn(base, kind === 'delete' && 'hover:text-alarm', className);
}

function Content({ kind, children }: { kind: ItemActionKind; children?: ReactNode }) {
    const Icon = ICONS[kind];
    return (
        <>
            <Icon className="size-4" aria-hidden />
            <span>{children ?? LABELS[kind]}</span>
        </>
    );
}

/** Navigates to the element (Ver / Editar on its own screen). */
export function ItemActionLink({
    kind,
    href,
    children,
    className,
}: {
    kind: ItemActionKind;
    href: string;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <Link href={href} className={classes(kind, className)}>
            <Content kind={kind}>{children}</Content>
        </Link>
    );
}

/** Acts in place (opens a dialog, enters an edit mode). Forwards refs so it can be a DialogTrigger child. */
export const ItemActionButton = forwardRef<
    HTMLButtonElement,
    { kind: ItemActionKind; children?: ReactNode } & Omit<ComponentProps<'button'>, 'children'>
>(function ItemActionButton({ kind, children, className, ...props }, ref) {
    return (
        <button ref={ref} type="button" className={classes(kind, className)} {...props}>
            <Content kind={kind}>{children}</Content>
        </button>
    );
});

/**
 * Visual-only version for when the whole element is already a link (e.g. an incident row):
 * the verb is still shown so people know the row opens something.
 */
export function ItemActionHint({ kind, children, className }: { kind: ItemActionKind; children?: ReactNode; className?: string }) {
    return (
        <span className={classes(kind, cn('group-hover:bg-accent group-hover:text-foreground', className))} aria-hidden>
            <Content kind={kind}>{children}</Content>
        </span>
    );
}

/** Groups the actions of one element, aligned to the right. */
export function ItemActions({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('flex shrink-0 items-center gap-1', className)}>{children}</div>;
}
