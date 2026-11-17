import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Briefcase, Building2, Cctv, DoorOpen, FlaskConical, LayoutGrid, ShieldCheck, Siren, Users } from 'lucide-react';
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
        title: 'Accesos',
        url: '/access',
        icon: DoorOpen,
    },
    {
        title: 'Propiedades',
        url: '/sites',
        icon: Building2,
    },
];

const supervisionNavItems: NavItem[] = [
    {
        title: 'Guardias',
        url: '/guards',
        icon: ShieldCheck,
    },
];

const centralNavItems: NavItem[] = [
    {
        title: 'Pruebas de IA',
        url: '/ai/shadow',
        icon: FlaskConical,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Clientes',
        url: '/clients',
        icon: Briefcase,
    },
    {
        title: 'Usuarios',
        url: '/team',
        icon: Users,
    },
];

export function AppSidebar() {
    const { auth } = usePage<{ auth: { user: { role?: string } } }>().props;
    const role = auth.user.role;
    const items = [
        ...mainNavItems,
        ...(role !== 'guard' ? supervisionNavItems : []),
        ...(role === 'admin' || role === 'operator' ? centralNavItems : []),
        ...(role === 'admin' ? adminNavItems : []),
    ];
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
