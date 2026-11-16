import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Building2, Cctv, LayoutGrid, Siren, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Panel',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Cámaras',
        url: '/cameras',
        icon: Cctv,
    },
    {
        title: 'Incidentes',
        url: '/incidents',
        icon: Siren,
    },
    {
        title: 'Edificios y countries',
        url: '/sites',
        icon: Building2,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Equipo',
        url: '/team',
        icon: Users,
    },
];

export function AppSidebar() {
    const { auth } = usePage<{ auth: { user: { role?: string } } }>().props;
    const items = auth.user.role === 'admin' ? [...mainNavItems, ...adminNavItems] : mainNavItems;
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
