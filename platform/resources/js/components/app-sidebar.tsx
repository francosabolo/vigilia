import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Briefcase,
    Building2,
    Cctv,
    ChartNoAxesCombined,
    DoorOpen,
    FlaskConical,
    Gauge,
    LayoutGrid,
    Search,
    ShieldCheck,
    Siren,
    UserRound,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

type Role = 'admin' | 'operator' | 'guard' | 'site_admin' | 'client_admin';

interface Entry extends NavItem {
    roles?: Role[];
}

/** What the guard and the central use every shift. */
const operationItems: Entry[] = [
    { title: 'Panel', url: '/dashboard', icon: LayoutGrid },
    { title: 'Incidentes', url: '/incidents', icon: Siren },
    { title: 'Cámaras', url: '/cameras', icon: Cctv },
    { title: 'Accesos', url: '/access', icon: DoorOpen },
    { title: 'Vecinos', url: '/residents', icon: UserRound },
    { title: 'Buscar', url: '/search', icon: Search },
];

/** Setting things up and following how the service goes. */
const managementItems: Entry[] = [
    { title: 'Propiedades', url: '/sites', icon: Building2 },
    { title: 'Guardias', url: '/guards', icon: ShieldCheck, roles: ['admin', 'operator', 'site_admin', 'client_admin'] },
    { title: 'Clientes', url: '/clients', icon: Briefcase, roles: ['admin'] },
    { title: 'Usuarios', url: '/team', icon: Users, roles: ['admin'] },
    { title: 'Negocio', url: '/business', icon: ChartNoAxesCombined, roles: ['admin'] },
    { title: 'Calidad de la IA', url: '/ai/quality', icon: Gauge, roles: ['admin', 'operator'] },
    { title: 'Pruebas de IA', url: '/ai/shadow', icon: FlaskConical, roles: ['admin', 'operator'] },
];

export function AppSidebar() {
    const { auth } = usePage<{ auth: { user: { role?: Role } } }>().props;
    const role = auth.user.role;
    const allowed = (items: Entry[]) => items.filter((item) => !item.roles || (role && item.roles.includes(role)));

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
                <NavMain label="Operación diaria" items={allowed(operationItems)} />
                <NavMain label="Gestión" items={allowed(managementItems)} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
