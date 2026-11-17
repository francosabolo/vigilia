import { usePage } from '@inertiajs/react';
import AppLogoIcon from './app-logo-icon';

/**
 * H3 — the security company's name when it set its own brand; Vigilia otherwise.
 */
export default function AppLogo() {
    const { brand } = usePage<{ brand: { name: string | null; color: string | null } | null }>().props;

    return (
        <>
            <div className="text-brand flex aspect-square size-8 items-center justify-center">
                <AppLogoIcon className="size-7" />
            </div>
            <span className="ml-1 flex min-w-0 flex-col leading-none">
                <span className="font-display truncate text-xl font-semibold tracking-tight">{brand?.name ?? 'vigilia'}</span>
                {brand?.name && <span className="text-muted-foreground truncate text-[11px]">con Vigilia</span>}
            </span>
        </>
    );
}
