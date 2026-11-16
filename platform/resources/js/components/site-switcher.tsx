import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, Layers } from 'lucide-react';

/**
 * Always shows which gated community the console is scoped to. Central staff can switch; guards see their site.
 */
export function SiteSwitcher() {
    const { siteContext } = usePage<SharedData>().props;

    if (!siteContext) return null;

    const label = siteContext.current?.name ?? 'Todos los barrios';
    const Icon = siteContext.current ? Building2 : Layers;

    if (!siteContext.canSwitch) {
        return (
            <div className="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium">
                <Icon className="text-primary size-4" />
                {label}
            </div>
        );
    }

    const select = (siteId: number | null) => router.put('/current-site', { site_id: siteId }, { preserveScroll: true });

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="hover:bg-accent flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium">
                <Icon className="text-primary size-4" />
                <span className="max-w-48 truncate">{label}</span>
                <ChevronsUpDown className="text-muted-foreground size-4" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel>Barrio en pantalla</DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => select(null)}>
                    <Layers className="mr-2 size-4" /> Todos los barrios
                    {!siteContext.current && <Check className="ml-auto size-4" />}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                {siteContext.sites.map((site) => (
                    <DropdownMenuItem key={site.id} onSelect={() => select(site.id)} className={cn(siteContext.current?.id === site.id && 'font-semibold')}>
                        <Building2 className="mr-2 size-4" /> {site.name}
                        {site.is_simulated && <span className="text-muted-foreground ml-1 text-xs">simulado</span>}
                        {siteContext.current?.id === site.id && <Check className="ml-auto size-4" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
