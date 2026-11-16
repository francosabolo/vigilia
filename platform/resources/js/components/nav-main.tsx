import { SidebarGroup, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage<SharedData>();
    const alerts = page.props.siteContext?.alerts;

    return (
        <SidebarGroup className="px-2 py-2">
            <SidebarMenu>
                {items.map((item) => {
                    const showBadge = item.url === '/incidents' && alerts && alerts.open > 0;
                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={page.url === item.url || page.url.startsWith(`${item.url}/`) || page.url.startsWith(`${item.url}?`)}
                                className="h-10 text-[15px]"
                            >
                                <Link href={item.url} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                            {showBadge && (
                                <SidebarMenuBadge
                                    className={cn(
                                        'font-display rounded-full px-1.5 text-sm',
                                        alerts.escalated > 0 ? 'bg-alarm text-white' : 'bg-pending text-[#1b1d22]',
                                    )}
                                >
                                    {alerts.open}
                                </SidebarMenuBadge>
                            )}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
